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
			throw new SchemaException(sprintf('Unable to read XML schema file "%s"', $xmlFile));
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
	 * @param      array<string, string> $attributes The specified or defaulted attributes.
	 */
	public function startElement($parser, $name, $attributes): void
	{
	  $parentTag = $this->peekCurrentSchemaTag();

	  if ($parentTag === false) {

				switch($name) {
					case "database":
						if ($this->isExternalSchema()) {
							$this->currentPackage = $attributes["package"] ?? $this->defaultPackage;
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
					$xmlFile = $attributes["filename"] ?? '';
					if ($xmlFile === '') {
						throw new SchemaException('Missing "filename" attribute on <external-schema> tag');
					}

					// "referenceOnly" attribute is valid in the main schema XML file only,
					// and it's ignored in the nested external-schemas
					if (!$this->isExternalSchema()) {
						$isForRefOnly = $attributes["referenceOnly"] ?? null;
						$this->isForReferenceOnly = ($isForRefOnly !== null ? (strtolower($isForRefOnly) === "true") : true); // defaults to TRUE
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
				  $this->requireCurrDB()->addDomain($attributes);
			  break;

				case "table":
					$this->currTable = $this->requireCurrDB()->addTable($attributes);
					if ($this->isExternalSchema()) {
						$this->currTable->setForReferenceOnly($this->isForReferenceOnly ?? true);
						$this->currTable->setPackage($this->currentPackage);
					}
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrDB()->addVendorInfo($attributes);
				break;

				case "behavior":
				  $this->currBehavior = $this->requireCurrDB()->addBehavior($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}

		} elseif  ($parentTag == "table") {

			switch($name) {
				case "column":
					$this->currColumn = $this->requireCurrTable()->addColumn($attributes);
				break;

				case "foreign-key":
					$this->currFK = $this->requireCurrTable()->addForeignKey($attributes);
				break;

				case "index":
					$this->currIndex = $this->requireCurrTable()->addIndex($attributes);
				break;

				case "unique":
					$this->currUnique = $this->requireCurrTable()->addUnique($attributes);
				break;

				case "exclusion":
					$this->currExclusion = $this->requireCurrTable()->addExclusion($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrTable()->addVendorInfo($attributes);
				break;

	  		case "validator":
				  $this->currValidator = $this->requireCurrTable()->addValidator($attributes);
	  		break;

	  		case "id-method-parameter":
					$this->requireCurrTable()->addIdMethodParameter($attributes);
				break;

				case "behavior":
				  $this->currBehavior = $this->requireCurrTable()->addBehavior($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}

		} elseif  ($parentTag == "column") {

			switch($name) {
				case "inheritance":
					$this->requireCurrColumn()->addInheritance($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrColumn()->addVendorInfo($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}

		} elseif ($parentTag == "foreign-key") {

			switch($name) {
				case "reference":
					$this->requireCurrFK()->addReference($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrFK()->addVendorInfo($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}

		} elseif  ($parentTag == "index") {

			switch($name) {
				case "index-column":
					$this->requireCurrIndex()->addColumn($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrIndex()->addVendorInfo($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}

		} elseif ($parentTag == "unique") {

			switch($name) {
				case "unique-column":
					$this->requireCurrUnique()->addColumn($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrUnique()->addVendorInfo($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}
		} elseif ($parentTag == "exclusion") {

			switch($name) {
				case "exclusion-column":
					$this->requireCurrExclusion()->addColumn($attributes);
				break;

				case "vendor":
					$this->currVendorObject = $this->requireCurrExclusion()->addVendorInfo($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}
		} elseif ($parentTag == "behavior") {

			switch($name) {
				case "parameter":
					$this->requireCurrBehavior()->addParameter($attributes);
				break;

				default:
					$this->_throwInvalidTagException($parser, $name);
			}
		} elseif ($parentTag == "validator") {
			switch($name) {
				case "rule":
					$this->requireCurrValidator()->addRule($attributes);
				break;
				default:
					$this->_throwInvalidTagException($parser, $name);
			}
		} elseif ($parentTag == "vendor") {

			switch($name) {
				case "parameter":
					$this->requireCurrVendorObject()->addParameter($attributes);
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

	private function requireCurrDB(): Database
	{
		if ($this->currDB === null) {
			throw new SchemaException('No <database> element is currently open.');
		}

		return $this->currDB;
	}

	private function requireCurrTable(): Table
	{
		if ($this->currTable === null) {
			throw new SchemaException('No <table> element is currently open.');
		}

		return $this->currTable;
	}

	private function requireCurrColumn(): Column
	{
		if ($this->currColumn === null) {
			throw new SchemaException('No <column> element is currently open.');
		}

		return $this->currColumn;
	}

	private function requireCurrFK(): ForeignKey
	{
		if ($this->currFK === null) {
			throw new SchemaException('No <foreign-key> element is currently open.');
		}

		return $this->currFK;
	}

	private function requireCurrIndex(): Index
	{
		if ($this->currIndex === null) {
			throw new SchemaException('No <index> element is currently open.');
		}

		return $this->currIndex;
	}

	private function requireCurrUnique(): Unique
	{
		if ($this->currUnique === null) {
			throw new SchemaException('No <unique> element is currently open.');
		}

		return $this->currUnique;
	}

	private function requireCurrExclusion(): Exclusion
	{
		if ($this->currExclusion === null) {
			throw new SchemaException('No <exclusion> element is currently open.');
		}

		return $this->currExclusion;
	}

	private function requireCurrBehavior(): Behavior
	{
		if ($this->currBehavior === null) {
			throw new SchemaException('No <behavior> element is currently open.');
		}

		return $this->currBehavior;
	}

	private function requireCurrValidator(): Validator
	{
		if ($this->currValidator === null) {
			throw new SchemaException('No <validator> element is currently open.');
		}

		return $this->currValidator;
	}

	private function requireCurrVendorObject(): VendorInfo
	{
		if ($this->currVendorObject === null) {
			throw new SchemaException('No <vendor> element is currently open.');
		}

		return $this->currVendorObject;
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

	/**
	 * The path of the schema file whose tags are currently being tracked
	 * (the most recently pushed entry in $schemasTagsStack).
	 */
	private function currentSchemaFile(): string
	{
		$file = array_key_last($this->schemasTagsStack);
		if ($file === null) {
			throw new SchemaException('No schema file is currently being parsed.');
		}

		return $file;
	}

	protected function peekCurrentSchemaTag(): string|false
	{
		return end($this->schemasTagsStack[$this->currentSchemaFile()]);
	}

	protected function popCurrentSchemaTag(): void
	{
		array_pop($this->schemasTagsStack[$this->currentSchemaFile()]);
	}

	protected function pushCurrentSchemaTag(string $tag): void
	{
		$this->schemasTagsStack[$this->currentSchemaFile()][] = $tag;
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
}
