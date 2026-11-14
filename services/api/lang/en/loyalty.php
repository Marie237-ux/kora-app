<?php

return [
    'REDEMPTION_RULES_INVALID' => 'Redemption rules must define positive and consistent amounts.',
    'REDEMPTION_BUDGET_COMMITTED' => 'The monetary budget cannot fall below consumed and reserved discounts.',
    'REDEMPTION_BUDGET_REQUIRED' => 'The remaining budget cannot fund this discount. Request a new quotation.',
    'REDEMPTION_NOT_AVAILABLE' => 'Point redemption is unavailable or the card is suspended.',
    'POINTS_UNAVAILABLE' => 'The required points are unavailable or do not match the redemption step.',
    'REDEMPTION_LIMIT' => 'The discount exceeds the eligible limit for selected items.',
    'MINIMUM_PAYABLE' => 'The discount would violate the minimum payable amount.',
    'REDEMPTION_QUOTE_INVALID' => 'The point quotation expired or its conditions changed. Review the new total.',
    'REDEMPTION_LOSS_NOT_APPROVED' => 'A negative-margin discount requires explicit, reasoned owner approval.',
    'REDEMPTION_COST_UNAVAILABLE' => 'Purchase terms must be verified before granting this discount.',
    'RULES_INVALID' => 'Programme rules and tiers must be consistent, starting at a zero threshold.',
    'BUDGET_COMMITTED' => 'The budget cannot fall below points already committed.',
    'BUDGET_REQUIRED' => 'Configured rules and a sufficient points budget are required.',
    'ADJUSTMENT_LIMIT' => 'The adjustment exceeds the ledger limit.',
    'NO_PROGRAM_SNAPSHOT' => 'This order does not participate in the loyalty programme.',
    'PAID_REFUND_REQUIRED' => 'Attribution requires a verified paid refund for this order.',
    'RETURN_QUANTITY_INVALID' => 'Previously attributed and requested quantities exceed the sold line.',
    'RETURN_EXCEEDS_REFUND' => 'The value of the returned items exceeds the verified refund.',
];
