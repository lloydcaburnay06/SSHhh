import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { CommandCardHandlers } from '@/components/project/command-card';
import { type CommandPreset, CommandsPanel } from '@/components/project/commands-panel';
import { EventLogPanel, useProjectEvents } from '@/components/project/event-log-panel';
import { CONSOLE_READY_TEXT, LiveConsole } from '@/components/project/live-console';
import { ProjectHero } from '@/components/project/project-hero';
import { cloneCmd, type EditableCommand, newUid, type StepState, toPayload } from '@/components/project/types';
import { useConfirm, useToast } from '@/components/feedback';
import AppLayout from '@/layouts/app-layout';
import { api, url } from '@/lib/api';
import { useDataChanged } from '@/lib/events';
import { copyText, escapeHtml, formatDuration } from '@/lib/format';
import { useModals } from '@/modals/modals-context';
import type { EventQueryResult, ExecPacket, Project, ProjectCommand, SshProfile } from '@/types';

interface ProjectPageProps {
    project: Project;
    commands: ProjectCommand[];
    all_projects: Project[];
    ssh_profiles: SshProfile[];
    events: EventQueryResult;
}

type ExecMode = 'deploy_all' | 'single_command' | 'adhoc_command';

const UNSAVED_LEAVE_MESSAGE = 'You have unsaved command edits. Leave this page and discard them?';

export default function ProjectPage(props: ProjectPageProps) {
    return (
        <AppLayout title={props.project.name}>
            {/* Keyed so switching to another project (e.g. after Duplicate) starts with fresh state */}
            <ProjectView key={props.project.id} {...props} />
        </AppLayout>
    );
}

