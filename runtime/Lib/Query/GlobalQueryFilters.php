<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Query;

use Propulsion\Exception\PropulsionException;

/**
 * Predicates applied to every query on a model unless the query opts out --
 * soft delete ("never show rows with a deleted_at") and multi-tenancy ("never
 * show another tenant's rows") being the two that motivate the feature.
 *
 * Registered once at bootstrap and consulted at SQL-build time:
 *
 * <code>
 * Propulsion::addGlobalQueryFilter('Book', 'not-deleted', function (ModelCriteria $q) {
 *     $q->filterByDeletedAt(null);
 * });
 *
 * BookQuery::create()->find();                            // ... WHERE book.DELETED_AT IS NULL
 * BookQuery::create()->withoutGlobalFilters()->find();    // ... no such clause
 * </code>
 *
 * **Why a callable rather than a stored `Criterion`.** A filter is applied to
 * the actual query object, so it can use the generated `filterByX()` methods,
 * read request state (the current tenant), or apply nothing at all under some
 * condition. A pre-built Criterion would be evaluated once at registration,
 * which is wrong for every interesting case: the tenant is not known then.
 *
 * **Filters are process-scoped configuration, not request state.** They live
 * on {@see \Propulsion\ServiceContainer} for the same reason the query cache
 * pool does, and survive `Session::reset()`. Under a persistent worker that is
 * what you want: register the closure once, and let it read whatever
 * request-scoped thing it needs each time it runs. Do not register a filter
 * that captures a tenant id by value inside a request -- capture the *lookup*
 * instead.
 *
 * **Named, so a query can drop one without dropping the rest.** A report that
 * needs to see soft-deleted rows should not thereby lose the tenancy filter,
 * which would be a data leak rather than an inconvenience; that is what
 * {@see ModelCriteria::withoutGlobalFilter()} is for, and why
 * `withoutGlobalFilters()` (all of them) is the more dangerous spelling.
 */
final class GlobalQueryFilters
{
	/**
	 * Model name => filter name => filter.
	 *
	 * Keyed by name rather than appended so re-registering the same name
	 * replaces rather than stacks -- a bootstrap that runs twice (a test, a
	 * worker reload) must not end up applying its filters twice.
	 *
	 * @var array<string, array<string, callable(ModelCriteria): void>>
	 */
	private array $filters = array();

	/**
	 * @param     string $modelName  The model's name as {@see ModelCriteria::getModelName()}
	 *                               reports it -- i.e. exactly what the generated query class
	 *                               carries, namespace included if it has one.
	 * @param     string $filterName A name for this filter, unique per model.
	 * @param     callable(ModelCriteria): void $filter Receives the query and adds conditions to it.
	 */
	public function add(string $modelName, string $filterName, callable $filter): void
	{
		if ($filterName === '') {
			throw new PropulsionException('A global query filter needs a non-empty name so a query can opt out of it individually.');
		}
		$this->filters[$modelName][$filterName] = $filter;
	}

	/**
	 * Unregisters one filter. Removing one that was never registered is a
	 * no-op rather than an error, so teardown does not have to track what it
	 * added.
	 */
	public function remove(string $modelName, string $filterName): void
	{
		unset($this->filters[$modelName][$filterName]);
		if (isset($this->filters[$modelName]) && $this->filters[$modelName] === array()) {
			unset($this->filters[$modelName]);
		}
	}

	/**
	 * Unregisters every filter on $modelName, or on every model when it is null.
	 */
	public function clear(?string $modelName = null): void
	{
		if ($modelName === null) {
			$this->filters = array();

			return;
		}
		unset($this->filters[$modelName]);
	}

	/**
	 * The filters registered for $modelName, keyed by name. Empty if none.
	 *
	 * @return    array<string, callable(ModelCriteria): void>
	 */
	public function forModel(string $modelName): array
	{
		return $this->filters[$modelName] ?? array();
	}

	/**
	 * Whether any filter is registered at all -- the check every query makes,
	 * so an application using none pays a single array test.
	 */
	public function isEmpty(): bool
	{
		return $this->filters === array();
	}

	/**
	 * The names registered for $modelName, for diagnostics.
	 *
	 * @return    array<int, string>
	 */
	public function names(string $modelName): array
	{
		return array_keys($this->filters[$modelName] ?? array());
	}
}
