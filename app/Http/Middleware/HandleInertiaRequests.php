<?php

namespace App\Http\Middleware;

use App\Models\CashShift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Middleware;
use Laravel\Fortify\Fortify;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        Inertia::encryptHistory($user !== null);
        $activeShift = $user?->is_admin
            ? CashShift::active()
            : $request->attributes->get('activeCashShift');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'loginField' => Fortify::username(),
                'canManageAdvances' => Gate::allows('manage-advances'),
                'canManageCashSettings' => Gate::allows('manage-cash-settings'),
                'canViewMonthlyReport' => Gate::allows('view-monthly-report'),
                'canManageMovementSensitivity' => Gate::allows('manage-movement-sensitivity'),
                'cashShiftAccess' => $request->attributes->get('cashShiftAccess'),
                'activeCashShift' => $activeShift === null ? null : [
                    'responsible' => $activeShift->responsible_name,
                    'awaitingReceipt' => $activeShift->delivered_cash_cents !== null,
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
