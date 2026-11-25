<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ProtectSharedBrowserSession
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $cookie = Auth::guard('web')->getRecallerName();

        if ($request->cookies->has($cookie)) {
            $request->cookies->remove($cookie);
            Cookie::expire($cookie);
        }

        if ($request->routeIs('login.store')) {
            $request->merge(['remember' => false]);
        }

        $response = $next($request);

        if ($request->routeIs('logout', 'cash-closings.prepare-handoff') && $request->user() === null) {
            Inertia::clearHistory();
        }

        return $response;
    }
}
