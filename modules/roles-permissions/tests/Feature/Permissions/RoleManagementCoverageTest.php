<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Models\Team;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Liberu\Foundation\RolesPermissions\Contracts\PrivilegedActor;
use Liberu\Foundation\RolesPermissions\Enums\Role as RoleEnum;
use Liberu\Foundation\RolesPermissions\Models\Role as CustomRoleModel;
use Liberu\Foundation\RolesPermissions\Policies\RolePolicy;
use Liberu\Foundation\RolesPermissions\Registry\PermissionRegistry;
use Liberu\Foundation\RolesPermissions\Services\AnyTeamRoleLookup;
use Liberu\Foundation\RolesPermissions\Services\BreakGlass;
use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;
use Liberu\Foundation\RolesPermissions\Services\SeparationOfDuty;
use Liberu\Foundation\RolesPermissions\Support\PermissionCatalog;
use Liberu\Foundation\RolesPermissions\Support\RolePermissionCatalog;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RoleManagementCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['permissions' => [
            'actions' => ['view', 'create', 'update', 'delete'],
            'groups' => [
                'core' => ['contact', 'company', 'lead', 'deal', 'opportunity', 'task', 'note', 'message'],
                'advertising' => ['advertising_account', 'campaign', 'ad_set', 'ad', 'marketing_campaign', 'social_media_post', 'dashboard_widget', 'call_setting', 'whatsapp_number'],
                'settings' => ['team_member', 'team_role', 'territory'],
                'logs' => ['audit_log'],
                'staff' => ['knowledge_base_article'],
            ],
            'matrix' => [
                'admin' => [
                    'groups' => ['core' => ['view', 'create', 'update', 'delete']],
                ],
            ],
        ]]);
    }

    public function test_role_enum_and_model_team_relation(): void
    {
        $this->assertSame(
            ['super_admin', 'admin', 'manager', 'sales_rep', 'free', 'customer'],
            RoleEnum::values(),
        );

        $role = new CustomRoleModel();
        $this->assertInstanceOf(BelongsTo::class, $role->team());
    }

    public function test_role_policy_checks_all_abilities(): void
    {
        $user = User::factory()->create();
        $role = SpatieRole::create(['name' => 'policy_target', 'guard_name' => 'web', 'team_id' => null]);
        $policy = new RolePolicy();

        Gate::define('roles-permissions.roles.view-any', fn () => true);
        Gate::define('roles-permissions.roles.view', fn () => true);
        Gate::define('roles-permissions.roles.create', fn () => true);
        Gate::define('roles-permissions.roles.update', fn () => true);
        Gate::define('roles-permissions.roles.delete', fn () => true);
        Gate::define('roles-permissions.roles.delete-any', fn () => true);
        Gate::define('roles-permissions.roles.restore', fn () => true);
        Gate::define('roles-permissions.roles.force-delete', fn () => true);
        Gate::define('roles-permissions.roles.force-delete-any', fn () => true);
        Gate::define('roles-permissions.roles.restore-any', fn () => true);
        Gate::define('roles-permissions.roles.replicate', fn () => true);
        Gate::define('roles-permissions.roles.reorder', fn () => true);

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $role));
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->update($user, $role));
        $this->assertTrue($policy->delete($user, $role));
        $this->assertTrue($policy->deleteAny($user));
        $this->assertTrue($policy->restore($user, $role));
        $this->assertTrue($policy->forceDelete($user, $role));
        $this->assertTrue($policy->forceDeleteAny($user));
        $this->assertTrue($policy->restoreAny($user));
        $this->assertTrue($policy->replicate($user, $role));
        $this->assertTrue($policy->reorder($user));
    }

    public function test_permission_registry_validates_format_uniqueness_and_lists_all(): void
    {
        $registry = new PermissionRegistry();

        try {
            $registry->declare('invalid_format', 'billing', 'Invalid');
            $this->fail('Expected InvalidArgumentException for bad format.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Permission must use {module}.{resource}.{action}.', $e->getMessage());
        }

        $registry->declare('billing.invoices.view', 'billing', 'View invoices');

        try {
            $registry->declare('billing.invoices.view', 'billing', 'Duplicate');
            $this->fail('Expected InvalidArgumentException for duplicate permission.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Permission [billing.invoices.view] is already declared.', $e->getMessage());
        }

        $this->assertSame([
            'billing.invoices.view' => [
                'owner' => 'billing',
                'description' => 'View invoices',
            ],
        ], $registry->all());
    }

    public function test_any_team_role_lookup_checks_roles_across_teams(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $role = SpatieRole::create(['name' => 'auditor', 'guard_name' => 'web', 'team_id' => $team->id]);

        $actor = new class($user) implements PrivilegedActor
        {
            public function __construct(private readonly User $user) {}

            public function authorizationIdentifier(): int|string
            {
                return $this->user->getKey();
            }

            public function authorizationType(): string
            {
                return $this->user->getMorphClass();
            }

            public function hasRoleInAnyTeam(string|array $roles): bool
            {
                return false;
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }
        };

        $lookup = new AnyTeamRoleLookup();

        $this->assertFalse($lookup->hasRoleInAnyTeam($actor, []));
        $this->assertFalse($lookup->hasRoleInAnyTeam($actor, 'auditor'));

        DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))->insert([
            'role_id' => $role->id,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->getKey(),
            'team_id' => $team->id,
        ]);

        $this->assertTrue($lookup->hasRoleInAnyTeam($actor, 'auditor'));
        $this->assertTrue($lookup->hasRoleInAnyTeam($actor, ['other', 'auditor']));
        $this->assertFalse($lookup->hasRoleInAnyTeam($actor, ['other']));
    }

    public function test_break_glass_and_separation_of_duty(): void
    {
        $breakGlass = new BreakGlass();

        foreach ([
            ['actor-1', 'billing.refunds.approve', 'urgent', new DateTimeImmutable('+1 hour'), false],
            ['actor-1', 'billing.refunds.approve', '   ', new DateTimeImmutable('+1 hour'), true],
            ['actor-1', 'billing.refunds.approve', 'urgent', new DateTimeImmutable('-1 minute'), true],
        ] as [$actorId, $permission, $reason, $expiresAt, $strong]) {
            try {
                $breakGlass->grant($actorId, $permission, $reason, $expiresAt, $strong);
                $this->fail('Expected RuntimeException for invalid break-glass grant.');
            } catch (RuntimeException $e) {
                $this->assertSame('Break-glass access requires strong authentication, reason, and future expiry.', $e->getMessage());
            }
        }

        $id = $breakGlass->grant('actor-1', 'billing.refunds.approve', 'incident-123', new DateTimeImmutable('+1 hour'), true);
        $this->assertGreaterThan(0, $id);
        $this->assertTrue($breakGlass->active('actor-1', 'billing.refunds.approve'));
        $this->assertFalse($breakGlass->active('actor-2', 'billing.refunds.approve'));

        $sod = new SeparationOfDuty();
        $this->assertFalse($sod->permits(1, 1, true));
        $this->assertTrue($sod->permits(1, 2, true));
        $this->assertTrue($sod->permits(1, 1, false));
    }

    public function test_role_permission_catalog_options_presets_and_labels(): void
    {
        PermissionCatalog::sync();
        Permission::findOrCreate('custom_platform_flag', 'web');

        $teamOptions = RolePermissionCatalog::options(platform: false);
        $this->assertArrayHasKey('view_contact', $teamOptions);
        $this->assertSame('Contact · View', $teamOptions['view_contact']);
        $this->assertSame('Contact · Create', $teamOptions['create_contact']);
        $this->assertSame('Contact · Edit', $teamOptions['update_contact']);
        $this->assertSame('Contact · Delete', $teamOptions['delete_contact']);
        $this->assertArrayHasKey('view_territory', $teamOptions);
        $this->assertArrayNotHasKey('view_call_setting', $teamOptions);
        $this->assertArrayNotHasKey('view_whatsapp_number', $teamOptions);
        $this->assertArrayNotHasKey('custom_platform_flag', $teamOptions);

        $platformOptions = RolePermissionCatalog::options(platform: true);
        $this->assertArrayHasKey('custom_platform_flag', $platformOptions);
        $this->assertSame('Custom Platform Flag', $platformOptions['custom_platform_flag']);

        $presets = RolePermissionCatalog::presets();
        $this->assertSame([
            'sales' => 'Sales',
            'support' => 'Support',
            'social_media' => 'Social media',
            'advertising' => 'Advertising',
        ], $presets);

        $salesPreset = RolePermissionCatalog::preset('sales');
        $this->assertContains('view_contact', $salesPreset);
        $this->assertNotContains('delete_contact', $salesPreset);
        $this->assertSame([], RolePermissionCatalog::preset('nonexistent'));
    }

    public function test_role_configuration_service_manages_platform_and_team_roles(): void
    {
        PermissionCatalog::sync();

        $service = new RoleConfigurationService();

        $superAdmin = User::factory()->create();
        setPermissionsTeamId(null);
        $superAdmin->assignRole(RoleEnum::SuperAdmin->value);

        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);

        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'editor']);

        $teamAdmin = User::factory()->create();
        $team->users()->attach($teamAdmin, ['role' => 'admin']);
        $globalAdminRole = SpatieRole::whereNull('team_id')->where('guard_name', 'web')->where('name', RoleEnum::Admin->value)->firstOrFail();
        DB::table('model_has_roles')->insert([
            'role_id' => $globalAdminRole->id,
            'model_type' => $teamAdmin->getMorphClass(),
            'model_id' => $teamAdmin->getKey(),
            'team_id' => $team->id,
        ]);

        // canManageTeam branches: super admin, owner, non-member, plain member, team admin.
        $this->assertTrue($service->canManageTeam($superAdmin, $team));
        $this->assertTrue($service->canManageTeam($owner, $team));
        $this->assertFalse($service->canManageTeam($outsider, $team));
        $this->assertFalse($service->canManageTeam($member, $team));
        $this->assertTrue($service->canManageTeam($teamAdmin, $team));

        // Platform role creation by super admin.
        $platformRole = $service->save($superAdmin, null, [
            'name' => '  Billing Auditor  ',
            'permissions' => ['view_contact'],
        ]);
        $this->assertSame('Billing Auditor', $platformRole->name);
        $this->assertNull($platformRole->team_id);
        $this->assertTrue($platformRole->hasPermissionTo('view_contact'));

        // Platform role update.
        $updatedPlatform = $service->save($superAdmin, null, [
            'name' => 'Billing Auditor',
            'permissions' => ['view_contact', 'create_contact'],
        ], $platformRole);
        $this->assertTrue($updatedPlatform->hasPermissionTo('create_contact'));

        // Duplicate name in same scope fails.
        try {
            $service->save($superAdmin, null, ['name' => 'billing auditor', 'permissions' => []]);
            $this->fail('Expected duplicate role ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        // Renaming a system role fails.
        try {
            $service->save($superAdmin, null, ['name' => 'renamed_admin', 'permissions' => []], $globalAdminRole);
            $this->fail('Expected system role rename ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        // Reserved system role name fails for new role.
        try {
            $service->save($superAdmin, null, ['name' => 'Sales Rep', 'permissions' => []]);
            $this->fail('Expected reserved role name ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        // SuperAdmin role itself is protected from editing.
        $superAdminRole = SpatieRole::whereNull('team_id')->where('guard_name', 'web')->where('name', RoleEnum::SuperAdmin->value)->firstOrFail();
        try {
            $service->save($superAdmin, null, ['name' => RoleEnum::SuperAdmin->value, 'permissions' => []], $superAdminRole);
            $this->fail('Expected 403 for super_admin role.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Team role creation and update by team owner.
        $teamRole = $service->save($owner, $team, [
            'name' => 'Account Manager',
            'permissions' => ['view_contact'],
        ]);
        $this->assertSame($team->id, (int) $teamRole->team_id);

        // Unauthorized outsider cannot save team role.
        try {
            $service->save($outsider, $team, ['name' => 'Rogue', 'permissions' => []]);
            $this->fail('Expected 403 for outsider.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Deleting a system role aborts 403.
        try {
            $service->delete($superAdmin, null, $globalAdminRole);
            $this->fail('Expected 403 when deleting system role.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Deleting an assigned custom role throws ValidationException.
        DB::table('model_has_roles')->insert([
            'role_id' => $teamRole->id,
            'model_type' => $member->getMorphClass(),
            'model_id' => $member->getKey(),
            'team_id' => $team->id,
        ]);

        try {
            $service->delete($owner, $team, $teamRole);
            $this->fail('Expected ValidationException when deleting assigned role.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role', $e->errors());
        }

        // Unassign and delete custom role cleanly.
        DB::table('model_has_roles')->where('role_id', $teamRole->id)->delete();
        $service->delete($owner, $team, $teamRole);
        $this->assertDatabaseMissing('roles', ['id' => $teamRole->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'team.role_deleted']);
    }
}
