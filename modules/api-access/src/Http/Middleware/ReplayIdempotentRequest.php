<?php

declare(strict_types=1);

namespace Liberu\Foundation\ApiAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Liberu\Foundation\ApiAccess\Support\IdempotencyStore;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class ReplayIdempotentRequest
{
    public function __construct(private IdempotencyStore $store) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return $next($request);
        }

        $actor = $request->user();
        $identity = implode(':', [
            (string) ($actor?->getAuthIdentifier() ?? 'guest'),
            (string) data_get($actor, 'current_team_id', 'none'),
        ]);
        $existing = $this->store->begin($identity, $key, (string) $request->getContent());
        if ($existing !== null) {
            if ($existing->response_body === null) {
                return response()->json(['message' => 'A request with this idempotency key is in progress.'], 409)->header('Retry-After', '1');
            }

            return response($existing->response_body, (int) $existing->response_status)
                ->header('Content-Type', 'application/json')
                ->header('Idempotent-Replayed', 'true');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->store->forget($identity, $key);

            throw $exception;
        }

        $body = $response->getContent();
        if ($response->isSuccessful() && $body !== false) {
            $this->store->complete($identity, $key, $response->getStatusCode(), $body);
        } else {
            $this->store->forget($identity, $key);
        }

        return $response;
    }
}
