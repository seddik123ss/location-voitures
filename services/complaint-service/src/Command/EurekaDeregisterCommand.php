<?php

namespace App\Command;

use Rental\Eureka\EurekaClient;
use Rental\Eureka\EurekaUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Retire l'instance d'Eureka, par exemple si app:eureka:run a été tué sans pouvoir le faire (terminal fermé). */
#[AsCommand(name: 'app:eureka:deregister', description: 'Retire l\'instance du service d\'Eureka')]
final class EurekaDeregisterCommand extends Command
{
    public function __construct(private readonly EurekaClient $eureka)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->eureka->deregister();
        } catch (EurekaUnavailable $e) {
            $output->writeln('<error>Retrait impossible : ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('Retiré d\'Eureka : ' . $this->eureka->instanceId());

        return Command::SUCCESS;
    }
}
