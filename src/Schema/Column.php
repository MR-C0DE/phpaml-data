<?php

declare(strict_types=1);

namespace AML\Data\Schema;

use InvalidArgumentException;
use AML\Data\SQL\Dialect;

final class Column
{
    private bool $nullable = false;
    private bool $unique = false;
    private bool $primary = false;
    private bool $autoIncrement = false;
    private bool $hasDefault = false;
    private mixed $default = null;
    private ?string $defaultExpression = null;
    /** @var array{string, string, string}|null */
    private ?array $reference = null;

    public function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly ?int $length = null,
    )
    {
        self::identifier($name);
    }

    public function nullable(): self { $this->nullable = true; return $this; }
    public function unique(): self { $this->unique = true; return $this; }
    public function primary(): self { $this->primary = true; return $this; }
    public function autoIncrement(): self { $this->autoIncrement = true; return $this; }
    public function default(mixed $value): self { $this->hasDefault = true; $this->default = $value; return $this; }
    public function defaultExpression(string $expression): self
    {
        if (!in_array(strtoupper($expression), ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'], true)) {
            throw new InvalidArgumentException("Expression par défaut non autorisée : {$expression}");
        }
        $this->defaultExpression = strtoupper($expression);
        return $this;
    }

    public function references(string $column, string $table, string $onDelete = 'NO ACTION'): self
    {
        self::identifier($column); self::identifier($table);
        $onDelete = strtoupper($onDelete);
        if (!in_array($onDelete, ['NO ACTION', 'RESTRICT', 'CASCADE', 'SET NULL'], true)) {
            throw new InvalidArgumentException("Action de suppression invalide : {$onDelete}");
        }
        $this->reference = [$table, $column, $onDelete];
        return $this;
    }

    public function toSql(Dialect $dialect): string
    {
        if ($this->primary && $this->autoIncrement) {
            return $dialect->primaryKey($this->name);
        }
        $sql = $dialect->quote($this->name) . ' ' . $dialect->columnType($this->type, $this->length);
        if ($this->primary) $sql .= ' PRIMARY KEY';
        if ($this->autoIncrement) $sql .= ' AUTOINCREMENT';
        if (!$this->nullable && !$this->primary) $sql .= ' NOT NULL';
        if ($this->unique) $sql .= ' UNIQUE';
        if ($this->defaultExpression !== null) {
            $sql .= ' DEFAULT ' . $this->defaultExpression;
        } elseif ($this->hasDefault) {
            $sql .= ' DEFAULT ' . $dialect->literal($this->default);
        }
        if ($this->reference !== null) {
            [$table, $column, $onDelete] = $this->reference;
            $sql .= ' REFERENCES ' . $dialect->quote($table) . ' (' . $dialect->quote($column) . ") ON DELETE {$onDelete}";
        }
        return $sql;
    }

    public static function identifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new InvalidArgumentException("Identifiant de schéma invalide : {$value}");
        }
        return $value;
    }
}
