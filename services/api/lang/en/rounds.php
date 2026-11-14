<?php

return [
    'PICKUP_WAREHOUSE' => 'Collect :count parcel(s) at :place (:address) for :missions. Count the parcels, then confirm the pickup.',
    'PICKUP_COURIER' => 'Collect :count parcel(s) from courier :name for :missions, then confirm the pickup.',
    'DELIVER' => 'Deliver :count parcel(s) in :district (landmark: :landmark), slot :slot — mission :mission. Check the items with the customer, then enter their code.',
    'NO_SLOT' => 'no slot',
    'COLLECT' => 'Collect at :place (:address): :items — collection :collection. Count every item; if some are missing, note what the shop said.',
    'DROP' => 'Drop :count parcel(s) from :collections at :place (:address) and hand them to the stock team.',
    'RECEIVE_SHOP' => 'Receive at :place (:address): :items — order :order, collection :collection. Photograph the shop\'s slip, enter the serial numbers, then confirm the receipt.',
    'RECEIVE_SHOP_SUPERVISED' => 'Go to :place (:address) with :supervisor, who receives: :items — order :order, collection :collection.',
    'RETURN_PICKUP' => 'Collect from the customer in :district (landmark: :landmark): :items — case :cases, trip :trips. Check the quantity and serial numbers before leaving.',
    'RETURN_DROP' => 'Drop :count returned parcel(s) (:items) at :place (:address) and hand them to the stock team — case :cases.',
    'EXCHANGE_PICKUP' => 'Collect :count exchange parcel(s) (:items) at :place (:address) — case :cases.',
    'EXCHANGE_DELIVER' => 'Deliver the exchange item (:items) in :district (landmark: :landmark) — case :cases. Enter the customer\'s code.',
];
