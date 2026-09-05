# Contributing to Portico

Portico is a standalone member check-in and membership-management app for member clubs.
The core problem it solves is **concurrent multi-user check-in with per-event payment
tracking** — several volunteers checking members in at the door at once, on the same
night, without stepping on each other's data. It is deliberately not coupled to any
other system.

**Read [`docs/BLUEPRINT.md`](docs/BLUEPRINT.md) first.** It is the authoritative spec for
the schema, fee rules, admission rules, and roles. When this file and the blueprint
disagree, the blueprint wins.

## Stack

- Laravel (current stable), PHP 8.4+
- Filament v5 (admin panel + custom pages) — TALL stack
- MariaDB / MySQL 8 in production; SQLite in-memory for the test suite
- Pest for tests
- **Vanilla Laravel by default.** Every added package is a maintenance burden for a
  future volunteer. Don't add one without a clear reason, and never for something the
  framework already does. Don't add a permissions package.

## Architecture — the rules that matter

1. **Migrations are the source of truth for the schema.** The database must rebuild from
   scratch with `php artisan migrate:fresh --seed`. Write migrations with Laravel's
   schema builder (`->enum(...)`, not raw SQL) so they work on both MySQL and SQLite.
2. **Two domain decisions, each in ONE place, never in the UI:**
   - **Admission** — *whether* a member gets in (banned / deceased / under-18 block,
     watchlist warn, under-21 flag, identity capture). `App\Services\AdmissionPolicy`
     returns a decision object (outcome + reason).
   - **Pricing** — *what* they pay (comp → subscription coverage → per-event comp →
     vouchers → remainder). `App\Services\PricingService`.
   - A third centralized decision, `App\Services\CapacityService`, tracks building
     occupancy.
   Filament resources, pages, and Blade views **call** these services; they never
   re-implement the logic. This centralization is the whole point of the app — keep it
   testable.
3. **DOB is the single source of truth** for both age cutoffs. Derive the under-18 block
   and under-21 flag from `dob` at read time. Never store a precomputed "under 21 until".
4. **Derived data is computed, never stored.** Attendance counts, "most recent
   subscription month", occupancy, door take, voucher balance, probation status — all
   `COUNT`/`MAX`/`SUM` queries or live predicates, not columns.
5. **Attendance rows are price snapshots.** Store `entry_fee`, `entry_coverage`,
   `entry_covered_by`, `voucher_coverage`, `amount_paid` at check-in; every other
   chargeable a visit draws on (Pool, and any other subscribable add-on) is its own
   `attendance_add_ons` row (`fee`/`coverage`/`covered_by`), not a second hardcoded pair
   of columns. Never recompute or rewrite historical rows when fees later change.
6. **Fees are editable data, not constants.** Subscription price/credit live in `plans`
   (effective-dated, targeting an `add_ons` row); event fees live on the event; operational tunables
   live in the `membership_settings` singleton (`/admin/membership-settings`). Never
   hardcode a dollar amount in code.

## Permissions — enforce server-side, not just hidden in the UI

Seven nested roles: `showrunner` ⊂ `volunteer` ⊂ `dm` ⊂ `door` ⊂ `manager` ⊂ `admin` ⊂
`owner`. Plain Laravel policies/gates keyed on `users.role` (`app/Policies/`, plus gates
in `AppServiceProvider`). No permissions package. These are the enum case names, not
display text — `Role::getLabel()` gives Showrunner/DM generic defaults ("Event Lead"/
"Monitor"), and any of the seven can be aliased per install without touching the
hierarchy (`App\Filament\Admin\Pages\RoleLabels`, `Role::displayLabel()`).

- **Showrunner** — the narrowest role. Can only submit Admin-approved comp requests for
  the one event they're assigned to run (`events.showrunner_id`). Must not reach
  check-in / payment / anything else.
- **Volunteer** — one capability above Showrunner: record building departures (Dashboard
  headcount or a named patron on Active Patrons), under the `record-departures` gate.
- **DM** — a further level of trusted volunteer. No desk access. Its one added privilege
  is seeing a behavior note's full text on Active Patrons regardless of who wrote it
  (without the author, unlike Manager+).
- **Door** — check-in, take payment, see the admit *decision*, complete a Prospective's
  identity fields (first/last/email, plus DOB only if under 21), collect a subscription
  payment or redeem voucher credit *as part of check-in*. Door has **bounded write**:
  those identity fields, plus creating that one `subscriptions` row — nothing else. This
  is a narrower capability than the Subscriptions/Vouchers **resources**, which stay
  Manager+.
- **Manager** — everything Door + member editing, browsing/editing any subscription
  record, reports, fee/plan settings, `comp_reasons`/`categories`/`payment_methods`,
  the *reasons* behind flags (`watchlist_reason`, `ban_reason`, `notes`) + raw `dob`,
  plus waiving one visit's entry fee at check-in (`grant-event-comp` gate).
- **Admin** — everything Manager + create/edit/delete events (incl. their fees and the
  door-prepay flag) + manage user accounts + associate a Skill with a member
  (`assign-member-skills` gate). Event cost is fixed — Manager cannot adjust it.
- **Owner** — everything Admin, plus the one deliberate hole in the hierarchy below.

**One non-monotonic exception**: the monthly Manager & Owner subscription perk is gated
to exactly `Manager` or `Owner` — Admin cannot grant it, despite sitting between them in
rank. Everywhere else, `atLeast()` monotonicity holds.

