# Portico — Design Blueprint

*Standalone Laravel + Filament application. Schema, fee logic, admission rules, and roles.*

Target stack: current Laravel + Filament v5 (PHP 8.4+), MySQL 8 / MariaDB. DDL below is MySQL/MariaDB flavour.

Portico was built to replace a single shared spreadsheet a member club used to run its
door. Several schema decisions below are explained by reference to "the legacy
spreadsheet" — that context is kept because it motivates the design, not because the
spreadsheet matters to a new install. Dollar figures ($60 regular, $25 credit, $15 pool)
are **example seed values**, editable at runtime via the Plans and Membership Settings
screens — never hardcoded.

---

## Entity map

```
        users (staff: showrunner / volunteer / dm / door / manager / admin / owner)
          │ checked_in_by
          ▼
categories ─< members ─< attendance >─ events >─ event_types
                 │            ▲            (entry_fee + optional pool_fee;
                 │            │ prices from    event_type = analytics label)
                 ├─< subscriptions        plans (regular = $25 credit · pool = full)
                 │   (regular→entry, pool→pool component)
                 └─< vouchers             (account-credit ledger; redeemable on
                     (issued by Admin,      behalf of another member)
                      redeemed by any role)
```

Four core tables (categories, members, events, attendance) plus supporting tables added in discussion: **users** (who did it), **subscriptions**, **plans** (editable fees), **event_types** (an analytics label for events), and **vouchers** (account-credit ledger).

---

## What changed from the legacy spreadsheet

- **Events are rows, not columns.** The spreadsheet's date columns become `events`; every filled grid cell becomes an `attendance` row.
- **Pool is an additive per-event component, not a separate event.** An event carries an `entry_fee` and an optional `pool_fee`; a single check-in can draw on *both* subscriptions at once.
- **Events are categorised for analytics.** An `event_type` (manager-managed lookup) tags each event — Social, Pool Social, Class, and so on — so attendance and revenue can finally be sliced by kind. It is a *label only*, never a fee input.
- **DOB is the single source of truth.** The old "Under21 Until" column is gone — both the hard **under-18 block** and the **under-21 flag** derive from `dob`.
- **Colour-coded states become explicit flags** (watchlist, banned, deceased, missing paperwork).
- **"Attended 20xx" and "Most Recent Subscription Month" are no longer stored** — they're a `COUNT` and a `MAX`.
- **Fees are editable data**, not hardcoded — held in `plans` with effective dates.

---

## Schema (DDL)

