<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\Sluggable;
/**
 * Adds a slug column
 *
 * @author    Francois Zaninotto
 * @author    Massimiliano Arione
 * @version		$Revision$
 */
use Propulsion\Generator\Builder\OM\OMBuilder;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Behavior;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\Unique;
class SluggableBehavior extends Behavior
{
	// default parameters value
	/** @var array<string, string> */
	protected $parameters = array(
		'slug_column'     => 'slug',
		'slug_pattern'    => '',
		'replace_pattern' => '/\W+/', // Tip: use '/[^\\pL\\d]+/u' instead if you're in PHP5.3
		'replacement'     => '-',
		'separator'       => '-',
		'permanent'       => 'false'
	);

	protected ?OMBuilder $builder = null;

	private function requireBuilder(): OMBuilder
	{
		if ($this->builder === null) {
			throw new EngineException('No builder has been set yet; objectMethods()/queryMethods() must run before this call');
		}
		return $this->builder;
	}

	/**
	 * Non-nullable wrapper around getColumnForParameter(), since every
	 * call site here only runs once modifyTable() has already added the
	 * slug column to the table.
	 */
	private function requireColumnForParameter(string $param): Column
	{
		$column = $this->getColumnForParameter($param);
		if ($column === null) {
			throw new EngineException(sprintf("Parameter '%s' does not reference an existing column", $param));
		}
		return $column;
	}

	/**
	 * getParameter() is typed to return mixed at the Behavior base class,
	 * but this behavior's own $parameters map is declared
	 * array<string, string>, so every value obtained through it is
	 * actually a string.
	 */
	private function getStringParameter(string $name): string
	{
		$value = $this->getParameter($name);
		if (!is_string($value)) {
			throw new EngineException(sprintf("Parameter '%s' is expected to be a string", $name));
		}
		return $value;
	}

	/**
	 * Add the slug_column to the current table
	 */
	public function modifyTable(): void
	{
		$table = $this->requireTable();
		$slugColumnName = $this->getStringParameter('slug_column');
		if(!$table->hasColumn($slugColumnName)) {
			$table->addColumn(array(
				'name' => $slugColumnName,
				'type' => 'VARCHAR',
				'size' => 255
			));
			// add a unique to column
			$unique = new Unique($this->requireColumnForParameter('slug_column'));
			$unique->setName($table->getCommonName() . '_slug');
			$unique->addColumn($this->requireColumnForParameter('slug_column'));
			$table->addUnique($unique);
		}
	}

	/**
	 * Get the getter of the column of the behavior
	 *
	 * @return string The related getter, e.g. 'getSlug'
	 */
	protected function getColumnGetter()
	{
		return 'get' . $this->requireColumnForParameter('slug_column')->getPhpName();
	}

	/**
	 * Get the setter of the column of the behavior
	 *
	 * @return string The related setter, e.g. 'setSlug'
	 */
	protected function getColumnSetter()
	{
		return 'set' . $this->requireColumnForParameter('slug_column')->getPhpName();
	}

	/**
	 * Add code in AbstractObjectBuilder::preSave
	 *
	 * @return string The code to put at the hook
	 */
	public function preSave(OMBuilder $builder): string
	{
		$const = $builder->getColumnConstant($this->getColumnForParameter('slug_column'));
		$script = "
if (\$this->isColumnModified($const) && \$this->{$this->getColumnGetter()}()) {
	\$this->{$this->getColumnSetter()}(\$this->makeSlugUnique(\$this->{$this->getColumnGetter()}()));";
		if ($this->getParameter('permanent') == 'true') {
			$script .= "
} elseif (!\$this->{$this->getColumnGetter()}()) {
	\$this->{$this->getColumnSetter()}(\$this->createSlug());
}";
		} else {
			$script .= "
} else {
	\$this->{$this->getColumnSetter()}(\$this->createSlug());
}";
		}

