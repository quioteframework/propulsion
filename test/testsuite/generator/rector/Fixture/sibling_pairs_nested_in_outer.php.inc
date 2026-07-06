<?php

// The real-world pain point: two sibling relations (Book, Publisher) both queried
// inside a single outer relation (Author) -- must produce two chained with*Query()
// calls inside the outer closure, not a single merged one.
$q = $q->useAuthorQuery()
        ->useBookQuery()
            ->filterBySomething('xyz')
        ->endUse()
        ->usePublisherQuery()
            ->filterBySomethingElse('quux')
        ->endUse()
    ->endUse();
-----
<?php

// The real-world pain point: two sibling relations (Book, Publisher) both queried
// inside a single outer relation (Author) -- must produce two chained with*Query()
// calls inside the outer closure, not a single merged one.
$q = $q->withAuthorQuery(fn($q2) => $q2->withBookQuery(fn($q) => $q
            ->filterBySomething('xyz'))->withPublisherQuery(fn($q) => $q
            ->filterBySomethingElse('quux')));