```sql
-- VOLUNTEERS who sign in. Role drives every permission.
CREATE TABLE users (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  email       VARCHAR(120) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,                 -- Laravel-hashed
  role        ENUM('showrunner','volunteer','dm','door','manager','admin','owner') NOT NULL DEFAULT 'door',
  active      BOOLEAN NOT NULL DEFAULT TRUE,
  created_at  TIMESTAMP NULL, updated_at TIMESTAMP NULL
);

-- MEMBERSHIP CLASS (one per member)
CREATE TABLE categories (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(40) NOT NULL UNIQUE,           -- example seed set: Owner, Manager,
                                                     -- Emeritus, Staff, Former_Staff, SH,
                                                     -- Irregular, Prospective, Guest.
                                                     -- Editable via the Categories screen;
                                                     -- only Prospective/Guest/Irregular are
                                                     -- load-bearing in code (protected names).
  description VARCHAR(255),
  is_comped   BOOLEAN NOT NULL DEFAULT FALSE,        -- free entry (Owner/Emeritus/Staff)
  sort_order  INT NOT NULL DEFAULT 0,
  active      BOOLEAN NOT NULL DEFAULT TRUE
);

-- MEMBERS
CREATE TABLE members (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  member_number     INT UNIQUE,                      -- the club's member "#"; system-assigned
  username          VARCHAR(60) NOT NULL UNIQUE,
  preferred_name    VARCHAR(60),
  first_name        VARCHAR(60),                     -- captured at Prospective completion
  last_name         VARCHAR(60),
  email             VARCHAR(120),
  category_id       INT NOT NULL,
  sponsor_id        INT,                               -- self-referential; the member responsible for a Guest they brought
  date_vetted       DATE,
  dob               DATE,                            -- SOURCE OF TRUTH for 18 & 21 cutoffs
  is_active         BOOLEAN NOT NULL DEFAULT TRUE,
  subscription_eligible BOOLEAN NOT NULL DEFAULT FALSE,
  -- signed forms/waivers are tracked per-signing in member_paperwork now
  -- (see paperwork_types) -- the old single paperwork_date column was
  -- backfilled into a "Standard Paperwork" row and dropped
  -- status flags (were cell colours) --
  on_watchlist      BOOLEAN NOT NULL DEFAULT FALSE,
  watchlist_reason  VARCHAR(255),                    -- MANAGER-ONLY
  is_banned         BOOLEAN NOT NULL DEFAULT FALSE,
  ban_reason        VARCHAR(255),                    -- MANAGER-ONLY
  probation_override_start DATE,                    -- manual override; NULL => basis is date_vetted (see below)
  missing_paperwork BOOLEAN NOT NULL DEFAULT FALSE,
  is_deceased       BOOLEAN NOT NULL DEFAULT FALSE,   -- blocks admission, same as is_banned
  hospitality_note  VARCHAR(120),
  notes             TEXT,                            -- MANAGER-ONLY
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  FOREIGN KEY (category_id) REFERENCES categories(id),
  FOREIGN KEY (sponsor_id)  REFERENCES members(id)
);
-- on_probation is NOT a stored column — it's derived (Member::isOnProbation()):
--   on probation while today < (probation_override_start ?? date_vetted) + probation_period_days (config, default 90)
--   reporting-only: never affects admission or pricing.

-- BAN EXCEPTIONS — a one-time exception admitting a specific banned member to
-- a specific event without lifting the ban itself (e.g. a re-introduction to
-- decorum at a newbie night). Surfaces at the door as a WARN, not a silent OK.
CREATE TABLE ban_exceptions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  member_id   INT NOT NULL,
  event_id    INT NOT NULL,
  granted_by  INT NOT NULL,
  reason      VARCHAR(255),
  created_at  TIMESTAMP NULL,                        -- append-only: no updated_at, no edit/delete path
  UNIQUE (member_id, event_id),
  FOREIGN KEY (member_id)  REFERENCES members(id),
  FOREIGN KEY (event_id)   REFERENCES events(id),
  FOREIGN KEY (granted_by) REFERENCES users(id)
);

-- MEMBER STATUS CHANGES — append-only audit trail for is_banned/on_watchlist
-- changes: who changed it, to what, when, and why. Edit access to those two
-- fields stays Manager+ same as always; this log is the accountability
-- mechanism, not a tighter gate. Written by a model observer, not a form.
CREATE TABLE member_status_changes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  member_id   INT NOT NULL,
  status      ENUM('banned','watchlist') NOT NULL,
  value       BOOLEAN NOT NULL,                       -- the new state being set
  reason      VARCHAR(255),
  changed_by  INT NOT NULL,
  created_at  TIMESTAMP NULL,                        -- append-only: no updated_at, no edit/delete path
  FOREIGN KEY (member_id)  REFERENCES members(id),
  FOREIGN KEY (changed_by) REFERENCES users(id)
);

-- EVENT TYPES — descriptive taxonomy for analytics (manager-managed lookup; NOT a pricing input)
CREATE TABLE event_types (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(40) NOT NULL UNIQUE,           -- Social, Pool Social, Class, Private Rental, Meeting, Special
  description VARCHAR(255),
  sort_order  INT NOT NULL DEFAULT 0,
  active      BOOLEAN NOT NULL DEFAULT TRUE
);

-- EVENTS — two independent optional charges; concurrent same-day events allowed (no unique on date)
CREATE TABLE events (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  event_date    DATE NOT NULL,
  starts_at     DATETIME,                            -- additive to event_date; also widens the check-in picker's "current" window for an event spanning midnight
  ends_at       DATETIME,                             -- once past, the comp list's voucher rewards become grantable (vouchers:grant-comp-rewards)
  name          VARCHAR(80),                         -- 'Sunday Pool Social', etc.
  event_type_id INT,                                 -- FK -> event_types (label / analytics ONLY, not a fee input)
  entry_fee     DECIMAL(8,2) NOT NULL DEFAULT 0,     -- regular admission; 0 => pool-only event
  pool_fee      DECIMAL(8,2) NOT NULL DEFAULT 0,     -- Pool add-on's price for this event; 0 => pool closed / none (see add_ons below)
  door_prepay_enabled BOOLEAN NOT NULL DEFAULT FALSE, -- lets this event surface at the check-in desk ahead of its own date
  showrunner_id INT,                                  -- FK -> members; the member running this event (Showrunner role's comp-list nominations, Admin-approved)
  host_id       INT,                                  -- FK -> members; automatically free entry at THIS event when they check in (see "Host")
  notes         VARCHAR(255),
  created_by    INT,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  FOREIGN KEY (created_by)    REFERENCES users(id),
  FOREIGN KEY (event_type_id) REFERENCES event_types(id),
  FOREIGN KEY (showrunner_id) REFERENCES members(id),
  FOREIGN KEY (host_id)       REFERENCES members(id)
);
-- has_entry = entry_fee > 0 · has_pool = pool_fee > 0 (both derived, not stored)
-- Event cost is fixed — creating, editing, or deleting an event is Admin+ only (EventPolicy).
-- starts_at/ends_at are nullable at the DB level (the ~111 events that predate this
-- feature stay null) but the EventForm requires both going forward, ends_at after starts_at.

-- OCCUPANCY ADJUSTMENTS — append-only ledger of known departures (or
-- corrections) reducing the building's effective occupancy for a given
-- night; attendance rows are never rewritten, so a departure can't be
-- "un-counted" any other way. See "Prepay events" below.
CREATE TABLE occupancy_adjustments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  for_date    DATE NOT NULL,
  delta       INT NOT NULL,                          -- negative = departures freeing up room
  reason      VARCHAR(255),
  recorded_by INT NOT NULL,
  created_at  TIMESTAMP NULL,                        -- append-only: no updated_at, no edit/delete path
  FOREIGN KEY (recorded_by) REFERENCES users(id)
);
-- This ledger stays purely anonymous ("N people left, don't know exactly
-- who"). Departing a specific, known person instead sets attendance.departed_at
-- (see below) via the Active Patrons page — CapacityService::occupancy()
-- excludes departed rows from its attendance COUNT the same way this ledger's
-- SUM already reduced it. Both mechanisms coexist; see the code for details.

-- ADD_ONS — editable catalog of chargeable extras, including Pool. `kind='entry'`
-- marks the one protected row every Regular subscription targets (so plans/
-- subscriptions can reference one NOT NULL add_on_id FK for every target,
-- Entry included — a nullable "no target" case would break the unique index
-- below, since SQL treats two NULLs as non-colliding). `subscribable` gates
-- whether this add-on participates in the coverage engine (comp/subscription/
-- day-pass) at all; a non-subscribable add-on is a flat, always-full-price
-- extra (Private room rental, Sleepover) tracked only via attendance_add_ons,
-- never through PricingService. `priced_per_event` means the price varies by
-- event (Pool, from events.pool_fee) rather than one flat catalog `price`.
CREATE TABLE add_ons (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(60) NOT NULL UNIQUE,
  kind             ENUM('entry','addon') NOT NULL DEFAULT 'addon',
  priced_per_event BOOLEAN NOT NULL DEFAULT FALSE,
  price            DECIMAL(8,2),                     -- NULL when priced_per_event
  subscribable     BOOLEAN NOT NULL DEFAULT FALSE,
  max_per_night    INT,                               -- building-wide cap (e.g. one rentable room); NULL = unlimited
  is_overnight     BOOLEAN NOT NULL DEFAULT FALSE,     -- extends sponsor accountability / Active Patrons visibility past midnight
  description      VARCHAR(255),
  sort_order       INT NOT NULL DEFAULT 0,
  active           BOOLEAN NOT NULL DEFAULT TRUE
);

-- SUBSCRIPTIONS — monthly, tied to a calendar month
CREATE TABLE subscriptions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  member_id     INT NOT NULL,
  add_on_id     INT NOT NULL,                        -- what this covers: the entry add-on, or a subscribable one (e.g. Pool)
  covered_month DATE NOT NULL,                        -- first day of covered month (e.g. 2026-07-01)
  amount_paid   DECIMAL(8,2) NOT NULL DEFAULT 0,
  paid_on       DATE,
  recorded_by   INT,
  comp_source   VARCHAR(50),                          -- e.g. 'manager_monthly_perk'; NULL for a normal paid sub
  notes         VARCHAR(255),                          -- audit detail, e.g. "gifted to <member>"
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE (member_id, add_on_id, covered_month),
  FOREIGN KEY (member_id)  REFERENCES members(id),
  FOREIGN KEY (add_on_id)  REFERENCES add_ons(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
);

-- PLANS — editable, effective-dated fee settings (manager-managed)
CREATE TABLE plans (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  add_on_id      INT NOT NULL,                       -- what this plan prices: the entry add-on, or a subscribable one
  price          DECIMAL(8,2) NOT NULL,              -- e.g. 60.00 entry · 15.00 pool (seed defaults, editable)
  credit         DECIMAL(8,2),                       -- per-visit credit (e.g. 25.00 for entry); NULL = full coverage instead
  effective_from DATE NOT NULL,
  effective_to   DATE,                               -- NULL = current
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  FOREIGN KEY (add_on_id) REFERENCES add_ons(id)
);

-- ATTENDANCE — the join + a self-auditing entry price record. Every other
-- chargeable (Pool, and any other subscribable add-on) is its own
-- attendance_add_ons row below, not a second hardcoded pair of columns here.
CREATE TABLE attendance (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  member_id         INT NOT NULL,
  event_id          INT NOT NULL,
  checked_in_by     INT,                             -- WHICH volunteer (attribution)
  checked_in_at     TIMESTAMP NULL,
  departed_at       TIMESTAMP NULL,                  -- set from the Active Patrons page; excluded from CapacityService::occupancy()
  -- ENTRY component snapshot --
  entry_fee         DECIMAL(8,2) NOT NULL DEFAULT 0, -- snapshot of event.entry_fee
  entry_coverage    DECIMAL(8,2) NOT NULL DEFAULT 0, -- comp, regular-subscription credit, or a manager's per-visit comp
  entry_covered_by  ENUM('none','comp','regular_subscription','legacy_import','event_comp') NOT NULL DEFAULT 'none',
  comp_reason_id    INT,                             -- set only when entry_covered_by = 'event_comp' (see Comp reasons)
  -- settlement --
  voucher_coverage  DECIMAL(8,2) NOT NULL DEFAULT 0, -- account-credit applied after subscription coverage (see Vouchers)
  amount_paid       DECIMAL(8,2) NOT NULL DEFAULT 0, -- (entry_fee-entry_coverage) + every add-on line's (fee-coverage) - voucher_coverage
  payment_method    VARCHAR(30),
  on_behalf_note    VARCHAR(120),                    -- guest / paid-for-by breadcrumb
  notes             VARCHAR(255),
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE (member_id, event_id),
  FOREIGN KEY (member_id)     REFERENCES members(id),
  FOREIGN KEY (event_id)      REFERENCES events(id),
  FOREIGN KEY (checked_in_by) REFERENCES users(id),
  FOREIGN KEY (comp_reason_id) REFERENCES comp_reasons(id)
);

-- ATTENDANCE_ADD_ONS — one row per add-on charged on a visit, name/price
-- snapshotted at purchase time (never rewritten by a later catalog edit).
-- `price` is the amount actually paid for the line (fee-coverage), the same
-- meaning for a flat item or a subscribable one, so it always folds straight
-- into attendance.amount_paid. `fee`/`coverage`/`covered_by` are only ever
-- set for a subscribable add-on's line (Pool, at launch) -- null/0/null for
-- an ordinary flat add-on, which never goes through PricingService at all.
CREATE TABLE attendance_add_ons (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,
  add_on_id    INT,                                  -- nullable: survives the catalog row being deleted later
  name         VARCHAR(60) NOT NULL,
  price        DECIMAL(8,2) NOT NULL,
  fee          DECIMAL(8,2),
  coverage     DECIMAL(8,2) NOT NULL DEFAULT 0,
  covered_by   ENUM('none','comp','subscription','day_pass','legacy_import'),
  is_overnight BOOLEAN NOT NULL DEFAULT FALSE,
  created_at   TIMESTAMP NULL,
  FOREIGN KEY (attendance_id) REFERENCES attendance(id),
  FOREIGN KEY (add_on_id)     REFERENCES add_ons(id)
);

-- COMP REASONS — extensible list of reasons a specific visit's entry was waived
-- (e.g. "House Sub"), editable settings data rather than a hardcoded enum —
-- distinct from a member's own comped category, which is permanent (see
-- Categories above). Only ever waives the ENTRY component; pool is priced
-- independently. Manager+ can add to this list as new reasons come up.
CREATE TABLE comp_reasons (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60) NOT NULL UNIQUE,           -- e.g. "House Sub", "Presenter"
  description VARCHAR(255),
  grants_voucher_amount DECIMAL(8,2),                -- NULL = no automatic voucher; else granted once the event ends (vouchers:grant-comp-rewards)
  sort_order  INT NOT NULL DEFAULT 0,
  active      BOOLEAN NOT NULL DEFAULT TRUE
);

-- VOUCHERS — append-only account-credit ledger; no stored balance (SUM(amount) per member = balance)
CREATE TABLE vouchers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  member_id     INT NOT NULL,                         -- whose balance this row affects
  amount        DECIMAL(8,2) NOT NULL,                 -- positive = issued, negative = redeemed
  reason        VARCHAR(255) NOT NULL,                 -- required on every row — see Vouchers below
  attendance_id INT,                                    -- set on redemption rows: the visit this covered
  recorded_by   INT NOT NULL,                            -- who performed the transaction
  created_at TIMESTAMP NULL,
  FOREIGN KEY (member_id)     REFERENCES members(id),
  FOREIGN KEY (attendance_id) REFERENCES attendance(id),
  FOREIGN KEY (recorded_by)   REFERENCES users(id)
);

CREATE INDEX ix_attendance_event  ON attendance(event_id);
CREATE INDEX ix_attendance_member ON attendance(member_id);
CREATE INDEX ix_events_date        ON events(event_date);
CREATE INDEX ix_events_type        ON events(event_type_id);
CREATE INDEX ix_subs_lookup        ON subscriptions(member_id, add_on_id, covered_month);
CREATE INDEX ix_vouchers_member    ON vouchers(member_id);
```

