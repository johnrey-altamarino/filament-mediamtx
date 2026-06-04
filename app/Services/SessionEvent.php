<?php

namespace App\Services;

final class SessionEvent
{
    public function __construct(
        public readonly string $kind,
        public readonly string $sessionId,
        public readonly string $path,
        public readonly string $ip,
        public readonly string $state,
        public readonly string $occurredAt,
        public readonly string $type = '',
        public readonly string $referer = '',
    ) {}
}
