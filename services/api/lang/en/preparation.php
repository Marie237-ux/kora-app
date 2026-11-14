<?php

return [
    'PAID_OPEN_ORDER_REQUIRED' => 'Only a paid order that is still open can be prepared.',
    'ALREADY_READY' => 'This order is already declared ready. Report an anomaly to reopen it.',
    'RESERVATION_NOT_IN_ORDER' => 'This stock reservation does not belong to this order.',
    'RESERVATION_NOT_ACTIVE' => 'This reservation has already been issued or released.',
    'IDENTIFIERS_MISMATCH' => 'The serial or IMEI numbers entered do not match the units reserved for this order.',
    'ANOMALY_OPEN' => 'A preparation anomaly is still open.',
    'PREPARATION_INCOMPLETE' => 'Every non-cancelled unit must be issued from stock before the order is declared ready.',
    'NOTHING_TO_PREPARE' => 'Every unit of this order is cancelled: nothing to prepare.',
    'QUANTITY_INVALID' => 'The quantity exceeds the non-cancelled units of this line.',
    'ANOMALY_RESOLVED' => 'This anomaly is already resolved.',
    'MISSION_ACTIVE' => 'A delivery mission is under way: handle the problem from the mission or cancel it first.',
    'PACKAGES_REQUIRED' => 'State at least one parcel: no depot parcel is only possible when everything comes from a shop on the way.',
];
