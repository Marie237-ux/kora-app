# Suite du backend : échéances, rappels et reprise

Les 38 sections et 110 scénarios du lancement restent requis. Cet ajout complète les traitements fournisseurs du troisième incrément ; il ne livre pas encore les écrans ni la trésorerie complète.

## Traitements ajoutés

- Politique de rappels internes administrable par le propriétaire : activation, horizon des échéances proches, heure locale, jours ISO de semaine et intervalle de répétition. Version attendue, motif et audit. Aucune politique commerciale par défaut ; sans configuration, les dettes échues/du jour et dépassements restent détectables sans mise en file de rappel.
- Alertes sur le reliquat réel des échéances et le dépassement du crédit d'un contrat accepté effectif. La lecture ne substitue pas une alerte ancienne à l'obligation actuelle ; last_checked_at indique la fraîcheur. Une modification d'échéancier retire les anciens signaux sans effacer leurs versions. Un problème qui réapparaît ouvre un nouveau cycle sur la même alerte.
- Mise en file selon les créneaux Africa/Douala, sans doublon par cycle/sévérité/jour et avec intervalle configuré. last_queued_at signifie mis en file, pas remis à un utilisateur.
- Consommateur d'outbox pour SupplierPayableReminder uniquement : effet de notification interne et reçu de traitement dans la même transaction. Nouvelle vérification de la dette et de la politique avant remise ; rappel périmé, réglé, ancien cycle ou désactivé supprimé de la remise avec résultat suppressed.
- Erreurs : délais croissants de reprise (60, 120, 240, 480 secondes), arrêt après cinq échecs consécutifs, codes minimaux sans payload/SQL privé. Historique de tentatives, diagnostic propriétaire, reprise manuelle motivée/MFA/reconfirmation avec historique conservé.
- Notifications internes FR/EN, aperçu générique sans montant ni nom fournisseur, accès selon permissions actuelles. Marquage lu propre à chaque utilisateur. Aucun email, SMS, WhatsApp, push navigateur ou message externe.
- Échéancier filtrable par fournisseur, contrat, état et période. Réglé/restant calculé par une projection SQL sur toutes les échéances avant de filtrer ou paginer.
- Prévisionnel des dettes fournisseurs : avant/dans/après période, dette sans échéance, retards, engagement non encore reconnu, avances/crédits non affectés et ventes de consignation à rapprocher. Les montants réglés ou déjà rapprochés ne sont pas déduits deux fois.
- Rapports cohérents en PostgreSQL REPEATABLE READ / READ ONLY. Les agrégations ne parcourent plus chaque fournisseur pour relire ses dettes séparément. Coûts des excédents non facturés suivant l'ordre d'acceptation audité, même si timestamps égaux et UUID en ordre inverse.
- OpenAPI 0.4.0 : 108 chemins implémentés au total, types partagés générés et contrôle des nouvelles routes/permissions/idempotence/reconfirmation.

## Preuves

Les suites complètes et comptes définitifs sont dans VALIDATION.md et preuves/. Les nouveaux tests couvrent absence de politique, droits/version/créneaux, répétition, dette réglée avant remise, avenant, désactivation, réapparition, lecture propre à l'utilisateur, erreur après effet puis reprise sans doublon, cinq échecs puis reprise autorisée, filtres et prévisionnel, consignation sans double dette et ordre réel d'acceptation.

PostgreSQL ajoute deux preuves distinctes : deux processus attendent réellement le même événement puis produisent une seule notification/un seul reçu ; une seconde connexion modifie une donnée pendant un rapport, dont le snapshot reste stable. Le test confirme aussi que READ ONLY ne reste pas activé sur la connexion après le rapport.

La base locale réelle est vide : scan et worker exécutés avec zéro fournisseur/événement à traiter, aucun destinataire réel contacté. schedule:list montre les trois commandes par minute. Cela ne prouve pas qu'un planificateur système est installé ou supervisé.

## Utilisation

API de gestion sous /api/v1/operations, session/CSRF/MFA :
- GET/PUT settings/payable-reminders : propriétaire, politique versionnée.
- GET payables/alerts et payables/alerts/{id} : achats/finance autorisés.
- GET payables/forecast?from=YYYY-MM-DD&to=YYYY-MM-DD : période requise, écart entre dates au plus 366 jours, filtre supplier_id possible.
- GET payables/schedule : filtres supplier_id, contract_id, status, from, to et page.
- GET notifications : filtre unread=0 ou 1, pagination, langue fr/en ; POST notifications/{id}/read avec UUID Idempotency-Key.
- GET system/outbox et system/outbox/{id}/attempts : propriétaire ; aucune donnée privée d'événement renvoyée.
- POST system/outbox/{id}/retry : propriétaire avec MFA et confirmation récente du mot de passe, motif et clé d'idempotence ; seulement un échec final pris en charge.

Commandes depuis services/api :
- php artisan payables:scan-alerts
- php artisan outbox:process --limit=100
- php artisan schedule:list

Un événement sans consommateur reste unsupported, sans processed_at artificiel. Les événements catalogue, autres domaines et futurs envois distants ne sont pas pris en charge par ce consommateur. Un retard d'exécution du worker peut retarder la remise : les créneaux déterminent la mise en file et les horodatages de traitement donnent l'état réel.

## Limites

La couverture est supplier_payables_only : available_funds, provider_held_funds, refunds_to_fund et free_cash restent null, car aucune trésorerie rapprochée complète ne les fournit encore. Une dette sans date reste undated ; aucun jour ni zéro de trésorerie n'est inventé. Avances et avoirs non affectés sont séparés, pas retranchés arbitrairement d'une échéance.

Les alertes ne déclenchent pas de règlement. Les trois tests de concurrence ne prouvent pas encore tous les futurs paiements, remboursements, points ou budgets. Écrans, trésorerie/rapprochement comptable, modèles/export contractuels, canaux externes, performance en charge et exploitation supervisée restent obligatoires avant lancement. ClamAV/S3 réels et preuves navigateur toujours ouverts.
