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
use Liberu\Foundation\Organizations\Tests\Fixtures\TenantItem;
use Tests\TestCase;

class ImportTeamCommandTest extends TestCase
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

    public function test_command_imports_a_zip_into_a_new_team(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $source = Team::factory()->create();
        TenantItem::withoutGlobalScopes()->create(['team_id' => $source->id, 'name' => 'Sample']);

        $result = (new TeamBackupService())->backup($source, 'local');
        TenantItem::withoutGlobalScopes()->where('team_id', $source->id)->delete();
        $path = Storage::disk('local')->path($result['path']);

        $this->artisan('team:import', ['path' => $path, '--name' => 'Imported', '--owner' => $owner->id])
            ->assertSuccessful();

        $new = Team::where('name', 'Imported')->first();
        $this->assertNotNull($new);
        $this->assertSame($owner->id, $new->user_id);
        $this->assertSame(1, TenantItem::withoutGlobalScopes()->where('team_id', $new->id)->count());
    }

    public function test_command_fails_for_a_missing_file(): void
    {
        $this->artisan('team:import', ['path' => '/no/such/file.zip'])->assertFailed();
    }
}