**This DDL covers the core domain plus a few early additions** (`ban_exceptions`, `member_status_changes`, `occupancy_adjustments`, `comp_reasons`). It is not kept in lockstep with every table added since — `payment_methods`, `comp_requests`, `add_on_day_passes`, `miscellaneous_payments`, `registers`/`register_shifts`, `membership_settings` (incl. its feature-flag columns), `member_username_changes`, `skills`/`member_skill`, `showrunner_payout_tiers`/`instructor_pay_rates`, `attendance_behavior_notes`, and `paperwork_types`/`member_paperwork` are all real, in-use tables that this block doesn't define. **Migrations under `database/migrations/` are the source of truth for the schema** — read those for the current shape of any table.

Note the **snapshot columns**: a check-in records how each component's price was reached *at that moment*, so later fee changes never rewrite history. Entry lives on `attendance` itself; every other chargeable a visit draws on (Pool, and any other subscribable add-on) is its own `attendance_add_ons` row — a single shared shape for "a charge that can be comped/subscribed/day-passed," rather than a second hardcoded pair of columns per new chargeable. `voucher_coverage` is a further, independent settlement line on `attendance` — it discounts the *combined* total still due after entry and every add-on line's own coverage, not a specific component (see Vouchers below).

---

## Fee pipeline (per attendance row)

Runs once per check-in. Entry is priced on its own; every **subscribable**
add-on (Pool, at launch — see "Add-ons" below) is priced independently of
entry and of every other add-on, each drawing on its own subscription/
day-pass/comp coverage. A non-subscribable add-on (a rentable room, a
sleepover) never runs through this pipeline at all — flat price, always
charged in full, folded straight into `amount_paid`. `event_type` plays no
part here.

```
price(member, event):
    entry = event.entry_fee        # 0 on a pool-only event

    # Comp categories are free on everything subscribable
    if member.category.is_comped:
        lines = [ (addOn, fee, fee, 'comp') for addOn in subscribable_add_ons()
                  if (fee := addOn.price_for(event)) is not None ]
        return entry_fee=entry, entry_cov=entry, entry_src='comp',
               add_on_lines=lines, due=0

    month = first_of_month(event.event_date)

    # ENTRY — the event's host is automatically free, ahead of any subscription
    if entry > 0 and event.host_id == member.id:
        entry_cov = entry ; entry_src = 'host'
    # ENTRY — Regular subscription, per-event $25 credit, floored at zero
    elif entry > 0 and active_sub(member, entry_add_on, month):
        entry_cov = min(entry, current_plan(entry_add_on).credit)   # 25
        entry_src = 'regular_subscription'
    else:
        entry_cov = 0 ; entry_src = 'none'

    # Every subscribable add-on priced for this event (Pool, at launch) —
    # day pass beats an active subscription beats nothing, same precedence
    # Pool always had.
    lines = []
    for addOn in subscribable_add_ons():
        fee = addOn.price_for(event)
        if fee is None: continue
        if has_day_pass(member, addOn, event):
            cov, src = fee, 'day_pass'
        elif active_sub(member, addOn, month):
            credit = current_plan(addOn).credit
            cov, src = (min(fee, credit) if credit is not None else fee), 'subscription'
        else:
            cov, src = 0, 'none'
        lines.append((addOn, fee, cov, src))

    due = (entry - entry_cov) + sum(fee - cov for (_, fee, cov, _) in lines)
    return entry_fee=entry, entry_cov, entry_src, add_on_lines=lines, due

# active_sub = a subscriptions row where member + add_on_id match
# and covered_month = the first-of-month of the event's date.
# subscribable_add_ons() = add_ons where subscribable AND active
# (Pool additionally drops out while the pool_enabled flag is off —
# see "Feature flags" — but that never affects pricing of an event that
# already has a nonzero pool_fee or coverage from an existing subscription,
# only whether NEW Pool commitments can be bought).
```

