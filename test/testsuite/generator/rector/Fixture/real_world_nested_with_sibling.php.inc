<?php

// Real-world shape: a nested pair (Item > ItemRelation) followed by a sibling pair
// (DeliveryControlReminder) at the top level, with per-call aliases and a raw add()
// call inside the innermost closure.
$orders = PurchaseOrderQuery::create('order')
            ->filterByIsGlobalDeliveryControlDisabled(false)
            ->useItemQuery('i')
                ->filterByRefAuthorCompany($reminder->getRefAuthorCompany())
                ->filterByCreationTime($ordersAfterTime, Criteria::GREATER_EQUAL)
                ->useItemRelationQuery('ir')
                    ->add(null, "ir.ref_relation IN (SELECT ref_relation FROM p_order.delivery_control_relation WHERE ref_reminder = " . $reminder->getId() . ")", Criteria::CUSTOM)
                ->endUse()
            ->endUse()
            ->useDeliveryControlReminderQuery('dcr')
                ->filterByRefOrder(null)
            ->endUse();
-----
<?php

// Real-world shape: a nested pair (Item > ItemRelation) followed by a sibling pair
// (DeliveryControlReminder) at the top level, with per-call aliases and a raw add()
// call inside the innermost closure.
$orders = PurchaseOrderQuery::create('order')
            ->filterByIsGlobalDeliveryControlDisabled(false)->withItemQuery(fn($q2) => $q2
                ->filterByRefAuthorCompany($reminder->getRefAuthorCompany())
                ->filterByCreationTime($ordersAfterTime, Criteria::GREATER_EQUAL)->withItemRelationQuery(fn($q) => $q
                    ->add(null, "ir.ref_relation IN (SELECT ref_relation FROM p_order.delivery_control_relation WHERE ref_reminder = " . $reminder->getId() . ")", Criteria::CUSTOM), 'ir'), 'i')->withDeliveryControlReminderQuery(fn($q) => $q
                ->filterByRefOrder(null), 'dcr');
