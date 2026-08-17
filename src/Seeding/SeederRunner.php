<?php

declare(strict_types=1);

namespace AML\Data\Seeding;

use AML\Data\DbContext;

final readonly class SeederRunner
{
    public function __construct(private DbContext $context)
    {
    }

    /** @param iterable<Seeder> $seeders */
    public function run(iterable $seeders): void
    {
        $this->context->transaction(function () use ($seeders): void {
            foreach ($seeders as $seeder) {
                $seeder->seed($this->context);
            }
        });
    }
}
