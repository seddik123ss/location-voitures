# stop-all.ps1 - arrete les fenetres ouvertes par start-all.ps1, puis nettoie Eureka. PowerShell 5.1.
#
# Usage :  .\scripts\stop-all.ps1 [-PhpHome <dossier>] [-Infra]
#   -Infra : arrete aussi les conteneurs (docker compose stop, les donnees sont gardees)
# Un service lance hors de start-all n'est pas touche.
param([string] $PhpHome, [switch] $Infra)

. (Join-Path $PSScriptRoot "integration-tools.ps1")
$Repository = Split-Path -Parent $PSScriptRoot
$PidFile = Join-Path $env:TEMP "location-voitures-integration-pids.csv"   # hors du depot : jamais commite

# 1. Retrait propre des runners Eureka PHP (equivalent du Ctrl+C prevu par leurs README)
Write-Host "[eureka] retrait des instances PHP" -ForegroundColor Yellow
$phpDirectory = Resolve-PhpHome $PhpHome
if ($phpDirectory) { $env:Path = "$phpDirectory;" + $env:Path }
Push-Location (Join-Path $Repository "services\reservation-service"); php artisan eureka:deregister 2>&1 | Out-Null; Pop-Location
Push-Location (Join-Path $Repository "services\complaint-service"); php bin/console app:eureka:deregister 2>&1 | Out-Null
if (Get-Command symfony -ErrorAction SilentlyContinue) { symfony server:stop 2>&1 | Out-Null }
Pop-Location

# 2. Fermeture des fenetres et de leurs processus enfants (java, php, node, python, dotnet)
if (Test-Path $PidFile) {
    foreach ($line in Get-Content $PidFile) {
        $title, $processId = $line -split ",", 2
        if (Get-Process -Id $processId -ErrorAction SilentlyContinue) {
            taskkill /PID $processId /T /F 2>&1 | Out-Null
            Write-Host ("  arrete : {0} (PID {1})" -f $title, $processId)
        }
    }
    Remove-Item $PidFile
} else {
    Write-Host "Aucun fichier $PidFile : aucune fenetre de start-all a fermer." -ForegroundColor DarkGray
}

# 3. Eureka : retire les instances des services arretes (evite les 500/503 de la Gateway)
Start-Sleep -Seconds 2
Write-Host "[eureka] nettoyage des instances arretees" -ForegroundColor Yellow
$ports = @{ "RENTAL-USER" = 8101; "RENTAL-VEHICLE" = 8102; "RENTAL-RESERVATION" = 8103; "RENTAL-PAYMENT" = 8104;
            "RENTAL-CONTRACT" = 8105; "RENTAL-COMPLAINT" = 8106; "RENTAL-ML" = 8107 }
try { $apps = (Invoke-RestMethod "http://localhost:8761/eureka/apps" -Headers @{ Accept = "application/json" }).applications.application } catch { $apps = @() }
foreach ($app in $apps) {
    if (-not $ports.ContainsKey($app.name)) { continue }
    $stillRunning = $false
    try { $stillRunning = (Invoke-WebRequest "http://localhost:$($ports[$app.name])/health" -UseBasicParsing -TimeoutSec 3).StatusCode -eq 200 } catch {}
    if ($stillRunning) { Write-Host ("  {0} tourne encore (lance hors start-all) : garde" -f $app.name) -ForegroundColor DarkGray; continue }
    foreach ($instance in $app.instance) {
        try { Invoke-RestMethod -Method Delete "http://localhost:8761/eureka/apps/$($app.name)/$($instance.instanceId)" | Out-Null
              Write-Host ("  retire : {0} ({1})" -f $app.name, $instance.instanceId) } catch {}
    }
}

# 4. Infrastructure (optionnel) : stop garde les volumes (bases, Keycloak)
if ($Infra) {
    Write-Host "[infra] docker compose stop" -ForegroundColor Yellow
    Push-Location (Join-Path $Repository "infra"); docker compose stop; Pop-Location
}
Write-Host "Termine." -ForegroundColor Green
