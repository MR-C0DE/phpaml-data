<?php

declare(strict_types=1);

namespace AML\Data\Compatibility;

use AML\Data\Connection;
use InvalidArgumentException;
use PDO;

final class LegacyConnectionAdapter
{
    public static function adapt(object $legacy): Connection
    {
        if (!method_exists($legacy, 'pdo')) {
            throw new InvalidArgumentException("La connexion historique doit exposer une méthode pdo().");
        }
        $pdo = $legacy->pdo();
        if (!$pdo instanceof PDO) {
            throw new InvalidArgumentException("La méthode pdo() de la connexion historique doit retourner PDO.");
        }
        return Connection::fromPdo($pdo);
    }
}
