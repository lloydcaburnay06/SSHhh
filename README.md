<p align="center">
  <img src="assets/img/logo.svg" alt="SSHhh — Deployments so quiet, you can hear a server drop." width="520">
</p>

<p align="center">
  <em>"Deployments so quiet, you can hear a server drop."</em>
</p>

---

# SSHhh

**SSHhh** is a lightweight, self-hosted, mobile-ready **Progressive Web App (PWA) SSH Deployer** built with **PHP 8.1+**, **MySQL / MariaDB**, and **phpseclib v3**. Manage all your servers and projects, store SSH passwords and private keys in an **AES-256-GCM encrypted vault**, edit ordered deployment command pipelines inline, trigger **1-click live streaming deployments** from desktop or mobile, and inspect a **complete event & audit log**.

---

## Features

- **Multi-Project Dashboard**: Add, update, search, and filter projects across `Production`, `Staging`, `Development`, and `Testing` environments with starter pipeline presets (`Git Pull`, `PHP + Composer`, `Laravel`, `Node.js / PM2`, or custom).
- **AES-256-GCM Encrypted SSH Vault**:
  - Supports **SSH Password** and **SSH Private Key** (+ optional passphrase) authentication via pure-PHP `phpseclib/phpseclib` (no `ext-ssh2` C extension required).
  - Encrypts credentials at rest using **AES-256-GCM** + **HKDF-SHA256**, unique 96-bit IVs, and 128-bit authentication tags.
  - Zero browser exposure: stored secrets are never sent back in HTML, JSON responses, or logs. Leaving the password field blank while editing preserves your encrypted secret; entering a new password rotates it.
  - 1-click **Test SSH Connection** verifies authentication, records the server's OpenSSH `SHA256` host key fingerprint, and checks the remote working directory.
- **Inline Editable Deployment Pipeline & 1-Button Execute**:
  - Edit step labels and multi-line shell commands directly on the **Manage Project** page, reorder steps (`↑` / `↓`), toggle `Include in Deploy All` / `Stop on error`, or insert 1-click preset steps.
  - **1-Click `Deploy Now (Execute All)`** runs all enabled steps sequentially over a single SSH session inside the project's remote working directory (automatically saving any pending inline command edits first).
  - **Per-Step `▶ Run`** and **Quick One-Off Command** runner for single-command execution.
  - **Live Streaming SSH Console** streams `stdout` + `stderr` in real time over NDJSON with ANSI color rendering.
- **Installable PWA & Mobile Responsive**:
  - Web App Manifest (`manifest.webmanifest`), custom `192x192` / `512x512` / maskable / Apple Touch icons, Service Worker (`sw.js`) with offline fallback (`offline.html`), and a 1-click **Install App** prompt.
  - Touch-optimized responsive UI with notched-display safe areas (`viewport-fit=cover`), bottom-sheet mobile modals, and iOS zoom-safe inputs.
- **Complete Event & Audit Log**:
  - Persists every full deployment (with per-step exit codes and durations), single/ad-hoc command run, SSH connection test, and project/SSH/command configuration audit change.
  - Filter by category (`All`, `Deployments`, `Commands`, `SSH Tests`, `Config & Audit`), status, or search query, copy output, or download `.log` files.

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

## License

Released under the [MIT License](LICENSE).
