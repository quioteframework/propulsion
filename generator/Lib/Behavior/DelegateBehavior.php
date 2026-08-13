<?php
namespace Propulsion\Generator\Behavior;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Gives a model class the ability to delegate methods to a relationship.
 *
 * @author     François Zaninotto
 */
use Propulsion\Generator\Builder\OM\AbstractObjectBuilder;
use Propulsion\Generator\Builder\OM\ObjectBuilder;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Behavior;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\Table;
class DelegateBehavior extends Behavior
{
	const ONE_TO_ONE = 1;
	const MANY_TO_ONE = 2;

	// default parameters value
	/** @var array<string,string> */
	protected $parameters = array(
		'to' => ''
	);

	/** @var array<string,int> */
	protected $delegates = array();

	private function requireTableName(Table $table): string
	{
		$name = $table->getName();
		if ($name === null) {
			throw new EngineException('Table has no name');
		}
		return $name;
	}

	/**
	 * getDelegateTable() legitimately returns null when the delegate
	 * name (from the 'to' parameter) doesn't resolve to a real table,
	 * but modifyTable() already validates every delegate name against
	 * Database::hasTable() before it's used anywhere else, so treat a
	 * null result at any later call site as a programming error.
	 */
	private function requireDelegateTable(string $delegateTableName): Table
	{
		$delegateTable = $this->getDelegateTable($delegateTableName);
		if ($delegateTable === null) {
			throw new EngineException(sprintf("Delegate table '%s' could not be resolved", $delegateTableName));
		}
		return $delegateTable;
	}

	/**
	 * Lists the delegates and checks that the behavior can use them,
	 * And adds a fk from the delegate to the main table if not already set
	 */
	public function modifyTable(): void
	{
		$table = $this->requireTable();
		$database = $table->requireDatabase();
		$delegates = explode(',', $this->parameters['to']);
		foreach ($delegates as $delegate) {
			$delegate = trim($delegate);
			if (!$database->hasTable($delegate)) {
				throw new \InvalidArgumentException(sprintf(
					'No delegate table "%s" found for table "%s"',
					$delegate,
					$table->getName()
				));
			}
			if (in_array($delegate, $table->getForeignTableNames())) {
				// existing many-to-one relationship
				$type = self::MANY_TO_ONE;
			} else {
				// one_to_one relationship
				$delegateTable = $this->requireDelegateTable($delegate);
				if (in_array($table->getName(), $delegateTable->getForeignTableNames())) {
					// existing one-to-one relationship
					$fks = $delegateTable->getForeignKeysReferencingTable($this->requireTableName($table));
					$fk = $fks[0];
					if (!$fk->isLocalPrimaryKey()) {
						throw new \InvalidArgumentException(sprintf(
							'Delegate table "%s" has a relationship with table "%s", but it\'s a one-to-many relationship. The `delegate` behavior only supports one-to-one relationships in this case.',
							$delegate,
							$table->getName()
						));
					}
				} else {
					// no relationship yet: must be created
					$this->relateDelegateToMainTable($delegateTable, $table);
				}
				$type = self::ONE_TO_ONE;
			}
			$this->delegates[$delegate] = $type;
		}
	}

	protected function relateDelegateToMainTable(Table $delegateTable, Table $mainTable): void
	{
		$pks = $mainTable->getPrimaryKey();
		foreach ($pks as $column) {
			$mainColumnName = $column->getName();
			if ($mainColumnName === null) {
				throw new EngineException(sprintf("A primary key column of table '%s' has no name", $mainTable->getName()));
			}
			if (!$delegateTable->hasColumn($mainColumnName)) {
				$column = clone $column;
				$column->setAutoIncrement(false);
				$delegateTable->addColumn($column);
			}
		}
		// Add a one-to-one fk
		$fk = new ForeignKey();
		$fk->setForeignTableCommonName($mainTable->getCommonName());
		$fk->setForeignSchemaName($mainTable->getSchema());
		$fk->setDefaultJoin('LEFT JOIN');
		$fk->setOnDelete(ForeignKey::CASCADE);
		$fk->setOnUpdate(ForeignKey::NONE);
		foreach ($pks as $column) {
			$fk->addReference($column->getName(), $column->getName());
		}
		$delegateTable->addForeignKey($fk);
	}

