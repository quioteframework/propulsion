<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\I18n;

use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Builder\OM\QueryBuilder;

/**
 * Allows translation of text columns through transparent one-to-many relationship.
 * Modifier for the query builder.
 *
 * @author     François Zaninotto
 * @version    $Revision$
 */
class I18nBehaviorQueryBuilderModifier
{
	protected I18nBehavior $behavior;
	protected Table $table;
	protected QueryBuilder $builder;

	public function __construct(I18nBehavior $behavior)
	{
		$this->behavior = $behavior;
		$this->table = $behavior->getTable();
	}

	public function queryMethods(QueryBuilder $builder): string
	{
		$this->builder = $builder;
		$script = '';
		$script .= $this->addJoinI18n();
		$script .= $this->addJoinWithI18n();
		$script .= $this->addUseI18nQuery();

		return $script;
	}

	protected function addJoinI18n(): string
	{
		$fk = $this->behavior->getI18nForeignKey();
		return $this->behavior->renderTemplate('queryJoinI18n', array(
			'queryClass'       => $this->builder->getStubQueryBuilder()->getClassname(),
			'defaultLocale'    => $this->behavior->getDefaultLocale(),
			'i18nRelationName' => $this->builder->getRefFKPhpNameAffix($fk),
			'localeColumn'     => $this->behavior->getLocaleColumn()->getPhpName(),
		));
	}

	protected function addJoinWithI18n(): string
	{
		$fk = $this->behavior->getI18nForeignKey();
		return $this->behavior->renderTemplate('queryJoinWithI18n', array(
			'queryClass'       => $this->builder->getStubQueryBuilder()->getClassname(),
			'defaultLocale'    => $this->behavior->getDefaultLocale(),
			'i18nRelationName' => $this->builder->getRefFKPhpNameAffix($fk),
		));
	}

	protected function addUseI18nQuery(): string
	{
		$i18nTable = $this->behavior->getI18nTable();
		$fk = $this->behavior->getI18nForeignKey();
		return $this->behavior->renderTemplate('queryUseI18nQuery', array(
			'queryClass'           => $this->builder->getNewStubQueryBuilder($i18nTable)->getClassname(),
			'namespacedQueryClass' => $this->builder->getNewStubQueryBuilder($i18nTable)->getFullyQualifiedClassname(),
			'defaultLocale'        => $this->behavior->getDefaultLocale(),
			'i18nRelationName'     => $this->builder->getRefFKPhpNameAffix($fk),
			'localeColumn'         => $this->behavior->getLocaleColumn()->getPhpName(),
		));
	}

}