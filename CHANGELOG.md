# Changelog

All notable changes to Portico are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/).

Versions are annotated git tags on `main` (`vMAJOR.MINOR.PATCH`). While Portico is `0.x`, a
minor release may include a database migration and small breaking changes; a patch release
is fixes only.

## [Unreleased]

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

[0.1.0]: https://github.com/AsteriaCreations/portico/releases/tag/v0.1.0
