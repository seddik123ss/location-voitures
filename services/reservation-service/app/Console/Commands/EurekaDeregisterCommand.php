<?php

namespace App\Console\Commands;

use App\Eureka\ServiceRegistry;
use Illuminate\Console\Command;
use Rental\Eureka\EurekaUnavailable;

/** Retire une instance restée dans Eureka (eureka:run tué sans Ctrl+C, fenêtre fermée...). */
final class EurekaDeregisterCommand extends Command
{
    protected $signature = 'eureka:deregister';

    protected $description = "Retire l'instance du service d'Eureka";

    public function handle(ServiceRegistry $registry): int
    {
        try {
            $registry->deregister();
        } catch (EurekaUnavailable $e) {
            $this->error('Retrait impossible : '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Retiré d'Eureka : ".$registry->instanceId());

        return self::SUCCESS;
    }
}
