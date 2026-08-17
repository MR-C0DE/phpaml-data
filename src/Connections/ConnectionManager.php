<?php

declare(strict_types=1);

namespace AML\Data\Connections;

use AML\Data\Connection;
use AML\Data\DbContext;
use InvalidArgumentException;

final class ConnectionManager
{
    /** @var array<string, DriverAdapter> */
    private array $adapters = [];
    /** @var array<string, mixed> */
    private array $resolved = [];
    /** @var array<string, mixed> */
    private array $config;
    /** @var array<string, array<string, mixed>> */
    private array $connections;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly string $projectRoot, array $config)
    {
        $this->config = isset($config['connections']) ? $config : [
            'default' => 'main',
            'connections' => ['main' => $config],
        ];
        $connections = $this->config['connections'] ?? null;
        if (!is_array($connections) || $connections === []) {
            throw new InvalidArgumentException("La configuration doit contenir au moins une connexion.");
        }
        $this->connections = [];
        foreach ($connections as $name => $connection) {
            if (!is_string($name) || !is_array($connection)) {
                throw new InvalidArgumentException('Chaque connexion doit être un tableau associé à un nom.');
            }
            $normalized = [];
            foreach ($connection as $key => $value) {
                if (!is_string($key)) throw new InvalidArgumentException('Les clés de configuration doivent être des chaînes.');
                $normalized[$key] = $value;
            }
            $this->connections[$name] = $normalized;
        }
        $mongoAdapter = 'AML\\Data\\MongoDB\\MongoDriverAdapter';
        if (class_exists($mongoAdapter)) {
            $adapter = new $mongoAdapter();
            if ($adapter instanceof DriverAdapter) {
                $this->register('mongodb', $adapter);
            }
        }
    }

    public function defaultName(): string
    {
        $default = $this->config['default'] ?? array_key_first($this->connections);
        if (!is_string($default) || !isset($this->connections[$default])) {
            throw new InvalidArgumentException('La connexion par défaut est invalide.');
        }
        return $default;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->connections);
    }

    public function register(string $driver, DriverAdapter $adapter): void
    {
        $this->adapters[strtolower($driver)] = $adapter;
    }

    public function connection(?string $name = null): mixed
    {
        $name ??= $this->defaultName();
        if (array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name];
        }
        $config = $this->configuration($name);
        $dsn = is_string($config['dsn'] ?? null) ? $config['dsn'] : '';
        $configuredDriver = is_string($config['driver'] ?? null) ? $config['driver'] : '';
        $driver = strtolower($configuredDriver !== '' ? $configuredDriver : ($this->driverFromDsn($dsn) ?: 'sqlite'));
        if (isset($this->adapters[$driver])) {
            return $this->resolved[$name] = $this->adapters[$driver]->connect($config, $this->projectRoot);
        }
        if ($driver === 'mongodb' || $driver === 'mongo') {
            throw AdapterNotInstalledException::forDriver('mongodb');
        }
        return $this->resolved[$name] = $this->sqlConnection($driver, $config);
    }

    public function sql(?string $name = null): Connection
    {
        $connection = $this->connection($name);
        if (!$connection instanceof Connection) {
            throw new InvalidArgumentException("La connexion demandée n'est pas une connexion SQL.");
        }
        return $connection;
    }

    /**
     * @template T of DbContext
     * @param class-string<T> $contextClass
     * @return T
     */
    public function context(string $contextClass, ?string $name = null): DbContext
    {
        if (!is_subclass_of($contextClass, DbContext::class)) {
            throw new InvalidArgumentException("{$contextClass} doit étendre " . DbContext::class . '.');
        }
        return new $contextClass($this->sql($name));
    }

    /** @return array<string, mixed> */
    public function configuration(?string $name = null): array
    {
        $name ??= $this->defaultName();
        $config = $this->connections[$name] ?? null;
        if ($config === null) {
            throw new InvalidArgumentException("Connexion inconnue : {$name}. Disponibles : " . implode(', ', $this->names()));
        }
        return $config;
    }

    /** @return array<string, mixed> */
    public function rootConfiguration(): array
    {
        return $this->config;
    }

    /** @param array<string, mixed> $config */
    private function sqlConnection(string $driver, array $config): Connection
    {
        $dsn = $config['dsn'] ?? null;
        if (!is_string($dsn) || $dsn === '') {
            $dsn = match ($driver) {
                'sqlite' => 'sqlite:' . $this->absolute($this->string($config, 'database', 'runtime/storage/app.sqlite')),
                'mysql', 'mariadb' => 'mysql:host=' . $this->string($config, 'host', '127.0.0.1') . ';port=' . $this->string($config, 'port', '3306') . ';dbname=' . $this->string($config, 'database') . ';charset=' . $this->string($config, 'charset', 'utf8mb4'),
                'pgsql', 'postgres', 'postgresql' => 'pgsql:host=' . $this->string($config, 'host', '127.0.0.1') . ';port=' . $this->string($config, 'port', '5432') . ';dbname=' . $this->string($config, 'database'),
                default => throw AdapterNotInstalledException::forDriver($driver),
            };
        }
        if (str_starts_with($dsn, 'sqlite:')) {
            $path = substr($dsn, 7);
            if ($path !== ':memory:' && !str_starts_with($path, '/')) {
                $dsn = 'sqlite:' . $this->absolute($path);
            }
        }
        $username = is_string($config['username'] ?? null) ? $config['username'] : null;
        $password = is_string($config['password'] ?? null) ? $config['password'] : null;
        return new Connection($dsn, $username, $password);
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($this->projectRoot, '/\\') . '/' . ltrim($path, '/\\');
    }

    private function driverFromDsn(string $dsn): string
    {
        return strtolower((string) strstr($dsn, ':', true));
    }

    /** @param array<string, mixed> $config */
    private function string(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException("La configuration {$key} doit être une chaîne ou un entier.");
        }
        return (string) $value;
    }
}
