<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * F4 per-team RBAC backfill: stamp each existing role assignment with the team
 * it now applies in.
 *
 *  - super_admin assignments become global (team_id = null) — the platform role.
 *  - every other assignment is stamped with the user's current_team_id, so a
 *    user keeps their effective role in the team they actively work in. If the
 *    user has no current team, the row is left global (team_id = null) and logged
 *    — a user on multiple teams with one legacy assignment collapses to their
 *    current team only (accepted per the F4 design).
 *
 * On a fresh migrate model_has_roles is empty, so this is a no-op.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! config('permission.teams')) {
            return;
        }

        $names = config('permission.table_names');
        $columns = config('permission.column_names');
        $teamKey = $columns['team_foreign_key'] ?? 'team_id';
        $morphKey = $columns['model_morph_key'] ?? 'model_id';
        $roleKey = $columns['role_pivot_key'] ?? 'role_id';
        $mhr = $names['model_has_roles'];

        $superAdminIds = DB::table($names['roles'])
            ->where('name', 'super_admin')
            ->pluck('id')
            ->all();

        // super_admin → global.
        if ($superAdminIds !== []) {
            DB::table($mhr)->whereIn($roleKey, $superAdminIds)->update([$teamKey => null]);
        }

        $guard = config('auth.defaults.guard', 'web');
        $provider = config("auth.guards.{$guard}.provider", 'users');
        $userModel = config("auth.providers.{$provider}.model");
        $actor = new $userModel();
        $userMorph = $actor->getMorphClass();
        $userTable = $actor->getTable();

        // Every other User assignment → the user's current team.
        DB::table($mhr)
            ->where('model_type', $userMorph)
            ->when($superAdminIds !== [], fn ($q) => $q->whereNotIn($roleKey, $superAdminIds))
            ->orderBy($morphKey)
            ->orderBy($roleKey)
            ->get()
            ->each(function ($row) use ($mhr, $teamKey, $morphKey, $roleKey, $userTable): void {
                $currentTeamId = DB::table($userTable)->where('id', $row->{$morphKey})->value('current_team_id');

                DB::table($mhr)
                    ->where('model_type', $row->model_type)
                    ->where($morphKey, $row->{$morphKey})
                    ->where($roleKey, $row->{$roleKey})
                    ->update([$teamKey => $currentTeamId]);

                if ($currentTeamId === null) {
                    Log::info('RBAC backfill: assignment left global (user has no current team)', [
                        'model_id' => $row->{$morphKey},
                        'role_id' => $row->{$roleKey},
                    ]);
                }
            });

        app('cache')->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        // Data migration; no safe automatic reversal.
    }
};
