# Security Policy & Architecture — SSHhh

## Credential Encryption Vault

- **Cipher**: All SSH passwords, SSH private keys, and key passphrases are encrypted at rest using **AES-256-GCM** authenticated encryption (`openssl_encrypt` / `openssl_decrypt`) with **HKDF-SHA256** key derivation, a unique 96-bit CSPRNG initialization vector per secret, context-bound Additional Authenticated Data (AAD), and a 128-bit authentication tag.
- **Zero Plaintext Exposure**: Once stored, neither plaintext secrets nor encrypted payloads are ever returned in HTML views, JSON API responses, service worker caches, or audit event logs.
- **Master Key Management**: On first launch, a 256-bit cryptographically random key is generated and written to `storage/secret.key` (ignored by Git via `.gitignore`). You may alternatively supply the key via the `DEPLOYER_SECRET_KEY` environment variable.

## Deployment Hardening Checklist

1. **Never commit `.env` or `storage/secret.key`** to version control.
2. **Back up `storage/secret.key`** in a secure password manager. If the master key is lost, stored SSH credentials cannot be decrypted and will need to be re-entered.
3. **Remote / Mobile / Non-Localhost Hosting**:
   - All pages and API calls require a Google Authenticator (TOTP) code or a remembered-device cookie (`AccessGuard`). The only exception is the webhook endpoint, which is authenticated by its per-project token.
   - The first-time authenticator setup can only be completed from `127.0.0.1` / `::1`, so nobody on your network can claim an unconfigured install.
   - Login is locked for 15 minutes after 5 wrong codes from the same IP.
   - Before exposing SSHhh over a LAN or public network (for example, to use it from your mobile phone as a PWA), serve it over **HTTPS** so session and device cookies are not sent in the clear.
   - SSH host keys are pinned per project; a changed key blocks deployments until you re-run **Test SSH Connection**.
4. **Web Server Rules**:
   - **Apache**: Protected out of the box via `.htaccess` files in the root, `config/`, `src/`, `storage/`, and `vendor/`. Ensure `AllowOverride All` is enabled in your Apache vhost.
   - **Nginx**: Nginx does not read `.htaccess` files. You **must** block direct access to internal directories in your Nginx server block (see `README.md`).

## Reporting a Vulnerability

Please report security issues privately via GitHub Security Advisories on this repository rather than opening a public issue.
