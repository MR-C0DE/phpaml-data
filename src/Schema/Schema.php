<?php

declare(strict_types=1);

namespace AML\Data\Schema;

use AML\Data\Connection;

final readonly class Schema
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(string $name, callable $definition): void
    {
        $table = new Table($name, $this->connection->dialect());
        $definition($table);
        foreach ($table->statements() as $statement) $this->connection->pdo()->exec($statement);
    }

    public function table(string $name, callable $definition): void
    {
        $table = new AlterTable($this->connection, $name);
        $definition($table);
        $table->apply();
    }

    public function rename(string $from, string $to): void
    {
        Column::identifier($from); Column::identifier($to);
        $this->connection->pdo()->exec('ALTER TABLE ' . $this->connection->dialect()->quote($from) . ' RENAME TO ' . $this->connection->dialect()->quote($to));
    }

    public function drop(string $name): void
    {
        Column::identifier($name);
        $this->connection->pdo()->exec('DROP TABLE ' . $this->connection->dialect()->quote($name));
    }

    public function dropIfExists(string $name): void
    {
        Column::identifier($name);
        $this->connection->pdo()->exec('DROP TABLE IF EXISTS ' . $this->connection->dialect()->quote($name));
    }

    public function hasTable(string $name): bool
    {
        Column::identifier($name);
        if ($this->connection->dialect()->name() !== 'sqlite') {
            throw new \LogicException('hasTable() sera généralisé avec le pilote de schéma de chaque SGBD.');
        }
        $statement = $this->connection->execute("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name", [':name' => $name]);
        return $statement->fetchColumn() !== false;
    }
}
