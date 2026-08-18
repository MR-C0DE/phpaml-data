<?php

declare(strict_types=1);

namespace AML\Data;

use AML\Data\Metadata\EntityMetadata;
use AML\Data\Pagination\Page;
use AML\Data\Validation\EntityValidator;
use InvalidArgumentException;
use AML\Data\Relations\RelationLoader;

/** @template T of Entity */
final class DbSet
{
    /** @var list<array{column:string, operator:string, value:mixed}> */
    private array $wheres = [];
    /** @var list<array{column:string, direction:string}> */
    private array $orders = [];
    /** @var list<array{column:string, values:list<mixed>}> */
    private array $whereIns = [];
    /** @var list<string> */
    private array $relations = [];
    private ?int $limit = null;
    private int $offset = 0;

    /** @param class-string<T> $entityClass */
    public function __construct(
        private readonly Connection $connection,
        string $entityClass,
        private readonly ?\Closure $onHydrate = null,
    )
    {
        $this->metadata = EntityMetadata::from($entityClass);
    }

    /** @var EntityMetadata<T> */
    private readonly EntityMetadata $metadata;

    /** @return self<T> */
    public function where(string $column, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE'], true)) {
            throw new InvalidArgumentException("Opérateur non pris en charge : {$operator}");
        }
        $clone->assertColumn($column);
        $clone->wheres[] = ['column' => $column, 'operator' => $operator, 'value' => $value];
        return $clone;
    }

    /** @return self<T> */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $clone = clone $this;
        $clone->assertColumn($column);
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('La direction doit être asc ou desc.');
        }
        $clone->orders[] = ['column' => $column, 'direction' => $direction];
        return $clone;
    }

    /**
     * @param list<mixed> $values
     * @return self<T>
     */
    public function whereIn(string $column, array $values): self
    {
        $clone = clone $this;
        $clone->assertColumn($column);
        $clone->whereIns[] = ['column' => $column, 'values' => $values];
        return $clone;
    }

    /** @return self<T> */
    public function with(string ...$relations): self
    {
        $clone = clone $this;
        foreach ($relations as $relation) {
            if ($relation === '') {
                throw new InvalidArgumentException('Le nom de relation ne peut pas être vide.');
            }
            $clone->relations[] = $relation;
        }
        return $clone;
    }

    /** @return self<T> */
    public function limit(int $limit, int $offset = 0): self
    {
        if ($limit < 1 || $offset < 0) {
            throw new InvalidArgumentException('La limite doit être positive et le décalage positif ou nul.');
        }
        $clone = clone $this;
        $clone->limit = $limit;
        $clone->offset = $offset;
        return $clone;
    }

    /** @return list<T> */
    public function all(): array
    {
        [$where, $parameters] = $this->whereSql();
        $order = $this->orders === [] ? '' : ' ORDER BY ' . implode(', ', array_map(
            fn (array $item): string => $this->quote($item['column']) . ' ' . $item['direction'],
            $this->orders,
        ));
        $paging = $this->limit === null ? '' : " LIMIT {$this->limit} OFFSET {$this->offset}";
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->execute('SELECT * FROM ' . $this->quote($this->metadata->table) . "{$where}{$order}{$paging}", $parameters)->fetchAll();
        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->hydrate($row);
        }
        if ($this->onHydrate !== null) {
            foreach ($entities as $entity) {
                ($this->onHydrate)($entity);
            }
        }
        foreach (array_unique($this->relations) as $relation) {
            (new RelationLoader($this->connection))->load($entities, $this->metadata, $relation);
        }
        return $entities;
    }

    /** @return T|null */
    public function first(): ?Entity
    {
        return $this->limit(1)->all()[0] ?? null;
    }

    /** @return T|null */
    public function find(int|string $key): ?Entity
    {
        return $this->where($this->metadata->key, '=', $key)->first();
    }

    public function count(): int
    {
        [$where, $parameters] = $this->whereSql();
        return (int) $this->connection->execute('SELECT COUNT(*) FROM ' . $this->quote($this->metadata->table) . $where, $parameters)->fetchColumn();
    }

    /** @return Page<T> */
    public function paginate(int $page = 1, int $perPage = 15): Page
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('La page et sa taille doivent être positives.');
        }
        return new Page($this->limit($perPage, ($page - 1) * $perPage)->all(), $this->count(), $page, $perPage);
    }

    /** @param T $entity */
    public function add(Entity $entity): Entity
    {
        (new EntityValidator())->validate($entity);
        $keyProperty = $this->metadata->properties[$this->metadata->key];
        $hasExplicitKey = $keyProperty->isInitialized($entity);
        $data = $this->metadata->extract($entity, $hasExplicitKey);
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $returning = !$hasExplicitKey ? $this->connection->dialect()->insertReturning($this->metadata->key) : '';
        $statement = $this->connection->execute(
            'INSERT INTO ' . $this->quote($this->metadata->table) . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ') VALUES (' . implode(', ', $placeholders) . ')' . $returning,
            array_combine($placeholders, array_values($data)) ?: [],
        );
        if (!$hasExplicitKey) {
            $generated = $returning === '' ? $this->connection->pdo()->lastInsertId() : $statement->fetchColumn();
            $keyProperty->setValue($entity, is_numeric($generated) ? (int) $generated : $generated);
        }
        return $entity;
    }

    /** @param T $entity */
    public function update(Entity $entity): void
    {
        $data = $this->metadata->extract($entity, false);
        $keyProperty = $this->metadata->properties[$this->metadata->key];
        if (!$keyProperty->isInitialized($entity)) {
            throw new InvalidArgumentException("Impossible de modifier une entité sans clé.");
        }
        if ($data === []) {
            return;
        }
        (new EntityValidator())->validate($entity);
        $sets = array_map(fn (string $column): string => $this->quote($column) . " = :{$column}", array_keys($data));
        $parameters = [];
        foreach ($data as $column => $value) {
            $parameters[':' . $column] = $value;
        }
        $parameters[':__key'] = $keyProperty->getValue($entity);
        $this->connection->execute('UPDATE ' . $this->quote($this->metadata->table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $this->quote($this->metadata->key) . ' = :__key', $parameters);
    }

    /** @param T $entity */
    public function remove(Entity $entity): void
    {
        $keyProperty = $this->metadata->properties[$this->metadata->key];
        if (!$keyProperty->isInitialized($entity)) {
            throw new InvalidArgumentException("Impossible de supprimer une entité sans clé.");
        }
        $this->connection->execute('DELETE FROM ' . $this->quote($this->metadata->table) . ' WHERE ' . $this->quote($this->metadata->key) . ' = :key', [':key' => $keyProperty->getValue($entity)]);
    }

    /** @return array{string, array<string, mixed>} */
    private function whereSql(): array
    {
        $parts = [];
        $parameters = [];
        foreach ($this->wheres as $index => $where) {
            $parameter = ':w' . $index;
            $parts[] = $this->quote($where['column']) . " {$where['operator']} {$parameter}";
            $parameters[$parameter] = $where['value'];
        }
        foreach ($this->whereIns as $index => $where) {
            if ($where['values'] === []) {
                $parts[] = '1 = 0';
                continue;
            }
            $holders = [];
            foreach ($where['values'] as $valueIndex => $value) {
                $parameter = ':in' . $index . '_' . $valueIndex;
                $holders[] = $parameter;
                $parameters[$parameter] = $value;
            }
            $parts[] = $this->quote($where['column']) . ' IN (' . implode(', ', $holders) . ')';
        }
        return [$parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts), $parameters];
    }

    private function assertColumn(string $column): void
    {
        if (!isset($this->metadata->properties[$column])) {
            throw new InvalidArgumentException("Colonne inconnue : {$column}");
        }
    }

    private function quote(string $identifier): string
    {
        return $this->connection->dialect()->quote($identifier);
    }

    /**
     * @param array<string, mixed> $row
     * @return T
     */
    private function hydrate(array $row): Entity
    {
        return $this->metadata->hydrate($row);
    }
}
