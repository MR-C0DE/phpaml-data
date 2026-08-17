<?php

declare(strict_types=1);

namespace AML\Data\Migrations;

use AML\Data\Connection;

abstract class Migration
{
    abstract public function up(Connection $connection): void;

    abstract public function down(Connection $connection): void;
}
