# Contributing to SSHhh

First off, thank you for considering contributing to **SSHhh**! 🚀 

SSHhh is built with a simple, developer-first philosophy: **lightweight, zero-build-step, and secure deployments that just work**. Whether you are fixing a typo in documentation, polishing mobile responsive styling, adding a new deployment pipeline preset, or implementing a new feature, your contributions are warmly welcomed.

---

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
  - [Good First Issues](#good-first-issues)
  - [Reporting Bugs](#reporting-bugs)
  - [Suggesting Features](#suggesting-features)
  - [Submitting Pull Requests](#submitting-pull-requests)
- [Local Development Setup](#local-development-setup)
  - [Prerequisites](#prerequisites)
  - [Step-by-Step Setup](#step-by-step-setup)
  - [Running the App](#running-the-app)
- [Codebase Architecture & Tour](#codebase-architecture--tour)
- [Coding Standards & Conventions](#coding-standards--conventions)
  - [PHP Guidelines](#php-guidelines)
  - [JavaScript Guidelines (Zero Build-Step)](#javascript-guidelines-zero-build-step)
  - [CSS & Design System](#css--design-system)
  - [Security Best Practices](#security-best-practices)
- [Pull Request (PR) Workflow](#pull-request-pr-workflow)
- [Questions and Help](#questions-and-help)

---

## Code of Conduct

We are committed to providing a welcoming, inclusive, and harassment-free environment for everyone. Please be respectful, constructive, and collaborative in all issues, pull requests, and discussions.

---

## How Can I Contribute?

### Good First Issues
If you are new to the project or looking for a great starting point, look for issues labeled:
- [`good first issue`](https://github.com/aizenaevan/deployer/labels/good%20first%20issue) — Perfect for first-time contributors; typically self-contained fixes or small enhancements.
- [`help wanted`](https://github.com/aizenaevan/deployer/labels/help%20wanted) — Areas where the core team would love community expertise or extra hands.

Some great areas to contribute right now:
- **Pipeline Presets**: Adding starter command templates for common tech stacks (e.g., Docker Compose, Django/Gunicorn, Go binaries, Next.js standalone).
- **Deployment Webhooks**: Integrating notifications (Discord, Slack, Telegram, or custom webhooks) upon deployment success/failure.
- **UI/UX Polish**: Keyboard shortcuts, accessibility (ARIA attributes), touch gestures.
- **Unit & Feature Tests**: Expanding test coverage for cryptographic operations, TOTP math, and repository CRUD methods.

### Reporting Bugs
Before submitting an issue, search the issue tracker to ensure the bug hasn't already been reported.

When filing a bug report, please include:
1. **Clear description**: What happened vs. what you expected to happen.
2. **Environment details**: Operating System, PHP version, MySQL/MariaDB version, browser (desktop or mobile).
3. **Steps to reproduce**: Minimal step-by-step instructions.
4. **Relevant log output**: Any relevant output from the event log or PHP error log (ensure all server IPs, passwords, and private keys are redacted).

### Suggesting Features
Feature requests are always appreciated! Please open an issue outlining:
- The problem or workflow you are trying to solve.
- Your proposed solution or user experience.
- Any alternative approaches considered.

---

## Local Development Setup

### Prerequisites
- **PHP** `>= 8.1` with extensions enabled in `php.ini`:
  - `openssl`
  - `pdo`
  - `pdo_mysql`
  - `mbstring`
  - `json`
- **MySQL** `>= 5.7` or **MariaDB** `>= 10.3` (or XAMPP / WAMP / LAMP / Docker)
- **Composer** `>= 2.0`
- **Git**

### Step-by-Step Setup

1. **Fork & Clone**:
   ```bash
   git clone https://github.com/<your-username>/sshhh.git
   cd sshhh
   ```

2. **Install PHP Dependencies**:
   ```bash
   composer install
   ```
   *(SSHhh uses pure-PHP `phpseclib/phpseclib` for SSH/SFTP and `chillerlan/php-qrcode` for offline SVG QR code generation. No native C extensions required!)*

3. **Configure Environment (`.env`)**:
   Copy the example environment configuration:
   ```bash
   cp .env.example .env
   ```
   Edit `.env` to match your local MySQL credentials:
   ```env
   DEPLOYER_DB_HOST=127.0.0.1
   DEPLOYER_DB_PORT=3306
   DEPLOYER_DB_NAME=ssh_deployer
   DEPLOYER_DB_USER=root
   DEPLOYER_DB_PASS=
   ```

4. **Zero-Setup Database & Vault**:
   You do **not** need to manually import SQL dumps. On initial load, SSHhh automatically:
   - Creates the database `ssh_deployer` if permitted.
   - Executes non-destructive schema migrations (projects, commands, event logs, authentication states, remembered devices).
   - Generates a cryptographically secure 256-bit AES master encryption key in `storage/secret.key`.

### Running the App

#### Option A: Built-in PHP Development Server
```bash
php -S localhost:8080
```
Open [`http://localhost:8080`](http://localhost:8080) in your browser.

#### Option B: XAMPP / WAMP / Apache
Place or symlink the folder in your web root (e.g., `c:\xampp\htdocs\deployer`) and navigate to [`http://localhost/deployer/`](http://localhost/deployer/).

---

## Codebase Architecture & Tour

SSHhh is deliberately architected **without a heavy node/npm build step**. You edit PHP, CSS, or JS files and instantly see your changes upon refresh!

```text
├── api.php                 # JSON REST endpoints & live NDJSON streaming SSH runner
├── index.php               # Main dashboard (projects grid, global event log, stats)
├── project.php             # Single project view (command pipelines, live terminal)
├── sw.js                   # PWA Service Worker (stale-while-revalidate, offline fallback)
├── manifest.webmanifest    # Web App Manifest for mobile/desktop PWA installation
│
├── config/
│   └── config.php          # Base configuration loader & environment defaults
│
├── src/                    # Backend Core Architecture
│   ├── AccessGuard.php     # Google Authenticator TOTP & persistent session guard
│   ├── Bootstrap.php       # Dependency injection container & runtime bootstrap
│   ├── Crypto.php          # AES-256-GCM authenticated encryption at rest (HKDF-SHA256)
│   ├── Database.php        # PDO connection wrapper & non-destructive migrations
│   ├── Env.php             # Lightweight .env parser
│   ├── EventLogger.php     # Audit and execution history persistence
│   ├── ProjectRepository.php # Projects & pipeline commands repository (CRUD, sort, duplicate)
│   ├── SshRunner.php       # SSH execution engine (phpseclib v3, live stdout/stderr streams)
│   ├── Totp.php            # RFC 6238 / RFC 4226 TOTP engine & SVG QR code generator
│   └── Views/              # Modular server-rendered partials
│       ├── header.php      # Top navigation, PWA install button, session controls
│       └── project_modal.php # Add/Edit project modal form with connection test
│
└── assets/                 # Frontend Assets (Zero Build Step)
    ├── css/
    │   └── app.css         # Dark slate theme, mobile responsive design, custom scrollbars
    ├── js/
    │   ├── common.js       # PWA controller, modal manager, ANSI-to-HTML parser, API helpers
    │   ├── dashboard.js    # Projects grid, drag-and-drop sort, search/filter, quick run
    │   └── project.js      # Pipeline card editor, drag reordering, live SSH terminal stream
    └── img/                # PWA icons, SVG logo, and favicons
```

---

## Coding Standards & Conventions

### PHP Guidelines
- **Strict Typing**: Always include `declare(strict_types=1);` at the top of every PHP file.
- **Standards**: Adhere to **PSR-12** code styling.
- **Type Hints**: Use native PHP 8.1+ parameter and return type declarations.
- **Prepared Statements**: Never concatenate dynamic values into SQL queries. Always use PDO parameterized statements.
- **Output Sanitization**: Escape all user-supplied data rendered into HTML templates using `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`.

### JavaScript Guidelines (Zero Build-Step)
- **Vanilla ES2020+**: Keep frontend scripts lightweight and build-step free. Do not introduce Webpack, Babel, or Vite unless discussed and agreed upon.
- **DOM Safety**: Always sanitize untrusted strings before inserting into DOM using `DeployerApp.escapeHtml(str)`.
- **Async/Await**: Prefer modern `async/await` and standard `fetch()` with JSON request bodies.
- **CSRF Tokens**: All mutating POST requests must include the `X-CSRF-Token` header (`window.DEPLOYER_CSRF`).

### CSS & Design System
- **CSS Variables**: Use existing custom properties defined in `:root` in [`assets/css/app.css`](assets/css/app.css) (`--bg`, `--surface`, `--primary`, `--border`, `--font-sans`, `--font-mono`).
- **Responsive & Touch-First**: Support viewport widths down to 320px. Respect notch safe areas using `env(safe-area-inset-bottom, 0px)`.
- **Theme**: Maintain the signature dark slate aesthetic (`#090d16` background with cyan `#06b6d4` and emerald `#10b981` accents).

### Security Best Practices
- **No Plaintext Secrets**: Passwords and private keys must never be logged, echoed, or included in client API responses.
- **Storage Protection**: The `storage/` directory contains encryption keys and session databases; direct HTTP access is blocked via `.htaccess` and server configuration.
- **Single-User TOTP**: Authentication changes must preserve the permanent one-time setup lockout and 30-day device token hashing invariants.

---

## Pull Request (PR) Workflow

1. **Branch Off `main`**:
   Create a descriptive branch for your work:
   ```bash
   git checkout -b feature/slack-webhook-alerts
   # or
   git checkout -b fix/mobile-modal-scroll
   ```

2. **Make Your Changes**:
   Keep commits focused and write concise, descriptive commit messages.

3. **Verify Locally**:
   Run syntax and lint checks before pushing:
   ```bash
   # Validate Composer configuration
   composer validate

   # Check PHP syntax across all source files
   find src -name "*.php" -exec php -l {} \;

   # Check JavaScript syntax
   node --check assets/js/common.js
   node --check assets/js/dashboard.js
   node --check assets/js/project.js
   node --check sw.js
   ```

4. **Update Asset Version If Needed**:
   If modifying `assets/css/app.css`, `assets/js/*.js`, or `manifest.webmanifest`, increment the cache version (e.g., `v1.2.0`) in `sw.js` and the corresponding query parameters in templates so browsers and PWA clients pick up your changes.

5. **Submit Your Pull Request**:
   - Push your branch to your GitHub fork:
     ```bash
     git push origin feature/your-feature-name
     ```
   - Open a Pull Request against the `main` branch.
   - Describe the changes made, the motivation, and attach screenshots or terminal logs where helpful.

---

## Questions and Help

Have a question, feedback, or need guidance on an implementation detail?
- Open a discussion or question on GitHub Issues.
- We love seeing ideas and will happily guide you through your first PR!

Happy deploying! 🚀

