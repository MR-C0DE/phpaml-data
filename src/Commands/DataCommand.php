<?php

declare(strict_types=1);

namespace AML\Data\Commands;

use AML\Data\Connection;
use AML\Data\DbContext;
use AML\Data\Migrations\Migrator;
use AML\Data\Seeding\Seeder;
use AML\Data\Seeding\SeederRunner;
use RuntimeException;
use AML\Data\Diagnostics\ConnectionDoctor;
use AML\Data\Connections\ConnectionManager;
use AML\Data\Integration\ProjectScaffolder;
use AML\Data\Connections\InspectableConnection;

final class DataCommand
{
    private ConnectionManager $manager;
    private ?string $connectionName = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly string $root,
        private readonly array $config,
    ) {
        $this->manager = new ConnectionManager($root, $config);
    }

    /**
     * @param list<string> $arguments
     */
    public function run(string $command, array $arguments = []): int
    {
        $arguments = $this->selectConnection($arguments);
        return match ($command) {
            'data:install' => $this->install($arguments),
            'data:make-model' => $this->generate('model', $this->optionalString($arguments[0] ?? null)),
            'data:make-migration' => $this->generate('migration', $this->optionalString($arguments[0] ?? null)),
            'data:make-seeder' => $this->generate('seeder', $this->optionalString($arguments[0] ?? null)),
            'data:migrate' => $this->migrate(),
            'data:rollback' => $this->rollback($this->steps($arguments)),
            'data:status' => $this->status(in_array('--json', $arguments, true)),
            'data:seed' => $this->seed($this->optionalString($arguments[0] ?? null)),
            'data:doctor' => $this->doctor(in_array('--json', $arguments, true)),
            default => throw new RuntimeException("Commande de données inconnue : {$command}"),
        };
    }

    /** @param list<string> $arguments */
    private function install(array $arguments): int
    {
        $driver = 'sqlite';
        $index = array_search('--driver', $arguments, true);
        if ($index !== false) $driver = $arguments[$index + 1] ?? throw new RuntimeException("L'option --driver exige une valeur.");
        $changes = (new ProjectScaffolder($this->root))->install($driver);
        foreach ($changes as $path) $this->line("Préparé : {$path}");
        $this->line("phpaml/data configuré avec le pilote {$driver}.");
        return 0;
    }

    /** @param 'model'|'migration'|'seeder' $type */
    private function generate(string $type, ?string $name): int
    {
        if ($name === null || $name === '') throw new RuntimeException('Indiquez un nom à générer.');
        $scaffolder = new ProjectScaffolder($this->root);
        $path = match ($type) {
            'model' => $scaffolder->model($name),
            'migration' => $scaffolder->migration($name),
            'seeder' => $scaffolder->seeder($name),
        };
        $this->line("Créé : {$path}");
        return 0;
    }

    private function migrate(): int
    {
        $completed = $this->migrator()->migrate();
        if ($completed === []) {
            $this->line('Aucune migration en attente.');
            return 0;
        }
        foreach ($completed as $name) {
            $this->line("Migrée : {$name}");
        }
        return 0;
    }

    private function rollback(int $steps): int
    {
        $completed = $this->migrator()->rollback($steps);
        if ($completed === []) {
            $this->line('Aucune migration à annuler.');
            return 0;
        }
        foreach ($completed as $name) {
            $this->line("Annulée : {$name}");
        }
        return 0;
    }

    private function status(bool $json): int
    {
        $statuses = $this->migrator()->status();
        $data = array_map(static fn ($status): array => [
            'migration' => $status->name,
            'executed' => $status->executed,
            'batch' => $status->batch,
        ], $statuses);
        if ($json) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 0;
        }
        foreach ($data as $row) {
            $this->line(($row['executed'] ? '[x] ' : '[ ] ') . $row['migration'] . ($row['batch'] === null ? '' : " (lot {$row['batch']})"));
        }
        return 0;
    }

    private function seed(?string $requested): int
    {
        $connectionConfig = $this->manager->configuration($this->connectionName);
        $contextClass = $connectionConfig['context'] ?? $this->manager->rootConfiguration()['context'] ?? null;
        if (!is_string($contextClass) || !is_subclass_of($contextClass, DbContext::class)) {
            throw new RuntimeException("La configuration 'context' doit désigner un DbContext.");
        }
        $context = new $contextClass($this->connection());
        $configured = $connectionConfig['seeders'] ?? $this->manager->rootConfiguration()['seeders'] ?? [];
        if (!is_array($configured)) {
            throw new RuntimeException("La configuration 'seeders' doit être une liste de classes.");
        }
        $candidates = $requested === null ? $configured : array_values(array_filter($configured, static fn (mixed $class): bool => is_string($class) && ($class === $requested || str_ends_with($class, '\\' . $requested))));
        /** @var list<class-string<Seeder>> $classes */
        $classes = [];
        foreach ($candidates as $class) {
            if (!is_string($class) || !is_subclass_of($class, Seeder::class)) {
                throw new RuntimeException('Chaque seeder doit implémenter ' . Seeder::class . '.');
            }
            $classes[] = $class;
        }
        if ($classes === []) {
            throw new RuntimeException($requested === null ? 'Aucun seeder configuré.' : "Seeder introuvable : {$requested}");
        }
        $seeders = array_map(static fn (string $class): Seeder => new $class(), $classes);
        (new SeederRunner($context))->run($seeders);
        foreach ($classes as $class) {
            $this->line("Seeder exécuté : {$class}");
        }
        return 0;
    }

    private function doctor(bool $json): int
    {
        $selected = $this->manager->connection($this->connectionName);
        if ($selected instanceof InspectableConnection) return $this->adapterDoctor($selected, $json);
        if (!$selected instanceof Connection) throw new RuntimeException('Cette connexion ne fournit aucun diagnostic.');
        $report = (new ConnectionDoctor())->inspect($selected);
        $checks = [
            'connection_name' => ['ok' => true, 'message' => $this->connectionName ?? $this->manager->defaultName()],
            'php' => ['ok' => true, 'message' => PHP_VERSION],
            'pdo' => ['ok' => extension_loaded('pdo'), 'message' => extension_loaded('pdo') ? 'chargée' : 'absente'],
            'driver' => ['ok' => $report->extensionAvailable, 'message' => $report->driver],
            'connection' => ['ok' => $report->connected, 'message' => $report->connected ? 'réussie' : ($report->error ?? 'échouée')],
            'server_version' => ['ok' => $report->connected, 'message' => $report->serverVersion ?? 'inconnue'],
            'capabilities' => ['ok' => $report->connected, 'message' => $report->capabilities],
            'migrations' => ['ok' => is_dir($this->migrationsPath()), 'message' => $this->migrationsPath()],
        ];
        if ($json) {
            $this->line(json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $name => $check) {
                $message = is_array($check['message']) ? json_encode($check['message'], JSON_UNESCAPED_SLASHES) : $check['message'];
                $this->line(($check['ok'] ? '✓' : '✗') . " {$name} : {$message}");
            }
        }
        return count(array_filter($checks, static fn (array $check): bool => !$check['ok'])) === 0 ? 0 : 1;
    }

    private function adapterDoctor(InspectableConnection $connection, bool $json): int
    {
        $report = $connection->diagnostics();
        $connected = ($report['connected'] ?? false) === true;
        $checks = [
            'connection_name' => ['ok' => true, 'message' => $this->connectionName ?? $this->manager->defaultName()],
            'driver' => ['ok' => true, 'message' => is_string($report['driver'] ?? null) ? $report['driver'] : 'adapter'],
            'connection' => ['ok' => $connected, 'message' => $connected ? 'réussie' : (is_string($report['error'] ?? null) ? $report['error'] : 'échouée')],
            'capabilities' => ['ok' => $connected, 'message' => $report],
        ];
        if ($json) $this->line(json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        else foreach ($checks as $name => $check) { $message = is_array($check['message']) ? json_encode($check['message'], JSON_UNESCAPED_SLASHES) : $check['message']; $this->line(($check['ok'] ? '✓' : '✗') . " {$name} : {$message}"); }
        return $connected ? 0 : 1;
    }

    private function connection(): Connection
    {
        $connection = $this->manager->sql($this->connectionName);
        if ($connection->dialect()->name() === 'sqlite') {
            $config = $this->manager->configuration($this->connectionName);
            $database = $config['database'] ?? null;
            if (is_string($database) && $database !== ':memory:') {
                $path = str_starts_with($database, '/') ? $database : $this->root . '/' . ltrim($database, '/');
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }
            }
        }
        return $connection;
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->connection(), $this->migrationsPath());
    }

    private function migrationsPath(): string
    {
        $connection = $this->manager->configuration($this->connectionName);
        $configured = $connection['migrations_path'] ?? $this->manager->rootConfiguration()['migrations_path'] ?? 'runtime/database/migrations';
        if (!is_string($configured)) throw new RuntimeException('migrations_path doit être une chaîne.');
        $path = $configured;
        return str_starts_with($path, '/') ? $path : $this->root . '/' . ltrim($path, '/');
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function selectConnection(array $arguments): array
    {
        $index = array_search('--connection', $arguments, true);
        if ($index === false) {
            $this->connectionName = $this->manager->defaultName();
            return $arguments;
        }
        $name = $arguments[$index + 1] ?? null;
        if (!is_string($name) || $name === '') {
            throw new RuntimeException("L'option --connection exige un nom.");
        }
        $this->manager->configuration($name);
        $this->connectionName = $name;
        array_splice($arguments, $index, 2);
        return $arguments;
    }

    /** @param list<string> $arguments */
    private function steps(array $arguments): int
    {
        $index = array_search('--steps', $arguments, true);
        $value = $index === false ? 1 : filter_var($arguments[$index + 1] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException("L'option --steps exige un entier positif.");
        }
        return $value;
    }

    private function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null || is_string($value)) return $value;
        throw new RuntimeException('Un argument texte était attendu.');
    }
}
