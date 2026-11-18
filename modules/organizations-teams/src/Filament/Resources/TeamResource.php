<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Filament\Resources;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Foundation\Organizations\Filament\Resources\TeamResource\Pages\ListTeams;
use Liberu\Foundation\Organizations\Http\Middleware\SetFilamentDefaultTenant;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamCloneService;
use Liberu\Foundation\RolesPermissions\Enums\Role;

/**
 * Super-admin-only team lifecycle. Lists every team (including archived, which
 * the global scope hides elsewhere) so a platform admin can archive a team
 * (freeze + hide, data preserved) or restore one. No create/edit — teams are
 * born through the app registration flow.
 */
class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    protected static bool $isScopedToTenant = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Teams';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::SuperAdmin);
    }

    public static function getRouteMiddleware(Panel $panel): string|array
    {
        return [SetFilamentDefaultTenant::class];
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope('archived'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                IconColumn::make('personal_team')->boolean()->label('Personal'),
                TextColumn::make('status')
                    ->state(fn (Team $record): string => $record->isArchived() ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'danger' : 'success'),
                TextColumn::make('archived_at')->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Archive this team? Members lose access and its data is hidden, but nothing is deleted. You can restore it later.')
                    ->visible(fn (Team $record): bool => ! $record->isArchived() && ! $record->personal_team)
                    ->action(fn (Team $record) => $record->archive()),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Team $record): bool => $record->isArchived())
                    ->action(fn (Team $record) => $record->restore()),
                Action::make('clone')
                    ->label('Clone')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->schema([
                        TextInput::make('name')
                            ->label('New team name')
                            ->required()
                            ->default(fn (Team $record): string => "Copy of {$record->name}"),
                        Select::make('owner_id')
                            ->label('Owner')
                            ->options(fn (): array => User::query()->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->default(fn (Team $record): int => $record->user_id),
                    ])
                    ->action(function (Team $record, array $data): void {
                        $owner = User::find((int) $data['owner_id']);
                        if (! $owner) {
                            Notification::make()->title('Owner not found')->danger()->send();

                            return;
                        }

                        $new = app(TeamCloneService::class)->clone($record, (string) $data['name'], $owner);

                        Notification::make()
                            ->title('Team cloned')
                            ->body("Created “{$new->name}” from “{$record->name}”.")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
        ];
    }
}
