<?php

declare(strict_types=1);

namespace AML\Data\Migrations;

use AML\Data\Connection;
use RuntimeException;

final class MigrationLock
{
    /** @var resource|null */
    private mixed $file = null;
    private bool $databaseLock = false;

    public function __construct(private readonly Connection $connection, private readonly string $directory)
    {
    }

    public function acquire(): void
    {
        $driver = $this->connection->dialect()->name();
        if ($driver === 'sqlite') {
            if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
                throw new RuntimeException("Impossible de créer le dossier des migrations.");
            }
            $path = rtrim($this->directory, '/\\') . '/.aml-data-migrations.lock';
            $file = fopen($path, 'c+');
            if (!is_resource($file) || !flock($file, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Une autre migration phpaml/data est déjà en cours.');
            }
            $this->file = $file;
            return;
        }
        $acquired = match ($driver) {
            'mysql' => (int) $this->connection->execute("SELECT GET_LOCK('phpaml_data_migrations', 0)")->fetchColumn() === 1,
            'pgsql' => (bool) $this->connection->execute('SELECT pg_try_advisory_lock(706870616D6C)')->fetchColumn(),
            default => false,
        };
        if (!$acquired) {
            throw new RuntimeException('Une autre migration phpaml/data est déjà en cours.');
        }
        $this->databaseLock = true;
    }

    public function release(): void
    {
        if (is_resource($this->file)) {
            flock($this->file, LOCK_UN);
            fclose($this->file);
            $this->file = null;
        }
        if (!$this->databaseLock) return;
        match ($this->connection->dialect()->name()) {
            'mysql' => $this->connection->execute("SELECT RELEASE_LOCK('phpaml_data_migrations')"),
            'pgsql' => $this->connection->execute('SELECT pg_advisory_unlock(706870616D6C)'),
            default => null,
        };
        $this->databaseLock = false;
    }
}
