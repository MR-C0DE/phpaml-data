<?php

declare(strict_types=1);

namespace AML\Data\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Length
{
    public function __construct(
        public ?int $min = null,
        public ?int $max = null,
        public string $message = 'La longueur de ce champ est invalide.',
    ) {
    }
}
