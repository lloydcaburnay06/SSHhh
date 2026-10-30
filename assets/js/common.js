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

    function initProjectModal() {
        const modal = document.getElementById('projectModal');
        if (!modal) return;

        const form = document.getElementById('projectForm');
        const authSelect = document.getElementById('proj_ssh_auth_type');
        const pwFields = document.getElementById('passwordAuthFields');
        const keyFields = document.getElementById('keyAuthFields');
        const togglePwBtn = document.getElementById('togglePwVisibilityBtn');
        const pwInput = document.getElementById('proj_ssh_password');
        const testBtn = document.getElementById('modalTestSshBtn');
        const testBanner = document.getElementById('modalSshTestBanner');

        function syncAuthVisibility() {
            const isKey = authSelect.value === 'key';
            pwFields.style.display = isKey ? 'none' : '';
            keyFields.style.display = isKey ? '' : 'none';
        }

        authSelect.addEventListener('change', syncAuthVisibility);

        togglePwBtn.addEventListener('click', () => {
            const isHidden = pwInput.type === 'password';
            pwInput.type = isHidden ? 'text' : 'password';
            togglePwBtn.textContent = isHidden ? 'Hide' : 'Show';
        });

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            pwInput.type = 'password';
            togglePwBtn.textContent = 'Show';
        }

        document.getElementById('closeProjectModalBtn').addEventListener('click', closeModal);
        document.getElementById('cancelProjectModalBtn').addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        function collectFormPayload() {
            const idVal = document.getElementById('proj_id').value;
            return {
                id: idVal ? Number(idVal) : undefined,
                project_id: idVal ? Number(idVal) : undefined,
                name: document.getElementById('proj_name').value.trim(),
                environment: document.getElementById('proj_environment').value,
                remote_path: document.getElementById('proj_remote_path').value.trim(),
                repo_branch: document.getElementById('proj_branch').value.trim() || 'main',
                preset: document.getElementById('proj_preset').value,
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

            saveBtn.disabled = true;
            const origLabel = saveBtn.textContent;
            saveBtn.textContent = 'Saving...';

            try {
                const res = await apiPost(isEdit ? 'update_project' : 'create_project', payload);
                toast(isEdit ? 'Project & SSH settings updated securely.' : 'Project created with encrypted SSH credentials.', 'success');
                closeModal();
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

        window.DeployerApp.openProjectModal = function (project = null, onSaved = null, focusSsh = false) {
            modalSaveCallback = onSaved;
            form.reset();
            testBanner.className = 'ssh-test-banner';
            testBanner.textContent = '';
            pwInput.type = 'password';
            togglePwBtn.textContent = 'Show';

            const titleEl = document.getElementById('projectModalTitle');
            const presetGroup = document.getElementById('presetGroup');
            const savedPwBadge = document.getElementById('savedPasswordBadge');
            const savedKeyBadge = document.getElementById('savedKeyBadge');
            const pwHint = document.getElementById('passwordHint');

            if (project && project.id) {
                titleEl.textContent = focusSsh ? ('SSH Settings — ' + project.name) : ('Edit Project & SSH — ' + project.name);
                document.getElementById('proj_id').value = project.id;
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

                savedPwBadge.style.display = project.has_password ? '' : 'none';
                savedKeyBadge.style.display = project.has_private_key ? '' : 'none';
                pwInput.placeholder = project.has_password
                    ? 'Leave blank to keep saved password, or enter new password to update'
                    : 'Enter SSH password';
                pwHint.textContent = project.has_password
                    ? 'Your existing SSH password remains encrypted in the AES-256-GCM vault unless you type a new one.'
                    : 'Encrypted at rest with AES-256-GCM and never sent back to the browser.';
            } else {
                titleEl.textContent = 'Add New Project';
                document.getElementById('proj_id').value = '';
                document.getElementById('proj_ssh_port').value = 22;
                document.getElementById('proj_branch').value = 'main';
                document.getElementById('proj_command_timeout').value = 300;
                authSelect.value = 'password';
                presetGroup.style.display = '';
                savedPwBadge.style.display = 'none';
                savedKeyBadge.style.display = 'none';
                pwInput.placeholder = 'Enter SSH password';
                pwHint.textContent = 'Encrypted at rest with AES-256-GCM and never sent back to the browser.';
            }

            syncAuthVisibility();
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');

            setTimeout(() => {
                if (focusSsh) {
                    document.getElementById('proj_ssh_host').focus();
                } else {
                    document.getElementById('proj_name').focus();
                }
            }, 40);
        };
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

    document.addEventListener('DOMContentLoaded', initProjectModal);
})();

