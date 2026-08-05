@echo off
rem ---------------------------------------------------------------------------
rem  What Windows Task Scheduler runs every minute so Laravel's own schedule
rem  (routes/console.php) actually fires. Without something calling this, every
rem  Schedule::command in the app is a definition nobody executes -- which is
rem  exactly how the leaflets went a fortnight without a refresh.
rem
rem  One minute is Laravel's expected tick: the schedule decides for itself what
rem  is due. This is a cheap process to start and start again; the leaflet
rem  import guards itself on staleness, so most ticks do nothing at all.
rem
rem  Register it with scripts\install-scheduler.ps1.
rem ---------------------------------------------------------------------------

cd /d "%~dp0.."

rem Herd's PHP by full path, because a task started by the scheduler does not
rem always inherit the PATH a terminal has.
set "PHP=%USERPROFILE%\.config\herd-lite\bin\php.exe"
if not exist "%PHP%" set "PHP=php"

"%PHP%" artisan schedule:run >> storage\logs\schedule.log 2>&1
