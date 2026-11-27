<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\App\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\CreateTeamRole;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\EditTeamRole;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\ListTeamRoles;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;
use Liberu\Foundation\RolesPermissions\Support\RolePermissionCatalog;
use Spatie\Permission\Models\Role as SpatieRole;

class TeamRoleResource extends Resource
{
    protected static ?string $model = SpatieRole::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Team';

    protected static ?string $navigationLabel = 'Custom roles';

    protected static ?string $slug = 'custom-roles';

    protected static bool $isScopedToTenant = false;

    public static function permissionResource(): string
    {
        return 'team_role';
    }

    public static function team(): ?Team
    {
        $id = Filament::getTenant()?->getKey() ?? Auth::user()?->getAttribute('current_team_id');

        return $id === null ? null : Team::query()->find($id);
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        $team = self::team();

        return $user instanceof Model && $team !== null && app(RoleConfigurationService::class)->canManageTeam($user, $team);
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return static::canViewAny()
            ? parent::getViewAnyAuthorizationResponse()
            : Response::deny();
    }

    public static function canCreate(): bool
    {
        return self::canAccess();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return static::canCreate()
            ? parent::getCreateAuthorizationResponse()
            : Response::deny();
    }

    public static function canView(Model $record): bool
    {
        return self::canAccess()
            && self::team() !== null
            && (int) $record->getAttribute('team_id') === (int) self::team()->getKey()
            && $record->getAttribute('guard_name') === 'web';
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return static::canView($record)
            ? parent::getViewAuthorizationResponse($record)
            : Response::deny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canView($record);
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::canEdit($record)
            ? parent::getEditAuthorizationResponse($record)
            : Response::deny();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canView($record) && ! in_array(mb_strtolower((string) $record->getAttribute('name')), Role::values(), true);
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return static::canDelete($record)
            ? parent::getDeleteAuthorizationResponse($record)
            : Response::deny();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function grantablePermissions(): array
    {
        return RolePermissionCatalog::options();
    }

    public static function getEloquentQuery(): Builder
    {
        $team = self::team();

        return SpatieRole::query()
            ->where('guard_name', 'web')
            ->where('team_id', $team?->getKey())
            ->when($team === null, fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    }

    public static function form(Schema $schema): Schema
    {
        $builderClass = 'Liberu\\Foundation\\RolesPermissionsFilament\\Forms\\RolePermissionBuilder';
        if (class_exists($builderClass)) {
            return $schema->components($builderClass::components());
        }

        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            CheckboxList::make('permissions')->options(self::grantablePermissions()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->emptyStateHeading('Build your first team role')
            ->emptyStateDescription('Start with a role preset or configure permissions for this team.')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('permissions_count')->counts('permissions')->label('Permissions'),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->using(function (SpatieRole $record): bool {
                    $user = Auth::user();
                    $team = self::team();
                    abort_unless($user instanceof Model && $team !== null, 403);
                    app(RoleConfigurationService::class)->delete($user, $team, $record);

                    return true;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamRoles::route('/'),
            'create' => CreateTeamRole::route('/create'),
            'edit' => EditTeamRole::route('/{record}/edit'),
        ];
    }
}
