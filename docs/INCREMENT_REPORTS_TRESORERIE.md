# Décalages de dates et éléments en circulation

Suite backend des §17/§22 et de INCREMENT_RAPPROCHEMENTS_TRESORERIE.md. Contrat 0.22.0, 304 chemins. Cet incrément permet de terminer un rapprochement en conservant explicitement les mouvements en attente, puis de les rapprocher sur un relevé ultérieur. Il ne crée aucun paiement, frais, retrait ni écriture.

## Deux sens du décalage

Une ligne comptable validée peut attendre son apparition sur le relevé ; une ligne de relevé peut attendre sa comptabilisation explicite. Dans les deux cas, une décision de report référence la ligne entière, une preuve privée `accounting_evidence` propre, un motif et une date attendue strictement postérieure à la fin du relevé. Aucune affectation partielle dans cet incrément.

Le report commence `pending` et continue de bloquer la clôture. Le rôle comptable examine son empreinte et la pièce pour le passer à `approved`. En réel, le créateur ne peut approuver sa propre décision, même avec les permissions comptables. Un maximum de 1 000 décisions actives par relevé évite des listes de report non bornées.

Une décision ne remplace ni rapprochement ni règlement. Pour rapprocher une ligne portant une décision active dans le relevé courant, il faut d’abord retirer cette décision avec motif. L’annulation conserve contenu, pièce, examen éventuel et audit ; elle libère sa place active. Une ligne déjà rapprochée ne peut être déclarée en attente.

## Reprise au relevé suivant

Seules les lignes courantes et celles inscrites en attente dans la dernière clôture du relevé précédent sont candidates. Même livre, trésorerie, compte et sens configuré ; aucun élargissement arbitraire vers toute l’histoire du journal. Les listes `rows` et `ledger` montrent aussi ces lignes héritées. `statement_id` sur une ligne de relevé indique son origine. La date et le numéro d’écriture restent ceux du journal validé.

Un groupe peut ainsi rapprocher un mouvement comptable de novembre avec une ligne de relevé de décembre, ou une ancienne ligne de relevé avec une écriture explicitement validée en décembre. Le montant net doit toujours être exactement égal et chaque ligne conserve une seule place active globale. Les écritures et relevés historiques ne sont pas modifiés. Une ligne d’un exercice déjà clôturé peut être réglée dans un relevé courant sans réouverture de cet exercice.

Si le mouvement reste en attente, la clôture suivante exige une nouvelle décision avec motif, preuve, nouvelle date et nouvel examen. Elle conserve l’origine de la décision précédente. Aucune reconduction ni disparition automatique, même lorsque les entrées et sorties non rapprochées ont un net nul.

## Clôtures et corrections

Toutes les lignes doivent être rapprochées ou explicitement reportées avec examen. Les compteurs `unmatched_*` montrent encore les lignes en attente ; `unresolved_*` doit être nul pour clôturer. Les décisions `pending` restent bloquantes. La preuve et son empreinte sont revérifiées avant l’examen et la clôture.

Chaque clôture ajoute un snapshot immuable avec totaux, groupes et reports examinés, version du relevé et empreinte. Le bilan d’une clôture reste identique après un rapprochement ultérieur. Les listes de lignes restent opérationnelles : leur `match_id` reflète le rapprochement courant ; la situation historique figure dans `closure.snapshot`. Les anciennes clôtures strictes sans snapshot ne reçoivent pas de bilan inventé ; une réouverture puis nouvelle clôture crée leur première nouvelle preuve.

Réouverture propriétaire motivée et calendrier ouvert, comme auparavant. Les successeurs clôturés doivent d’abord être rouverts, puis leurs groupes actifs annulés et décisions actives retirées avant de rouvrir le prédécesseur. Les nouveaux rapprochements/reports des successeurs attendent ensuite la reclôture du prédécesseur. Cela protège la provenance des lignes héritées. Une reclôture ajoute une nouvelle preuve sans remplacer les précédentes.

Un relevé avec décision active ne peut être annulé. Un relevé ouvert bloque toujours la clôture des dates comptables concernées ; toute nouvelle validation ou contrepassation dans l’intervalle d’un relevé clôturé exige encore sa réouverture.

## Routes ajoutées

Préfixe : `/api/v1/operations/accounting/books/{book}/treasury/statements/{id}`.

| Route | Permission |
| --- | --- |
| GET `outstanding`, `closures` | accounting.read |
| POST `outstanding` | accounting.write |
| POST `outstanding/{item}/review` | accounting.review |
| POST `outstanding/{item}/void` | accounting.write |

Session HttpOnly, MFA, CSRF ; mutations avec mot de passe confirmé depuis 15 minutes au plus, `Idempotency-Key` UUID et version de relevé. Examen avec empreinte de décision. Listes paginées et lectures composées en snapshot transactionnel ; identifiants hors livre/relevé refusés. Les mutations partagent le verrou du livre avec écritures, configuration et clôtures.

## Protection SQL et migration

Migration additive `2026_11_04_000026_create_treasury_outstanding`, sans données de démonstration ni dépendance supplémentaire. Tables de décisions et preuves de clôture avec contenu protégé en SQLite/PostgreSQL ; transitions bornées, aucune modification de décision sur relevé clôturé. Les places actives uniques empêchent les doubles décisions.

Les tests PostgreSQL ont découvert un défaut du garde-fou générique créé dans 000025 : lorsqu’il n’avait aucun argument, `TG_ARGV` pouvait être nul et rendre la comparaison de contenu indéterminée. La migration 000026 remplace ce garde-fou en traitant ce cas comme une liste vide. Le correctif protège aussi les lignes des relevés existants ; une régression vérifie leur mise à jour SQL interdite. Le rollback 000026 laisse ce renforcement du garde-fou commun en place, tout en supprimant les deux nouvelles tables ; ne pas employer un rollback pour corriger un report métier.

## Limites restantes

Les nets des éléments en circulation et leur différence sont exposés exactement. Ils ne constituent pas un solde comptable absolu rapproché : le premier solde déclaré et les reports d’ouverture comptables ne sont pas encore reliés. `available_funds=null`, `availability_verified=false` et éditions provisoires restent en vigueur. Aucun raccordement bancaire/Monetbil réel, aucun montant réputé disponible ou retirable.

Restent : allocations partielles, obligation configurable de couverture, rapprochement des soldes d’ouverture, import de formats, vérification externe des disponibilités, dépenses/frais/retraits et fonds engagés, inventaire physique de caisse, lettrage des tiers, autres événements/valorisations, états/exports et interfaces. Résultats et preuves définitifs dans VALIDATION.md ; pas de dépôt Git pour revue différentielle/commit.

## Évolution suivante

INCREMENT_COUVERTURE_TRESORERIE.md complète les ouvertures et soldes rapprochés ainsi que l'obligation configurable de couverture. Les limites décrites ci-dessus conservent le contexte de la version 0.22.0 ; disponibilité réelle, dépenses/retraits, lettrage et interfaces restent ouverts.
