<?php

$books = $q->useAuthorQuery()
        ->filterByFirstName('Jane')
    ->endUse()
    ->find();
-----
<?php

$books = $q->withAuthorQuery(fn($q) => $q
        ->filterByFirstName('Jane'))
    ->find();
