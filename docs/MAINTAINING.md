# Maintaining Portico

This guide is for whoever looks after the Portico repository itself: reviewing pull requests,
keeping dependencies current, and cutting releases. Writing code is covered by
[`CONTRIBUTING.md`](../CONTRIBUTING.md), and running a club's copy by
[`ADOPTING.md`](ADOPTING.md) and [`DEPLOYMENT.md`](DEPLOYMENT.md). Read those first. This file
covers the work between them.

Portico is maintained by volunteers for volunteers. Every rule here exists to keep that work
small: few dependencies, one branch, and one place for each decision.

## 1. How the repository is set up

GitHub settings live in GitHub, not in files, so a fork or a fresh copy doesn't carry them. If
you ever rebuild the repository, set these again.

| Setting | Value | Why |
|---|---|---|
| Merge methods | **Squash only**; delete branch on merge | One commit per change on `main`, so `git log` reads as the changelog does. |
| Ruleset "Protect main" | No deletion, no force-push, linear history, PR required, review threads resolved | `main` changes only through a reviewed PR. |
| Required checks (strict: branch must be up to date) | `Pest (PHP 8.4)`, `Migrate + seed (MariaDB)`, `Pint (code style)`, `Analyze (javascript-typescript)`, `Analyze (actions)` | See §2. A renamed CI job must be renamed here too, or every PR waits forever. |
| Ruleset "Protect release tags" (`refs/tags/v*`) | No deletion, no update, no force-push | A published version never moves. |
| Private vulnerability reporting | On | [`SECURITY.md`](../SECURITY.md) sends reports there. |
| Dependabot security updates, secret scanning, push protection | On | |
| Discussions | On | Questions and install help go there, not into Issues. |

[`.github/CODEOWNERS`](../.github/CODEOWNERS) requests a review automatically for changes to the
authorization boundary (policies, providers, middleware, `AdmissionPolicy`), CI, deploy
scripts and `composer.*`. Give those paths a slower read.

## 2. What CI checks

[`.github/workflows/ci.yml`](../.github/workflows/ci.yml) runs on every PR and every push to
`main`:

- **Pest (PHP 8.4)**: the whole suite on SQLite in memory. This includes the guard tests, which
  fail the build on a rule that review alone would miss:
  - `LocalizationGuardTest`: an unwrapped user-facing string, or a Resource/Page missing its
    translation trait.
  - `MoneyArithmeticGuardTest`: float math on money in `app/Services` or `app/Support`.
  - `PanelCssGuardTest`: a Tailwind class in a view the panel theme doesn't compile.
- **Migrate + seed (MariaDB)**: `migrate:fresh --seed` and the demo data on real MariaDB, then
  the whole suite there. SQLite hides a missing enum widening, a bad `->after()`, and every row lock, so this
  job is the only thing that tests them (`CheckInConcurrencyTest` skips itself on SQLite).
- **Build front-end assets**: `npm run build` succeeds. This job isn't a required check, so
  look at it before merging anything that touches `resources/` or `package.json`.
- **Pint**: code style.

[`codeql.yml`](../.github/workflows/codeql.yml) scans the JavaScript and the workflows on each PR
and weekly. It doesn't cover PHP, so authorization is still a human review job.

Every action is pinned to a commit SHA with the version in a comment. Dependabot keeps those
pins current; don't replace one with a tag.

## 3. Reviewing a pull request

Check, in roughly this order:

1. **Does it belong in Portico?** A club-specific rule belongs in that club's overlay or behind
   a setting. A new optional capability goes behind a feature flag that defaults **off**; a new
   setting defaults to the old behavior.
2. **Is the decision in the right place?** Admission goes in `AdmissionPolicy`, price in
   `PricingService`, occupancy in `CapacityService`, and check-in writes in `CheckInService`.
   Logic in a Filament page or a Blade view is the most common thing to send back.
3. **Is every write guarded on the server?** A hidden button isn't a guard. Each action closure
   should check its gate again. The test should prove a forged request fails, not just that the
   button is hidden.
4. **Ledgers stay append-only.** A correction to a payment, voucher, note or status is a new row.
   An edit or delete on one of those tables is a red flag.
5. **Money is integer cents** in services (`App\Support\Cents`), and nothing is hardcoded.
6. **A migration rebuilds from scratch and runs on MariaDB.** A PHP enum that gains a case needs a
   migration widening the MySQL `enum()` column.
7. **A `CHANGELOG.md` entry under `[Unreleased]`** in the same PR, written for a club's operator,
   not a developer. Say what changed for them and whether they need a migration, an
   `npm run build`, or a flag turned on.
