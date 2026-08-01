<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Formatter;

/**
 * Abstract class for query formatter
 *
 * @author     Francois Zaninotto
 * @version    $Revision$
 */

 use Propulsion\Query\ModelCriteria;
 use Propulsion\OM\BaseObject;
 use Propulsion\Propulsion;
 use Propulsion\Exception\PropulsionException;
 use Propulsion\Map\TableMap;
 use PDOStatement;
abstract class PropulsionFormatter
{
	protected ?string $dbName = null;

	protected ?string $class = null;

	/** @var string|null */
	protected $peer;

	/** @var array<string, ModelWith> */
	protected array $with = array();

	/** @var array<string, string> */
	protected array $asColumns = array();

	protected bool $hasLimit = false;

	/** @var array<int, BaseObject> */
	protected array $currentObjects = array();

	public function __construct(?ModelCriteria $criteria = null)
	{
		if (null !== $criteria) {
			$this->init($criteria);
		}
	}

	/**
	 * Define the hydration schema based on a query object.
	 * Fills the Formatter's properties using a Criteria as source
	 *
	 * @param ModelCriteria $criteria
	 *
	 * @return static The current formatter object
	 */
	public function init(ModelCriteria $criteria): static
	{
		$this->dbName = $criteria->getDbName();
		$this->setClass($criteria->getModelName());
		$this->setWith($criteria->getWith());
		$this->asColumns = $criteria->getAsColumns();
		$this->hasLimit = $criteria->getLimit() != 0;

		return $this;
	}

	// DataObject getters & setters

	public function setDbName(?string $dbName): void
	{
		$this->dbName = $dbName;
	}

	public function getDbName(): ?string
	{
		return $this->dbName;
	}

	public function setClass(string $class): void
	{
		$this->class = $class;
		$peer = constant($this->class . '::PEER');
		if (!is_string($peer)) {
			throw new PropulsionException('The PEER constant of ' . $class . ' must be a string');
		}
		$this->peer = $peer;
	}

	public function getClass(): ?string
	{
		return $this->class;
	}

	/**
	 * `$this->class` is set (together with `$this->peer`) by setClass(), which init()
	 * always calls -- but it's typed nullable since a freshly-constructed formatter with
	 * no criteria has neither set yet. checkInit() already verifies a formatter was
	 * initialized before format()/formatOne() run; this narrows that same guarantee to a
	 * non-null string for callers (like setModel()) that need one, instead of repeating
	 * the same null check ad hoc.
	 */
	protected function requireClass(): string
	{
		if ($this->class === null) {
			throw new PropulsionException('You must initialize a formatter object before calling format() or formatOne()');
		}
		return $this->class;
	}

	/** @param string|null $peer */
	public function setPeer($peer): void
	{
		$this->peer = $peer;
	}

	/** @return string|null */
	public function getPeer()
	{
		return $this->peer;
	}

	/** @param array<string, ModelWith> $withs */
	public function setWith(array $withs = array()): void
	{
		$this->with = $withs;
	}

	/** @return array<string, ModelWith> */
	public function getWith(): array
	{
		return $this->with;
	}

	/** @param array<string, string> $asColumns */
	public function setAsColumns(array $asColumns = array()): void
	{
		$this->asColumns = $asColumns;
	}

	/** @return array<string, string> */
	public function getAsColumns(): array
	{
		return $this->asColumns;
	}

	public function setHasLimit(bool $hasLimit = false): void
	{
		$this->hasLimit = $hasLimit;
	}

	public function hasLimit(): bool
	{
		return $this->hasLimit;
	}

	/**
	 * Formats an ActiveRecord object
	 *
	 * @param BaseObject $record the object to format
	 *
	 * @return mixed The formatted record (subclasses vary: BaseObject as-is, an array, etc.)
	 */
	public function formatRecord(?BaseObject $record = null): mixed
	{
		return $record;
	}

	abstract public function format(PDOStatement $stmt): mixed;

	abstract public function formatOne(PDOStatement $stmt): mixed;

	abstract public function isObjectFormatter(): bool;

