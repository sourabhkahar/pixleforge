<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UsageController;
use App\Http\Controllers\Webhooks\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? to_route('generations.index')
        : Inertia::render('Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
        ]);
});

// Pixelforge → us. Signature-verified; excluded from CSRF in bootstrap/app.php.
Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {
    // Breeze redirects here after login/registration.
    Route::redirect('/dashboard', '/generate')->name('dashboard');

    Route::get('/generate', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('/generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:120,1')
        ->name('generations.estimate');
    Route::post('/generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::get('/generations/status', [GenerationController::class, 'status'])->name('generations.status');

    Route::get('/usage', [UsageController::class, 'index'])->name('usage.index');
    Route::post('/credits/purchase', [CreditsController::class, 'purchase'])
        ->middleware('throttle:10,1')
        ->name('credits.purchase');

    Route::get('/admin/report', ReportController::class)->middleware('admin')->name('admin.report');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
