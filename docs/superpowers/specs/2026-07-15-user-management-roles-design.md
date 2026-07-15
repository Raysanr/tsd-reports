# User Management & Roles — Design

## Problem

There is currently no concept of roles in this app: any authenticated user has identical
access to every page, including config pages (TSA Management, Product Management, Settings)
that affect shared data. There is also no way to add a teammate's account except through
public self-registration (`/register`), and no way to remove or restrict access for someone
without deleting their row directly in the database.

Two accounts exist today: `raysanred0@gmail.com` (real, signs in via Google) and
`lizzie28@example.com` (a leftover factory/seed row, no real person behind it). Neither is to
be deleted or have data altered beyond the role/active-status backfill described below.

## Roles

Four fixed roles, most to least privileged:

- **Super Admin** — full control. Can manage every account, including other Admins and Super
  Admins.
- **Admin** — full control over app config (TSA Management, Product Management, Settings) and
  User Management, but can only manage Normal/Guest accounts — cannot edit, deactivate, or
  change the role of an Admin or Super Admin account.
- **Normal User** — full access to every MAIN report page (Dashboard, Leads Report, TSA
  Performance, Analytics, RTS/Delivered), including triggering Dashboard "Sync". No access to
  any CONFIG page.
- **Guest** — same MAIN report pages as Normal User, but view-only: blocked from triggering
  Dashboard "Sync" (the only mutating action in the MAIN section). No access to any CONFIG
  page.

No dynamic/custom permission editing is needed — a plain `role` string column plus a small
role-check middleware is enough; not pulling in a package like spatie/laravel-permission for
four fixed tiers.

### Permission matrix

| | Super Admin | Admin | Normal | Guest |
|---|---|---|---|---|
| MAIN report pages | ✅ | ✅ | ✅ | ✅ (view-only) |
| Dashboard "Sync" | ✅ | ✅ | ✅ | ❌ |
| CONFIG pages (TSA Mgmt, Product Mgmt, Settings) | ✅ | ✅ | ❌ | ❌ |
| User Management page | ✅ | ✅ | ❌ | ❌ |
| Create/edit/deactivate a Normal or Guest account | ✅ | ✅ | ❌ | ❌ |
| Create/edit/deactivate an Admin or Super Admin account | ✅ | ❌ | ❌ | ❌ |

Safety guard: the system must refuse to deactivate or demote the **last active Super Admin**
account, so it's never possible to lock everyone out of the top tier. Enforced server-side in
the User Management controller, independent of who is making the request.

## Data model

New migration adds two columns to `users`:

- `role` — string, not null, default `'normal'`. One of `super_admin`, `admin`, `normal`,
  `guest` (validated in the controller, not a DB enum — consistent with how this codebase
  already handles fixed string sets like `tsa_shifts.team`).
- `is_active` — boolean, not null, default `true`.

A second migration (or a data step in the same one) backfills both existing rows to
`role = 'super_admin'`, `is_active = true`.

`User` model gains:
- `role`, `is_active` added to `$fillable` / casts (`is_active` => boolean).
- Helper methods: `isSuperAdmin()`, `isAdmin()`, `isAtLeastAdmin()` (super_admin or admin),
  `canManage(User $target)` (encapsulates the "Admin can't touch Admin/Super Admin" rule and
  the "can't touch yourself for role/deactivate purposes in a way that locks out the last
  Super Admin" guard).

## Auth changes

- **Public registration removed.** `/register` (GET + POST), `showRegister()`/`register()` in
  `AuthController`, `resources/views/auth/register.blade.php`, and the "Sign up" link on the
  login page are all deleted. Going forward, an account only ever gets created via User
  Management.
- **Google sign-in no longer auto-creates accounts.** In `handleGoogleCallback()`, the
  `$user ?? User::create([...])` fallback is removed. If no existing user matches by
  `google_id` or `email`, redirect back to `/login` with an error ("No account found for that
  Google email — ask an admin to add you first.") instead of silently creating one. This is
  the actual backdoor around "no sign-up" that removing `/register` alone wouldn't close.
- **Deactivated accounts are blocked at login**, both the password path (`AuthController::login`)
  and the Google path (`handleGoogleCallback`) — check `is_active` after the credential/Google
  match succeeds, before `Auth::login()`/session regeneration, and show a clear error instead
  ("This account has been deactivated.").
- **Deactivated mid-session is force-logged-out.** A new lightweight middleware
  (`EnsureUserIsActive`, or folded into the role middleware below) runs on every authenticated
  route: if the session's user has `is_active = false`, log them out and redirect to `/login`
  with the same message. Covers the case where an Admin deactivates someone who is currently
  browsing the app.
- Existing email/password login form is left untouched — no real account has a usable password
  today anyway (Google-created accounts get an unguessable random one at creation), so this is
  a no-op in practice, and it's out of scope to remove UI that wasn't part of what was asked.

## Route protection

A new `role:<allowed-roles>` middleware (e.g. `role:super_admin,admin`) gates route groups:

- CONFIG routes (`tsa-management.*`, `product-management.*`, `settings.*`, new
  `user-management.*`) → `role:super_admin,admin`.
- Dashboard sync (`POST /sync`) → `role:super_admin,admin,normal` (i.e. not guest).
- All other MAIN report routes stay on plain `auth` (every role can view them) plus the
  active-session check above.

Registered as a middleware alias in `bootstrap/app.php` alongside Laravel's built-ins.

## User Management page

New CONFIG sidebar item ("User Management"), visible only to Super Admin/Admin (the sidebar
already only renders CONFIG for authenticated users generally — this adds a role check on top).
Mirrors the existing modal-based add/edit pattern used by TSA Management and Product
Management:

- **Index**: table of all users — name, email, role badge, active/inactive status, last-role
  changed at a glance. Admin-tier viewers don't see Edit/Deactivate controls on
  Admin/Super-Admin rows (matches the permission matrix — not just hidden client-side, also
  enforced server-side).
- **Add User modal**: Name, Email (must be the person's real Google account email — this is
  how they'll actually sign in), Role dropdown. An Admin's dropdown only offers Normal/Guest; a
  Super Admin's offers all four.
- **Edit modal**: same fields, same role-dropdown constraint, plus the last-Super-Admin guard
  on role changes.
- **Deactivate/Reactivate**: a toggle per row (no hard delete anywhere in this feature), same
  last-Super-Admin guard on deactivation.
- New account rows have no usable password (same unguessable-random-string pattern as existing
  Google-created accounts) — the person becomes able to log in the moment they do "Sign in with
  Google" with the matching email; there's no separate "invite" or "activation" step to build.

## Out of scope

- Self-service profile editing: User Management is for managing *other* people's accounts, not
  your own — your own row in the table shows no Edit/Deactivate controls at all (checked
  server-side too, not just hidden). Combined with the last-Super-Admin guard, this removes any
  self-lockout path.
- Any notification/email to a newly-added user — no mailer is configured for real delivery
  (`MAIL_MAILER=log`), and it wasn't asked for. They find out they've been added the normal way
  (told directly), then just sign in with Google.
- Manual password-setting anywhere in this feature (explicitly declined in favor of Google-email-only).
- Audit log / history of role changes.

## Testing

- Feature tests for: role backfill migration, `role` middleware (each of the four
  CONFIG-adjacent boundaries), Google sign-in rejecting an unmatched email (no account
  created), login/Google-login rejecting a deactivated account, force-logout of an
  already-deactivated session, Admin blocked from editing/deactivating an Admin or Super Admin
  via both the controller (403) and via the UI (controls absent), and the last-active-Super-Admin
  guard (deactivate attempt and role-change attempt both rejected).
