<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Models;

use Illuminate\Database\Eloquent\Model;

class TeamOnboarding extends Model
{
    protected $table = 'team_onboarding';

    protected $fillable = ['team_id', 'completed_at'];

    protected $casts = ['completed_at' => 'datetime'];
}
