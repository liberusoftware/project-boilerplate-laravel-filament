<?php

declare(strict_types=1);

use Liberu\Integrations\LinkedIn\IntegrationsLinkedinServiceProvider;

it('registers the service provider', function (): void {
    $provider = new IntegrationsLinkedinServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(IntegrationsLinkedinServiceProvider::class);
});
