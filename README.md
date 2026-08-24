# phpaml/data

Couche de données typée et indépendante pour PHPAML, AML View et les projets PHP 8.2+.

> État : `0.2.0-alpha.1`. SQLite, MySQL, MariaDB et PostgreSQL sont validés par intégration réelle. PHPStan passe au niveau maximal.

```bash
composer require phpaml/data:^0.2@alpha
```

## Exemple minimal

```php
use AML\Data\Connection;
use AML\Data\DbContext;
use AML\Data\DbSet;
use AML\Data\Entity;
use AML\Data\Metadata\{Key, Table};

#[Table('users')]
final class User extends Entity
{
    #[Key]
    public int $id;
    public string $name;
    public string $email;
}

final class AppDbContext extends DbContext
{
    /** @return DbSet<User> */
    public function users(): DbSet
    {
        return $this->set(User::class);
    }
}

$db = new AppDbContext(Connection::sqlite(__DIR__ . '/storage/app.sqlite'));

$user = new User();
$user->name = 'Ada';
$user->email = 'ada@example.com';
$db->users()->add($user);

$admins = $db->users()
    ->where('email', 'LIKE', '%@example.com')
    ->orderBy('name')
    ->paginate(page: 1, perPage: 20);
```

## Connexions nommées et choix du moteur

```php
return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'driver' => 'sqlite',
            'database' => 'runtime/storage/app.sqlite',
        ],
        'reporting' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'reporting',
            'username' => 'phpaml',
            'password' => getenv('REPORTING_PASSWORD'),
        ],
        'documents' => [
            'driver' => 'mongodb',
            'uri' => 'mongodb://127.0.0.1:27017',
            'database' => 'documents',
        ],
    ],
];
```

```php
$manager = new AML\Data\Connections\ConnectionManager($projectRoot, $config);
$main = $manager->sql();
$reporting = $manager->sql('reporting');
$db = $manager->context(AppDbContext::class, 'main');
```

Les pilotes SQL natifs sont `sqlite`, `mysql`, `mariadb` et `pgsql`. `mongodb` est un point d'extension : sans adaptateur enregistré, le gestionnaire demande explicitement l'installation de `phpaml/data-mongodb`.

Les commandes acceptent le même nom de connexion :

```bash
aml data:migrate --connection main
aml data:rollback --connection main --steps 2
aml data:seed --connection main
aml data:status --connection reporting
aml data:doctor --connection documents --json
```

L'ancienne configuration plate avec une simple clé `dsn` reste acceptée et devient implicitement la connexion `main`.

## Installation et génération AML

```bash
aml install data --driver sqlite
aml make:model User
aml make:migration create_users_table
aml make:seeder UserSeeder
```

Dans un projet moderne, l'installation prépare `src/models/`, `src/Data/`,
`runtime/database/migrations/`, `runtime/database/seeders/` et le stockage
SQLite, puis écrit la configuration dans la section `data` de `phpaml.json`.
Un ancien projet sans section `application` conserve automatiquement
`configs/data.php` pour assurer sa compatibilité.

Les modèles générés étendent `AML\Data\Entity`. Les migrations utilisent `Schema` et `Table`; les seeders implémentent `AML\Data\Seeding\Seeder`.

## Relations

```php
use AML\Data\Relations\{BelongsTo, BelongsToMany, HasMany, HasOne};

final class User extends Entity
{
    public int $id;

    /** @var list<Post> */
    #[HasMany(Post::class, foreignKey: 'user_id')]
    public array $posts = [];

    #[HasOne(Profile::class, foreignKey: 'user_id')]
    public ?Profile $profile = null;

    /** @var list<Role> */
    #[BelongsToMany(
        Role::class,
        pivotTable: 'role_user',
        pivotLocalKey: 'user_id',
        pivotTargetKey: 'role_id',
    )]
    public array $roles = [];
}

final class Post extends Entity
{
    public int $id;
    public int $userId;

    #[BelongsTo(User::class, foreignKey: 'user_id')]
    public ?User $author = null;
}

$users = $db->users()->with('posts')->all();
```

`with()` charge chaque relation par requêtes groupées et évite le problème N+1. Une table pivot peut être maintenue explicitement :

```php
$roles = $db->relation($user, 'roles');
$roles->attach([1, 2]);
$roles->detach(1);
$roles->sync([2, 3]);
```

`attach()` ignore les associations déjà présentes. `sync()` attache et détache uniquement les différences.

## Migrations et schéma SQLite

```php
use AML\Data\Connection;
use AML\Data\Migrations\Migration;
use AML\Data\Schema\{Schema, Table};

return new class extends Migration {
    public function up(Connection $connection): void
    {
        (new Schema($connection))->create('users', function (Table $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 180)->unique();
            $table->boolean('active')->default(true);
            $table->text('bio')->nullable();
            $table->timestamps();
        });
    }

    public function down(Connection $connection): void
    {
        (new Schema($connection))->dropIfExists('users');
    }
};
```

