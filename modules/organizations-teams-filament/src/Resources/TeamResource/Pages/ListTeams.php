<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Liberu\Foundation\Organizations\Jobs\ImportTeamBackup;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource;

class ListTeams extends ListRecords
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('import')
                ->label('Import backup')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalDescription('Import a backup zip from another environment into a brand-new team. All records are re-owned by you.')
                ->schema([
                    TextInput::make('name')
                        ->label('New team name')
                        ->helperText('Leave blank to use the name from the backup.'),
                    FileUpload::make('file')
                        ->label('Backup zip')
                        ->disk('local')
                        ->directory('imports')
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    ImportTeamBackup::dispatch(
                        'local',
                        (string) $data['file'],
                        $data['name'] ?: null,
                        (int) auth()->id(),
                    );

                    Notification::make()->title('Import queued')->success()->send();
                }),
        ];
    }
}
