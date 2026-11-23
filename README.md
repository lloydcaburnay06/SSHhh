<p align="center">
  <img src="assets/img/logo.svg" alt="SSHhh — Deployments so quiet, you can hear a server drop." width="520">
</p>

<p align="center">
  <em>"Deployments so quiet, you can hear a server drop."</em>
</p>

<p align="center">
  <a href="CONTRIBUTING.md"><img src="https://img.shields.io/badge/contributions-welcome-brightgreen.svg?style=flat-square" alt="Contributions Welcome"></a>
  <a href="CONTRIBUTING.md"><img src="https://img.shields.io/badge/PRs-welcome-brightgreen.svg?style=flat-square" alt="PRs Welcome"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg?style=flat-square&logo=php&logoColor=white" alt="PHP Version"></a>
  <a href="manifest.webmanifest"><img src="https://img.shields.io/badge/PWA-Ready-blueviolet.svg?style=flat-square&logo=pwa&logoColor=white" alt="PWA Ready"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square" alt="License: MIT"></a>
</p>

---

# SSHhh

**SSHhh** is a lightweight, self-hosted, mobile-ready **Progressive Web App (PWA) SSH Deployer** built with **PHP 8.1+**, **MySQL / MariaDB**, and **phpseclib v3**. Manage all your servers and projects, store SSH passwords and private keys in an **AES-256-GCM encrypted vault**, edit ordered deployment command pipelines inline, trigger **1-click live streaming deployments** from desktop or mobile, and inspect a **complete event & audit log**.

---

## Features

- **Multi-Project Dashboard & Priority Sorting**:
  - Add, update, search, and filter projects across `Production`, `Staging`, `Development`, and `Testing` environments.
  - **Drag-and-Drop Priority Sorting**: Arrange project cards into your preferred order with persistent database storage.
  - **1-Click Project Duplication**: Clone existing projects and command pipelines with a single click to quickly spin up similar environments.
  - **Quick Run All (`▶ Run`)**: Execute all pipeline commands directly from the dashboard card next to **Manage & Deploy**.
- **Single-User Google Authenticator 2FA (TOTP)**:
  - First-time interactive setup screen featuring an **offline SVG QR code** and manual secret key.
  - **Permanent One-Time Lockout**: Once verified, the setup screen and secret key are encrypted at rest and permanently locked out.
  - **Brute-force lockout**: 5 wrong codes from one IP locks login for 15 minutes.
  - **Localhost-only setup**: the first-time setup screen can only be completed from the machine running SSHhh.
  - **Remember This Device**: Persistent 30-day SHA-256 hashed device cookie so you don't have to enter the 6-digit code every time you launch the app on your phone.
- **AES-256-GCM Encrypted SSH Vault**:
  - Supports **SSH Password** and **SSH Private Key** (+ optional passphrase) authentication via pure-PHP `phpseclib/phpseclib` (no `ext-ssh2` C extension required).
  - Encrypts credentials at rest using **AES-256-GCM** + **HKDF-SHA256**, unique 96-bit IVs, and 128-bit authentication tags.
  - Zero browser exposure: stored secrets are never sent back in HTML, JSON responses, or logs.
  - 1-click **Test SSH Connection** verifies authentication, pins the server's OpenSSH `SHA256` host key fingerprint, and checks the remote working directory.
  - **Host key pinning**: deployments refuse to connect (and never send credentials) if a server's host key changes. Re-run **Test SSH Connection** to trust a legitimately changed key.
  - **One deployment per project at a time**, with automatic recovery of deployments interrupted by a crash or restart.
- **Card-Based Pipeline Editor & Live Streaming Terminal**:
  - Full-width two-panel layout with independently scrollable panels on desktop and mobile.
  - Drag-and-drop command card reordering, multi-line command editing, and presets (`Git Pull`, `Composer`, `Laravel`, `Node/PM2`).
  - **1-Click `Deploy Now (Execute All)`** runs all enabled steps sequentially over a single SSH session.
  - **Live Streaming SSH Console** streams `stdout` + `stderr` in real time over NDJSON with ANSI color rendering.
- **Installable PWA & Mobile Responsive**:
  - Web App Manifest (`manifest.webmanifest`), custom icons, and Service Worker (`sw.js`) with offline fallback (`offline.html`).
  - Native standalone PWA experience without address bar interference. Touch-optimized with notch safe-area handling.
- **Complete Event & Audit Log**:
  - Persists every full deployment (with per-step exit codes and durations), single/ad-hoc command run, SSH connection test, and project audit change.
  - Filter by category, copy raw output, or download `.log` files.

---

## Requirements

- **PHP** `>= 8.2` with extensions: `openssl`, `pdo`, `pdo_mysql`, `mbstring`, `json`
- **MySQL** `>= 5.7` or **MariaDB** `>= 10.3`
- **Composer** `>= 2.0` and **Node.js** `>= 20` (to build the frontend)
- **Apache** (XAMPP / WAMP / LAMP with `.htaccess` enabled) or **Nginx**

### Stack

