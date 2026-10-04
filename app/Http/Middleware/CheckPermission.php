<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact an administrator.',
            ], 403);
        }

        // If no permissions specified on route, being authenticated is sufficient
        if (empty($permissions)) {
            return $next($request);
        }

        // Super-admin always bypasses
        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        // Verify if user possesses required permission (OR logic between args)
        if ($user->hasPermission($permissions)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized. Missing required permission: '.implode(', ', $permissions),
        ], 403);
    }
}
