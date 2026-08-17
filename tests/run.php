<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AML\Data\Connection;
use AML\Data\DbContext;
use AML\Data\Diagnostics\QueryLog;
use AML\Data\Diagnostics\QueryLogger;
use AML\Data\Entity;
use AML\Data\Metadata\Key;
use AML\Data\Metadata\Table;
use AML\Data\Migrations\Migration;
use AML\Data\Migrations\Migrator;
use AML\Data\Seeding\Seeder;
use AML\Data\Seeding\SeederRunner;
use AML\Data\Validation\Email;
use AML\Data\Validation\Required;
use AML\Data\Validation\ValidationException;
use AML\Data\Relations\BelongsTo;
use AML\Data\Relations\HasMany;
use AML\Data\Relations\HasOne;
use AML\Data\Relations\BelongsToMany;
use AML\Data\Schema\Schema;
use AML\Data\Schema\Table as SchemaTable;
use AML\Data\SQL\MySqlDialect;
use AML\Data\SQL\PostgresDialect;
use AML\Data\SQL\SqliteDialect;
use AML\Data\Diagnostics\ConnectionDoctor;
use AML\Data\Connections\ConnectionManager;
use AML\Data\Connections\DriverAdapter;
use AML\Data\Connections\AdapterNotInstalledException;
use AML\Data\Integration\ProjectScaffolder;
use AML\Data\Migrations\MigrationLock;
use AML\Data\Migrations\MigrationException;
use AML\Data\Schema\AlterTable;
use AML\Data\Compatibility\LegacyConnectionAdapter;

#[Table('users')]
final class User extends Entity
{
    #[Key]
    public int $id;
    public string $name;
    #[Required, Email]
    public string $email;
    /** @var list<Post> */
    #[HasMany(Post::class, foreignKey: 'user_id')]
    public array $posts = [];
    #[HasOne(Profile::class, foreignKey: 'user_id')]
    public ?Profile $profile = null;
    /** @var list<Role> */
    #[BelongsToMany(Role::class, pivotTable: 'role_user', pivotLocalKey: 'user_id', pivotTargetKey: 'role_id')]
    public array $roles = [];
}

#[Table('posts')]
final class Post extends Entity
{
    #[Key]
    public int $id;
    public int $userId;
    public string $title;
    #[BelongsTo(User::class, foreignKey: 'user_id')]
    public ?User $author = null;
}

#[Table('profiles')]
final class Profile extends Entity
{
    public int $id;
    public int $userId;
    public string $bio;
}

#[Table('roles')]
final class Role extends Entity
{
    public int $id;
    public string $name;
}

final class TestContext extends DbContext
{
    /** @return \AML\Data\DbSet<User> */
    public function users(): \AML\Data\DbSet { return $this->set(User::class); }
    /** @return \AML\Data\DbSet<Post> */
    public function posts(): \AML\Data\DbSet { return $this->set(Post::class); }
}

final class MemoryLogger implements QueryLogger
{
    /** @var list<QueryLog> */
    public array $queries = [];
    public function log(QueryLog $query): void { $this->queries[] = $query; }
}

$tests = [];
$test = static function (string $name, Closure $case) use (&$tests): void { $tests[$name] = $case; };
$expect = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };

$test('CRUD typé, requêtes chaînables et pagination SQLite', function () use ($expect): void {
    $logger = new MemoryLogger();
    $connection = Connection::sqlite(logger: $logger);
    $connection->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');
    $context = new TestContext($connection);
    foreach ([['Ada', 'ada@example.test'], ['Grace', 'grace@example.test'], ['Linus', 'linus@example.test']] as [$name, $email]) {
        $user = new User(); $user->name = $name; $user->email = $email; $context->users()->add($user);
    }
    $page = $context->users()->where('name', 'LIKE', '%a%')->orderBy('name')->paginate(1, 2);
    $expect($page->total === 2 && count($page->items) === 2, 'La pagination filtrée est incorrecte.');
    $ada = $context->users()->find(1);
    $expect($ada instanceof User && $ada->name === 'Ada', "L'hydratation typée est incorrecte.");
    $ada->name = 'Ada Lovelace'; $context->users()->update($ada);
    $expect($context->users()->find(1)?->name === 'Ada Lovelace', 'La mise à jour a échoué.');
    $context->users()->remove($ada);
    $expect($context->users()->count() === 2, 'La suppression a échoué.');
    $expect(count($logger->queries) >= 8 && $logger->queries[0]->durationMilliseconds >= 0, 'Le diagnostic des requêtes est absent.');
});

