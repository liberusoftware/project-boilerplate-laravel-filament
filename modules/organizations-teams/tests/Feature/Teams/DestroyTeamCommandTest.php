<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Organizations\Models\Team;
use Tests\TestCase;

class DestroyTeamCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_destroys_a_non_personal_team(): void
    {
        $team = Team::factory()->create(['personal_team' => false]);

        $this->artisan('team:destroy', ['team' => $team->id])->assertSuccessful();

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    public function test_command_refuses_personal_team_without_flag(): void
    {
        $team = Team::factory()->create(['personal_team' => true]);

        $this->artisan('team:destroy', ['team' => $team->id])->assertFailed();
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    public function test_command_fails_for_unknown_team(): void
    {
        $this->artisan('team:destroy', ['team' => 999999])->assertFailed();
    }
}
