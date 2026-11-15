<?php

// Texts that come with a product on its page (App\\Domain\\Catalogue\\ProductService). :shop and :district are the product's shop.
return [
    'delivery_intro' => 'Votre article part de l’entrepôt KORA quand il y est en stock, ou de la boutique qui le vend. Dans les deux cas, c’est KORA qui le prépare, vous le livre et vous tient informé jusqu’à votre porte.',
    'route_warehouse' => [
        ['En stock chez KORA', 'Préparé dans l’entrepôt KORA, prêt à partir'],
        ['Livraison', 'Par un livreur KORA, suivi en direct'],
        ['Réception', 'Chez vous, au créneau que vous avez choisi'],
    ],
    'route_shop' => [
        ['Chez :shop', 'Préparé par la boutique à :district, récupéré par KORA'],
        ['Livraison', 'Par un livreur KORA, suivi en direct'],
        ['Réception', 'Chez vous, au créneau que vous avez choisi'],
    ],
    'terms' => [
        ['Zone', 'Tout Douala'],
        ['Frais', 'Selon votre quartier, affichés avant le paiement'],
        ['Créneau', 'Vous le choisissez à la commande'],
        ['Suivi', 'En direct, jusqu’à votre porte'],
    ],
    'warranty_title' => 'de garantie boutique, suivie par KORA.',
    'warranty_intro' => 'Si un article pose problème, vous n’êtes pas seul face à la boutique : KORA ouvre le dossier, le suit et vous tient informé à chaque étape.',
    'warranty_steps' => [
        ['Votre facture', 'Elle reste disponible dans votre compte, rubrique commandes.'],
        ['Un dossier', 'En cas de problème, ouvrez un dossier après-vente depuis votre compte, avec une description et des photos.'],
        ['On s’en occupe', 'KORA suit le dossier avec :shop : diagnostic, réparation, échange ou remboursement selon les conditions de la garantie.'],
        ['Vous suivez tout', 'Vous suivez chaque étape du dossier et échangez avec l’équipe dans la messagerie.'],
    ],
    'bulk_intro' => 'Pour un commerce, un événement ou une entreprise : achetez par unité de gros, au prix de gros KORA.',
    'bulk_steps' => [
        ['Un devis écrit', 'Demandez un devis depuis l’espace professionnel de votre compte : le prix dépend de la quantité.'],
        ['Vous validez', 'Le devis vous est envoyé par écrit ; vous l’acceptez avant de payer.'],
        ['Paiement et livraison', 'Vous payez par le parcours habituel ; KORA livre la commande en une ou plusieurs fois.'],
    ],
];
