<?php

use Illuminate\Http\Request;
use Liberu\Foundation\ApplicationCore\Http\Middleware\SecurityHeaders;
use Symfony\Component\HttpFoundation\Response;

it('adds baseline headers while preserving the response', function (string $scheme, bool $hsts) {
    $response = new Response('payload', 201);
    $result = (new SecurityHeaders())->handle(Request::create($scheme.'://example.test'), fn () => $response);

    expect($result)->toBe($response)
        ->and($result->getContent())->toBe('payload')
        ->and($result->getStatusCode())->toBe(201)
        ->and($result->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($result->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($result->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($result->headers->get('X-Permitted-Cross-Domain-Policies'))->toBe('none')
        ->and($result->headers->get('Permissions-Policy'))->toBe('geolocation=(), camera=(), microphone=(), interest-cohort=()')
        ->and($result->headers->has('Strict-Transport-Security'))->toBe($hsts);
    if ($hsts) {
        expect($result->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');
    }
})->with(['HTTP' => ['http', false], 'HTTPS' => ['https', true]]);

it('preserves explicitly configured response security policies', function () {
    $headers = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'custom-content-policy',
        'Referrer-Policy' => 'same-origin',
        'X-Permitted-Cross-Domain-Policies' => 'master-only',
        'Permissions-Policy' => 'geolocation=(self)',
        'Strict-Transport-Security' => 'max-age=3600',
        'Content-Security-Policy' => "script-src 'self'",
    ];
    $response = new Response('payload', 200, $headers);
    (new SecurityHeaders())->handle(Request::create('https://example.test'), fn () => $response);

    foreach ($headers as $name => $value) {
        expect($response->headers->get($name))->toBe($value);
    }
});
