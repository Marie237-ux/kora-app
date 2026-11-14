# Rapprochements de trésorerie — premier périmètre backend

Évolution : les décalages de dates dans les deux sens, reports examinés et snapshots historiques de clôture sont ajoutés dans INCREMENT_REPORTS_TRESORERIE.md (0.22.0/304 chemins). Les règles strictes ci-dessous décrivent le premier périmètre ; la clôture accepte désormais les lignes explicitement reportées après examen. La migration 000026 renforce aussi le garde-fou PostgreSQL commun sans argument.

Cet incrément des §17 et §22 relie les mouvements d’un relevé aux lignes comptables validées. Contrat OpenAPI 0.21.0, 300 chemins. Les écrans restent à construire ; résultats et preuves des suites complètes dans VALIDATION.md.

## Configuration

Les comptes de trésorerie acceptent banque, Mobile Money, Monetbil et caisse, sans compte ni solde prérempli. Le type Monetbil permet un rapprochement manuel, sans appel au prestataire ni retrait.

Le propriétaire associe explicitement une trésorerie à un compte actif/postable du livre, sans auxiliaire, et choisit le côté représentant une entrée. La correspondance conserve version, version du plan, snapshot et empreinte. Un compte représente une seule trésorerie par livre. Dès qu’un relevé non annulé utilise la trésorerie, changer son compte ou son sens est refusé ; les révisions du plan permettent une nouvelle correspondance de même compte/sens.

Avant import réel, le comptable examine le plan courant et l’empreinte de la correspondance ; le propriétaire seul n’a pas ce droit d’examen. Nouveau plan : nouvelle correspondance examinée pour les nouveaux relevés. Les historiques gardent leur configuration figée et peuvent terminer leur rapprochement.

## Relevé et preuve

Import JSON de transcription manuelle : référence, dates, soldes initial/final et lignes identifiées avec date, référence, entrée/sortie et montant XAF. Une pièce privée `accounting_evidence` sans fournisseur doit avoir un statut antivirus propre. Identifiant et empreinte de la pièce sont figés ; la clôture revérifie son statut et son empreinte enregistrés. Le téléchargement privé contrôle les octets selon le mécanisme existant. Les tests utilisent un scanner simulé, sans valider d’antivirus réel ni de lecture bancaire automatique.

Chaque mouvement est une chaîne entière positive plafonnée à 10^18 XAF. Les soldes signés canoniques acceptent jusqu’à 36 chiffres ; les agrégats restent exacts au-delà du plafond individuel. Solde initial + entrées − sorties = solde final exactement. Dates des lignes dans l’intervalle, identifiants externes distincts par relevé, maximum 1 000 lignes. Référence active unique pour le livre et la trésorerie.

Le premier relevé fixe le début de la série, sans inventer de report comptable initial. Les suivants exigent le précédent clôturé, des dates contiguës et la reprise exacte de son solde. L’intervalle doit être couvert par des périodes ouvertes du même exercice. Une erreur exige annulation motivée puis nouvel import ; l’original reste consultable. L’annulation demande un relevé ouvert, sans groupe actif ni successeur non annulé.

Les déclencheurs SQLite/PostgreSQL refusent modification/suppression du contenu et ajout de lignes après constitution du relevé dans la transaction. Un relevé annulé ne peut être réactivé.

## Rapprochement et clôture

Seules les lignes validées du même livre, compte et intervalle sont candidates, avec identifiants stables sous forme de chaînes, numéro et empreinte d’écriture. Un brouillon ne vaut pas mouvement constaté.

Un groupe manuel sélectionne jusqu’à 200 lignes du relevé et 500 lignes comptables. Les nets doivent être exactement égaux selon le sens configuré, sans allocation partielle. Un versement net peut réunir encaissement brut et frais déjà comptabilisés explicitement ; aucun frais n’est calculé ou créé. Chaque ligne appartient à un seul groupe actif, sous contrainte SQL et verrou de livre partagé avec validations, calendrier et configuration.

Une erreur se corrige par annulation motivée du groupe sur relevé ouvert, puis nouveau groupe. Les membres historiques restent conservés et leurs places actives sont libérées. Les groupes constitués refusent ajout, modification ou suppression de membres ; seules les libérations contrôlées des places actives sont permises.

La clôture exige toutes les lignes du relevé et toutes les lignes validées du compte dans l’intervalle rapprochées. Tout nouveau posting ou contrepassation sur ce compte et ces dates exige réouverture préalable, avec protection applicative et SQL. Les écritures validées restent immuables.

Réouverture réservée au propriétaire, motivée/auditée, avec calendrier ouvert. Les successeurs clôturés sont d’abord rouverts ; reclôture dans l’ordre chronologique. Un relevé ouvert bloque la clôture comptable des dates recoupées. Les périodes sans relevé ne sont pas réputées rapprochées et ne sont pas bloquées par une obligation de couverture encore non configurée.

## API privée

Préfixe : `/api/v1/operations/accounting/books/{book}/treasury`.

| Routes | Permission |
| --- | --- |
| GET `bindings`, `statements`, `statements/{id}`, sous-listes `rows`, `ledger`, `matches` | accounting.read |
| POST `bindings` | accounting.settings |
| POST `bindings/{id}/review` | accounting.review |
| POST `statements`, `statements/{id}/matches`, `statements/{id}/matches/{match}/void`, `statements/{id}/void` | accounting.write |
| POST `statements/{id}/close` | accounting.close |
| POST `statements/{id}/reopen` | accounting.reopen |

Session HttpOnly, MFA de gestion, CSRF ; toutes les mutations exigent mot de passe confirmé depuis 15 minutes au plus et `Idempotency-Key` UUID. Version de relevé obligatoire pour groupes/transitions. Listes paginées, lectures composées en snapshot transactionnel, périmètre livre/relevé contrôlé.

## Limites et suite

Les compteurs exposent lignes non rapprochées et nets. `scope=registered_statement_movements`, `provisional=true`, `available_funds=null`, `availability_verified=false` : le solde demeure déclaré sur pièce, jamais qualifié de liquidité disponible. Le prévisionnel fournisseur conserve ses fonds disponibles inconnus. `external_reconciliation=false` dans le recensement des sept familles reste exact : la capture n’effectue pas elle-même ce rapprochement.

Restent obligatoires : décalages de dates et éléments en circulation, allocations partielles, obligation configurable de couverture, soldes comptables d’ouverture rapprochés, imports bancaires/prestataires, vérification externe des soldes retirables/disponibles, retraits et fonds engagés, dépenses/frais avec workflow dédié, lettrage des tiers, autres événements/valorisations, états/exports et interfaces. La caisse dispose seulement ici du rapprochement de relevés transcrits ; inventaire physique et double contrôle restent ouverts.

Migration additive `2026_11_04_000025_create_treasury_reconciliations`. Aucune nouvelle dépendance, écriture automatique, opération financière réelle ou activation de compte. Relecture locale des invariants ; aucun dépôt Git disponible pour revue différentielle ou commit.
