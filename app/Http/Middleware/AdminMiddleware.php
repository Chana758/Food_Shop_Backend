<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
{
    // ១. ឆែក Login
    if (!auth()->check()) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    // ២. ឆែក Status (ការពារមិនឱ្យ Customer ដែលជាប់ Block ធ្វើអ្វីបានទាំងអស់)
    if (auth()->user()->status === 'blocked') {
        return response()->json(['message' => 'Your account is blocked.'], 403);
    }

    // ៣. ឆែក Role (បើបងដាក់ parameter ចូល)
    if (!empty($roles) && !in_array(auth()->user()->role, $roles)) {
        return response()->json(['message' => 'You do not have permission.'], 403);
    }

    return $next($request);
}
}   