# CMS, événements et conditions légales — backend

Incrément du 12 novembre 2026. Il couvre le §27 côté API (réglages du site, pages en blocs, thèmes, calendrier d'événements, aperçu, programmation, retour arrière) et les conditions légales versionnées avec acceptations, ainsi que les scénarios V64 et V65 côté API. Le carrousel d'accueil existant (`content/hero`, scènes) reste en place.

## Réglages et pages versionnés

- Deux sortes de documents : les **réglages du site** (un seul document, « site » : entreprise, logos clair/sombre, favicon, thème, polices, menus, réseaux sociaux) et les **pages** (par adresse, ex. `home`).
- Chaque enregistrement crée un **brouillon numéroté** ; les versions précédentes restent. **Aperçu** par lien privé réservé à l'équipe, jamais indexé.
- **Publication immédiate ou programmée** (heure de Douala avec son décalage). Le site affiche la version en vigueur à l'instant : une version programmée prend la main **toute seule** à son heure.
- **Retour arrière** : le contenu d'une ancienne version redevient une nouvelle version publiée ; rien n'est effacé.
- Une version publiée ou programmée **ne peut plus être modifiée ni supprimée**, même directement dans la base.

## Contenu structuré et sûr

- Le contenu n'est **jamais du HTML ni du code** : uniquement des blocs approuvés — sélection de produits, sélection de boutiques, image + texte, catégorie, message de service, bannière, texte éditorial, FAQ.
- Textes en **français et anglais**, sans balises ; liens internes (`/…`) ou `https` ; couleurs hexadécimales ; **contraste lisible** contrôlé (4,5:1 pour le texte sur son fond et sur la couleur principale, en mode clair et sombre) ; polices de la liste approuvée ; produits, boutiques, catégories et médias doivent exister.
- Publication refusée si un média n'est **pas public** dans la médiathèque.
- Côté site, une sélection ne montre que les produits et boutiques **encore publiés** (un bloc vidé est masqué) ; un média retiré après publication **disparaît** sans republier la page.

## Événements (Black Friday, Noël, 8 mars…)

- Période en heure de Douala, **priorité explicite**, pages et langues ciblées, variation de thème (seules les couleurs changées), bannière par langue, produits mis en avant, offre de livraison liée.
- Création en brouillon, puis activation ou pause. Les couleurs de l'événement sont vérifiées **fusionnées au thème de base** : une combinaison illisible est refusée.
- Entre plusieurs événements actifs : priorité la plus haute, puis début le plus récent. **À la fin de la période, la configuration normale revient sans action.**

## Rafraîchissement du site

`GET content/revision` donne un numéro de révision (augmenté à chaque publication) et l'heure du **prochain changement prévu** (version programmée, début ou fin d'événement, conditions entrant en vigueur). Le site sait ainsi quand rafraîchir ses caches, même sans nouvelle publication.

## Conditions légales

- Conditions d'utilisation, de vente, confidentialité, retours : **versions numérotées** avec date d'effet, texte FR et EN obligatoire avant publication.
- Publication réservée à un **droit dédié** (`legal.publish`, propriétaire) avec **confirmation récente de l'identité**. Règle des **quatre yeux** activable (`KORA_CMS_FOUR_EYES=true`) : l'auteur ne peut alors pas publier son propre texte.
- Version publiée **figée** ; page publique avec la version en vigueur et l'historique.
- **Acceptation explicite** conservée avec son contexte (inscription, commande, mise à jour) ; une commande ne peut être que celle du client ; la même acceptation n'est enregistrée qu'une fois ; les acceptations ne sont **jamais modifiées ni supprimées**.
- Une nouvelle version qui le demande apparaît dans `account/legal/pending` **à partir de sa date d'effet** ; ce qui a été accepté avant n'est pas réécrit.
- **Chaque commande garde la version des conditions de vente en vigueur** au moment où elle est passée (`orders.terms_version_id`).

## Droits

`content.read` (lecture, aperçu), `content.write` (brouillons, événements), `content.publish` (publication, retour arrière, activation d'événement) : propriétaire et équipe catalogue ; le gérant lit seulement. `legal.publish` : propriétaire.

## Migrations, contrat et vérifications

Migration additive `2026_11_12_000046_create_cms`, appliquée à la base locale `kora` (vide). Garde-fous SQL (PostgreSQL et SQLite) : états de version, publication tracée, contenu figé hors brouillon, aucune suppression ; conditions légales figées une fois publiées ; acceptations en ajout seul. Contrat **0.40.0 / 492 chemins** (`update-cms-contract.mjs`) ; la chaîne rejouée donne une empreinte identique.

Tests `CmsTest` (3) :
- pages : couleurs illisibles, police inconnue, balises, bloc inconnu et lien `javascript:` refusés ; média privé bloquant ; aperçu non indexé et fermé au public ; version figée en base ; programmation au lendemain 6 h Douala avec bascule seule ; produit retiré et média révoqué masqués ; retour arrière en version 3 ; droits du gérant ;
- événements : thème illisible, dates inversées et bannière manquante refusés ; priorité et langue ; fusion du thème ; conflit de version ; prochain changement annoncé ; retour au thème normal à la fin ;
- conditions : texte bilingue obligatoire, droit dédié, confirmation récente, quatre yeux, version figée, page publique et historique, version capturée dans la commande, acceptation unique, commande d'un autre client et brouillon refusés, acceptations indélébiles, nouvelle version demandée à sa date d'effet.

Résultats : [VALIDATION.md](VALIDATION.md).

## Limites

- Aucun écran : l'éditeur de blocs, l'aperçu visuel et le calendrier restent à construire côté gestion, et le rendu côté site.
- Les sélections éditoriales sont manuelles ; aucune sélection automatique (meilleures ventes, nouveautés) n'est calculée.
- Pas de traduction automatique : les deux langues sont saisies.
- Le texte des conditions est fourni par l'entreprise ; aucun texte juridique n'est rédigé ni validé ici.
