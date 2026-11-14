# KORA

Commerce à Douala : boutique Astro, gestion web Next.js et API métier Laravel/PostgreSQL.

Le périmètre contractuel reste celui de [KORA_PROMPT_MAITRE_CODEX.md](KORA_PROMPT_MAITRE_CODEX.md), lu intégralement le 29 octobre 2026. Aucune application native n'est développée actuellement. Aucune publication, aucun encaissement ni message externe réel n'est autorisé pendant ce développement.

## Organisation du dépôt

Trois applications partagent un même dépôt, comme l'exige le §2 du prompt : chacune garde son moteur, et toutes les règles métier vivent dans l'API.

| Dossier                    | Rôle                                                                                  |
| -------------------------- | ------------------------------------------------------------------------------------- |
| `apps/storefront`          | Boutique publique (Astro) : accueil, catalogue, fiches, vitrines, compte, puis panier et paiement |
| `apps/operations`          | Back-office, espace livreur et fournisseur (Next.js)                                  |
| `services/api`             | API Laravel : prix, stock, droits, paiements, comptabilité. Seule source de vérité    |
| `services/api/app/Domain`  | Logique métier rangée par domaine (Catalogue, Cart, Boards, Inventory, Procurement…)  |
| `packages/ui`              | Composants React partagés, sans dépendance à un routeur                              |
| `packages/design-tokens`   | Couleurs, typographies, thèmes clair/sombre                                          |
| `packages/api-client`      | Client TypeScript généré depuis `docs/openapi.json`                                  |
| `docs`                     | Suivi, exigences, décisions, installation, exploitation, preuves de tests            |
| `scripts`                  | Base locale (`postgres.mjs`), installation API, contrôle du contrat                  |
| `scripts/contract`         | Un script par module qui ajoute ses routes et schémas au contrat OpenAPI             |
| `tests`                    | Tests du contrat, des composants et des réponses HTTP                                 |
| `references`               | Copies exactes des visuels et du logo fournis                                        |

## Style du code

Le code est formaté automatiquement, sans réglage à la main :

- TypeScript, Astro, CSS, JSON et scripts : Prettier (`.prettierrc.json`).
- PHP : Prettier coupe les lignes longues (120 caractères), puis Laravel Pint applique les conventions Laravel (`services/api/pint.json`).

```powershell
npm.cmd run format        # reformate tout le dépôt
npm.cmd run format:check  # vérifie sans modifier
```

Lancer `format` avant de livrer un changement. Une instruction par ligne, des imports en tête de fichier.

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Avancement et reprise](docs/AVANCEMENT.md)
- [Exigences et 110 scénarios](docs/EXIGENCES.md)
- [Résultats de vérification](docs/VALIDATION.md)
- [Promotions de livraison : règles et limites du backend](docs/INCREMENT_PROMOTIONS_LIVRAISON.md)
- [Confirmations fournisseur et choix client](docs/INCREMENT_CONFIRMATIONS_COMMANDES.md)
- [Achats et réceptions affectés aux commandes](docs/INCREMENT_AFFECTATIONS_COMMANDES.md)
- [Profils professionnels et devis](docs/INCREMENT_DEVIS_PROFESSIONNELS.md)
- [Fidélité : carte et registre de gains](docs/INCREMENT_FIDELITE.md)
- [Utilisation et restitution des points au paiement](docs/INCREMENT_CHECKOUT_POINTS.md)
- [Utiliser le catalogue et la médiathèque](docs/UTILISATION_CATALOGUE.md)
- [Décisions](docs/DECISIONS.md)
- [Références](references/README.md)

Les fonctions incomplètes sont indiquées dans le suivi ; la présence d'une interface ne vaut pas livraison d'un module.

Aperçus locaux : [boutique](http://localhost:4321/fr) et [gestion](http://localhost:3000/fr), également disponibles en anglais via /en. La gestion exige un compte autorisé et le MFA ; aucun compte privilégié partagé n'est créé par défaut.
