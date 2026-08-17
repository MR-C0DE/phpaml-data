<?php

declare(strict_types=1);

namespace AML\Data\Migrations;

use RuntimeException;
use Throwable;

final class MigrationException extends RuntimeException
{
    public function __construct(public readonly string $migration, public readonly string $phase, Throwable $previous)
    {
        parent::__construct("La migration {$migration} a échoué pendant {$phase} : {$previous->getMessage()}", 0, $previous);
    }
}
