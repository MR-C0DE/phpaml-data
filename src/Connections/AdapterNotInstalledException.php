<?php

declare(strict_types=1);

namespace AML\Data\Connections;

use RuntimeException;

final class AdapterNotInstalledException extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        $package = $driver === 'mongodb' ? 'phpaml/data-mongodb' : "un adaptateur {$driver}";
        return new self("Le pilote '{$driver}' nécessite {$package}. Installez son adaptateur puis relancez la commande.");
    }
}
