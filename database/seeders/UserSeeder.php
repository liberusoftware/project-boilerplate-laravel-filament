<?php

namespace Database\Seeders;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\RolesPermissions\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $configuredPassword = (string) config('app.seed_admin_password');
        $adminUser = User::firstOrNew(['email' => 'admin@example.com']);
        $generatedPassword = null;

        if (! $adminUser->exists) {
            $generatedPassword = $configuredPassword === '' ? Str::random(64) : null;
            $adminUser->name = 'Admin User';
            $adminUser->email_verified_at = now();
            $adminUser->password = Hash::make($generatedPassword ?? $configuredPassword);
            $adminUser->save();
        } elseif ($configuredPassword !== '' && ! Hash::check($configuredPassword, $adminUser->password)) {
            $adminUser->password = Hash::make($configuredPassword);
            $adminUser->save();
        }

        $team = Team::firstOrFail();

        // TeamSeeder runs first and may have created a throwaway owner to satisfy
        // the team's user_id. Hand ownership to the admin and drop that placeholder.
        $placeholderOwnerId = $team->user_id;
        $team->forceFill(['user_id' => $adminUser->id])->save();

        $adminUser->teams()->syncWithoutDetaching([$team->id]);
        $adminUser->forceFill(['current_team_id' => $team->id])->save();

        if ($placeholderOwnerId !== null && $placeholderOwnerId !== $adminUser->id) {
            User::where('id', $placeholderOwnerId)
                ->where('email', 'owner@example.com')
                ->whereDoesntHave('ownedTeams')
                ->delete();
        }

        // Assign the role in the team's permission context (team-scoped roles).
        if (Utils::isTenancyEnabled()) {
            setPermissionsTeamId($team->id);
        }
        $role = Role::where('name', 'super_admin')->firstOrFail();
        $adminUser->assignRole($role);

        if (app()->environment('local') && $generatedPassword !== null) {
            $this->command?->info("Admin password: {$generatedPassword}");
        }
    }
}