$test('une transaction en erreur est annulée', function () use ($expect): void {
    $connection = Connection::sqlite();
    $connection->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');
    $context = new TestContext($connection);
    try {
        $context->transaction(function (TestContext $db): void {
            $user = new User(); $user->name = 'Rollback'; $user->email = 'rollback@example.test'; $db->users()->add($user);
            throw new RuntimeException('stop');
        });
    } catch (RuntimeException) {
    }
    $expect($context->users()->count() === 0, "La transaction n'a pas été annulée.");
});

$test("la validation bloque l'écriture et expose les erreurs", function () use ($expect): void {
    $connection = Connection::sqlite();
    $connection->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');
    $context = new TestContext($connection);
    $user = new User(); $user->name = 'Invalid'; $user->email = 'not-an-email';
    try {
        $context->users()->add($user);
        $expect(false, 'Une adresse invalide a été acceptée.');
    } catch (ValidationException $error) {
        $expect(isset($error->errors()['email']), "L'erreur de validation manque.");
    }
    $expect($context->users()->count() === 0, "L'entité invalide a été écrite.");
});

$test('les migrations utilisent des lots, un statut et un rollback', function () use ($expect): void {
    $directory = sys_get_temp_dir() . '/phpaml-data-' . bin2hex(random_bytes(5));
    mkdir($directory, 0755, true);
    $source = <<<'PHP'
<?php
return new class extends \AML\Data\Migrations\Migration {
    public function up(\AML\Data\Connection $connection): void { $connection->pdo()->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)'); }
    public function down(\AML\Data\Connection $connection): void { $connection->pdo()->exec('DROP TABLE notes'); }
};
PHP;
    file_put_contents($directory . '/202608170001_create_notes.php', $source);
    $connection = Connection::sqlite();
    $migrator = new Migrator($connection, $directory);
    $expect($migrator->migrate() === ['202608170001_create_notes.php'], "La migration n'a pas été appliquée.");
    $expect($migrator->migrate() === [], 'Une migration a été rejouée.');
    $status = $migrator->status()[0];
    $expect($status->executed && $status->batch === 1, 'Le statut de migration est incorrect.');
    $expect($migrator->rollback() === ['202608170001_create_notes.php'], "Le rollback n'a pas été exécuté.");
    $tables = $connection->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'notes'")->fetchAll();
    $expect($tables === [], 'La table existe encore après rollback.');
    unlink($directory . '/202608170001_create_notes.php'); unlink($directory . '/.aml-data-migrations.lock'); rmdir($directory);
});

$test('les seeders sont atomiques', function () use ($expect): void {
    $connection = Connection::sqlite();
    $connection->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');
    $context = new TestContext($connection);
    $seeder = new class implements Seeder {
        public function seed(DbContext $context): void {
            $user = new User(); $user->name = 'Seed'; $user->email = 'seed@example.test';
            $context->set(User::class)->add($user);
        }
    };
    (new SeederRunner($context))->run([$seeder]);
    $expect($context->users()->count() === 1, "Le seeder n'a pas été exécuté.");
});

$test('with charge les relations par groupes sans requête N+1', function () use ($expect): void {
    $logger = new MemoryLogger();
    $connection = Connection::sqlite(logger: $logger);
    $connection->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');
    $connection->pdo()->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL)');
    $context = new TestContext($connection);
    foreach ([['Ada', 'ada@example.test'], ['Grace', 'grace@example.test']] as [$name, $email]) {
        $user = new User(); $user->name = $name; $user->email = $email; $context->users()->add($user);
    }
    foreach ([[1, 'A'], [1, 'B'], [2, 'C']] as [$userId, $title]) {
        $post = new Post(); $post->userId = $userId; $post->title = $title; $context->posts()->add($post);
    }
    $before = count($logger->queries);
    $users = $context->users()->orderBy('id')->with('posts')->all();
    $expect(count($logger->queries) - $before === 2, 'Le chargement has-many doit utiliser exactement deux requêtes.');
    $expect(count($users[0]->posts) === 2 && count($users[1]->posts) === 1, 'Les articles sont mal groupés.');
    $before = count($logger->queries);
    $posts = $context->posts()->orderBy('id')->with('author')->all();
    $expect(count($logger->queries) - $before === 2, 'Le chargement belongs-to doit utiliser exactement deux requêtes.');
    $expect($posts[0]->author?->name === 'Ada' && $posts[2]->author?->name === 'Grace', 'Les auteurs sont incorrects.');
});