	protected function getDelegateTable(string $delegateTableName): ?Table
	{
		return $this->requireTable()->requireDatabase()->getTable($delegateTableName);
	}

	/**
	 * Forwards to the delegate at runtime via method_exists()/
	 * call_user_func_array() -- the magic __call() fallback, kept
	 * specifically for a hand-written custom method that exists directly on
	 * the delegate's own stub class rather than being schema-derived.
	 *
	 * objectMethods() below covers everything schema-derivable (columns,
	 * FK/refFK/crossFK relations) with real, named, statically-visible
	 * methods instead -- PHP only ever reaches this __call() fallback for a
	 * method name that's genuinely undefined elsewhere, so a name
	 * objectMethods() already generated is never routed through here. This
	 * stays specifically because a hand-written delegate method can't be
	 * discovered from the schema at all, so there's nothing else to
	 * generate a real forwarding method from.
	 */
	public function objectCall(ObjectBuilder $builder): string
	{
		$script = '';
		foreach ($this->delegates as $delegate => $type) {
			$delegateTable = $this->requireDelegateTable($delegate);
			if ($type == self::ONE_TO_ONE) {
				$fks = $delegateTable->getForeignKeysReferencingTable($this->requireTableName($this->requireTable()));
				$fk = $fks[0];
				$fkTable = $fk->getTable();
				if ($fkTable === null) {
					throw new EngineException('ForeignKey is not attached to a parent table');
				}
				$ARClassName = $builder->getNewStubObjectBuilder($fkTable)->getClassname();
				$ARFullClassName = $builder->getNewStubObjectBuilder($fkTable)->getFullyQualifiedClassname();
				$delegateObjectBuilder = $builder->getNewObjectBuilder($fkTable);
				$relationName = $builder->getRefFKPhpNameAffix($fk, $plural = false);
			} else {
				$fks = $this->requireTable()->getForeignKeysReferencingTable($delegate);
				$fk = $fks[0];
				$ARClassName = $builder->getNewStubObjectBuilder($delegateTable)->getClassname();
				$ARFullClassName = $builder->getNewStubObjectBuilder($delegateTable)->getFullyQualifiedClassname();
				$delegateObjectBuilder = $builder->getNewObjectBuilder($delegateTable);
				$relationName = $builder->getFKPhpNameAffix($fk);
			}

			// Declare the class for import
			$builder->declareClass($ARFullClassName);

			// A name that exists on the delegate purely because *it* forwards to a
			// delegate of its own (not a name it genuinely defines) has to be
			// excluded here too, or this method_exists() check reaches it and
			// cascades the call onward -- cascading delegation has never been
			// supported (see testAModelCannotHaveCascadingDelegates), and
			// objectMethods() below already declines to generate a real forwarding
			// method for exactly these names for the same reason.
			$delegateTableName = $this->requireTableName($delegateTable);
			$cascadedNames = array_diff_key(
				$delegateObjectBuilder->getGeneratedMethodSignatures(),
				$this->getOwnGeneratedMethodSignatures($delegateObjectBuilder, $delegateTableName)
			);
			$exclusionGuard = $cascadedNames !== array()
				? ' && !in_array($name, ' . var_export(array_keys($cascadedNames), true) . ', true)'
				: '';

			$script .= "
		if (method_exists($ARClassName::class, \$name)$exclusionGuard) {
			if (!\$delegate = \$this->get$relationName()) {
				\$delegate = new $ARClassName();
				\$this->set$relationName(\$delegate);
			}
			return call_user_func_array(array(\$delegate, \$name), \$params);
		}";
		}
		$script .= "
		";
		return $script;
	}

