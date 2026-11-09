<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // UUID des autres services : pas de clé étrangère entre bases.
            $table->uuid('user_id');
            $table->uuid('vehicle_id');
            // Catégorie du véhicule au moment de la réservation (message ReservationCompleted vers ml-service).
            $table->uuid('category_id')->nullable();
            $table->uuid('pickup_agency_id');
            $table->uuid('return_agency_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 16);
            $table->decimal('total_price', 12, 3);
            $table->decimal('deposit_amount', 12, 3);
            $table->char('currency', 3)->default('TND');
            $table->timestamps();

            $table->index('user_id');
            // Recherche des réservations actives d'un véhicule et des chevauchements.
            $table->index(['vehicle_id', 'status', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