$test('saveChanges applique une unité de travail atomique', function () use ($expect): void {
    $connection = Connection::sqlite();
    $connection->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');
    $context = new TestContext($connection);
    $first = new User(); $first->name = 'First'; $first->email = 'first@example.test';
    $second = new User(); $second->name = 'Second'; $second->email = 'invalid';
    $context->add($first); $context->add($second);
    try { $context->saveChanges(); } catch (ValidationException) {}
    $expect($context->users()->count() === 0 && $context->pendingChanges() === 2, "L'unité de travail invalide doit rester en attente après rollback.");
    $second->email = 'second@example.test';
    $expect($context->saveChanges() === 2 && $context->pendingChanges() === 0, 'Les changements valides ne sont pas enregistrés.');
    $first->name = 'Updated'; $context->update($first); $context->saveChanges();
    $expect($context->users()->find($first->id)?->name === 'Updated', 'La modification suivie a échoué.');
    $context->remove($second); $context->saveChanges();
    $expect($context->users()->count() === 1, 'La suppression suivie a échoué.');
});

$test('les transactions imbriquées utilisent des points de sauvegarde', function () use ($expect): void {
    $connection = Connection::sqlite();
    $connection->pdo()->exec('CREATE TABLE values_test (value TEXT)');
    $connection->transaction(function (Connection $connection): void {
        $connection->execute("INSERT INTO values_test (value) VALUES ('outer')");
        try {
            $connection->transaction(function (Connection $connection): void {
                $connection->execute("INSERT INTO values_test (value) VALUES ('inner')");
                throw new RuntimeException('rollback inner');
            });
        } catch (RuntimeException) {
        }
        $connection->execute("INSERT INTO values_test (value) VALUES ('after')");
    });
    $values = $connection->pdo()->query('SELECT value FROM values_test ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN);
    $expect($values === ['outer', 'after'], 'Le point de sauvegarde imbriqué est incorrect.');
});

$test('HasOne et BelongsToMany sont chargées par groupes', function () use ($expect): void {
    $logger = new MemoryLogger();
    $connection = Connection::sqlite(logger: $logger);
    $connection->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
    $connection->pdo()->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY, user_id INTEGER, bio TEXT)');
    $connection->pdo()->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->pdo()->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
    $connection->pdo()->exec("INSERT INTO users VALUES (1, 'Ada', 'ada@example.test'), (2, 'Grace', 'grace@example.test')");
    $connection->pdo()->exec("INSERT INTO profiles VALUES (1, 1, 'Math'), (2, 2, 'Compilers')");
    $connection->pdo()->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'editor')");
    $connection->pdo()->exec('INSERT INTO role_user VALUES (1, 1), (1, 2), (2, 2)');
    $context = new TestContext($connection);
    $before = count($logger->queries);
    $users = $context->users()->orderBy('id')->with('profile', 'roles')->all();
    $expect(count($logger->queries) - $before === 4, 'HasOne et many-to-many doivent utiliser quatre requêtes au total.');
    $expect($users[0]->profile?->bio === 'Math' && count($users[0]->roles) === 2, 'Les relations du premier utilisateur sont incorrectes.');
    $expect($users[1]->profile?->bio === 'Compilers' && $users[1]->roles[0]->name === 'editor', 'Les relations du second utilisateur sont incorrectes.');
});

