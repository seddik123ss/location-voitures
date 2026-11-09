<?php

namespace App\Command;

use Rental\Eureka\EurekaClient;
use Rental\Eureka\EurekaUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Enregistre RENTAL-COMPLAINT dans Eureka et envoie un battement toutes les 30 s, à lancer à côté du serveur web.
 *
 * Même comportement qu'EurekaRunner (shared/php-eureka) : nouvel essai si Eureka est injoignable,
 * nouvel enregistrement si Eureka a oublié l'instance. En plus, Ctrl+C (Windows) ou SIGINT / SIGTERM
 * (Linux, docker stop) retire l'instance d'Eureka avant de quitter : la Gateway cesse aussitôt de router
 * vers un service arrêté, sans attendre l'expiration du bail (que l'auto-préservation d'Eureka peut bloquer).
 */
#[AsCommand(name: 'app:eureka:run', description: 'Enregistre le service dans Eureka et maintient l\'enregistrement (Ctrl+C pour se retirer)')]
final class EurekaRunCommand extends Command
{
    private bool $stopRequested = false;

    public function __construct(private readonly EurekaClient $eureka)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Secondes entre deux battements', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int) $input->getOption('interval'));
        $log = static fn (string $m) => $output->writeln(sprintf('[%s] %s', gmdate('H:i:s'), $m));
        $this->installStopHandlers();

        $log(sprintf('Instance %s, battement toutes les %d s', $this->eureka->instanceId(), $interval));
        $registered = $this->register($log);
        $nextBeat = time() + $interval;

        while (!$this->stopRequested) {
            sleep(1); // court, pour réagir vite à Ctrl+C / SIGTERM
            if (\function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->stopRequested || time() < $nextBeat) {
                continue;
            }
            $nextBeat = time() + $interval;

            if (!$registered) {
                $registered = $this->register($log);
                continue;
            }
            try {
                $this->eureka->heartbeat();
            } catch (EurekaUnavailable $e) {
                $log('Battement refusé (' . $e->getMessage() . '), nouvel enregistrement');
                $registered = $this->register($log);
            }
        }

        if ($registered) {
            try {
                $this->eureka->deregister();
                $log('Retiré d\'Eureka : ' . $this->eureka->instanceId());
            } catch (EurekaUnavailable $e) {
                $log('Retrait impossible (' . $e->getMessage() . ') : Eureka retirera l\'instance à l\'expiration du bail');
            }
        }

        return Command::SUCCESS;
    }

    private function register(callable $log): bool
    {
        try {
            $this->eureka->register();
            $log('Enregistré dans Eureka : ' . $this->eureka->instanceId());

            return true;
        } catch (EurekaUnavailable $e) {
            $log('Eureka indisponible, nouvel essai au prochain battement : ' . $e->getMessage());

            return false;
        }
    }

    private function installStopHandlers(): void
    {
        $stop = function (): void {
            $this->stopRequested = true;
        };
        if (\function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(static fn (int $event) => $stop());
        }
        if (\function_exists('pcntl_signal')) {
            pcntl_signal(\SIGINT, static fn () => $stop());
            pcntl_signal(\SIGTERM, static fn () => $stop());
        }
    }
}
