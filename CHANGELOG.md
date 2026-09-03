# Changelog

All notable changes to Portico are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/); this project does not use semantic
version tags yet — run a recent commit on `main`.

## [Unreleased]

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
