<?php

declare(strict_types=1);

namespace AML\Data\Schema;

use AML\Data\Connection;

final class AlterTable
{
    /** @var list<callable():void> */
    private array $operations = [];

    public function __construct(private readonly Connection $connection, private readonly string $table)
    {
        Column::identifier($table);
    }

    public function string(string $name, int $length = 255): Column { return $this->add(new Column($name, 'string', $length)); }
    public function text(string $name): Column { return $this->add(new Column($name, 'text')); }
    public function integer(string $name): Column { return $this->add(new Column($name, 'integer')); }
    public function boolean(string $name): Column { return $this->add(new Column($name, 'boolean')); }
    public function dateTime(string $name): Column { return $this->add(new Column($name, 'datetime')); }
    public function foreignId(string $name): Column { return $this->integer($name); }

    public function dropColumn(string $name): void
    {
        Column::identifier($name);
        $this->operations[] = function () use ($name): void { $this->connection->pdo()->exec('ALTER TABLE ' . $this->q($this->table) . ' DROP COLUMN ' . $this->q($name)); };
    }

    public function renameColumn(string $from, string $to): void
    {
        Column::identifier($from); Column::identifier($to);
        $this->operations[] = function () use ($from, $to): void { $this->connection->pdo()->exec('ALTER TABLE ' . $this->q($this->table) . ' RENAME COLUMN ' . $this->q($from) . ' TO ' . $this->q($to)); };
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null, bool $unique = false): void
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $index = new Index($name ?? $this->table . '_' . implode('_', $columns) . ($unique ? '_unique' : '_index'), $columns, $unique);
        $this->operations[] = function () use ($index): void { $this->connection->pdo()->exec($index->toSql($this->connection->dialect(), $this->table)); };
    }

    public function dropIndex(string $name): void
    {
        Column::identifier($name);
        $sql = $this->connection->dialect()->name() === 'mysql'
            ? 'DROP INDEX ' . $this->q($name) . ' ON ' . $this->q($this->table)
            : 'DROP INDEX ' . $this->q($name);
        $this->operations[] = function () use ($sql): void { $this->connection->pdo()->exec($sql); };
    }

    public function dropIndexIfExists(string $name): void
    {
        Column::identifier($name);
        $sql = $this->connection->dialect()->name() === 'mysql'
            ? 'DROP INDEX ' . $this->q($name) . ' ON ' . $this->q($this->table)
            : 'DROP INDEX IF EXISTS ' . $this->q($name);
        $this->operations[] = function () use ($sql): void {
            try { $this->connection->pdo()->exec($sql); } catch (\PDOException $exception) {
                if ($this->connection->dialect()->name() !== 'mysql' || !in_array((string) $exception->getCode(), ['42000', '1091'], true)) { throw $exception; }
            }
        };
    }

    public function apply(): void
    {
        foreach ($this->operations as $operation) $operation();
    }

    private function add(Column $column): Column
    {
        $this->operations[] = function () use ($column): void { $this->connection->pdo()->exec('ALTER TABLE ' . $this->q($this->table) . ' ADD COLUMN ' . $column->toSql($this->connection->dialect())); };
        return $column;
    }

    private function q(string $identifier): string { return $this->connection->dialect()->quote($identifier); }
}
