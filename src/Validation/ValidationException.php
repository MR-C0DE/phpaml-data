<?php

declare(strict_types=1);

namespace AML\Data\Validation;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct("L'entité contient des données invalides.");
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
