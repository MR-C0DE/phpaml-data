<?php

declare(strict_types=1);

namespace AML\Data\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Email
{
    public function __construct(public string $message = 'Cette adresse courriel est invalide.')
    {
    }
}
