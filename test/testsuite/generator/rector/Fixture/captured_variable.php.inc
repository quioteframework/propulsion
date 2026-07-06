<?php

// A filter argument pulled from the enclosing scope, at two nesting depths -- proves
// the arrow-function form needs no `use (...)` clause at any level.
function buildQuery(string $firstName, string $bookTitle)
{
    return $q->useAuthorQuery()
            ->filterByFirstName($firstName)
            ->useBookQuery()
                ->filterByTitle($bookTitle)
            ->endUse()
        ->endUse();
}
-----
<?php

// A filter argument pulled from the enclosing scope, at two nesting depths -- proves
// the arrow-function form needs no `use (...)` clause at any level.
function buildQuery(string $firstName, string $bookTitle)
{
    return $q->withAuthorQuery(fn($q2) => $q2
            ->filterByFirstName($firstName)->withBookQuery(fn($q) => $q
                ->filterByTitle($bookTitle)));
}
