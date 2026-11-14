# Comptabilité : premier socle backend

Évolution ultérieure : la capture configurable, le recensement des sept familles depuis les faits et les reçus d’événements sont désormais décrits dans INCREMENT_CAPTURE_COMPTABLE.md. Les passages « capture explicite seulement » ci-dessous décrivent le périmètre du premier socle, avant cet ajout. La préparation et la validation des écritures restent explicites.

Incrément du 4 novembre 2026, rattaché au §22 du prompt maître. Le module reste partiel : le journal en partie double et la capture explicite de sources sont disponibles ; l’intégration exhaustive, les rapprochements et les états réglementaires restent à construire.

## Livres et paramétrage

Aucun livre, compte, exercice, période, journal ou schéma métier n’est créé par la migration. Plusieurs livres de démonstration sont possibles ; un seul livre réel est autorisé. La création du livre réel exige l’identité exploitant et le régime fiscal renseignés dans les paramètres de facturation. L’identité est conservée dans le livre.

Le propriétaire importe un plan complet, versionné et référencé. Codes numériques fournis explicitement, parents présents et préfixant leurs enfants, comptes actifs/imputables et auxiliaires `none/customer/supplier`. Les comptes omis sont désactivés ; leurs identifiants et instantanés historiques restent conservés. Une réimportation invalide l’examen du plan et oblige à actualiser les brouillons ordinaires.

Le rôle comptable examine la version et son empreinte avant comptabilisation réelle. Le propriétaire seul ne possède pas `accounting.review`. Les schémas par type de source sont eux aussi versionnés, activables, examinés et fondés sur des mesures explicites. Ils conservent la version du plan : après réimportation, un nouveau schéma doit être enregistré et examiné avant usage réel. Aucun numéro de compte ni qualification achat-revente/agent, taxe déductible, commission, frais prestataire ou traitement d’avance n’est choisi automatiquement.

