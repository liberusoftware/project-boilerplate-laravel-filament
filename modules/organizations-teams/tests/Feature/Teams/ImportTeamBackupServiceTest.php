<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Exceptions\TeamImportException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\ImportTeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Liberu\Foundation\Organizations\Support\TenantModels;
use Liberu\Foundation\Organizations\Tests\Fixtures\TenantChildItem;
use Liberu\Foundation\Organizations\Tests\Fixtures\TenantItem;
use Tests\TestCase;
use ZipArchive;

class ImportTeamBackupServiceTest extends TestCase
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
        Schema::create('org_test_child_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('org_test_item_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('title')->default('Child');
            $table->timestamps();
            $table->foreign('org_test_item_id')->references('id')->on('org_test_items')->nullOnDelete();
        });

        TenantModels::useModels([TenantItem::class, TenantChildItem::class]);
    }

    protected function tearDown(): void
    {
        TenantModels::useModels(null);
        parent::tearDown();
    }

    private function backupThenClear(Team $source): string
    {
        $result = (new TeamBackupService())->backup($source, 'local');

        TenantChildItem::withoutGlobalScopes()->where('team_id', $source->id)->delete();
        TenantItem::withoutGlobalScopes()->where('team_id', $source->id)->delete();

        return Storage::disk('local')->path($result['path']);
    }

    public function test_imports_a_backup_into_a_new_team_remapping_fks_and_users(): void
    {
        Storage::fake('local');
        $importer = User::factory()->create();
        $assignee = User::factory()->create();

        $source = Team::factory()->create();
        $item = TenantItem::withoutGlobalScopes()->create(['team_id' => $source->id, 'name' => 'CrossEnv Co']);
        TenantChildItem::withoutGlobalScopes()->create([
            'team_id' => $source->id,
            'org_test_item_id' => $item->id,
            'assigned_to' => $assignee->id,
        ]);

        $localZip = $this->backupThenClear($source);

        $new = (new ImportTeamBackupService())->import($localZip, 'Imported Team', $importer);

        $this->assertNotSame($source->id, $new->id);
        $this->assertSame($importer->id, $new->user_id);

        $newItem = DB::table('org_test_items')->where('team_id', $new->id)->first();
        $newChild = DB::table('org_test_child_items')->where('team_id', $new->id)->first();
        $this->assertNotNull($newItem);
        $this->assertNotNull($newChild);
        $this->assertSame('CrossEnv Co', $newItem->name);

        $this->assertNotSame($item->id, (int) $newItem->id);
        $this->assertSame((int) $newItem->id, (int) $newChild->org_test_item_id);
        $this->assertSame($importer->id, (int) $newChild->assigned_to);
    }

    public function test_import_is_atomic_and_leaves_no_orphan_team_on_failure(): void
    {
        Storage::fake('local');
        $importer = User::factory()->create();
        $source = Team::factory()->create();
        TenantItem::withoutGlobalScopes()->create(['team_id' => $source->id, 'name' => 'CorruptMe']);

        $result = (new TeamBackupService())->backup($source, 'local');
        $zip = new ZipArchive();
        $zip->open(Storage::disk('local')->path($result['path']));
        $items = json_decode((string) $zip->getFromName('models/TenantItem.json'), true, flags: JSON_THROW_ON_ERROR);
        $items[0]['invalid_column'] = 'forces rollback';
        $zip->deleteName('models/TenantItem.json');
        $zip->addFromString('models/TenantItem.json', json_encode($items, JSON_THROW_ON_ERROR));
        $zip->close();
        $teamsBefore = DB::table('teams')->count();

        try {
            (new ImportTeamBackupService())->import(Storage::disk('local')->path($result['path']), 'X', $importer);
            $this->fail('expected an insert failure');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($teamsBefore, DB::table('teams')->count());
    }

    public function test_rejects_a_bad_archive(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('bad.zip', 'not a zip');
        $importer = User::factory()->create();

        $this->expectException(TeamImportException::class);
        (new ImportTeamBackupService())->import(Storage::disk('local')->path('bad.zip'), 'X', $importer);
    }

    public function test_rejects_wrong_format_version(): void
    {
        Storage::fake('local');
        $importer = User::factory()->create();
        $path = Storage::disk('local')->path('v2.zip');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode(['format_version' => 99, 'team' => ['id' => 1, 'name' => 'x']]));
        $zip->close();

        $this->expectException(TeamImportException::class);
        (new ImportTeamBackupService())->import($path, 'X', $importer);
    }
}
