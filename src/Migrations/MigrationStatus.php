<?php

declare(strict_types=1);

namespace AML\Data\Migrations;

final readonly class MigrationStatus
{
    public function __construct(
        public string $name,
        public bool $executed,
        public ?int $batch = null,
    ) {
    }
}
