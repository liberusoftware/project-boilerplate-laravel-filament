<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;

/**
 * @extends Factory<TeamBackup>
 */
class TeamBackupFactory extends Factory
{
    protected $model = TeamBackup::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'disk' => 'local',
            'path' => null,
            'size_bytes' => null,
            'status' => 'pending',
            'error' => null,
            'created_by' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'completed',
            'path' => 'backups/team-backup.zip',
            'size_bytes' => 2048,
        ]);
    }
}
