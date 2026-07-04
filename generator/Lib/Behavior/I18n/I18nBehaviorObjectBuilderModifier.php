<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\I18n;

/**
 * Allows translation of text columns through transparent one-to-many relationship.
 * Modifier for the object builder.
 *
 * @author     François Zaninotto
 * @version    $Revision$
 * @package    propel.generator.behavior.i18n
 */

 use Propulsion\Generator\Model\Column;
 use Propulsion\Generator\Model\PropulsionTypes;
class I18nBehaviorObjectBuilderModifier
{
	protected $behavior, $table, $builder;

	public function __construct($behavior)
	{
		$this->behavior = $behavior;
		$this->table = $behavior->getTable();
	}

	public function postDelete($builder)
	{
		$this->builder = $builder;
		if (!$builder->getPlatform()->supportsNativeDeleteTrigger() && !$builder->getBuildProperty('emulateForeignKeyConstraints')) {
			$i18nTable = $this->behavior->getI18nTable();
			return $this->behavior->renderTemplate('objectPostDelete', array(
				'i18nQueryName'    => $builder->getNewStubQueryBuilder($i18nTable)->getClassname(),
				'objectClassname' => $builder->getNewStubObjectBuilder($this->behavior->getTable())->getClassname(),
			));
		}
	}

	public function objectAttributes($builder)
	{
		return $this->behavior->renderTemplate('objectAttributes', array(
			'defaultLocale'   => $this->behavior->getDefaultLocale(),
			'objectClassname' => $builder->getNewStubObjectBuilder($this->behavior->getI18nTable())->getClassname(),
		));
	}

	public function objectClearReferences($builder)
	{
		return $this->behavior->renderTemplate('objectClearReferences', array(
			'defaultLocale'   => $this->behavior->getDefaultLocale(),
		));
	}

	public function objectMethods($builder)
	{
		$this->builder = $builder;
		$script = '';
		$script .= $this->addSetLocale();
		$script .= $this->addGetLocale();
		if ($alias = $this->behavior->getParameter('locale_alias')) {
			$script .= $this->addGetLocaleAlias($alias);
			$script .= $this->addSetLocaleAlias($alias);
		}
		$script .= $this->addGetTranslation();
		$script .= $this->addRemoveTranslation();
		$script .= $this->addGetCurrentTranslation();
		foreach ($this->behavior->getI18nColumns() as $column) {
			$script .= $this->addTranslatedColumnGetter($column);
			$script .= $this->addTranslatedColumnSetter($column);
		}

		return $script;
	}

	protected function addSetLocale()
	{
		return $this->behavior->renderTemplate('objectSetLocale', array(
			'objectClassname' => $this->builder->getStubObjectBuilder($this->table)->getClassname(),
			'defaultLocale'    => $this->behavior->getDefaultLocale(),
		));
	}

	protected function addGetLocale()
	{
		return $this->behavior->renderTemplate('objectGetLocale');
	}

	protected function addSetLocaleAlias($alias)
	{
		return $this->behavior->renderTemplate('objectSetLocaleAlias', array(
			'objectClassname' => $this->builder->getStubObjectBuilder($this->table)->getClassname(),
			'defaultLocale'    => $this->behavior->getDefaultLocale(),
			'alias'            => ucfirst($alias),
		));
	}

	protected function addGetLocaleAlias($alias)
	{
		return $this->behavior->renderTemplate('objectGetLocaleAlias', array(
			'alias' => ucfirst($alias),
		));
	}

	protected function addGetTranslation()
	{
		$i18nTable = $this->behavior->getI18nTable();
		$fk = $this->behavior->getI18nForeignKey();
		return $this->behavior->renderTemplate('objectGetTranslation', array(
			'i18nTablePhpName' => $this->builder->getNewStubObjectBuilder($i18nTable)->getClassname(),
			'defaultLocale'    => $this->behavior->getDefaultLocale(),
			'i18nListVariable' => $this->builder->getRefFKCollVarName($fk),
			'localeColumnName' => $this->behavior->getLocaleColumn()->getPhpName(),
			'i18nQueryName'    => $this->builder->getNewStubQueryBuilder($i18nTable)->getClassname(),
			'i18nSetterMethod' => $this->builder->getRefFKPhpNameAffix($fk, $plural = false),
		));
	}

	protected function addRemoveTranslation()
	{
		$i18nTable = $this->behavior->getI18nTable();
		$fk = $this->behavior->getI18nForeignKey();
		return $this->behavior->renderTemplate('objectRemoveTranslation', array(
			'objectClassname' => $this->builder->getStubObjectBuilder($this->table)->getClassname(),
			'defaultLocale'    => $this->behavior->getDefaultLocale(),
			'i18nQueryName'    => $this->builder->getNewStubQueryBuilder($i18nTable)->getClassname(),
			'i18nCollection'   => $this->builder->getRefFKCollVarName($fk),
			'localeColumnName' => $this->behavior->getLocaleColumn()->getPhpName(),
		));
	}

