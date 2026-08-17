<?php

declare(strict_types=1);

namespace AML\Data\Validation;

use AML\Data\Entity;
use ReflectionClass;
use ReflectionProperty;

final class EntityValidator
{
    public function validate(Entity $entity): void
    {
        $errors = [];
        foreach ((new ReflectionClass($entity))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $initialized = $property->isInitialized($entity);
            $value = $initialized ? $property->getValue($entity) : null;
            foreach ($property->getAttributes(Required::class) as $attribute) {
                $rule = $attribute->newInstance();
                if (!$initialized || $value === null || (is_string($value) && trim($value) === '')) {
                    $errors[$property->getName()][] = $rule->message;
                }
            }
            if ($value === null) {
                continue;
            }
            foreach ($property->getAttributes(Email::class) as $attribute) {
                $rule = $attribute->newInstance();
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[$property->getName()][] = $rule->message;
                }
            }
            foreach ($property->getAttributes(Length::class) as $attribute) {
                $rule = $attribute->newInstance();
                $length = is_string($value) ? (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) : null;
                if ($length === null || ($rule->min !== null && $length < $rule->min) || ($rule->max !== null && $length > $rule->max)) {
                    $errors[$property->getName()][] = $rule->message;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
