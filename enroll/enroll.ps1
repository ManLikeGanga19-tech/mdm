<#
.SYNOPSIS
  Enrolls freshly reset company phones as Noahport MDM devices over USB.

.DESCRIPTION
  Runs on any Windows laptop (Windows PowerShell 5.1, no admin rights, no Android Studio). Everything it needs is in
  this kit folder; adb (Android Platform-Tools) is downloaded from Google on first use if the kit doesn't include it.

  For each phone: checks it is safe to enroll, installs the agent, makes it device owner, hands it its enrollment
  token and label, and appends one row to Documents\Noahport\enrollments.csv.

  Started by double-clicking enroll.cmd. See README.txt for the steps.

.PARAMETER AssetTag
  Label for the phone (e.g. NP-0042). Asked for when not given.

.PARAMETER AssignedTo
  Person the phone is issued to. Optional; asked for when not given (Enter to skip).

.PARAMETER Once
  Enroll one phone and exit, without prompts other than the labels. Fails if no phone appears within 60 s.

.PARAMETER Token
  Enrollment token. Until the backend issues tokens (Phase 2), a random one is generated per phone.
#>
[CmdletBinding()]
param(
    [string]$AssetTag,
    [string]$AssignedTo,
    [switch]$Once,
    [string]$Token
)

Set-StrictMode -Version 2
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue' # Invoke-WebRequest is very slow with the progress bar on 5.1

$KitDir = $PSScriptRoot
$Kit = Get-Content -Raw (Join-Path $KitDir 'kit.json') | ConvertFrom-Json
$Component = "$($Kit.package)/$($Kit.adminReceiver)"
$DataDir = Join-Path $env:LOCALAPPDATA 'Noahport'
$CsvPath = Join-Path ([Environment]::GetFolderPath('MyDocuments')) 'Noahport\enrollments.csv'
$PlatformToolsUrl = 'https://dl.google.com/android/repository/platform-tools-latest-windows.zip'

function Say([string]$Text, [string]$Color = 'Gray') { Write-Host $Text -ForegroundColor $Color }
function Step([string]$Text) { Write-Host "  - $Text" -ForegroundColor Cyan }

# A phone-level failure: shown to the user, recorded in the CSV, then the script moves on to the next phone.
class EnrollError : Exception { EnrollError([string]$m) : base($m) {} }

function Find-Adb {
    $candidates = @(
        (Join-Path $KitDir 'platform-tools\adb.exe'),
        (Join-Path $DataDir 'platform-tools\adb.exe')
    )
    foreach ($c in $candidates) { if (Test-Path $c) { return $c } }
    $onPath = Get-Command adb.exe -ErrorAction SilentlyContinue
    if ($onPath) { return $onPath.Source }

    Say "adb not found. Downloading Android Platform-Tools from Google (about 7 MB)..."
    New-Item -ItemType Directory -Force $DataDir | Out-Null
    $zip = Join-Path $DataDir 'platform-tools.zip'
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    try {
        Invoke-WebRequest -UseBasicParsing -Uri $PlatformToolsUrl -OutFile $zip
    } catch {
        throw "Could not download Platform-Tools ($($_.Exception.Message)). Download it manually from " +
            "https://developer.android.com/tools/releases/platform-tools and unzip it into this kit folder, " +
            "so that platform-tools\adb.exe sits next to enroll.ps1."
    }
    Expand-Archive -Force $zip $DataDir
    Remove-Item $zip
    return (Join-Path $DataDir 'platform-tools\adb.exe')
}

# Runs adb and returns exit code + combined output. Uses Process directly: on PowerShell 5.1 redirecting a native
# command's stderr turns each line into an error record.
function Invoke-Adb([string[]]$Arguments) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $script:Adb
    $psi.Arguments = ($Arguments | ForEach-Object { if ($_ -match '[\s"]') { '"' + ($_ -replace '"', '\"') + '"' } else { $_ } }) -join ' '
    $psi.UseShellExecute = $false
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.CreateNoWindow = $true
    $p = [Diagnostics.Process]::Start($psi)
    $stdout = $p.StandardOutput.ReadToEndAsync()
    $stderr = $p.StandardError.ReadToEnd()
    $p.WaitForExit()
    [pscustomobject]@{ Code = $p.ExitCode; Out = ($stdout.Result + $stderr).Trim() }
}

function Shell([string]$Command) { (Invoke-Adb @('-s', $script:Serial, 'shell', $Command)).Out }

function Get-Devices {
    $lines = (Invoke-Adb @('devices')).Out -split "`r?`n" | Select-Object -Skip 1
    foreach ($l in $lines) {
        if ($l -match '^(\S+)\s+(\S+)') { [pscustomobject]@{ Serial = $Matches[1]; State = $Matches[2] } }
    }
}

