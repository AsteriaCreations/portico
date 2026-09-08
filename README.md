# Portico

A standalone member check-in and membership-management app for member clubs (built for one
of ~1,000 members), replacing a single shared spreadsheet. It solves one core problem:
**concurrent multi-user check-in with per-event payment tracking** — several volunteers
checking members in at the door simultaneously, on the same night, without stepping on
each other's data.

Full schema, fee logic, admission rules, and role permissions are specified in
[`docs/BLUEPRINT.md`](docs/BLUEPRINT.md) — read that first if you're changing business
logic. [`CONTRIBUTING.md`](CONTRIBUTING.md) has the working conventions for this codebase.

> **License note:** Portico is [AGPL-3.0-or-later](LICENSE). If you run a modified version
> as a network service, you must offer your users the modified source. See
> [License](#license) below.

## Stack

- Laravel 13, PHP 8.4+
- Filament v5 (admin panel + a custom check-in page) — TALL stack
- MariaDB / MySQL 8
- Pest for tests

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in `.env` for your local MariaDB, then:

```bash
php artisan migrate:fresh --seed
```

This seeds membership categories, a starter `event_types` list (Social, Pool Social, Class, Munch, Private Rental, Meeting, Special, Yoga), example subscription plans (regular $60 / $25 credit, pool $15 / full coverage — all editable at runtime via the Plans and Membership Settings screens), and a starter `comp_reasons` list (Presenter, Volunteer, Guest of a staff member). Every one of those is ordinary settings data you edit, rename, or deactivate to match your club. In `local`/`testing` the shipped `DatabaseSeeder` also creates an admin at `test@example.com` / `password`; outside those environments it instead requires `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env` and refuses to seed a guessable credential.

Visit `/admin` to log in. `/admin/check-in` is the primary door-facing screen; everything else (Members, Events, Subscriptions, Plans, Event Types, Users, reports) is gated to Manager/Admin per the roles table below.

### Sample data for local development

`migrate:fresh --seed` only seeds the minimal lookup data above — no members, events, or attendance, so the admin panel looks empty at first. For a populated demo state to click around in:

```bash
php artisan db:seed --class=DemoDataSeeder
```

Adds ~70 members across every category (including a few banned/watchlisted/under-21/incomplete-Prospective ones), a dozen events spanning the last two months plus two upcoming, ~200 attendance rows, subscriptions, vouchers, comp requests (including a freeform one), and a couple of register shifts — plus login-capable users for every role (`demo-<role>@example.com` / `password`, except the Showrunner login at `demo-showrunner@example.com`). Not run by default, and never touches the minimal seed set `DatabaseSeeder` provides — safe to skip entirely for a production install. It's additive, not idempotent: run it once on a fresh database, and to re-seed, run `migrate:fresh --seed` first (a second run on an already-seeded database just prints a notice and does nothing).

### Verify your install

After `migrate:fresh --seed`, with the app served (see [Production / LAN deployment](#production--lan-deployment), or `php artisan serve` for a quick local look):

- `/admin/login` shows the sign-in page, styled — no `npm` build is needed for the panel.
- Log in: `test@example.com` / `password` in `local`, or your `ADMIN_EMAIL` / `ADMIN_PASSWORD` otherwise.
- The **Dashboard** loads with an empty "in the building" widget.
- **Check-In Desk** shows the member/event pickers; **Analytics**, **Members**, and **Feature Flags** all render (empty until you add data, or run `DemoDataSeeder`).
- **Membership Settings** shows the tunables seeded from their defaults (eligibility threshold 5, probation 90 days, event window 15 min).

## Testing

```bash
./vendor/bin/pest
# or
php artisan test          # also clears config first

./vendor/bin/pest tests/Feature/PricingServiceTest.php --filter=some_test
```

Pest itself always runs against an in-memory SQLite database (`phpunit.xml`'s own `<env>` overrides), never your real `.env`. `.env.testing` exists separately for a different case: running an `artisan` command directly with `--env=testing` (e.g. to sanity-check a migration by hand) — without it, Laravel silently falls back to `.env` and points at your real dev database. `.env.testing` targets a disposable `database/testing.sqlite` instead; delete that file freely, `migrate:fresh` recreates it.

Coverage focuses on the two engines that encode house policy — `AdmissionPolicy` (who gets in) and `PricingService` (what they pay) — plus the role-authorization boundary between Door/Manager/Admin. See `CONTRIBUTING.md`'s Testing section for the specific cases these are expected to cover.

```bash
php vendor/bin/pint        # code style
```

## Roles

Seven nested tiers — `Showrunner ⊂ Volunteer ⊂ DM ⊂ Door ⊂ Manager ⊂ Admin ⊂ Owner` — enforced with plain Laravel policies/gates keyed on `users.role` (`app/Policies/`, plus gates like `view-sensitive-member-fields`, `grant-manager-subscription-perk`, `grant-event-comp`, `record-departures`, and several feature-flag-backed gates in `AppServiceProvider`). No permissions package. These names are the code's own case names, not fixed display text — a Manager+ can relabel any of the seven per install (`/admin/role-labels`) without touching the hierarchy or a single gate; Showrunner and DM ship with generic defaults ("Event Lead"/"Monitor") precisely so a club that prefers that jargon can alias it right back.

| Capability | Showrunner | Volunteer | DM | Door | Manager | Admin | Owner |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| Submit an Admin-approved comp request for the one event they're assigned to run | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Record building departures (Dashboard headcount or a named patron on Active Patrons) | | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| See a behavior note's full text on Active Patrons without knowing who wrote it | | | ✓ | ✓ | ✓ | ✓ | ✓ |
| Check members in, take payment, see the admit decision | | | | ✓ | ✓ | ✓ | ✓ |
| Complete a Prospective's name/email/DOB, promote to Irregular | | | | ✓ | ✓ | ✓ | ✓ |
| Collect a subscription payment *during check-in* (member must be subscription-eligible; fixed at the current plan price) | | | | ✓ | ✓ | ✓ | ✓ |
| Redeem voucher credit at check-in (own or another member's balance; partial amounts allowed) | | | | ✓ | ✓ | ✓ | ✓ |
| See watchlist/ban reasons, notes, raw DOB | | | | | ✓ | ✓ | ✓ |
| Edit members, browse/edit any subscription record, view events, manage plans/event types/comp reasons, reports | | | | | ✓ | ✓ | ✓ |
| Browse the voucher ledger | | | | | ✓ | ✓ | ✓ |
| Waive one visit's entry fee at check-in (per-event comp, e.g. a House Sub) | | | | | ✓ | ✓ | ✓ |
| Manage an event's admin-run Prepay List (single add or bulk upload) | | | | | ✓ | ✓ | ✓ |
| Manage an event's admin-run Comp List | | | | | ✓ | ✓ | ✓ |
| Grant the monthly free regular subscription (self or gift to a member) † | | | | | ✓ | | ✓ |
| Create, edit, or delete events (incl. their fees, start/end times, and door-prepay flag), manage volunteer accounts, issue/correct voucher credit | | | | | | ✓ | ✓ |

† Deliberately not monotonic — Admin sits between Manager and Owner in rank but can't grant this perk. See `docs/BLUEPRINT.md` "Monthly Manager & Owner Subscription Perk".

Showrunner's entire surface is the comp-request page, and Door's entire surface is the check-in page — every other resource returns 403 for those roles. Member names shown there are always `preferred_name` (falling back to `username`); legal name and email never appear outside the Members resource itself. Every Manager+-only capability above is enforced **server-side**, not just hidden in the UI — e.g. a forged check-in payload from a Door session can't apply a per-event comp or grant the subscription perk.

This table covers the roles' distinguishing capabilities, not an exhaustive feature-by-feature matrix — for the full, continuously-updated picture (feature flags, per-event payouts, member skill tracking, and everything else added since), see `CONTRIBUTING.md` and the commit history.

**Subscription eligibility** (`Member::isSubscriptionEligible()`) gates who can subscribe at all, independent of role — including the monthly Manager & Owner perk, which waives price but not this rule: 5+ attended events all-time, or `subscription_eligible` manually set on the member (Manager+, via the Members resource, or set automatically by the historical import below). Threshold is `config('membership.subscription_eligibility_threshold')`, default 5.

## Production / LAN deployment

Everything below (Backups, Post-event notifications, Comp-reward vouchers) assumes a single dev machine. Standing this up as a venue's network-reachable check-in server — a dedicated workstation on the club's LAN, multiple devices checking members in at once — is a separate, more involved process: network setup (DHCP reservation, local DNS, VLAN / client-isolation, TLS), a from-scratch production install, and the scheduled jobs below all registered on the real box. See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for the full checklist, plus `scripts/apache/` for a scripted Apache + mod_php setup on Windows.

## Backups

Nightly `mysqldump`, written to a folder that gets copied off the machine on its own (OneDrive/Dropbox/a network share) — never just left on the same disk as the live database.

```bash
php artisan backup:database
```

Writes a gzipped dump to `daily/` under the configured destination, additionally copies it to `monthly/` on the last day of the month (kept indefinitely — `daily/` is pruned to the last `BACKUP_RETENTION_DAYS`, default 30). Configure in `.env`:

```
BACKUP_DESTINATION="C:/Users/<you>/OneDrive/portico-backups"   # defaults to that path if unset
BACKUP_RETENTION_DAYS=30
MYSQLDUMP_PATH="C:/Program Files/MariaDB 12.3/bin/mysqldump.exe"     # set this if mysqldump isn't on PATH — find yours under "C:/Program Files/MariaDB */bin/"
```

**Register the nightly run in Windows Task Scheduler** (this app is never run via `php artisan serve` in production — see `CONTRIBUTING.md`):

1. Task Scheduler → Create Task.
2. General: run whether the user is logged on or not; run with the account that owns this project's `.env`.
3. Triggers: New → Daily, at a low-traffic time (e.g. 3:00 AM).
4. Actions: New → Start a program → `scripts\run-backup.bat` in this project's root, with **Start in** set to the project root.
5. Save, then right-click the task → Run, to confirm it works before trusting it to run unattended.

Output is appended to `storage/logs/backup.log` — check there if a night's backup didn't land.

**Test a restore periodically** — don't wait for a real emergency to find out a backup is bad:

```bash
gunzip -k path\to\backup.sql.gz
mysql -u root -p -e "CREATE DATABASE restore_test"
mysql -u root -p restore_test < path\to\backup.sql
# spot-check a few tables, then drop it
mysql -u root -p -e "DROP DATABASE restore_test"
```

One expected quirk when you diff a restore against the live database: `command_runs` will be one row short. `mysqldump` snapshots the database *before* `backup:database` writes its own "I just succeeded" row at the end of the same run, so that row can never appear in its own backup. Everything else should match exactly, `migrations` included.

## Post-event notifications

Emails and in-app-notifies the Owner(s), plus the event's own assigned Showrunner if it has one, shortly after an event ends — attendance count and revenue, nothing more. Admin isn't pushed anything; the same numbers are visible live on the event's own edit page once it's ended.

```bash
php artisan events:notify-ended
```

Idempotent — `events.ended_notification_sent_at` is set once an event's been processed, so re-running (or running hourly) never double-sends. Needs a real mail transport configured in `.env` (`MAIL_MAILER=log` by default, which just writes to the log instead of actually delivering anything).

**Register the hourly run in Windows Task Scheduler**, same as the nightly backup above:

1. Task Scheduler → Create Task.
2. Triggers: New → Daily, repeat every 1 hour for a duration of 1 day.
3. Actions: New → Start a program → `scripts\run-notify-event-ended.bat` in this project's root, with **Start in** set to the project root.
4. Save, then right-click the task → Run, to confirm it works before trusting it to run unattended.

Output is appended to `storage/logs/notify-event-ended.log`.

## Comp-reward vouchers

`php artisan vouchers:grant-comp-rewards` grants a comp reason's configured voucher (`comp_reasons.grants_voucher_amount`) to every comped, arrived attendee once their event's `ends_at` has passed — e.g. a "Presenter" comp reason set to $25. Idempotent — never grants twice for the same attendance row.

```bash
php artisan vouchers:grant-comp-rewards
```

This app has no Laravel scheduler (same as backups above) — register it in Windows Task Scheduler on a timer (hourly is a reasonable default; adjust as needed), via `scripts\run-vouchers-grant-comp-rewards.bat`, the same way `backup:database` already is. Requires the system user seeded by `DatabaseSeeder` (`system@portico.internal`, `active = false` — it exists purely as the ledger's `recorded_by`, and can never actually log in).

## Project status

Built through the blueprint's step-by-step build order:

1. ✅ Migrations + seeders
2. ✅ Filament auth + panel; Members resource
3. ✅ Events, Subscriptions, Plans, Event Types resources
4. ✅ Attendance relation managers + `PricingService`
5. ✅ `AdmissionPolicy` + the check-in page
6. ✅ Role policies + reporting widgets
7. ✅ Backups, README, `.env.example`

The app has grown substantially since via many incremental slices — vouchers, guests, prepay / building-capacity, ban exceptions, per-event comp, feature flags, showrunner / instructor payouts, membership settings, member skill tracking, analytics, and more. The commit history is the detailed record; `CONTRIBUTING.md` covers the conventions and the architecture rules that hold across all of it.

Portico ships a **feature-flag mechanism** (`/admin/feature-flags`, Manager+) so a club that doesn't want vouchers, add-ons, the pool component, prepay, register shifts, the showrunner comp-request pipeline, the Manager/Owner perk, suspensions, or patron notes can turn each off. Flags stop new writes; they never hide data already collected.

## License

Licensed under the [GNU Affero General Public License v3.0 or later](LICENSE) (AGPL-3.0-or-later). The AGPL's key difference from the plain GPL: running a modified version of this app on a server and letting others use it over a network counts as distribution, so the modified source must be made available to those users too. The admin panel footer carries a "Source code" link for exactly this reason — point it at your fork if you deploy a modified version.
