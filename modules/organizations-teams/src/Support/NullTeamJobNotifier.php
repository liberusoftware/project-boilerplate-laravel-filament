<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Support;

use Illuminate\Database\Eloquent\Model;
use Liberu\Foundation\Organizations\Contracts\TeamJobNotifier;

final class NullTeamJobNotifier implements TeamJobNotifier
{
    public function notify(Model $user, string $title, string $body, bool $success): void {}
}