# Waits until exactly one authorized phone is connected and returns its adb serial.
function Wait-Phone {
    $deadline = (Get-Date).AddSeconds(60)
    $lastMessage = ''
    while ($true) {
        # ANDROID_SERIAL (adb's own convention) picks one phone when several are plugged in.
        $devices = @(Get-Devices | Where-Object { -not $env:ANDROID_SERIAL -or $_.Serial -eq $env:ANDROID_SERIAL })
        $message = ''
        if ($devices.Count -eq 0) {
            $message = 'Waiting for a phone... (USB cable plugged in? USB debugging on?)'
        } elseif ($devices.Count -gt 1) {
            $message = "$($devices.Count) phones are connected. Unplug all but one."
        } elseif ($devices[0].State -eq 'unauthorized') {
            $message = 'Unlock the phone and tap Allow on "Allow USB debugging?" (tick "Always allow").'
        } elseif ($devices[0].State -eq 'device') {
            return $devices[0].Serial
        } else {
            $message = "Phone state is '$($devices[0].State)'. Unplug it and plug it back in."
        }
        if ($message -ne $lastMessage) { Say $message 'Yellow'; $lastMessage = $message }
        if ($Once -and (Get-Date) -gt $deadline) { throw 'No usable phone connected within 60 seconds.' }
        Start-Sleep -Seconds 2
    }
}

function Read-Label([string]$Prompt, [string]$Value, [string]$Pattern, [bool]$Required) {
    while ($true) {
        if (-not $Value -and $Once -and -not $Required) { return '' }
        if (-not $Value) { $Value = (Read-Host $Prompt).Trim() }
        if (-not $Value -and -not $Required) { return '' }
        if ($Value -match $Pattern) { return $Value }
        Say 'Use letters, digits, spaces and . _ / - only (max 40 characters).' 'Yellow'
        if ($Once) { throw "Invalid value '$Value' for: $Prompt" }
        $Value = ''
    }
}

