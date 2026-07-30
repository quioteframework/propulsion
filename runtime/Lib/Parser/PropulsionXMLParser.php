<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Parser;

use Propulsion\Exception\PropulsionException;

/**
 * XML parser. Converts data between associative array and XML formats
 *
 * @author     Francois Zaninotto
 */
class PropulsionXMLParser extends PropulsionParser
{

	/**
	 * Converts data from an associative array to XML.
	 *
	 * @param  array<mixed>   $array Source data to convert
	 * @param  string  $rootElementName Name of the root element of the XML document
	 * @param  string  $charset Character set of the input data. Defaults to UTF-8.
	 *
	 * @return string Converted data, as an XML string
	 * @throws PropulsionException if the document cannot be serialized to XML
	 */
	public function fromArray($array, $rootElementName = 'data', $charset = null)
	{
		$rootNode = $this->getRootNode($rootElementName);
		$this->arrayToDOM($array, $rootNode, $charset, false);

		$xml = $this->ownerDocumentOf($rootNode)->saveXML();
		if ($xml === false) {
			throw new PropulsionException('Unable to serialize document to XML');
		}
		return $xml;
	}

	/**
	 * @param  array<mixed>  $array Source data to convert
	 * @param  string  $rootElementName Name of the root element of the XML document
	 * @param  string|null  $charset Character set of the input data. Defaults to UTF-8.
	 *
	 * @return string|false Converted data, as an XML string
	 */
	public function listFromArray($array, $rootElementName = 'data', $charset = null)
	{
		$rootNode = $this->getRootNode($rootElementName);
		$this->arrayToDOM($array, $rootNode, $charset, true);

		return $this->ownerDocumentOf($rootNode)->saveXML();
	}

	/**
	 * Create a DOMDocument and get the root \DOMNode using a root element name
	 *
	 * @param  string $rootElementName The Root Element Name
	 *
	 * @return \DOMElement The root DOMElement
	 * @throws PropulsionException if the root element cannot be created
	 */
	protected function getRootNode($rootElementName = 'data'): \DOMElement
	{
		$xml = new \DOMDocument('1.0', 'UTF-8');
		$xml->preserveWhiteSpace = false;
		$xml->formatOutput = true;
		$rootElement = $xml->createElement($rootElementName);
		if ($rootElement === false) {
			throw new PropulsionException('Unable to create root XML element: ' . $rootElementName);
		}
		$xml->appendChild($rootElement);

		return $rootElement;
	}

	/**
	 * Returns the ownerDocument of a DOMNode that is known to have been
	 * attached to a document (e.g. via getRootNode()/arrayToDOM()).
	 *
	 * @throws PropulsionException if the node has no owner document
	 */
	private function ownerDocumentOf(\DOMNode $node): \DOMDocument
	{
		if ($node->ownerDocument === null) {
			throw new PropulsionException('DOM node has no owner document');
		}
		return $node->ownerDocument;
	}

	/**
	 * Alias for PropulsionXMLParser::fromArray()
	 *
	 * @param  array<mixed>   $array Source data to convert
	 * @param  string  $rootElementName Name of the root element of the XML document
	 * @param  string  $charset Character set of the input data. Defaults to UTF-8.
	 *
	 * @return string Converted data, as an XML string
	 */
	public function toXML($array, $rootElementName = 'data', $charset = null)
	{
		return $this->fromArray($array, $rootElementName, $charset);
	}

	/**
	 * Alias for PropulsionXMLParser::listFromArray()
	 *
	 * @param  array<mixed>   $array Source data to convert
	 * @param  string  $rootElementName Name of the root element of the XML document
	 * @param  string  $charset Character set of the input data. Defaults to UTF-8.
	 *
	 * @return string|false Converted data, as an XML string
	 */
	public function listToXML($array, $rootElementName = 'data', $charset = null)
	{
		return $this->listFromArray($array, $rootElementName, $charset);
	}

