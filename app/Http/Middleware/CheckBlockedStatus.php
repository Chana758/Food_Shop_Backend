<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBlockedStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        // ឆែកមើល User ដែលកំពុង Login
        if (auth()->check() && auth()->user()->status === 'blocked') {
            // បើ Block គឺកាត់ផ្តាច់ភ្លាមៗ
            return response()->json([
                'status'  => 'error',
                'message' => 'Your account has been blocked by admin.'
            ], 403);
        }

        return $next($request);
    }
}