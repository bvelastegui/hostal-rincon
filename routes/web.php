<?php

use App\Http\Controllers\AdvanceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('adelantos', [AdvanceController::class, 'index'])->name('advances.index');
    Route::get('adelantos/{employee}/nuevo', [AdvanceController::class, 'create'])->name('advances.create');
    Route::post('adelantos/{employee}', [AdvanceController::class, 'store'])->name('advances.store');
    Route::post('adelantos/{employee}/descuento', [AdvanceController::class, 'deduct'])->name('advances.deduct');
});

require __DIR__.'/settings.php';
