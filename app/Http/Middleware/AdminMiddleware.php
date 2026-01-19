<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'Unauthorized',
                'data' => null,
                'errors' => ['Unauthenticated'],
            ], 401);
        }
        if (($user->role ?? null) !== 'admin') {
            return response()->json([
                'message' => 'Forbidden',
                'data' => null,
                'errors' => ['You don\'t have admin access'],
            ], 403);
        }

        return $next($request);
    }
}
