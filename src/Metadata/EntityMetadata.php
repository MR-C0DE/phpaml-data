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
     * @param array<string, array{property: ReflectionProperty, rule: BelongsTo|HasMany|HasOne|BelongsToMany}> $relations
     */
    private function __construct(
        public string $class,
        public string $table,
        public array $properties,
        public string $key,
        private ReflectionClass $reflection,
        public array $relations,
    ) {
    }

    /**
     * @template E of Entity
     * @param class-string<E> $class
     * @return self<E>
     */
    public static function from(string $class): self
    {
        /** @var array<class-string<Entity>, self<Entity>> $cache */
        static $cache = [];
        if (isset($cache[$class])) {
            return $cache[$class];
        }
        if (!is_subclass_of($class, Entity::class)) {
            throw new InvalidArgumentException("{$class} doit étendre " . Entity::class . '.');
        }
        $reflection = new ReflectionClass($class);
        $tableAttribute = $reflection->getAttributes(Table::class)[0] ?? null;
        $table = $tableAttribute ? $tableAttribute->newInstance()->name : self::snake($reflection->getShortName()) . 's';
        $properties = [];
        $relations = [];
        $key = null;
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $relationAttribute = $property->getAttributes(BelongsTo::class)[0]
                ?? $property->getAttributes(HasMany::class)[0]
                ?? $property->getAttributes(HasOne::class)[0]
                ?? $property->getAttributes(BelongsToMany::class)[0]
                ?? null;
            if ($relationAttribute !== null) {
                $relations[$property->getName()] = [
                    'property' => $property,
                    'rule' => $relationAttribute->newInstance(),
                ];
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
        return $cache[$class] = new self(
            $class,
            self::identifier($table),
            $properties,
            self::identifier($key),
            $reflection,
            $relations,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return T
     */
    public function hydrate(array $row): Entity
    {
        $entity = $this->reflection->newInstanceWithoutConstructor();
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
