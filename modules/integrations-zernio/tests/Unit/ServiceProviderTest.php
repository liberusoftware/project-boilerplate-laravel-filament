<?php

declare(strict_types=1);

use Liberu\Integrations\Zernio\IntegrationsZernioServiceProvider;

it('registers the service provider', function (): void {
    $provider = new IntegrationsZernioServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(IntegrationsZernioServiceProvider::class);
});
