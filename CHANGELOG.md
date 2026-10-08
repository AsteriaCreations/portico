# Changelog

All notable changes to Portico are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/).

Versions are annotated git tags on `main` (`vMAJOR.MINOR.PATCH`). While Portico is `0.x`, a
minor release may include a database migration and small breaking changes; a patch release
is fixes only.

## [Unreleased]

### Security

- Filament updated to 5.8.3. Before 5.8.2, managing app-based multi-factor authentication didn't
  ask for the password again (medium-severity advisory). Portico doesn't turn on Filament's MFA,
  so nothing was exposed, but the update clears the Dependabot alert.

### Fixed

- Managers can now manage an event's Prepay list and Comp list. Editing an event is Admin-only,
  and the edit page was the only way to reach those tabs, so in practice only Admins could fill
  them. Events now have a **View** page: Managers open it from the Events list (row click or
  **View**), see the event's fields read-only, and use the Attendance, Prepay list and Comp list
  tabs as before. Archived events stay read-only there too. Admins still get **Edit**.
- An event's View page no longer shows the edit page's warning that "changing the fees won't
  change what they paid": nothing can be changed there.
- After **Save & promote to Irregular** at the Check-In Desk, the member card now shows
  **Irregular** straight away. It kept saying Prospective until the page was reloaded.
- Several behavior notes on one visit now show one per line on Active Patrons, newest first.
  They used to run together into a single line, with nothing showing where one note ended.

### Added

- Prepaid cash is now kept apart from the night it was taken until the event itself. Cash taken
  at the Check-In Desk for a future door-prepay event still counts as received on that shift,
  but comes off the box's expected close: the desk is told to put it in the event's prepay
  envelope, and the close-out envelope reminder lists one envelope per event, apart from the
  night's own. The box summary and the Register shifts list show it as **Prepaid**, separate
  from **Event**. Cash taken on an event's Prepay List counts toward the same held total. The
  Prepay List tab shows how much prepaid cash is held for the event, and on the night the
  desk's box summary shows what's held for tonight. Refunding a held prepay points at that
  cash, not a drawer. New `attendance.prepaid_ahead` and `visit_removals.prepaid_ahead`
  columns. Prepays recorded before this update stay in the box they were taken on.

