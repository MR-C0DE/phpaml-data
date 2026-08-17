<?php

declare(strict_types=1);

namespace AML\Data\Diagnostics;

final readonly class QueryLog
{
    /** @param array<array-key, mixed> $parameters */
    public function __construct(
        public string $sql,
        public array $parameters,
        public float $durationMilliseconds,
    ) {
    }
}
