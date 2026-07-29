# Run on Windows (where dbtoolbox-ui source exists, sibling to dbtoolbox-web / dbtoolbox-php).
# Pushes the shared UI repo to GitHub.
param(
    [string]$UiPath = (Join-Path (Split-Path $PSScriptRoot -Parent | Split-Path -Parent) "dbtoolbox-ui"),
    [string]$Repo = "git@github.com:ldchino-hub/dbtoolbox-ui.git"
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path (Join-Path $UiPath "package.json"))) {
    throw "dbtoolbox-ui not found at: $UiPath"
}
if (-not (Test-Path (Join-Path $UiPath "src\index.ts"))) {
    throw "Missing src\index.ts in $UiPath"
}

Set-Location $UiPath

if (-not (Test-Path ".git")) {
    git init -b main
}

if (-not (Test-Path ".gitignore")) {
    @"
node_modules/
dist/
*.log
.DS_Store
tsconfig.tsbuildinfo
"@ | Set-Content .gitignore -Encoding UTF8
}

git add -A
$status = git status --porcelain
if ($status) {
    git commit -m "Publish dbtoolbox-ui from Windows dev machine"
}

if (-not (git remote get-url origin 2>$null)) {
    git remote add origin $Repo
}

$gh = Get-Command gh -ErrorAction SilentlyContinue
if ($gh) {
    gh repo view ldchino-hub/dbtoolbox-ui 2>$null
    if ($LASTEXITCODE -ne 0) {
        gh repo create dbtoolbox-ui --private --source=. --remote=origin --push
    } else {
        git push -u origin main
    }
} else {
    git push -u origin main
}

Write-Host "Pushed: https://github.com/ldchino-hub/dbtoolbox-ui"
