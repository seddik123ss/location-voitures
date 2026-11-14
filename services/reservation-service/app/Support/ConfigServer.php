<?php

namespace App\Support;

/**
 * Configuration NON secrète lue sur le Config Server (Spring Cloud Config, infra/config-server) :
 * GET {CONFIG_SERVER_URL}/rental-reservation/default renvoie infra/config-repo/rental-reservation.yml et application.yml.
 *
 * Appelé juste après le chargement du .env (bootstrap/app.php) : seules les variables encore absentes sont remplies.
 * Priorité : variable d'environnement ou .env  >  Config Server  >  valeur par défaut de config/*.php.
 * Les secrets ne viennent jamais du Config Server. CONFIG_SERVER_URL= (vide) désactive l'appel ; jamais en test.
 *
 * Le serveur PHP intégré (artisan serve) redémarre le framework à chaque requête : les valeurs sont gardées
 * 60 s dans bootstrap/cache/config-server.json (un échec aussi, pour ne jamais ralentir les requêtes).
 */
final class ConfigServer
{
    /** Clé du Config Server => variable d'environnement lue par config/*.php */
    public const KEYS = [
        'service.port' => 'SERVICE_PORT',
        'service.host' => 'SERVICE_HOST',
        'eureka.url' => 'EUREKA_URL',
        'keycloak.issuer' => 'KEYCLOAK_ISSUER',
    ];

    private const APPLICATION = 'rental-reservation';
    private const CACHE_SECONDS = 60;
    private const TIMEOUT_SECONDS = 3;

    /** @return array<string, string> variables remplies par le Config Server */
    public static function fillEnvironment(string $cacheFile): array
    {
        if (self::env('APP_ENV') === 'testing') {
            return [];
        }
        $baseUrl = self::env('CONFIG_SERVER_URL') ?? 'http://localhost:8888';
        if ($baseUrl === '') {
            return [];
        }
        $applied = [];
        foreach (self::values($baseUrl, $cacheFile) as $variable => $value) {
            if (self::env($variable) === null || self::env($variable) === '') {
                putenv("$variable=$value");
                $_ENV[$variable] = $value;
                $_SERVER[$variable] = $value;
                $applied[$variable] = $value;
            }
        }

        return $applied;
    }

    /** @return array<string, string> {VARIABLE: valeur} du Config Server (cache 60 s), vide s'il est injoignable */
    public static function values(string $baseUrl, string $cacheFile): array
    {
        $cached = is_file($cacheFile) ? json_decode((string) @file_get_contents($cacheFile), true) : null;
        if (is_array($cached) && ($cached['url'] ?? null) === $baseUrl && time() - ($cached['at'] ?? 0) < self::CACHE_SECONDS) {
            return $cached['values'] ?? [];
        }
        $values = self::fetch($baseUrl);
        if ($values === null) {
            $values = [];
            error_log('[config] Config Server injoignable ('.self::url($baseUrl).') : .env et valeurs par défaut');
        }
        @file_put_contents($cacheFile, json_encode(['url' => $baseUrl, 'at' => time(), 'values' => $values]));

        return $values;
    }

    public static function url(string $baseUrl): string
    {
        return rtrim($baseUrl, '/').'/'.self::APPLICATION.'/default';
    }

    /** @return array<string, string>|null null si injoignable ou réponse illisible */
    public static function fetch(string $baseUrl): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => self::TIMEOUT_SECONDS, 'ignore_errors' => false]]);
        $body = @file_get_contents(self::url($baseUrl), false, $context);
        $document = $body === false ? null : json_decode($body, true);
        if (! is_array($document) || ! is_array($document['propertySources'] ?? null)) {
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
            if (array_key_exists($key, $merged)) {
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
