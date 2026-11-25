<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Jobs\RestoreTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamRestoreService;
use Liberu\Foundation\Organizations\Support\TenantModels;
use Liberu\Foundation\Organizations\Tests\Fixtures\TenantItem;
use Tests\TestCase;
use Throwable;

class RestoreTeamBackupJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('org_test_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('name');
            $table->timestamps();
        });

        TenantModels::useModels([TenantItem::class]);
    }

    protected function tearDown(): void
    {
        TenantModels::useModels(null);
        parent::tearDown();
    }

    public function test_job_restores_and_notifies_the_initiator(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $team = Team::factory()->create();
        $item = TenantItem::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => 'RestoreMe']);

        $result = (new TeamBackupService())->backup($team, 'local');
        $backup = TeamBackup::factory()->create([
            'team_id' => $team->id,
            'disk' => 'local',
            'path' => $result['path'],
            'status' => 'completed',
        ]);
        TenantItem::withoutGlobalScopes()->where('team_id', $team->id)->delete();

        (new RestoreTeamBackup($backup->id, $admin->id))->handle(app(TeamRestoreService::class));

        $this->assertDatabaseHas('org_test_items', ['id' => $item->id]);
        $this->assertSame(1, $admin->fresh()->notifications()->count());
    }

    public function test_job_rethrows_on_failure(): void
    {
        $team = Team::factory()->create();
        $backup = TeamBackup::factory()->create(['team_id' => $team->id, 'status' => 'pending']);

        $this->expectException(Throwable::class);
        (new RestoreTeamBackup($backup->id))->handle(app(TeamRestoreService::class));
    }
}
