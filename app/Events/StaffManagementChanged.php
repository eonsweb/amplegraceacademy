<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class StaffManagementChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<string, mixed> $changes */
    public function __construct(
        public readonly string $action,
        public readonly int $actorId,
        public readonly int $staffId,
        public readonly array $changes = [],
    ) {}
}
