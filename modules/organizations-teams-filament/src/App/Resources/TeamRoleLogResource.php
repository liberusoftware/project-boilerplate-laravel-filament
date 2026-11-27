<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\App\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleLogResource\Pages\ListTeamRoleLogs;

class TeamRoleLogResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Team';

    protected static ?string $navigationLabel = 'Role change log';

    protected static ?string $slug = 'team-role-log';

    public static function getModel(): string
    {
        $configured = config('organizations-teams.audit_log_model');

        return is_string($configured) && class_exists($configured) ? $configured : Team::class;
    }

    public static function canAccess(): bool
    {
        $configured = config('organizations-teams.audit_log_model');

        return is_string($configured) && class_exists($configured) && TeamRoleResource::canAccess();
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

    public static function permissionResource(): string
    {
        return 'team_role_log';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('team_id', TeamRoleResource::team()?->getKey() ?? 0)
            ->where('action', 'like', 'team.%');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('user.name')->label('By')->searchable(),
                TextColumn::make('action')->badge(),
                TextColumn::make('description')->wrap()->searchable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamRoleLogs::route('/'),
        ];
    }
}
