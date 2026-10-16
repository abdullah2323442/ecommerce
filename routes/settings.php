<?php

use App\Http\Controllers\Settings\ConnectedAccountsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PhoneController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/phone', [PhoneController::class, 'show'])->name('phone.show');
    Route::post('settings/phone', [PhoneController::class, 'send'])->middleware('throttle:6,1,phone-send')->name('phone.send');
    Route::post('settings/phone/verify', [PhoneController::class, 'verify'])->middleware('throttle:10,1,phone-verify')->name('phone.verify');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/connected-accounts', [ConnectedAccountsController::class, 'show'])->name('connected-accounts.show');
    Route::get('settings/connected-accounts/{provider}/connect', [ConnectedAccountsController::class, 'connect'])->whereIn('provider', ['google', 'facebook'])->name('connected-accounts.connect');
    Route::delete('settings/connected-accounts/{provider}', [ConnectedAccountsController::class, 'disconnect'])->whereIn('provider', ['google', 'facebook'])->name('connected-accounts.disconnect');
    Route::get('settings/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('settings/two-factor', [TwoFactorController::class, 'begin'])->middleware('throttle:10,1,two-factor-setup')->name('two-factor.begin');
    Route::post('settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1,two-factor-confirm')->name('two-factor.confirm');
    Route::post('settings/two-factor/codes', [TwoFactorController::class, 'codes'])->middleware('throttle:5,1,two-factor-codes')->name('two-factor.codes');
    Route::delete('settings/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:5,1,two-factor-off')->name('two-factor.disable');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});
