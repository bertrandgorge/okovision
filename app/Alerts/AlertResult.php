<?php

namespace App\Alerts;

final class AlertResult
{
    public function __construct(
        public readonly bool $triggered,
        public readonly string $title = '',
        public readonly string $message = '',
        public readonly array $context = [],
    ) {}

    public static function ok(array $context = []): self
    {
        return new self(false, context: $context);
    }
}
