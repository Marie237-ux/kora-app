# Règles métier conservées

- Boutique publique, fournisseur contractuel, produit/variante, offre et source physique sont distincts. Le dépôt ne devient pas une boutique fictive.
- XAF entier, taux exacts, coût négocié calculé sur le prix de référence. À 5 000 et 3 %, coût 4 850 ; vente 4 900 financée par KORA : coût inchangé, écart 50 avant frais. Le frontend ne fixe jamais les montants.
- Quantités et capacités réservées atomiquement, expiration/libération, paiement tardif avec réallocation ou incident. La disponibilité fournisseur non confirmée n'est pas un stock garanti.
- Livraison calculée selon les sources physiques réelles et grille versionnée. Une panne distance ne donne jamais un tarif nul inventé.
- Trois premières livraisons admissibles : au moins un article éligible de prix unitaire >= 5 000 XAF, zone configurée, budget et couverture base/total explicites. Pas de seuil panier de 15 000.
- Paiement navigateur non probant ; vérification serveur Monetbil, montant/devise/service/référence, effets idempotents et gestion du trop-perçu réel.
- Achat comptant, crédit fournisseur et dépôt-vente ont des obligations et propriétés distinctes. Une réception n'est pas un paiement. Dette de 100 000 réglée réellement de 30 000 : reliquat 70 000, indépendant des ventes clients.
- Conditions, coûts internes, contrats et garanties figés à l'opération ; documents émis et écritures validées immuables. Correction reliée et auditée.
- Comptabilité en partie double, périodes protégées, rapprochements ; encaissement, revenu, trésorerie disponible et bénéfice distincts.
- Fidélité par registre transactionnel ; progression indépendante des points dépensés. Pas de double gain/dépense ; remboursement monétaire non bloqué par des points consommés.
- Demandes de produit privées, sans débit ni promesse de disponibilité ; notification seulement après publication effective et dédupliquée. Photos client privées.
- Tableaux privés par défaut, partage révocable ; cadeaux sans fuite d'adresse. Livreur limité à sa mission actuelle, code de remise à usage unique.
- IA : données fournisseur non fiables, proposition validée par humain, catalogue réel seulement ; aucun droit financier.

La totalité des contraintes détaillées du prompt demeure applicable. Cette synthèse ne les remplace pas.

## Accords et publication actuellement implémentés

Priorité résolue : produit, puis catégorie héritée la plus proche, puis fournisseur ; au sein du périmètre, palier de quantité admissible le plus élevé puis priorité. Un même rang ne peut couvrir deux périodes qui se chevauchent ; une ambiguïté existante bloque la résolution. Les accords retirés restent stockés ; les révisions publiées conservent leur règle et calcul. La future commande doit encore figer ses propres conditions.

Hors promotion active, S = R. Une simulation future utilise la date de début de promotion et l’accord effectif à cette date. Le financement KORA + fournisseur doit égaler R - S ; une contribution fournisseur exige une référence d’accord, sans déduction automatique sur le coût d’achat. Une marge négative exige un propriétaire autorisé et un motif conservé. Les budgets/cumuls/remboursements du moteur de campagnes complet restent à implémenter.

Les totaux de devis internes exposés à l’interface sont des chaînes décimales XAF afin de ne pas perdre d’unités à la lecture JSON dans JavaScript. Les calculs serveur restent des entiers ; BigInt sert seulement au formatage.

## Stock, achats et dette actuellement implémentés

Les réservations internes exigent une durée configurée et l'offre/variante/garantie exactes. La disponibilité fournisseur peut être inconnue ou périmée ; demande et confirmation restent distinctes. Un excédent reçu reste isolé jusqu'à l'avenant accepté. Les séries/IMEI sont tracés par unité ; inventaires et transferts ne consomment pas les réservés et ne réintroduisent pas les consommés.

Une facture saisie est corrigible avant approbation, avec historique et version attendue ; seule l'approbation reconnaît une obligation unique. Réception et règlement restent indépendants. Pour une échéance sur première réception enregistrée, la séquence d'audit tranche les réceptions enregistrées dans la même seconde ; une saisie ultérieure antidatée ne déplace pas l'ancre déjà déterminée.

