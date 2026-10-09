<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects /api/harness/* with a shared bearer token.
 *
 * Uses a constant-time comparison so the token cannot be recovered by timing,
 * and fails closed when HARNESS_SECRET_TOKEN is not configured.
 */
class VerifyHarnessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.harness.token');

        if ($expected === '') {
            return response()->json([
                'error' => 'harness_token_not_configured',
                'message' => 'HARNESS_SECRET_TOKEN is not set on the server.',
            ], 503);
        }

        $provided = $this->presentedToken($request);

        if ($provided === null || ! hash_equals($expected, $provided)) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'A valid Bearer token is required.',
            ], 401);
        }

        return $next($request);
    }

    /**
     * Accept either an Authorization header or a token query parameter
     * (the latter is convenient for quick manual curl checks).
     */
    private function presentedToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $query = $request->query('token');

        return is_string($query) && $query !== '' ? $query : null;
    }
}
