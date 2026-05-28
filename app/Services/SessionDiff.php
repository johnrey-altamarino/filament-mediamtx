<?php

namespace App\Services;

final class SessionDiff
{
    /**
     * @param  SessionEvent[]  $started
     * @param  SessionEvent[]  $ended
     */
    public function __construct(
        public readonly array $started,
        public readonly array $ended,
    ) {}

    public static function empty(): self
    {
        return new self([], []);
    }
}