8. **Docs** move with the behavior: `FEATURES.md`, `CONFIGURING.md` for a new flag or setting,
   the screen's "How to use this screen" panel, and `DATA_DICTIONARY.md` for a new column.
9. **No new dependency** without a reason a future volunteer would accept.

Squash-merge with a title that would read well in `git log`.

**Parallel PRs and the changelog.** Open PRs that all add to the same `[Unreleased]` subsection
conflict with each other as soon as one merges. Merge `main` into the branch and resolve; this
is routine, not a sign anything is wrong.

## 4. Dependencies

[`.github/dependabot.yml`](../.github/dependabot.yml) opens one grouped PR a week per ecosystem
(Composer, npm, Actions) for minor and patch bumps, and a separate PR for each major bump.

- **Grouped minor/patch PR:** if CI is green, skim the changelogs of Laravel, Filament and
  Livewire for anything flagged as a behavior change, then merge.
- **Major bump:** treat it as a real change. Read the upgrade guide, run the app, click through
  the check-in desk, and give it its own changelog entry if a club will notice.
- **Filament and Livewire** have broken subtle things before (toasts moving to a mailbox in
  Filament 5.11, for example). After bumping them, use the check-in desk in a browser, not just
  the tests.
- **The PHP platform pin.** `composer.json` sets `config.platform.php` (currently `8.4.27`) so
  `composer.lock` never pulls a package that needs a newer PHP than the clubs run. Dependabot
  can't see past it, so a package that needs newer PHP just stops updating. Raise the pin only
  when you're ready to tell clubs to upgrade PHP, which is a breaking change (see §6) unless
  it's for a PHP security fix.
- Clubs set `open-pull-requests-limit: 0` in their own copies ([`ADOPTING.md`](ADOPTING.md) §6),
  so every dependency update reaches them through a Portico release. Release reasonably often
  after a security bump.

## 5. Issues, Discussions and security reports

- **Discussions** are for questions, install help and ideas that aren't a concrete request yet.
  Move an issue that's really a question to Discussions instead of closing it cold.
- **Issues** come in through the bug and feature forms. Label them (`bug`, `enhancement`,
  `documentation`, `accessibility`, `good first issue`, `help wanted`). An accessibility barrier
  at the desk is a bug, not a nice-to-have: staff use screen readers.
- **Member data.** Ask reporters to redact names, dates of birth and notes from logs and
  screenshots. Edit the post yourself if they didn't.
- **Security reports** arrive as private advisories (Security tab). Acknowledge within a few
  days, fix on a private fork from the advisory, publish the advisory with the release, and
  credit the reporter if they want it. A report that only works with the app on the public
  internet is a deployment problem (see `SECURITY.md` "Scope"), but still answer it.
- **Scope.** Portico is LAN-only by design and has no member-facing internet surface: no online
  payments, no member portal. Decline requests for those politely and point to the
  [README](../README.md) and `SECURITY.md`.

## 6. Releases

The steps for cutting a release are in [`CONTRIBUTING.md`](../CONTRIBUTING.md) "Versioning &
releases". In practice:

- **When:** after a user-visible fix, after a dependency security update, or when `[Unreleased]`
  has a handful of features. Clubs take updates by merging a release, so smaller and more
  frequent releases are easier for them.
- **Which number:** anything that could change what an existing install does without the club
  choosing it is a **major** release. That includes removing a feature, setting, flag, `.env`
  key, command or role capability; a migration that drops data; or raising the minimum PHP or
  database version. When unsure, write the upgrade note first. If it says "you must…", it's
  probably major.
- **Upgrade notes:** at the top of the release section, list anything an operator must do beyond
  `scripts/deploy.ps1`: a new `.env` key, a flag to turn on, a scheduled task to add.
- After pushing the tag, publish the GitHub Release with that changelog section as the body and
  mark it **Latest**.

## 7. Recurring chores

| When | What |
|---|---|
| Weekly | Merge Dependabot PRs (§4). Answer new Discussions and issues. |
| After a UI change to a pictured screen | Retake the screenshot in `docs/screenshots/` (from the demo data, never a real club's). |
| A new screen | Its "How to use this screen" panel; a desk-reference card if staff use it live during a shift. |
| A new PHP minor version | Add it to the CI matrix first, and move the platform pin only when clubs are ready. |
| Yearly, or when handing over | Check that the settings in §1 still match, that the "Protect main" required checks match the CI job names, and that `CODEOWNERS` names someone who still reads their notifications. |

## 8. Handing over

If you step back, give at least one other person admin on the repository, update `CODEOWNERS`
and the maintainer handle in it, and say so in a pinned Discussion. A project with one person
holding the keys is one lost phone away from being unmaintainable.
