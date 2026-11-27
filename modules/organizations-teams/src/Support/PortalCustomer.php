<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resolves whether an email belongs to an active portal customer holding the
 * global (team_id = null) `customer` role without mutating the request's
 * permission-team context.
 */
class PortalCustomer
{
    public static function forEmail(?string $email): ?Model
    {
        if (blank($email)) {
            return null;
        }

        $userModel = Jetstream::userModel();
        $user = $userModel::where('email', $email)->first();

        if (! $user instanceof Model) {
            return null;
        }

        return self::hasGlobalCustomerRole($user) ? $user : null;
    }

    public static function existsForEmail(?string $email): bool
    {
        return self::forEmail($email) instanceof Model;
    }

    private static function hasGlobalCustomerRole(Model $user): bool
    {
        $tables = config('permission.table_names');
        $morphKey = config('permission.column_names.model_morph_key');
        $teamKey = config('permission.column_names.team_foreign_key');
        $roleKey = app(PermissionRegistrar::class)->pivotRole;

        return DB::table($tables['model_has_roles'])
            ->join($tables['roles'], $tables['roles'].'.id', '=', $tables['model_has_roles'].'.'.$roleKey)
            ->where($tables['model_has_roles'].'.model_type', $user->getMorphClass())
            ->where($tables['model_has_roles'].'.'.$morphKey, $user->getKey())
            ->whereNull($tables['model_has_roles'].'.'.$teamKey)
            ->where($tables['roles'].'.name', Role::Customer->value)
            ->exists();
    }
}
