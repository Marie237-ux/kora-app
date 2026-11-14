<?php

return [
    'PICKUP_WAREHOUSE' => 'Récupérer :count colis au :place (:address) pour :missions. Comptez les colis puis confirmez l’enlèvement.',
    'PICKUP_COURIER' => 'Récupérer :count colis auprès du livreur :name pour :missions, puis confirmez l’enlèvement.',
    'DELIVER' => 'Livrer :count colis à :district (repère : :landmark), créneau :slot — mission :mission. Contrôlez les articles avec le client puis saisissez son code.',
    'NO_SLOT' => 'sans créneau',
    'COLLECT' => 'Collecter chez :place (:address) : :items — collecte :collection. Comptez chaque article ; s’il en manque, notez ce que dit la boutique.',
    'DROP' => 'Déposer :count colis de :collections au :place (:address) et les remettre à l’équipe stock.',
    'RECEIVE_SHOP' => 'Réceptionner chez :place (:address) : :items — commande :order, collecte :collection. Photographiez le bon de la boutique, saisissez les numéros de série, puis confirmez la réception.',
    'RECEIVE_SHOP_SUPERVISED' => 'Aller chez :place (:address) avec :supervisor, qui réceptionne : :items — commande :order, collecte :collection.',
    'RETURN_PICKUP' => 'Récupérer chez le client à :district (repère : :landmark) : :items — dossier :cases, déplacement :trips. Vérifiez la quantité et les numéros de série avant de partir.',
    'RETURN_DROP' => 'Déposer :count colis de retour (:items) au :place (:address) et les remettre à l\'équipe stock — dossier :cases.',
    'EXCHANGE_PICKUP' => 'Récupérer :count colis d\'échange (:items) au :place (:address) — dossier :cases.',
    'EXCHANGE_DELIVER' => 'Livrer l\'article d\'échange (:items) à :district (repère : :landmark) — dossier :cases. Saisissez le code du client.',
];
