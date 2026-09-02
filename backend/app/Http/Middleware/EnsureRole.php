<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level role guard. Usage: ->middleware('role:super_admin') or 'role:super_admin,supervisor'.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
                'error_code' => 'UNAUTHENTICATED',
                'data' => (object) [],
            ], 401);
        }

        if (! in_array($user->role?->name, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke resource ini',
                'error_code' => 'FORBIDDEN',
                'data' => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
