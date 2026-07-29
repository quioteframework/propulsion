<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
 namespace Propulsion\Generator\Model;

/**
 * Information about indices of a table.
 *
 * @author     Jason van Zyl <vanzyl@apache.org>
 * @author     Daniel Rall <dlr@finemaltcoding.com>
 * @version    $Revision$
 */
use Propulsion\Generator\Exception\EngineException;
class Index extends XMLElement
{

	/** enables debug output */
	const DEBUG = false;

	private ?string $indexName;
	private ?Table $parentTable = null;

	/** @var        string[] */
	private array $indexColumns = array();

	/** @var        array<string, int|string> */
	private array $indexColumnSizes = array();

	/**
	 * Parallel to $indexColumns: whether the entry at the same position is a
	 * raw SQL expression (`<index-column expression="lower(title)"/>`)
	 * rather than a plain column name. Only honored by PgsqlPlatform.
	 *
	 * @var        bool[]
	 */
	private array $columnIsExpression = array();

	/**
	 * The index access method (`indexType="gin"`/`"gist"`/`"brin"`/`"hash"`),
	 * e.g. for a GIN/GiST index over a full-text-search (TSVECTOR) or JSONB
	 * column. Null means the platform's own default index type (a btree, on
	 * every platform this codebase supports). Currently only honored by
	 * PgsqlPlatform's `CREATE INDEX ... USING <type> (...)`.
	 */
	private ?string $indexType = null;

	/**
	 * A `WHERE` predicate restricting this index to a subset of rows (a
	 * "partial" index), or null for none. Only honored by PgsqlPlatform.
	 */
	private ?string $whereClause = null;

	/**
	 * Non-key "covering" column names appended via `INCLUDE (...)`
	 * (`include="col1,col2"`) -- stored in the index for index-only scans
	 * without being part of the index's own key/uniqueness. Only honored by
	 * PgsqlPlatform.
	 *
	 * @var        string[]
	 */
	private array $includeColumns = array();

	/**
	 * Raw storage-parameter text (`storageParameters="fillfactor=70"`)
	 * emitted verbatim inside a trailing `WITH (...)` clause, or null for
	 * none. Only honored by PgsqlPlatform.
	 */
	private ?string $storageParameters = null;

	/**
	 * Whether to build the index without locking out concurrent writes
	 * (`concurrently="true"`, Postgres `CREATE INDEX CONCURRENTLY`). The
	 * caller is responsible for not running the generated statement inside a
	 * transaction block -- Postgres rejects `CREATE INDEX CONCURRENTLY`
	 * there. Only honored by PgsqlPlatform.
	 */
	private bool $concurrent = false;

	/**
	 * Creates a new Index instance.
	 *
	 * @param      string $name
	 */
	public function __construct($name=null)
	{
		$this->indexName = $name;
	}

	private function createName(): void
	{
		$table = $this->getTable();
		$inputs = array();
		$inputs[] = $table->getDatabase();
		$inputs[] = $table->getCommonName();
		if ($this->isUnique()) {
			$inputs[] = "U";
		} else {
			$inputs[] = "I";
		}
		// ASSUMPTION: This Index not yet added to the list.
		if ($this->isUnique()) {
			$inputs[] = count($table->getUnices()) + 1;
		} else {
			$inputs[] = count($table->getIndices()) + 1;
		}

		$this->indexName = NameFactory::generateName(
		NameFactory::CONSTRAINT_GENERATOR, $inputs);
	}

	/**
	 * Sets up the Index object based on the attributes that were passed to loadFromXML().
	 * @see        parent::loadFromXML()
	 */
	protected function setupObject(): void
	{
		$this->indexName = $this->getAttribute("name");
		$this->indexType = $this->getAttribute("indexType", null);
		$this->whereClause = $this->getAttribute("where", null);
		$includeAttr = $this->getAttribute('include', null);
		if ($includeAttr !== null) {
			$this->includeColumns = array_map('trim', explode(',', (string) $includeAttr));
		}
		$this->storageParameters = $this->getAttribute('storageParameters', null);
		$this->concurrent = $this->booleanValue($this->getAttribute('concurrently'));
	}