		return $script;
	}

	public function objectMethods(OMBuilder $builder): string
	{
		$this->builder = $builder;
		$script = '';
		if ($this->getParameter('slug_column') != 'slug') {
			$this->addSlugSetter($script);
			$this->addSlugGetter($script);
		}
		$this->addCreateSlug($script);
		$this->addCreateRawSlug($script);
		$this->addCleanupSlugPart($script);
		$this->addLimitSlugSize($script);
		$this->addMakeSlugUnique($script);

		return $script;
	}

	protected function addSlugSetter(string &$script): void
	{
		$script .= "
/**
 * Wrap the setter for slug value
 *
 * @param   string \$v
 * @return  " . $this->requireTable()->getPhpName() . "
 */
public function setSlug(\$v)
{
	return \$this->" . $this->getColumnSetter() . "(\$v);
}
";
	}

	protected function addSlugGetter(string &$script): void
	{
		$script .= "
/**
 * Wrap the getter for slug value
 *
 * @return  string
 */
public function getSlug()
{
	return \$this->" . $this->getColumnGetter() . "();
}
";
	}

	protected function addCreateSlug(string &$script): void
	{
		$script .= "
/**
 * Create a unique slug based on the object
 *
 * @return string The object slug
 */
protected function createSlug()
{
	\$slug = \$this->createRawSlug();
	\$slug = \$this->limitSlugSize(\$slug);
	\$slug = \$this->makeSlugUnique(\$slug);

	return \$slug;
}
";
	}

	protected function addCreateRawSlug(string &$script): string
	{
		$pattern = $this->getStringParameter('slug_pattern');
		$script .= "
/**
 * Create the slug from the appropriate columns
 *
 * @return string
 */
protected function createRawSlug()
{
	";
		if ($pattern) {
			$script .= "return '" . str_replace(array('{', '}'), array('\' . $this->cleanupSlugPart($this->get', '()) . \''), $pattern). "';";
		} else {
			$script .= "return \$this->cleanupSlugPart(\$this->__toString());";
		}
		$script .= "
}
";
		return $script;
	}

	public function addCleanupSlugPart(string &$script): void
	{
		$script .= "
/**
 * Cleanup a string to make a slug of it
 * Removes special characters, replaces blanks with a separator, and trim it
 *
 * @param     string \$slug        the text to slugify
 * @param     string \$replacement the separator used by slug
 * @return    string               the slugified text
 */
protected static function cleanupSlugPart(\$slug, \$replacement = '" . $this->getStringParameter('replacement') . "')
{
	\$slug = (string) \$slug;
	// transliterate
	if (function_exists('iconv')) {
		\$slug = iconv('utf-8', 'us-ascii//TRANSLIT', \$slug);
	}

	// lowercase
	if (function_exists('mb_strtolower')) {
		\$slug = mb_strtolower(\$slug);
	} else {
		\$slug = strtolower(\$slug);
	}

	// remove accents resulting from OSX's iconv
	\$slug = str_replace(array('\'', '`', '^'), '', \$slug);

	// replace non letter or digits with separator
	\$slug = preg_replace('" . $this->getStringParameter('replace_pattern') . "', \$replacement, \$slug);

	// trim
	\$slug = trim(\$slug, \$replacement);

	if (empty(\$slug)) {
		return 'n-a';
	}

	return \$slug;
}
";
	}

	public function addLimitSlugSize(string &$script): void
	{
		$size = $this->requireColumnForParameter('slug_column')->getSize();
		$script .= "

/**
 * Make sure the slug is short enough to accomodate the column size
 *
 * @param	string \$slug			the slug to check
 * @param	int \$incrementReservedSpace	characters to keep free for a uniqueness suffix
 *
 * @return string						the truncated slug
 */
protected static function limitSlugSize(\$slug, \$incrementReservedSpace = 3)
{
	// check length, as suffix could put it over maximum
	if (strlen(\$slug) > ($size - \$incrementReservedSpace)) {
		\$slug = substr(\$slug, 0, $size - \$incrementReservedSpace);
	}
	return \$slug;
}
";
	}

	public function addMakeSlugUnique(string &$script): void
	{
		$script .= "

/**
 * Get the slug, ensuring its uniqueness
 *
 * @param	string \$slug			the slug to check
 * @param	string \$separator the separator used by slug
 * @param	int \$increment		suffix counter, bumped until the slug is unique
 * @return string						the unique slug
 */
protected function makeSlugUnique(\$slug, \$separator = '" . $this->getStringParameter('separator') ."', \$increment = 0)
{
	\$slug2 = empty(\$increment) ? \$slug : \$slug . \$separator . \$increment;
	\$slugAlreadyExists = " . $this->requireBuilder()->getStubQueryBuilder()->getClassname() . "::create()
		->filterBySlug(\$slug2)
		->prune(\$this)";
		// watch out: some of the columns may be hidden by the soft_delete behavior
		if ($this->requireTable()->hasBehavior('soft_delete')) {
			$script .= "
		->includeDeleted()";
		}
		$script .= "
		->count();
	if (\$slugAlreadyExists) {
		return \$this->makeSlugUnique(\$slug, \$separator, ++\$increment);
	} else {
		return \$slug2;
	}
}
";
	}

	public function queryMethods(OMBuilder $builder): string
	{
		$this->builder = $builder;
		$script = '';
		if ($this->getParameter('slug_column') != 'slug') {
			$this->addFilterBySlug($script);
		}
		$this->addFindOneBySlug($script);

		return $script;
	}

	protected function addFilterBySlug(string &$script): void
	{
		$script .= "
/**
 * Filter the query on the slug column
 *
 * @param     string \$slug The value to use as filter.
 *
 * @return    " . $this->requireBuilder()->getStubQueryBuilder()->getClassname() . " The current query, for fluid interface
 */
public function filterBySlug(\$slug)
{
	return \$this->addUsingAlias(" . $this->requireBuilder()->getColumnConstant($this->getColumnForParameter('slug_column')) . ", \$slug, Criteria::EQUAL);
}
";
	}

	protected function addFindOneBySlug(string &$script): void
	{
		$script .= "
/**
 * Find one object based on its slug
 *
 * @param     string \$slug The value to use as filter.
 * @param     PropulsionPDO \$con The optional connection object
 *
 * @return    " . $this->requireBuilder()->getStubObjectBuilder()->getClassname() . " the result, formatted by the current formatter
 */
public function findOneBySlug(\$slug, \$con = null)
{
	return \$this->filterBySlug(\$slug)->findOne(\$con);
}
";
	}

}
