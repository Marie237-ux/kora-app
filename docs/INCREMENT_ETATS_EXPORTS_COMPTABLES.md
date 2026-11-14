# États et exports comptables — backend

Incrément du 13 novembre 2026, rattaché au §22. Il ajoute les états de synthèse paramétrés (compte de résultat, bilan), les exports du livre (CSV, tableur, PDF) et la marge contributive opérationnelle. Il complète la balance et le grand livre consultables en API depuis INCREMENT_COMPTABILITE.md.

## États de synthèse paramétrés

- **Aucun état n'est déduit d'un plan de comptes.** L'entreprise écrit un **modèle** par état (compte de résultat ou bilan) :
  - des rubriques FR/EN alimentées par des **préfixes de comptes** ;
  - un sens de lecture (débit ou crédit) ;
  - pour le bilan, un côté (actif ou passif) ;
  - des **totaux** de rubriques précédentes (+ ou −) ;
  - un périmètre de comptes à couvrir.
- Contrôles :
  - un même compte ne peut pas être compté dans deux rubriques (préfixes qui se chevauchent refusés) ;
  - un total ne reprend que des rubriques placées avant lui.
- Chaque enregistrement crée une **version figée**. Le **comptable l'examine** sur son empreinte (droit `accounting.review`, confirmation récente). Le modèle examiné le plus récent sert aux états. Sans modèle, l'état est **refusé** plutôt qu'inventé.
- Calcul sur les **écritures validées seulement** :
  - compte de résultat : mouvements de la période ;
  - bilan : position à la date de fin depuis le début de l'exercice, sans report automatique d'un exercice précédent (comme la balance).
  - Les totaux sont exacts, en entiers de précision arbitraire.
- **Rien n'est masqué** :
  - les comptes du périmètre qui ont un solde mais qu'aucune rubrique ne couvre sont listés ;
  - pour le bilan, totaux actif/passif et **écart** affichés (le résultat de l'exercice non encore affecté y apparaît honnêtement).
- **Provisoire ou définitif**, avec les raisons :
  - modèle non examiné ;
  - plan non examiné ;
  - périodes ouvertes ;
  - sources connues non comptabilisées ;
  - lignes de relevé non rapprochées.
  - L'état n'est « définitif » que sans aucune de ces raisons. Il n'y a jamais de certification comptable ou fiscale.

## Exports

- Rapports : **balance, grand livre, journal, compte de résultat, bilan**.
- Formats :
  - **CSV** (point-virgule, UTF-8 avec BOM pour les tableurs) ;
  - **XLSX** (classeur standard, montants en nombres) ;
  - **PDF** (lecture et classement, 3 000 lignes au plus ; au-delà, tableur conseillé).
- **Gros volumes** : les lignes sont écrites dans un fichier temporaire au fil de la lecture, sans tout charger en mémoire.
- **Protection des tableurs** : un texte qui commencerait par `=`, `+`, `-`, `@` (par exemple une référence piégée `=HYPERLINK(...)`) reçoit une apostrophe et n'est pas exécuté. Les montants restent des nombres exacts.
- **Grand livre** par numéro de compte : chaque compte s'ouvre sur son solde antérieur dans l'exercice, puis donne ses lignes avec le solde courant.
- **Journal** avec les noms de colonnes du FEC français (JournalCode, EcritureNum, CompteNum…), format d'échange courant. **Ce n'est pas un fichier réglementaire camerounais ou OHADA** : le comptable confirme le format attendu par l'administration.
- **Export sensible** : droit de lecture comptable et **confirmation récente de l'identité**.
- Chaque export est **tracé** et la trace ne peut pas être modifiée : rapport, format, période, nombre de lignes, taille, empreinte SHA-256 (aussi renvoyée dans l'en-tête `X-Export-Sha256`), auteur.
- Un livre de démonstration est signalé dans le nom du fichier et sur le PDF.

## Marge contributive opérationnelle

- Par **commande, produit, boutique ou campagne de livraison**, sur les commandes payées d'une période (dates de Douala). Droit `finance.read`.
- Calcul : chiffre d'affaires articles net des remises de commande − remboursements payés − coût des marchandises − subventions des campagnes de livraison. La livraison encaissée est montrée à part.
- **Coût réel** quand les unités sont tracées (lots du dépôt, lignes d'achat affectées). Sinon il est **estimé** depuis l'accord fournisseur figé à la vente, et la base est indiquée (« réel », « partiellement estimé », « estimé »).
- **Coûts encore inconnus nommés** (frais de paiement, coût réel de livraison, valeur des points de fidélité) et exclus du calcul plutôt que devinés.
- Ce chiffre **n'est ni le résultat comptable ni l'argent disponible** ; la réponse le précise.

## Migrations, contrat et vérifications

Migration additive `2026_11_13_000049_create_accounting_statements`, appliquée à la base locale `kora` (vide). Garde-fous SQL :

- modèles figés, seul l'examen peut être ajouté ;
- traces d'export en ajout seul.

Contrat **0.43.0 / 541 chemins** (`update-accounting-statements-contract.mjs`) ; la chaîne rejouée donne une empreinte identique.

Tests `AccountingStatementsTest` (3) :

- **états** :
  - refus sans modèle ;
  - chevauchement, total inconnu et côté manquant refusés ;
  - compte non affecté listé ;
  - nouvelle version qui couvre tout ;
  - mouvements limités à la période ;
  - examen réservé au comptable, empreinte vérifiée, examen unique, modèle indélébile ;
  - raisons provisoires ; bilan avec côtés et écart ; droits ;
- **exports** :
  - confirmation récente exigée ;
  - CSV avec BOM et en-têtes, formule neutralisée, soldes courants exacts par compte, empreinte identique au fichier ;
  - XLSX valide avec montants numériques et formule neutralisée ;
  - PDF ;
  - état sans modèle refusé ; trace immuable ; droits ;
- **marge** :
  - coût réel des lots du dépôt, base « réel » ;
  - contribution exacte, livraison encaissée par commande ;
  - coûts inconnus nommés ; période vide ; droits.

Résultats : [VALIDATION.md](VALIDATION.md).

## Limites

- Les modèles SYSCOHADA réels (rubriques du compte de résultat et du bilan) restent à saisir et faire examiner par le comptable de l'entreprise ; aucun n'est fourni par défaut.
- Pas de tableau des flux de trésorerie ni de déclarations fiscales (formats officiels à confirmer).
- Pas de report à nouveau automatique d'un exercice à l'autre.
- Marge : coût réel de livraison, frais Monetbil et valeur des points restent inconnus tant que les rapprochements ou paramétrages correspondants n'existent pas. Les remboursements sont répartis au prorata des lignes.
- Aucun écran.
