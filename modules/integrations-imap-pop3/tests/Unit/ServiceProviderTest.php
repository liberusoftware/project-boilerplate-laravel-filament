<?php

declare(strict_types=1);

use Liberu\Integrations\ImapPop3\IntegrationsImapPop3ServiceProvider;

it('registers the service provider', function (): void {
    $provider = new IntegrationsImapPop3ServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(IntegrationsImapPop3ServiceProvider::class);
});
