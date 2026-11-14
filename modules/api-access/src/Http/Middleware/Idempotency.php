<?php

declare(strict_types=1);

namespace Liberu\Foundation\ApiAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Liberu\Foundation\ApiAccess\Support\IdempotencyStore;
use Symfony\Component\HttpFoundation\Response;

final class Idempotency
{
    public function __construct(private readonly IdempotencyStore $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $identity = sprintf('%s:%s', (string) $request->user()?->getAuthIdentifier(), (string) data_get($request->user(), 'current_team_id'));
        $existing = $this->store->begin($identity, $key, (string) $request->getContent());
        if ($existing !== null) {
            if ($existing->response_body === null) {
                return response()->json(['message' => 'This request is already in progress.'], 409);
            }

            return response($existing->response_body, (int) $existing->response_status)
                ->header('Content-Type', 'application/json')
                ->header('Idempotency-Replayed', 'true');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->store->forget($identity, $key);

            throw $exception;
        }

        $body = $response->getContent();
        if ($body === false || $response->getStatusCode() >= 500) {
            $this->store->forget($identity, $key);
        } else {
            $this->store->complete($identity, $key, $response->getStatusCode(), $body);
        }

        $response->headers->set('Idempotency-Key', $key);

        return $response;
    }
}
