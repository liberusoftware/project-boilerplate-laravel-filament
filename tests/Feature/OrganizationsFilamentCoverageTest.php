<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\App\Resources {
    if (! function_exists(__NAMESPACE__.'\\class_exists')) {
        function class_exists(string $class, bool $autoload = true): bool
        {
            if (($GLOBALS['__test_hide_role_permission_builder'] ?? false) && str_ends_with($class, 'RolePermissionBuilder')) {
                return false;
            }

            return \class_exists($class, $autoload);
        }
    }
}

namespace {
    use App\Models\AuditLog;
    use App\Models\Territory;
    use App\Models\User;
    use Filament\Actions\Action;
    use Filament\Facades\Filament;
    use Filament\Forms\Components\Select;
    use Filament\Forms\Components\TextInput;
    use Filament\Resources\Pages\CreateRecord;
    use Filament\Resources\Pages\ListRecords;
    use Filament\Schemas\Schema;
    use Filament\Tables\Contracts\HasTable;
    use Filament\Tables\Table;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Queue;
    use Illuminate\Support\Facades\Schema as DbSchema;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Validation\ValidationException;
    use Laravel\Jetstream\Jetstream;
    use Liberu\Foundation\Organizations\Jobs\GenerateTeamBackup;
    use Liberu\Foundation\Organizations\Jobs\ImportTeamBackup;
    use Liberu\Foundation\Organizations\Jobs\RestoreTeamBackup;
    use Liberu\Foundation\Organizations\Models\Membership;
    use Liberu\Foundation\Organizations\Models\Team;
    use Liberu\Foundation\Organizations\Models\TeamBackup;
    use Liberu\Foundation\Organizations\Models\TeamInvitation;
    use Liberu\Foundation\Organizations\Policies\TeamPolicy;
    use Liberu\Foundation\Organizations\Services\TeamManagementService;
    use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamMemberResource;
    use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamMemberResource\Pages\ListTeamMembers;
    use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleLogResource;
    use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleLogResource\Pages\ListTeamRoleLogs;
    use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource;
    use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\CreateTeamRole;
    use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\EditTeamRole;
    use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\ListTeamRoles;
    use Liberu\Foundation\OrganizationsFilament\Http\Middleware\SetFilamentDefaultTenant;
    use Liberu\Foundation\OrganizationsFilament\Resources\TeamBackupResource;
    use Liberu\Foundation\OrganizationsFilament\Resources\TeamBackupResource\Pages\ListTeamBackups;
    use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource;
    use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\ListTeams;
    use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource;
    use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\CreateTeamSubscription;
    use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\EditTeamSubscription;
    use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\ListTeamSubscriptions;
    use Liberu\Foundation\RolesPermissions\Enums\Role as RoleEnum;
    use Liberu\Foundation\RolesPermissions\Support\PermissionCatalog;
    use Spatie\Permission\Models\Role as SpatieRole;
    use Symfony\Component\HttpFoundation\StreamedResponse;

