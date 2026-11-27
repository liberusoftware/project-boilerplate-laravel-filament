<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\Resources\TeamBackupResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Liberu\Foundation\Organizations\Jobs\GenerateTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamBackupResource;

class ListTeamBackups extends ListRecords
{
    protected static string $resource = TeamBackupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate backup')
                ->icon('heroicon-o-plus')
                ->schema([
                    Select::make('team_id')
                        ->label('Team')
                        ->options(fn (): array => Team::withoutGlobalScope('archived')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $backup = TeamBackup::create([
                        'team_id' => (int) $data['team_id'],
                        'disk' => (string) config('filesystems.default', 'local'),
                        'status' => 'pending',
                        'created_by' => auth()->id(),
                    ]);

                    GenerateTeamBackup::dispatch($backup->id);

                    Notification::make()->title('Backup queued')->success()->send();
                }),
        ];
    }
}
