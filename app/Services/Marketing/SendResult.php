<?php

namespace App\Services\Marketing;

/**
 * Tek bir kanal gönderiminin sonucu (driver → orchestrator).
 */
class SendResult
{
    public function __construct(
        public bool $success,
        public ?string $providerMessageId = null,
        public ?string $provider = null,
        public ?string $error = null,
        public ?float $cost = null,
    ) {}

    public static function ok(?string $providerMessageId = null, ?string $provider = null, ?float $cost = null): self
    {
        return new self(true, $providerMessageId, $provider, null, $cost);
    }

    public static function fail(string $error, ?string $provider = null): self
    {
        return new self(false, null, $provider, $error, null);
    }
}
