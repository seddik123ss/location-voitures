<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\InternalReservationController;
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// Publiques, via la Gateway (/api/reservations/** -> lb://RENTAL-RESERVATION) : token d'utilisateur.
Route::prefix('api/reservations')
    ->middleware(['keycloak', 'role:CLIENT,AGENT,ADMIN'])
    ->controller(ReservationController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{id}', 'show');
        Route::put('/{id}', 'update');
        Route::post('/{id}/cancel', 'cancel');
        Route::patch('/{id}/status', 'changeStatus')->middleware('role:AGENT,ADMIN');
        Route::delete('/{id}', 'destroy')->middleware('role:ADMIN');
    });

// Entre services (jamais via la Gateway) : token de compte de service avec le rôle INTERNAL.
Route::prefix('internal/reservations')
    ->middleware(['keycloak', 'role:INTERNAL'])
    ->controller(InternalReservationController::class)
    ->group(function () {
        Route::get('/', 'byUser');
        Route::get('/active', 'active'); // avant /{id}
        Route::get('/{id}', 'show');
    });
