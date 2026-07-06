<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Collection;

/**
 * Class for iterating over a list of Propulsion elements
 * The collection keys must be integers - no associative array accepted
 *
 * @method     PropulsionCollection fromXML(string $data) Populate the collection from an XML string
 * @method     PropulsionCollection fromYAML(string $data) Populate the collection from a YAML string
 * @method     PropulsionCollection fromJSON(string $data) Populate the collection from a JSON string
 * @method     PropulsionCollection fromCSV(string $data) Populate the collection from a CSV string
 *
 * @method     string toXML(boolean $usePrefix, boolean $includeLazyLoadColumns) Export the collection to an XML string
 * @method     string toYAML(boolean $usePrefix, boolean $includeLazyLoadColumns) Export the collection to a YAML string
 * @method     string toJSON(boolean $usePrefix, boolean $includeLazyLoadColumns) Export the collection to a JSON string
 * @method     string toCSV(boolean $usePrefix, boolean $includeLazyLoadColumns) Export the collection to a CSV string
 *
 * @author     Francois Zaninotto
 */

 use Propulsion\Formatter\PropulsionFormatter;
 use Propulsion\Exception\PropulsionException;
 use ArrayIterator;
 use Iterator;
 use PDO;
 use Propulsion\Connection\PropulsionPDO;
 use Propulsion\Propulsion;
 use Propulsion\OM\BaseObject;
 use Propulsion\Parser\PropulsionParser;
 use Propulsion\Util\BasePeer;

class PropulsionCollection extends \ArrayObject implements \Serializable
{
	/**
	 * @var       string
	 */
	protected $model = '';

	/**
	 * @var       Iterator|null
	 */
	protected $iterator;

	/**
	 * @var       PropulsionFormatter
	 */
	protected $formatter;

	// Generic Collection methods

	/**
	 * Get the data in the collection
	 *
	 * @return    array
	 */
	public function getData()
	{
		return $this->getArrayCopy();
	}

	/**
	 * Returns the collection as a plain array.
	 * Subclasses override this to serialize ORM objects.
	 *
	 * @param     string|null $keyColumn
	 * @param     bool        $usePrefix
	 * @param     string      $keyType
	 * @param     bool|null   $includeLazyLoadColumns
	 * @param     array       $alreadyDumpedObjects
	 * @return    array
	 */
	public function toArray($keyColumn = null, $usePrefix = false, $keyType = BasePeer::TYPE_PHPNAME, $includeLazyLoadColumns = true, $alreadyDumpedObjects = array())
	{
		return $this->getArrayCopy();
	}

	public function toXML(bool $usePrefix = true, bool $includeLazyLoadColumns = true): string
	{
		return $this->exportTo('XML', $usePrefix, $includeLazyLoadColumns);
	}

	public function toYAML(bool $usePrefix = true, bool $includeLazyLoadColumns = true): string
	{
		return $this->exportTo('YAML', $usePrefix, $includeLazyLoadColumns);
	}

	public function toJSON(bool $usePrefix = true, bool $includeLazyLoadColumns = true): string
	{
		return $this->exportTo('JSON', $usePrefix, $includeLazyLoadColumns);
	}

	public function toCSV(bool $usePrefix = true, bool $includeLazyLoadColumns = true): string
	{
		return $this->exportTo('CSV', $usePrefix, $includeLazyLoadColumns);
	}

	/**
	 * Set the data in the collection
	 *
	 * @param     array $data
	 */
	public function setData($data)
	{
		$this->exchangeArray($data);
	}

	/**
	 * Populates the collection from an array.
	 * Subclasses override this to hydrate ORM objects.
	 *
	 * @param     array $arr
	 * @return    void
	 */
	public function fromArray($arr)
	{
		$this->setData($arr);
	}

	/**
	 * Gets the position of the internal pointer
	 * This position can be later used in seek()
	 *
	 * @return    integer
	 */
	public function getPosition()
	{
		return (int) $this->getInternalIterator()->key();
	}

	/**
	 * Move the internal pointer to the beginning of the list
	 * And get the first element in the collection
	 *
	 * @return    mixed
	 */
	public function getFirst()
	{
		$this->getInternalIterator()->rewind();
		return $this->getCurrent();
	}