    if (! function_exists('setupOrganizationsAndRolesTables')) {
        function setupOrganizationsAndRolesTables(): void
        {
            if (! DbSchema::hasColumn('teams', 'archived_at')) {
                DbSchema::table('teams', function (Blueprint $table): void {
                    $table->timestamp('archived_at')->nullable();
                });
            }
            if (! DbSchema::hasColumn('teams', 'setup_completed_at')) {
                DbSchema::table('teams', function (Blueprint $table): void {
                    $table->timestamp('setup_completed_at')->nullable();
                    $table->string('zernio_profile_id')->nullable();
                    $table->string('portal_brand_name')->nullable();
                    $table->string('portal_logo_url')->nullable();
                    $table->string('portal_logo_path')->nullable();
                    $table->unsignedBigInteger('branch_id')->nullable();
                    $table->json('marketing_preferences')->nullable();
                });
            }
            if (! DbSchema::hasColumn('team_invitations', 'token')) {
                DbSchema::table('team_invitations', function (Blueprint $table): void {
                    $table->string('token', 64)->nullable();
                });
            }
            if (! DbSchema::hasTable('team_backups')) {
                DbSchema::create('team_backups', function (Blueprint $table): void {
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
            if (! DbSchema::hasTable('team_subscriptions')) {
                DbSchema::create('team_subscriptions', function (Blueprint $table): void {
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
            if (! DbSchema::hasTable('territories')) {
                DbSchema::create('territories', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                    $table->unsignedBigInteger('manager_id')->nullable();
                    $table->string('name');
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                });
            }
            if (! DbSchema::hasTable('territory_user')) {
                DbSchema::create('territory_user', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
                    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                    $table->string('role')->nullable();
                    $table->timestamps();
                });
            }
            if (! DbSchema::hasTable('audit_logs')) {
                DbSchema::create('audit_logs', function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('team_id')->nullable();
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->string('action')->nullable();
                    $table->string('event')->nullable();
                    $table->text('description')->nullable();
                    $table->string('auditable_type')->nullable();
                    $table->unsignedBigInteger('auditable_id')->nullable();
                    $table->json('changes')->nullable();
                    $table->json('old_values')->nullable();
                    $table->json('new_values')->nullable();
                    $table->string('ip_address')->nullable();
                    $table->string('user_agent')->nullable();
                    $table->timestamps();
                });
            }
            if (! DbSchema::hasTable('branches')) {
                DbSchema::create('branches', function (Blueprint $table): void {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });
            }
            if (! DbSchema::hasTable('notifications')) {
                DbSchema::create('notifications', function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->string('type');
                    $table->morphs('notifiable');
                    $table->text('data');
                    $table->timestamp('read_at')->nullable();
                    $table->timestamps();
                });
            }
            if (! DbSchema::hasTable('call_settings')) {
                DbSchema::create('call_settings', function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('team_id')->nullable();
                    $table->string('name');
                    $table->string('value')->nullable();
                    $table->text('description')->nullable();
                    $table->timestamps();
                });
            } elseif (! DbSchema::hasColumn('call_settings', 'team_id')) {
                DbSchema::table('call_settings', function (Blueprint $table): void {
                    $table->unsignedBigInteger('team_id')->nullable();
                });
            }
            if (! DbSchema::hasTable('webhooks')) {
                DbSchema::create('webhooks', function (Blueprint $table): void {
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
            if (! DbSchema::hasColumn('webhooks', 'owner_id')) {
                DbSchema::table('webhooks', function (Blueprint $table): void {
                    $table->unsignedBigInteger('owner_id')->nullable();
                });
            }
            if (! DbSchema::hasColumn('webhooks', 'call_setting_id')) {
                DbSchema::table('webhooks', function (Blueprint $table): void {
                    $table->unsignedBigInteger('call_setting_id')->nullable();
                });
            }
            if (! DbSchema::hasColumn('webhooks', 'external_id')) {
                DbSchema::table('webhooks', function (Blueprint $table): void {
                    $table->unsignedBigInteger('external_id')->nullable();
                });
            }
            if (! DbSchema::hasTable('crm_webhook_deliveries')) {
                DbSchema::create('crm_webhook_deliveries', function (Blueprint $table): void {
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
                    ],
                ],
            ]);
        }
    }

    function makeDummyFilamentTableLivewire(): HasTable
    {
        $livewire = Mockery::mock(ListRecordsStubForFilament::class);
        $livewire->shouldIgnoreMissing();

        return $livewire;
    }

    if (! class_exists(ListRecordsStubForFilament::class)) {
        abstract class ListRecordsStubForFilament extends ListRecords {}
    }

    function getClosureProperty(object $object, string $property): mixed
    {
        $ref = new ReflectionObject($object);
        while ($ref) {
            if ($ref->hasProperty($property)) {
                $prop = $ref->getProperty($property);
                $prop->setAccessible(true);

                return $prop->getValue($object);
            }
            $ref = $ref->getParentClass();
        }

        return null;
    }

    beforeEach(function (): void {
        setupOrganizationsAndRolesTables();
        Jetstream::useTeamModel(Team::class);
        Jetstream::useMembershipModel(Membership::class);
        Jetstream::useTeamInvitationModel(TeamInvitation::class);
        Gate::policy(Team::class, TeamPolicy::class);

        User::resolveRelationUsing('territories', fn (User $user) => $user->belongsToMany(Territory::class, 'territory_user')->withTimestamps());
        Territory::resolveRelationUsing('users', fn (Territory $territory) => $territory->belongsToMany(User::class, 'territory_user')->withTimestamps());
    });

    afterEach(function (): void {
        Jetstream::useTeamModel(Team::class);
        Jetstream::useMembershipModel(Membership::class);
        Jetstream::useTeamInvitationModel(TeamInvitation::class);
    });

    it('covers TeamRoleResource, TeamRoleLogResource, and their Pages', function (): void {
        PermissionCatalog::sync();
        $livewire = new ListTeamRoles();

        // Unauthenticated / no tenant
        Auth::logout();
        Filament::setTenant(null);
        expect(TeamRoleResource::permissionResource())->toBe('team_role')
            ->and(TeamRoleResource::team())->toBeNull()
            ->and(TeamRoleResource::canAccess())->toBeFalse()
            ->and(TeamRoleResource::canViewAny())->toBeFalse()
            ->and(TeamRoleResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue()
            ->and(TeamRoleResource::canCreate())->toBeFalse()
            ->and(TeamRoleResource::getCreateAuthorizationResponse()->denied())->toBeTrue()
            ->and(TeamRoleResource::canDeleteAny())->toBeFalse()
            ->and(TeamRoleResource::getDeleteAnyAuthorizationResponse()->denied())->toBeTrue()
            ->and(TeamRoleResource::grantablePermissions())->toHaveKey('view_contact')
            ->and(TeamRoleResource::getEloquentQuery()->count())->toBe(0);

        expect(TeamRoleLogResource::permissionResource())->toBe('team_role_log')
            ->and(TeamRoleLogResource::canAccess())->toBeFalse()
            ->and(TeamRoleLogResource::canViewAny())->toBeFalse()
            ->and(TeamRoleLogResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue()
            ->and(TeamRoleLogResource::canCreate())->toBeFalse()
            ->and(TeamRoleLogResource::getCreateAuthorizationResponse()->denied())->toBeTrue();

        // Authenticated team owner
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);
        $owner->forceFill(['current_team_id' => $team->id])->save();
        setPermissionsTeamId(null);
        $owner->assignRole(RoleEnum::SuperAdmin->value);
        Gate::before(fn () => true);

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);

        config(['organizations-teams.audit_log_model' => AuditLog::class]);

        expect(TeamRoleResource::team()?->id)->toBe($team->id)
            ->and(TeamRoleResource::canAccess())->toBeTrue()
            ->and(TeamRoleResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
            ->and(TeamRoleResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
            ->and(TeamRoleLogResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
            ->and(TeamRoleLogResource::getEloquentQuery()->toSql())->toBeString()
            ->and(TeamRoleLogResource::getPages())->toHaveKey('index');

        setPermissionsTeamId($team->id);
        $customRole = SpatieRole::create(['name' => 'Custom Team Role', 'guard_name' => 'web', 'team_id' => $team->id]);
        $otherTeam = Team::factory()->create();
        setPermissionsTeamId($otherTeam->id);
        $otherRole = SpatieRole::create(['name' => 'Other Team Role', 'guard_name' => 'web', 'team_id' => $otherTeam->id]);
        $reservedRoleId = DB::table('roles')->insertGetId([
            'name' => 'admin',
            'guard_name' => 'web',
            'team_id' => $team->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reservedNameRole = SpatieRole::findOrFail($reservedRoleId);

        $allowDeleteAnySubclass = new class() extends TeamRoleResource
        {
            public static function canDeleteAny(): bool
            {
                return true;
            }
        };
        $allowCreateLogSubclass = new class() extends TeamRoleLogResource
        {
            public static function canCreate(): bool
            {
                return true;
            }
        };
        expect($allowDeleteAnySubclass::getDeleteAnyAuthorizationResponse()->allowed())->toBeFalse()
            ->and($allowCreateLogSubclass::getCreateAuthorizationResponse()->allowed())->toBeFalse();

        expect(TeamRoleResource::canView($customRole))->toBeTrue()
            ->and(TeamRoleResource::getViewAuthorizationResponse($customRole)->allowed())->toBeTrue()
            ->and(TeamRoleResource::getViewAuthorizationResponse($otherRole)->denied())->toBeTrue()
            ->and(TeamRoleResource::canEdit($customRole))->toBeTrue()
            ->and(TeamRoleResource::getEditAuthorizationResponse($customRole)->allowed())->toBeTrue()
            ->and(TeamRoleResource::getEditAuthorizationResponse($otherRole)->denied())->toBeTrue()
            ->and(TeamRoleResource::canDelete($customRole))->toBeTrue()
            ->and(TeamRoleResource::getDeleteAuthorizationResponse($customRole)->allowed())->toBeTrue()
            ->and(TeamRoleResource::getDeleteAuthorizationResponse($reservedNameRole)->denied())->toBeTrue();

        // Fallback form when RolePermissionBuilder is unavailable
        $GLOBALS['__test_hide_role_permission_builder'] = true;
        try {
            expect(TeamRoleResource::form(Schema::make($livewire))->getComponents())->toHaveCount(2);
        } finally {
            $GLOBALS['__test_hide_role_permission_builder'] = false;
        }

        // Form, Table, Pages for TeamRoleResource and TeamRoleLogResource
        $schema = TeamRoleResource::form(Schema::make($livewire));
        expect($schema->getComponents())->not->toBeEmpty();

        $table = TeamRoleResource::table(Table::make($livewire));
        expect($table->getColumns())->not->toBeEmpty()
            ->and(TeamRoleResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

        $deleteAction = collect($table->getRecordActions())->first(fn ($a) => $a->getName() === 'delete');
        $usingClosure = getClosureProperty($deleteAction, 'using');
        $roleToDelete = SpatieRole::create(['name' => 'Deletable Role', 'guard_name' => 'web', 'team_id' => $team->id]);
        expect($usingClosure($roleToDelete))->toBeTrue();

        $logTable = TeamRoleLogResource::table(Table::make(new ListTeamRoleLogs()));
        expect($logTable->getColumns())->not->toBeEmpty();

        $listLogsPage = new ListTeamRoleLogs();
        $listLogsActions = (new ReflectionMethod($listLogsPage, 'getHeaderActions'))->invoke($listLogsPage);
        expect($listLogsActions)->toBe([]);

        $listRolesPage = new ListTeamRoles();
        $listRolesActions = (new ReflectionMethod($listRolesPage, 'getHeaderActions'))->invoke($listRolesPage);
        expect($listRolesActions)->toHaveCount(1);

        // CreateTeamRole page methods
        $createPage = new CreateTeamRole();
        $handleCreate = new ReflectionMethod($createPage, 'handleRecordCreation');
        $createdRole = $handleCreate->invoke($createPage, [
            'name' => 'Page Created Role',
            'permissions' => ['view_contact'],
        ]);
        expect($createdRole)->toBeInstanceOf(SpatieRole::class);

        expect(fn () => $handleCreate->invoke($createPage, [
            'name' => 'Page Created Role', // duplicate
            'permissions' => ['view_contact'],
        ]))->toThrow(ValidationException::class);

        // EditTeamRole page methods
        $editPage = new EditTeamRole();
        $editPage->record = $createdRole;
        $mutateFill = new ReflectionMethod($editPage, 'mutateFormDataBeforeFill');
        $filled = $mutateFill->invoke($editPage, ['name' => $createdRole->name]);
        expect($filled['permissions'])->toBe(['view_contact']);

        $handleUpdate = new ReflectionMethod($editPage, 'handleRecordUpdate');
        $updatedRole = $handleUpdate->invoke($editPage, $createdRole, [
            'name' => 'Page Updated Role',
            'permissions' => ['view_contact', 'create_contact'],
        ]);
        expect($updatedRole->name)->toBe('Page Updated Role');

        expect(fn () => $handleUpdate->invoke($editPage, $createdRole, [
            'name' => 'admin', // reserved
            'permissions' => ['view_contact'],
        ]))->toThrow(ValidationException::class);
    });

    it('covers TeamMemberResource and ListTeamMembers page', function (): void {
        PermissionCatalog::sync();
        Auth::logout();
        Filament::setTenant(null);

        expect(TeamMemberResource::permissionResource())->toBe('team_member')
            ->and(TeamMemberResource::canViewAny())->toBeFalse()
            ->and(TeamMemberResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue()
            ->and(TeamMemberResource::getEloquentQuery()->count())->toBe(0)
            ->and(TeamMemberResource::assignableRoles())->toHaveKey(RoleEnum::Manager->value)
            ->and(TeamMemberResource::getPages())->toHaveKey('index');

        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);
        $owner->forceFill(['current_team_id' => $team->id])->save();
        setPermissionsTeamId(null);
        $owner->assignRole(RoleEnum::SuperAdmin->value);
        Gate::before(fn () => true);

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);

        expect(TeamMemberResource::canViewAny())->toBeTrue()
            ->and(TeamMemberResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
            ->and(TeamMemberResource::getEloquentQuery()->pluck('id')->all())->toContain($owner->id);

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'sales_rep']);
        setPermissionsTeamId($team->id);
        $member->assignRole(RoleEnum::SalesRep->value);

        $customRole = SpatieRole::create(['name' => 'Custom Support', 'guard_name' => 'web', 'team_id' => $team->id]);
        $customRole->syncPermissions(['view_contact']);

        $unassignableCustomRole = SpatieRole::create(['name' => 'Escalated Role', 'guard_name' => 'web', 'team_id' => $team->id]);
        $unassignableCustomRole->syncPermissions(['manage_roles']);

        $livewire = new ListTeamMembers();
        $table = TeamMemberResource::table(Table::make($livewire));

        // Role column state closure
        $roleCol = $table->getColumn('team_role');
        $getState = getClosureProperty($roleCol, 'getStateUsing');
        setPermissionsTeamId($team->id);
        expect($getState($member))->toBe(RoleEnum::SalesRep->value);

        // Record actions: changeRole & removeMember
        $actions = collect($table->getRecordActions())->keyBy(fn (Action $a) => $a->getName());
        $changeRoleAction = $actions->get('changeRole');
        $removeMemberAction = $actions->get('removeMember');

        $changeVisible = getClosureProperty($changeRoleAction, 'isVisible');
        expect($changeVisible($owner))->toBeFalse()
            ->and($changeVisible($member))->toBeTrue();

        $changeSchema = getClosureProperty($changeRoleAction, 'schema');
        /** @var Select $roleSelect */
        $roleSelect = $changeSchema[0];
        $optionsClosure = getClosureProperty($roleSelect, 'options');
        expect($optionsClosure())->toHaveKey('Custom Support');

        $changeCallback = getClosureProperty($changeRoleAction, 'action');
        $service = app(TeamManagementService::class);

        // Fixed role change
        $changeCallback($member, ['role' => RoleEnum::Manager->value], $service);
        // Custom role change
        $changeCallback($member, ['role' => 'Custom Support'], $service);

        // When no active team is set
        Filament::setTenant(null);
        $owner->forceFill(['current_team_id' => null])->save();
        $changeCallback($member, ['role' => RoleEnum::Manager->value], $service);

        // Restore active team
        Filament::setTenant($team);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        // Bulk action: setRole
        $bulkActions = collect($table->getToolbarActions());
        $setRoleBulk = $bulkActions->first();
        $bulkSchema = getClosureProperty($setRoleBulk, 'schema');
        $bulkOptions = getClosureProperty($bulkSchema[0], 'options');
        expect($bulkOptions())->toHaveKey('Custom Support');

        $bulkCallback = getClosureProperty($setRoleBulk, 'action');
        // When no active team
        Filament::setTenant(null);
        $owner->forceFill(['current_team_id' => null])->save();
        $bulkCallback(collect([$member]), ['role' => RoleEnum::Manager->value], $service);

        // Restore active team and test bulk action with owner (skipped), fixed role, custom role, and unassignable custom role (caught InvalidArgumentException)
        Filament::setTenant($team);
        $owner->forceFill(['current_team_id' => $team->id])->save();
        $bulkCallback(collect([$owner, $member]), ['role' => RoleEnum::Free->value], $service);
        $bulkCallback(collect([$member]), ['role' => 'Custom Support'], $service);
        $bulkCallback(collect([$member]), ['role' => 'Escalated Role'], $service);

        // removeMember visibility & action
        $removeVisible = getClosureProperty($removeMemberAction, 'isVisible');
        expect($removeVisible($owner))->toBeFalse()
            ->and($removeVisible($member))->toBeTrue();

        $removeCallback = getClosureProperty($removeMemberAction, 'action');
        $removeCallback($member, $service);
        expect($team->users()->whereKey($member->id)->exists())->toBeFalse();

        // ListTeamMembers header action: addMember
        $headerActions = (new ReflectionMethod($livewire, 'getHeaderActions'))->invoke($livewire);
        $addMemberAction = $headerActions[0];
        $addMemberCallback = getClosureProperty($addMemberAction, 'action');

        // 1. No active team selected
        Filament::setTenant(null);
        $owner->forceFill(['current_team_id' => null])->save();
        $addMemberCallback(['email' => 'x@example.test', 'role' => RoleEnum::SalesRep->value], $service);

        // Restore active team
        Filament::setTenant($team);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        // 2. Existing user without password -> attaches to team
        $existingUser = User::factory()->create(['email' => 'existing@example.test']);
        $addMemberCallback(['email' => 'existing@example.test', 'password' => '', 'role' => RoleEnum::SalesRep->value], $service);
        expect($existingUser->fresh()->belongsToTeam($team))->toBeTrue();

        // 3. New user without password -> danger notification
        $addMemberCallback(['email' => 'brandnew@example.test', 'password' => '', 'role' => RoleEnum::SalesRep->value], $service);
        expect(User::where('email', 'brandnew@example.test')->exists())->toBeFalse();

        // 4. New user with password -> creates and attaches
        $addMemberCallback([
            'name' => 'Brand New',
            'email' => 'brandnew@example.test',
            'password' => 'StrongPass123!@#',
            'role' => RoleEnum::SalesRep->value,
        ], $service);
        expect(User::where('email', 'brandnew@example.test')->exists())->toBeTrue();
    });

    it('covers TeamBackupResource, TeamResource, TeamSubscriptionResource, and their Pages', function (): void {
        PermissionCatalog::sync();
        Storage::fake('local');
        Queue::fake();

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        config(['app.admin_emails' => ['admin@example.test']]);
        setPermissionsTeamId(null);
        $admin->assignRole(RoleEnum::SuperAdmin->value);
        $this->actingAs($admin);

        $panel = Filament::getPanel('admin');

        // TeamBackupResource
        expect(TeamBackupResource::isScopedToTenant())->toBeFalse()
            ->and(TeamBackupResource::canAccess())->toBeTrue()
            ->and(TeamBackupResource::getRouteMiddleware($panel))->toContain(SetFilamentDefaultTenant::class)
            ->and(TeamBackupResource::getPages())->toHaveKey('index');

        $listBackupsPage = new ListTeamBackups();
        expect(TeamBackupResource::form(Schema::make($listBackupsPage))->getComponents())->toBe([]);

        $backupTable = TeamBackupResource::table(Table::make($listBackupsPage));
        $statusCol = $backupTable->getColumn('status');
        $statusColor = getClosureProperty($statusCol, 'color');
        expect($statusColor('completed'))->toBe('success')
            ->and($statusColor('failed'))->toBe('danger')
            ->and($statusColor('processing'))->toBe('warning')
            ->and($statusColor('pending'))->toBe('gray');

        $sizeCol = $backupTable->getColumn('size_bytes');
        $formatSize = getClosureProperty($sizeCol, 'formatStateUsing');
        expect($formatSize(2048))->toBe('2.0 KB')
            ->and($formatSize(null))->toBe('—');

        $team = Team::factory()->create(['user_id' => $admin->id, 'personal_team' => false]);
        Storage::disk('local')->put('backups/b1.zip', 'zipcontent');
        $backup = TeamBackup::create([
            'team_id' => $team->id,
            'disk' => 'local',
            'path' => 'backups/b1.zip',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);

        $backupActions = collect($backupTable->getRecordActions())->keyBy(fn (Action $a) => $a->getName());
        $downloadAction = $backupActions->get('download');
        $restoreAction = $backupActions->get('restore');
        $deleteAction = $backupActions->get('delete');

        expect((getClosureProperty($downloadAction, 'isVisible'))($backup))->toBeTrue()
            ->and((getClosureProperty($downloadAction, 'action'))($backup))->toBeInstanceOf(StreamedResponse::class)
            ->and((getClosureProperty($restoreAction, 'isVisible'))($backup))->toBeTrue();

        // Restore action when team is NOT empty
        $restoreCallback = getClosureProperty($restoreAction, 'action');
        DB::table('call_settings')->insert(['team_id' => $team->id, 'name' => 'k', 'value' => 'v']);
        $restoreCallback($backup);
        Queue::assertNotPushed(RestoreTeamBackup::class);

        // Restore action when team IS empty
        DB::table('call_settings')->where('team_id', $team->id)->delete();
        $restoreCallback($backup);
        Queue::assertPushed(RestoreTeamBackup::class);

        // DeleteAction before hook deletes file from disk
        $beforeDelete = getClosureProperty($deleteAction, 'before');
        $beforeDelete($backup);
        Storage::disk('local')->assertMissing('backups/b1.zip');

        // ListTeamBackups header action
        $backupHeaderActions = (new ReflectionMethod($listBackupsPage, 'getHeaderActions'))->invoke($listBackupsPage);
        $genAction = $backupHeaderActions[0];
        $genSchema = getClosureProperty($genAction, 'schema');
        $teamOptions = getClosureProperty($genSchema[0], 'options');
        expect($teamOptions())->toHaveKey($team->id);
        (getClosureProperty($genAction, 'action'))(['team_id' => $team->id]);
        Queue::assertPushed(GenerateTeamBackup::class);

        // TeamResource
        expect(TeamResource::isScopedToTenant())->toBeFalse()
            ->and(TeamResource::canAccess())->toBeTrue()
            ->and(TeamResource::getRouteMiddleware($panel))->toContain(SetFilamentDefaultTenant::class)
            ->and(TeamResource::getPages())->toHaveKey('index');

        $listTeamsPage = new ListTeams();
        expect(TeamResource::form(Schema::make($listTeamsPage))->getComponents())->toHaveCount(3);

        $teamsTable = TeamResource::table(Table::make($listTeamsPage));
        $queryScopes = getClosureProperty($teamsTable, 'queryScopes');
        expect(($queryScopes[0])(Team::query()))->toBeInstanceOf(Builder::class);

        $teamStatusCol = $teamsTable->getColumn('status');
        $teamStateClosure = getClosureProperty($teamStatusCol, 'getStateUsing');
        $teamColorClosure = getClosureProperty($teamStatusCol, 'color');
        expect($teamStateClosure($team))->toBe('Active')
            ->and($teamColorClosure('Active'))->toBe('success')
            ->and($teamColorClosure('Archived'))->toBe('danger');

        $teamActions = collect($teamsTable->getRecordActions())->keyBy(fn (Action $a) => $a->getName());
        $archiveTeamAction = $teamActions->get('archive');
        $restoreTeamAction = $teamActions->get('restore');
        $cloneTeamAction = $teamActions->get('clone');

        expect((getClosureProperty($archiveTeamAction, 'isVisible'))($team))->toBeTrue();
        (getClosureProperty($archiveTeamAction, 'action'))($team);
        expect($team->fresh()->isArchived())->toBeTrue()
            ->and($teamStateClosure($team->fresh()))->toBe('Archived');

        expect((getClosureProperty($restoreTeamAction, 'isVisible'))($team->fresh()))->toBeTrue();
        (getClosureProperty($restoreTeamAction, 'action'))($team->fresh());
        expect($team->fresh()->isArchived())->toBeFalse();

        // Clone action schema & action callback
        $cloneSchema = getClosureProperty($cloneTeamAction, 'schema');
        /** @var TextInput $cloneNameInput */
        $cloneNameInput = $cloneSchema[0];
        /** @var Select $cloneOwnerSelect */
        $cloneOwnerSelect = $cloneSchema[1];

        expect((getClosureProperty($cloneNameInput, 'defaultState'))($team))->toBe("Copy of {$team->name}")
            ->and((getClosureProperty($cloneOwnerSelect, 'options'))())->toHaveKey($admin->id)
            ->and((getClosureProperty($cloneOwnerSelect, 'defaultState'))($team))->toBe($admin->id);

        $cloneCallback = getClosureProperty($cloneTeamAction, 'action');
        // Owner not found
        $cloneCallback($team, ['name' => 'Cloned 1', 'owner_id' => 999999]);
        // Valid owner
        $cloneCallback($team, ['name' => 'Cloned 1', 'owner_id' => $admin->id]);
        $clonedForDestroy = Team::where('name', 'Cloned 1')->first();
        expect($clonedForDestroy)->not->toBeNull();

        // Edit and delete actions are registered
        expect($teamActions->has('edit'))->toBeTrue()
            ->and($teamActions->has('delete'))->toBeTrue();

        // ListTeams header action (import)
        $listTeamsHeaderActions = (new ReflectionMethod($listTeamsPage, 'getHeaderActions'))->invoke($listTeamsPage);
        $importAction = collect($listTeamsHeaderActions)->first(fn ($a) => $a->getName() === 'import');
        expect($importAction)->not->toBeNull();
        (getClosureProperty($importAction, 'action'))(['name' => 'Imported Team', 'file' => 'imports/test.zip']);
        Queue::assertPushed(ImportTeamBackup::class);

        // TeamSubscriptionResource & Pages
        $listSubsPage = new ListTeamSubscriptions();
        expect(TeamSubscriptionResource::form(Schema::make($listSubsPage))->getComponents())->not->toBeEmpty()
            ->and(TeamSubscriptionResource::table(Table::make($listSubsPage))->getColumns())->not->toBeEmpty()
            ->and(TeamSubscriptionResource::getRelations())->toBe([])
            ->and(TeamSubscriptionResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

        expect(new CreateTeamSubscription())->toBeInstanceOf(CreateRecord::class);

        $editSubPage = new EditTeamSubscription();
        expect((new ReflectionMethod($editSubPage, 'getHeaderActions'))->invoke($editSubPage))->toHaveCount(1)
            ->and((new ReflectionMethod($listSubsPage, 'getHeaderActions'))->invoke($listSubsPage))->toHaveCount(1);

        // Cover unauthenticated & fallback branches on TeamResource and TeamBackupResource
        Auth::logout();
        expect(TeamResource::canAccess())->toBeFalse()
            ->and(TeamBackupResource::canAccess())->toBeFalse();

        $plainUser = new class() extends Illuminate\Foundation\Auth\User {};
        $this->actingAs($plainUser);
        $originalGate = Gate::getFacadeRoot();
        Gate::swap(new Illuminate\Auth\Access\Gate(app(), fn () => $plainUser, beforeCallbacks: [fn () => true]));
        try {
            expect(TeamResource::canAccess())->toBeTrue()
                ->and(TeamBackupResource::canAccess())->toBeFalse();
        } finally {
            Gate::swap($originalGate);
        }

        $hasRoleOnlyUser = new class() extends Illuminate\Foundation\Auth\User
        {
            public function hasRole(mixed $role): bool
            {
                return true;
            }
        };
        $this->actingAs($hasRoleOnlyUser);
        expect(TeamBackupResource::canAccess())->toBeTrue();
    });
}
