<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Services {
    if (! function_exists(__NAMESPACE__.'\\tempnam')) {
        function tempnam(string $directory, string $prefix): string|false
        {
            if (($GLOBALS['__legacy_test_tempnam_override'] ?? null) !== null) {
                return $GLOBALS['__legacy_test_tempnam_override'];
            }

            return \tempnam($directory, $prefix);
        }
    }
}

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;
    use Liberu\Foundation\Organizations\Traits\IsTenantModel;

    if (! class_exists(Contact::class)) {
        class Contact extends Model
        {
            protected $guarded = [];
        }
    }

    if (! class_exists(AbstractTenantModelForCoverage::class)) {
        abstract class AbstractTenantModelForCoverage extends Model
        {
            use IsTenantModel;
        }
    }
}

namespace {
    use App\Events\ContactUpdated;
    use App\Models\Branch;
    use App\Models\Territory;
    use App\Models\User;
    use Filament\Facades\Filament;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Auth\Events\Registered;
    use Illuminate\Console\Application;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Database\Eloquent\Relations\HasOne;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Http\Request;
    use Illuminate\Routing\Route as IlluminateRoute;
    use Illuminate\Support\Facades\Artisan;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Facades\Queue;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Validation\ValidationException;
    use Laravel\Jetstream\Events\TeamCreated;
    use Laravel\Jetstream\Events\TeamMemberAdded;
    use Laravel\Jetstream\Jetstream;
    use Liberu\Foundation\Integrations\Listeners\ProvisionZernioProfile;
    use Liberu\Foundation\Integrations\Models\Webhook;
    use Liberu\Foundation\Integrations\Services\Zernio\ZernioClient;
    use Liberu\Foundation\Integrations\Services\Zernio\ZernioTenantService;
    use Liberu\Foundation\Organizations\Actions\Jetstream\AddTeamMember;
    use Liberu\Foundation\Organizations\Actions\Jetstream\CreateTeam as JetstreamCreateTeam;
    use Liberu\Foundation\Organizations\Actions\Jetstream\DeleteTeam;
    use Liberu\Foundation\Organizations\Actions\Jetstream\DeleteUser;
    use Liberu\Foundation\Organizations\Actions\Jetstream\DeleteUserWithTeams;
    use Liberu\Foundation\Organizations\Actions\Jetstream\InviteTeamMember;
    use Liberu\Foundation\Organizations\Actions\Jetstream\RemoveTeamMember;
    use Liberu\Foundation\Organizations\Actions\Jetstream\UpdateTeamName;
    use Liberu\Foundation\Organizations\Console\Commands\BackupTeam;
    use Liberu\Foundation\Organizations\Console\Commands\CloneTeam;
    use Liberu\Foundation\Organizations\Console\Commands\ImportTeam;
    use Liberu\Foundation\Organizations\Console\Commands\RestoreTeam;
    use Liberu\Foundation\Organizations\Exceptions\TeamImportException;
    use Liberu\Foundation\Organizations\Exceptions\TeamNotEmptyException;
    use Liberu\Foundation\Organizations\Exceptions\TeamRestoreException;
    use Liberu\Foundation\Organizations\Http\Controllers\TeamInvitationController;
    use Liberu\Foundation\Organizations\Http\Livewire\CreateTeam as LegacyHttpLivewireCreateTeam;
    use Liberu\Foundation\Organizations\Http\Middleware\AssignDefaultTeam;
    use Liberu\Foundation\Organizations\Http\Middleware\SetFilamentDefaultTenant;
    use Liberu\Foundation\Organizations\Http\Middleware\SetPermissionsTeamContext;
    use Liberu\Foundation\Organizations\Http\Middleware\SetTenantContext;
    use Liberu\Foundation\Organizations\Http\Middleware\TeamsPermission;
    use Liberu\Foundation\Organizations\Jobs\GenerateTeamBackup;
    use Liberu\Foundation\Organizations\Jobs\ImportTeamBackup;
    use Liberu\Foundation\Organizations\Jobs\RestoreTeamBackup;
    use Liberu\Foundation\Organizations\Listeners\AssignDefaultTeamRole;
    use Liberu\Foundation\Organizations\Listeners\CreatePersonalTeam;
    use Liberu\Foundation\Organizations\Listeners\NotifyTeamMembers;
    use Liberu\Foundation\Organizations\Listeners\SwitchTeam;
    use Liberu\Foundation\Organizations\Livewire\CreateTeam as LegacyLivewireCreateTeam;
    use Liberu\Foundation\Organizations\Models\Membership;
    use Liberu\Foundation\Organizations\Models\Team;
    use Liberu\Foundation\Organizations\Models\TeamBackup;
    use Liberu\Foundation\Organizations\Models\TeamInvitation;
    use Liberu\Foundation\Organizations\Models\TeamSubscription;
    use Liberu\Foundation\Organizations\Policies\TeamPolicy;
    use Liberu\Foundation\Organizations\Services\ImportTeamBackupService;
    use Liberu\Foundation\Organizations\Services\TeamBackupService;
    use Liberu\Foundation\Organizations\Services\TeamCloneService;
    use Liberu\Foundation\Organizations\Services\TeamManagementService;
    use Liberu\Foundation\Organizations\Services\TeamRestoreService;
    use Liberu\Foundation\Organizations\Support\AccessContext;
    use Liberu\Foundation\Organizations\Support\PortalCustomer;
    use Liberu\Foundation\Organizations\Support\TenantContext;
    use Liberu\Foundation\Organizations\Support\TenantModels;
    use Liberu\Foundation\RolesPermissions\Enums\Role as RoleEnum;
    use Liberu\Foundation\RolesPermissions\Models\Role as LegacyRoleModel;
    use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;
    use Liberu\Foundation\RolesPermissions\Support\PermissionCatalog;
    use Liberu\Foundation\RolesPermissions\Support\RolePermissionCatalog;
    use Spatie\Permission\Models\Permission;
    use Spatie\Permission\Models\Role as SpatieRole;
    use Symfony\Component\HttpKernel\Exception\HttpException;

