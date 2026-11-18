<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Console\Commands;

use Illuminate\Console\Command;
use Liberu\Foundation\Organizations\Jobs\RestoreTeamBackup;
use Liberu\Foundation\Organizations\Models\TeamBackup;

class RestoreTeam extends Command
{
    protected $signature = 'team:restore {backup : The team_backups id}';

    protected $description = 'Restore a team\'s data from one of its completed backups (same-team recovery).';

    public function handle(): int
    {
        $id = (int) $this->argument('backup');
        $backup = TeamBackup::find($id);

        if (! $backup) {
            $this->error("Backup {$id} not found.");

            return self::FAILURE;
        }

        RestoreTeamBackup::dispatch($backup->id);

        $this->info("Queued restore from backup #{$backup->id} into team {$backup->team_id}.");

        return self::SUCCESS;
    }
}