Coverage matrix (entry $8, pool $5 where present):

| Event shape | Nothing | Regular Subscription | Pool Subscription | Both |
|---|---|---|---|---|
| Entry only ($8) | $8 | $0 | $8 | $0 |
| Entry + pool ($8+$5) | $13 | $5 | $8 | $0 |
| Pool only ($5) | $5 | $5 | $0 | $0 |

Each subscription discounts only its own line. `entry_fee = 0` is a pool-only event; a pool event with no price for it produces no add-on line at all — no separate "type" needed for pricing. The `event_type` label plays no part in the math; fees come solely from `entry_fee` and each add-on's own price.

**Check-in desk flow**: member is looked up first — it alone determines admission decision, subscription eligibility, and guest-sponsor status — then event, since that only affects per-event price and capacity. Member status (raw ban/watchlist/deceased flags, subscription eligibility, Prospective identity-capture) shows the moment a member is selected, before any event is picked — only the *final* admission outcome (ban exceptions, age) and anything touching an attendance row need an event too. A subscription itself can be bought two ways: standalone against the member's account the moment they're selected (no event needed — `SubscriptionBundleService::purchase()`, the same mechanism `ListSubscriptions::bulkPurchaseAction()` already uses standalone, Manager+, for phone-order purchases; the check-in page's version is deliberately ungated, matching the existing "subscription collection at check-in is every role including Door" rule), or bundled into check-in itself via live, on-page subscription/per-event-comp/voucher state (not sealed inside a "Check In" confirmation dialog), so staff see the running total update as they pick things; `PricingService::previewWithSelections()` runs the same `price()` math above against a selected-but-not-yet-purchased option, without writing a speculative `subscriptions` row. Either way "Check In" itself is a small final-confirm step (payment method, notes) reading whatever coverage — bought standalone or picked live — is already in effect.

The event picker's "current" window (`CheckIn::eventSelectQuery()`/`defaultEventId()`) isn't `event_date` alone: an event's own `event_date` still matches for the whole calendar day regardless of its actual `starts_at`/`ends_at`, but an event that spans midnight would otherwise vanish from the picker the instant the clock passes 12am, since `event_date` no longer says "today." Widened with an additional check — is `now()` within `starts_at`/`ends_at`, plus `config('membership.event_window_buffer_minutes')` (default 15) of slack on each side, so staff can pull the event up a little before it officially starts and keep working it a little after it ends. `door_prepay_enabled` events are unaffected by any of this — that flag alone surfaces them, however far in the future their own `starts_at`/`ends_at` fall.

---

## Host (automatic free entry)

Each event can have one `host_id` (set on the event, Admin+ only — same edit bar as the rest of the event record). When the host checks in to the event they're hosting, their entry is free **automatically** — no comp request, no checkbox, nothing for staff at the desk to do. This is what distinguishes it from the two other "someone doesn't pay" mechanisms:

- **`categories.is_comped`** — permanent, applies to *every* event that member ever attends, regardless of who's hosting.
- **Per-event comp** (below) — a one-off decision a Manager+ makes *at check-in* for a specific visit, requiring a person to click a checkbox.
- **Host** — deterministic from the event record itself (`event.host_id == member.id`), exactly like `regular_subscription` coverage is deterministic from an active subscription. That's why it's baked directly into `price()` above rather than layered on as a separate `applyX()` step: it's a property of *this* member+event pair, not a per-visit staff decision.

Entry only, same as per-event comp — pool is priced independently (the host still needs a Pool subscription, or pays the pool fee, if the event has one). Checked ahead of the Regular subscription in the pipeline, so a host's own subscription (if they have one) is never needed to zero out their own event. Also distinct from **Showrunner** (`showrunner_id`, a separate role that can nominate *other* members for an Admin-approved comp on the event they're running) — Host is about the host's own entry, not anyone else's, and needs no approval step.

---

## Per-event comp (waiving one visit's entry)

Distinct from a member's own comped category (`categories.is_comped` — permanent, applies to everything that member ever attends): this is a **Manager+** decision to waive **one specific visit's entry fee**, e.g. a member who worked that event as a "House Sub." Every add-on line is priced independently — a comped entry doesn't touch pool or any other add-on.

A separate, explicit step layered on top of `price()` above, the same shape as applying voucher credit — not baked into the pipeline itself, since it's a one-off decision about a visit, not a property of member+event:

```
applyEventComp(breakdown):
    return entry_fee=breakdown.entry_fee,
           entry_cov=breakdown.entry_fee, entry_src='event_comp',   # full override
           add_on_lines=breakdown.add_on_lines,   # untouched
           due = breakdown.due - (breakdown.entry_fee - breakdown.entry_cov)
```

Fully overrides whatever `price()` already computed for entry — comp-by-category, regular-subscription coverage, or nothing — same as comp-by-category already overrides a subscription in the pipeline. `comp_reason_id` (optional) records why, from the extensible `comp_reasons` list — a manager can add a new reason as one comes up; it's editable settings data, not a hardcoded enum, same pattern as `event_types`/`plans`. Enforced server-side (not just a hidden checkbox): the check-in page only *applies* the comp if the submitting user passes the `grant-event-comp` gate (Manager+), regardless of what's in the submitted payload.

---

## Add-ons (chargeable extras, some subscribable)

`add_ons` is one editable catalog for two different shapes of "extra charge":

- **Flat, non-subscribable** (the default — e.g. Private room rental, Sleepover): one catalog `price`, opt-in via a checkbox at check-in, always charged in full. Never touches `PricingService` at all — comp, subscriptions, and vouchers only ever apply to entry and to subscribable add-on lines, never to these. Recorded as an `attendance_add_ons` row with `fee`/`coverage`/`covered_by` left null — the row exists purely as a snapshot of what was sold and for how much.
- **Subscribable** (`subscribable = true` — Pool, at launch): participates in the same coverage engine entry does. A `plans` row can target it (`add_on_id`), a member can hold a `subscriptions` row covering it for a given month, and a one-time `add_on_day_passes` row can cover it for exactly one event. Coverage precedence is day pass, then an active subscription, then nothing — identical to entry's own host-then-subscription-then-nothing order, just without the host case. Priced automatically whenever the event has a price for it — no checkbox, the same "no staff action required" behavior Pool always had.

A subscribable add-on can additionally be `priced_per_event = true` (Pool is the only one today) — its price comes from a per-event source (`events.pool_fee`) instead of one flat catalog `price`, since not every event has a pool. A day pass only ever makes sense for a `priced_per_event` add-on: a flat add-on's price never varies by event, so "buy just today's coverage" is identical to just checking the flat-add-on box that visit.

