<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Model;

use Propulsion\Generator\Exception\EngineException;

/**
 * Validator.
 *
 * @author     Michael Aichler <aichler@mediacluster.de> (Propel)
 * @version    $Revision$
 */
class Validator extends XMLElement
{

	const TRANSLATE_NONE = "none";
	const TRANSLATE_GETTEXT = "gettext";

	/**
	 * The column this validator applies to.
	 *
	 * @var        Column|null
	 */
	private $column;

	/**
	 * The rules for the validation.
	 *
	 * @var        Rule[]
	 */
	private $ruleList = array();

	/**
	 * The translation mode.
	 *
	 * @var        string|null
	 */
	private $translate;

	/**
	 * Parent table.
	 *
	 * @var        Table|null
	 */
	private $table;

	/**
	 * Sets up the Validator object based on the attributes that were passed to loadFromXML().
	 * @see        parent::loadFromXML()
	 */
	protected function setupObject(): void
	{
		$table = $this->requireTable();
		$columnName = $this->getStringAttribute("column");
		$column = $columnName !== null ? $table->getColumn($columnName) : null;
		if ($column === null) {
			throw new EngineException(sprintf(
				"Failed adding validator to table '%s': column '%s' does not exist !",
				$table->getName() ?? '(unnamed)',
				$columnName ?? '(none)'
			));
		}
		$this->column = $column;
		$database = $table->getDatabase();
		$this->translate = $this->getStringAttribute("translate")
			?? $database?->getDefaultTranslateMethod()
			?? self::TRANSLATE_NONE;
	}

	/**
	 * Add a Rule to this validator.
	 * Supports two signatures:
	 * - addRule(Rule $rule)
	 * - addRule(array $attribs)
	 * @param      array<string, mixed>|Rule $data Rule object or XML attribs (array) from <rule/> element.
	 * @return     Rule The added Rule.
	 */
	public function addRule($data)
	{
		if ($data instanceof Rule) {
			$rule = $data; // alias
			$rule->setValidator($this);
			$this->ruleList[] = $rule;
			return $rule;
		}
		else {
			$rule = new Rule();
			$rule->setValidator($this);
			$rule->loadFromXML($data);
			return $this->addRule($rule); // call self w/ different param
		}
	}

	/**
	 * Gets an array of all added rules for this validator.
	 * @return     Rule[]
	 */
	public function getRules()
	{
		return $this->ruleList;
	}

	/**
	 * Gets the name of the column that this Validator applies to.
	 * @return     string|null
	 */
	public function getColumnName()
	{
		return $this->column?->getName();
	}

	/**
	 * Sets the Column object that this validator applies to.
	 * @param      Column $column
	 * @see        Table::addValidator()
	 */
	public function setColumn(Column $column): void
	{
		$this->column = $column;
	}

	/**
	 * Gets the Column object that this validator applies to.
	 * @return     Column|null
	 */
	public function getColumn()
	{
		return $this->column;
	}

	/**
	 * Set the owning Table.
	 * @param      Table $table
	 */
	public function setTable(Table $table): void
	{
		$this->table = $table;
	}

	/**
	 * Get the owning Table.
	 * @return     Table|null
	 */
	public function getTable()
	{
		return $this->table;
	}

	/**
	 * Get the owning Table, or throw if this Validator hasn't been attached
	 * to one yet. Table::addValidator() always calls setTable() unconditionally
	 * before loadFromXML(), so every real (post-attach) call site can assume this.
	 *
	 * @throws EngineException
	 */
	public function requireTable(): Table
	{
		if ($this->table === null) {
			throw new EngineException('This Validator has not been attached to a Table.');
		}
		return $this->table;
	}

	/**
	 * Set the translation mode to use for the message.
	 * Currently only "gettext" and "none" are supported.  The default is "none".
	 * @param      string $method Translation method ("gettext", "none").
	 */
	public function setTranslate($method): void
	{
		$this->translate = $method;
	}

	/**
	 * Get the translation mode to use for the message.
	 * Currently only "gettext" and "none" are supported.  The default is "none".
	 * @return     string|null Translation method ("gettext", "none").
	 */
	public function getTranslate()
	{
		return $this->translate;
	}

	/**
	 * @see        XMLElement::appendXml(\DOMNode)
	 */
	public function appendXml(\DOMNode $node): void
	{
		$doc = ($node instanceof \DOMDocument) ? $node : $node->ownerDocument;
		if ($doc === null) {
			throw new EngineException('Cannot append XML: given DOMNode has no owner document');
		}

		$valNode = $node->appendChild($doc->createElement('validator'));
		$valNode->setAttribute('column', $this->getColumnName() ?? '');

		if ($this->translate !== null) {
			$valNode->setAttribute('translate', $this->translate);
		}

		foreach ($this->ruleList as $rule) {
			$rule->appendXml($valNode);
		}
	}
}
