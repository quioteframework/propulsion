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
			if ($column->getSize()) {
				$this->indexColumnSizes[$column->getName()] = $column->getSize();
			}
		} else {
			$attrib = $data;
			$name = $attrib["name"];
			$this->indexColumns[] = $name;
			if (isset($attrib["size"])) {
				$this->indexColumnSizes[$name] = $attrib["size"];
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
		foreach ($indexColumns as $col) {
			$this->addColumn($col);
		}
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
