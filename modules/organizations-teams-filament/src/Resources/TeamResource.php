<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamCloneService;
use Liberu\Foundation\OrganizationsFilament\Http\Middleware\SetFilamentDefaultTenant;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\CreateTeam;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\EditTeam;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\ListTeams;
use Liberu\Foundation\RolesPermissions\Enums\Role;

class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Teams';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isScopedToTenant = false;

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'hasRole')) {
            return (bool) $user->hasRole(Role::SuperAdmin);
        }

        return parent::canAccess();
    }

    public static function getRouteMiddleware(Panel $panel): string|array
    {
        return [SetFilamentDefaultTenant::class];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('user_id')
                    ->label('Owner')
                    ->relationship('owner', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Toggle::make('personal_team')
                    ->label('Personal team'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope('archived'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('personal_team')
                    ->boolean()
                    ->label('Personal'),
                TextColumn::make('status')
                    ->state(fn (Team $record): string => $record->isArchived() ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'danger' : 'success'),
                TextColumn::make('archived_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                            ->options(function (): array {
                                $userModel = Jetstream::userModel();

                                return $userModel::query()->pluck('name', 'id')->all();
                            })
                            ->searchable()
                            ->required()
                            ->default(fn (Team $record): int => (int) $record->user_id),
                    ])
                    ->action(function (Team $record, array $data): void {
                        $userModel = Jetstream::userModel();
                        $owner = $userModel::find((int) $data['owner_id']);
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
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
            'create' => CreateTeam::route('/create'),
            'edit' => EditTeam::route('/{record}/edit'),
        ];
    }
}