- **Pay the instructor from the Check-In Desk.** For tonight's events whose type has Instructor
  Pay Rates, the desk now shows Door and up the running payout ("Sunrise Yoga instructor payout
  so far: $40.00 (5 people)") with its per-coverage breakdown. It updates as people check in.
  **Pay instructor** in the Cash box records the cash handed over. The amount starts at the
  calculated total but can be changed (e.g. rounding), and the calculated figure is kept beside
  it. Each event's instructor can be paid once; after that the desk shows who paid what and when.
  The payout comes off the box's expected cash, so the drawer still balances at close. The box
  summary and close-box envelope note show the amount paid out. A Manager can fix a wrong payout
  with **Correct instructor payout**: a further amount (negative if the instructor handed money
  back) with a required reason. With a correction on file, the desk and the event page also show
  the **net paid**. A payout corrected back to $0 shows as "reversed — not paid", the running
  payout reappears, and **Pay instructor** opens up again. The event page's Instructor payout box
  now shows what was paid at the desk. Paying needs an open box, and is off along with `instructor_payouts_enabled`.
  **Upgrading:** run `php artisan migrate` (1 new migration) and `npm run build`.
- **Check voucher file** on Vouchers (Admins and up): runs a voucher spreadsheet through the bulk
  upload's own rules without issuing anything, then downloads a results file. Every row is
  listed with the member it matched and either "OK" or the problem that would make the upload
  skip it: no such member, a bad amount, a blank reason, a voucher already on file, or a repeat of
  an earlier row in the same file. Fix the file, check again, then upload.
- Staff can turn club email off: **Email me club communications** on My account (Admins can
  set it on a user's form too). It's on by default, so nothing changes until someone turns it
  off. Off means no event-ended summary email, or any later staff email; the in-app notification
  (the bell) still arrives. Members' own "OK to email" setting is separate and unchanged.
  **Upgrading:** run `php artisan migrate` (1 new migration).
- **My account**, in the user menu (top right) for every role. Sign-in emails are often made-up
  names nobody reads, so you can now enter a **preferred email for communication**. The
  event-ended summary (today the only email staff get) goes there instead, and so will any staff
  email added later. Leave it blank to keep using the sign-in email. Changing it asks for your
  current password. Admins can set it on a user's form too. The sign-in email itself still changes
  only on the Users screen. **Change password** is now in the user menu too, so anyone can
  change their password whenever they like, not just when an Admin flags them.
  **Upgrading:** run `php artisan migrate` (1 new migration).
- Kiosk check-in, part 4: email members their kiosk QR code, so they don't have to come to the
  desk for it. "Email kiosk QR code" sits next to "Kiosk QR code" at the Check-In Desk (Door and
  up) and on the member's page; training mode sends nothing. "Email kiosk codes to subscribers"
  on Subscriptions (Managers and up) emails this month's subscribers who have an email address,
  are opted in to club email and haven't been sent their code yet. It sends 50 per click; click
  again for the next batch, and nobody gets it twice. Replacing a member's code makes them due
  again. The code is an image inside the email, not a link, so it works offline at the door.
  Until the server has real email (`MAIL_MAILER=log`), a single send only reaches the log and
  isn't counted, and the bulk send refuses to run. `docs/DEPLOYMENT.md` §5 now covers sending
  through a club Gmail account with an App Password. **Upgrading:** run `php artisan migrate`
  (1 new migration).
- Kiosk check-in, part 3: the kiosk screen at `/kiosk`, for a tablet by the door. It uses the
  tablet's camera to read a member's kiosk QR code, then shows "Welcome", "Already checked in"
  or "Please see the front desk" for a few seconds before scanning again. With no event running
  it says so, asks for no camera, and checks again every minute. An Admin links the tablet with
  the new **Set up a kiosk tablet** button on Feature Flags: it shows a link, also as a QR
  code, that is opened once on the tablet. The server checks the key and keeps it on the tablet
  as a cookie that page scripts can't read, and the key leaves the address bar. Setting up
  again issues a new key, and the old tablet stops working. Turning
  kiosk check-in on adds a bell reminder to do this. The page needs HTTPS (browsers allow the
  camera only there) and a front-end build; see "A kiosk tablet" in `docs/DEPLOYMENT.md`. New
  front-end package: `jsqr`. **Upgrading:** run `npm install && npm run build`, which
  `deploy.ps1` does unless `-SkipNpm` is passed.
- Kiosk check-in, part 2: the endpoint the kiosk tablet scans codes into (`POST /kiosk/scan`), so
  nothing changes until part 3's kiosk screen exists. A scan checks the member in only when
  everything is clear: exactly one event running, not already checked in, an admission decision
  with no warning or flag, room in the building, an active subscription for this month, and
  nothing to pay. The visit is recorded at $0 by the system user with a new **Kiosk** payment
  method, which is inactive so no picker offers it. Anyone else is told to see the front desk.
  When staff need to act (watchlist, ban, missing sign-up or paperwork, under the alcohol-flag
  age, building full), every active Door and up gets a bell notification naming the member; it
  never includes a watchlist or ban reason. The endpoint answers only with kiosk check-in on and
  the tablet's device secret (a cookie set when part 3 links the tablet), and is rate limited.
  **Upgrading:** run `php artisan migrate` (1 new migration).
- "Kiosk check-in" on Feature Flags (off by default), the first part of self check-in at a kiosk
  for subscribers. When on, the Check-In Desk (Door and up) and each member's page show a "Kiosk
  QR code" button. It opens the member's code with a link to a printable card, and a photo of
  the screen works too. The code is created the first time it's opened and reused after that, so
  a printed card keeps working. Managers and up can "Replace kiosk code" on the member's page
  when a card is lost, and the old one stops working straight away. The code only identifies
  the member: the kiosk itself (coming next) will re-check admission, subscription and capacity
  on every scan. Training mode shows an existing code but never creates one. **Upgrading:** run
  `composer install` (one new package, `endroid/qr-code`, which draws the codes using the
  already-required `gd` extension) and `php artisan migrate` (1 new migration).
- "Bulk upload users" and "Download user template" on Users (Admin+). Each row gives a name, email,
  role and optional linked member; rows follow the Users form's rules (unique email, only roles you
  may grant, a member not already linked) and bad rows are skipped and logged. Every new account
  gets a random temporary password, downloaded once as a CSV straight after the upload (never
  saved on the server), and must choose its own at first sign-in. An email that already has an account is skipped, so
  re-uploading a file never creates anyone twice.
- "Selectable at the Check-In Desk" on each payment method. The desk's payment pickers (check-in,
  subscription sale, day pass) offer only methods with it on. It's **off for "Other"** after
  upgrading, since picking Other at check-in still records the full entry as paid. "Record other
  payment" and the Manager edit forms still list every active method.
