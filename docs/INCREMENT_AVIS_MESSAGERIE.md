# Avis, alertes, notifications et messagerie — backend

Incrément du 11 novembre 2026. Il couvre le reste du §24 (avis, alertes, historique, racheter, préférences) et le §26 (messagerie client–support, WhatsApp, notifications) côté API, ainsi que les scénarios V61 et V62 côté API. Les sélections éditoriales relèvent du CMS (§27).

## Avis vérifiés

- **Un avis par article réellement livré**, par son acheteur (ligne de commande livrée, unités sorties). Modifiable tant qu'il n'est pas modéré ; quatre photos au plus, **réencodées par la médiathèque** (métadonnées comme la position retirées) et publiques seulement après approbation.
- **Modération** (`reviews.moderate` : propriétaire, support) : publication, ou refus pour un motif de la liste (abusif, données personnelles, hors sujet, spam, sans rapport avec le produit). **« Négatif » n'est pas un motif** : un avis n'est jamais refusé parce qu'il est négatif.
- **Signalement** par un autre client (une fois par personne), classé ou suivi d'un retrait motivé. **Réponse publique** de l'équipe.
- **Page produit** (`GET products/{id}/reviews`) : résumé des notes, avis publiés avec mention « achat vérifié », prénom et initiale seulement, photos approuvées, réponse.

## Alertes, historique, racheter

- **Alertes** : baisse de prix (sous le prix du jour ou sous un prix visé) et retour en stock (offre actuellement indisponible). Une tâche planifiée (`alerts:check`, toutes les 5 minutes) **notifie une seule fois** puis arrête l'alerte.
- **Historique consulté** : 50 derniers produits, enregistré seulement si le client le garde activé. Le couper l'efface ; il peut aussi l'effacer à tout moment.
- **Racheter** : remet au panier un article de ses commandes, **aux conditions du jour** (le panier montre les différences de prix).

## Centre de notifications et préférences

- Chaque avis est écrit **dans la langue enregistrée** du client, avec un lien vers une ressource qu'il peut ouvrir.
- Trois catégories : transactionnel (toujours dans le centre), rappel (actif par défaut, réglable par canal) et campagne (**désactivée par défaut**, sur accord).
- **Désinscription en un clic** depuis un lien signé, sans connexion ; le transactionnel ne se coupe pas.
- **États d'envoi honnêtes** : pour l'e-mail, les notifications web et WhatsApp, l'état est « préférence » ou « canal non configuré » tant qu'aucun prestataire n'est branché. Rien n'est affiché comme envoyé.
- Un contenu sensible (message du support) est marqué pour ne jamais apparaître dans un aperçu public.
- Abonnement aux notifications web du navigateur enregistré ; la réponse indique si un envoi est réellement configuré.

Les avis existants des demandes de produits gardent leur centre propre (`/notifications`) ; le nouveau centre est `account/inbox`.

## Messagerie client–support

- **Conversations** générales ou liées à **sa** commande, un produit ou **son** dossier SAV ; toute autre référence est refusée.
- **Messages numérotés** : l'appareil récupère ce qui suit son dernier numéro (synchronisation et reprise après coupure). La **même référence d'appareil renvoie le message déjà enregistré**, sans doublon.
- **Pièces jointes** privées, contrôlées par l'antivirus. Un client n'ouvre que celles des messages publics de sa conversation : un lien copié ne donne rien à quelqu'un d'autre.
- **Notes internes** du support, jamais transmises au client. **Non-lus** côté client et côté service ; affectation à un agent ; ouverture et clôture.
- **Réponses rapides** FR/EN, envoyées dans la langue du client.
- **Conversion** d'une conversation en dossier SAV structuré, une seule fois.
- Chaque réponse publique notifie le client **sans aperçu du contenu**.
- **WhatsApp** : le lien n'est proposé que si le numéro Business est configuré (`KORA_WHATSAPP_NUMBER`) et avec l'accord du client. Il contient une référence préremplie. **Aucun historique WhatsApp n'est synchronisé** ou présenté comme tel.

## Migrations, contrat et vérifications

Migrations additives `2026_11_11_000044_create_reviews_alerts_notifications` et `2026_11_11_000045_create_conversations`, appliquées à la base locale `kora` (vide). Garde-fous SQL : avis (note, motif de refus dans la liste, modération tracée), envois (états honnêtes, un « envoyé » exige sa date), messages en ajout seul et numérotés, notes internes réservées au support, conversation rattachée à son client. Contrat **0.39.0 / 474 chemins** (`update-engagement-messaging-contract.mjs`) ; la chaîne rejouée donne une empreinte identique.

Tests :
- `EngagementTest` (2) : avis refusé avant livraison, modifiable en attente, photo, refus « négatif » impossible, publication avec photo publique, page produit sans e-mail, signalements, réponse, droits ; alertes uniques, langue anglaise, états d'envoi honnêtes, préférences coupées, désinscription signée, historique, racheter, abonnement web ;
- `ConversationsTest` (2) : contexte étranger refusé, renvoi sans doublon, pièce jointe, réponse rapide dans la langue du client, note interne invisible, synchronisation, non-lus, notification sans contenu, isolation entre clients, conversion en dossier unique, droits ; lien WhatsApp seulement si configuré et accepté.

Résultats : [VALIDATION.md](VALIDATION.md).

## Limites

- Aucun prestataire d'e-mail, de notifications web ni de WhatsApp Business n'est branché : les envois restent « non configurés » ; un expéditeur réel et ses tentatives restent à construire.
- Temps réel : synchronisation par numéro (interrogation régulière) ; pas encore de canal poussé (WebSocket).
- Sélections éditoriales (CMS §27) ; écrans client et support.
