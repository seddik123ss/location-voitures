<?php

namespace Rental\Eureka;

/**
 * Boucle à lancer dans une commande console à côté du serveur web :
 * enregistrement, puis heartbeat toutes les 30 s, et nouvel enregistrement si Eureka a oublié l'instance.
 */
final class EurekaRunner
{
    public static function run(EurekaClient $client, callable $log, int $intervalSeconds = 30): void
    {
        self::tryRegister($client, $log);
        while (true) {
            sleep($intervalSeconds);
            try {
                $client->heartbeat();
            } catch (EurekaUnavailable $e) {
                $log('Heartbeat refusé (' . $e->getMessage() . '), nouvel enregistrement');
                self::tryRegister($client, $log);
            }
        }
    }

    private static function tryRegister(EurekaClient $client, callable $log): void
    {
        try {
            $client->register();
            $log('Enregistré dans Eureka : ' . $client->instanceId());
        } catch (EurekaUnavailable $e) {
            $log('Eureka indisponible, nouvel essai dans 30 s : ' . $e->getMessage());
        }
    }
}