	/**
	 * The row-array counterpart of {@see format()}, used when the rows came
	 * from the global query result cache rather than from a live statement
	 * (see {@see \Propulsion\Cache\SharedQueryCache}).
	 *
	 * Deliberately concrete rather than abstract: this pair of methods and
	 * {@see supportsRowCaching()} were added after the formatter hierarchy was
	 * public, and making them abstract would break every third-party formatter
	 * on upgrade. The default throws, and the capability flag below defaults to
	 * false, so a formatter that does not opt in is simply never asked.
	 *
	 * @param     iterable<int, array<int, mixed>> $rows rows as PDO::FETCH_NUM produces them
	 * @return    mixed
	 */
	public function formatFromRows(iterable $rows): mixed
	{
		throw new PropulsionException(static::class . ' does not support formatting from a row array');
	}

	/**
	 * The row-array counterpart of {@see formatOne()}.
	 *
	 * @param     iterable<int, array<int, mixed>> $rows rows as PDO::FETCH_NUM produces them
	 * @return    mixed
	 */
	public function formatOneFromRows(iterable $rows): mixed
	{
		throw new PropulsionException(static::class . ' does not support formatting from a row array');
	}

	/**
	 * Whether this formatter's result can be reconstructed from a plain row
	 * array -- i.e. whether a query using it may be cached at all.
	 *
	 * False for the two formatters whose results are inherently tied to a live
	 * statement: {@see PropulsionOnDemandFormatter} streams rather than
	 * materialising, and {@see PropulsionStatementFormatter} returns the
	 * statement itself. Caching either of those hands the next caller an
	 * exhausted cursor, so both cache tiers skip them entirely.
	 */
	public function supportsRowCaching(): bool
	{
		return false;
	}

	/**
	 * @param     ?PDOStatement $stmt The statement format()/formatOne() was just
	 *            handed, already executed by the caller before either of them
	 *            ever runs -- closed here before throwing (if given) since
	 *            it's otherwise abandoned with its result set still open.
	 *            FreeTDS/pdo_dblib (MSSQL, no MARS support) can fail a later,
	 *            unrelated statement on the same connection with "Attempt to
	 *            initiate a new Adaptive Server operation with results
	 *            pending" before PHP's GC gets around to it; harmless
	 *            everywhere else.
	 */
	public function checkInit(?PDOStatement $stmt = null): void
	{
		if (null === $this->peer) {
			if ($stmt !== null) {
				$stmt->closeCursor();
			}
			throw new PropulsionException('You must initialize a formatter object before calling format() or formatOne()');
		}
	}

	public function getTableMap(): TableMap
	{
		return Propulsion::getDatabaseMap($this->dbName)->getTableByPhpName((string) $this->class);
	}

	protected function isWithOneToMany(): bool
	{
		foreach ($this->with as $modelWith) {
			if ($modelWith->isWithOneToMany()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Gets the worker object for the class.
	 * To save memory, we don't create a new object for each row,
	 * But we keep hydrating a single object per class.
	 * The column offset in the row is used to index the array of classes
	 * As there may be more than one object of the same class in the chain
	 *
	 * @param     int    $col    Offset of the object in the list of objects to hydrate
	 * @param     string $class  Propulsion model object class
	 *
	 * @return    BaseObject
	 */
	protected function getWorkerObject(int $col, string $class): BaseObject
	{
		if(isset($this->currentObjects[$col])) {
			$this->currentObjects[$col]->clear();
		} else {
			// $class is a plain string -- no shared interface constrains what schema
			// authors name their generated model classes -- but this ORM's own generator
			// only ever produces BaseObject subclasses for it. is_a(..., true) is a real
			// runtime check, not just a docblock claim, so a genuinely wrong model class
			// name fails loudly here instead of fatal-erroring on `new`.
			if (!is_a($class, BaseObject::class, true)) {
				throw new PropulsionException("Model class '$class' does not extend " . BaseObject::class . '.');
			}
			$this->currentObjects[$col] = new $class();
		}
		return $this->currentObjects[$col];
	}

	/**
	 * Gets a Propulsion object hydrated from a selection of columns in statement row
	 *
	 * @param     array<int, mixed>  $row associative array indexed by column number,
	 *                   as returned by PDOStatement::fetch(PDO::FETCH_NUM)
	 * @param     string $class The classname of the object to create
	 * @param     int    $col The start column for the hydration (modified)
	 *
	 * @return    BaseObject
	 */
	public function getSingleObjectFromRow(array $row, string $class, int &$col = 0): BaseObject
	{
		$obj = $this->getWorkerObject($col, $class);
		$col = $obj->hydrate($row, $col);

		return $obj;
	}

}