Laravel 12 · Inertia.js 2 · React 19 + TypeScript · Vite · Pest. The SSH, vault and alerting logic lives in `app/Services/`, the JSON API in `app/Http/Controllers/Api/` (routes in `routes/web.php`), and the pages and modals in `resources/js/`.

---

## Quick Start

### 1. Clone & Install Dependencies

```bash
git clone https://github.com/<your-username>/sshhh.git deployer
cd deployer
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

### 2. Configure Environment

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Set your MySQL settings and `APP_URL` in `.env` first. The migrations are safe on a database created by a pre-Laravel SSHhh version: existing tables are kept as they are and only missing columns are added. A 256-bit vault master key is generated at `storage/secret.key` on first use unless `DEPLOYER_SECRET_KEY` is set.

> **Upgrading from the pre-Laravel version:** rename the old `.env` keys (`DEPLOYER_DB_HOST` → `DB_HOST`, `DEPLOYER_DB_NAME` → `DB_DATABASE`, `DEPLOYER_DB_USER` → `DB_USERNAME`, `DEPLOYER_DB_PASS` → `DB_PASSWORD`, `DEPLOYER_APP_NAME` → `APP_NAME`, `DEPLOYER_APP_TAGLINE` → `APP_TAGLINE`, `DEPLOYER_TIMEZONE` → `APP_TIMEZONE`), and keep `DEPLOYER_SECRET_KEY` and `storage/secret.key` exactly as they were, or stored credentials can't be decrypted. Re-run `cli\install_log_watch_task.bat` once. Old webhook URLs (`api.php?action=webhook&token=…`) keep working.

### 3. Open or Install as PWA

- **XAMPP / Apache subdirectory**: `http://localhost/deployer/` (the root `.htaccess` and `index.php` route everything through Laravel; a virtual host should point its DocumentRoot at `public/` instead)
- **Development**: `composer dev` (runs `php artisan serve` and Vite with hot reload)
- **Install as App**: Click **Install App** in the top navigation bar (Chrome / Edge / Android) or **Share → Add to Home Screen** on iOS Safari. *(Note: Service Workers require `localhost` or `HTTPS`.)*

### Tests

```bash
php artisan test
```

Feature tests use a separate MySQL database, `ssh_deployer_testing` (see `phpunit.xml`); create it once before the first run.

---

## Environment Configuration (`.env`)

| Variable | Default | Description |
| :--- | :--- | :--- |
| `APP_NAME` | `"SSHhh"` | Application brand title |
| `APP_TAGLINE` | `"Deployments so quiet, you can hear a server drop."` | Brand tagline |
| `APP_URL` | `http://localhost/deployer` | Where SSHhh is served from |
| `APP_TIMEZONE` | `UTC` | PHP timezone identifier (e.g., `UTC`, `Asia/Manila`, `America/New_York`) |
| `DB_HOST` | `127.0.0.1` | MySQL / MariaDB host |
| `DB_PORT` | `3306` | MySQL / MariaDB port |
| `DB_DATABASE` | `ssh_deployer` | Database name |
| `DB_USERNAME` | `root` | Database username |
| `DB_PASSWORD` | *(empty)* | Database password |
| `DEPLOYER_SECRET_KEY` | *(auto)* | Optional 32+ char master key override (otherwise stored in `storage/secret.key`) |

---

## Built-In Remote Environment Variables

Every remote SSH command runs inside your project's configured **Remote Working Directory** and automatically receives:

- `$DEPLOY_BRANCH` — The project's configured default Git branch (e.g. `main`)
- `$DEPLOY_ENV` — The project's environment (`production`, `staging`, `development`, `testing`)

---

## Git Push Webhooks on a Local Install

GitHub/Bitbucket cannot reach `localhost`. To use push-to-deploy webhooks, expose only the webhook URL through a tunnel (e.g. Cloudflare Tunnel or ngrok) pointing at `http://localhost/deployer/`, and use the URL shown in the project's webhook settings with the tunnel hostname. The webhook is authenticated by its per-project token; everything else still requires Google Authenticator.

---

## Send Laravel Logs to Claude Code (auto-fix)

Each project can be linked to a **Claude Code scheduled task** (formerly "routine") for its repository. In **Remote Server Logs**, **🤖 Send to Claude** then:

1. Reads a snapshot of the selected log over SSH.
2. Groups repeated errors into unique issues (warning level and above, newest sample with its stack trace, limited to the 65,536-character payload size) and masks secrets (passwords, tokens, credentials in `mysql://` / `redis://` URLs, `"password":"…"` values).
3. Starts the project's scheduled task with that report.
4. Once Claude Code confirms it received the report, removes **only the sent part** of the log. Lines written during sending are kept, and the file keeps its owner and permissions. Nothing is cleared if sending fails.

The scheduled task fixes the bugs, runs the builds and tests, commits, and merges into the branch you choose. If a test fails or a fix is uncertain, it opens a pull request instead.

**Setup (once per Laravel project):**

