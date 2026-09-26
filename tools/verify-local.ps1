[CmdletBinding()]
param(
    [string]$ComposeProject = "fregat-feo",
    [string]$BaseUrl = "http://127.0.0.1:8080/fregat/feo"
)

$ErrorActionPreference = "Stop"
$repoRoot = (git rev-parse --show-toplevel).Trim()
if (-not $repoRoot) {
    throw "Run this command inside a LEGACY FREGAT FEO Git worktree."
}

Write-Host "Git worktree: $repoRoot"
git status --short --branch

$composeJson = docker compose -p $ComposeProject ps --format json
if ($LASTEXITCODE -ne 0 -or -not $composeJson) {
    throw "Compose project '$ComposeProject' is not running or is unavailable."
}

$containers = @($composeJson | ForEach-Object { $_ | ConvertFrom-Json })
$unexpectedOwners = @($containers | Where-Object { $_.Project -ne $ComposeProject })
if ($unexpectedOwners.Count -gt 0) {
    throw "A container has an unexpected Compose project name."
}

$requiredServices = @("web", "db")
foreach ($service in $requiredServices) {
    $container = $containers | Where-Object { $_.Service -eq $service }
    if (-not $container -or $container.State -ne "running") {
        throw "Service '$service' is not running in project '$ComposeProject'."
    }
}
Write-Host "Compose owner: $ComposeProject; services: web, db"

$httpResult = curl.exe --noproxy "*" -sS -o NUL -w "%{http_code} %{size_download}" "$BaseUrl/maptest.php"
if ($LASTEXITCODE -ne 0) {
    throw "The maptest.php HTTP check failed."
}
$httpParts = $httpResult -split " "
if ($httpParts[0] -ne "200" -or [int64]$httpParts[1] -le 0) {
    throw "maptest.php returned an unexpected result: $httpResult"
}
Write-Host "HTTP maptest.php: $httpResult"

$phpFiles = @(git ls-files "*.php")
$phpCount = 0
foreach ($phpFile in $phpFiles) {
    $containerPath = "/var/www/html/fregat/feo/$($phpFile.Replace('\', '/'))"
    $phpOutput = docker compose -p $ComposeProject exec -T web php -l $containerPath
    if ($LASTEXITCODE -ne 0) {
        $phpOutput
        throw "PHP lint failed: $phpFile"
    }
    $phpCount++
}
Write-Host "PHP lint: $phpCount files passed"

$jsFiles = @(git ls-files "*.js")
if ($jsFiles.Count -gt 0) {
    $nodeCommand = Get-Command node -ErrorAction SilentlyContinue
    if ($nodeCommand) {
        foreach ($jsFile in $jsFiles) {
            & $nodeCommand.Source --check $jsFile
            if ($LASTEXITCODE -ne 0) {
                throw "JavaScript lint failed: $jsFile"
            }
        }
        Write-Host "JavaScript lint: $($jsFiles.Count) files passed"
    }
    else {
        Write-Warning "Node.js is unavailable; verify JavaScript in the browser console."
    }
}

$dbUser = (docker compose -p $ComposeProject exec -T db printenv MYSQL_USER).Trim()
$dbPassword = (docker compose -p $ComposeProject exec -T db printenv MYSQL_PASSWORD).Trim()
$dbName = (docker compose -p $ComposeProject exec -T db printenv MYSQL_DATABASE).Trim()
$dbQuery = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE(); SELECT COUNT(*) FROM feo; SELECT @@character_set_database, @@collation_database;"
$dbResult = docker compose -p $ComposeProject exec -T db mysql -N "-u$dbUser" "-p$dbPassword" $dbName -e $dbQuery
if ($LASTEXITCODE -ne 0) {
    throw "The read-only SQL check failed."
}
Write-Host "Database proof:"
$dbResult | ForEach-Object { Write-Host "  $_" }

Write-Host "PASS: the local FREGAT workspace passed verification."
