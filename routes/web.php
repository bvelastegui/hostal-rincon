<?php

use App\Http\Controllers\AdvanceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::prefix('adelantos')->name('advances.')->middleware('can:manage-advances')->group(function () {
        Route::get('/', [AdvanceController::class, 'index'])->name('index');
        Route::get('{employee}/nuevo', [AdvanceController::class, 'create'])->name('create');
        Route::post('{employee}', [AdvanceController::class, 'store'])->name('store');
        Route::post('{employee}/descuento', [AdvanceController::class, 'deduct'])->name('deduct');
    });
});

require __DIR__.'/settings.php';
