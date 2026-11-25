<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Console\Commands;

use DomainException;
use Illuminate\Console\Command;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamDestroyService;

class DestroyTeam extends Command
{
    protected $signature = 'team:destroy {team : The team id} {--allow-personal : Allow destroying a personal team}';

    protected $description = 'Permanently destroy a team and all of its tenant-scoped data.';

    public function handle(TeamDestroyService $service): int
    {
        $id = (int) $this->argument('team');
        $team = Team::withoutGlobalScope('archived')->find($id);

        if (! $team) {
            $this->error("Team {$id} not found.");

            return self::FAILURE;
        }

        try {
            $deleted = $service->destroy($team, (bool) $this->option('allow-personal'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $total = array_sum($deleted);
        $this->info("Destroyed team {$id} ({$team->name}); removed {$total} rows.");

        return self::SUCCESS;
    }
}
