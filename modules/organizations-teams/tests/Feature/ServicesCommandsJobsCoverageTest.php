<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Console\Commands\BackupTeam;
use Liberu\Foundation\Organizations\Console\Commands\CloneTeam;
use Liberu\Foundation\Organizations\Console\Commands\ImportTeam;
use Liberu\Foundation\Organizations\Console\Commands\RestoreTeam;
use Liberu\Foundation\Organizations\Contracts\TeamAuditLogger;
use Liberu\Foundation\Organizations\Exceptions\TeamImportException;
use Liberu\Foundation\Organizations\Exceptions\TeamNotEmptyException;
use Liberu\Foundation\Organizations\Exceptions\TeamRestoreException;
use Liberu\Foundation\Organizations\Jobs\GenerateTeamBackup;
use Liberu\Foundation\Organizations\Jobs\ImportTeamBackup;
use Liberu\Foundation\Organizations\Jobs\RestoreTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Services\CurrentTeamResolver;
use Liberu\Foundation\Organizations\Services\ImportTeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamCloneService;
use Liberu\Foundation\Organizations\Services\TeamManagementService;
use Liberu\Foundation\Organizations\Services\TeamRestoreService;
use Liberu\Foundation\Organizations\Support\SchemaGraph;
use Liberu\Foundation\Organizations\Tests\Fixtures\OrgTestMenu;
use Liberu\Foundation\Organizations\Tests\Fixtures\OrgTestPipeline;
use Liberu\Foundation\Organizations\Tests\Fixtures\OrgTestStage;
use Liberu\Foundation\Organizations\Tests\Fixtures\OrgTestTenantItem;
use Liberu\Foundation\Organizations\Tests\Fixtures\OrgTestUser;
use Liberu\Foundation\Organizations\Tests\Fixtures\RecordingTeamAuditLogger;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Liberu\Foundation\RolesPermissions\Support\RolePermissionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

function createServiceUser(array $overrides = []): OrgTestUser
{
    static $seq = 0;
    $seq++;

    return OrgTestUser::forceCreate(array_merge([
        'name' => "Service User {$seq}",
        'email' => "svc-user-{$seq}-".uniqid().'@example.test',
        'password' => bcrypt('Password123!@#'),
    ], $overrides));
}

function createServiceTeam(OrgTestUser $owner, array $overrides = []): Team
{
    return Team::forceCreate(array_merge([
        'user_id' => $owner->id,
        'name' => 'Service Team '.uniqid(),
        'personal_team' => false,
    ], $overrides));
}

/**
 * Sets up a temporary base path containing OrgTestTenantItem so TenantModels::all()
 * discovers it, and creates its backing table.
 *
 * @return array{root: string, originalBase: string}
 */
function setupTenantBackupFixture(): array
{
    $originalBase = app()->make('path.base');
    $root = sys_get_temp_dir().'/org-backup-fixture-'.bin2hex(random_bytes(6));
    mkdir($root.'/app/Models', 0700, true);
    file_put_contents(
        $root.'/app/Models/OrgTestTenantItem.php',
        "<?php\nnamespace Liberu\\Foundation\\Organizations\\Tests\\Fixtures;\nclass OrgTestTenantItem {}\n",
    );
    file_put_contents(
        $root.'/app/Models/OrgTestMissingTableItem.php',
        "<?php\nnamespace Liberu\\Foundation\\Organizations\\Tests\\Fixtures;\nclass OrgTestMissingTableItem {}\n",
    );
    file_put_contents(
        $root.'/app/Models/OrgTestSimpleItem.php',
        "<?php\nnamespace Liberu\\Foundation\\Organizations\\Tests\\Fixtures;\nclass OrgTestSimpleItem {}\n",
    );

    app()->instance('path.base', $root);

    Schema::dropIfExists('org_test_simple_items');
    Schema::create('org_test_simple_items', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id');
        $table->string('name');
    });

    Schema::dropIfExists('org_test_tenant_items');
    Schema::create('org_test_tenant_items', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id');
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->unsignedBigInteger('assigned_to')->nullable();
        $table->unsignedBigInteger('org_test_tenant_item_id')->nullable();
        $table->string('name');
        $table->timestamps();
        $table->foreign('team_id')->references('id')->on('teams');
        $table->foreign('parent_id')->references('id')->on('org_test_tenant_items');
    });

    return ['root' => $root, 'originalBase' => $originalBase];
}

