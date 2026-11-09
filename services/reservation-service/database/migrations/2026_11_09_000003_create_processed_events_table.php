<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Messages RabbitMQ déjà traités : un eventId reçu deux fois n'est appliqué qu'une fois. */
    public function up(): void
    {
        Schema::create('processed_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->string('type', 64);
            $table->string('queue', 128);
            // Ce que le message a produit (ex. « PENDING -> CONFIRMED », « ignoré : type DEPOSIT »).
            $table->string('outcome', 255);
            $table->dateTime('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_events');
    }
};
