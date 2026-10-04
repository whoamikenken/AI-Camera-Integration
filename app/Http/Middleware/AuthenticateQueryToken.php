<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateQueryToken
{
    /**
     * If incoming request has token in query string but lacks Authorization header,
     * populate the Authorization header as a Bearer token.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->headers->has('Authorization')) {
            $token = $request->query('token') ?: $request->query('api_token');
            if ($token && is_string($token)) {
                $request->headers->set('Authorization', 'Bearer ' . trim($token));
            }
        }

        return $next($request);
    }
}
