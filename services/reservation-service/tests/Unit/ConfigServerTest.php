<?php

namespace Tests\Unit;

use App\Support\ConfigServer;
use PHPUnit\Framework\TestCase;

/** Lecture de la configuration non secrète sur le Config Server (sans réseau : réponse et cache simulés). */
class ConfigServerTest extends TestCase
{
    private const RESPONSE = [
        'name' => 'rental-reservation',
        'propertySources' => [
            ['name' => 'file:/config-repo/rental-reservation.yml', 'source' => ['service.port' => 8103, 'service.eureka-name' => 'RENTAL-RESERVATION']],
            ['name' => 'file:/config-repo/application.yml', 'source' => [
                'service.host' => 'host.docker.internal', 'eureka.url' => 'http://localhost:8761/eureka',
                'keycloak.issuer' => 'http://localhost:8180/realms/location-voitures', 'service.port' => 9999,
            ]],
        ],
    ];

    public function test_le_fichier_du_service_l_emporte_sur_application_yml(): void
    {
        $this->assertSame([
            'SERVICE_PORT' => '8103',
            'SERVICE_HOST' => 'host.docker.internal',
            'EUREKA_URL' => 'http://localhost:8761/eureka',
            'KEYCLOAK_ISSUER' => 'http://localhost:8180/realms/location-voitures',
        ], ConfigServer::extract(self::RESPONSE));
    }

    public function test_les_valeurs_sont_reprises_du_cache_pendant_60_secondes(): void
    {
        $cache = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($cache, json_encode(['url' => 'http://cs:8888', 'at' => time(), 'values' => ['SERVICE_PORT' => '8103']]));

        // aucun appel réseau : l'URL n'existe pas, seul le cache peut répondre
        $this->assertSame(['SERVICE_PORT' => '8103'], ConfigServer::values('http://cs:8888', $cache));
        unlink($cache);
    }

    public function test_url_du_fichier_rental_reservation(): void
    {
        $this->assertSame('http://localhost:8888/rental-reservation/default', ConfigServer::url('http://localhost:8888/'));
    }
}
