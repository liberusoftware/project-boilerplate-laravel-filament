<?php

declare(strict_types=1);

use Liberu\Integrations\Twitter\IntegrationsTwitterServiceProvider;

it('registers the service provider', function (): void {
    $provider = new IntegrationsTwitterServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(IntegrationsTwitterServiceProvider::class);
});