Le constructeur de schéma compile désormais SQLite, MySQL/MariaDB et PostgreSQL. Les dialectes gèrent notamment les identifiants, booléens, clés auto-incrémentées et le `RETURNING` PostgreSQL.

```php
$mysql = new Connection('mysql:host=localhost;dbname=app', 'user', 'password');
$postgres = new Connection('pgsql:host=localhost;dbname=app', 'user', 'password');
```

Ces moteurs sont couverts par la matrice d'intégration sur serveurs réels.

## Matrice d'intégration SQL

Les tests serveur sont activés uniquement lorsque leurs variables sont présentes :

```bash
AML_DATA_MYSQL_DSN='mysql:host=127.0.0.1;dbname=phpaml_data_test' \
AML_DATA_MYSQL_USER='phpaml' \
AML_DATA_MYSQL_PASSWORD='secret' \
php tests/databases.php

AML_DATA_PGSQL_DSN='pgsql:host=127.0.0.1;dbname=phpaml_data_test' \
AML_DATA_PGSQL_USER='phpaml' \
AML_DATA_PGSQL_PASSWORD='secret' \
php tests/databases.php
```

La matrice crée une table temporaire dédiée, vérifie schéma, CRUD, génération de clé et rollback, puis supprime cette table. Sans DSN, le moteur est indiqué comme ignoré et la suite reste verte.

`aml data:doctor --json` expose désormais le pilote PDO, la connexion, la version du serveur et les capacités détectées (`transactions`, `savepoints`, `returning`, `foreign_keys`) sans afficher le mot de passe.

Les paramètres dont le nom contient `password`, `secret`, `token`, `api_key`, `authorization` ou `cookie` sont automatiquement masqués dans les diagnostics de requêtes.

## Validation multi-environnement

La procédure [qa/README.md](qa/README.md) démarre MySQL 8.4, MariaDB 11.4, PostgreSQL 17 et MongoDB 8.2 en replica set. Le workflow `.github/workflows/ci.yml` couvre PHP 8.2, 8.3 et 8.4.

Des smoke tests valident aussi l'installation dans une application PHPAML classique, une application AML View et un projet PHP autonome.

## Durcissement des migrations

Les migrations utilisent un verrou concurrent : fichier verrouillé sous SQLite, `GET_LOCK` sous MySQL/MariaDB et verrou consultatif sous PostgreSQL. Une erreur expose la migration concernée et la phase `up` ou `down`. Le constructeur de schéma prend en charge les index simples/composés, l'ajout ou le renommage de colonnes, la suppression de colonnes, les index et le renommage de tables.

Le guide [docs/MIGRATION.md](docs/MIGRATION.md) explique la transition depuis l'ancienne API `PHPAML\Data` et le pont temporaire qui réutilise son objet PDO.

## Unité de travail

Le CRUD de `DbSet` reste immédiat. Pour regrouper plusieurs changements atomiquement :

```php
$db->add($newUser);
$db->update($existingUser);
$db->remove($obsoleteUser);
$affected = $db->saveChanges();
```

Les entités obtenues depuis un `DbSet` sont suivies automatiquement. Modifier une propriété publique puis appeler `saveChanges()` suffit donc sans `update()` explicite. Si une opération échoue, toute l'unité de travail est annulée et demeure en attente. Les transactions imbriquées utilisent des points de sauvegarde.

Les méthodes de construction de requête retournent des clones : un `DbSet` peut être réutilisé sans conserver les filtres d'une requête précédente. Les valeurs passent toujours par des paramètres préparés et les noms de colonnes doivent appartenir aux métadonnées de l'entité.

## Transactions et diagnostic

```php
$db->transaction(function (AppDbContext $db): void {
    $db->users()->add($first);
    $db->users()->add($second);
});
```

Un objet implémentant `AML\Data\Diagnostics\QueryLogger` peut être fourni à `Connection::sqlite()`. Il reçoit le SQL, ses paramètres et la durée. Les applications doivent masquer les données sensibles dans leur implémentation du journal.

## Structure d'application

Les entités applicatives restent dans `src/models/`, avec le namespace `App\Models`. Le package ne déplace pas les modèles dans son propre dossier.

```text
src/
  models/
    User.php
  Data/
    AppDbContext.php
database/
  migrations/
  seeders/
```

## Portée actuelle

Disponible dans cette version alpha : entités, attributs `Table`, `Column` et `Key`, `DbContext`, `DbSet`, CRUD, `where`, `orderBy`, `limit`, `first`, `find`, `count`, pagination, transactions et diagnostic des requêtes.

La validation (`Required`, `Email`, `Length`), les quatre relations principales, `with()`, le suivi automatique, `saveChanges()`, les migrations par lots, les rollbacks, les statuts, les seeders transactionnels et les commandes `data:migrate`, `data:rollback`, `data:seed`, `data:status` et `data:doctor` font également partie de l'alpha.

L'installation automatisée est disponible via `aml install data`, avec sélection du pilote par `--driver`.
