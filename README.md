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

- **PHP** `>= 8.1` with extensions: `openssl`, `pdo`, `pdo_mysql`, `mbstring`, `json`
- **MySQL** `>= 5.7` or **MariaDB** `>= 10.3`
- **Composer** `>= 2.0`
- **Apache** (XAMPP / WAMP / LAMP with `.htaccess` enabled) or **Nginx**

---

## Quick Start

### 1. Clone & Install Dependencies

```bash
git clone https://github.com/<your-username>/sshhh.git deployer
cd deployer
composer install --no-dev --optimize-autoloader
```

### 2. Configure Environment

Copy `.env.example` to `.env` and adjust your MySQL settings:

```bash
cp .env.example .env
```

> **Zero-Setup Database**: On first load, SSHhh automatically creates the configured MySQL database (default `ssh_deployer`), runs schema migrations, and generates a 256-bit master encryption key at `storage/secret.key`.

### 3. Open or Install as PWA

- **XAMPP / Apache subdirectory**: `http://localhost/deployer/`
- **Install as App**: Click **Install App** in the top navigation bar (Chrome / Edge / Android) or **Share → Add to Home Screen** on iOS Safari. *(Note: Service Workers require `localhost` or `HTTPS`.)*

---

## Environment Configuration (`.env`)

| Variable | Default | Description |
| :--- | :--- | :--- |
| `DEPLOYER_APP_NAME` | `"SSHhh"` | Application brand title |
| `DEPLOYER_APP_TAGLINE` | `"Deployments so quiet, you can hear a server drop."` | Brand tagline |
| `DEPLOYER_TIMEZONE` | `UTC` | PHP timezone identifier (e.g., `UTC`, `Asia/Manila`, `America/New_York`) |
| `DEPLOYER_DB_HOST` | `127.0.0.1` | MySQL / MariaDB host |
| `DEPLOYER_DB_PORT` | `3306` | MySQL / MariaDB port |
| `DEPLOYER_DB_NAME` | `ssh_deployer` | Database name (auto-created if permitted) |
| `DEPLOYER_DB_USER` | `root` | Database username |
| `DEPLOYER_DB_PASS` | *(empty)* | Database password |
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

### Automatic checks every 10 minutes

`cli/log_watch.php` checks the log of every project that has **Check this log automatically** turned on in its Claude link. It only starts a Claude Code session when the log has an **error-level issue not reported in the last 24 hours**. A recurring error whose fix is merged but not deployed yet does not start a new session every 10 minutes. Warnings are included in reports but never start one by themselves. Runs never overlap, and each run is recorded in `storage/log_watch.log` and in the Claude link panel.

On Windows, double-click **`cli\install_log_watch_task.bat`** once. It creates the task **SSHhh Log Watch** (every 10 minutes, using `php-win.exe` so no window pops up) and runs one check right away. XAMPP's MySQL must be running. To remove the task: `schtasks /Delete /TN "SSHhh Log Watch" /F`.

---

## Error Escalation: Push → SMS → Claude Code

With **Settings → 📟 Error Escalation → "Ask me before sending new errors to Claude Code"** turned on, the automatic checker does not send new errors straight to Claude. It asks you first:

1. **New error found** → a **push notification** on your phone with **🤖 Proceed** and **Ignore** buttons. The text is a plain SMS-style summary of 160 characters or less, written by **Gemini** (free tier) or by SSHhh itself when no Gemini key is set or Gemini fails.
2. **No answer within 10 minutes** → the same summary is sent by **SMS (PhilSMS)**. The SMS is only an alert; decide with the notification or in SSHhh. SMS is only ever sent for errors.
3. **Still no answer 10 minutes after the SMS** → the log is **sent to Claude Code automatically**.

**Ignore** stops the same errors from alerting again for 24 hours. Tapping the notification itself opens an alert page with the same two buttons, which is how it works on iPhone, where notification buttons aren't shown. Only one alert is open per project at a time. Anything newer is included when that alert is sent.

**Privacy:** only the masked one-line error message and its `File.php:line` go to Gemini, never stack traces or the full log. On the free tier, Google may use and review what is sent.

### Setup

1. **Get your phone to SSHhh over HTTPS.** Browsers only allow push notifications on HTTPS sites. A Cloudflare Tunnel is free and needs no open ports. Install `cloudflared` on this PC, run `cloudflared tunnel login`, then create a tunnel that points a hostname such as `deployer.yourdomain.com` at `http://localhost:80` (or `http://localhost:8090` if you serve SSHhh on its own port). Open that address on your phone and sign in with Google Authenticator. On iPhone, tap Share → **Add to Home Screen** and open SSHhh from the icon, since iOS only allows push for installed web apps.
2. **Turn on notifications:** on the phone, open **Settings → 📟 Error Escalation → 🔔 Turn on notifications on this device**, then **Send test notification**.
3. **Gemini (optional):** create a free API key at [aistudio.google.com/apikey](https://aistudio.google.com/apikey) and paste it in. **Preview a summary** shows an example SMS.
4. **PhilSMS:** paste your API token, your approved **Sender ID**, and your mobile number, then **Send test SMS**.
5. Tick **Ask me before sending new errors to Claude Code** and **Save**.
6. **Re-run `cli\install_log_watch_task.bat`**: the task now runs every minute so the 10-minute timers are on time. Logs are still checked every 10 minutes per project.

---

## Web Server Security Notes

### Apache
`.htaccess` files are included in the root, `config/`, `src/`, `storage/`, and `vendor/` directories to block direct HTTP access (`403 Forbidden`). Ensure `AllowOverride All` is active in your Apache configuration.

### Nginx
Nginx ignores `.htaccess` files. Add the following rules inside your Nginx `server {}` block:

```nginx
location ~ ^/(\.git|\.github|config|src|storage|vendor)/ {
    deny all;
    return 403;
}

location ~ /(\.|composer\.(json|lock)|README\.md|SECURITY\.md|LICENSE) {
    deny all;
    return 403;
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
