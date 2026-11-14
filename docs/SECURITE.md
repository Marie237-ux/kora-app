# Sécurité et preuves

Référentiel cible : OWASP ASVS 5 niveau 2 applicable, contrôles renforcés administration/finance. Aucune certification revendiquée.

Menaces prioritaires : usurpation/session et contournement MFA, accès à un autre client/fournisseur/mission, paiement falsifié, doubles allocations, fuite de coûts, pièce privée exposée, téléversement malveillant, injection de prompt fournisseur, SSRF et secrets dans les journaux.

Contrôles : cookies HttpOnly/SameSite/Secure en production, CSRF Sanctum, origines explicites ; validation serveur ; DTO distincts ; permissions par action/ressource ; MFA sans voie alternative moins protégée ; idempotence et transactions ; médias réencodés privés ; outbox et audit minimisé. Leur état réel et leurs tests sont consignés dans VALIDATION.md, pas présumés acquis par ce document.

Interdictions : aucun secret Git/navigateur/log ; aucun jeton durable localStorage ; aucun CORS * avec credentials ; aucune confiance dans une URL de succès paiement ; aucune table commerciale publique Supabase ; aucun cache partagé de pages privées.

Avant lancement : matrice ASVS détaillée et preuves, tests d'isolation API/fichiers/exports/canaux, concurrence réelle PostgreSQL, audit dépendances, restauration et revue des règles finance. Conservation et suppression à fixer selon l'exploitant réel.

Preuves catalogue : médias privés intergestionnaires, refus PHP/SVG/dimensions excessives, réencodage/EXIF/transparence, confirmation des droits, retrait public et nettoyage sur échec DB. Contacts fournisseur limités aux permissions achats ; coût, accords et marge absents des DTO publics. Publication catalogue soumise au MFA, à la version courante et aux dépendances. Aucun contrôle S3 distant revendiqué.

Stock/approvisionnement : permissions/MFA sur toutes les routes, confirmation récente du mot de passe sur les routes finance, UUID Idempotency-Key et conflit de contenu, transactions/verrous, historiques et affectations append-only. Deux tests multiprocessus prouvent l'absence de double réservation et de suraffectation financière pour leurs scénarios PostgreSQL ; les autres domaines restent à vérifier.

Pièces originales privées sous droits par finalité, empreinte SHA-256, scan bloquant, téléchargement en pièce jointe/no-store/nosniff/sandbox et contrôle d'intégrité. Un justificatif financier est illisible aux achats. Un scanner absent/échoué laisse pending ; ClamAV réel non configuré ici. Les tests injectent explicitement un scanner contrôlé. Les logs d'erreurs DB excluent SQL, bindings et contenus privés.


Rappels : aperçu générique sans nom/montant fournisseur, audience sous permissions actuelles/MFA, lecture liée à l'utilisateur connecté. Diagnostics sans payload et reprise propriétaire motivée avec confirmation récente. Les erreurs de worker ne journalisent que l'identifiant/catégorie ; reprises bornées et historique append-only. Trois scénarios de concurrence réelle PostgreSQL sont prouvés, plus un snapshot multiconnexion ; ils ne certifient pas les domaines futurs.
