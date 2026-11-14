# Profils professionnels et devis — backend du §14

Implémentation du 4 novembre 2026. Ce module fournit des API ; les écrans et le PDF du devis restent à construire. Le lancement complet n'est pas déclaré prêt.

## Profil et contacts

Un compte crée un profil avec raison sociale et adresse de facturation ; NIU, RCCM et coordonnées restent facultatifs. L'identité est déclarée, pas présentée comme vérifiée. Le créateur est propriétaire du profil, sans devenir propriétaire KORA ni recevoir une permission de gestion.

Le propriétaire autorise explicitement des comptes dont l'e-mail est vérifié : acheteur (`buyer`) ou lecteur (`viewer`). Les acheteurs gèrent les adresses communes et demandent, acceptent ou convertissent les devis ; les lecteurs consultent l'historique partagé. Modification des contacts réservée au propriétaire ; retrait ou rétrogradation de celui-ci refusé.

Les droits actuels sont revérifiés avant le rejeu d'une réponse idempotente, puis sous verrou lors des écritures. Une révocation retire aussi l'accès aux commandes professionnelles dans les routes ordinaires, y compris leur liste. Un paiement déjà engagé reste rapproché par sa notification serveur vérifiée ; révoquer le payeur ne peut faire disparaître de l'argent reçu. Les autres contacts ne reçoivent pas son lien de paiement.

## Demande, émission et accord

La demande conserve les références, quantités sélectionnées et adresse d'entreprise, indépendamment des modifications ultérieures du panier personnel. L'équipe disposant de `professional.manage`, MFA et d'un mot de passe reconfirmé depuis moins de 15 minutes émet une proposition FR/EN.

Chaque révision immuable porte prix TTC unitaires, remise par rapport au catalogue, totaux XAF entiers, traitement de TVA issu des paramètres explicites, identité de facturation, livraison calculée, conditions et validité horodatée avec fuseau. Les coûts viennent des accords fournisseurs réellement applicables, paliers de quantité compris. Une marge négative exige `pricing.loss_override` et un motif ; aucune remise fournisseur fictive. Coûts et dérogations ne sont jamais exposés aux contacts d'entreprise.

Numéro serveur `DEV-année-séquence`, ou `DEMO-DEV-année-séquence` tant que l'identité vendeur est incomplète. Les versions partagent le numéro logique et conservent leur propre identifiant, séquence et empreinte SHA-256. Les anciennes versions ne sont pas réécrites.

L'acceptation désigne la version courante et son total exact, avec reconnaissance explicite de l'absence de réservation du stock fournisseur. Elle conserve acteur et date. Refus, nouvelle émission et expiration sont historisés. Un devis expiré est inutilisable même avant le passage du planificateur ; `professional:expire-quotes --limit=100` matérialise ensuite l'expiration sans doubler les événements.

## Conversion et paiement

Un aperçu recalcule les contraintes physiques et les créneaux disponibles. La conversion d'une version acceptée et valable vérifie à nouveau disponibilité, minimum de quantité, variante, attributs et garantie. Toute modification de ces conditions refuse la substitution. Prix produits et livraison acceptés restent ceux promis ; une hausse du catalogue ou de la grille ne crée pas de supplément. L'écart de livraison est conservé dans le suivi interne.

La commande suit le checkout habituel : réservations réelles du stock KORA et du créneau pendant le paiement, même rapprochement Monetbil et mêmes incidents. Aucun crédit client ni paiement réel activé. Un panier privé de calcul évite de modifier le panier personnel. Deux conversions simultanées donnent une seule commande et une seule réservation de créneau, avec retour de la même commande au second appel.

La commande conserve le lien de révision et l'identité de facturation acceptée. La facture client reprend cette identité figée et ses PDF FR/EN, même après modification du profil.

## Installation et contrat

Migration `2026_11_04_000020_create_professional_quotes` : profils, membres, adresses, demandes, révisions et événements, puis références de devis sur les commandes. Appliquer avec `php artisan migrate --no-interaction`, jamais par réinitialisation de la base consultée. L'expiration apparaît chaque minute dans `schedule:list` ; le planificateur système reste à installer et superviser en exploitation.

Contrat OpenAPI 0.16.0 : 241 chemins au total, types client générés. Routes sous `/api/v1/professional/profiles` et `/api/v1/operations/professional-quotes`. Écritures avec session, CSRF, `Idempotency-Key` UUID et versions attendues ; propriété et permissions documentées dans le contrat.

## Vérification et limites

Les résultats complets actualisés figurent dans [VALIDATION.md](VALIDATION.md), avec rapports SQLite et PostgreSQL dans `preuves/`. Les tests couvrent isolation des entreprises, permissions des contacts, révocation et rejeu, versions et consentement exact, durée de validité, accords réels, marge, changement de prix/garantie/livraison, facture et paiement simulé, ainsi que conversion concurrente avec deux processus PostgreSQL.

Restent obligatoires : écrans boutique/gestion, PDF/export du devis, révision des quantités demandées et négociation enrichie, conditions de cumul avec le moteur commercial complet et parcours interactifs. Le socle expose explicitement `campaigns_combined: false` ; il ne cumule pas automatiquement les campagnes du checkout courant. La validation fiscale/juridique des modèles et le paiement Monetbil réel restent ouverts.
