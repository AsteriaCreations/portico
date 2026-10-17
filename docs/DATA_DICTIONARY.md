# Data dictionary (for analysts)

What's in Portico's database and how to query it correctly. It's written for someone running
SQL or connecting a BI tool (Excel, Power BI, Metabase, …), not for app developers. The
migrations in `database/migrations` are the source of truth. If this page and a migration
disagree, the migration wins, and this page needs fixing.

- **Access:** use the read-only database account; see `docs/DEPLOYMENT.md` §4a
  ("Read-only access for reporting"). Never point a reporting tool at the app's own database
  user: that user can write.
- **Dialect:** MariaDB 10.6+ / MySQL 8. Examples below are MySQL.

## Ground rules: read these first

1. **Times are local wall-clock time, with no time zone.** Every `datetime` is stored in the
   app's `APP_TIMEZONE` (set in `.env`, for example `America/Chicago`), not UTC. Don't convert
   them.
2. **Money is `DECIMAL(8,2)`** in the club's currency (`membership_settings.currency`). Sum the
   decimals directly; don't cast to float.
3. **Some "dates" are datetimes.** `subscriptions.covered_month` and `member_paperwork.signed_on`
   are stored as `YYYY-MM-DD 00:00:00` on some installs. Compare with `DATE(col) = '2026-10-01'`,
   never `col = '2026-10-01'`.
4. **Many tables are append-only ledgers.** Nobody edits or deletes rows in them, so a
   correction is a new row with the opposite sign or a reversing meaning. Always aggregate the
   ledger; don't pick out "the" row. These are: `vouchers`, `occupancy_adjustments`,
   `ban_exceptions`, `member_status_changes`, `member_username_changes`,
   `attendance_behavior_notes`, `miscellaneous_payments`, `add_on_day_passes`,
   `attendance_add_ons`, `member_paperwork`, `watchlist_reviews`, `payment_corrections`,
   `visit_removals`, `instructor_payouts`, `register_drops`.
5. **Attendance rows are price snapshots.** Each row stores what was charged that night.
   Changing an event's fee or a plan's price later never rewrites old rows. Report from the row,
   not from today's prices.
6. **Some statuses are computed, not stored.** A few columns look like the answer but aren't on
   their own:
   - *Banned right now* = `is_banned = 1 AND (banned_until IS NULL OR banned_until >= CURDATE())`.
     `is_banned` alone includes suspensions that have already expired.
   - *On probation*, *subscription-eligible* and *occupancy* are derived; the recipes are below.
7. **The singleton:** `membership_settings` has exactly one row (`id = 1`). It holds the club's
   tunables (probation length, eligibility threshold, capacity, currency) and the feature flags.
   Join to it when a rule depends on a setting.
