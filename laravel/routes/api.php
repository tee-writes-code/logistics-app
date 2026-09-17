<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\MagicLinkController;
use Illuminate\Support\Facades\Route;

/*
| Sanctum SPA (cookie) authentication for first-party clients. The SPA first
| calls GET /sanctum/csrf-cookie, then these endpoints.
*/
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user'])->name('auth.user');
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
});

/*
| Recipient magic-link access: a signed URL scoped to a single job. The
| `signed` middleware rejects tampered or expired signatures with a 403.
*/
Route::get('/recipient/jobs/{job}', [MagicLinkController::class, 'show'])
    ->middleware('signed')
    ->name('recipient.jobs.show');
