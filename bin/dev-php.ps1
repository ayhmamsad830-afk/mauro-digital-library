<#
  MAURO local development launcher for Windows.

  Runs PHP with the project-local settings in deploy\local-php\ (CA bundle for
  HTTPS, so Google Sign-In can fetch its signing keys) layered on top of the
  global php.ini, which is never modified. Extensions the app needs but the
  global php.ini leaves disabled are enabled for this process only.

  Usage (from the project root):
    powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1                 # start the site on http://localhost:8000
    powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 bin/console env:check
    powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 tests/run.php GoogleSignIn

  With no arguments it starts the development server. Any arguments are passed
  to php unchanged.
#>
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$iniDir = Join-Path $root 'deploy\local-php'

# Layer the project ini on top of php.ini (PHP_INI_SCAN_DIR adds, it does not replace).
$env:PHP_INI_SCAN_DIR = $iniDir

$caLine = Select-String -Path (Join-Path $iniDir 'mauro-dev.ini') -Pattern '^\s*curl\.cainfo\s*=\s*"?([^"]+)"?' | Select-Object -First 1
if ($caLine) {
    $ca = $caLine.Matches[0].Groups[1].Value
    if (-not (Test-Path -LiteralPath $ca)) {
        Write-Warning "CA bundle not found: $ca - install Git for Windows or edit deploy\local-php\mauro-dev.ini. HTTPS calls (Google Sign-In) will fail."
    }
}

# Enable required extensions that the global php.ini leaves off (this process only).
$loaded = & php -m 2>$null | ForEach-Object { $_.Trim().ToLower() }
$extArgs = @()
foreach ($ext in 'mbstring', 'gd', 'fileinfo', 'curl', 'openssl', 'pdo_sqlite', 'pdo_mysql') {
    if ($loaded -notcontains $ext) { $extArgs += @('-d', "extension=$ext") }
}

Set-Location $root
if ($args.Count -eq 0) {
    Write-Host 'MAURO dev server: http://localhost:8000  (Ctrl+C to stop)'
    & php @extArgs -S 127.0.0.1:8000 -t public public/index.php
} else {
    & php @extArgs @args
}
exit $LASTEXITCODE
