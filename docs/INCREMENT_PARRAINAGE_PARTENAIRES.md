# Parrainage et partenaires — backend

Incrément du 11 novembre 2026. Il couvre le parrainage à un niveau (§24, premier volet ; scénario V63) et les partenaires avec leurs candidatures (§28, scénarios V01 et V67 côté API). Les avis, alertes et sélections éditoriales du reste du §24 viendront ensuite.

## Parrainage

**Programme** (`referral.manage`) : versions successives, une seule active à la fois. Activation avec confirmation récente du mot de passe. Les paramètres sont tous définis par le propriétaire, **aucune valeur imposée** (les anciennes hypothèses de 2 %, 1 000 et 2 000 FCFA ne sont pas reprises) :
- taux en points de base, plafond par achat ;
- nombre d'achats récompensés (2 selon le cahier des charges, jusqu'à 5, à fixer) ;
- seuil de versement, délai de validation après livraison et budget total.

Une version retirée continue de s'appliquer aux parrainages qu'elle a signés.

**Code et rattachement** :
- chaque client a un code opaque (`K…`) sans nom ni numéro ;
- un **nouveau client** (aucun achat payé) se rattache une seule fois à un parrain ; son propre code est refusé ;
- un téléphone ou une adresse en commun avec le parrain **signale** le parrainage pour une revue humaine (validé ou bloqué), sans le refuser : ce n'est jamais une preuve de fraude à lui seul.

**Gains** (tâche planifiée `referrals:reconcile`, chaque minute) :
- seuls les **premiers achats admissibles** du filleul, payés puis livrés, comptent ; **rien pour l'inscription seule** ;
- l'assiette est le montant des articles payés, remise fidélité déduite, remboursements versés déduits, livraison exclue ;
- le gain reste en attente pendant le délai de validation ; à l'échéance, l'assiette est recalculée. Un achat entièrement remboursé est annulé et **l'achat suivant prend sa place** ;
- le **budget** du programme est respecté au franc près (verrou sur le programme) : gain réduit, ou épuisé ;
- un parrainage signalé n'est pas réglé avant la revue ; un parrainage bloqué voit ses gains annulés.

**Versements** :
1. le parrain demande un versement (mobile money, virement ou espèces) entre le seuil et son solde validé ;
2. la finance l'approuve ou le refuse avec une note ;
3. elle constate ensuite le versement avec une preuve privée analysée et la référence du canal (`finance.write`, confirmation récente).

Le parrain voit son code, les conditions, ses filleuls **anonymes** (« #1 », sans identité ni numéro de commande), ses gains, son solde et ses versements. Ces gains sont des créances payables, **séparées de la carte de fidélité**.

## Partenaires et candidatures

**Candidature publique** (`POST partner-applications`, sans compte, 5 envois par minute) :
- contenu : identité du contact, activité, téléphone (séparateurs acceptés), e-mail, ville et quartier, types de produits, message, et **accord sur l'usage des données** (version du consentement conservée) ;
- un champ piège laissé vide par les personnes : un robot qui le remplit reçoit la même réponse, mais rien n'est enregistré ;
- une candidature antérieure au même téléphone ou e-mail est signalée à l'équipe seulement.

**Suivi** (`partners.read` / `partners.manage`) :
- notes et pièces privées (« partner_evidence ») ; décision motivée ; ce que le candidat a envoyé n'est jamais réécrit ;
- **conversion** d'une candidature acceptée, par les outils du catalogue (historique et contrôles habituels) : un fournisseur existant est relié, ou un nouveau est créé depuis la candidature, avec une boutique en brouillon facultative ;
- **sans doublon** : refus si un fournisseur au même téléphone ou e-mail existe déjà (il faut alors le relier), ou si ce fournisseur vient déjà d'une autre candidature.

**Page Partenaires** :
- fiches avec nom, domaine, présentation et avantages vérifiables (FR/EN), logo issu de la médiathèque, site ;
- la publication exige la présentation dans les deux langues et un logo public s'il y en a un ;
- la page publique (`GET partners`) ne montre que les partenaires **publiés**, sans coordonnées privées. Le lien vers la boutique n'apparaît que lorsque la boutique elle-même est publiée ;
- sans partenaire publié, `meta.has_partners` vaut faux : le site affiche une **invitation honnête** à rejoindre le projet, jamais de marques fictives.

## Protections

Garde-fous SQL (PostgreSQL et SQLite) :
- programmes figés une fois créés, sauf activation ou retrait ; un seul actif ;
- un seul parrain par client ;
- gains réglés une seule fois, jamais au-dessus de l'assiette ;
- versement payé seulement avec preuve, référence et auteur ;
- codes de parrainage immuables ;
- candidatures non réécrites, consentement obligatoire, une seule conversion par fournisseur ;
- un partenaire par fournisseur ; notes en ajout seul.

## Migrations, contrat et vérifications

Migrations additives `2026_11_11_000042_create_referrals` et `2026_11_11_000043_create_partners`, appliquées à la base locale `kora` (vide). Sous PostgreSQL, la référence d'une candidature vers une autre (doublon) est ajoutée après la création de la table ; le numéro de version des programmes est calculé sans agrégat verrouillé, que PostgreSQL refuse. Contrat **0.38.0 / 442 chemins** (`update-referrals-partners-contract.mjs`) ; la chaîne rejouée donne une empreinte identique.

Tests :
- `ReferralsTest` (2) : programme activé, codes, refus (propre code, code inconnu, second parrain, client déjà acheteur), deux gains créés puis un remboursement qui annule le second et fait compter le troisième, budget plafonné, filleuls anonymes, seuil de versement, approbation puis versement avec preuve, protections SQL ; téléphone partagé signalé, gain non réglé avant revue, revue réservée au gestionnaire du parrainage, droits ;
- `PartnersTest` (2) : page vide puis publiée, consentement exigé, piège anti-robot, doublon signalé, note, pièce, décision, conversion en fournisseur et boutique, double conversion refusée, publication exigeant les deux langues, fiche publique sans coordonnées privées et sans lien vers une boutique non publiée, archivage ; droits et protections.

Résultats : [VALIDATION.md](VALIDATION.md).

## Limites et restant obligatoire

- Avis vérifiés, photos modérées, signalements et réponses, alertes de prix et de disponibilité, historique consulté, racheter, sélections éditoriales, préférences marketing et désabonnement (reste du §24).
- Exécution réelle des versements de parrainage par un prestataire (aujourd'hui constatés à la main avec preuve) et écritures comptables associées.
- Portail fournisseur pour suivre sa candidature ; notifications ; écrans.
