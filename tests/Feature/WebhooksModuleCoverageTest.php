<?php

declare(strict_types=1);

namespace Liberu\Foundation\Webhooks\Support {
    if (! function_exists(__NAMESPACE__.'\\extension_loaded')) {
        function extension_loaded(string $extension): bool
        {
            if (($GLOBALS['__test_webhooks_extension_loaded'] ?? null) !== null) {
                return (bool) $GLOBALS['__test_webhooks_extension_loaded'];
            }

            return \extension_loaded($extension);
        }
    }

    if (! function_exists(__NAMESPACE__.'\\dns_get_record')) {
        function dns_get_record(string $hostname, int $type = DNS_ANY, ?array &$authoritative_name_servers = null, ?array &$additional_records = null, bool $raw = false): array|false
        {
            if (($GLOBALS['__test_webhooks_dns_records'] ?? null) !== null) {
                return $GLOBALS['__test_webhooks_dns_records'];
            }

            return \dns_get_record($hostname, $type, $authoritative_name_servers, $additional_records, $raw);
        }
    }
}

namespace {
    use Illuminate\Support\Facades\Http;
    use Liberu\Foundation\Webhooks\Actions\DeliverWebhook;
    use Liberu\Foundation\Webhooks\Actions\QueueWebhook;
    use Liberu\Foundation\Webhooks\Actions\RegisterEndpoint;
    use Liberu\Foundation\Webhooks\Actions\RotateEndpointSecret;
    use Liberu\Foundation\Webhooks\Models\WebhookDelivery;
    use Liberu\Foundation\Webhooks\Models\WebhookEndpoint;
    use Liberu\Foundation\Webhooks\Support\RetrySchedule;
    use Liberu\Foundation\Webhooks\Support\SigningSecretVault;
    use Liberu\Foundation\Webhooks\Support\WebhookSigner;
    use Liberu\Foundation\Webhooks\Support\WebhookUrlPolicy;
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

    afterEach(function (): void {
        $GLOBALS['__test_webhooks_extension_loaded'] = null;
        $GLOBALS['__test_webhooks_dns_records'] = null;
    });

