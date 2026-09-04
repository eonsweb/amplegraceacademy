<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AuthorizationChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        public readonly string $action,
        public readonly int $actorId,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly array $changes = [],
    ) {}
}
