<?php

// mirrors testUseFkQueryMixed: pair, then a further call on the primary criteria
$q = BookQuery::create()
    ->useAuthorQuery()
        ->filterByFirstName('Leo')
    ->endUse()
    ->filterByTitle('War And Peace');
-----
<?php

// mirrors testUseFkQueryMixed: pair, then a further call on the primary criteria
$q = BookQuery::create()->withAuthorQuery(fn($q) => $q
        ->filterByFirstName('Leo'))
    ->filterByTitle('War And Peace');