	protected function addGetCurrentTranslation()
	{
		return $this->behavior->renderTemplate('objectGetCurrentTranslation', array(
			'i18nTablePhpName' => $this->builder->getNewStubObjectBuilder($this->behavior->getI18nTable())->getClassname(),
		));
	}

	// FIXME: the connection used by getCurrentTranslation in the generated code
	// cannot be specified by the user
	protected function addTranslatedColumnGetter(Column $column)
	{
		$objectBuilder = $this->builder->getNewObjectBuilder($this->behavior->getI18nTable());
		$comment = '';
		$functionStatement = '';
		if ($column->getType() === PropulsionTypes::DATE || $column->getType() === PropulsionTypes::TIME || $column->getType() === PropulsionTypes::TIMESTAMP) {
			$objectBuilder->addTemporalAccessorComment($comment, $column);
			$objectBuilder->addTemporalAccessorOpen($functionStatement, $column);
		} else {
			$objectBuilder->addDefaultAccessorComment($comment, $column);
			$objectBuilder->addDefaultAccessorOpen($functionStatement, $column);
		}
		$comment = preg_replace('/^\t/m', '', $comment);
		$functionStatement = preg_replace('/^\t/m', '', $functionStatement);
		preg_match_all('/\$[a-z]+/i', $functionStatement, $params);
		return $this->behavior->renderTemplate('objectTranslatedColumnGetter', array(
			'comment'           => $comment,
			'functionStatement' => $functionStatement,
			'columnPhpName'     => $column->getPhpName(),
			'params'            => implode(', ', $params[0]),
		));
	}

	// FIXME: the connection used by getCurrentTranslation in the generated code
	// cannot be specified by the user
	protected function addTranslatedColumnSetter(Column $column)
	{
		$i18nTablePhpName = $this->builder->getNewStubObjectBuilder($this->behavior->getI18nTable())->getClassname();
		$tablePhpName = $this->builder->getStubObjectBuilder()->getClassname();
		$objectBuilder = $this->builder->getNewObjectBuilder($this->behavior->getI18nTable());
		$comment = '';
		$functionStatement = '';
		if ($column->getType() === PropulsionTypes::DATE || $column->getType() === PropulsionTypes::TIME || $column->getType() === PropulsionTypes::TIMESTAMP) {
			$objectBuilder->addTemporalMutatorComment($comment, $column);
			$objectBuilder->addMutatorOpenOpen($functionStatement, $column);
		} else {
			$objectBuilder->addMutatorComment($comment, $column);
			$objectBuilder->addMutatorOpenOpen($functionStatement, $column);
		}
		$comment = preg_replace('/^\t/m', '', $comment);
		// addMutatorComment()/addTemporalMutatorComment() were called on the i18n
		// table's own ObjectBuilder (needed so getClassname() etc. reflect the i18n
		// table's columns), so both the doc comment's "@return" line and (more
		// importantly, since this is now a real PHP return type declaration, not
		// just a docblock under PHP5) the actual method signature's return type
		// say the i18n table's classname (e.g. BaseFooI18n) -- but the composed
		// translated-column setter actually returns $this, the *outer* table's
		// object (e.g. Foo). A plain string replace of the classname fixes both;
		// the old PHP5-era '@return     '-prefixed replace only patched the
		// (then purely cosmetic) docblock text and never touched the signature,
		// which was harmless when nothing was strictly typed but throws a hard
		// TypeError ("Return value must be of type BaseFooI18n, Foo returned")
		// now that addMutatorOpenOpen() emits a real ": $returnType" hint.
		$comment = str_replace($i18nTablePhpName, $tablePhpName, $comment);
		$functionStatement = preg_replace('/^\t/m', '', $functionStatement);
		$functionStatement = str_replace($i18nTablePhpName, $tablePhpName, $functionStatement);
		preg_match_all('/\$[a-z]+/i', $functionStatement, $params);
		return $this->behavior->renderTemplate('objectTranslatedColumnSetter', array(
			'comment'           => $comment,
			'functionStatement' => $functionStatement,
			'columnPhpName'     => $column->getPhpName(),
			'params'            => implode(', ', $params[0]),
		));
	}

	public function objectFilter(&$script, $builder)
	{
		$i18nTable = $this->behavior->getI18nTable();
		$i18nTablePhpName = $this->builder->getNewStubObjectBuilder($i18nTable)->getClassname();
		$localeColumnName = $this->behavior->getLocaleColumn()->getPhpName();
		$pattern = '/public function add' . $i18nTablePhpName . '.*[\r\n]\s*\{/';
		$addition = "
		if (\$l && \$locale = \$l->get$localeColumnName()) {
			\$this->set$localeColumnName(\$locale);
			\$this->currentTranslations[\$locale] = \$l;
		}";
		$replacement = "\$0$addition";
		$script = preg_replace($pattern, $replacement, $script);
	}

}