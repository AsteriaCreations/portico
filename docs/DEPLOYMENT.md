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
  staff VLAN. If a VLAN: allow the check-in device subnet → the server on TCP 80 (and 443
  if you use §6), and block everything else.

### Network checklist (any router)

The router-specific menu paths differ, but the requirements don't. Whatever you run
(UniFi, pfSense/OPNsense, MikroTik, a consumer router…), you need:

- [ ] **A DHCP reservation** for the server's MAC address, so its IP is stable. Prefer this
      over hand-setting a static IP in Windows — the reservation is then the single source
      of truth.
- [ ] **A local DNS record** — an `A` record for `checkin.<club>.lan` → the reserved IP —
      and check-in devices actually using the router as their DNS server. A device with a
      hard-coded public DNS server won't resolve the local name at all.
- [ ] **(Recommended) An isolated staff network.** Put the server and the check-in devices
      on their own VLAN / SSID, and block that network from your guest and general-purpose
      networks in both directions. Keep an allow rule for the gateway/DNS (see the
      gotcha below) and for outbound internet if the server needs it (Windows updates,
      cloud-synced backups, mail).
- [ ] **No WAN port-forward** targets the server. Check the router's port-forwarding list.
- [ ] **Verify segmentation once the rules are in:** from a check-in device,
      `ping checkin.<club>.lan` resolves to the reserved IP and the app loads; from a
      guest/general device, that IP is unreachable; from the server, internet access (and
      backup sync, if you use it) still works.

Two gotchas that apply on any router:

- **A new isolated zone/VLAN often drops traffic to the gateway by default.** DHCP can
  keep working while DNS and ping to the gateway silently don't — which looks like "the
  server has no internet". If a freshly segmented box can't resolve names or ping the
  gateway, add the missing "this network → gateway" allow rule first.
- **A Windows NIC won't re-request DHCP after a VLAN change until the link drops.**
  After moving the server's switch port to a different VLAN, reboot it (or disable and
  re-enable the adapter) before concluding the change didn't work.

### Worked example: UniFi

Verified against UniFi OS 5.1.x on a Dream Machine Pro. Exact menu wording drifts between
firmware versions — if a label doesn't match, search Settings for the feature name.

1. **DHCP reservation + local DNS in one step:** Network app → **Client Devices** →
   connect the server so it appears in the list → select it → enable **Fixed IP Address**
   and set the IP (this is the reservation) → enable **Local DNS Record** and enter a
   hostname → **Apply Changes**.
   - Alternative, if you don't want the record tied to one client: Settings →
     **Routing → DNS** → **Create Entry** → type **Host (A)** → the full name
     (`checkin.<club>.lan`) and the reserved IP → **Add**.
2. **DNS resolver:** UniFi's default DHCP already hands out the gateway itself as the DNS
   server; just don't override it on the staff network.
3. **Isolation with the Zone-Based Firewall:** create a **VLAN network** for staff, map a
   staff Wi-Fi SSID to it, and set the server's wired switch port's **native VLAN** to it.
   Then pull that network into its **own zone** — by default every network lands in the
   built-in `Internal` zone and `Internal → Internal` is *Allow*, so two VLANs in the same
   zone can still reach each other. With the new zone in place, set the staff zone ↔
   `Internal`/guest zones to **Block** in both directions, and staff → **Gateway** and
   staff → **External** to **Allow**. (The Gateway cell is the gotcha above: a new custom
   zone drops traffic to it by default.)
4. **Port forwarding:** Settings → **Firewall & Security → Port Forwarding** — confirm
   nothing targets the server.
5. Run the segmentation check from the checklist above.

---

## 1. Runtime

### Which account owns what

Pick one dedicated account (a local Windows account, or a work/Microsoft account tied to
a club mailbox) to own the install rather than someone's personal login:

- It should **own the app directory** (full control, recursive — `icacls <dir> /setowner
  <account>` and `icacls <dir> /grant "<account>:(OI)(CI)F"`).
- It should own the **scheduled tasks** (§3), so they can read `.env` and write `storage/`.
- The Apache service may run as it too, or as `LocalSystem` — both work as long as both
  have full control of the app directory, so `mod_php` and the scheduled tasks don't fight
  over `storage/` or `bootstrap/cache`. See §2.
- If a different account (a human admin) needs to run `git` in a checkout this account
  owns, git's "dubious ownership" check will refuse until you run
  `git config --global --add safe.directory <path-with-forward-slashes>` as that account.

