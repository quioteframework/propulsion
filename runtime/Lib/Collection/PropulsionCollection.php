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

/**
 * @extends \ArrayObject<array-key,mixed>
 */
class PropulsionCollection extends \ArrayObject implements \Serializable
{
	/**
	 * @var       string
	 */
	protected $model = '';

	/**
	 * The internal cursor backing getPosition()/getNext()/isLast()/etc. when no
	 * foreach is currently driving this collection. Held strongly, because it
	 * carries a position that has to survive between those calls and nothing
	 * else references it.
	 *
	 * Only ever populated by getInternalIterator(), i.e. only for collections
	 * whose caller actually uses that cursor API -- notably *not* by
	 * getIterator(), which every plain foreach goes through. See
	 * $foreachIterator for why that distinction matters.
	 *
	 * @var       Iterator<array-key,mixed>|null
	 */
	protected $iterator;

	/**
	 * A **weak** reference to the iterator most recently handed out by
	 * getIterator(), i.e. the one a foreach currently in progress is driving.
	 *
	 * Weak, because ArrayObject::getIterator() returns an iterator that refers
	 * back to this object, so memoising it strongly made every iterated
	 * collection part of a reference cycle. A cycle is not freed when its
	 * refcount drops -- only when PHP's cycle collector runs -- and under a
	 * persistent worker there is no process exit to fall back on, so collections
	 * accumulated: measured at roughly a kilobyte of retained overhead per
	 * iterated collection, on top of the collection's own contents, until GC
	 * happened to fire. clearIterator() exists to break exactly this, and
	 * nothing has ever called it.
	 *
	 * A weak reference is enough because a running foreach holds the iterator
	 * strongly for the duration of the loop. So calling isLast()/getNext()
	 * *inside* a foreach still resolves to that loop's own iterator, exactly as
	 * before; once the loop ends the iterator is collected immediately and the
	 * cycle never forms.
	 *
	 * @var       \WeakReference<Iterator<array-key,mixed>>|null
	 */
	private ?\WeakReference $foreachIterator = null;

	/**
	 * @var       PropulsionFormatter
	 */
	protected $formatter;

	// Generic Collection methods

	/**
	 * Get the data in the collection
	 *
	 * @return    array<array-key,mixed>
	 */
	public function getData()
	{
		return $this->getArrayCopy();
	}

	/**
	 * Returns the collection as a plain array.
	 * Subclasses override this to serialize ORM objects.
	 *
	 * @param     string|null           $keyColumn
	 * @param     bool                  $usePrefix
	 * @param     string                $keyType
	 * @param     bool|null             $includeLazyLoadColumns
	 * @param     array<array-key,mixed> $alreadyDumpedObjects
	 * @return    array<array-key,mixed>
	 */
	public function toArray($keyColumn = null, $usePrefix = false, $keyType = BasePeer::TYPE_PHPNAME, $includeLazyLoadColumns = true, $alreadyDumpedObjects = array()): array
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
	 * @param     array<array-key,mixed> $data
	 */
	public function setData(array $data): void
	{
		$this->exchangeArray($data);
	}

	/**
	 * Populates the collection from an array.
	 * Subclasses override this to hydrate ORM objects.
	 *
	 * @param     array<array-key,mixed> $arr
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
	public function getPosition(): int
	{
		$key = $this->getInternalIterator()->key();
		return is_numeric($key) ? (int) $key : 0;
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
		return (bool) ($this->getPosition() % 2);
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
	 * @param     int|string  $key
	 * @return    mixed  The element
	 */
	public function get(int|string $key)
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
		$this->offsetUnset($lastKey);
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
	 * @param     int|string|null  $key
	 * @param     mixed  $value
	 */
	public function set(int|string|null $key, $value): void
	{
		$this->offsetSet($key, $value);
	}

