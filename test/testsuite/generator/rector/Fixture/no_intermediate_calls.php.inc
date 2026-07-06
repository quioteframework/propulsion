<?php

// Degenerate case: useQuery()->endUse() with nothing in between -- must still produce
// valid (if pointless) output rather than erroring.
$q = $q->useAuthorQuery()->endUse();
-----
<?php

// Degenerate case: useQuery()->endUse() with nothing in between -- must still produce
// valid (if pointless) output rather than erroring.
$q = $q->withAuthorQuery(fn($q) => $q);