function teardownTenantBackupFixture(array $fixture): void
{
    app()->instance('path.base', $fixture['originalBase']);
    Schema::dropIfExists('org_test_tenant_items');
    Schema::dropIfExists('org_test_simple_items');
    (new Filesystem())->deleteDirectory($fixture['root']);
}

afterEach(function () {
    Auth::logout();
});

it('covers CurrentTeamResolver for null, active membership, time windows, owner, and outsider', function () {
    $owner = createServiceUser();
    $member = createServiceUser();
    $outsider = createServiceUser();
    $team = createServiceTeam($owner, ['status' => 'active']);

    $resolver = new CurrentTeamResolver();

    expect($resolver->resolve($owner, null))->toBeNull()
        ->and($resolver->resolve($owner, $team->id)?->id)->toBe($team->id)
        ->and($resolver->resolve($outsider, $team->id))->toBeNull();

    // Active membership within effective window
    DB::table('team_user')->insert([
        'team_id' => $team->id,
        'user_id' => $member->id,
        'role' => 'member',
        'status' => 'active',
        'effective_from' => now()->subDay(),
        'effective_until' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($resolver->resolve($member, $team->id)?->id)->toBe($team->id);

    // Expired membership
    DB::table('team_user')->where('team_id', $team->id)->where('user_id', $member->id)
        ->update(['effective_until' => now()->subMinute()]);
    expect($resolver->resolve($member, $team->id))->toBeNull();

    // Future membership
    DB::table('team_user')->where('team_id', $team->id)->where('user_id', $member->id)
        ->update(['effective_from' => now()->addDay(), 'effective_until' => null]);
    expect($resolver->resolve($member, $team->id))->toBeNull();
});

it('covers TeamManagementService member lifecycle, roles, custom roles, defaults, and switching', function () {
    $auditLogger = new RecordingTeamAuditLogger();
    app()->instance(TeamAuditLogger::class, $auditLogger);
    $service = new TeamManagementService($auditLogger);
    $owner = createServiceUser();
    $existing = createServiceUser();
    $outsider = createServiceUser();
    $team = createServiceTeam($owner);

    // Unauthorized guest
    expect(fn () => $service->createTeamMember($team, 'New', 'new@example.test', 'StrongPassword1!', Role::Manager))
        ->toThrow(InvalidArgumentException::class, 'You cannot manage members for this team.');

    Auth::login($owner);

    // Disallowed role
    expect(fn () => $service->createTeamMember($team, 'New', 'new@example.test', 'StrongPassword1!', Role::SuperAdmin))
        ->toThrow(InvalidArgumentException::class, 'That role cannot be assigned to a new team member.');

    // Duplicate email
    expect(fn () => $service->createTeamMember($team, 'Dup', $existing->email, 'StrongPassword1!', Role::Manager))
        ->toThrow(InvalidArgumentException::class, 'That email address is already registered.');

    // Short password
    expect(fn () => $service->createTeamMember($team, 'Short', 'short@example.test', 'short', Role::Manager))
        ->toThrow(InvalidArgumentException::class, 'Passwords must contain at least 12 characters.');

    // Valid createTeamMember
    $created = $service->createTeamMember($team, 'Alice Member', 'alice-new@example.test', 'StrongPassword123!', Role::Manager);
    expect($created->email)->toBe('alice-new@example.test')
        ->and($auditLogger->recorded)->not->toBeEmpty();

    // addTeamMember invalid role
    expect(fn () => $service->addTeamMember($existing, $team, Role::Customer))
        ->toThrow(InvalidArgumentException::class, 'That role cannot be assigned to a team member.');

    // addTeamMember valid
    $service->addTeamMember($existing, $team, Role::SalesRep);
    expect($existing->fresh()->belongsToTeam($team))->toBeTrue();

    // provisionTeamRole errors: non-member, disallowed role, owner
    expect(fn () => $service->provisionTeamRole($outsider, $team, Role::SalesRep))
        ->toThrow(InvalidArgumentException::class, 'The user is not a member of this team.');
    expect(fn () => $service->provisionTeamRole($existing, $team, Role::SuperAdmin))
        ->toThrow(InvalidArgumentException::class);

    // Owner cannot be changed via provisionTeamRole (even if attached to pivot)
    $team->users()->attach($owner, ['role' => 'admin']);
    expect(fn () => $service->provisionTeamRole($owner, $team, Role::Manager))
        ->toThrow(InvalidArgumentException::class, "The team owner's role cannot be changed.");

    // changeTeamRole valid & self-check error
    $service->changeTeamRole($existing, $team, Role::Free);
    expect(fn () => $service->changeTeamRole($owner, $team, Role::Manager))
        ->toThrow(InvalidArgumentException::class, 'Choose another member of this team.');

    // assignCustomRole
    $otherTeam = createServiceTeam($owner);
    $otherTeamRole = SpatieRole::create(['name' => 'other_custom', 'guard_name' => 'web', 'team_id' => $otherTeam->id]);
    expect(fn () => $service->assignCustomRole($existing, $team, $otherTeamRole))
        ->toThrow(InvalidArgumentException::class, 'The role does not belong to this team.');

    $systemNamedRoleId = DB::table('roles')->insertGetId([
        'name' => 'admin',
        'guard_name' => 'web',
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $systemNamedRole = SpatieRole::findOrFail($systemNamedRoleId);
    expect(fn () => $service->assignCustomRole($existing, $team, $systemNamedRole))
        ->toThrow(InvalidArgumentException::class, 'This custom role contains permissions that cannot be assigned by a team owner.');

    $grantable = array_keys(RolePermissionCatalog::options());
    $customRole = SpatieRole::create(['name' => 'custom_analyst', 'guard_name' => 'web', 'team_id' => $team->id]);
    if ($grantable !== []) {
        $perm = Permission::firstOrCreate(['name' => $grantable[0], 'guard_name' => 'web']);
        $customRole->givePermissionTo($perm);
    }
    $service->assignCustomRole($existing, $team, $customRole);
    setPermissionsTeamId($team->id);
    expect($existing->fresh()->hasRole('custom_analyst'))->toBeTrue();

    // removeTeamMember
    $service->removeTeamMember($existing, $team);
    expect($team->users()->whereKey($existing->id)->exists())->toBeFalse();

    // Owner cannot be removed (checked by a super_admin actor)
    $superAdmin = createServiceUser();
    setPermissionsTeamId(null);
    $saRole = SpatieRole::firstOrCreate(['name' => Role::SuperAdmin->value, 'guard_name' => 'web', 'team_id' => null]);
    $superAdmin->assignRole($saRole);
    Auth::login($superAdmin);
    expect(fn () => $service->removeTeamMember($owner, $team))
        ->toThrow(InvalidArgumentException::class, 'The team owner cannot be removed.');
    expect(fn () => $service->assignCustomRole($owner, $team, $customRole))
        ->toThrow(InvalidArgumentException::class, "The team owner's role cannot be changed.");

    // createPersonalTeamForUser
    $personal = $service->createPersonalTeamForUser($outsider);
    expect($personal->personal_team)->toBeTrue();

    // assignUserToDefaultTeam: assigns to existing non-personal team
    $newUser = createServiceUser();
    $service->assignUserToDefaultTeam($newUser);
    expect($newUser->fresh()->current_team_id)->not->toBeNull();

    // assignUserToDefaultTeam fallback when no non-personal teams & no branches exist
    Team::query()->where('personal_team', false)->delete();
    $fallbackUser = createServiceUser();
    $service->assignUserToDefaultTeam($fallbackUser);
    expect($fallbackUser->fresh()->currentTeam?->personal_team)->toBeTrue();

    // switchTeam success & failure
    $t1 = createServiceTeam($fallbackUser);
    $service->switchTeam($fallbackUser, $t1);
    expect($fallbackUser->fresh()->current_team_id)->toBe($t1->id);

    $unjoined = createServiceTeam($owner);
    expect(fn () => $service->switchTeam($fallbackUser, $unjoined))
        ->toThrow(Exception::class, 'User does not belong to the specified team.');
});

it('covers TeamBackupService, TeamRestoreService, and ImportTeamBackupService end-to-end and error paths', function () {
    Storage::fake('local');
    $fixture = setupTenantBackupFixture();

    try {
        $owner = createServiceUser();
        $importer = createServiceUser();
        $teamA = createServiceTeam($owner, ['name' => 'Source Team']);
        $teamB = createServiceTeam($owner, ['name' => 'Other Team']);
        $teamA->users()->attach($importer, ['role' => 'member']);

        $parentId = DB::table('org_test_tenant_items')->insertGetId([
            'team_id' => $teamA->id,
            'parent_id' => null,
            'assigned_to' => $owner->id,
            'org_test_tenant_item_id' => null,
            'name' => 'Parent Item',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $childId = DB::table('org_test_tenant_items')->insertGetId([
            'team_id' => $teamA->id,
            'parent_id' => $parentId,
            'assigned_to' => $owner->id,
            'org_test_tenant_item_id' => $parentId,
            'name' => 'Child Item',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('org_test_tenant_items')->insert([
            'team_id' => $teamB->id,
            'name' => 'Other Team Item',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('org_test_simple_items')->insert([
            'team_id' => $teamA->id,
            'name' => 'Simple Item',
        ]);

        // 1. Backup (also verify missing extra table is skipped cleanly)
        $backupService = new TeamBackupService();
        $result = $backupService->backup($teamA, 'local');
        Storage::disk('local')->assertExists($result['path']);
        expect($result['size'])->toBeGreaterThan(0);

        Schema::dropIfExists('team_invitations');
        $backupWithoutInvitations = $backupService->backup($teamA, 'local');
        expect($backupWithoutInvitations['size'])->toBeGreaterThan(0);

        $backup = TeamBackup::create([
            'team_id' => $teamA->id,
            'disk' => 'local',
            'path' => $result['path'],
            'size_bytes' => $result['size'],
            'status' => 'completed',
        ]);

        // 2. Restore error paths
        $restoreService = new TeamRestoreService();

        // Not completed
        $pendingBackup = TeamBackup::create(['team_id' => $teamA->id, 'disk' => 'local', 'status' => 'pending']);
        expect(fn () => $restoreService->restore($pendingBackup))->toThrow(TeamRestoreException::class);

        // Missing file
        $missingBackup = TeamBackup::create(['team_id' => $teamA->id, 'disk' => 'local', 'path' => 'backups/nope.zip', 'status' => 'completed']);
        expect(fn () => $restoreService->restore($missingBackup))->toThrow(TeamRestoreException::class);

        // Corrupt zip
        Storage::disk('local')->put('backups/corrupt.zip', 'not-a-zip');
        $corruptBackup = TeamBackup::create(['team_id' => $teamA->id, 'disk' => 'local', 'path' => 'backups/corrupt.zip', 'status' => 'completed']);
        expect(fn () => $restoreService->restore($corruptBackup))->toThrow(TeamRestoreException::class);

        // Missing manifest
        $noManifestPath = Storage::disk('local')->path('backups/no-manifest.zip');
        $z = new ZipArchive();
        $z->open($noManifestPath, ZipArchive::CREATE);
        $z->addFromString('dummy.txt', 'hi');
        $z->close();
        $noManifestBackup = TeamBackup::create(['team_id' => $teamA->id, 'disk' => 'local', 'path' => 'backups/no-manifest.zip', 'status' => 'completed']);
        expect(fn () => $restoreService->restore($noManifestBackup))->toThrow(TeamRestoreException::class, 'no manifest');

        // Wrong format version
        $badVerPath = Storage::disk('local')->path('backups/bad-ver.zip');
        $z2 = new ZipArchive();
        $z2->open($badVerPath, ZipArchive::CREATE);
        $z2->addFromString('manifest.json', json_encode(['format_version' => 99, 'team' => ['id' => $teamA->id]]));
        $z2->close();
        $badVerBackup = TeamBackup::create(['team_id' => $teamA->id, 'disk' => 'local', 'path' => 'backups/bad-ver.zip', 'status' => 'completed']);
        expect(fn () => $restoreService->restore($badVerBackup))->toThrow(TeamRestoreException::class, 'Unsupported');

        // Missing target team
        $missingTeamPath = Storage::disk('local')->path('backups/missing-team.zip');
        $z3 = new ZipArchive();
        $z3->open($missingTeamPath, ZipArchive::CREATE);
        $z3->addFromString('manifest.json', json_encode(['format_version' => 1, 'team' => ['id' => 999999]]));
        $z3->close();
        $missingTeamBackup = TeamBackup::create(['team_id' => $teamA->id, 'disk' => 'local', 'path' => 'backups/missing-team.zip', 'status' => 'completed']);
        expect(fn () => $restoreService->restore($missingTeamBackup))->toThrow(TeamRestoreException::class, 'no longer exists');

        // Non-empty team refused
        expect($restoreService->firstPopulatedTable($teamA))->toBe('org_test_simple_items');
        expect(fn () => $restoreService->restore($backup))->toThrow(TeamNotEmptyException::class);

        // Empty teamA -> restore succeeds with original PKs
        DB::table('org_test_tenant_items')->where('team_id', $teamA->id)->delete();
        DB::table('org_test_simple_items')->where('team_id', $teamA->id)->delete();
        expect($restoreService->firstPopulatedTable($teamA))->toBeNull();

        // Manifest-only zip skips missing model files cleanly on both restore and import
        $emptyModelsPath = Storage::disk('local')->path('backups/empty-models.zip');
        $z4 = new ZipArchive();
        $z4->open($emptyModelsPath, ZipArchive::CREATE);
        $z4->addFromString('manifest.json', json_encode(['format_version' => 1, 'team' => ['id' => $teamA->id, 'name' => 'Manifest Only']]));
        $z4->close();
        $emptyModelsBackup = TeamBackup::create(['team_id' => $teamA->id, 'disk' => 'local', 'path' => 'backups/empty-models.zip', 'status' => 'completed']);
        expect($restoreService->restore($emptyModelsBackup))->toBe([]);
        expect((new ImportTeamBackupService())->import($emptyModelsPath, null, $importer)->name)->toBe('Manifest Only');

        $counts = $restoreService->restore($backup);
        expect($counts['OrgTestTenantItem'])->toBe(2)
            ->and(DB::table('org_test_tenant_items')->where('id', $parentId)->where('team_id', $teamA->id)->exists())->toBeTrue()
            ->and(DB::table('org_test_tenant_items')->where('id', $childId)->where('team_id', $teamA->id)->exists())->toBeTrue();

        // 3. ImportTeamBackupService
        $importService = new ImportTeamBackupService();

        // Bad archive & wrong format version
        expect(fn () => $importService->import(Storage::disk('local')->path('backups/corrupt.zip'), 'X', $importer))
            ->toThrow(TeamImportException::class);
        expect(fn () => $importService->import($badVerPath, 'X', $importer))
            ->toThrow(TeamImportException::class);

        // Valid import with FK remapping (declared + user ref + undeclared *_id)
        expect(SchemaGraph::edges(['nonexistent_table']))->toBe([]);
        $importedTeam = $importService->import(Storage::disk('local')->path($result['path']), 'Imported Team', $importer);
        expect($importedTeam->name)->toBe('Imported Team')
            ->and($importedTeam->user_id)->toBe($importer->id);

        $importedRows = DB::table('org_test_tenant_items')->where('team_id', $importedTeam->id)->orderBy('id')->get();
        expect($importedRows)->toHaveCount(2);
        $newParent = $importedRows->firstWhere('name', 'Parent Item');
        $newChild = $importedRows->firstWhere('name', 'Child Item');
        expect((int) $newChild->parent_id)->toBe((int) $newParent->id)
            ->and((int) $newChild->org_test_tenant_item_id)->toBe((int) $newParent->id)
            ->and((int) $newChild->assigned_to)->toBe($importer->id);
    } finally {
        teardownTenantBackupFixture($fixture);
    }
});

it('covers TeamCloneService cloning config tables and remapping FK edges', function () {
    Schema::create('org_test_pipelines', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id');
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('org_test_stages', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id');
        $table->unsignedBigInteger('pipeline_id')->nullable();
        $table->string('name');
        $table->timestamps();
    });

    try {
        $owner = createServiceUser();
        $source = createServiceTeam($owner, ['name' => 'Template Source']);

        $pipeId = DB::table('org_test_pipelines')->insertGetId([
            'team_id' => $source->id,
            'name' => 'Sales Pipeline',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('org_test_stages')->insert([
            ['team_id' => $source->id, 'pipeline_id' => $pipeId, 'name' => 'Lead', 'created_at' => now(), 'updated_at' => now()],
            ['team_id' => $source->id, 'pipeline_id' => null, 'name' => 'Orphan', 'created_at' => now(), 'updated_at' => now()],
            ['team_id' => $source->id, 'pipeline_id' => 999999, 'name' => 'Dangling', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $cloned = (new TeamCloneService(
            [OrgTestPipeline::class, OrgTestStage::class, OrgTestMenu::class],
            [
                OrgTestStage::class => ['pipeline_id' => OrgTestPipeline::class],
                OrgTestPipeline::class => ['nonexistent_col' => OrgTestPipeline::class],
                'NonExistentModelClass' => ['foo_id' => OrgTestPipeline::class],
            ],
        ))->clone($source, 'Cloned Workspace', $owner);

        expect($cloned->name)->toBe('Cloned Workspace')
            ->and($cloned->id)->not->toBe($source->id);

        $newPipe = DB::table('org_test_pipelines')->where('team_id', $cloned->id)->first();
        $leadStage = DB::table('org_test_stages')->where('team_id', $cloned->id)->where('name', 'Lead')->first();
        $danglingStage = DB::table('org_test_stages')->where('team_id', $cloned->id)->where('name', 'Dangling')->first();

        expect($newPipe)->not->toBeNull()
            ->and((int) $leadStage->pipeline_id)->toBe((int) $newPipe->id)
            ->and($danglingStage->pipeline_id)->toBeNull();
    } finally {
        Schema::dropIfExists('org_test_stages');
        Schema::dropIfExists('org_test_pipelines');
    }
});

it('covers GenerateTeamBackup, ImportTeamBackup, and RestoreTeamBackup jobs (success and failure)', function () {
    Storage::fake('local');
    $fixture = setupTenantBackupFixture();

    try {
        $owner = createServiceUser();
        $team = createServiceTeam($owner);

        // GenerateTeamBackup success
        $backup = TeamBackup::create(['team_id' => $team->id, 'disk' => 'local', 'status' => 'pending']);
        (new GenerateTeamBackup($backup->id))->handle(new TeamBackupService());
        expect($backup->fresh()->status)->toBe('completed')
            ->and($backup->fresh()->path)->not->toBeNull();

        // GenerateTeamBackup failure
        $failingBackup = TeamBackup::create(['team_id' => $team->id, 'disk' => 'local', 'status' => 'pending']);
        $failingService = new class() extends TeamBackupService
        {
            public function backup(Team $team, ?string $disk = null): array
            {
                throw new RuntimeException('disk full');
            }
        };
        expect(fn () => (new GenerateTeamBackup($failingBackup->id))->handle($failingService))
            ->toThrow(RuntimeException::class, 'disk full');
        expect($failingBackup->fresh()->status)->toBe('failed')
            ->and($failingBackup->fresh()->error)->toBe('disk full');

        // RestoreTeamBackup success (with notifyUserId, null notifyUserId, and missing user)
        (new RestoreTeamBackup($backup->id, $owner->id))->handle(new TeamRestoreService());
        (new RestoreTeamBackup($backup->id, null))->handle(new TeamRestoreService());
        (new RestoreTeamBackup($backup->id, 999999))->handle(new TeamRestoreService());

        // RestoreTeamBackup failure
        expect(fn () => (new RestoreTeamBackup($failingBackup->id, $owner->id))->handle(new TeamRestoreService()))
            ->toThrow(TeamRestoreException::class);

        // ImportTeamBackup success
        (new ImportTeamBackup('local', (string) $backup->fresh()->path, 'Job Imported', $owner->id))
            ->handle(new ImportTeamBackupService());
        expect(Team::where('name', 'Job Imported')->exists())->toBeTrue();

        // ImportTeamBackup failure
        Storage::disk('local')->put('imports/broken.zip', 'corrupt');
        expect(fn () => (new ImportTeamBackup('local', 'imports/broken.zip', 'Broken', $owner->id))
            ->handle(new ImportTeamBackupService()))
            ->toThrow(TeamImportException::class);
    } finally {
        teardownTenantBackupFixture($fixture);
    }
});

it('covers BackupTeam, CloneTeam, ImportTeam, and RestoreTeam artisan commands', function () {
    Queue::fake();
    Storage::fake('local');

    $kernel = app(ConsoleKernel::class);
    $kernel->registerCommand(new BackupTeam());
    $kernel->registerCommand(new CloneTeam());
    $kernel->registerCommand(new ImportTeam());
    $kernel->registerCommand(new RestoreTeam());

    $owner = createServiceUser();
    $team = createServiceTeam($owner, ['name' => 'CLI Source']);

    // BackupTeam
    $this->artisan('team:backup', ['team' => 999999])->assertFailed();
    $this->artisan('team:backup', ['team' => $team->id])->assertSuccessful();
    Queue::assertPushed(GenerateTeamBackup::class);

    // RestoreTeam
    $backup = TeamBackup::create(['team_id' => $team->id, 'disk' => 'local', 'status' => 'completed', 'path' => 'backups/a.zip']);
    $this->artisan('team:restore', ['backup' => 999999])->assertFailed();
    $this->artisan('team:restore', ['backup' => $backup->id])->assertSuccessful();
    Queue::assertPushed(RestoreTeamBackup::class);

    // CloneTeam
    $this->artisan('team:clone', ['source' => 999999])->assertFailed();
    $this->artisan('team:clone', ['source' => $team->id, '--owner' => 999999])->assertFailed();
    $this->artisan('team:clone', ['source' => $team->id, '--name' => 'CLI Clone', '--owner' => $owner->id])->assertSuccessful();
    expect(Team::where('name', 'CLI Clone')->exists())->toBeTrue();

    // ImportTeam
    $fixture = setupTenantBackupFixture();
    try {
        $this->artisan('team:import', ['path' => '/nonexistent/backup.zip'])->assertFailed();

        $res = (new TeamBackupService())->backup($team, 'local');
        $zipPath = Storage::disk('local')->path($res['path']);

        $this->artisan('team:import', ['path' => $zipPath, '--owner' => 999999])->assertFailed();
        $this->artisan('team:import', ['path' => $zipPath, '--name' => 'CLI Import', '--owner' => $owner->id])->assertSuccessful();
        $this->artisan('team:import', ['path' => $zipPath, '--name' => 'CLI Default Owner'])->assertSuccessful();
        expect(Team::where('name', 'CLI Import')->exists())->toBeTrue()
            ->and(Team::where('name', 'CLI Default Owner')->exists())->toBeTrue();
    } finally {
        teardownTenantBackupFixture($fixture);
    }
});
