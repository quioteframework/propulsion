<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion;

use Propulsion\Connection\PropulsionPDO;
use Propulsion\Event\PostFlushEvent;
use Propulsion\Event\PreFlushEvent;
use Propulsion\Exception\PropulsionException;
use Propulsion\OM\BaseObject;

/**
 * Opt-in Unit-of-Work layer on top of Propulsion's existing ActiveRecord
 * API -- collects changes across a request/scope, then flushes them
 * together as one planned, dependency-ordered batch, instead of every
 * `->save()`/`->delete()` call driving its own depth-first cascade. See
 * UNIT_OF_WORK.md for the full design rationale.
 *
 * Scoped to a single connection/database for now -- entities spanning more
 * than one configured database in one flush() aren't supported (each
 * tracked entity is saved/deleted via the one connection passed to the
 * constructor, regardless of which database its own table actually lives
 * in).
 *
 * Not a replacement for `->save()`: existing ActiveRecord-style code is
 * completely unaffected. This is an additional way to drive persistence for
 * callers that want batched, dependency-ordered flushing instead.
 *
 * <code>
 * $uow = new UnitOfWork($con);
 * $uow->track($book);
 * $uow->markDeleted($author);
 * $uow->attach($detachedEntity, EntityState::Modified);
 * $affected = $uow->flush();
 * </code>
 */
class UnitOfWork
{
	/**
	 * @var        array<int,BaseObject> spl_object_id($entity) => $entity
	 */
	private array $tracked = array();

	/**
	 * @var        array<int,EntityState> spl_object_id($entity) => explicit override
	 */
	private array $explicitStates = array();

	public function __construct(private readonly PropulsionPDO $con)
	{
	}

	/**
	 * Registers $entity to be flushed. A no-op if it's already tracked (by
	 * object identity -- tracking the same instance twice, e.g. because it's
	 * reachable from two different places in caller code, doesn't duplicate
	 * work).
	 */
	public function track(BaseObject $entity): void
	{
		$this->tracked[spl_object_id($entity)] = $entity;
	}

	/**
	 * Registers $entity to be deleted on flush(), regardless of its current
	 * isDeleted() state. Shorthand for `attach($entity, EntityState::Deleted)`.
	 */
	public function markDeleted(BaseObject $entity): void
	{
		$this->attach($entity, EntityState::Deleted);
	}

	/**
	 * Tracks $entity and overrides how flush() treats it, regardless of what
	 * isNew()/isModified()/isDeleted() currently say -- for a detached entity
	 * (e.g. hydrated from a deserialized API request body) whose isNew() is
	 * otherwise meaningless: a fresh BaseObject always starts isNew() ===
	 * true, whether it represents a brand new row or an existing one the
	 * caller already knows the primary key of.
	 *
	 * EntityState::Added/Modified additionally set isNew() to match (true/
	 * false respectively) -- EntityState::Modified still needs the entity to
	 * actually have modified columns set via its own setters for flush() to
	 * have anything to write; this only overrides the insert-vs-update
	 * decision, not where the SET clause's values come from.
	 */
	public function attach(BaseObject $entity, EntityState $state): void
	{
		$this->track($entity);
		$this->explicitStates[spl_object_id($entity)] = $state;
		match ($state) {
			EntityState::Added => $entity->setNew(true),
			EntityState::Modified => $entity->setNew(false),
			EntityState::Deleted, EntityState::Unchanged => null,
		};
	}

	/**
	 * Stops tracking $entity -- it's left exactly as-is (no state changes)
	 * and simply won't be visited by the next flush().
	 */
	public function detach(BaseObject $entity): void
	{
		$id = spl_object_id($entity);
		unset($this->tracked[$id], $this->explicitStates[$id]);
	}

	/**
	 * @return     array<int,BaseObject> Every currently-tracked entity, in no
	 *             particular order (see flush() for the order entities are
	 *             actually written in).
	 */
	public function getTrackedEntities(): array
	{
		return array_values($this->tracked);
	}

