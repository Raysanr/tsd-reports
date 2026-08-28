# TSD Reports — Project Guide

Laravel 12 (PHP 8.2) internal ops app: syncs order/lead data from **Pancake** (pos.pages.fm, a Facebook/Instagram-commerce POS platform) and turns it into performance reports for TSAs (Telesales Agents/reps), plus a bundled **Call Tracker** module for call/lead management.

Read `docs/ARCHITECTURE.md` for the full directory map, data model, and domain glossary before making non-trivial changes — it's kept short here on purpose. Past feature work (specs + implementation plans) lives in `docs/superpowers/` — check there before re-deriving a decision that's already documented.

## Quick facts
- Auth: session login + Google OAuth (`AuthController`, Socialite). Roles: `super_admin`, `admin`, `normal`.
- DB: SQLite locally (`.env.example`); confirm prod driver before assuming.
- Deploy: Render (`render.yaml`). `/cron/run` is hit by an external pinger, protected by `CRON_SECRET`, not session auth.
- Frontend: Vite 7 + Tailwind 4 + Chart.js. No SPA framework — server-rendered Blade views.
- Tests: PHPUnit, `tests/` — run before considering a change done.

## Where things live (fast lookup)
- Routes: `routes/web.php` — one file, organized by auth/role groups.
- Controllers: `app/Http/Controllers/` (main app) and `app/Http/Controllers/CallTracker/` (Call Tracker module — parallel structure, separate namespace).
- Business logic that talks to Pancake: `app/Services/PancakeService.php` (API client) and `app/Services/TagParser.php`.
- Scheduled/manual sync jobs: `app/Console/Commands/` — `Sync*` (pull from Pancake), `Reconcile*`/`Backfill*` (data repair), `Expire*` (housekeeping).
- Data models: `app/Models/` — see `docs/ARCHITECTURE.md` for what each one represents.
- Middleware: `app/Http/Middleware/` — `EnsureUserIsActive`, `EnsureUserHasRole`, `TrackLastSeen`.

## Known gotchas (don't relearn these the hard way)
- Login POST is rate-limited via a named `throttle:login` limiter (5/min, email+IP) defined in `AppServiceProvider` — this was a real fix for unlimited password guessing, don't remove it.
- `active` middleware force-logs-out a user deactivated mid-session; `last-seen` stamps `last_seen_at`, shared by TSD Reports and Call Tracker for the same "online" indicator — don't duplicate this per-module.
- `/team-report` is a permanent redirect to `/leads-report` — old bookmarks depend on it.
- Call Tracker has its own `TsaManagementController` and `SyncHealthController`, separate from the main app's — don't assume there's only one of each.

## Persistent memory (outside this repo)
This user keeps a second-memory Obsidian vault synced via Claude Code hooks (global `~/.claude/CLAUDE.md` auto-loads it). The vault has a linked note graph for this project starting at `TSD Reports Hub` (path: `Desktop/Claude obsidian/Claude/Memory/Projects/TSD/TSD Reports Hub.md`). If asked to "check memory" or "update memory," that's where it lives — not in this repo.

## Live status (check before starting work)
In the Obsidian vault, also check:
- `Memory/Projects/TSD/Current Status.md` — what's actively broken/in-progress right now
- `Memory/Projects/TSD/Tasks.md` — open task list
- `Memory/Projects/TSD/Decisions.md` — why past decisions were made, so you don't undo them unknowingly
Update these when you finish meaningful work, not just the codebase docs above.

## New task/objective protocol (do this automatically, don't wait to be asked)
Whenever a new task or objective comes up in conversation — whether Raysan states it directly or it falls out of a discussion — log it without being asked each time:

