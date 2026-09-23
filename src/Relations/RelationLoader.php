<?php

declare(strict_types=1);

namespace AML\Data\Relations;

use AML\Data\Connection;
use AML\Data\DbSet;
use AML\Data\Entity;
use AML\Data\Metadata\EntityMetadata;
use InvalidArgumentException;

final readonly class RelationLoader
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<Entity> $entities
     * @param EntityMetadata<covariant Entity> $metadata
     */
    public function load(array $entities, EntityMetadata $metadata, string $relation): void
    {
        if ($entities === []) {
            return;
        }
        $definition = $metadata->relations[$relation] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException("{$relation} n'est pas une relation de {$metadata->class}.");
        }
        $property = $definition['property'];
        $rule = $definition['rule'];
        if ($rule instanceof BelongsTo) {
            $this->loadBelongsTo($entities, $metadata, $property, $rule);
            return;
        }
        if ($rule instanceof HasMany) {
            $this->loadHasMany($entities, $metadata, $property, $rule);
            return;
        }
        if ($rule instanceof HasOne) {
            $this->loadHasOne($entities, $metadata, $property, $rule);
            return;
        }
        if (!$rule instanceof BelongsToMany) throw new InvalidArgumentException("Relation invalide : {$relation}");
        $this->loadManyToMany($entities, $metadata, $property, $rule);
    }

    /**
     * @param list<Entity> $entities
     * @param EntityMetadata<covariant Entity> $metadata
     */
    private function loadBelongsTo(array $entities, EntityMetadata $metadata, \ReflectionProperty $property, BelongsTo $rule): void
    {
        $foreignProperty = $metadata->properties[$rule->foreignKey] ?? null;
        if ($foreignProperty === null) {
            throw new InvalidArgumentException("Clé étrangère inconnue : {$rule->foreignKey}");
        }
        $keys = [];
        foreach ($entities as $entity) {
            if ($foreignProperty->isInitialized($entity)) {
                $keys[] = $foreignProperty->getValue($entity);
            }
        }
        $related = (new DbSet($this->connection, $rule->target))->whereIn($rule->ownerKey, array_values(array_unique($keys, SORT_REGULAR)))->all();
        $targetMetadata = EntityMetadata::from($rule->target);
        $ownerProperty = $targetMetadata->properties[$rule->ownerKey] ?? throw new InvalidArgumentException("Clé propriétaire inconnue : {$rule->ownerKey}");
        $indexed = [];
        foreach ($related as $item) {
            $indexed[$this->key($ownerProperty->getValue($item))] = $item;
        }
        foreach ($entities as $entity) {
            $key = $foreignProperty->isInitialized($entity) ? $this->key($foreignProperty->getValue($entity)) : '';
            $property->setValue($entity, $indexed[$key] ?? null);
        }
    }

    /**
     * @param list<Entity> $entities
     * @param EntityMetadata<covariant Entity> $metadata
     */
    private function loadHasMany(array $entities, EntityMetadata $metadata, \ReflectionProperty $property, HasMany $rule): void
    {
        $localProperty = $metadata->properties[$rule->localKey] ?? throw new InvalidArgumentException("Clé locale inconnue : {$rule->localKey}");
        $keys = array_map(static fn (Entity $entity): mixed => $localProperty->getValue($entity), $entities);
        $related = (new DbSet($this->connection, $rule->target))->whereIn($rule->foreignKey, array_values(array_unique($keys, SORT_REGULAR)))->all();
        $targetMetadata = EntityMetadata::from($rule->target);
        $foreignProperty = $targetMetadata->properties[$rule->foreignKey] ?? throw new InvalidArgumentException("Clé étrangère inconnue : {$rule->foreignKey}");
        $grouped = [];
        foreach ($related as $item) {
            $grouped[$this->key($foreignProperty->getValue($item))][] = $item;
        }
        foreach ($entities as $entity) {
            $property->setValue($entity, $grouped[$this->key($localProperty->getValue($entity))] ?? []);
        }
    }

    /**
     * @param list<Entity> $entities
     * @param EntityMetadata<covariant Entity> $metadata
     */
    private function loadHasOne(array $entities, EntityMetadata $metadata, \ReflectionProperty $property, HasOne $rule): void
    {
        $localProperty = $metadata->properties[$rule->localKey] ?? throw new InvalidArgumentException("Clé locale inconnue : {$rule->localKey}");
        $keys = array_map(static fn (Entity $entity): mixed => $localProperty->getValue($entity), $entities);
        $related = (new DbSet($this->connection, $rule->target))->whereIn($rule->foreignKey, array_values(array_unique($keys, SORT_REGULAR)))->all();
        $targetMetadata = EntityMetadata::from($rule->target);
        $foreignProperty = $targetMetadata->properties[$rule->foreignKey] ?? throw new InvalidArgumentException("Clé étrangère inconnue : {$rule->foreignKey}");
        $indexed = [];
        foreach ($related as $item) {
            $indexed[$this->key($foreignProperty->getValue($item))] ??= $item;
        }
        foreach ($entities as $entity) {
            $property->setValue($entity, $indexed[$this->key($localProperty->getValue($entity))] ?? null);
        }
    }

    /**
     * @param list<Entity> $entities
     * @param EntityMetadata<covariant Entity> $metadata
     */
    private function loadManyToMany(array $entities, EntityMetadata $metadata, \ReflectionProperty $property, BelongsToMany $rule): void
    {
        foreach ([$rule->pivotTable, $rule->pivotLocalKey, $rule->pivotTargetKey] as $identifier) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
                throw new InvalidArgumentException("Identifiant pivot invalide : {$identifier}");
            }
        }
        $localProperty = $metadata->properties[$rule->localKey] ?? throw new InvalidArgumentException("Clé locale inconnue : {$rule->localKey}");
        $localKeys = array_values(array_unique(array_map(static fn (Entity $entity): mixed => $localProperty->getValue($entity), $entities), SORT_REGULAR));
        if ($localKeys === []) {
            return;
        }
        $holders = [];
        $parameters = [];
        foreach ($localKeys as $index => $key) {
            $holder = ':pivot' . $index;
            $holders[] = $holder;
            $parameters[$holder] = $key;
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->execute(
            'SELECT ' . $this->q($rule->pivotLocalKey) . ', ' . $this->q($rule->pivotTargetKey)
                . ' FROM ' . $this->q($rule->pivotTable) . ' WHERE ' . $this->q($rule->pivotLocalKey) . ' IN (' . implode(', ', $holders) . ')',
            $parameters,
        )->fetchAll();
        $targetIds = array_values(array_unique(array_column($rows, $rule->pivotTargetKey), SORT_REGULAR));
        $targets = (new DbSet($this->connection, $rule->target))->whereIn($rule->targetKey, $targetIds)->all();
        $targetMetadata = EntityMetadata::from($rule->target);
        $targetProperty = $targetMetadata->properties[$rule->targetKey] ?? throw new InvalidArgumentException("Clé cible inconnue : {$rule->targetKey}");
        $targetIndex = [];
        foreach ($targets as $target) {
            $targetIndex[$this->key($targetProperty->getValue($target))] = $target;
        }
        $grouped = [];
        foreach ($rows as $row) {
            $target = $targetIndex[$this->key($row[$rule->pivotTargetKey] ?? null)] ?? null;
            if ($target !== null) {
                $grouped[$this->key($row[$rule->pivotLocalKey] ?? null)][] = $target;
            }
        }
        foreach ($entities as $entity) {
            $property->setValue($entity, $grouped[$this->key($localProperty->getValue($entity))] ?? []);
        }
    }

    private function q(string $identifier): string
    {
        return $this->connection->dialect()->quote($identifier);
    }

    private function key(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Une clé de relation doit être une chaîne ou un entier.');
        return (string) $value;
    }
}
