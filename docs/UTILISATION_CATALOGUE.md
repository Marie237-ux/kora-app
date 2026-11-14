# Utiliser le catalogue local

Boutique : http://localhost:4321/fr ; gestion : http://localhost:3000/fr. Les versions anglaises sont sous /en. Aucun article ni accord commercial de démonstration n'est créé dans la base consultée.

## Premier accès

Créer un propriétaire avec la commande interactive kora:create-admin décrite dans INSTALLATION.md. Le mot de passe n'est jamais fourni en argument. Se connecter à la gestion, configurer le TOTP et conserver les codes de récupération, puis se reconnecter avec le second facteur. Les nouveaux comptes clients ne deviennent pas gestionnaires.

Le menu apparaît après vérification de session/MFA. Les écrans visibles dépendent des permissions ; chaque route API vérifie ces mêmes droits.

## Préparer une offre réelle

1. Fournisseurs : créer l'identité contractuelle et les coordonnées internes autorisées. Ces contacts ne figurent pas dans les DTO publics.
2. Boutiques : sélectionner le fournisseur, saisir le slug et la description FR/EN, enregistrer puis publier.
3. Catégories : créer le parent éventuel, les noms FR/EN et les attributs spécifiques. Chaque attribut possède une clé stable, un type, des libellés, une unité éventuelle et des choix traduits. Publier le parent avant ses enfants.
4. Produits et variantes : sélectionner une catégorie publiée, compléter nom/description FR/EN, SKU et caractéristiques proposées par le formulaire. Les nombres utilisent un point décimal, jusqu'à quatre décimales. Enregistrer puis publier ; les attributs obligatoires sont contrôlés à la publication.
5. Médiathèque : importer une véritable photo autorisée, renseigner ses descriptions FR/EN. Le fichier reste privé. Confirmer les droits puis autoriser la publication. La sélection de média utilise uniquement les images ainsi approuvées.
6. Accords fournisseurs : choisir le périmètre et les dates en heure de Douala, le palier, la priorité, le taux négocié ou le coût fixe, et la référence réelle du contrat. Le pourcentage accepte un point ou une virgule et deux décimales au maximum. La pièce contractuelle jointe n'est pas encore gérée.
7. Offres : choisir boutique et variante publiées, prix public convenu, quantité minimale, disponibilité et garantie vérifiées. Sélectionner la photo publiée. Enregistrer le brouillon puis calculer le coût ; l'accord résolu et l'écart brut avant frais sont affichés.
8. Une promotion demande dates et financement exact par partie. La simulation future est séparée du prix actuel. Une marge négative exige un propriétaire autorisé et un motif conservé dans la révision. Vérifier les conditions, puis publier explicitement.

La boutique charge ces publications depuis l'API. Changer de catégorie met à jour les champs et filtres ; les caractéristiques sont affichées sur la fiche. Les montants internes restent dans la gestion.

## Modifier sans réécrire le passé

Un brouillon modifié conserve l'ancienne publication jusqu'à la prochaine publication explicite. Une version périmée est refusée : recharger la liste, rouvrir l'élément courant et reprendre les changements utiles. Ne pas forcer une sauvegarde silencieuse.

Les accords ne sont pas écrasés : retirer la règle courante des nouvelles résolutions, puis créer sa nouvelle période/règle. Le retrait peut laisser temporairement une offre sans accord utilisable : aucune nouvelle publication de cette offre ne doit passer avec un prix d'achat inventé. Les révisions existantes conservent leur accord et leur calcul. Les futures commandes devront encore figer leurs propres conditions.

L'archivage conserve les lignes et l'historique. Un fournisseur ayant des boutiques publiées, ou une catégorie ayant des dépendances publiées, ne peut pas être archivé directement. Une variante retirée d'un produit n'est pas remplacée silencieusement par une autre. Un ancien slug de boutique est résolu puis redirigé vers le slug publié actuel.

Retirer l'accès public d'une image la rend inaccessible sans autorisation. L'offre ne renvoie plus cette image ; une scène principale utilisant le média est retirée du carrousel public.

## Limites de validation

Les tests API et HTML serveur ne prouvent pas encore les interactions dans un navigateur. Aucun paiement, commande, réception ou notification réelle n'est déclenché par cet incrément. Les autres exigences du lancement demeurent dans EXIGENCES.md.
