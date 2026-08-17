<?php

declare(strict_types=1);

namespace AML\Data\Diagnostics;

interface QueryLogger
{
    public function log(QueryLog $query): void;
}
