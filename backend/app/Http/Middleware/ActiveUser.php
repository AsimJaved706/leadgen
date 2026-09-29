<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        abort_if($request->user()?->suspended_at, 403, 'Your account is suspended. Contact your administrator.');

        return $next($request);
    }
}
