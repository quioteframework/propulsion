<?php

// Five levels deep -- proves the fixpoint loop (one level resolved per pass) scales
// to arbitrary depth without the rule itself needing recursion.
$q = $q->useAuthorQuery()
        ->useBookQuery()
            ->useReviewQuery()
                ->useTagQuery()
                    ->useLabelQuery()
                        ->filterByName('deep')
                    ->endUse()
                ->endUse()
            ->endUse()
        ->endUse()
    ->endUse();
-----
<?php

// Five levels deep -- proves the fixpoint loop (one level resolved per pass) scales
// to arbitrary depth without the rule itself needing recursion.
$q = $q->withAuthorQuery(fn($q5) => $q5->withBookQuery(fn($q4) => $q4->withReviewQuery(fn($q3) => $q3->withTagQuery(fn($q2) => $q2->withLabelQuery(fn($q) => $q
                        ->filterByName('deep'))))));