Règlements constatés sur pièce clean, montants réels distincts des demandes, référence de transaction unique par compte. Affectations de règlements/avoirs append-only, limitées au même fournisseur et au reliquat. Renégociation sur le montant nominal intégral ; couverture des échéances par date puis séquence avant filtrage. Le solde à une date représente la connaissance enregistrée à cette date.

Dépôt-vente : propriété fournisseur, aucun dû automatique sur réception/invendus ; relevé de ventes constatées au coût figé, sans seconde remise. Les pièces originales sont privées et leur scan doit réussir. Ces traitements manuels ne remplacent pas les futures commandes/paiements clients ni la comptabilité complète.


Les alertes proches utilisent un horizon explicitement choisi ; les rappels suivent les jours ISO et l'heure Douala configurés. Une notification en file n'est pas déjà remise. Régler, renégocier ou désactiver avant la remise peut supprimer celle-ci ; une réapparition a un nouveau cycle, sans duplication journalière de rappel.

Le prévisionnel est limité aux engagements/dettes fournisseurs et ventes consignées à rapprocher. Il ne déduit pas arbitrairement les avances/avoirs non affectés et ne transforme pas les ventes en cash disponible. Les champs de trésorerie inconnus restent null. L'ordre audité des avenants tranche les coûts non facturés quand des dates de saisie sont identiques.

## Fidélité : carte et gains

Programme désactivé sans règle commerciale préremplie. Règles FR/EN versionnées et conservées par commande ; gain unique après paiement et date de livraison issue du futur workflow vérifié, sans endpoint permettant de simuler cette preuve. Articles admissibles au prix vendu, hors livraison et catégories explicitement exclues ; retours attribués avant le premier gain déduits avant arrondi/plafond. Points en attente puis acquis selon délai, validité facultative et budget de points réservé sous verrou, sans recyclage silencieux des anciens engagements.

Solde dérivé des mouvements, corrections motivées/réconfirmées réservées au propriétaire. Reprise proportionnelle des gains sur les unités d'un remboursement vérifié ; une expiration ou annulation précédente ne provoque pas de seconde reprise. Solde négatif possible et compensé par les autres crédits, sans bloquer l'argent dû au client et sans dette ressuscitée à expiration. Niveaux sur dépenses admissibles nettes livrées pendant une période glissante, indépendants des corrections du solde.

QR opaque/révocable : consultation minimale seulement, sans identité, solde, authentification ou action financière. Le registre de points ne vaut ni monnaie rechargeable ni solde retirable. Détails du socle : INCREMENT_FIDELITE.md.

Le checkout en points dispose désormais de règles de conversion et budget XAF distincts, avec devis privé serveur, total net exact accepté, réservation sous verrou, consommation unique au paiement vérifié et libération à expiration. Remise et points figés par ligne ; tranches de retour et remboursements monétaires nets, avec restitution selon les conditions initiales. Les gains attendent les versements liés aux unités déjà annulées. Le cumul de livraison est explicite et réévalué après remise ; une perte exige autorisation propriétaire motivée. Paiement tardif sans points disponibles : incident puis décision financière auditée, jamais un double débit ou supplément au client. Avantages automatiques des niveaux, rapprochement comptable, remise physique et écrans restent à livrer. Détails : INCREMENT_CHECKOUT_POINTS.md.

## Comptabilité en partie double

Livre et dates doivent être configurés ; aucune qualification financière implicite des événements. Les écritures validées sont équilibrées, numérotées et figées avec comptes/tiers/pièces ; correction par contrepassation exacte liée et auditée. Le verrou du livre sérialise écritures, import du plan et clôtures. En réel, un comptable examine le plan et les schémas de source en vigueur ; une modification du plan invalide les anciens schémas.

Facture et encaissement, avoir et remboursement, réception et dette sont des sources distinctes. Un schéma explicite doit éviter de doubler chiffre d’affaires, charges ou stock. Les paiements de test, intentions de versement et stocks consignés ne sont pas transformés en encaissements réels ou stocks achetés. Les états provisoires portent uniquement sur le journal validé et les sources explicitement capturées ; le solde d’ouverture commence à l’exercice sélectionné, sans report automatique. Aucune égalité débit/crédit ne vaut rapprochement bancaire ou certification. Voir INCREMENT_COMPTABILITE.md pour les limites et travaux restants.

## Capture comptable et couverture des faits

