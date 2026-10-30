# Security Policy & Architecture

## Credential Encryption Vault

- **Cipher**: All SSH passwords, SSH private keys, and key passphrases are encrypted at rest using **AES-256-GCM** authenticated encryption (`openssl_encrypt` / `openssl_decrypt`) with **HKDF-SHA256** key derivation, a unique 96-bit CSPRNG initialization vector per secret, context-bound Additional Authenticated Data (AAD), and a 128-bit authentication tag.
- **Zero Plaintext Exposure**: Once stored, neither plaintext secrets nor encrypted payloads are ever returned in HTML views, JSON API responses, or audit event logs.
- **Master Key Management**: On first launch, a 256-bit cryptographically random key is generated and written to `storage/secret.key` (ignored by Git via `.gitignore`). You may alternatively supply the key via the `DEPLOYER_SECRET_KEY` environment variable.

## Deployment Hardening Checklist

1. **Never commit `.env` or `storage/secret.key`** to version control.
2. **Back up `storage/secret.key`** in a secure password manager. If the master key is lost, stored SSH credentials cannot be decrypted and will need to be re-entered.
3. **Remote / Non-Localhost Hosting**:
   - By default, unauthenticated access is restricted to `127.0.0.1` and `::1` (`AccessGuard`).
   - Before exposing SSH Deployer over a LAN or public network, serve over **HTTPS** and configure `DEPLOYER_APP_PASSWORD_HASH` (recommended, generated with `php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT);"`) or `DEPLOYER_APP_PASSWORD` in `.env`.
4. **Web Server Rules**:
   - **Apache**: Protected out of the box via `.htaccess` files in the root, `config/`, `src/`, `storage/`, and `vendor/`. Ensure `AllowOverride All` is enabled in your Apache vhost.
   - **Nginx**: Nginx does not read `.htaccess` files. You **must** block direct access to internal directories in your Nginx server block (see `README.md`).

## Reporting a Vulnerability

Please report security issues privately via GitHub Security Advisories on this repository rather than opening a public issue.

