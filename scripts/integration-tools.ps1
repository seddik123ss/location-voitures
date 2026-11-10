# integration-tools.ps1 - fonctions communes a start-all.ps1, stop-all.ps1 et reset-demo.ps1 (PowerShell 5.1).
# Trouve PHP 8.4 et un JDK 17-23 (21 conseille) : parametre, puis variable d'environnement, puis PATH.
#   PHP84_HOME  = dossier qui contient php.exe (PHP 8.4+, extensions activees dans son php.ini)
#   JDK21_HOME  = dossier du JDK (celui qui contient bin\java.exe)

$script:RequiredPhpExtensions = "pdo_mysql", "curl", "mbstring", "openssl", "sockets", "intl", "fileinfo", "zip"

function Stop-WithError([string] $Message) {
    Write-Host "ERREUR : $Message" -ForegroundColor Red
    Write-Host "Voir docs/integration-locale.md" -ForegroundColor Red
    exit 1
}

# Renvoie le dossier de PHP a mettre en tete du PATH ("" si le php du PATH convient).
function Resolve-PhpHome([string] $PhpHome) {
    if (-not $PhpHome) { $PhpHome = $env:PHP84_HOME }
    if ($PhpHome) {
        $php = Join-Path $PhpHome "php.exe"
        if (-not (Test-Path $php)) { Stop-WithError "php.exe introuvable dans '$PhpHome' (parametre -PhpHome ou variable PHP84_HOME)." }
    } else {
        $command = Get-Command php -ErrorAction SilentlyContinue
        if (-not $command) { Stop-WithError "PHP introuvable. Installez PHP 8.4 (winget install --id PHP.PHP.8.4 --exact) puis definissez PHP84_HOME." }
        $php = $command.Source
    }
    $version = (& $php -r "echo PHP_VERSION;" 2>$null)
    if (-not $version -or [version]($version -replace '[^0-9.].*$', '') -lt [version]"8.4.0") {
        Stop-WithError "PHP $version trouve ($php) : il faut PHP 8.4 ou plus (les composer.lock l'exigent). Definissez PHP84_HOME."
    }
    $loaded = (& $php -m 2>$null) | ForEach-Object { $_.Trim().ToLower() }
    $missing = $script:RequiredPhpExtensions | Where-Object { $loaded -notcontains $_ }
    if ($missing) { Stop-WithError ("Extensions PHP manquantes dans {0} : {1}. Activez-les dans php.ini (extension=...)." -f (& $php --ini | Select-String "Loaded Configuration").ToString().Trim(), ($missing -join ", ")) }
    Write-Host "  PHP $version : $php" -ForegroundColor DarkGray
    if ($PhpHome) { return $PhpHome } else { return "" }
}

# Renvoie le dossier du JDK a utiliser comme JAVA_HOME.
function Resolve-JavaHome([string] $JavaHome) {
    if (-not $JavaHome) { $JavaHome = $env:JDK21_HOME }
    if (-not $JavaHome) { $JavaHome = $env:JAVA_HOME }
    if ($JavaHome) {
        $java = Join-Path $JavaHome "bin\java.exe"
        if (-not (Test-Path $java)) { Stop-WithError "java.exe introuvable dans '$JavaHome\bin' (parametre -JavaHome, JDK21_HOME ou JAVA_HOME)." }
    } else {
        $command = Get-Command java -ErrorAction SilentlyContinue
        if (-not $command) { Stop-WithError "Java introuvable. Installez un JDK 21 (winget install --id EclipseAdoptium.Temurin.21.JDK --exact) puis definissez JDK21_HOME." }
        $java = $command.Source
        $JavaHome = Split-Path (Split-Path $java)
    }
    $versionLine = (cmd /c "`"$java`" -version 2>&1" | Select-Object -First 1)
    $major = if ($versionLine -match 'version "(\d+)') { [int]$Matches[1] } else { 0 }
    if ($major -lt 17 -or $major -gt 23) {
        Stop-WithError "Java $major trouve ($java) : user-service (Spring Boot 3.3) demande Java 17 a 23 (21 conseille). Definissez JDK21_HOME."
    }
    Write-Host "  Java $major : $JavaHome" -ForegroundColor DarkGray
    return $JavaHome
}

# Lignes PowerShell a executer au debut d'une fenetre pour utiliser ce PHP / ce JDK.
function Get-ToolPrefix([string] $PhpDirectory, [string] $JavaDirectory) {
    $prefix = ""
    if ($PhpDirectory) { $prefix += "`$env:Path = '$PhpDirectory;' + `$env:Path`n" }
    if ($JavaDirectory) { $prefix += "`$env:JAVA_HOME = '$JavaDirectory'; `$env:Path = '$JavaDirectory\bin;' + `$env:Path`n" }
    return $prefix
}
