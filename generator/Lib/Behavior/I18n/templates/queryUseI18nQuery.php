
/**
 * Use the I18n relation query object
 *
 * @see       useQuery()
 *
 * @param     string $locale Locale to use for the join condition, e.g. 'fr_FR'
 * @param     string $relationAlias optional alias for the relation
 * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'. Defaults to left join.
 *
 * @return    <?php echo $queryClass ?> A secondary query class using the current class as primary query
 */
public function useI18nQuery($locale = '<?php echo $defaultLocale ?>', $relationAlias = null, $joinType = Criteria::LEFT_JOIN)
{
	return $this
		->joinI18n($locale, $relationAlias, $joinType)
		->useQuery($relationAlias ? $relationAlias : '<?php echo $i18nRelationName ?>', '<?php echo $namespacedQueryClass ?>');
}

/**
 * Use the I18n relation query object as a closure-scoped sub-query. Unlike
 * useI18nQuery(), which must be paired with a later endUse() call to return to this
 * query (and loses this query's concrete type across that call), $callback receives
 * the secondary query directly and this method returns $this, typed @return static.
 *
 * @see       \Propulsion\Query\ModelCriteria::withQuery()
 *
 * @param     callable(<?php echo $queryClass ?>): void $callback Receives the secondary query to add conditions to
 * @param     string $locale Locale to use for the join condition, e.g. 'fr_FR'
 * @param     string $relationAlias optional alias for the relation
 * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'. Defaults to left join.
 *
 * @return    static
 */
public function withI18nQuery(callable $callback, $locale = '<?php echo $defaultLocale ?>', $relationAlias = null, $joinType = Criteria::LEFT_JOIN)
{
	return $this
		->joinI18n($locale, $relationAlias, $joinType)
		->withQuery($relationAlias ? $relationAlias : '<?php echo $i18nRelationName ?>', $callback, '<?php echo $namespacedQueryClass ?>');
}
