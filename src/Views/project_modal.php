<div class="modal-backdrop" id="projectModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="projectModalTitle">
        <div class="modal-header">
            <h2 id="projectModalTitle">Add New Project</h2>
            <button type="button" class="icon-btn" id="closeProjectModalBtn" title="Close">&times;</button>
        </div>
        <form id="projectForm" autocomplete="off">
            <input type="hidden" id="proj_id" name="id" value="">
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
                        <label for="proj_preset">Starter Deployment Commands Template</label>
                        <select class="form-select" id="proj_preset">
                            <option value="git_basic">Git Status + Git Pull (Recommended starter)</option>
                            <option value="php_composer">PHP + Git Pull + Composer Install</option>
                            <option value="laravel">Laravel (Maintenance Mode, Git Pull, Composer, Migrate, Optimize)</option>
                            <option value="node_pm2">Node.js (Git Pull, NPM CI, Build, PM2 Reload)</option>
                            <option value="empty">Empty (Add custom commands manually)</option>
                        </select>
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

