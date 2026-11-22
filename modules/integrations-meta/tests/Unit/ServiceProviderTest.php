<?php

declare(strict_types=1);

use Liberu\Integrations\Meta\IntegrationsMetaServiceProvider;

it('registers the service provider', function (): void {
    $provider = new IntegrationsMetaServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(IntegrationsMetaServiceProvider::class);
});
