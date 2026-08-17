<?php

declare(strict_types=1);

namespace AML\Data\ChangeTracking;

enum EntityState: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
}
