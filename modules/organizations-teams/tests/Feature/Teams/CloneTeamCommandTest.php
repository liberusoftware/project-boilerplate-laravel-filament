<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Organizations\Models\Team;
use Tests\TestCase;

class CloneTeamCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_clones_configured_data_into_a_new_team(): void
    {
        Schema::create('clone_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('name');
        });
        config(['organizations-teams.clone.models' => ['clone_templates']]);

        $source = Team::factory()->create();
        DB::table('clone_templates')->insert(['team_id' => $source->id, 'name' => 'Starter']);
        $owner = User::factory()->create();

        $this->artisan('team:clone', [
            'source' => $source->id,
            '--name' => 'Fresh Team',
            '--owner' => $owner->id,
        ])->assertSuccessful();

        $new = Team::where('name', 'Fresh Team')->first();
        $this->assertNotNull($new);
        $this->assertSame($owner->id, $new->user_id);
        $this->assertSame(1, DB::table('clone_templates')->where('team_id', $new->id)->count());
    }

    public function test_command_fails_for_unknown_source(): void
    {
        $this->artisan('team:clone', ['source' => 999999])->assertFailed();
    }
}
