# reset-demo.ps1 - remet les donnees de demo de chaque service a zero (docs/demo-ids.md). PowerShell 5.1.
#
# A lancer services ARRETES (.\scripts\stop-all.ps1), infrastructure Docker LANCEE.
# Usage :  .\scripts\reset-demo.ps1 [-PhpHome <dossier>] [-Force]
#   -Force : ne verifie pas que les services sont arretes
param([string] $PhpHome, [switch] $Force)

. (Join-Path $PSScriptRoot "integration-tools.ps1")
$Repository = Split-Path -Parent $PSScriptRoot
$DemoMlReservation = "30eeff32-a413-42fb-9845-8ede4b7f98e0"   # r-completed (docs/demo-ids.md)
$failures = @()

function Step([string] $Title) { Write-Host "`n[$Title]" -ForegroundColor Yellow }
function Check-Exit([string] $Name) {
    if ($LASTEXITCODE -ne 0) { Write-Host "  ECHEC : $Name (code $LASTEXITCODE)" -ForegroundColor Red; $script:failures += $Name }
    else { Write-Host "  OK : $Name" -ForegroundColor Green }
}
function Postgres([string] $Database, [string] $Sql) {
    Push-Location (Join-Path $Repository "infra")
    docker compose exec -T postgres psql -U postgres -d $Database -v ON_ERROR_STOP=1 -tAc $Sql
    Pop-Location
}
function Mysql([string] $Sql) {
    # Requete par l'entree standard : pas de guillemets imbriques (mal transmis par PowerShell 5.1)
    Push-Location (Join-Path $Repository "infra")
    $Sql | docker compose exec -T mysql sh -c 'mysql -uroot -p$MYSQL_ROOT_PASSWORD -N 2>/dev/null'
    Pop-Location
}

# ---------- 0. Verifications ----------
if (-not $Force) {
    $running = @()
    foreach ($port in 8101..8107) {
        try { if ((Invoke-WebRequest "http://localhost:$port/health" -UseBasicParsing -TimeoutSec 3).StatusCode -eq 200) { $running += $port } } catch {}
    }
    if ($running.Count -gt 0) { Stop-WithError ("services encore lances (ports {0}) : lancez d'abord .\scripts\stop-all.ps1" -f ($running -join ", ")) }
}
Push-Location (Join-Path $Repository "infra"); $containers = docker compose ps --services --status running; Pop-Location
foreach ($needed in "mysql", "postgres", "rabbitmq") {
    if ($containers -notcontains $needed) { Stop-WithError "le conteneur $needed ne tourne pas : cd infra ; docker compose up -d" }
}
$phpDirectory = Resolve-PhpHome $PhpHome
if ($phpDirectory) { $env:Path = "$phpDirectory;" + $env:Path }

Step "vehicle-service"     # upsert : agences, categories, 6 vehicules (km et statut)
Push-Location (Join-Path $Repository "services\vehicle-service"); npm run seed *> $null; Check-Exit "vehicle seed"; Pop-Location

Step "reservation-service" # tables recreees + 3 reservations de demo
Push-Location (Join-Path $Repository "services\reservation-service"); php artisan migrate:fresh --seed --force *> $null; Check-Exit "reservation migrate:fresh --seed"; Pop-Location

Step "complaint-service"   # base videe puis fixtures
Push-Location (Join-Path $Repository "services\complaint-service"); php bin/console doctrine:fixtures:load --no-interaction *> $null; Check-Exit "complaint fixtures:load"; Pop-Location

Step "payment-service"     # tous les paiements effaces, 4 paiements de demo
Push-Location (Join-Path $Repository "services\payment-service"); .\venv\Scripts\python.exe manage.py seed_demo --reset *> $null; Check-Exit "payment seed_demo --reset"; Pop-Location

Step "contract-service"    # base recreee ; le contrat de demo revient au demarrage (migration)
Postgres "postgres" "DROP DATABASE IF EXISTS contract_db WITH (FORCE);" | Out-Null; Check-Exit "contract_db supprimee"
Postgres "postgres" "CREATE DATABASE contract_db OWNER contract_svc;" | Out-Null; Check-Exit "contract_db recreee (vide jusqu'au demarrage)"

Step "ml-service"          # modele et ligne de demo gardes, donnees de scenario retirees
Postgres "ml_db" "DELETE FROM completed_reservations WHERE reservation_id <> '$DemoMlReservation'; DELETE FROM prediction_logs;" | Out-Null; Check-Exit "ml_db nettoyee"

Step "RabbitMQ"            # files videes (pas supprimees) ; curl.exe car PowerShell 5.1 abime le %2F
$queues = curl.exe -s -u guest:guest "http://localhost:15672/api/queues" | ConvertFrom-Json
foreach ($queue in $queues) {
    if ($queue.messages -gt 0) {
        curl.exe -s -u guest:guest -X DELETE "http://localhost:15672/api/queues/%2F/$($queue.name)/contents" | Out-Null
        Write-Host ("  videe : {0} ({1} message(s))" -f $queue.name, $queue.messages)
    }
}
Write-Host "  OK : files vides" -ForegroundColor Green

Step "controle des donnees de demo"
Write-Host ("  vehicle_db      vehicules={0}  (attendu 6, dont 5 AVAILABLE : {1})" -f (Mysql "SELECT count(*) FROM vehicle_db.vehicles"), (Mysql "SELECT count(*) FROM vehicle_db.vehicles WHERE status='AVAILABLE'"))
Write-Host ("  reservation_db  reservations={0}  (attendu 3)" -f (Mysql "SELECT count(*) FROM reservation_db.reservations"))
Write-Host ("  complaint_db    reclamations={0}" -f (Mysql "SELECT count(*) FROM complaint_db.complaint"))
Write-Host ("  payment_db      paiements={0}  (attendu 4)" -f (Postgres "payment_db" "SELECT count(*) FROM payments_payment"))
Write-Host ("  contract_db     tables={0}  (attendu 0 : migration au demarrage)" -f (Postgres "contract_db" "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'"))
Write-Host ("  ml_db           lignes d'entrainement={0} (attendu 1), versions={1}" -f (Postgres "ml_db" "SELECT count(*) FROM completed_reservations"), (Postgres "ml_db" "SELECT count(*) FROM model_versions"))

if ($failures.Count -gt 0) { Stop-WithError "echecs : $($failures -join ', ')" }
Write-Host "`nDonnees de demo remises a zero. Lancez : .\scripts\start-all.ps1" -ForegroundColor Green
