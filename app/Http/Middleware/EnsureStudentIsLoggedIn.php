<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EnsureStudentIsLoggedIn
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return Inertia::render('Landing', [
                'intendedUrl' => $request->url(),
                'message' => 'Please sign in to access this form.'
            ]);
        }

        return $next($request);
    }
}
