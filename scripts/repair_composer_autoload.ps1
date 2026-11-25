param(
    [string]$Backend = 'D:\code\ai\factoryHR\backend'
)

# Composer bakes an absolute $baseDir into vendor/composer/autoload_*.php.
# When a worktree links `vendor` with a junction, `composer dump-autoload`
# inside that worktree rewrites the SHARED autoload files to point at the
# worktree, so every other checkout silently loads that worktree's sources.
# This script detects and repairs that corruption.
#
# Usage: powershell -File scripts/repair_composer_autoload.ps1

$composerDir = Join-Path $Backend 'vendor\composer'

if (-not (Test-Path $composerDir)) {
    Write-Error "vendor/composer not found under $Backend"
    exit 1
}

$expectedBase = (Resolve-Path $Backend).Path.Replace('\', '/')

$files = @(
    'autoload_classmap.php',
    'autoload_files.php',
    'autoload_namespaces.php',
    'autoload_psr4.php',
    'autoload_static.php'
)

$repaired = 0

foreach ($name in $files) {
    $path = Join-Path $composerDir $name

    if (-not (Test-Path $path)) {
        continue
    }

    $content = Get-Content $path -Raw
    $original = $content

    # Rewrite any foreign absolute base dir back to this checkout.
    $content = [regex]::Replace(
        $content,
        "dirname\(dirname\(\`$vendorDir\)\)\.'[^']*'",
        "dirname(`$vendorDir)"
    )
    $content = [regex]::Replace(
        $content,
        "__DIR__ \. '/\.\./\.\./\.\.'\.'[^']*'",
        "__DIR__ . '/../..'"
    )
    $content = [regex]::Replace(
        $content,
        "__DIR__ \. '/\.\./\.\.'\.'[^']*'",
        "__DIR__ . '/../..'"
    )

    if ($content -ne $original) {
        Set-Content -Path $path -Value $content -NoNewline
        $repaired++
        Write-Output "repaired: $name"
    }
}

# Report any remaining reference to another checkout.
$remaining = Select-String -Path (Join-Path $composerDir '*.php') -Pattern 'worktrees' -SimpleMatch

if ($remaining) {
    Write-Output 'STILL CORRUPT:'
    $remaining | ForEach-Object { Write-Output "  $($_.Path):$($_.LineNumber)" }
    exit 1
}

Write-Output "composer autoload OK (repaired files: $repaired; expected base: $expectedBase)"