**Entry is modeled the same way, once removed** — the one protected `add_ons` row (`kind = 'entry'`, name "Entry", never renamable or deletable) is what every Regular subscription's `plans`/`subscriptions.add_on_id` actually points at. This is purely so those two tables get one clean NOT NULL foreign key for every target instead of a nullable "no target" case (which would silently break their `UNIQUE (member_id, add_on_id, covered_month)` index — SQL treats two NULLs as non-colliding, so a member could otherwise hold two "no-target" subscriptions for the same month with no constraint catching it). Entry's own fee logic — `event.entry_fee`, the host bypass, the per-visit credit — never reads anything off that row; only the subscription-lookup side is unified through it.

---

## Comp list & automated comp-reward vouchers

An event's **Comp List** (`CompListRelationManager` on `EventResource`, Manager+) is the admin-managed counterpart to the live check-in comp checkbox — pre-authorizing a member's comp ahead of the night rather than deciding it at the door. It uses exactly the same `PricingService::applyEventComp()` mechanism, so entry is fully waived and pool stays priced independently either way. Adding someone creates a real `Attendance` row ahead of time (`checked_in_at = null`, `comp_reason_id` set) — the same underlying shape as the Prepay List, so a comp-listed member automatically shows up in the check-in page's back-check-in roster and is capacity-gated the same way (adding to either list counts against the building immediately). The two lists stay disjoint by querying on `comp_reason_id`: Prepay List = `whereNull`, Comp List = `whereNotNull`.

**Any `comp_reasons` row can optionally grant a voucher once the event ends** — `grants_voucher_amount` (nullable) generalizes the "comped presenters get a $25 voucher" rule to any reason the club wants to treat that way, rather than hardcoding "Presenter" in code. A new command, `php artisan vouchers:grant-comp-rewards`, finds every attendance row that (a) has a comp reason with a configured amount, (b) actually arrived (`checked_in_at` not null — a no-show on the comp list gets no reward), (c) belongs to an event whose `ends_at` has passed, and (d) hasn't already been granted a voucher (`Attendance::vouchers()`, checked via `whereDoesntHave`) — and creates one `Voucher` per qualifying row, `attendance_id` linking back to it (idempotency check, and a reuse of that FK beyond its original "redemption" meaning — it's really just "the attendance row this voucher relates to"). Attributed to a dedicated, non-authenticatable **system user** (`active = false`, so it can never log in — it exists purely as a valid `recorded_by`), since no human is present when a scheduled command runs.

**This app has no Laravel scheduler** — the only recurring job (nightly backup) is a plain Artisan command invoked directly by Windows Task Scheduler, not the `Schedule` facade. `vouchers:grant-comp-rewards` follows that exact same pattern rather than introducing new scheduling infrastructure.

---

## Subscription eligibility (who can subscribe)

Not every member can start a subscription on demand. A member is eligible for **any** plan — entry or a subscribable add-on like Pool — once:

- they've attended **5 events, all-time** — computed live from `attendance` (never stored as a count, per the "derived, never stored" rule), **or**
- `members.subscription_eligible` is manually set to `true`.

The manual flag is the override path — primarily for the eventual Excel migration, where a member's years of pre-system attendance won't exist as `attendance` rows, so they'd otherwise incorrectly read as "0 events attended" (see "Still open" below). One rule governs every plan, whatever it targets; the threshold is configurable (`config/membership.php`, defaults to 5), not hardcoded.

Enforced everywhere a subscription can be created: the check-in page's "pay subscription" option (any role — see Roles & permissions), the Subscriptions resource's create form (Manager+), and the monthly Manager & Owner subscription perk (see below) — the perk waives price, not this rule.

---

## Vouchers (account credit)

An append-only ledger of account credit per member — not a stored balance, to avoid the drift a stored total is prone to (especially when members are renamed). A member's available credit is always `SUM(vouchers.amount) WHERE member_id = ?`, computed live, never stored (same "derived, never stored" rule as everywhere else).

