<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Même base : vraie clé étrangère, l'historique disparaît avec la réservation.
            $table->foreignUuid('reservation_id')->constrained('reservations')->cascadeOnDelete();
            // 1, 2, 3... par réservation : ordre fiable même si deux changements tombent dans la même seconde.
            $table->unsignedInteger('position');
            $table->string('from_status', 16)->nullable(); // null pour la création
            $table->string('to_status', 16);
            $table->dateTime('changed_at');
            // UUID de l'utilisateur (sub du token), null pour un changement automatique (message RabbitMQ).
            $table->uuid('changed_by')->nullable();
            $table->string('comment', 1000)->nullable();

            $table->unique(['reservation_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_status_history');
    }
};
