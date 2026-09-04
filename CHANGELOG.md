# Changelog

All notable changes to Portico are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/); this project does not use semantic
version tags yet — run a recent commit on `main`.

## [Unreleased]

### Changed

- Pool is now a real, editable `add_ons` catalog row (not a hardcoded second pricing
  lane) with a `subscribable` flag — any add-on a club flags `subscribable` gets a
  monthly Plan, coverage, and a day pass through the same mechanism Pool already used,
  instead of that being wired specifically to Pool. Entry's own fee logic (event fee,
  host bypass, per-visit credit) is unchanged; only the subscription-lookup side is
  unified onto one `add_ons`-backed FK. No user-visible change for a club running Pool
  today — see `docs/BLUEPRINT.md` "Add-ons" and "Fee pipeline".

### Added

- Initial public release. Portico is a member check-in and membership-management app for
  member clubs — concurrent multi-user door check-in with per-event payment tracking,
  built on Laravel + Filament v5.
- Core engines: `AdmissionPolicy` (admission decisions), `PricingService` (fee pipeline),
  `CapacityService` (building occupancy).
- Seven-tier role hierarchy, subscriptions, vouchers, guests, prepay events, per-event
  comp, comp lists, register-shift cash tracking, showrunner/instructor payouts,
  analytics, member skill tracking.
- Feature-flag mechanism (`/admin/feature-flags`) for opting out of optional features.
- See [`docs/FEATURES.md`](docs/FEATURES.md) for the full inventory.
