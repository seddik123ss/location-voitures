<?php

namespace Rental\Eureka;

/**
 * Client Eureka minimal pour les services PHP (Symfony, Laravel), basé sur l'API REST d'Eureka.
 * Utilisé par complaint-service (RENTAL-COMPLAINT) et reservation-service (RENTAL-RESERVATION).
 */
final class EurekaClient
{
    private string $host;

    public function __construct(
        private string $eurekaUrl,   // ex. http://localhost:8761/eureka
        private string $app,         // ex. RENTAL-COMPLAINT
        private int $port,           // ex. 8106
        ?string $host = null         // nom annoncé aux autres ; par défaut SERVICE_HOST, sinon host.docker.internal
    ) {
        $this->app = strtoupper($this->app);
        $this->host = $host ?? (getenv('SERVICE_HOST') ?: 'host.docker.internal');
    }

    public function instanceId(): string
    {
        return "{$this->host}:" . strtolower($this->app) . ":{$this->port}";
    }

    public function register(): void
    {
        $base = "http://{$this->host}:{$this->port}";
        $this->send('POST', "/apps/{$this->app}", ['instance' => [
            'instanceId' => $this->instanceId(),
            'hostName' => $this->host,
            'app' => $this->app,
            'ipAddr' => gethostbyname($this->host),
            'status' => 'UP',
            'port' => ['$' => $this->port, '@enabled' => 'true'],
            'vipAddress' => strtolower($this->app),
            'secureVipAddress' => strtolower($this->app),
            'homePageUrl' => "$base/",
            'statusPageUrl' => "$base/health",
            'healthCheckUrl' => "$base/health",
            'dataCenterInfo' => [
                '@class' => 'com.netflix.appinfo.InstanceInfo$DefaultDataCenterInfo',
                'name' => 'MyOwn',
            ],
        ]]);
    }

    public function heartbeat(): void
    {
        $this->send('PUT', "/apps/{$this->app}/{$this->instanceId()}");
    }

    public function deregister(): void
    {
        $this->send('DELETE', "/apps/{$this->app}/{$this->instanceId()}");
    }

    /** Adresse d'une instance UP du service demandé, par ex. http://192.168.1.20:8103. */
    public function resolve(string $app): string
    {
        $data = $this->send('GET', '/apps/' . strtoupper($app));
        $instances = $data['application']['instance'] ?? [];
        if (isset($instances['instanceId'])) {
            $instances = [$instances]; // une seule instance renvoyée comme objet
        }
        $up = array_values(array_filter($instances, fn ($i) => ($i['status'] ?? '') === 'UP'));
        if ($up === []) {
            throw new EurekaUnavailable("Aucune instance UP pour $app");
        }
        $i = $up[array_rand($up)];
        $host = $i['hostName'] ?? $i['ipAddr'];
        return "http://{$host}:{$i['port']['$']}";
    }

    private function send(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(rtrim($this->eurekaUrl, '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code === 0) {
            throw new EurekaUnavailable("Eureka injoignable ($method $path)");
        }
        if ($code === 404 && $method === 'GET') {
            throw new EurekaUnavailable("Service inconnu d'Eureka ($path)");
        }
        if ($code >= 400) {
            throw new EurekaUnavailable("Eureka a répondu $code ($method $path)", $code);
        }
        return $res === '' ? [] : (json_decode($res, true) ?? []);
    }
}