Le cadre demandé est le SYSCOHADA applicable à l’entreprise. La référence publique consultée est la présentation officielle de l’[AUDCIF par l’OHADA](https://www.ohada.org/en/uniform-act-relating-to-accounting-law-and-financial-information-audcif/) et son [annonce officielle](https://www.ohada.org/publication-du-nouvel-acte-uniforme-relatif-au-droit-comptable-et-a-l-information-financiere-audcif/). Ces pages ne constituent ni un plan importé, ni une validation de schéma, ni une certification de ce logiciel. Les règles et formats effectivement applicables doivent encore être confirmés par le comptable de l’entreprise.

## Écritures et corrections

Un brouillon manuel contient journal, date, description FR/EN, référence, pièces et lignes débit/crédit exprimées en chaînes d’entiers XAF. Chaque ligne a exactement un côté positif et un compte actif imputable du même livre ; les comptes auxiliaires exigent un client/fournisseur existant. Le nom du tiers et les caractéristiques du compte sont instantanés côté serveur.

La validation exige des totaux positifs égaux, au moins deux comptes distincts, une période et un exercice ouverts, un journal actif, le plan courant et la chronologie des dates dans le journal/exercice. Les totaux d’une écriture sont bornés à `Money::MAX`. En réel, le plan et le schéma de source sont examinés ; une écriture manuelle exige une pièce privée contrôlée. Le numéro est attribué transactionnellement par livre/exercice/journal ; il comprend la date complète de début d’exercice pour distinguer deux exercices courts commencés dans la même année civile, avec préfixe `DEMO-` dans les livres de démonstration.

La validation fige un instantané et son empreinte canonique SHA-256. Des déclencheurs PostgreSQL et SQLite refusent ensuite la modification/suppression de l’écriture et l’ajout/modification/suppression de ses lignes. Les mêmes déclencheurs vérifient l’équilibre et la période lors de la validation. Le code applicatif et les migrations utilisent un compte de base privilégié : ces protections ne remplacent pas des sauvegardes et une séparation des accès en exploitation.

La correction passe par une contrepassation liée, unique par original, validée atomiquement dans une période ouverte, avec motif et montants exactement inversés. L’original et ses instantanés restent intacts. Les brouillons seuls peuvent être annulés ; chaque révision et transition est auditée.

## Sources métier

Capture explicite unique par livre/type/ressource, sans consommateur automatique de l’outbox ni comptabilisation à la seule consultation d’un document :

| Type | Fait admis et base conservée |
| --- | --- |
| `customer_invoice` | Facture émise et empreinte vérifiée ; total net de remise, base hors taxe/TVA lorsqu’elles existent, articles, livraison et remise fidélité |
| `customer_credit_note` | Avoir émis et empreinte vérifiée ; total et ventilation fiscale du document |
| `customer_payment` | Encaissement confirmé, montant réellement reçu, XAF, référence prestataire et mode test explicitement faux ; frais encore non rapprochés |
| `customer_refund` | Remboursement réellement marqué payé, montant, référence, canal et pièce privée |
| `supplier_obligation` | Dette fournisseur reconnue, montant initial et origine ; facture fournisseur approuvée lorsqu’elle en est la cause |
| `supplier_payment` | Règlement fournisseur vérifié avec montant effectif, référence et pièce ; intention exclue et allocation d’avance non déduite |
| `goods_receipt` | Coût unitaire réel multiplié par quantité acceptée disponible, hors consignation, quarantaine et excédent |

Les factures/avoirs de démonstration sont exclus du livre réel. Les montants d’une dette ne sont pas remplacés par son solde restant après paiement. Facture et encaissement, avoir et remboursement, réception et dette sont des sources distinctes : leur articulation doit être prévue dans les schémas retenus pour éviter de doubler ventes, stocks ou charges.

Un schéma explicite associe mesure, côté et compte. `prepare` crée ou actualise le brouillon de source ; il n’est pas modifiable par la route manuelle. Un décalage de date comptable par rapport à l’événement exige un motif. La validation revérifie la source, son empreinte et le schéma courant ; une seule écriture validée est possible par source. Une source déjà comptabilisée retourne son écriture existante. Les ajustements ultérieurs de valorisation ou de qualification ne sont pas générés dans cet incrément.

## Calendrier et éditions

Les exercices ne se chevauchent pas, les périodes appartiennent à leur exercice sans chevauchement. La clôture bloque si des brouillons ou des sources connues non comptabilisées subsistent dans l’intervalle. La clôture annuelle exige des périodes clôturées couvrant exactement l’exercice. La réouverture motivée est réservée au propriétaire ; l’exercice doit être rouvert avant une période. Le verrou du livre est partagé par imports, écritures, captures et clôtures.

Balance, grand livre et auxiliaires sont calculés uniquement sur les écritures validées, dans un snapshot de lecture cohérent. Les filtres imposent exercice et plage de dates ; `account_id` et `party_id` permettent la consultation auxiliaire. L’ouverture est calculée depuis le début de l’exercice choisi, sans reprise automatique d’un exercice précédent. Les totaux emploient des entiers de précision arbitraire et sont retournés sous forme de chaînes ; le grand livre pagine les détails par 24 sans tronquer ses totaux.

Les éditions indiquent livre/mode, période, statut provisoire, couverture `explicit_capture_only` et nombre de sources connues non comptabilisées. Ce compteur ne constitue pas une preuve d’exhaustivité des événements métier et ne signifie pas que les rapprochements bancaires sont faits. Aucun bilan, résultat réglementaire ou cash disponible n’est déduit de la seule égalité débit/crédit.

## API et accès

Préfixe `/api/v1/operations/accounting` : `books`, `source-types`, puis `books/{book}/chart`, `journals`, `years`, `years/{year}/periods`, `entries`, `mappings`, `sources` et `reports/trial-balance` / `reports/ledger`. Actions documentées dans OpenAPI 0.19.0, 284 chemins au total. Types TypeScript générés depuis le contrat.

Toutes les routes sont privées, soumises à session et MFA de gestion. Mutations avec clé d’idempotence ; paramétrage, examen, validation, contrepassation et clôture/réouverture exigent une confirmation du mot de passe depuis quinze minutes au plus. Propriétaire : paramétrage, saisie, validation, contrepassation, clôture et réouverture. Comptable : lecture, saisie, examen, validation, contrepassation et clôture. Aucun accès initial des autres rôles. Les pièces `accounting_evidence` n’ont pas de fournisseur et exigent `accounting.write`/`accounting.read`.

## Vérifications finales

PostgreSQL : 292 tests, 6 778 assertions, aucun échec ni test ignoré. SQLite : 292 recensés, 270 exécutés, 6 283 assertions, 22 réservés à PostgreSQL ; aucun échec. Vingt tests fonctionnels et deux courses ajoutés. Preuves finales dans preuves/backend-postgresql.xml et preuves/backend-sqlite.xml ; suites exécutées successivement. Huit tests npm, types OpenAPI synchronisés, TypeScript/Astro et formatage vérifiés. Migration locale appliquée sans réinitialisation ni données comptables fictives. Détail : VALIDATION.md.

## Suite obligatoire

- Recensement exhaustif et consommateurs idempotents des événements, suivi des sources absentes/écartées et résolution des sources modifiées ; le compteur actuel porte seulement sur les captures connues.
- Lettrage, rapprochements banque/caisse/Monetbil/Mobile Money, dépenses et frais réellement constatés ; avances et reclassements explicites.
- Valorisation de stock configurée, coût des ventes, retours, pertes/écarts et ajustements ; articulation consignation, achats/revente et commissions selon contrats.
- Traitements fidélité/parrainage et subventions de campagnes, immobilisations/amortissements, reports d’ouverture et clôture annuelle complète.
- États financiers et exports applicables, marge contributive distincte du résultat/cash, interfaces et parcours FR/EN vérifiés visuellement.

R22 et V35 restent partiels. Les protections techniques de V34/V36 sont testées côté API et SQL ; les scénarios interactifs complets restent ouverts. Aucune opération financière réelle, publication distante, dépendance supplémentaire ou interface n’est livrée par cet incrément.

## Évolution du lettrage

INCREMENT_LETTRAGE_COMPTABLE.md complète désormais le lettrage partiel/total et les reliquats des auxiliaires sur tous les exercices, avec annulations datées. Les limites du socle ci-dessus conservent leur contexte historique ; autres événements/valorisations, dépenses/frais, états/exports complets et interfaces restent obligatoires.