- "Door can rename usernames" on Feature Flags (off by default). When on, Door staff get a
  "Rename username" button for the selected member on the Check-In Desk. It uses the same
  duplicate check and audit log as the Manager rename on the member's page, updates the Member
  box to the new name straight away, and saves nothing in training mode. Each Door rename sends
  every active Manager, Admin and Owner a bell notification with the old and new username and who
  made the change.
- "Cash envelope reminder on closing the box" on Feature Flags (off by default). When a box closes
  with cash collected, the confirmation stays on screen and tells staff to seal the cash in an
  envelope labelled with its Entry / Subscription / Other split and the event(s) it covers (or the
  close date if there were no visits). Only cash payment methods count, and the split always adds
  up to the cash received. It's a reminder only; nothing extra is saved.
- Watchlist review dates. Manager+ can give a watchlist entry an optional review date (blank =
  stays on indefinitely); the Members nav badge counts entries whose date has arrived, with a
  matching table filter. An Owner resolves a review from the member's edit page: remove (optionally
  starting watchlist probation), extend to a new date, or keep on indefinitely. Each decision is
  kept in an append-only `watchlist_reviews` log.
- Watchlist probation, set on Membership Settings. By default it's the same as new-member
  probation (same length, same guest rule); a club can pick Custom (its own length and guest rule)
  or Off. A member on it shows "Recently off watchlist" at the Check-In Desk and on Active Patrons;
  it never changes the admit decision.
- Same-night "Convert entry to subscription" at the Check-In Desk (Manager+), for a
  subscription-eligible member who paid a door entry and then wants to subscribe. It records the
  subscription and re-prices the visit's entry with the subscription credit, on the visit's own
  register shift and payment method, and shows what to collect or refund. Every conversion needs a
  reason and is kept in an append-only `payment_corrections` log (Records → Payment corrections,
  and on the member's page). Active Owners are notified.
- Audited removal of a paid visit. "Remove" on an attendance tab takes a $0 visit off quietly, as
  before; a paid one needs a reason, is kept in an append-only `visit_removals` log (Records →
  Visit removals), and notifies active Owners. A Manager can do it while the visit's register shift
  is open (the refund comes out of that drawer); once it has closed, only an Owner can, and the
  closed shift's expected cash and variance don't change.

### Changed

- The Check-In Desk's **Buy Day Pass** now only offers tonight's events. A pass sold for a later
  event went into tonight's box with nothing tying it to that event. A club that does sell them
  ahead can turn on **Day passes for later events** on Feature Flags (off by default). New
  `membership_settings.day_pass_future_events_enabled` column.

- The close-box envelope reminder now gives subscription cash its own envelope, labelled
  "Subscriptions" with the close date. The night's envelope holds entry and other cash only.

- The Prepay List's **Payment method** is now a pick from the club's payment methods instead of
  free text, so a cash prepay can be recognised as cash.

- **Mark follow-up sent** on the Members list now also moves each guest to Irregular: once the
  club has welcomed them, they're a standard member. **Mark follow-up not sent** moves anyone still in
  Irregular back to Guest (someone recategorized since keeps their category), and the "already sent"
  follow-up filter now lists welcomed guests whatever their category. No migration.
- New-member probation now starts on the member's first entry (first arrived check-in, where a new
  member first does paperwork) instead of Date Vetted, which no longer affects probation. A member
  who has never been in isn't on probation; "Probation start override" still wins when set. No
  migration: probation is computed, so every member's status moves to the new rule on deploy.
