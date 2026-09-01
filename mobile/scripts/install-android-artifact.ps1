[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string]$ArtifactPath,

    [string]$DeviceSerial = ''
)

$ErrorActionPreference = 'Stop'
$applicationId = 'com.rokn'
$resolvedArtifact = (Resolve-Path -LiteralPath $ArtifactPath).Path
if ([System.IO.Path]::GetExtension($resolvedArtifact) -ine '.apk') {
    throw 'Only an APK can be installed directly on an Android device.'
}

$sdkCandidates = @(
    $env:ANDROID_HOME
    $env:ANDROID_SDK_ROOT
    $(if ($env:LOCALAPPDATA) { Join-Path $env:LOCALAPPDATA 'Android\Sdk' })
) | Where-Object { $_ -and (Test-Path -LiteralPath $_ -PathType Container) }
$androidSdk = $sdkCandidates | Select-Object -First 1
if (-not $androidSdk) {
    throw 'Android SDK was not found. Set ANDROID_HOME or install it with Android Studio.'
}

$buildToolsRoot = Join-Path $androidSdk 'build-tools'
$buildTools = Get-ChildItem -LiteralPath $buildToolsRoot -Directory -ErrorAction SilentlyContinue |
    Sort-Object { [version]$_.Name } -Descending
$apkSigner = $buildTools |
    ForEach-Object { Join-Path $_.FullName 'apksigner.bat' } |
    Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } |
    Select-Object -First 1
$aapt = $buildTools |
    ForEach-Object { Join-Path $_.FullName 'aapt.exe' } |
    Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } |
    Select-Object -First 1
$adb = Join-Path $androidSdk 'platform-tools\adb.exe'
foreach ($tool in @($apkSigner, $aapt, $adb)) {
    if (-not $tool -or -not (Test-Path -LiteralPath $tool -PathType Leaf)) {
        throw 'Android SDK platform-tools, aapt, and apksigner are required.'
    }
}

function Invoke-Tool {
    param(
        [Parameter(Mandatory = $true)][string]$Command,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [string]$FailureMessage = 'Android tool failed.'
    )

    $output = (& $Command @Arguments 2>&1 | Out-String)
    if ($LASTEXITCODE -ne 0) {
        throw "$FailureMessage`n$output"
    }
    return $output
}

