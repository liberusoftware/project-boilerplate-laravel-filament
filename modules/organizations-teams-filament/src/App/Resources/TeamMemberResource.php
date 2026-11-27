<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\App\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamManagementService;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamMemberResource\Pages\ListTeamMembers;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;
use Spatie\Permission\Models\Role as SpatieRole;

class TeamMemberResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Team';

    protected static ?string $navigationLabel = 'Team roles';

    protected static ?string $slug = 'team-roles';

    protected static bool $isScopedToTenant = false;

    public static function getModel(): string
    {
        return Jetstream::userModel();
    }

    public static function permissionResource(): string
    {
        return 'team_member';
    }

    public static function canViewAny(): bool
    {
        $actor = Auth::user();
        $team = TeamRoleResource::team();

        return $actor instanceof Model && $team !== null && app(RoleConfigurationService::class)->canManageTeam($actor, $team);
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return static::canViewAny()
            ? parent::getViewAnyAuthorizationResponse()
            : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = TeamRoleResource::team();
        $ids = $tenant instanceof Team ? $tenant->allUsers()->pluck('id')->all() : [];
        $userModel = static::getModel();

        return $userModel::query()->whereKey($ids);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('team_role')
                    ->label('Role')
                    ->badge()
                    ->getStateUsing(fn (Model $record): string => method_exists($record, 'getRoleNames') ? ($record->getRoleNames()->first() ?? '—') : '—'),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label('Change role')
                    ->icon('heroicon-o-key')
                    ->visible(function (Model $record): bool {
                        $tenant = TeamRoleResource::team();
                        $ownerId = $tenant instanceof Team ? $tenant->getAttribute('user_id') : null;

                        return $record->getKey() !== Auth::id() && $record->getKey() !== $ownerId;
                    })
                    ->schema([
                        Select::make('role')
                            ->options(fn (): array => self::assignableRoles())
                            ->required(),
                    ])
                    ->action(function (Model $record, array $data, TeamManagementService $service): void {
                        $tenant = TeamRoleResource::team();
                        if (! $tenant instanceof Team) {
                            return;
                        }

                        $value = $data['role'];
                        $fixed = [Role::Admin->value, Role::Manager->value, Role::SalesRep->value, Role::Free->value];

                        if (in_array($value, $fixed, true)) {
                            $service->changeTeamRole($record, $tenant, Role::from($value));
                        } else {
                            $customRole = SpatieRole::where('team_id', $tenant->getKey())
                                ->where('name', $value)
                                ->first();

                            abort_unless($customRole !== null, 422, 'Choose a role belonging to this team.');
                            $service->assignCustomRole($record, $tenant, $customRole);
                        }

                        Notification::make()->title('Role updated')->success()->send();
                    }),
                Action::make('removeMember')
                    ->label('Remove')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(function (Model $record): bool {
                        $tenant = TeamRoleResource::team();
                        $ownerId = $tenant instanceof Team ? $tenant->getAttribute('user_id') : null;

                        return $record->getKey() !== Auth::id() && $record->getKey() !== $ownerId;
                    })
                    ->action(function (Model $record, TeamManagementService $service): void {
                        $tenant = TeamRoleResource::team();
                        if ($tenant instanceof Team) {
                            $service->removeTeamMember($record, $tenant);
                            Notification::make()->title('Member removed')->success()->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('setRole')
                    ->label('Set role')
                    ->icon('heroicon-o-key')
                    ->schema([
                        Select::make('role')
                            ->options(fn (): array => self::assignableRoles())
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data, TeamManagementService $service): void {
                        $tenant = TeamRoleResource::team();
                        if (! $tenant instanceof Team) {
                            return;
                        }

                        $value = $data['role'];
                        $role = Role::tryFrom($value);
                        $ownerId = $tenant->getAttribute('user_id');

                        foreach ($records as $record) {
                            if ($record->getKey() === Auth::id() || $record->getKey() === $ownerId) {
                                continue;
                            }

                            try {
                                if ($role !== null) {
                                    $service->changeTeamRole($record, $tenant, $role);
                                } else {
                                    $custom = SpatieRole::query()
                                        ->where('team_id', $tenant->getKey())
                                        ->where('guard_name', 'web')
                                        ->where('name', $value)
                                        ->firstOrFail();
                                    $service->assignCustomRole($record, $tenant, $custom);
                                }
                            } catch (\InvalidArgumentException) {
                            }
                        }

                        Notification::make()->title('Roles updated')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('name');
    }

    public static function assignableRoles(): array
    {
        $team = TeamRoleResource::team();
        $custom = $team === null
            ? []
            : SpatieRole::query()
                ->where('team_id', $team->getKey())
                ->where('guard_name', 'web')
                ->pluck('name', 'name')
                ->all();

        return [
            Role::Manager->value => 'Manager',
            Role::SalesRep->value => 'Sales rep',
            Role::Free->value => 'Free',
        ] + $custom;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamMembers::route('/'),
        ];
    }
}
