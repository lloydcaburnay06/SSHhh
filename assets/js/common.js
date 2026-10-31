(function () {
    'use strict';

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function ansiToHtml(rawText) {
        if (!rawText) return '';
        // Collapse standalone carriage-return progress overwrites cleanly
        const normalized = String(rawText).replace(/\r\n/g, '\n');
        let escaped = escapeHtml(normalized);

        const colorMap = {
            '1': 'ansi-bold',
            '2': 'ansi-dim',
            '30': 'ansi-gray',
            '31': 'ansi-red',
            '32': 'ansi-green',
            '33': 'ansi-yellow',
            '34': 'ansi-blue',
            '35': 'ansi-magenta',
            '36': 'ansi-cyan',
            '90': 'ansi-gray',
            '91': 'ansi-red',
            '92': 'ansi-green',
            '93': 'ansi-yellow',
            '94': 'ansi-blue',
            '95': 'ansi-magenta',
            '96': 'ansi-cyan'
        };

        let openSpans = 0;
        escaped = escaped.replace(/\x1b\[([0-9;]*)m/g, function (_, codesStr) {
            if (!codesStr || codesStr === '0') {
                const closers = '</span>'.repeat(openSpans);
                openSpans = 0;
                return closers;
            }
            const codes = codesStr.split(';');
            let out = '';
            for (const c of codes) {
                if (c === '0') {
                    out += '</span>'.repeat(openSpans);
                    openSpans = 0;
                } else if (colorMap[c]) {
                    out += '<span class="' + colorMap[c] + '">';
                    openSpans++;
                }
            }
            return out;
        });

        // Strip any remaining non-color CSI control codes
        escaped = escaped.replace(/\x1b\[[0-9;?]*[A-Za-z]/g, '');

        if (openSpans > 0) {
            escaped += '</span>'.repeat(openSpans);
        }
        return escaped;
    }

    function formatDuration(ms) {
        if (ms === null || ms === undefined) return '—';
        const n = Number(ms);
        if (Number.isNaN(n)) return '—';
        if (n < 1000) return n + 'ms';
        if (n < 60000) return (n / 1000).toFixed(2) + 's';
        const mins = Math.floor(n / 60000);
        const secs = ((n % 60000) / 1000).toFixed(0);
        return mins + 'm ' + secs + 's';
    }

    function formatDateTime(dtStr) {
        if (!dtStr) return 'Never';
        return String(dtStr);
    }

    function toast(message, type = 'info', durationMs = 4200) {
        const stack = document.getElementById('toastStack');
        if (!stack) return;
        const el = document.createElement('div');
        el.className = 'toast ' + type;
        el.textContent = message;
        stack.appendChild(el);
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.2s ease';
            setTimeout(() => el.remove(), 220);
        }, durationMs);
    }

    async function apiGet(action, params = {}) {
        const qs = new URLSearchParams({ action, ...params });
        const res = await fetch('api.php?' + qs.toString(), {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        });
        if (res.status === 401 || res.status === 428) {
            window.location.reload();
            return new Promise(() => {});
        }
        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || ('Request failed with status ' + res.status));
        }
        return data;
    }

    async function apiPost(action, body = {}) {
        const res = await fetch('api.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': window.DEPLOYER_CSRF || ''
            },
            body: JSON.stringify(body)
        });
        if (res.status === 401 || res.status === 428) {
            window.location.reload();
            return new Promise(() => {});
        }
        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || ('Request failed with status ' + res.status));
        }
        return data;
    }

    async function streamExecute(payload, onPacket) {
        const res = await fetch('api.php?action=execute', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.DEPLOYER_CSRF || ''
            },
            body: JSON.stringify({ ...payload, stream: true })
        });

        if (res.status === 401 || res.status === 428) {
            window.location.reload();
            return new Promise(() => {});
        }

        const contentType = res.headers.get('Content-Type') || '';
        if (!res.ok || contentType.includes('application/json')) {
            const errJson = await res.json().catch(() => ({}));
            throw new Error(errJson.error || ('Execution request failed (' + res.status + ')'));
        }

        const reader = res.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';
        let finalPacket = null;

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });
            let newlineIdx;
            while ((newlineIdx = buffer.indexOf('\n')) !== -1) {
                const line = buffer.slice(0, newlineIdx).trim();
                buffer = buffer.slice(newlineIdx + 1);
                if (!line) continue;
                try {
                    const packet = JSON.parse(line);
                    if (packet.type === 'complete') {
                        finalPacket = packet;
                    }
                    if (onPacket) onPacket(packet);
                } catch (_) {
                    // Ignore malformed chunk boundary if any
                }
            }
        }

        if (buffer.trim()) {
            try {
                const packet = JSON.parse(buffer.trim());
                if (packet.type === 'complete') finalPacket = packet;
                if (onPacket) onPacket(packet);
            } catch (_) {}
        }

        return finalPacket;
    }

    function statusBadgeHtml(status) {
        const s = (status || 'never').toLowerCase();
        const labelMap = {
            success: 'Succeeded',
            failed: 'Failed',
            running: 'Running',
            warning: 'Warning',
            info: 'Info',
            never: 'Never Deployed'
        };
        const label = labelMap[s] || s;
        const pulse = s === 'running' ? ' dot-pulse' : '';
        return `<span class="status-pill status-${escapeHtml(s)}"><span class="dot${pulse}"></span>${escapeHtml(label)}</span>`;
    }

    function renderEventItemHtml(ev, showProjectName = false) {
        const steps = Array.isArray(ev.steps) ? ev.steps : [];
        let stepsHtml = '';
        if (steps.length > 0) {
            stepsHtml = '<div class="event-steps-grid">' + steps.map(step => {
                const stClass = step.exit_code === 0 ? 'last-exit-ok' : 'last-exit-err';
                return `
                    <div class="event-step-card">
                        <div style="min-width:0;">
                            <strong>#${escapeHtml(step.step_number)} ${escapeHtml(step.name)}</strong>
                            <div style="font-family:var(--font-mono); color:var(--text-muted); font-size:11.5px; margin-top:2px;">$ ${escapeHtml(step.command)}</div>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                            <span style="color:var(--text-secondary);">${escapeHtml(formatDuration(step.duration_ms))}</span>
                            <span class="last-exit-tag ${stClass}">exit ${escapeHtml(step.exit_code)}</span>
                        </div>
                    </div>
                `;
            }).join('') + '</div>';
        }

        const projectPrefix = (showProjectName && ev.project_name)
            ? `<strong style="color:#a5b4fc;">[${escapeHtml(ev.project_name)}]</strong> `
            : '';

        const exitTag = (ev.exit_code !== null && ev.exit_code !== undefined)
            ? `<span class="last-exit-tag ${ev.exit_code === 0 ? 'last-exit-ok' : 'last-exit-err'}">exit ${escapeHtml(ev.exit_code)}</span>`
            : '';

        const durTag = (ev.duration_ms !== null && ev.duration_ms !== undefined)
            ? `<span>${escapeHtml(formatDuration(ev.duration_ms))}</span>`
            : '';

        const subParts = [];
        if (ev.ssh_target) subParts.push(ev.ssh_target);
        if (ev.event_type) subParts.push(ev.event_type);

        const outputHtml = ev.output_log
            ? `<pre class="event-log-pre">${ansiToHtml(ev.output_log)}</pre>`
            : '';

        return `
            <div class="event-item" data-event-id="${ev.id}">
                <div class="event-summary-row" data-toggle-event="${ev.id}">
                    <div class="event-left">
                        ${statusBadgeHtml(ev.status)}
                        <span class="cat-tag cat-${escapeHtml(ev.category)}">${escapeHtml(ev.category)}</span>
                        <div class="event-title-wrap">
                            <div class="event-title">${projectPrefix}${escapeHtml(ev.title)}</div>
                            <div class="event-sub">${escapeHtml(subParts.join(' • '))}</div>
                        </div>
                    </div>
                    <div class="event-right">
                        ${exitTag}
                        ${durTag}
                        <span>${escapeHtml(ev.created_at)}</span>
                        <a class="btn btn-ghost btn-sm" href="api.php?action=download_event&id=${ev.id}" title="Download .log file" onclick="event.stopPropagation();">.log</a>
                    </div>
                </div>
                <div class="event-details">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; gap:8px; flex-wrap:wrap;">
                        <span style="font-size:12px; color:var(--text-secondary);">
                            Event #${ev.id} • Started: ${escapeHtml(ev.created_at)}${ev.completed_at ? ' • Completed: ' + escapeHtml(ev.completed_at) : ''}
                        </span>
                        <div style="display:flex; gap:6px;">
                            <button type="button" class="btn btn-secondary btn-sm" data-copy-event="${ev.id}">Copy Output</button>
                            <a class="btn btn-ghost btn-sm" href="api.php?action=download_event&id=${ev.id}">Download .log</a>
                        </div>
                    </div>
                    ${stepsHtml}
                    ${outputHtml}
                </div>
            </div>
        `;
    }

    // Shared Project & SSH Modal Controller
    let modalSaveCallback = null;
    const modalDrafts = new Map(); // contextKey ('new' | 'edit:<id>' | 'duplicate:<id>') -> raw field map
    let availableTemplateProjects = [];

    function initProjectModal() {
        const modal = document.getElementById('projectModal');
        if (!modal) return;

        const dialog = document.getElementById('projectModalDialog');
        const form = document.getElementById('projectForm');
        const authSelect = document.getElementById('proj_ssh_auth_type');
        const presetSelect = document.getElementById('proj_preset');
        const existingTemplatesGroup = document.getElementById('existingProjectTemplatesGroup');
        const duplicateTemplateBadge = document.getElementById('duplicateTemplateBadge');
        const duplicateCredWrap = document.getElementById('duplicateCredReuseWrap');
        const reuseCredCheckbox = document.getElementById('proj_reuse_credentials');
        const pwFields = document.getElementById('passwordAuthFields');
        const keyFields = document.getElementById('keyAuthFields');
        const togglePwBtn = document.getElementById('togglePwVisibilityBtn');
        const pwInput = document.getElementById('proj_ssh_password');
        const testBtn = document.getElementById('modalTestSshBtn');
        const testBanner = document.getElementById('modalSshTestBanner');
        const saveBtnEl = document.getElementById('saveProjectBtn');

        const dirtyBadge = document.getElementById('modalDirtyBadge');
        const draftNotice = document.getElementById('modalDraftNotice');
        const resetDraftBtn = document.getElementById('resetModalDraftBtn');
        const unsavedGuard = document.getElementById('modalUnsavedGuard');
        const keepEditingBtn = document.getElementById('modalKeepEditingBtn');
        const keepDraftCloseBtn = document.getElementById('modalKeepDraftCloseBtn');
        const discardCloseBtn = document.getElementById('modalDiscardCloseBtn');

        let currentMode = 'new'; // 'new' | 'edit' | 'duplicate'
        let currentContextKey = 'new';
        let currentSourceProject = null;
        let baselineSnapshot = '';

        // Seed initial available projects for template cloning dropdown
        if (window.INITIAL_DASHBOARD_DATA && Array.isArray(window.INITIAL_DASHBOARD_DATA.projects)) {
            availableTemplateProjects = window.INITIAL_DASHBOARD_DATA.projects.slice();
        } else if (window.INITIAL_PROJECT_PAGE) {
            if (Array.isArray(window.INITIAL_PROJECT_PAGE.all_projects)) {
                availableTemplateProjects = window.INITIAL_PROJECT_PAGE.all_projects.slice();
            } else if (window.INITIAL_PROJECT_PAGE.project) {
                availableTemplateProjects = [window.INITIAL_PROJECT_PAGE.project];
            }
        }

        function syncProjectTemplateOptions(ensureProject = null) {
            if (!existingTemplatesGroup || !presetSelect) return;

            const prevVal = presetSelect.value;
            const byId = new Map();
            for (const p of availableTemplateProjects) {
                if (p && Number(p.id) > 0) {
                    byId.set(Number(p.id), p);
                }
            }
            if (ensureProject && Number(ensureProject.id) > 0) {
                byId.set(Number(ensureProject.id), ensureProject);
            }

            const list = Array.from(byId.values());
            if (list.length === 0) {
                existingTemplatesGroup.hidden = true;
                existingTemplatesGroup.innerHTML = '';
                return;
            }

            existingTemplatesGroup.hidden = false;
            existingTemplatesGroup.innerHTML = list.map((p) => {
                const count = Number(p.command_count ?? 0);
                const plural = count === 1 ? 'command' : 'commands';
                return `<option value="project:${Number(p.id)}">Clone "${escapeHtml(p.name)}" (${count} ${plural})</option>`;
            }).join('');

            if (prevVal && Array.from(presetSelect.options).some((opt) => opt.value === prevVal)) {
                presetSelect.value = prevVal;
            }
        }

        window.DeployerApp.setAvailableProjects = function (projectsList) {
            availableTemplateProjects = Array.isArray(projectsList) ? projectsList.slice() : [];
            syncProjectTemplateOptions(currentSourceProject);
        };

        syncProjectTemplateOptions();

        function nudgeModal() {
            if (!dialog) return;
            dialog.classList.remove('modal-locked-nudge');
            void dialog.offsetWidth; // trigger reflow
            dialog.classList.add('modal-locked-nudge');
            setTimeout(() => dialog.classList.remove('modal-locked-nudge'), 260);
        }

        function syncAuthVisibility() {
            const isKey = authSelect.value === 'key';
            pwFields.style.display = isKey ? 'none' : '';
            keyFields.style.display = isKey ? '' : 'none';
        }

        function syncTemplateBadge() {
            if (!duplicateTemplateBadge || !presetSelect) return;
            const isCloningProject = currentMode !== 'edit' && String(presetSelect.value || '').startsWith('project:');
            duplicateTemplateBadge.style.display = isCloningProject ? '' : 'none';
        }

        function syncCredentialBadgesAndHints() {
            const savedPwBadge = document.getElementById('savedPasswordBadge');
            const savedKeyBadge = document.getElementById('savedKeyBadge');
            const pwHint = document.getElementById('passwordHint');

            if (currentMode === 'edit' && currentSourceProject) {
                if (duplicateCredWrap) duplicateCredWrap.style.display = 'none';
                savedPwBadge.innerHTML = '&#128274; Encrypted password stored';
                savedKeyBadge.innerHTML = '&#128274; Encrypted private key stored';
                savedPwBadge.style.display = currentSourceProject.has_password ? '' : 'none';
                savedKeyBadge.style.display = currentSourceProject.has_private_key ? '' : 'none';
                pwInput.placeholder = currentSourceProject.has_password
                    ? 'Leave blank to keep saved password, or enter new password to update'
                    : 'Enter SSH password';
                pwHint.textContent = currentSourceProject.has_password
                    ? 'Your existing SSH password remains encrypted in the AES-256-GCM vault unless you type a new one.'
                    : 'Encrypted at rest with AES-256-GCM and never sent back to the browser.';
            } else if (currentMode === 'duplicate' && currentSourceProject) {
                const sourceHasCreds = Boolean(currentSourceProject.has_password || currentSourceProject.has_private_key);
                if (duplicateCredWrap) {
                    duplicateCredWrap.style.display = sourceHasCreds ? '' : 'none';
                }
                const reusing = sourceHasCreds && Boolean(reuseCredCheckbox && reuseCredCheckbox.checked);
                savedPwBadge.innerHTML = '&#128274; Reusing source encrypted password';
                savedKeyBadge.innerHTML = '&#128274; Reusing source encrypted private key';
                savedPwBadge.style.display = (reusing && currentSourceProject.has_password) ? '' : 'none';
                savedKeyBadge.style.display = (reusing && currentSourceProject.has_private_key) ? '' : 'none';
                pwInput.placeholder = (reusing && currentSourceProject.has_password)
                    ? 'Leave blank to reuse source password, or enter a new password to override'
                    : 'Enter SSH password';
                pwHint.textContent = (reusing && currentSourceProject.has_password)
                    ? 'Source project password will be re-encrypted with a fresh AES-256-GCM IV unless you type a new one.'
                    : 'Encrypted at rest with AES-256-GCM and never sent back to the browser.';
            } else {
                if (duplicateCredWrap) duplicateCredWrap.style.display = 'none';
                savedPwBadge.style.display = 'none';
                savedKeyBadge.style.display = 'none';
                pwInput.placeholder = 'Enter SSH password';
                pwHint.textContent = 'Encrypted at rest with AES-256-GCM and never sent back to the browser.';
            }
        }

        function collectRawFields() {
            return {
                id: document.getElementById('proj_id').value,
                source_project_id: document.getElementById('proj_source_id').value,
                reuse_source_credentials: Boolean(reuseCredCheckbox && reuseCredCheckbox.checked),
                name: document.getElementById('proj_name').value,
                environment: document.getElementById('proj_environment').value,
                remote_path: document.getElementById('proj_remote_path').value,
                repo_branch: document.getElementById('proj_branch').value,
                preset: presetSelect.value,
                description: document.getElementById('proj_description').value,
                ssh_host: document.getElementById('proj_ssh_host').value,
                ssh_port: document.getElementById('proj_ssh_port').value,
                ssh_username: document.getElementById('proj_ssh_username').value,
                ssh_auth_type: authSelect.value,
                command_timeout: document.getElementById('proj_command_timeout').value,
                ssh_password: pwInput.value,
                ssh_private_key: document.getElementById('proj_ssh_private_key').value,
                ssh_passphrase: document.getElementById('proj_ssh_passphrase').value,
                deploy_strategy: document.getElementById('proj_deploy_strategy') ? document.getElementById('proj_deploy_strategy').value : 'direct',
                releases_to_keep: document.getElementById('proj_releases_to_keep') ? document.getElementById('proj_releases_to_keep').value : 5,
                backup_db_before_deploy: Boolean(document.getElementById('proj_backup_db_before_deploy') && document.getElementById('proj_backup_db_before_deploy').checked),
                backup_db_driver: document.getElementById('proj_backup_db_driver') ? document.getElementById('proj_backup_db_driver').value : 'auto',
                health_check_url: document.getElementById('proj_health_check_url') ? document.getElementById('proj_health_check_url').value : '',
                webhook_enabled: Boolean(document.getElementById('proj_webhook_enabled') && document.getElementById('proj_webhook_enabled').checked),
                webhook_branch: document.getElementById('proj_webhook_branch') ? document.getElementById('proj_webhook_branch').value : 'main'
            };
        }

        function applyRawFields(fields) {
            document.getElementById('proj_id').value = fields.id ?? '';
            document.getElementById('proj_source_id').value = fields.source_project_id ?? '';
            if (reuseCredCheckbox) {
                reuseCredCheckbox.checked = Boolean(fields.reuse_source_credentials);
            }
            document.getElementById('proj_name').value = fields.name ?? '';
            document.getElementById('proj_environment').value = fields.environment || 'production';
            document.getElementById('proj_remote_path').value = fields.remote_path ?? '';
            document.getElementById('proj_branch').value = fields.repo_branch ?? 'main';
            const desiredPreset = fields.preset || 'git_basic';
            if (Array.from(presetSelect.options).some((opt) => opt.value === desiredPreset)) {
                presetSelect.value = desiredPreset;
            } else {
                presetSelect.value = 'git_basic';
            }
            document.getElementById('proj_description').value = fields.description ?? '';
            document.getElementById('proj_ssh_host').value = fields.ssh_host ?? '';
            document.getElementById('proj_ssh_port').value = fields.ssh_port ?? 22;
            document.getElementById('proj_ssh_username').value = fields.ssh_username ?? '';
            authSelect.value = fields.ssh_auth_type || 'password';
            document.getElementById('proj_command_timeout').value = fields.command_timeout ?? 300;
            pwInput.value = fields.ssh_password ?? '';
            document.getElementById('proj_ssh_private_key').value = fields.ssh_private_key ?? '';
            document.getElementById('proj_ssh_passphrase').value = fields.ssh_passphrase ?? '';

            const stratEl = document.getElementById('proj_deploy_strategy');
            if (stratEl) stratEl.value = fields.deploy_strategy || 'direct';
            const releasesWrap = document.getElementById('projReleasesToKeepWrap');
            if (releasesWrap) releasesWrap.style.display = (fields.deploy_strategy === 'symlink') ? '' : 'none';
            const releasesInput = document.getElementById('proj_releases_to_keep');
            if (releasesInput) releasesInput.value = fields.releases_to_keep ?? 5;

            const backupDbCb = document.getElementById('proj_backup_db_before_deploy');
            if (backupDbCb) backupDbCb.checked = Boolean(fields.backup_db_before_deploy);
            const backupDriverWrap = document.getElementById('backupDriverWrap');
            if (backupDriverWrap) backupDriverWrap.style.display = fields.backup_db_before_deploy ? 'block' : 'none';
            const backupDriverSel = document.getElementById('proj_backup_db_driver');
            if (backupDriverSel) backupDriverSel.value = fields.backup_db_driver || 'auto';

            const healthUrlInput = document.getElementById('proj_health_check_url');
            if (healthUrlInput) healthUrlInput.value = fields.health_check_url ?? '';
            const healthBadge = document.getElementById('projHealthBadge');
            if (healthBadge) {
                if (fields.last_health_status === 'healthy') {
                    healthBadge.className = 'badge badge-up';
                    healthBadge.textContent = '🟢 HTTP ' + (fields.last_health_response_code || 200) + ' OK';
                    healthBadge.style.display = 'inline-flex';
                } else if (fields.last_health_status === 'unhealthy') {
                    healthBadge.className = 'badge badge-down';
                    healthBadge.textContent = '🔴 HTTP ' + (fields.last_health_response_code || 'Err');
                    healthBadge.style.display = 'inline-flex';
                } else {
                    healthBadge.style.display = 'none';
                }
            }

            const webhookCb = document.getElementById('proj_webhook_enabled');
            if (webhookCb) webhookCb.checked = Boolean(fields.webhook_enabled);
            const webhookDetailsWrap = document.getElementById('webhookDetailsWrap');
            if (webhookDetailsWrap) webhookDetailsWrap.style.display = fields.webhook_enabled ? 'block' : 'none';
            const webhookBranchInput = document.getElementById('proj_webhook_branch');
            if (webhookBranchInput) webhookBranchInput.value = fields.webhook_branch || 'main';

            const token = fields.webhook_token || (currentSourceProject ? currentSourceProject.webhook_token : '') || '';
            const webhookInput = document.getElementById('projWebhookUrl');
            if (webhookInput) {
                if (token) {
                    const base = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
                    webhookInput.value = base + '/api.php?action=webhook&token=' + token;
                } else {
                    webhookInput.value = '(Generated after saving project)';
                }
            }

            syncAuthVisibility();
            syncCredentialBadgesAndHints();
            syncTemplateBadge();
        }

        function serializeCurrentState() {
            return JSON.stringify(collectRawFields());
        }

        function isModalDirty() {
            return baselineSnapshot !== '' && serializeCurrentState() !== baselineSnapshot;
        }

        function syncDirtyIndicators() {
            const dirty = isModalDirty();
            if (dirtyBadge) {
                dirtyBadge.style.display = dirty ? 'inline-flex' : 'none';
            }
            if (dirty) {
                modalDrafts.set(currentContextKey, collectRawFields());
            } else {
                modalDrafts.delete(currentContextKey);
                if (draftNotice) draftNotice.style.display = 'none';
                if (unsavedGuard) unsavedGuard.style.display = 'none';
            }
        }

        authSelect.addEventListener('change', () => {
            syncAuthVisibility();
            syncDirtyIndicators();
        });

        presetSelect.addEventListener('change', () => {
            syncTemplateBadge();
            syncDirtyIndicators();
        });

        if (reuseCredCheckbox) {
            reuseCredCheckbox.addEventListener('change', () => {
                syncCredentialBadgesAndHints();
                syncDirtyIndicators();
            });
        }

        form.addEventListener('input', () => {
            if (unsavedGuard && unsavedGuard.style.display !== 'none') {
                unsavedGuard.style.display = 'none';
            }
            syncDirtyIndicators();
        });

        form.addEventListener('change', syncDirtyIndicators);

        togglePwBtn.addEventListener('click', () => {
            const isHidden = pwInput.type === 'password';
            pwInput.type = isHidden ? 'text' : 'password';
            togglePwBtn.textContent = isHidden ? 'Hide' : 'Show';
        });

        function hideModalDom() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            pwInput.type = 'password';
            togglePwBtn.textContent = 'Show';
            if (unsavedGuard) unsavedGuard.style.display = 'none';
        }

        function requestCloseModal() {
            if (!isModalDirty()) {
                modalDrafts.delete(currentContextKey);
                hideModalDom();
                return;
            }

            // Prevent immediate close when unsaved; surface inline choice bar
            if (unsavedGuard) {
                unsavedGuard.style.display = 'flex';
            }
            nudgeModal();
            if (keepEditingBtn) {
                keepEditingBtn.focus();
            }
        }

        // Only close via explicit X button or Cancel button
        document.getElementById('closeProjectModalBtn').addEventListener('click', requestCloseModal);
        document.getElementById('cancelProjectModalBtn').addEventListener('click', requestCloseModal);

        // Backdrop clicks never close the modal
        modal.addEventListener('mousedown', (e) => {
            if (e.target === modal) {
                nudgeModal();
            }
        });

        // Escape key never closes the modal unexpectedly
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('open')) {
                e.preventDefault();
                nudgeModal();
            }
        });

        if (keepEditingBtn) {
            keepEditingBtn.addEventListener('click', () => {
                unsavedGuard.style.display = 'none';
            });
        }

        if (keepDraftCloseBtn) {
            keepDraftCloseBtn.addEventListener('click', () => {
                modalDrafts.set(currentContextKey, collectRawFields());
                hideModalDom();
                toast('Closed — your unsaved inputs are preserved for when you reopen.', 'info');
            });
        }

        if (discardCloseBtn) {
            discardCloseBtn.addEventListener('click', () => {
                modalDrafts.delete(currentContextKey);
                populateCleanBaseline(currentSourceProject);
                syncDirtyIndicators();
                hideModalDom();
            });
        }

        if (resetDraftBtn) {
            resetDraftBtn.addEventListener('click', () => {
                modalDrafts.delete(currentContextKey);
                populateCleanBaseline(currentSourceProject);
                baselineSnapshot = serializeCurrentState();
                if (draftNotice) draftNotice.style.display = 'none';
                syncDirtyIndicators();
                toast('Form reset to saved defaults.', 'info');
            });
        }

        window.addEventListener('beforeunload', (e) => {
            if ((modal.classList.contains('open') && isModalDirty()) || modalDrafts.size > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        function collectFormPayload() {
            const idVal = document.getElementById('proj_id').value;
            const sourceIdVal = document.getElementById('proj_source_id').value;
            return {
                id: idVal ? Number(idVal) : undefined,
                project_id: idVal ? Number(idVal) : undefined,
                source_project_id: (!idVal && sourceIdVal) ? Number(sourceIdVal) : undefined,
                reuse_source_credentials: (!idVal && sourceIdVal) ? Boolean(reuseCredCheckbox && reuseCredCheckbox.checked) : false,
                name: document.getElementById('proj_name').value.trim(),
                environment: document.getElementById('proj_environment').value,
                remote_path: document.getElementById('proj_remote_path').value.trim(),
                repo_branch: document.getElementById('proj_branch').value.trim() || 'main',
                preset: presetSelect.value,
                description: document.getElementById('proj_description').value.trim(),
                ssh_host: document.getElementById('proj_ssh_host').value.trim(),
                ssh_port: Number(document.getElementById('proj_ssh_port').value) || 22,
                ssh_username: document.getElementById('proj_ssh_username').value.trim(),
                ssh_auth_type: authSelect.value,
                command_timeout: Number(document.getElementById('proj_command_timeout').value) || 300,
                ssh_password: pwInput.value,
                ssh_private_key: document.getElementById('proj_ssh_private_key').value.trim(),
                ssh_passphrase: document.getElementById('proj_ssh_passphrase').value,
                deploy_strategy: document.getElementById('proj_deploy_strategy') ? document.getElementById('proj_deploy_strategy').value : 'direct',
                releases_to_keep: Number(document.getElementById('proj_releases_to_keep') ? document.getElementById('proj_releases_to_keep').value : 5) || 5,
                backup_db_before_deploy: Boolean(document.getElementById('proj_backup_db_before_deploy') && document.getElementById('proj_backup_db_before_deploy').checked),
                backup_db_driver: document.getElementById('proj_backup_db_driver') ? document.getElementById('proj_backup_db_driver').value : 'auto',
                health_check_url: document.getElementById('proj_health_check_url') ? document.getElementById('proj_health_check_url').value.trim() : null,
                webhook_enabled: Boolean(document.getElementById('proj_webhook_enabled') && document.getElementById('proj_webhook_enabled').checked),
                webhook_branch: document.getElementById('proj_webhook_branch') ? document.getElementById('proj_webhook_branch').value.trim() : 'main'
            };
        }

        function populateCleanBaseline(project) {
            form.reset();
            const presetGroup = document.getElementById('presetGroup');

            function syncExtraOptions(p) {
                const strat = p ? (p.deploy_strategy || 'direct') : 'direct';
                const stratEl = document.getElementById('proj_deploy_strategy');
                if (stratEl) stratEl.value = strat;
                const releasesWrap = document.getElementById('projReleasesToKeepWrap');
                if (releasesWrap) releasesWrap.style.display = (strat === 'symlink') ? '' : 'none';
                const releasesInput = document.getElementById('proj_releases_to_keep');
                if (releasesInput) releasesInput.value = p ? (p.releases_to_keep || 5) : 5;

                const backupDb = p ? Boolean(p.backup_db_before_deploy) : false;
                const backupDbCb = document.getElementById('proj_backup_db_before_deploy');
                if (backupDbCb) backupDbCb.checked = backupDb;
                const backupDriverWrap = document.getElementById('backupDriverWrap');
                if (backupDriverWrap) backupDriverWrap.style.display = backupDb ? 'block' : 'none';
                const backupDriverSel = document.getElementById('proj_backup_db_driver');
                if (backupDriverSel) backupDriverSel.value = p ? (p.backup_db_driver || 'auto') : 'auto';

                const healthUrl = p ? (p.health_check_url || '') : '';
                const healthUrlInput = document.getElementById('proj_health_check_url');
                if (healthUrlInput) healthUrlInput.value = healthUrl;
                const healthBadge = document.getElementById('projHealthBadge');
                if (healthBadge) {
                    if (p && p.last_health_status === 'healthy') {
                        healthBadge.className = 'badge badge-up';
                        healthBadge.textContent = '🟢 HTTP ' + (p.last_health_response_code || 200) + ' OK';
                        healthBadge.style.display = 'inline-flex';
                    } else if (p && p.last_health_status === 'unhealthy') {
                        healthBadge.className = 'badge badge-down';
                        healthBadge.textContent = '🔴 HTTP ' + (p.last_health_response_code || 'Err');
                        healthBadge.style.display = 'inline-flex';
                    } else {
                        healthBadge.style.display = 'none';
                    }
                }

                const whEnabled = p ? Boolean(p.webhook_enabled) : false;
                const webhookCb = document.getElementById('proj_webhook_enabled');
                if (webhookCb) webhookCb.checked = whEnabled;
                const webhookDetailsWrap = document.getElementById('webhookDetailsWrap');
                if (webhookDetailsWrap) webhookDetailsWrap.style.display = whEnabled ? 'block' : 'none';
                const webhookBranchInput = document.getElementById('proj_webhook_branch');
                if (webhookBranchInput) webhookBranchInput.value = p ? (p.webhook_branch || 'main') : 'main';

                const token = p ? (p.webhook_token || '') : '';
                const webhookInput = document.getElementById('projWebhookUrl');
                if (webhookInput) {
                    if (token) {
                        const base = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
                        webhookInput.value = base + '/api.php?action=webhook&token=' + token;
                    } else {
                        webhookInput.value = '(Generated after saving project)';
                    }
                }
            }

            if (currentMode === 'edit' && project && project.id) {
                document.getElementById('proj_id').value = project.id;
                document.getElementById('proj_source_id').value = '';
                if (reuseCredCheckbox) reuseCredCheckbox.checked = false;
                document.getElementById('proj_name').value = project.name || '';
                document.getElementById('proj_environment').value = project.environment || 'production';
                document.getElementById('proj_remote_path').value = project.remote_path || '';
                document.getElementById('proj_branch').value = project.repo_branch || 'main';
                document.getElementById('proj_description').value = project.description || '';
                document.getElementById('proj_ssh_host').value = project.ssh_host || '';
                document.getElementById('proj_ssh_port').value = project.ssh_port || 22;
                document.getElementById('proj_ssh_username').value = project.ssh_username || '';
                authSelect.value = project.ssh_auth_type || 'password';
                document.getElementById('proj_command_timeout').value = project.command_timeout || 300;
                presetGroup.style.display = 'none';
                syncExtraOptions(project);
            } else if (currentMode === 'duplicate' && project && project.id) {
                syncProjectTemplateOptions(project);
                document.getElementById('proj_id').value = '';
                document.getElementById('proj_source_id').value = project.id;
                if (reuseCredCheckbox) {
                    reuseCredCheckbox.checked = Boolean(project.has_password || project.has_private_key);
                }
                document.getElementById('proj_name').value = (project.name || 'Project') + ' (Copy)';
                document.getElementById('proj_environment').value = project.environment || 'production';
                document.getElementById('proj_remote_path').value = project.remote_path || '';
                document.getElementById('proj_branch').value = project.repo_branch || 'main';
                document.getElementById('proj_description').value = project.description || '';
                document.getElementById('proj_ssh_host').value = project.ssh_host || '';
                document.getElementById('proj_ssh_port').value = project.ssh_port || 22;
                document.getElementById('proj_ssh_username').value = project.ssh_username || '';
                authSelect.value = project.ssh_auth_type || 'password';
                document.getElementById('proj_command_timeout').value = project.command_timeout || 300;
                presetGroup.style.display = '';
                const clonePresetValue = 'project:' + Number(project.id);
                if (Array.from(presetSelect.options).some((opt) => opt.value === clonePresetValue)) {
                    presetSelect.value = clonePresetValue;
                } else {
                    presetSelect.value = 'git_basic';
                }
                syncExtraOptions(project);
            } else {
                syncProjectTemplateOptions();
                document.getElementById('proj_id').value = '';
                document.getElementById('proj_source_id').value = '';
                if (reuseCredCheckbox) reuseCredCheckbox.checked = false;
                document.getElementById('proj_ssh_port').value = 22;
                document.getElementById('proj_branch').value = 'main';
                document.getElementById('proj_command_timeout').value = 300;
                authSelect.value = 'password';
                presetGroup.style.display = '';
                presetSelect.value = 'git_basic';
                syncExtraOptions(null);
            }

            syncAuthVisibility();
            syncCredentialBadgesAndHints();
            syncTemplateBadge();
        }

        const stratEl = document.getElementById('proj_deploy_strategy');
        if (stratEl) {
            stratEl.addEventListener('change', () => {
                const releasesWrap = document.getElementById('projReleasesToKeepWrap');
                if (releasesWrap) releasesWrap.style.display = (stratEl.value === 'symlink') ? '' : 'none';
                syncDirtyIndicators();
            });
        }

        const backupDbCb = document.getElementById('proj_backup_db_before_deploy');
        if (backupDbCb) {
            backupDbCb.addEventListener('change', () => {
                const backupDriverWrap = document.getElementById('backupDriverWrap');
                if (backupDriverWrap) backupDriverWrap.style.display = backupDbCb.checked ? 'block' : 'none';
                syncDirtyIndicators();
            });
        }

        const webhookCb = document.getElementById('proj_webhook_enabled');
        if (webhookCb) {
            webhookCb.addEventListener('change', () => {
                const webhookDetailsWrap = document.getElementById('webhookDetailsWrap');
                if (webhookDetailsWrap) webhookDetailsWrap.style.display = webhookCb.checked ? 'block' : 'none';
                syncDirtyIndicators();
            });
        }

        const copyWebhookBtn = document.getElementById('copyWebhookUrlBtn');
        if (copyWebhookBtn) {
            copyWebhookBtn.addEventListener('click', () => {
                const webhookInput = document.getElementById('projWebhookUrl');
                if (webhookInput && webhookInput.value) {
                    navigator.clipboard.writeText(webhookInput.value).then(() => {
                        toast('Webhook URL copied to clipboard.', 'success');
                    });
                }
            });
        }

        const regenWebhookTokenBtn = document.getElementById('regenWebhookTokenBtn');
        if (regenWebhookTokenBtn) {
            regenWebhookTokenBtn.addEventListener('click', async () => {
                const idVal = Number(document.getElementById('proj_id').value);
                if (!idVal) {
                    toast('Save project first to generate webhook URL.', 'info');
                    return;
                }
                try {
                    regenWebhookTokenBtn.disabled = true;
                    const res = await apiPost('regenerate_webhook_token', { project_id: idVal });
                    if (res && res.token) {
                        const base = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
                        const webhookInput = document.getElementById('projWebhookUrl');
                        if (webhookInput) {
                            webhookInput.value = base + '/api.php?action=webhook&token=' + res.token;
                        }
                        if (currentSourceProject) {
                            currentSourceProject.webhook_token = res.token;
                        }
                        toast('New webhook token generated.', 'success');
                    }
                } catch (err) {
                    toast('Failed to regenerate token: ' + err.message, 'error');
                } finally {
                    regenWebhookTokenBtn.disabled = false;
                }
            });
        }

        const testHealthBtn = document.getElementById('projTestHealthBtn');
        if (testHealthBtn) {
            testHealthBtn.addEventListener('click', async () => {
                const healthInput = document.getElementById('proj_health_check_url');
                const url = healthInput ? healthInput.value.trim() : '';
                if (!url) {
                    toast('Enter a valid Health Check URL first.', 'warning');
                    return;
                }
                testHealthBtn.disabled = true;
                const orig = testHealthBtn.textContent;
                testHealthBtn.textContent = 'Pinging...';
                try {
                    const res = await apiPost('check_health', { url });
                    const r = res.result;
                    if (r && r.ok) {
                        toast(`Health Check OK! HTTP ${r.status_code} in ${r.duration_ms} ms`, 'success');
                    } else {
                        toast(`Health Check Alert: ${r ? r.error : 'Connection error'} (${r ? r.duration_ms : 0} ms)`, 'warning');
                    }
                } catch (err) {
                    toast('Health Check Failed: ' + err.message, 'error');
                } finally {
                    testHealthBtn.disabled = false;
                    testHealthBtn.textContent = orig;
                }
            });
        }

        testBtn.addEventListener('click', async () => {
            const payload = collectFormPayload();
            testBtn.disabled = true;
            const origText = testBtn.innerHTML;
            testBtn.textContent = 'Testing SSH...';
            testBanner.className = 'ssh-test-banner show';
            testBanner.textContent = 'Connecting to ' + (payload.ssh_username || '?') + '@' + (payload.ssh_host || '?') + ':' + payload.ssh_port + '...';

            try {
                const res = await apiPost('test_ssh', payload);
                const r = res.result;
                testBanner.className = 'ssh-test-banner show ' + (r.path_ok ? 'ok' : 'err');
                testBanner.textContent = r.message
                    + (r.fingerprint ? '\nFingerprint: ' + r.fingerprint : '')
                    + (r.probe_output ? '\n' + r.probe_output : '');
                toast(r.message, r.path_ok ? 'success' : 'warning');
                if (modalSaveCallback && res.project) {
                    modalSaveCallback(res.project, false);
                }
            } catch (err) {
                testBanner.className = 'ssh-test-banner show err';
                testBanner.textContent = 'SSH Connection Failed: ' + err.message;
                toast(err.message, 'error');
            } finally {
                testBtn.disabled = false;
                testBtn.innerHTML = origText;
            }
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const saveBtn = document.getElementById('saveProjectBtn');
            const payload = collectFormPayload();
            const isEdit = Boolean(payload.id);
            const isDuplicate = !isEdit && currentMode === 'duplicate';

            saveBtn.disabled = true;
            const origLabel = saveBtn.textContent;
            saveBtn.textContent = isDuplicate ? 'Duplicating...' : 'Saving...';

            try {
                const res = await apiPost(isEdit ? 'update_project' : 'create_project', payload);
                const successMsg = isEdit
                    ? 'Project & SSH settings updated securely.'
                    : (isDuplicate
                        ? 'Project duplicated with cloned command template.'
                        : 'Project created with encrypted SSH credentials.');
                toast(successMsg, 'success');
                modalDrafts.delete(currentContextKey);
                form.reset();
                baselineSnapshot = '';
                if (dirtyBadge) dirtyBadge.style.display = 'none';
                if (draftNotice) draftNotice.style.display = 'none';
                hideModalDom();
                if (modalSaveCallback) {
                    modalSaveCallback(res.project, true, res.stats);
                }
            } catch (err) {
                toast(err.message, 'error');
            } finally {
                saveBtn.disabled = false;
                saveBtn.textContent = origLabel;
            }
        });

        window.DeployerApp.openProjectModal = function (project = null, onSaved = null, optionsOrFocusSsh = false) {
            const opts = (optionsOrFocusSsh && typeof optionsOrFocusSsh === 'object')
                ? optionsOrFocusSsh
                : { focusSsh: Boolean(optionsOrFocusSsh), mode: (project && project.id) ? 'edit' : 'new' };

            const focusSsh = Boolean(opts.focusSsh);
            const hasProject = Boolean(project && project.id);

            if (opts.mode === 'duplicate' && hasProject) {
                currentMode = 'duplicate';
                currentSourceProject = { ...project };
                currentContextKey = 'duplicate:' + currentSourceProject.id;
            } else if (hasProject) {
                currentMode = 'edit';
                currentSourceProject = { ...project };
                currentContextKey = 'edit:' + currentSourceProject.id;
            } else {
                currentMode = 'new';
                currentSourceProject = null;
                currentContextKey = 'new';
            }

            modalSaveCallback = onSaved;
            syncProjectTemplateOptions(currentSourceProject);

            testBanner.className = 'ssh-test-banner';
            testBanner.textContent = '';
            pwInput.type = 'password';
            togglePwBtn.textContent = 'Show';
            if (unsavedGuard) unsavedGuard.style.display = 'none';

            const titleEl = document.getElementById('projectModalTitle');
            if (currentMode === 'duplicate' && currentSourceProject) {
                titleEl.textContent = 'Duplicate Project — ' + currentSourceProject.name;
                if (saveBtnEl) saveBtnEl.textContent = 'Create Duplicate';
            } else if (currentMode === 'edit' && currentSourceProject) {
                titleEl.textContent = focusSsh
                    ? ('SSH Settings — ' + currentSourceProject.name)
                    : ('Edit Project & SSH — ' + currentSourceProject.name);
                if (saveBtnEl) saveBtnEl.textContent = 'Save Project';
            } else {
                titleEl.textContent = 'Add New Project';
                if (saveBtnEl) saveBtnEl.textContent = 'Save Project';
            }

            // 1. Establish clean saved/default baseline snapshot first
            populateCleanBaseline(currentSourceProject);
            baselineSnapshot = serializeCurrentState();

            // 2. Restore any preserved unsaved draft for this exact context so inputs are never lost
            const savedDraft = modalDrafts.get(currentContextKey);
            if (savedDraft) {
                applyRawFields(savedDraft);
                if (draftNotice) draftNotice.style.display = 'flex';
            } else {
                if (draftNotice) draftNotice.style.display = 'none';
            }

            syncDirtyIndicators();
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');

            setTimeout(() => {
                if (focusSsh && currentMode === 'edit') {
                    document.getElementById('proj_ssh_host').focus();
                } else {
                    const nameInput = document.getElementById('proj_name');
                    nameInput.focus();
                    if (currentMode === 'duplicate' && !savedDraft) {
                        nameInput.select();
                    }
                }
            }, 40);
        };

        window.DeployerApp.openDuplicateModal = function (sourceProject, onSaved = null) {
            if (!sourceProject || !sourceProject.id) return;
            window.DeployerApp.openProjectModal(sourceProject, onSaved, { mode: 'duplicate' });
        };
    }

    // PWA Service Worker Registration & Install Prompt Controller
    function initPwa() {
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('./sw.js', { scope: './' }).catch(() => {
                    // Service workers require localhost or HTTPS; silently ignore if unsupported
                });
            });
        }

        let deferredInstallPrompt = null;
        const installBtn = document.getElementById('pwaInstallBtn');

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredInstallPrompt = e;
            if (installBtn) {
                installBtn.style.display = 'inline-flex';
            }
        });

        if (installBtn) {
            installBtn.addEventListener('click', async () => {
                if (!deferredInstallPrompt) return;
                deferredInstallPrompt.prompt();
                const choice = await deferredInstallPrompt.userChoice.catch(() => null);
                if (choice && choice.outcome === 'accepted') {
                    installBtn.style.display = 'none';
                }
                deferredInstallPrompt = null;
            });
        }

        window.addEventListener('appinstalled', () => {
            deferredInstallPrompt = null;
            if (installBtn) {
                installBtn.style.display = 'none';
            }
            toast('SSHhh installed! Launch it anytime from your home screen.', 'success');
        });
    }

    // Ensure all internal in-scope link clicks remain inside standalone PWA window on iOS/Android
    function initStandaloneNavigationTrap() {
        const isStandalone = ('standalone' in navigator && navigator.standalone) ||
                             window.matchMedia('(display-mode: standalone)').matches ||
                             window.matchMedia('(display-mode: fullscreen)').matches;
        if (!isStandalone) return;

        document.addEventListener('click', (e) => {
            const a = e.target.closest('a');
            if (!a || !a.href || a.target === '_blank' || a.hasAttribute('download')) return;
            try {
                const destUrl = new URL(a.href, window.location.href);
                if (destUrl.origin === window.location.origin) {
                    const currentDir = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
                    if (destUrl.pathname.startsWith(currentDir)) {
                        e.preventDefault();
                        window.location.href = destUrl.href;
                    }
                }
            } catch {}
        });
    }

    /**
     * Reusable, accessible, stylish confirmation dialog modal that returns a Promise<boolean>.
     *
     * @param {Object} options
     * @param {string} [options.title='Confirm Action']
     * @param {string} [options.subtitle='']
     * @param {string} [options.icon='⚡']
     * @param {string} [options.message='']
     * @param {string} [options.warning='']
     * @param {string} [options.code='']
     * @param {string} [options.confirmText='Confirm']
     * @param {string} [options.confirmClass='btn-primary']
     * @param {string} [options.cancelText='Cancel']
     * @param {boolean} [options.focusCancel=false]
     * @returns {Promise<boolean>}
     */
    function confirmAction(options = {}) {
        return new Promise((resolve) => {
            const modal = document.getElementById('confirmActionModal');
            if (!modal) {
                const rawMsg = options.message ? options.message.replace(/<[^>]+>/g, '') : (options.title || 'Are you sure?');
                resolve(window.confirm(rawMsg));
                return;
            }

            const titleEl = document.getElementById('confirmModalTitle');
            const subEl = document.getElementById('confirmModalSubtitle');
            const iconEl = document.getElementById('confirmModalIcon');
            const msgEl = document.getElementById('confirmModalMessage');
            const warnEl = document.getElementById('confirmModalWarning');
            const codeEl = document.getElementById('confirmModalCode');
            const confirmBtn = document.getElementById('confirmModalConfirmBtn');
            const cancelBtn = document.getElementById('confirmModalCancelBtn');
            const closeBtn = document.getElementById('confirmModalCloseBtn');

            if (titleEl) titleEl.textContent = options.title || 'Confirm Action';
            if (subEl) {
                subEl.textContent = options.subtitle || '';
                subEl.style.display = options.subtitle ? 'block' : 'none';
            }
            if (iconEl) {
                iconEl.className = 'confirm-icon-bubble' + (options.iconVariant ? ' variant-' + options.iconVariant : '');
                iconEl.innerHTML = options.icon || '⚡';
            }

            if (msgEl) {
                if (options.message) {
                    msgEl.innerHTML = options.message;
                    msgEl.style.display = 'block';
                } else {
                    msgEl.style.display = 'none';
                }
            }

            if (warnEl) {
                if (options.warning) {
                    warnEl.innerHTML = options.warning;
                    warnEl.style.display = 'flex';
                } else {
                    warnEl.style.display = 'none';
                }
            }

            if (codeEl) {
                const codeSnippet = options.code || options.codeSnippet;
                if (codeSnippet) {
                    codeEl.textContent = codeSnippet;
                    codeEl.style.display = 'block';
                } else {
                    codeEl.style.display = 'none';
                }
            }

            if (confirmBtn) {
                confirmBtn.textContent = options.confirmText || 'Confirm';
                confirmBtn.className = 'btn ' + (options.confirmClass || 'btn-primary');
            }
            if (cancelBtn) {
                cancelBtn.textContent = options.cancelText || 'Cancel';
            }

            modal.style.display = 'flex';
            void modal.offsetWidth; // Force layout reflow for animation
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');

            if (options.focusCancel && cancelBtn) {
                cancelBtn.focus();
            } else if (confirmBtn) {
                confirmBtn.focus();
            }

            function cleanup(result) {
                modal.classList.remove('show');
                modal.setAttribute('aria-hidden', 'true');
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 180);
                if (confirmBtn) confirmBtn.removeEventListener('click', onConfirm);
                if (cancelBtn) cancelBtn.removeEventListener('click', onCancel);
                if (closeBtn) closeBtn.removeEventListener('click', onCancel);
                modal.removeEventListener('click', onBackdropClick);
                document.removeEventListener('keydown', onKeyDown);
                resolve(result);
            }

            function onConfirm() { cleanup(true); }
            function onCancel() { cleanup(false); }
            function onBackdropClick(e) {
                if (e.target === modal) {
                    cleanup(false);
                }
            }
            function onKeyDown(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    cleanup(false);
                }
            }

            if (confirmBtn) confirmBtn.addEventListener('click', onConfirm);
            if (cancelBtn) cancelBtn.addEventListener('click', onCancel);
            if (closeBtn) closeBtn.addEventListener('click', onCancel);
            modal.addEventListener('click', onBackdropClick);
            document.addEventListener('keydown', onKeyDown);
        });
    }

    // =========================================================================
    // Server Pulse Modal Controller
    // =========================================================================
    function initServerPulseModal() {
        const modal = document.getElementById('serverPulseModal');
        if (!modal) return;

        const title = document.getElementById('pulseModalTitle');
        const subtitle = document.getElementById('pulseModalSubtitle');
        const loadingState = document.getElementById('pulseLoadingState');
        const errorState = document.getElementById('pulseErrorState');
        const errorMessage = document.getElementById('pulseErrorMessage');
        const contentState = document.getElementById('pulseContentState');
        const closeBtn = document.getElementById('pulseModalCloseBtn');
        const footerCloseBtn = document.getElementById('pulseCloseFooterBtn');
        const refreshBtn = document.getElementById('pulseRefreshBtn');

        const osEl = document.getElementById('pulseOs');
        const phpEl = document.getElementById('pulsePhp');
        const uptimeEl = document.getElementById('pulseUptime');
        const cpuValEl = document.getElementById('pulseCpuValue');
        const cpuPillsEl = document.getElementById('pulseCpuLoadPills');
        const ramValEl = document.getElementById('pulseRamValue');
        const ramTextEl = document.getElementById('pulseRamText');
        const ramBarEl = document.getElementById('pulseRamBar');
        const diskValEl = document.getElementById('pulseDiskValue');
        const diskTextEl = document.getElementById('pulseDiskText');
        const diskBarEl = document.getElementById('pulseDiskBar');
        const servicesListEl = document.getElementById('pulseServicesList');

        let currentProject = null;

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            setTimeout(() => { modal.style.display = 'none'; }, 180);
        }

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (footerCloseBtn) footerCloseBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

        async function fetchPulse(project) {
            loadingState.style.display = 'block';
            errorState.style.display = 'none';
            contentState.style.display = 'none';
            if (refreshBtn) refreshBtn.disabled = true;

            try {
                const res = await apiGet('server_pulse', { project_id: project.id });
                if (!res || !res.result) throw new Error(res && res.error ? res.error : 'Failed to query server vitals');
                const d = res.result;

                osEl.textContent = d.os || 'Linux';
                phpEl.textContent = d.php_version || 'PHP';
                uptimeEl.textContent = d.uptime_raw ? d.uptime_raw.split(',')[0].replace(/^.*up\s+/i, 'up ') : '-';

                const loads = Array.isArray(d.cpu_load) ? d.cpu_load : [0, 0, 0];
                cpuValEl.textContent = Number(loads[0]).toFixed(2);
                cpuPillsEl.innerHTML = `
                    <span style="background:rgba(255,255,255,0.06); padding:2px 6px; border-radius:4px;">1m: ${loads[0]}</span>
                    <span style="background:rgba(255,255,255,0.06); padding:2px 6px; border-radius:4px;">5m: ${loads[1]}</span>
                    <span style="background:rgba(255,255,255,0.06); padding:2px 6px; border-radius:4px;">15m: ${loads[2]}</span>
                `;

                const ram = d.ram || {};
                const ramPct = ram.used_percent !== null ? ram.used_percent : 0;
                ramValEl.textContent = ramPct + '%';
                ramTextEl.textContent = `${ram.used_mb || 0} MB / ${ram.total_mb || 0} MB used (${ram.free_mb || 0} MB free)`;
                ramBarEl.style.width = Math.min(100, Math.max(0, ramPct)) + '%';
                ramBarEl.style.background = ramPct > 85 ? 'var(--danger)' : (ramPct > 70 ? 'var(--warning)' : 'var(--brand-accent)');

                const disk = d.disk || {};
                const diskPct = disk.used_percent !== null ? disk.used_percent : 0;
                diskValEl.textContent = diskPct + '%';
                diskTextEl.textContent = `${disk.used || '0'} / ${disk.size || '0'} used (${disk.avail || '0'} free on ${disk.mount || '/'})`;
                diskBarEl.style.width = Math.min(100, Math.max(0, diskPct)) + '%';
                diskBarEl.style.background = diskPct > 85 ? 'var(--danger)' : (diskPct > 70 ? 'var(--warning)' : 'var(--brand-accent)');

                const svcs = Array.isArray(d.services) ? d.services : [];
                if (svcs.length === 0) {
                    servicesListEl.innerHTML = '<span style="font-size:12px; color:var(--text-muted);">No managed systemd services detected or insufficient permissions.</span>';
                } else {
                    servicesListEl.innerHTML = svcs.map((s) => {
                        const isAct = s.status === 'active';
                        const badgeClass = isAct ? 'badge-up' : 'badge-down';
                        const icon = isAct ? '🟢' : '🔴';
                        return `<span class="badge ${badgeClass}" style="font-size:12px; padding:4px 8px;">${icon} ${escapeHtml(s.name)}: ${escapeHtml(s.status)}</span>`;
                    }).join('');
                }

                loadingState.style.display = 'none';
                contentState.style.display = 'block';
            } catch (err) {
                loadingState.style.display = 'none';
                errorMessage.textContent = err.message;
                errorState.style.display = 'block';
            } finally {
                if (refreshBtn) refreshBtn.disabled = false;
            }
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                if (currentProject) fetchPulse(currentProject);
            });
        }

        window.DeployerApp.openServerPulseModal = function (proj) {
            if (!proj || !proj.id) return;
            currentProject = proj;
            title.textContent = `Server Pulse — ${proj.name}`;
            subtitle.textContent = `${proj.ssh_username}@${proj.ssh_host}:${proj.ssh_port} (${(proj.environment || '').toUpperCase()})`;
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            fetchPulse(proj);
        };
    }

    // =========================================================================
    // Remote .env Manager Modal Controller
    // =========================================================================
    function initRemoteEnvModal() {
        const modal = document.getElementById('remoteEnvModal');
        if (!modal) return;

        const title = document.getElementById('envModalTitle');
        const subtitle = document.getElementById('envModalSubtitle');
        const loadingState = document.getElementById('envLoadingState');
        const errorState = document.getElementById('envErrorState');
        const errorMessage = document.getElementById('envErrorMessage');
        const contentState = document.getElementById('envContentState');
        const missingAlert = document.getElementById('envMissingKeysAlert');
        const missingPills = document.getElementById('envMissingPills');
        const pathBadge = document.getElementById('envFilePathBadge');
        const textarea = document.getElementById('envTextarea');
        const saveBtn = document.getElementById('saveEnvBtn');
        const copyBtn = document.getElementById('copyEnvBtn');
        const refreshBtn = document.getElementById('refreshEnvBtn');
        const closeBtn = document.getElementById('envModalCloseBtn');
        const footerCloseBtn = document.getElementById('envCloseFooterBtn');

        let currentProject = null;
        let originalContent = '';

        function closeModal() {
            if (saveBtn && !saveBtn.disabled && textarea && textarea.value !== originalContent) {
                if (!window.confirm('You have unsaved changes in .env. Discard and close?')) return;
            }
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            setTimeout(() => { modal.style.display = 'none'; }, 180);
        }

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (footerCloseBtn) footerCloseBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

        async function fetchEnv(project) {
            loadingState.style.display = 'block';
            errorState.style.display = 'none';
            contentState.style.display = 'none';
            missingAlert.style.display = 'none';
            if (saveBtn) saveBtn.disabled = true;

            try {
                const res = await apiGet('get_env', { project_id: project.id });
                if (!res || !res.result) throw new Error(res && res.error ? res.error : 'Failed to query remote .env');
                const d = res.result;

                originalContent = d.env || '';
                textarea.value = originalContent;
                pathBadge.textContent = d.current_dir ? `(${d.current_dir}/.env)` : '';

                const missing = Array.isArray(d.missing_keys) ? d.missing_keys : [];
                if (missing.length > 0) {
                    missingPills.innerHTML = missing.map((k) => `
                        <button type="button" class="btn btn-xs" data-insert-key="${escapeHtml(k)}" style="background:#b45309; color:#fef3c7; border:none; border-radius:4px; font-family:var(--font-mono); font-size:11px; padding:2px 6px;">
                            + ${escapeHtml(k)}
                        </button>
                    `).join('');
                    missingAlert.style.display = 'block';
                } else {
                    missingAlert.style.display = 'none';
                }

                loadingState.style.display = 'none';
                contentState.style.display = 'block';
                saveBtn.disabled = true;
            } catch (err) {
                loadingState.style.display = 'none';
                errorMessage.textContent = err.message;
                errorState.style.display = 'block';
            }
        }

        if (textarea) {
            textarea.addEventListener('input', () => {
                if (saveBtn) {
                    saveBtn.disabled = (textarea.value === originalContent);
                }
            });
        }

        if (missingPills) {
            missingPills.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-insert-key]');
                if (!btn || !textarea) return;
                const key = btn.dataset.insertKey;
                if (textarea.value.endsWith('\n') || textarea.value === '') {
                    textarea.value += `${key}=\n`;
                } else {
                    textarea.value += `\n${key}=\n`;
                }
                btn.style.display = 'none';
                if (saveBtn) saveBtn.disabled = false;
                toast(`Appended ${key}= to .env editor.`, 'info');
            });
        }

        if (copyBtn) {
            copyBtn.addEventListener('click', () => {
                if (textarea && textarea.value) {
                    navigator.clipboard.writeText(textarea.value).then(() => toast('.env copied to clipboard.', 'success'));
                }
            });
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                if (currentProject) fetchEnv(currentProject);
            });
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', async () => {
                if (!currentProject) return;
                const isProd = currentProject.environment === 'production';
                const confirmed = await confirmAction({
                    title: 'Save Remote .env?',
                    subtitle: `${currentProject.name} [${currentProject.environment.toUpperCase()}]`,
                    message: 'A timestamped backup of the current <code>.env</code> will be created on the remote server before overwriting.',
                    warning: isProd ? 'Caution: Updating secrets on PRODUCTION may immediately affect live database connections and services.' : null,
                    confirmText: 'Create Backup & Save',
                    confirmClass: isProd ? 'btn-danger' : 'btn-primary',
                    icon: '💾',
                    iconVariant: isProd ? 'danger' : 'deploy',
                });
                if (!confirmed) return;

                saveBtn.disabled = true;
                const origText = saveBtn.innerHTML;
                saveBtn.innerHTML = '<span>⏳</span><span>Saving...</span>';

                try {
                    const res = await apiPost('save_env', {
                        project_id: currentProject.id,
                        content: textarea.value
                    });
                    originalContent = textarea.value;
                    toast(res.result.message || 'Remote .env saved successfully.', 'success');
                    closeModal();
                } catch (err) {
                    toast('Failed to save .env: ' + err.message, 'error');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origText;
                }
            });
        }

        window.DeployerApp.openRemoteEnvModal = function (proj) {
            if (!proj || !proj.id) return;
            currentProject = proj;
            title.textContent = `.env Secrets — ${proj.name}`;
            subtitle.textContent = `${proj.ssh_username}@${proj.ssh_host} (${(proj.environment || '').toUpperCase()})`;
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            fetchEnv(proj);
        };
    }

    // =========================================================================
    // Releases & Rollback Modal Controller
    // =========================================================================
    function initReleasesModal() {
        const modal = document.getElementById('releasesModal');
        if (!modal) return;

        const title = document.getElementById('releasesModalTitle');
        const subtitle = document.getElementById('releasesModalSubtitle');
        const loadingState = document.getElementById('releasesLoadingState');
        const errorState = document.getElementById('releasesErrorState');
        const errorMessage = document.getElementById('releasesErrorMessage');
        const contentState = document.getElementById('releasesContentState');
        const directNotice = document.getElementById('releasesDirectNotice');
        const releasesList = document.getElementById('releasesList');
        const refreshBtn = document.getElementById('refreshReleasesBtn');
        const closeBtn = document.getElementById('releasesModalCloseBtn');
        const footerCloseBtn = document.getElementById('releasesCloseFooterBtn');

        let currentProject = null;
        let onRollbackComplete = null;

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            setTimeout(() => { modal.style.display = 'none'; }, 180);
        }

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (footerCloseBtn) footerCloseBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

        async function fetchReleases(project) {
            loadingState.style.display = 'block';
            errorState.style.display = 'none';
            contentState.style.display = 'none';

            try {
                const res = await apiGet('list_releases', { project_id: project.id });
                if (!res || !res.result) throw new Error(res && res.error ? res.error : 'Failed to query releases');
                const d = res.result;

                directNotice.style.display = (d.deploy_strategy !== 'symlink') ? 'block' : 'none';

                const releases = Array.isArray(d.releases) ? d.releases : [];
                if (releases.length === 0) {
                    releasesList.innerHTML = '<div style="padding:24px; text-align:center; color:var(--text-muted);">No release folders found in <code>releases/</code> yet. Run an atomic zero-downtime deployment to create your first release snapshot.</div>';
                } else {
                    releasesList.innerHTML = releases.map((r, idx) => {
                        const isActive = r.is_active;
                        const badge = isActive
                            ? `<span class="badge badge-up">🟢 CURRENT ACTIVE</span>`
                            : `<span class="badge" style="opacity:0.75;">Historical Release #${releases.length - idx}</span>`;
                        const rollbackBtn = isActive
                            ? `<span style="font-size:12px; color:var(--text-muted);">Live</span>`
                            : `<button type="button" class="btn btn-warning btn-xs" data-rollback-release="${escapeHtml(r.id)}">Rollback to This ⏪</button>`;

                        return `
                            <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 14px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:8px;">
                                <div>
                                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                                        <strong style="font-family:var(--font-mono); font-size:13px; color:var(--text-primary);">${escapeHtml(r.id)}</strong>
                                        ${badge}
                                    </div>
                                    <span style="font-size:12px; color:var(--text-muted);">${escapeHtml(r.date)}</span>
                                </div>
                                <div>${rollbackBtn}</div>
                            </div>
                        `;
                    }).join('');
                }

                loadingState.style.display = 'none';
                contentState.style.display = 'block';
            } catch (err) {
                loadingState.style.display = 'none';
                errorMessage.textContent = err.message;
                errorState.style.display = 'block';
            }
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                if (currentProject) fetchReleases(currentProject);
            });
        }

        if (releasesList) {
            releasesList.addEventListener('click', async (e) => {
                const btn = e.target.closest('[data-rollback-release]');
                if (!btn || !currentProject) return;
                const targetRel = btn.dataset.rollbackRelease;

                const confirmed = await confirmAction({
                    title: `Rollback to Release ${targetRel}?`,
                    subtitle: `${currentProject.name} [${currentProject.environment.toUpperCase()}]`,
                    message: `The active <code>current</code> symlink will be immediately repointed to <code>releases/${escapeHtml(targetRel)}</code> with zero downtime, and queue workers will be signaled to restart.`,
                    confirmText: 'Rollback Now',
                    confirmClass: 'btn-warning',
                    icon: '⏪',
                    iconVariant: 'danger',
                });
                if (!confirmed) return;

                btn.disabled = true;
                btn.textContent = 'Rolling back...';
                try {
                    const res = await apiPost('rollback_release', {
                        project_id: currentProject.id,
                        target_release: targetRel
                    });
                    toast(res.result.message || `Rolled back to ${targetRel}`, 'success');
                    await fetchReleases(currentProject);
                    if (onRollbackComplete) {
                        onRollbackComplete();
                    }
                } catch (err) {
                    toast('Rollback failed: ' + err.message, 'error');
                    btn.disabled = false;
                    btn.textContent = 'Rollback to This ⏪';
                }
            });
        }

        window.DeployerApp.openReleasesModal = function (proj, onDone = null) {
            if (!proj || !proj.id) return;
            currentProject = proj;
            onRollbackComplete = onDone;
            title.textContent = `Releases & Rollback — ${proj.name}`;
            subtitle.textContent = `${proj.ssh_username}@${proj.ssh_host} (${(proj.environment || '').toUpperCase()})`;
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            fetchReleases(proj);
        };
    }

    window.DeployerApp = {
        escapeHtml,
        ansiToHtml,
        formatDuration,
        formatDateTime,
        toast,
        confirmAction,
        apiGet,
        apiPost,
        streamExecute,
        statusBadgeHtml,
        renderEventItemHtml
    };

    document.addEventListener('DOMContentLoaded', () => {
        initProjectModal();
        initServerPulseModal();
        initRemoteEnvModal();
        initReleasesModal();
        initPwa();
        initStandaloneNavigationTrap();
    });
})();

