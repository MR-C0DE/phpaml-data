# Architecture proposée pour phpaml/data

## Décisions issues de l'audit

Le dépôt exige PHP 8.2, utilise PSR-4, `strict_types`, des classes finales par défaut et des tests PHP autonomes. Les modules modernes ont leur propre `composer.json`, le namespace `AML\<Module>` et vivent dans `runtime/build/phpaml-<module>`. Le CLI est bilingue et centralise aujourd'hui génération, installation, diagnostic et migrations dans un même exécutable.

Une première API `PHPAML\Data` existe dans le framework (`Connection`, `QueryBuilder`, `DbContext`, `Migration`, `Migrator`). Elle est utile comme preuve de concept, mais reste liée au framework, expose PDO directement, ne possède ni métadonnées riches, ni entités typées, ni adaptateurs. Le nouveau package doit devenir la source de vérité. Une couche de compatibilité du framework pourra ensuite déléguer vers `AML\Data`; aucune suppression de l'ancienne API n'est prévue avant une dépréciation documentée.

Le générateur actuel place les modèles classiques dans `app/Models/` et ceux d'AML View dans `src/models/`. Pour respecter le contrat du nouveau package, `aml make:model` devra converger vers `src/models/` dans les deux types de projet. Cette migration de structure doit être explicite et non destructive.

## Découpage cible

```text
phpaml-data/
  src/
    Contracts/          interfaces de stockage, session et dialecte
    Metadata/           attributs, conventions, cache de métadonnées
    Query/              AST commun, expressions, pagination
    ChangeTracking/     états Added/Modified/Deleted, unité de travail
    Validation/         règles et erreurs d'entité
    Relations/          has-one, has-many, belongs-to, many-to-many
    Diagnostics/        événements, durées, paramètres masqués
    SQL/
      Drivers/          SQLite, MySQL/MariaDB, PostgreSQL
      Query/            compilation de l'AST vers chaque dialecte
      Migrations/       schéma, diff et historique SQL
    NoSQL/
      MongoDB/          package/adaptateur séparé, compilation Mongo
    Migrations/         contrats communs et planification
    Seeding/            contrats et exécution des seeders
    Integration/AML/    manifeste et pont vers les commandes AML
  tests/
    Unit/
    Integration/SQLite/
    Integration/MySQL/
    Integration/PostgreSQL/
```

Le noyau ne dépend d'aucun framework. PDO reste une dépendance des pilotes SQL. L'adaptateur MongoDB est distribué séparément (nom proposé : `phpaml/data-mongodb`) et dépend de `phpaml/data` ainsi que de `ext-mongodb` ou de la bibliothèque MongoDB officielle.

## Contrats publics cibles

- `Entity` : identité et support futur du suivi des changements, sans imposer de magie aux propriétés.
- `DbContext` : durée de vie d'une session, accès aux ensembles, transactions et `saveChanges()` à partir de la v0.2.
- `DbSet<T>` : point d'entrée typé pour requêter et modifier un type d'entité.
- `Query<T>` : objet immuable représentant une requête, compilé par l'adaptateur.
- `EntityMetadata` : table/collection, clé, propriétés, conversions et relations.
- `DataStore` et `Transaction` : contrats indépendants du moteur.
- `Migration`, `Schema` et `Seeder` : contrats explicites, distincts du runtime de requête.

Les docblocks génériques (`DbSet<User>`, `Page<User>`) donnent l'inférence aux analyseurs statiques. Les attributs PHP décrivent uniquement ce que les conventions ne peuvent pas déduire.

## Relations et chargement

API cible SQL :

```php
#[HasMany(Post::class, foreignKey: 'author_id')]
public array $posts = [];

$users = $db->users()
    ->with('posts')
    ->where('active', '=', true)
    ->all();
```

`with()` effectue par défaut un chargement groupé afin d'éviter le problème N+1. `load($entity, 'posts')` assure le chargement explicite. Le lazy loading par proxy n'est pas prévu au départ : il masque les accès réseau et complique les propriétés typées.

## SQL et MongoDB

Le chemin SQL partage une représentation interne des filtres, tris, projections et limites, puis utilise un dialecte pour les identifiants, paramètres, retours de clés et opérations de schéma. SQLite est la référence fonctionnelle; MySQL et MariaDB partagent un pilote avec détection de capacités; PostgreSQL possède son dialecte et ses types.

MongoDB partage les concepts `Entity`, `DbContext`, `DbSet`, filtres, pagination, validation et diagnostic, mais pas le SQL ni les migrations de tables. Les différences restent visibles :

| Besoin | SQL | MongoDB |
|---|---|---|
| Identité | clé primaire, souvent entière | `_id`, généralement `ObjectId` |
| Relations | clés étrangères et jointures | références ou documents imbriqués |
| Schéma | migrations DDL | migrations de documents/index optionnelles |
| Transaction | support du pilote/SGBD | sessions MongoDB, replica set requis |
| Chargement | jointure ou requêtes groupées | agrégation `$lookup` ou références groupées |
| Requête avancée | expressions portables + extensions SQL | expressions portables + pipeline Mongo natif explicite |

Une API d'échappement spécifique (`sqlRaw()` ou `mongoPipeline()`) ne doit jamais prétendre être portable. Les migrations MongoDB utilisent `DocumentMigration` et ne réutilisent pas artificiellement `SchemaMigration`.

## Commandes AML cibles

```text
aml install data                         installe et configure SQLite par défaut
aml make:model User                     crée src/models/User.php
aml make:migration create_users_table   crée une migration horodatée
aml data:migrate                        applique les migrations du magasin actif
aml data:rollback [--steps N]            annule les derniers lots
aml data:seed [Seeder]                   exécute les seeders
aml data:status [--json]                 affiche pilote et migrations
aml data:doctor [--json]                 vérifie extension, connexion, droits et schéma
```

Le CLI doit découvrir un fournisseur de commandes livré par le module, plutôt que d'ajouter durablement chaque commande au grand `switch` actuel. L'installation met à jour `phpaml.json` et l'autoload de façon atomique. Elle ne contacte GitHub/Packagist qu'à la demande explicite de l'utilisateur; aucun mécanisme de publication n'appartient au package.

## Configuration cible

```php
'data' => [
    'default' => 'main',
    'connections' => [
        'main' => [
            'driver' => 'sqlite',
            'database' => dirname(__DIR__) . '/runtime/storage/app.sqlite',
        ],
    ],
    'models_path' => dirname(__DIR__) . '/src/models',
    'migrations_path' => dirname(__DIR__) . '/runtime/database/migrations',
    'seeders_path' => dirname(__DIR__) . '/runtime/database/seeders',
];
```

Les secrets MySQL/PostgreSQL/MongoDB viennent de l'environnement. `data:doctor` ne les affiche jamais. Le diagnostic de requête doit masquer les paramètres nommés `password`, `token`, `secret` et permettre une politique de masquage applicative.

`ConnectionManager` constitue désormais le point d'entrée de cette configuration. Il fournit les connexions SQL natives, le cache des connexions nommées et un registre de `DriverAdapter`. Le futur package `phpaml/data-mongodb` enregistrera son adaptateur `mongodb` dans ce registre sans introduire de dépendance MongoDB dans le noyau.