function Get-ApkIdentity {
    param([Parameter(Mandatory = $true)][string]$Path)

    $badging = Invoke-Tool -Command $aapt -Arguments @('dump', 'badging', $Path) `
        -FailureMessage "Could not inspect APK manifest: $Path"
    $packageMatch = [regex]::Match(
        $badging,
        "(?m)^package:\s+name='(?<id>[^']+)'\s+versionCode='(?<code>\d+)'"
    )
    if (-not $packageMatch.Success) {
        throw "APK does not expose a valid package name and versionCode: $Path"
    }

    $signature = Invoke-Tool -Command $apkSigner `
        -Arguments @('verify', '--verbose', '--print-certs', $Path) `
        -FailureMessage "APK signature verification failed: $Path"
    $digestMatches = [regex]::Matches(
        $signature,
        '(?im)^Signer #\d+ certificate SHA-256 digest:\s*(?<digest>[0-9a-f:]+)\s*$'
    )
    $digests = @($digestMatches | ForEach-Object {
        $_.Groups['digest'].Value.Replace(':', '').ToLowerInvariant()
    } | Select-Object -Unique)
    if ($digests.Count -eq 0) {
        throw "APK signer fingerprint is missing: $Path"
    }

    return [pscustomobject]@{
        ApplicationId = $packageMatch.Groups['id'].Value
        VersionCode = [long]$packageMatch.Groups['code'].Value
        Signers = $digests
    }
}

$adbPrefix = @()
if (-not [string]::IsNullOrWhiteSpace($DeviceSerial)) {
    $adbPrefix = @('-s', $DeviceSerial.Trim())
}
$candidate = Get-ApkIdentity -Path $resolvedArtifact
if ($candidate.ApplicationId -ne $applicationId) {
    throw "Expected Android application id '$applicationId', found '$($candidate.ApplicationId)'."
}

$sidecarPath = $resolvedArtifact + '.json'
if (Test-Path -LiteralPath $sidecarPath -PathType Leaf) {
    $sidecar = Get-Content -LiteralPath $sidecarPath -Raw | ConvertFrom-Json
    $sidecarSigner = ([string]$sidecar.signerSha256 -replace '[^0-9A-Fa-f]', '').ToLowerInvariant()
    if (
        [string]$sidecar.applicationId -ne $candidate.ApplicationId -or
        [long]$sidecar.versionCode -ne $candidate.VersionCode -or
        $candidate.Signers -notcontains $sidecarSigner
    ) {
        throw 'APK identity or signer does not match its provenance sidecar.'
    }
}

$state = Invoke-Tool -Command $adb -Arguments ($adbPrefix + @('get-state')) `
    -FailureMessage 'No authorized Android device is available.'
if ($state.Trim() -ne 'device') {
    throw "Android device is not ready: $($state.Trim())"
}

$temporaryRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
$auditDirectory = Join-Path $temporaryRoot ('rokn-upgrade-audit-' + [guid]::NewGuid().ToString('N'))
$resolvedAuditDirectory = [System.IO.Path]::GetFullPath($auditDirectory)
if (-not $resolvedAuditDirectory.StartsWith($temporaryRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Refusing to create the upgrade audit outside the system temporary directory.'
}

try {
    $packagePathOutput = (& $adb @adbPrefix shell pm path $applicationId 2>&1 | Out-String)
    $packagePathExitCode = $LASTEXITCODE
    $installedPackageMatch = [regex]::Match($packagePathOutput, '(?m)^package:(?<path>.+base\.apk)\s*$')
    if ($packagePathExitCode -eq 0 -and $installedPackageMatch.Success) {
        $packageReport = Invoke-Tool -Command $adb `
            -Arguments ($adbPrefix + @('shell', 'dumpsys', 'package', $applicationId)) `
            -FailureMessage "Could not inspect installed package $applicationId."
        $installedVersionMatch = [regex]::Match(
            $packageReport,
            '(?m)^\s*versionCode=(?<code>\d+)'
        )
        if (-not $installedVersionMatch.Success) {
            throw 'Installed Rokn versionCode could not be read safely.'
        }
        $installedVersionCode = [long]$installedVersionMatch.Groups['code'].Value
        if ($candidate.VersionCode -lt $installedVersionCode) {
            throw "Downgrade refused: installed versionCode is $installedVersionCode and candidate is $($candidate.VersionCode). The installed app and its local data were left unchanged."
        }

        New-Item -ItemType Directory -Path $resolvedAuditDirectory | Out-Null
        $installedApk = Join-Path $resolvedAuditDirectory 'installed-base.apk'
        Invoke-Tool -Command $adb `
            -Arguments ($adbPrefix + @('pull', $installedPackageMatch.Groups['path'].Value.Trim(), $installedApk)) `
            -FailureMessage 'Could not inspect the installed APK signer.' | Out-Null
        $installed = Get-ApkIdentity -Path $installedApk
        $sharesSigner = @($candidate.Signers | Where-Object { $installed.Signers -contains $_ }).Count -gt 0
        if (-not $sharesSigner) {
            throw @"
Update refused because the installed app and candidate APK use different signing certificates.
The script did not uninstall the existing app because uninstalling would erase its local session and data.
Use an APK signed with the same stable Rokn application-signing key.
"@
        }
    }

    $installOutput = Invoke-Tool -Command $adb `
        -Arguments ($adbPrefix + @('install', '-r', $resolvedArtifact)) `
        -FailureMessage 'Android rejected the in-place APK update. The existing app was not uninstalled.'
    if ($installOutput -notmatch '(?im)^Success\s*$') {
        throw "Android did not confirm installation success.`n$installOutput"
    }
    Write-Output "Installed $applicationId versionCode $($candidate.VersionCode) in place."
} finally {
    if (
        (Test-Path -LiteralPath $resolvedAuditDirectory -PathType Container) -and
        $resolvedAuditDirectory.StartsWith($temporaryRoot, [System.StringComparison]::OrdinalIgnoreCase) -and
        (Split-Path -Leaf $resolvedAuditDirectory) -match '^rokn-upgrade-audit-[0-9a-f]{32}$'
    ) {
        Remove-Item -LiteralPath $resolvedAuditDirectory -Recurse -Force
    }
}
