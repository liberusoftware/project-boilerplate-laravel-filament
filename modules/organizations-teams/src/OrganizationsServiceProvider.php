<?php

namespace Liberu\Foundation\Organizations;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Organizations\Console\Commands\BackupTeam;
use Liberu\Foundation\Organizations\Console\Commands\CloneTeam;
use Liberu\Foundation\Organizations\Console\Commands\DestroyTeam;
use Liberu\Foundation\Organizations\Console\Commands\ImportTeam;
use Liberu\Foundation\Organizations\Console\Commands\RestoreTeam;
use Liberu\Foundation\Organizations\Models\Membership;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamInvitation;
use Liberu\Foundation\Organizations\Policies\TeamPolicy;

final class OrganizationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/organizations-teams.php', 'organizations-teams');

        Jetstream::useTeamModel(Team::class);
        Jetstream::useMembershipModel(Membership::class);
        Jetstream::useTeamInvitationModel(TeamInvitation::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        Gate::policy(Team::class, TeamPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupTeam::class,
                CloneTeam::class,
                DestroyTeam::class,
                ImportTeam::class,
                RestoreTeam::class,
            ]);
        }
    }
}
