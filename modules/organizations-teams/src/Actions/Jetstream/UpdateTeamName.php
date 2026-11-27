<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Actions\Jetstream;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\UpdatesTeamNames;
use Liberu\Foundation\Organizations\Models\Team;

class UpdateTeamName implements UpdatesTeamNames
{
    /**
     * Validate and update the given team's name.
     *
     * @param  array<string, string>  $input
     */
    public function update(Model $user, Team $team, array $input): void
    {
        Gate::forUser($user)->authorize('update', $team);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
        ])->validateWithBag('updateTeamName');

        $team->forceFill([
            'name' => $input['name'],
        ])->save();
    }
}
