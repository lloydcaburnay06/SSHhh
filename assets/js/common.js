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
                ssh_passphrase: document.getElementById('proj_ssh_passphrase').value
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
                ssh_passphrase: document.getElementById('proj_ssh_passphrase').value
            };
        }

        function populateCleanBaseline(project) {
            form.reset();
            const presetGroup = document.getElementById('presetGroup');

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
            }

            syncAuthVisibility();
            syncCredentialBadgesAndHints();
            syncTemplateBadge();
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

    window.DeployerApp = {
        escapeHtml,
        ansiToHtml,
        formatDuration,
        formatDateTime,
        toast,
        apiGet,
        apiPost,
        streamExecute,
        statusBadgeHtml,
        renderEventItemHtml
    };

    document.addEventListener('DOMContentLoaded', () => {
        initProjectModal();
        initPwa();
    });
})();

