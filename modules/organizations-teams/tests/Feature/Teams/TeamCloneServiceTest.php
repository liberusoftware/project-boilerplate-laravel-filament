<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamCloneService;
use Tests\TestCase;

class TeamCloneServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('clone_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('name');
        });
        Schema::create('clone_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('name');
            $table->foreign('group_id')->references('id')->on('clone_groups')->nullOnDelete();
        });
        Schema::create('clone_nodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
        });
        Schema::create('clone_labels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('name');
        });
        Schema::create('transactional_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('title');
        });

        config([
            'organizations-teams.clone.models' => [
                'clone_groups',
                'clone_items',
                'clone_nodes',
                'clone_labels',
            ],
            'organizations-teams.clone.fk_edges' => [
                'clone_nodes' => ['parent_id' => 'clone_nodes'],
            ],
        ]);
    }

    /**
     * @return array{Team, int, int}
     */
    private function seedSource(): array
    {
        $source = Team::factory()->create();

        $groupId = DB::table('clone_groups')->insertGetId(['team_id' => $source->id, 'name' => 'Default']);
        $itemId = DB::table('clone_items')->insertGetId(['team_id' => $source->id, 'group_id' => $groupId, 'name' => 'Step 1']);

        $parentId = DB::table('clone_nodes')->insertGetId(['team_id' => $source->id, 'parent_id' => null, 'name' => 'Root']);
        DB::table('clone_nodes')->insert(['team_id' => $source->id, 'parent_id' => $parentId, 'name' => 'Child']);

        DB::table('clone_labels')->insert(['team_id' => $source->id, 'name' => 'VIP']);

        return [$source, $groupId, $itemId];
    }

    public function test_clones_configured_tables_and_remaps_declared_and_explicit_foreign_keys(): void
    {
        [$source, $sourceGroupId] = $this->seedSource();
        $owner = User::factory()->create();

        $new = (new TeamCloneService())->clone($source, 'Cloned Team', $owner);

        $this->assertSame('Cloned Team', $new->name);
        $this->assertSame($owner->id, $new->user_id);
        $this->assertFalse((bool) $new->personal_team);
        $this->assertNotSame($source->id, $new->id);

        $newGroup = DB::table('clone_groups')->where('team_id', $new->id)->first();
        $newItem = DB::table('clone_items')->where('team_id', $new->id)->first();

        // Declared FK (clone_items.group_id -> clone_groups.id) remapped automatically via SchemaGraph.
        $this->assertNotSame($sourceGroupId, (int) $newGroup->id);
        $this->assertSame((int) $newGroup->id, (int) $newItem->group_id);

        // Self-referential explicit FK edge remapped to the cloned parent.
        $newParent = DB::table('clone_nodes')->where('team_id', $new->id)->whereNull('parent_id')->first();
        $newChild = DB::table('clone_nodes')->where('team_id', $new->id)->whereNotNull('parent_id')->first();
        $this->assertSame((int) $newParent->id, (int) $newChild->parent_id);

        // Flat config table carried over.
        $this->assertDatabaseHas('clone_labels', ['team_id' => $new->id, 'name' => 'VIP']);
    }

    public function test_does_not_clone_unconfigured_transactional_data(): void
    {
        [$source] = $this->seedSource();
        DB::table('transactional_entries')->insert(['team_id' => $source->id, 'title' => 'Private']);
        $owner = User::factory()->create();

        $new = (new TeamCloneService())->clone($source, 'No Data', $owner);

        $this->assertSame(0, DB::table('transactional_entries')->where('team_id', $new->id)->count());
    }

    public function test_source_team_is_untouched(): void
    {
        [$source, $groupId, $itemId] = $this->seedSource();
        $owner = User::factory()->create();

        (new TeamCloneService())->clone($source, 'Clone', $owner);

        $this->assertSame(1, DB::table('clone_groups')->where('team_id', $source->id)->count());
        $this->assertSame($groupId, (int) DB::table('clone_items')->where('id', $itemId)->first()->group_id);
    }
}