8. **Personal data:** see [Personal data](#personal-data) before exporting anything.

## Entity map

```
categories ─< members >─ sponsor (members, a guest's sponsor)
                 │
                 ├─< attendance >── events >── event_types
                 │      │             │
                 │      ├─< attendance_add_ons >── add_ons
                 │      ├── comp_reasons
                 │      └── register_shifts >── registers
                 │
                 ├─< subscriptions >── add_ons (which subscription), plans priced by add_on
                 ├─< vouchers (credit ledger)
                 ├─< member_paperwork >── paperwork_types
                 └─< member_status_changes, watchlist_reviews, ban_exceptions, …

users (staff logins) ── recorded_by / checked_in_by / changed_by on almost every table
```

One `attendance` row means one member at one event: `UNIQUE(member_id, event_id)`.

## Core tables

### `members`
One row per person who has ever been recorded: members, prospectives and guests.

| Column | Meaning |
|---|---|
| `member_number` | Club-facing number, assigned by the system. Unique, can be null for old imports. |
| `username` | Unique handle. Can be renamed; history is in `member_username_changes`. |
| `preferred_name`, `first_name`, `last_name`, `email` | Identity (**personal data**). A Prospective may have only a username. |
| `category_id` | → `categories`. *Prospective* (not yet signed up), *Irregular* (signed up), *Guest*, plus club-defined ones. `categories.is_comped = 1` means entry is always free. |
| `dob` | Date of birth (**sensitive**). Age-based flags are derived from it at read time. |
| `date_vetted` | Informational only; it no longer starts probation. |
| `is_active` | Soft "still a member" flag. Inactive members remain in history. |
| `subscription_eligible` | Manual override that makes a member eligible regardless of visit count. |
| `on_watchlist`, `watchlist_reason`, `watchlist_review_on`, `watchlist_probation_start` | Watchlist state (**the reason is sensitive**). |
| `is_banned`, `ban_reason`, `banned_until` | Ban; `banned_until` set = a suspension that ends on that date. See ground rule 6. |
| `is_deceased`, `missing_paperwork` | Status flags. History of every flag change is in `member_status_changes`. |
| `sponsor_id` | For a guest: the member who brought them. |
| `probation_override_start` | Manager-set probation start that replaces the first visit. |
| `email_opt_in` | Consented to club email. |
| `guest_followup_sent_at` / `_by` | When staff followed up with a guest. |
| `kiosk_token*` | Self check-in QR code (**secret**: never export). |
| `notes`, `hospitality_note` | Free text (**sensitive**). |
| `created_at` | When the row was created. Imported members all share their import date, so don't use it for "joined" on imported data. |

### `events`
One row per event night. Several events can share a date.

| Column | Meaning |
|---|---|
| `event_date` | The night it belongs to. |
| `starts_at`, `ends_at` | Times; `ends_at` may be the next day for an event that runs past midnight. |
| `checkin_closes_at` | When the desk and kiosk stopped taking new check-ins for this event. Defaults to an hour before `ends_at` for an event running past midnight, 30 minutes otherwise (both set in Membership Settings). Blank only on events that had ended before this column existed. |
| `name`, `event_type_id` | Label; `event_types` is an analytics taxonomy (Social, Class, …) and has no effect on price. |
| `entry_fee`, `pool_fee` | Price at the door *as currently set*. For what people actually paid, use `attendance`. |
| `workday_voucher_amount`, `workday_min_hours` | Set together on a workday event: an attendee whose `departed_at` is at least `workday_min_hours` after `checked_in_at` is granted a voucher of `workday_voucher_amount` once the event ends (a `vouchers` row with that `attendance_id`). Both blank = an ordinary event. |
| `comped_entry_fee` | What comped categories (Owner, Manager, Emeritus, Staff) pay for entry at this event. Blank = free. Their attendance rows still say `entry_covered_by = 'comp'`, with `entry_coverage` below `entry_fee`. |
| `door_prepay_enabled` | Desk may sell it before the night. |
| `showrunner_id`, `host_id` | → `members`. Host gets in free automatically. |
| `comp_list_due_at` | Reminder date only. |
| `archived_at`, `archived_by` | Archived events are hidden from the desk but remain in history. Usually keep them in reports. |

### `attendance`
One row per member per event: a visit, or a prepaid visit not yet used. **The central fact
table.**

| Column | Meaning |
|---|---|
| `checked_in_at` | When they arrived. **NULL = prepaid or comp-listed but not (yet) arrived.** Filter `checked_in_at IS NOT NULL` for "attended". |
| `departed_at` | When staff recorded them leaving (not always recorded). |
| `entry_fee` | The event's entry fee at the time. |
| `entry_coverage` | How much of `entry_fee` was waived. |
| `entry_covered_by` | Why: `none`, `comp` (comped category), `regular_subscription`, `event_comp` (manager waived this visit), `host`, `legacy_import`. |
| `voucher_coverage` | Amount paid with voucher credit (not money). |
| `amount_paid` | **Money actually taken for the visit**, see below. |
| `payment_method` | → `payment_methods.code` (a string, not an id). NULL when nothing was owed. |
| `comp_reason_id` | Set = on the event's **Comp List**; NULL with `checked_in_at` NULL = **Prepay List**. |
| `prepaid_ahead` | Paid at the desk on an earlier night than the event. |
| `register_shift_id` | Cash drawer session that took the money. |
| `checked_in_by` | → `users` (staff). |
| `visit_note`, `notes`, `on_behalf_note` | Free text. |

**What `amount_paid` contains:**
`(entry_fee − entry_coverage) + Σ subscribable add-on lines (fee − coverage) + Σ flat add-ons
(price) − voucher_coverage + the payment method's transaction fee`.
In other words, it's everything that changed hands for the visit in one figure. The transaction
fee isn't stored separately; `payment_methods.transaction_fee` is only today's setting. So
"entry revenue" vs "add-on revenue" needs `attendance_add_ons` (below), and is approximate
whenever a transaction fee applied.

### `attendance_add_ons`
One row per extra on a visit (Pool, room rental, sleepover, …). Name and price are copied in at
the time.

| Column | Meaning |
|---|---|
| `attendance_id` | → `attendance` (deleted along with the visit). |
| `add_on_id` | → `add_ons`; may become NULL if the add-on is later deleted, so use `name`. |
| `name`, `price`, `is_overnight` | Copied in at check-in. |
| `fee` | For a *subscribable* add-on (Pool): what it cost at this event. NULL for a flat add-on, whose charge is `price`. |
| `coverage`, `covered_by` | Subscribable lines only: amount waived and why (`none`, `comp`, `subscription`, `day_pass`, `legacy_import`). NULL for flat add-ons. |

Money from an add-on line = `COALESCE(fee, price) − coverage`.

### `subscriptions`
One row per member per subscription per covered month.
`UNIQUE(member_id, add_on_id, covered_month)`.

| Column | Meaning |
|---|---|
| `add_on_id` | Which subscription: the `add_ons` row with `kind = 'entry'` is the **Regular (entry)** subscription; others (e.g. Pool) are add-on subscriptions. |
| `covered_month` | First day of the month it covers (ground rule 3). A multi-month bundle is one row per month. |
| `amount_paid` | Money taken for this month; a bundle's price is split across its rows. |
| `voucher_coverage` | Part paid with voucher credit. |
| `paid_on`, `payment_method`, `register_shift_id` | When/how it was paid. |
| `comp_source` | Set when given free, e.g. `manager_monthly_perk` (the monthly staff perk). |

### `plans`
Effective-dated prices per subscription (`add_on_id`): `price`, `credit` (max entry it covers
per visit; NULL = covers it all), `duration_months`, `effective_from`/`effective_to`. The plan
in force on a date is the one where `effective_from <= date AND (effective_to IS NULL OR
effective_to >= date)`.

### `vouchers`
Account-credit ledger. **Positive `amount` = credit issued; negative = credit spent or a grant
corrected.** A member's balance = `SUM(amount)`. A spend links the `attendance_id` or
`subscription_id` it paid for. `reason` is free text. Comp-reward vouchers are issued
automatically after an event to comp-listed members who arrived (`comp_reasons.grants_voucher_amount`).

### Other money tables

| Table | One row per | Money column |
|---|---|---|
| `add_on_day_passes` | An add-on bought for one event outside a check-in | `amount_paid` |
| `miscellaneous_payments` | Other money at the desk (vendor, donation, …), with a `notation` | `amount` |
| `payment_corrections` | A same-night paid entry converted into a subscription (see below) | `old_amount_paid`, `new_amount_paid`, `subscription_amount`, `net_amount` |
| `visit_removals` | A deleted visit, a copy of what it had paid | `amount_paid` |
| `instructor_payouts` | Cash paid to an event's instructor out of the drawer (signed; corrections are further rows) | `amount` (`calculated_amount` = what the rule suggested) |
| `register_drops` | Cash taken out of the drawer mid-shift | `amount` |

**Payment corrections** are the one case where a visit's `amount_paid` is changed after the
fact. The visit is re-priced (`old_amount_paid` → `new_amount_paid`), a new `subscriptions` row
is created (`subscription_id`), and the correction row keeps the before/after. Revenue totals
stay right if you sum `attendance` + `subscriptions` as they are now.

**Visit removals:** a removed visit's `attendance` row is deleted, and a `visit_removals` row
keeps a copy (member, event, amount, method, shift). `after_shift_closed = 1` means the money
was *not* handed back; it's still in that night's takings. For takings that match the cash
drawer, add those rows back (see recipe).

### Cash drawer

- `registers`: the physical cash boxes.
- `register_shifts`: one open-to-close session: `opening_count`, `closing_count`, `opened_by`,
  `closed_by`, `closed_at` (NULL = still open).
- Expected cash at close = `opening_count` + cash-method takings on the shift (attendance,
  subscriptions, misc payments, day passes) − drops − instructor payouts − cash held for
  prepaid future events. Variance = `closing_count − expected`.
  A payment method is "cash" when `payment_methods.requires_register_shift = 1`.

### Lookups and settings

| Table | What |
|---|---|
| `categories` | Member categories; `is_comped`. *Prospective*, *Guest*, *Irregular* are fixed names the app relies on. |
| `event_types` | Event taxonomy for reporting. |
| `comp_reasons` | Why someone was comp-listed; `grants_voucher_amount`. |
| `payment_methods` | `code` (what other tables store), `label`, `requires_register_shift` (cash), `one_time_only`, `transaction_fee`, `active`. |
| `add_ons` | Extras and subscriptions. `kind = 'entry'` is the Regular subscription's anchor row; `subscribable`; `priced_per_event` (price comes from the event, e.g. `events.pool_fee`); `price`. |
| `add_on_event` | Which flat add-ons each event offers. |
| `paperwork_types` / `member_paperwork` | Forms and each signing (`signed_on`, ground rule 3). A type with `renewal_months` expires. |
| `skills` / `member_skill` | Skills tagged on members. |
| `instructor_pay_rates`, `showrunner_payout_tiers` | Payout rules. |
| `membership_settings` | The singleton (ground rule 7). |

### Audit and status history

| Table | Records |
|---|---|
| `member_status_changes` | Every flag flip: `status` ∈ `banned`, `watchlist`, `deceased`, `missing_paperwork`, `active`; `value` = new state; `reason`; `changed_by`. |
| `member_username_changes` | `old_username` → `new_username`. |
| `watchlist_reviews` | Owner decisions: `removed`, `extended`, `kept_indefinitely`. |
| `ban_exceptions` | A banned member allowed into one specific event. |
| `comp_requests` | Showrunner comp requests: `status` ∈ `pending`, `approved`, `rejected`; approved ones link the `attendance_id` created. |
| `attendance_behavior_notes` | Staff notes on a visit (**sensitive**). |
| `occupancy_adjustments` | Anonymous "N people left" counts per night (`delta`, usually negative). |
| `cleaning_tasks`, `cleaning_task_completions` | Weekly cleaning checklist. |

### Staff

`users` = staff logins: `role` ∈ `showrunner`, `volunteer`, `dm`, `door`, `manager`, `admin`,
`owner` (in increasing rank); `member_id` links a login to their member record. Extra
permissions are in `user_capabilities`. `password`, `remember_token` and the framework tables
(`sessions`, `cache*`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`,
`notifications`, `migrations`, `command_runs`) are not for reporting.

## Derived rules as SQL

**Attended (a real visit):** `attendance.checked_in_at IS NOT NULL`.

**Prepay List / Comp List for an event:** `checked_in_at IS NULL AND comp_reason_id IS NULL` /
`comp_reason_id IS NOT NULL`.

**Occupancy for a night** (who's in the building):
```sql
SELECT GREATEST(0,
  (SELECT COUNT(*) FROM attendance a JOIN events e ON e.id = a.event_id
    WHERE e.event_date = :night AND a.departed_at IS NULL)
  + (SELECT COALESCE(SUM(delta), 0) FROM occupancy_adjustments WHERE for_date = :night)
) AS occupancy;
```
(The app counts unarrived prepays too, because they hold a place under the capacity limit.)

**Subscription-eligible:**
```sql
SELECT m.id,
       m.subscription_eligible = 1
       OR COUNT(a.id) >= s.subscription_eligibility_threshold AS eligible
FROM members m
CROSS JOIN membership_settings s
LEFT JOIN attendance a ON a.member_id = m.id AND a.checked_in_at IS NOT NULL
     AND (s.subscription_eligibility_window_months IS NULL
          OR a.checked_in_at >= NOW() - INTERVAL s.subscription_eligibility_window_months MONTH)
GROUP BY m.id;
```

**On probation:** probation starts at `probation_override_start`, else the date of the first
arrived visit, and lasts `membership_settings.probation_period_days`.
```sql
SELECT m.id,
       CURDATE() < COALESCE(m.probation_override_start, DATE(MIN(a.checked_in_at)))
                   + INTERVAL s.probation_period_days DAY AS on_probation
FROM members m
CROSS JOIN membership_settings s
LEFT JOIN attendance a ON a.member_id = m.id AND a.checked_in_at IS NOT NULL
GROUP BY m.id;
```

**Has a Regular subscription for a month:**
```sql
SELECT 1 FROM subscriptions sub JOIN add_ons ao ON ao.id = sub.add_on_id AND ao.kind = 'entry'
WHERE sub.member_id = :member AND DATE(sub.covered_month) = '2026-10-01';
```

**Voucher balance owed to members (liability):** `SELECT SUM(amount) FROM vouchers;`

## Common queries

**Monthly revenue, the same way the Analytics page counts it.** Attended visits are counted by
arrival month, subscriptions by `paid_on`, and other money by `created_at`. Prepaid no-shows
aren't counted.
```sql
SELECT DATE_FORMAT(d, '%Y-%m') AS month, src, SUM(amt) AS revenue FROM (
  SELECT checked_in_at AS d, 'event' AS src, amount_paid AS amt FROM attendance WHERE checked_in_at IS NOT NULL
  UNION ALL SELECT paid_on, 'subscription', amount_paid FROM subscriptions WHERE paid_on IS NOT NULL
  UNION ALL SELECT created_at, 'other', amount FROM miscellaneous_payments
  UNION ALL SELECT created_at, 'other', amount_paid FROM add_on_day_passes
) x GROUP BY month, src ORDER BY month, src;
```

**Takings by payment method for one night**, matching the drawer: include removals made after
the shift closed.
```sql
SELECT payment_method, SUM(amount_paid) FROM (
  SELECT a.payment_method, a.amount_paid FROM attendance a JOIN events e ON e.id = a.event_id WHERE e.event_date = :night
  UNION ALL SELECT vr.payment_method, vr.amount_paid FROM visit_removals vr JOIN events e ON e.id = vr.event_id
    WHERE e.event_date = :night AND vr.after_shift_closed = 1
) x GROUP BY payment_method;
```

**Attendance and revenue by event type:**
```sql
SELECT et.name, COUNT(*) AS visits, SUM(a.amount_paid) AS revenue
FROM attendance a JOIN events e ON e.id = a.event_id LEFT JOIN event_types et ON et.id = e.event_type_id
WHERE a.checked_in_at IS NOT NULL AND e.event_date BETWEEN :from AND :to
GROUP BY et.name ORDER BY visits DESC;
```

**How entry was covered** (comp vs subscription vs paid):
```sql
SELECT entry_covered_by, COUNT(*) AS visits, SUM(entry_coverage) AS waived
FROM attendance WHERE checked_in_at IS NOT NULL GROUP BY entry_covered_by;
```

**Active Regular subscribers per month:**
```sql
SELECT DATE_FORMAT(sub.covered_month, '%Y-%m') AS month, COUNT(DISTINCT sub.member_id) AS subscribers
FROM subscriptions sub JOIN add_ons ao ON ao.id = sub.add_on_id AND ao.kind = 'entry'
GROUP BY month ORDER BY month;
```

**New members per month** (first arrived visit, which is more reliable than `created_at`):
```sql
SELECT DATE_FORMAT(first_visit, '%Y-%m') AS month, COUNT(*) AS new_members FROM (
  SELECT member_id, MIN(checked_in_at) AS first_visit FROM attendance WHERE checked_in_at IS NOT NULL GROUP BY member_id
) f GROUP BY month ORDER BY month;
```

**Retention: members who came back within 90 days of their first visit:**
```sql
SELECT COUNT(*) AS first_timers,
       SUM(EXISTS (SELECT 1 FROM attendance b WHERE b.member_id = f.member_id AND b.checked_in_at IS NOT NULL
                   AND b.checked_in_at > f.first_visit AND b.checked_in_at < f.first_visit + INTERVAL 90 DAY)) AS returned
FROM (SELECT member_id, MIN(checked_in_at) AS first_visit FROM attendance WHERE checked_in_at IS NOT NULL GROUP BY member_id) f;
```

**Prepaid no-shows for past events:**
```sql
SELECT e.event_date, e.name, COUNT(*) AS no_shows, SUM(a.amount_paid) AS paid
FROM attendance a JOIN events e ON e.id = a.event_id
WHERE a.checked_in_at IS NULL AND a.comp_reason_id IS NULL AND e.event_date < CURDATE()
GROUP BY e.id ORDER BY e.event_date DESC;
```

## Personal data

Keep these out of shared exports and dashboards unless there's a reason and the club agrees:

- **Identity:** `members.first_name`, `last_name`, `email`, `preferred_name`, `dob`. Report by
  `member_number` or `members.id` instead.
- **Sensitive (Manager+ in the app):** `members.dob`, `watchlist_reason`, `ban_reason`, `notes`,
  `hospitality_note`; `attendance_behavior_notes.note`; `member_status_changes.reason`;
  `watchlist_reviews.notes`.
- **Secrets, never export:** `members.kiosk_token`, `users.password`, `users.remember_token`,
  `sessions.*`, `membership_settings.kiosk_device_secret_hash`.

`docs/DEPLOYMENT.md` §4a gives two read-only accounts. The *full* one can see everything above.
The *limited* one can't see the personal or secret columns at all (MySQL column-level grants),
so it's the one to hand to an outside analyst.