Sensitive fields — `watchlist_reason`, `ban_reason`, `notes`, raw `dob` — are Manager+
only. Gate them in policies **and** hide them in Filament for Door. Note the asymmetry:
Door *writes* DOB during sign-up but afterward only *reads* the derived under-21 flag.

Subscription eligibility (5 attended events all-time, or `members.subscription_eligible`
manually set) gates *who can subscribe at all*, independent of role — enforce it
everywhere a `subscriptions` row gets created.

## Conventions

- PSR-12 / Laravel conventions. Eloquent relationships for every FK. Validation via Form
  Requests or Filament schema rules.
- Backed PHP enums for `role`, `event_type`, `add_on_kind`, coverage sources, mirroring the
  DB enums.
- Money as `DECIMAL(8,2)`; no float math on currency.
- Business logic in services / model methods — not controllers, not Blade.
- **Accessibility isn't optional — it's a real desk tool, staff use screen readers too.**
  Filament's Schema/Table builders carry their own accessibility — don't fight that. Two
  things Filament doesn't give you for free: (1) custom Blade content that updates live
  via Livewire (a running total, a polling count) needs `role="status"` so the change is
  announced; (2) `IconColumn::boolean()` renders a bare icon with no accessible name —
  add `->tooltip()`. A hand-rolled `<table>` needs `scope="col"` on its header cells.
- **Every admin-panel screen ships an in-app "How to use this screen" panel** —
  `<x-screen-instructions title="...">` (`resources/views/components/screen-instructions.blade.php`,
  a collapsed `<details>/<summary>`), grounded in that screen's actual gates/behavior, not
  filler. For a page with a hand-written Blade view (a custom `Filament\Pages\Page`, e.g.
  `CheckIn`/`ActivePatrons`/`FeatureFlags`/`RoleLabels`), insert it directly at the top of
  that view. For a Resource or a default-schema page with no hand-written view (every
  entry in `app/Filament/Admin/Resources/`, plus `Analytics`/`Technical`/the stock
  `Dashboard`), add one line to the `foreach` loop in `AdminPanelProvider::panel()` —
  `PanelsRenderHook::CONTENT_START` scoped to that Resource/Page class fires the hook on
  every one of its pages (List/Create/Edit/View) from a single registration — and drop
  the copy in its own `resources/views/filament/admin/instructions/{name}.blade.php`. A
  screen staff actually operate live during a shift (check-in, floor rosters, departures)
  also gets an entry on the printable desk-reference card set in
  `storage/desk-reference-cards.html` — settings/catalog screens don't need a card.
- Filament resources/pages/widgets live under `App\Filament\Admin\{Resources,Pages,Widgets}`
  (not the default `app/Filament/...`) — `AdminPanelProvider` discovers them there.
  Generate with `php artisan make:filament-resource ... --panel=admin`.

## Testing

Pest feature tests are required for the two engines — they **are** the written record of
house policy. Every change must be programmatically tested: add or update a test, then
run the affected tests.

At minimum, keep these covered:

- **Admission**: banned → blocked · under-18 → blocked · watchlist → warn requiring
  acknowledgment · under-21 → flagged but admitted · clear member → OK · deceased →
  blocked ahead of a ban · a banned member with a granted per-event exception → warn.
- **Pricing**: comp category → $0 · regular subscription on $20 → $0 · on $40 → $15 · on
  $100 → $75 · pool subscription on a pool event → $0 · no subscription → base fee · a
  per-event comp waives entry regardless of prior coverage and leaves pool untouched · a
  Door submission cannot comp an entry even via a forged payload.
- **Subscription eligibility**: fewer than 5 attended events and no manual flag → not
  eligible · 5+ → eligible · `subscription_eligible = true` → eligible regardless.
- **Vouchers**: redeeming more than the balance is capped · partial redemption leaves the
  remainder due · redeeming against another member's balance debits that member · balance
  is always `SUM(amount)` · a correction is a new offsetting row, never an edit.
- **Monthly Manager & Owner perk**: a second grant in the same calendar month is
  rejected · Admin cannot grant it · each qualifying user's allowance is independent ·
  the beneficiary must be subscription-eligible.

Broad coverage is not the goal; cover the rules. See `docs/BLUEPRINT.md`
"Testing the fee pipeline" for the full list of expected cases.

Tests run against **SQLite in-memory** (`phpunit.xml`), while the production DDL is
MySQL/MariaDB-flavoured. `.env.testing` exists for running `artisan --env=testing` by
hand against a disposable `database/testing.sqlite`.

## Working on the code

- Work one thin vertical slice at a time. Prefer a plan before a large change.
- Branch + PR for anything non-trivial; don't push straight to `main`.
- Run `vendor/bin/pint` before finalizing — the project style is Laravel Pint's default.
- Run the affected tests (`php artisan test --compact --filter=...`), and the full suite
  before a PR.

### Commands

- `php artisan migrate:fresh --seed` — rebuild the database
- `php artisan db:seed --class=DemoDataSeeder` — populate realistic sample data
- `./vendor/bin/pest` — run tests (or `php artisan test`)
- `./vendor/bin/pest tests/Feature/PricingServiceTest.php --filter=some_test` — one test
- `npm run dev` / `npm run build` — Vite / Tailwind assets
- `./vendor/bin/pint` — code style

## Do NOT

- Do NOT put business logic in Filament resources or Blade views.
- Do NOT store derived values or hardcode fees.
- Do NOT add a permissions package or other dependencies without a clear reason.
- Do NOT use `php artisan serve` for production.
