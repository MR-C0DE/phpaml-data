<?php

declare(strict_types=1);

namespace AML\Data\SQL;

use InvalidArgumentException;

final class PostgresDialect extends AbstractDialect
{
    public function name(): string { return 'pgsql'; }
    public function quote(string $identifier): string { return '"' . $this->validate($identifier) . '"'; }
    public function primaryKey(string $name): string { return $this->quote($name) . ' BIGSERIAL PRIMARY KEY'; }
    public function literal(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : parent::literal($value);
    }
    public function insertReturning(string $key): string { return ' RETURNING ' . $this->quote($key); }
    public function columnType(string $type, ?int $length = null): string
    {
        return match ($type) {
            'string' => 'VARCHAR(' . $this->length($length) . ')',
            'text' => 'TEXT', 'integer' => 'BIGINT', 'boolean' => 'BOOLEAN', 'datetime' => 'TIMESTAMP',
            default => throw new InvalidArgumentException("Type inconnu : {$type}"),
        };
    }
}