	/**
	 * Check whether the internal pointer is at the beginning of the list
	 *
	 * @return    boolean
	 */
	public function isFirst()
	{
		return $this->getPosition() == 0;
	}

	/**
	 * Move the internal pointer backward
	 * And get the previous element in the collection
	 *
	 * @return    mixed
	 */
	public function getPrevious()
	{
		$pos = $this->getPosition();
		if ($pos == 0) {
			return null;
		} else {
			$iterator = $this->getInternalIterator();
			if (!$iterator instanceof \SeekableIterator) {
				throw new PropulsionException(get_class($this) . ' does not support getPrevious().');
			}
			$iterator->seek($pos - 1);
			return $this->getCurrent();
		}
	}

	/**
	 * Get the current element in the collection
	 *
	 * @return    mixed
	 */
	public function getCurrent()
	{
		return $this->getInternalIterator()->current();
	}

	/**
	 * Move the internal pointer forward
	 * And get the next element in the collection
	 *
	 * @return    mixed
	 */
	public function getNext()
	{
		$this->getInternalIterator()->next();
		return $this->getCurrent();
	}

	/**
	 * Move the internal pointer to the end of the list
	 * And get the last element in the collection
	 *
	 * @return    mixed
	 */
	public function getLast()
	{
		$count = $this->count();
		if ($count == 0) {
			return null;
		} else {
			$iterator = $this->getInternalIterator();
			if (!$iterator instanceof \SeekableIterator) {
				throw new PropulsionException(get_class($this) . ' does not support getLast().');
			}
			$iterator->seek($count - 1);
			return $this->getCurrent();
		}
	}

	/**
	 * Check whether the internal pointer is at the end of the list
	 *
	 * @return    boolean
	 */
	public function isLast()
	{
		$count = $this->count();
		if ($count == 0) {
			// empty list... so yes, this is the last
			return true;
		} else {
			return $this->getPosition() == $count - 1;
		}
	}

	/**
	 * Check if the collection is empty
	 *
	 * @return    boolean
	 */
	public function isEmpty()
	{
		return $this->count() == 0;
	}

	/**
	 * Check if the current index is an odd integer
	 *
	 * @return    boolean
	 */
	public function isOdd()
	{
		return (bool) ($this->getInternalIterator()->key() % 2);
	}

	/**
	 * Check if the current index is an even integer
	 *
	 * @return    boolean
	 */
	public function isEven()
	{
		return !$this->isOdd();
	}

	/**
	 * Get an element from its key
	 * Alias for ArrayObject::offsetGet()
	 *
	 * @param     mixed  $key
	 * @return    mixed  The element
	 */
	public function get($key)
	{
		if (!$this->offsetExists($key)) {
			throw new PropulsionException('Unknown key ' . $key);
		}
		return $this->offsetGet($key);
	}

	/**
	 * Pops an element off the end of the collection
	 *
	 * @return    mixed  The popped element
	 */
	public function pop()
	{
		if ($this->count() == 0) {
			return null;
		}
		$ret = $this->getLast();
		$lastKey = $this->getInternalIterator()->key();
		$this->offsetUnset((string) $lastKey);
		return $ret;
	}

	/**
	 * Pops an element off the beginning of the collection
	 *
	 * @return    mixed  The popped element
	 */
	public function shift()
	{
		// the reindexing is complicated to deal with through the iterator
		// so let's use the simple solution
		$arr = $this->getArrayCopy();
		$ret = array_shift($arr);
		$this->exchangeArray($arr);

		return $ret;
	}

	/**
	 * Prepend one or more elements to the beginning of the collection
	 *
	 * @param     mixed  $value the element to prepend
	 * @return    integer  The number of new elements in the array
	 */
	public function prepend($value)
	{
		// the reindexing is complicated to deal with through the iterator
		// so let's use the simple solution
		$arr = $this->getArrayCopy();
		$ret = array_unshift($arr, $value);
		$this->exchangeArray($arr);

		return $ret;
	}

	/**
	 * Add an element to the collection with the given key
	 * Alias for ArrayObject::offsetSet()
	 *
	 * @param     mixed  $key
	 * @param     mixed  $value
	 */
	public function set($key, $value)
	{
		$this->offsetSet($key, $value);
	}

