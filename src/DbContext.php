<?php

declare(strict_types=1);

namespace AML\Data;

use AML\Data\ChangeTracking\EntityState;
use AML\Data\ChangeTracking\TrackedEntity;
use AML\Data\Metadata\EntityMetadata;
use AML\Data\Relations\ManyToManyRelation;

abstract class DbContext
{
    /** @var array<int, TrackedEntity> */
    private array $tracked = [];
    /** @var array<int, array{entity: Entity, data: array<string, mixed>}> */
    private array $snapshots = [];
    public function __construct(protected readonly Connection $connection)
    {
    }

    /**
     * @template T of Entity
     * @param class-string<T> $entity
     * @return DbSet<T>
     */
    final public function set(string $entity): DbSet
    {
        return new DbSet($this->connection, $entity, fn (Entity $item): null => $this->attach($item));
    }

    final public function transaction(callable $operation): mixed
    {
        return $this->connection->transaction(fn (): mixed => $operation($this));
    }

    final public function relation(Entity $entity, string $relation): ManyToManyRelation
    {
        return new ManyToManyRelation($this->connection, $entity, $relation);
    }

    final public function add(Entity $entity): void
    {
        $this->track($entity, EntityState::Added);
    }

    final public function update(Entity $entity): void
    {
        $this->track($entity, EntityState::Modified);
    }

    final public function remove(Entity $entity): void
    {
        $this->track($entity, EntityState::Deleted);
    }

    final public function saveChanges(): int
    {
        $this->detectChanges();
        if ($this->tracked === []) {
            return 0;
        }
        $tracked = $this->tracked;
        $count = $this->connection->transaction(function () use ($tracked): int {
            foreach ($tracked as $entry) {
                $set = $this->set($entry->entity::class);
                match ($entry->state) {
                    EntityState::Added => $set->add($entry->entity),
                    EntityState::Modified => $set->update($entry->entity),
                    EntityState::Deleted => $set->remove($entry->entity),
                };
            }
            return count($tracked);
        });
        foreach (array_keys($tracked) as $id) {
            unset($this->tracked[$id]);
        }
        foreach ($tracked as $id => $entry) {
            if ($entry->state === EntityState::Deleted) {
                unset($this->snapshots[$id]);
                continue;
            }
            $this->attach($entry->entity, true);
        }
        if (!is_int($count)) {
            throw new \LogicException("Le résultat de l'unité de travail est invalide.");
        }
        return $count;
    }

    final public function pendingChanges(): int
    {
        $this->detectChanges();
        return count($this->tracked);
    }

    private function track(Entity $entity, EntityState $state): void
    {
        $this->tracked[spl_object_id($entity)] = new TrackedEntity($entity, $state);
    }

    private function attach(Entity $entity, bool $refresh = false): null
    {
        $id = spl_object_id($entity);
        if ($refresh || !isset($this->snapshots[$id])) {
            $this->snapshots[$id] = [
                'entity' => $entity,
                'data' => EntityMetadata::from($entity::class)->extract($entity),
            ];
        }
        return null;
    }

    private function detectChanges(): void
    {
        foreach ($this->snapshots as $id => $snapshot) {
            if (isset($this->tracked[$id])) {
                continue;
            }
            $current = EntityMetadata::from($snapshot['entity']::class)->extract($snapshot['entity']);
            if ($current !== $snapshot['data']) {
                $this->tracked[$id] = new TrackedEntity($snapshot['entity'], EntityState::Modified);
            }
        }
    }
}
