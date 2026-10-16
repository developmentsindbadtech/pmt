<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject cookie/session-only auth — integration endpoints require an Authorization Bearer token.
 */
class EnsureBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || trim($token) === '') {
            return response()->json([
                'message' => 'Bearer token required. Session cookies are not accepted for this endpoint.',
            ], 401);
        }

        return $next($request);
    }
}
