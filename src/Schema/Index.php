<?php

declare(strict_types=1);

namespace AML\Data\Schema;

use AML\Data\SQL\Dialect;

final readonly class Index
{
    /** @param list<string> $columns */
    public function __construct(public string $name, public array $columns, public bool $unique = false)
    {
        Column::identifier($name);
        foreach ($columns as $column) Column::identifier($column);
        if ($columns === []) throw new \InvalidArgumentException('Un index doit contenir au moins une colonne.');
    }

    public function toSql(Dialect $dialect, string $table): string
    {
        return 'CREATE ' . ($this->unique ? 'UNIQUE ' : '') . 'INDEX ' . $dialect->quote($this->name)
            . ' ON ' . $dialect->quote($table) . ' (' . implode(', ', array_map($dialect->quote(...), $this->columns)) . ')';
    }
}
