<?php
namespace Propulsion\Generator\Behavior\AggregateColumn;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Keeps an aggregate column updated with related table
 *
 * @author     François Zaninotto
 * @version    $Revision$
 */

use Propulsion\Generator\Builder\OM\ObjectBuilder;
use Propulsion\Generator\Builder\OM\OMBuilder;
use Propulsion\Generator\Builder\OM\QueryBuilder;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Behavior;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\Table;

class AggregateColumnRelationBehavior extends Behavior
{

	/**
	 * default parameters value
	 * @var array<string, mixed>
	 */
	protected $parameters = array(
		'foreign_table' => '',
		'update_method' => '',
	);

	private function getStringParameter(string $name): string
	{
		$value = $this->getParameter($name);
		return is_string($value) ? $value : '';
	}

	/**
	 * getForeignKey() legitimately returns null when no matching foreign
	 * key can be found, but every call site here requires one to exist.
	 */
	private function requireForeignKey(): ForeignKey
	{
		$fk = $this->getForeignKey();
		if ($fk === null) {
			throw new \InvalidArgumentException(sprintf(
				"No foreign key was found relating table '%s' to '%s'",
				$this->requireTable()->getName(),
				$this->requireForeignTable()->getName(),
			));
		}
		return $fk;
	}

	public function postSave(ObjectBuilder $builder): string
	{
		$relationName = $this->getRelationName($builder);
		return "\$this->updateRelated{$relationName}(\$con);";
	}

	// no need for a postDelete() hook, since delete() uses Query::delete(),
	// which already has a hook

	public function objectAttributes(ObjectBuilder $builder): string
	{
		$relationName = $this->getRelationName($builder);
		$relatedClass = $this->requireForeignTable()->getPhpName();
		return "/** @var ?\\{$relatedClass} The related object this one used to point at, so its aggregate can be updated too */
protected \$old{$relationName};
";
	}

	public function objectMethods(ObjectBuilder $builder): string
	{
		return $this->addObjectUpdateRelated($builder);
	}

	protected function addObjectUpdateRelated(ObjectBuilder $builder): string
	{
		$relationName = $this->getRelationName($builder);
		$updateMethodName = $this->getParameter('update_method');
		return $this->renderTemplate('objectUpdateRelated', array(
			'relationName'     => $relationName,
			'variableName'     => self::lcfirst($relationName),
			'updateMethodName' => $this->getParameter('update_method'),
		));
	}

	/**
	 * @param string|null $script
	 */
	public function objectFilter(&$script, ObjectBuilder $builder): void
	{
		if ($script === null) {
			return;
		}
		$relationName = $this->getRelationName($builder);
		$relatedClass = $this->requireForeignTable()->getPhpName();
		if ($relatedClass === null) {
			throw new EngineException('Foreign table has no PHP name');
		}
		// Match the FK relation setter's signature loosely (optional leading "?" on the
		// parameter type, and any return type declaration) rather than the exact PHP5-era
		// literal "public function setX(RelatedClass $v = null)\n\t{" this used to require --
		// the promoted ObjectBuilder's addFKMutator() generates
		// "public function setX(?RelatedClass $v = null): static\n\t{", which the old fixed
		// string never matched, so this filter silently never fired and $this->oldX was
		// never populated (GeneratedObjectRelTest et al -- see KNOWN_ISSUES.md).
		$pattern = '/(public function set' . preg_quote($relationName, '/') . '\(\??' . preg_quote($relatedClass, '/') . ' \$v = null\)(?::\s*\S+)?\s*\{)/';
		$replace = '$1' . "
		// aggregate_column_relation behavior
		if (null !== \$this->a{$relationName} && \$v !== \$this->a{$relationName}) {
			\$this->old{$relationName} = \$this->a{$relationName};
		}";
		$script = preg_replace($pattern, $replace, $script);
	}

	public function preUpdateQuery(QueryBuilder $builder): string
	{
		return $this->getFindRelated($builder);
	}

