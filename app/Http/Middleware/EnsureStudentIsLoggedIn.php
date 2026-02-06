<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureStudentIsLoggedIn
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            session(['url.intended' => $request->url()]);
            return redirect()->route('google.redirect');
        }

        return $next($request);
    }
}
