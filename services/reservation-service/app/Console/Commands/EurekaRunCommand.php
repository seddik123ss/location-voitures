<?php

namespace App\Console\Commands;

use App\Eureka\ServiceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Rental\Eureka\EurekaUnavailable;

/**
 * php artisan eureka:run (ou composer eureka), à lancer à côté du serveur web :
 * enregistre RENTAL-RESERVATION, envoie un battement toutes les 30 s, se réenregistre si Eureka a oublié l'instance
 * ou redémarre, et retire l'instance proprement à l'arrêt (Ctrl+C sous Windows, SIGINT / SIGTERM sous Linux).
 */
final class EurekaRunCommand extends Command
{
    protected $signature = 'eureka:run
        {--interval= : Secondes entre deux battements (30 par défaut)}
        {--beats= : Arrêt après ce nombre de battements (démonstration et tests)}';

    protected $description = "Enregistre le service dans Eureka et maintient l'enregistrement (Ctrl+C pour se retirer)";

    private bool $stopRequested = false;

    public function handle(ServiceRegistry $registry): int
    {
        $interval = max(1, (int) ($this->option('interval') ?? config('eureka.heartbeat_interval')));
        $maxBeats = $this->option('beats') !== null ? max(0, (int) $this->option('beats')) : null;
        $this->installStopHandlers();

        $this->log(sprintf('Instance %s, Eureka %s, battement toutes les %d s', $registry->instanceId(), config('eureka.url'), $interval));
        $registered = $this->register($registry);
        $beats = 0;
        $seconds = 0;

        while (! $this->stopRequested && ($maxBeats === null || $beats < $maxBeats)) {
            // Pauses d'une seconde pour réagir vite à Ctrl+C.
            Sleep::for(1)->second();
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->stopRequested || ++$seconds % $interval !== 0) {
                continue;
            }
            $beats++;

            if (! $registered) {
                $registered = $this->register($registry);
                continue;
            }
            try {
                $registry->heartbeat();
                $this->log('Battement envoyé', verbose: true);
            } catch (EurekaUnavailable $e) {
                $this->log('Battement refusé ('.$e->getMessage().'), nouvel enregistrement');
                $registered = $this->register($registry);
            }
        }

        if ($registered) {
            try {
                $registry->deregister();
                $this->log("Retiré d'Eureka : ".$registry->instanceId());
            } catch (EurekaUnavailable $e) {
                $this->log("Retrait impossible ({$e->getMessage()}) : Eureka retirera l'instance à l'expiration du bail (90 s)");
            }
        }

        return self::SUCCESS;
    }

    /** Arrêt demandé (Ctrl+C, signal, ou test) : l'instance est retirée à la sortie de la boucle. */
    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    private function register(ServiceRegistry $registry): bool
    {
        try {
            $registry->register();
            $this->log('Enregistré dans Eureka : '.$registry->instanceId());

            return true;
        } catch (EurekaUnavailable $e) {
            $this->log('Eureka indisponible, nouvel essai au prochain battement : '.$e->getMessage());

            return false;
        }
    }

    private function installStopHandlers(): void
    {
        // Windows : Ctrl+C et Ctrl+Pause dans la console (PHP CLI).
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function (int $event): void {
                $this->requestStop();
            });
        }
        // Linux (conteneur Docker) : docker stop envoie SIGTERM.
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, fn () => $this->requestStop());
            pcntl_signal(SIGTERM, fn () => $this->requestStop());
        }
    }

    private function log(string $message, bool $verbose = false): void
    {
        $line = sprintf('[%s] %s', gmdate('H:i:s'), $message);
        $verbose ? $this->line($line, null, 'v') : $this->line($line);
    }
}
