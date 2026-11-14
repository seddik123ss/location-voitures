# start-all.ps1 - lance toute la demo (infra + 8 services + front), une fenetre PowerShell par processus.
# PowerShell 5.1. Guide complet : docs/integration-locale.md
#
# Usage (racine du depot) :  .\scripts\start-all.ps1 [-PhpHome <dossier>] [-JavaHome <dossier>] [-SkipInfra]
#   PHP 8.4 et JDK 21 : parametres, sinon variables PHP84_HOME / JDK21_HOME, sinon PATH.
# Un service qui repond deja sur /health n'est pas relance. Arret : .\scripts\stop-all.ps1
param([string] $PhpHome, [string] $JavaHome, [switch] $SkipInfra)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "integration-tools.ps1")
$Repository = Split-Path -Parent $PSScriptRoot
$PidFile = Join-Path $env:TEMP "location-voitures-integration-pids.csv"   # hors du depot : jamais commite

function Test-Url([string] $Url, [int] $TimeoutSeconds = 15) {
    try { return (Invoke-WebRequest $Url -UseBasicParsing -TimeoutSec $TimeoutSeconds).StatusCode -eq 200 } catch { return $false }
}

function Wait-Url([string] $Label, [string] $Url, [int] $MaxSeconds = 240) {
    $deadline = (Get-Date).AddSeconds($MaxSeconds)
    Write-Host ("  attente de {0} ..." -f $Label) -NoNewline
    while ((Get-Date) -lt $deadline) {
        if (Test-Url $Url) { Write-Host " OK" -ForegroundColor Green; return $true }
        Start-Sleep -Seconds 3
    }
    Write-Host " PAS DE REPONSE (voir sa fenetre)" -ForegroundColor Red
    return $false
}

# Fenetre PowerShell : titre, PHP / JDK si demande, note son PID (avec nouvel essai si le fichier est verrouille).
function Open-Window([string] $Title, [string] $Directory, [string] $Command, [switch] $Php, [switch] $Jdk) {
    $script = "`$Host.UI.RawUI.WindowTitle = '$Title'`n"
    $script += "for (`$try = 0; `$try -lt 20; `$try++) { try { Add-Content -Path '$PidFile' -Value ('$Title,' + `$PID) -ErrorAction Stop; break } catch { Start-Sleep -Milliseconds (Get-Random -Minimum 50 -Maximum 300) } }`n"
    $script += Get-ToolPrefix $(if ($Php) { $script:PhpDirectory } else { "" }) $(if ($Jdk) { $script:JavaDirectory } else { "" })
    $script += "Set-Location '$(Join-Path $Repository $Directory)'`n"
    $script += "Write-Host '=== $Title ===' -ForegroundColor Cyan`n"
    $script += $Command
    $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($script))
    Start-Process powershell.exe -ArgumentList "-NoExit", "-NoProfile", "-EncodedCommand", $encoded | Out-Null
    Write-Host ("  fenetre ouverte : {0}" -f $Title)
}

function Start-ServiceGroup([string] $Name, [int] $Port, [scriptblock] $OpenWindows) {
    $health = "http://localhost:$Port/health"
    Write-Host ("[{0}] port {1}" -f $Name, $Port) -ForegroundColor Yellow
    if (Test-Url $health 5) { Write-Host "  deja lance : je ne le relance pas" -ForegroundColor DarkGray; return }
    & $OpenWindows
    Wait-Url "$Name /health" $health | Out-Null
}

# ---------- 0. Verifications de la machine ----------
Write-Host "[verifications]" -ForegroundColor Yellow
$script:PhpDirectory = Resolve-PhpHome $PhpHome
$script:JavaDirectory = Resolve-JavaHome $JavaHome
docker info *> $null
if ($LASTEXITCODE -ne 0) { Stop-WithError "Docker Desktop n'est pas lance." }
try { Resolve-DnsName host.docker.internal -ErrorAction Stop | Out-Null }
catch { Stop-WithError "Windows ne resout pas host.docker.internal. En administrateur : Add-Content C:\Windows\System32\drivers\etc\hosts ""``r``n127.0.0.1 host.docker.internal"" -Encoding ASCII" }
$mysqlOwner = Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue | ForEach-Object { (Get-Process -Id $_.OwningProcess).ProcessName } | Select-Object -First 1
if ($mysqlOwner -and $mysqlOwner -notin "com.docker.backend", "wslrelay", "vpnkit", "docker-proxy") {
    Stop-WithError "Le port 3306 est pris par '$mysqlOwner' (MySQL local, XAMPP ?). Arretez-le (ex. en administrateur : Stop-Service mysql)."
}
$envFiles = "services\user-service\.env", "services\vehicle-service\.env", "services\reservation-service\.env", "services\payment-service\.env",
            "services\contract-service\.env", "services\complaint-service\.env.local", "services\ml-service\.env", "services\loyalty-service\.env"
$missingEnv = $envFiles | Where-Object { -not (Test-Path (Join-Path $Repository $_)) }
if ($missingEnv) { Stop-WithError ("Fichiers de configuration manquants : {0} (a creer depuis .env.example, voir le guide)." -f ($missingEnv -join ", ")) }

