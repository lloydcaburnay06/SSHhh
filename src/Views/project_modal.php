<div class="modal-backdrop" id="projectModal" aria-hidden="true">
    <div class="modal" id="projectModalDialog" role="dialog" aria-modal="true" aria-labelledby="projectModalTitle">
        <div class="modal-header">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; min-width:0;">
                <h2 id="projectModalTitle">Add New Project</h2>
                <span class="dirty-badge unsaved" id="modalDirtyBadge" style="display:none;">Unsaved</span>
            </div>
            <button type="button" class="icon-btn" id="closeProjectModalBtn" title="Close">&times;</button>
        </div>

        <div class="modal-draft-notice" id="modalDraftNotice" style="display:none;">
            <span>&#128190; Restored your unsaved inputs.</span>
            <button type="button" class="btn btn-ghost btn-sm" id="resetModalDraftBtn">Reset Inputs</button>
        </div>

        <div class="modal-unsaved-guard" id="modalUnsavedGuard" style="display:none;" role="alert">
            <div class="guard-text">
                <strong>You have unsaved changes.</strong> Prevented accidental close—choose an action:
            </div>
            <div class="guard-actions">
                <button type="button" class="btn btn-primary btn-sm" id="modalKeepEditingBtn">Keep Editing</button>
                <button type="button" class="btn btn-secondary btn-sm" id="modalKeepDraftCloseBtn">Close &amp; Keep Inputs</button>
                <button type="button" class="btn btn-danger btn-sm" id="modalDiscardCloseBtn">Discard &amp; Close</button>
            </div>
        </div>

        <form id="projectForm" autocomplete="off">
            <input type="hidden" id="proj_id" name="id" value="">
            <input type="hidden" id="proj_source_id" name="source_project_id" value="">
            <div class="modal-body">
                <div class="form-section-title">Project Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="proj_name">Project Name *</label>
                        <input type="text" class="form-control" id="proj_name" required placeholder="e.g. Acme Customer Portal">
                    </div>
                    <div class="form-group">
                        <label for="proj_environment">Environment</label>
                        <select class="form-select" id="proj_environment">
                            <option value="production">Production</option>
                            <option value="staging">Staging</option>
                            <option value="development">Development</option>
                            <option value="testing">Testing</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="proj_remote_path">Remote Working Directory</label>
                        <input type="text" class="form-control" id="proj_remote_path" placeholder="e.g. /var/www/html/my-app">
                        <span class="form-hint">Commands automatically execute inside this remote folder.</span>
                    </div>
                    <div class="form-group">
                        <label for="proj_branch">Default Git Branch</label>
                        <input type="text" class="form-control" id="proj_branch" value="main" placeholder="main">
                        <span class="form-hint">Exposed to commands as <code>$DEPLOY_BRANCH</code>.</span>
                    </div>
                    <div class="form-group full" id="presetGroup">
                        <label for="proj_preset">
                            <span id="presetGroupLabel">Deployment Commands Template</span>
                            <span class="secret-saved-hint" id="duplicateTemplateBadge" style="display:none;">&#128203; Cloning command template</span>
                        </label>
                        <select class="form-select" id="proj_preset">
                            <optgroup label="Clone Command Template From Existing Project" id="existingProjectTemplatesGroup" hidden></optgroup>
                            <optgroup label="Built-in Starter Templates">
                                <option value="git_basic">Git Status + Git Pull (Recommended starter)</option>
                                <option value="php_composer">PHP + Git Pull + Composer Install</option>
                                <option value="laravel">Laravel (Maintenance Mode, Git Pull, Composer, Migrate, Optimize)</option>
                                <option value="node_pm2">Node.js (Git Pull, NPM CI, Build, PM2 Reload)</option>
                                <option value="empty">Empty (Add custom commands manually)</option>
                            </optgroup>
                        </select>
                        <span class="form-hint" id="presetHint">Select a starter pipeline or clone all deployment commands from an existing project.</span>
                    </div>
                    <div class="form-group full">
                        <label for="proj_description">Notes / Description (Optional)</label>
                        <input type="text" class="form-control" id="proj_description" placeholder="Optional server notes or URL">
                    </div>
                </div>

                <div class="form-section-title" id="sshSectionHeader">SSH Connection &amp; Encrypted Credentials</div>
                <div class="form-grid-3">
                    <div class="form-group">
                        <label for="proj_ssh_host">SSH Host / IP *</label>
                        <input type="text" class="form-control" id="proj_ssh_host" required placeholder="e.g. 203.0.113.10 or app.example.com">
                    </div>
                    <div class="form-group">
                        <label for="proj_ssh_port">SSH Port *</label>
                        <input type="number" class="form-control" id="proj_ssh_port" required min="1" max="65535" value="22">
                    </div>
                    <div class="form-group">
                        <label for="proj_ssh_username">SSH Username *</label>
                        <input type="text" class="form-control" id="proj_ssh_username" required placeholder="e.g. deploy or ubuntu">
                    </div>
                </div>

                <div class="form-grid" style="margin-top: 14px;">
                    <div class="form-group">
                        <label for="proj_ssh_auth_type">Authentication Method</label>
                        <select class="form-select" id="proj_ssh_auth_type">
                            <option value="password">SSH Password (Encrypted AES-256-GCM)</option>
                            <option value="key">SSH Private Key (Encrypted AES-256-GCM)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="proj_command_timeout">Command Timeout (seconds)</label>
                        <input type="number" class="form-control" id="proj_command_timeout" min="5" max="3600" value="300">
                    </div>

                    <div class="form-group full duplicate-cred-box" id="duplicateCredReuseWrap" style="display:none;">
                        <label class="toggle-label" for="proj_reuse_credentials" style="justify-content:flex-start; color:var(--text-primary); font-size:13px;">
                            <input type="checkbox" id="proj_reuse_credentials" checked>
                            <span id="reuseCredLabel">Reuse saved encrypted SSH credentials from source project</span>
                        </label>
                        <span class="form-hint" id="reuseCredHint">Re-encrypts stored credentials server-side with a fresh AES-256-GCM IV without exposing secrets. Uncheck or enter a new password/key below for a different server.</span>
                    </div>

                    <div class="form-group full" id="passwordAuthFields">
                        <label for="proj_ssh_password">
                            <span>SSH Password</span>
                            <span class="secret-saved-hint" id="savedPasswordBadge" style="display:none;">&#128274; Encrypted password stored</span>
                        </label>
                        <div class="password-input-wrap">
                            <input type="password" class="form-control" id="proj_ssh_password" autocomplete="new-password" placeholder="Enter SSH password">
                            <button type="button" class="pw-toggle-btn" id="togglePwVisibilityBtn">Show</button>
                        </div>
                        <span class="form-hint" id="passwordHint">Encrypted at rest with AES-256-GCM and never sent back to the browser.</span>
                    </div>

                    <div class="form-group full" id="keyAuthFields" style="display:none;">
                        <label for="proj_ssh_private_key">
                            <span>SSH Private Key (PEM / OpenSSH)</span>
                            <span class="secret-saved-hint" id="savedKeyBadge" style="display:none;">&#128274; Encrypted private key stored</span>
                        </label>
                        <textarea class="step-cmd-editor" id="proj_ssh_private_key" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
                        <div style="margin-top:8px;">
                            <label for="proj_ssh_passphrase" style="margin-bottom:4px;">Key Passphrase (Optional)</label>
                            <input type="password" class="form-control" id="proj_ssh_passphrase" autocomplete="new-password" placeholder="Optional private key passphrase" style="width:100%;">
                        </div>
                    </div>
                </div>

                <div class="ssh-test-banner" id="modalSshTestBanner"></div>

                <!-- Advanced Deployment & Automation Options -->
                <div class="form-section-title" style="margin-top:20px;">Deployment Strategy &amp; Automation</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="proj_deploy_strategy">Deployment Strategy</label>
                        <select class="form-select" id="proj_deploy_strategy">
                            <option value="direct">Direct Path (Standard `cd &lt;remote_path&gt;`)</option>
                            <option value="symlink">Atomic Zero-Downtime (releases/ &bull; current symlink)</option>
                        </select>
                        <span class="form-hint">Atomic mode prepares releases in timestamped folders and flips a symlink for 0 downtime.</span>
                    </div>

                    <div class="form-group" id="projReleasesToKeepWrap">
                        <label for="proj_releases_to_keep">Releases to Keep</label>
                        <input type="number" class="form-control" id="proj_releases_to_keep" min="2" max="30" value="5">
                        <span class="form-hint">Older releases are automatically pruned after successful deploys.</span>
                    </div>

                    <div class="form-group full" style="padding:12px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:8px;">
                        <label class="toggle-label" for="proj_backup_db_before_deploy" style="margin-bottom:6px; color:var(--text-primary); font-weight:600;">
                            <input type="checkbox" id="proj_backup_db_before_deploy">
                            <span>🛡️ Automated Pre-Deploy Database Snapshot</span>
                        </label>
                        <span class="form-hint" style="margin-bottom:10px; display:block;">Creates a compressed SQL dump in <code>storage/backups/</code> before running deployment migrations.</span>
                        <div id="backupDriverWrap" style="display:none; max-width:260px;">
                            <label for="proj_backup_db_driver" style="font-size:11.5px; margin-bottom:4px;">Database Driver</label>
                            <select class="form-select form-select-sm" id="proj_backup_db_driver">
                                <option value="auto">Auto-detect from .env</option>
                                <option value="mysql">MySQL / MariaDB</option>
                                <option value="postgres">PostgreSQL</option>
                                <option value="sqlite">SQLite</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group full">
                        <label for="proj_health_check_url">
                            <span>Post-Deploy Health Check URL</span>
                            <span id="projHealthBadge" class="badge" style="display:none; margin-left:6px;"></span>
                        </label>
                        <div style="display:flex; gap:8px;">
                            <input type="url" class="form-control" id="proj_health_check_url" placeholder="https://app.example.com/up or /api/health">
                            <button type="button" class="btn btn-secondary btn-sm" id="projTestHealthBtn" style="white-space:nowrap;">Test Ping</button>
                        </div>
                        <span class="form-hint">Pings this URL immediately after deployment. Returns verified green if HTTP 200 OK.</span>
                    </div>

                    <div class="form-group full" style="padding:12px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:8px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label class="toggle-label" for="proj_webhook_enabled" style="margin-bottom:0; color:var(--text-primary); font-weight:600;">
                                <input type="checkbox" id="proj_webhook_enabled">
                                <span>🚀 Git Push Webhook ("Push to Deploy")</span>
                            </label>
                        </div>
                        <span class="form-hint" style="margin-bottom:10px; display:block;">Trigger this project's deployment pipeline automatically whenever you push to GitHub, GitLab, or Bitbucket.</span>
                        <div id="webhookDetailsWrap" style="display:none;">
                            <div style="margin-bottom:8px;">
                                <label for="proj_webhook_branch" style="font-size:11.5px; margin-bottom:4px;">Target Webhook Branch</label>
                                <input type="text" class="form-control form-control-sm" id="proj_webhook_branch" value="main" placeholder="main" style="max-width:240px;">
                            </div>
                            <div>
                                <label style="font-size:11.5px; margin-bottom:4px;">Webhook Payload URL</label>
                                <div style="display:flex; gap:8px;">
                                    <input type="text" class="form-control form-control-sm" id="projWebhookUrl" readonly style="font-family:var(--font-mono); font-size:11.5px; color:var(--text-secondary);">
                                    <button type="button" class="btn btn-secondary btn-xs" id="copyWebhookUrlBtn" style="white-space:nowrap;">📋 Copy</button>
                                    <button type="button" class="btn btn-ghost btn-xs" id="regenWebhookTokenBtn" title="Regenerate secret token" style="white-space:nowrap;">🔄 New Token</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="modalTestSshBtn">
                    <span>&#9889;</span>
                    <span>Test SSH Connection</span>
                </button>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="btn btn-ghost" id="cancelProjectModalBtn">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveProjectBtn">Save Project</button>
                </div>
            </div>
        </form>
    </div>
</div>
<div class="toast-stack" id="toastStack"></div>
