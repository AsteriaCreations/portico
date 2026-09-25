# Changelog

All notable changes to Portico are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/).

Versions are annotated git tags on `main` (`vMAJOR.MINOR.PATCH`). While Portico is `0.x`, a
minor release may include a database migration and small breaking changes; a patch release
is fixes only.

## [Unreleased]

### Added

- The admin panel's language is now a per-installation setting (Membership Settings →
  Language, stored as `membership_settings.locale`; blank keeps the server default). Only
  languages with a `lang/{code}.json` translation file are offered, so English is the only
  choice until one is added. Translation keys are the English text (`__('Check-In Desk')`),
  so English needs no file. Conversion is incremental: the coverage/role/capability/payout
  enum labels, `AdmissionPolicy` decision messages, and the whole Check-In Desk (page,
  notifications, Blade views, roster and cash-box summary) and the Members, Categories, Events,
  Subscriptions, Plans, Vouchers, Add-Ons, payment methods, registers, comp reasons, paperwork
  types, skills, cleaning tasks, payout tiers and Users screens, the dashboard
  widgets, the remaining admin pages, and every screen's "How to use" panel are translatable, as are
  the event-ended email and its notification (rendered in the installation's language even
  when sent by the scheduled `events:notify-ended` command) and the user-guard error messages.
  Hand-written dates now use `translatedFormat()` so month names follow the language too.
  A guard test fails the build on a new screen missing its translation trait or shipping an
  unwrapped prose string. Names a club types itself (categories, plans, comp reasons, ...) are
  never translated. See CONTRIBUTING.md for the convention.
- Every Filament form field, table column, filter and action label is now looked up in the
  translator (one global default in `AppServiceProvider`), whether set with `->label()` or
  derived from the attribute name; Resource, Page and relation-manager model/navigation
  labels and titles do the same through small traits in `App\Filament\Concerns`, and the
  panel's navigation groups translate lazily so they follow the installation's language.

- Age of majority and the check-ID/no-alcohol flag age (18/21 by default) are now
  club-configurable on the Membership Settings page, instead of hardcoded in
  `AdmissionPolicy` — both are jurisdiction-specific. Setting them equal disables the
  flag outcome entirely.
- Currency is now club-configurable on the Membership Settings page (a 3-letter ISO 4217
  code, default `USD`) instead of every dollar figure assuming USD. Applies everywhere a
  money figure is shown — the check-in desk's live totals and notifications, every
  Analytics widget, and every `->money()` table column across the admin panel (set via one
  panel-wide default rather than touching each column).

### Changed

- Events screen: the list gains a start-time column, an "Arrived" count (arrivals only,
  not prepays awaiting arrival) and an Upcoming / Past filter. An unnamed event is titled
  by its date and type ("Aug 1, 2026 — Social") instead of a bare "Event", in page titles,
  breadcrumbs, global search and the check-in desk's picker (new `Event::label()`). Editing
  an event that already has attendance shows a notice that recorded payments keep their
  price and subscription month. The ended-event Summary runs its query once, not three
  times.

- The check-in desk's recording transaction (subscriptions bought with the visit, the
  priced attendance row, add-ons, voucher draw, and the capacity and add-on-limit
  re-checks under the admission lock) moved out of the Check-In page into
  `App\Services\CheckInService`, so it can be tested and reused without Livewire. It takes
  the staff member as an argument instead of reading `auth()`. No behavior change; the
  page's existing tests pass unmodified, and `CheckInServiceTest` covers the service
  directly.
