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
        foreach ($this->plan($entity::class) as [$property, $required, $email, $length]) {
            $initialized = $property->isInitialized($entity);
            $value = $initialized ? $property->getValue($entity) : null;
            foreach ($required as $rule) {
                if (!$initialized || $value === null || (is_string($value) && trim($value) === '')) {
                    $errors[$property->getName()][] = $rule->message;
                }
            }
            if ($value === null) {
                continue;
            }
            foreach ($email as $rule) {
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[$property->getName()][] = $rule->message;
                }
            }
            foreach ($length as $rule) {
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

    /** @param class-string<Entity> $class @return list<array{ReflectionProperty, list<Required>, list<Email>, list<Length>}> */
    private function plan(string $class): array
    {
        /** @var array<class-string<Entity>, list<array{ReflectionProperty, list<Required>, list<Email>, list<Length>}>> $cache */
        static $cache = [];
        if (isset($cache[$class])) {
            return $cache[$class];
        }

        $plan = [];
        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) continue;
            $instantiate = static fn (string $attribute): array => array_map(
                static fn (\ReflectionAttribute $definition): object => $definition->newInstance(),
                $property->getAttributes($attribute),
            );
            $plan[] = [$property, $instantiate(Required::class), $instantiate(Email::class), $instantiate(Length::class)];
        }
        return $cache[$class] = $plan;
    }
}
