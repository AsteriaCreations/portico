# Adopting Portico for your club

This guide is for a club that wants to run Portico as its own system and keep taking updates
from this project. It covers how to set up your copy of the code, what belongs to you and what
stays shared, and how to pull in each release without losing your changes.

It is not the install guide. Standing the app up on a machine is
[`DEPLOYMENT.md`](DEPLOYMENT.md), and setting it up for your club's rules is
[`CONFIGURING.md`](CONFIGURING.md). Read this first, so you set up the repository the right way
before you install.

---

## 1. Pick a path

**A. Run Portico unchanged.** Clone this repository on your server, check out a release tag, and
do all of your setup through `.env` and the in-app settings. Updating is checking out the next
tag. This is the simplest path, and enough for many clubs.

**B. Your own repository, tracking Portico.** You keep a repository of your own whose `main` is
Portico's `main` plus a thin layer of your files (your runbook, your web server config, maybe a
one-off import script). You pull in Portico's releases with an ordinary `git merge`. Pick this
as soon as you have anything you want under version control that isn't Portico's.

You can start on A and move to B later: the steps in §2 work from any Portico checkout.

Either way, all of Portico's features are configured, not coded. Before you write code for
something your club needs, check the feature flags and settings in `CONFIGURING.md`; it is
probably already a switch.

## 2. Setting up your own repository (path B)

**Don't use GitHub's Fork button.** A fork of a public repository stays public, and your copy
will hold your club's runbook and server details. Create a separate private repository instead
and point it at Portico as a second remote. Because you start from a clone of Portico, your
history and Portico's are already shared, so later updates are plain merges.

1. Create an **empty** private repository (no README, no license, no `.gitignore`), for example
   `yourclub/membership`.
2. Clone Portico and point it at your new repository:

   ```
   git clone https://github.com/AsteriaCreations/portico.git yourclub-membership
   cd yourclub-membership
   git remote rename origin portico
   git remote add origin https://github.com/yourclub/membership.git
   git push -u origin main
   ```

