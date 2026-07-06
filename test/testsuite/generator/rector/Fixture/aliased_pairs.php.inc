<?php

// mirrors testUseFkQueryTwiceTwoAliases -- trailing args (alias) on the opener must
// be preserved and carried after the closure
$q = BookQuery::create()
    ->useAuthorQuery('a')
        ->filterByFirstName('Leo')
    ->endUse()
    ->useAuthorQuery('b')
        ->filterByLastName('Tolstoi')
    ->endUse();
-----
<?php

// mirrors testUseFkQueryTwiceTwoAliases -- trailing args (alias) on the opener must
// be preserved and carried after the closure
$q = BookQuery::create()->withAuthorQuery(fn($q) => $q
        ->filterByFirstName('Leo'), 'a')->withAuthorQuery(fn($q) => $q
        ->filterByLastName('Tolstoi'), 'b');
