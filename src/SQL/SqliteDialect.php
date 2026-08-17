<?php

declare(strict_types=1);

namespace AML\Data\SQL;

use InvalidArgumentException;

final class SqliteDialect extends AbstractDialect
{
    public function name(): string { return 'sqlite'; }
    public function quote(string $identifier): string { return '"' . $this->validate($identifier) . '"'; }
    public function primaryKey(string $name): string { return $this->quote($name) . ' INTEGER PRIMARY KEY AUTOINCREMENT'; }
    public function columnType(string $type, ?int $length = null): string
    {
        return match ($type) {
            'string' => 'VARCHAR(' . $this->length($length) . ')',
            'text' => 'TEXT', 'integer', 'boolean' => 'INTEGER', 'datetime' => 'DATETIME',
            default => throw new InvalidArgumentException("Type inconnu : {$type}"),
        };
    }
}
