<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Propulsion\Generator\Builder\Util;

/**
 * A class that is used to parse an input xml schema file and creates an AppData
 * PHP object.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Leon Messerschmidt <leon@opticode.co.za> (Torque)
 * @author     Jason van Zyl <jvanzyl@apache.org> (Torque)
 * @author     Martin Poeschl <mpoeschl@marmot.at> (Torque)
 * @author     Daniel Rall <dlr@collab.net> (Torque)
 * @version    $Revision$
 */

 use Propulsion\Generator\Platform\PropulsionPlatformInterface;
 use Propulsion\Generator\Model\AppData;
 use Propulsion\Generator\Model\Database;
 use Propulsion\Generator\Model\Table;
 use Propulsion\Generator\Model\Column;
 use Propulsion\Generator\Model\ForeignKey;
 use Propulsion\Generator\Model\Index;
 use Propulsion\Generator\Model\Unique;
 use Propulsion\Generator\Model\Exclusion;
 use Propulsion\Generator\Model\Validator;
 use Propulsion\Generator\Model\Behavior;
 use Propulsion\Generator\Model\VendorInfo;
 use Propulsion\Generator\Config\GeneratorConfig;
 use Propulsion\Generator\Exception\SchemaException;
class XmlToAppData
{

	/** enables debug output */
	const DEBUG = false;

	private AppData $app;
	private ?Database $currDB = null;
	private ?Table $currTable = null;
	private ?Column $currColumn = null;
	private ?ForeignKey $currFK = null;
	private ?Index $currIndex = null;
	private ?Unique $currUnique = null;
	private ?Exclusion $currExclusion = null;
	private ?Validator $currValidator = null;
	private ?Behavior $currBehavior = null;
	private ?VendorInfo $currVendorObject = null;

	private ?bool $isForReferenceOnly = null;
	private ?string $currentPackage = null;
	private ?string $currentXmlFile = null;
	private ?string $defaultPackage;

	private string $encoding;

	/* two-dimensional array,
		first dimension is for schemas(key is the path to the schema file),
		second is for tags within the schema */
	/** @var array<string, list<string>> */
	private array $schemasTagsStack = array();

	/**
	 * Creates a new instance for the specified database type.
	 *
	 * @param      PropulsionPlatformInterface $defaultPlatform The default database platform for the application.
	 * @param      string $defaultPackage the default PHP package used for the om
	 * @param      string $encoding The database encoding.
	 */
	public function __construct(?PropulsionPlatformInterface $defaultPlatform = null, ?string $defaultPackage = null, string $encoding = 'iso-8859-1')
	{
		$this->app = new AppData($defaultPlatform);
		$this->defaultPackage = $defaultPackage;
		$this->encoding = $encoding;
	}

	/**
	 * Set the AppData generator configuration
	 *
	 * @param GeneratorConfig $generatorConfig
	 */
	public function setGeneratorConfig(GeneratorConfig $generatorConfig): void
	{
		$this->app->setGeneratorConfig($generatorConfig);
	}

	/**
	 * Parses a XML input file and returns a newly created and
	 * populated AppData structure.
	 *
	 * @param      string $xmlFile The input file to parse.
	 * @return     AppData populated by <code>xmlFile</code>.
	 */
	public function parseFile(string $xmlFile): AppData
	{
		// we don't want infinite recursion
		if ($this->isAlreadyParsed($xmlFile)) {
			return $this->app;
		}

		$xmlString = file_get_contents($xmlFile);
		if ($xmlString === false) {
			throw new SchemaException(sprintf('Unable to read schema file "%s"', $xmlFile));
		}

		return $this->parseString($xmlString, $xmlFile);
	}

	/**
	 * Parses a XML input string and returns a newly created and
	 * populated AppData structure.
	 *
	 * @param      string $xmlString The input string to parse.
	 * @param      string $xmlFile The input file name.
	 * @return     AppData populated by <code>xmlFile</code>.
	 */
	public function parseString(string $xmlString, ?string $xmlFile = null): AppData
	{
		$xmlFile ??= '';
		// we don't want infinite recursion
		if ($this->isAlreadyParsed($xmlFile)) {
			return $this->app;
		}
		// store current schema file path
		$this->schemasTagsStack[$xmlFile] = array();
		$this->currentXmlFile = $xmlFile;

		$parser = xml_parser_create($this->encoding);
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
		xml_set_element_handler($parser, [$this, 'startElement'], [$this, 'endElement']);
		if (!xml_parse($parser, $xmlString)) {
			throw new \Exception(sprintf("XML error: %s at line %d",
				xml_error_string(xml_get_error_code($parser)),
				xml_get_current_line_number($parser))
			);
		}
		array_pop($this->schemasTagsStack);

		return $this->app;
	}