- **Issued** by Admin only: a positive-amount row, with a required `reason`. There are many ways to earn one (referral, promo, manual goodwill), so this is free text, not an enum.
- **Redeemed** by any role, at check-in, as a negative-amount row linked to the `attendance` row it covered via `attendance_id`.
- **Stackable, no expiry** — typically $25, credit accumulates and lasts until spent.
- **On-behalf-of redemption.** The balance drawn from doesn't have to belong to the member checking in — anyone with redemption rights (Door included) can apply a *different* member's credit to cover this visit (e.g. a manager spending their own balance on someone else's entry). `reason` is mandatory on redemption rows for exactly this case — it's the only record of who a transfer was for.
- **Corrections are new rows, never edits.** A wrongly-issued voucher is never updated or deleted; Admin adds an offsetting negative row with a `reason` explaining the correction. `vouchers` has no update/delete path — Manager can view the ledger, only Admin can issue or correct it (redemption at check-in is the one exception, open to every role — see Roles & permissions).

**Applying credit at check-in** is a separate, explicit step from the fee pipeline above — opt-in and partial amounts are allowed, not automatic:

```
after due is computed by price(member, event):
    if door/member chooses to apply credit:
        available = SUM(vouchers.amount) for the paying member (self or someone else)
        voucherApplied = door-entered amount, capped at min(available, due)   # partial allowed
        amount_paid = due - voucherApplied
        write vouchers row: amount = -voucherApplied, attendance_id = this visit, reason = required
```

`attendance.voucher_coverage` stores `voucherApplied` as the price snapshot — same rule as `entry_coverage` and each add-on line's own `coverage`: never recomputed later.

---

## Monthly Manager & Owner Subscription Perk

**Manager and Owner — not Admin.** The one deliberate exception to the "each tier contains the one below" rule (see Roles & permissions): this is a front-line perk, not extended to Admin, even though Owner otherwise outranks Admin. Owner's inclusion isn't a rank thing — Owner qualifies for the same reason Manager does, not because Owner sits above Admin in the hierarchy.

Once per calendar month, a Manager or Owner may create a **regular** subscription (targeting the protected `add_ons` entry row) at `amount_paid = 0`, for themselves or gifted to any *subscription-eligible* member, tagged `comp_source = 'manager_monthly_perk'` with `notes` explaining the grant (e.g. "gifted to jsmith92"). `recorded_by` is always the granting user, whether the subscription covers themselves or someone else. Each qualifying user (each Manager, each Owner) has their own independent monthly allowance — one grant per person, not one per club per month.

The beneficiary must pass the same "Subscription eligibility" check as every other subscription-creating path (see above) — this perk waives the *price*, not the eligibility rule.

No pre-issued or expiring row — eligibility is a **live check**, the same "derived, never stored" pattern as subscription eligibility above:

```
perk_available(user, month = current_month):
    return NOT EXISTS subscriptions row WHERE
        recorded_by = user.id
        AND comp_source = 'manager_monthly_perk'
        AND created_at BETWEEN start_of(month) AND end_of(month)
```

If a Manager or Owner doesn't use it in a given month, there's nothing to expire — the right simply doesn't carry forward ("use it or lose it" falls out of the live check; no cron job needed).

---

## Admission policy (decision, separate from price)

Returns an **outcome** (everyone at the door sees it) and a **reason** (manager-only). One class; the check-in page and any future API both call it.

```
decide(member, event):
    age = years_between(member.dob, event.event_date)  # resolved as of the event, not today; null if dob unknown

    if member.is_deceased:        BLOCK  "Member marked deceased"
    if member.is_banned:
        if a ban_exceptions row exists for (member, event):
                                  WARN   "Banned — one-time exception granted for this event"   reason=ban_reason   (needs acknowledge)
        else:                     BLOCK  "Do not admit"          reason=ban_reason
    if age != null and age < 18:  BLOCK  "Under 18 — no admittance"
    if member.on_watchlist:       WARN   "Notify the staff channel"  reason=watchlist_reason   (needs acknowledge)
    if member is Prospective and identity incomplete:
                                  CAPTURE "Complete sign-up"      (Door fills fields → becomes Irregular)
    if age != null and age < 21:  FLAG   "Under 21 — no alcohol, mark hand"
    otherwise:                    OK     "Cleared"
```

Comp status affects **price**, not admission. The **decision** is public; the **reason string** is gated to Manager+. `on_probation` and `missing_paperwork` deliberately never appear here — they're reporting-only.

---

## Roles & permissions

Seven tiers: Showrunner ⊂ Volunteer ⊂ DM ⊂ Door ⊂ Manager ⊂ Admin ⊂ Owner — each strictly containing the one below, simple to enforce with plain Laravel policies (no permissions package needed) — **except** the Manager/Owner subscription perk, the one deliberate hole in that hierarchy (see the dagger below). Showrunner, Volunteer, and DM all sit below Door and are deliberately narrow: Showrunner can only submit Admin-approved comp requests for the one event they're running; Volunteer can only record building departures from the Dashboard (see the "Record known departures" row below); DM is a further level of trusted volunteer — everything Volunteer can do, plus seeing a behavior note's full text regardless of who wrote it (see the § row below) — none of the three reaches check-in, payment, or anything else Door and up can do.

These are `App\Enums\Role`'s case names — the actual permission tier, gates, and everything the rest of this document means by "Manager+" etc. never changes. What staff *see* is separate: `Role::getLabel()` ships generic defaults for the two that read as one club's own jargon ("Showrunner" → "Event Lead", "DM" → "Monitor"; the other five are generic already), and any of the seven can be aliased per install — `App\Filament\Admin\Pages\RoleLabels` (Manager+), read back via `Role::displayLabel()`. A club that already uses "Showrunner"/"DM" (or wants something else entirely) sets its own label there; nothing about the hierarchy, a gate check, or the `users.role` column changes.

| Capability | Door | Manager | Admin | Owner |
|---|:--:|:--:|:--:|:--:|
| Check members in, take payment | ✓ | ✓ | ✓ | ✓ |
| See admit **decision** (block/warn/ok) | ✓ | ✓ | ✓ | ✓ |
| See member name, category, under-21 status | ✓ | ✓ | ✓ | ✓ |
| Complete a Prospective's preferred name/name/email/**DOB**, promote to Irregular | ✓ | ✓ | ✓ | ✓ |
| Collect a subscription payment *during check-in* (member must be subscription-eligible; fixed at the current plan price) | ✓ | ✓ | ✓ | ✓ |
| Redeem voucher credit *during check-in* (own or another member's balance; partial amounts allowed) | ✓ | ✓ | ✓ | ✓ |
| Record a register-box transaction outside the normal event/subscription flow (vendor payment, rental, donation) | ✓ | ✓ | ✓ | ✓ |
| See watchlist/ban **reasons**, notes | | ✓ | ✓ | ✓ |
| Read raw **DOB** (vs. just under-21 status) | | ✓ | ✓ | ✓ |
| Edit members, browse/edit **any** subscription record | | ✓ | ✓ | ✓ |
| Rename a member's username (audited — old/new/who/when logged) | | ✓ | ✓ | ✓ |
| Browse the full voucher ledger (resource) | | ✓ | ✓ | ✓ |
| Edit fees/credits (`plans`), manage `event_types`, `comp_reasons`, `categories`, `payment_methods`, run reports | | ✓ | ✓ | ✓ |
| Waive one visit's entry fee (per-event comp), e.g. a House Sub | | ✓ | ✓ | ✓ |
| Grant a one-time ban exception for a specific event; view the ban/watchlist change log | | ✓ | ✓ | ✓ |
| Manage an event's admin-run Prepay List (single add or bulk upload) | | ✓ | ✓ | ✓ |
| Manage an event's admin-run Comp List | | ✓ | ✓ | ✓ |
| Record known departures, adjusting tonight's building occupancy ‡ | ✓ | ✓ | ✓ | ✓ |
| See a behavior note's full text, but not who wrote it § | ✓ | ✓ | ✓ | ✓ |
| Grant the monthly free regular subscription (self or gift to a member) † | | ✓ | | ✓ |
| Create, edit, or delete events (incl. concurrent), including their fees, start/end times, and door-prepay flag; manage volunteer accounts | | | ✓ | ✓ |
| Issue voucher credit (positive-amount rows) / correct a wrongly-issued one | | | ✓ | ✓ |

† Deliberately **not** monotonic — Admin is the one tier in this table without a capability that both a lower tier (Manager) and a higher tier (Owner) have. Owner's inclusion isn't rank — Owner qualifies for the same reason Manager does, not because Owner outranks Admin. See "Monthly Manager & Owner Subscription Perk" above.

§ The real floor is DM, one level below Door — Volunteer (and Showrunner) only sees their own behavior note's full text plus a bare count of everyone else's. Manager+ sees the same full text as DM/Door, plus who wrote each note (`ActivePatrons::behaviorNotesSummary()`).

‡ Two surfaces, one gate: the admin Dashboard (`RecordDeparturesWidget`, anonymous headcount) and the Active Patrons page (`/admin/active-patrons`, departs one specific known person across every currently-active event at once). Neither lives on the check-in page, and the real floor for both is Volunteer, not Door — every tier from Volunteer up has it, same as every other row in this table, but this is the one capability Volunteer has that Showrunner doesn't.

Five subtleties this encodes: **Door has bounded write** (it may set the five identity fields on a Prospective, and create one new `subscriptions` row during check-in if the member is eligible — nothing else); **DOB read ≠ DOB write** (Door enters it during sign-up but afterward sees only the derived under-21 flag); **collecting a subscription payment or voucher credit at check-in is not the same capability as the Subscriptions/Vouchers resources** — those are bounded, desk-level actions open to every role, while full browse/edit access to historical records stays Manager+ (issuing/correcting vouchers is Admin+ only); **the monthly subscription perk breaks the nested-role model** — Manager and Owner qualify, Admin doesn't, even though Admin sits between them in rank; and **Owner is a superset of Admin** in every other respect — everything Admin can do, Owner can also do, plus the perk.

---

## Derived — never stored, always computed

- **Attended 2026** → `COUNT` of attendance rows joined to events in that year.
- **Most recent subscription month** → `MAX(subscriptions.covered_month)` per member+type.
- **Under 18 / Under 21** → from `dob` vs. today.
- **Currently covered?** → does an active subscription of the matching type exist for the event's month.
- **Tonight's occupancy / door take** → `COUNT` / `SUM(amount_paid)` over tonight's event(s), `CapacityService::occupancy()` — a `COUNT` of every attendance row (prepaid or arrived — a prepay counts immediately) across all of a night's concurrent events, plus the signed `SUM` of that night's `occupancy_adjustments` (known departures). Never a stored total.
- **Attendance & revenue by event type** → `GROUP BY event_type_id` over attendance joined to events — the analytics the spreadsheet never captured.
- **Member's voucher balance** → `SUM(vouchers.amount)` per member — never a stored column.
- **A Manager or Owner's monthly subscription perk availability** → does a `subscriptions` row with `comp_source = 'manager_monthly_perk'` exist for that user this calendar month.
- **On probation** → `today < (probation_override_start ?? date_vetted) + probation_period_days` (config, default 90) — reporting-only, same "derived, never stored" shape as subscription eligibility and under-21.
- **Volunteer+ staff currently in the building** → `User::signedInStaffQuery()` — any `active`, Volunteer+ user with an unexpired `sessions` row (`last_activity` within `config('session.lifetime')`). No stored "checked in" flag for staff, who don't pass through the desk the way a patron does.

---

## Prospective → Irregular (the sign-up transition)

Per your definitions: Prospective = "vetted, never been here"; Irregular = "vetted, been here at least once." So the **first successful check-in is the transition.** At that check-in the Door volunteer captures preferred name, first name, last name, email, and — only if under 21 — DOB; on save, the member's category flips to Irregular. This is why Door needs bounded write, and it's the one place Door touches DOB.

---

## Guests (a member walking in a plus-one)

A guest is a full `Member` row (category `Guest`) from the start — not a lighter-weight or temporary record. The gap this closed was that nothing at the desk could actually *create* one; the check-in page's member search only ever found existing rows.

**Registration is gated on the sponsor, not a new role rule.** A member (the "sponsor") must already be checked in for tonight's event — a prepaid-but-not-yet-arrived row doesn't count — and must not be `isOnProbation()`, before "Register a guest" appears on the check-in page at all. This is re-checked server-side in the action itself, not just hidden in the UI, matching the same never-trust-visibility-alone precedent as per-event comp's `grant-event-comp` gate. Once both hold, any role including Door can use it — this *is* Door's bounded write, same category as completing a Prospective's identity fields, and it deliberately bypasses `MemberPolicy::create`'s Manager+ gate the same way `saveAndPromoteAction` already bypasses `MemberPolicy::update` for a Prospective.

Registering a guest collects first/last name, optional email, and DOB only if the guest appears under 21 (mirroring the Prospective-capture convention above rather than inventing a new rule) — sets `category_id` to Guest, `sponsor_id` to the checked-in host, an auto-generated unique `username` (a walk-in guest has no pre-existing one), and a "Guest of {sponsor}" line in `notes`. The new guest is immediately auto-selected so staff can check them straight into the same event.

**Accountability is scoped to the night of the visit, not open-ended.** If a guest gets banned or put on the watchlist while they still have an attendance row checked in *that same day*, `App\Observers\MemberObserver` appends a note to the sponsor's own `notes` — the sponsor is only on the hook for what happened the night they brought the guest in, not anything that surfaces later and is unrelated to that visit.

Everything downstream of registration reuses existing mechanisms with no new code: filtering to "which Guests need the post-event welcome message" is the existing `category_id` filter on the Members table; "moving a welcomed guest to their standard class" is just editing `category_id` on the existing Members edit form.

---

## Build order (thin vertical slices)

The app was built in thin vertical slices, in roughly this order — a useful reading order for the codebase:

1. Migrations for all tables (this DDL) + seeders: categories, a starter `event_types` list (Social, Pool Social, Class, …), and `plans` (Regular $60/$25, Pool $15/full — seed defaults).
2. Filament auth + panel; **Members** resource (CRUD).
3. **Events** and **Subscriptions** resources; **plans** and **event_types** settings screens.
4. **Attendance** as a relation manager; the two-component fee pipeline in one service class (`PricingService`).
5. The custom **check-in page**: search → decide (`AdmissionPolicy`) → capture-if-Prospective → price → record with `checked_in_by`.
6. Role policies applied across fields/columns/pages; reporting widgets (incl. by-event-type breakdowns).
7. Pest tests for the rule engine + fee pipeline; README, `.env.example`, backup job.

Everything after step 7 (vouchers, guests, prepay/capacity, ban exceptions, per-event comp, the comp list, feature flags, register shifts, member skills, analytics, …) landed as further incremental slices. The commit history is the detailed record.

---

## Testing the fee pipeline (Pest)

- comp category → $0 on any event.
- entry-only $20, Regular subscription → $0 · entry-only $40, Regular subscription → $15 · entry-only $40, no sub → $40.
- entry $8 + pool $5: both subs → $0 · Regular only → $5 · Pool only → $8 · nothing → $13.
- pool-only $5: Pool subscription → $0 · no sub → $5 · Regular subscription only → $5 (regular does nothing).

Each case asserts the stored `entry_coverage` / the pool add-on line's `coverage` / `amount_paid`, not just the total.

**Subscription eligibility**: fewer than 5 attended events and `subscription_eligible = false` → not eligible · 5+ attended events → eligible · `subscription_eligible = true` → eligible regardless of count · prepaid (`checked_in_at` null) attendance rows don't count toward the 5.

**Vouchers**: redeeming more than the available balance is rejected or capped at the balance · partial redemption leaves the remainder due · redeeming against a different member's balance debits that member, not the one checking in · balance is always `SUM(amount)`, never a stored column · a correction is a new offsetting row, never an edit to the original.

**Monthly Manager & Owner perk**: a Manager or Owner granting a second perk in the same calendar month is rejected · eligibility resets in a new month · Admin cannot grant the perk despite outranking Manager and sitting below Owner in every other respect · each qualifying user's allowance is independent of every other's · the perk always creates a `regular` subscription at `amount_paid = 0` · the beneficiary must be subscription-eligible, same as any other subscription.

**Historical attendance import**: re-running the same file is idempotent — no duplicate members, events, or attendance rows · a malformed fee-row header skips that entire event column · an unparseable per-cell amount skips just that cell without failing the row · `SH`/`VR` legend codes are recorded as `amount_paid = 0` with the coverage attributed to subscription/voucher respectively · the unrelated cash-reconciliation footer below the member rows is never read as member data · `--dry-run` writes nothing.

**Per-event comp**: waives entry regardless of what coverage `price()` already computed (category comp, regular subscription, or nothing) · pool stays priced independently, untouched by the comp · a Door submission cannot comp an entry even with a forged payload — the check-in action only applies it if the submitting user passes the `grant-event-comp` gate.

**Host**: the designated host owes nothing on entry at their own event, with no staff action required · pool is still priced independently (a subscription or the pool fee applies as normal) · being the host of one event doesn't comp entry at any other event · host coverage is reported (`entry_covered_by = host`) even when the host also holds an active Regular subscription that would otherwise have covered it.

**Deceased blocks admission**: a deceased member is blocked at check-in, taking precedence over every other outcome including a ban exception.

**Ban exceptions**: a banned member with a `ban_exceptions` row for the specific event being checked into is warned (not blocked) and can check in after acknowledgement · an exception granted for one event does not cover a different event · the ban/watchlist audit log (`member_status_changes`) gets a row — capturing who, the new value, and the reason — whenever `is_banned` or `on_watchlist` changes on an authenticated save, and no row for an unrelated field edit or an unauthenticated (console) one · the log itself can never be edited or deleted, by anyone.

**Probation (computed)**: within the configured period of `date_vetted` → on probation · past it → not · `probation_override_start`, when set, replaces `date_vetted` as the basis · neither date set → not on probation, no error.

**Guests**: "Register a guest" is hidden until the selected member is actually checked in tonight (hidden for a not-yet-selected member and for a prepaid/not-yet-arrived attendance) · hidden (and server-side rejected even via a forged call) when the checked-in host is on probation · registering requires `username`, `preferred_name`, `first_name`, `last_name`, and `email` (`username` validated unique, with a race-safe fallback for two registers claiming the same one at once — no more auto-generated username) and creates a Guest-category member with `sponsor_id` set and a "Guest of {sponsor}" note · the new guest is auto-selected and can be checked in immediately · any role including Door can register one, once their host qualifies · two guests with the same name get distinct usernames · banning/watchlisting a guest who's checked in tonight appends a note to the sponsor, but the same guest banned on an unrelated later date leaves the sponsor untouched, and lifting a ban never writes a note.

**Prepay events**: the check-in event picker only lists today's events plus any `door_prepay_enabled` event, regardless of date · a prepay for a future-month event prices and covers the subscription for *that* event's month, not today's · `checkInAction` is hidden once the building is at capacity, and a recorded departure restores it · `markArrivedAction` (and its per-row table equivalent) is never blocked by capacity, since that attendance row already counted the moment it was created · the back check-in table lists unarrived attendance for the selected event and requires acknowledgement inline for a watchlisted row, blocking outright (no state change) for a hard-blocked one · adding to the admin-managed Prepay List is rejected once at capacity, same as the check-in desk · bulk upload creates one row per valid identifiable member, skips and logs an unmatched identifier, a member already on the list, and rows past the capacity limit, and is idempotent on re-run · a Manager is forbidden from editing or creating an event; an Admin is not · `starts_at`/`ends_at` are required to create an event, and `ends_at` must be after `starts_at`.

**Comp list & comp-reward vouchers**: adding a member to the Comp List waives entry via `applyEventComp` regardless of prior coverage, leaves pool priced independently, and is rejected once at capacity, same as the Prepay List · the Comp List and Prepay List stay disjoint (a row appears in exactly one) · a comp-listed member shows up in the check-in page's back-check-in table like any other unarrived attendance · `vouchers:grant-comp-rewards` grants a voucher for a comped, arrived attendee once the event's `ends_at` has passed, but not before it ends, not when the comp reason has no configured amount, and not for a comp-listed member who never actually arrived · re-running the command is idempotent · the granted voucher's `recorded_by` is the dedicated system user.

**Volunteer role & Active Patrons**: the role hierarchy is six deep, `Showrunner ⊂ Volunteer ⊂ Door ⊂ Manager ⊂ Admin ⊂ Owner` · Volunteer+ can access Active Patrons, Showrunner cannot · the page lists arrived, non-departed patrons across every currently-active event at once, omitting prepays still awaiting arrival and other days' attendance · departing a patron marks `departed_at` on exactly that attendance row (it then drops off the table) and immediately reduces `CapacityService::occupancy()`, the same as a `RecordDeparturesWidget` adjustment · the watchlist icon and "Watchlist only" filter reflect `on_watchlist`.

**Member record polish**: `member_number` is system-assigned (`MAX(member_number) + 1`) for any member created by an authenticated request with no number already supplied, covering both the admin form and guest registration · left alone for an unauthenticated (console/import) create, and whenever a caller already supplies a value · the field is display-only on the form afterward. The bulk-email CSV export is scoped to `email_opt_in = true` AND a non-empty `email`; Manager+, inherited from the Members resource's own gate.

**Category / payment method settings, register cash tracking**: renaming or deleting a protected category (`Prospective`/`Guest`/`Irregular`, string-matched directly by business logic) is rejected, even via a bulk action, even by an Admin · a custom category is freely editable · a payment method's `code` (the literal value stored on historical `attendance`/`subscriptions` rows) is immutable after creation · a `requires_register_shift` payment method is unselectable with no register shift open, and counts toward `RegisterShiftService::cashReceived()`'s box reconciliation; a non-flagged one does neither · recording a miscellaneous payment (a vendor payment, a private rental, a donation — cash that never touches `Attendance`/`Subscription`) requires an open shift and folds its cash into reconciliation the same way · `revenueBreakdown()` splits Event/Subscription/Other correctly across every payment method, not just cash.

**Check-in arrival time / username rename**: `checked_in_at` is auto-set to `now()` for a live/today event and forced to `null` for a future `door_prepay_enabled` event even with a forged submitted value; the field itself is hidden on the form for a non-live event · a Manager can rename a member's username via a dedicated header action and the change is logged (old value, new value, who, when) · renaming to a username already taken by another member is rejected · the plain Member edit form can no longer change `username` directly · a console-driven username change writes no audit row · the audit log can never be updated or deleted, by anyone.

**Paperwork & waivers**: `paperwork_types` is an editable Manager+ catalog (`/admin/paperwork-types`) of the signed forms a club tracks — each with `required`, an optional `renewal_months` (null = one-time; 12 = renews yearly), and an optional `gates_add_on_id`. `member_paperwork` is an append-only log, one row per signing (no edit/delete path — `MemberPaperworkPolicy`); `Member::hasValidPaperwork($type)` reads the latest per type and checks the renewal window. A type with `gates_add_on_id` set (the **Pool Waiver** → Pool, seeded) blocks that add-on for a member without valid paperwork: `PricingService` drops the add-on's line entirely (no charge, `build()` untouched), `CheckIn` blocks a day-pass purchase server-side, and the check-in page shows a warning plus a **"Record a waiver signature"** action so staff can capture the signing at the desk (Door+, mirrors the Prospective-capture and confirm-standard-paperwork flows). The manual `missing_paperwork` flag and its Capture flow are unchanged — a lapsed *gating* waiver never blocks admission, only the gated add-on. Seeded types: **Standard Paperwork** (one-time, gates nothing) and **Pool Waiver** (annual, gates Pool).

**One-time payment methods**: `payment_methods.one_time_only` — a method flagged true (Venmo and PayPal, seeded; plus a neutral `electronic` code) may be used once per member, ever, across entry and subscription payments; using any one appends a dated note to `hospitality_note` and disables *all* one-time methods for that member thereafter (`Member::hasUsedOneTimeMethod()`, computed from payment history, never stored). Replaces the old hardcoded "Venmo is one use" rule.

---

## Historical import

Portico ships no importer for any particular legacy system — every club's old
spreadsheet or database is shaped differently. If you write one, the schema gives you
what you need: `attendance` rows are price snapshots, so a historical row just needs
`entry_fee` / `entry_coverage` / `entry_covered_by` (use `legacy_import` as the
coverage source) filled in directly rather than run back through `PricingService`.
Recommended shape for such a command: a `--dry-run` flag, and skip-and-log any cell it
can't confidently parse rather than guessing — an import that silently invents data is
worse than one that tells you which 40 rows need a human.

---

## Operational non-negotiables

- Serve via a real web server (IIS/Apache + PHP or a container) — never `php artisan serve` in production.
- Nightly `mysqldump` written **off** the machine; test a restore once.
- Git repo is the source of truth; migrations rebuild the database from scratch; pin versions.
- The two domain engines — `AdmissionPolicy` (who gets in) and `PricingService` (what they
  pay) — live in exactly one place each. UI calls them; it never re-implements them.
