<?php

use App\Http\Controllers\Settings\CashSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::prefix('settings/cash')->name('cash-settings.')->can('manage-cash-settings')->group(function () {
        Route::get('/', [CashSettingsController::class, 'edit'])->name('edit');
        Route::put('opening', [CashSettingsController::class, 'updateOpening'])->name('opening.update');
    });

    Route::prefix('settings/banks')->name('bank-settings.')->can('manage-cash-settings')->group(function () {
        Route::get('/', [CashSettingsController::class, 'editBanks'])->name('edit');
        Route::post('/', [CashSettingsController::class, 'storeBank'])->name('store');
        Route::patch('{bank}/status', [CashSettingsController::class, 'updateBankStatus'])->name('status.update');
        Route::get('accounts/nueva', [CashSettingsController::class, 'createAccount'])->name('accounts.create');
        Route::post('accounts', [CashSettingsController::class, 'storeAccount'])->name('accounts.store');
        Route::get('accounts/{account}/editar', [CashSettingsController::class, 'editAccount'])->name('accounts.edit');
        Route::patch('accounts/{account}', [CashSettingsController::class, 'updateAccount'])->name('accounts.update');
    });
});