- A member flagged missing paperwork is now re-recorded at the Check-In Desk, not just ticked off.
  "Confirm paperwork on file" became "Record new paperwork": it opens the member's preferred name,
  first/last name and email pre-filled for staff to check against the new paperwork (plus DOB if
  they appear under 21), then records the Standard Paperwork signing and clears the flag. Door can
  do this, the same bounded write as completing a Prospective's sign-up; the category is unchanged.
  The desk's status line now reads "Record new paperwork to admit" (not "Finish sign-up to admit")
  for such a member, and flags them "Missing paperwork".
- New members start out active. The Members form's "Is active" switch now defaults on, matching
  the database default; before, a member created without touching it was saved inactive.
- Deleting a visit no longer bypasses the rules. The row Delete on every attendance tab is now
  "Remove", and the bulk delete only removes visits with nothing paid. A visit that has voucher
  activity, behavior notes, a comp request or a payment correction can't be removed at all, with a
  message saying why; before, that crashed with a database error. **Upgrading:** run
  `php artisan migrate` (1 new migration).

- Only an Owner can take a member off the watchlist, on every save path. Manager+ can still put
  members on it. **Upgrading:** run `php artisan migrate` (4 new migrations; nothing changes
  until someone sets a review date, and probation only starts when an Owner removes someone with
  it ticked). An install that already set its own watchlist probation length keeps it as Custom.