- The showrunner and instructor payouts, the event summary (event-ended email,
  notification and the event page's summary), and the Monthly Revenue chart now sum and
  compare money in integer cents, finishing the move off PHP floats for money.
  `ShowrunnerPayoutResult` and `InstructorPayoutResult` fields are `*Cents` ints, and
  `EventSummaryService` returns `revenue_cents`. **A percentage payout is now rounded once,
  to the cent, half-up** (new `Cents::percentOf()`); it was never rounded before, and only
  the display rounded it, half-even. So a payout landing exactly on a half cent shows 1¢
  more than it used to (15% of $10.30 is $1.545: now $1.55, previously shown as $1.54).
  `membership_settings.default_opening_float` is cast `decimal:2` like every other money
  column.
- A new `MoneyArithmeticGuardTest` fails the build on a `(float)` cast or a money-named
  `float` parameter, property or return type in `app/Services` / `app/Support`, with a
  commented allowlist for the safe ones; CONTRIBUTING.md gains the matching money rule. The
  event, comp-reason and prepay-list importers and the register drop/payment/count writes
  now store amounts via `Cents::toDecimal()`.
- `docs/DEPLOYMENT.md` is expanded with lessons from a real production go-live, and the
  network guidance is now router-agnostic: a vendor-neutral checklist (DHCP reservation,
  local DNS, isolated staff network, no WAN port-forward, verify segmentation) with UniFi
  as a worked example. Also new: which account should own the install, the `php.ini`
  gotchas (absolute `extension_dir`, the `bcmath`/`exif`/`zip` lines the Windows template
  lacks), the Apache VS17-vs-VS18 pairing note, Node and MariaDB notes, `schtasks.exe`
  examples, fuller mkcert/TLS steps, and a post-launch smoke-test checklist (§8). Node is
  documented as optional: the admin panel doesn't use the Vite build, so it's needed only
  for `deploy.ps1`'s default `npm` step (`-SkipNpm` skips it).
- `README.md` brought up to date: current release (`v0.2.0`), the full feature-flag list,
  configurable currency and check-in display name, the complete seed set, the
  `SYSTEM_USER_EMAIL` override, the optional `upstream:check` job and one-command deploy
  tooling, and links to `docs/FEATURES.md`, `docs/CONFIGURING.md` and `CHANGELOG.md`.
- The README's Roles table audited against the policies and gates. Corrected: the
  comp-request submission row (Showrunner only, not every role), the "Door's entire surface
  is the check-in page" claim, the behavior-note visibility wording, and the subscription
  eligibility paragraph (the threshold is a Membership Settings value; there is no import
  command in Portico). Added the capabilities the table omitted: visit/behavior notes,
  register shifts and miscellaneous payments, Analytics and the Manager-level settings
  screens, comp-request approval, Skill assignment, per-user capabilities, the Technical
  and Upstream Updates pages, and the Owner-only club name.

### Fixed

- The check-in desk could record a prepayment for any past or future event through a
  forged `event_id`, as long as prepay was switched on for the club: the server-side
  re-check looked at the club setting, not the event's own "Allow prepay ahead of the
  door". It now requires both, matching what the event picker offers.
- An event's entry and pool fees accepted negative amounts and silently rounded a third
  decimal; both must now be zero or more, to the cent. An event's start could be on a
  different day from its event date, surfacing it at the desk on two nights; the start
  must now be on the event date (the end may still run past midnight). The events bulk
  upload skips and logs rows breaking either rule.

- A check-in that subscription credit plus a voucher covered exactly could still be
  charged the payment method's transaction fee (e.g. $20.30 entry, $20.00 credit, $0.30
  voucher: a $0.50 card fee recorded on a visit with nothing due). Pricing subtracted in
  PHP floats and left 7.2e-16 "due", which counted as money changing hands. Pricing and
  the check-in transaction now work in integer cents: `PriceBreakdown`, `AddOnPriceLine`
  and `CheckInResult` fields are `*Cents` ints, `PricingService::applyVoucher()` takes
  cents, and amounts are written to the database as exact decimal strings. **After
  upgrading, run `php artisan attendance:find-stray-fees`**: a new read-only report
  listing past check-ins charged a fee with nothing due, so the club can decide on
  refunds. It changes nothing.
- A register drawer that balanced to the cent could be reported as over or short by $0.00
  (e.g. $50.00 opening + $0.05 cash − $20.00 dropped, counted at $30.05), and counted as a
  "shift with variance" on the weekly widget: the expected-cash sums were done in PHP
  floats, leaving variances like 3.6e-15. About one in seven balanced drawers was affected.
  `RegisterShiftService` now sums in integer cents (new `App\Support\Cents` helper), and
  the desk's close-box message and the variance widget compare cents. Variance is never
  stored, so past shifts now show correctly too. First of the changes moving money
  arithmetic off floats.
