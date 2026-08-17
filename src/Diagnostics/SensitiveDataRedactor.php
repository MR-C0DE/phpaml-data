<?php

declare(strict_types=1);

namespace AML\Data\Diagnostics;

final readonly class SensitiveDataRedactor
{
    /** @param list<string> $patterns */
    public function __construct(private array $patterns = ['password', 'passwd', 'secret', 'token', 'api_key', 'authorization', 'cookie'])
    {
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @return array<array-key, mixed>
     */
    public function redact(array $parameters): array
    {
        $redacted = [];
        foreach ($parameters as $key => $value) {
            $name = strtolower(ltrim((string) $key, ':'));
            $sensitive = false;
            if (is_string($key)) {
                foreach ($this->patterns as $pattern) {
                    if (str_contains($name, $pattern)) { $sensitive = true; break; }
                }
            }
            $redacted[$key] = $sensitive ? '[redacted]' : (is_array($value) ? $this->redact($value) : $value);
        }
        return $redacted;
    }
}
