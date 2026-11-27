<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Liberu\Foundation\Organizations\Models\TeamSubscription;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\CreateTeamSubscription;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\EditTeamSubscription;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\ListTeamSubscriptions;

class TeamSubscriptionResource extends Resource
{
    protected static ?string $model = TeamSubscription::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = 'Teams';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('team_id')
                    ->relationship('team', 'name')
                    ->required(),
                TextInput::make('stripe_id')
                    ->required()
                    ->maxLength(255),
                TextInput::make('stripe_status')
                    ->required()
                    ->maxLength(255),
                TextInput::make('stripe_price')
                    ->maxLength(255),
                TextInput::make('quantity')
                    ->required()
                    ->numeric(),
                DateTimePicker::make('trial_ends_at'),
                DateTimePicker::make('ends_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('team.name'),
                TextColumn::make('stripe_status'),
                TextColumn::make('quantity'),
                TextColumn::make('trial_ends_at')->dateTime(),
                TextColumn::make('ends_at')->dateTime(),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('stripe_status')
                    ->options([
                        'active' => 'Active',
                        'canceled' => 'Canceled',
                        'incomplete' => 'Incomplete',
                        'incomplete_expired' => 'Incomplete Expired',
                        'past_due' => 'Past Due',
                        'trialing' => 'Trialing',
                        'unpaid' => 'Unpaid',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamSubscriptions::route('/'),
            'create' => CreateTeamSubscription::route('/create'),
            'edit' => EditTeamSubscription::route('/{record}/edit'),
        ];
    }
}
