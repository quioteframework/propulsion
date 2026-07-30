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
 * Information related to an ID method.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     John McNally <jmcnally@collab.net> (Torque)
 * @author     Daniel Rall <dlr@collab.net> (Torque)
 * @version    $Revision$
 */
class IdMethodParameter extends XMLElement
{

	private ?string $name = null;
	private ?string $value = null;
	private ?Table $parentTable = null;

   /**
	 * Sets up the IdMethodParameter object based on the attributes that were passed to loadFromXML().
	 * @see        parent::loadFromXML()
	 */
	protected function setupObject(): void
	{
		$this->name = $this->getStringAttribute("name");
		$this->value = $this->getStringAttribute("value");
	}

	/**
	 * Get the parameter name
	 */
	public function getName(): ?string
	{
		return $this->name;
	}

	/**
	 * Set the parameter name
	 */
	public function setName(?string $name): void
	{
		$this->name = $name;
	}

	/**
	 * Get the parameter value
	 */
	public function getValue(): ?string
	{
		return $this->value;
	}

	/**
	 * Set the parameter value
	 */
	public function setValue(?string $value): void
	{
		$this->value = $value;
	}

	/**
	 * Set the parent Table of the id method
	 */
	public function setTable(Table $parent): void
	{
		$this->parentTable = $parent;
	}

	/**
	 * Get the parent Table of the id method
	 */
	public function getTable(): ?Table
	{
		return $this->parentTable;
	}

	/**
	 * Get the parent Table of the id method, or throw if this
	 * IdMethodParameter hasn't been attached to one yet.
	 *
	 * @throws EngineException
	 */
	public function requireTable(): Table
	{
		if ($this->parentTable === null) {
			throw new EngineException('This IdMethodParameter has not been attached to a Table.');
		}
		return $this->parentTable;
	}

	/**
	 * Returns the Name of the table the id method is in
	 */
	public function getTableName(): ?string
	{
		return $this->requireTable()->getName();
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

		$paramNode = $node->appendChild($doc->createElement('id-method-parameter'));
		$name = $this->getName();
		if ($name) {
			$paramNode->setAttribute('name', $name);
		}
		$paramNode->setAttribute('value', $this->getValue() ?? '');
	}
}
