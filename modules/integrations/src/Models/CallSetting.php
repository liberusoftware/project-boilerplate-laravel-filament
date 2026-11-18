<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Liberu\Foundation\Organizations\Traits\IsTenantModel;

class CallSetting extends Model
{
    use HasFactory;
    use IsTenantModel;

    protected $fillable = [
        'name',
        'value',
        'description',
    ];
}