- An add-on with a per-night limit (`max_per_night`, e.g. a single rentable room) could
  be sold past that limit by two registers at once. The desk's picker greyed a sold-out
  add-on out, but only for sales committed before the page last rendered. The limit is now
  re-checked inside the check-in transaction, under the same admission lock as venue
  capacity. The register that loses the race gets a "sold out for tonight" warning and
  nothing is recorded or charged; the add-on is not silently dropped from the sale.
- Two registers could admit two different members into the last spot under the venue
  capacity: each counted occupancy before either had committed. The capacity check now
  runs inside the check-in transaction, behind a row lock every admission takes
  (`CapacityService::lockForAdmission()`), so the second waits for the first and then sees
  it. The register that loses the race gets a "Building at capacity" warning, not an error
  page, and nothing is recorded or charged.
- A check-in rolled back by any database integrity error was reported as "Already checked
  in", even when no attendance row existed — for example, when another register sold the
  same member the same subscription month at the same moment. The member could then be
  waved through with nothing recorded. That message now appears only when the attendance
  row really exists; otherwise the desk says the check-in was not saved.
- CI now also runs the full Pest suite on MariaDB, not only migrations and seeds: SQLite
  has no row locks, so no locking guarantee was ever actually tested. The new
  `CheckInConcurrencyTest` stages real two-register races there.

### Security

- Repository hardening ahead of wider public use: `main` and `v*` release tags are now
  protected by rulesets (PR + passing CI required, no force-push or deletion, squash
  merge only); CI actions are pinned to commit SHAs with an explicit `contents: read`
  token scope; Dependabot version updates (composer, npm, GitHub Actions) and a CodeQL
  workflow (JS + workflow files — CodeQL has no PHP support) are added; and a
  `CODEOWNERS` file covers the authorization and deploy-script paths.
- `/storage/imports` is now gitignored, as a drop folder for files fed to an importer
  command (a roster export, say). Those files hold member names, emails and dates of
  birth, and unlike `storage/app` the folder wasn't ignored, so a broad `git add -A` could
  have staged one.

## [0.2.0] — 2026-09-17

### Added

- Forced password change: an Admin+ can flag a user account ("Require password change at
  next login" on the Users form) so its next sign-in is redirected to a dedicated
  change-password screen before anything else in the panel is reachable.
- `scripts/deploy.ps1` automates pulling down an update on a Windows box: stop the web
  server, `git pull --ff-only`, reinstall dependencies, migrate, rebuild caches, restart —
  see `docs/DEPLOYMENT.md` §7.
- Upstream update visibility: an opt-in `upstream:check` scheduled command periodically
  `git fetch`es a configured remote, and a new Admin+ "Upstream Updates" page shows which
  commits are pending — locally, no network call per page load. A "Run update now" button
  can trigger `deploy.ps1` via an already-registered Windows Scheduled Task, fully
  detached from the request. Both are inert and invisible until a fork configures them.
- The Check-In Desk's member display name (greeting, roster, voucher labels, sponsor
  note) is now club-configurable — `preferred_name`, full name, or `username` — instead
  of hardcoded to preferred name.
- Members table gains a "Require paperwork" bulk action, so a club rolling out a new
  waiver can flag a selected group as needing it re-confirmed, instead of toggling each
  member individually — each flip is still logged to the member status audit trail.
- Flat, non-subscribable add-ons (room rental, sleepover, …) can now be bound to specific
  events instead of being offered everywhere: a new "Available add-ons" field on the Event
  form (Admin+) controls which ones the Check-In Desk offers for that event.
- A per-event comp list cost breakdown (foregone entry revenue, grouped by comp reason)
  on the Event edit page, alongside the existing global/weekly Analytics figure.
- A configurable per-method transaction fee (e.g. a card-processor surcharge on Venmo,
  PayPal, or an electronic payment), folded into the amount charged at check-in, a
  standalone subscription purchase, or an add-on day pass.
