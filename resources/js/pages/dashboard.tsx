import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { CommandRunModal, type CommandRunRequest } from '@/components/dashboard/command-run-modal';
import type { DirectoryCommand } from '@/components/dashboard/commands-data';
import { DashboardAside } from '@/components/dashboard/dashboard-aside';
import type { CardAction, CardBusy } from '@/components/dashboard/project-card';
import { ProjectsGrid } from '@/components/dashboard/projects-grid';
import { useConfirm, useToast } from '@/components/feedback';
import AppLayout from '@/layouts/app-layout';
import { api } from '@/lib/api';
import { useDataChanged } from '@/lib/events';
import { escapeHtml, formatDuration } from '@/lib/format';
import { useModals } from '@/modals/modals-context';
import type { DashboardStats, EventLog, Project, SharedProps, SshProfile } from '@/types';

interface DashboardProps {
    projects: Project[];
    ssh_profiles: SshProfile[];
    stats: DashboardStats;
    events: EventLog[];
}

const ENV_FILTERS = [
    { env: 'all', label: 'All' },
    { env: 'production', label: 'Production' },
    { env: 'staging', label: 'Staging' },
    { env: 'development', label: 'Development' },
];

export default function Dashboard(props: DashboardProps) {
    return (
        <AppLayout title="Projects Dashboard">
            <DashboardPage {...props} />
        </AppLayout>
    );
}