	/**
	 * The `WHERE` predicate restricting this index to a subset of rows, or
	 * null for none. See the property docblock.
	 */
	public function getWhereClause(): ?string
	{
		return $this->whereClause;
	}

	/**
	 * Sets the partial-index `WHERE` predicate -- see getWhereClause().
	 */
	public function setWhereClause(?string $whereClause): void
	{
		$this->whereClause = $whereClause;
	}

	/**
	 * Non-key "covering" column names appended via `INCLUDE (...)`. See the
	 * property docblock.
	 *
	 * @return string[]
	 */
	public function getIncludeColumns(): array
	{
		return $this->includeColumns;
	}

	/**
	 * @param string[] $includeColumns
	 */
	public function setIncludeColumns(array $includeColumns): void
	{
		$this->includeColumns = $includeColumns;
	}

	/**
	 * Raw storage-parameter text for a trailing `WITH (...)` clause, or null
	 * for none. See the property docblock.
	 */
	public function getStorageParameters(): ?string
	{
		return $this->storageParameters;
	}

	public function setStorageParameters(?string $storageParameters): void
	{
		$this->storageParameters = $storageParameters;
	}

	/**
	 * Whether to build via `CREATE INDEX CONCURRENTLY`. See the property
	 * docblock.
	 */
	public function isConcurrent(): bool
	{
		return $this->concurrent;
	}

	public function setConcurrent(bool $concurrent): void
	{
		$this->concurrent = $concurrent;
	}

	/**
	 * The index access method (e.g. "gin", "gist"), or null for the
	 * platform's default (a btree). See the property docblock.
	 */
	public function getIndexType(): ?string
	{
		return $this->indexType;
	}

	/**
	 * Sets the index access method -- see getIndexType().
	 */
	public function setIndexType(?string $indexType): void
	{
		$this->indexType = $indexType;
	}

	/**
	 * @see        #isUnique()
	 * @deprecated Use isUnique() instead.
	 */
	public function getIsUnique(): bool
	{
		return $this->isUnique();
	}

	/**
	 * Returns the uniqueness of this index.
	 */
	public function isUnique(): bool
	{
		return false;
	}

	/**
	 * @see        #getName()
	 * @deprecated Use getName() instead.
	 */
	public function getIndexName(): ?string
	{
		return $this->getName();
	}

	/**
	 * Gets the name of this index.
	 */
	public function getName(): ?string
	{
		if ($this->indexName === null) {
			try {
				// generate an index name if we don't have a supplied one
				$this->createName();
			} catch (EngineException $e) {
				// still no name
			}
		}
		if ($database = $this->getTable()->getDatabase()) {
			return substr($this->indexName, 0, $database->getPlatform()->getMaxColumnNameLength());
		} else {
			return $this->indexName;
		}
	}

	/**
	 * @see        #setName(String name)
	 * @deprecated Use setName(String name) instead.
	 */
	public function setIndexName(?string $name): void
	{
		$this->setName($name);
	}

	/**
	 * Set the name of this index.
	 */
	public function setName(?string $name): void
	{
		$this->indexName = $name;
	}

	/**
	 * Set the parent Table of the index
	 */
	public function setTable(Table $parent): void
	{
		$this->parentTable = $parent;
	}

	/**
	 * Get the parent Table of the index
	 */
	public function getTable(): ?Table
	{
		return $this->parentTable;
	}

	/**
	 * Returns the Name of the table the index is in
	 */
	public function getTableName(): ?string
	{
		return $this->parentTable->getName();
	}

	/**
	 * Adds a new column to an index.
	 * @param      array<string, mixed>|Column $data Column or attributes from XML.
	 */
	public function addColumn($data): void
	{
		if ($data instanceof Column) {
			$column = $data;
			$this->indexColumns[] = $column->getName();
			$this->columnIsExpression[] = false;
			if ($column->getSize()) {
				$this->indexColumnSizes[$column->getName()] = $column->getSize();
			}
		} else {
			$attrib = $data;
			if (isset($attrib['expression'])) {
				$this->indexColumns[] = $attrib['expression'];
				$this->columnIsExpression[] = true;
			} else {
				$name = $attrib["name"];
				$this->indexColumns[] = $name;
				$this->columnIsExpression[] = false;
				if (isset($attrib["size"])) {
					$this->indexColumnSizes[$name] = $attrib["size"];
				}
			}
		}
	}

