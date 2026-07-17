<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureManager
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        if (! $request->user()->is_manager) {
            abort(403, 'Bạn không có quyền truy cập khu vực Lãnh đạo.');
        }

        return $next($request);
    }
}
