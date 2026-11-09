<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WajibGantiPassword
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs('password.ganti', 'logout')) {
            return redirect()->route('password.ganti');
        }

        return $next($request);
    }
}
