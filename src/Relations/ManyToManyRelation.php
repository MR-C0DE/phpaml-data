<?php

declare(strict_types=1);

namespace AML\Data\Relations;

use AML\Data\Connection;
use AML\Data\Entity;
use AML\Data\Metadata\EntityMetadata;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ManyToManyRelation
{
    private BelongsToMany $rule;
    private mixed $localValue;

    public function __construct(
        private Connection $connection,
        Entity $entity,
        string $relation,
    ) {
        $property = (new ReflectionClass($entity))->getProperty($relation);
        $attribute = $property->getAttributes(BelongsToMany::class)[0] ?? null;
        if ($attribute === null) {
            throw new InvalidArgumentException("{$relation} n'est pas une relation many-to-many.");
        }
        $this->rule = $attribute->newInstance();
        foreach ([$this->rule->pivotTable, $this->rule->pivotLocalKey, $this->rule->pivotTargetKey] as $identifier) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
                throw new InvalidArgumentException("Identifiant pivot invalide : {$identifier}");
            }
        }
        $metadata = EntityMetadata::from($entity::class);
        $localProperty = $metadata->properties[$this->rule->localKey] ?? throw new InvalidArgumentException("Clé locale inconnue : {$this->rule->localKey}");
        if (!$localProperty->isInitialized($entity)) {
            throw new InvalidArgumentException("L'entité doit être persistée avant de modifier {$relation}.");
        }
        $this->localValue = $localProperty->getValue($entity);
    }

    /** @param int|string|list<int|string> $keys */
    public function attach(int|string|array $keys): int
    {
        $keys = $this->normalize($keys);
        if ($keys === []) {
            return 0;
        }
        $existing = array_flip(array_map('strval', $this->keys()));
        $added = 0;
        $this->connection->transaction(function () use ($keys, $existing, &$added): void {
            foreach ($keys as $key) {
                if (isset($existing[(string) $key])) {
                    continue;
                }
                $this->connection->execute(
                    'INSERT INTO ' . $this->q($this->rule->pivotTable) . ' (' . $this->q($this->rule->pivotLocalKey) . ', ' . $this->q($this->rule->pivotTargetKey) . ') VALUES (:local, :target)',
                    [':local' => $this->localValue, ':target' => $key],
                );
                $added++;
            }
        });
        return $added;
    }

    /** @param int|string|list<int|string>|null $keys null détache tout */
    public function detach(int|string|array|null $keys = null): int
    {
        $parameters = [':local' => $this->localValue];
        $sql = 'DELETE FROM ' . $this->q($this->rule->pivotTable) . ' WHERE ' . $this->q($this->rule->pivotLocalKey) . ' = :local';
        if ($keys !== null) {
            $keys = $this->normalize($keys);
            if ($keys === []) {
                return 0;
            }
            $holders = [];
            foreach ($keys as $index => $key) {
                $holder = ':target' . $index;
                $holders[] = $holder;
                $parameters[$holder] = $key;
            }
            $sql .= ' AND ' . $this->q($this->rule->pivotTargetKey) . ' IN (' . implode(', ', $holders) . ')';
        }
        return $this->connection->execute($sql, $parameters)->rowCount();
    }

    /** @param list<int|string> $keys */
    public function sync(array $keys): int
    {
        $desired = $this->normalize($keys);
        $current = $this->keys();
        $desiredIndex = array_fill_keys(array_map(static fn (int|string $key): string => (string) $key, $desired), true);
        $currentIndex = array_fill_keys(array_map(static fn (int|string $key): string => (string) $key, $current), true);
        $attach = [];
        foreach ($desired as $key) if (!isset($currentIndex[(string) $key])) $attach[] = $key;
        $detach = [];
        foreach ($current as $key) if (!isset($desiredIndex[(string) $key])) $detach[] = $key;
        $result = $this->connection->transaction(fn (): int => $this->detach($detach) + $this->attach($attach));
        if (!is_int($result)) throw new \LogicException('Résultat de synchronisation invalide.');
        return $result;
    }

    /** @return list<int|string> */
    public function keys(): array
    {
        $rows = $this->connection->execute(
            'SELECT ' . $this->q($this->rule->pivotTargetKey) . ' FROM ' . $this->q($this->rule->pivotTable) . ' WHERE ' . $this->q($this->rule->pivotLocalKey) . ' = :local',
            [':local' => $this->localValue],
        )->fetchAll(\PDO::FETCH_COLUMN);
        $keys = [];
        foreach ($rows as $value) {
            if (!is_int($value) && !is_string($value)) throw new InvalidArgumentException('Clé pivot invalide.');
            $keys[] = $value;
        }
        return $keys;
    }

    /**
     * @param int|string|list<int|string> $keys
     * @return list<int|string>
     */
    private function normalize(int|string|array $keys): array
    {
        $values = is_array($keys) ? $keys : [$keys];
        return array_values(array_unique($values, SORT_REGULAR));
    }

    private function q(string $identifier): string
    {
        return $this->connection->dialect()->quote($identifier);
    }
}
