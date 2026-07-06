<?php

// Real-world shape: three levels of nesting, a where()/_or()/where() pair at the
// bottom, and a trailing filterBy*() call sitting in the *same segment* as a nested
// pair that has already closed (Item's segment = ItemRelation pair + a plain call
// after it) -- proves mixed pair+plain-call segments resolve correctly across passes.
LibraryEntryQuery::create()
            ->useItemQuery()
                ->useItemRelationQuery()
                    ->useRelationQuery('relation')
                        ->where('relation.RefBuyer = ?', $company->getId())
                        ->_or()
                        ->where('relation.RefSupplier = ?', $company->getId())
                    ->endUse()
                ->endUse()
            ->filterByRefAuthorCompany($company->getId(), Criteria::NOT_EQUAL)
            ->endUse()
            ->filterByIsAttachment(false)
            ->count();
-----
<?php

// Real-world shape: three levels of nesting, a where()/_or()/where() pair at the
// bottom, and a trailing filterBy*() call sitting in the *same segment* as a nested
// pair that has already closed (Item's segment = ItemRelation pair + a plain call
// after it) -- proves mixed pair+plain-call segments resolve correctly across passes.
LibraryEntryQuery::create()->withItemQuery(fn($q3) => $q3->withItemRelationQuery(fn($q2) => $q2->withRelationQuery(fn($q) => $q
                        ->where('relation.RefBuyer = ?', $company->getId())
                        ->_or()
                        ->where('relation.RefSupplier = ?', $company->getId()), 'relation'))
            ->filterByRefAuthorCompany($company->getId(), Criteria::NOT_EQUAL))
            ->filterByIsAttachment(false)
            ->count();
