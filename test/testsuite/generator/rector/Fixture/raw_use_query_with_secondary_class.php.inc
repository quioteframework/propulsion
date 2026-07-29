<?php

// raw useQuery(string $relationName, ?string $secondaryCriteriaClass) form -- a
// second argument is present, so this must rewrite to withTypedQuery(), the split-out
// (string $relationName, callable $callback, string $secondaryCriteriaClass) form
$q = $q->useQuery('Author', NamespacedAuthorQuery::class)
        ->filterByFirstName('Jane')
    ->endUse();
-----
<?php

// raw useQuery(string $relationName, ?string $secondaryCriteriaClass) form -- a
// second argument is present, so this must rewrite to withTypedQuery(), the split-out
// (string $relationName, callable $callback, string $secondaryCriteriaClass) form
$q = $q->withTypedQuery('Author', fn($q) => $q
        ->filterByFirstName('Jane'), NamespacedAuthorQuery::class);
