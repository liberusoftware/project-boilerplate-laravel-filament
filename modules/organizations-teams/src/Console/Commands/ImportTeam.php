<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Organizations\Services\ImportTeamBackupService;

class ImportTeam extends Command
{
    protected $signature = 'team:import {path : Local path to a backup zip} {--name= : New team name} {--owner= : Importer/owner user id}';

    protected $description = 'Import a backup zip from another environment into a new team.';

    public function handle(ImportTeamBackupService $service): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $userModel = Jetstream::userModel();
        $owner = $this->option('owner') !== null
            ? $userModel::find((int) $this->option('owner'))
            : $userModel::query()->orderBy('id')->first();

        if (! $owner) {
            $this->error('No owner user resolved — pass --owner.');

            return self::FAILURE;
        }

        $team = $service->import($path, $this->option('name') ?: null, $owner);

        $this->info("Imported into new team {$team->id} ({$team->name}), owned by user {$owner->id}.");

        return self::SUCCESS;
    }
}