1. In SSHhh, open the project's logs → **🔗 Claude link** → **Copy prompt**.
2. At [claude.ai/code/routines](https://claude.ai/code/routines) create a scheduled task: paste the prompt, select the project's GitHub repository, and add an **API** trigger. Copy the **Fire URL** and **token** (the token is shown only once).
3. Back in SSHhh, paste both, set the merge branch, and click **Save link**.

The token is stored encrypted and never sent back to the browser. Limits: 30 sends per task per hour. Each run uses your Claude subscription usage.

### Automatic checks every 5 minutes

`php artisan sshhh:log-watch` runs every 5 minutes and fetches **every project's** live Laravel log over SSH. By default it reads every `storage/logs/laravel*.log` changed in the last 2 days, so `laravel.log` and daily `laravel-YYYY-MM-DD.log` files are both covered.

- **Projects linked to a Claude Code task** with **Send new errors to Claude automatically** on: a new error-level issue that wasn't reported in the last 24 hours goes through the Claude flow. With error escalation on, you get a push notification first; otherwise it's sent straight to Claude. Then the sent lines are cleared.
- **Every other project:** a new error sends a push notification only, which opens the project page. The log is never cleared or sent anywhere.
- The same error never notifies or sends twice within 24 hours. Warnings never trigger anything on their own. Runs never overlap.

On Windows, double-click **`cli\install_log_watch_task.bat`** once, or again after updating. It creates the task **SSHhh Log Watch** (every 5 minutes, running `php artisan sshhh:log-watch` with `php-win.exe` so no window pops up) and runs one check right away. If you already run `php artisan schedule:run` every minute, the scheduler runs the same check instead. `php artisan sshhh:health-check` pings every project's Health Check URL. XAMPP's MySQL must be running. To remove the task: `schtasks /Delete /TN "SSHhh Log Watch" /F`.

---

## Error Escalation: Push → Claude Code

With **Settings → 📟 Error Escalation → "Ask me before sending new errors to Claude Code"** turned on, the automatic checker does not send new errors straight to Claude. It asks you first:

1. **New error found** → a **push notification** on your phone with **🤖 Proceed** and **Ignore** buttons. The text is a plain summary of 160 characters or less, written by **Gemini** (free tier) or by SSHhh itself when no Gemini key is set or Gemini fails.
2. **No answer within 10 minutes** → the log is **sent to Claude Code automatically** at the next check (10–15 minutes, since checks run every 5 minutes).

**Ignore** stops the same errors from alerting again for 24 hours. Tapping the notification itself opens an alert page with the same two buttons, which is how it works on iPhone, where notification buttons aren't shown. Only one alert is open per project at a time. Anything newer is included when that alert is sent.

**Privacy:** only the masked one-line error message and its `File.php:line` go to Gemini, never stack traces or the full log. On the free tier, Google may use and review what is sent.

### Setup

1. **Open SSHhh over HTTPS on your phone** (e.g. through a Cloudflare Tunnel) and sign in with Google Authenticator. Browsers only allow push notifications on HTTPS sites. On iPhone, tap Share → **Add to Home Screen** and open SSHhh from the icon.
2. **Settings → 📟 Error Escalation → 🔔 Turn on notifications on this device**, then **Send test notification**.
3. **Gemini (optional):** create a free API key at [aistudio.google.com/apikey](https://aistudio.google.com/apikey) and paste it in. **Preview a summary** shows an example.
4. Tick **Ask me before sending new errors to Claude Code** and **Save**.

---

## Log Checker Status

**Settings → 🩺 Log Checker** shows whether the scheduled checker is working:

- **Health:** ✅ *Working* when the scheduled task ran in the last 11 minutes, ❌ *Not running* (with what to check) when it hasn't, ⚠️ when the last run had errors.
- **Projects:** every project, whether it is linked to Claude and checked automatically, its last check time and result.
- **Run history:** every run is recorded, including runs where no project was due yet. Tap a run to see what it did for each project. **▶ Run check now** checks every watched project immediately.

Runs are also written to `storage/log_watch.log`. That file is the only record when the checker can't start, for example when XAMPP's MySQL is stopped. History is kept for 7 days.

---

## Web Server Security Notes

### Apache
The root `.htaccess` serves only `public/build`, `public/assets` and the PWA files directly and sends every other request (including `.env`, `storage/` and `vendor/`) to Laravel, which only answers its own routes. Ensure `AllowOverride All` is active. Better: point a virtual host's DocumentRoot at `public/`.

### Nginx
Point `root` at the `public/` directory and use Laravel's standard config:

```nginx
root /var/www/deployer/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
    fastcgi_buffering off; # live deploy output streams line by line
}
```

---

## Contributing

Contributions of all kinds are warmly welcomed! 🚀

- **Found a bug or have an idea?** Open an [Issue](../../issues).
- **Looking for a starter task?** Check out issues labeled [`good first issue`](../../issues?q=is%3Aissue+is%3Aopen+label%3A%22good+first+issue%22) and [`help wanted`](../../issues?q=is%3Aissue+is%3Aopen+label%3A%22help+wanted%22).
- **Want to contribute code?** Read our detailed [**CONTRIBUTING.md**](CONTRIBUTING.md) guide for local environment setup, architecture overview, and PR guidelines.

---

## License

Released under the [MIT License](LICENSE).
