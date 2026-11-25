param(
    [Parameter(Mandatory = $true)][string[]]$Worktrees
)

# Copies the shared backend dependencies into each worktree.
#
# Why a COPY and not a junction: Composer bakes an absolute $baseDir into
# vendor/composer/autoload_*.php. With a junction every worktree shares one
# autoload file, so running any composer command (or Laravel package discovery)
# in one worktree silently repoints every other checkout at it. A local copy
# costs disk but keeps each stream's class resolution correct.

$root = 'D:\code\ai\factoryHR'
$sourceVendor = Join-Path $root 'backend\vendor'
$sourceModules = Join-Path $root 'frontend\node_modules'
$sourceEnv = Join-Path $root 'backend\.env'

foreach ($worktree in $Worktrees) {
    $target = Join-Path $root ".worktrees\$worktree"

    if (-not (Test-Path $target)) {
        Write-Output "skip (missing): $worktree"
        continue
    }

    $vendor = Join-Path $target 'backend\vendor'

    if (Test-Path $vendor) {
        $item = Get-Item $vendor -Force

        if ($item.LinkType -eq 'Junction') {
            [System.IO.Directory]::Delete($vendor, $false)
            Write-Output "removed junction: $worktree/backend/vendor"
        } else {
            Write-Output "vendor already local: $worktree"
        }
    }

    if (-not (Test-Path $vendor)) {
        Copy-Item -Recurse -Force $sourceVendor $vendor
        Write-Output "copied vendor: $worktree"
    }

    $modules = Join-Path $target 'frontend\node_modules'

    if (Test-Path $modules) {
        $item = Get-Item $modules -Force

        if ($item.LinkType -eq 'Junction') {
            [System.IO.Directory]::Delete($modules, $false)
            Write-Output "removed junction: $worktree/frontend/node_modules"
        }
    }

    if (-not (Test-Path $modules)) {
        # node_modules is only read; a junction is safe here because no tool
        # rewrites an absolute base path into it.
        New-Item -ItemType Junction -Path $modules -Target $sourceModules | Out-Null
        Write-Output "linked node_modules: $worktree"
    }

    $env = Join-Path $target 'backend\.env'

    if ((Test-Path $sourceEnv) -and -not (Test-Path $env)) {
        Copy-Item $sourceEnv $env
        Write-Output "copied .env: $worktree"
    }
}
