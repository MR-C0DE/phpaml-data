<?php

declare(strict_types=1);

namespace AML\Data\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Required
{
    public function __construct(public string $message = 'Ce champ est obligatoire.')
    {
    }
}
