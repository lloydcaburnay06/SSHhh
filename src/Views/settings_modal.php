<!-- Global Settings, Shared SSH Profiles, Notifications & Encrypted Vault Modal -->
<div class="modal-backdrop" id="settingsModal" aria-hidden="true" style="display:none;">
    <div class="modal settings-modal" role="dialog" aria-modal="true" aria-labelledby="settingsModalTitle">
        <div class="modal-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="confirm-icon-bubble variant-deploy" style="font-size:18px;">⚙️</div>
                <div>
                    <h2 id="settingsModalTitle" style="margin:0; font-size:16px;">Workspace Settings &amp; Vault</h2>
                    <p style="margin:2px 0 0; font-size:12px; color:var(--text-secondary);">Shared SSH server profiles, outbound alerts &amp; encrypted backup/restore</p>
                </div>
            </div>
            <button type="button" class="icon-btn" id="settingsModalCloseBtn" title="Close">&times;</button>
        </div>

        <div class="settings-tabs-bar" role="tablist">
            <button type="button" class="settings-tab-btn active" data-settings-tab="profiles" role="tab" aria-selected="true">🖥️ Shared SSH Profiles</button>
            <button type="button" class="settings-tab-btn" data-settings-tab="notifications" role="tab" aria-selected="false">🔔 Outbound Alerts</button>
            <button type="button" class="settings-tab-btn" data-settings-tab="checker" role="tab" aria-selected="false">🩺 Log Checker</button>
            <button type="button" class="settings-tab-btn" data-settings-tab="alerts" role="tab" aria-selected="false">📟 Error Escalation</button>
            <button type="button" class="settings-tab-btn" data-settings-tab="vault" role="tab" aria-selected="false">🛡️ Vault Backup &amp; Restore</button>
        </div>

        <div class="modal-body" style="padding:18px 20px;">
            <!-- Tab 1: Shared SSH Server Profiles -->
            <div class="settings-tab-pane active" id="settingsPaneProfiles">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
                    <p style="margin:0; font-size:12.5px; color:var(--text-secondary);">
                        Store SSH credentials once and link multiple projects/domains on the same server. Updating a profile updates all linked projects.
                    </p>
                    <button type="button" class="btn btn-primary btn-sm" id="newSshProfileBtn">+ New Server Profile</button>
                </div>

                <div id="sshProfilesList" style="display:flex; flex-direction:column; gap:8px; margin-bottom:14px;"></div>

                <form id="sshProfileForm" style="display:none; padding:14px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:10px;" autocomplete="off">
                    <input type="hidden" id="prof_id" value="">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <strong id="sshProfileFormTitle" style="font-size:13.5px;">Create Shared Server Profile</strong>
                        <button type="button" class="btn btn-ghost btn-xs" id="cancelSshProfileFormBtn">Cancel</button>
                    </div>
                    <div class="form-grid">
                        <div class="form-group full">
                            <label for="prof_name">Profile Label *</label>
                            <input type="text" class="form-control" id="prof_name" required placeholder="e.g. Hostinger Production Cluster 1">
                        </div>
                    </div>
                    <div class="form-grid-3" style="margin-top:10px;">
                        <div class="form-group">
                            <label for="prof_ssh_host">SSH Host / IP *</label>
                            <input type="text" class="form-control" id="prof_ssh_host" required placeholder="145.79.25.231">
                        </div>
                        <div class="form-group">
                            <label for="prof_ssh_port">Port *</label>
                            <input type="number" class="form-control" id="prof_ssh_port" required min="1" max="65535" value="22">
                        </div>
                        <div class="form-group">
                            <label for="prof_ssh_username">Username *</label>
                            <input type="text" class="form-control" id="prof_ssh_username" required placeholder="u812193548">
                        </div>
                    </div>
                    <div class="form-grid" style="margin-top:10px;">
                        <div class="form-group">
                            <label for="prof_ssh_auth_type">Auth Method</label>
                            <select class="form-select" id="prof_ssh_auth_type">
                                <option value="password">SSH Password</option>
                                <option value="key">SSH Private Key</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="prof_ssh_timeout">Connect Timeout (sec)</label>
                            <input type="number" class="form-control" id="prof_ssh_timeout" min="3" max="120" value="15">
                        </div>
                        <div class="form-group full" id="profPasswordWrap">
                            <label for="prof_ssh_password">SSH Password <span id="profSavedPwBadge" class="secret-saved-hint" style="display:none;">&#128274; Stored</span></label>
                            <input type="password" class="form-control" id="prof_ssh_password" autocomplete="new-password" placeholder="Enter SSH password">
                        </div>
                        <div class="form-group full" id="profKeyWrap" style="display:none;">
                            <label for="prof_ssh_private_key">SSH Private Key <span id="profSavedKeyBadge" class="secret-saved-hint" style="display:none;">&#128274; Stored</span></label>
                            <textarea class="step-cmd-editor" id="prof_ssh_private_key" rows="3" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
                            <input type="password" class="form-control" id="prof_ssh_passphrase" autocomplete="new-password" placeholder="Optional key passphrase" style="margin-top:6px;">
                        </div>
                    </div>
                    <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px;">
                        <button type="submit" class="btn btn-primary btn-sm" id="saveSshProfileBtn">Save Server Profile</button>
                    </div>
                </form>
            </div>

            <!-- Tab 2: Outbound Notification Alerts -->
            <div class="settings-tab-pane" id="settingsPaneNotifications" style="display:none;">
                <form id="notificationSettingsForm" autocomplete="off">
                    <div style="padding:12px 14px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:8px; margin-bottom:14px;">
                        <label class="toggle-label" for="notify_enabled" style="color:var(--text-primary); font-weight:600; margin:0;">
                            <input type="checkbox" id="notify_enabled">
                            <span>Enable Outbound Deployment &amp; Health Notifications</span>
                        </label>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="notify_channel">Notification Channel</label>
                            <select class="form-select" id="notify_channel">
                                <option value="discord">Discord Webhook</option>
                                <option value="slack">Slack Incoming Webhook</option>
                                <option value="telegram">Telegram Bot API</option>
                                <option value="webhook">Custom JSON Webhook</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Notify On Events</label>
                            <div style="display:flex; flex-direction:column; gap:6px; padding-top:4px;">
                                <label class="toggle-label" for="notify_on_deploy_success" style="font-size:12.5px;">
                                    <input type="checkbox" id="notify_on_deploy_success" checked>
                                    <span>✅ Deployment succeeded</span>
                                </label>
                                <label class="toggle-label" for="notify_on_deploy_failure" style="font-size:12.5px;">
                                    <input type="checkbox" id="notify_on_deploy_failure" checked>
                                    <span>🚨 Deployment failed</span>
                                </label>
                                <label class="toggle-label" for="notify_on_health_down" style="font-size:12.5px;">
                                    <input type="checkbox" id="notify_on_health_down" checked>
                                    <span>🔴 Health URL alert / recovery</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-group full" id="notifyWebhookUrlWrap">
                            <label for="notify_webhook_url">
                                <span>Webhook URL</span>
                                <span id="notifySavedWebhookBadge" class="secret-saved-hint" style="display:none;">&#128274; Stored</span>
                            </label>
                            <input type="url" class="form-control" id="notify_webhook_url" placeholder="https://discord.com/api/webhooks/...">
                            <span class="form-hint" id="notifyWebhookHint">Encrypted at rest with AES-256-GCM. Leave blank to keep existing saved webhook URL.</span>
                        </div>

                        <div class="form-group" id="notifyTelegramTokenWrap" style="display:none;">
                            <label for="notify_telegram_token">
                                <span>Telegram Bot Token</span>
                                <span id="notifySavedTgBadge" class="secret-saved-hint" style="display:none;">&#128274; Stored</span>
                            </label>
                            <input type="password" class="form-control" id="notify_telegram_token" autocomplete="new-password" placeholder="123456:ABC-DEF...">
                        </div>
                        <div class="form-group" id="notifyTelegramChatWrap" style="display:none;">
                            <label for="notify_telegram_chat_id">Telegram Chat ID</label>
                            <input type="text" class="form-control" id="notify_telegram_chat_id" placeholder="-1001234567890">
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; gap:8px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" id="testNotificationBtn">🔔 Send Test Alert</button>
                        <button type="submit" class="btn btn-primary btn-sm" id="saveNotificationsBtn">Save Notification Settings</button>
                    </div>
                </form>
            </div>

            <!-- Tab: Automatic log checker status & run history -->
            <div class="settings-tab-pane" id="settingsPaneChecker" style="display:none;">
                <div id="checkerHealth" style="padding:12px 14px; border-radius:8px; border:1px solid var(--border-color); margin-bottom:10px; font-size:13px;">Loading…</div>
                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
                    <span id="checkerToday" style="font-size:12px; color:var(--text-secondary);"></span>
                    <span style="display:flex; gap:6px;">
                        <button type="button" class="btn btn-ghost btn-sm" id="checkerRefreshBtn">↻ Refresh</button>
                        <button type="button" class="btn btn-primary btn-sm" id="checkerRunNowBtn">▶ Run check now</button>
                    </span>
                </div>
                <h4 style="margin:6px 0; font-size:13.5px;">Projects</h4>
                <div id="checkerProjects" style="font-size:12.5px; margin-bottom:12px;"></div>
                <h4 style="margin:6px 0; font-size:13.5px;">Run history <span style="font-weight:400; color:var(--text-muted); font-size:11.5px;">(tap a run for details)</span></h4>
                <div id="checkerRuns" style="font-size:12px; max-height:340px; overflow:auto;"></div>
            </div>

            <!-- Tab: Error escalation (push → Claude Code) -->
            <div class="settings-tab-pane" id="settingsPaneAlerts" style="display:none;">
                <form id="alertSettingsForm" autocomplete="off">
                    <div style="padding:12px 14px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:8px; margin-bottom:12px;">
                        <label class="toggle-label" for="alert_enabled" style="color:var(--text-primary); font-weight:600; margin:0;">
                            <input type="checkbox" id="alert_enabled">
                            <span>Ask me before sending new errors to Claude Code</span>
                        </label>
                        <p class="form-hint" style="margin:6px 0 0;">
                            When the automatic checker finds a new error: <strong>push notification</strong> (Proceed / Ignore) →
                            no answer in <input type="number" id="alert_push_wait_min" class="form-control inline-number" min="1" max="120" value="10"> min → <strong>sent to Claude Code</strong>.
                            Turn this off to send new errors to Claude right away.
                        </p>
                    </div>

                    <h4 style="margin:14px 0 6px; font-size:13.5px;">📱 Push notifications</h4>
                    <div id="alertPushInsecure" class="form-hint" style="display:none; color:#fca5a5; margin-bottom:6px;">
                        This page is not open over HTTPS, so browsers on other devices can't turn on notifications here. Open SSHhh through your HTTPS tunnel address on your phone (see README).
                    </div>
                    <div id="alertDevicesList" style="font-size:12.5px; margin-bottom:8px;"></div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:6px;">
                        <button type="button" class="btn btn-primary btn-sm" id="alertEnablePushBtn">🔔 Turn on notifications on this device</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="alertTestPushBtn">Send test notification</button>
                    </div>

                    <h4 style="margin:16px 0 6px; font-size:13.5px;">✨ Gemini summary (free tier)</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="alert_gemini_key"><span>Gemini API key</span> <span id="alertGeminiSaved" class="secret-saved-hint" style="display:none;">&#128274; Stored</span></label>
                            <input type="password" class="form-control" id="alert_gemini_key" autocomplete="new-password" placeholder="From aistudio.google.com/apikey">
                        </div>
                        <div class="form-group">
                            <label for="alert_gemini_model">Model</label>
                            <input type="text" class="form-control" id="alert_gemini_model" placeholder="gemini-flash-latest">
                        </div>
                    </div>
                    <span class="form-hint">Only the masked one-line headline of each error is sent to Gemini, never stack traces. On the free tier Google may use and review what you send. Without a key, SSHhh writes the summary itself.</span>
                    <div style="margin-top:6px;"><button type="button" class="btn btn-secondary btn-sm" id="alertTestSummaryBtn">Preview a summary</button> <span id="alertSummaryPreview" style="font-size:12px; color:var(--text-secondary);"></span></div>

                    <div style="display:flex; justify-content:flex-end; align-items:center; margin-top:14px; gap:8px;">
                        <button type="submit" class="btn btn-primary btn-sm" id="alertSaveBtn">Save escalation settings</button>
                    </div>

                    <h4 style="margin:18px 0 6px; font-size:13.5px;">Recent alerts</h4>
                    <div id="alertRecentList" style="font-size:12px;"></div>
                </form>
            </div>

            <!-- Tab 3: Encrypted Vault Backup & Restore -->
            <div class="settings-tab-pane" id="settingsPaneVault" style="display:none;">
                <div class="vault-section-card" style="padding:14px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:10px; margin-bottom:14px;">
                    <h4 style="margin:0 0 6px; font-size:14px;">📤 Export Encrypted Vault Backup</h4>
                    <p style="margin:0 0 10px; font-size:12px; color:var(--text-secondary);">
                        Exports all SSH profiles, projects, encrypted credentials, variables, command pipelines, and notification settings into a portable file encrypted with PBKDF2-SHA256 + AES-256-GCM.
                    </p>
                    <form id="vaultExportForm" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <input type="password" class="form-control" id="vault_export_passphrase" required minlength="8" placeholder="Backup passphrase (min 8 chars)" style="flex:1; min-width:220px;" autocomplete="new-password">
                        <button type="submit" class="btn btn-primary btn-sm" id="exportVaultBtn">Download .json Backup</button>
                    </form>
                </div>

                <div class="vault-section-card" style="padding:14px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:10px;">
                    <h4 style="margin:0 0 6px; font-size:14px;">📥 Restore From Encrypted Backup</h4>
                    <p style="margin:0 0 10px; font-size:12px; color:var(--text-secondary);">
                        Select a previously exported <code>sshhh-vault-*.json</code> file and enter its passphrase to restore profiles, projects, and commands.
                    </p>
                    <form id="vaultImportForm" style="display:flex; flex-direction:column; gap:10px;">
                        <input type="file" class="form-control" id="vault_import_file" accept=".json,application/json" required>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <input type="password" class="form-control" id="vault_import_passphrase" required placeholder="Enter backup passphrase" style="flex:1; min-width:220px;" autocomplete="off">
                            <button type="submit" class="btn btn-secondary btn-sm" id="importVaultBtn">Restore Vault</button>
                        </div>
                        <label class="toggle-label" for="vault_import_overwrite" style="font-size:12px; color:var(--text-secondary);">
                            <input type="checkbox" id="vault_import_overwrite">
                            <span>Overwrite existing profiles/projects with the same name &amp; environment</span>
                        </label>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal-footer" style="justify-content:flex-end;">
            <button type="button" class="btn btn-ghost btn-sm" id="settingsCloseFooterBtn">Close</button>
        </div>
    </div>
</div>
