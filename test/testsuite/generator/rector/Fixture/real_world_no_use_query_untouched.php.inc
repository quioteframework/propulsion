<?php

// Real-world shape with condition()/combine()/where() and no useQuery()/endUse() at
// all (variable-split, if/else branches) -- must be left completely untouched, since
// there is nothing for this rule to match.
$companiesToCompaniesQuery = CompaniesToCompaniesQuery::create("ctc")
    ->condition("a", "ctc.RefBuyer = ?", $partnerCompanyId)
    ->condition("b", "ctc.RefSupplier = ?", $ownCompany->getId())
    ->condition("c", "ctc.RefBuyer = ?", $ownCompany->getId())
    ->condition("d", "ctc.RefSupplier = ?", $partnerCompanyId);

$companiesToCompaniesQuery
    ->combine(["a", "b"], "and", "ab")
    ->combine(["c", "d"], "and", "cd");

if ($ownCompany->getBusinessUnitEnabled() || $partnerCompany->getBusinessUnitEnabled()) {
    $companiesToCompaniesQuery
        ->condition("bbu", "ctc.RefBuyerBU IS NOT NULL")
        ->condition("sbu", "ctc.RefSupplierBU IS NOT NULL")
        ->where(["bbu", "sbu"], "or");
} else {
    $companiesToCompaniesQuery
        ->condition("bbu", "ctc.RefBuyerBU IS NULL")
        ->condition("sbu", "ctc.RefSupplierBU IS NULL")
        ->where(["bbu", "sbu"], "and");
}

$relationship = $companiesToCompaniesQuery->find();
