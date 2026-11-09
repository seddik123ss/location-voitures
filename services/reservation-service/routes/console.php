<?php

use App\Models\Reservation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;

// Démarrage du conteneur : données de docs/demo-ids.md seulement si la base est vide, pour ne pas remettre
// à zéro les réservations à chaque redémarrage (php artisan db:seed le fait, lui, à la demande).
Artisan::command('demo:seed-if-empty', function () {
    if (Reservation::query()->exists()) {
        $this->line('Réservations déjà présentes : données de démonstration non chargées');

        return;
    }
    $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
    $this->info('Données de démonstration chargées (docs/demo-ids.md)');
})->purpose('Charge les réservations de démonstration si la base est vide');
