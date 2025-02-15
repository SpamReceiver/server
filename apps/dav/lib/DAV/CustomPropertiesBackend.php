<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @copyright Copyright (c) 2017, Georg Ehrke <oc.list@georgehrke.com>
 *
 * @author Georg Ehrke <oc.list@georgehrke.com>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\DAV;

use OCA\DAV\Connector\Sabre\Node;
use OCP\IDBConnection;
use OCP\IUser;
use Sabre\DAV\PropertyStorage\Backend\BackendInterface;
use Sabre\DAV\PropFind;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Tree;
use Sabre\DAV\Xml\Property\Complex;
use function array_intersect;

class CustomPropertiesBackend implements BackendInterface {

	/** @var string */
	private const TABLE_NAME = 'properties';

	/**
	 * Value is stored as string.
	 */
	public const PROPERTY_TYPE_STRING = 1;

	/**
	 * Value is stored as XML fragment.
	 */
	public const PROPERTY_TYPE_XML = 2;

	/**
	 * Value is stored as a property object.
	 */
	public const PROPERTY_TYPE_OBJECT = 3;

	/**
	 * Ignored properties
	 *
	 * @var string[]
	 */
	private const IGNORED_PROPERTIES = [
		'{DAV:}getcontentlength',
		'{DAV:}getcontenttype',
		'{DAV:}getetag',
		'{DAV:}quota-used-bytes',
		'{DAV:}quota-available-bytes',
		'{http://owncloud.org/ns}permissions',
		'{http://owncloud.org/ns}downloadURL',
		'{http://owncloud.org/ns}dDC',
		'{http://owncloud.org/ns}size',
		'{http://nextcloud.org/ns}is-encrypted',

		// Currently, returning null from any propfind handler would still trigger the backend,
		// so we add all known Nextcloud custom properties in here to avoid that

		// text app
		'{http://nextcloud.org/ns}rich-workspace',
		'{http://nextcloud.org/ns}rich-workspace-file',
		// groupfolders
		'{http://nextcloud.org/ns}acl-enabled',
		'{http://nextcloud.org/ns}acl-can-manage',
		'{http://nextcloud.org/ns}acl-list',
		'{http://nextcloud.org/ns}inherited-acl-list',
		'{http://nextcloud.org/ns}group-folder-id',
		// files_lock
		'{http://nextcloud.org/ns}lock',
		'{http://nextcloud.org/ns}lock-owner-type',
		'{http://nextcloud.org/ns}lock-owner',
		'{http://nextcloud.org/ns}lock-owner-displayname',
		'{http://nextcloud.org/ns}lock-owner-editor',
		'{http://nextcloud.org/ns}lock-time',
		'{http://nextcloud.org/ns}lock-timeout',
		'{http://nextcloud.org/ns}lock-token',
	];

	/**
	 * Properties set by one user, readable by all others
	 *
	 * @var array[]
	 */
	private const PUBLISHED_READ_ONLY_PROPERTIES = [
		'{urn:ietf:params:xml:ns:caldav}calendar-availability',
	];

	/**
	 * @var Tree
	 */
	private $tree;

	/**
	 * @var IDBConnection
	 */
	private $connection;

	/**
	 * @var IUser
	 */
	private $user;

	/**
	 * Properties cache
	 *
	 * @var array
	 */
	private $userCache = [];

	/**
	 * @param Tree $tree node tree
	 * @param IDBConnection $connection database connection
	 * @param IUser $user owner of the tree and properties
	 */
	public function __construct(
		Tree $tree,
		IDBConnection $connection,
		IUser $user) {
		$this->tree = $tree;
		$this->connection = $connection;
		$this->user = $user;
	}

