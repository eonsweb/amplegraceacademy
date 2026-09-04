<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SystemSettingsChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changedKeys
     */
    public function __construct(
        public readonly string $action,
        public readonly int $actorId,
        public readonly string $group,
        public readonly array $changedKeys,
    ) {}
}