	/**
	 * Generates one real forwarding method per public method the delegate's
	 * own Generated trait defines (see AbstractObjectBuilder::
	 * getGeneratedMethodSignatures()) that this table doesn't already define
	 * itself -- column accessors/mutators, FK/refFK/crossFK relation
	 * accessors in all their variants, whatever else that trait exposes.
	 *
	 * Complements objectCall() above: that magic __call() fallback forwarded
	 * *everything* the delegate exposes purely at runtime, so none of it was
	 * ever a real, named symbol -- invisible to PHPStan/IDEs, and --
	 * concretely -- broken the moment a `use Trait { getComments as ...; }`
	 * alias needed getComments() to be a real trait method to alias. This
	 * generates a real method for everything the schema can derive; only
	 * the delegate's hand-written custom methods still rely on objectCall().
	 *
	 * "This table doesn't already define it" mirrors __call()'s runtime
	 * behavior exactly: PHP only ever invokes __call for undefined method
	 * names, so a name this table already has always won before, too.
	 *
	 * "This table's own methods" (to know what to skip) is itself computed
	 * by building this same table -- via a *separate* builder instance, not
	 * $builder, whose own build() is already mid-execution on this exact
	 * call stack; reusing it would interleave two overlapping build() calls
	 * over the same mutable instance state (declaredClasses and friends).
	 * That separate build in turn re-enters this same method for the same
	 * table, so $tablesBeingProbed short-circuits the re-entrant call to ''
	 * -- "while computing my own baseline, my own delegate forwarding
	 * contributes nothing", which is exactly right, and also the right
	 * behavior in the case a chain of delegates loops back on itself.
	 * @var array<string, true>
	 */
	private static array $tablesBeingProbed = array();

	public function objectMethods(ObjectBuilder $builder): string
	{
		$tableName = $this->requireTableName($this->requireTable());
		if (isset(self::$tablesBeingProbed[$tableName])) {
			return '';
		}
		self::$tablesBeingProbed[$tableName] = true;
		try {
			return $this->buildObjectMethods($builder);
		} finally {
			unset(self::$tablesBeingProbed[$tableName]);
		}
	}

	/**
	 * A table's generated method signatures, with that table's own name
	 * marked as "being probed" for the duration -- so if the table itself
	 * carries a `delegate` behavior, ITS objectMethods() (a different
	 * DelegateBehavior instance, but sharing $tablesBeingProbed as a static
	 * property) short-circuits to '' instead of contributing its own
	 * forwarded methods into this result.
	 *
	 * Without this, table A delegating to B, which itself delegates to C,
	 * would let A "see" (and re-forward) C's methods through B's tokenized
	 * output -- cascading/transitive delegation, which this behavior has
	 * never supported (a direct call to a second-hop method has always
	 * thrown, and stays that way).
	 * @return array<string, array{params: string, returnType: ?string}>
	 */
	private function getOwnGeneratedMethodSignatures(AbstractObjectBuilder $objectBuilder, string $tableName): array
	{
		if (isset(self::$tablesBeingProbed[$tableName])) {
			return array();
		}
		self::$tablesBeingProbed[$tableName] = true;
		try {
			return $objectBuilder->getGeneratedMethodSignatures();
		} finally {
			unset(self::$tablesBeingProbed[$tableName]);
		}
	}

