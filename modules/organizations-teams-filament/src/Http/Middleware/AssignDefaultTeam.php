<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

class AssignDefaultTeam
{
    public function handle(Request $request, Closure $next)
    {
        if (! Filament::getTenant() && auth()->check()) {
            $user = auth()->user();
            $defaultTeam = $user->currentTeam ?? $user->ownedTeams()->first();
            if (! $defaultTeam) {
                $defaultTeam = $user->ownedTeams()->create([
                    'name' => $user->getAttribute('name')."'s Team",
                    'personal_team' => true,
                ]);
                $user->forceFill(['current_team_id' => $defaultTeam->getKey()])->save();
            }
            Filament::setTenant($defaultTeam);
        }

        return $next($request);
    }
}