$test('saveChanges détecte automatiquement une entité chargée modifiée', function () use ($expect): void {
    $connection = Connection::sqlite();
    $connection->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
    $connection->pdo()->exec("INSERT INTO users VALUES (1, 'Before', 'before@example.test')");
    $context = new TestContext($connection);
    $user = $context->users()->find(1);
    $expect($user instanceof User && $context->pendingChanges() === 0, "L'entité chargée ne doit pas être modifiée initialement.");
    $user->name = 'After';
    $expect($context->pendingChanges() === 1, "La modification automatique n'a pas été détectée.");
    $expect($context->saveChanges() === 1 && $context->pendingChanges() === 0, "La modification automatique n'a pas été enregistrée.");
    $expect($context->users()->find(1)?->name === 'After', 'La valeur persistée est incorrecte.');
});

$test('attach, detach et sync maintiennent une table pivot', function () use ($expect): void {
    $connection = Connection::sqlite();
    $connection->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
    $connection->pdo()->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->pdo()->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER, UNIQUE(user_id, role_id))');
    $connection->pdo()->exec("INSERT INTO users VALUES (1, 'Ada', 'ada@example.test')");
    $connection->pdo()->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'editor'), (3, 'reader')");
    $context = new TestContext($connection);
    $user = $context->users()->find(1);
    $expect($user instanceof User, "L'utilisateur pivot est absent.");
    $roles = $context->relation($user, 'roles');
    $expect($roles->attach([1, 2, 2]) === 2, "L'attachement initial est incorrect.");
    $expect($roles->attach(2) === 0, "L'attachement doit être idempotent.");
    $expect($roles->sync([2, 3]) === 2, 'La synchronisation doit détacher 1 et attacher 3.');
    $expect(array_map('intval', $roles->keys()) === [2, 3], 'Les clés synchronisées sont incorrectes.');
    $expect($roles->detach() === 2 && $roles->keys() === [], 'Le détachement complet a échoué.');
});

$test('le constructeur de schéma crée des tables SQLite typées', function () use ($expect): void {
    $connection = Connection::sqlite();
    $schema = new Schema($connection);
    $schema->create('accounts', function (SchemaTable $table): void {
        $table->id();
        $table->string('email', 180)->unique();
        $table->boolean('active')->default(true);
        $table->text('bio')->nullable();
        $table->timestamps();
    });
    $expect($schema->hasTable('accounts'), "La table n'a pas été créée.");
    $connection->execute('INSERT INTO accounts (email) VALUES (:email)', [':email' => 'ada@example.test']);
    $row = $connection->pdo()->query('SELECT * FROM accounts')->fetch();
    $expect($row['active'] === 1 && $row['bio'] === null && $row['created_at'] !== null, 'Les valeurs par défaut du schéma sont incorrectes.');
    $schema->dropIfExists('accounts');
    $expect(!$schema->hasTable('accounts'), "La table n'a pas été supprimée.");
});

$test('les dialectes compilent identifiants, clés et types propres au SGBD', function () use ($expect): void {
    $sqlite = new SchemaTable('users', new SqliteDialect());
    $sqlite->id(); $sqlite->boolean('active');
    $mysql = new SchemaTable('users', new MySqlDialect());
    $mysql->id(); $mysql->boolean('active');
    $postgres = new SchemaTable('users', new PostgresDialect());
    $postgres->id(); $postgres->boolean('active')->default(true);
    $expect(str_contains($sqlite->toSql(), '"id" INTEGER PRIMARY KEY AUTOINCREMENT'), 'La clé SQLite est incorrecte.');
    $expect(str_contains($mysql->toSql(), '`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY') && str_contains($mysql->toSql(), 'TINYINT(1)'), 'Le dialecte MySQL est incorrect.');
    $expect(str_contains($postgres->toSql(), '"id" BIGSERIAL PRIMARY KEY') && str_contains($postgres->toSql(), 'BOOLEAN NOT NULL DEFAULT TRUE'), 'Le dialecte PostgreSQL est incorrect.');
    $expect((new PostgresDialect())->insertReturning('id') === ' RETURNING "id"', 'RETURNING PostgreSQL est absent.');
    $expect((new Connection('mysql:host=localhost'))->dialect()->name() === 'mysql', 'La détection MySQL a échoué.');
    $expect((new Connection('pgsql:host=localhost'))->dialect()->name() === 'pgsql', 'La détection PostgreSQL a échoué.');
});

