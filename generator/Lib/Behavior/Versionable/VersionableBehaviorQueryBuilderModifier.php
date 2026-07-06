<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\Versionable;

/**
 * Behavior to add versionable columns and abilities
 *
 * @author     François Zaninotto
 */
class VersionableBehaviorQueryBuilderModifier
{
  protected VersionableBehavior $behavior;
  protected \Propulsion\Generator\Model\Table $table;
  protected \Propulsion\Generator\Builder\OM\QueryBuilder $builder;
  protected string $objectClassname;
  protected string $peerClassname;
  protected string $queryClassname;

  public function __construct(VersionableBehavior $behavior)
  {
    $this->behavior = $behavior;
    $this->table = $behavior->getTable();
  }

  protected function getParameter(string $key): mixed
  {
    return $this->behavior->getParameter($key);
  }

  protected function getColumnAttribute(string $name = "version_column"): string
  {
    return strtolower($this->behavior->getColumnForParameter($name)->getName());
  }

  protected function getColumnPhpName(string $name = "version_column"): string
  {
    return $this->behavior->getColumnForParameter($name)->getPhpName();
  }

  protected function getVersionQueryClassName(): string
  {
    return $this->builder
      ->getNewStubQueryBuilder($this->behavior->getVersionTable())
      ->getClassname();
  }

  protected function setBuilder(\Propulsion\Generator\Builder\OM\QueryBuilder $builder): void
  {
    $this->builder = $builder;
    $this->objectClassname = $builder->getStubObjectBuilder()->getClassname();
    $this->queryClassname = $builder->getStubQueryBuilder()->getClassname();
    $this->peerClassname = $builder->getStubPeerBuilder()->getClassname();
  }

  /**
   * Get the getter of the column of the behavior
   *
   * @return string The related getter, e.g. 'getVersion'
   */
  protected function getColumnGetter(string $name = "version_column"): string
  {
    return "get" . $this->getColumnPhpName($name);
  }

  /**
   * Get the setter of the column of the behavior
   *
   * @return string The related setter, e.g. 'setVersion'
   */
  protected function getColumnSetter(string $name = "version_column"): string
  {
    return "set" . $this->getColumnPhpName($name);
  }

  public function queryMethods(\Propulsion\Generator\Builder\OM\QueryBuilder $builder): string
  {
    $this->setBuilder($builder);
    $script = "";
    if ($this->getParameter("version_column") != "version") {
      $this->addFilterByVersion($script);
      $this->addOrderByVersion($script);
    }

    return $script;
  }

  protected function addFilterByVersion(string &$script): void
  {
    $script .=
      "
/**
 * Wrap the filter on the version column
 *
 * @param     integer \$version
 * @param     string  \$comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
 * @return    " .
      $this->builder->getStubQueryBuilder()->getClassname() .
      " The current query, for fluid interface
 */
public function filterByVersion(\$version = null, \$comparison = null)
{
	return \$this->filterBy{$this->getColumnPhpName()}(\$version, \$comparison);
}
";
  }

  protected function addOrderByVersion(string &$script): void
  {
    $script .=
      "
/**
 * Wrap the order on the version volumn
 *
 * @param   string \$order The sorting order. Criteria::ASC by default, also accepts Criteria::DESC
 * @return  " .
      $this->builder->getStubQueryBuilder()->getClassname() .
      " The current query, for fluid interface
 */
public function orderByVersion(\$order = Criteria::ASC)
{
	return \$this->orderBy('{$this->getColumnPhpName()}', \$order);
}
";
  }
}
