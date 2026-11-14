# Demandes privées de produits — backend

Le parcours API du §12.1 est implémenté : brouillon avant connexion, reprise après connexion réelle, confirmation explicite de l’envoi, suivi privé, précision, retrait, propositions et choix d’une alternative. Les interfaces FR/EN clair/sombre et l’achat issu d’une demande restent à construire.

## Confidentialité et traitement

Une session opaque possède le brouillon visiteur ; l’envoi le rattache au compte authentifié. Les commandes rejouées sont isolées par session et compte. Le serveur impose les versions, l’idempotence, les limites d’envoi et les transitions ; aucune demande ne crée un paiement ou une réservation.

JPEG/PNG/WebP : 2 Mio, 8 mégapixels et huit photos actives au maximum. L’orientation est corrigée et les métadonnées retirées ; seul le WebP normalisé privé est conservé. Les fichiers exigent une autorisation actuelle et leur SHA-256 est contrôlé. Les liens de référence HTTPS ne sont jamais téléchargés.

La boîte équipe exclut les brouillons. Affectation, priorité, regroupements et propositions fournisseur restent internes. Les échanges client et notes internes sont séparés. Les coûts nécessitent les droits achats et ne figurent jamais dans les réponses client.

La création depuis une demande utilise le vrai service de brouillon catalogue. Elle reprend le texte dans sa langue source, sans traduire, publier ni réutiliser les photos privées. Les champs catalogue restent validés normalement.

## Notifications et reprise

Une offre reliée mais non publiée ne déclenche aucune annonce de disponibilité. Après publication effective, le worker recontrôle l’offre et l’état de la demande, puis inscrit une notification de compte unique. Une alternative produit d’abord une proposition ; le client doit l’accepter. Une offre archivée n’est plus exposée comme achetable dans l’ancienne notification.

Les handlers locaux prennent maintenant en charge ContentPublished (projection vers les demandes), ProductRequestOfferReady et ProductRequestMessageAdded, en plus des rappels fournisseurs. Résultats, reçus atomiques et reprises bornées utilisent l’outbox existante. Aucun email, SMS, message WhatsApp ou push externe n’est envoyé.

## Brouillons expirés

KORA_REQUEST_DRAFT_DAYS fixe la durée technique des brouillons ; KORA_SESSION_COMMAND_DAYS celle des réponses de commandes de session (sept jours par défaut pour chacune). La commande `php artisan product-requests:cleanup-drafts --limit=100` est planifiée chaque heure. Elle retire les fichiers avant leurs enregistrements, conserve les suppressions en échec pour réessai et expurge les snapshots privés expirés ; un ancien UUID de commande ne rejoue pas le contenu expiré. Les demandes envoyées restent conservées. Ces durées techniques ne constituent pas une politique légale de conservation des dossiers.

## Vérifications exécutées

- 14 tests fonctionnels ciblés réussis sur SQLite : 187 assertions.
- Les 10 premiers tests ont aussi été exécutés sur PostgreSQL ; la suite complète PostgreSQL couvre ensuite les 14.
- Un test supplémentaire utilise deux processus PHP réellement bloqués sur le même verrou PostgreSQL : deux événements distincts pour une demande produisent une seule notification et une seule transition.
- Le contrat OpenAPI 0.5.0 décrit les 133 chemins implémentés et les types TypeScript sont régénérés.
- Aucun appel à un prestataire externe ni parcours navigateur n’est déclaré validé.

Les indicateurs donnent la cohorte des demandes envoyées, états actuels, regroupements, propositions, brouillons/offres liés et publications effectives. Le délai de première réponse mesure les messages destinés au client, en excluant les notes internes. Les dates sont interprétées à Douala, sur 366 jours au maximum, avec snapshot cohérent. L’attribution d’achats reste null, sans faux zéro, jusqu’à intégration des commandes réelles.

Restant obligatoire : interfaces, enrichissement des indicateurs par produits recherchés/achats réels, intégration à la recherche photo avec consentement et canaux externes configurés.
