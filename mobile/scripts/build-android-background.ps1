$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$exitFile = Join-Path $projectRoot 'artifacts\apk-build-latest.exit'

try {
    & (Join-Path $projectRoot 'scripts\build-android-release.ps1') @args
    $exitCode = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
} catch {
    Write-Error $_
    $exitCode = 1
}

[System.IO.File]::WriteAllText($exitFile, [string]$exitCode)
exit $exitCode
