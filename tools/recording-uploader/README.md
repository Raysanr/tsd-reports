# Automatic Call Recording Uploader

Watches a folder on a TSA's PC and automatically uploads every finished
recording to Call Tracker — no manual upload step. This is the second half
of call recording setup; the first half (actually capturing the audio) is
Phone Link + a free recorder, described below.

## What you need (all free)

1. **Phone Link** (built into Windows, or free from the Microsoft Store) —
   mirrors the TSA's Android phone to their PC, including call audio.
2. **A free system-audio recorder** — [OBS Studio](https://obsproject.com)
   is the recommended one: free, records only system audio (not the room),
   and can be set to auto-save each recording as its own file.
3. This folder's `upload-recordings.ps1` script — the auto-upload piece.

## Setup, in order

1. **Set up Phone Link** on the TSA's PC and pair it with their phone
   (Settings → Bluetooth & devices → Phone Link, or search "Phone Link" in
   the Start menu). Confirm calls show up on the PC when the phone rings.
2. **Install OBS Studio**, add an Audio Output source, and set it to record
   only that source — not the screen. In Settings → Output, set the
   recording format to `mp3` or `wav` (both are accepted), and set the
   recording folder to somewhere easy to remember, e.g.
   `C:\Users\<name>\Videos\Call Recordings`.
3. **Get the TSA's API token** — in Call Tracker, go to **Call Rotation**,
   find the TSA, click "Generate token" under "Phone call automation" (the
   same token used for the MacroDroid call-log setup above it), and copy it.
4. **Edit `upload-recordings.ps1`** in this folder — open it in Notepad, and
   fill in the three lines near the top:
   - `$ApiToken` — paste the token from step 3.
   - `$WatchFolder` — the exact recording folder from step 2.
   - `$UploadUrl` — leave as-is unless told otherwise.
5. **Run it**: right-click the file → "Run with PowerShell". Leave the
   window open — it keeps watching in the background.
6. **Test it**: make one real call, hang up, let OBS finish saving the
   recording. Within a few seconds it should upload automatically and show
   up in Call Tracker's **Call Recordings** page. The script's own window
   also prints what it's doing.

## Making it start automatically

So nobody has to remember to open it every morning: press `Win+R`, type
`shell:startup`, press Enter. In the folder that opens, right-click →
New → Shortcut, and point it at `upload-recordings.ps1`. From then on it
starts automatically whenever that PC turns on and someone signs in.

## If something isn't working

The script writes everything it does to `upload-log.txt`, inside the same
folder it's watching — check that first. Common issues:

- **Nothing uploads at all** — check `$WatchFolder` in the script points at
  the exact folder OBS actually saves into.
- **"FAILED (HTTP 401)"** — the API token is wrong or was regenerated since
  — get the current one from Call Rotation and update the script.
- **"FAILED (HTTP 422)"** — the file format isn't one of mp3/wav/m4a/mp4/
  mkv/ogg/webm — check OBS's output format setting.
- **Files pile up unuploaded** — check the PC's internet connection; the
  script leaves failed files in place and will retry them next time it's
  restarted.
