<?php

declare(strict_types=1);

namespace AML\Data\Seeding;

use AML\Data\DbContext;

interface Seeder
{
    public function seed(DbContext $context): void;
}
