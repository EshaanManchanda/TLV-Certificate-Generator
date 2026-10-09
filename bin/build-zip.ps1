<#
.SYNOPSIS
    Builds a production-only zip of the Certificate Generator plugin.

    Exports the last commit via `git archive` (honoring .gitattributes
    export-ignore rules), regenerates a dev-free vendor/ with
    `composer install --no-dev`, and zips the result into dist/.
#>

$ErrorActionPreference = "Stop"
$repoRoot = git rev-parse --show-toplevel
Set-Location $repoRoot

$slug = "certificate-generator"

if (git status --porcelain) {
    Write-Warning "Working tree has uncommitted changes - the zip is built from the last commit (HEAD), not your working tree. Commit first if you want those changes included."
}

$versionLine = Select-String -Path certificate-generator.php -Pattern 'Version:\s*([\d.]+)' | Select-Object -First 1
if (-not $versionLine) {
    throw "Could not find a Version: header in certificate-generator.php"
}
$version = $versionLine.Matches[0].Groups[1].Value

$stage = Join-Path $env:TEMP "cg-build-$([System.Guid]::NewGuid().ToString('N'))"
$pluginDir = Join-Path $stage $slug
New-Item -ItemType Directory -Force -Path $pluginDir | Out-Null

$sourceZip = Join-Path $stage "src.zip"
git archive --format=zip -o $sourceZip HEAD
Expand-Archive -Path $sourceZip -DestinationPath $pluginDir -Force
Remove-Item $sourceZip

# composer/php often aren't on PATH under Local by Flywheel (see doc/dev/TESTING.md) -
# fall back to the repo's vendored composer.phar run through Local's bundled PHP.
$composerCmd = Get-Command composer -ErrorAction SilentlyContinue
if ($composerCmd) {
    $installArgs = @("install", "--no-dev", "--optimize-autoloader", "--no-interaction", "--quiet")
    $installExe = "composer"
} else {
    $phpExe = Get-Command php -ErrorAction SilentlyContinue
    if (-not $phpExe) {
        $phpExe = Get-ChildItem "$env:APPDATA\Local\lightning-services\php-*\bin\win64\php.exe" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
    }
    $localPhar = Join-Path $repoRoot "composer.phar"
    if (-not $phpExe -or -not (Test-Path $localPhar)) {
        throw "Could not find composer on PATH, nor a php.exe + composer.phar fallback. See doc/dev/TESTING.md for this machine's bundled PHP path, or run this from Local's Site Shell."
    }
    # The CLI php.ini under lightning-services often lacks extensions (e.g. openssl)
    # that the site's own php.ini enables - reuse the site's ini when present.
    $siteIni = Get-ChildItem "$env:APPDATA\Local\run\*\conf\php\php.ini" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
    $installExe = "$phpExe"
    $installArgs = @()
    if ($siteIni) {
        $installArgs += @("-c", $siteIni)
    }
    $installArgs += @($localPhar, "install", "--no-dev", "--optimize-autoloader", "--no-interaction", "--quiet")
}

Push-Location $pluginDir
try {
    & $installExe @installArgs
    if ($LASTEXITCODE -ne 0) {
        throw "composer install --no-dev failed"
    }
} finally {
    Pop-Location
}

$distDir = Join-Path $repoRoot "dist"
New-Item -ItemType Directory -Force -Path $distDir | Out-Null
$zipPath = Join-Path $distDir "$slug-$version.zip"
if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}
# Both Compress-Archive and ZipFile::CreateFromDirectory (on Windows PowerShell
# 5.1 / .NET Framework) write backslash separators, which unzip on Linux hosting
# as flat files named "a\b.php". Write each entry with forward slashes ourselves.
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -Path $pluginDir -Recurse -File | ForEach-Object {
        $entry = $slug + "/" + $_.FullName.Substring($pluginDir.Length + 1).Replace("\", "/")
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entry, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
} finally {
    $zip.Dispose()
}

Remove-Item -Recurse -Force $stage

$hash = (Get-FileHash -Algorithm SHA256 -Path $zipPath).Hash.ToLower()
Set-Content -Path "$zipPath.sha256" -Value "$hash  $slug-$version.zip" -Encoding ascii
Write-Host "Built $zipPath (SHA-256 $hash)"
