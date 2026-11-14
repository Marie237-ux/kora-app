# Abonnements fournisseurs et mise en avant — backend

Incrément du 13 novembre 2026. Il couvre la fin du §19 côté API : abonnement fournisseur (formule « Standard ») et option de visibilité payante, comme **outils administrables**. Il n'y a ni tarif inventé, ni prélèvement automatique, ni abonnement obligatoire.

## Fermé par défaut, prix fixés par l'entreprise

- Abonnements et visibilité sont **fermés** tant que le propriétaire ne les ouvre pas (droit `suppliers.plans`, confirmation récente). On peut ouvrir l'un sans l'autre.
- **Aucune formule n'est créée par le logiciel.** L'entreprise saisit :
  - code, nom et description en FR/EN ;
  - **prix en XAF** (0 = gratuite) et durée de la période ;
  - avantages : liste de services, ou pour la visibilité, l'emplacement et le nombre d'articles mis en avant.
- Une formule proposée **ne se modifie pas** : on la retire et on en crée une autre. Les souscriptions déjà acceptées gardent leurs conditions figées (prix, période, avantages, version).

## Souscription et consentement

- Un **gérant du fournisseur** souscrit dans son portail **en acceptant explicitement** la formule affichée. Un employé ne peut pas engager le fournisseur.
- L'équipe peut aussi **proposer** une formule : elle reste sans effet tant que le fournisseur n'a pas consenti, soit dans le portail, soit hors portail avec un **document signé** enregistré et analysé.
- Le consentement garde sa méthode, sa date et l'empreinte des conditions acceptées.

## Paiement : des demandes identifiées, pas de prélèvement

- Chaque période donne lieu à une **demande de paiement numérotée** (`DPF-…`). La période **ne démarre qu'au paiement vérifié** : canal, référence réelle, date et preuve du fournisseur, saisis par la finance avec confirmation récente. Une formule gratuite démarre au consentement.
- Une demande payée reste payée ; elle ne peut être ni modifiée ni supprimée.
- **Renouvellement** seulement si le fournisseur l'a demandé :
  - le service quotidien (`supplier-subscriptions:daily`, 6 h Douala) émet la demande suivante avant la fin (7 jours par défaut) ;
  - payée, elle prolonge la période sans trou ;
  - impayée, la période se termine simplement et la demande est annulée.
- **Résiliation** :
  - par le gérant : la période payée va à son terme, rien n'est renouvelé, les demandes ouvertes sont retirées ;
  - par l'équipe : à terme ou immédiate avec motif.

## Mise en avant honnête

- Les articles mis en avant sont ceux du fournisseur (limite fixée par la formule), en vente à l'instant.
- Ils apparaissent dans un **bloc séparé, étiqueté « Sponsorisé / Sponsored »**, avec le nom de la boutique (`GET catalogue/sponsored`), et en nombre limité par emplacement (3 par défaut).
- **L'ordre normal du catalogue et de la recherche n'est jamais modifié par un paiement.**
- Un article retiré de la vente n'est plus mis en avant.
- Fermer la visibilité masque immédiatement tous les emplacements.

## Migrations, contrat et vérifications

Migration additive `2026_11_13_000050_create_supplier_subscriptions`, appliquée à la base locale `kora` (vide). Garde-fous SQL :
- consentement exigé hors proposition ;
- période cohérente ;
- conditions figées ;
- demande payée avec référence et vérificateur, immuable ensuite ;
- aucune suppression.

Contrat **0.44.0 / 558 chemins** (`update-supplier-offers-contract.mjs`) ; la chaîne rejouée donne une empreinte identique.

Tests `SupplierSubscriptionsTest` (2) :
- **formules et paiement** :
  - aucune formule par défaut, offres fermées ;
  - formule proposée non modifiable ; visibilité sans emplacement refusée ; droit propriétaire ;
  - employé refusé, acceptation explicite exigée ;
  - conditions figées malgré le retrait de la formule ;
  - paiement exigeant preuve et confirmation récente, une seule fois ; demande payée immuable ;
  - renouvellement émis une seule fois, prolongation sans trou ;
  - résiliation à terme puis clôture ;
- **visibilité** :
  - articles d'un autre fournisseur ou trop nombreux refusés ;
  - rien d'affiché avant le consentement par document signé ;
  - bloc étiqueté FR/EN, catalogue organique inchangé ;
  - article retiré non promu ;
  - résiliation immédiate, souscription close immuable ;
  - fermeture globale.

Résultats : [VALIDATION.md](VALIDATION.md).

## Limites

- Les demandes de paiement ne sont pas encore reliées à une facture client KORA ni capturées en comptabilité : ce sera fait avec le schéma comptable de ces services, à valider par le comptable.
- Pas d'envoi de rappel au fournisseur tant qu'aucun canal n'est configuré.
- Aucun écran (formules, portail, bloc sponsorisé).
