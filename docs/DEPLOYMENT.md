# Deploying Portico on a club LAN

Portico is designed to run on **one dedicated workstation on the club's own network**,
reachable by several check-in devices (tablets, laptops) at once over LAN or a private
VLAN. It is **not** designed to be exposed to the public internet — it stores dates of
birth, ban/watchlist reasons, and other sensitive member data. Keep it on the LAN.

This is a checklist, not a script. Values in `<angle brackets>` are yours to choose.
`scripts/apache/setup-apache.ps1` automates the Apache half on Windows, and
`scripts/deploy.ps1` automates pulling down updates afterward (§7).

---

## 0. Before you start

- A always-on Windows (or Linux) machine that isn't someone's daily driver.
- A wired connection to the club network with a **DHCP reservation** so its IP never
  changes — say `<192.0.2.10>`.
- A hostname staff will type — e.g. `checkin.<club>.lan` — resolvable on the LAN
  (a local DNS record on the router, or a hosts-file entry pushed to the check-in
  devices).
- Decide now whether check-in devices reach the box over the main LAN or an isolated
  staff VLAN. If a VLAN: allow the check-in device subnet → the server on TCP 80, and
  block everything else.

---

## 1. Runtime

### PHP

- PHP **8.4+**. On Windows with Apache + mod_php you need the **Thread Safe** (ZTS) build,
  and its VS version must match your Apache build (see §2).
- Extensions: `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`,
  `exif`, `zip`, `bcmath`.
- Put `php` on the machine PATH.

### Database

- MariaDB 10.6+ / MySQL 8. Create an empty database (`<portico>`) and a dedicated user
  with rights on just that database.

### Composer