- Amount paid and payment method are now read-only on every attendance edit form (a member's
  Attendance tab, and an event's Attendance, Prepay List and Comp List tabs). A hand edit there
  silently changed a register shift's expected cash. **Upgrading:** run `php artisan migrate`
  (1 new migration).
- `/` now redirects to the admin panel (the sign-in page when signed out) instead of showing
  Laravel's stock welcome page. The welcome page, the empty `resources/css/app.css` and
  `resources/js/app.js`, and the Vite font setup only it used are removed, so `npm run build`
  now builds just the panel theme. That also ends the build-time download of a web font from
  Bunny Fonts and the "optimized font fallbacks require fontaine" warning. The panel is
  unaffected: Filament ships its own font.

### Fixed

- "Run backup now" and "Reset member & event data" on Technical no longer show a bare "error"
  page when the backup folder can't be created or written. They now say "Backup failed" or
  "Reset failed — nothing was deleted", naming the folder and the Windows account that couldn't
  write to it, and the reason also shows on the Scheduled Jobs panel. This usually happens
  because those buttons run as the web server's account (often `LocalSystem`), not the nightly
  task's account; see "Backups" in `docs/DEPLOYMENT.md`. The reset was never at risk: it
  deletes nothing unless the backup succeeds.

- "Run update now" on Upstream Updates no longer shows "Error while loading page" while the update
  runs. The update stops the web server, so the page's automatic refresh fails until the server is
  back. The page now says the server is restarting and that it will reconnect on its own. The
  update itself was never affected.

## [0.3.0] — 2026-09-26

**Upgrading from 0.2.0:** run `php artisan migrate` (12 new migrations; every new setting defaults
to the old behavior), and build the front-end assets with `npm run build`, which the new panel
theme needs (`scripts/deploy.ps1` does both; don't pass `-SkipNpm` this time). Then run
`php artisan attendance:find-stray-fees` once: a read-only report of past check-ins charged a
transaction fee with nothing due (see Fixed).

### Added

- **Setup reminders when a feature is turned on.** Saving Feature Flags with a feature newly
  switched on sends the person who saved it a notification (the bell) for each setup step it
  still needs, with an **Open** button to that screen. For example, turning on Pool reminds you to
  set the Pool subscription price and link the Pool Waiver; Register shift tracking reminds you to
  add registers and mark cash as needing the register. Steps already done, and screens the person
  can't open, are skipped. The list lives in `App\Services\FeatureSetupReminders`. No migration.

- **Guest follow-up** on the Members list, for sending newly registered guests whatever the club
  sends them (welcome email, waiver, membership info). A **Guest follow-up** filter lists guests
  not yet sent it (or already sent), and a **Registered** date-range filter narrows by sign-up
  date. The **Mark follow-up sent** bulk action records when and by whom, skipping non-guests;
  **Mark follow-up not sent** undoes it. Manager+, like the rest of the list. **Export member list
  (CSV)** gains Registered, Sponsor and Guest Follow-up Sent columns. New
  `members.guest_followup_sent_at` / `guest_followup_sent_by` columns: **run `php artisan migrate`**.

- Membership Settings → **Max guests per member per night**: caps how many new guests one member
  may register at the Check-In Desk in a night. Counted from the member's check-in that night,
  so an event running past midnight doesn't reset it, and returning guests who check in under
  them don't count. At the limit, the desk shows "Guest limit reached" in place of **Register
  a guest**; the limit is re-checked with the sponsor's row locked, so two registers can't both
  go over. Blank means no limit, as before (`Member::hasGuestAllowanceLeft()`). New
  `membership_settings.max_guests_per_night` column: **run `php artisan migrate`**.

- Membership Settings → **Week starts on**: the first day of the club's week, for the "this
  week" Analytics figures and the Cleaning Checklist reset. Blank follows the language (Monday
  in English), which is what they always did. All week boundaries now go through
  `MembershipSetting::startOfWeek()` / `endOfWeek()`. New `membership_settings.week_starts_on`
  column: **run `php artisan migrate`**.

- Membership Settings → **Require email at sign-up**: turn off for a club that doesn't collect
  email at the door. The Check-In Desk then accepts a Prospective's sign-up or a new guest
  without one, and a Prospective with no email no longer counts as needing sign-up. On by
  default, so upgrading changes nothing. New `membership_settings.member_email_required`
  column: **run `php artisan migrate`**.

- Membership Settings → **Count attended events from the last (months)**: an optional window
  for subscription eligibility, so only recent attendance counts toward the threshold. Blank
  counts all-time, as before. The Check-In Desk's "(3/5 events attended)" note uses the same
  count (`Member::eligibilityAttendanceCount()`) and names the window when one is set. New
  `membership_settings.subscription_eligibility_window_months` column: **run `php artisan
  migrate`**.

- Membership Settings → **Watchlist: who staff notify**: set the "Notify …" channel name
  from the admin panel instead of `WATCHLIST_NOTIFY_LABEL` in `.env`. Blank keeps using the
  `.env` value, so upgrading changes nothing. Read through
  `MembershipSetting::watchlistNotifyLabel()`. New `membership_settings.watchlist_notify_label`
  column: **run `php artisan migrate`**.

- Feature Flags → **Guests**: turn off "Register a guest" on the Check-In Desk for a club
  that doesn't allow guests. Membership Settings → **Allow guests during probation**: lets
  a member still on probation register one. Defaults keep today's behavior (guests on,
  blocked during probation), and the rule lives in `Member::canSponsorGuests()`. New
  `membership_settings.guests_enabled` and `guests_allowed_during_probation` columns:
  **run `php artisan migrate`**.