	/**
	 * Removes a specified collection element
	 * Alias for ArrayObject::offsetUnset()
	 *
	 * @param     mixed  $key
	 * @return    mixed  The removed element
	 */
	public function remove($key)
	{
		if (!$this->offsetExists($key)) {
			throw new PropulsionException('Unknown key ' . $key);
		}
		$removed = $this->offsetGet($key);
		$this->offsetUnset($key);
		return $removed;
	}

	/**
	 * Clears the collection
	 *
	 * @return    array  The previous collection
	 */
	public function clear()
	{
		return $this->exchangeArray(array());
	}

	/**
	 * Whether or not this collection contains a specified element
	 *
	 * @param     mixed  $element
	 * @return    boolean
	 */
	public function contains($element)
	{
		return in_array($element, $this->getArrayCopy(), true);
	}

	/**
	 * Search an element in the collection
	 *
	 * @param     mixed  $element
	 * @return    mixed  Returns the key for the element if it is found in the collection, FALSE otherwise
	 */
	public function search($element)
	{
		return array_search($element, $this->getArrayCopy(), true);
	}

	// Serializable interface

	/**
	 * @return string
	 */
	public function serialize(): string
	{
		$repr = array(
			'data'   => $this->getArrayCopy(),
			'model'  => $this->model,
		);
		return serialize($repr);
	}

	/**
	 * @param     string  $data
	 */
	public function unserialize($data): void
	{
		$repr = unserialize($data);
		$this->exchangeArray($repr['data']);
		$this->model = $repr['model'];
	}

	// IteratorAggregate method

	/**
	 * Overrides ArrayObject::getIterator() to save the iterator object
	 * for internal use e.g. getNext(), isOdd(), etc.
	 *
	 * @return    Iterator
	 */
	public function getIterator(): Iterator
	{
		// Use the ArrayObject-native iterator (bound to this object's own
		// storage) rather than `new ArrayIterator($this->getArrayCopy())`,
		// which iterates a disconnected copy: modifying an element via
		// `foreach ($collection as &$item) { ... }` would silently be lost
		// (never written back to the collection) since PHP arrays are
		// value types and getArrayCopy() detaches from the original data.
		$this->iterator = parent::getIterator();
		return $this->iterator;
	}

	/**
	 * @return    Iterator
	 */
	public function getInternalIterator()
	{
		if (null === $this->iterator) {
			return $this->getIterator();
		}
		return $this->iterator;
	}

	/**
	 * Clear the internal Iterator.
	 * PHP 5.3 doesn't know how to free a PropulsionCollection object if it has an attached
	 * Iterator, so this must be done manually to avoid memory leaks.
	 * @see http://www.propelorm.org/ticket/1232
	 */
	public function clearIterator()
	{
		$this->iterator = null;
	}

	// Propulsion collection methods

	/**
	 * Set the model of the elements in the collection
	 *
	 * @param     string  $model  Name of the Propulsion object classes stored in the collection
	 */
	public function setModel($model)
	{
		$this->model = $model;
	}

	/**
	 * Get the model of the elements in the collection
	 *
	 * @return    string  Name of the Propulsion object class stored in the collection
	 */
	public function getModel()
	{
		return $this->model;
	}

	/**
	 * Get the peer class of the elements in the collection
	 *
	 * @return    string  Name of the Propulsion peer class stored in the collection
	 */
	public function getPeerClass()
	{
		if ($this->model == '') {
			throw new PropulsionException('You must set the collection model before interacting with it');
		}
		return constant($this->getModel() . '::PEER');
	}

	/**
	 * @param     PropulsionFormatter  $formatter
	 */
	public function setFormatter(PropulsionFormatter $formatter)
	{
		$this->formatter = $formatter;
	}

	/**
	 * @return    PropulsionFormatter
	 */
	public function getFormatter()
	{
		return $this->formatter;
	}