Don't use a developer stack like Laravel Herd, Laragon or XAMPP to serve the production
box. Herd for Windows, for one, serves only on `127.0.0.1`, locks its top-level domain,
and only serves while its owning user is signed in — none of which suits an always-on LAN
server. Apache as a Windows service (§2) has none of those constraints.

### PHP

- PHP **8.4+**. On Windows with Apache + mod_php you need the **Thread Safe** (ZTS) build,
  and its VS version must match your Apache build (see §2). Use the **x64** zip *without*
  `-nts-` in its name, extracted to a short path such as `C:\php`. `php -v` should report
  the version **and** `ZTS`.
- The download page serves the newest stable release. If that's newer than the version the
  project's CI runs on, get the one CI uses from the `releases/archives/` section instead —
  a fresh go-live isn't the place to be the first to run the app on a newer PHP.
- `copy C:\php\php.ini-production C:\php\php.ini`, then edit it:
  - `extension_dir = "C:\php\ext"` — use the **absolute** path, not the template's
    relative `"ext"`. A relative value resolves against the *current working directory*,
    so extensions load when you run `php` from `C:\php` and silently fail from anywhere
    else — including Task Scheduler.
  - `date.timezone = "<your timezone>"`
  - `upload_max_filesize` / `post_max_size` — raise to whatever your largest legitimate
    upload (a roster import, say) needs.
  - Enable these extensions: `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`,
    `pdo_mysql`, `exif`, `zip`, `bcmath`. Most are in the file as `;extension=…` — remove
    the leading `;`. **The Windows `php.ini-production` template has no lines at all for
    `bcmath`, `exif` or `zip`** — add those three yourself, and put `extension=exif`
    *after* `extension=mbstring` (exif depends on it and fails to load if it comes first).
- Confirm it took:
  - `php --ini` → `Loaded Configuration File: C:\php\php.ini` (not `(none)`).
  - `php -i | findstr /i extension_dir` → both values `C:\php\ext`.
  - `php -m` lists all ten extensions. If one is missing, run `php -m 2>&1 | more` and read
    the `PHP Startup: Unable to load dynamic library …` warning above the list — it names
    the exact DLL and reason.
- Put `C:\php` on the **machine** `PATH` (System Properties → Environment Variables →
  System, or from an elevated shell: `[Environment]::SetEnvironmentVariable('Path',
  "C:\php;" + [Environment]::GetEnvironmentVariable('Path','Machine'), 'Machine')`).
  Machine scope, not user scope, so Task Scheduler's "run whether logged on or not" jobs
  resolve `php` too. Open a **new** shell, then `where php` → `C:\php\php.exe` on line 1.
