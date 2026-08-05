# -----------------------------------------------------------------------------
#  Registers the Windows task that ticks Laravel's scheduler every minute.
#
#  Run once, in a normal (non-admin) PowerShell:
#
#      powershell -ExecutionPolicy Bypass -File scripts\install-scheduler.ps1
#
#  It runs as the logged-in user, so no elevation and no stored password. The
#  cost is that it only ticks while somebody is logged in -- which is true of a
#  desktop anyway, and is why the leaflet import is guarded on staleness rather
#  than pinned to an hour: a machine that was off simply catches up.
#
#  Remove it with:  Unregister-ScheduledTask -TaskName 'Kitchen scheduler'
# -----------------------------------------------------------------------------

$ErrorActionPreference = 'Stop'

$name = 'Kitchen scheduler'
$script = Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) 'schedule-run.cmd'

if (-not (Test-Path $script)) {
    throw "Nie znaleziono $script"
}

# schtasks rather than Register-ScheduledTask: a repeating trigger with no end
# is what we want, and the cmdlet cannot express it -- an "indefinite" duration
# is rejected as out of range, and any finite one would silently stop ticking on
# the day it ran out.
schtasks /Create /TN $name /TR "`"$script`"" /SC MINUTE /MO 1 /F | Out-Null

if ($LASTEXITCODE -ne 0) {
    throw "schtasks zwrocilo $LASTEXITCODE"
}

# Keep running once started, and catch up after the machine was off or asleep.
schtasks /Change /TN $name /RI 1 /DU 9999:59 | Out-Null

Write-Output "Zarejestrowano zadanie '$name' (co minute, jako $env:USERNAME)."
Write-Output "Podglad:  schtasks /Query /TN '$name' /V /FO LIST"
