<?php

declare(strict_types=1);

namespace Liberu\Integrations\Zernio\Listeners;

use Laravel\Jetstream\Events\TeamCreated;
use Liberu\Integrations\Zernio\Services\Zernio\ZernioTenantService;

final class ProvisionZernioProfile
{
    public function __construct(private readonly ZernioTenantService $zernio) {}

    public function handle(TeamCreated $event): void
    {
        $this->zernio->provisionIfConfigured($event->team);
    }
}
