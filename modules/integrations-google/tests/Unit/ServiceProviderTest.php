<?php

declare(strict_types=1);

use Liberu\Integrations\Google\IntegrationsGoogleServiceProvider;

it('registers the service provider', function (): void {
    $provider = new IntegrationsGoogleServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(IntegrationsGoogleServiceProvider::class);
});
