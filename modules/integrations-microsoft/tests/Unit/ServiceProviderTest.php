<?php

declare(strict_types=1);

use Liberu\Integrations\Microsoft\IntegrationsMicrosoftServiceProvider;

it('registers the service provider', function (): void {
    $provider = new IntegrationsMicrosoftServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(IntegrationsMicrosoftServiceProvider::class);
});