	/**
	 * Sets array of columns to use for index.
	 *
	 * @param      array<int, Column> $indexColumns
	 */
	public function setColumns(array $indexColumns): void
	{
		$this->indexColumns = array();
		$this->indexColumnSizes = array();
		$this->columnIsExpression = array();
		foreach ($indexColumns as $col) {
			$this->addColumn($col);
		}
	}

	/**
	 * Whether the column entry at $pos (the same ordinal position
	 * getColumns() uses) is a raw SQL expression rather than a plain column
	 * name -- see the `expression` schema attribute on `<index-column>`.
	 */
	public function isExpressionAtPosition(int $pos): bool
	{
		return $this->columnIsExpression[$pos] ?? false;
	}

	/**
	 * Whether there is a size for the specified column.
	 * @param      string $name
	 * @return     boolean
	 */
	public function hasColumnSize($name)
	{
		return isset($this->indexColumnSizes[$name]);
	}

	/**
	 * Returns the size for the specified column, if given.
	 * @param      string $name
	 * @return     numeric|null The size or NULL
	 */
	public function getColumnSize($name)
	{
		if (isset($this->indexColumnSizes[$name])) {
			return $this->indexColumnSizes[$name];
		}
		return null; // just to be explicit
	}

	/**
	 * Reset the column sizes. Useful for generated indices for FKs
	 */
	public function resetColumnSize(): void
	{
		$this->indexColumnSizes = array();
	}

	/**
	 * @see        #getColumnList()
	 * @deprecated Use getColumnList() instead (which is not deprecated too!)
	 */
	public function getIndexColumnList(): string
	{
		return $this->getColumnList();
	}

	/**
	 * Return a comma delimited string of the columns which compose this index.
	 * @deprecated because Column::makeList() is deprecated; use the array-returning getColumns() instead.
	 */
	public function getColumnList(): string
	{
		return Column::makeList($this->getColumns(), $this->getTable()->getDatabase()->getPlatform());
	}

	/**
	 * @see        #getColumns()
	 * @deprecated Use getColumns() instead.
	 * @return     string[]
	 */
	public function getIndexColumns(): array
	{
		return $this->getColumns();
	}

	/**
	 * Check whether this index has a given column at a given position
	 *
	 * @param integer $pos Position in the column list
	 * @param string  $name Column name
	 * @param integer $size optional size check
	 * @param boolean $caseInsensitive Whether the comparison is case insensitive.
	 *                                 False by default.
	 *
	 * @return boolean
	 */
	public function hasColumnAtPosition($pos, $name, $size = null, $caseInsensitive = false)
	{
		if (!isset($this->indexColumns[$pos])) {
			return false;
		}
		$test = $caseInsensitive ?
			strtolower($this->indexColumns[$pos]) != strtolower($name) :
			$this->indexColumns[$pos] != $name;
		if ($test) {
			return false;
		}
		if (null !== $size && $this->indexColumnSizes[$name] != $size) {
			return false;
		}
		return true;
	}

	/**
	 * Check whether the index has columns.
	 * @return     boolean
	 */
	public function hasColumns()
	{
		return count($this->indexColumns) > 0;
	}

	/**
	 * Return the list of local columns. You should not edit this list.
	 * @return     string[]
	 */
	public function getColumns(): array
	{
		return $this->indexColumns;
	}

	/**
	 * @see        XMLElement::appendXml(\DOMNode)
	 */
	public function appendXml(\DOMNode $node): void
	{
		$doc = ($node instanceof \DOMDocument) ? $node : $node->ownerDocument;

		$idxNode = $node->appendChild($doc->createElement('index'));
		$idxNode->setAttribute('name', $this->getName());

		foreach ($this->indexColumns as $colname) {
			$idxColNode = $idxNode->appendChild($doc->createElement('index-column'));
			$idxColNode->setAttribute('name', $colname);
		}

		foreach ($this->vendorInfos as $vi) {
			$vi->appendXml($idxNode);
		}
	}
}
