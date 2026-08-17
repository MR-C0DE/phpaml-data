<?php

declare(strict_types=1);

namespace AML\Data\Relations;

use AML\Data\Entity;
use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class BelongsToMany
{
    /** @param class-string<Entity> $target */
    public function __construct(
        public string $target,
        public string $pivotTable,
        public string $pivotLocalKey,
        public string $pivotTargetKey,
        public string $localKey = 'id',
        public string $targetKey = 'id',
    ) {
    }
}
