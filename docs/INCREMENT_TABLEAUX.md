# Tableaux de favoris et listes cadeaux — backend

Un compte crée des tableaux (collection ou liste cadeau), privés par défaut, avec sections, épingles ordonnées, couverture et quantités souhaitées. Une épingle garde le nom, le SKU et le prix à l’enregistrement ; la vue expose séparément l’offre publiée actuelle, ou null si elle a été retirée, sans casser le tableau. Cent tableaux actifs, trente sections et cinq cents épingles au maximum.

## Commandes, versions et journal

Toutes les mutations passent par le bus de commandes : UUID d’idempotence, version attendue relue sous verrou, conflit 409 sur une version périmée. Chaque changement incrémente la version et inscrit un événement unique (tableau, version) dans le journal consultable par les membres. Le déplacement ou la copie d’une épingle verrouille les deux tableaux dans un ordre stable et vérifie les deux versions.

Les notes personnelles sont propres à leur auteur : un éditeur ne lit pas celles du propriétaire, et une copie ne reprend une note que sur demande explicite et seulement la sienne.

## Partage et collaboration

- Publication publique explicite après relecture (`reviewed`), révocable ; elle supprime tout lien non répertorié.
- Lien non répertorié opaque, expirant (1 à 90 jours), stocké en SHA-256 ; un nouveau lien remplace l’ancien.
- Les vues publiques et partagées n’exposent ni notes, ni membres, ni coordonnées.
- Invitations éditeur/lecteur liées à une adresse stockée en HMAC avec un indice partiel ; acceptation explicite par le compte vérifié correspondant. Aucun message externe envoyé : le propriétaire transmet le lien lui-même (`external_message_sent: false`).
- Révoquer un membre bloque lectures, écritures et rejeux de commandes en cache, et annule ses invitations en attente. L’archivage rend le tableau privé et révoque liens et invitations.

## Liens avec le panier

`to-cart` ajoute les épingles choisies au panier avec les conditions actuelles et rapporte les offres indisponibles. `from-cart` déplace ou copie une ligne de panier atomiquement, avec cumul de quantité optionnel. Les frais de collecte restent null et `delivery_quote_required` true tant que le devis de livraison n’existe pas.

## Vérifications et restant

Onze tests fonctionnels (BoardsTest) couvrent isolation des comptes, notes privées, liens/publication, invitations, révocation, copie/déplacement versionnés, panier et réordonnancement. Contrat 0.8.0 généré par `scripts/contract/update-boards-contract.mjs` ; tests de contrat étendus aux routes des tableaux.

Restent obligatoires : statut acheté et réservation cadeau (dépend de la commande client), livraison à l’adresse du destinataire sans lui révéler ses coordonnées, interfaces FR/EN clair/sombre et animation d’épingle. `purchase_tracking_available` et `recipient_delivery_available` restent false jusque-là.
