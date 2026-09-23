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
        /** @var array<string, list<string>> $errors */
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

    /**
     * @param class-string<Entity> $class
     * @return list<array{0: ReflectionProperty, 1: list<Required>, 2: list<Email>, 3: list<Length>}>
     */
    private function plan(string $class): array
    {
        /** @var array<class-string<Entity>, list<array{0: ReflectionProperty, 1: list<Required>, 2: list<Email>, 3: list<Length>}>> $cache */
        static $cache = [];
        if (isset($cache[$class])) {
            return $cache[$class];
        }

        $plan = [];
        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) continue;
            /** @var list<Required> $required */
            $required = array_map(
                static fn (\ReflectionAttribute $definition): Required => $definition->newInstance(),
                $property->getAttributes(Required::class),
            );
            /** @var list<Email> $email */
            $email = array_map(
                static fn (\ReflectionAttribute $definition): Email => $definition->newInstance(),
                $property->getAttributes(Email::class),
            );
            /** @var list<Length> $length */
            $length = array_map(
                static fn (\ReflectionAttribute $definition): Length => $definition->newInstance(),
                $property->getAttributes(Length::class),
            );
            $plan[] = [$property, $required, $email, $length];
        }
        return $cache[$class] = $plan;
    }
}