	/**
	 * Fetches properties for a path.
	 *
	 * @param string $path
	 * @param PropFind $propFind
	 * @return void
	 */
	public function propFind($path, PropFind $propFind) {
		$requestedProps = $propFind->get404Properties();

		// these might appear
		$requestedProps = array_diff(
			$requestedProps,
			self::IGNORED_PROPERTIES
		);

		// substr of calendars/ => path is inside the CalDAV component
		// two '/' => this a calendar (no calendar-home nor calendar object)
		if (substr($path, 0, 10) === 'calendars/' && substr_count($path, '/') === 2) {
			$allRequestedProps = $propFind->getRequestedProperties();
			$customPropertiesForShares = [
				'{DAV:}displayname',
				'{urn:ietf:params:xml:ns:caldav}calendar-description',
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone',
				'{http://apple.com/ns/ical/}calendar-order',
				'{http://apple.com/ns/ical/}calendar-color',
				'{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp',
			];

			foreach ($customPropertiesForShares as $customPropertyForShares) {
				if (in_array($customPropertyForShares, $allRequestedProps)) {
					$requestedProps[] = $customPropertyForShares;
				}
			}
		}

		if (empty($requestedProps)) {
			return;
		}

		// First fetch the published properties (set by another user), then get the ones set by
		// the current user. If both are set then the latter as priority.
		foreach ($this->getPublishedProperties($path, $requestedProps) as $propName => $propValue) {
			$propFind->set($propName, $propValue);
		}
		foreach ($this->getUserProperties($path, $requestedProps) as $propName => $propValue) {
			$propFind->set($propName, $propValue);
		}
	}

	/**
	 * Updates properties for a path
	 *
	 * @param string $path
	 * @param PropPatch $propPatch
	 *
	 * @return void
	 */
	public function propPatch($path, PropPatch $propPatch) {
		$propPatch->handleRemaining(function ($changedProps) use ($path) {
			return $this->updateProperties($path, $changedProps);
		});
	}

	/**
	 * This method is called after a node is deleted.
	 *
	 * @param string $path path of node for which to delete properties
	 */
	public function delete($path) {
		$statement = $this->connection->prepare(
			'DELETE FROM `*PREFIX*properties` WHERE `userid` = ? AND `propertypath` = ?'
		);
		$statement->execute([$this->user->getUID(), $this->formatPath($path)]);
		$statement->closeCursor();

		unset($this->userCache[$path]);
	}

	/**
	 * This method is called after a successful MOVE
	 *
	 * @param string $source
	 * @param string $destination
	 *
	 * @return void
	 */
	public function move($source, $destination) {
		$statement = $this->connection->prepare(
			'UPDATE `*PREFIX*properties` SET `propertypath` = ?' .
			' WHERE `userid` = ? AND `propertypath` = ?'
		);
		$statement->execute([$this->formatPath($destination), $this->user->getUID(), $this->formatPath($source)]);
		$statement->closeCursor();
	}

	/**
	 * @param string $path
	 * @param string[] $requestedProperties
	 *
	 * @return array
	 */
	private function getPublishedProperties(string $path, array $requestedProperties): array {
		$allowedProps = array_intersect(self::PUBLISHED_READ_ONLY_PROPERTIES, $requestedProperties);

		if (empty($allowedProps)) {
			return [];
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('propertypath', $qb->createNamedParameter($path)));
		$result = $qb->executeQuery();
		$props = [];
		while ($row = $result->fetch()) {
			$props[$row['propertyname']] = $this->decodeValueFromDatabase($row['propertyvalue'], $row['valuetype']);
		}
		$result->closeCursor();
		return $props;
	}

