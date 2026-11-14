# Conditions de lancement — non satisfait

Tous les modules web et les scénarios V01–V110 restent dans le périmètre avant ouverture. L'état détaillé est dans EXIGENCES.md et VALIDATION.md.

Il faut renseigner et valider :

- Exploitant, identifiants, coordonnées, rôle vendeur et régime fiscal réels, mentions et contrats approuvés.
- Fournisseurs/boutiques autorisés, accords datés, catalogue réel, stocks vérifiés, garanties et droits médias.
- Domaines, hébergement chiffré, TLS, secrets, PostgreSQL/migrations, stockage, workers, planificateur et sauvegardes restaurées.
- Compte et version Monetbil, clés sandbox/production distinctes, frais, retraits, remboursements et rapprochements réels autorisés. À confirmer avec Monetbil avant ouverture (la seule documentation publique est le SDK PHP de 2021) : champs exacts de checkPayment (montant, devise, payment_ref), identité entre transaction_id et paymentId, URL de base actuelles, activation du mode test et politique de renvoi des notifications. Variables : MONETBIL_SERVICE_KEY, MONETBIL_SERVICE_SECRET, MONETBIL_ACCEPT_TESTMODE (false en production), KORA_STOREFRONT_URL ; URL de notification publique en HTTPS ; planificateur actif pour payments:reconcile.
- Zones Douala, grilles de livraison, collecte, distance, véhicules, créneaux et capacités ; couverture des avantages et budgets.
- Règles fidélité, parrainage, professionnels, souscriptions et campagnes activées explicitement.
- Numéro WhatsApp, canaux transactionnels, consentements, IA/embeddings et budgets.
- MFA et récupération de tous les rôles sensibles, isolation et tests financiers/concurrence, sécurité et performances.
- Paramétrage comptable validé par le comptable, pièces fiscales et formats réellement applicables.

Références manquantes : briefing historique Word ; image de composition grand sac ; variante logo sombre d'origine exploitable. Leur absence n'autorise pas une réinvention présentée comme l'original.