function ProjectView({ project: initialProject, commands: initialCommands, all_projects, ssh_profiles, events: initialEvents }: ProjectPageProps) {
    const toast = useToast();
    const confirm = useConfirm();
    const modals = useModals();
    const { setAvailableProjects, setAvailableProfiles } = modals;

    const [project, setProjectState] = useState<Project>(initialProject);
    const projectRef = useRef(project);
    const allProjectsRef = useRef<Project[]>(Array.isArray(all_projects) ? all_projects.slice() : [initialProject]);

    const [commands, setCommandsState] = useState<EditableCommand[]>(() => (Array.isArray(initialCommands) ? initialCommands.map(cloneCmd) : []));
    const commandsRef = useRef(commands);
    const [stepStates, setStepStates] = useState<Record<number, StepState>>({});
    const [focusUid, setFocusUid] = useState<number | null>(null);

    const [isDirty, setIsDirty] = useState(false);
    const dirtyRef = useRef(false);
    const [isExecuting, setIsExecuting] = useState(false);
    const executingRef = useRef(false);
    const [isSaving, setIsSaving] = useState(false);
    const [togglingState, setTogglingState] = useState(false);
    const [testingSsh, setTestingSsh] = useState(false);

    const [consoleText, setConsoleTextState] = useState<string | null>(null);
    const consoleRef = useRef('');
    const [consoleStatus, setConsoleStatus] = useState<string | null>(null);
    const [timer, setTimer] = useState('');
    const timerInterval = useRef<number | null>(null);
    const liveAttachTimer = useRef<number | null>(null);
    const terminalPanelRef = useRef<HTMLDivElement>(null);

    const eventsApi = useProjectEvents(initialProject.id, initialEvents);
    const { loadEvents } = eventsApi;

    // ----- state helpers ---------------------------------------------------

    const setProject = (p: Project) => {
        projectRef.current = p;
        setProjectState(p);
    };

    const setCommands = (next: EditableCommand[] | ((prev: EditableCommand[]) => EditableCommand[])) => {
        const value = typeof next === 'function' ? next(commandsRef.current) : next;
        commandsRef.current = value;
        setCommandsState(value);
    };

    const setDirty = (dirty: boolean) => {
        dirtyRef.current = dirty;
        setIsDirty(dirty);
    };

    const syncAvailableProjectsState = useCallback(
        (updated: Project | null = null) => {
            const list = allProjectsRef.current;
            if (updated && updated.id) {
                const idx = list.findIndex((p) => Number(p.id) === Number(updated.id));
                if (idx !== -1) list[idx] = updated;
                else list.unshift(updated);
            }
            setAvailableProjects(list.slice());
        },
        [setAvailableProjects],
    );

    const setConsole = (text: string) => {
        consoleRef.current = text;
        setConsoleTextState(text);
    };

    const appendConsole = (chunk: string) => {
        consoleRef.current += chunk;
        setConsoleTextState(consoleRef.current);
    };

    const stopTimer = (finalMs: number | null = null) => {
        if (timerInterval.current !== null) {
            window.clearInterval(timerInterval.current);
            timerInterval.current = null;
        }
        if (finalMs !== null && finalMs !== undefined) setTimer(formatDuration(finalMs));
    };

    const startTimer = () => {
        stopTimer();
        const started = performance.now();
        setTimer('0.0s');
        timerInterval.current = window.setInterval(() => {
            setTimer(((performance.now() - started) / 1000).toFixed(1) + 's');
        }, 100);
    };

    const setExecutionLock = (locked: boolean, status: string | null = null) => {
        executingRef.current = locked;
        setIsExecuting(locked);
        setConsoleStatus(status);
    };

    // ----- mount: share lists with the modals, re-attach to a running deploy ----

    const pollLiveRunOnce = async () => {
        try {
            const res = await api.getLiveRun(projectRef.current.id);
            if (!res) return;
            if (res.project) {
                setProject(res.project);
                syncAvailableProjectsState(res.project);
            }
            const ev = res.event;
            if (ev && typeof ev.output_log === 'string') {
                setConsole(ev.output_log + '\n');
            }
            if (ev && Array.isArray(ev.steps)) {
                const states: Record<number, StepState> = {};
                ev.steps.forEach((s) => {
                    if (s && s.command_id) {
                        const idx = commandsRef.current.findIndex((c) => Number(c.id) === Number(s.command_id));
                        if (idx !== -1) states[idx] = s.status === 'success' ? 'success' : 'failed';
                    }
                });
                setStepStates(states);
            }
            if (!ev || ev.status !== 'running') {
                if (liveAttachTimer.current !== null) {
                    window.clearInterval(liveAttachTimer.current);
                    liveAttachTimer.current = null;
                }
                const finalStatus = ev ? ev.status : projectRef.current.last_deploy_status || 'info';
                stopTimer(ev ? ev.duration_ms : null);
                setExecutionLock(false, finalStatus);
                await loadEvents();
                if (ev && ev.title) toast(ev.title, finalStatus === 'success' ? 'success' : 'error');
            }
        } catch {
            // Silently retry next poll interval
        }
    };
    const pollRef = useRef(pollLiveRunOnce);
    pollRef.current = pollLiveRunOnce;

    useEffect(() => {
        syncAvailableProjectsState(projectRef.current);
        if (Array.isArray(ssh_profiles)) setAvailableProfiles(ssh_profiles.slice());

        // attachToLiveRunIfRunning()
        if (projectRef.current.last_deploy_status === 'running' && !executingRef.current) {
            setExecutionLock(true, 'running');
            startTimer();
            toast('Re-attached to active deployment stream.', 'info');
            void pollRef.current();
            liveAttachTimer.current = window.setInterval(() => void pollRef.current(), 1000);
        }

        return () => {
            if (liveAttachTimer.current !== null) window.clearInterval(liveAttachTimer.current);
            if (timerInterval.current !== null) window.clearInterval(timerInterval.current);
            liveAttachTimer.current = null;
            timerInterval.current = null;
            executingRef.current = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useDataChanged(async () => {
        try {
            const res = await api.getProject(projectRef.current.id);
            if (res && res.project) {
                setProject(res.project);
                syncAvailableProjectsState(res.project);
            }
        } catch {
            /* ignore */
        }
    });

    // ----- unsaved-changes guard (browser unload + Inertia navigation) -----

    useEffect(() => {
        const onBeforeUnload = (e: BeforeUnloadEvent) => {
            if (dirtyRef.current) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', onBeforeUnload);
        const offBefore = router.on('before', (event) => {
            if (!dirtyRef.current || event.detail.visit.prefetch) return;
            if (!window.confirm(UNSAVED_LEAVE_MESSAGE)) return false;
            // Leaving: don't ask again when the page unloads
            dirtyRef.current = false;
        });
        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            offBefore();
        };
    }, []);

    // ----- commands: save + execution --------------------------------------

    const saveCommandsExplicit = async (): Promise<boolean> => {
        const list = commandsRef.current;
        for (let i = 0; i < list.length; i++) {
            if (!list[i].command || !list[i].command.trim()) {
                toast(`Step #${i + 1} cannot have an empty command.`, 'warning');
                return false;
            }
        }

        setIsSaving(true);
        try {
            const res = await api.saveCommands(projectRef.current.id, toPayload(list));
            setCommands((res.commands || []).map(cloneCmd));
            if (res.project) {
                setProject(res.project);
                syncAvailableProjectsState(res.project);
            }
            setDirty(false);
            toast('Deployment commands saved.', 'success');
            await loadEvents();
            return true;
        } catch (err) {
            toast((err as Error).message, 'error');
            return false;
        } finally {
            setIsSaving(false);
        }
    };
    const saveRef = useRef(saveCommandsExplicit);
    saveRef.current = saveCommandsExplicit;

    // Ctrl+S / Cmd+S shortcut to save commands (not while a modal is open)
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                const modalOpen = document.querySelector('.modal-backdrop.open') !== null;
                if (!modalOpen) {
                    e.preventDefault();
                    if (!executingRef.current) void saveRef.current();
                }
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);

    const runExecution = async (mode: ExecMode, stepIndex: number | null = null, adhocCommand: string | null = null) => {
        if (executingRef.current) return;
        const list = commandsRef.current;
        const proj = projectRef.current;

        // Validate non-empty commands if syncing pipeline
        if (mode !== 'adhoc_command') {
            for (let i = 0; i < list.length; i++) {
                if (!list[i].command || !list[i].command.trim()) {
                    toast(`Step #${i + 1} has an empty shell command. Fill it in or remove the step first.`, 'warning');
                    return;
                }
            }
        }

        // Map enabled step numbers back to commands indices for live highlighting in deploy_all mode
        const enabledIndices: number[] = [];
        if (mode === 'deploy_all') {
            list.forEach((c, idx) => {
                if (c.is_enabled) enabledIndices.push(idx);
            });
            if (enabledIndices.length === 0) {
                toast('Enable or add at least one command before deploying.', 'warning');
                return;
            }
        }

        setStepStates(mode === 'single_command' && stepIndex !== null ? { [stepIndex]: 'running' } : {});
        setConsole('');
        setExecutionLock(true, 'running');
        startTimer();

        if (window.innerWidth <= 1180) {
            terminalPanelRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        const needsSync = mode !== 'adhoc_command' && (dirtyRef.current || list.some((c) => !c.id));
        const payload = {
            project_id: proj.id,
            mode,
            command_id: stepIndex !== null && list[stepIndex] ? list[stepIndex].id : null,
            step_index: stepIndex,
            adhoc_command: adhocCommand,
            commands: needsSync ? toPayload(list) : undefined,
        };

        const targetIndex = (pkt: ExecPacket): number | null | undefined =>
            mode === 'deploy_all' ? enabledIndices[(Number(pkt.step_number) || 1) - 1] : stepIndex;

        try {
            const finalPacket = await api.streamExecute(proj.id, payload, (pkt) => {
                switch (pkt.type) {
                    case 'init':
                    case 'connected':
                    case 'aborted':
                        appendConsole(String(pkt.message) + '\n');
                        break;

                    case 'step_start': {
                        appendConsole(String(pkt.message) + '\n');
                        const idx = targetIndex(pkt);
                        if (idx !== undefined && idx !== null) setStepStates((s) => ({ ...s, [idx]: 'running' }));
                        break;
                    }

                    case 'chunk':
                        appendConsole(String(pkt.data || ''));
                        break;

                    case 'step_end': {
                        const prefix = consoleRef.current.endsWith('\n') ? '' : '\n';
                        appendConsole(prefix + String(pkt.message) + '\n');
                        const idx = targetIndex(pkt);
                        if (idx !== undefined && idx !== null) {
                            setStepStates((s) => ({ ...s, [idx]: pkt.exit_code === 0 ? 'success' : 'failed' }));
                        }
                        if (pkt.app_status) {
                            setProject({ ...projectRef.current, app_status: String(pkt.app_status) });
                        }
                        break;
                    }

                    case 'error':
                        appendConsole('\n[SSH ERROR] ' + String(pkt.message) + '\n');
                        break;

                    case 'complete':
                        appendConsole('\n[' + String(pkt.status).toUpperCase() + '] ' + String(pkt.title) + '\n');
                        break;
                }
            });

            if (finalPacket) {
                const fin = finalPacket as ExecPacket & { project?: Project; commands?: ProjectCommand[]; duration_ms?: number; status: string; title: string };
                if (fin.project) setProject(fin.project);
                if (Array.isArray(fin.commands)) setCommands(fin.commands.map(cloneCmd));
                if (needsSync) setDirty(false);
                stopTimer(fin.duration_ms ?? null);
                setExecutionLock(false, fin.status);
                toast(fin.title, fin.status === 'success' ? 'success' : 'error');
            } else {
                stopTimer();
                setExecutionLock(false, 'info');
            }
        } catch (err) {
            stopTimer();
            appendConsole('\n[ERROR] ' + (err as Error).message + '\n');
            setExecutionLock(false, 'failed');
            toast((err as Error).message, 'error');
        } finally {
            await loadEvents();
        }
    };

    // ----- hero actions -----------------------------------------------------

    const sshTarget = () => `<strong>${escapeHtml(project.ssh_username)}@${escapeHtml(project.ssh_host)}</strong>`;

    const onDeployAll = async () => {
        if (executingRef.current) return;
        const enabledCount = commandsRef.current.filter((c) => c.is_enabled).length;
        if (enabledCount === 0) {
            toast('Enable or add at least one command before deploying.', 'warning');
            return;
        }
        const confirmed = await confirm({
            title: 'Deploy Now (Execute All)?',
            subtitle: project.name,
            message: `You are about to run all <strong>${enabledCount} active command(s)</strong> sequentially on remote host ${sshTarget()} in directory <code>${escapeHtml(project.remote_path || '~')}</code>.`,
            warning:
                project.environment === 'production'
                    ? 'Warning: This will execute on your LIVE PRODUCTION environment. Service interruptions or database alterations may affect live users.'
                    : undefined,
            confirmText: 'Execute Deployment',
            confirmClass: 'btn-deploy',
            icon: '▶',
            iconVariant: 'deploy',
        });
        if (!confirmed) return;
        void runExecution('deploy_all');
    };

    const onToggleAppState = async () => {
        if (executingRef.current) return;
        const targetState = project.app_status === 'down' ? 'up' : 'down';
        const actionLabel = targetState === 'down' ? 'DOWN (Maintenance Mode)' : 'UP (Live)';
        const confirmed = await confirm({
            title: `Mark App as ${targetState.toUpperCase()}?`,
            subtitle: project.name,
            message: `Change project app status flag to <strong>${actionLabel}</strong>?`,
            warning:
                targetState === 'down'
                    ? 'This marks the project card as DOWN across the dashboard to alert team members.'
                    : 'This clears the DOWN alert and marks the project as live.',
            confirmText: `Set as ${targetState.toUpperCase()}`,
            confirmClass: targetState === 'down' ? 'btn-danger' : 'btn-success',
            icon: targetState === 'down' ? '🔴' : '🟢',
            iconVariant: targetState === 'down' ? 'danger' : 'success',
        });
        if (!confirmed) return;

        setTogglingState(true);
        try {
            const res = await api.setAppState(project.id, targetState);
            if (res.project) {
                setProject(res.project);
                syncAvailableProjectsState(res.project);
            }
            toast(`App status updated to ${targetState.toUpperCase()}.`, 'success');
            await loadEvents();
        } catch (err) {
            toast('Failed to update app status: ' + (err as Error).message, 'error');
        } finally {
            setTogglingState(false);
        }
    };

    const onDuplicate = () => {
        if (executingRef.current) return;
        if (dirtyRef.current) {
            toast('Note: Duplicating saved commands. Click "Save Commands" first if you want unsaved command edits included.', 'warning');
        }
        modals.openDuplicateModal(project, (newProj) => {
            if (newProj && newProj.id) {
                syncAvailableProjectsState(newProj);
                if (!dirtyRef.current) {
                    router.visit(url(`/projects/${encodeURIComponent(newProj.id)}`));
                } else {
                    toast(`Created duplicate "${newProj.name}". Save or discard current command edits before switching projects.`, 'info', 5500);
                }
            }
        });
    };

    const onEditSsh = () => {
        modals.openProjectModal(
            project,
            async (updated) => {
                if (updated) {
                    setProject(updated);
                    syncAvailableProjectsState(updated);
                }
                await loadEvents();
            },
            { mode: 'edit', focusSsh: true },
        );
    };

    const onTestSsh = async () => {
        if (executingRef.current) return;
        const proj = projectRef.current;
        setTestingSsh(true);
        setConsole(`Testing SSH connection to ${proj.ssh_username}@${proj.ssh_host}:${proj.ssh_port}...\n`);
        startTimer();

        try {
            const res = await api.testSsh({ project_id: proj.id });
            const r = res.result;
            if (res.project) setProject(res.project);
            stopTimer(r.duration_ms);
            setConsoleStatus(r.path_ok ? 'success' : 'warning');
            appendConsole(
                r.message +
                    '\n' +
                    (r.banner ? 'Server Banner: ' + r.banner + '\n' : '') +
                    (r.fingerprint ? 'Host Key: ' + r.fingerprint + '\n' : '') +
                    (r.probe_output ? '\n--- Remote Environment ---\n' + r.probe_output + '\n' : ''),
            );
            toast(r.message, r.path_ok ? 'success' : 'warning');
        } catch (err) {
            stopTimer();
            setConsoleStatus('failed');
            appendConsole('\n[SSH TEST FAILED] ' + (err as Error).message + '\n');
            toast('SSH Test Failed: ' + (err as Error).message, 'error');
        } finally {
            setTestingSsh(false);
            await loadEvents();
        }
    };

    const heroActions = {
        onToggleAppState,
        onDeployAll,
        onDuplicate,
        onEditSsh,
        onTestSsh,
        onPulse: () => {
            if (!executingRef.current) modals.openServerPulseModal(project);
        },
        onEnv: () => {
            if (!executingRef.current) modals.openRemoteEnvModal(project);
        },
        onRemoteLogs: () => {
            if (!executingRef.current) modals.openRemoteLogsModal(project);
        },
        onReleases: () => {
            if (executingRef.current) return;
            modals.openReleasesModal(project, async () => {
                await loadEvents();
            });
        },
    };

    // ----- command card editing --------------------------------------------

    const blankCommand = (overrides: Partial<EditableCommand>): EditableCommand => ({
        uid: newUid(),
        id: null,
        name: '',
        command: '',
        is_enabled: true,
        stop_on_error: true,
        app_state_trigger: 'none',
        last_exit_code: null,
        last_duration_ms: null,
        last_run_at: null,
        ...overrides,
    });

    const cardHandlers: CommandCardHandlers = {
        onText: (index, field, value) => {
            setCommands((list) =>
                list.map((c, i) => {
                    if (i !== index) return c;
                    const next = { ...c, [field]: value };
                    if (field === 'command' && next.app_state_trigger === 'none') {
                        const val = value.toLowerCase();
                        if (val.includes('artisan down') || val.includes('compose down')) next.app_state_trigger = 'down';
                        else if (val.includes('artisan up') || val.includes('compose up')) next.app_state_trigger = 'up';
                    }
                    return next;
                }),
            );
            setStepStates({});
            setDirty(true);
        },
        onToggle: (index, field, checked) => {
            setCommands((list) => list.map((c, i) => (i === index ? { ...c, [field]: checked } : c)));
            setDirty(true);
        },
        onTrigger: (index, trigger, checked) => {
            setCommands((list) => list.map((c, i) => (i === index ? { ...c, app_state_trigger: checked ? trigger : 'none' } : c)));
            setDirty(true);
        },
        onMove: (index, direction) => {
            if (executingRef.current) return;
            const target = index + direction;
            const list = commandsRef.current;
            if (target < 0 || target >= list.length) return;
            const next = list.slice();
            [next[index], next[target]] = [next[target], next[index]];
            setCommands(next);
            setStepStates({});
            setDirty(true);
        },
        onDelete: async (index) => {
            if (executingRef.current) return;
            const cmd = commandsRef.current[index];
            if (!cmd) return;
            if (cmd.command && cmd.command.trim()) {
                const confirmed = await confirm({
                    title: `Delete Step #${index + 1}?`,
                    subtitle: cmd.name || 'Unnamed Step',
                    message: 'Remove this command step from the deployment pipeline?',
                    code: cmd.command,
                    confirmText: 'Delete Step',
                    confirmClass: 'btn-danger',
                    icon: '🗑',
                    iconVariant: 'danger',
                });
                if (!confirmed) return;
            }
            setCommands((list) => list.filter((c) => c.uid !== cmd.uid));
            setStepStates({});
            setDirty(true);
        },
        onRun: async (index) => {
            if (executingRef.current) return;
            const cmd = commandsRef.current[index];
            if (!cmd) return;
            const cmdName = cmd.name || `Step #${index + 1}`;
            const confirmed = await confirm({
                title: `Run Step #${index + 1}?`,
                subtitle: project.name + ' • ' + cmdName,
                message: `Execute single command on ${sshTarget()}:`,
                code: cmd.command,
                warning: project.environment === 'production' ? 'Caution: Executing directly on PRODUCTION environment.' : undefined,
                confirmText: 'Run Step',
                confirmClass: 'btn-primary',
                icon: '▶',
                iconVariant: 'info',
            });
            if (!confirmed) return;
            void runExecution('single_command', index);
        },
    };

    const onAdd = () => {
        if (executingRef.current) return;
        const cmd = blankCommand({ name: 'Step #' + (commandsRef.current.length + 1) });
        setCommands((list) => [...list, cmd]);
        setFocusUid(cmd.uid);
        setStepStates({});
        setDirty(true);
    };

    const onPreset = (preset: CommandPreset) => {
        if (executingRef.current) return;
        setCommands((list) => [
            ...list,
            blankCommand({ name: preset.name || 'Command step', command: preset.cmd || '', app_state_trigger: preset.trigger || 'none' }),
        ]);
        setStepStates({});
        setDirty(true);
    };

    const onAdhoc = async (cmd: string) => {
        if (executingRef.current) return;
        const confirmed = await confirm({
            title: 'Run Quick Command?',
            subtitle: project.name,
            message: `Execute one-off command on remote host ${sshTarget()}:`,
            code: cmd,
            warning: project.environment === 'production' ? 'Caution: Running on PRODUCTION environment.' : undefined,
            confirmText: 'Run Once',
            confirmClass: 'btn-primary',
            icon: '⚡',
            iconVariant: 'warning',
        });
        if (!confirmed) return;
        void runExecution('adhoc_command', null, cmd);
    };

    // ----- console helpers --------------------------------------------------

    const onCopyConsole = async () => {
        const text = consoleRef.current || (consoleText === null ? CONSOLE_READY_TEXT : '');
        if (await copyText(text)) toast('Console output copied to clipboard.', 'success');
    };

    const onClearConsole = () => {
        if (executingRef.current) return;
        setConsole('Console cleared.\n');
        setConsoleStatus(null);
        setTimer('');
    };

    return (
        <main className="container">
            <Head title={project.name} />
            <nav className="breadcrumb">
                <Link href={url('/')}>&larr; All Projects</Link>
                <span>/</span>
                <strong id="breadcrumbProjectName">{project.name}</strong>
            </nav>

            <div className="project-layout">
                {/* Left Independently Scrollable Pane: Project Hero + Card/Grid Commands + Complete Event Log */}
                <div className="project-left-pane">
                    <ProjectHero project={project} isExecuting={isExecuting} togglingState={togglingState} testingSsh={testingSsh} actions={heroActions} />

                    <CommandsPanel
                        commands={commands}
                        stepStates={stepStates}
                        isDirty={isDirty}
                        isExecuting={isExecuting}
                        isSaving={isSaving}
                        focusUid={focusUid}
                        handlers={cardHandlers}
                        onAdd={onAdd}
                        onPreset={onPreset}
                        onSave={() => void saveCommandsExplicit()}
                        onAdhoc={onAdhoc}
                    />

                    <EventLogPanel project={project} events={eventsApi} />
                </div>

                {/* Right Independently Scrollable Pane: Live SSH Console */}
                <LiveConsole
                    text={consoleText}
                    status={consoleStatus}
                    timer={timer}
                    panelRef={terminalPanelRef}
                    onCopy={() => void onCopyConsole()}
                    onClear={onClearConsole}
                />
            </div>
        </main>
    );
}
