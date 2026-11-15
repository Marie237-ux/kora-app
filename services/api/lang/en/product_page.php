<?php

// Texts that come with a product on its page (App\\Domain\\Catalogue\\ProductService). :shop and :district are the product's shop.
return [
    'delivery_intro' => 'Your item ships from the KORA warehouse when it is in stock there, or from the shop that sells it. Either way, KORA prepares it, delivers it and keeps you posted all the way to your door.',
    'route_warehouse' => [
        ['In stock at KORA', 'Prepared in the KORA warehouse, ready to go'],
        ['Delivery', 'By a KORA courier, tracked live'],
        ['Arrival', 'At your home, in the slot you chose'],
    ],
    'route_shop' => [
        ['At :shop', 'Prepared by the shop in :district, picked up by KORA'],
        ['Delivery', 'By a KORA courier, tracked live'],
        ['Arrival', 'At your home, in the slot you chose'],
    ],
    'terms' => [
        ['Area', 'All of Douala'],
        ['Fee', 'Based on your district, shown before payment'],
        ['Slot', 'You choose it when ordering'],
        ['Tracking', 'Live, to your door'],
    ],
    'warranty_title' => 'shop warranty, followed up by KORA.',
    'warranty_intro' => 'If an item has a problem, you are not alone with the shop: KORA opens the case, follows it and keeps you posted at every step.',
    'warranty_steps' => [
        ['Your invoice', 'It stays available in your account, under orders.'],
        ['A case', 'If something goes wrong, open an after-sales case from your account with a description and photos.'],
        ['We handle it', 'KORA follows the case with :shop: diagnosis, repair, exchange or refund according to the warranty terms.'],
        ['You follow it all', 'You follow every step of the case and talk with the team in the messages.'],
    ],
    'bulk_intro' => 'For a shop, an event or a company: buy by the bulk unit, at KORA's wholesale price.',
    'bulk_steps' => [
        ['A written quote', 'Ask for a quote from the professional space of your account: the price depends on the quantity.'],
        ['You approve', 'The quote is sent to you in writing; you accept it before paying.'],
        ['Payment and delivery', 'You pay through the usual checkout; KORA delivers the order in one or several drops.'],
    ],
];