/** Legacy index.php + assets/js/dashboard.js. */
function DashboardPage({ projects: initialProjects, ssh_profiles, events: initialEvents }: DashboardProps) {
    const { app } = usePage<SharedProps>().props;
    const toast = useToast();
    const confirm = useConfirm();
    const modals = useModals();
    const { setAvailableProjects, setAvailableProfiles } = modals;

    const [projects, setProjects] = useState<Project[]>(initialProjects || []);
    const [events, setEvents] = useState<EventLog[]>(initialEvents || []);
    const [activeEnv, setActiveEnv] = useState('all');
    const [search, setSearch] = useState('');
    const [expandedIds, setExpandedIds] = useState<Set<number>>(() => new Set());
    const [busy, setBusy] = useState<Record<number, CardBusy>>({});
    const [checkingHealth, setCheckingHealth] = useState(false);
    const [cmdRun, setCmdRun] = useState<CommandRunRequest | null>(null);

    const scrollPaneRef = useRef<HTMLDivElement>(null);

    // Keep the project modal's template / profile lists in sync (legacy setAvailableProjects / Profiles).
    useEffect(() => {
        setAvailableProjects(projects);
    }, [projects, setAvailableProjects]);

    useEffect(() => {
        setAvailableProfiles(ssh_profiles || []);
    }, [ssh_profiles, setAvailableProfiles]);

    const setCardBusy = (id: number, patch: CardBusy) => setBusy((b) => ({ ...b, [id]: { ...b[id], ...patch } }));

    const refreshAll = useCallback(async () => {
        try {
            const [projData, evData] = await Promise.all([api.listProjects(), api.listEvents({ limit: 30 })]);
            setProjects(projData.projects || []);
            setEvents(evData.items || []);
        } catch (err) {
            toast((err as Error).message, 'error');
        }
    }, [toast]);

    const refreshEvents = useCallback(async () => {
        try {
            const evRes = await api.listEvents({ limit: 30 });
            if (evRes && evRes.items) setEvents(evRes.items);
        } catch {
            /* ignore */
        }
    }, []);

    useDataChanged(() => {
        refreshAll();
    });

    const downCount = projects.filter((p) => p.app_status === 'down').length;

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return projects.filter((p) => {
            if (activeEnv === 'app-down') {
                if (p.app_status !== 'down') return false;
            } else if (activeEnv !== 'all' && p.environment !== activeEnv) {
                return false;
            }
            if (!q) return true;
            const hay = [p.name, p.ssh_host, p.ssh_username, p.remote_path, p.repo_branch, p.description].filter(Boolean).join(' ').toLowerCase();
            return hay.includes(q);
        });
    }, [projects, activeEnv, search]);

    const openAddModal = () => modals.openProjectModal(null, () => refreshAll());

    const checkAllHealth = async () => {
        setCheckingHealth(true);
        try {
            const res = await api.checkAllHealth();
            if (Array.isArray(res.projects)) setProjects(res.projects);
            if (res.checked === 0) {
                toast('No projects have a Health Check URL configured yet.', 'info');
            } else if (res.unhealthy > 0) {
                toast(`Health check: ${res.healthy} healthy, ${res.unhealthy} unhealthy!`, 'warning', 5000);
            } else {
                toast(`All ${res.healthy} monitored endpoint(s) are healthy (HTTP 2xx/3xx)!`, 'success');
            }
        } catch (err) {
            toast('Health check failed: ' + (err as Error).message, 'error');
        } finally {
            setCheckingHealth(false);
        }
    };

    const commitOrder = async (next: Project[]) => {
        const previous = projects;
        setProjects(next);
        try {
            const res = await api.reorderProjects(next.map((p) => p.id));
            if (Array.isArray(res.projects)) setProjects(res.projects);
            toast('Project priority order saved.', 'success', 2000);
        } catch (err) {
            setProjects(previous);
            toast('Failed to save priority order: ' + (err as Error).message, 'error');
        }
    };

    const toggleDetails = (id: number) =>
        setExpandedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });

    const runPipeline = async (project: Project) => {
        const id = project.id;
        if ((project.enabled_command_count || 0) === 0) {
            toast(`No enabled commands found in "${project.name}". Click "Manage & Deploy" to configure commands first.`, 'warning');
            return;
        }
        const isProd = project.environment === 'production';
        const confirmed = await confirm({
            title: 'Run Deployment Pipeline?',
            subtitle: project.name,
            message: `You are about to execute all <strong>${project.enabled_command_count} active command(s)</strong> on remote host <strong>${escapeHtml(project.ssh_username)}@${escapeHtml(project.ssh_host)}</strong> in directory <code>${escapeHtml(project.remote_path || '~')}</code>.`,
            warning: isProd ? 'Caution: This targets your PRODUCTION environment. Live traffic and database changes may take effect.' : undefined,
            confirmText: 'Run All Commands',
            confirmClass: 'btn-deploy',
            icon: '▶',
            iconVariant: 'deploy',
        });
        if (!confirmed) return;

        setCardBusy(id, { run: true });
        toast(`Running deployment pipeline for "${project.name}"...`, 'info', 3000);
        try {
            const finalPacket = await api.streamExecute(id, { mode: 'deploy_all' });
            if (finalPacket && finalPacket.status === 'success') {
                toast(`Deployment succeeded for "${project.name}" (${formatDuration(finalPacket.duration_ms as number)})`, 'success');
            } else if (finalPacket && finalPacket.status === 'failed') {
                toast(`Deployment failed for "${project.name}" (exit ${finalPacket.exit_code ?? 'non-zero'})`, 'error');
            } else {
                toast(`Deployment finished for "${project.name}".`, 'info');
            }
        } catch (err) {
            toast(`Deployment failed for "${project.name}": ` + (err as Error).message, 'error');
        } finally {
            await refreshAll();
            setCardBusy(id, { run: false });
        }
    };

    const toggleAppState = async (project: Project, requested?: string) => {
        const id = project.id;
        const targetState = requested || (project.app_status === 'down' ? 'up' : 'down');
        const actionLabel = targetState === 'down' ? 'DOWN (Maintenance Mode)' : 'UP (Live)';
        const confirmed = await confirm({
            title: `Mark App as ${targetState.toUpperCase()}?`,
            subtitle: project.name,
            message: `Change application status flag to <strong>${actionLabel}</strong>?`,
            warning:
                targetState === 'down'
                    ? 'This highlights the project card as DOWN across the dashboard.'
                    : 'This clears the DOWN alert and marks the project as live.',
            confirmText: `Set as ${targetState.toUpperCase()}`,
            confirmClass: targetState === 'down' ? 'btn-danger' : 'btn-success',
            icon: targetState === 'down' ? '🔴' : '🟢',
            iconVariant: targetState === 'down' ? 'danger' : 'success',
        });
        if (!confirmed) return;

        setCardBusy(id, { toggleState: true });
        try {
            await api.setAppState(id, targetState);
            toast(`Project "${project.name}" marked as ${targetState.toUpperCase()}.`, 'success');
            await refreshAll();
        } catch (err) {
            toast('Failed to change app state: ' + (err as Error).message, 'error');
        } finally {
            setCardBusy(id, { toggleState: false });
        }
    };

    const testSsh = async (project: Project) => {
        const id = project.id;
        setCardBusy(id, { testSsh: true });
        try {
            const res = await api.testSsh({ project_id: id });
            toast(res.result.message, res.result.path_ok ? 'success' : 'warning');
            await refreshAll();
        } catch (err) {
            toast('SSH Test Failed: ' + (err as Error).message, 'error');
            await refreshAll();
        } finally {
            setCardBusy(id, { testSsh: false });
        }
    };

    const deleteProject = async (project: Project) => {
        const id = project.id;
        const confirmed = await confirm({
            title: 'Delete Project?',
            subtitle: project.name,
            message: `Are you sure you want to permanently delete <strong>${escapeHtml(project.name)}</strong> and all its command definitions, deployment logs, and saved SSH credentials?`,
            warning: 'This action cannot be undone.',
            confirmText: 'Delete Project',
            confirmClass: 'btn-danger',
            icon: '🗑',
            iconVariant: 'danger',
        });
        if (!confirmed) return;

        setCardBusy(id, { delete: true });
        try {
            await api.deleteProject(id);
            toast(`Project "${project.name}" deleted.`, 'info');
            await refreshAll();
        } catch (err) {
            toast((err as Error).message, 'error');
        } finally {
            setCardBusy(id, { delete: false });
        }
    };

    const onCardAction = (action: CardAction, project: Project, targetState?: string) => {
        switch (action) {
            case 'run':
                return runPipeline(project);
            case 'toggle-state':
                return toggleAppState(project, targetState);
            case 'edit':
                return modals.openProjectModal(project, () => refreshAll());
            case 'pulse':
                return modals.openServerPulseModal(project);
            case 'env':
                return modals.openRemoteEnvModal(project);
            case 'releases':
                return modals.openReleasesModal(project, () => refreshAll());
            case 'remote-logs':
                return modals.openRemoteLogsModal(project);
            case 'duplicate':
                return modals.openDuplicateModal(project, () => refreshAll());
            case 'ssh':
                return modals.openProjectModal(project, () => refreshAll(), { focusSsh: true });
            case 'test-ssh':
                return testSsh(project);
            case 'delete':
                return deleteProject(project);
        }
    };

    const openCommandRun = (command: DirectoryCommand, asAdmin: boolean) => setCmdRun({ command, asAdmin });

    return (
        <main className="container">
            <div className="mobile-tagline-strip">{app.tagline}</div>

            <div className="dashboard-layout">
                {/* Left Side: Project Content Area */}
                <div className="dashboard-main" ref={scrollPaneRef}>
                    <section className="toolbar">
                        <div className="toolbar-left">
                            <input
                                type="search"
                                className="search-input"
                                id="projectSearchInput"
                                placeholder="Search projects, SSH hosts, paths..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <div className="filter-pills" id="envFilterPills">
                                {ENV_FILTERS.map((f) => (
                                    <button
                                        key={f.env}
                                        type="button"
                                        className={'filter-pill' + (activeEnv === f.env ? ' active' : '')}
                                        data-env={f.env}
                                        onClick={() => setActiveEnv(f.env)}
                                    >
                                        {f.label}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    className={'filter-pill filter-pill-down' + (downCount > 0 ? ' has-down' : '') + (activeEnv === 'app-down' ? ' active' : '')}
                                    data-env="app-down"
                                    onClick={() => setActiveEnv('app-down')}
                                >
                                    🔴 Down (<span id="downFilterCount">{downCount}</span>)
                                </button>
                            </div>
                        </div>
                        <div className="toolbar-right" style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                            <button
                                type="button"
                                className="btn btn-secondary btn-lg"
                                id="checkAllHealthBtn"
                                title="Ping all projects with configured Health Check URLs"
                                disabled={checkingHealth}
                                onClick={checkAllHealth}
                            >
                                {checkingHealth ? (
                                    <>
                                        <span>⏳</span>
                                        <span>Checking...</span>
                                    </>
                                ) : (
                                    <>
                                        <span>🩺</span>
                                        <span>Health Check All</span>
                                    </>
                                )}
                            </button>
                            <button type="button" className="btn btn-primary btn-lg toolbar-add-btn" id="addProjectBtn" onClick={openAddModal}>
                                <span>+</span>
                                <span>Add Project</span>
                            </button>
                        </div>
                    </section>

                    <ProjectsGrid
                        projects={projects}
                        filtered={filtered}
                        expandedIds={expandedIds}
                        busy={busy}
                        onToggleDetails={toggleDetails}
                        onAction={onCardAction}
                        onAddFirst={openAddModal}
                        onCommitOrder={commitOrder}
                        scrollPaneRef={scrollPaneRef}
                    />
                </div>

                {/* Right Side: Recent Global Event Log & Developer Command Directory Tabs */}
                <DashboardAside events={events} onRefresh={refreshAll} onRunCommand={openCommandRun} />
            </div>

            {cmdRun && <CommandRunModal request={cmdRun} projects={projects} onClose={() => setCmdRun(null)} onFinished={refreshEvents} />}
        </main>
    );
}
