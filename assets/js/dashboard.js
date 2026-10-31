(function () {
    'use strict';

    const App = window.DeployerApp;
    let projects = (window.INITIAL_DASHBOARD_DATA && window.INITIAL_DASHBOARD_DATA.projects) || [];
    let globalEvents = (window.INITIAL_DASHBOARD_DATA && window.INITIAL_DASHBOARD_DATA.events) || [];
    let activeEnv = 'all';
    let searchQuery = '';

    let activeAsideTab = 'events'; // 'events' | 'directory'
    let activeCmdCat = 'all';
    let cmdSearchQuery = '';
    let cmdRunModal = null;
    let execModalIsRunning = false;
    let execModalTimerInterval = null;
    let execModalRawOutput = '';

    function updateStats(stats) {
        if (!stats) return;
        const totalProjEl = document.getElementById('statTotalProjects');
        if (totalProjEl) totalProjEl.textContent = stats.total_projects ?? 0;
        const downProjEl = document.getElementById('statDownProjects');
        if (downProjEl) downProjEl.textContent = stats.down_projects ?? 0;
        const totalDepEl = document.getElementById('statTotalDeploys');
        if (totalDepEl) totalDepEl.textContent = stats.total_deployments ?? 0;
        const succDepEl = document.getElementById('statSuccessDeploys');
        if (succDepEl) succDepEl.textContent = stats.successful_deployments ?? 0;
        const failDepEl = document.getElementById('statFailedDeploys');
        if (failDepEl) failDepEl.textContent = stats.failed_deployments ?? 0;
    }

    function renderProjects() {
        const container = document.getElementById('projectsContainer');
        const q = searchQuery.toLowerCase();

        const downCount = projects.filter((p) => p.app_status === 'down').length;
        const downCountEl = document.getElementById('downFilterCount');
        if (downCountEl) {
            downCountEl.textContent = downCount;
        }
        const downPill = document.querySelector('#envFilterPills [data-env="app-down"]');
        if (downPill) {
            downPill.classList.toggle('has-down', downCount > 0);
        }
        const statDownProjectsEl = document.getElementById('statDownProjects');
        if (statDownProjectsEl) {
            statDownProjectsEl.textContent = downCount;
        }

        const filtered = projects.filter((p) => {
            if (activeEnv === 'app-down') {
                if (p.app_status !== 'down') return false;
            } else if (activeEnv !== 'all' && p.environment !== activeEnv) {
                return false;
            }
            if (!q) return true;
            const hay = [p.name, p.ssh_host, p.ssh_username, p.remote_path, p.repo_branch, p.description]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();
            return hay.includes(q);
        });

        if (projects.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <h3>No SSH deployment projects configured yet</h3>
                    <p>Add your first project with its remote SSH host, working directory, and password (encrypted automatically with AES-256-GCM) to manage 1-click deployments.</p>
                    <button type="button" class="btn btn-primary btn-lg" id="emptyStateAddBtn">+ Create First Project</button>
                </div>
            `;
            document.getElementById('emptyStateAddBtn').addEventListener('click', () => openAddModal());
            return;
        }

        if (filtered.length === 0) {
            container.innerHTML = `
                <div class="empty-state" style="padding: 32px 20px;">
                    <h3>No matching projects</h3>
                    <p>Try clearing your search query or switching the environment filter.</p>
                </div>
            `;
            return;
        }

        const rankById = new Map(projects.map((item, index) => [Number(item.id), index + 1]));

        const cardsHtml = filtered.map((p) => {
            const authLabel = p.ssh_auth_type === 'key'
                ? (p.has_private_key ? '&#128273; SSH Key Encrypted' : '&#9888; Missing Key')
                : (p.has_password ? '&#128274; Password Encrypted' : '&#9888; Missing Password');

            const lastDeployText = p.last_deployed_at
                ? `${App.escapeHtml(p.last_deployed_at)}${p.last_deploy_duration_ms ? ' (' + App.escapeHtml(App.formatDuration(p.last_deploy_duration_ms)) + ')' : ''}`
                : 'No deployments run yet';

            const priorityRank = rankById.get(Number(p.id)) || 1;
            const isDown = (p.app_status === 'down');

            const downStripHtml = isDown ? `
                <div class="card-down-strip">
                    <div class="card-down-msg">
                        <span class="down-warning-dot" aria-hidden="true"></span>
                        <strong>APP IS CURRENTLY DOWN</strong>
                        <span>(Maintenance mode active)</span>
                    </div>
                    <button type="button" class="btn btn-xs btn-outline-down" data-action="toggle-state" data-id="${p.id}" data-target-state="up" title="Manually mark application as UP">
                        Mark Up 🟢
                    </button>
                </div>
            ` : '';

            const appBadgeHtml = isDown
                ? `<span class="badge badge-down"><span class="down-warning-dot" aria-hidden="true"></span> App Down</span>`
                : (p.app_status === 'up'
                    ? `<span class="badge badge-up">&#9679; App Up</span>`
                    : '');

            return `
                <article class="project-card ${isDown ? 'app-down' : ''}" data-project-id="${p.id}">
                    ${downStripHtml}
                    <div class="project-card-header">
                        <div class="project-card-heading-left">
                            <div class="project-drag-grip" title="Drag to arrange project priority" aria-label="Drag to arrange project priority">
                                <svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor" aria-hidden="true">
                                    <circle cx="2.5" cy="2.5" r="1.3"/><circle cx="7.5" cy="2.5" r="1.3"/>
                                    <circle cx="2.5" cy="7" r="1.3"/><circle cx="7.5" cy="7" r="1.3"/>
                                    <circle cx="2.5" cy="11.5" r="1.3"/><circle cx="7.5" cy="11.5" r="1.3"/>
                                </svg>
                                <span class="priority-rank" data-priority-rank>#${priorityRank}</span>
                            </div>
                            <div style="min-width:0;">
                                <h3 class="project-card-title">
                                    <a href="project.php?id=${p.id}">${App.escapeHtml(p.name)}</a>
                                </h3>
                                ${p.description ? `<p class="project-card-desc">${App.escapeHtml(p.description)}</p>` : ''}
                            </div>
                        </div>
                        <div class="project-card-badges">
                            ${appBadgeHtml}
                            <span class="badge env-${App.escapeHtml(p.environment)}">${App.escapeHtml(p.environment)}</span>
                        </div>
                    </div>

                    <div class="meta-box">
                        <div class="meta-row">
                            <span class="meta-key">SSH Target</span>
                            <span class="meta-val" title="${App.escapeHtml(p.ssh_username + '@' + p.ssh_host + ':' + p.ssh_port)}">
                                ${App.escapeHtml(p.ssh_username)}@${App.escapeHtml(p.ssh_host)}:${p.ssh_port}
                            </span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-key">Remote Directory</span>
                            <span class="meta-val" title="${App.escapeHtml(p.remote_path || '$HOME')}">
                                ${App.escapeHtml(p.remote_path || '~ ($HOME)')}
                            </span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-key">Branch &amp; Vault</span>
                            <span class="meta-val" style="color:#34d399;">
                                ${App.escapeHtml(p.repo_branch)} &bull; ${authLabel}
                            </span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-key">App State</span>
                            <span class="meta-val">
                                ${isDown
                                    ? `<span style="color:#ef4444; font-weight:600;">🔴 Down (Maintenance)</span>`
                                    : (p.app_status === 'up'
                                        ? `<span style="color:#10b981; font-weight:600;">🟢 Up (Live)</span>`
                                        : `<span style="color:var(--text-muted);">Unknown / Untracked</span>`)
                                }
                                ${p.app_status_updated_at ? `<span style="color:var(--text-muted); font-size:11px;"> &bull; since ${App.escapeHtml(p.app_status_updated_at)}</span>` : ''}
                            </span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-key">Pipeline</span>
                            <span class="meta-val">
                                ${p.enabled_command_count} active / ${p.command_count} commands &bull; ${p.deployment_count} deploys
                            </span>
                        </div>
                    </div>

                    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:12px;">
                        ${App.statusBadgeHtml(p.last_deploy_status)}
                        <span style="color:var(--text-muted);">${lastDeployText}</span>
                    </div>

                    <div class="project-card-footer">
                        <div class="card-actions-left">
                            <button type="button" class="btn btn-ghost btn-sm" data-action="edit" data-id="${p.id}">Edit</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-action="duplicate" data-id="${p.id}" title="Duplicate project &amp; command template">Duplicate</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-action="ssh" data-id="${p.id}">SSH Settings</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-action="test-ssh" data-id="${p.id}">Test SSH</button>
                            <button type="button" class="btn btn-danger btn-sm" data-action="delete" data-id="${p.id}">Delete</button>
                        </div>
                        <div class="card-actions-right">
                            <button type="button" class="btn btn-deploy btn-sm" data-action="run" data-id="${p.id}" title="Quick run all enabled deployment commands for this project">
                                <span>&#9654;</span>
                                <span>Run</span>
                            </button>
                            <a href="project.php?id=${p.id}" class="btn btn-primary btn-sm">Manage &amp; Deploy &rarr;</a>
                        </div>
                    </div>
                </article>
            `;
        }).join('');

        container.innerHTML = `<div class="projects-grid">${cardsHtml}</div>`;
    }

    function renderGlobalEvents() {
        const listEl = document.getElementById('globalEventsList');
        const countEl = document.getElementById('asideEventsCount');
        if (countEl) {
            countEl.textContent = globalEvents ? globalEvents.length : 0;
        }
        if (!listEl) return;
        if (!globalEvents || globalEvents.length === 0) {
            listEl.innerHTML = '<div style="padding:24px; text-align:center; color:var(--text-muted);">No events logged yet.</div>';
            return;
        }
        listEl.innerHTML = globalEvents.map((ev) => App.renderEventItemHtml(ev, true)).join('');
    }

    async function refreshAll() {
        try {
            const [projData, evData] = await Promise.all([
                App.apiGet('list_projects'),
                App.apiGet('list_events', { limit: 30 })
            ]);
            projects = projData.projects || [];
            if (App.setAvailableProjects) {
                App.setAvailableProjects(projects);
            }
            updateStats(projData.stats);
            globalEvents = evData.items || [];
            renderProjects();
            renderGlobalEvents();
            if (cmdRunModal && cmdRunModal.populateProjects) {
                cmdRunModal.populateProjects();
            }
        } catch (err) {
            App.toast(err.message, 'error');
        }
    }

    function openAddModal() {
        App.openProjectModal(null, async (savedProject, wasSaved) => {
            if (wasSaved && savedProject) {
                await refreshAll();
            }
        }, false);
    }

    function initProjectDragReorder() {
        const container = document.getElementById('projectsContainer');
        const scrollPane = document.querySelector('.dashboard-main');
        if (!container) return;

        let draggedCard = null;
        let touchDragCard = null;
        let dragInitialIds = [];

        function getDomCardElements() {
            return Array.from(container.querySelectorAll('.project-card[data-project-id]'));
        }

        function getDomCardIds() {
            return getDomCardElements().map((el) => Number(el.dataset.projectId));
        }

        function computePreviewProjectsFromDom() {
            const domIds = getDomCardIds();
            const visibleSet = new Set(domIds);
            const byId = new Map(projects.map((p) => [Number(p.id), p]));
            const orderedVisible = domIds.map((id) => byId.get(id)).filter(Boolean);
            let cursor = 0;
            return projects.map((p) => (visibleSet.has(Number(p.id)) ? orderedVisible[cursor++] : p));
        }

        function syncDomPriorityBadges() {
            const previewList = computePreviewProjectsFromDom();
            const rankMap = new Map(previewList.map((p, idx) => [Number(p.id), idx + 1]));
            for (const cardEl of getDomCardElements()) {
                const pid = Number(cardEl.dataset.projectId);
                const badgeEl = cardEl.querySelector('[data-priority-rank]');
                if (badgeEl && rankMap.has(pid)) {
                    badgeEl.textContent = '#' + rankMap.get(pid);
                }
            }
        }

        function autoScrollNearEdges(clientY) {
            const edgeThreshold = 68;
            const maxStep = 16;

            if (scrollPane && window.innerWidth > 1180) {
                const rect = scrollPane.getBoundingClientRect();
                if (clientY < rect.top + edgeThreshold) {
                    const ratio = Math.min(1, (rect.top + edgeThreshold - clientY) / edgeThreshold);
                    scrollPane.scrollTop -= Math.ceil(maxStep * ratio);
                } else if (clientY > rect.bottom - edgeThreshold) {
                    const ratio = Math.min(1, (clientY - (rect.bottom - edgeThreshold)) / edgeThreshold);
                    scrollPane.scrollTop += Math.ceil(maxStep * ratio);
                }
            } else {
                if (clientY < edgeThreshold) {
                    window.scrollBy(0, -12);
                } else if (clientY > window.innerHeight - edgeThreshold) {
                    window.scrollBy(0, 12);
                }
            }
        }

        function maybeSwapCards(activeCard, overCard, clientX, clientY) {
            if (!activeCard || !overCard || activeCard === overCard) return;

            const cards = getDomCardElements();
            const activeIdx = cards.indexOf(activeCard);
            const overIdx = cards.indexOf(overCard);
            if (activeIdx === -1 || overIdx === -1) return;

            const activeRect = activeCard.getBoundingClientRect();
            const overRect = overCard.getBoundingClientRect();
            const sameRow = Math.abs(activeRect.top - overRect.top) < overRect.height * 0.45;

            if (sameRow) {
                const midX = overRect.left + overRect.width * 0.5;
                if (activeIdx < overIdx && clientX > midX) {
                    overCard.after(activeCard);
                    syncDomPriorityBadges();
                } else if (activeIdx > overIdx && clientX < midX) {
                    overCard.before(activeCard);
                    syncDomPriorityBadges();
                }
            } else {
                const midY = overRect.top + overRect.height * 0.5;
                if (activeIdx < overIdx && clientY > midY) {
                    overCard.after(activeCard);
                    syncDomPriorityBadges();
                } else if (activeIdx > overIdx && clientY < midY) {
                    overCard.before(activeCard);
                    syncDomPriorityBadges();
                }
            }
        }

        async function commitDomReorder(initialIds) {
            const currentIds = getDomCardIds();
            if (
                initialIds.length === 0
                || currentIds.length !== initialIds.length
                || initialIds.join(',') === currentIds.join(',')
            ) {
                return;
            }

            const previousProjects = projects.slice();
            projects = computePreviewProjectsFromDom();
            syncDomPriorityBadges();
            if (App.setAvailableProjects) {
                App.setAvailableProjects(projects);
            }

            try {
                const res = await App.apiPost('reorder_projects', {
                    ordered_ids: projects.map((p) => p.id)
                });
                if (Array.isArray(res.projects)) {
                    projects = res.projects;
                    if (App.setAvailableProjects) {
                        App.setAvailableProjects(projects);
                    }
                    syncDomPriorityBadges();
                }
                App.toast('Project priority order saved.', 'success', 2000);
            } catch (err) {
                projects = previousProjects;
                if (App.setAvailableProjects) {
                    App.setAvailableProjects(projects);
                }
                renderProjects();
                App.toast('Failed to save priority order: ' + err.message, 'error');
            }
        }

        // Enable native HTML5 dragging from grip handle or card surface (while keeping text in .meta-box selectable)
        container.addEventListener('mousedown', (e) => {
            const card = e.target.closest('.project-card');
            if (!card) return;
            const isInteractiveOrSelectable = Boolean(
                e.target.closest('a, button, input, select, textarea, .meta-box')
            );
            if (isInteractiveOrSelectable) {
                card.removeAttribute('draggable');
            } else {
                card.setAttribute('draggable', 'true');
            }
        });

        container.addEventListener('mouseup', (e) => {
            const card = e.target.closest('.project-card');
            if (card && !draggedCard) {
                card.removeAttribute('draggable');
            }
        });

        container.addEventListener('dragstart', (e) => {
            const card = e.target.closest('.project-card');
            if (!card || card.getAttribute('draggable') !== 'true') {
                e.preventDefault();
                return;
            }
            draggedCard = card;
            dragInitialIds = getDomCardIds();
            container.classList.add('is-reordering');
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', String(card.dataset.projectId || ''));
            }
            requestAnimationFrame(() => {
                if (draggedCard === card) {
                    card.classList.add('is-dragging');
                }
            });
        });

        container.addEventListener('dragover', (e) => {
            if (!draggedCard) return;
            e.preventDefault();
            if (e.dataTransfer) {
                e.dataTransfer.dropEffect = 'move';
            }
            autoScrollNearEdges(e.clientY);
            const overCard = e.target.closest('.project-card');
            maybeSwapCards(draggedCard, overCard, e.clientX, e.clientY);
        });

        container.addEventListener('drop', (e) => {
            if (!draggedCard) return;
            e.preventDefault();
        });

        container.addEventListener('dragend', () => {
            if (!draggedCard) return;
            const finishedCard = draggedCard;
            const initialIds = dragInitialIds.slice();
            draggedCard = null;
            dragInitialIds = [];
            finishedCard.classList.remove('is-dragging');
            finishedCard.removeAttribute('draggable');
            container.classList.remove('is-reordering');
            commitDomReorder(initialIds);
        });

        // Mobile / Touch drag support via the `.project-drag-grip` handle
        container.addEventListener('touchstart', (e) => {
            const grip = e.target.closest('.project-drag-grip');
            if (!grip || e.touches.length !== 1) return;
            const card = grip.closest('.project-card');
            if (!card) return;
            touchDragCard = card;
            dragInitialIds = getDomCardIds();
            card.classList.add('is-dragging');
            container.classList.add('is-reordering');
        }, { passive: true });

        container.addEventListener('touchmove', (e) => {
            if (!touchDragCard || e.touches.length !== 1) return;
            e.preventDefault();
            const touch = e.touches[0];
            autoScrollNearEdges(touch.clientY);
            const targetEl = document.elementFromPoint(touch.clientX, touch.clientY);
            const overCard = targetEl ? targetEl.closest('.project-card') : null;
            maybeSwapCards(touchDragCard, overCard, touch.clientX, touch.clientY);
        }, { passive: false });

        function finishTouchDrag() {
            if (!touchDragCard) return;
            const finishedCard = touchDragCard;
            const initialIds = dragInitialIds.slice();
            touchDragCard = null;
            dragInitialIds = [];
            finishedCard.classList.remove('is-dragging');
            container.classList.remove('is-reordering');
            commitDomReorder(initialIds);
        }

        container.addEventListener('touchend', finishTouchDrag);
        container.addEventListener('touchcancel', finishTouchDrag);
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (App.setAvailableProjects) {
            App.setAvailableProjects(projects);
        }
        renderProjects();
        renderGlobalEvents();
        initProjectDragReorder();

        document.getElementById('addProjectBtn').addEventListener('click', openAddModal);

        document.getElementById('projectSearchInput').addEventListener('input', (e) => {
            searchQuery = e.target.value.trim();
            renderProjects();
        });

        document.getElementById('envFilterPills').addEventListener('click', (e) => {
            const btn = e.target.closest('[data-env]');
            if (!btn) return;
            activeEnv = btn.dataset.env;
            document.querySelectorAll('#envFilterPills .filter-pill').forEach((el) => el.classList.remove('active'));
            btn.classList.add('active');
            renderProjects();
        });

        document.getElementById('projectsContainer').addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-action]');
            if (!btn) return;
            const id = Number(btn.dataset.id);
            const project = projects.find((p) => p.id === id);
            if (!project) return;

            const action = btn.dataset.action;
            if (action === 'run') {
                if ((project.enabled_command_count || 0) === 0) {
                    App.toast(`No enabled commands found in "${project.name}". Click "Manage & Deploy" to configure commands first.`, 'warning');
                    return;
                }

                const isProd = project.environment === 'production';
                const confirmed = await App.confirmAction({
                    title: 'Run Deployment Pipeline?',
                    subtitle: project.name,
                    message: `You are about to execute all <strong>${project.enabled_command_count} active command(s)</strong> on remote host <strong>${App.escapeHtml(project.ssh_username)}@${App.escapeHtml(project.ssh_host)}</strong> in directory <code>${App.escapeHtml(project.remote_path || '~')}</code>.`,
                    warning: isProd
                        ? 'Caution: This targets your PRODUCTION environment. Live traffic and database changes may take effect.'
                        : null,
                    confirmText: 'Run All Commands',
                    confirmClass: 'btn-deploy',
                    icon: '▶',
                    iconVariant: 'deploy',
                });
                if (!confirmed) return;

                btn.disabled = true;
                const origHtml = btn.innerHTML;
                btn.innerHTML = '<span>&#9203;</span><span>Running...</span>';

                const cardEl = btn.closest('.project-card');
                const statusBadgeEl = cardEl ? cardEl.querySelector('.status-pill') : null;
                if (statusBadgeEl) {
                    statusBadgeEl.outerHTML = App.statusBadgeHtml('running');
                }

                App.toast(`Running deployment pipeline for "${project.name}"...`, 'info', 3000);

                try {
                    const finalPacket = await App.streamExecute({
                        project_id: id,
                        mode: 'deploy_all'
                    });

                    if (finalPacket && finalPacket.status === 'success') {
                        App.toast(`Deployment succeeded for "${project.name}" (${App.formatDuration(finalPacket.duration_ms)})`, 'success');
                    } else if (finalPacket && finalPacket.status === 'failed') {
                        App.toast(`Deployment failed for "${project.name}" (exit ${finalPacket.exit_code ?? 'non-zero'})`, 'error');
                    } else {
                        App.toast(`Deployment finished for "${project.name}".`, 'info');
                    }
                } catch (err) {
                    App.toast(`Deployment failed for "${project.name}": ` + err.message, 'error');
                } finally {
                    await refreshAll();
                }
            } else if (action === 'toggle-state') {
                const targetState = btn.dataset.targetState || (project.app_status === 'down' ? 'up' : 'down');
                const actionLabel = targetState === 'down' ? 'DOWN (Maintenance Mode)' : 'UP (Live)';
                const confirmed = await App.confirmAction({
                    title: `Mark App as ${targetState.toUpperCase()}?`,
                    subtitle: project.name,
                    message: `Change application status flag to <strong>${actionLabel}</strong>?`,
                    warning: targetState === 'down'
                        ? 'This highlights the project card as DOWN across the dashboard.'
                        : 'This clears the DOWN alert and marks the project as live.',
                    confirmText: `Set as ${targetState.toUpperCase()}`,
                    confirmClass: targetState === 'down' ? 'btn-danger' : 'btn-success',
                    icon: targetState === 'down' ? '🔴' : '🟢',
                    iconVariant: targetState === 'down' ? 'danger' : 'success',
                });
                if (!confirmed) return;

                btn.disabled = true;
                try {
                    await App.apiPost('set_app_state', { project_id: id, app_state: targetState });
                    App.toast(`Project "${project.name}" marked as ${targetState.toUpperCase()}.`, 'success');
                    await refreshAll();
                } catch (err) {
                    App.toast('Failed to change app state: ' + err.message, 'error');
                    btn.disabled = false;
                }
            } else if (action === 'edit') {
                App.openProjectModal(project, async () => refreshAll(), false);
            } else if (action === 'duplicate') {
                App.openDuplicateModal(project, async (savedProject, wasSaved) => {
                    if (wasSaved && savedProject) {
                        await refreshAll();
                    }
                });
            } else if (action === 'ssh') {
                App.openProjectModal(project, async () => refreshAll(), true);
            } else if (action === 'test-ssh') {
                btn.disabled = true;
                const orig = btn.textContent;
                btn.textContent = 'Testing...';
                try {
                    const res = await App.apiPost('test_ssh', { project_id: id });
                    App.toast(res.result.message, res.result.path_ok ? 'success' : 'warning');
                    await refreshAll();
                } catch (err) {
                    App.toast('SSH Test Failed: ' + err.message, 'error');
                    await refreshAll();
                } finally {
                    btn.disabled = false;
                    btn.textContent = orig;
                }
            } else if (action === 'delete') {
                const confirmed = await App.confirmAction({
                    title: 'Delete Project?',
                    subtitle: project.name,
                    message: `Are you sure you want to permanently delete <strong>${App.escapeHtml(project.name)}</strong> and all its command definitions, deployment logs, and saved SSH credentials?`,
                    warning: 'This action cannot be undone.',
                    confirmText: 'Delete Project',
                    confirmClass: 'btn-danger',
                    icon: '🗑',
                    iconVariant: 'danger',
                });
                if (!confirmed) return;

                btn.disabled = true;
                try {
                    await App.apiPost('delete_project', { id });
                    App.toast(`Project "${project.name}" deleted.`, 'info');
                    await refreshAll();
                } catch (err) {
                    App.toast(err.message, 'error');
                    btn.disabled = false;
                }
            }
        });

        document.getElementById('refreshGlobalEventsBtn').addEventListener('click', refreshAll);

        document.getElementById('globalEventsList').addEventListener('click', (e) => {
            const copyBtn = e.target.closest('[data-copy-event]');
            if (copyBtn) {
                e.stopPropagation();
                const evId = Number(copyBtn.dataset.copyEvent);
                const ev = globalEvents.find((item) => item.id === evId);
                if (ev && ev.output_log) {
                    navigator.clipboard.writeText(ev.output_log).then(() => App.toast('Event log copied to clipboard.', 'success'));
                }
                return;
            }
            const toggleRow = e.target.closest('[data-toggle-event]');
            if (toggleRow) {
                const itemEl = toggleRow.closest('.event-item');
                if (itemEl) itemEl.classList.toggle('expanded');
            }
        });

        // =========================================================================
        // Developer Command Directory & Aside Tab Navigation
        // =========================================================================
        function initAsideTabs() {
            const eventsTabBtn = document.getElementById('asideTabEventsBtn');
            const dirTabBtn = document.getElementById('asideTabDirectoryBtn');
            const eventsPane = document.getElementById('asidePaneEvents');
            const dirPane = document.getElementById('asidePaneDirectory');
            const eventsActions = document.getElementById('asideEventsActions');

            function switchTab(tab) {
                activeAsideTab = tab;
                if (tab === 'events') {
                    if (eventsTabBtn) {
                        eventsTabBtn.classList.add('active');
                        eventsTabBtn.setAttribute('aria-selected', 'true');
                    }
                    if (dirTabBtn) {
                        dirTabBtn.classList.remove('active');
                        dirTabBtn.setAttribute('aria-selected', 'false');
                    }
                    if (eventsPane) {
                        eventsPane.style.display = '';
                        eventsPane.classList.add('active');
                    }
                    if (dirPane) {
                        dirPane.style.display = 'none';
                        dirPane.classList.remove('active');
                    }
                    if (eventsActions) eventsActions.style.display = '';
                } else {
                    if (dirTabBtn) {
                        dirTabBtn.classList.add('active');
                        dirTabBtn.setAttribute('aria-selected', 'true');
                    }
                    if (eventsTabBtn) {
                        eventsTabBtn.classList.remove('active');
                        eventsTabBtn.setAttribute('aria-selected', 'false');
                    }
                    if (dirPane) {
                        dirPane.style.display = '';
                        dirPane.classList.add('active');
                    }
                    if (eventsPane) {
                        eventsPane.style.display = 'none';
                        eventsPane.classList.remove('active');
                    }
                    if (eventsActions) eventsActions.style.display = 'none';
                }
            }

            if (eventsTabBtn) eventsTabBtn.addEventListener('click', () => switchTab('events'));
            if (dirTabBtn) dirTabBtn.addEventListener('click', () => switchTab('directory'));

            const cmdCountEl = document.getElementById('asideCommandsCount');
            if (cmdCountEl && window.COMMAND_DIRECTORY) {
                cmdCountEl.textContent = window.COMMAND_DIRECTORY.length;
            }
        }

        function renderCommandFilters() {
            const pillsContainer = document.getElementById('cmddirFilterPills');
            if (!pillsContainer) return;

            const categories = window.COMMAND_DIRECTORY_CATEGORIES || [];
            const commands = window.COMMAND_DIRECTORY || [];

            pillsContainer.innerHTML = categories.map((cat) => {
                const count = cat.id === 'all'
                    ? commands.length
                    : commands.filter((c) => c.category === cat.id).length;
                const isActive = activeCmdCat === cat.id ? 'active' : '';
                return `
                    <button type="button" class="filter-pill ${isActive}" data-cmd-cat="${cat.id}">
                        <span>${cat.icon}</span> <span>${cat.label}</span> (<span class="tab-count">${count}</span>)
                    </button>
                `;
            }).join('');

            pillsContainer.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-cmd-cat]');
                if (!btn) return;
                activeCmdCat = btn.dataset.cmdCat;
                pillsContainer.querySelectorAll('.filter-pill').forEach((el) => el.classList.remove('active'));
                btn.classList.add('active');
                renderCommandDirectory();
            });
        }

        function renderCommandDirectory() {
            const listEl = document.getElementById('cmddirList');
            if (!listEl) return;

            const allCommands = window.COMMAND_DIRECTORY || [];
            const categories = window.COMMAND_DIRECTORY_CATEGORIES || [];
            const catMap = new Map(categories.map((c) => [c.id, c]));

            const q = cmdSearchQuery.toLowerCase();

            const filtered = allCommands.filter((cmd) => {
                if (activeCmdCat !== 'all' && cmd.category !== activeCmdCat) {
                    return false;
                }
                if (!q) return true;
                const hay = [
                    cmd.title,
                    cmd.command,
                    cmd.desc,
                    cmd.category,
                    Array.isArray(cmd.tags) ? cmd.tags.join(' ') : ''
                ].join(' ').toLowerCase();
                return hay.includes(q);
            });

            if (filtered.length === 0) {
                listEl.innerHTML = `
                    <div style="padding:32px 16px; text-align:center; color:var(--text-muted);">
                        <div style="font-size:24px; margin-bottom:8px;">🔍</div>
                        <strong style="display:block; color:var(--text-primary); margin-bottom:4px;">No matching commands found</strong>
                        <span style="font-size:12px;">Try adjusting your search query or switching to another category.</span>
                    </div>
                `;
                return;
            }

            listEl.innerHTML = filtered.map((cmd) => {
                const cat = catMap.get(cmd.category);
                const catLabel = cat ? cat.label : cmd.category;
                const catClass = (cat && cat.badgeClass) ? cat.badgeClass : ('cat-' + cmd.category);

                return `
                    <div class="cmddir-item" data-cmd-id="${cmd.id}">
                        <div class="cmddir-item-header">
                            <div class="cmddir-item-title-wrap">
                                <span class="cat-badge ${catClass}">${App.escapeHtml(catLabel)}</span>
                                <strong class="cmddir-item-title">${App.escapeHtml(cmd.title)}</strong>
                            </div>
                            ${cmd.suggestSudo ? `<span class="sudo-tag" title="Command typically requires sudo/root access">sudo</span>` : ''}
                        </div>
                        ${cmd.desc ? `<p class="cmddir-item-desc">${App.escapeHtml(cmd.desc)}</p>` : ''}
                        <div class="cmddir-code-wrap">
                            <code class="cmddir-code" title="${App.escapeHtml(cmd.command)}">${App.escapeHtml(cmd.command)}</code>
                            <button type="button" class="cmddir-copy-btn" data-copy-cmd="${App.escapeHtml(cmd.command)}" title="Copy command to clipboard">
                                📋
                            </button>
                        </div>
                        <div class="cmddir-item-actions">
                            <button type="button" class="btn btn-xs cmddir-run-btn" data-action="run-cmd" data-id="${cmd.id}" data-admin="false" title="Edit &amp; Run command">
                                <span>▶</span> <span>Run</span>
                            </button>
                            <button type="button" class="btn btn-xs cmddir-admin-btn" data-action="run-cmd" data-id="${cmd.id}" data-admin="true" title="Edit &amp; Run with sudo (Admin privileges)">
                                <span>⚡</span> <span>Run as Admin</span>
                            </button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // =========================================================================
        // Command Execution & Confirmation Modal Controller
        // =========================================================================
        function initCommandRunModal() {
            const modal = document.getElementById('commandRunModal');
            if (!modal) return null;

            const projectSelect = document.getElementById('execModalProjectSelect');
            const envBadge = document.getElementById('execModalEnvBadge');
            const targetHostEl = document.getElementById('execProjectTarget');
            const targetDirEl = document.getElementById('execProjectDir');
            const prodWarningEl = document.getElementById('execProdWarning');
            const sudoToggle = document.getElementById('execModalSudoToggle');
            const cmdTextarea = document.getElementById('execModalCmdText');
            const cmdDesc = document.getElementById('execModalCmdDesc');
            const closeBtn = document.getElementById('execModalCloseBtn');
            const cancelBtn = document.getElementById('execModalCancelBtn');
            const submitBtn = document.getElementById('execModalSubmitBtn');
            const submitLabel = document.getElementById('execModalSubmitLabel');
            const terminalWrap = document.getElementById('execModalTerminalWrap');
            const terminalOutput = document.getElementById('execModalOutput');
            const terminalStatus = document.getElementById('execTerminalStatus');
            const terminalTimer = document.getElementById('execTerminalTimer');
            const copyOutputBtn = document.getElementById('execCopyOutputBtn');
            const clearOutputBtn = document.getElementById('execClearOutputBtn');

            function populateProjectOptions() {
                if (!projectSelect) return;
                const lastChosenId = localStorage.getItem('deployer_last_target_project_id');
                const currentVal = projectSelect.value || lastChosenId;

                projectSelect.innerHTML = projects.map((p) => {
                    const envTag = `[${p.environment.toUpperCase()}]`;
                    return `<option value="${p.id}">${App.escapeHtml(p.name)} ${envTag} &bull; ${App.escapeHtml(p.ssh_username)}@${App.escapeHtml(p.ssh_host)}</option>`;
                }).join('');

                const hasPrev = projects.some((p) => String(p.id) === String(currentVal));
                if (hasPrev) {
                    projectSelect.value = String(currentVal);
                } else if (projects.length > 0) {
                    projectSelect.value = String(projects[0].id);
                }
                updateProjectContext();
            }

            function updateProjectContext() {
                if (!projectSelect) return;
                const pid = Number(projectSelect.value);
                const proj = projects.find((p) => p.id === pid);
                if (!proj) return;

                localStorage.setItem('deployer_last_target_project_id', String(proj.id));

                if (envBadge) {
                    envBadge.className = 'badge env-' + proj.environment;
                    envBadge.textContent = proj.environment;
                }
                if (targetHostEl) {
                    targetHostEl.textContent = `${proj.ssh_username}@${proj.ssh_host}:${proj.ssh_port}`;
                }
                if (targetDirEl) {
                    targetDirEl.textContent = `dir: ${proj.remote_path || '~ ($HOME)'}`;
                }
                if (prodWarningEl) {
                    prodWarningEl.style.display = (proj.environment === 'production') ? 'flex' : 'none';
                }
            }

            if (projectSelect) {
                projectSelect.addEventListener('change', updateProjectContext);
            }

            function updateSubmitButtonLabel() {
                if (!submitBtn || !submitLabel) return;
                if (sudoToggle && sudoToggle.checked) {
                    submitLabel.textContent = 'Run as Admin (sudo)';
                    submitBtn.className = 'btn btn-warning';
                } else {
                    submitLabel.textContent = 'Run Command';
                    submitBtn.className = 'btn btn-primary';
                }
            }

            if (sudoToggle) {
                sudoToggle.addEventListener('change', () => {
                    if (!cmdTextarea) return;
                    const raw = cmdTextarea.value.trim();
                    const hasSudo = raw.startsWith('sudo ');
                    if (sudoToggle.checked && !hasSudo) {
                        cmdTextarea.value = 'sudo ' + raw;
                    } else if (!sudoToggle.checked && hasSudo) {
                        cmdTextarea.value = raw.replace(/^sudo\s+/, '');
                    }
                    updateSubmitButtonLabel();
                });
            }

            if (cmdTextarea) {
                cmdTextarea.addEventListener('input', () => {
                    const raw = cmdTextarea.value.trim();
                    const hasSudo = raw.startsWith('sudo ');
                    if (sudoToggle && sudoToggle.checked !== hasSudo) {
                        sudoToggle.checked = hasSudo;
                        updateSubmitButtonLabel();
                    }
                });
            }

            function openModalForCommand(commandObj, asAdmin = false) {
                populateProjectOptions();

                let cmd = (commandObj && commandObj.command) ? commandObj.command : '';
                if (asAdmin && !cmd.startsWith('sudo ')) {
                    cmd = 'sudo ' + cmd;
                }

                if (cmdTextarea) {
                    cmdTextarea.value = cmd;
                }
                if (sudoToggle) {
                    sudoToggle.checked = cmd.startsWith('sudo ');
                }
                updateSubmitButtonLabel();

                if (cmdDesc) {
                    if (commandObj && commandObj.desc) {
                        cmdDesc.textContent = commandObj.desc;
                        cmdDesc.style.display = 'block';
                    } else {
                        cmdDesc.style.display = 'none';
                    }
                }

                // Reset terminal display
                if (terminalWrap) terminalWrap.style.display = 'none';
                if (terminalOutput) terminalOutput.textContent = 'Ready to execute.';
                if (terminalStatus) terminalStatus.innerHTML = '';
                if (terminalTimer) terminalTimer.textContent = '';
                execModalRawOutput = '';
                execModalIsRunning = false;

                if (submitBtn) submitBtn.disabled = false;
                if (cancelBtn) cancelBtn.textContent = 'Cancel';

                modal.style.display = 'flex';
                void modal.offsetWidth;
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');

                setTimeout(() => {
                    if (cmdTextarea) {
                        cmdTextarea.focus();
                        cmdTextarea.setSelectionRange(cmdTextarea.value.length, cmdTextarea.value.length);
                    }
                }, 80);
            }

            function closeModal() {
                if (execModalIsRunning) {
                    if (!window.confirm('Command is currently running on the server. Close console window? (The background task will continue on the remote host).')) {
                        return;
                    }
                }
                if (execModalTimerInterval) {
                    clearInterval(execModalTimerInterval);
                    execModalTimerInterval = null;
                }
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 180);
            }

            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeModal();
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal.classList.contains('open')) {
                    closeModal();
                }
            });

            if (copyOutputBtn) {
                copyOutputBtn.addEventListener('click', () => {
                    const text = execModalRawOutput || (terminalOutput ? terminalOutput.textContent : '');
                    navigator.clipboard.writeText(text).then(() => App.toast('Console output copied.', 'success'));
                });
            }

            if (clearOutputBtn) {
                clearOutputBtn.addEventListener('click', () => {
                    if (execModalIsRunning) return;
                    execModalRawOutput = '';
                    if (terminalOutput) terminalOutput.textContent = 'Cleared.\n';
                    if (terminalTimer) terminalTimer.textContent = '';
                    if (terminalStatus) terminalStatus.innerHTML = '';
                });
            }

            if (submitBtn) {
                submitBtn.addEventListener('click', async () => {
                    if (execModalIsRunning) return;

                    const pid = Number(projectSelect ? projectSelect.value : 0);
                    const proj = projects.find((p) => p.id === pid);
                    if (!proj) {
                        App.toast('Please select a target project first.', 'warning');
                        return;
                    }

                    const rawCmd = cmdTextarea ? cmdTextarea.value.trim() : '';
                    if (!rawCmd) {
                        App.toast('Command text cannot be empty.', 'warning');
                        if (cmdTextarea) cmdTextarea.focus();
                        return;
                    }

                    // If production target, prompt confirmation
                    if (proj.environment === 'production') {
                        const confirmed = await App.confirmAction({
                            title: 'Execute on PRODUCTION Server?',
                            subtitle: `${proj.name} (${proj.ssh_username}@${proj.ssh_host})`,
                            message: `You are about to execute the following command live on your production server:`,
                            codeSnippet: rawCmd,
                            warning: 'Caution: This targets your live production environment. Database changes or service reloads may affect live users.',
                            confirmText: 'Yes, Execute on Production',
                            confirmClass: 'btn-danger',
                            icon: '⚠️',
                            iconVariant: 'danger',
                        });
                        if (!confirmed) return;
                    }

                    execModalIsRunning = true;
                    submitBtn.disabled = true;
                    if (cancelBtn) cancelBtn.textContent = 'Close';

                    if (terminalWrap) terminalWrap.style.display = 'block';
                    execModalRawOutput = `[SSH] Connecting to ${proj.ssh_username}@${proj.ssh_host}:${proj.ssh_port}...\n`;
                    if (terminalOutput) {
                        terminalOutput.innerHTML = App.ansiToHtml(execModalRawOutput);
                    }
                    if (terminalStatus) {
                        terminalStatus.innerHTML = App.statusBadgeHtml('running');
                    }

                    const startT = performance.now();
                    if (terminalTimer) terminalTimer.textContent = '0.0s';
                    if (execModalTimerInterval) clearInterval(execModalTimerInterval);
                    execModalTimerInterval = setInterval(() => {
                        if (terminalTimer) {
                            terminalTimer.textContent = ((performance.now() - startT) / 1000).toFixed(1) + 's';
                        }
                    }, 100);

                    try {
                        const finalPacket = await App.streamExecute({
                            project_id: proj.id,
                            mode: 'adhoc_command',
                            adhoc_command: rawCmd
                        }, (pkt) => {
                            switch (pkt.type) {
                                case 'init':
                                case 'connected':
                                case 'aborted':
                                    execModalRawOutput += pkt.message + '\n';
                                    if (terminalOutput) {
                                        terminalOutput.innerHTML = App.ansiToHtml(execModalRawOutput);
                                        terminalOutput.scrollTop = terminalOutput.scrollHeight;
                                    }
                                    break;
                                case 'step_start':
                                    execModalRawOutput += pkt.message + '\n';
                                    if (terminalOutput) {
                                        terminalOutput.innerHTML = App.ansiToHtml(execModalRawOutput);
                                        terminalOutput.scrollTop = terminalOutput.scrollHeight;
                                    }
                                    break;
                                case 'chunk':
                                    execModalRawOutput += (pkt.data || '');
                                    if (terminalOutput) {
                                        terminalOutput.innerHTML = App.ansiToHtml(execModalRawOutput);
                                        terminalOutput.scrollTop = terminalOutput.scrollHeight;
                                    }
                                    break;
                                case 'step_end':
                                    const pfx = execModalRawOutput.endsWith('\n') ? '' : '\n';
                                    execModalRawOutput += pfx + pkt.message + '\n';
                                    if (terminalOutput) {
                                        terminalOutput.innerHTML = App.ansiToHtml(execModalRawOutput);
                                        terminalOutput.scrollTop = terminalOutput.scrollHeight;
                                    }
                                    break;
                                case 'error':
                                    execModalRawOutput += '\n[SSH ERROR] ' + pkt.message + '\n';
                                    if (terminalOutput) {
                                        terminalOutput.innerHTML = App.ansiToHtml(execModalRawOutput);
                                        terminalOutput.scrollTop = terminalOutput.scrollHeight;
                                    }
                                    break;
                                case 'complete':
                                    execModalRawOutput += '\n[' + pkt.status.toUpperCase() + '] ' + pkt.title + '\n';
                                    if (terminalOutput) {
                                        terminalOutput.innerHTML = App.ansiToHtml(execModalRawOutput);
                                        terminalOutput.scrollTop = terminalOutput.scrollHeight;
                                    }
                                    break;
                            }
                        });

                        if (execModalTimerInterval) {
                            clearInterval(execModalTimerInterval);
                            execModalTimerInterval = null;
                        }

                        if (finalPacket) {
                            if (terminalTimer && finalPacket.duration_ms !== undefined) {
                                terminalTimer.textContent = App.formatDuration(finalPacket.duration_ms);
                            }
                            if (terminalStatus) {
                                terminalStatus.innerHTML = App.statusBadgeHtml(finalPacket.status);
                            }
                            if (finalPacket.status === 'success') {
                                App.toast(`Command succeeded on "${proj.name}".`, 'success');
                            } else {
                                App.toast(`Command failed on "${proj.name}" (exit ${finalPacket.exit_code ?? 'non-zero'}).`, 'error');
                            }
                        } else {
                            if (terminalStatus) terminalStatus.innerHTML = App.statusBadgeHtml('info');
                        }
                    } catch (err) {
                        if (execModalTimerInterval) {
                            clearInterval(execModalTimerInterval);
                            execModalTimerInterval = null;
                        }
                        execModalRawOutput += '\n[ERROR] ' + err.message + '\n';
                        if (terminalOutput) {
                            terminalOutput.innerHTML = App.ansiToHtml(execModalRawOutput);
                        }
                        if (terminalStatus) {
                            terminalStatus.innerHTML = App.statusBadgeHtml('failed');
                        }
                        App.toast('Execution error: ' + err.message, 'error');
                    } finally {
                        execModalIsRunning = false;
                        submitBtn.disabled = false;
                        if (cancelBtn) cancelBtn.textContent = 'Done / Close';

                        // Refresh global events in background so newly run command appears in the Events tab
                        try {
                            const evRes = await App.apiGet('list_events', { limit: 30 });
                            if (evRes && evRes.items) {
                                globalEvents = evRes.items;
                                renderGlobalEvents();
                            }
                        } catch (ignore) {}
                    }
                });
            }

            return {
                open: openModalForCommand,
                populateProjects: populateProjectOptions
            };
        }

        // Initialize Command Directory and Aside Tabs
        cmdRunModal = initCommandRunModal();
        initAsideTabs();
        renderCommandFilters();
        renderCommandDirectory();

        const cmddirListEl = document.getElementById('cmddirList');
        if (cmddirListEl) {
            cmddirListEl.addEventListener('click', (e) => {
                const copyBtn = e.target.closest('[data-copy-cmd]');
                if (copyBtn) {
                    e.stopPropagation();
                    const cmd = copyBtn.dataset.copyCmd;
                    if (cmd) {
                        navigator.clipboard.writeText(cmd).then(() => {
                            App.toast('Command copied to clipboard.', 'success');
                        });
                    }
                    return;
                }

                const runBtn = e.target.closest('button[data-action="run-cmd"]');
                if (runBtn) {
                    e.stopPropagation();
                    const id = runBtn.dataset.id;
                    const asAdmin = runBtn.dataset.admin === 'true';
                    const allCommands = window.COMMAND_DIRECTORY || [];
                    const cmdObj = allCommands.find((c) => c.id === id);
                    if (cmdObj && cmdRunModal) {
                        cmdRunModal.open(cmdObj, asAdmin);
                    }
                }
            });
        }

        const cmdSearchInput = document.getElementById('cmddirSearchInput');
        if (cmdSearchInput) {
            cmdSearchInput.addEventListener('input', (e) => {
                cmdSearchQuery = e.target.value.trim();
                renderCommandDirectory();
            });
        }
    });
})();

