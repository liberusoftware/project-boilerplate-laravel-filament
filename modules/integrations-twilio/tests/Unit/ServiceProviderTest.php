<?php

declare(strict_types=1);

use Liberu\Integrations\Twilio\IntegrationsTwilioServiceProvider;

it('registers the service provider', function (): void {
    $provider = new IntegrationsTwilioServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(IntegrationsTwilioServiceProvider::class);
});
