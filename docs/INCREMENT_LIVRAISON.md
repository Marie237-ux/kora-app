# Livraison : zones, devis et créneaux — backend

Le devis de livraison est calculé côté serveur sur les lignes sélectionnées du panier, à partir des sources physiques réelles : stock détenu dans un dépôt KORA d'abord, puis point de collecte de la boutique partenaire. Le nombre de boutiques affichées dans le panier n'intervient pas.

## Paramétrage (gestion)

- **Zones** : code, nom FR/EN et quartiers desservis. Un quartier appartient à une seule zone active ; la comparaison ignore accents, casse et ponctuation (« Bonaprisó » = « bonapriso »).
- **Points de collecte** : rattachés soit à une boutique, soit à un dépôt, avec zone et priorité. Le point actif de plus faible priorité est retenu.
- **Grilles tarifaires** : brouillon, puis publication après relecture. Une grille publiée est figée ; en publier une nouvelle retire l'ancienne, et chaque devis garde la grille qu'il a utilisée. Le drapeau `demo` signale des montants fictifs, affichés comme tels.
- **Créneaux** : règles hebdomadaires en heure de Douala (jour, horaire, capacité, délai limite), pour une zone ou toutes, et fermetures datées qui réduisent ou annulent la capacité. La fermeture la plus restrictive s'applique.

Permissions : `delivery.read` (propriétaire, gestionnaire, planification) et `delivery.settings` (propriétaire). MFA obligatoire.

## Calcul du devis

Chaque contrainte est facturée une seule fois :

| Élément | Règle |
| --- | --- |
| Base | Forfait de la zone de destination |
| Collectes supplémentaires | Supplément × (nombre de points de collecte distincts − 1) |
| Trajet | Supplément configuré pour chaque point situé dans une autre zone (paire orientée origine → destination) |
| Poids | Une tranche pour le colis entier, d'après le poids des profils physiques |
| Encombrant / fragile | Au plus un supplément de chaque, si une ligne l'exige |

Le client voit le détail et le nombre de collectes, jamais l'identité des points, les emplacements de stock ni les coûts.

Aucun montant n'est supposé. Si une donnée manque, le devis passe en `confirmation_required` avec un total null et la raison : grille absente, poids inconnu, poids hors grille, disponibilité fournisseur non vérifiée ou insuffisante, ou point de collecte manquant. Il passe en `unserviceable` pour une zone non desservie ou une classe de transport non livrable (froid, non livrable). Un panier dont les conditions ont changé doit d'abord être revu.

Le délai de préparation combine le délai fournisseur vérifié et celui du dépôt configuré dans la grille. Il masque les créneaux trop proches.

## Adresses et créneaux (client)

Une adresse se décrit par quartier, repère, consignes et contacts ; la carte est facultative. Elle indique tout de suite si la zone est desservie. Elle est archivée, jamais supprimée, pour que les devis passés gardent l'adresse exacte.

Un devis valable propose les créneaux des sept prochains jours. Réserver un créneau prend une place jusqu'à l'expiration du devis, sous un verrou par créneau : la dernière place ne peut pas être prise deux fois, et une réservation expirée libère sa place d'elle-même. Un devis devenu obsolète (panier modifié) ou expiré ne peut plus réserver.

## Vérifications

`DeliveryTest` : 9 tests (185 assertions). `PostgresConcurrencyTest` : deux processus PHP indépendants attendent réellement sur le même verrou de créneau ; un seul obtient la dernière place. Contrat 0.9.0 / 190 chemins via `scripts/contract/update-delivery-contract.mjs`.

## Restant obligatoire

- Confirmation du créneau et figement du devis au paiement, quand la commande existera ; libération à l'annulation.
- Promotions de livraison (trois premières livraisons, §16).
- Service de distance réel éventuel, tournées, véhicules, capacités de transport et livreurs (§15, §20).
- Écrans boutique et gestion FR/EN, clair/sombre.