- Install [Composer](https://getcomposer.org) 2.x. On Windows, the plain installer drops
  an extensionless phar — you may need a `composer.bat` shim (`@echo off` /
  `php "%~dp0composer" %*`) so `composer` is runnable by name.

### The app

```bash
git clone <your fork> C:\portico
cd C:\portico
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=http://checkin.<club>.lan
APP_TIMEZONE=<your timezone>

DB_DATABASE=<portico>
DB_USERNAME=<portico_user>
DB_PASSWORD=<...>

# Required outside local/testing — DatabaseSeeder refuses to seed a guessable admin.
ADMIN_EMAIL=<you@club>
ADMIN_PASSWORD=<a real password>

MAIL_MAILER=log        # until you wire a transactional provider (see §5)
```

Then:

```bash
php artisan migrate:fresh --seed --force   # --force: no interactive prompt in production
php artisan optimize
```

Log in at `http://checkin.<club>.lan/admin` as `ADMIN_EMAIL`. Change the seeded plan
prices, categories, event types, and Membership Settings to match your club. Create real
staff accounts and stop using the admin/Owner login for day-to-day work.

---

## 2. Serving the app on the LAN

**Never `php artisan serve` in production.** Use a real web server.

### Windows: Apache Lounge + mod_php (scripted)

1. Download an Apache Lounge **VS17** httpd build (win64/x64) whose VS version matches
   your PHP build. Do **not** mix VS17 and VS18 — mod_php will crash httpd on the first
   PHP request.
2. Edit `scripts/apache/portico.conf` — set `SITE_ROOT`, `SITE_IP`, `SITE_HOST`,
   `SITE_LAN` for your box.
3. From an **elevated** PowerShell:

   ```powershell
   Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
   & 'C:\portico\scripts\apache\setup-apache.ps1' -ApacheZip '<path to httpd zip>'
   ```

   The script extracts Apache, patches `httpd.conf` (server root, `Listen <ip>:80`,
   `Include conf/extra/portico.conf`), validates the config, installs and starts the
   `Apache-Portico` service, and opens an inbound firewall rule for TCP 80 from your LAN
   CIDR. It's idempotent — re-run it after editing `portico.conf`.
4. Confirm the service `StartType` is **Automatic** (`Get-Service Apache-Portico`), so it
   comes back after a reboot. Verify it survives one.

### Other platforms

Apache or nginx + PHP-FPM, or a container. Point the web root at `public/`, enable
`mod_rewrite` (Apache) / the standard Laravel `try_files` block (nginx), and restrict
access to the LAN / VLAN CIDR at the web layer as defence in depth.

---

## 3. Scheduled tasks

Portico has **no Laravel scheduler**. Each recurring job is a plain Artisan command you
register on a timer (Windows Task Scheduler, or cron). Wrappers live in `scripts/`.

| Command | Suggested cadence | Wrapper |
|---|---|---|
| `backup:database` | nightly, low-traffic hour | `scripts/run-backup.bat` |
| `events:notify-ended` | hourly | `scripts/run-notify-event-ended.bat` |
| `vouchers:grant-comp-rewards` | hourly | `scripts/run-vouchers-grant-comp-rewards.bat` |
| `upstream:check` | hourly, or your preference | `scripts/run-upstream-check.bat` — optional, only relevant if this fork tracks an upstream remote (§7) |

For each Task Scheduler task: run whether the user is logged on or not, as the account
that owns the project's `.env`; **Start in** = the project root; action = the `.bat`.
Right-click → Run once to confirm before trusting it unattended. Output appends to
`storage/logs/`.

Task Scheduler caches its environment block — if you change the machine PATH, reboot
before expecting tasks to see it.

---

## 4. Backups

See [README.md](../README.md#backups). In short: nightly `mysqldump`, gzipped, written to
a folder that syncs **off** the machine on its own (OneDrive / Dropbox / a network
share). Set `BACKUP_DESTINATION` and `MYSQLDUMP_PATH` in `.env`. Test a restore into a
throwaway database periodically — don't wait for an emergency.

---

## 5. Mail (optional, deferred)

`events:notify-ended` and any future mail need a transport. `MAIL_MAILER=log` just writes
to `storage/logs/`. When you want real delivery, pick a transactional provider
(Resend / Brevo / SMTP2GO / your own SMTP) and set the `MAIL_*` vars.

---

## 6. TLS (optional)

Plain HTTP over an isolated VLAN is a defensible choice for a closed LAN tool. If you
want TLS anyway, stand up an internal CA or `mkcert` cert for `checkin.<club>.lan`, add
an HTTPS vhost, and distribute the CA cert to the check-in devices.

---

## 7. Updating

Pulling down an update is: stop the web server, `git pull`, reinstall
dependencies, migrate, rebuild caches, restart. On Windows,
`scripts/deploy.ps1` automates that sequence:

```powershell
# From an elevated PowerShell, in the project root:
.\scripts\deploy.ps1
```

It stops the `Apache-Portico` service (rename with `-ServiceName`, or pass
`-ServiceName ''` to skip entirely on a non-Apache / non-Windows setup),
requires a clean working tree before pulling (`git pull --ff-only` — it
refuses to guess through a diverged or dirty tree), runs
`composer install --no-dev --optimize-autoloader`, `npm install && npm run
build`, `php artisan migrate --force`, `storage:link` / `config:clear` /
`optimize`, then restarts the service — in a `finally` block, so a failure
partway through never leaves the box down. Flags for the common variations:

- `-SkipNpm` — this update touched no front-end asset (most don't).
- `-SkipMigrate` — this update has no schema change.
- `-MigrateFresh` — **pre-launch only, destroys data** — rebuilds the schema
  from scratch instead of a forward-only `migrate --force`.
- `-WhatIf` — see what it would do without changing anything.

It deliberately does **not** read the changelog for you or run a smoke test —
check what actually changed before running it, and verify the app afterward.

On other platforms, do the same steps by hand:

```bash
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
npm install && npm run build
php artisan migrate --force
php artisan storage:link && php artisan config:clear && php artisan optimize
```

### Checking for upstream updates

If this fork tracks an upstream remote (e.g. a fork of `AsteriaCreations/portico`) —
a **second** remote, distinct from `origin` above — run `git remote add <name> <url>`
on the server first; this app never adds a remote itself. Then set **Upstream
remote** / **Upstream branch** on Membership Settings and turn on **Upstream update
checking enabled** on Feature Flags. A scheduled `upstream:check` command (see §3)
periodically `git fetch`es that remote; the **Upstream Updates** admin page (Admin+)
then shows which commits are pending, entirely from local git state — no network
call on page load. Check it before running `scripts/deploy.ps1`.

---

## Operational non-negotiables

- Serve via a real web server — never `php artisan serve`.
- Never port-forward this server to the internet.
- Nightly `mysqldump` written off the machine; test a restore once.
- Git repo is the source of truth; `migrate:fresh` rebuilds the database; pin versions.
