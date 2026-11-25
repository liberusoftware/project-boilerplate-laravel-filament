<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Jobs\ImportTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\ImportTeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Liberu\Foundation\Organizations\Support\TenantModels;
use Liberu\Foundation\Organizations\Tests\Fixtures\TenantItem;
use Tests\TestCase;
use Throwable;

class ImportTeamBackupJobTest extends TestCase
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

    public function test_job_imports_a_stored_zip_and_notifies(): void
    {
        Storage::fake('local');
        $importer = User::factory()->create();
        $source = Team::factory()->create();
        TenantItem::withoutGlobalScopes()->create(['team_id' => $source->id, 'name' => 'Sample']);

        $result = (new TeamBackupService())->backup($source, 'local');
        TenantItem::withoutGlobalScopes()->where('team_id', $source->id)->delete();

        (new ImportTeamBackup('local', $result['path'], 'Imported', $importer->id))
            ->handle(app(ImportTeamBackupService::class));

        $new = Team::where('name', 'Imported')->first();
        $this->assertNotNull($new);
        $this->assertSame(1, TenantItem::withoutGlobalScopes()->where('team_id', $new->id)->count());
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
