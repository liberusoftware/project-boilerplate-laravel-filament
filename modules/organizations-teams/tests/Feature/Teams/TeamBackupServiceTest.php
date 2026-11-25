<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Liberu\Foundation\Organizations\Support\TenantModels;
use Liberu\Foundation\Organizations\Tests\Fixtures\TenantChildItem;
use Liberu\Foundation\Organizations\Tests\Fixtures\TenantItem;
use Tests\TestCase;
use ZipArchive;

class TeamBackupServiceTest extends TestCase
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

    private function readEntry(string $path, string $entry): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($path)) === true);
        $contents = $zip->getFromName($entry);
        $zip->close();
        $this->assertNotFalse($contents, "zip entry `{$entry}` is missing");

        return $contents;
    }

    public function test_backup_produces_a_zip_with_model_json_and_manifest(): void
    {
        Storage::fake('local');
        $team = Team::factory()->create();
        TenantItem::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => 'Acme Record']);

        $result = (new TeamBackupService())->backup($team, 'local');

        Storage::disk('local')->assertExists($result['path']);
        $this->assertGreaterThan(0, $result['size']);

        $manifest = json_decode($this->readEntry($result['path'], 'manifest.json'), true);
        $this->assertSame($team->id, $manifest['team']['id']);
        $this->assertSame(1, $manifest['models']['TenantItem']);

        $this->assertStringContainsString('Acme Record', $this->readEntry($result['path'], 'models/TenantItem.json'));
    }

    public function test_backup_contains_only_the_target_teams_rows(): void
    {
        Storage::fake('local');
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        TenantItem::withoutGlobalScopes()->create(['team_id' => $teamA->id, 'name' => 'Mine Record']);
        TenantItem::withoutGlobalScopes()->create(['team_id' => $teamB->id, 'name' => 'Other Record']);
        TenantChildItem::withoutGlobalScopes()->create(['team_id' => $teamB->id, 'title' => 'Other Child']);

        $result = (new TeamBackupService())->backup($teamA, 'local');

        $items = $this->readEntry($result['path'], 'models/TenantItem.json');
        $this->assertStringContainsString('Mine Record', $items);
        $this->assertStringNotContainsString('Other Record', $items);

        $manifest = json_decode($this->readEntry($result['path'], 'manifest.json'), true);
        $this->assertSame(1, $manifest['models']['TenantItem']);
    }

    public function test_backup_includes_team_membership_extras(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'member']);

        $result = (new TeamBackupService())->backup($team, 'local');

        $manifest = json_decode($this->readEntry($result['path'], 'manifest.json'), true);
        $this->assertSame(1, $manifest['extras']['team_user']);
        $this->assertStringContainsString((string) $member->id, $this->readEntry($result['path'], 'extras/team_user.json'));
    }
}
