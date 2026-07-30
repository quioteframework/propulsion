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
 * A class for holding data about a domain used in the schema.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Martin Poeschl <mpoeschl@marmot.at> (Torque)
 * @version    $Revision$
 */
use Propulsion\Generator\Exception\EngineException;
class Domain extends XMLElement
{

	/**
	 * @var        string|null The name of this domain
	 */
	private $name;

	/**
	 * @var        string|null Description for this domain.
	 */
	private $description;

	/**
	 * @var        int|string|null Size
	 */
	private $size;

	/**
	 * @var        int|string|null Scale
	 */
	private $scale;

	/**
	 * @var        string|null Propulsion type from schema
	 */
	private $propelType;

	/**
	 * @var        string|null The SQL type to use for this column
	 */
	private $sqlType;

	/**
	 * @var        ColumnDefaultValue|null A default value
	 */
	private $defaultValue;

	private Database $database;

	/**
	 * Creates a new Domain object.
	 * If this domain needs a name, it must be specified manually.
	 *
	 * @param      string|null $type Propulsion type.
	 * @param      string|null $sqlType SQL type.
	 * @param      int|string|null $size
	 * @param      int|string|null $scale
	 */
	public function __construct($type = null, $sqlType = null, $size = null, $scale = null)
	{
		$this->propelType = $type;
		$this->sqlType = ($sqlType !== null) ? $sqlType : $type;
		$this->size = $size;
		$this->scale = $scale;
	}

	/**
	 * Copy the values from current object into passed-in Domain.
	 * @param      Domain $domain Domain to copy values into.
	 */
	public function copy(Domain $domain): void
	{
		$this->defaultValue = $domain->getDefaultValue();
		$this->description = $domain->getDescription();
		$this->name = $domain->getName();
		$this->scale = $domain->getScale();
		$this->size = $domain->getSize();
		$this->sqlType = $domain->getSqlType();
		$this->propelType = $domain->getType();
	}

	/**
	 * Sets up the Domain object based on the attributes that were passed to loadFromXML().
	 * @see        parent::loadFromXML()
	 */
	protected function setupObject(): void
	{
		$schemaType = strtoupper($this->getStringAttribute("type") ?? '');
		$platform = $this->getDatabase()->getPlatform();
		if ($platform === null) {
			throw new EngineException('Cannot set up a Domain: no platform is configured for this Database.');
		}
		$this->copy($platform->getDomainForType($schemaType));

		//Name
		$this->name = $this->getStringAttribute("name");

		// Default value
		$defval = $this->getStringAttribute("defaultValue", $this->getStringAttribute("default"));
		$defExpr = $this->getStringAttribute("defaultExpr");
		if ($defval !== null) {
			$this->setDefaultValue(new ColumnDefaultValue($defval, ColumnDefaultValue::TYPE_VALUE));
		} elseif ($defExpr !== null) {
			$this->setDefaultValue(new ColumnDefaultValue($defExpr, ColumnDefaultValue::TYPE_EXPR));
		}

		$this->size = $this->getIntOrStringAttribute("size");
		$this->scale = $this->getIntOrStringAttribute("scale");
		$this->description = $this->getStringAttribute("description");
	}

	/**
	 * Sets the owning database object (if this domain is being setup via XML).
	 * @param      Database $database
	 */
	public function setDatabase(Database $database): void
	{
		$this->database = $database;
	}

	/**
	 * Gets the owning database object (if this domain was setup via XML).
	 *
	 * Most Domain instances are never attached to a Database at all (a
	 * Column's own lazily-created default Domain, or a Platform's
	 * getDomainForType() template) -- but nothing anywhere reads
	 * getDatabase() on those (verified: zero null-checks against this getter
	 * exist in the codebase), only on XML-declared `<domain>` elements, which
	 * always have setDatabase() called before setupObject() ever runs. A
	 * native (non-nullable, no-default) property type means calling this
	 * before that happens throws PHP's own "must not be accessed before
	 * initialization" error, rather than silently returning null.
	 */
	public function getDatabase(): Database
	{
		return $this->database;
	}

	/**
	 * @return     string|null Returns the description.
	 */
	public function getDescription()
	{
		return $this->description;
	}

	/**
	 * @param      string|null $description The description to set.
	 */
	public function setDescription($description): void
	{
		$this->description = $description;
	}

	/**
	 * @return     string|null Returns the name.
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * @param      string|null $name The name to set.
	 */
	public function setName($name): void
	{
		$this->name = $name;
	}

	/**
	 * @return     int|string|null Returns the scale.
	 */
	public function getScale()
	{
		return $this->scale;
	}