	/**
	 * Partitions tracked entities into insert/update/delete sets, orders them
	 * by table-level FK dependency, and writes the whole batch in one
	 * transaction: parent tables before the tables that FK into them for
	 * inserts/updates, and the reverse for deletes. Each entity's own
	 * automatic cascade (BaseObject::$suppressAutoCascade) is suppressed for
	 * the duration -- this is the mechanism that replaces it, not just an
	 * optimization on top of it, so an entity reachable only via another
	 * tracked entity's FK setter/collection and not itself tracked won't be
	 * persisted (see BaseObject::$suppressAutoCascade's own doc comment).
	 *
	 * Dispatches PreFlushEvent first (a listener calling stopPropagation()
	 * aborts the whole flush, returning 0 with nothing persisted) and
	 * PostFlushEvent after a successful commit.
	 *
	 * On any PropulsionException from an entity's own save()/delete() --
	 * including a ConcurrencyException from an OptimisticLockBehavior-guarded
	 * update -- the whole batch is rolled back and the exception rethrown;
	 * tracked entities are left tracked (nothing is cleared) so a caller can
	 * inspect what failed and retry.
	 *
	 * @return     int Total affected rows across every entity's own
	 *             save()/delete() call.
	 * @throws     PropulsionException
	 */
	public function flush(): int
	{
		$preEvent = new PreFlushEvent($this, $this->con);
		Propulsion::dispatch($preEvent);
		if ($preEvent->isPropagationStopped()) {
			return 0;
		}

		[$inserts, $updates, $deletes] = $this->partition();
		if ($inserts === array() && $updates === array() && $deletes === array()) {
			return 0;
		}

		$tableOrder = $this->topologicalTableOrder(array_merge($inserts, $updates, $deletes));
		$writeOrder = array_merge(
			$this->orderByTable($inserts, $tableOrder),
			$this->orderByTable($updates, $tableOrder)
		);
		$deleteOrder = $this->orderByTable($deletes, array_reverse($tableOrder));

		$affectedRows = 0;
		$suppressed = array();
		$this->con->beginTransaction();
		try {
			foreach ($writeOrder as $entity) {
				$entity->setSuppressAutoCascade(true);
				$suppressed[] = $entity;
				$affectedRows += $entity->save($this->con);
			}
			foreach ($deleteOrder as $entity) {
				$entity->setSuppressAutoCascade(true);
				$suppressed[] = $entity;
				$entity->delete($this->con);
				$affectedRows++;
			}
			$this->con->commit();
		} catch (PropulsionException $e) {
			$this->con->rollBack();
			throw $e;
		} finally {
			foreach ($suppressed as $entity) {
				$entity->setSuppressAutoCascade(false);
			}
		}

		$this->tracked = array();
		$this->explicitStates = array();

		Propulsion::dispatch(new PostFlushEvent($this, $this->con, $affectedRows));

		return $affectedRows;
	}

	/**
	 * @return     array{0:array<int,BaseObject>,1:array<int,BaseObject>,2:array<int,BaseObject>}
	 *             [$inserts, $updates, $deletes]
	 */
	private function partition(): array
	{
		$inserts = $updates = $deletes = array();
		foreach ($this->tracked as $id => $entity) {
			$state = $this->explicitStates[$id] ?? null;
			if ($state === EntityState::Deleted) {
				if (!$entity->isDeleted()) {
					$deletes[] = $entity;
				}
			} elseif ($state === EntityState::Added || ($state === null && $entity->isNew())) {
				$inserts[] = $entity;
			} elseif ($state === EntityState::Modified || ($state === null && $entity->isModified())) {
				$updates[] = $entity;
			}
			// EntityState::Unchanged, or no explicit state and neither new
			// nor modified: nothing to do for this entity.
		}
		return array($inserts, $updates, $deletes);
	}

	/**
	 * The real (unquoted) table name for $entity, via its generated
	 * getPeer() -- the one generic, table-name-string accessor every model
	 * exposes without needing any per-model UnitOfWork-specific codegen.
	 */
	private function tableNameFor(BaseObject $entity): string
	{
		$peerClass = $entity->getPeer();
		$tableName = constant($peerClass . '::TABLE_NAME');
		if (!is_string($tableName)) {
			throw new PropulsionException(get_class($entity) . '::getPeer() -> ' . $peerClass . '::TABLE_NAME is not a string');
		}
		return $tableName;
	}

