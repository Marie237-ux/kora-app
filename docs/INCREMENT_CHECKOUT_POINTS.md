# Utilisation des points au paiement — backend du §23

Incrément du 4 novembre 2026, après la carte et le registre de gains décrits dans INCREMENT_FIDELITE.md. Réservation, consommation, libération, remise par ligne et restitution sont disponibles en API. Les écrans, la remise physique vérifiée, les avantages automatiques des niveaux et la comptabilisation restent obligatoires avant lancement.

## Règles et financement

Une politique d'échange distincte démarre désactivée, sans taux ni budget prérempli. Le propriétaire configure explicitement points par palier, valeur XAF du palier, plafond de remise, minimum total à payer, catégories exclues, budget monétaire, cumul de livraison et conditions FR/EN. Il choisit aussi la restitution des points en cas de retour et sa validité facultative.

Modification avec version attendue, `loyalty.settings`, MFA et mot de passe reconfirmé depuis moins de 15 minutes. Les révisions et empreintes sont conservées. Une perte sur article exige en plus `pricing.loss_override`, autorisation explicite et motif. Les coûts proviennent des accords fournisseurs réels, revérifiés à la commande ; aucune remise fournisseur n'est inventée pour masquer une perte. Les marges internes utilisent le total réellement vendu après fidélité.

Le budget XAF est distinct du budget d'émission des points. Les réservations monétaires non échues et les remises consommées comptent sous verrou global ; le budget ne descend pas sous leurs engagements. Les consommations cumulées restent des entiers exacts, y compris en cas de dépassement exceptionnel après paiement tardif. Un retour ne recycle pas silencieusement ce budget. Les budgets et motifs internes sont consultables par propriétaire/comptable autorisés, pas par le support ni le titulaire.

## Parcours API

1. Le client revoit son panier et choisit le créneau sur le devis de livraison habituel.
2. `POST /api/v1/account/loyalty/checkout-quotes` reçoit `cart_version`, `delivery_quote_id` et `points`. Le serveur calcule remise, frais, total et répartition. Cette étape ne réserve ni points ni budget.
3. Le devis de remise est privé, immuable, valable au plus dix minutes et jamais au-delà du devis de livraison. Il conserve les conditions du panier, la politique, le total exact et le choix de campagne. L'identifiant d'un autre titulaire n'est pas utilisable.
4. `POST /api/v1/orders` reçoit le devis de livraison, sa version, `accepted_total` et l'option `loyalty_quote_id`. Les conditions sont revérifiées ; la commande réserve atomiquement ses points et le budget. Une modification de politique, panier, campagne ou une insuffisance impose un nouveau devis, sans supplément caché.
5. Le paiement utilise le circuit Monetbil habituel et sa vérification serveur. Les points réservés sont consommés une seule fois après confirmation ; les reprises ou notifications doublées ne créent pas une seconde consommation.

Les lots de points les plus proches de leur expiration sont utilisés d'abord. La réservation protège leur validité pendant la fenêtre de paiement. À expiration de la commande impayée, les points sont libérés ; leur validité d'origine reste applicable, sans résurrection d'un lot déjà échu. Le compte affiche séparément les points réservés et cesse de les compter comme réservés lorsque leur délai expire, même avant le passage du planificateur.

Si le cumul de livraison est désactivé, le nouveau devis utilise les points sans la promotion de livraison ; le total correspondant est présenté avant acceptation. Si le cumul est activé, la campagne est recalculée sur le prix unitaire effectivement payé après remise. Une unité ne qualifie pas artificiellement une livraison offerte avec son prix d'avant fidélité. Le devis professionnel accepté conserve son prix figé ; cet incrément ne lui ajoute pas implicitement une nouvelle remise.

## Remise, facture et retours

