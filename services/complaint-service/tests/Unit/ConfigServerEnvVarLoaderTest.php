<?php

namespace App\Tests\Unit;

use App\Config\ConfigServerEnvVarLoader;
use PHPUnit\Framework\TestCase;

/** Lecture de la configuration non secrète sur le Config Server (sans réseau : réponse et cache simulés). */
class ConfigServerEnvVarLoaderTest extends TestCase
{
    private const RESPONSE = [
        'name' => 'rental-complaint',
        'propertySources' => [
            ['name' => 'file:/config-repo/rental-complaint.yml', 'source' => ['service.port' => 8106, 'service.eureka-name' => 'RENTAL-COMPLAINT']],
            ['name' => 'file:/config-repo/application.yml', 'source' => [
                'service.host' => 'host.docker.internal', 'eureka.url' => 'http://localhost:8761/eureka',
                'keycloak.issuer' => 'http://localhost:8180/realms/location-voitures', 'service.port' => 9999,
            ]],
        ],
    ];

    public function testLeFichierDuServiceLEmporteSurApplicationYml(): void
    {
        self::assertSame([
            'SERVICE_PORT' => '8106',
            'SERVICE_HOST' => 'host.docker.internal',
            'EUREKA_URL' => 'http://localhost:8761/eureka',
            'KEYCLOAK_ISSUER' => 'http://localhost:8180/realms/location-voitures',
        ], ConfigServerEnvVarLoader::extract(self::RESPONSE));
    }

    public function testLesValeursSontReprisesDuCachePendant60Secondes(): void
    {
        $cache = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($cache, json_encode(['url' => 'http://cs:8888', 'at' => time(), 'values' => ['SERVICE_PORT' => '8106']]));

        // aucun appel réseau : l'URL n'existe pas, seul le cache peut répondre
        self::assertSame(['SERVICE_PORT' => '8106'], (new ConfigServerEnvVarLoader($cache))->values('http://cs:8888'));
        unlink($cache);
    }

    public function testDesactiveEnTest(): void
    {
        // APP_ENV=test (phpunit) : aucun appel au Config Server
        self::assertSame([], (new ConfigServerEnvVarLoader())->loadEnvVars());
    }
}