3. Every clone needs the `portico` remote added once (it isn't carried by `git clone`). That
   includes the server, if it pulls from your repository:

   ```
   git remote add portico https://github.com/AsteriaCreations/portico.git
   git fetch portico
   ```

4. In your repository's GitHub settings, under **Pull Requests**, keep **Allow merge commits**
   ticked. Updates from Portico must be merged with a merge commit (§5).

## 3. What's yours and what's shared

The rule that keeps updates painless: **add your own files, don't edit Portico's.** Every edit
to a shared file is a possible conflict on every future update. Your layer of files (the
"overlay") should be small.

| Yours | Where | Notes |
|---|---|---|
| Your install's identity | `.env` (never committed) | See §4. |
| Your runbook | a new file, such as `docs/RUNBOOK.md` | Your machine names, accounts, scheduled tasks, network, and your update history (§5). Portico's `DEPLOYMENT.md` stays the generic procedure; write only what's specific to your box. |
| Your web server config | a new file, such as `scripts/apache/yourclub.conf` | Copy `portico.conf` or `portico-tls-example.conf` and pass it to `setup-apache.ps1 -ConfFile`. |
| Club-only code | new files only | For example, an Artisan command that imports your old spreadsheet once. Keep it in its own files so Portico's never change under it. |
| Dependabot | `.github/dependabot.yml` | The one shared file worth editing (§6). Keep your version if an update conflicts on it. |

Everything else (`app/`, `resources/`, `database/`, `tests/`, the other docs) is shared. If you
find you need to change a shared file, that change probably belongs in Portico (§7).

## 4. Making it yours in `.env`

Club-specific values are read from `.env` with generic defaults, so an update never resets them.
Set these on the server (and on any development machine):

| Key | What it's for | Default |
|---|---|---|
| `APP_NAME` | The name in the panel, emails and footer. | `Portico` |
| `DB_DATABASE` | Your database name. | `portico` |
| `WATCHLIST_NOTIFY_LABEL` | Who staff tell about a watchlisted member ("Notify …"). | `the staff channel` |
| `SYSTEM_USER_EMAIL` | The non-login user automated grants are recorded as. | `system@portico.internal` |
| `BACKUP_DESTINATION` | Where nightly backups go; somewhere that leaves the machine. | your OneDrive |
| `BACKUP_PREFIX` | Backup filenames. | `portico` |
| `BACKUP_EMERGENCY_ROSTER` | Also write a door roster beside each backup, for a night the system is down. | off |
| `SOURCE_CODE_URL` | The footer's "Source code" link (§8). | this repository |

After changing `.env` on the server, run `php artisan optimize`. Your club's name, logo, fees,
categories, house policy wording and feature flags are set in the app itself, by an Admin or
Owner, and live in the database rather than in git.

## 5. Taking an update

Portico publishes releases as tags (`v1.1.0`, `v1.2.0`, …) with notes in
[`CHANGELOG.md`](../CHANGELOG.md). Releases follow semantic versioning: a minor release can add a
migration or need an `npm run build`, but anything that can break an existing install waits for
a major release. **Track releases, not `main`**: `main` is where work lands between releases.

Do this on a development machine, not the server:

1. Read the release's section of `CHANGELOG.md`. Note any migration, npm build, new `.env` key
   or new setting.
2. Merge it on a branch:

   ```
   git checkout main
   git pull origin main
   git fetch portico --tags
   git switch -c update/v1.1.0
   git merge v1.1.0
   ```

3. If there are conflicts, keep your side for your own files (§3) and Portico's side for
   everything else. `.env` isn't tracked, so it never conflicts, but compare `.env.example` for
   new keys your server needs.
4. Test:

   ```
   composer install
   php artisan migrate:fresh --seed     # development database only, never the server's
   ./vendor/bin/pest
   ```

5. Open a pull request into your `main` and **merge it with "Create a merge commit"**. Never
   squash or rebase it: a squash drops Portico's commit as a parent, and the next update then
   sees every change since as a conflict.
6. On the server, `git pull` and run `scripts\deploy.ps1` (`DEPLOYMENT.md` §7). It runs forward
   migrations only. Never pass it `-MigrateFresh` once the server holds real data: that wipes
   the database.
7. Add one line to your runbook: the date, the version, any conflicts, and what the server
   needed. It's the record of what's running and why.

The in-app **Upstream Updates** page (`DEPLOYMENT.md` §7, "Checking for upstream updates")
watches a branch, usually `portico/main`, so it tells you something new has landed. Treat it as
a reminder to check for a release.

## 6. GitHub settings for your repository

- **Merge commits allowed** (§2). You may also want squash for your own changes; that's fine, as
  long as updates from Portico are merged with a merge commit.
- **Dependabot.** Your copy of `.github/dependabot.yml` will open the same dependency bumps
  Portico already handles, and each one you merge yourself conflicts with the release that
  brings the same bump. Set `open-pull-requests-limit: 0` on each ecosystem in your copy so
  dependency updates reach you through Portico's releases. Keep Dependabot *security alerts* on.
- **Actions.** Portico's CI (`.github/workflows/ci.yml`) runs in your repository too, on your
  account's Actions minutes. CodeQL on a private repository needs GitHub Advanced Security, so on
  a free plan expect that workflow to show as skipped.
- **Branch protection** is optional for a small team. If you add it, don't require linear
  history: that rule refuses merge commits.

## 7. Giving back

If you fix a bug or build something another club could use, offer it to Portico rather than
keeping it in your overlay. You get it maintained for you, and your next update has nothing to
conflict with.

1. Branch from Portico's `main`, not yours: `git switch -c fix/thing portico/main`.
2. Make the change, with a test and a `CHANGELOG.md` entry
   ([`CONTRIBUTING.md`](../CONTRIBUTING.md)).
3. Push the branch to your own public fork of Portico (the Fork button is fine for this, since
   it holds only shared code) and open a pull request against `AsteriaCreations/portico`.
4. Once it's merged and released, it reaches your repository with the next update.

Don't cherry-pick your own change onto your `main` while it waits. When the released version
arrives, git sees two different commits making the same change, and you get a conflict.

## 8. The license, in practice

Portico is [AGPL-3.0-or-later](../LICENSE). Running it unchanged for your club needs nothing
from you. If you modify it and other people use your modified version over a network, you must
offer them its source code. Set `SOURCE_CODE_URL` in `.env` to wherever they can get it, and the
panel footer's "Source code" link points there. The "Powered by Portico" logo link always points
here.

## 9. Getting help

- Questions and install help: [GitHub Discussions](https://github.com/AsteriaCreations/portico/discussions).
- Bugs and feature requests: [Issues](https://github.com/AsteriaCreations/portico/issues).
- Security problems: privately, as described in [`SECURITY.md`](../SECURITY.md). Not in a public
  issue.
