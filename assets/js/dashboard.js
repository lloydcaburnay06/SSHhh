(function () {
    'use strict';

    const App = window.DeployerApp;
    let projects = (window.INITIAL_DASHBOARD_DATA && window.INITIAL_DASHBOARD_DATA.projects) || [];
    let globalEvents = (window.INITIAL_DASHBOARD_DATA && window.INITIAL_DASHBOARD_DATA.events) || [];
    let activeEnv = 'all';
    let searchQuery = '';

    function updateStats(stats) {
        if (!stats) return;
        document.getElementById('statTotalProjects').textContent = stats.total_projects ?? 0;
        document.getElementById('statTotalDeploys').textContent = stats.total_deployments ?? 0;
        document.getElementById('statSuccessDeploys').textContent = stats.successful_deployments ?? 0;
        document.getElementById('statFailedDeploys').textContent = stats.failed_deployments ?? 0;
    }

    function renderProjects() {
        const container = document.getElementById('projectsContainer');
        const q = searchQuery.toLowerCase();

        const filtered = projects.filter((p) => {
            if (activeEnv !== 'all' && p.environment !== activeEnv) return false;
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

            return `
                <article class="project-card" data-project-id="${p.id}">
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
                        <span class="badge env-${App.escapeHtml(p.environment)}">${App.escapeHtml(p.environment)}</span>
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
            if (action === 'edit') {
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
                if (!window.confirm(`Delete project "${project.name}" and its saved SSH configuration?`)) {
                    return;
                }
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
    });
})();

