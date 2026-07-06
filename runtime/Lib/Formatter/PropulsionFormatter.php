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
		$this->peer = constant($this->class . '::PEER');
	}

	public function getClass(): ?string
	{
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

	public function checkInit(): void
	{
		if (null === $this->peer) {
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
