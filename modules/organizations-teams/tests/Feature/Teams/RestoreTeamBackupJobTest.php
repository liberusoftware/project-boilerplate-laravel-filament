<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Jobs\RestoreTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamRestoreService;
use Tests\TestCase;
use Throwable;

class RestoreTeamBackupJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_restores_and_notifies_the_initiator(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $team = Team::factory()->create();
        $contact = Contact::factory()->create(['team_id' => $team->id]);

        $result = (new TeamBackupService())->backup($team, 'local');
        $backup = TeamBackup::factory()->create([
            'team_id' => $team->id, 'disk' => 'local', 'path' => $result['path'], 'status' => 'completed',
        ]);
        Contact::withoutGlobalScope('tenant')->where('team_id', $team->id)->delete();

        (new RestoreTeamBackup($backup->id, $admin->id))->handle(app(TeamRestoreService::class));

        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
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
