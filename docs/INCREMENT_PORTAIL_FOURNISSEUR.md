# Portail fournisseur — backend

Incrément du 13 novembre 2026. Il couvre le portail fournisseur du §19 côté API : invitations, profil, contrats et documents autorisés, propositions de fiches, disponibilités, collectes, confirmations, garanties et historique des achats. Les abonnements fournisseurs et la mise en avant sponsorisée (§19, fin) restent à faire.

## Construit, fermé par défaut

- Le portail est **complet mais fermé** tant que le propriétaire ne l'ouvre pas.
  - Ouverture : droit `suppliers.portal_settings`, avec confirmation récente de l'identité.
  - Fermé, un membre reçoit un refus clair (`SUPPLIER_PORTAL_DISABLED`). L'équipe peut tout préparer en attendant (invitations, partages).
- **La publication reste toujours à KORA** : un fournisseur ne publie rien, ne fixe aucun prix de vente et ne confirme rien à la place de l'équipe.
- Réglage des disponibilités :
  - « vérification » (par défaut) : l'équipe valide chaque déclaration ;
  - « direct » : une déclaration s'applique tout de suite.

## Accès

- **Invitation** par l'équipe (droit `suppliers.portal` : propriétaire, achats), rôle gérant ou employé.
  - Le lien est remis à l'équipe pour le transmettre, car aucun e-mail n'est envoyé sans canal configuré.
  - Valable 7 jours et une seule fois. Seul le compte de l'adresse invitée, e-mail vérifié, peut l'accepter.
  - Une invitation inconnue, déjà utilisée, retirée, expirée ou destinée à une autre adresse reçoit la même réponse.
- **Retrait d'accès** immédiat, à la requête suivante.
- **Chaque ressource est vérifiée** : un membre n'atteint que son fournisseur. L'espace, les offres, les achats, les documents et les propositions d'un autre fournisseur répondent « introuvable ».

## Ce que voit et fait un membre

- **Accueil** : ses boutiques, propositions ouvertes, achats en attente de réponse, collectes en cours.
- **Offres** :
  - il voit son **prix convenu avec KORA**, jamais le prix de vente ni la marge de KORA ;
  - il déclare une **disponibilité** (quantité, validité, délai de préparation). Une fois appliquée, elle entre dans la disponibilité fournisseur habituelle, avec la déclaration comme référence.
- **Achats** :
  - visibles seulement quand l'équipe les **partage** explicitement ;
  - il voit quantités, son coût unitaire et les quantités reçues ;
  - il ne voit ni les détails internes du contrat ni l'instantané commercial ;
  - il **confirme avec sa référence** ou refuse avec un motif. Cette réponse est gardée telle quelle et **ne confirme pas l'achat** : l'équipe confirme ensuite par le parcours habituel (contrôle du plafond de crédit compris).
- **Collectes** dans ses boutiques :
  - il voit quoi remettre, où, et l'identité publique du livreur, pour vérifier qui vient ;
  - il ne voit **jamais le client ni la commande** d'une livraison directe ;
  - il signale « prêt » ou un problème (motif obligatoire).
- **Garanties** : les dossiers SAV attribués au fournisseur, avec numéros de série et constat. **Aucune donnée client** : ni nom, ni description écrite par le client.
- **Relevé** : achats confirmés, réceptions, dettes, règlements vérifiés, avoirs, retours, échéances, et soldes à une date. Les révisions internes de KORA sont exclues.
- **Contrats** approuvés, acceptés ou terminés (jamais les brouillons), et leur exemplaire signé.
- **Documents** :
  - il ouvre ceux que l'équipe lui partage, tant que le partage n'est pas retiré ;
  - il **envoie** les siens (PDF ou image, 10 Mo, antivirus), que l'équipe achats retrouve.
- **Propositions** en texte simple, sans balises : nouvelle fiche produit, changement d'offre, changement de profil. Il peut retirer une proposition encore en attente.

## Ce que fait l'équipe

- File des propositions : étude, acceptation ou refus motivé.
  - Accepter une fiche (droit catalogue en plus) crée un **brouillon produit** dans le formulaire habituel, avec la catégorie et l'adresse choisies par l'équipe.
  - Offre, prix et publication restent des actions séparées. Le prix proposé est une information.
- File des disponibilités déclarées : appliquer ou refuser.
- Partager un achat, voir les réponses du fournisseur (aussi publiées comme événement `SupplierResponded`).
- Partager ou retirer un document : seul un document sain, rattaché à ce fournisseur ou à aucun, peut être partagé.

## Migrations, contrat et vérifications

Migration additive `2026_11_13_000048_create_supplier_portal`, appliquée à la base locale `kora` (vide). Elle crée membres, invitations, propositions, déclarations, réponses et documents partagés, et ajoute le partage des achats. Garde-fous SQL :

- réponses en ajout seul ;
- propositions à contenu figé, avec décision tracée ;
- aucune suppression.

Contrat **0.42.0 / 534 chemins** (`update-supplier-portal-contract.mjs`) ; la chaîne rejouée donne une empreinte identique.

Tests `SupplierPortalTest` (3) :

- **invitation** : adresse vérifiée seulement, usage unique ; portail fermé puis ouvert par le propriétaire avec confirmation récente ; refus pour les achats ;
- **isolation** entre fournisseurs ; retrait d'accès immédiat ;
- **offres et disponibilités** : prix convenu sans prix de vente, disponibilité en vérification puis directe ;
- **achats** : invisibles avant partage ; confirmation sans référence refusée ; réponse sans effet sur l'achat, figée et publiée comme événement ;
- **relevé, contrats et documents** : relevé sans révisions internes ; contrat et exemplaire signé ; document d'un autre fournisseur refusé ; partage, envoi et retrait ;
- **propositions** : balises refusées, boutique ou offre d'un autre fournisseur refusée, refus motivé, acceptation en brouillon sans offre ni publication, proposition figée ;
- **collectes et garanties** : collecte visible sans commande, réponse « prêt » sans effet sur la collecte ; dossier SAV avec numéros de série, sans données client.

Résultats : [VALIDATION.md](VALIDATION.md).

## Limites

- Aucun écran (espace fournisseur dans l'application de gestion).
- Pas d'envoi d'e-mail ni de notification au fournisseur : canaux non configurés.
- Les photos d'une proposition passent par l'envoi de documents ; elles ne deviennent pas des médias catalogue automatiquement.
- Abonnements fournisseurs et mise en avant sponsorisée : à construire, sans tarif inventé ni prélèvement automatique.