	/**
	 * Get a connection object for the database containing the elements of the collection
	 *
	 * @param     string  $type  The connection type (Propulsion::CONNECTION_READ by default; can be Propulsion::connection_WRITE)
	 * @return    PDO|PropulsionPDO  A database connection object
	 */
	public function getConnection($type = Propulsion::CONNECTION_READ)
	{
		$databaseName = constant($this->getPeerClass() . '::DATABASE_NAME');

		return Propulsion::getConnection($databaseName, $type);
	}

	/**
	 * Populate the current collection from a string, using a given parser format
	 * <code>
	 * $coll = new PropulsionObjectCollection();
	 * $coll->setModel('Book');
	 * $coll->importFrom('JSON', '{{"Id":9012,"Title":"Don Juan","ISBN":"0140422161","Price":12.99,"PublisherId":1234,"AuthorId":5678}}');
	 * </code>
	 *
	 * @param     mixed   $parser  A PropulsionParser instance, or a format name ('XML', 'YAML', 'JSON', 'CSV')
	 * @param     string  $data    The source data to import from
	 *
	 * @return    $this  The current object, for fluid interface
	 */
	public function importFrom($parser, $data): mixed
	{
		if (!$parser instanceof PropulsionParser) {
			$parser = PropulsionParser::getParser($parser);
		}
		$this->fromArray($parser->listToArray($data));

		return $this;
	}

	/**
	 * Export the current collection to a string, using a given parser format
	 * <code>
	 * $books = BookQuery::create()->find();
	 * echo $book->exportTo('JSON');
	 *  => {{"Id":9012,"Title":"Don Juan","ISBN":"0140422161","Price":12.99,"PublisherId":1234,"AuthorId":5678}}');
	 * </code>
	 *
	 * A PropulsionOnDemandCollection cannot be exported. Any attempt will result in a PropulsionExecption being thrown.
	 *
	 * @param     mixed   $parser                 A PropulsionParser instance, or a format name ('XML', 'YAML', 'JSON', 'CSV')
	 * @param     boolean $usePrefix              (optional) If true, the returned element keys will be prefixed with the
	 *                                            model class name ('Article_0', 'Article_1', etc). Defaults to TRUE.
	 *                                            Not supported by PropulsionArrayCollection, as PropulsionArrayFormatter has
	 *                                            already created the array used here with integers as keys.
	 * @param     boolean $includeLazyLoadColumns (optional) Whether to include lazy load(ed) columns. Defaults to TRUE.
	 *                                            Not supported by PropulsionArrayCollection, as PropulsionArrayFormatter has
	 *                                            already included lazy-load columns in the array used here.
	 * @return    string                          The exported data
	 */
	public function exportTo($parser, $usePrefix = true, $includeLazyLoadColumns = true)
	{
		if (!$parser instanceof PropulsionParser) {
			$parser = PropulsionParser::getParser($parser);
		}
		return $parser->listFromArray($this->toArray(null, $usePrefix, BasePeer::TYPE_PHPNAME, $includeLazyLoadColumns));
	}

	/**
	 * Catches calls to undefined methods.
	 *
	 * Provides magic import/export method support (fromXML()/toXML(), fromYAML()/toYAML(), etc.).
	 * Allows to define default __call() behavior if you use a custom BaseObject
	 *
	 * @param     string  $name
	 * @param     mixed   $params
	 *
	 * @return    $this|array|string
	 */
	public function __call($name, $params)
	{
		if (preg_match('/^from(\w+)$/', $name, $matches)) {
			return $this->importFrom($matches[1], reset($params));
		}
		if (preg_match('/^to(\w+)$/', $name, $matches)) {
			$usePrefix = isset($params[0]) ? $params[0] : true;
			$includeLazyLoadColumns = isset($params[1]) ? $params[1] : true;

			return $this->exportTo($matches[1], $usePrefix, $includeLazyLoadColumns);
		}
		throw new PropulsionException('Call to undefined method: ' . $name);
	}

	/**
	 * Returns a string representation of the current collection.
	 * Based on the string representation of the underlying objects, defined in
	 * the Peer::DEFAULT_STRING_FORMAT constant
	 *
	 * @return    string
	 */
	public function __toString()
	{
		return (string) $this->exportTo(constant($this->getPeerClass() . '::DEFAULT_STRING_FORMAT'));
	}
}
