<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyTeamMembers implements ShouldQueue
{
    public function handle(object $event): void
    {
        // Host applications may extend or replace this listener to notify team members.
    }
}