- **A Filament panel theme** (`resources/css/filament/admin/theme.css`, registered with
  `->viteTheme()`). Filament's own stylesheet only contains its `fi-*` classes, so every
  Tailwind utility in the app's own views (`text-sm`, `mt-2`, `gap-4`, `grid`,
  `text-danger-600`, `sm:hidden`, …, 79 in all) had silently done nothing: the Check-In
  Desk's status rows, Active Patrons' header, several dashboard widgets and every "How to use"
  panel rendered with default browser styling. The theme compiles every class found under
  `app/Filament`, `resources/views/filament` and `resources/views/components`. **Deploy with
  `npm run build`** (`scripts/deploy.ps1` does it unless `-SkipNpm`); Node is no longer
  optional. The theme is only loaded once a build has produced it, so an install that never
  built assets keeps working exactly as before rather than failing on a missing Vite
  manifest. CI gains a job that builds the assets; tests run `withoutVite()`.
  `PanelCssGuardTest` now checks the theme scans every folder of panel views.

- Membership Settings → **Show staff roles on Active Patrons**: turn it off to list signed-in
  staff by name only in Active Patrons' "Also in the building" note, without each person's
  role. On by default, so upgrading changes nothing. New
  `membership_settings.active_patrons_show_staff_roles` column: **run `php artisan
  migrate`**.

- **Reset password** on a staff account (Users list and the user's edit page, Admin+):
  sets a random, readable temporary password (e.g. `Maple-Otter-4172!`, always passing the
  password rule), shows it once in a notification to pass on, signs the account out
  everywhere, and makes it choose its own password at next sign-in. Never available on
  your own account (use Change password) or one that outranks you. Deliberately not a
  shared default like "changeme", which anyone knowing the convention could use to sign in
  as a just-reset account first. New `App\Support\TemporaryPassword`,
  `User::resetToTemporaryPassword()` and `User::endSessions()`.

- **Archive events** (Admin+), instead of deleting them. An archived event leaves the
  check-in desk, Active Patrons, the Showrunner comp-request screen, day-pass sales and the
  default events list (an **Archived** filter shows it again), and becomes read-only: its
  form, tabs and comp-request approvals are locked, and a forged check-in is refused (in
  `CheckInService` too). Every attendance, payment and comp row is kept, and **Unarchive**
  restores it. A past event can always be archived; an upcoming one only while nobody is on
  it, so no prepayment is stranded. New `events.archived_at` / `archived_by` columns:
  **run `php artisan migrate`**. The ended-event summary skips an event archived before it
  ended; comp-reward vouchers are still granted for one archived after.

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

- The printable desk reference cards (Check-In, Active Patrons, Record Departures) are served at
  `/admin/desk-reference-cards` (sign-in required) and linked from the Dashboard, Check-In and
  Active Patrons help panels, each link jumping to its own card. Before, only someone with file
  access to the server could open them.
- Paperwork Types gets its "How to use this screen" panel, the one screen without one, and a
  test now fails the build if any admin screen ships without a panel.

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

- Users screen: a new account now requires a password change at first sign-in by default
  (whoever created it chose its password); the list gains an Active filter. Change
  password rejects reusing your current password, and signs out your other sessions while
  keeping this one.
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
  examples, fuller mkcert/TLS steps, and a post-launch smoke-test checklist (§8). (It
  first described Node as optional; the panel theme under Added now needs `npm run build`,
  and the guide says so.)
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
- The printable desk reference cards and every screen's help panel were checked against the
  code and corrected. The Check-In card follows the current desk (status line first, payment
  taken in the Check in dialog) and adds Mark arrived, Mark as returned, training mode and the
  cash box. Field and tab names on Comp Reasons, Payment Methods and Members now match the
  screens, and panels now cover bulk uploads, Duplicate, the personal-info toggle, one-time
  payment methods and transaction fees.
- Example text, test literals and the deploy docs no longer carry identifiers from the private
  deployment Portico was extracted from.
- The CodeQL job skips private repositories, where uploading results needs a paid GitHub
  feature, so a private fork no longer fails CI on every push. Unchanged on public repos.
- Dependency updates: `concurrently` 9 → 10 (dev only), `actions/checkout` 4 → 7 and
  `actions/cache` 4 → 6 in CI.
- Test-suite reliability: `FeatureFlagsTest` passes under `--parallel`, and the payment-method
  and member test factories no longer produce values that can collide with a unique index or
  overflow a column (random failures seen only on MariaDB CI). Test data only.

### Fixed

- With the Pool feature flag off, the Check-In Desk still said "Pool unavailable — Pool
  Waiver missing or expired" and offered **Record a waiver signature**. The waiver prompt now
  only appears for an add-on that's actually in play: still on sale, or charged by tonight's
  event (an older event whose pool fee predates the flag being turned off). It no longer
  appears for an inactive add-on either.
- The Check-In Desk's **Due** line left out the price of a subscription bought in the same
  check-in. It showed only the entry left after the subscription's credit, while the member
  was charged that plus the subscription. Due now includes it, priced by the same rule as
  the charge (`SubscriptionBundleService::quoteCents()`). The "This month — $X" option also
  shows today's plan price, which is what's charged, rather than the event date's.
- An install with no active Owner could never get one. The seeder creates only an Admin,
  and the rank rule stopped anyone from granting a role above their own, so no one could
  grant Owner. Now, while there's no active Owner, an Admin may grant Owner, to another
  account or their own (`User::canGrantRole()`, used by the role picker, `CreateUser`
  and `UserObserver`). Once an Owner exists, the normal rule applies again.
- The Check-In Desk's "Checked in tonight" list showed everyone twice: once as a stacked
  card above the table, once in it. The phone-card and table layouts were switched with
  Tailwind's `sm:hidden` / `hidden sm:block`, which weren't in the panel's compiled CSS (the
  app had no Tailwind build for its own views until the panel theme under Added), so both
  always rendered. The roster
  now switches them with a small scoped `<style>` block, and a new `PanelCssGuardTest` fails
  the build on any panel view using a show/hide utility the compiled CSS lacks.