    it('covers RegisterEndpoint, RotateEndpointSecret, QueueWebhook, DeliverWebhook, and models', function (): void {
        $vault = new SigningSecretVault(app('encrypter'));
        $register = new RegisterEndpoint($vault);

        expect(fn () => $register->handle('org:1', 'ftp://example.com/hook', ['order.created']))
            ->toThrow(InvalidArgumentException::class, 'The webhook URL is invalid.')
            ->and(fn () => $register->handle('org:1', 'https://93.184.216.34/hook', ['']))
            ->toThrow(InvalidArgumentException::class, 'At least one webhook event is required.');

        $endpoint = $register->handle('org:1', 'https://93.184.216.34/hook', ['order.created', 'order.created']);
        $wildcard = $register->handle('org:1', 'https://93.184.216.34/all', ['*'], 'explicit-secret-value');
        $inactive = $register->handle('org:1', 'https://93.184.216.34/off', ['*']);
        $inactive->forceFill(['active' => false])->save();

        expect($endpoint->events)->toBe(['order.created'])
            ->and($endpoint->active)->toBeTrue()
            ->and($vault->open($wildcard->signing_secret))->toBe('explicit-secret-value');

        $rotated = (new RotateEndpointSecret($vault))->handle($endpoint);
        expect($rotated)->toHaveLength(64)
            ->and($endpoint->fresh()->rotated_at)->not->toBeNull()
            ->and($vault->open($endpoint->fresh()->signing_secret))->toBe($rotated);

        $queue = new QueueWebhook();
        expect($queue->handle('evt_1', 'order.created', ['amount' => 100]))->toBe(2)
            ->and($queue->handle('evt_1', 'order.created', ['amount' => 100]))->toBe(0);

        $deliver = new DeliverWebhook($vault, new WebhookSigner(), new RetrySchedule(), new WebhookUrlPolicy());

        $deliveries = WebhookDelivery::query()->orderBy('id')->get();
        expect($deliveries)->toHaveCount(2)
            ->and($deliveries[0]->endpoint)->toBeInstanceOf(WebhookEndpoint::class);

        Http::fake([
            'https://93.184.216.34/hook' => Http::response('ok', 200),
            'https://93.184.216.34/all' => Http::response('server error', 500),
        ]);

        $ok = $deliver->handle($deliveries->firstWhere('endpoint_id', $endpoint->id));
        expect($ok->status)->toBe('delivered')
            ->and($ok->response_status)->toBe(200)
            ->and($ok->next_attempt_at)->toBeNull();

        $failed = $deliver->handle($deliveries->firstWhere('endpoint_id', $wildcard->id));
        expect($failed->status)->toBe('failed')
            ->and($failed->response_status)->toBe(500)
            ->and($failed->next_attempt_at)->not->toBeNull();

        // Delivery exception path (private IP rejected by WebhookUrlPolicy)
        $badEndpoint = $register->handle('org:1', 'https://127.0.0.1/hook', ['order.failed']);
        $queue->handle('evt_bad', 'order.failed', ['reason' => 'private']);
        $badDelivery = WebhookDelivery::query()
            ->where('event_id', 'evt_bad')
            ->where('endpoint_id', $badEndpoint->id)
            ->firstOrFail();
        $errored = $deliver->handle($badDelivery);
        expect($errored->status)->toBe('failed')
            ->and($errored->response_excerpt)->toContain('public IP addresses');

        // Missing endpoint aborts 404
        $orphan = new WebhookDelivery([
            'id' => 'orphan-delivery',
            'endpoint_id' => 999999,
            'event_id' => 'evt_orphan',
            'event' => 'order.created',
            'payload' => [],
            'status' => 'pending',
            'attempts' => 0,
        ]);
        expect(fn () => $deliver->handle($orphan))->toThrow(NotFoundHttpException::class);
    });

    it('covers WebhookUrlPolicy validation, DNS resolution, IPv6 pinning, and curl check', function (): void {
        $policy = new WebhookUrlPolicy();

        expect(fn () => $policy->options('not-a-url'))->toThrow(InvalidArgumentException::class, 'invalid')
            ->and(fn () => $policy->options('https://user:pass@93.184.216.34/hook'))->toThrow(InvalidArgumentException::class, 'invalid')
            ->and(fn () => $policy->options('https://127.0.0.1/hook'))->toThrow(InvalidArgumentException::class, 'public IP')
            ->and(fn () => $policy->options('https://[::ffff:93.184.216.34]/hook'))->toThrow(InvalidArgumentException::class, 'public IP');

        $literalOptions = $policy->options('https://93.184.216.34/hook');
        expect($literalOptions['curl'])->toBe([])
            ->and($literalOptions['allow_redirects'])->toBeFalse();

        // Unresolvable host
        $GLOBALS['__test_webhooks_dns_records'] = [];
        expect(fn () => $policy->options('https://unresolvable.example.test/hook'))
            ->toThrow(InvalidArgumentException::class, 'could not be resolved');

        // Host resolving to IPv4 + IPv6
        $GLOBALS['__test_webhooks_dns_records'] = [
            ['ip' => '93.184.216.34'],
            ['ipv6' => '2606:2800:220:1:248:1893:25c8:1946'],
        ];
        $hostOptions = $policy->options('http://hooks.example.com/events');
        expect($hostOptions['curl'][CURLOPT_RESOLVE][0])
            ->toBe('hooks.example.com:80:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]');

        // Missing curl extension branch
        $GLOBALS['__test_webhooks_extension_loaded'] = false;
        expect(fn () => $policy->options('https://93.184.216.34/hook'))
            ->toThrow(RuntimeException::class, 'cURL extension');
    });
}
