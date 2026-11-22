<?php

declare(strict_types=1);

namespace Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\RolesPermissions\Contracts\PrivilegedActor;
use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource;
use Spatie\Permission\Models\Role as SpatieRole;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $role = $this->getRecord();
        abort_unless($role instanceof SpatieRole, 404);
        $data['permissions'] = $role->getPermissionNames()->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof PrivilegedActor && $record instanceof SpatieRole, 403);

        try {
            return app(RoleConfigurationService::class)->save($actor, null, $data, $record);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        }
    }
}
