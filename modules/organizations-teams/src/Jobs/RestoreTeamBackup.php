<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Organizations\Contracts\TeamJobNotifier;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Services\TeamRestoreService;
use Throwable;

/**
 * Restores a team's data off the request cycle and reports the outcome to the
 * initiating super admin via a database notification. Not TenantAware — writes
 * unscoped by design.
 */
class RestoreTeamBackup implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $backupId, public ?int $notifyUserId = null) {}

    public function handle(TeamRestoreService $service): void
    {
        $backup = TeamBackup::findOrFail($this->backupId);

        try {
            $counts = $service->restore($backup);
            $this->notify('Restore complete', true, 'Restored '.array_sum($counts)." rows into team {$backup->team_id}.");
        } catch (Throwable $e) {
            $this->notify('Restore failed', false, $e->getMessage());
            throw $e;
        }
    }

    private function notify(string $title, bool $success, string $body): void
    {
        if ($this->notifyUserId === null) {
            return;
        }

        $userModel = Jetstream::userModel();
        $user = $userModel::find($this->notifyUserId);
        if (! $user) {
            return;
        }

        app(TeamJobNotifier::class)->notify($user, $title, $body, $success);
    }
}
