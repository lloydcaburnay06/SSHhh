# SSH Deployer

A lightweight, self-hosted **SSH Project Deployer** built with **PHP 8.1+**, **MySQL / MariaDB**, and **phpseclib v3**. Manage multiple remote servers and applications, store SSH passwords and private keys in an **AES-256-GCM encrypted vault**, edit ordered deployment command pipelines inline, execute deployments in **1 click** with real-time streaming terminal output, and inspect a **complete event & audit log**.

---

## Features

- **Multi-Project Management**: Add, edit, search, and filter projects across `Production`, `Staging`, `Development`, and `Testing` environments with starter pipeline templates (`Git Pull`, `PHP + Composer`, `Laravel`, `Node.js / PM2`, or custom).
- **AES-256-GCM Encrypted SSH Vault**:
  - Supports **SSH Password** and **SSH Private Key** (+ optional passphrase) authentication via pure-PHP `phpseclib/phpseclib` (no `ext-ssh2` C extension required).
  - Encrypts secrets at rest with **AES-256-GCM** + **HKDF-SHA256**, unique 96-bit IVs, and 128-bit authentication tags.
  - Zero plaintext exposure: stored passwords/keys are never sent back to the browser or written to logs. Leaving the password field blank while editing preserves the saved credential; entering a new one rotates it.
  - 1-click **Test SSH Connection** verifies authentication, records the server's OpenSSH `SHA256` host key fingerprint, and checks whether the remote working directory exists.
- **Inline Editable Deployment Pipeline & 1-Button Execute**:
  - Reorder, toggle (`Include in Deploy All` / `Stop on error`), add preset steps, or edit multi-line shell commands directly on the **Manage Project** page.
  - **1-Click `Deploy Now (Execute All)`** runs all enabled steps sequentially over a single SSH session inside the project's remote working directory (auto-saving any pending inline command edits first).
  - **Per-Step `▶ Run`** and **Quick One-Off Command** runner for single-command execution.
  - **Live Streaming SSH Console** renders `stdout` + `stderr` chunks in real time over NDJSON with ANSI color support.
- **Complete Event & Audit Log**:
  - Logs every full deployment (including per-step exit codes and durations), single/ad-hoc command execution, SSH connection test, and project/SSH/command configuration update.
  - Filter by category (`All`, `Deployments`, `Commands`, `SSH Tests`, `Config & Audit`), status, or full-text search, copy transcripts, or download `.log` files.
- **Safe by Default**:
  - Enforces **localhost-only access** (`127.0.0.1` / `::1`) out of the box when no app password is set, and automatically enables a built-in login gate when `DEPLOYER_APP_PASSWORD` or `DEPLOYER_APP_PASSWORD_HASH` is configured in `.env`.
  - Session-backed CSRF token verification on all state-changing endpoints.

---

## Requirements

- **PHP** `>= 8.1` with extensions: `openssl`, `pdo`, `pdo_mysql`, `mbstring`, `json`
- **MySQL** `>= 5.7` or **MariaDB** `>= 10.3`
- **Composer** `>= 2.0`
- **Apache** (XAMPP / WAMP / LAMP with `mod_rewrite` / `.htaccess` enabled) or **Nginx**

---

## Quick Start

### 1. Clone & Install Dependencies

```bash
git clone https://github.com/<your-username>/ssh-deployer.git deployer
cd deployer
composer install --no-dev --optimize-autoloader
```

### 2. Configure Environment

Copy `.env.example` to `.env` and set your MySQL credentials:

```bash
cp .env.example .env
```

> **Zero-Setup Database**: On first load, SSH Deployer automatically creates the configured MySQL database (default `ssh_deployer`), runs schema migrations, and generates a 256-bit master encryption key at `storage/secret.key`.

### 3. Open in Browser

- **XAMPP / Apache subdirectory**: `http://localhost/deployer/`
- **Dedicated VirtualHost**: `http://deployer.local/`

---

## Environment Configuration (`.env`)

| Variable | Default | Description |
| :--- | :--- | :--- |
| `DEPLOYER_APP_NAME` | `"SSH Deployer"` | Application title shown in the header |
| `DEPLOYER_TIMEZONE` | `UTC` | PHP timezone identifier (e.g., `UTC`, `Asia/Manila`, `America/New_York`) |
| `DEPLOYER_DB_HOST` | `127.0.0.1` | MySQL / MariaDB host |
| `DEPLOYER_DB_PORT` | `3306` | MySQL / MariaDB port |
| `DEPLOYER_DB_NAME` | `ssh_deployer` | Database name (auto-created if permitted) |
| `DEPLOYER_DB_USER` | `root` | Database username |
| `DEPLOYER_DB_PASS` | *(empty)* | Database password |
| `DEPLOYER_APP_PASSWORD` | *(empty)* | Plaintext app login password (enables login screen & remote access) |
| `DEPLOYER_APP_PASSWORD_HASH` | *(empty)* | Recommended bcrypt/argon2 hash (`php -r "echo password_hash('secret', PASSWORD_DEFAULT);"`) |
| `DEPLOYER_ALLOW_REMOTE` | `false` | Allow remote IPs without a login password (**keep `false` outside trusted networks**) |
| `DEPLOYER_SECRET_KEY` | *(auto)* | Optional 32+ char master key override (otherwise stored in `storage/secret.key`) |

---

## Built-In Remote Environment Variables

Every remote SSH command executes inside your project's configured **Remote Working Directory** and automatically receives:

- `$DEPLOY_BRANCH` — The project's configured default Git branch (e.g. `main`)
- `$DEPLOY_ENV` — The project's environment (`production`, `staging`, `development`, `testing`)

---

## Web Server Security Notes

### Apache
`.htaccess` files are included in the root, `config/`, `src/`, `storage/`, and `vendor/` directories to block direct HTTP access (`403 Forbidden`). Ensure `AllowOverride All` is active for your directory in `httpd.conf` / vhost configuration.

### Nginx
Nginx ignores `.htaccess` files. Add the following `location` block inside your Nginx `server {}` configuration to deny access to internal directories and dotfiles:

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

### Backing Up `storage/secret.key`
Back up `storage/secret.key` (or `DEPLOYER_SECRET_KEY`) in a secure password manager. If this key is lost or overwritten, existing encrypted SSH passwords and private keys in the database cannot be decrypted and must be re-entered.

---

## License

Released under the [MIT License](LICENSE).

