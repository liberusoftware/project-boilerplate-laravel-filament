<?php

declare(strict_types=1);

namespace Liberu\Integrations\Meta\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Liberu\Foundation\Organizations\Traits\IsTenantModel;

class WhatsAppNumber extends Model
{
    use HasFactory;
    use IsTenantModel;

    protected $table = 'whats_app_numbers';

    protected $fillable = [
        'number',
        'display_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
