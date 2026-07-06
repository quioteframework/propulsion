<?php

// mirrors testUseFkQueryTwoRelations
$q = BookQuery::create()
    ->useAuthorQuery()
        ->filterByFirstName('Leo')
    ->endUse()
    ->usePublisherQuery()
        ->filterByName('Penguin')
    ->endUse();
-----
<?php

// mirrors testUseFkQueryTwoRelations
$q = BookQuery::create()->withAuthorQuery(fn($q) => $q
        ->filterByFirstName('Leo'))->withPublisherQuery(fn($q) => $q
        ->filterByName('Penguin'));
