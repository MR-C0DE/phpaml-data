<?php

declare(strict_types=1);

namespace AML\Data\Schema;

use InvalidArgumentException;
use AML\Data\SQL\Dialect;

final class Table
{
    /** @var list<Column> */
    private array $columns = [];
    /** @var list<Index> */
    private array $indexes = [];

    public function __construct(private readonly string $name, private readonly Dialect $dialect)
    {
        Column::identifier($name);
    }

    public function id(string $name = 'id'): Column
    {
        return $this->add(new Column($name, 'integer'))->primary()->autoIncrement();
    }

    public function string(string $name, int $length = 255): Column
    {
        if ($length < 1 || $length > 65535) throw new InvalidArgumentException('Longueur VARCHAR invalide.');
        return $this->add(new Column($name, 'string', $length));
    }

    public function text(string $name): Column { return $this->add(new Column($name, 'text')); }
    public function integer(string $name): Column { return $this->add(new Column($name, 'integer')); }
    public function boolean(string $name): Column { return $this->add(new Column($name, 'boolean')); }
    public function decimal(string $name): Column { return $this->add(new Column($name, 'decimal')); }
    public function dateTime(string $name): Column { return $this->add(new Column($name, 'datetime')); }

    public function foreignId(string $name): Column
    {
        return $this->integer($name);
    }

    public function timestamps(): void
    {
        $this->dateTime('created_at')->defaultExpression('CURRENT_TIMESTAMP');
        $this->dateTime('updated_at')->defaultExpression('CURRENT_TIMESTAMP');
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): void
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->indexes[] = new Index($name ?? $this->indexName($columns), $columns);
    }

    /** @param string|list<string> $columns */
    public function uniqueIndex(string|array $columns, ?string $name = null): void
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->indexes[] = new Index($name ?? $this->indexName($columns, 'unique'), $columns, true);
    }

    public function toSql(): string
    {
        if ($this->columns === []) throw new InvalidArgumentException("La table {$this->name} doit contenir au moins une colonne.");
        return 'CREATE TABLE ' . $this->dialect->quote($this->name) . ' (' . implode(', ', array_map(fn (Column $column): string => $column->toSql($this->dialect), $this->columns)) . ')';
    }

    /** @return list<string> */
    public function statements(): array
    {
        return array_merge([$this->toSql()], array_map(fn (Index $index): string => $index->toSql($this->dialect, $this->name), $this->indexes));
    }

    private function add(Column $column): Column
    {
        $this->columns[] = $column;
        return $column;
    }

    /** @param list<string> $columns */
    private function indexName(array $columns, string $suffix = 'index'): string
    {
        return $this->name . '_' . implode('_', $columns) . '_' . $suffix;
    }
}
