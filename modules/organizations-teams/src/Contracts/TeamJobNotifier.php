<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TeamJobNotifier
{
    public function notify(Model $user, string $title, string $body, bool $success): void;
}
