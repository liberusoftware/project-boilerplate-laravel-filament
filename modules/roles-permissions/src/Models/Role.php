<?php

declare(strict_types=1);

namespace Liberu\Foundation\RolesPermissions\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(config('permission.models.team') ?: 'Liberu\\Foundation\\Organizations\\Models\\Team', 'team_id');
    }
}
