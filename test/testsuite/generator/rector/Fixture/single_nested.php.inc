<?php

// mirrors testUseFkQueryNested
$q = ReviewQuery::create()
    ->useBookQuery()
        ->useAuthorQuery()
            ->filterByFirstName('Leo')
        ->endUse()
    ->endUse();
-----
<?php

// mirrors testUseFkQueryNested
$q = ReviewQuery::create()->withBookQuery(fn($q2) => $q2->withAuthorQuery(fn($q) => $q
            ->filterByFirstName('Leo')));
