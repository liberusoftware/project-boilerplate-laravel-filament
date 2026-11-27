<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\Support;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Liberu\Foundation\Organizations\Contracts\TeamJobNotifier;

final class FilamentTeamJobNotifier implements TeamJobNotifier
{
    public function notify(Model $user, string $title, string $body, bool $success): void
    {
        $notification = Notification::make()->title($title)->body($body);
        ($success ? $notification->success() : $notification->danger())->sendToDatabase($user);
    }
}
