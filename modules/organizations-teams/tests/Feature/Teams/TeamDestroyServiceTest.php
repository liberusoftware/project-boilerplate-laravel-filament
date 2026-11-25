<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Tests\Feature\Teams;

use App\Models\User;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Services\TeamDestroyService;
use Liberu\Foundation\Organizations\Support\TenantModels;
use Liberu\Foundation\Organizations\Tests\Fixtures\TenantItem;
use Tests\TestCase;

class TeamDestroyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('org_test_items')) {
            Schema::create('org_test_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('team_id');
                $table->string('name');
                $table->timestamps();
            });
        }

        TenantModels::useModels([TenantItem::class]);
    }

    protected function tearDown(): void
    {
        TenantModels::useModels(null);
        parent::tearDown();
    }

    public function test_refuses_to_destroy_personal_team_by_default(): void
    {
        $team = Team::factory()->create(['personal_team' => true]);

        $this->expectException(DomainException::class);
        (new TeamDestroyService())->destroy($team);
    }

    public function test_destroys_team_tenant_rows_backups_and_reassigns_users(): void
    {
        Storage::fake('local');

        $user = User::factory()->withPersonalTeam()->create();
        $personal = $user->personalTeam();

        $team = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false]);
        $otherTeam = Team::factory()->create(['personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        DB::table('org_test_items')->insert([
            ['team_id' => $team->id, 'name' => 'Victim'],
            ['team_id' => $otherTeam->id, 'name' => 'Survivor'],
        ]);

        Storage::disk('local')->put('backups/team-to-delete.zip', 'zip-data');
        TeamBackup::factory()->create([
            'team_id' => $team->id,
            'disk' => 'local',
            'path' => 'backups/team-to-delete.zip',
            'status' => 'completed',
        ]);

        $deleted = (new TeamDestroyService())->destroy($team);

        $this->assertSame(1, $deleted['org_test_items'] ?? 0);
        $this->assertSame(1, $deleted['teams'] ?? 0);
        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
        $this->assertDatabaseMissing('org_test_items', ['team_id' => $team->id]);
        $this->assertDatabaseHas('org_test_items', ['team_id' => $otherTeam->id, 'name' => 'Survivor']);
        $this->assertSame($personal->id, $user->fresh()->current_team_id);
        Storage::disk('local')->assertMissing('backups/team-to-delete.zip');
    }
}