	private function databaseNameFor(BaseObject $entity): string
	{
		$peerClass = $entity->getPeer();
		$dbName = constant($peerClass . '::DATABASE_NAME');
		if (!is_string($dbName)) {
			throw new PropulsionException(get_class($entity) . '::getPeer() -> ' . $peerClass . '::DATABASE_NAME is not a string');
		}
		return $dbName;
	}

	/**
	 * Topologically sorts the distinct tables $entities span by FK
	 * dependency (a table with an FK into another comes after it), via a
	 * standard DFS-based topological sort. Cycles (a genuine cross-table
	 * mutual FK, not the common and harmless self-referencing case, e.g. a
	 * tree's parent_id) can't be satisfied by any single order -- the DFS
	 * simply ignores the back-edge that would complete the cycle rather than
	 * refusing to produce an order at all; this is a best-effort improvement
	 * over the per-object cascade it replaces, which never handled that case
	 * correctly either.
	 *
	 * @param      array<int,BaseObject> $entities
	 * @return     array<int,string> Table names, dependency-ascending (an
	 *             independent/parent table before anything that FKs into it).
	 */
	private function topologicalTableOrder(array $entities): array
	{
		$tableNameSet = array();
		$dbNameByTable = array();
		foreach ($entities as $entity) {
			$tableName = $this->tableNameFor($entity);
			$tableNameSet[$tableName] = true;
			$dbNameByTable[$tableName] = $this->databaseNameFor($entity);
		}
		$tableNames = array_keys($tableNameSet);

		// $dependsOn[$table] = tables it has an FK into (must be placed first).
		$dependsOn = array();
		foreach ($tableNames as $tableName) {
			$tableMap = Propulsion::getDatabaseMap($dbNameByTable[$tableName])->getTable($tableName);
			$deps = array();
			foreach ($tableMap->getForeignKeys() as $fkColumn) {
				// Name-only accessor, deliberately not getRelatedTable() -- that one
				// eagerly resolves the actual TableMap via the DatabaseMap, which
				// throws for any table whose Peer class happens not to be
				// autoloaded/registered yet (unrelated to whether it's even one of
				// $tableNames -- e.g. "book" always has an FK into "publisher",
				// registered or not, regardless of whether anything in this flush
				// touches Publisher at all). Comparing plain name strings against
				// $tableNames sidesteps needing that table registered at all.
				$relatedTableName = $fkColumn->getRelatedTableName();
				if ($relatedTableName !== $tableName && in_array($relatedTableName, $tableNames, true)) {
					$deps[$relatedTableName] = true;
				}
			}
			$dependsOn[$tableName] = array_keys($deps);
		}

		$ordered = array();
		$visited = array();
		$visiting = array();
		$visit = function (string $tableName) use (&$visit, &$ordered, &$visited, &$visiting, $dependsOn): void {
			if (isset($visited[$tableName]) || isset($visiting[$tableName])) {
				return;
			}
			$visiting[$tableName] = true;
			foreach ($dependsOn[$tableName] as $dep) {
				$visit($dep);
			}
			unset($visiting[$tableName]);
			$visited[$tableName] = true;
			$ordered[] = $tableName;
		};
		foreach ($tableNames as $tableName) {
			$visit($tableName);
		}

		return $ordered;
	}

	/**
	 * Stably reorders $entities to match $tableOrder (an entity whose table
	 * isn't in $tableOrder at all -- shouldn't happen, since
	 * topologicalTableOrder() is always called with every table $entities
	 * spans -- sorts last).
	 *
	 * @param      array<int,BaseObject> $entities
	 * @param      array<int,string> $tableOrder
	 * @return     array<int,BaseObject>
	 */
	private function orderByTable(array $entities, array $tableOrder): array
	{
		if ($entities === array()) {
			return array();
		}
		$positions = array_flip($tableOrder);
		$buckets = array();
		foreach ($entities as $entity) {
			$tableName = $this->tableNameFor($entity);
			$buckets[$positions[$tableName] ?? count($tableOrder)][] = $entity;
		}
		ksort($buckets);
		return array_merge(...array_values($buckets));
	}
}
