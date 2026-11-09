# Verifie les routes /internal avec un vrai token Keycloak (client credentials du client reservation-service).
# Prerequis : infra lancee (docker compose up -d), service lance (composer serve), base migree et seedee.
# Usage, depuis services/reservation-service :  powershell -ExecutionPolicy Bypass -File scripts\test-internal.ps1
# Le secret est lu dans infra/keycloak/location-voitures-realm.json a l'execution : il n'est jamais ecrit nulle part.

param(
    [string]$BaseUrl = "http://localhost:8103",
    [string]$TokenUrl = "http://localhost:8180/realms/location-voitures/protocol/openid-connect/token"
)

$ErrorActionPreference = "Stop"
$realmFile = Join-Path $PSScriptRoot "..\..\..\infra\keycloak\location-voitures-realm.json"
$realm = [IO.File]::ReadAllText((Resolve-Path $realmFile)) | ConvertFrom-Json

function Get-ServiceToken([string]$clientId) {
    $secret = ($realm.clients | Where-Object { $_.clientId -eq $clientId }).secret
    $body = @{ grant_type = "client_credentials"; client_id = $clientId; client_secret = $secret }
    (Invoke-RestMethod -Method Post -Uri $TokenUrl -Body $body -ContentType "application/x-www-form-urlencoded").access_token
}

function Show([string]$title, [string]$uri, [string]$token) {
    Write-Host "--- $title" -ForegroundColor Cyan
    if ($token) { curl.exe -s -w "  [HTTP %{http_code}]`n" -H "Authorization: Bearer $token" $uri }
    else { curl.exe -s -w "  [HTTP %{http_code}]`n" $uri }
}

function Get-Claims([string]$jwt) {
    $part = $jwt.Split('.')[1].Replace('-', '+').Replace('_', '/')
    while ($part.Length % 4 -ne 0) { $part += '=' }
    [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($part)) | ConvertFrom-Json
}

$internal = Get-ServiceToken "reservation-service"
$claims = Get-Claims $internal
Write-Host "Token reservation-service obtenu, roles : $($claims.roles -join ', ') ; iss : $($claims.iss)"

# Token d'utilisateur sans le role INTERNAL : client1 via le client public rental-front (mot de passe client1_pwd).
$client1 = $null
try {
    $client1 = (Invoke-RestMethod -Method Post -Uri $TokenUrl -ContentType "application/x-www-form-urlencoded" -Body @{
        grant_type = "password"; client_id = "rental-front"; username = "client1"; password = "client1_pwd"; scope = "openid"
    }).access_token
} catch {
    Write-Host "(token client1 impossible : le client rental-front n'autorise peut-etre pas le mot de passe direct)" -ForegroundColor Yellow
}

$r = "33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8"
Show "200 avec le token de service" "$BaseUrl/internal/reservations/$r" $internal
Show "401 sans token" "$BaseUrl/internal/reservations/$r" $null
if ($client1) { Show "403 avec un token CLIENT (sans INTERNAL)" "$BaseUrl/internal/reservations/$r" $client1 }
Show "404 reservation inconnue" "$BaseUrl/internal/reservations/00000000-0000-4000-8000-000000000000" $internal
Show "liste de client1" "$BaseUrl/internal/reservations?userId=f4d1bab3-468d-428d-b02c-b44341430bfd" $internal
Show "reservations actives de v1" "$BaseUrl/internal/reservations/active?vehicleId=ecdcc60f-ed6a-4007-8622-2b568338ef7a" $internal
