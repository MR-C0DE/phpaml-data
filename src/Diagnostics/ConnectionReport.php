<?php

declare(strict_types=1);

namespace AML\Data\Diagnostics;

final readonly class ConnectionReport
{
    /** @param array<string, bool> $capabilities */
    public function __construct(
        public string $driver,
        public bool $extensionAvailable,
        public bool $connected,
        public ?string $serverVersion,
        public array $capabilities,
        public ?string $error = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'extension_available' => $this->extensionAvailable,
            'connected' => $this->connected,
            'server_version' => $this->serverVersion,
            'capabilities' => $this->capabilities,
            'error' => $this->error,
        ];
    }
}