	/**
	 * @param      int|string|null $scale The scale to set.
	 */
	public function setScale($scale): void
	{
		$this->scale = $scale;
	}

	/**
	 * Replaces the size if the new value is not null.
	 *
	 * @param      int|string|null $value The size to set.
	 */
	public function replaceScale($value): void
	{
		if ($value !== null) {
			$this->scale = $value;
		}
	}

	/**
	 * @return     int|string|null Returns the size.
	 */
	public function getSize()
	{
		return $this->size;
	}

	/**
	 * @param      int|string|null $size The size to set.
	 */
	public function setSize($size): void
	{
		$this->size = $size;
	}

	/**
	 * Replaces the size if the new value is not null.
	 *
	 * @param      int|string|null $value The size to set.
	 */
	public function replaceSize($value): void
	{
		if ($value !== null) {
			$this->size = $value;
		}
	}

	/**
	 * @return     string|null Returns the propelType.
	 */
	public function getType()
	{
		return $this->propelType;
	}

	/**
	 * @param      string|null $propelType The PropulsionTypes type to set.
	 */
	public function setType($propelType): void
	{
		$this->propelType = $propelType;
	}

	/**
	 * Replaces the type if the new value is not null.
	 *
	 * @param      string|null $value The tyep to set.
	 */
	public function replaceType($value): void
	{
		if ($value !== null) {
			$this->propelType = $value;
		}
	}

	/**
	 * Gets the default value object.
	 * @return     ColumnDefaultValue|null The default value object for this domain.
	 */
	public function getDefaultValue()
	{
		return $this->defaultValue;
	}

	/**
	 * Gets the default value, type-casted for use in PHP OM.
	 * @return     mixed
	 * @see        getDefaultValue()
	 */
	public function getPhpDefaultValue()
	{
		if ($this->defaultValue === null) {
			return null;
		} else {
			if ($this->defaultValue->isExpression()) {
				throw new EngineException("Cannot get PHP version of default value for default value EXPRESSION.");
			}
			if ($this->propelType === PropulsionTypes::BOOLEAN || $this->propelType === PropulsionTypes::BOOLEAN_EMU) {
				return $this->booleanValue($this->defaultValue->getValue());
			} else {
				return $this->defaultValue->getValue();
			}
		}
	}

	/**
	 * @param      ColumnDefaultValue $value The column default value to set.
	 */
	public function setDefaultValue(ColumnDefaultValue $value): void
	{
		$this->defaultValue = $value;
	}

	/**
	 * Replaces the default value if the new value is not null.
	 *
	 * @param      ColumnDefaultValue $value The defualt value object
	 */
	public function replaceDefaultValue(?ColumnDefaultValue $value = null): void
	{
		if ($value !== null) {
			$this->defaultValue = $value;
		}
	}

	/**
	 * @return     string|null Returns the sqlType.
	 */
	public function getSqlType()
	{
		return $this->sqlType;
	}

	/**
	 * @param      string|null $sqlType The sqlType to set.
	 */
	public function setSqlType($sqlType): void
	{
		$this->sqlType = $sqlType;
	}

	/**
	 * Replaces the SQL type if the new value is not null.
	 * @param      string|null $sqlType The native SQL type to use for this domain.
	 */
	public function replaceSqlType($sqlType): void
	{
		if ($sqlType !== null) {
			$this->sqlType = $sqlType;
		}
	}

	/**
	 * Return the size and scale in brackets for use in an sql schema.
	 *
	 * @return     string Size and scale or an empty String if there are no values
	 *         available.
	 */
	public function printSize()
	{
		if ($this->size !== null && $this->scale !== null)  {
			return '(' . $this->size . ',' . $this->scale . ')';
		} elseif ($this->size !== null) {
			return '(' . $this->size . ')';
		} else {
			return "";
		}
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

		$domainNode = $node->appendChild($doc->createElement('domain'));
		$domainNode->setAttribute('type', $this->getType() ?? '');
		$domainNode->setAttribute('name', $this->getName() ?? '');

		if ($this->sqlType !== $this->getType()) {
			$domainNode->setAttribute('sqlType', $this->sqlType ?? '');
		}

		$def = $this->getDefaultValue();
		if ($def) {
			if ($def->isExpression()) {
				$domainNode->setAttribute('defaultExpr', $def->getValue());
			} else {
				$domainNode->setAttribute('defaultValue', $def->getValue());
			}
		}

		if ($this->size) {
			$domainNode->setAttribute('size', (string) $this->size);
		}

		if ($this->scale) {
			$domainNode->setAttribute('scale', (string) $this->scale);
		}

		if ($this->description) {
			$domainNode->setAttribute('description', $this->description);
		}
	}

}
