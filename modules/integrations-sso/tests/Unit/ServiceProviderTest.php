<?php

declare(strict_types=1);

use Liberu\Integrations\Sso\IntegrationsSsoServiceProvider;

it('registers the service provider', function (): void {
    $provider = new IntegrationsSsoServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(IntegrationsSsoServiceProvider::class);
});
