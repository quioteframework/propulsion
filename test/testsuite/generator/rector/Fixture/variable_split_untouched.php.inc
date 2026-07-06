<?php

// Non-fluent (variable-split) chain -- the closing endUse() is called on a stored
// variable, not chained directly off the opener, so the spine walk never finds a
// balanced opener and must leave this untouched.
$sub = $q->useAuthorQuery();
$sub->filterByFirstName('Jane');
$q = $sub->endUse();
