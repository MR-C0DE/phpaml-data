<?php

declare(strict_types=1);

namespace AML\Data\Metadata;

use AML\Data\Entity;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use AML\Data\Relations\BelongsTo;
use AML\Data\Relations\HasMany;
use AML\Data\Relations\HasOne;
use AML\Data\Relations\BelongsToMany;

/** @template T of Entity */
final readonly class EntityMetadata
{
    /**
     * @param class-string<T> $class
     * @param array<string, ReflectionProperty> $properties
     */
    private function __construct(
        public string $class,
        public string $table,
        public array $properties,
        public string $key,
    ) {
    }

    /**
     * @template E of Entity
     * @param class-string<E> $class
     * @return self<E>
     */
    public static function from(string $class): self
    {
        if (!is_subclass_of($class, Entity::class)) {
            throw new InvalidArgumentException("{$class} doit étendre " . Entity::class . '.');
        }
        $reflection = new ReflectionClass($class);
        $tableAttribute = $reflection->getAttributes(Table::class)[0] ?? null;
        $table = $tableAttribute ? $tableAttribute->newInstance()->name : self::snake($reflection->getShortName()) . 's';
        $properties = [];
        $key = null;
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            if ($property->getAttributes(BelongsTo::class) !== []
                || $property->getAttributes(HasMany::class) !== []
                || $property->getAttributes(HasOne::class) !== []
                || $property->getAttributes(BelongsToMany::class) !== []) {
                continue;
            }
            $columnAttribute = $property->getAttributes(Column::class)[0] ?? null;
            $column = $columnAttribute?->newInstance()->name ?? self::snake($property->getName());
            $properties[$column] = $property;
            if ($property->getAttributes(Key::class) !== [] || $property->getName() === 'id') {
                $key = $column;
            }
        }
        if ($properties === [] || $key === null) {
            throw new InvalidArgumentException("L'entité {$class} doit exposer une clé publique #[Key] ou nommée id.");
        }
        return new self($class, self::identifier($table), $properties, self::identifier($key));
    }

    /**
     * @param array<string, mixed> $row
     * @return T
     */
    public function hydrate(array $row): Entity
    {
        $entity = (new ReflectionClass($this->class))->newInstanceWithoutConstructor();
        foreach ($this->properties as $column => $property) {
            if (array_key_exists($column, $row)) {
                $property->setValue($entity, $row[$column]);
            }
        }
        return $entity;
    }

    /** @return array<string, mixed> */
    public function extract(Entity $entity, bool $includeKey = true): array
    {
        $data = [];
        foreach ($this->properties as $column => $property) {
            if (!$includeKey && $column === $this->key) {
                continue;
            }
            if ($property->isInitialized($entity)) {
                $data[$column] = $property->getValue($entity);
            }
        }
        return $data;
    }

    private static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    private static function identifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new InvalidArgumentException("Identifiant de données invalide : {$value}");
        }
        return $value;
    }
}
