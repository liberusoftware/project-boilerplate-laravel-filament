<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament;

use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\Organizations\Contracts\TeamJobNotifier;
use Liberu\Foundation\OrganizationsFilament\Support\FilamentTeamJobNotifier;

final class OrganizationsFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TeamJobNotifier::class, FilamentTeamJobNotifier::class);
    }
}
