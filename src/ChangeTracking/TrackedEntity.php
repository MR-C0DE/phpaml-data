<?php

declare(strict_types=1);

namespace AML\Data\ChangeTracking;

use AML\Data\Entity;

final readonly class TrackedEntity
{
    public function __construct(public Entity $entity, public EntityState $state)
    {
    }
}