# ---------- 1. Infrastructure (MySQL, Postgres, RabbitMQ, Keycloak, Eureka, Gateway, Config Server) ----------
if (-not $SkipInfra) {
    Write-Host "[infra] docker compose up -d" -ForegroundColor Yellow
    # docker compose ecrit sa progression sur stderr : via cmd (2>&1), PowerShell 5.1 n'y voit pas une erreur.
    Push-Location (Join-Path $Repository "infra"); cmd /c "docker compose up -d 2>&1"; $composeExit = $LASTEXITCODE; Pop-Location
    if ($composeExit -ne 0) { Stop-WithError "docker compose up -d a echoue (code $composeExit) : voir les messages ci-dessus." }
}
Wait-Url "Keycloak (realm)" "http://localhost:8180/realms/location-voitures" 180 | Out-Null
Wait-Url "Eureka" "http://localhost:8761/" 120 | Out-Null
# Les services lisent leur configuration non secrete sur le Config Server au demarrage (infra/config-repo)
Wait-Url "Config Server" "http://localhost:8888/actuator/health" 120 | Out-Null

# ---------- 2. Fournisseurs : user, vehicle, ml ----------
Start-ServiceGroup "user-service" 8101 { Open-Window "user-service :8101" "services\user-service" ".\mvnw.cmd spring-boot:run" -Jdk }
Start-ServiceGroup "vehicle-service" 8102 {
    Open-Window "vehicle-service :8102" "services\vehicle-service" "npm run dev"
    Open-Window "vehicle-consumer" "services\vehicle-service" "npm run consumer"
}
Start-ServiceGroup "ml-service" 8107 { Open-Window "ml-service :8107" "services\ml-service" ".\.venv\Scripts\python.exe -m uvicorn main:app --host 0.0.0.0 --port 8107" }

# ---------- 3. reservation (serveur, Eureka, consommateur) ----------
Start-ServiceGroup "reservation-service" 8103 {
    Open-Window "reservation-service :8103" "services\reservation-service" "composer serve" -Php
    Open-Window "reservation-eureka" "services\reservation-service" "composer eureka" -Php
    Open-Window "reservation-consumer" "services\reservation-service" "composer consume" -Php
}

# ---------- 4. payment (serveur + consommateur) ----------
Start-ServiceGroup "payment-service" 8104 {
    Open-Window "payment-service :8104" "services\payment-service" ".\venv\Scripts\python.exe manage.py runserver 0.0.0.0:8104"
    Open-Window "payment-consumer" "services\payment-service" ".\venv\Scripts\python.exe manage.py consume_events"
}

# ---------- 5. contract ----------
Start-ServiceGroup "contract-service" 8105 { Open-Window "contract-service :8105" "services\contract-service\src\ContractService" "dotnet run" }

# ---------- 6. complaint (serveur + Eureka) ----------
# composer serve demande la Symfony CLI ; sans elle, serveur integre de PHP (composer serve:php, meme port).
$complaintServe = if (Get-Command symfony -ErrorAction SilentlyContinue) { "composer serve" } else { "composer serve:php" }
Start-ServiceGroup "complaint-service" 8106 {
    Open-Window "complaint-service :8106" "services\complaint-service" $complaintServe -Php
    Open-Window "complaint-eureka" "services\complaint-service" "composer eureka" -Php
}

# ---------- 6 bis. loyalty (API + consommateur RabbitMQ dans le meme processus) ----------
Start-ServiceGroup "loyalty-service" 8108 { Open-Window "loyalty-service :8108" "services\loyalty-service" ".\mvnw.cmd spring-boot:run" -Jdk }

# ---------- 7. Frontend ----------
Write-Host "[frontend] port 4200" -ForegroundColor Yellow
if (Test-Url "http://localhost:4200/" 5) { Write-Host "  deja lance" -ForegroundColor DarkGray }
else { Open-Window "frontend :4200" "frontend" "npm start"; Wait-Url "frontend" "http://localhost:4200/" 240 | Out-Null }

# ---------- 8. Echauffement (1res requetes lentes : compilation Next.js, cache Symfony) ----------
Write-Host "`n[echauffement]" -ForegroundColor Yellow
foreach ($url in "http://localhost:8102/api/vehicles", "http://localhost:8106/health") {
    $watch = [Diagnostics.Stopwatch]::StartNew()
    $ok = Test-Url $url 90
    Write-Host ("  {0} -> {1} en {2:N1} s" -f $url, $(if ($ok) { "OK" } else { "KO" }), $watch.Elapsed.TotalSeconds)
}

# ---------- 9. Controle ----------
Write-Host "`n[controle] Eureka (attendu : 8 services + API-GATEWAY + CONFIG-SERVER, UP)" -ForegroundColor Yellow
Start-Sleep -Seconds 5
$apps = (Invoke-RestMethod "http://localhost:8761/eureka/apps" -Headers @{ Accept = "application/json" }).applications.application
foreach ($app in $apps | Sort-Object name) { Write-Host ("  {0,-20} {1}" -f $app.name, (($app.instance | ForEach-Object { $_.status }) -join ",")) }
Write-Host "`n[controle] RabbitMQ (attendu : 6 files + leurs .dlq, 1 consommateur par file)" -ForegroundColor Yellow
$queues = curl.exe -s -u guest:guest "http://localhost:15672/api/queues" | ConvertFrom-Json
foreach ($queue in $queues | Sort-Object name) { Write-Host ("  {0,-36} consommateurs={1} messages={2}" -f $queue.name, $queue.consumers, $queue.messages) }
Write-Host "`nDemo prete : http://localhost:4200 (admin1 / admin1_pwd). Arret : .\scripts\stop-all.ps1" -ForegroundColor Green
