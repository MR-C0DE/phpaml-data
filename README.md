# PHPAML Data

Typed data access for PHPAML, AML View, and standalone PHP 8.2+ applications.

> Status: `0.2.0-alpha.1`. SQLite, MySQL, MariaDB, and PostgreSQL are tested
> against real database servers. The package also passes PHPStan at its highest level.

[Documentation française](README.fr.md) · [Architecture](docs/ARCHITECTURE.md) ·
[Migration guide](docs/MIGRATION.md) · [Roadmap](docs/ROADMAP.md) · [Changelog](CHANGELOG.md)

## Why PHPAML Data?

PHPAML Data gives PHP applications a small, typed persistence layer without
hiding SQL behind a large framework. Define entities with PHP attributes, query
them through reusable sets, and keep migrations, validation, relationships, and
transactions in one consistent API.

- typed entities, contexts, and query sets;
- SQLite, MySQL, MariaDB, and PostgreSQL support;
- CRUD, pagination, validation, and transactions;
- eager-loaded relationships without N+1 queries;
- automatic change tracking and atomic `saveChanges()`;
- migrations, seeders, schema building, diagnostics, and named connections;
- optional MongoDB support through `phpaml/data-mongodb`;
- usable with PHPAML CLI or as an independent Composer package.

## Install

In a PHPAML project:

```bash
aml install data --driver sqlite
```

In any Composer project:

```bash
composer require phpaml/data:^0.2@alpha
```

## Five-minute example

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

$page = $db->users()
    ->where('email', 'LIKE', '%@example.com')
    ->orderBy('name')
    ->paginate(page: 1, perPage: 20);
```

## PHPAML workflow

```bash
aml install data --driver sqlite
aml make:model User
aml make:migration create_users_table
aml make:seeder UserSeeder
aml data:migrate
aml data:doctor
```

Modern PHPAML projects store the normalized configuration in the `data`
section of `phpaml.json`. Existing projects using `configs/data.php` remain
supported.

## Platform relationship

```text
PHPAML CLI
  └─ installs and configures PHPAML Data
       ├─ SQL drivers: SQLite, MySQL, MariaDB, PostgreSQL
       └─ optional adapter: PHPAML Data MongoDB

PHPAML Framework / AML View / standalone PHP
  └─ use the same entities, contexts, migrations, and diagnostics
```

PHPAML Data is independent of the presentation layer. A project can use it
with classic MVC views, AML View, an API-only application, or plain PHP.

## Quality

The core suite covers typed CRUD, identity handling, validation, migrations,
relations, change tracking, transactions, schema operations, diagnostics, and
compatibility adapters. The integration matrix validates PHP 8.2–8.4 and real
database servers.

```bash
composer test
composer test:databases
```

See [qa/README.md](qa/README.md) for the multi-database procedure.

## Stability

PHPAML Data is alpha software. Its current features are tested, but public APIs
may still change before `1.0.0`. Pin an alpha-compatible constraint and review
the [changelog](CHANGELOG.md) when upgrading.

## License

PHPAML Data is open-source software licensed under the [MIT License](LICENSE).
