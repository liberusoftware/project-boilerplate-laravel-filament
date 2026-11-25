<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Filament\App\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Foundation\Organizations\Filament\App\Resources\TeamRoleLogResource\Pages\ListTeamRoleLogs;
use Spatie\Activitylog\Models\Activity;

/**
 * Team-scoped, read-only view of team role changes (`team.*` events).
 *
 * Uses a host-configured audit log model (`config('organizations-teams.audit_log_model')`)
 * when provided, or Spatie's `Activity` model by default.
 */
class TeamRoleLogResource extends Resource
{
    public static function getModel(): string
    {
        $configured = config('organizations-teams.audit_log_model');
        if (is_string($configured) && $configured !== '' && is_subclass_of($configured, Model::class)) {
            return $configured;
        }

        return Activity::class;
    }

    public static function canAccess(): bool
    {
        return TeamRoleResource::canAccess();
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

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Team';

    protected static ?string $navigationLabel = 'Role change log';

    protected static ?string $slug = 'team-role-log';

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getCreateAuthorizationResponse(): Response
    {
        return static::canCreate()
            ? parent::getCreateAuthorizationResponse()
            : Response::deny();
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        $teamId = TeamRoleResource::team()?->getKey() ?? 0;
        $query = parent::getEloquentQuery();

        if (static::getModel() === Activity::class) {
            return $query
                ->where('log_name', 'team')
                ->where('event', 'like', 'team.%')
                ->where('properties->team_id', $teamId);
        }

        return $query
            ->where('team_id', $teamId)
            ->where('action', 'like', 'team.%');
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        $isActivity = static::getModel() === Activity::class;

        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make($isActivity ? 'causer.name' : 'user.name')->label('By')->searchable(),
                TextColumn::make($isActivity ? 'event' : 'action')->badge(),
                TextColumn::make('description')->wrap()->searchable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTeamRoleLogs::route('/'),
        ];
    }
}
