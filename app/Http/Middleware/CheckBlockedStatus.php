<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBlockedStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        // Check the currently logged-in user
        if (auth()->check() && auth()->user()->status === 'blocked') {
            // If blocked, it is cut off immediately.
            return response()->json([
                'status'  => 'error',
                'message' => 'Your account has been blocked by admin.'
            ], 403);
        }

        return $next($request);
    }
}