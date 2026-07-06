<?php

// raw useQuery(string $relationName) form (no generated wrapper)
$q = $q->useQuery('Author')
        ->filterByFirstName('Jane')
    ->endUse();
-----
<?php

// raw useQuery(string $relationName) form (no generated wrapper)
$q = $q->withQuery('Author', fn($q) => $q
        ->filterByFirstName('Jane'));
