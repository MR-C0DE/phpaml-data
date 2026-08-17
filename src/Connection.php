<?php

declare(strict_types=1);

namespace AML\Data;

use AML\Data\Diagnostics\QueryLog;
use AML\Data\Diagnostics\QueryLogger;
use PDO;
use PDOStatement;
use Throwable;
use AML\Data\SQL\Dialect;
use AML\Data\SQL\MySqlDialect;
use AML\Data\SQL\PostgresDialect;
use AML\Data\SQL\SqliteDialect;
use InvalidArgumentException;
use AML\Data\Diagnostics\SensitiveDataRedactor;

final class Connection
{
    private ?PDO $pdo = null;
    private int $transactionDepth = 0;

    public function __construct(
        private readonly string $dsn,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?QueryLogger $logger = null,
        private readonly ?Dialect $configuredDialect = null,
        private readonly ?SensitiveDataRedactor $redactor = null,
    ) {
    }

    public function dialect(): Dialect
    {
        if ($this->configuredDialect !== null) {
            return $this->configuredDialect;
        }
        $driver = strtolower((string) strstr($this->dsn, ':', true));
        return match ($driver) {
            'sqlite' => new SqliteDialect(),
            'mysql' => new MySqlDialect(),
            'pgsql', 'postgres', 'postgresql' => new PostgresDialect(),
            default => throw new InvalidArgumentException("Pilote de données non pris en charge : {$driver}"),
        };
    }

    public static function sqlite(string $path = ':memory:', ?QueryLogger $logger = null): self
    {
        return new self('sqlite:' . $path, logger: $logger);
    }

    public static function fromPdo(PDO $pdo, ?QueryLogger $logger = null): self
    {
        $value = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($value) || $value === '') throw new InvalidArgumentException('Le pilote de la connexion PDO est invalide.');
        $driver = $value;
        $connection = new self($driver . ':', logger: $logger);
        $connection->pdo = $pdo;
        return $connection;
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (str_starts_with($this->dsn, 'sqlite:')) {
            $path = substr($this->dsn, 7);
            if ($path !== ':memory:' && !is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
        }
        $this->pdo = new PDO($this->dsn, $this->username, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($this->dialect()->name() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
        return $this->pdo;
    }

    /** @param array<string, mixed> $parameters */
    public function execute(string $sql, array $parameters = []): PDOStatement
    {
        $started = hrtime(true);
        try {
            $statement = $this->pdo()->prepare($sql);
            $statement->execute($parameters);
            return $statement;
        } finally {
            $safeParameters = ($this->redactor ?? new SensitiveDataRedactor())->redact($parameters);
            $this->logger?->log(new QueryLog($sql, $safeParameters, (hrtime(true) - $started) / 1_000_000));
        }
    }

    public function transaction(callable $operation): mixed
    {
        $pdo = $this->pdo();
        $nested = $pdo->inTransaction();
        $savepoint = 'aml_data_' . $this->transactionDepth;
        if ($nested) {
            $pdo->exec("SAVEPOINT {$savepoint}");
        } else {
            $pdo->beginTransaction();
        }
        $this->transactionDepth++;
        try {
            $result = $operation($this);
            $this->transactionDepth--;
            if ($nested) {
                $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            $this->transactionDepth--;
            if ($nested && $pdo->inTransaction()) {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