$test('le diagnostic décrit la connexion SQLite et ses capacités', function () use ($expect): void {
    $report = (new ConnectionDoctor())->inspect(Connection::sqlite());
    $expect($report->connected && $report->driver === 'sqlite', 'Le diagnostic SQLite a échoué.');
    $expect($report->extensionAvailable && $report->serverVersion !== null, 'La version SQLite manque.');
    $expect(($report->capabilities['foreign_keys'] ?? false) === true && ($report->capabilities['savepoints'] ?? false) === true, 'Les capacités SQLite sont incorrectes.');
});

$test('ConnectionManager sélectionne les connexions et accepte des adaptateurs', function () use ($expect): void {
    $manager = new ConnectionManager(sys_get_temp_dir(), [
        'default' => 'main',
        'connections' => [
            'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'archive' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'documents' => ['driver' => 'mongodb', 'uri' => 'mongodb://localhost'],
        ],
    ]);
    $expect($manager->defaultName() === 'main' && $manager->names() === ['main', 'archive', 'documents'], 'Les connexions nommées sont incorrectes.');
    $expect($manager->sql() === $manager->sql('main') && $manager->sql('archive') !== $manager->sql('main'), 'Le cache des connexions est incorrect.');
    try { $manager->connection('documents'); $expect(false, "L'absence de MongoDB aurait dû échouer."); }
    catch (AdapterNotInstalledException $error) { $expect(str_contains($error->getMessage(), 'phpaml/data-mongodb'), "Le package MongoDB n'est pas indiqué."); }
    $adapter = new class implements DriverAdapter {
        public function connect(array $config, string $projectRoot): mixed { return (object) ['uri' => $config['uri'], 'root' => $projectRoot]; }
    };
    $manager->register('mongodb', $adapter);
    $documents = $manager->connection('documents');
    $expect(is_object($documents) && $documents->uri === 'mongodb://localhost', "L'adaptateur MongoDB enregistré n'est pas utilisé.");
    $legacy = new ConnectionManager(sys_get_temp_dir(), ['dsn' => 'sqlite::memory:']);
    $expect($legacy->sql()->dialect()->name() === 'sqlite', "L'ancienne configuration plate n'est plus compatible.");
});

$test("l'installateur et les générateurs préparent un projet sans écraser", function () use ($expect): void {
    $root = sys_get_temp_dir() . '/phpaml-data-project-' . bin2hex(random_bytes(5));
    mkdir($root, 0755, true);
    file_put_contents($root . '/phpaml.json', json_encode(['name' => 'test', 'modules' => []], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_THROW_ON_ERROR));
    $scaffolder = new ProjectScaffolder($root);
    $changes = $scaffolder->install('sqlite');
    $expect(in_array('configs/data.php', $changes, true) && is_dir($root . '/src/models'), "L'installation est incomplète.");
    $originalConfig = file_get_contents($root . '/configs/data.php');
    $scaffolder->install('pgsql');
    $expect(file_get_contents($root . '/configs/data.php') === $originalConfig, 'La configuration existante a été écrasée.');
    $model = $scaffolder->model('User');
    $migration = $scaffolder->migration('create_users_table');
    $seeder = $scaffolder->seeder('User');
    $expect($model === 'src/models/User.php' && str_contains((string) file_get_contents($root . '/' . $model), 'extends Entity'), 'Le modèle généré est incorrect.');
    $expect(str_contains($migration, 'create_users_table') && str_contains((string) file_get_contents($root . '/' . $migration), "create('users'"), 'La migration générée est incorrecte.');
    $expect($seeder === 'database/seeders/UserSeeder.php' && str_contains((string) file_get_contents($root . '/' . $seeder), 'implements Seeder'), 'Le seeder généré est incorrect.');
    $manifest = json_decode((string) file_get_contents($root . '/phpaml.json'), true, 512, JSON_THROW_ON_ERROR);
    $expect(($manifest['modules']['data']['driver'] ?? null) === 'pgsql', "Le pilote du manifeste n'a pas été actualisé.");
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
});

