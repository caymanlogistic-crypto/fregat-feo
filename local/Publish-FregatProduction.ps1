[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9a-f]{40}$')]
    [string]$ExpectedSha,

    [Parameter(Mandatory = $true)]
    [switch]$OwnerApproved,

    [string]$SshAlias = 'fregat-map',

    [string]$ProductionUrl = 'http://spugovxsim.temp.swtest.ru/fregat/feo/maptest.php'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repositoryRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$targetRoot = '/home/s/spugovxsim/public_html/fregat/feo'
$stateDir = '.fregat-deploy-feo'
$remoteApply = Join-Path $repositoryRoot '.github\scripts\remote-apply.sh'

function Invoke-Native {
    param(
        [Parameter(Mandatory = $true)][string]$Command,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [switch]$Capture
    )

    if ($Capture) {
        $result = & $Command @Arguments 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "$Command failed with exit code $LASTEXITCODE.`n$($result -join "`n")"
        }
        return ($result -join "`n").Trim()
    }

    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Command failed with exit code $LASTEXITCODE."
    }
}

function Test-DeployablePath {
    param([Parameter(Mandatory = $true)][string]$Path)

    if ($Path -notmatch '^[A-Za-z0-9_.\-/]+$' -or $Path.Contains('..') -or $Path.StartsWith('/')) {
        throw "Unsafe repository path: $Path"
    }

    $fileName = [System.IO.Path]::GetFileName($Path)
    $extension = [System.IO.Path]::GetExtension($Path).ToLowerInvariant()

    if ($Path -in @('map8000_data/requests.xlsx', 'map8000_data/.htaccess')) {
        return $true
    }

    if ($Path -eq 'map2.php' -or
        $fileName -eq 'config.php' -or
        $fileName.EndsWith('.example.php') -or
        $extension -in @('.sql', '.log', '.gz', '.bak', '.backup', '.tmp', '.cache')) {
        return $false
    }

    if ($Path -match '^(\.github/|docs/|docs2/|docs3/|docker/|storage/|uploads/|vendor/)' -or
        $Path -match '(^|/)(venv|__pycache__)/' -or
        $Path -in @('.gitignore', 'AGENTS.md', 'CODEX_WORKFLOW_RULES.md', 'README.md', 'compose.yaml', 'Dockerfile', 'composer.json') -or
        $Path.StartsWith('.env')) {
        return $false
    }

    return $extension -in @('.php', '.js', '.css', '.json', '.html', '.svg', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.ico')
}

if (-not $OwnerApproved) {
    throw 'Local production publishing requires explicit OWNER approval.'
}

foreach ($tool in @('git', 'ssh', 'scp', 'php', 'curl.exe')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        throw "Required local tool is unavailable: $tool"
    }
}
if (-not (Test-Path -LiteralPath $remoteApply -PathType Leaf)) {
    throw "Remote apply script is missing: $remoteApply"
}

$branch = Invoke-Native git @('-C', $repositoryRoot, 'branch', '--show-current') -Capture
if ($branch -ne 'main') {
    throw "Local publisher may run only from main; current branch is $branch."
}
$dirty = Invoke-Native git @('-C', $repositoryRoot, 'status', '--porcelain') -Capture
if ($dirty) {
    throw 'Local publisher requires a clean working tree.'
}

Invoke-Native git @('-C', $repositoryRoot, 'fetch', 'origin', 'main')
$head = Invoke-Native git @('-C', $repositoryRoot, 'rev-parse', 'HEAD') -Capture
$originMain = Invoke-Native git @('-C', $repositoryRoot, 'rev-parse', 'origin/main') -Capture
if ($head -ne $originMain -or $head -ne $ExpectedSha) {
    throw "Exact-candidate mismatch. HEAD=$head origin/main=$originMain expected=$ExpectedSha"
}

