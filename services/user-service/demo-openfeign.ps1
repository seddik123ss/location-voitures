# Demo du workshop OpenFeign (5 minutes) - user-service -> reservation-service
#
# 1) client1 demande son historique : user-service appelle reservation-service avec OpenFeign -> 200, 2 reservations
# 2) on arrete reservation-service -> 503 propre (FeignErrorHandler)
# 3) on le relance -> 200 de nouveau
#
# Prerequis : infra lancee, user-service lance avec RESERVATIONS_MOCK=false, reservation-service lance et UP dans Eureka.
#
# Utilisation (depuis services\user-service) :
#   .\demo-openfeign.ps1
#       -> service d'Aziz lance a la main dans le conteneur "lv-reservation"
#   .\demo-openfeign.ps1 -AzizContainers location-voitures-reservation-service-1
#       -> service d'Aziz lance par docker compose --profile services
#   Ajouter -Auto pour enchainer sans appuyer sur Entree.
#
# Si Windows bloque le script : Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass

param(
    [string]$Gateway = 'http://localhost:8088',
    [string]$Keycloak = 'http://localhost:8180/realms/location-voitures',
    [string[]]$AzizContainers = @('lv-reservation'),
    [switch]$Auto
)

$client1 = 'f4d1bab3-468d-428d-b02c-b44341430bfd'
$invariant = [Globalization.CultureInfo]::InvariantCulture

function Step([string]$text) {
    Write-Host ''
    Write-Host "=== $text" -ForegroundColor Cyan
}

function Pause-Demo([string]$text) {
    if ($Auto) { return }
    Write-Host ''
    Read-Host "$text (Entree pour continuer)" | Out-Null
}

function Get-Client1Token {
    $body = @{ grant_type = 'password'; client_id = 'rental-front'; username = 'client1'; password = 'client1_pwd' }
    (Invoke-RestMethod -Method Post -Uri "$Keycloak/protocol/openid-connect/token" -Body $body).access_token
}

function Show-History([string]$token) {
    $url = "$Gateway/api/users/$client1/reservations"
    Write-Host "GET $url" -ForegroundColor DarkGray
    try {
        $r = Invoke-WebRequest -UseBasicParsing -Uri $url -Headers @{ Authorization = "Bearer $token"; 'X-Correlation-Id' = 'demo-openfeign' }
        Write-Host "HTTP $($r.StatusCode)" -ForegroundColor Green
        $page = $r.Content | ConvertFrom-Json
        Write-Host "total = $($page.total)"
        foreach ($res in $page.items) {
            $price = ([decimal]$res.totalPrice).ToString('0.000', $invariant)
            Write-Host ("  {0}  {1} -> {2}  {3,-10} {4} {5}" -f $res.id.Substring(0, 8), $res.startDate, $res.endDate, $res.status, $price, $res.currency)
        }
    } catch {
        $response = $_.Exception.Response
        if ($null -eq $response) { throw }
        Write-Host "HTTP $([int]$response.StatusCode)" -ForegroundColor Yellow
        $text = $_.ErrorDetails.Message
        if ([string]::IsNullOrEmpty($text)) {
            try {
                $stream = $response.GetResponseStream()
                $stream.Position = 0
                $text = (New-Object IO.StreamReader($stream, [Text.Encoding]::UTF8)).ReadToEnd()
            } catch { $text = '' }
        }
        try {
            $err = $text | ConvertFrom-Json
            Write-Host "status  = $($err.status)"
            Write-Host "error   = $($err.error)"
            Write-Host "message = $($err.message)"
        } catch { Write-Host $text }
    }
}

function Wait-Url([string]$url, [int]$seconds) {
    for ($i = 0; $i -lt $seconds; $i++) {
        try { Invoke-WebRequest -UseBasicParsing -Uri $url -TimeoutSec 2 | Out-Null; return $true } catch { Start-Sleep -Seconds 1 }
    }
    return $false
}

Step '0. Verification avant la demo'
$userOk = Wait-Url 'http://localhost:8101/health' 5
$azizOk = Wait-Url 'http://localhost:8103/health' 5
Write-Host ("user-service        (8101) : " + $(if ($userOk) { 'UP' } else { 'ARRETE' }))
Write-Host ("reservation-service (8103) : " + $(if ($azizOk) { 'UP' } else { 'ARRETE' }))
$apps = Invoke-RestMethod -Uri 'http://localhost:8761/eureka/apps' -Headers @{ Accept = 'application/json' }
$names = @($apps.applications.application | ForEach-Object { $_.name })
Write-Host ("Eureka : " + ($names -join ', '))
if (-not ($userOk -and $azizOk) -or ($names -notcontains 'RENTAL-RESERVATION')) {
    Write-Host 'Un service manque : lance-le avant la demo.' -ForegroundColor Red
    exit 1
}
$token = Get-Client1Token
Write-Host 'Token de client1 obtenu (Keycloak).'

Pause-Demo 'Etape 1 : historique de client1'
Step '1. client1 -> user-service -> OpenFeign -> reservation-service'
Show-History $token

Pause-Demo "Etape 2 : on arrete le service d'Aziz"
Step "2. Service d'Aziz arrete -> 503 attendu"
docker stop -t 5 $AzizContainers | Out-Null
Write-Host ("docker stop " + ($AzizContainers -join ' '))
Show-History $token

Pause-Demo 'Etape 3 : on le relance'
Step "3. Service d'Aziz relance -> 200 attendu"
docker start $AzizContainers | Out-Null
Write-Host ("docker start " + ($AzizContainers -join ' '))
if (-not (Wait-Url 'http://localhost:8103/health' 60)) { Write-Host 'reservation-service ne repond pas encore.' -ForegroundColor Red }
Show-History $token

Write-Host ''
Write-Host 'Fin de la demo.' -ForegroundColor Cyan
