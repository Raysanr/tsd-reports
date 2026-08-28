# Architecture Map

## Domain glossary
- **Pancake**: External POS/order platform (pos.pages.fm) this app syncs FROM. Source of truth for orders, leads, products, tags.
- **TSA**: Telesales Agent / rep — the people whose performance this app reports on. Has shifts, rest days, a daily lead cap.
- **RTS**: "Return to Seller" — reported in `RtsReportController` / `rts-report` route, tracking orders returned to the seller (logistics returns).
- **Upsell**: Order-level flag/amount tracked on `orders` (`is_upsell`, `cancelled_upsell`, `returned_upsell`, `restocking_upsell`, `is_upsell_on_voided_order`) — additional items sold on top of a base order.
- **Reconciliation**: Process of fixing/matching sync drift — see `ReconciliationRun` model, `Reconcile*` commands, and `unmatched-orders` / `sync-health` admin pages.
- **Round robin**: Lead-distribution scheme among TSAs in Call Tracker — see `RoundRobinState` model, `RoundRobinSetupController`.

## Data model (app/Models)
Order pipeline:
- `Order`, `Product`, `SyncRun` — core order sync from Pancake. `Order` has heavy tag/keyword matching to infer product and TSA (see `match_keyword`/`match_exclude_keyword` on `Product`, and several migrations fixing keyword conflicts — this matching is fragile, check migration history before changing matching logic).
- `ReconciliationRun`, `TagConflictReview` — repair/audit trail for sync drift.
- `PancakePageToken` — auth token(s) for the Pancake API, per page/account.

Lead / Call Tracker pipeline:
- `Lead`, `LeadActivity`, `LeadSyncRun` — leads synced from Pancake, separate sync cycle from orders.
- `CallEvent`, `CallRecording`, `CallRecordingHour` — call activity and recordings.
- `TsaStatusLog` — live status changes for agents (used by Call Tracker's TSA Status page).
- `RoundRobinState` — current state of lead round-robin distribution.

TSA / people:
- `User` — app users (has `role`, `is_active`, `tsa_id` linking a login to a TSA identity, `last_seen_at`).
- `TsaShift`, `TsaRestDay` — scheduling. `TsaShift` also carries Call Tracker columns (added later — see `add_call_tracker_columns_to_tsa_shifts_table`) and a `daily_lead_cap`.

Ops/meta:
- `Setting` — key/value app config (includes `pancake_api_key`, read via `Setting::get()` with a config fallback).
- `ActivityLog` — audit log, includes an actor snapshot (captures who did what even if the user is later changed/deleted).

## Sync flow (how data enters the app)
1. `app/Console/Commands/Sync*` commands (`SyncTodayOrders`, `SyncPancakeOrders`, `SyncPancakeLeads`, `SyncCallRecordings`) pull from `PancakeService` on a schedule (triggered via `/cron/run`, see root CLAUDE.md) or manually.
2. `TagParser` interprets Pancake order tags to help infer product/TSA matches.
3. Drift/mismatches get caught by `Reconcile*`, `Backfill*`, `LinkSeparateParcelOrders`, `ReinferOrderTeams` commands and surfaced in the `unmatched-orders` and `sync-health` admin pages.
4. `PancakeReconcile`, `ReconcileOrderStatuses`, `ReconcileCallTrackerRoster` — targeted reconciliation commands; check their `--help`/source before assuming what they cover.

## Controllers map
Main app (`app/Http/Controllers/`): Auth, Cron, Dashboard, LeadsReport, TsaPerformance, Charts, RtsReport, Search, TsaManagement, ProductManagement, SyncHealth, ActivityLog, UnmatchedOrders, Settings, UserManagement, CallEvent, CallRecording.

Call Tracker (`app/Http/Controllers/CallTracker/`): Dashboard, Analytics, Lead, CallLog, TsaStatus, RoundRobinSetup, Notification, Monitor, SyncHealth, TsaManagement — deliberately parallel to the main app's equivalents but scoped to Call Tracker's own data/UI.

## Where feature history lives
`docs/superpowers/plans/` and `docs/superpowers/specs/` contain prior planning docs and design specs (e.g. `tsa-rest-day-calendar`, `product-management`, `user-management-roles`, `pancake-sync-reconciliation`). Check these before redesigning something that may already have documented rationale.
