<?php

declare(strict_types=1);

namespace AML\Data\SQL;

interface Dialect
{
    public function name(): string;
    public function quote(string $identifier): string;
    public function columnType(string $type, ?int $length = null): string;
    public function primaryKey(string $name): string;
    public function literal(mixed $value): string;
    public function insertReturning(string $key): string;
    public function transactionalDdl(): bool;
}
