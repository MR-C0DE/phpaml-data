<?php

declare(strict_types=1);

namespace AML\Data\Connections;

interface InspectableConnection
{
    /** @return array<string, bool|string|null> */
    public function diagnostics(): array;
}