- **Small, immediate task** (clear scope, doing it now or next): add a line directly to `Memory/Projects/TSD/Tasks.md`. No bridge needed.
- **Larger or not-yet-scoped objective** (needs research, spans multiple steps, or isn't ready to start yet): create a child note under `Memory/Projects/TSD/Objectives/` using `_Template.md`, add one row to `Objectives/Upcoming Objectives.md` (the compact bridge/index), and use the child note as scratch space for ideas/approach as the objective gets scoped. When it's ready to work on, promote it into `Tasks.md` and mark the bridge row `→ Tasks`.

Judgment call, not a rigid rule — if genuinely unsure which bucket, default to the lighter one (Tasks.md line) rather than over-creating objective notes for small things.

## Memory file structure (standing rule)
Keep memory notes split by purpose, never merged into one growing file — Tasks.md, Current Status.md, Decisions.md, and Objectives/ child notes each stay separate and small. When updating memory, edit the file that matches the purpose (status → Current Status.md, a decision → Decisions.md, a task → Tasks.md or Objectives/, per the protocol above) — don't create a new catch-all file, and don't dump everything into one file for convenience. This keeps each session read only what's relevant instead of one large file every time.

## Session hygiene
Long sessions (>150k context) and heavy skills (e.g. webapp-testing) are the main token cost drivers, confirmed via /usage breakdown on 2026-08-28 — not memory files, which cost <0.1% of context. When switching to a genuinely different task, prefer starting fresh over continuing an old long session. Don't auto-run webapp-testing (or similarly heavy skills) after every small change — only when actually asked to test.

## Token hygiene
Don't read files under `Memory/Claude Code Log/` in the Obsidian vault unless explicitly asked — that folder is an append-only prompt/response history meant for the periodic scheduled review, not for context loading. It grows daily and reading it wholesale wastes tokens for no benefit.

<!-- rtk-instructions v2 -->
# RTK (Rust Token Killer) - Token-Optimized Commands

## Golden Rule

**Always prefix commands with `rtk`**. If RTK has a dedicated filter, it uses it. If not, it passes through unchanged. This means RTK is always safe to use.

**Important**: Even in command chains with `&&`, use `rtk`:
```bash
# ❌ Wrong
git add . && git commit -m "msg" && git push

# ✅ Correct
rtk git add . && rtk git commit -m "msg" && rtk git push
```

## RTK Commands by Workflow

### Build & Compile (80-90% savings)
```bash
rtk cargo build         # Cargo build output
rtk cargo check         # Cargo check output
rtk cargo clippy        # Clippy warnings grouped by file (80%)
rtk tsc                 # TypeScript errors grouped by file/code (83%)
rtk lint                # ESLint/Biome violations grouped (84%)
rtk prettier --check    # Files needing format only (70%)
rtk next build          # Next.js build with route metrics (87%)
```

### Test (60-99% savings)
```bash
rtk cargo test          # Cargo test failures only (90%)
rtk go test             # Go test failures only (90%)
rtk jest                # Jest failures only (99.5%)
rtk vitest              # Vitest failures only (99.5%)
rtk playwright test     # Playwright failures only (94%)
rtk pytest              # Python test failures only (90%)
rtk rake test           # Ruby test failures only (90%)
rtk rspec               # RSpec test failures only (60%)
rtk test <cmd>          # Generic test wrapper - failures only
```

### Git (59-80% savings)
```bash
rtk git status          # Compact status
rtk git log             # Compact log (works with all git flags)
rtk git diff            # Compact diff (80%)
rtk git show            # Compact show (80%)
rtk git add             # Ultra-compact confirmations (59%)
rtk git commit          # Ultra-compact confirmations (59%)
rtk git push            # Ultra-compact confirmations
rtk git pull            # Ultra-compact confirmations
rtk git branch          # Compact branch list
rtk git fetch           # Compact fetch
rtk git stash           # Compact stash
rtk git worktree        # Compact worktree
```

Note: Git passthrough works for ALL subcommands, even those not explicitly listed.

### GitHub (26-87% savings)
```bash
rtk gh pr view <num>    # Compact PR view (87%)
rtk gh pr checks        # Compact PR checks (79%)
rtk gh run list         # Compact workflow runs (82%)
rtk gh issue list       # Compact issue list (80%)
rtk gh api              # Compact API responses (26%)
```

### JavaScript/TypeScript Tooling (70-90% savings)
```bash
rtk pnpm list           # Compact dependency tree (70%)
rtk pnpm outdated       # Compact outdated packages (80%)
rtk pnpm install        # Compact install output (90%)
rtk npm run <script>    # Compact npm script output
rtk npx <cmd>           # Compact npx command output
rtk prisma              # Prisma without ASCII art (88%)
rtk uv run <cmd>        # Compact uv project command output
```

### Files & Search (60-75% savings)
```bash
rtk ls <path>           # Tree format, compact (65%)
rtk read <file>         # Code reading with filtering (60%)
rtk grep <pattern>      # Search grouped by file (75%). Format flags (-c, -l, -L, -o, -Z) run raw.
rtk find <pattern>      # Find grouped by directory (70%)
```

### Analysis & Debug (70-90% savings)
```bash
rtk err <cmd>           # Filter errors only from any command
rtk log <file>          # Deduplicated logs with counts
rtk json <file>         # JSON structure without values
rtk deps                # Dependency overview
rtk env                 # Environment variables compact
rtk summary <cmd>       # Smart summary of command output
rtk diff                # Ultra-compact diffs
```

### Infrastructure (85% savings)
```bash
rtk docker ps           # Compact container list
rtk docker images       # Compact image list
rtk docker logs <c>     # Deduplicated logs
rtk kubectl get         # Compact resource list
rtk kubectl logs        # Deduplicated pod logs
```

### Network (65-70% savings)
```bash
rtk curl <url>          # Compact HTTP responses (70%)
rtk wget <url>          # Compact download output (65%)
```

### Meta Commands
```bash
rtk gain                # View token savings statistics
rtk gain --history      # View command history with savings
rtk discover            # Analyze Claude Code sessions for missed RTK usage
rtk proxy <cmd>         # Run command without filtering (for debugging)
rtk init                # Add RTK instructions to CLAUDE.md
rtk init --global       # Add RTK to ~/.claude/CLAUDE.md
```

## Token Savings Overview

| Category | Commands | Typical Savings |
|----------|----------|-----------------|
| Tests | vitest, playwright, cargo test | 90-99% |
| Build | next, tsc, lint, prettier | 70-87% |
| Git | status, log, diff, add, commit | 59-80% |
| GitHub | gh pr, gh run, gh issue | 26-87% |
| Package Managers | pnpm, npm, npx | 70-90% |
| Files | ls, read, grep, find | 60-75% |
| Infrastructure | docker, kubectl | 85% |
| Network | curl, wget | 65-70% |

Overall average: **60-90% token reduction** on common development operations.
<!-- /rtk-instructions -->