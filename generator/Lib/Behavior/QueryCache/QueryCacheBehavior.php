<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\QueryCache;;

/**
 * Speeds up queries on a model by caching the query
 *
 * @author     François Zaninotto
 * @version    $Revision$
 */
use Propulsion\Generator\Builder\OM\OMBuilder;
use Propulsion\Generator\Model\Behavior;
class QueryCacheBehavior extends Behavior
{
	// default parameters value
	/** @var array<string, mixed> */
	protected $parameters = array(
		'backend'     => 'apc',
		'lifetime'    => 3600,
	);

	protected string $peerClassname = '';

	public function queryAttributes(OMBuilder $builder): string
	{
		$script = "protected \$queryKey = '';
";
		switch ($this->getParameter('backend')) {
			case 'backend':
				$script .= "protected static \$cacheBackend = array();
			";
				break;
			case 'apc':
				break;
			case 'custom':
			default:
				$script .= "protected static \$cacheBackend;
			";
				break;
		}

		return $script;
	}

	public function queryMethods(OMBuilder $builder): string
	{
		$this->peerClassname = $builder->getStubPeerBuilder()->getClassname();
		$script = '';
		$this->addSetQueryKey($script);
		$this->addGetQueryKey($script);
		$this->addCacheContains($script);
		$this->addCacheFetch($script);
		$this->addCacheStore($script);
		$this->addGetSelectStatement($script);
		$this->addGetCountStatement($script);

		return $script;
	}

	protected function addSetQueryKey(string &$script): void
	{
		$script .= "
public function setQueryKey(\$key)
{
	\$this->queryKey = \$key;
	return \$this;
}
";
	}

	protected function addGetQueryKey(string &$script): void
	{
		$script .= "
public function getQueryKey()
{
	return \$this->queryKey;
}
";
	}

	protected function addCacheContains(string &$script): void
	{
		$script .= "
public function cacheContains(\$key)
{";
		switch ($this->getParameter('backend')) {
			case 'apc':
				$script .= "
	return apc_fetch(\$key);";
				break;
			case 'array':
				$script .= "
	return isset(self::\$cacheBackend[\$key]);";
				break;
			case 'custom':
			default:
				$script .= "
	throw new PropulsionException('You must override the cacheContains(), cacheStore(), and cacheFetch() methods to enable query cache');";
				break;

		}
		$script .= "
}
";
	}

	protected function addCacheStore(string &$script): void
	{
		$script .= "
public function cacheStore(\$key, \$value, \$lifetime = " .$this->getParameter('lifetime') . ")
{";
		switch ($this->getParameter('backend')) {
			case 'apc':
				$script .= "
	apc_store(\$key, \$value, \$lifetime);";
				break;
			case 'array':
				$script .= "
	self::\$cacheBackend[\$key] = \$value;";
				break;
			case 'custom':
			default:
				$script .= "
	throw new PropulsionException('You must override the cacheContains(), cacheStore(), and cacheFetch() methods to enable query cache');";
				break;
		}
		$script .= "
}
";
	}

	protected function addCacheFetch(string &$script): void
	{
		$script .= "
public function cacheFetch(\$key)
{";
		switch ($this->getParameter('backend')) {
			case 'apc':
				$script .= "
	return apc_fetch(\$key);";
				break;
			case 'array':
				$script .= "
	return isset(self::\$cacheBackend[\$key]) ? self::\$cacheBackend[\$key] : null;";
				break;
			case 'custom':
			default:
				$script .= "
	throw new PropulsionException('You must override the cacheContains(), cacheStore(), and cacheFetch() methods to enable query cache');";
				break;
		}
		$script .= "
}
";
	}

	protected function addGetSelectStatement(string &$script): void
	{
		$script .= "
protected function getSelectStatement(\$con = null)
{
	\$dbMap = Propulsion::getDatabaseMap(" . $this->peerClassname ."::DATABASE_NAME);
	\$db = Propulsion::getDB(" . $this->peerClassname ."::DATABASE_NAME);
  if (\$con === null) {
		\$con = Propulsion::getConnection(" . $this->peerClassname ."::DATABASE_NAME, Propulsion::CONNECTION_READ);
	}

	if (!\$this->hasSelectClause() && !\$this->getPrimaryCriteria()) {
		\$this->addSelfSelectColumns();
	}

	\$this->configureSelectColumns();

	\$con->beginTransaction();
	try {
		\$this->basePreSelect(\$con);
		\$key = \$this->getQueryKey();
		if (\$key && \$this->cacheContains(\$key)) {
			\$params = \$this->getParams();
			\$sql = \$this->cacheFetch(\$key);
		} else {
			\$params = array();
			\$sql = BasePeer::createSelectSql(\$this, \$params);
			if (\$key) {
				\$this->cacheStore(\$key, \$sql);
			}
		}
		\$stmt = \$con->prepare(\$sql);
		\$db->bindValues(\$stmt, \$params, \$dbMap);
		\$stmt->execute();
		\$con->commit();
	} catch (PropulsionException \$e) {
		\$con->rollback();
		throw \$e;
	}

	return \$stmt;
}
";
	}

	protected function addGetCountStatement(string &$script): void
	{
		$script .= "
protected function getCountStatement(\$con = null)
{
	\$dbMap = Propulsion::getDatabaseMap(\$this->getDbName());
	\$db = Propulsion::getDB(\$this->getDbName());
  if (\$con === null) {
		\$con = Propulsion::getConnection(\$this->getDbName(), Propulsion::CONNECTION_READ);
	}

	\$con->beginTransaction();
	try {
		\$this->basePreSelect(\$con);
		\$key = \$this->getQueryKey();
		if (\$key && \$this->cacheContains(\$key)) {
			\$params = \$this->getParams();
			\$sql = \$this->cacheFetch(\$key);
		} else {
			if (!\$this->hasSelectClause() && !\$this->getPrimaryCriteria()) {
				\$this->addSelfSelectColumns();
			}
			\$params = array();
			\$needsComplexCount = \$this->getGroupByColumns()
				|| \$this->getOffset()
				|| \$this->getLimit()
				|| \$this->getHaving()
				|| in_array(Criteria::DISTINCT, \$this->getSelectModifiers());
			if (\$needsComplexCount) {
				if (BasePeer::needsSelectAliases(\$this)) {
					if (\$this->getHaving()) {
						throw new PropulsionException('Propulsion cannot create a COUNT query when using HAVING and  duplicate column names in the SELECT part');
					}
					\$db->turnSelectColumnsToAliases(\$this);
				}
				\$selectSql = BasePeer::createSelectSql(\$this, \$params);
				\$sql = 'SELECT COUNT(*) FROM (' . \$selectSql . ') propelmatch4cnt';
			} else {
				// Replace SELECT columns with COUNT(*)
				\$this->clearSelectColumns()->addSelectColumn('COUNT(*)');
				\$sql = BasePeer::createSelectSql(\$this, \$params);
			}
			if (\$key) {
				\$this->cacheStore(\$key, \$sql);
			}
		}
		\$stmt = \$con->prepare(\$sql);
		\$db->bindValues(\$stmt, \$params, \$dbMap);
		\$stmt->execute();
		\$con->commit();
	} catch (PropulsionException \$e) {
		\$con->rollback();
		throw \$e;
	}

	return \$stmt;
}
";
	}

}