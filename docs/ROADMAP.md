# Feuille de route

## 0.1 — noyau SQLite

- package autonome et autoload PSR-4;
- entités typées et métadonnées par conventions/attributs;
- `DbContext`, `DbSet`, CRUD et requêtes paramétrées immuables;
- pagination, transactions et diagnostic;
- tests d'intégration SQLite en mémoire;
- stabilisation des erreurs, conversions de types et cache de métadonnées;
- `install data`, `data:doctor`, commandes de migration et générateurs modèle/migration/seeder disponibles;
- verrouillage des migrations, paramètres sensibles masqués et PHPStan au niveau maximal disponibles;

## 0.2 — unité de travail et schéma

- unité de travail, snapshots, détection automatique et `saveChanges()` disponibles en 0.1;
- validation avant écriture (premiers attributs disponibles en 0.1, règles extensibles à compléter);
- migrations SQLite, historique par lots, rollback et statut (socle disponible en 0.1; verrouillage à ajouter);
- seeders déterministes (exécution transactionnelle disponible en 0.1);
- `HasMany`, `BelongsTo`, `HasOne`, `BelongsToMany`, chargement groupé et synchronisation pivot disponibles en 0.1;
- constructeur de schéma SQLite disponible en 0.1, opérations d'altération à compléter;
- événements de requête et masquage configurable.

## 0.3 — SQL multi-moteur

- dialectes SQLite, MySQL/MariaDB et PostgreSQL disponibles en compilation dès 0.1;
- matrice de tests d'intégration activable par DSN disponible dès 0.1; automatisation par conteneurs à ajouter en CI;
- différences de types, identités et DDL documentées;
- transactions imbriquées par points de sauvegarde lorsque disponibles;
- génération/diff de migrations après stabilisation du format manuel.

## 0.4 — MongoDB séparé

- `ConnectionManager`, connexions nommées et contrat `DriverAdapter` disponibles dans le noyau dès 0.1;
- package local `phpaml/data-mongodb`, transport officiel, transport mémoire, CRUD, requêtes, pagination, transactions et diagnostic disponibles en alpha;
- package `phpaml/data-mongodb`;
- identités `ObjectId`, documents imbriqués et références;
- filtres communs et pipelines Mongo explicites;
- index, migrations de documents et seeders;
- sessions/transactions avec vérification des capacités du serveur.

## 0.5 — intégration et robustesse

- fournisseur extensible de commandes AML;
- intégration complète PHPAML classique et AML View;
- cache de métadonnées production, observabilité et détection N+1;
- guide de migration depuis `PHPAML\Data` et `app/Models`;
- tests de compatibilité et analyse statique au niveau maximal raisonnable.

## 1.0 — contrat stable

- API publique et politique de compatibilité sémantique;
- SQLite, MySQL/MariaDB et PostgreSQL supportés en production;
- adaptateur MongoDB versionné indépendamment;
- documentation complète, sécurité auditée et benchmarks publiables;
- publication uniquement après autorisation explicite du mainteneur.
