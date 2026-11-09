<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** Documentation OpenAPI : Swagger UI, chaque route documentée, contrat partagé à jour. */
class OpenApiTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function spec(): array
    {
        Artisan::call('l5-swagger:generate');

        return json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true);
    }

    public function test_swagger_ui_et_contrat_json(): void
    {
        self::spec();

        $this->get('/api/doc')->assertOk()->assertSee('swagger-ui', false);
        $this->get('/docs')->assertOk()->assertJsonPath('info.title', 'reservation-service');
    }

    public function test_chaque_route_du_service_est_documentee_avec_ses_erreurs(): void
    {
        $paths = self::spec()['paths'];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            if (! preg_match('#^/(api/reservations|internal/reservations|health)#', $uri)) {
                continue; // routes de Swagger UI
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $operation = $paths[$uri][strtolower($method)] ?? null;
                $this->assertNotNull($operation, "$method $uri non documentée");
                $this->assertNotEmpty($operation['summary'] ?? '', "$method $uri sans résumé");
                $codes = array_map('intval', array_keys($operation['responses']));
                $this->assertNotEmpty(array_filter($codes, fn ($c) => $c < 300), "$method $uri sans réponse de succès");
                if ($uri !== '/health') {
                    $this->assertContains(401, $codes, "$method $uri sans 401");
                    $this->assertNotEmpty(array_filter($codes, fn ($c) => $c >= 403), "$method $uri sans réponse d'erreur");
                }
            }
        }
    }

    public function test_le_contrat_partage_est_a_jour(): void
    {
        self::spec();
        $generated = Yaml::parseFile(storage_path('api-docs/api-docs.yaml'));
        $shared = Yaml::parseFile(base_path('../../docs/openapi/reservation-service.yaml'));

        $this->assertEquals($generated, $shared, 'docs/openapi/reservation-service.yaml est en retard : lancer composer openapi:dump');
    }
}
