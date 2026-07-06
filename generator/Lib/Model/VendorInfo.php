<?php

namespace Propulsion\Generator\Model;

/**
 * Object to hold vendor-specific info.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 */
class VendorInfo extends XMLElement
{

	/**
	 * The vendor RDBMS type.
	 *
	 * @var        string
	 */
	private $type;

	/**
	 * Vendor parameters.
	 *
	 * @var        array<string, mixed>
	 */
	private array $parameters = array();

	/**
	 * Creates a new VendorInfo instance.
	 *
	 * @param      string $type RDBMS type (optional)
	 */
	public function __construct($type = null)
	{
		$this->type = $type;
	}

	/**
	 * Sets up this object based on the attributes that were passed to loadFromXML().
	 * @see        parent::loadFromXML()
	 */
	protected function setupObject(): void
	{
		$this->type = $this->getAttribute("type");
	}

	/**
	 * Set RDBMS type for this vendor-specific info.
	 *
	 * @param      string $v
	 */
	public function setType($v): void
	{
		$this->type = $v;
	}

	/**
	 * Get RDBMS type for this vendor-specific info.
	 *
	 * @return     string
	 */
	public function getType()
	{
		return $this->type;
	}

	/**
	 * Adds a new vendor parameter to this object.
	 * @param      array<string, mixed> $attrib Attributes from XML.
	 */
	public function addParameter($attrib): void
	{
		$name = $attrib["name"];
		$this->parameters[$name] = $attrib["value"];
	}

	/**
	 * Sets parameter value.
	 *
	 * @param      string $name
	 * @param      mixed $value The value for the parameter.
	 */
	public function setParameter($name, $value): void
	{
		$this->parameters[$name] = $value;
	}

	/**
	 * Gets parameter value.
	 *
	 * @param      string $name
	 * @return     mixed Paramter value.
	 */
	public function getParameter($name)
	{
		if (isset($this->parameters[$name])) {
			return $this->parameters[$name];
		}
		return null; // just to be explicit
	}

	/**
	 * Whether parameter exists.
	 *
	 * @param      string $name
	 */
	public function hasParameter($name): bool
	{
		return isset($this->parameters[$name]);
	}

	/**
	 * Sets assoc array of parameters for venfor specific info.
	 *
	 * @param      array<string, mixed> $params Paramter data.
	 */
	public function setParameters(array $params = array()): void
	{
		$this->parameters = $params;
	}

	/**
	 * Gets assoc array of parameters for venfor specific info.
	 *
	 * @return     array<string, mixed>
	 */
	public function getParameters()
	{
		return $this->parameters;
	}

	/**
	 * Tests whether this vendor info is empty
	 *
	 * @return boolean
	 */
	public function isEmpty()
	{
	 return empty($this->parameters);
	}

	/**
	 * Gets a new merged VendorInfo object.
	 * @param      VendorInfo $merge
	 * @return     VendorInfo new object with merged parameters
	 */
	public function getMergedVendorInfo(VendorInfo $merge)
	{
		$newParams = array_merge($this->getParameters(), $merge->getParameters());
		$newInfo = new VendorInfo($this->getType());
		$newInfo->setParameters($newParams);
		return $newInfo;
	}

	/**
	 * @see        XMLElement::appendXml(\DOMNode)
	 */
	public function appendXml(\DOMNode $node): void
	{
		$doc = ($node instanceof \DOMDocument) ? $node : $node->ownerDocument;

		$vendorNode = $node->appendChild($doc->createElement("vendor"));
		$vendorNode->setAttribute("type", $this->getType());

		foreach ($this->parameters as $key => $value) {
			$parameterNode = $doc->createElement("parameter");
			$parameterNode->setAttribute("name", $key);
			$parameterNode->setAttribute("value", $value);
			$vendorNode->appendChild($parameterNode);
		}
	}
}
