<?php

// raw useQuery(string $relationName, ?string $secondaryCriteriaClass) form -- the
// trailing class-string argument must land after the closure, matching withQuery()'s
// (string $relationName, callable $callback, ?string $secondaryCriteriaClass) signature
$q = $q->useQuery('Author', NamespacedAuthorQuery::class)
        ->filterByFirstName('Jane')
    ->endUse();
-----
<?php

// raw useQuery(string $relationName, ?string $secondaryCriteriaClass) form -- the
// trailing class-string argument must land after the closure, matching withQuery()'s
// (string $relationName, callable $callback, ?string $secondaryCriteriaClass) signature
$q = $q->withQuery('Author', fn($q) => $q
        ->filterByFirstName('Jane'), NamespacedAuthorQuery::class);