	/**
	 * Returns a list of properties for the given path and current user
	 *
	 * @param string $path
	 * @param array $requestedProperties requested properties or empty array for "all"
	 * @return array
	 * @note The properties list is a list of propertynames the client
	 * requested, encoded as xmlnamespace#tagName, for example:
	 * http://www.example.org/namespace#author If the array is empty, all
	 * properties should be returned
	 */
	private function getUserProperties(string $path, array $requestedProperties) {
		if (isset($this->userCache[$path])) {
			return $this->userCache[$path];
		}

		// TODO: chunking if more than 1000 properties
		$sql = 'SELECT * FROM `*PREFIX*properties` WHERE `userid` = ? AND `propertypath` = ?';

		$whereValues = [$this->user->getUID(), $this->formatPath($path)];
		$whereTypes = [null, null];

		if (!empty($requestedProperties)) {
			// request only a subset
			$sql .= ' AND `propertyname` in (?)';
			$whereValues[] = $requestedProperties;
			$whereTypes[] = \Doctrine\DBAL\Connection::PARAM_STR_ARRAY;
		}

		$result = $this->connection->executeQuery(
			$sql,
			$whereValues,
			$whereTypes
		);

		$props = [];
		while ($row = $result->fetch()) {
			$props[$row['propertyname']] = $this->decodeValueFromDatabase($row['propertyvalue'], $row['valuetype']);
		}

		$result->closeCursor();

		$this->userCache[$path] = $props;
		return $props;
	}

	/**
	 * Update properties
	 *
	 * @param string $path path for which to update properties
	 * @param array $properties array of properties to update
	 *
	 * @return bool
	 */
	private function updateProperties(string $path, array $properties) {
		$deleteStatement = 'DELETE FROM `*PREFIX*properties`' .
			' WHERE `userid` = ? AND `propertypath` = ? AND `propertyname` = ?';

		$insertStatement = 'INSERT INTO `*PREFIX*properties`' .
			' (`userid`,`propertypath`,`propertyname`,`propertyvalue`, `valuetype`) VALUES(?,?,?,?,?)';

		$updateStatement = 'UPDATE `*PREFIX*properties` SET `propertyvalue` = ?, `valuetype` = ?' .
			' WHERE `userid` = ? AND `propertypath` = ? AND `propertyname` = ?';

		// TODO: use "insert or update" strategy ?
		$existing = $this->getUserProperties($path, []);
		$this->connection->beginTransaction();
		foreach ($properties as $propertyName => $propertyValue) {
			// If it was null, we need to delete the property
			if (is_null($propertyValue)) {
				if (array_key_exists($propertyName, $existing)) {
					$this->connection->executeUpdate($deleteStatement,
						[
							$this->user->getUID(),
							$this->formatPath($path),
							$propertyName,
						]
					);
				}
			} else {
				[$value, $valueType] = $this->encodeValueForDatabase($propertyValue);
				if (!array_key_exists($propertyName, $existing)) {
					$this->connection->executeUpdate($insertStatement,
						[
							$this->user->getUID(),
							$this->formatPath($path),
							$propertyName,
							$value,
							$valueType
						]
					);
				} else {
					$this->connection->executeUpdate($updateStatement,
						[
							$value,
							$valueType,
							$this->user->getUID(),
							$this->formatPath($path),
							$propertyName,
						]
					);
				}
			}
		}

		$this->connection->commit();
		unset($this->userCache[$path]);

		return true;
	}

	/**
	 * long paths are hashed to ensure they fit in the database
	 *
	 * @param string $path
	 * @return string
	 */
	private function formatPath(string $path): string {
		if (strlen($path) > 250) {
			return sha1($path);
		}

		return $path;
	}

	/**
	 * @param mixed $value
	 * @return array
	 */
	private function encodeValueForDatabase($value): array {
		if (is_scalar($value)) {
			$valueType = self::PROPERTY_TYPE_STRING;
		} elseif ($value instanceof Complex) {
			$valueType = self::PROPERTY_TYPE_XML;
			$value = $value->getXml();
		} else {
			$valueType = self::PROPERTY_TYPE_OBJECT;
			$value = serialize($value);
		}
		return [$value, $valueType];
	}

	/**
	 * @return mixed|Complex|string
	 */
	private function decodeValueFromDatabase(string $value, int $valueType) {
		switch ($valueType) {
			case self::PROPERTY_TYPE_XML:
				return new Complex($value);
			case self::PROPERTY_TYPE_OBJECT:
				return unserialize($value);
			case self::PROPERTY_TYPE_STRING:
			default:
				return $value;
		}
	}
}