- Staff can mark a departed patron as returned from the Check-In Desk, undoing an
  accidental or premature "Depart" without creating a new attendance row.
- Optional local-HTTPS support for the Apache deploy tooling: `setup-apache.ps1` gains
  `-EnableTls`/`-CertFile`/`-KeyFile`, plus a `scripts/client/install-local-ca.ps1` helper
  for trusting a self-issued CA (e.g. mkcert) on staff PCs — fully opt-in, verified
  end-to-end against a real Apache Lounge install.
- An HSTS header (`Strict-Transport-Security`) is now sent on any request actually served
  over HTTPS; absent otherwise, so a fork that hasn't done its own TLS cutover is
  unaffected.

### Changed

- The Feature Flags settings page groups its toggles into sections matching the admin
  nav groups, instead of one flat list.
- The Check-In Desk's two subscription-purchase surfaces are relabeled to make the
  difference clear: "Buy Subscription (no check-in)" for a standalone purchase vs.
  "... (covers tonight)" for the inline check-in option.
- The one-time-use restriction on Venmo/PayPal/electronic (`payment_methods.one_time_only`)
  now applies to entry-tier payments (check-in, day passes) only — it no longer blocks or
  flags a repeat subscription purchase made with the same method.

### Fixed

- "Buy Day Pass" no longer appears when no event, today or future, actually has a fee for
  that add-on.
- "Save & promote to Irregular" now captures `preferred_name`, matching the parallel
  guest-registration flow.
- `deploy.ps1`'s Apache service name is now configurable via a machine environment
  variable, and its pre-pull dirty-tree check no longer trips on an untracked file.
- The Upstream Updates page's git calls no longer fail with "dubious ownership" when the
  web server runs as a different OS account than the checkout's owner.
- `run-deploy.bat` passes an explicit project root, fixing a Task-Scheduler-specific
  failure where `$PSScriptRoot` came back empty.

## [0.1.0] — 2026-09-08

### Added

- Initial public release. Portico is a member check-in and membership-management app for
  member clubs — concurrent multi-user door check-in with per-event payment tracking,
  built on Laravel + Filament v5, licensed AGPL-3.0-or-later.
- Core engines, each the single source of truth for its decision: `AdmissionPolicy`
  (who gets in), `PricingService` (what they pay), `CapacityService` (building occupancy).
- Seven-tier role hierarchy; subscriptions; account-credit vouchers; guests as first-class
  member records; prepay events; per-event comp and admin comp lists; register-shift cash
  tracking; showrunner and instructor payouts; analytics; member skill tracking; an
  append-only audit trail for member status changes.
- Pool is modeled as an editable `add_ons` catalog row with a `subscribable` flag, not a
  hardcoded second pricing lane — any add-on a club marks `subscribable` gets a monthly
  plan, coverage, and a day pass through the same mechanism.
- Feature-flag mechanism (`/admin/feature-flags`, Manager+) to turn off optional features
  a club doesn't use. Flags stop new writes; they never hide data already collected.
- Per-install configuration without a code change: the displayed org name, watchlist
  notification wording, system-user address, backup filename prefix, and every operational
  tunable (`/admin/membership-settings`) are runtime settings or `.env` values.
- Deliberately minimal seed data plus an on-demand `DemoDataSeeder` for a populated
  local instance.
- Docs: [`docs/BLUEPRINT.md`](docs/BLUEPRINT.md) (schema and domain rules),
  [`docs/CONFIGURING.md`](docs/CONFIGURING.md) (set up Portico for your club),
  [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) (LAN deployment),
  [`docs/FEATURES.md`](docs/FEATURES.md) (full feature inventory).
- CI runs the full Pest suite on SQLite plus a `migrate:fresh --seed` smoke on MariaDB,
  the production target.

[0.2.0]: https://github.com/AsteriaCreations/portico/releases/tag/v0.2.0
[0.1.0]: https://github.com/AsteriaCreations/portico/releases/tag/v0.1.0
