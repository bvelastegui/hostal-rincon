<?php

use App\Http\Controllers\AdvanceController;
use App\Http\Controllers\CashClosingController;
use App\Http\Controllers\CashMovementController;
use App\Http\Controllers\DashboardController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request): RedirectResponse {
    return to_route($request->user() ? 'dashboard' : 'login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::redirect('cierres-de-caja', '/turno-de-caja');
    Route::prefix('turno-de-caja')->name('cash-closings.')->group(function () {
        Route::get('/', [CashClosingController::class, 'index'])->name('index');
        Route::post('/', [CashClosingController::class, 'store'])->can('manage-cash-closings')->name('store');
        Route::post('cierre-administrativo', [CashClosingController::class, 'administrativeClose'])->can('manage-cash-closings')->name('administrative-close');
        Route::post('turno-inicial', [CashClosingController::class, 'startShift'])->can('manage-cash-closings')->name('start-shift');
        Route::post('abrir-caja', [CashClosingController::class, 'openOwnShift'])->name('open-own-shift');
        Route::post('entregar-turno', [CashClosingController::class, 'prepareHandoff'])->name('prepare-handoff');
        Route::post('recibir-turno', [CashClosingController::class, 'receiveShift'])->name('receive-shift');
        Route::post('historial/{closing}/resolver-faltante', [CashClosingController::class, 'resolveShortage'])
            ->can('manage-cash-closings')->name('resolve-shortage');
    });

    Route::prefix('movimientos')->name('movements.')->group(function () {
        Route::get('/', [CashMovementController::class, 'index'])->name('index');
        Route::get('nuevo', [CashMovementController::class, 'create'])->name('create');
        Route::post('/', [CashMovementController::class, 'store'])->name('store');
        Route::get('{movement}/confirmar', [CashMovementController::class, 'edit'])->name('edit');
        Route::patch('{movement}/confirmar', [CashMovementController::class, 'update'])->name('update');
        Route::patch('{movement}/devolver', [CashMovementController::class, 'returnToCash'])
            ->can('manage-deposits')->name('return-to-cash');
    });
    Route::prefix('adelantos')->name('advances.')->middleware('can:manage-advances')->group(function () {
        Route::get('/', [AdvanceController::class, 'index'])->name('index');
        Route::get('empleados/nuevo', [AdvanceController::class, 'createEmployee'])->name('employees.create');
        Route::post('empleados', [AdvanceController::class, 'storeEmployee'])->name('employees.store');
        Route::get('empleados/{employee}/editar', [AdvanceController::class, 'editEmployee'])->name('employees.edit');
        Route::put('empleados/{employee}', [AdvanceController::class, 'updateEmployee'])->name('employees.update');
        Route::get('{employee}/nuevo', [AdvanceController::class, 'create'])->name('create');
        Route::post('{employee}', [AdvanceController::class, 'store'])->name('store');
        Route::post('{employee}/descuento', [AdvanceController::class, 'deduct'])->name('deduct');
    });
});

require __DIR__.'/settings.php';
