(function () {
    'use strict';

    const App = window.DeployerApp;
    const initData = window.INITIAL_PROJECT_PAGE || {};

    let project = initData.project;
    let commands = Array.isArray(initData.commands) ? initData.commands.map(cloneCmd) : [];
    let eventsState = initData.events || { items: [], total: 0, counts: {} };

    let isDirty = false;
    let isExecuting = false;
    let rawConsoleText = '';
    let timerInterval = null;
    let activeStepStates = {}; // index -> 'running' | 'success' | 'failed'

    let eventCategory = 'all';
    let eventStatus = 'all';
    let eventSearch = '';
    let searchDebounce = null;

    function cloneCmd(c) {
        return {
            id: c.id || null,
            name: c.name || '',
            command: c.command || '',
            is_enabled: c.is_enabled !== undefined ? Boolean(c.is_enabled) : true,
            stop_on_error: c.stop_on_error !== undefined ? Boolean(c.stop_on_error) : true,
            last_exit_code: c.last_exit_code ?? null,
            last_duration_ms: c.last_duration_ms ?? null,
            last_run_at: c.last_run_at ?? null
        };
    }

    function setDirty(dirty) {
        isDirty = dirty;
        const badge = document.getElementById('commandsDirtyBadge');
        if (!badge) return;
        if (dirty) {
            badge.className = 'dirty-badge unsaved';
            badge.textContent = 'Unsaved edits (auto-saved on Run / Deploy)';
        } else {
            badge.className = 'dirty-badge saved';
            badge.textContent = 'All changes saved';
        }
    }

    function renderHero() {
        if (!project) return;
        document.title = project.name + ' — SSH Deployer';
        document.getElementById('breadcrumbProjectName').textContent = project.name;
        document.getElementById('heroProjectName').textContent = project.name;

        const envBadge = document.getElementById('heroEnvBadge');
        envBadge.className = 'badge env-' + project.environment;
        envBadge.textContent = project.environment;

        document.getElementById('heroStatusBadge').innerHTML = App.statusBadgeHtml(project.last_deploy_status);

        const vaultChip = project.ssh_auth_type === 'key'
            ? (project.has_private_key ? '&#128273; SSH Key Encrypted (AES-256-GCM)' : '&#9888; No SSH Key Stored')
            : (project.has_password ? '&#128274; Password Encrypted (AES-256-GCM)' : '&#9888; No Password Stored');

        const chips = [
            `<span class="mono-chip" title="SSH Target">${App.escapeHtml(project.ssh_username)}@${App.escapeHtml(project.ssh_host)}:${project.ssh_port}</span>`,
            `<span class="mono-chip" title="Remote Working Directory">dir: ${App.escapeHtml(project.remote_path || '~ ($HOME)')}</span>`,
            `<span class="mono-chip" title="Git Branch">branch: ${App.escapeHtml(project.repo_branch || 'main')}</span>`,
            `<span class="mono-chip" style="color:#34d399;">${vaultChip}</span>`
        ];

        if (project.host_fingerprint) {
            chips.push(`<span class="mono-chip" title="Verified Server Host Key Fingerprint" style="max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${App.escapeHtml(project.host_fingerprint)}</span>`);
        }
        if (project.last_deployed_at) {
            chips.push(`<span>Last deploy: ${App.escapeHtml(project.last_deployed_at)}${project.last_deploy_duration_ms ? ' (' + App.escapeHtml(App.formatDuration(project.last_deploy_duration_ms)) + ')' : ''}</span>`);
        }

        document.getElementById('heroChips').innerHTML = chips.join('');
    }

    function renderCommands() {
        const listEl = document.getElementById('commandsList');
        if (commands.length === 0) {
            listEl.innerHTML = `
                <div style="text-align:center; padding:26px 16px; border:1px dashed var(--border-strong); border-radius:10px; color:var(--text-secondary);">
                    <strong style="color:var(--text-primary); display:block; margin-bottom:6px;">No deployment commands configured yet</strong>
                    Click <strong>+ Add Command</strong> or select one of the quick preset chips below to build your deployment pipeline.
                </div>
            `;
            return;
        }

        listEl.innerHTML = commands.map((cmd, idx) => {
            const stepState = activeStepStates[idx] || '';
            const stateClass = stepState ? (stepState + '-step') : '';
            const disabledClass = !cmd.is_enabled ? 'disabled-step' : '';
            const linesCount = Math.max(1, Math.min(8, String(cmd.command || '').split('\n').length));

            let lastRunHtml = '';
            if (cmd.last_exit_code !== null && cmd.last_exit_code !== undefined) {
                const ok = Number(cmd.last_exit_code) === 0;
                const dur = cmd.last_duration_ms !== null ? ' • ' + App.formatDuration(cmd.last_duration_ms) : '';
                lastRunHtml = `<span class="last-exit-tag ${ok ? 'last-exit-ok' : 'last-exit-err'}" title="Last run: ${App.escapeHtml(cmd.last_run_at || '')}">exit ${cmd.last_exit_code}${App.escapeHtml(dur)}</span>`;
            }

            return `
                <div class="command-step ${disabledClass} ${stateClass}" data-step-index="${idx}">
                    <div class="step-top-row">
                        <span class="step-num">#${idx + 1}</span>
                        <input
                            type="text"
                            class="step-name-input"
                            data-field="name"
                            data-index="${idx}"
                            value="${App.escapeHtml(cmd.name)}"
                            placeholder="Step label (e.g. Pull latest code)"
                        >
                        <div class="step-order-btns">
                            <button type="button" class="icon-btn" data-cmd-action="up" data-index="${idx}" title="Move Up" ${idx === 0 || isExecuting ? 'disabled' : ''}>&uarr;</button>
                            <button type="button" class="icon-btn" data-cmd-action="down" data-index="${idx}" title="Move Down" ${idx === commands.length - 1 || isExecuting ? 'disabled' : ''}>&darr;</button>
                            <button type="button" class="icon-btn danger" data-cmd-action="delete" data-index="${idx}" title="Remove Step" ${isExecuting ? 'disabled' : ''}>&times;</button>
                        </div>
                    </div>
                    <textarea
                        class="step-cmd-editor"
                        data-field="command"
                        data-index="${idx}"
                        rows="${linesCount}"
                        placeholder="Enter SSH command (e.g. git pull origin main)"
                        spellcheck="false"
                    >${App.escapeHtml(cmd.command)}</textarea>
                    <div class="step-bottom-row">
                        <div class="step-toggles">
                            <label class="toggle-label" title="Include this command when clicking Deploy Now (Execute All)">
                                <input type="checkbox" data-field="is_enabled" data-index="${idx}" ${cmd.is_enabled ? 'checked' : ''}>
                                <span>Include in Deploy All</span>
                            </label>
                            <label class="toggle-label" title="Stop deployment pipeline if this command exits with a non-zero error code">
                                <input type="checkbox" data-field="stop_on_error" data-index="${idx}" ${cmd.stop_on_error ? 'checked' : ''}>
                                <span>Stop on error</span>
                            </label>
                        </div>
                        <div class="step-actions">
                            ${lastRunHtml}
                            <button type="button" class="btn btn-secondary btn-sm" data-cmd-action="run" data-index="${idx}" ${isExecuting ? 'disabled' : ''}>
                                &#9654; Run
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    function setConsoleText(rawText) {
        rawConsoleText = rawText;
        const outEl = document.getElementById('terminalOutput');
        outEl.innerHTML = App.ansiToHtml(rawConsoleText);
        outEl.scrollTop = outEl.scrollHeight;
    }

    function appendConsoleText(chunk) {
        rawConsoleText += chunk;
        const outEl = document.getElementById('terminalOutput');
        outEl.innerHTML = App.ansiToHtml(rawConsoleText);
        outEl.scrollTop = outEl.scrollHeight;
    }

    function startTimer() {
        stopTimer();
        const started = performance.now();
        const timerEl = document.getElementById('consoleTimer');
        timerEl.textContent = '0.0s';
        timerInterval = setInterval(() => {
            const elapsedSec = ((performance.now() - started) / 1000).toFixed(1);
            timerEl.textContent = elapsedSec + 's';
        }, 100);
    }

    function stopTimer(finalMs = null) {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
        if (finalMs !== null) {
            document.getElementById('consoleTimer').textContent = App.formatDuration(finalMs);
        }
    }

    function setExecutionLock(locked, statusHtml = '') {
        isExecuting = locked;
        document.getElementById('deployAllBtn').disabled = locked;
        document.getElementById('saveCommandsBtn').disabled = locked;
        document.getElementById('addCommandBtn').disabled = locked;
        document.getElementById('testSshHeroBtn').disabled = locked;
        document.getElementById('runAdhocBtn').disabled = locked;
        document.getElementById('consoleStateBadge').innerHTML = statusHtml;
        renderCommands();
    }

    async function saveCommandsExplicit() {
        for (let i = 0; i < commands.length; i++) {
            if (!commands[i].command || !commands[i].command.trim()) {
                App.toast(`Step #${i + 1} cannot have an empty command.`, 'warning');
                return false;
            }
        }

        const btn = document.getElementById('saveCommandsBtn');
        btn.disabled = true;
        const orig = btn.textContent;
        btn.textContent = 'Saving...';

        try {
            const res = await App.apiPost('save_commands', {
                project_id: project.id,
                commands
            });
            commands = (res.commands || []).map(cloneCmd);
            if (res.project) project = res.project;
            setDirty(false);
            renderHero();
            renderCommands();
            App.toast('Deployment commands saved.', 'success');
            await loadEvents();
            return true;
        } catch (err) {
            App.toast(err.message, 'error');
            return false;
        } finally {
            btn.disabled = isExecuting;
            btn.textContent = orig;
        }
    }

    async function runExecution(mode, stepIndex = null, adhocCommand = null) {
        if (isExecuting) return;

        // Validate non-empty commands if syncing pipeline
        if (mode !== 'adhoc_command') {
            for (let i = 0; i < commands.length; i++) {
                if (!commands[i].command || !commands[i].command.trim()) {
                    App.toast(`Step #${i + 1} has an empty shell command. Fill it in or remove the step first.`, 'warning');
                    return;
                }
            }
        }

        activeStepStates = {};
        if (mode === 'single_command' && stepIndex !== null) {
            activeStepStates[stepIndex] = 'running';
        }

        // Map enabled step numbers back to commands indices for live highlighting in deploy_all mode
        const enabledIndices = [];
        if (mode === 'deploy_all') {
            commands.forEach((c, idx) => {
                if (c.is_enabled) enabledIndices.push(idx);
            });
            if (enabledIndices.length === 0) {
                App.toast('Enable or add at least one command before deploying.', 'warning');
                return;
            }
        }

        setConsoleText('');
        setExecutionLock(true, App.statusBadgeHtml('running'));
        startTimer();

        const needsSync = (mode !== 'adhoc_command') && (isDirty || commands.some((c) => !c.id));
        const payload = {
            project_id: project.id,
            mode,
            command_id: (stepIndex !== null && commands[stepIndex]) ? commands[stepIndex].id : null,
            step_index: stepIndex,
            adhoc_command: adhocCommand,
            commands: needsSync ? commands : undefined
        };

        try {
            const finalPacket = await App.streamExecute(payload, (pkt) => {
                switch (pkt.type) {
                    case 'init':
                    case 'connected':
                    case 'aborted':
                        appendConsoleText(pkt.message + '\n');
                        break;

                    case 'step_start': {
                        appendConsoleText(pkt.message + '\n');
                        const targetIdx = mode === 'deploy_all'
                            ? enabledIndices[(pkt.step_number || 1) - 1]
                            : stepIndex;
                        if (targetIdx !== undefined && targetIdx !== null) {
                            activeStepStates[targetIdx] = 'running';
                            renderCommands();
                        }
                        break;
                    }

                    case 'chunk':
                        appendConsoleText(pkt.data || '');
                        break;

                    case 'step_end': {
                        const prefix = rawConsoleText.endsWith('\n') ? '' : '\n';
                        appendConsoleText(prefix + pkt.message + '\n');
                        const targetIdx = mode === 'deploy_all'
                            ? enabledIndices[(pkt.step_number || 1) - 1]
                            : stepIndex;
                        if (targetIdx !== undefined && targetIdx !== null) {
                            activeStepStates[targetIdx] = pkt.exit_code === 0 ? 'success' : 'failed';
                            renderCommands();
                        }
                        break;
                    }

                    case 'error':
                        appendConsoleText('\n[SSH ERROR] ' + pkt.message + '\n');
                        break;

                    case 'complete':
                        appendConsoleText('\n[' + pkt.status.toUpperCase() + '] ' + pkt.title + '\n');
                        break;
                }
            });

            if (finalPacket) {
                if (finalPacket.project) project = finalPacket.project;
                if (Array.isArray(finalPacket.commands)) {
                    commands = finalPacket.commands.map(cloneCmd);
                }
                if (needsSync) setDirty(false);
                stopTimer(finalPacket.duration_ms);
                renderHero();
                setExecutionLock(false, App.statusBadgeHtml(finalPacket.status));

                if (finalPacket.status === 'success') {
                    App.toast(finalPacket.title, 'success');
                } else {
                    App.toast(finalPacket.title, 'error');
                }
            } else {
                stopTimer();
                setExecutionLock(false, App.statusBadgeHtml('info'));
            }
        } catch (err) {
            stopTimer();
            appendConsoleText('\n[ERROR] ' + err.message + '\n');
            setExecutionLock(false, App.statusBadgeHtml('failed'));
            App.toast(err.message, 'error');
        } finally {
            await loadEvents();
        }
    }

    function renderEvents() {
        const counts = eventsState.counts || {};
        ['all', 'deployment', 'command', 'ssh', 'audit'].forEach((cat) => {
            const el = document.getElementById('cnt-' + cat);
            if (el) el.textContent = counts[cat] ?? 0;
        });

        const listEl = document.getElementById('projectEventsList');
        const items = eventsState.items || [];
        if (items.length === 0) {
            listEl.innerHTML = `
                <div style="padding:32px 20px; text-align:center; color:var(--text-muted);">
                    No matching event logs found for this filter.
                </div>
            `;
            return;
        }

        listEl.innerHTML = items.map((ev) => App.renderEventItemHtml(ev, false)).join('');
    }

    async function loadEvents() {
        try {
            const res = await App.apiGet('list_events', {
                project_id: project.id,
                category: eventCategory,
                status: eventStatus,
                search: eventSearch,
                limit: 80
            });
            eventsState = res;
            renderEvents();
        } catch (err) {
            App.toast('Failed to refresh event logs: ' + err.message, 'error');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        renderHero();
        renderCommands();
        renderEvents();

        // Top Hero Buttons
        document.getElementById('deployAllBtn').addEventListener('click', () => {
            runExecution('deploy_all');
        });

        document.getElementById('editSshHeroBtn').addEventListener('click', () => {
            App.openProjectModal(project, async (updatedProj) => {
                if (updatedProj) {
                    project = updatedProj;
                    renderHero();
                }
                await loadEvents();
            }, true);
        });

        document.getElementById('testSshHeroBtn').addEventListener('click', async () => {
            if (isExecuting) return;
            const btn = document.getElementById('testSshHeroBtn');
            btn.disabled = true;
            const orig = btn.innerHTML;
            btn.textContent = 'Testing SSH...';
            setConsoleText(`Testing SSH connection to ${project.ssh_username}@${project.ssh_host}:${project.ssh_port}...\n`);
            startTimer();

            try {
                const res = await App.apiPost('test_ssh', { project_id: project.id });
                const r = res.result;
                if (res.project) {
                    project = res.project;
                    renderHero();
                }
                stopTimer(r.duration_ms);
                document.getElementById('consoleStateBadge').innerHTML = App.statusBadgeHtml(r.path_ok ? 'success' : 'warning');
                appendConsoleText(
                    r.message + '\n'
                    + (r.banner ? 'Server Banner: ' + r.banner + '\n' : '')
                    + (r.fingerprint ? 'Host Key: ' + r.fingerprint + '\n' : '')
                    + (r.probe_output ? '\n--- Remote Environment ---\n' + r.probe_output + '\n' : '')
                );
                App.toast(r.message, r.path_ok ? 'success' : 'warning');
            } catch (err) {
                stopTimer();
                document.getElementById('consoleStateBadge').innerHTML = App.statusBadgeHtml('failed');
                appendConsoleText('\n[SSH TEST FAILED] ' + err.message + '\n');
                App.toast('SSH Test Failed: ' + err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
                await loadEvents();
            }
        });

        // Commands inline editing (without full DOM re-render so focus/cursor stays intact)
        const commandsListEl = document.getElementById('commandsList');

        commandsListEl.addEventListener('input', (e) => {
            const target = e.target;
            const idx = Number(target.dataset.index);
            const field = target.dataset.field;
            if (Number.isNaN(idx) || !commands[idx] || !field) return;

            if (field === 'name' || field === 'command') {
                commands[idx][field] = target.value;
                activeStepStates = {};
                setDirty(true);
            }
        });

        commandsListEl.addEventListener('change', (e) => {
            const target = e.target;
            const idx = Number(target.dataset.index);
            const field = target.dataset.field;
            if (Number.isNaN(idx) || !commands[idx] || !field) return;

            if (field === 'is_enabled' || field === 'stop_on_error') {
                commands[idx][field] = target.checked;
                setDirty(true);
                if (field === 'is_enabled') {
                    const card = target.closest('.command-step');
                    if (card) card.classList.toggle('disabled-step', !target.checked);
                }
            }
        });

        commandsListEl.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-cmd-action]');
            if (!btn || isExecuting) return;

            const idx = Number(btn.dataset.index);
            const action = btn.dataset.cmdAction;
            if (Number.isNaN(idx) || !commands[idx]) return;

            if (action === 'up' && idx > 0) {
                const tmp = commands[idx - 1];
                commands[idx - 1] = commands[idx];
                commands[idx] = tmp;
                activeStepStates = {};
                setDirty(true);
                renderCommands();
            } else if (action === 'down' && idx < commands.length - 1) {
                const tmp = commands[idx + 1];
                commands[idx + 1] = commands[idx];
                commands[idx] = tmp;
                activeStepStates = {};
                setDirty(true);
                renderCommands();
            } else if (action === 'delete') {
                commands.splice(idx, 1);
                activeStepStates = {};
                setDirty(true);
                renderCommands();
            } else if (action === 'run') {
                runExecution('single_command', idx);
            }
        });

        document.getElementById('addCommandBtn').addEventListener('click', () => {
            if (isExecuting) return;
            commands.push({
                id: null,
                name: 'Step #' + (commands.length + 1),
                command: '',
                is_enabled: true,
                stop_on_error: true,
                last_exit_code: null,
                last_duration_ms: null,
                last_run_at: null
            });
            activeStepStates = {};
            setDirty(true);
            renderCommands();
            const editors = commandsListEl.querySelectorAll('.step-cmd-editor');
            if (editors.length > 0) {
                editors[editors.length - 1].focus();
            }
        });

        document.getElementById('presetBar').addEventListener('click', (e) => {
            const chip = e.target.closest('.preset-chip');
            if (!chip || isExecuting) return;
            commands.push({
                id: null,
                name: chip.dataset.name || 'Command step',
                command: chip.dataset.cmd || '',
                is_enabled: true,
                stop_on_error: true,
                last_exit_code: null,
                last_duration_ms: null,
                last_run_at: null
            });
            activeStepStates = {};
            setDirty(true);
            renderCommands();
        });

        document.getElementById('saveCommandsBtn').addEventListener('click', saveCommandsExplicit);

        // Ctrl+S / Cmd+S shortcut to save commands
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                const modalOpen = document.getElementById('projectModal').classList.contains('open');
                if (!modalOpen) {
                    e.preventDefault();
                    if (!isExecuting) saveCommandsExplicit();
                }
            }
        });

        // Quick Ad-hoc Command Runner
        document.getElementById('adhocForm').addEventListener('submit', (e) => {
            e.preventDefault();
            if (isExecuting) return;
            const input = document.getElementById('adhocCommandInput');
            const cmd = input.value.trim();
            if (!cmd) return;
            runExecution('adhoc_command', null, cmd);
        });

        // Terminal Console helpers
        document.getElementById('copyConsoleBtn').addEventListener('click', () => {
            navigator.clipboard.writeText(rawConsoleText || document.getElementById('terminalOutput').textContent)
                .then(() => App.toast('Console output copied to clipboard.', 'success'));
        });

        document.getElementById('clearConsoleBtn').addEventListener('click', () => {
            if (isExecuting) return;
            setConsoleText('Console cleared.\n');
            document.getElementById('consoleStateBadge').innerHTML = '';
            document.getElementById('consoleTimer').textContent = '';
        });

        // Complete Event Log filters & expansion
        document.getElementById('eventCategoryTabs').addEventListener('click', (e) => {
            const tab = e.target.closest('[data-cat]');
            if (!tab) return;
            eventCategory = tab.dataset.cat;
            document.querySelectorAll('#eventCategoryTabs .event-tab').forEach((el) => el.classList.remove('active'));
            tab.classList.add('active');
            loadEvents();
        });

        document.getElementById('eventStatusFilter').addEventListener('change', (e) => {
            eventStatus = e.target.value;
            loadEvents();
        });

        document.getElementById('eventSearchInput').addEventListener('input', (e) => {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => {
                eventSearch = e.target.value.trim();
                loadEvents();
            }, 250);
        });

        document.getElementById('refreshEventsBtn').addEventListener('click', loadEvents);

        document.getElementById('clearEventsBtn').addEventListener('click', async () => {
            if (!window.confirm('Clear historical event logs for this project?')) return;
            try {
                const res = await App.apiPost('clear_events', { project_id: project.id });
                App.toast(`Cleared ${res.deleted} event log entries.`, 'info');
                await loadEvents();
            } catch (err) {
                App.toast(err.message, 'error');
            }
        });

        document.getElementById('projectEventsList').addEventListener('click', (e) => {
            const copyBtn = e.target.closest('[data-copy-event]');
            if (copyBtn) {
                e.stopPropagation();
                const evId = Number(copyBtn.dataset.copyEvent);
                const ev = (eventsState.items || []).find((item) => item.id === evId);
                if (ev && ev.output_log) {
                    navigator.clipboard.writeText(ev.output_log)
                        .then(() => App.toast('Event transcript copied to clipboard.', 'success'));
                }
                return;
            }
            const toggleRow = e.target.closest('[data-toggle-event]');
            if (toggleRow) {
                const itemEl = toggleRow.closest('.event-item');
                if (itemEl) itemEl.classList.toggle('expanded');
            }
        });

        window.addEventListener('beforeunload', (e) => {
            if (isDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    });
})();

