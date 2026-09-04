# Portico — Feature Inventory

A flat list of what's built, by area. For *why* each works the way it does, see the
commit history and [`BLUEPRINT.md`](BLUEPRINT.md).

CORE ENGINES
------------
- Admission Policy — single-source decision engine: bans, deceased status, watchlist warnings, under-18/under-21 age checks, ban exceptions, missing-paperwork capture
- Pricing Service — comp categories, subscription coverage (regular + pool), vouchers, per-event comp, add-ons, pool day passes
- Capacity Service — building-wide occupancy tracking across concurrent events, configurable venue capacity

CHECK-IN DESK
-------------
- Member-first check-in flow with live running price total
- Prospective member identity capture and promotion to Irregular
- Subscription payment collection at check-in (eligibility-gated)
- Standalone subscription purchase (independent of event check-in)
- Voucher redemption (account credit, cross-member)
- Per-event comp (Manager+ waives one visit's entry fee)
- Event Add-Ons (room rental, sleepover — priced extras)
- Pool day pass (single-visit pool coverage)
- Guest registration (sponsor-gated, full identity capture)
- Appears-under-21 enforcement on guest registration and Prospective promotion (DOB required only when checked)
- Overnight guest tracking (room rental / sleepover add-ons) — sponsor stays accountable, guest stays visible on Active Patrons until checked out, regardless of calendar days
- Prepay events (pay ahead of the event date)
- Back check-in / "mark arrived" roster for prepaid and comped attendees
- Auto-derived arrival time based on event's live/prepay status
- Register/cash box shift tracking (open/close, drops, misc. payments)
- Miscellaneous (off-book) payment recording
- Confirm-paperwork flow for missing-paperwork members

ACTIVE PATRONS (LIVE FLOOR VIEW)
---------------------------------
- Real-time roster of every arrived, non-departed patron across all currently-active events at once
- Recorded departures (anonymous headcount from the Dashboard + named, per-person via Active Patrons)
- Watchlist icon column + "watchlist only" filter for at-a-glance floor monitoring
- Visit notes — mutable, ephemeral (e.g. a clothing description), never surfaced once the patron drops off the live roster
- Behavior notes — append-only, permanent, tiered visibility: the author always sees their own note in full; another Volunteer sees only a count; DM and Door see every note's full text but not who wrote it; Manager+ sees full text and authorship
- Behavior-note history / oversight — Manager+ reviews every behavior note on a member (relation manager on Members), Admin+ reviews every behavior note written by a given user (relation manager on Users); a permanent audit trail that outlasts the live roster
- Member skill badges — skills assigned to a member render as chips on the live roster, visible to anyone who can see the page (Volunteer+)
- Signed-in staff noted alongside checked-in patrons — Volunteer+ users currently signed into the system (session-based) are flagged as "in the building" even when they haven't generated a check-in of their own

MEMBER MANAGEMENT
------------------
- Members, Categories, Plans, Event Types as full CRUD resources
- System-assigned member numbers
- Ban / watchlist / deceased / missing-paperwork status tracking
- Time-boxed suspensions (banned_until) with auto-expiry
- One-time per-event ban exceptions
- Computed probation status (derived, not stored)
- Append-only audit logs: status changes, username changes
- Username rename (audited, race-safe)
- Bulk member recategorization
- Email opt-in flag + bulk email CSV export
- Member status extract (filtered CSV export)
- Member skill tracking — a Skills catalog (Manager+) plus a member-to-skills association; assigning a skill to a member sits behind a dedicated Admin+ gate, above the Manager+ bar for the rest of member editing

EVENTS
------
- Event CRUD with fixed cost (Admin-only edits)
- Concurrent same-day events
- Midnight-spanning event detection (buffered window)
- Door-prepay flag for future-dated events
- Showrunner assignment per event
- Comp List (Manager-added comped attendees) distinct from Prepay List
- Comp-list due-date reminder + overdue indicator
- Event-ended notification (email + in-app) to Owner(s) and the assigned Showrunner, with an attendance/revenue summary (events:notify-ended, timer-driven, idempotent)

SHOWRUNNER COMP REQUESTS
-------------------------
- Showrunner nominates a member for comp on their assigned event
- Freeform reason support, resolved into a real reason at approval
- Admin/Owner approval queue with real-time notifications
- Excludes banned members (honoring granted exceptions)
- Event must be current/future (no reaching into past events)

SHOWRUNNER & INSTRUCTOR PAYOUTS
---------------------------------
- Showrunner door commission — tiered payout (flat voucher amount or percentage of door total) based on arrived headcount, configurable per-tier via Showrunner Payout Tiers; door total optionally includes pool/add-on revenue alongside cash and subscription-covered entry revenue
- Per-event-type instructor pay — configurable pay rate per event type, per coverage-source bucket (Instructor Pay Rates on Event Types), multiplied by arrived attendees in each bucket
- Both shown as read-only, Manager+ widgets on the event edit page; reporting/liability only, never auto-issued
- Independently feature-flagged (showrunner_payouts_enabled, instructor_payouts_enabled)

ORTHOGONAL CAPABILITIES
--------------------------
- Capability system independent of the role hierarchy — a user can hold any combination, granted/revoked via a relation manager on Users
- Cleaning Crew capability gates a dedicated weekly Cleaning Checklist page, with per-task (not whole-list) completion that resets every calendar week

VOUCHERS & COMP REWARDS
-------------------------
- Append-only voucher ledger (account credit)
- Automated comp-reward vouchers (e.g., presenter comps) via scheduled command

ROLES & PERMISSIONS
---------------------
- Seven-tier role hierarchy: Showrunner < Volunteer < DM < Door < Manager < Admin < Owner
- DM — a further level of trusted volunteer above Volunteer, still short of Door's check-in/payment access; sees full behavior-note text on Active Patrons without authorship
- Server-side policy enforcement (not just UI hiding)
- Monthly Manager & Owner subscription perk (non-monotonic exception)

REPORTING & ANALYTICS
------------------------
- Tonight/weekly attendance overview
- Weekly per-event-type and per-category breakdowns
- 12-month revenue trend (Event/Subscription/Other)
- Voucher liability (outstanding credit owed)
- Subscription overview (active counts + revenue)
- Add-on revenue breakout
- Comp cost aggregation by reason
- Register variance trend
- Membership growth/composition (new members, category mix)
- Register shift revenue breakdown (Event/Subscription/Other)

SETTINGS & CONFIGURATION
---------------------------
- Membership Settings page (subscription threshold, probation period, venue capacity, opening float, event window buffer, PII-hide default)
- Owner-only organization display name — rebrands the admin panel (header/tab/login) for a club running this software under its own name; falls back to the default app name when unset
- Feature Flags page (vouchers, add-ons, showrunner comp requests, manager perk, suspensions, pool, prepay, register shifts, showrunner payouts, instructor payouts, visit notes, behavior notes)
- Role Labels page — relabel any of the seven role names for display (e.g. rename "Showrunner"/"DM" to a club's own terminology) without touching the underlying permission hierarchy
- Payment Methods settings (with register-shift requirement flag)
- Comp Reasons settings (with optional voucher-grant amount)
- Add-Ons catalog (with overnight-stay flag)
- Categories settings (with protected-name safeguards)
- Skills catalog

DATA IMPORT/EXPORT
---------------------
- Prepay List bulk upload
- Member status CSV export
- Bulk-email list CSV export

OPERATIONS
------------
- Nightly database backup job
- Demo data seeder for local development
- Windows Task Scheduler-driven commands (no Laravel scheduler)