La capture d’événements est désactivée par défaut. Son activation par livre exige une date de début explicite ; cette date peut ensuite seulement être avancée vers le passé. Une pause du traitement ne supprime ni les obligations de capture du périmètre, ni les contrôles de clôture.

Le consommateur tient des reçus propres à chaque livre et ne marque pas les autres consommateurs de l’outbox comme terminés. Il récupère aussi les faits des sept familles sans notification, capture une fois les données vérifiées et conserve les exclusions/anomalies. Les effets d’une capture échouée sont annulés ; après cinq échecs locaux consécutifs, la reprise est explicite et auditée. Une source déjà figée n’est pas écrasée par une correction automatique.

Le recensement des candidats et incidents complète les contrôles des brouillons/sources non comptabilisées à la clôture. Il ne vaut pas rapprochement bancaire, traitement des domaines encore absents ou certification. Préparation et validation restent explicites. Voir INCREMENT_CAPTURE_COMPTABLE.md.

## Rapprochements de trésorerie

Un relevé transcrit avec preuve reste un solde déclaré ; ni chiffre d’affaires ni paiement confirmé ne vaut solde disponible. Correspondance explicite de compte/sens, liée au plan et examinée avant usage réel. Dates et reprise de solde contiguës après le premier relevé ; aucune ouverture comptable inventée.

Rapprochement à nets exactement égaux entre lignes de relevé et lignes déjà validées du même livre/compte/intervalle, sans double consommation ni frais implicites. Correction par annulation motivée conservant les membres historiques. Clôture stricte sans ligne restante, nouveau posting/contrepassation refusé avant réouverture propriétaire auditée. Un relevé ouvert bloque la clôture comptable concernée ; une période sans relevé n’est jamais réputée rapprochée. Le traitement des décalages de dates et la vérification des fonds disponibles restent à construire. Voir INCREMENT_RAPPROCHEMENTS_TRESORERIE.md.

## Mouvements en circulation et clôtures historiques

Un mouvement non rapproché demeure visible : son report requiert preuve, motif, date attendue après la fin du relevé et examen comptable. Son créateur ne peut l’approuver en réel. Seules les lignes validées ou lignes de relevé courantes et celles héritées de la dernière clôture précédente sont admissibles ; les dates et contenus d’origine restent inchangés. Les nets nuls ne dispensent pas du traitement de chaque ligne.

Chaque reconduction nécessite une nouvelle décision examinée ; chaque clôture conserve un snapshot immuable de ses totaux, groupes et reports. Le règlement ultérieur laisse ce bilan intact. Avant réouverture d’un prédécesseur, rouvrir les successeurs puis retirer leurs groupes et décisions actifs ; leurs nouveaux traitements attendent sa reclôture. Aucun report ne vaut argent disponible, abandon de dette, frais ou paiement. Voir INCREMENT_REPORTS_TRESORERIE.md.

## Couverture explicite et soldes de trésorerie

Une obligation de couverture naît uniquement d'une version examinée, avec date de début fixe par livre/trésorerie. Les relevés manquants, ouverts ou sans preuve de solde correspondant à la version active bloquent les clôtures de période/exercice. Aucun compte non configuré n'est réputé couvert.

Ouverture et clôture relient le cumul exact du journal au solde du relevé, augmenté des écritures en attente et diminué des lignes de relevé en attente. Les montants et empreintes de provenance doivent correspondre ; une contrepassation de net nul ne préserve pas l'examen ancien. Le passage d'exercice ne crée aucune ouverture comptable automatique. Une correction antérieure exige la réouverture de toutes les clôtures couvertes puis un nouvel examen de l'ouverture ; preuves antérieures conservées. Aucune disponibilité ou authenticité bancaire déduite du rapprochement. Voir INCREMENT_COUVERTURE_TRESORERIE.md.

## Lettrage des auxiliaires

Le lettrage rapproche explicitement des montants positifs entre lignes comptables validées du même livre, compte auxiliaire et tiers. Affectations partielles possibles, débits/crédits égaux, plafond par opération de 10^18 XAF, aucun dépassement du reliquat ni compensation automatique au seul net nul. Les instantanés historiques du compte/tiers restent la référence après changement du plan.