$test('les migrations concurrentes sont verrouillées et leurs erreurs normalisées', function () use ($expect): void {
    $directory = sys_get_temp_dir() . '/phpaml-data-lock-' . bin2hex(random_bytes(5));
    mkdir($directory, 0755, true);
    $connection = Connection::sqlite();
    $lock = new MigrationLock($connection, $directory); $lock->acquire();
    try { (new Migrator($connection, $directory))->migrate(); $expect(false, 'Le verrou concurrent a été ignoré.'); }
    catch (RuntimeException $error) { $expect(str_contains($error->getMessage(), 'déjà en cours'), 'Le message de verrou est incorrect.'); }
    finally { $lock->release(); }
    $source = "<?php return new class extends \\AML\\Data\\Migrations\\Migration { public function up(\\AML\\Data\\Connection \$c): void { throw new \\RuntimeException('boom'); } public function down(\\AML\\Data\\Connection \$c): void {} };";
    file_put_contents($directory . '/001_broken.php', $source);
    try { (new Migrator($connection, $directory))->migrate(); $expect(false, 'La migration cassée a réussi.'); }
    catch (MigrationException $error) { $expect($error->migration === '001_broken.php' && $error->phase === 'up', "L'erreur normalisée est incorrecte."); }
    unlink($directory . '/001_broken.php'); unlink($directory . '/.aml-data-migrations.lock'); rmdir($directory);
});

$test('Schema gère index et altérations SQLite', function () use ($expect): void {
    $connection = Connection::sqlite();
    $schema = new Schema($connection);
    $schema->create('search_items', function (SchemaTable $table): void {
        $table->id(); $table->string('slug'); $table->string('locale', 10);
        $table->uniqueIndex(['slug', 'locale']);
    });
    $indexes = $connection->pdo()->query("PRAGMA index_list('search_items')")->fetchAll();
    $expect(count($indexes) === 1 && $indexes[0]['unique'] === 1, "L'index composé manque.");
    $schema->table('search_items', function (AlterTable $table): void {
        $table->boolean('active')->default(true);
        $table->renameColumn('slug', 'path');
        $table->index('active');
    });
    $connection->execute("INSERT INTO search_items (path, locale) VALUES ('home', 'fr')");
    $row = $connection->pdo()->query('SELECT path, active FROM search_items')->fetch();
    $expect($row['path'] === 'home' && $row['active'] === 1, "L'altération de table est incorrecte.");
    $schema->rename('search_items', 'indexed_items');
    $expect($schema->hasTable('indexed_items'), 'Le renommage de table a échoué.');
});

$test('les diagnostics masquent automatiquement les paramètres sensibles', function () use ($expect): void {
    $logger = new MemoryLogger();
    $connection = Connection::sqlite(logger: $logger);
    $connection->pdo()->exec('CREATE TABLE secrets_test (password TEXT, public_value TEXT)');
    $connection->execute('INSERT INTO secrets_test VALUES (:password, :public_value)', [':password' => 'top-secret', ':public_value' => 'visible']);
    $parameters = $logger->queries[0]->parameters;
    $expect($parameters[':password'] === '[redacted]' && $parameters[':public_value'] === 'visible', 'Le masquage des paramètres est incorrect.');
});

$test("l'adaptateur historique réutilise la connexion PDO existante", function () use ($expect): void {
    $pdo = new PDO('sqlite::memory:');
    $legacy = new class($pdo) {
        public function __construct(private PDO $connection) {}
        public function pdo(): PDO { return $this->connection; }
    };
    $modern = LegacyConnectionAdapter::adapt($legacy);
    $modern->pdo()->exec('CREATE TABLE compatibility_test (id INTEGER)');
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE name = 'compatibility_test'")->fetchAll();
    $expect(count($tables) === 1 && $modern->pdo() === $pdo, "La connexion historique n'a pas été réutilisée.");
});

$failed = 0;
foreach ($tests as $name => $case) {
    try { $case(); echo "✓ {$name}\n"; } catch (Throwable $error) { fwrite(STDERR, "✗ {$name}: {$error->getMessage()}\n"); $failed++; }
}
exit($failed === 0 ? 0 : 1);
