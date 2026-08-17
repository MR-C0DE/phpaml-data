<?php

declare(strict_types=1);

namespace AML\Data\Migrations;

use AML\Data\Connection;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $lock = new MigrationLock($this->connection, $this->directory);
        $lock->acquire();
        try {
            return $this->migrateUnlocked();
        } finally {
            $lock->release();
        }
    }

    /** @return list<string> */
    private function migrateUnlocked(): array
    {
        $this->prepareHistory();
        $executed = $this->executed();
        $files = $this->files();
        $pending = array_values(array_diff($files, array_keys($executed)));
        if ($pending === []) {
            return [];
        }
        $batch = $executed === [] ? 1 : max($executed) + 1;
        $completed = [];
        foreach ($pending as $name) {
            $migration = $this->load($name);
            try {
                $this->ddl(function () use ($migration, $name, $batch): void {
                    $migration->up($this->connection);
                    $this->connection->execute(
                        'INSERT INTO aml_data_migrations (migration, batch, executed_at) VALUES (:migration, :batch, :executed_at)',
                        [':migration' => $name, ':batch' => $batch, ':executed_at' => date(DATE_ATOM)],
                    );
                });
            } catch (\Throwable $error) {
                throw new MigrationException($name, 'up', $error);
            }
            $completed[] = $name;
        }
        return $completed;
    }

    /** @return list<string> */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new RuntimeException('Le nombre de lots doit être positif.');
        }
        $lock = new MigrationLock($this->connection, $this->directory);
        $lock->acquire();
        try {
            return $this->rollbackUnlocked($steps);
        } finally {
            $lock->release();
        }
    }

    /** @return list<string> */
    private function rollbackUnlocked(int $steps): array
    {
        $this->prepareHistory();
        $rawBatches = $this->connection->execute('SELECT DISTINCT batch FROM aml_data_migrations ORDER BY batch DESC LIMIT ' . $steps)->fetchAll(\PDO::FETCH_COLUMN);
        $batches = [];
        foreach ($rawBatches as $batch) {
            if (is_int($batch) || (is_string($batch) && ctype_digit($batch))) $batches[] = (int) $batch;
        }
        if ($batches === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($batches), '?'));
        $statement = $this->connection->pdo()->prepare("SELECT migration FROM aml_data_migrations WHERE batch IN ({$placeholders}) ORDER BY batch DESC, migration DESC");
        $statement->execute($batches);
        $rolledBack = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $rawName) {
            if (!is_string($rawName)) throw new RuntimeException('Nom de migration invalide dans l’historique.');
            $name = $rawName;
            $migration = $this->load($name);
            try {
                $this->ddl(function () use ($migration, $name): void {
                    $migration->down($this->connection);
                    $this->connection->execute('DELETE FROM aml_data_migrations WHERE migration = :migration', [':migration' => $name]);
                });
            } catch (\Throwable $error) {
                throw new MigrationException($name, 'down', $error);
            }
            $rolledBack[] = $name;
        }
        return $rolledBack;
    }

    /** @return list<MigrationStatus> */
    public function status(): array
    {
        $this->prepareHistory();
        $executed = $this->executed();
        return array_map(
            static fn (string $name): MigrationStatus => new MigrationStatus($name, isset($executed[$name]), $executed[$name] ?? null),
            $this->files(),
        );
    }

    private function prepareHistory(): void
    {
        $this->connection->pdo()->exec('CREATE TABLE IF NOT EXISTS aml_data_migrations (migration VARCHAR(255) PRIMARY KEY, batch INTEGER NOT NULL, executed_at VARCHAR(35) NOT NULL)');
    }

    /** @return array<string, int> */
    private function executed(): array
    {
        /** @var list<array{migration: mixed, batch: mixed}> $rows */
        $rows = $this->connection->execute('SELECT migration, batch FROM aml_data_migrations')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            if (!is_string($row['migration']) || (!is_int($row['batch']) && !(is_string($row['batch']) && ctype_digit($row['batch'])))) {
                throw new RuntimeException('Historique de migrations invalide.');
            }
            $result[$row['migration']] = (int) $row['batch'];
        }
        return $result;
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_map('basename', glob(rtrim($this->directory, '/\\') . '/*.php') ?: []);
        sort($files, SORT_STRING);
        return $files;
    }

    private function load(string $name): Migration
    {
        $path = rtrim($this->directory, '/\\') . '/' . basename($name);
        if (!is_file($path)) {
            throw new RuntimeException("La migration {$name} est introuvable; rollback impossible.");
        }
        $migration = require $path;
        if (!$migration instanceof Migration) {
            throw new RuntimeException("La migration {$name} doit retourner une instance de " . Migration::class . '.');
        }
        return $migration;
    }

    private function ddl(callable $operation): void
    {
        if ($this->connection->dialect()->transactionalDdl()) {
            $this->connection->transaction($operation);
            return;
        }
        $operation();
    }
}
