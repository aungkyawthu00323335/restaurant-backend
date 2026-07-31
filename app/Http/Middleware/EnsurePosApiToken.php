<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;

class EnsurePosApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) ($request->header('X-Request-Id') ?: Str::uuid());
        $request->attributes->set('request_id', $requestId);
        $expectedToken = (string) config('pos.api_token', '');

        if ($expectedToken === '') {
            return $this->secure($next($request), $requestId);
        }

        $providedToken = $request->bearerToken()
            ?? $request->header('X-POS-API-TOKEN', '');

        if (! is_string($providedToken) || ! hash_equals($expectedToken, $providedToken)) {
            $response = new JsonResponse([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
            $response->headers->set('X-Request-Id', $requestId);
            return $response;
        }

        return $this->secure($next($request), $requestId);
    }

    private function secure(Response $response, string $requestId): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
