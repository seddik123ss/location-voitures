<?php

namespace Tests\Feature;

use App\Support\DemoIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestJwt;
use Tests\TestCase;

/** Contrôles du token : signature, clé, algorithme, émetteur, expiration, type, sub. */
class JwtAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/internal/reservations/active?vehicleId='.DemoIds::V1;

    private function assertInvalid(?string $token, string $messagePart, ?string $header = null): void
    {
        $response = $this->getJson(self::URI, ['Authorization' => $header ?? 'Bearer '.$token]);
        $this->assertError($response, 401, 'UNAUTHORIZED', $messagePart);
        $response->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    }

    public function test_token_valide_accepte(): void
    {
        $this->getWithToken(self::URI, TestJwt::internal())->assertOk();
    }

    public function test_en_tete_qui_n_est_pas_bearer(): void
    {
        $this->assertInvalid(null, 'Bearer <token>', 'Basic Z3Vlc3Q6Z3Vlc3Q=');
    }

    public function test_token_mal_forme(): void
    {
        $this->assertInvalid('pas-un-jwt', 'Token invalide');
        $this->assertInvalid('a.b.c', 'Token invalide');
    }

    public function test_signature_d_une_autre_cle(): void
    {
        $this->assertInvalid(TestJwt::token(TestJwt::SERVICE_ACCOUNT, ['INTERNAL'], [], TestJwt::OTHER_KEY_FILE), 'Signature du token invalide');
    }

    public function test_kid_inconnu(): void
    {
        $this->assertInvalid(TestJwt::token(TestJwt::SERVICE_ACCOUNT, ['INTERNAL'], [], TestJwt::OTHER_KEY_FILE, 'autre-kid'), 'clé inconnue');
    }

    public function test_algorithme_autre_que_rs256_refuse(): void
    {
        $hs256 = \Firebase\JWT\JWT::encode(['iss' => TestJwt::ISSUER, 'sub' => TestJwt::SERVICE_ACCOUNT, 'exp' => time() + 60, 'roles' => ['INTERNAL']], str_repeat('k', 32), 'HS256', TestJwt::KID);
        $this->assertInvalid($hs256, 'RS256');

        $none = rtrim(strtr(base64_encode('{"alg":"none","kid":"test-key"}'), '+/', '-_'), '=').'.'
            .rtrim(strtr(base64_encode('{"sub":"'.TestJwt::SERVICE_ACCOUNT.'","roles":["INTERNAL"]}'), '+/', '-_'), '=').'.';
        $this->assertInvalid($none, 'RS256');
    }

    public function test_token_expire(): void
    {
        $this->assertInvalid(TestJwt::internal(['exp' => time() - 60, 'iat' => time() - 400]), 'Token expiré');
    }

    public function test_tolerance_d_horloge_de_10_secondes(): void
    {
        $this->getWithToken(self::URI, TestJwt::internal(['exp' => time() - 5]))->assertOk();
    }

    public function test_token_sans_expiration(): void
    {
        $this->assertInvalid(TestJwt::internal(['exp' => null]), "date d'expiration");
    }

    public function test_autre_emetteur(): void
    {
        $this->assertInvalid(TestJwt::internal(['iss' => 'http://localhost:8180/realms/master']), 'Émetteur du token invalide');
        $this->assertInvalid(TestJwt::internal(['iss' => 'http://keycloak:8080/realms/location-voitures']), 'Émetteur du token invalide');
    }

    public function test_id_token_ou_refresh_token_refuse(): void
    {
        $this->assertInvalid(TestJwt::internal(['typ' => 'ID']), 'access token');
        $this->assertInvalid(TestJwt::internal(['typ' => 'Refresh']), 'access token');
    }

    public function test_sub_absent_ou_invalide(): void
    {
        $this->assertInvalid(TestJwt::internal(['sub' => null]), 'sub');
        $this->assertInvalid(TestJwt::internal(['sub' => 'service-account']), 'sub');
    }

    public function test_roles_absents_403(): void
    {
        $this->assertError($this->getWithToken(self::URI, TestJwt::internal(['roles' => null])), 403, 'FORBIDDEN');
    }

    public function test_role_internal_ailleurs_que_dans_le_claim_roles_403(): void
    {
        // Le rôle doit être dans "roles" (mapper du realm), pas dans realm_access ni sous une autre casse.
        $token = TestJwt::internal(['roles' => ['internal'], 'realm_access' => ['roles' => ['INTERNAL']]]);
        $this->assertError($this->getWithToken(self::URI, $token), 403, 'FORBIDDEN');
    }
}