$remoteState = Invoke-Native ssh @('-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $SshAlias, "test -f '$stateDir/current-sha' && cat '$stateDir/current-sha' || true") -Capture
if ($remoteState -notmatch '^[0-9a-f]{40}$') {
    throw "Remote deploy state is missing or invalid: $remoteState"
}
Invoke-Native git @('-C', $repositoryRoot, 'cat-file', '-e', "$remoteState^{commit}")
Invoke-Native git @('-C', $repositoryRoot, 'merge-base', '--is-ancestor', $remoteState, $head)

$addedOrModified = @(Invoke-Native git @('-C', $repositoryRoot, 'diff', '--name-only', '--diff-filter=AM', $remoteState, $head) -Capture -ErrorAction Stop) -split "`n"
$deletedOrRenamed = @(Invoke-Native git @('-C', $repositoryRoot, 'diff', '--name-only', '--diff-filter=DR', $remoteState, $head) -Capture -ErrorAction Stop) -split "`n"

$deployFiles = @($addedOrModified | Where-Object { $_ -and (Test-DeployablePath $_) } | Sort-Object -Unique)
$forbiddenDeletes = @($deletedOrRenamed | Where-Object { $_ -and (Test-DeployablePath $_) } | Sort-Object -Unique)
if ($forbiddenDeletes.Count -gt 0) {
    throw "Automatic deletion/rename of runtime files is forbidden:`n$($forbiddenDeletes -join "`n")"
}

# The first map8000 fallback publish may follow a skipped Actions run. In that
# case its source workbook and access rule are unchanged in Git but still absent
# on production, so include only those missing bootstrap files explicitly.
foreach ($relative in @('map8000_data/requests.xlsx', 'map8000_data/.htaccess')) {
    $source = Join-Path $repositoryRoot ($relative -replace '/', '\')
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Required map8000 bootstrap file is missing: $relative"
    }
    $remotePath = "$targetRoot/$relative"
    $remotePresence = Invoke-Native ssh @(
        '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $SshAlias,
        "test -f '$remotePath' && printf present || printf missing"
    ) -Capture
    if ($remotePresence -eq 'missing') {
        $deployFiles += $relative
    }
}
$deployFiles = @($deployFiles | Sort-Object -Unique)

foreach ($relative in $deployFiles) {
    $source = Join-Path $repositoryRoot ($relative -replace '/', '\')
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Candidate file is missing: $relative"
    }
    if ($relative.EndsWith('.php', [System.StringComparison]::OrdinalIgnoreCase)) {
        Invoke-Native php @('-l', $source)
    }
}

$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("fregat-local-publisher-{0}-{1}" -f $head, [guid]::NewGuid().ToString('N'))
$tempRoot = [System.IO.Path]::GetFullPath($tempRoot)
if (-not $tempRoot.StartsWith([System.IO.Path]::GetTempPath(), [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "Unexpected temporary path: $tempRoot"
}

[System.IO.Directory]::CreateDirectory($tempRoot) | Out-Null
try {
    $manifest = Join-Path $tempRoot 'manifest.txt'
    $manifestText = if ($deployFiles.Count -gt 0) { ($deployFiles -join "`n") + "`n" } else { '' }
    [System.IO.File]::WriteAllText($manifest, $manifestText, [System.Text.UTF8Encoding]::new($false))
    $remoteApplyUpload = Join-Path $tempRoot 'remote-apply.sh'
    $remoteApplyText = [System.IO.File]::ReadAllText($remoteApply).Replace("`r`n", "`n").Replace("`r", "`n")
    [System.IO.File]::WriteAllText($remoteApplyUpload, $remoteApplyText, [System.Text.UTF8Encoding]::new($false))

    $remoteStage = "$stateDir/staging/$head"
    Invoke-Native ssh @('-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $SshAlias, "mkdir -p '$remoteStage/files' '$stateDir/control'")

    foreach ($relative in $deployFiles) {
        $remoteParent = [System.IO.Path]::GetDirectoryName($relative).Replace('\', '/')
        if ($remoteParent) {
            Invoke-Native ssh @('-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $SshAlias, "mkdir -p '$remoteStage/files/$remoteParent'")
        }
        $source = Join-Path $repositoryRoot ($relative -replace '/', '\')
        Invoke-Native scp @('-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $source, "${SshAlias}:$remoteStage/files/$relative")
    }

    Invoke-Native scp @('-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $manifest, "${SshAlias}:$remoteStage/manifest.txt")
    Invoke-Native scp @('-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $remoteApplyUpload, "${SshAlias}:$stateDir/control/remote-apply.sh")
    Invoke-Native ssh @('-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $SshAlias, "bash '$stateDir/control/remote-apply.sh' '$remoteState' '$head' '$targetRoot' '$stateDir'")

    Invoke-Native curl.exe @('--fail', '--silent', '--show-error', '--max-time', '20', '--output', 'NUL', $ProductionUrl)
    $confirmedState = Invoke-Native ssh @('-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $SshAlias, "cat '$stateDir/current-sha'") -Capture
    if ($confirmedState -ne $head) {
        throw "Post-deploy state mismatch: expected $head, found $confirmedState"
    }

    Write-Output "LOCAL_PUBLISH_PASS SHA=$head"
    if ($deployFiles.Count -eq 0) {
        Write-Output 'DEPLOYED_FILES: none (state-only synchronization)'
    } else {
        Write-Output 'DEPLOYED_FILES:'
        $deployFiles | ForEach-Object { Write-Output "- $_" }
    }
}
finally {
    if ([System.IO.Directory]::Exists($tempRoot)) {
        [System.IO.Directory]::Delete($tempRoot, $true)
    }
}