	private function buildObjectMethods(ObjectBuilder $builder): string
	{
		$script = '';
		// Seeded from this table's own methods, then grown as each delegate is
		// processed: a table can delegate to more than one target (the 'to'
		// parameter is comma-separated), and unrelated delegates commonly share
		// method names -- initRelation() exists on every generated trait, for
		// instance -- so a name claimed by the first delegate has to block a
		// second delegate from redeclaring it, not just names this table already
		// had before either delegate was considered.
		$claimedMethods = $builder->getNewObjectBuilder($this->requireTable())->getGeneratedMethodSignatures();
		foreach ($this->delegates as $delegate => $type) {
			$delegateTable = $this->requireDelegateTable($delegate);
			if ($type == self::ONE_TO_ONE) {
				$fks = $delegateTable->getForeignKeysReferencingTable($this->requireTableName($this->requireTable()));
				$fk = $fks[0];
				$fkTable = $fk->getTable();
				if ($fkTable === null) {
					throw new EngineException('ForeignKey is not attached to a parent table');
				}
				// The stub (concrete, instantiable class) for naming/importing/instantiating;
				// the object (trait) builder separately, only to read its method signatures.
				$delegateStubBuilder = $builder->getNewStubObjectBuilder($fkTable);
				$delegateObjectBuilder = $builder->getNewObjectBuilder($fkTable);
				$relationName = $builder->getRefFKPhpNameAffix($fk, $plural = false);
			} else {
				$fks = $this->requireTable()->getForeignKeysReferencingTable($delegate);
				$fk = $fks[0];
				$delegateStubBuilder = $builder->getNewStubObjectBuilder($delegateTable);
				$delegateObjectBuilder = $builder->getNewObjectBuilder($delegateTable);
				$relationName = $builder->getFKPhpNameAffix($fk);
			}

			$ARClassName = $delegateStubBuilder->getClassname();
			$builder->declareClassFromBuilder($delegateStubBuilder);

			foreach ($this->getOwnGeneratedMethodSignatures($delegateObjectBuilder, $this->requireTableName($delegateTable)) as $name => $signature) {
				if (isset($claimedMethods[$name])) {
					// This table, or an earlier delegate, already defines/claimed it --
					// same precedence __call() gave the earliest match before.
					continue;
				}
				$claimedMethods[$name] = $signature;
				$returnType = $signature['returnType'];
				$returnTypeDecl = $returnType !== null ? (': ' . $returnType) : '';
				$forwardedArgs = $this->extractParamNames($signature['params']);
				$forwardedCall = "\$delegate->$name($forwardedArgs)";
				if ($returnType === 'void' || $returnType === 'never') {
					// A void-typed method can't `return <expr>;` at all -- not even one
					// that itself evaluates to nothing -- so call it as a statement.
					$forwardingStatement = "$forwardedCall;";
				} elseif ($returnType === 'static') {
					// `static` here means "an instance of the class this wrapper method
					// is called on" (this table's own class) under late static binding --
					// not the delegate's class. $delegate's own return value would be an
					// instance of the DELEGATE's class instead, which is a TypeError against
					// this table's `static` return type. Discard it and return $this instead:
					// still satisfies the contract, and keeps fluent chaining on this object.
					$forwardingStatement = "$forwardedCall;\n\t\treturn \$this;";
				} else {
					$forwardingStatement = "return $forwardedCall;";
				}
				$script .= "
	/**
	 * Delegates to " . $ARClassName . "::" . $name . "().
	 */
	public function $name({$signature['params']})$returnTypeDecl
	{
		if (!\$delegate = \$this->get$relationName()) {
			\$delegate = new $ARClassName();
			\$this->set$relationName(\$delegate);
		}
		$forwardingStatement
	}
";
			}
		}
		return $script;
	}

	/**
	 * Reduces a parameter-list signature (e.g. "?int \$id, string \$name = 'x'")
	 * to just its variable names in order (e.g. "$id, $name"), for use as the
	 * argument list of a call that forwards to the method this signature
	 * belongs to.
	 */
	private function extractParamNames(string $params): string
	{
		$depth = 0;
		$segment = '';
		$segments = array();
		for ($i = 0, $len = strlen($params); $i < $len; $i++) {
			$char = $params[$i];
			if ($char === '(' || $char === '[' || $char === '{') {
				$depth++;
			} elseif ($char === ')' || $char === ']' || $char === '}') {
				$depth--;
			} elseif ($char === ',' && $depth === 0) {
				$segments[] = $segment;
				$segment = '';
				continue;
			}
			$segment .= $char;
		}
		if (trim($segment) !== '') {
			$segments[] = $segment;
		}

		$names = array();
		foreach ($segments as $segment) {
			if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*/', $segment, $matches)) {
				$names[] = $matches[0];
			}
		}
		return implode(', ', $names);
	}

}