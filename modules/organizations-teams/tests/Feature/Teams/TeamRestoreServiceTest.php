<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Tests\Feature\Teams;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Exceptions\TeamNotEmptyException;
use Liberu\Foundation\Organizations\Exceptions\TeamRestoreException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamRestoreService;
use Liberu\Foundation\Organizations\Support\TenantModels;
use Liberu\Foundation\Organizations\Tests\Fixtures\TenantChildItem;
use Liberu\Foundation\Organizations\Tests\Fixtures\TenantItem;
use Tests\TestCase;
use Throwable;
use ZipArchive;

class TeamRestoreServiceTest extends TestCase
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
            $table->string('title')->default('Child');
            $table->timestamps();
        });

        TenantModels::useModels([TenantItem::class, TenantChildItem::class]);
    }

    protected function tearDown(): void
    {
        TenantModels::useModels(null);
        parent::tearDown();
    }

    private function backupOf(Team $team): TeamBackup
    {
        $result = (new TeamBackupService())->backup($team, 'local');

        return TeamBackup::factory()->create([
            'team_id' => $team->id,
            'disk' => 'local',
            'path' => $result['path'],
            'size_bytes' => $result['size'],
            'status' => 'completed',
        ]);
    }

    public function test_round_trips_rows_with_original_ids_and_fks(): void
    {
        Storage::fake('local');
        $team = Team::factory()->create();
        $item = TenantItem::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => 'RoundTrip Co']);
        $child = TenantChildItem::withoutGlobalScopes()->create(['team_id' => $team->id, 'org_test_item_id' => $item->id]);

        $backup = $this->backupOf($team);

        TenantChildItem::withoutGlobalScopes()->where('team_id', $team->id)->delete();
        TenantItem::withoutGlobalScopes()->where('team_id', $team->id)->delete();
        $this->assertDatabaseMissing('org_test_items', ['id' => $item->id]);

        $restored = (new TeamRestoreService())->restore($backup);

        $this->assertDatabaseHas('org_test_items', ['id' => $item->id, 'name' => 'RoundTrip Co', 'team_id' => $team->id]);
        $this->assertDatabaseHas('org_test_child_items', ['id' => $child->id, 'org_test_item_id' => $item->id]);
        $this->assertSame(1, $restored['TenantItem']);
    }

    public function test_refuses_to_restore_over_a_non_empty_team(): void
    {
        Storage::fake('local');
        $team = Team::factory()->create();
        TenantItem::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => 'Existing']);
        $backup = $this->backupOf($team);

        $this->expectException(TeamNotEmptyException::class);
        (new TeamRestoreService())->restore($backup);
    }

    public function test_rejects_a_non_completed_backup(): void
    {
        $team = Team::factory()->create();
        $backup = TeamBackup::factory()->create(['team_id' => $team->id, 'status' => 'pending']);

        $this->expectException(TeamRestoreException::class);
        (new TeamRestoreService())->restore($backup);
    }

    public function test_rejects_when_the_backup_file_is_missing(): void
    {
        Storage::fake('local');
        $team = Team::factory()->create();
        $backup = TeamBackup::factory()->create([
            'team_id' => $team->id,
            'disk' => 'local',
            'path' => 'backups/gone.zip',
            'status' => 'completed',
        ]);

        $this->expectException(TeamRestoreException::class);
        (new TeamRestoreService())->restore($backup);
    }

    public function test_restore_is_atomic_on_failure(): void
    {
        Storage::fake('local');
        $team = Team::factory()->create();
        $item = TenantItem::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => 'Item']);
        $backup = $this->backupOf($team);

        $full = Storage::disk('local')->path((string) $backup->path);
        $zip = new ZipArchive();
        $zip->open($full);
        $zip->addFromString('models/TenantChildItem.json', (string) json_encode([['team_id' => $team->id, 'bogus_col' => 1]]));
        $zip->close();

        TenantItem::withoutGlobalScopes()->where('team_id', $team->id)->delete();

        try {
            (new TeamRestoreService())->restore($backup);
            $this->fail('expected the restore to throw');
        } catch (Throwable) {
            // expected
        }

        $this->assertDatabaseMissing('org_test_items', ['id' => $item->id]);
        $this->assertSame(0, TenantChildItem::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }
}
