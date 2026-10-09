<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @deprecated Deprecated as part of SEC-14 to prevent Bearer token leakage in URL query parameters (CWE-598).
 * Query-string authentication has been removed in favor of cryptographically signed temporary routes or Authorization headers.
 */
class AuthenticateQueryToken
{
    /**
     * Handle an incoming request.
     * Note: Query-string token extraction is disabled for security compliance.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
