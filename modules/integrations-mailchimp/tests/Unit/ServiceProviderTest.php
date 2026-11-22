<?php

declare(strict_types=1);

use Liberu\Integrations\MailChimp\IntegrationsMailchimpServiceProvider;

it('registers the service provider', function (): void {
    $provider = new IntegrationsMailchimpServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(IntegrationsMailchimpServiceProvider::class);
});