La remise est répartie entre lignes admissibles avec des calculs entiers, des plus grands restes et des égalités stables. Chaque unité garde au moins 1 XAF de valeur monétaire remboursable ; le reste minimal total suit la politique publiée. La ligne conserve prix unitaire brut, remise, points affectés et total net. La commande, le montant Monetbil, la facture, la TVA explicitement configurée et les avoirs utilisent les mêmes montants nets. La facture PDF FR/EN montre la remise par ligne.

Exemple de test uniquement : 500 XAF de remise sur trois articles à 1 000 XAF donne 2 500 XAF d'articles payés. Le retour d'une unité rembourse 834 XAF et restitue 33 points sur les 100 dépensés ; les deux unités suivantes représentent 1 666 XAF et 67 points. Sur une rupture complète, les frais de livraison nets sont ajoutés selon le flux d'annulation existant. Les tests vérifient que les avoirs totalisent exactement la facture, sans rembourser en argent la partie payée par points.

Les demandes de remboursement fournisseur réservent dès leur création des tranches de quantités. Leur montant ne peut pas changer d'un franc si un autre retour est attribué entre-temps. Ces tranches restent `pending` jusqu'au versement vérifié. Le premier gain attend la fin de ces remboursements pour ne pas créditer des unités annulées et non livrées. Pour les autres retours, l'attribution explicite du remboursement déjà versé conserve les plafonds de quantité et montant.

La restitution porte uniquement sur les points réellement consommés, selon les conditions de la vente. Elle crée un lot identifiable avec ses conditions et sa date de validité, sans nouvelle émission de points fictifs. Une modification ultérieure de politique ne supprime pas la restitution promise. La reprise des points gagnés et la restitution des points dépensés sont deux mouvements distincts. Des points gagnés puis réellement dépensés sur une autre commande peuvent donner un solde ajusté négatif au retour ; cela ne bloque pas le remboursement monétaire dû.

## Paiements tardifs et résolution

Un paiement vérifié arrivé après libération conserve le prix annoncé. Si les points sont encore disponibles, ils sont réalloués sous verrou. Si leur validité a expiré, qu'ils ont été dépensés ailleurs ou que la carte est suspendue, aucun second débit n'est inventé : incident `loyalty_points_unavailable_after_payment`, commande payée et remise `review_required`. Le budget enregistre le coût réel de la vente ; son dépassement éventuel crée `loyalty_budget_exceeded_after_payment`.

La clôture financière utilise les routes existantes avec permission, MFA, confirmation récente et note. Une remise prise en charge à l'issue de la revue devient `covered`, sans inventer des points consommés ; une résolution par remboursement devient `review_resolved`. Les remboursements restent des versements vérifiés. Les gains de livraison attendent la résolution des incidents de fidélité ouverts. Aucune surcharge automatique ni nouvelle connexion Monetbil réelle n'est déclenchée.

## Installation, contrat et preuves

Migration `2026_11_04_000022_create_loyalty_redemptions`, additive : politique/révisions, devis privés, consommations et affectations aux lots, remise des commandes/lignes, mouvements de réservation et tranches de retour. Utiliser `php artisan migrate --no-interaction`, jamais une réinitialisation de la base consultée. Les anciennes commandes et attributions gardent zéro remise et leur statut payé existant.

`loyalty:reconcile --limit=100`, déjà planifié chaque minute, libère aussi les réservations échues. Le planificateur système doit toujours être installé et supervisé en exploitation. Les paramètres réels et les comptes clients ne sont pas créés par les migrations.

Contrat OpenAPI 0.18.0 : 258 chemins, types générés. Les résultats complets et rapports SQLite/PostgreSQL sont suivis dans VALIDATION.md. Les courses lancent deux processus indépendants : impossibilité de réserver les mêmes points sur deux checkouts et de dépasser le dernier budget monétaire entre deux comptes.

Paiements, preuves de versement et dates de remise utilisés par les tests restent des fixtures contrôlées. Aucun encaissement, remboursement réel, message, fournisseur externe ou déploiement exécuté ; aucune nouvelle dépendance installée. L'API du checkout est vérifiée séparément des futurs écrans et de la livraison physique.