- Deleting an event that had any check-ins, prepays, comp requests, day passes or ban
  exceptions (singly or by bulk delete) failed with a server error on the database's
  foreign keys. Delete now only appears for an event with nothing recorded against it, bulk
  delete skips the rest, and those events can be archived instead.
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
- The check-in desk showed no under-21 note for a member who was also watchlisted, an
  unfinished Prospective or missing paperwork, since the note was only ever the status headline
  and those outcomes outrank it. It's now always listed with the member's flags, and appears
  before an event is picked (as of today).
- An eligible member was offered two subscription pickers at the desk, "Regular Subscription"
  and "Entry Subscription", and picking both bought two. `AddOn::subscribable()` returned the
  Entry row, which every caller already handles separately; it now returns ordinary add-ons only.

### Security

- An Admin could take over the Owner role: nothing compared the acting user's rank with
  the account being changed, so an Admin could promote anyone (themselves included) to
  Owner, and edit, deactivate, delete or set the password of an Owner account. Now nobody
  can change an account, or grant a role, above their own: `UserObserver` refuses it on
  every save (including a direct model update), `UserPolicy` hides Edit/Delete/Reset on
  higher-ranked rows and 403s their edit page, the role picker stops at your own role, and
  `CreateUser` refuses a forged higher role. An Admin still manages Admins and below; only
  an Owner manages Owners. The self-edit check reads your saved role, so changing your own
  role in the same save can't raise it.
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

[0.3.0]: https://github.com/AsteriaCreations/portico/releases/tag/v0.3.0
[0.2.0]: https://github.com/AsteriaCreations/portico/releases/tag/v0.2.0
[0.1.0]: https://github.com/AsteriaCreations/portico/releases/tag/v0.1.0