    if (! function_exists('setupLegacyOrganizationsAndRolesTables')) {
        function setupLegacyOrganizationsAndRolesTables(): void
        {
            if (! Schema::hasColumn('teams', 'archived_at')) {
                Schema::table('teams', function (Blueprint $table): void {
                    $table->timestamp('archived_at')->nullable();
                });
            }
            if (! Schema::hasColumn('teams', 'setup_completed_at')) {
                Schema::table('teams', function (Blueprint $table): void {
                    $table->timestamp('setup_completed_at')->nullable();
                    $table->string('zernio_profile_id')->nullable();
                    $table->string('portal_brand_name')->nullable();
                    $table->string('portal_logo_url')->nullable();
                    $table->string('portal_logo_path')->nullable();
                    $table->json('marketing_preferences')->nullable();
                });
            }
            if (! Schema::hasColumn('teams', 'branch_id')) {
                Schema::table('teams', function (Blueprint $table): void {
                    $table->unsignedBigInteger('branch_id')->nullable();
                });
            }
            if (! Schema::hasColumn('team_invitations', 'token')) {
                Schema::table('team_invitations', function (Blueprint $table): void {
                    $table->string('token', 64)->nullable();
                });
            }
            if (! Schema::hasTable('team_backups')) {
                Schema::create('team_backups', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                    $table->string('disk')->default('local');
                    $table->string('path')->nullable();
                    $table->unsignedBigInteger('size_bytes')->nullable();
                    $table->string('status')->default('pending');
                    $table->text('error')->nullable();
                    $table->foreignId('created_by')->nullable();
                    $table->timestamps();
                });
            }
            if (! Schema::hasTable('team_subscriptions')) {
                Schema::create('team_subscriptions', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                    $table->string('stripe_id')->unique();
                    $table->string('stripe_status');
                    $table->string('stripe_price')->nullable();
                    $table->string('plan_key')->nullable();
                    $table->integer('quantity')->default(1);
                    $table->timestamp('trial_ends_at')->nullable();
                    $table->timestamp('current_period_ends_at')->nullable();
                    $table->timestamp('ends_at')->nullable();
                    $table->timestamp('cancelled_at')->nullable();
                    $table->timestamps();
                    $table->softDeletes();
                });
            }
            if (! Schema::hasTable('territories')) {
                Schema::create('territories', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                    $table->unsignedBigInteger('manager_id')->nullable();
                    $table->string('name');
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                });
            }
            if (! Schema::hasTable('territory_user')) {
                Schema::create('territory_user', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
                    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                    $table->string('role')->nullable();
                    $table->timestamps();
                });
            }
            if (! Schema::hasTable('audit_logs')) {
                Schema::create('audit_logs', function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('team_id')->nullable();
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->string('action');
                    $table->text('description')->nullable();
                    $table->string('auditable_type')->nullable();
                    $table->unsignedBigInteger('auditable_id')->nullable();
                    $table->json('changes')->nullable();
                    $table->string('ip_address')->nullable();
                    $table->timestamps();
                });
            }
            if (! Schema::hasTable('branches')) {
                Schema::create('branches', function (Blueprint $table): void {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });
            }
            if (! Schema::hasTable('notifications')) {
                Schema::create('notifications', function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->string('type');
                    $table->morphs('notifiable');
                    $table->text('data');
                    $table->timestamp('read_at')->nullable();
                    $table->timestamps();
                });
            }
            if (! Schema::hasTable('call_settings')) {
                Schema::create('call_settings', function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('team_id')->nullable();
                    $table->string('name');
                    $table->string('value')->nullable();
                    $table->text('description')->nullable();
                    $table->timestamps();
                });
            }
            if (! Schema::hasTable('webhooks')) {
                Schema::create('webhooks', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->unsignedBigInteger('call_setting_id')->nullable();
                    $table->unsignedBigInteger('external_id')->nullable();
                    $table->string('name');
                    $table->string('url')->default('https://example.test/hook');
                    $table->json('events')->nullable();
                    $table->text('secret')->nullable();
                    $table->boolean('is_active')->default(true);
                    $table->integer('failure_count')->default(0);
                    $table->timestamp('last_triggered_at')->nullable();
                    $table->timestamps();
                });
            }
            if (! Schema::hasColumn('webhooks', 'owner_id')) {
                Schema::table('webhooks', function (Blueprint $table): void {
                    $table->unsignedBigInteger('owner_id')->nullable();
                });
            }
            if (! Schema::hasColumn('webhooks', 'call_setting_id')) {
                Schema::table('webhooks', function (Blueprint $table): void {
                    $table->unsignedBigInteger('call_setting_id')->nullable();
                });
            }
            if (! Schema::hasColumn('webhooks', 'external_id')) {
                Schema::table('webhooks', function (Blueprint $table): void {
                    $table->unsignedBigInteger('external_id')->nullable();
                });
            }
            if (! Schema::hasTable('crm_webhook_deliveries')) {
                Schema::create('crm_webhook_deliveries', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                    $table->foreignId('webhook_id')->nullable()->constrained('webhooks')->nullOnDelete();
                    $table->string('event')->default('contact.created');
                    $table->boolean('success')->default(true);
                    $table->integer('status_code')->nullable();
                    $table->text('error')->nullable();
                    $table->timestamps();
                });
            }

            config([
                'permissions' => [
                    'actions' => ['view', 'create', 'update', 'delete'],
                    'groups' => [
                        'core' => ['contact', 'deal', 'lead', 'company', 'opportunity', 'task', 'note', 'activation', 'message'],
                        'advertising' => ['advertising_account', 'campaign', 'ad_set', 'ad', 'marketing_campaign', 'mailchimp_campaign', 'social_media_post', 'landing_page', 'form_builder', 'workflow', 'dashboard_widget', 'call_setting', 'whatsapp_number'],
                        'settings' => ['team_member', 'team_role', 'sso_connection', 'saml_connection', 'oauth_configuration', 'webhook_delivery', 'portal_branding', 'territory'],
                        'logs' => ['audit_log', 'portal_access_log', 'team_role_log'],
                        'staff' => ['knowledge_base_article'],
                    ],
                    'matrix' => [
                        'admin' => [
                            'groups' => [
                                'core' => ['view', 'create', 'update', 'delete'],
                                'advertising' => ['view', 'create', 'update', 'delete'],
                                'settings' => ['view', 'create', 'update', 'delete'],
                                'logs' => ['view'],
                            ],
                            'resources' => [
                                'knowledge_base_article' => ['view', 'create', 'update', 'delete'],
                            ],
                        ],
                        'manager' => [
                            'groups' => [
                                'core' => ['view', 'create', 'update', 'delete'],
                                'advertising' => ['view', 'create', 'update', 'delete'],
                            ],
                            'resources' => [
                                'territory' => ['view', 'create', 'update', 'delete'],
                                'knowledge_base_article' => ['view', 'create', 'update', 'delete'],
                                'portal_access_log' => ['view'],
                            ],
                        ],
                        'sales_rep' => [
                            'groups' => [
                                'core' => ['view', 'create', 'update', 'delete'],
                                'advertising' => ['view'],
                            ],
                        ],
                        'free' => [
                            'groups' => [
                                'core' => ['view', 'create', 'update'],
                            ],
                        ],
                    ],
                ],
            ]);
        }
    }

    beforeEach(function (): void {
        $GLOBALS['__legacy_test_tempnam_override'] = null;
        setupLegacyOrganizationsAndRolesTables();
        Jetstream::useTeamModel(Team::class);
        Jetstream::useMembershipModel(Membership::class);
        Jetstream::useTeamInvitationModel(TeamInvitation::class);
        Gate::policy(Team::class, TeamPolicy::class);

        User::resolveRelationUsing('territories', fn (User $user) => $user->belongsToMany(Territory::class, 'territory_user')->withTimestamps());
        Territory::resolveRelationUsing('users', fn (Territory $territory) => $territory->belongsToMany(User::class, 'territory_user')->withTimestamps());
    });

    afterEach(function (): void {
        $GLOBALS['__legacy_test_tempnam_override'] = null;
        AccessContext::clear();
        Jetstream::useTeamModel(Team::class);
        Jetstream::useMembershipModel(Membership::class);
        Jetstream::useTeamInvitationModel(TeamInvitation::class);
    });

    it('covers Role enum, Role model, PermissionCatalog, RolePermissionCatalog, and RoleConfigurationService', function (): void {
        expect(RoleEnum::values())->toContain('super_admin', 'admin', 'manager', 'sales_rep', 'free', 'customer');
        expect(new LegacyRoleModel())->toBeInstanceOf(SpatieRole::class);

        PermissionCatalog::sync();
        // Second call hits $missing === [] early return
        PermissionCatalog::sync();
        expect(PermissionCatalog::catalogPermissions())->toContain('view_contact');

        $platformOptions = RolePermissionCatalog::options(true);
        $teamOptions = RolePermissionCatalog::options(false);
        expect($platformOptions)->toHaveKey('manage_roles')
            ->and($teamOptions)->toHaveKey('view_contact')
            ->and(RolePermissionCatalog::presets())->toHaveKey('sales')
            ->and(RolePermissionCatalog::preset('sales'))->toContain('view_contact');

        $service = app(RoleConfigurationService::class);
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);

        $superAdmin = User::factory()->create();
        setPermissionsTeamId(null);
        $superAdmin->assignRole(RoleEnum::SuperAdmin->value);

        $stranger = User::factory()->create();
        $teamAdmin = User::factory()->create();
        $team->users()->attach($teamAdmin, ['role' => 'admin']);
        setPermissionsTeamId($team->id);
        $teamAdmin->assignRole(RoleEnum::Admin->value);

        $salesRep = User::factory()->create();
        $team->users()->attach($salesRep, ['role' => 'sales_rep']);
        setPermissionsTeamId($team->id);
        $salesRep->assignRole(RoleEnum::SalesRep->value);

        expect($service->canManageTeam($superAdmin, $team))->toBeTrue()
            ->and($service->canManageTeam($owner, $team))->toBeTrue()
            ->and($service->canManageTeam($stranger, $team))->toBeFalse()
            ->and($service->canManageTeam($teamAdmin, $team))->toBeTrue()
            ->and($service->canManageTeam($salesRep, $team))->toBeFalse();

        // Create and update a custom team role
        $customRole = $service->save($owner, $team, [
            'name' => '  Field Agent  ',
            'permissions' => ['view_contact'],
        ]);
        expect($customRole->name)->toBe('Field Agent');

        $updatedRole = $service->save($owner, $team, [
            'name' => 'Senior Field Agent',
            'permissions' => ['view_contact', 'create_contact'],
        ], $customRole);
        expect($updatedRole->name)->toBe('Senior Field Agent');

        // Platform role save by super_admin
        $platformRole = $service->save($superAdmin, null, [
            'name' => 'Platform Auditor',
            'permissions' => ['view_audit_log'],
        ]);
        expect($platformRole->team_id)->toBeNull();

        // Cannot rename system role
        $adminSystemRole = SpatieRole::whereNull('team_id')->where('name', RoleEnum::Admin->value)->firstOrFail();
        expect(fn () => $service->save($superAdmin, null, [
            'name' => 'Renamed Admin',
            'permissions' => ['view_contact'],
        ], $adminSystemRole))->toThrow(ValidationException::class);

        // Cannot use reserved system role name for custom role
        expect(fn () => $service->save($owner, $team, [
            'name' => 'Sales Rep',
            'permissions' => ['view_contact'],
        ]))->toThrow(ValidationException::class);

        // Duplicate role name in same scope
        expect(fn () => $service->save($owner, $team, [
            'name' => 'senior field agent',
            'permissions' => ['view_contact'],
        ]))->toThrow(ValidationException::class);

        // Super admin role is protected
        $superAdminRole = SpatieRole::whereNull('team_id')->where('name', RoleEnum::SuperAdmin->value)->firstOrFail();
        expect(fn () => $service->save($superAdmin, null, [
            'name' => RoleEnum::SuperAdmin->value,
            'permissions' => ['view_contact'],
        ], $superAdminRole))->toThrow(HttpException::class);

        // System roles cannot be deleted
        expect(fn () => $service->delete($superAdmin, null, $adminSystemRole))->toThrow(HttpException::class);

        // Assigned custom role cannot be deleted until members are reassigned
        setPermissionsTeamId($team->id);
        $salesRep->assignRole($updatedRole);
        expect(fn () => $service->delete($owner, $team, $updatedRole))->toThrow(ValidationException::class);

        // Unassign and delete custom role
        DB::table('model_has_roles')->where('role_id', $updatedRole->id)->delete();
        $service->delete($owner, $team, $updatedRole);
        expect(SpatieRole::find($updatedRole->id))->toBeNull();
    });

    it('covers Legacy Team, Membership, TeamBackup, TeamInvitation, TeamSubscription, TeamPolicy, and Exceptions', function (): void {
        expect(new Membership())->toBeInstanceOf(Laravel\Jetstream\Membership::class)
            ->and(new TeamImportException('err'))->toBeInstanceOf(RuntimeException::class)
            ->and(new TeamNotEmptyException('err'))->toBeInstanceOf(TeamRestoreException::class);

        $owner = User::factory()->create();
        $personalTeam = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => true]);
        $workTeam = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);
        $owner->forceFill(['current_team_id' => $workTeam->id])->save();

        expect($workTeam->teamInvitations())->toBeInstanceOf(HasMany::class)
            ->and($workTeam->subscription())->toBeInstanceOf(HasOne::class)
            ->and($workTeam->contacts())->toBeInstanceOf(HasMany::class)
            ->and($workTeam->isArchived())->toBeFalse()
            ->and($workTeam->canAddMoreUsers())->toBeTrue();

        expect(fn () => $personalTeam->archive())->toThrow(DomainException::class);

        $workTeam->archive();
        expect($workTeam->isArchived())->toBeTrue()
            ->and($owner->fresh()->current_team_id)->toBe($personalTeam->id);

        // Calling archive again when already archived returns early
        $workTeam->archive();

        expect(Team::withArchived()->whereKey($workTeam->id)->exists())->toBeTrue();
        $workTeam->restore();
        expect($workTeam->isArchived())->toBeFalse();

        // Subscriptions and SaaS mode
        config(['saas.enabled' => false]);
        expect($workTeam->hasActiveSubscription())->toBeTrue();

        config(['saas.enabled' => true]);
        $sub = TeamSubscription::create([
            'team_id' => $workTeam->id,
            'stripe_id' => 'sub_123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_123',
            'plan_key' => 'pro',
            'quantity' => 5,
            'trial_ends_at' => now()->addDays(7),
            'ends_at' => now()->addDays(30),
        ]);
        expect($sub->team->id)->toBe($workTeam->id)
            ->and($sub->onTrial())->toBeTrue()
            ->and($sub->hasExpired())->toBeFalse()
            ->and($sub->isActive())->toBeTrue()
            ->and($sub->cancelAt())->toBeTrue()
            ->and($workTeam->fresh()->hasActiveSubscription())->toBeTrue();

        $backup = TeamBackup::create([
            'team_id' => $workTeam->id,
            'disk' => 'local',
            'path' => 'backups/test.zip',
            'size_bytes' => 123,
            'status' => 'completed',
            'created_by' => $owner->id,
        ]);
        expect($backup->team->id)->toBe($workTeam->id)
            ->and($backup->creator->id)->toBe($owner->id);

        $invitation = $workTeam->teamInvitations()->create([
            'email' => 'guest@example.test',
            'role' => 'admin',
            'token' => 'tok_123',
        ]);
        expect($invitation->team->id)->toBe($workTeam->id);

        $policy = new TeamPolicy();
        expect($policy->viewAny($owner))->toBeTrue()
            ->and($policy->view($owner, $workTeam))->toBeTrue()
            ->and($policy->create($owner))->toBeTrue()
            ->and($policy->update($owner, $workTeam))->toBeTrue()
            ->and($policy->addTeamMember($owner, $workTeam))->toBeTrue()
            ->and($policy->updateTeamMember($owner, $workTeam))->toBeTrue()
            ->and($policy->removeTeamMember($owner, $workTeam))->toBeTrue()
            ->and($policy->delete($owner, $workTeam))->toBeTrue()
            ->and($policy->deleteTeamInvitation($owner, $invitation))->toBeTrue();
    });

    it('covers AccessContext, PortalCustomer, TenantContext, and TenantModels', function (): void {
        PermissionCatalog::sync();
        expect(new TenantContext())->toBeInstanceOf(Liberu\Foundation\Tenancy\TenantContext::class);

        // Unauthenticated AccessContext
        Auth::logout();
        AccessContext::clear();
        expect(AccessContext::restrictToOwnerId())->toBeNull()
            ->and(AccessContext::restrictedTerritoryIds())->toBeNull()
            ->and(AccessContext::shouldMaskFields())->toBeFalse();

        // Overridden AccessContext
        AccessContext::restrictTo(42);
        expect(AccessContext::restrictToOwnerId())->toBe(42);
        AccessContext::clear();

        $team = Team::factory()->create();
        $rep = User::factory()->create(['current_team_id' => $team->id]);
        setPermissionsTeamId($team->id);
        $rep->assignRole(RoleEnum::SalesRep->value);

        $territory = Territory::create(['team_id' => $team->id, 'name' => 'North']);
        $territory->users()->attach($rep->id);

        Auth::forgetGuards();
        $this->actingAs($rep);
        setPermissionsTeamId($team->id);
        expect(AccessContext::restrictToOwnerId())->toBe($rep->id)
            ->and(AccessContext::restrictedTerritoryIds())->toBe([$territory->id])
            ->and(AccessContext::shouldMaskFields())->toBeFalse();

        $freeUser = User::factory()->create(['current_team_id' => $team->id]);
        setPermissionsTeamId($team->id);
        $freeUser->assignRole(RoleEnum::Free->value);
        Auth::forgetGuards();
        $this->actingAs($freeUser);
        setPermissionsTeamId($team->id);
        expect(AccessContext::shouldMaskFields())->toBeTrue();

        $adminUser = User::factory()->create(['current_team_id' => $team->id]);
        setPermissionsTeamId($team->id);
        $adminUser->assignRole(RoleEnum::Admin->value);
        Auth::forgetGuards();
        $this->actingAs($adminUser);
        setPermissionsTeamId($team->id);
        expect(AccessContext::restrictToOwnerId())->toBeNull()
            ->and(AccessContext::restrictedTerritoryIds())->toBeNull()
            ->and(AccessContext::shouldMaskFields())->toBeFalse();

        // PortalCustomer
        expect(PortalCustomer::forEmail(null))->toBeNull()
            ->and(PortalCustomer::forEmail('nobody@example.test'))->toBeNull()
            ->and(PortalCustomer::forEmail($adminUser->email))->toBeNull()
            ->and(PortalCustomer::existsForEmail($adminUser->email))->toBeFalse();

        $customer = User::factory()->create(['email' => 'portal@example.test']);
        setPermissionsTeamId(null);
        $customer->assignRole(RoleEnum::Customer->value);
        expect(PortalCustomer::forEmail('portal@example.test')?->id)->toBe($customer->id)
            ->and(PortalCustomer::existsForEmail('portal@example.test'))->toBeTrue();

        // TenantModels::all() normal discovery + edge cases (!class_exists and !isInstantiable)
        expect(TenantModels::all())->toContain(Webhook::class);

        $originalBasePath = base_path();
        $tmpBase = sys_get_temp_dir().'/tenant-models-cov-'.uniqid();
        @mkdir($tmpBase.'/app/Models', 0777, true);
        file_put_contents($tmpBase.'/app/Models/GhostNonExistentModel.php', "<?php\n");
        file_put_contents($tmpBase.'/app/Models/AbstractTenantModelForCoverage.php', "<?php\n");

        try {
            app()->instance('path.base', $tmpBase);
            expect(TenantModels::all())->toBeArray();
        } finally {
            app()->instance('path.base', $originalBasePath);
            @unlink($tmpBase.'/app/Models/GhostNonExistentModel.php');
            @unlink($tmpBase.'/app/Models/AbstractTenantModelForCoverage.php');
            @rmdir($tmpBase.'/app/Models');
            @rmdir($tmpBase);
        }
    });

    it('covers TeamBackupService, TeamRestoreService, ImportTeamBackupService, and TeamCloneService', function (): void {
        setupLegacyOrganizationsAndRolesTables();
        Storage::fake('local');
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);

        // Tempnam failure and zip open failure branches on TeamBackupService
        $backupService = new TeamBackupService();
        $GLOBALS['__legacy_test_tempnam_override'] = false;
        expect(fn () => $backupService->backup($team, 'local'))->toThrow(RuntimeException::class, 'Could not allocate a temp file');

        $GLOBALS['__legacy_test_tempnam_override'] = '/nonexistent_dir_xyz/cannot_open.zip';
        expect(fn () => $backupService->backup($team, 'local'))->toThrow(RuntimeException::class, 'Could not open the backup archive');
        $GLOBALS['__legacy_test_tempnam_override'] = null;

        // Populate tenant models for backup/restore/import
        $callSettingId = DB::table('call_settings')->insertGetId([
            'team_id' => $team->id,
            'name' => 'recording',
            'value' => 'on',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $webhookId = DB::table('webhooks')->insertGetId([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'owner_id' => $owner->id,
            'call_setting_id' => $callSettingId,
            'external_id' => 999,
            'name' => 'Hook 1',
            'url' => 'https://example.test/1',
            'events' => json_encode(['contact.created']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('webhooks')->insert([
            'team_id' => $team->id,
            'user_id' => null,
            'owner_id' => null,
            'call_setting_id' => null,
            'external_id' => null,
            'name' => 'Hook 2',
            'url' => 'https://example.test/2',
            'events' => json_encode(['contact.updated']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('crm_webhook_deliveries')->insert([
            [
                'team_id' => $team->id,
                'webhook_id' => $webhookId,
                'event' => 'contact.created',
                'success' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Also test TeamBackupService when one EXTRA_TABLES table is missing and one TenantModels table is missing
        Schema::dropIfExists('whats_app_numbers');
        Schema::dropIfExists('team_subscriptions');
        $backupResult = $backupService->backup($team, 'local');
        setupLegacyOrganizationsAndRolesTables();
        // Recreate whats_app_numbers (so it is in DB but missing from the first zip) and drop sso_connections (so it is missing from DB)
        Schema::create('whats_app_numbers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('number')->nullable();
            $table->timestamps();
        });
        Schema::dropIfExists('sso_connections');

        $backupRow = TeamBackup::create([
            'team_id' => $team->id,
            'disk' => 'local',
            'path' => $backupResult['path'],
            'size_bytes' => $backupResult['size'],
            'status' => 'completed',
        ]);

        // TeamRestoreService error branches
        $restoreService = new TeamRestoreService();
        $pendingBackup = TeamBackup::create(['team_id' => $team->id, 'disk' => 'local', 'status' => 'pending']);
        expect(fn () => $restoreService->restore($pendingBackup))->toThrow(TeamRestoreException::class, 'not a completed backup');

        $missingFileBackup = TeamBackup::create(['team_id' => $team->id, 'disk' => 'local', 'path' => 'backups/missing.zip', 'status' => 'completed']);
        expect(fn () => $restoreService->restore($missingFileBackup))->toThrow(TeamRestoreException::class, 'Backup file is missing');

        $GLOBALS['__legacy_test_tempnam_override'] = false;
        expect(fn () => $restoreService->restore($backupRow))->toThrow(TeamRestoreException::class, 'Could not allocate a temp file');
        $GLOBALS['__legacy_test_tempnam_override'] = null;

        Storage::disk('local')->put('backups/corrupt.zip', 'not-a-zip');
        $corruptBackup = TeamBackup::create(['team_id' => $team->id, 'disk' => 'local', 'path' => 'backups/corrupt.zip', 'status' => 'completed']);
        expect(fn () => $restoreService->restore($corruptBackup))->toThrow(TeamRestoreException::class, 'Could not open the backup archive');

        // Zip without manifest.json
        $noManifestTmp = \tempnam(sys_get_temp_dir(), 'nomanifest');
        $z = new ZipArchive();
        $z->open($noManifestTmp, ZipArchive::OVERWRITE);
        $z->addFromString('dummy.txt', 'hello');
        $z->close();
        Storage::disk('local')->put('backups/nomanifest.zip', (string) file_get_contents($noManifestTmp));
        @unlink($noManifestTmp);
        $noManifestBackup = TeamBackup::create(['team_id' => $team->id, 'disk' => 'local', 'path' => 'backups/nomanifest.zip', 'status' => 'completed']);
        expect(fn () => $restoreService->restore($noManifestBackup))->toThrow(TeamRestoreException::class, 'no manifest');

        // Zip with bad format_version
        $badFormatTmp = \tempnam(sys_get_temp_dir(), 'badfmt');
        $z->open($badFormatTmp, ZipArchive::OVERWRITE);
        $z->addFromString('manifest.json', json_encode(['format_version' => 999]));
        $z->close();
        Storage::disk('local')->put('backups/badformat.zip', (string) file_get_contents($badFormatTmp));
        @unlink($badFormatTmp);
        $badFormatBackup = TeamBackup::create(['team_id' => $team->id, 'disk' => 'local', 'path' => 'backups/badformat.zip', 'status' => 'completed']);
        expect(fn () => $restoreService->restore($badFormatBackup))->toThrow(TeamRestoreException::class, 'Unsupported or unreadable backup format');

        // Zip whose target team no longer exists
        $missingTeamTmp = \tempnam(sys_get_temp_dir(), 'missingteam');
        $z->open($missingTeamTmp, ZipArchive::OVERWRITE);
        $z->addFromString('manifest.json', json_encode(['format_version' => 1, 'team' => ['id' => 999999, 'name' => 'Gone']]));
        $z->close();
        Storage::disk('local')->put('backups/missingteam.zip', (string) file_get_contents($missingTeamTmp));
        @unlink($missingTeamTmp);
        $missingTeamBackup = TeamBackup::create(['team_id' => $team->id, 'disk' => 'local', 'path' => 'backups/missingteam.zip', 'status' => 'completed']);
        expect(fn () => $restoreService->restore($missingTeamBackup))->toThrow(TeamRestoreException::class, 'Target team no longer exists');

        // TeamNotEmptyException when team still holds rows
        expect(fn () => $restoreService->restore($backupRow))->toThrow(TeamNotEmptyException::class);

        // Empty the team's rows
        DB::table('crm_webhook_deliveries')->where('team_id', $team->id)->delete();
        DB::table('webhooks')->where('team_id', $team->id)->delete();
        DB::table('call_settings')->where('team_id', $team->id)->delete();

        $partialZipPath = Storage::disk('local')->path($backupResult['path']);

        $restoredCounts = $restoreService->restore($backupRow);
        expect($restoredCounts)->toHaveKey('Webhook', 2);

        // ImportTeamBackupService
        $importService = new ImportTeamBackupService();
        expect(fn () => $importService->import('/nonexistent/archive.zip', null, $owner))
            ->toThrow(TeamImportException::class, 'Could not open the backup archive');

        expect(fn () => $importService->import(Storage::disk('local')->path('backups/badformat.zip'), null, $owner))
            ->toThrow(TeamImportException::class, 'Unsupported or unreadable backup format');

        $importer = User::factory()->create();
        $importedTeam = $importService->import($partialZipPath, 'Imported Org', $importer);
        expect($importedTeam->name)->toBe('Imported Org')
            ->and($importedTeam->user_id)->toBe($importer->id);

        // TeamCloneService
        Schema::create('pipelines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('name');
        });
        Schema::create('stages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('pipeline_id')->nullable();
            $table->string('name');
        });
        Schema::create('workflow_conditions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            // Intentionally omit workflow_action_id to cover drift guard $edges === []
            $table->string('field')->default('status');
        });
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
        });

        $pipeId = DB::table('pipelines')->insertGetId(['team_id' => $team->id, 'name' => 'Sales']);
        DB::table('stages')->insert([
            ['team_id' => $team->id, 'pipeline_id' => $pipeId, 'name' => 'Qualified'],
            ['team_id' => $team->id, 'pipeline_id' => null, 'name' => 'Unassigned'],
        ]);
        DB::table('workflow_conditions')->insert(['team_id' => $team->id, 'field' => 'amount']);
        $parentMenuId = DB::table('menus')->insertGetId(['team_id' => $team->id, 'parent_id' => null, 'name' => 'Root']);
        DB::table('menus')->insert(['team_id' => $team->id, 'parent_id' => $parentMenuId, 'name' => 'Child']);

        $clonedTeam = (new TeamCloneService())->clone($team, 'Cloned Team', $importer);
        expect($clonedTeam->name)->toBe('Cloned Team')
            ->and(DB::table('stages')->where('team_id', $clonedTeam->id)->count())->toBe(2);
    });

    it('covers TeamManagementService and all branches', function (): void {
        setupLegacyOrganizationsAndRolesTables();
        PermissionCatalog::sync();
        $service = app(TeamManagementService::class);

        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);
        $owner->teams()->attach($team, ['role' => 'admin']);

        $coAdmin = User::factory()->create();
        $team->users()->attach($coAdmin, ['role' => 'admin']);
        setPermissionsTeamId($team->id);
        $coAdmin->assignRole(RoleEnum::Admin->value);

        $stranger = User::factory()->create();

        // Unauthorized actor
        $this->actingAs($stranger);
        expect(fn () => $service->createTeamMember($team, 'Bob', 'bob@example.test', 'ValidPass123!@#', RoleEnum::SalesRep))
            ->toThrow(InvalidArgumentException::class, 'You cannot manage members for this team.');

        // Authorized actor (owner)
        $this->actingAs($owner);
        expect(fn () => $service->createTeamMember($team, 'Bob', 'bob@example.test', 'ValidPass123!@#', RoleEnum::SuperAdmin))
            ->toThrow(InvalidArgumentException::class, 'That role cannot be assigned to a new team member.');

        expect(fn () => $service->createTeamMember($team, 'Owner Dup', $owner->email, 'ValidPass123!@#', RoleEnum::SalesRep))
            ->toThrow(InvalidArgumentException::class, 'already registered');

        expect(fn () => $service->createTeamMember($team, 'Bob', 'bob@example.test', 'short', RoleEnum::SalesRep))
            ->toThrow(InvalidArgumentException::class, 'at least 12 characters');

        $member = $service->createTeamMember($team, 'Bob', 'bob@example.test', 'ValidPass123!@#', RoleEnum::SalesRep);
        expect($member->email)->toBe('bob@example.test');

        // addTeamMember
        expect(fn () => $service->addTeamMember($stranger, $team, RoleEnum::SuperAdmin))
            ->toThrow(InvalidArgumentException::class, 'That role cannot be assigned to a team member.');

        $service->addTeamMember($stranger, $team, RoleEnum::Manager);
        expect($stranger->fresh()->belongsToTeam($team))->toBeTrue();

        // Self-management guard
        expect(fn () => $service->removeTeamMember($owner, $team))
            ->toThrow(InvalidArgumentException::class, 'Choose another member of this team.');

        // Co-admin trying to remove or re-role the team owner hits the owner guards
        $this->actingAs($coAdmin);
        expect(fn () => $service->removeTeamMember($owner, $team))
            ->toThrow(InvalidArgumentException::class, 'The team owner cannot be removed.');

        expect(fn () => $service->changeTeamRole($owner, $team, RoleEnum::Manager))
            ->toThrow(InvalidArgumentException::class, "The team owner's role cannot be changed.");

        $customRole = SpatieRole::create(['name' => 'Custom Support', 'guard_name' => 'web', 'team_id' => $team->id]);
        $customRole->syncPermissions(['view_contact']);
        expect(fn () => $service->assignCustomRole($owner, $team, $customRole))
            ->toThrow(InvalidArgumentException::class, "The team owner's role cannot be changed.");

        // provisionTeamRole guards
        $nonMember = User::factory()->create();
        expect(fn () => $service->provisionTeamRole($nonMember, $team, RoleEnum::SalesRep))
            ->toThrow(InvalidArgumentException::class, 'The user is not a member of this team.');

        expect(fn () => $service->provisionTeamRole($member, $team, RoleEnum::SuperAdmin))
            ->toThrow(InvalidArgumentException::class, 'is not assignable to a team member');

        // assignCustomRole guards and success
        $otherTeam = Team::factory()->create();
        setPermissionsTeamId($otherTeam->id);
        $otherRole = SpatieRole::create(['name' => 'Other Role', 'guard_name' => 'web', 'team_id' => $otherTeam->id]);
        setPermissionsTeamId($team->id);
        expect(fn () => $service->assignCustomRole($member, $team, $otherRole))
            ->toThrow(InvalidArgumentException::class, 'The role does not belong to this team.');

        $badRoleId = DB::table('roles')->insertGetId([
            'name' => 'admin',
            'guard_name' => 'web',
            'team_id' => $team->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $badRole = SpatieRole::findOrFail($badRoleId);
        expect(fn () => $service->assignCustomRole($member, $team, $badRole))
            ->toThrow(InvalidArgumentException::class, 'contains permissions that cannot be assigned');

        $service->assignCustomRole($member, $team, $customRole);
        $service->removeTeamMember($member, $team);
        expect($team->users()->whereKey($member->id)->exists())->toBeFalse();

        // createDefaultTeamForUser & assignUserToDefaultTeam & switchTeam
        Team::query()->delete();
        Branch::query()->delete();
        $newUser = User::factory()->create();
        expect(fn () => $service->createDefaultTeamForUser($newUser))
            ->toThrow(Exception::class, 'No default branch found');

        // Fallback to personal team when no default team and no branch
        $service->assignUserToDefaultTeam($newUser);
        expect($newUser->fresh()->current_team_id)->not->toBeNull();

        // With a Branch present and no non-personal team
        Team::query()->where('personal_team', false)->delete();
        Branch::create(['name' => 'HQ']);
        $user2 = User::factory()->create();
        $service->assignUserToDefaultTeam($user2);
        expect($user2->fresh()->currentTeam->name)->toBe('HQ Team');

        // Assign another user to existing default team
        $user3 = User::factory()->create();
        $service->assignUserToDefaultTeam($user3);
        expect($user3->fresh()->current_team_id)->toBe($user2->fresh()->current_team_id);

        // switchTeam
        $unjoinedTeam = Team::factory()->create(['personal_team' => false]);
        expect(fn () => $service->switchTeam($user3, $unjoinedTeam))
            ->toThrow(Exception::class, 'User does not belong to the specified team.');
        $service->switchTeam($user3, $user2->fresh()->currentTeam);
    });

    it('covers Console commands and Jobs for team backup, clone, import, and restore', function (): void {
        Application::starting(function ($artisan): void {
            $artisan->resolveCommands([
                BackupTeam::class,
                CloneTeam::class,
                ImportTeam::class,
                RestoreTeam::class,
            ]);
        });
        Storage::fake('local');
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);

        // BackupTeam command
        Queue::fake();
        Artisan::call('team:backup', ['team' => 999999]);
        Artisan::call('team:backup', ['team' => $team->id]);
        Queue::assertPushed(GenerateTeamBackup::class);

        // CloneTeam command
        Artisan::call('team:clone', ['source' => 999999]);
        Artisan::call('team:clone', ['source' => $team->id, '--owner' => 999999]);
        Artisan::call('team:clone', ['source' => $team->id, '--name' => 'CLI Clone']);
        expect(Team::where('name', 'CLI Clone')->exists())->toBeTrue();

        // RestoreTeam command
        Artisan::call('team:restore', ['backup' => 999999]);
        $backup = TeamBackup::create(['team_id' => $team->id, 'disk' => 'local', 'status' => 'pending']);
        Artisan::call('team:restore', ['backup' => $backup->id]);
        Queue::assertPushed(RestoreTeamBackup::class);

        // GenerateTeamBackup job success + failure
        $job = new GenerateTeamBackup($backup->id);
        $job->handle(new TeamBackupService());
        expect($backup->fresh()->status)->toBe('completed');

        $failingBackup = TeamBackup::create(['team_id' => $team->id, 'disk' => 'local', 'status' => 'pending']);
        $GLOBALS['__legacy_test_tempnam_override'] = false;
        expect(fn () => (new GenerateTeamBackup($failingBackup->id))->handle(new TeamBackupService()))
            ->toThrow(RuntimeException::class);
        $GLOBALS['__legacy_test_tempnam_override'] = null;
        expect($failingBackup->fresh()->status)->toBe('failed');

        // ImportTeam command
        $zipFullPath = Storage::disk('local')->path($backup->fresh()->path);
        Artisan::call('team:import', ['path' => '/nonexistent/file.zip']);
        Artisan::call('team:import', ['path' => $zipFullPath, '--owner' => 999999]);
        Artisan::call('team:import', ['path' => $zipFullPath, '--name' => 'CLI Imported']);
        expect(Team::where('name', 'CLI Imported')->exists())->toBeTrue();

        // ImportTeamBackup job success + failure
        (new ImportTeamBackup('local', $backup->fresh()->path, 'Job Imported', $owner->id))
            ->handle(new ImportTeamBackupService());
        expect(Team::where('name', 'Job Imported')->exists())->toBeTrue();

        Storage::disk('local')->put('backups/invalid.zip', 'corrupt');
        expect(fn () => (new ImportTeamBackup('local', 'backups/invalid.zip', 'Fail Import', $owner->id))
            ->handle(new ImportTeamBackupService()))->toThrow(TeamImportException::class);

        // RestoreTeamBackup job: with null notifyUserId, nonexistent notifyUserId, valid notifyUserId, and failure
        (new RestoreTeamBackup($backup->id, null))->handle(new TeamRestoreService());
        (new RestoreTeamBackup($backup->id, 999999))->handle(new TeamRestoreService());
        (new RestoreTeamBackup($backup->id, $owner->id))->handle(new TeamRestoreService());

        expect(fn () => (new RestoreTeamBackup($failingBackup->id, $owner->id))->handle(new TeamRestoreService()))
            ->toThrow(TeamRestoreException::class);
    });

    it('covers Jetstream actions, TeamInvitationController, Livewire CreateTeam components, Middleware, and Listeners', function (): void {
        PermissionCatalog::sync();
        Mail::fake();

        Jetstream::role('admin', 'Administrator', ['read', 'create', 'update', 'delete']);
        Jetstream::role('editor', 'Editor', ['read', 'create', 'update']);

        $owner = User::factory()->create();
        $creator = new JetstreamCreateTeam();
        $team = $creator->create($owner, ['name' => 'Created Via Action']);
        expect($team->name)->toBe('Created Via Action');

        (new UpdateTeamName())->update($owner, $team, ['name' => 'Renamed Team']);
        expect($team->fresh()->name)->toBe('Renamed Team');

        // InviteTeamMember
        $invitee = User::factory()->create(['email' => 'invitee@example.test']);
        $inviter = new InviteTeamMember();
        $inviter->invite($owner, $team, $invitee->email, 'admin');
        expect($team->teamInvitations()->where('email', $invitee->email)->exists())->toBeTrue();

        // AddTeamMember consumes existing invitation
        $adder = new AddTeamMember();
        $adder->add($owner, $team, $invitee->email, 'admin');
        $team = $team->fresh();
        expect($team->hasUserWithEmail($invitee->email))->toBeTrue();

        // Validation when user is already on team
        expect(fn () => $inviter->invite($owner, $team, $invitee->email, 'admin'))
            ->toThrow(ValidationException::class);
        expect(fn () => $adder->add($owner, $team, $invitee->email, 'admin'))
            ->toThrow(ValidationException::class);

        // Also cover Jetstream::hasRoles() === false ternary branch in AddTeamMember & InviteTeamMember
        $savedRoles = Jetstream::$roles;
        Jetstream::$roles = [];
        $member2 = User::factory()->create(['email' => 'member2@example.test']);
        $inviter->invite($owner, $team, $member2->email, null);
        $adder->add($owner, $team, $member2->email, null);
        Jetstream::$roles = $savedRoles;

        // RemoveTeamMember
        $remover = new RemoveTeamMember();
        expect(fn () => $remover->remove($invitee, $team, $member2))->toThrow(AuthorizationException::class);
        expect(fn () => $remover->remove($owner, $team, $owner))->toThrow(ValidationException::class);
        $remover->remove($owner, $team, $member2);

        // TeamInvitationController
        $controller = new TeamInvitationController();
        $sendReq = Request::create('/team-invitations', 'POST', [
            'email' => 'newguest@example.test',
            'role' => 'admin',
            'team_id' => $team->id,
        ]);
        $sendReq->setUserResolver(fn () => $owner);
        $resp = $controller->sendInvitation($sendReq, $inviter);
        expect($resp->getStatusCode())->toBe(302);

        // acceptInvitation unauthenticated
        $unauthReq = Request::create('/accept', 'GET');
        expect(fn () => $controller->acceptInvitation($unauthReq, 1))->toThrow(HttpException::class);

        // acceptInvitation with 'admin' role (maps to SalesRep)
        $guestUser = User::factory()->create(['email' => 'newguest@example.test']);
        $inv1 = $team->teamInvitations()->where('email', 'newguest@example.test')->firstOrFail();
        $acceptReq1 = Request::create('/accept', 'GET');
        $acceptReq1->setUserResolver(fn () => $guestUser);
        $resp1 = $controller->acceptInvitation($acceptReq1, $inv1->id);
        expect($resp1->getStatusCode())->toBe(302);

        // acceptInvitation with 'manager' role (calls provisionTeamRole)
        $managerGuest = User::factory()->create(['email' => 'mgrguest@example.test']);
        $inv2 = $team->teamInvitations()->create([
            'email' => 'mgrguest@example.test',
            'role' => RoleEnum::Manager->value,
            'token' => 'tok_mgr',
        ]);
        $acceptReq2 = Request::create('/accept', 'GET');
        $acceptReq2->setUserResolver(fn () => $managerGuest);
        $resp2 = $controller->acceptInvitation($acceptReq2, $inv2->id);
        expect($resp2->getStatusCode())->toBe(302);

        // Livewire CreateTeam components (both Http\Livewire\CreateTeam and Livewire\CreateTeam)
        Route::get('/_test/edit-team/{team}', fn () => 'ok')->name('filament.pages.edit-team');
        Route::get('/_test/tenant-profile/{tenant}', fn () => 'ok')->name('filament.app.tenant.profile');
        Route::get('/_test/login', fn () => 'login')->name('login');
        Route::get('/_test/home', fn () => 'home')->name('home');
        app('router')->getRoutes()->refreshNameLookups();

        $this->actingAs($owner);
        $httpLivewire = new LegacyHttpLivewireCreateTeam();
        $httpLivewire->state = ['name' => 'Http Livewire Team'];
        $httpLivewire->addRulesFromOutside(['state.name' => 'required']);
        expect($httpLivewire->createTeam($creator))->not->toBeNull();

        $appLivewire = new LegacyLivewireCreateTeam();
        $appLivewire->state = ['name' => 'App Livewire Team'];
        expect($appLivewire->createTeam($creator))->not->toBeNull();

        // Middleware: AssignDefaultTeam & SetFilamentDefaultTenant & SetPermissionsTeamContext & SetTenantContext
        expect(new SetPermissionsTeamContext())->toBeInstanceOf(Liberu\Foundation\Tenancy\Http\Middleware\SetPermissionsTeamContext::class)
            ->and(new SetTenantContext())->toBeInstanceOf(Liberu\Foundation\Tenancy\Http\Middleware\SetTenantContext::class);

        $teamlessUser = User::factory()->create(['current_team_id' => null]);
        Team::where('user_id', $teamlessUser->id)->delete();
        $this->actingAs($teamlessUser);
        Filament::setTenant(null);

        $assignMiddleware = new AssignDefaultTeam();
        $assignMiddleware->handle(Request::create('/app'), fn () => response('ok'));
        expect(Filament::getTenant())->not->toBeNull();

        Filament::setTenant(null);
        $setDefaultTenantMiddleware = new SetFilamentDefaultTenant();
        $setDefaultTenantMiddleware->handle(Request::create('/app'), fn () => response('ok'));

        // Middleware: TeamsPermission
        $tp = new TeamsPermission();
        Auth::logout();
        expect($tp->handle(Request::create('/app'), fn () => response('ok'))->getStatusCode())->toBe(302);

        // Admin user passes immediately
        setPermissionsTeamId($team->id);
        $owner->assignRole(RoleEnum::Admin->value);
        $this->actingAs($owner);
        expect($tp->handle(Request::create('/app'), fn () => response('ok'))->getContent())->toBe('ok');

        // Non-admin user without currentTeam passes
        $noTeamRep = User::factory()->create(['current_team_id' => null]);
        $this->actingAs($noTeamRep);
        expect($tp->handle(Request::create('/app'), fn () => response('ok'))->getContent())->toBe('ok');

        // Non-admin user with currentTeam: tenant mismatch, route without name, non-resource route, unknown action, missing permission, denied permission, allowed permission
        $repUser = User::factory()->create(['current_team_id' => $team->id]);
        $this->actingAs($repUser);

        $mismatchReq = Request::create('/app');
        $mismatchRoute = (new IlluminateRoute('GET', '/app/{tenant}', fn () => 'ok'))->bind($mismatchReq);
        $mismatchRoute->setParameter('tenant', $team->id + 999);
        $mismatchReq->setRouteResolver(fn () => $mismatchRoute);
        expect($tp->handle($mismatchReq, fn () => response('ok'))->getStatusCode())->toBe(302);

        // No route
        expect($tp->handle(Request::create('/app'), fn () => response('ok'))->getContent())->toBe('ok');

        // Non-filament route name
        $otherRouteReq = Request::create('/other');
        $otherRoute = (new IlluminateRoute('GET', '/other', ['as' => 'dashboard']))->bind($otherRouteReq);
        $otherRouteReq->setRouteResolver(fn () => $otherRoute);
        expect($tp->handle($otherRouteReq, fn () => response('ok'))->getContent())->toBe('ok');

        // Unknown action
        $unknownActReq = Request::create('/app');
        $unknownActRoute = (new IlluminateRoute('GET', '/app', ['as' => 'filament.app.resources.contacts.archive']))->bind($unknownActReq);
        $unknownActReq->setRouteResolver(fn () => $unknownActRoute);
        expect($tp->handle($unknownActReq, fn () => response('ok'))->getContent())->toBe('ok');

        // Valid resource action where permission does NOT exist in DB yet
        $resReq = Request::create('/app');
        $resRoute = (new IlluminateRoute('GET', '/app', ['as' => 'filament.app.resources.contacts.index']))->bind($resReq);
        $resReq->setRouteResolver(fn () => $resRoute);
        expect($tp->handle($resReq, fn () => response('ok'))->getContent())->toBe('ok');

        // Now create `view:Contact` permission: user lacks it -> 302 redirect; user has it -> passes
        $colonPerm = Permission::firstOrCreate(['name' => 'view:Contact', 'guard_name' => 'web']);
        expect($tp->handle($resReq, fn () => response('ok'))->getStatusCode())->toBe(302);

        setPermissionsTeamId($team->id);
        $repUser->givePermissionTo($colonPerm);
        expect($tp->handle($resReq, fn () => response('ok'))->getContent())->toBe('ok');

        // Listeners
        expect(new SwitchTeam())->toBeInstanceOf(Liberu\Foundation\Tenancy\Listeners\SwitchTeam::class);
        (new NotifyTeamMembers())->handle(new ContactUpdated());
        (new AssignDefaultTeamRole(app(TeamManagementService::class)))->handle(new TeamMemberAdded($team, $repUser));
        (new CreatePersonalTeam(app(TeamManagementService::class)))->handle(new Registered(User::factory()->create()));
        (new ProvisionZernioProfile(new ZernioTenantService(new ZernioClient())))->handle(new TeamCreated($team));

        // DeleteUser and DeleteUserWithTeams
        $userToDelete1 = User::factory()->create();
        (new DeleteUser())->delete($userToDelete1);
        expect(User::find($userToDelete1->id))->toBeNull();

        $userToDelete2 = User::factory()->create();
        Team::factory()->create(['user_id' => $userToDelete2->id, 'personal_team' => true]);
        (new DeleteUserWithTeams(new DeleteTeam()))->delete($userToDelete2);
        expect(User::find($userToDelete2->id))->toBeNull();
    });
}
