<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TeamAuditLogger
{
    public function record(string $action, string $description, ?Model $auditable = null): void;
}
