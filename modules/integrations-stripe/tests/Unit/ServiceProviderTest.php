<?php

declare(strict_types=1);

use Liberu\Integrations\Stripe\IntegrationsStripeServiceProvider;

it('registers the service provider', function (): void {
    $provider = new IntegrationsStripeServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(IntegrationsStripeServiceProvider::class);
});
