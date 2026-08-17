<?php

declare(strict_types=1);

namespace AML\Data\Relations;

use AML\Data\Entity;
use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class HasOne
{
    /** @param class-string<Entity> $target */
    public function __construct(
        public string $target,
        public string $foreignKey,
        public string $localKey = 'id',
    ) {
    }
}