- **Reboot** (don't just log off) and re-run each §3 task once. The Task Scheduler
  *service* caches its environment block, so it only sees a new machine `PATH` after a
  restart — this is the real test that `php` resolves in the scheduler's context, not just
  your interactive shell.

### Database

- MariaDB 10.6+ / MySQL 8, installed as a Windows service with Startup = Automatic, on the
  standard port 3306. Create an empty database (`<portico>`) and a dedicated user with
  rights on just that database.
- **MariaDB gotcha:** a TCP connection to `127.0.0.1` is reverse-resolved to `localhost`, so
  a user created only as `'<user>'@'127.0.0.1'` fails authentication. Create it as
  `'<user>'@'localhost'`; `DB_HOST=127.0.0.1` in `.env` still works.

### Composer

- Install [Composer](https://getcomposer.org) 2.x. On Windows, the plain installer drops
  an extensionless phar — you may need a `composer.bat` shim (`@echo off` /
  `php "%~dp0composer" %*`) so `composer` is runnable by name. Confirm `where composer`
  resolves to it on line 1.

### Node.js

**Install Node.** The admin panel's theme (`resources/css/filament/admin/theme.css`) is
compiled by Vite into `public/build/`: it provides the Tailwind classes the app's own screens
use (the Check-In Desk, Active Patrons, the dashboard widgets, every "How to use" panel).
`scripts/deploy.ps1` builds it on every deploy (§7). Without a build the panel still loads,
because the theme is only used once it exists, but those screens lose their spacing, colours
and layout.

If you do install it, use the current LTS (24, or 22); check `package.json`'s
`engines`/the Vite version if in doubt, as older Node lines fall below Vite's floor. The
MSI can fail writing `C:\Program Files\nodejs\corepack` if a previous Node install left
files there; if so, uninstall the old Node, delete that folder, and either re-run the MSI
elevated or use the `node-v<version>-win-x64.zip` build (extract to `C:\nodejs`, add to the
**machine** `PATH` — it has no corepack shim, so the error can't occur). `npm` also needs
the PowerShell execution policy at `RemoteSigned` or looser:
`Set-ExecutionPolicy RemoteSigned -Scope LocalMachine` (elevated).

### The app

```bash
git clone <your fork> C:\portico
cd C:\portico
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Clone from the project's remote; don't copy a dev machine's working directory (it can drag
in uncommitted or dev-only state).

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

# The check-in desk is a shared, walk-away terminal: keep sessions short and drop them
# when the browser closes.
SESSION_LIFETIME=45
SESSION_EXPIRE_ON_CLOSE=true

MAIL_MAILER=log        # until you wire a transactional provider (see §5)
```

Leave `SESSION_SECURE_COOKIE` **unset** until the site is actually being served over HTTPS
(§6) — set early, it stops the browser sending the session cookie over plain HTTP and
silently breaks every login.

Then:

```bash
php artisan migrate:fresh --seed --force   # --force: no interactive prompt in production
php artisan storage:link
php artisan optimize
```

`optimize` bakes `APP_URL` and the rest of `.env` into the config cache — run it after the
`.env` edit, and again after any later `.env` change.

Log in at `http://checkin.<club>.lan/admin` as `ADMIN_EMAIL`. Change the seeded plan
prices, categories, event types, and Membership Settings to match your club. Create real
staff accounts and stop using the admin/Owner login for day-to-day work.

---

## 2. Serving the app on the LAN

**Never `php artisan serve` in production.** Use a real web server.

### Windows: Apache Lounge + mod_php (scripted)

**Match the build triplet across Apache and PHP: win64 / VS17 / x64, with PHP Thread
Safe** — `mod_php` won't load a non-TS PHP.

> **VS17, not VS18.** PHP for Windows is still built with VS17. Apache Lounge moved its
> *current* download line to **VS18** at 2.4.67, so the main download page can serve a build
> `mod_php` can't safely pair with a VS17 PHP — the mismatch crashes httpd on the first PHP
> request. Get the Apache zip from the **VS17 line** instead:
> `https://www.apachelounge.com/download/VS17/binaries/` (a known-good build is
> `httpd-2.4.66-251206-Win64-VS17.zip`). Being a couple of patch releases behind on a
> LAN-only box behind a VLAN and host firewall is a non-issue; when PHP ships VS18 builds,
> re-run `setup-apache.ps1` against a VS18 Apache zip to move both forward together.

1. Download the VS17 Apache Lounge zip (above).
2. Edit `scripts/apache/portico.conf` — set `SITE_ROOT`, `SITE_IP`, `SITE_HOST`,
   `SITE_LAN` for your box. (Or copy it to your own overlay file and pass `-ConfFile`.)
3. From an **elevated** PowerShell:

   ```powershell
   Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
   & 'C:\portico\scripts\apache\setup-apache.ps1' -ApacheZip '<path to httpd zip>'
   ```

   Keep it on **one physical line** in the `&`-plus-full-path form — a wrapped paste of the
   `cd` + `.\setup-apache.ps1` form can run a trailing parameter value as its own command.

   The script extracts Apache, patches `httpd.conf` (server root, `Listen <ip>:80`,
   `Include conf/extra/portico.conf`), validates the config, installs and starts the
   `Apache-Portico` service, and opens an inbound firewall rule for TCP 80 from your LAN
   CIDR. It's idempotent — re-run it after editing `portico.conf`. `-WhatIf` dry-runs it
   (on a first run it stops at the `httpd.conf` read, since the extract it would have done
   is skipped).
4. Confirm the service `StartType` is **Automatic** (`Get-Service Apache-Portico`), so it
   comes back after a reboot — don't assume the script left it that way. If it's `Manual`,
   fix it from an **elevated** shell (`Set-Service` returns "Access is denied" without
   elevation):

   ```powershell
   Set-Service Apache-Portico -StartupType Automatic
   Start-Service Apache-Portico
   ```

   Then reboot once and confirm it comes up on its own.
5. **Verify.** The app lives at **`/admin`** — `/` is just the stock Laravel welcome route.
   On the box:

   ```
   curl.exe -I -H "Host: checkin.<club>.lan" http://<192.0.2.10>/admin/login
   ```

   should return `200 OK`. Then load `http://checkin.<club>.lan/admin` from a **second
   device** on the staff network. A 403 means check the `Require` lines and that the device
   really is inside your LAN CIDR; a 500 with a blank page means `APP_DEBUG` is off
   (correct) — read `storage/logs/laravel.log`.

**Service account.** By default the service runs as `LocalSystem`. To run it as your
dedicated account instead, pass `-ServiceAccount '<HOST\account>'` (the script prompts for
the password; `sc.exe config obj=` grants the "Log on as a service" right). If that step
fails — the service won't start and the System log shows logon failure `%%1326` / `%%1069`,
i.e. a wrong password — fall back to `sc.exe config Apache-Portico obj= "LocalSystem"` and
`Start-Service Apache-Portico`. That's fine: what matters is that both `LocalSystem` and
your account have full control of the app directory (§1).

**If `httpd -t` fails with `ServerRoot must be a valid directory`.** Newer Apache Lounge
builds dropped the `Define SRVROOT "..."` line older builds used and hard-code
`ServerRoot "C:/Apache24-64"` instead. The script handles both layouts; if a still-newer
build changes it again, fix it by hand with a global replace of the stock root in
`C:\Apache24\conf\httpd.conf` (written back ASCII, no BOM), then re-run the script.

Useful when routing looks wrong: `httpd.exe -S` dumps the resolved vhosts, and
`netstat -ano | Select-String ":80\s"` shows what's bound where.

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

To register them from the command line instead of the GUI, use `schtasks.exe`:

```
schtasks /create /F /TN "<Club> - Nightly DB Backup" ^
  /TR "C:\portico\scripts\run-backup.bat" ^
  /SC DAILY /ST 03:00 /RU <HOST\account> /RP <password> /RL LIMITED
```

Hourly jobs use `/SC HOURLY /MO 1` in place of `/SC DAILY /ST …`. Prefer `schtasks.exe` to
PowerShell's `ScheduledTasks` module here: on some Windows builds the module won't let you
set `RepetitionInterval` as a property.

Notes:

- Task Scheduler caches its environment block — if you change the machine `PATH`, reboot
  before expecting tasks to see it (§1).
- `vouchers:grant-comp-rewards` attributes its vouchers to the system user
  (`SYSTEM_USER_EMAIL`), which `DatabaseSeeder` creates — confirm it survived your last
  `migrate:fresh --seed` before trusting that task.
- `events:notify-ended` is inert until `MAIL_MAILER` is a real transport (§5); registering
  it earlier just writes to its log.
- **"Ran" isn't "worked".** Task Scheduler reporting a task as run only means the `.bat`
  was launched, not that the command inside it succeeded. After each task's first real
  run, check its log (`storage/logs/backup.log`, `vouchers-grant-comp-rewards.log`,
  `notify-event-ended.log`), and that `Last Result` is `0x0`.

---

## 4. Backups

See [README.md](../README.md#backups). In short: nightly `mysqldump`, gzipped, written to
a folder that syncs **off** the machine on its own (OneDrive / Dropbox / a network
share). Set `BACKUP_DESTINATION` and `MYSQLDUMP_PATH` in `.env`. Test a restore into a
throwaway database periodically — don't wait for an emergency.

The box is the single source of truth for your membership data once it's live, so a backup
that lives only on its own disk isn't enough. If the off-machine copy is a per-user cloud
sync (OneDrive, Dropbox), it only syncs while that user is signed in — either keep the
service account auto-logged-in (Sysinternals Autologon; for a Microsoft account, "Only
allow Windows Hello sign-in for Microsoft accounts" must be **off** for stored-password
auto-login to work) or sync to a network share instead. Confirm the sync icon goes green
after the first unattended run.

---

## 5. Mail (optional, deferred)

`events:notify-ended` and any future mail need a transport. `MAIL_MAILER=log` just writes
to `storage/logs/`. When you want real delivery, pick a transactional provider
(Resend / Brevo / SMTP2GO / your own SMTP) and set the `MAIL_*` vars, then `config:clear`
and re-test a send.

Don't plan on a consumer mailbox (Outlook.com, Gmail) as the sender: providers have
disabled SMTP Basic Auth for personal accounts (e.g. `535 5.7.139 …
SmtpClientAuthentication is disabled for the Mailbox`), and Laravel's `smtp` mailer speaks
only Basic Auth.

---

## 6. TLS (optional)

Plain HTTP over an isolated VLAN is a defensible choice for a closed LAN tool. If you
want TLS anyway:

1. Issue a cert/key pair for your host — [mkcert](https://github.com/FiloSottile/mkcert)
   is the easiest. Put `mkcert.exe` in its own directory (e.g. `C:\mkcert`) on the
   **machine** `PATH`, then `mkcert -install` once (creates the local CA and trusts it on
   this machine), and `mkcert checkin.<club>.lan <lan-ip>` to issue a cert covering both the
   hostname and the raw IP.
   - **Keep the CA folder** (`mkcert -CAROOT` prints it). It holds the CA's private key;
     losing it means re-issuing and re-distributing to every device. Whoever regenerates a
     cert later must run as the same account, or pass `-CAROOT`.
   - mkcert writes its output to the shell's *current directory* — run it somewhere
     outside the repo (the repo's `.gitignore` also excludes a root-level `*.pem`, but
     don't lean on that).
2. Base your overlay conf on `scripts/apache/portico-tls-example.conf` instead of
   `scripts/apache/portico.conf` — it's the same shape plus a `:443` vhost (`SSLEngine`,
   `SSLCertificateFile`/`SSLCertificateKeyFile`) and a `:80` block that redirects to
   `https://` instead of serving the app.
3. Run `setup-apache.ps1` with `-ConfFile '<your overlay>.conf' -ServiceName '<service>'
   -EnableTls -CertFile '<cert>' -KeyFile '<key>'` — it copies the cert/key into place, adds
   `Listen <ip>:443` to `httpd.conf`, and opens the firewall for 443 alongside the existing
   80 rule. Pass `-ConfFile` and `-ServiceName` explicitly if you renamed them: the
   script's defaults are the generic `portico.conf` / `Apache-Portico`. If your conf
   declares `SSLEngine on`, the script refuses to run without `-EnableTls`.
4. Set `APP_URL=https://...` and `SESSION_SECURE_COOKIE=true` in `.env`, then
   `config:clear && optimize`. **Log in afterwards** — that's the real test of
   `SESSION_SECURE_COOKIE`, which breaks login if the cookie isn't sent over a connection
   the browser considers secure. The app also sends `Strict-Transport-Security` on every
   HTTPS response once it's served that way.
5. Verify on the box: `https://checkin.<club>.lan/admin/login` loads with no certificate
   warning, and `http://checkin.<club>.lan` redirects (301) to `https://`.
6. On each device that will reach the app, trust the CA with
   `scripts/client/install-local-ca.ps1`: copy over the CA's root cert (`rootCA.pem`,
   found via `mkcert -CAROOT`) and the script (USB stick or a shared folder — both are
   tiny), then from an **elevated** PowerShell on that device:

   ```powershell
   Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
   .\install-local-ca.ps1 -TestUrl 'https://checkin.<club>.lan/admin/login'
   ```

   Confirm it reports success, then open the URL in the device's actual browser and check
   for no warning. A self-issued CA has to be trusted explicitly on every device; a public
   CA wouldn't need this step, but isn't an option for a `.lan` hostname with no public
   DNS. Firefox keeps its own certificate store on Windows by default — see the script's
   header comment for the one-time fix. On an Active Directory domain, push the CA root
   with Group Policy instead of running the script per device.

---

## 7. Updating

Pulling down an update is: stop the web server, `git pull`, reinstall
dependencies, migrate, rebuild caches, restart. On Windows,
`scripts/deploy.ps1` automates that sequence:

```powershell
# From an elevated PowerShell, in the project root:
.\scripts\deploy.ps1
```

It stops the `Apache-Portico` service (rename with `-ServiceName`, set a
persistent default with the `PORTICO_APACHE_SERVICE` machine environment
variable if your box's service is named differently — important for the
web-triggered path below, which never passes `-ServiceName` — or pass
`-ServiceName ''` to skip entirely on a non-Apache / non-Windows setup),
requires a clean working tree of **tracked files** before pulling
(`git pull --ff-only` — it refuses to guess through a diverged or dirty tree;
an untracked file like an npm-generated `package-lock.json` or something
staged under `storage/` doesn't count, since pulling can't touch what git
doesn't already track), runs
`composer install --no-dev --optimize-autoloader`, `npm install && npm run
build`, `php artisan migrate --force`, `storage:link` / `config:clear` /
`optimize`, then restarts the service — in a `finally` block, so a failure
partway through never leaves the box down. Flags for the common variations:

- `-SkipNpm` — skip `npm install && npm run build`. Only when the update changed no Blade
  view, CSS or JS: the panel theme is compiled from the classes in the views (§1,
  "Node.js"), so skipping after a view change leaves any new class unstyled until the next
  build.
- `-SkipMigrate` — this update has no schema change.
- `-MigrateFresh` — **pre-launch only, destroys data** — rebuilds the schema
  from scratch instead of a forward-only `migrate --force`.
- `-WhatIf` — see what it would do without changing anything.

To make the service name stick for every run, including the web-triggered one, set it once
as a machine environment variable from an elevated shell (a fresh shell/session picks it
up):

```powershell
setx PORTICO_APACHE_SERVICE <your-service-name> /M
```

It deliberately does **not** read the changelog for you or run a smoke test —
check what actually changed before running it, and verify the app afterward
(§8).

On other platforms, do the same steps by hand:

```bash
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
npm install && npm run build
php artisan migrate --force
php artisan storage:link && php artisan config:clear && php artisan optimize
```

**Once real data is on the box, never `migrate:fresh`** — forward migrations only.

### Web-triggered updates

`scripts/deploy.ps1` stops the very web server that would be serving a button
click, so an admin page can never run it in-process — it can only ask an
already-registered Windows Scheduled Task to run it, fully detached from the
request. To opt in:

1. If your box's Apache service isn't named `Apache-Portico`, set the
   `PORTICO_APACHE_SERVICE` machine environment variable first (see §7 above)
   — `scripts/run-deploy.bat` calls `deploy.ps1` with no `-ServiceName` at
   all, so this is the only way the web-triggered path knows which service to
   cycle.
2. Register a Scheduled Task pointing at `scripts/run-deploy.bat`, same
   general setup as §3 above, but with **no trigger** (or any trigger you
   like — this app only ever fires it with `schtasks /run`, so it just needs
   to be runnable on demand). Since `deploy.ps1` stops and restarts the web
   server, this task needs a run-level with permission to control that
   service — `/RL LIMITED` (the level the other jobs in §3 use) is not
   enough; grant it `/RL HIGHEST` or run it as an account already permitted
   to start/stop the service. This app never registers the task itself. For example:

   ```
   schtasks /create /F /TN "<Club> - Deploy Update" ^
     /TR "C:\portico\scripts\run-deploy.bat" ^
     /SC ONCE /ST 00:00 /SD 01/01/2099 /RU <HOST\account> /RP <password> /RL HIGHEST
   ```

   The `/SC ONCE … /SD 01/01/2099` trigger is far enough out that it never fires on its
   own; `schtasks /run /TN "<Club> - Deploy Update"` works regardless. Right-click → Run
   once and watch the Apache service actually bounce before trusting the button.
3. Set **Deploy Scheduled Task name** on Membership Settings to the exact
   task name you registered.
4. Turn on **Web-triggered deploy enabled** on Feature Flags.

Once configured, **Run update now** appears on the **Upstream Updates** page
(Admin+). It only confirms Windows accepted the run request — the deploy
itself happens out-of-band, and `deploy.ps1` reports its own outcome back via
`php artisan deploy:record-result`, shown on that same page once it lands
(the web server restarting mid-run means this can take a few minutes to show
up). This exposes nothing `-MigrateFresh`/`-SkipNpm`/`-SkipMigrate` would
change — those still need a manual run.

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

## 8. Smoke test

After go-live, and again after any update that touches code or assets:

- [ ] Load the app from a **second device** on the staff network by DNS name, not just from
      the server itself.
- [ ] The default dev login (`test@example.com` / `password`) does **not** work on this
      database — only the real `ADMIN_EMAIL` credential does.
- [ ] A non-admin staff account exists for day-to-day use, so the desk isn't signed in as
      Owner.
- [ ] `/admin/check-in` loads **and is styled**. Unstyled pages mean static assets aren't
      being served — check that the vhost's document root is the app's `public/` folder.
- [ ] A test check-in records correctly, and the register/reporting widgets reflect it.
- [ ] Each scheduled task (§3) shows as registered with a next-run time; after its first
      real run, its log shows success and `Last Result` is `0x0`.
- [ ] With a real `MAIL_MAILER`, run `php artisan events:notify-ended` against a past event
      and confirm the email actually arrives (not just that it logs "sent").
- [ ] After a reboot, the web server, the database and the scheduled tasks all come back on
      their own.

---

## Operational non-negotiables

- Serve via a real web server — never `php artisan serve`.
- Never port-forward this server to the internet.
- Nightly `mysqldump` written off the machine; test a restore once.
- Git repo is the source of truth; `migrate:fresh` rebuilds the database (pre-launch
  only); pin versions.
