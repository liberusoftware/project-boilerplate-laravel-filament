<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Console\Commands;

use Illuminate\Console\Command;
use Liberu\Foundation\Organizations\Jobs\GenerateTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;

class BackupTeam extends Command
{
    protected $signature = 'team:backup {team : The team id}';

    protected $description = 'Queue a full JSON data-export backup of a team.';

    public function handle(): int
    {
        $id = (int) $this->argument('team');
        $team = Team::withoutGlobalScope('archived')->find($id);

        if (! $team) {
            $this->error("Team {$id} not found.");

            return self::FAILURE;
        }

        $backup = TeamBackup::create([
            'team_id' => $team->id,
            'disk' => (string) config('filesystems.default', 'local'),
            'status' => 'pending',
        ]);

        GenerateTeamBackup::dispatch($backup->id);

        $this->info("Queued backup #{$backup->id} for team {$team->id}.");

        return self::SUCCESS;
    }
}
