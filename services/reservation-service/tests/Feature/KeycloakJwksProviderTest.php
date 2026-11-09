<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Security\KeycloakJwksProvider;
use App\Security\KeySetProvider;
use App\Support\DemoIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestJwt;
use Tests\TestCase;

/** Le vrai fournisseur JWKS, avec Keycloak simulé par Http::fake. */
class KeycloakJwksProviderTest extends TestCase
{
    use RefreshDatabase;

    private const CERTS = 'http://localhost:8180/realms/location-voitures/protocol/openid-connect/certs';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(KeySetProvider::class, KeycloakJwksProvider::class);
    }

    /** JWKS comme Keycloak le publie : une clé de signature RS256 et une clé de chiffrement. */
    private function jwks(): array
    {
        $enc = TestJwt::publicJwk(TestJwt::OTHER_KEY_FILE, 'enc-key');
        $enc['use'] = 'enc';
        $enc['alg'] = 'RSA-OAEP';

        return ['keys' => [TestJwt::publicJwk(), $enc]];
    }

    public function test_jwks_deduit_de_l_emetteur_et_mis_en_cache(): void
    {
        Http::fake([self::CERTS => Http::response($this->jwks())]);

        $uri = '/internal/reservations/active?vehicleId='.DemoIds::V1;
        $this->getWithToken($uri, TestJwt::internal())->assertOk();
        $this->getWithToken($uri, TestJwt::internal())->assertOk();

        Http::assertSentCount(1);
    }

    public function test_keycloak_jwks_separe_de_l_emetteur(): void
    {
        config(['keycloak.jwks_url' => 'http://keycloak:8080/realms/location-voitures/protocol/openid-connect/certs']);
        Http::fake(['http://keycloak:8080/*' => Http::response($this->jwks())]);

        $this->getWithToken('/internal/reservations/active?vehicleId='.DemoIds::V1, TestJwt::internal())->assertOk();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://keycloak:8080/'));
    }

    public function test_cle_de_chiffrement_ignoree(): void
    {
        Http::fake([self::CERTS => Http::response($this->jwks())]);

        $keys = app(KeySetProvider::class)->keys();

        $this->assertSame([TestJwt::KID], array_keys($keys));
    }

    public function test_kid_inconnu_recharge_le_jwks_au_plus_une_fois_par_minute(): void
    {
        Http::fake([self::CERTS => Http::response($this->jwks())]);
        $uri = '/internal/reservations/active?vehicleId='.DemoIds::V1;
        $unknownKid = TestJwt::token(TestJwt::SERVICE_ACCOUNT, ['INTERNAL'], [], TestJwt::OTHER_KEY_FILE, 'rotation');

        $this->assertError($this->getWithToken($uri, $unknownKid), 401, 'UNAUTHORIZED', 'clé inconnue');
        $this->assertError($this->getWithToken($uri, $unknownKid), 401, 'UNAUTHORIZED', 'clé inconnue');

        // 1 chargement initial + 1 seul rechargement malgré deux tokens à kid inconnu.
        Http::assertSentCount(2);
    }

    public function test_keycloak_injoignable_503(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $this->assertError(
            $this->getWithToken('/internal/reservations/active?vehicleId='.DemoIds::V1, TestJwt::internal()),
            503, 'SERVICE_UNAVAILABLE', 'Keycloak injoignable',
        );
    }

    public function test_reponse_jwks_inattendue_503(): void
    {
        Http::fake([self::CERTS => Http::response('erreur', 500)]);

        $this->expectException(ApiException::class);
        app(KeySetProvider::class)->keys();
    }
}
