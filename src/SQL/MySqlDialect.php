<?php

declare(strict_types=1);

namespace AML\Data\SQL;

use InvalidArgumentException;

final class MySqlDialect extends AbstractDialect
{
    public function transactionalDdl(): bool { return false; }
    public function name(): string { return 'mysql'; }
    public function quote(string $identifier): string { return '`' . $this->validate($identifier) . '`'; }
    public function primaryKey(string $name): string { return $this->quote($name) . ' BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY'; }
    public function columnType(string $type, ?int $length = null): string
    {
        return match ($type) {
            'string' => 'VARCHAR(' . $this->length($length) . ')',
            'text' => 'TEXT', 'integer' => 'BIGINT', 'boolean' => 'TINYINT(1)', 'decimal' => 'DECIMAL(10,2)', 'datetime' => 'DATETIME',
            default => throw new InvalidArgumentException("Type inconnu : {$type}"),
        };
    }
}
