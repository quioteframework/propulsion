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
 * A Class for information regarding possible objects representing a table
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     John McNally <jmcnally@collab.net> (Torque)
 * @version    $Revision$
 */
class Inheritance extends XMLElement
{

	private ?string $key = null;
	private ?string $className = null;
	private ?string $pkg = null;
	private ?string $ancestor = null;
	private ?Column $parent = null;

	/**
	 * Sets up the Inheritance object based on the attributes that were passed to loadFromXML().
	 * @see        parent::loadFromXML()
	 */
	protected function setupObject(): void
	{
		$this->key = $this->getAttribute("key");
		$this->className = $this->getAttribute("class");
		$this->pkg = $this->getAttribute("package");
		$this->ancestor = $this->getAttribute("extends");
	}

	/**
	 * Get the value of key.
	 * @return   string|null  value of key.
	 */
	public function getKey()
	{
		return $this->key;
	}

	/**
	 * Set the value of key.
	 * @param      string $v Value to assign to key.
	 */
	public function setKey($v): void
	{
		$this->key = $v;
	}

	/**
	 * Get the value of parent.
	 * @return     Column|null Value of parent.
	 */
	public function getColumn()
	{
		return $this->parent;
	}

	/**
	 * Set the value of parent.
	 * @param      Column $v Value to assign to parent.
	 */
	public function setColumn(Column  $v): void
	{
		$this->parent = $v;
	}

	/**
	 * Get the value of className.
	 * @return     string value of className.
	 */
	public function getClassName()
	{
		return $this->className;
	}

	/**
	 * Set the value of className.
	 * @param      string $v Value to assign to className.
	 */
	public function setClassName($v): void
	{
		$this->className = $v;
	}

	/**
	 * Get the value of package.
	 * @return     string Value of package.
	 */
	public function getPackage()
	{
		return $this->pkg;
	}

	/**
	 * Set the value of package.
	 * @param      string $v Value to assign to package.
	 */
	public function setPackage($v): void
	{
		$this->pkg = $v;
	}

	/**
	 * Get the value of ancestor.
	 * @return     string Value of ancestor.
	 */
	public function getAncestor()
	{
		return $this->ancestor;
	}

	/**
	 * Set the value of ancestor.
	 * @param      string $v Value to assign to ancestor.
	 */
	public function setAncestor($v): void
	{
		$this->ancestor = $v;
	}

	/**
	 * @see        XMLElement::appendXml(\DOMNode)
	 */
	public function appendXml(\DOMNode $node): void
	{
		$doc = ($node instanceof \DOMDocument) ? $node : $node->ownerDocument;

		$inherNode = $node->appendChild($doc->createElement('inheritance'));
		$inherNode->setAttribute('key', $this->key);
		$inherNode->setAttribute('class', $this->className);

		if ($this->ancestor !== null) {
			$inherNode->setAttribute('extends', $this->ancestor);
		}
	}
}
