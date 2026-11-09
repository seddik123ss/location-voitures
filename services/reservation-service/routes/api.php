<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\InternalReservationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// Entre services (jamais via la Gateway) : token de compte de service avec le rôle INTERNAL.
Route::prefix('internal/reservations')
    ->middleware(['keycloak', 'role:INTERNAL'])
    ->controller(InternalReservationController::class)
    ->group(function () {
        Route::get('/', 'byUser');
        Route::get('/active', 'active'); // avant /{id}
        Route::get('/{id}', 'show');
    });
