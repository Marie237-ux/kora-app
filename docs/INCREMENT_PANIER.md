# Panier persistant — backend

Le panier appartient à une session opaque ou à un compte et persiste côté serveur. Il conserve quantité, sélection, date d’ajout, conditions sauvegardées et offre exacte. Il ne crée ni crédit, ni commande payée, ni réservation permanente.

## Commandes et fusion

Les mutations utilisent une version attendue et un UUID d’idempotence. Les commandes de compte sont communes aux appareils ; celles des visiteurs sont isolées par session. Un ajout rejoué ne double pas la quantité. Une modification concurrente avec une ancienne version reçoit un conflit.

La fusion après connexion consomme une seule fois le panier visiteur. Les quantités d’une même offre sont additionnées et signalées ; la sélection du compte est conservée. Les variantes distinctes restent des lignes distinctes. Un dépassement de quantité ou de taille refuse toute la transaction et conserve les deux paniers. Les contrôles des partages visiteurs et reçus d’import suivent le panier fusionné.

## Conditions et partage

Le serveur relit prix, disponibilité, minimum, attributs, variante, boutique et garanties dans les deux langues. Une empreinte des conditions actuelles est requise pour accepter un changement ; une empreinte devenue périmée est refusée. Les totaux XAF sont des chaînes exactes. Une ligne choisie indisponible rend le sous-total inconnu ; les lignes décochées restent conservées.

Les liens partagés expirent et sont révocables. Le registre conserve le SHA-256 du jeton ; le lien initial peut aussi figurer dans le résultat privé d’idempotence de son propriétaire. Une vue partagée expose seulement références, quantités et offres actuellement publiques. Aucun compte, adresse, téléphone, note privée, ancien prix ou paiement du propriétaire n’est publié.

La copie recalcule les conditions dans le panier destinataire, rapporte les offres indisponibles et ne réimporte pas le même partage avec un nouvel UUID d’action.

## Vérifications et intégrations restantes

Neuf tests fonctionnels couvrent sessions/comptes isolés, reprise multi-session, connexion réelle et fusion unique, conflits, prix/garantie, partage/copie/révocation/expiration, variantes distinctes, transfert des droits sur les partages, rollback de dépassement et totaux jusqu’à 10^18 XAF.

Un test PostgreSQL supplémentaire met deux processus réellement en attente sur la même ligne panier ; une seule modification passe avec la version initiale. Les suites complètes et compteurs sont dans VALIDATION.md.

Le contrat n’expose pas encore de total payable ni de livraison chiffrée : ces valeurs restent null et checkout_ready/stock_reserved restent false. Origines physiques détaillées, devis/créneaux/capacités, réservation transactionnelle, achat des seules lignes sélectionnées et suppression après résultat de commande réel restent à intégrer. Déplacement vers tableaux, listes cadeaux et interfaces FR/EN/thèmes font toujours partie du lancement.
