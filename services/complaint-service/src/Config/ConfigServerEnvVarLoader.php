<?php

namespace App\Config;

use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;

/**
 * Configuration NON secrète lue sur le Config Server (Spring Cloud Config, infra/config-server) :
 * GET {CONFIG_SERVER_URL}/rental-complaint/default renvoie infra/config-repo/rental-complaint.yml et application.yml.
 *
 * Symfony n'appelle ce « chargeur de variables » que pour une variable absente de l'environnement et des .env :
 * priorité variable d'environnement ou .env.local  >  Config Server  >  valeur par défaut env(...) de config/services.yaml.
 * Les secrets ne viennent jamais du Config Server. CONFIG_SERVER_URL= (vide) désactive l'appel ; jamais en test.
 *
 * Le serveur PHP intégré redémarre l'application à chaque requête : les valeurs sont gardées 60 s dans
 * var/config-server.json (un échec aussi, pour ne jamais ralentir les requêtes).
 */
final class ConfigServerEnvVarLoader implements EnvVarLoaderInterface
{
    /** Clé du Config Server => variable d'environnement utilisée par config/services.yaml */
    public const KEYS = [
        'service.port' => 'SERVICE_PORT',
        'service.host' => 'SERVICE_HOST',
        'eureka.url' => 'EUREKA_URL',
        'keycloak.issuer' => 'KEYCLOAK_ISSUER',
    ];

    private const APPLICATION = 'rental-complaint';
    private const CACHE_SECONDS = 60;
    private const TIMEOUT_SECONDS = 3;

    private readonly string $cacheFile;

    public function __construct(?string $cacheFile = null)
    {
        $this->cacheFile = $cacheFile ?? \dirname(__DIR__, 2).'/var/config-server.json';
    }

    public function loadEnvVars(): array
    {
        if (self::env('APP_ENV') === 'test') {
            return [];
        }
        $baseUrl = self::env('CONFIG_SERVER_URL') ?? 'http://localhost:8888';

        return $baseUrl === '' ? [] : $this->values($baseUrl);
    }

    /** @return array<string, string> {VARIABLE: valeur} (cache 60 s), vide si le Config Server est injoignable */
    public function values(string $baseUrl): array
    {
        $cached = is_file($this->cacheFile) ? json_decode((string) @file_get_contents($this->cacheFile), true) : null;
        if (\is_array($cached) && ($cached['url'] ?? null) === $baseUrl && time() - ($cached['at'] ?? 0) < self::CACHE_SECONDS) {
            return $cached['values'] ?? [];
        }
        $values = self::fetch($baseUrl);
        if ($values === null) {
            $values = [];
            error_log('[config] Config Server injoignable ('.self::url($baseUrl).') : .env et valeurs par défaut');
        }
        @file_put_contents($this->cacheFile, json_encode(['url' => $baseUrl, 'at' => time(), 'values' => $values]));

        return $values;
    }

    public static function url(string $baseUrl): string
    {
        return rtrim($baseUrl, '/').'/'.self::APPLICATION.'/default';
    }

    /** @return array<string, string>|null null si injoignable ou réponse illisible */
    public static function fetch(string $baseUrl): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => self::TIMEOUT_SECONDS]]);
        $body = @file_get_contents(self::url($baseUrl), false, $context);
        $document = $body === false ? null : json_decode($body, true);
        if (!\is_array($document) || !\is_array($document['propertySources'] ?? null)) {
            return null;
        }

        return self::extract($document);
    }

    /**
     * propertySources : le fichier spécifique (le plus prioritaire) avant application.yml.
     *
     * @return array<string, string>
     */
    public static function extract(array $document): array
    {
        $merged = [];
        foreach (array_reverse($document['propertySources']) as $source) {
            $merged = array_merge($merged, (array) ($source['source'] ?? []));
        }
        $values = [];
        foreach (self::KEYS as $key => $variable) {
            if (\array_key_exists($key, $merged)) {
                $values[$variable] = (string) $merged[$key];
            }
        }

        return $values;
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return $value === false || $value === null ? null : (string) $value;
    }
}
