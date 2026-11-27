<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource;
use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;

class CreateTeamRole extends CreateRecord
{
    protected static string $resource = TeamRoleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        $team = TeamRoleResource::team();
        abort_unless($user instanceof Model && $team !== null, 403);

        try {
            return app(RoleConfigurationService::class)->save($user, $team, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])
                    ->all()
            );
        }
    }
}
