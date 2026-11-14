# Fidélité — carte et registre de gains

Premier incrément backend du §23, le 4 novembre 2026, complété ensuite par INCREMENT_CHECKOUT_POINTS.md pour la réservation, consommation, remise financée et restitution. Ce document décrit le socle de gains ; les écrans, avantages automatiques des niveaux et remise physique vérifiée restent à livrer. Le programme complet n'est pas déclaré terminé.

## Configuration et carte

La migration 000021 crée un programme désactivé, sans barème, avantage ou compte client prérempli. Le propriétaire configure des conditions FR/EN : montant par tranche, points par tranche, arrondi inférieur ou au plus proche, plafond par commande, catégories exclues explicitement, délai de validation, validité facultative, budget de points, inclusion des professionnels et niveaux sur une période glissante. Les exemples de tests n'activent aucune offre commerciale.

Chaque modification exige la version attendue, le MFA, une confirmation du mot de passe depuis moins de 15 minutes et `loyalty.settings`. Les règles publiées conservent version et empreinte ; une commande garde les règles en vigueur et sa catégorie vendue. Un changement ultérieur ne réécrit pas ses gains. Le budget ne peut pas descendre sous les points déjà engagés ; expiration, retours et débits ne le recyclent pas automatiquement. Ce budget en points est distinct du budget XAF de l'échange, désormais décrit dans INCREMENT_CHECKOUT_POINTS.md.

Le client crée explicitement sa carte, une seule par compte. Numéro opaque, statut, points et progression sont disponibles en API. Le lien QR contient un jeton aléatoire de 256 bits ; son empreinte et sa copie chiffrée sont conservées côté serveur. Le titulaire peut le renouveler, révoquant immédiatement l'ancien.

La consultation QR expose uniquement KORA, quatre caractères du numéro, le statut et le nom du niveau. Ni identité, téléphone, adresse, solde, connexion, ni opération financière. Réponses `no-store`, `noindex` et `no-referrer`. Le support autorisé consulte le registre sans recevoir le QR privé. La représentation visuelle de la carte et l'image QR seront produites dans les futurs écrans.

## Attribution des gains

`loyalty:reconcile --limit=100` traite les commandes ayant simultanément paiement confirmé, règles figées et `delivered_at` non futur. Le champ de livraison est réservé au futur workflow de remise vérifiée : aucune route de fidélité ne permet de prétendre qu'une commande est livrée. Les tests utilisent une fixture explicite de cette preuve ; les missions et preuves réelles restent à construire. Dans l'application actuelle, aucun paiement seul ne crédite donc un gain de livraison.

Une commande a au plus un gain, sous verrou de commande, de compte et de budget. Le calcul utilise les articles admissibles au prix effectivement vendu (y compris prix professionnel négocié), hors livraison et catégories exclues. Les quantités déjà remboursées et attribuées avant le premier gain réduisent la base avant l'arrondi et le plafond. Après cette attribution, les retours reprennent proportionnellement le gain, avec arrondi inférieur cumulé et reprise intégrale au retour complet de la base.

Un budget insuffisant produit un gain `funding_required` visible, sans faux points disponibles. Une augmentation autorisée permet sa reprise, sans deuxième attribution. Les gains financés restent en attente jusqu'à leur date de validation, puis deviennent acquis ; une validité éventuelle commence à cette date. Les conditions/version du gain restent consultables. La tâche d'expiration et de validation est bornée et rejouable ; les points expirés ne sont plus affichés disponibles même si le planificateur est en retard.

## Registre, retours et corrections

Le solde est dérivé exclusivement des mouvements append-only : attente, acquisition, annulation du gain en attente, reprise sur retour, expiration, ajustement motivé et compensation interne. Explications visibles FR/EN. Les corrections exigent `loyalty.adjust`, MFA et confirmation récente ; seul le propriétaire dispose initialement du droit de correction. Un crédit manuel consomme aussi le budget global, sans rechargement payant ni retrait.

Les ruptures fournisseur disposent déjà de lignes/quantités exactes : après remboursement vérifié, leur attribution de fidélité est automatique. Pour un autre remboursement, `operations/loyalty/refunds/{id}/lines` permet à un propriétaire autorisé de relier une fois les unités effectivement remboursées. Il contrôle l'appartenance à la commande, les quantités déjà attribuées et le montant versé ; une livraison ou un trop-perçu ne sont pas arbitrairement traités comme un retour d'article. Cette attribution est distincte du transfert d'argent et ne modifie pas la facture émise.

Un solde de points négatif reste possible après retour ou correction ; il ne bloque pas un remboursement monétaire dû. Les nouveaux crédits compensent les reprises antérieures. Les crédits inutilisés déjà expirés et les annulations précédentes ne sont pas repris deux fois. La compensation entre lots évite qu'une dette déjà absorbée réapparaisse à expiration du crédit suivant.

Le niveau dépend des dépenses admissibles nettes livrées pendant la période configurée, indépendamment des points disponibles et des corrections. Les sommes de progression et proportions de retour utilisent des entiers exacts, dont `Brick\Math` déjà requis par Laravel ; aucune nouvelle dépendance installée.

## Installation et preuves

Appliquer `2026_11_04_000021_create_loyalty_ledger` avec `php artisan migrate --no-interaction` depuis `services/api`, sans réinitialiser la base consultée. Vérifier `php artisan schedule:list`, qui doit contenir `loyalty:reconcile --limit=100` chaque minute. Le planificateur système reste à installer et superviser en exploitation.

Contrat OpenAPI 0.17.0 : 254 chemins, types client générés. Routes privées `/api/v1/account/loyalty`, gestion `/api/v1/operations/loyalty`, consultation minimale `/api/v1/loyalty/cards/{token}`. Toutes les écritures utilisent session, CSRF et clé UUID d'idempotence.

Les tests et rapports complets actualisés sont détaillés dans VALIDATION.md. Deux processus PostgreSQL vérifient le gain unique d'une commande et l'impossibilité de dépasser le dernier budget de points entre deux comptes. Ces contrôles ne constituent pas une preuve de livraison réelle ou de dépense de points au paiement.

## Travail obligatoire restant

- Réservation/consommation, libération, remise par ligne, budget monétaire, restitution et preuves de concurrence désormais traités dans INCREMENT_CHECKOUT_POINTS.md ; parcours interactifs et paiement réel restant à vérifier.
- Avantages automatiques des niveaux réellement financés, moteur complet de campagnes, cumul enrichi avec devis professionnels et comptabilisation.
- Workflow physique de livraison vérifiée alimentant `delivered_at`, SAV/retours complets et rapprochement des remboursements non encore attribués.
- Carte visuelle, génération graphique du QR et écrans FR/EN mobiles, puis parcours interactifs du checkout en points.

La fidélité reste distincte du parrainage payable. Aucun encaissement, retrait, versement externe, message ou déploiement n'est exécuté par cet incrément.
