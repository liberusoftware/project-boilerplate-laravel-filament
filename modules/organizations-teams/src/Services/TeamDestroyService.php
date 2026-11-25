<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Support\TenantModels;

/**
 * Permanently destroys a team and all of its tenant-scoped data, backup
 * artifacts, memberships, invitations, subscriptions, and team-scoped Spatie
 * role/permission assignments.
 *
 * Refuses to destroy personal teams unless `$allowPersonal` is explicitly set
 * to true. Any user whose `current_team_id` points at the destroyed team is
 * reassigned to their personal team (or null if they have none) so no session
 * is stranded.
 */
class TeamDestroyService
{
    /** Team-owned non-TenantModel tables cleaned up when destroying a team. */
    private const EXTRA_TABLES = [
        'team_backups',
        'team_invitations',
        'team_subscriptions',
        'team_user',
    ];

    /**
     * @return array<string, int> rows deleted per table
     */
    public function destroy(Team $team, bool $allowPersonal = false): array
    {
        if ($team->personal_team && ! $allowPersonal) {
            throw new DomainException('Personal teams cannot be destroyed.');
        }

        $backupFiles = $this->collectBackupFiles($team);
        $deleted = [];

        Schema::withoutForeignKeyConstraints(function () use ($team, &$deleted): void {
            DB::transaction(function () use ($team, &$deleted): void {
                $this->reassignStrandedUsers($team);

                foreach (TenantModels::all() as $class) {
                    $table = (new $class())->getTable();
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'team_id')) {
                        continue;
                    }

                    $count = DB::table($table)->where('team_id', $team->id)->delete();
                    if ($count > 0) {
                        $deleted[$table] = ($deleted[$table] ?? 0) + $count;
                    }
                }

                foreach (self::EXTRA_TABLES as $table) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'team_id')) {
                        continue;
                    }

                    $count = DB::table($table)->where('team_id', $team->id)->delete();
                    if ($count > 0) {
                        $deleted[$table] = $count;
                    }
                }

                $this->clearSpatieTeamRecords($team, $deleted);

                $deleted['teams'] = DB::table('teams')->where('id', $team->id)->delete();
            });
        });

        // Remove stored backup archives after the DB transaction commits so a
        // rolled-back destroy never loses existing backups.
        foreach ($backupFiles as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        return $deleted;
    }

    /**
     * @return list<array{string, string}>
     */
    private function collectBackupFiles(Team $team): array
    {
        if (! Schema::hasTable('team_backups')) {
            return [];
        }

        $files = [];
        foreach (TeamBackup::query()->where('team_id', $team->id)->whereNotNull('path')->get(['disk', 'path']) as $backup) {
            $files[] = [(string) $backup->disk, (string) $backup->path];
        }

        return $files;
    }

    private function reassignStrandedUsers(Team $team): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'current_team_id')) {
            return;
        }

        foreach (User::query()->where('current_team_id', $team->id)->get() as $user) {
            $personal = $user->personalTeam();
            $fallbackId = ($personal !== null && (int) $personal->id !== (int) $team->id)
                ? $personal->id
                : null;

            $user->forceFill(['current_team_id' => $fallbackId])->save();
        }
    }

    /**
     * @param  array<string, int>  $deleted
     */
    private function clearSpatieTeamRecords(Team $team, array &$deleted): void
    {
        $teamKey = (string) config('permission.column_names.team_foreign_key', 'team_id');
        $tables = config('permission.table_names', []);

        foreach (['model_has_roles', 'model_has_permissions', 'roles'] as $key) {
            $table = is_array($tables) && isset($tables[$key]) ? (string) $tables[$key] : $key;
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $teamKey)) {
                continue;
            }

            $count = DB::table($table)->where($teamKey, $team->id)->delete();
            if ($count > 0) {
                $deleted[$table] = $count;
            }
        }
    }
}
