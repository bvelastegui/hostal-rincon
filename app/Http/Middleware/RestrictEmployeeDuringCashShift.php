<?php

namespace App\Http\Middleware;

use App\Models\CashShift;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictEmployeeDuringCashShift
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_admin) {
            return $next($request);
        }

        $shift = CashShift::active();
        $ownsOpenCash = $shift?->responsible_id === $user->id && $shift->delivered_cash_cents === null;
        $request->attributes->set('cashShiftAccess', $ownsOpenCash ? 'owner' : 'restricted');

        if ($request->routeIs('cash-closings.*', 'logout') || ($ownsOpenCash && $request->routeIs('movements.*'))) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            return to_route('cash-closings.index');
        }

        abort(403, 'Solo puedes usar las funciones disponibles en Turno de caja.');
    }
}
