<?php

declare(strict_types=1);

namespace AML\Data\Connections;

interface DriverAdapter
{
    /** @param array<string, mixed> $config */
    public function connect(array $config, string $projectRoot): mixed;
}