function New-Token {
    $bytes = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Write-Record($Phone, [string]$Result, [string]$Detail) {
    New-Item -ItemType Directory -Force (Split-Path $CsvPath) | Out-Null
    [pscustomobject]@{
        TimeUtc      = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
        Result       = $Result
        Serial       = $Phone.Serial
        Manufacturer = $Phone.Manufacturer
        Model        = $Phone.Model
        Android      = $Phone.Android
        AssetTag     = $Phone.AssetTag
        AssignedTo   = $Phone.AssignedTo
        AgentVersion = $Kit.versionName
        Laptop       = $env:COMPUTERNAME
        Operator     = $env:USERNAME
        Detail       = $Detail
    } | Export-Csv -Append -NoTypeInformation -Encoding UTF8 $CsvPath
}

function Enroll-Phone($Phone) {
    Step 'Checking the phone'
    $sdk = [int](Shell 'getprop ro.build.version.sdk')
    if ($sdk -lt [int]$Kit.minSdk) { throw [EnrollError]::new("Android SDK $sdk is too old; the agent needs SDK $($Kit.minSdk)+ (Android 13).") }

    $owners = Shell 'dpm list-owners'
    if ($owners -match [regex]::Escape("$Component,DeviceOwner")) {
        throw [EnrollError]::new('ALREADY: this phone is already enrolled (the agent is device owner). Nothing changed.')
    }
    if ($owners -notmatch 'no owners') {
        throw [EnrollError]::new("Another app manages this phone: $owners. Factory reset it first.")
    }
    $accounts = Shell 'dumpsys account'
    if ($accounts -match 'Accounts: (\d+)' -and [int]$Matches[1] -gt 0) {
        $types = ([regex]::Matches($accounts, 'Account \{name=[^,]*, type=([^}]+)\}') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique) -join ', '
        throw [EnrollError]::new("The phone has $($Matches[1]) account(s) ($types). Factory reset it and skip all sign-ins during setup.")
    }
    $users = ([regex]::Matches((Shell 'pm list users'), 'UserInfo\{')).Count
    if ($users -gt 1) { throw [EnrollError]::new("The phone has $users users. Factory reset it.") }

    $Phone.AssetTag = Read-Label 'Asset tag (e.g. NP-0042)' $script:AssetTagArg '^[A-Za-z0-9 ._/-]{1,40}$' $true
    $Phone.AssignedTo = Read-Label 'Issued to (name, Enter to skip)' $script:AssignedToArg '^[A-Za-z0-9 ._/-]{1,40}$' $false

    Step 'Installing the agent'
    $install = Invoke-Adb @('-s', $script:Serial, 'install', '-r', $script:Apk)
    if ($install.Out -notmatch '(?m)^Success') {
        $hint = ''
        if ($install.Out -match 'INSTALL_FAILED_UPDATE_INCOMPATIBLE') { $hint = ' A test copy of the agent signed with another key is installed: factory reset the phone.' }
        throw [EnrollError]::new("Install failed: $($install.Out)$hint")
    }

    Step 'Making it device owner'
    # Shortly after boot Android is still working out whether the phone has accounts and refuses with "already some
    # accounts" even when there are none; it settles within a minute, so retry while dumpsys still shows 0 accounts.
    for ($try = 1; ; $try++) {
        $set = Shell "dpm set-device-owner $Component"
        if ($set -match '^Success') { break }
        $noAccounts = (Shell 'dumpsys account') -match 'Accounts: 0\b'
        if ($set -match 'already some accounts' -and $noAccounts -and $try -lt 18) {
            if ($try -eq 1) { Say '    The phone is still starting up. Retrying for up to 90 seconds...' 'Yellow' }
            Start-Sleep -Seconds 5
            continue
        }
        # Keep the one useful line of Android's stack trace.
        $reason = ($set -split "`r?`n" | Where-Object { $_ -match 'Exception:' } | Select-Object -First 1)
        if (-not $reason) { $reason = $set }
        throw [EnrollError]::new("set-device-owner failed: $($reason.Trim()) Factory reset the phone, skip every sign-in during setup, and try again.")
    }
    if ((Shell 'dpm list-owners') -notmatch [regex]::Escape("$Component,DeviceOwner")) {
        throw [EnrollError]::new('set-device-owner reported success but the agent is not device owner.')
    }

    Step 'Handing over the enrollment token'
    $token = if ($script:TokenArg) { $script:TokenArg } else { New-Token }
    # Labels are validated above (no quotes), so single-quoting is safe for the phone's shell.
    $broadcast = Shell ("am broadcast --include-stopped-packages -n $($Kit.package)/.EnrollmentTokenReceiver " +
        "--es enrollment_token $token --es asset_tag '$($Phone.AssetTag)' --es assigned_to '$($Phone.AssignedTo)'")
    if ($broadcast -notmatch 'result=1\b') {
        throw [EnrollError]::new("The agent did not accept the enrollment token: $broadcast")
    }

    Shell "am start -n $($Kit.package)/.MainActivity" | Out-Null
}

# ---- main ----
$script:AssetTagArg = $AssetTag
$script:AssignedToArg = $AssignedTo
$script:TokenArg = $Token

Say ''
Say "Noahport MDM - USB enrollment (agent $($Kit.versionName))" 'White'
Say ''

$script:Apk = Join-Path $KitDir $Kit.apk
if (-not (Test-Path $script:Apk)) { throw "Agent APK missing: $($script:Apk). Re-download the kit." }
if ((Get-FileHash -Algorithm SHA256 $script:Apk).Hash -ne $Kit.apkSha256) {
    throw "The agent APK does not match kit.json (corrupted or tampered download). Re-download the kit."
}

$script:Adb = Find-Adb
Invoke-Adb @('start-server') | Out-Null
Say "Results are saved to $CsvPath"

$enrolled = 0
while ($true) {
    Say ''
    Say 'Plug in a freshly reset phone with USB debugging on.' 'White'
    $script:Serial = Wait-Phone
    $phone = [pscustomobject]@{
        Serial       = (Shell 'getprop ro.serialno')
        Manufacturer = (Shell 'getprop ro.product.manufacturer')
        Model        = (Shell 'getprop ro.product.model')
        Android      = (Shell 'getprop ro.build.version.release')
        AssetTag     = ''
        AssignedTo   = ''
    }
    if (-not $phone.Serial) { $phone.Serial = $script:Serial }
    Say "Found $($phone.Manufacturer) $($phone.Model), Android $($phone.Android), serial $($phone.Serial)" 'White'

    $exitCode = 0
    try {
        Enroll-Phone $phone
        Write-Record $phone 'ENROLLED' ''
        $enrolled++
        Say "DONE: $($phone.AssetTag) is enrolled. You can unplug it." 'Green'
    } catch [EnrollError] {
        $msg = $_.Exception.Message
        if ($msg.StartsWith('ALREADY: ')) {
            $msg = $msg.Substring(9)
            Write-Record $phone 'ALREADY_ENROLLED' $msg
            Say $msg 'Yellow'
        } else {
            Write-Record $phone 'FAILED' $msg
            Say "NOT ENROLLED: $msg" 'Red'
            $exitCode = 1
        }
    } catch {
        # Unexpected (adb crashed, phone unplugged mid-way...): record it and carry on with the next phone.
        Write-Record $phone 'FAILED' "Unexpected error: $($_.Exception.Message)"
        Say "NOT ENROLLED (unexpected error): $($_.Exception.Message)" 'Red'
        Say 'Check the cable, then try this phone again.' 'Red'
        $exitCode = 1
    }

    if ($Once) { exit $exitCode }
    $script:AssetTagArg = ''
    $script:AssignedToArg = ''
    $script:TokenArg = ''
    Say ''
    $next = Read-Host 'Unplug the phone. Press Enter for the next phone, or type Q and Enter to quit'
    if ($next -match '^[Qq]') { break }
    while (@(Get-Devices | Where-Object { $_.Serial -eq $script:Serial }).Count -gt 0) {
        Say 'Waiting for the previous phone to be unplugged...' 'Yellow'
        Start-Sleep -Seconds 2
    }
}
Say "Enrolled $enrolled phone(s) this session. Results: $CsvPath" 'White'
