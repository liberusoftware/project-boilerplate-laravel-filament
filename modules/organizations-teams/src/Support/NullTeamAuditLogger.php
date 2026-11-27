<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Support;

use Illuminate\Database\Eloquent\Model;
use Liberu\Foundation\Organizations\Contracts\TeamAuditLogger;

final class NullTeamAuditLogger implements TeamAuditLogger
{
    public function record(string $action, string $description, ?Model $auditable = null): void {}
}
