<?php

// where()/_or()/where() and similar plain intermediate calls are neither an opener nor
// endUse(), so they should just pass through untouched as part of the closure body.
$q = $q->useAuthorQuery()
        ->where('Author.FIRST_NAME = ?', 'Jane')
        ->_or()
        ->where('Author.FIRST_NAME = ?', 'John')
    ->endUse();
-----
<?php

// where()/_or()/where() and similar plain intermediate calls are neither an opener nor
// endUse(), so they should just pass through untouched as part of the closure body.
$q = $q->withAuthorQuery(fn($q) => $q
        ->where('Author.FIRST_NAME = ?', 'Jane')
        ->_or()
        ->where('Author.FIRST_NAME = ?', 'John'));
