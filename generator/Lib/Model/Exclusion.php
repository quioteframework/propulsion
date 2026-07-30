<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Model;

use DOMDocument;
use Propulsion\Generator\Exception\EngineException;

/**
 * Information about a Postgres exclusion constraint
 * (`EXCLUDE USING gist (col WITH operator, ...) [WHERE (predicate)]`).
 * Modeled as its own class rather than reusing Index/Unique: unlike a plain
 * index/unique constraint, each column carries its own comparison operator
 * (`=`, `&&`, ...), not just a name.
 *
 * Currently only honored by PgsqlPlatform -- every other platform silently
 * ignores any `<exclusion>` element (the same way an opt-in column/index
 * attribute this platform doesn't support is silently unhonored elsewhere
 * in this codebase).
 */
class Exclusion extends XMLElement
{

	private ?string $name;
	private ?Table $parentTable = null;
	private string $indexType = 'gist';
	private ?string $whereClause = null;

	/** @var array<int, array{name: string, operator: string}> */
	private array $exclusionColumns = array();

	public function __construct(?string $name = null)
	{
		$this->name = $name;
	}

	/**
	 * @see        parent::loadFromXML()
	 */
	protected function setupObject(): void
	{
		$this->name = $this->getStringAttribute('name');
		$indexType = $this->getStringAttribute('indexType');
		if ($indexType !== null) {
			$this->indexType = $indexType;
		}
		$this->whereClause = $this->getStringAttribute('where');
	}

	public function getName(): ?string
	{
		if ($this->name === null && $this->parentTable !== null) {
			$this->name = $this->parentTable->getName() . '_exclusion_' . (count($this->parentTable->getExclusions()) + 1);
		}
		return $this->name;
	}

	public function setName(?string $name): void
	{
		$this->name = $name;
	}

	public function setTable(Table $parent): void
	{
		$this->parentTable = $parent;
	}

	public function getTable(): ?Table
	{
		return $this->parentTable;
	}

	/**
	 * The index access method the exclusion constraint's implicit index uses
	 * -- "gist" unless overridden via `indexType="..."` (most exclusion
	 * constraints use gist, since it's the method with the widest operator
	 * class support, but e.g. a plain equality-only exclusion can use btree).
	 */
	public function getIndexType(): string
	{
		return $this->indexType;
	}

	public function setIndexType(string $indexType): void
	{
		$this->indexType = $indexType;
	}

	/**
	 * The `WHERE (...)` predicate restricting the constraint to a subset of
	 * rows (a "partial" exclusion constraint), or null for none.
	 */
	public function getWhereClause(): ?string
	{
		return $this->whereClause;
	}

	public function setWhereClause(?string $whereClause): void
	{
		$this->whereClause = $whereClause;
	}

	/**
	 * Adds a column + comparison operator pair to this exclusion constraint.
	 *
	 * @param array{name?: mixed, operator?: mixed} $data Attributes from XML (`name`, `operator`).
	 */
	public function addColumn($data): void
	{
		$name = $data['name'] ?? null;
		$operator = $data['operator'] ?? null;
		if (!is_string($name) || $name === '' || !is_string($operator) || $operator === '') {
			throw new EngineException(sprintf(
				'exclusion-column on exclusion constraint "%s" requires both a "name" and an "operator" attribute',
				$this->name ?? '(unnamed)'
			));
		}
		$this->exclusionColumns[] = array(
			'name' => $name,
			'operator' => $operator,
		);
	}

	/**
	 * @return array<int, array{name: string, operator: string}>
	 */
	public function getColumns(): array
	{
		return $this->exclusionColumns;
	}

	/**
	 * @see        XMLElement::appendXml(\DOMNode)
	 */
	public function appendXml(\DOMNode $node): void
	{
		$doc = ($node instanceof DOMDocument) ? $node : $node->ownerDocument;
		if ($doc === null) {
			throw new EngineException('Cannot append XML: given DOMNode has no owner document');
		}

		$exclusionNode = $node->appendChild($doc->createElement('exclusion'));
		$exclusionNode->setAttribute('name', $this->getName() ?? '');
		$exclusionNode->setAttribute('indexType', $this->indexType);
		if ($this->whereClause !== null) {
			$exclusionNode->setAttribute('where', $this->whereClause);
		}
		foreach ($this->exclusionColumns as $col) {
			$colNode = $exclusionNode->appendChild($doc->createElement('exclusion-column'));
			$colNode->setAttribute('name', $col['name']);
			$colNode->setAttribute('operator', $col['operator']);
		}

		foreach ($this->vendorInfos as $vi) {
			$vi->appendXml($exclusionNode);
		}
	}

}