Créations et annulations respectent des dates non décroissantes par livre, en période/exercice ouverts. Les lignes historiques peuvent être lettrées au jour courant sans réouverture de leur exercice ; une annulation conserve les membres et ne libère les montants qu’à sa date effective. Les rapports cumulent tous les exercices à date et exposent les reliquats, sans changer les dettes/factures/encaissements métier. Voir INCREMENT_LETTRAGE_COMPTABLE.md.

## Dépenses et engagements documentés

Un budget de dépense devient engagé après examen comptable, sans créer de dette ou écriture automatique. Les règlements partiels rattachent des sorties manuelles déjà validées du même compte de trésorerie, avec preuve propre et montant exact accepté. Une écriture entière reste attachée une seule fois dans tout son historique ; les transferts internes et sources métier sont exclus. Aucun paiement externe n’est émis.

Annuler exige le reliquat courant exact et libère seulement la part non dépensée. Une correction passe par la contrepassation liée dans une transaction unique ; période ou relevé fermé fait échouer toute la correction. Les rapports datés préservent les événements antérieurs et calculent les entiers exacts. Leur périmètre est limité aux budgets de dépenses ; ils ne certifient ni disponibilité globale ni fonds externes. Voir INCREMENT_DEPENSES_ENGAGEMENTS.md.

## Prévisionnel consolidé des engagements connus

Le livre réel unique réunit les reliquats fournisseurs, achats non reconnus, ventes consignées à rapprocher, dépenses approuvées nettes à date effective et remboursements demandés. Les montants sans échéance restent sans date ; avances/avoirs non affectés ne compensent aucune autre dette automatiquement. Les livres de démonstration excluent les sources globales et dépenses des autres livres.

La fenêtre classe les échéances de l’état courant, sans fabriquer un historique as_of. Les totaux peuvent dépasser 10^18 et l’entier natif, toujours en chaînes exactes, avec les plafonds des opérations inchangés. Collectes confirmées et relevés datés ne prouvent ni fonds détenus, ni disponibilité, ni montant retirable : ces inconnues restent null. Toute la réponse partage un snapshot de lecture cohérent. Voir INCREMENT_PREVISIONNEL_TRESORERIE.md.

## Retraits manuels et réceptions partielles

Une demande externe déclarée inclut déjà son reliquat dans les retraits attendus, avant son examen. Chaque versement brut/net/frais utilise une écriture manuelle entière déjà validée, sans nouvelle dépense de transfert. Annulation externe du reliquat exact et correction de saisie sont distinctes ; références opaques réservées ou reprises uniquement avec prédécesseur explicite selon le cas. Contrepassations atomiques après annulation des rapprochements des deux lignes ; remplacement après annulation borné au montant du reçu corrigé de cette même demande. Aucun fonds disponible certifié, instruction prestataire ou affectation automatique des frais à un budget. Voir INCREMENT_RETRAITS_MANUELS.md.

## Constats datés des soldes externes

Les fonds propres disponibles hors crédit/découvert, les fonds détenus chez Monetbil et leur sous-ensemble retirable sont déclarés sur preuve privée examinée, à un instant UTC explicite avec expiration choisie au plus 24 heures plus tard. Solde négatif conservé et fonds propres disponibles nuls ; absence de preuve signifie inconnu. La dernière séquence prévaut même si en attente, corrigée ou expirée : aucun ancien montant approuvé ne revient automatiquement. Les totaux exigent tous les comptes du périmètre et un instant strictement commun ; aucune extrapolation après mouvement ultérieur ni disponibilité actuelle certifiée. Voir INCREMENT_CONSTATS_SOLDES.md.

## Financements monétaires de l’activité

Distinguer avances du fondateur, apports et emprunts selon les pièces et la contrepartie explicitement examinées. Un accord approuvé n’est pas de l’argent reçu ; seuls les versements liés au journal existant alimentent le principal réellement financé. Les remboursements sont plafonnés à ce principal, sans renouvellement de capacité, et interdits comme remboursements ordinaires d’apport. Annuler ne clôt que le non-versé accepté ; la dette reçue reste due. Corrections exactes après annulation des rapprochements, net financé jamais inférieur au remboursé, sans réemploi de l’écriture comme dépense. Le prévisionnel reprend seulement le principal restant dû, sans date inventée ni déduction de fonds disponibles. Voir INCREMENT_FINANCEMENTS.md pour les intérêts/frais, échéanciers et opérations hors de ce périmètre.
