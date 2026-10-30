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
  - **Remember This Device**: Persistent 30-day SHA-256 hashed device cookie so you don't have to enter the 6-digit code every time you launch the app on your phone.
- **AES-256-GCM Encrypted SSH Vault**:
  - Supports **SSH Password** and **SSH Private Key** (+ optional passphrase) authentication via pure-PHP `phpseclib/phpseclib` (no `ext-ssh2` C extension required).
  - Encrypts credentials at rest using **AES-256-GCM** + **HKDF-SHA256**, unique 96-bit IVs, and 128-bit authentication tags.
  - Zero browser exposure: stored secrets are never sent back in HTML, JSON responses, or logs.
  - 1-click **Test SSH Connection** verifies authentication, records the server's OpenSSH `SHA256` host key fingerprint, and checks the remote working directory.
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
| `DEPLOYER_APP_PASSWORD` | *(empty)* | Plaintext app login password (enables login screen & remote/mobile access) |
| `DEPLOYER_APP_PASSWORD_HASH` | *(empty)* | Recommended bcrypt/argon2 hash (`php -r "echo password_hash('secret', PASSWORD_DEFAULT);"`) |
| `DEPLOYER_ALLOW_REMOTE` | `false` | Allow remote IPs without a login password (**keep `false` outside trusted networks**) |
| `DEPLOYER_SECRET_KEY` | *(auto)* | Optional 32+ char master key override (otherwise stored in `storage/secret.key`) |

---

## Built-In Remote Environment Variables

Every remote SSH command runs inside your project's configured **Remote Working Directory** and automatically receives:

- `$DEPLOY_BRANCH` — The project's configured default Git branch (e.g. `main`)
- `$DEPLOY_ENV` — The project's environment (`production`, `staging`, `development`, `testing`)

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
