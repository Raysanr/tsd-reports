# ============================================================================
#  TSD Reports / Call Tracker — Automatic Call Recording Uploader
# ============================================================================
#  What this does: watches a folder on this PC (where your recorder saves
#  call audio files) and automatically uploads each new recording to Call
#  Tracker the moment it's finished writing — no manual step.
#
#  SETUP (do this once):
#    1. Fill in $ApiToken below — find it on the Call Rotation page
#       (Config -> Call Rotation), under "Phone call automation", after
#       clicking "Generate token" for this TSA.
#    2. Fill in $WatchFolder below — the exact folder your recorder (OBS
#       Studio, etc.) saves finished recordings into.
#    3. Right-click this file -> "Run with PowerShell" to start it.
#    4. Leave the window open — it keeps watching in the background for as
#       long as it's running. To make it start automatically every time this
#       PC turns on, see "RUN AT STARTUP" at the very bottom of this file.
#
#  Every file it uploads gets moved into an "Uploaded" subfolder afterward,
#  so it's never uploaded twice and you still have a local backup.
# ============================================================================

# --- 1. YOUR SETTINGS — edit these three lines ---------------------------

$ApiToken     = "PASTE_THE_TSA_API_TOKEN_HERE"
$WatchFolder  = "C:\Users\YourName\Videos\Call Recordings"
$UploadUrl    = "https://tsd-reports-production.up.railway.app/api/call-recordings"

# --- 2. You shouldn't need to change anything below this line ------------

$ErrorActionPreference = "Stop"
$AllowedExtensions = @(".mp3", ".wav", ".m4a", ".mp4", ".mkv", ".ogg", ".webm")
$UploadedFolder = Join-Path $WatchFolder "Uploaded"
$LogFile        = Join-Path $WatchFolder "upload-log.txt"

if (-not (Test-Path $WatchFolder)) {
    Write-Host "The folder '$WatchFolder' doesn't exist — check `$WatchFolder above and try again." -ForegroundColor Red
    Read-Host "Press Enter to close"
    exit 1
}
if (-not (Test-Path $UploadedFolder)) {
    New-Item -ItemType Directory -Path $UploadedFolder | Out-Null
}

function Write-Log {
    param([string]$Message)
    $line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Write-Host $line
    Add-Content -Path $LogFile -Value $line
}

# A recorder is often still writing to the file for a few seconds after it
# appears — uploading too early would send a half-finished, broken file.
# This waits until the file's size stops changing (a simple, reliable "is it
# done being written" check that works for any recorder, not just one specific
# app) before treating it as ready.
function Wait-UntilFileIsReady {
    param([string]$Path, [int]$MaxWaitSeconds = 120)

    $lastSize = -1
    $stableCount = 0
    $waited = 0

    while ($waited -lt $MaxWaitSeconds) {
        if (-not (Test-Path $Path)) { return $false }
        $currentSize = (Get-Item $Path).Length

        if ($currentSize -eq $lastSize -and $currentSize -gt 0) {
            $stableCount++
            if ($stableCount -ge 3) { return $true }  # same size 3 checks in a row (6 sec)
        } else {
            $stableCount = 0
        }

        $lastSize = $currentSize
        Start-Sleep -Seconds 2
        $waited += 2
    }
    return $true  # give up waiting for "stable" after 2 min and try anyway
}

function Upload-Recording {
    param([string]$Path)

    $fileName = Split-Path $Path -Leaf
    Write-Log "Uploading $fileName ..."

    try {
        Add-Type -AssemblyName System.Net.Http
        $client  = New-Object System.Net.Http.HttpClient
        $client.Timeout = [TimeSpan]::FromMinutes(5)

        $content = New-Object System.Net.Http.MultipartFormDataContent
        $content.Add((New-Object System.Net.Http.StringContent($ApiToken)), "api_token")

        $recordedAt = (Get-Item $Path).LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss")
        $content.Add((New-Object System.Net.Http.StringContent($recordedAt)), "recorded_at")

        $fileBytes   = [System.IO.File]::ReadAllBytes($Path)
        $fileContent = New-Object System.Net.Http.ByteArrayContent(, $fileBytes)
        $content.Add($fileContent, "recording", $fileName)

        $response = $client.PostAsync($UploadUrl, $content).GetAwaiter().GetResult()
        $body     = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()

        if ($response.IsSuccessStatusCode) {
            Write-Log "  Success: $body"
            $destination = Join-Path $UploadedFolder $fileName
            Move-Item -Path $Path -Destination $destination -Force
            Write-Log "  Moved to Uploaded folder."
            return $true
        } else {
            Write-Log "  FAILED (HTTP $([int]$response.StatusCode)): $body"
            return $false
        }
    } catch {
        Write-Log "  ERROR: $($_.Exception.Message)"
        return $false
    } finally {
        if ($client) { $client.Dispose() }
    }
}

Write-Log "Watching '$WatchFolder' for new recordings. Leave this window open. Press Ctrl+C to stop."

# Catch up on anything already sitting in the folder from before this was started.
Get-ChildItem -Path $WatchFolder -File | Where-Object { $AllowedExtensions -contains $_.Extension.ToLower() } | ForEach-Object {
    if (Wait-UntilFileIsReady -Path $_.FullName) {
        Upload-Recording -Path $_.FullName | Out-Null
    }
}

# Then keep watching for new ones, for as long as this script keeps running.
$watcher = New-Object System.IO.FileSystemWatcher
$watcher.Path = $WatchFolder
$watcher.Filter = "*.*"
$watcher.IncludeSubdirectories = $false
$watcher.EnableRaisingEvents = $true

$action = {
    $path = $Event.SourceEventArgs.FullPath
    $ext  = [System.IO.Path]::GetExtension($path).ToLower()
    if ($AllowedExtensions -notcontains $ext) { return }
    if ($path -like "*\Uploaded\*") { return }

    Start-Sleep -Seconds 3  # let the "created" event settle before checking size
    if (Wait-UntilFileIsReady -Path $path) {
        Upload-Recording -Path $path | Out-Null
    }
}

Register-ObjectEvent -InputObject $watcher -EventName Created -Action $action | Out-Null

# Keep the script alive forever (until closed) so the watcher above keeps working.
while ($true) { Start-Sleep -Seconds 60 }

# ============================================================================
#  RUN AT STARTUP (optional)
#  So you don't have to remember to open this manually every day:
#    1. Press Win+R, type: shell:startup, press Enter — this opens a folder.
#    2. Right-click this .ps1 file -> Copy, then in that Startup folder,
#       right-click -> Paste shortcut.
#    3. From now on, it starts automatically (minimized) every time this PC
#       turns on and you sign in.
# ============================================================================