	/**
 	 * @param  array<mixed> $array
	 * @param  \DOMElement $rootElement
	 * @param  string|null $charset
	 * @param  boolean $removeNumbersFromKeys
	 *
	 * @return \DOMElement
	 */
	protected function arrayToDOM($array, \DOMElement $rootElement, $charset = null, $removeNumbersFromKeys = false)
	{
		$ownerDocument = $this->ownerDocumentOf($rootElement);
		foreach ($array as $key => $value) {
			$key = (string) $key;
			if ($removeNumbersFromKeys) {
				$key = preg_replace('/[^a-z]/i', '', $key) ?? '';
			}
			$element = $ownerDocument->createElement($key);
			if ($element === false) {
				throw new PropulsionException('Unable to create XML element: ' . $key);
			}
			if (is_array($value)) {
				if (!empty($value)) {
					$element = $this->arrayToDOM($value, $element, $charset);
				}
			} elseif (is_string($value)) {
				$charset = $charset ? $charset : 'utf-8';
				if (function_exists('iconv') && strcasecmp($charset, 'utf-8') !== 0 && strcasecmp($charset, 'utf8') !== 0) {
					$convertedValue = iconv($charset, 'UTF-8', $value);
					$value = $convertedValue === false ? $value : $convertedValue;
				}
				$value = htmlspecialchars($value, ENT_COMPAT, 'UTF-8');
				$child = $ownerDocument->createCDATASection($value);
				$element->appendChild($child);
			} else {
				$stringValue = is_scalar($value) ? (string) $value : '';
				$child = $ownerDocument->createTextNode($stringValue);
				$element->appendChild($child);
			}
			$rootElement->appendChild($element);
		}

		return $rootElement;
	}

	/**
	 * Converts data from XML to an associative array.
	 *
	 * @param  string $data Source data to convert, as an XML string
	 * @return array<mixed> Converted data
	 * @throws PropulsionException if the data cannot be parsed as XML
	 */
	public function toArray($data)
	{
		$doc = new \DomDocument('1.0', 'UTF-8');
		$doc->loadXML($data);
		$element = $doc->documentElement;
		if ($element === null) {
			throw new PropulsionException('Unable to parse XML data: no root element found');
		}
		return $this->convertDOMElementToArray($element);
	}

	/**
	 * Alias for PropulsionXMLParser::toArray()
	 *
	 * @param  string $data Source data to convert, as an XML string
	 * @return array<mixed> Converted data
	 */
	public function fromXML($data)
	{
		return $this->toArray($data);
	}

	/**
	 * @param  \DOMElement $data
	 * @return array<mixed>
	 */
	protected function convertDOMElementToArray(\DOMElement $data)
	{
		$array = array();
		$elementNames = array();
		foreach ($data->childNodes as $element) {
			if (!$element instanceof \DOMElement) {
				// skip text, comment, CDATA, and other non-element nodes; only
				// elements are turned into array entries
				continue;
			}
			$name = $element->nodeName;
			if (isset($elementNames[$name])) {
				if (isset($array[$name])) {
					// change the first 'book' to 'book0'
					$array[$name . $elementNames[$name]] = $array[$name];
					unset($array[$name]);
				}
				$elementNames[$name] += 1;
				$index = $name . $elementNames[$name];
			} else {
				$index = $name;
				$elementNames[$name] = 0;
			}
			$firstChild = $element->firstChild;
			if ($element->hasChildNodes() && !$this->hasOnlyTextNodes($element)) {
				$array[$index] = $this->convertDOMElementToArray($element);
			} elseif ($firstChild !== null && $firstChild->nodeType == XML_CDATA_SECTION_NODE) {
				$array[$index] = htmlspecialchars_decode($firstChild->textContent ?? '');
			} elseif (!$element->hasChildNodes()) {
				$array[$index] = null;
			} else {
				$array[$index] = $element->textContent;
			}
		}
		return $array;
	}

	/**
	 * @param  \DOMElement $node
	 * @return boolean
	 */
	protected function hasOnlyTextNodes(\DOMElement $node)
	{
		foreach ($node->childNodes as $childNode) {
			if ($childNode->nodeType != XML_CDATA_SECTION_NODE && $childNode->nodeType != XML_TEXT_NODE) {
				return false;
			}
		}
		return true;
	}
}
