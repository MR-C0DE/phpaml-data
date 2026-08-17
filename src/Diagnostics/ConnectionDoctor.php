<?php

declare(strict_types=1);

namespace AML\Data\Diagnostics;

use AML\Data\Connection;
use PDO;
use Throwable;

final readonly class ConnectionDoctor
{
    public function inspect(Connection $connection): ConnectionReport
    {
        $driver = $connection->dialect()->name();
        $extensionAvailable = in_array($driver, PDO::getAvailableDrivers(), true);
        if (!$extensionAvailable) {
            return new ConnectionReport($driver, false, false, null, [], "Le pilote PDO {$driver} est absent.");
        }
        try {
            $pdo = $connection->pdo();
            $version = match ($driver) {
                'sqlite' => $this->queryString($pdo, 'SELECT sqlite_version()'),
                'mysql' => $this->queryString($pdo, 'SELECT VERSION()'),
                'pgsql' => $this->queryString($pdo, 'SHOW server_version'),
                default => null,
            };
            $capabilities = [
                'transactions' => true,
                'savepoints' => true,
                'returning' => $driver === 'pgsql' || ($driver === 'sqlite' && version_compare($version ?? '0', '3.35.0', '>=')),
                'foreign_keys' => $driver !== 'sqlite' || $this->queryString($pdo, 'PRAGMA foreign_keys') === '1',
            ];
            return new ConnectionReport($driver, true, true, $version, $capabilities);
        } catch (Throwable $error) {
            return new ConnectionReport($driver, true, false, null, [], $this->sanitize($error->getMessage()));
        }
    }

    private function sanitize(string $message): string
    {
        return (string) preg_replace('/password\s*=\s*[^\s;]+/i', 'password=[masked]', $message);
    }

    private function queryString(PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        if ($statement === false) return '';
        $value = $statement->fetchColumn();
        return is_scalar($value) ? (string) $value : '';
    }
}