	public function preDeleteQuery(QueryBuilder $builder): string
	{
		return $this->getFindRelated($builder);
	}

	protected function getFindRelated(QueryBuilder $builder): string
	{
		$relationName = $this->getRelationName($builder);
		return "\$this->findRelated{$relationName}s(\$con);";
	}

	public function postUpdateQuery(QueryBuilder $builder): string
	{
		return $this->getUpdateRelated($builder);
	}

	public function postDeleteQuery(QueryBuilder $builder): string
	{
		return $this->getUpdateRelated($builder);
	}

	protected function getUpdateRelated(QueryBuilder $builder): string
	{
		$relationName = $this->getRelationName($builder);
		return "\$this->updateRelated{$relationName}s(\$con);";
	}

	public function queryAttributes(QueryBuilder $builder): string
	{
		$relationName = $this->getRelationName($builder);
		$variableName = self::lcfirst($relationName);
		$relatedClass = $this->requireForeignTable()->getPhpName();
		return "/** @var \\Propulsion\\Collection\\PropulsionObjectCollection<\\{$relatedClass}>|null Related objects found before the write, updated after it */
protected \${$variableName}s;
";
	}

	public function queryMethods(QueryBuilder $builder): string
	{
		$script = '';
		$script .= $this->addQueryFindRelated($builder);
		$script .= $this->addQueryUpdateRelated($builder);

		return $script;
	}

	protected function addQueryFindRelated(QueryBuilder $builder): string
	{
		$foreignKey = $this->requireForeignKey();
		$relationName = $this->getRelationName($builder);
		return $this->renderTemplate('queryFindRelated', array(
			'foreignTable'     => $this->requireForeignTable(),
			'relationName'     => $relationName,
			'variableName'     => self::lcfirst($relationName),
			'foreignQueryName' => $foreignKey->requireForeignTable()->getPhpName() . 'Query',
			'refRelationName'  => $builder->getRefFKPhpNameAffix($foreignKey),
		));
	}

	protected function addQueryUpdateRelated(QueryBuilder $builder): string
	{
		$relationName = $this->getRelationName($builder);
		return $this->renderTemplate('queryUpdateRelated', array(
			'relationName'     => $relationName,
			'variableName'     => self::lcfirst($relationName),
			'updateMethodName' => $this->getParameter('update_method'),
		));
	}

	protected function getForeignTable(): ?Table
	{
		return $this->requireTable()->requireDatabase()->getTable($this->getStringParameter('foreign_table'));
	}

	/**
	 * getForeignTable() is a genuine lookup (Database::getTable() by the schema-configured
	 * `foreign_table` parameter name) that can legitimately return null for a misconfigured
	 * `foreign_table` parameter -- a real schema error worth a clear message.
	 */
	protected function requireForeignTable(): Table
	{
		$foreignTable = $this->getForeignTable();
		if ($foreignTable === null) {
			throw new EngineException(sprintf(
				"aggregate_column_relation behavior on table '%s' references unknown foreign_table '%s'.",
				$this->requireTable()->getName() ?? '(unnamed)',
				$this->getStringParameter('foreign_table')
			));
		}
		return $foreignTable;
	}

	protected function getForeignKey(): ?ForeignKey
	{
		$foreignTable = $this->requireForeignTable();
		$foreignTableName = $foreignTable->getName();
		if ($foreignTableName === null) {
			throw new EngineException('Foreign table has no name');
		}
		// let's infer the relation from the foreign table
		$fks = $this->requireTable()->getForeignKeysReferencingTable($foreignTableName);
		// FIXME doesn't work when more than one fk to the same table
		return array_shift($fks);
	}

	protected function getRelationName(OMBuilder $builder): string
	{
		return $builder->getFKPhpNameAffix($this->requireForeignKey());
	}

	protected static function lcfirst(string $input): string
	{
		// no lcfirst in php<5.3...
		$input[0] = strtolower($input[0]);
		return $input;
	}
}