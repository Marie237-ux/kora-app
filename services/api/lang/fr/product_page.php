<?php

// Texts that come with a product on its page (App\\Domain\\Catalogue\\ProductService). :shop, :district and :city are the product's shop.
return [
    'delivery_intro' => 'Votre article part de l’entrepôt KORA quand il y est en stock, ou de la boutique qui le vend. Où que vous soyez au Cameroun, c’est KORA qui le prépare, l’envoie et vous tient informé jusqu’à la réception.',
    // Chosen after the cart, when paying: delivery in the shop's city, or shipping to another city.
    'modes' => [
        ['Livraison à :city', 'Par un livreur KORA, au créneau que vous choisissez, suivie en direct. Frais selon votre quartier.'],
        ['Expédition partout au Cameroun', 'Yaoundé, Bafoussam, Garoua, Kribi… Le colis part par un transporteur partenaire, suivi jusqu’à l’agence ou votre porte. Délai et frais selon votre ville.'],
    ],
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
        ['Zone', 'Livraison à :city, expédition dans tout le Cameroun'],
        ['Frais', 'Selon votre quartier ou votre ville, affichés avant le paiement'],
        ['Mode', 'Livraison ou expédition : vous choisissez au paiement'],
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
