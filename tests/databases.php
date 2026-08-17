<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AML\Data\Connection;
use AML\Data\DbContext;
use AML\Data\Entity;
use AML\Data\Metadata\Table;
use AML\Data\Schema\Schema;
use AML\Data\Schema\Table as SchemaTable;

#[Table('aml_data_integration_records')]
final class IntegrationRecord extends Entity
{
    public int $id;
    public string $label;
}

final class IntegrationContext extends DbContext
{
    /** @return \AML\Data\DbSet<IntegrationRecord> */
    public function records(): \AML\Data\DbSet { return $this->set(IntegrationRecord::class); }
}

/** @return bool vrai si le test a été exécuté */
function testDatabase(string $name, string $prefix): bool
{
    $dsn = getenv($prefix . '_DSN');
    if (!is_string($dsn) || $dsn === '') {
        echo "↷ {$name} ignoré : {$prefix}_DSN absent.\n";
        return false;
    }
    $connection = new Connection($dsn, getenv($prefix . '_USER') ?: null, getenv($prefix . '_PASSWORD') ?: null);
    $schema = new Schema($connection);
    $schema->dropIfExists('aml_data_integration_records');
    try {
        $schema->create('aml_data_integration_records', function (SchemaTable $table): void {
            $table->id();
            $table->string('label', 100);
        });
        $context = new IntegrationContext($connection);
        $record = new IntegrationRecord(); $record->label = 'created';
        $context->records()->add($record);
        if ($record->id < 1 || $context->records()->find($record->id)?->label !== 'created') {
            throw new RuntimeException("CRUD {$name} invalide.");
        }
        try {
            $context->transaction(function (IntegrationContext $db): void {
                $record = new IntegrationRecord(); $record->label = 'rollback'; $db->records()->add($record);
                throw new RuntimeException('rollback expected');
            });
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'rollback expected') throw $error;
        }
        if ($context->records()->where('label', '=', 'rollback')->count() !== 0) {
            throw new RuntimeException("Rollback {$name} invalide.");
        }
        echo "✓ {$name} : schéma, CRUD et transaction.\n";
        return true;
    } finally {
        $schema->dropIfExists('aml_data_integration_records');
    }
}

$failed = 0;
foreach ([['MySQL', 'AML_DATA_MYSQL'], ['MariaDB', 'AML_DATA_MARIADB'], ['PostgreSQL', 'AML_DATA_PGSQL']] as [$name, $prefix]) {
    try { testDatabase($name, $prefix); } catch (Throwable $error) { fwrite(STDERR, "✗ {$name}: {$error->getMessage()}\n"); $failed++; }
}
exit($failed === 0 ? 0 : 1);
