<?php

declare(strict_types=1);

namespace Liberu\Foundation\RolesPermissionsFilament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Foundation\RolesPermissions\Contracts\PrivilegedActor;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;
use Liberu\Foundation\RolesPermissionsFilament\Forms\RolePermissionBuilder;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\CreateRole;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\EditRole;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\ListRoles;
use Spatie\Permission\Models\Role as SpatieRole;

class RoleResource extends Resource
{
    protected static ?string $model = SpatieRole::class;

    protected static ?string $slug = 'shield/roles';

    protected static ?string $navigationLabel = 'Global roles';

    protected static string|\UnitEnum|null $navigationGroup = 'Security & access';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static bool $isScopedToTenant = false;

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof PrivilegedActor && $actor->isSuperAdmin();
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
        return self::canAccess() && $record->getAttribute('team_id') === null && $record->getAttribute('guard_name') === 'web';
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return static::canView($record)
            ? parent::getViewAuthorizationResponse($record)
            : Response::deny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canView($record) && mb_strtolower($record->getAttribute('name')) !== Role::SuperAdmin->value;
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::canEdit($record)
            ? parent::getEditAuthorizationResponse($record)
            : Response::deny();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canView($record) && ! in_array(mb_strtolower($record->getAttribute('name')), Role::values(), true);
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

    public static function getEloquentQuery(): Builder
    {
        return SpatieRole::query()->whereNull('team_id')->where('guard_name', 'web');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(RolePermissionBuilder::components(platform: true));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('scope')->getStateUsing(fn (): string => 'Global')->badge()->color('warning'),
            TextColumn::make('permissions_count')->counts('permissions')->label('Permissions'),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make()->using(function (SpatieRole $record): bool {
                $actor = auth()->user();
                abort_unless($actor instanceof PrivilegedActor, 403);
                app(RoleConfigurationService::class)->delete($actor, null, $record);

                return true;
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRoles::route('/'), 'create' => CreateRole::route('/create'), 'edit' => EditRole::route('/{record}/edit')];
    }
}
