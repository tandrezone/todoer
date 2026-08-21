<#
  Creates the MVC branch and commits the refactored tree.

  Run this from the repository root in PowerShell:

      powershell -ExecutionPolicy Bypass -File .\setup-mvc-branch.ps1

  What it does, in order:
    1. checks you are in the todoer git repository with a clean working tree
    2. creates (or switches to) a branch called MVC
    3. removes the procedural files the refactor replaces -- they stay in git history on your
       current branch, so nothing is lost
    4. expands todoer-mvc.zip over the repository
    5. runs `composer install` if composer is on PATH
    6. commits everything as one commit on MVC

  Your data\ directory is never touched: the new code reads the same data\todoer.sqlite.
#>

$ErrorActionPreference = 'Stop'

function Fail($message) {
    Write-Host "ERROR: $message" -ForegroundColor Red
    exit 1
}

# --- 1. Sanity checks -------------------------------------------------------------------------
if (-not (Test-Path -Path '.git' -PathType Container)) {
    Fail 'No .git directory here. Run this from the root of the todoer repository.'
}
$zip = Join-Path (Get-Location) 'todoer-mvc.zip'
if (-not (Test-Path -Path $zip -PathType Leaf)) {
    Fail "todoer-mvc.zip not found in $(Get-Location). Save it here first."
}

$status = (git status --porcelain) | Where-Object { $_ -notmatch 'todoer-mvc\.zip|setup-mvc-branch\.(ps1|sh)' }
if ($status) {
    Write-Host 'Your working tree has uncommitted changes:' -ForegroundColor Yellow
    $status | ForEach-Object { Write-Host "  $_" }
    $answer = Read-Host 'Commit or stash them first. Continue anyway? (y/N)'
    if ($answer -ne 'y') { exit 1 }
}

$startingBranch = (git rev-parse --abbrev-ref HEAD).Trim()
Write-Host "Starting from branch '$startingBranch'." -ForegroundColor Cyan

# --- 2. The branch ----------------------------------------------------------------------------
$branches = git branch --list MVC
if ($branches) {
    Write-Host "Branch MVC already exists -- switching to it." -ForegroundColor Yellow
    git switch MVC
} else {
    git switch -c MVC
}
if ($LASTEXITCODE -ne 0) { Fail 'Could not create or switch to the MVC branch.' }

# --- 3. Remove what the refactor replaces -----------------------------------------------------
$legacy = @(
    'index.php', 'login.php', 'logout.php', 'group.php', 'prizes.php', 'import.php', 'backup.php',
    'service-worker.js', 'site.webmanifest', 'favicon.ico',
    'includes', 'api', 'assets'
)
foreach ($path in $legacy) {
    if (Test-Path -Path $path) {
        git rm -r -q --ignore-unmatch -- $path | Out-Null
        if (Test-Path -Path $path) { Remove-Item -Recurse -Force -- $path }
        Write-Host "  removed $path"
    }
}

# --- 4. The new tree --------------------------------------------------------------------------
Write-Host 'Expanding todoer-mvc.zip...' -ForegroundColor Cyan
Expand-Archive -Path $zip -DestinationPath (Get-Location) -Force
Remove-Item -Force -- $zip

# --- 5. Dependencies --------------------------------------------------------------------------
if (Get-Command composer -ErrorAction SilentlyContinue) {
    Write-Host 'Running composer install...' -ForegroundColor Cyan
    composer install --no-interaction
    if ($LASTEXITCODE -ne 0) {
        Write-Host 'composer install failed -- run it by hand before starting the app.' -ForegroundColor Yellow
    }
} else {
    Write-Host 'Composer is not on PATH. Run `composer install` yourself before starting the app.' -ForegroundColor Yellow
}

# --- 6. Commit --------------------------------------------------------------------------------
git add -A
git commit -q -m @'
Refactor into an MVC architecture

Replace the procedural page/api/includes layout with a layered MVC application:
a single front controller under public/, a PSR-15 middleware pipeline, PSR-11
dependency injection, controllers that only translate requests, services holding
the game rules, a domain model with typed enums and value objects, and one
repository per table where every statement is a prepared statement.

Security: the web root is now public/ only (the SQLite database and the Web Push
private key were previously downloadable), CSRF is enforced for every unsafe
method instead of per endpoint, sign-out is a POST, migrations no longer
interpolate ids into SQL, errors never leak stack traces or corrupt a response
body, output escaping is applied uniformly in templates, and task timers no
longer skew by the UTC offset on servers that are not set to UTC.

The database schema, every JSON key the front-end reads, and the game's rules are
unchanged. tests/smoke.php covers the stack end to end.
'@
if ($LASTEXITCODE -ne 0) { Fail 'Nothing was committed -- check `git status`.' }

Write-Host ''
Write-Host 'Done. You are on branch MVC.' -ForegroundColor Green
Write-Host 'Start the app with:  php -S 0.0.0.0:8080 -t public public/index.php'
Write-Host 'Run the tests with:  php tests/smoke.php'
Write-Host "Go back with:         git switch $startingBranch"
