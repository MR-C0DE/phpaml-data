<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AML\Data\Connections\ConnectionManager;
use AML\Data\Integration\ProjectScaffolder;

$root = sys_get_temp_dir() . '/phpaml-data-projects-' . bin2hex(random_bytes(5));
mkdir($root, 0755, true);
$failed = 0;
foreach (['classic' => [], 'view' => ['view' => ['version' => 'test']]] as $type => $modules) {
    $project = $root . '/' . $type; mkdir($project, 0755, true);
    file_put_contents($project . '/phpaml.json', json_encode(['name' => $type, 'application' => ['type' => $type], 'modules' => $modules], JSON_THROW_ON_ERROR));
    try {
        (new ProjectScaffolder($project))->install('sqlite');
        $manifest = json_decode((string) file_get_contents($project . '/phpaml.json'), true, 512, JSON_THROW_ON_ERROR);
        $config = $manifest['data'];
        $connection = (new ConnectionManager($project, $config))->sql();
        $connection->pdo()->query('SELECT 1');
        echo "✓ projet PHPAML {$type}.\n";
    } catch (Throwable $error) { fwrite(STDERR, "✗ {$type}: {$error->getMessage()}\n"); $failed++; }
}
try {
    $standalone = new ConnectionManager($root . '/standalone', ['driver' => 'sqlite', 'database' => 'storage/app.sqlite']);
    $standalone->sql()->pdo()->query('SELECT 1'); echo "✓ projet PHP autonome.\n";
} catch (Throwable $error) { fwrite(STDERR, "✗ standalone: {$error->getMessage()}\n"); $failed++; }
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
rmdir($root);
exit($failed === 0 ? 0 : 1);