	/**
	 * Removes a specified collection element
	 * Alias for ArrayObject::offsetUnset()
	 *
	 * @param     int|string  $key
	 * @return    mixed  The removed element
	 */
	public function remove(int|string $key)
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
	 * @return    array<array-key,mixed>  The previous collection
	 */
	public function clear(): array
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
		if (
			!is_array($repr)
			|| !isset($repr['data'], $repr['model'])
			|| !is_array($repr['data'])
			|| !is_string($repr['model'])
		) {
			throw new PropulsionException('Unable to unserialize ' . static::class . ': unexpected data format');
		}
		$this->exchangeArray($repr['data']);
		$this->model = $repr['model'];
	}

	// IteratorAggregate method

	/**
	 * Overrides ArrayObject::getIterator() to save the iterator object
	 * for internal use e.g. getNext(), isOdd(), etc.
	 *
	 * @return    Iterator<array-key,mixed>
	 */
	public function getIterator(): Iterator
	{
		// Use the ArrayObject-native iterator (bound to this object's own
		// storage) rather than `new ArrayIterator($this->getArrayCopy())`,
		// which iterates a disconnected copy: modifying an element via
		// `foreach ($collection as &$item) { ... }` would silently be lost
		// (never written back to the collection) since PHP arrays are
		// value types and getArrayCopy() detaches from the original data.
		$iterator = parent::getIterator();
		// Remembered weakly, not strongly -- see $foreachIterator. This is the
		// path every plain foreach takes, so it is the one that must not leave a
		// reference cycle behind.
		$this->foreachIterator = \WeakReference::create($iterator);

		return $iterator;
	}

	/**
	 * The iterator the position-bearing helpers (getPosition(), getNext(),
	 * isLast(), ...) read from.
	 *
	 * Prefers the iterator a foreach is currently driving, so those helpers
	 * called from inside a loop still describe that loop's position -- which is
	 * how they have always behaved and what they are mostly used for. Falls back
	 * to an internal cursor of its own otherwise, held strongly because nothing
	 * else would keep it (and its position) alive between calls.
	 *
	 * @return    Iterator<array-key,mixed>
	 */
	public function getInternalIterator()
	{
		$foreachIterator = $this->foreachIterator?->get();
		if (null !== $foreachIterator) {
			return $foreachIterator;
		}

		return $this->iterator ??= parent::getIterator();
	}

	/**
	 * Release the internal Iterator, breaking the reference cycle it forms with
	 * this collection.
	 *
	 * Much less load-bearing than it used to be: getIterator() -- the path every
	 * foreach takes -- now remembers its iterator weakly, so ordinary iteration
	 * never creates a cycle to break. Only the getInternalIterator() cursor is
	 * still held strongly (it has to be; see $iterator), so this is worth
	 * calling on a long-lived collection whose caller used getNext()/isLast()
	 * and is now done with it.
	 *
	 * @see http://www.propelorm.org/ticket/1232
	 */
	public function clearIterator(): void
	{
		$this->iterator = null;
		$this->foreachIterator = null;
	}

	// Propulsion collection methods

	/**
	 * Set the model of the elements in the collection
	 *
	 * @param     string  $model  Name of the Propulsion object classes stored in the collection
	 */
	public function setModel($model): void
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
	public function getPeerClass(): string
	{
		if ($this->model == '') {
			throw new PropulsionException('You must set the collection model before interacting with it');
		}
		$peerClass = constant($this->getModel() . '::PEER');
		if (!is_string($peerClass)) {
			throw new PropulsionException('The PEER constant of ' . $this->model . ' must be a string');
		}
		return $peerClass;
	}

	/**
	 * @param     PropulsionFormatter  $formatter
	 */
	public function setFormatter(PropulsionFormatter $formatter): void
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
		if (!is_string($databaseName)) {
			throw new PropulsionException('The DATABASE_NAME constant of ' . $this->getPeerClass() . ' must be a string');
		}

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
	 * @param     PropulsionParser|string  $parser  A PropulsionParser instance, or a format name ('XML', 'YAML', 'JSON', 'CSV')
	 * @param     string  $data    The source data to import from
	 *
	 * @return    $this  The current object, for fluid interface
	 */
	public function importFrom(PropulsionParser|string $parser, string $data): mixed
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
	 * @param     PropulsionParser|string $parser                 A PropulsionParser instance, or a format name ('XML', 'YAML', 'JSON', 'CSV')
	 * @param     boolean $usePrefix              (optional) If true, the returned element keys will be prefixed with the
	 *                                            model class name ('Article_0', 'Article_1', etc). Defaults to TRUE.
	 *                                            Not supported by PropulsionArrayCollection, as PropulsionArrayFormatter has
	 *                                            already created the array used here with integers as keys.
	 * @param     boolean $includeLazyLoadColumns (optional) Whether to include lazy load(ed) columns. Defaults to TRUE.
	 *                                            Not supported by PropulsionArrayCollection, as PropulsionArrayFormatter has
	 *                                            already included lazy-load columns in the array used here.
	 * @return    string                          The exported data
	 */
	public function exportTo(PropulsionParser|string $parser, bool $usePrefix = true, bool $includeLazyLoadColumns = true): string
	{
		if (!$parser instanceof PropulsionParser) {
			$parser = PropulsionParser::getParser($parser);
		}
		$result = $parser->listFromArray($this->toArray(null, $usePrefix, BasePeer::TYPE_PHPNAME, $includeLazyLoadColumns));
		if (!is_string($result)) {
			throw new PropulsionException(get_class($parser) . '::listFromArray() must return a string');
		}
		return $result;
	}

	/**
	 * Catches calls to undefined methods.
	 *
	 * Provides magic import/export method support (fromXML()/toXML(), fromYAML()/toYAML(), etc.).
	 * Allows to define default __call() behavior if you use a custom BaseObject
	 *
	 * @param     string  $name
	 * @param     array<array-key,mixed>  $params
	 *
	 * @return    $this|array<array-key,mixed>|string
	 */
	public function __call($name, array $params)
	{
		if (preg_match('/^from(\w+)$/', $name, $matches)) {
			$data = reset($params);
			if (!is_string($data)) {
				throw new PropulsionException($name . '() expects a string argument');
			}
			return $this->importFrom($matches[1], $data);
		}
		if (preg_match('/^to(\w+)$/', $name, $matches)) {
			$usePrefix = isset($params[0]) ? (bool) $params[0] : true;
			$includeLazyLoadColumns = isset($params[1]) ? (bool) $params[1] : true;

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
	public function __toString(): string
	{
		$format = constant($this->getPeerClass() . '::DEFAULT_STRING_FORMAT');
		if (!is_string($format)) {
			throw new PropulsionException('The DEFAULT_STRING_FORMAT constant of ' . $this->getPeerClass() . ' must be a string');
		}
		return $this->exportTo($format);
	}
}