	/**
	 * Handles opening elements of the xml file.
	 *
	 * @param      \XMLParser $parser The XML parser instance.
	 * @param      string $name The name of the element.
	 * @param      array<string, mixed> $attributes The specified or defaulted attributes.
	 */
	public function startElement($parser, $name, $attributes): void
	{
	  $parentTag = $this->peekCurrentSchemaTag();

	  if ($parentTag === false) {

				switch($name) {
					case "database":
						if ($this->isExternalSchema()) {
							$package = $attributes["package"] ?? null;
							$this->currentPackage = is_string($package) ? $package : $this->defaultPackage;
						} else {
							$this->currDB = $this->app->addDatabase($attributes);
						}
					break;

					default:
						$this->_throwInvalidTagException($parser, $name);
				}

		} elseif  ($parentTag == "database") {

			switch($name) {

				case "external-schema":
					$filename = $attributes["filename"] ?? null;
					if (!is_string($filename)) {
						throw new SchemaException('Missing required "filename" attribute on <external-schema> element');
					}
					$xmlFile = $filename;

					// "referenceOnly" attribute is valid in the main schema XML file only,
					// and it's ignored in the nested external-schemas
					if (!$this->isExternalSchema()) {
						$isForRefOnly = $attributes["referenceOnly"] ?? null;
						$this->isForReferenceOnly = (is_string($isForRefOnly) ? (strtolower($isForRefOnly) === "true") : true); // defaults to TRUE
					}

					if ($xmlFile[0] != '/') {
						$resolved = realpath(dirname($this->requireCurrentXmlFile()) . DIRECTORY_SEPARATOR . $xmlFile);
						if ($resolved === false || !file_exists($resolved)) {
							throw new SchemaException(sprintf('Unknown include external "%s"', $xmlFile));
						}
						$xmlFile = $resolved;
					}

					$this->parseFile($xmlFile);
				break;

  		  case "domain":
				  $this->requireCurrentDatabase()->addDomain($attributes);
			  break;

				case "table":
					$this->currTable = $this->requireCurrentDatabase()->addTable($attributes);
					if ($this->isExternalSchema()) {
						$this->currTable->setForReferenceOnly($this->isForReferenceOnly ?? true);
						$this->currTable->setPackage($this->currentPackage);
					}
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrentDatabase()->addVendorInfo($attributes);
				break;

				case "behavior":
				  $this->currBehavior = $this->requireCurrentDatabase()->addBehavior($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}

		} elseif  ($parentTag == "table") {

			switch($name) {
				case "column":
					$this->currColumn = $this->requireCurrentTable()->addColumn($attributes);
				break;

				case "foreign-key":
					$this->currFK = $this->requireCurrentTable()->addForeignKey($attributes);
				break;

				case "index":
					$this->currIndex = $this->requireCurrentTable()->addIndex($attributes);
				break;

				case "unique":
					$this->currUnique = $this->requireCurrentTable()->addUnique($attributes);
				break;

				case "exclusion":
					$this->currExclusion = $this->requireCurrentTable()->addExclusion($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrentTable()->addVendorInfo($attributes);
				break;

	  		case "validator":
				  $this->currValidator = $this->requireCurrentTable()->addValidator($attributes);
	  		break;

	  		case "id-method-parameter":
					$this->requireCurrentTable()->addIdMethodParameter($attributes);
				break;

				case "behavior":
				  $this->currBehavior = $this->requireCurrentTable()->addBehavior($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}

		} elseif  ($parentTag == "column") {

			switch($name) {
				case "inheritance":
					$this->requireCurrentColumn()->addInheritance($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrentColumn()->addVendorInfo($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}

		} elseif ($parentTag == "foreign-key") {

			switch($name) {
				case "reference":
					$this->requireCurrentForeignKey()->addReference($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrentForeignKey()->addVendorInfo($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}

		} elseif  ($parentTag == "index") {

			switch($name) {
				case "index-column":
					$this->requireCurrentIndex()->addColumn($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrentIndex()->addVendorInfo($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}

		} elseif ($parentTag == "unique") {

			switch($name) {
				case "unique-column":
					$this->requireCurrentUnique()->addColumn($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrentUnique()->addVendorInfo($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}
		} elseif ($parentTag == "exclusion") {

			switch($name) {
				case "exclusion-column":
					$this->requireCurrentExclusion()->addColumn($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrentExclusion()->addVendorInfo($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}
		} elseif ($parentTag == "behavior") {

			switch($name) {
				case "parameter":
					$this->requireCurrentBehavior()->addParameter($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}
		} elseif ($parentTag == "validator") {
			switch($name) {
				case "rule":
					$this->requireCurrentValidator()->addRule($attributes);
				break;
				default:
					$this->_throwInvalidTagException($parser, $name);
			}
		} elseif ($parentTag == "vendor") {

			switch($name) {
				case "parameter":
					$this->requireCurrentVendorObject()->addParameter($attributes);
				break;
				default:
					$this->_throwInvalidTagException($parser, $name);
			}

		} else {
			// it must be an invalid tag
			$this->_throwInvalidTagException($parser, $name);
		}

		$this->pushCurrentSchemaTag($name);
	}

	/**
	 * @param      \XMLParser $parser The XML parser instance.
	 * @param      string $tag_name The name of the unexpected tag.
	 */
	function _throwInvalidTagException($parser, string $tag_name): never
	{
		$location = '';
		if ($this->currentXmlFile !== null) {
			$location .= sprintf('file %s,', $this->currentXmlFile);
		}
		$location .= sprintf('line %d', xml_get_current_line_number($parser));
		if ($col = xml_get_current_column_number($parser)) {
			$location .= sprintf(', column %d', $col);
		}
		throw new SchemaException(sprintf('Unexpected tag <%s> in %s', $tag_name, $location));
	}

	/**
	 * Handles closing elements of the xml file.
	 *
	 * @param      \XMLParser $parser The XML parser instance.
	 * @param      string $name The name of the element.
	 */
	public function endElement($parser, $name): void
	{
		$this->popCurrentSchemaTag();
	}

	protected function peekCurrentSchemaTag(): string|false
	{
		return end($this->schemasTagsStack[$this->currentSchemaKey()]);
	}

	protected function popCurrentSchemaTag(): void
	{
		array_pop($this->schemasTagsStack[$this->currentSchemaKey()]);
	}

	protected function pushCurrentSchemaTag(string $tag): void
	{
		$this->schemasTagsStack[$this->currentSchemaKey()][] = $tag;
	}

	/**
	 * Returns the key (schema file path) of the schema currently being
	 * parsed, i.e. the top of the nested-parsing stack.
	 */
	private function currentSchemaKey(): string
	{
		$keys = array_keys($this->schemasTagsStack);
		$key = end($keys);
		if ($key === false) {
			throw new SchemaException('No schema file is currently being parsed');
		}
		return $key;
	}

	protected function isExternalSchema(): bool
	{
		return count($this->schemasTagsStack) > 1;
	}

	protected function isAlreadyParsed(string $filePath): bool
	{
		return isset($this->schemasTagsStack[$filePath]);
	}

	private function requireCurrentXmlFile(): string
	{
		if ($this->currentXmlFile === null) {
			throw new SchemaException('No current schema file is being parsed');
		}
		return $this->currentXmlFile;
	}

	private function requireCurrentDatabase(): Database
	{
		if ($this->currDB === null) {
			throw new SchemaException('Encountered a <database> child element without an enclosing <database> element');
		}
		return $this->currDB;
	}

	private function requireCurrentTable(): Table
	{
		if ($this->currTable === null) {
			throw new SchemaException('Encountered a <table> child element without an enclosing <table> element');
		}
		return $this->currTable;
	}

	private function requireCurrentColumn(): Column
	{
		if ($this->currColumn === null) {
			throw new SchemaException('Encountered a <column> child element without an enclosing <column> element');
		}
		return $this->currColumn;
	}

	private function requireCurrentForeignKey(): ForeignKey
	{
		if ($this->currFK === null) {
			throw new SchemaException('Encountered a <foreign-key> child element without an enclosing <foreign-key> element');
		}
		return $this->currFK;
	}

	private function requireCurrentIndex(): Index
	{
		if ($this->currIndex === null) {
			throw new SchemaException('Encountered an <index> child element without an enclosing <index> element');
		}
		return $this->currIndex;
	}

	private function requireCurrentUnique(): Unique
	{
		if ($this->currUnique === null) {
			throw new SchemaException('Encountered a <unique> child element without an enclosing <unique> element');
		}
		return $this->currUnique;
	}

	private function requireCurrentExclusion(): Exclusion
	{
		if ($this->currExclusion === null) {
			throw new SchemaException('Encountered an <exclusion> child element without an enclosing <exclusion> element');
		}
		return $this->currExclusion;
	}

	private function requireCurrentValidator(): Validator
	{
		if ($this->currValidator === null) {
			throw new SchemaException('Encountered a <validator> child element without an enclosing <validator> element');
		}
		return $this->currValidator;
	}

	private function requireCurrentBehavior(): Behavior
	{
		if ($this->currBehavior === null) {
			throw new SchemaException('Encountered a <behavior> child element without an enclosing <behavior> element');
		}
		return $this->currBehavior;
	}

	private function requireCurrentVendorObject(): VendorInfo
	{
		if ($this->currVendorObject === null) {
			throw new SchemaException('Encountered a <vendor> child element without an enclosing <vendor> element');
		}
		return $this->currVendorObject;
	}
}
