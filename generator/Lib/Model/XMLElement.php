<?php

namespace Propulsion\Generator\Model;

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Generator\Config\GeneratorConfig;

/**
 * An abstract class for elements represented by XML tags (e.g. Column, Table).
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 */
abstract class XMLElement
{

	/**
	 * The name => value attributes from XML.
	 *
	 * @var        array<string, mixed>
	 */
	protected array $attributes = array();

	/**
	 * Any associated vendor-specific information objects.
	 *
	 * @var        VendorInfo[]
	 */
	protected array $vendorInfos = array();

	/**
	 * Replaces the old loadFromXML() so that we can use loadFromXML() to load the attribs into the class.
	 *
	 * @return void
	 */
	abstract protected function setupObject();

	/**
	 * This is the entry point method for loading data from XML.
	 * It calls a setupObject() method that must be implemented by the child class.
	 * @param      array<string, mixed> $attributes The attributes for the XML tag.
	 *
	 * @return void
	 */
	public function loadFromXML($attributes)
	{
		$this->attributes = array_change_key_case($attributes, CASE_LOWER);
		$this->setupObject();
	}

	/**
	 * Returns the assoc array of attributes.
	 * All attribute names (keys) are lowercase.
	 * @return     array<string, mixed>
	 */
	public function getAttributes()
	{
		return $this->attributes;
	}

	/**
	 * Gets a particular attribute by [case-insensitive] name.
	 * If attribute is not set then the $defaultValue is returned.
	 * @param      string $name The [case-insensitive] name of the attribute to lookup.
	 * @param      mixed $defaultValue The default value to use in case the attribute is not set.
	 * @return     mixed The value of the attribute or $defaultValue if not set.
	 */
	public function getAttribute($name, $defaultValue = null)
	{
		$name = strtolower($name);
		if (isset($this->attributes[$name])) {
			return $this->attributes[$name];
		} else {
			return $defaultValue;
		}
	}

	/**
	 * Converts value specified in XML to a boolean value.
	 * This is to support the default value when used w/ a boolean column.
	 * @param mixed $val
	 * @return bool
	 */
	protected function booleanValue($val)
	{
		if ($val === null) {
			return false; // null is false
		} elseif (is_bool($val)) {
			return $val;
		} elseif (is_numeric($val)) {
			return (bool) $val;
		} elseif (is_string($val)) {
			return (in_array(strtolower($val), array('true', 't', 'y', 'yes'), true) ? true : false);
		} else {
			return (bool) $val;
		}
	}

	/**
	 * Gets a particular attribute by [case-insensitive] name as a string.
	 * XML attribute values are always strings (or absent), so this narrows
	 * getAttribute()'s mixed return for the common case of a typed string
	 * property.
	 * @param      string $name The [case-insensitive] name of the attribute to lookup.
	 * @param      string|null $defaultValue The default value to use in case the attribute is not set.
	 * @return     string|null
	 */
	protected function getStringAttribute($name, $defaultValue = null)
	{
		$value = $this->getAttribute($name, $defaultValue);
		if ($value === null) {
			return null;
		}
		if (is_string($value)) {
			return $value;
		}
		return is_scalar($value) ? (string) $value : null;
	}

	/**
	 * Gets a particular attribute by [case-insensitive] name as an int or a
	 * string (e.g. a Domain size/scale value, which may be either). Returns
	 * null if the attribute is absent or isn't an int/string/numeric value.
	 * @param      string $name The [case-insensitive] name of the attribute to lookup.
	 * @return     int|string|null
	 */
	protected function getIntOrStringAttribute($name)
	{
		$value = $this->getAttribute($name);
		if ($value === null || is_int($value) || is_string($value)) {
			return $value;
		}
		return is_scalar($value) ? (string) $value : null;
	}

	/**
	 * Gets a particular attribute by [case-insensitive] name, converted via
	 * booleanValue(), defaulting to $default when the attribute is absent.
	 * @param      string $name The [case-insensitive] name of the attribute to lookup.
	 * @param      bool $default The default value to use in case the attribute is not set.
	 * @return     bool
	 */
	protected function getBooleanAttribute($name, $default = false)
	{
		$value = $this->getAttribute($name);
		if ($value === null) {
			return $default;
		}
		return $this->booleanValue($value);
	}

	/**
	 * Appends DOM elements to represent this object in XML.
	 * @param      \DOMNode $node
	 * @return void
	 */
	abstract public function appendXml(\DOMNode $node);

	/**
	 * Sets an associated VendorInfo object.
	 *
	 * @param      array<string, mixed>|VendorInfo $data VendorInfo object or XML attrib data (array)
	 * @return     VendorInfo
	 */
	public function addVendorInfo($data)
	{
		if ($data instanceof VendorInfo) {
			$vi = $data;
			$this->vendorInfos[$vi->getType()] = $vi;
			return $vi;
		} else {
			$vi = new VendorInfo();
			$vi->loadFromXML($data);
			return $this->addVendorInfo($vi); // call self w/ different param
		}
	}

	/**
	 * Gets the any associated VendorInfo object.
	 * @return     VendorInfo
	 */
	public function getVendorInfoForType(string $type)
	{
		if (isset($this->vendorInfos[$type])) {
			return $this->vendorInfos[$type];
		} else {
			// return an empty object
			return new VendorInfo($type);
		}
	}

  /**
   * Gets the GeneratorConfig object, if this element (or one of its ancestors)
   * is attached to one. Overridden by Table, Database and AppData, which are
   * the only elements ever actually attached to a GeneratorConfig.
   *
   * @return GeneratorConfig|null
   */
  public function getGeneratorConfig()
  {
    return null;
  }

  /**
   * Find the best class name for a given behavior
   * Looks in build.properties for path like propulsion.behavior.[bname].class
   * If not found, tries to autoload [Bname]Behavior
   * If no success, returns 'Behavior'
   *
   * @param  string $bname behavior name, e.g. 'timestampable'
   * @return string        behavior class name, e.g. 'TimestampableBehavior'
   */
  public function getConfiguredBehavior($bname)
  {
    if ($config = $this->getGeneratorConfig()) {
      if ($class = $config->getConfiguredBehavior($bname)) {
        return $class;
      }
    }
    // fallback: maybe the behavior is loaded or autoloaded
    $gen = new PhpNameGenerator();
    if(class_exists($class = $gen->generateName(array($bname, PhpNameGenerator::CONV_METHOD_PHPNAME)) . 'Behavior')) {
      return $class;
    }

    throw new \InvalidArgumentException(sprintf('Unknown behavior "%s"; make sure you configured the propulsion.behavior.%s.class setting in your build.properties', $bname, $bname));
  }

	/**
	 * String representation of the current object.
	 *
	 * This is an xml representation with the XML declaration removed.
	 *
	 * @see        appendXml()
	 */
	public function toString(): string
	{
		$doc = new \DOMDocument('1.0');
		$doc->formatOutput = true;
		$this->appendXml($doc);
		$xmlstr = $doc->saveXML();
		if ($xmlstr === false) {
			throw new \RuntimeException('Failed to serialize XML document.');
		}
		return trim(preg_replace('/<\?xml.*?\?>/', '', $xmlstr) ?? '');
	}

	/**
	 * Magic string method
	 * @see toString()
	 */
	public function __toString()
	{
	  return $this->toString();
	}
}
