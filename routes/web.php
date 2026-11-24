<?php

use App\Http\Controllers\AdvanceController;
use App\Http\Controllers\DashboardController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request): RedirectResponse {
    return to_route($request->user() ? 'dashboard' : 'login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::prefix('adelantos')->name('advances.')->middleware('can:manage-advances')->group(function () {
        Route::get('/', [AdvanceController::class, 'index'])->name('index');
        Route::get('{employee}/nuevo', [AdvanceController::class, 'create'])->name('create');
        Route::post('{employee}', [AdvanceController::class, 'store'])->name('store');
        Route::post('{employee}/descuento', [AdvanceController::class, 'deduct'])->name('deduct');
    });
});

require __DIR__.'/settings.php';
