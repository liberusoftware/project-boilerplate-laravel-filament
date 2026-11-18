<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Jobs\ImportTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\ImportTeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Tests\TestCase;
use Throwable;

class ImportTeamBackupJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_imports_a_stored_zip_and_notifies(): void
    {
        Storage::fake('local');
        $importer = User::factory()->create();
        $source = Team::factory()->create();
        Contact::factory()->create(['team_id' => $source->id]);

        $result = (new TeamBackupService())->backup($source, 'local');
        Contact::withoutGlobalScope('tenant')->where('team_id', $source->id)->delete();

        (new ImportTeamBackup('local', $result['path'], 'Imported', $importer->id))
            ->handle(app(ImportTeamBackupService::class));

        $new = Team::where('name', 'Imported')->first();
        $this->assertNotNull($new);
        $this->assertSame(1, Contact::withoutGlobalScope('tenant')->where('team_id', $new->id)->count());
        $this->assertSame(1, $importer->fresh()->notifications()->count());
    }

    public function test_job_rethrows_on_failure(): void
    {
        Storage::fake('local');
        $importer = User::factory()->create();
        Storage::disk('local')->put('imports/bad.zip', 'not a zip');

        $this->expectException(Throwable::class);
        (new ImportTeamBackup('local', 'imports/bad.zip', 'X', $importer->id))
            ->handle(app(ImportTeamBackupService::class));
    }
}
