<?php

declare(strict_types=1);

namespace AML\Data\Integration;

use InvalidArgumentException;
use RuntimeException;

final readonly class ProjectScaffolder
{
    public function __construct(private string $root)
    {
    }

    /** @return list<string> fichiers créés ou modifiés */
    public function install(string $driver = 'sqlite'): array
    {
        $driver = strtolower($driver);
        if (!in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql', 'mongodb'], true)) {
            throw new InvalidArgumentException("Pilote inconnu : {$driver}");
        }
        $changes = [];
        foreach (['src/models', 'src/Data', 'runtime/database/migrations', 'runtime/database/seeders', 'runtime/storage'] as $directory) {
            $path = $this->root . '/' . $directory;
            if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
                throw new RuntimeException("Impossible de créer {$directory}.");
            }
        }
        $manifestPath = is_file($this->root . '/phpaml.json') ? $this->root . '/phpaml.json' : $this->root . '/info.json';
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest)) throw new RuntimeException('Le manifeste PHPAML est invalide.');
            $manifest['modules'] = is_array($manifest['modules'] ?? null) ? $manifest['modules'] : [];
            $manifest['modules']['data'] = ['version' => '0.2.0-alpha.1', 'driver' => $driver];
            if ($driver === 'mongodb') $manifest['modules']['data-mongodb'] = ['version' => '0.1.0-alpha.4'];
            if (isset($manifest['application']) && !isset($manifest['data'])) {
                $manifest['data'] = $this->declarativeConfig($driver);
            }
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
            $changes[] = basename($manifestPath);
        } else {
            $configPath = $this->root . '/configs/data.php';
            if (!is_file($configPath)) {
                if (!is_dir(dirname($configPath))) mkdir(dirname($configPath), 0755, true);
                file_put_contents($configPath, $this->configTemplate($driver));
                $changes[] = 'configs/data.php';
            }
        }
        $composerPath = $this->root . '/composer.json';
        if (is_file($composerPath)) {
            $composer = json_decode((string) file_get_contents($composerPath), true);
            if (is_array($composer)) {
                $autoload = is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
                $psr4 = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
                $psr4['App\\Models\\'] = 'src/models/';
                $autoload['psr-4'] = $psr4;
                $composer['autoload'] = $autoload;
                file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
                $changes[] = 'composer.json';
            }
        }
        return array_values(array_unique($changes));
    }

    public function model(string $name): string
    {
        $class = $this->className($name);
        $path = "src/models/{$class}.php";
        $content = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\nuse AML\\Data\\Entity;\nuse AML\\Data\\Metadata\\{Key, Table};\n\n#[Table('" . $this->snake($class) . "s')]\nfinal class {$class} extends Entity\n{\n    #[Key]\n    public int \$id;\n}\n";
        return $this->writeNew($path, $content);
    }

    public function migration(string $name): string
    {
        $slug = $this->snake($name);
        if ($slug === '') throw new InvalidArgumentException('Le nom de migration est invalide.');
        $path = 'runtime/database/migrations/' . date('YmdHis') . "_{$slug}.php";
        $table = preg_match('/^create_(.+)_table$/', $slug, $match) ? $match[1] : 'table_name';
        $content = "<?php\n\ndeclare(strict_types=1);\n\nuse AML\\Data\\Connection;\nuse AML\\Data\\Migrations\\Migration;\nuse AML\\Data\\Schema\\{Schema, Table};\n\nreturn new class extends Migration {\n    public function up(Connection \$connection): void\n    {\n        (new Schema(\$connection))->create('{$table}', function (Table \$table): void {\n            \$table->id();\n            \$table->timestamps();\n        });\n    }\n\n    public function down(Connection \$connection): void\n    {\n        (new Schema(\$connection))->dropIfExists('{$table}');\n    }\n};\n";
        return $this->writeNew($path, $content);
    }

    public function seeder(string $name): string
    {
        $class = $this->className($name, 'Seeder');
        $path = "runtime/database/seeders/{$class}.php";
        $content = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Database\\Seeders;\n\nuse AML\\Data\\DbContext;\nuse AML\\Data\\Seeding\\Seeder;\n\nfinal class {$class} implements Seeder\n{\n    public function seed(DbContext \$context): void\n    {\n        // Ajoutez vos données initiales ici.\n    }\n}\n";
        return $this->writeNew($path, $content);
    }

    private function configTemplate(string $driver): string
    {
        $database = $driver === 'sqlite' ? 'runtime/storage/app.sqlite' : 'app';
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'default' => getenv('DATA_CONNECTION') ?: 'main',\n    'connections' => [\n        'main' => [\n            'driver' => getenv('DATA_DRIVER') ?: '{$driver}',\n            'dsn' => getenv('DATA_DSN') ?: null,\n            'database' => getenv('DATA_DATABASE') ?: '{$database}',\n            'host' => getenv('DATA_HOST') ?: '127.0.0.1',\n            'port' => getenv('DATA_PORT') ?: null,\n            'username' => getenv('DATA_USERNAME') ?: null,\n            'password' => getenv('DATA_PASSWORD') ?: null,\n            'uri' => getenv('DATA_URI') ?: null,\n        ],\n    ],\n    'migrations_path' => dirname(__DIR__) . '/runtime/database/migrations',\n    'models_path' => dirname(__DIR__) . '/src/models',\n    'seeders' => [],\n];\n";
    }

    /** @return array<string, mixed> */
    private function declarativeConfig(string $driver): array
    {
        return [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'driver' => $driver,
                    'dsn' => null,
                    'database' => $driver === 'sqlite' ? 'runtime/storage/app.sqlite' : 'app',
                    'host' => '127.0.0.1',
                    'port' => null,
                    'username' => null,
                    'password' => null,
                    'uri' => null,
                ],
            ],
            'migrations_path' => 'runtime/database/migrations',
            'models_path' => 'src/models',
            'seeders' => [],
        ];
    }

    private function writeNew(string $relative, string $content): string
    {
        $path = $this->root . '/' . $relative;
        if (is_file($path)) throw new RuntimeException("Le fichier {$relative} existe déjà.");
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) throw new RuntimeException("Impossible de créer " . dirname($relative) . '.');
        file_put_contents($path, $content);
        return $relative;
    }

    private function className(string $name, string $suffix = ''): string
    {
        $value = str_replace(' ', '', ucwords((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $name)));
        if ($value === '' || ctype_digit($value[0])) throw new InvalidArgumentException('Le nom de classe est invalide.');
        return str_ends_with($value, $suffix) ? $value : $value . $suffix;
    }

    private function snake(string $value): string
    {
        $value = (string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value);
        $value = (string) preg_replace('/[^A-Za-z0-9]+/', '_', $value);
        return trim(strtolower($value), '_');
    }
}
