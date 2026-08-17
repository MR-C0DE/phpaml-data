<?php

declare(strict_types=1);

namespace AML\Data\SQL;

use InvalidArgumentException;

abstract class AbstractDialect implements Dialect
{
    public function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            default => throw new InvalidArgumentException('Valeur SQL littérale non prise en charge.'),
        };
    }

    public function insertReturning(string $key): string
    {
        return '';
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    final protected function validate(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Identifiant SQL invalide : {$identifier}");
        }
        return $identifier;
    }

    final protected function length(?int $length): int
    {
        if ($length === null || $length < 1 || $length > 65535) {
            throw new InvalidArgumentException('Longueur VARCHAR invalide.');
        }
        return $length;
    }
}
