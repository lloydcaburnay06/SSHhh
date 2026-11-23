import { useCallback, useEffect, useRef, useState } from 'react';
import { StatusBadge } from '@/components/event-item';
import { useConfirm, useToast } from '@/components/feedback';
import { ModalShell } from '@/components/modal-shell';
import { api } from '@/lib/api';
import { ansiToHtml, copyText, escapeHtml, formatDuration } from '@/lib/format';
import type { Project } from '@/types';
import type { DirectoryCommand } from './commands-data';

const LAST_PROJECT_KEY = 'deployer_last_target_project_id';

const DIR_CHIPS: { value: string; label: string; title?: string }[] = [
    { value: 'root', label: 'Project Root', title: 'Use project root path' },
    { value: 'public', label: 'public' },
    { value: 'storage', label: 'storage' },
    { value: 'storage/logs', label: 'storage/logs' },
    { value: 'bootstrap/cache', label: 'bootstrap/cache' },
    { value: 'database', label: 'database' },
    { value: '~', label: '~ ($HOME)' },
];

type BrowserState =
    | { kind: 'message'; text: string }
    | { kind: 'error'; text: string }
    | { kind: 'connecting' }
    | { kind: 'listing'; parentDir: string | null; currentDir: string; dirs: string[] };

function readLastProjectId(): string | null {
    try {
        return localStorage.getItem(LAST_PROJECT_KEY);
    } catch {
        return null;
    }
}

function writeLastProjectId(id: number): void {
    try {
        localStorage.setItem(LAST_PROJECT_KEY, String(id));
    } catch {
        /* storage unavailable */
    }
}

function pickProjectId(projects: Project[], preferred: string | number | null): number | null {
    if (preferred !== null && projects.some((p) => String(p.id) === String(preferred))) return Number(preferred);
    return projects.length > 0 ? Number(projects[0].id) : null;
}

export interface CommandRunRequest {
    command: DirectoryCommand;
    asAdmin: boolean;
}

/**
 * Legacy command_run_modal.php + initCommandRunModal(): pick a project & folder,
 * edit the command, confirm, then stream the output into an embedded terminal.
 */
export function CommandRunModal({
    request,
    projects,
    onClose,
    onFinished,
}: {
    request: CommandRunRequest;
    projects: Project[];
    onClose: () => void;
    /** Called after each run (legacy refreshed the global events list). */
    onFinished: () => void;
}) {
    const toast = useToast();
    const confirm = useConfirm();

    const initialCmd = (() => {
        let cmd = request.command.command || '';
        if (request.asAdmin && !cmd.startsWith('sudo ')) cmd = 'sudo ' + cmd;
        return cmd;
    })();

    const [selectedId, setSelectedId] = useState<number | null>(() => pickProjectId(projects, readLastProjectId()));
    const project = projects.find((p) => Number(p.id) === selectedId) ?? null;

    const [dir, setDir] = useState(project ? project.remote_path || '~' : '');
    const [activeChip, setActiveChip] = useState<string | null>('root');
    const [browserOpen, setBrowserOpen] = useState(false);
    const [browserLoading, setBrowserLoading] = useState(false);
    const [browser, setBrowser] = useState<BrowserState>({ kind: 'message', text: '' });

    const [cmdText, setCmdText] = useState(initialCmd);
    const [sudo, setSudo] = useState(initialCmd.startsWith('sudo '));

    const [running, setRunning] = useState(false);
    const [hasRun, setHasRun] = useState(false);
    const [terminalVisible, setTerminalVisible] = useState(false);
    const [output, setOutput] = useState('Ready to execute.');
    const rawOutput = useRef('');
    const [termStatus, setTermStatus] = useState<string | null>(null);
    const [timer, setTimer] = useState('');
    const timerInterval = useRef<number | null>(null);

    const cmdRef = useRef<HTMLTextAreaElement>(null);
    const outputRef = useRef<HTMLPreElement>(null);

    // Keep the selection valid when the project list changes (legacy populateProjectOptions).
    useEffect(() => {
        if (!projects.some((p) => Number(p.id) === selectedId)) {
            const next = pickProjectId(projects, null);
            if (next !== selectedId) {
                setSelectedId(next);
                const proj = projects.find((p) => Number(p.id) === next);
                setDir(proj ? proj.remote_path || '~' : '');
                setActiveChip('root');
            }
        }
    }, [projects, selectedId]);

    useEffect(() => {
        if (project) writeLastProjectId(project.id);
    }, [project]);

    // Focus the command editor with the caret at the end (legacy 80ms after opening).
    useEffect(() => {
        const t = window.setTimeout(() => {
            const el = cmdRef.current;
            if (el) {
                el.focus();
                el.setSelectionRange(el.value.length, el.value.length);
            }
        }, 80);
        return () => window.clearTimeout(t);
    }, []);

    useEffect(() => {
        if (outputRef.current) outputRef.current.scrollTop = outputRef.current.scrollHeight;
    }, [output]);

    const stopTimer = () => {
        if (timerInterval.current !== null) {
            window.clearInterval(timerInterval.current);
            timerInterval.current = null;
        }
    };
    useEffect(() => stopTimer, []);

    const runningRef = useRef(running);
    runningRef.current = running;

    const requestClose = useCallback(() => {
        if (runningRef.current) {
            if (
                !window.confirm(
                    'Command is currently running on the server. Close console window? (The background task will continue on the remote host).',
                )
            ) {
                return;
            }
        }
        stopTimer();
        onClose();
    }, [onClose]);

    const loadRemoteDirs = async (path: string, proj: Project | null = project) => {
        if (!proj) {
            setBrowser({ kind: 'message', text: 'Select a target project first.' });
            return;
        }
        setBrowserLoading(true);
        setBrowser({ kind: 'connecting' });
        try {
            const res = await api.listRemoteDirs(proj.id, path || '');
            if (!res || !res.result) throw new Error('Failed to query remote directory');
            const data = res.result;
            setBrowser({
                kind: 'listing',
                parentDir: data.parent_dir ?? null,
                currentDir: String(data.current_dir ?? ''),
                dirs: Array.isArray(data.directories) ? data.directories.map(String) : [],
            });
        } catch (err) {
            setBrowser({ kind: 'error', text: 'Failed to scan remote directory: ' + (err as Error).message });
        } finally {
            setBrowserLoading(false);
        }
    };

    const changeProject = (id: number) => {
        setSelectedId(id);
        const proj = projects.find((p) => Number(p.id) === id) ?? null;
        const defaultDir = proj ? proj.remote_path || '~' : '~';
        setDir(defaultDir);
        setActiveChip('root');
        if (browserOpen) loadRemoteDirs(defaultDir, proj);
    };

    const pickChip = (chipVal: string) => {
        const baseDir = (project ? project.remote_path || '~' : '~').replace(/\/+$/, '');
        let newPath: string;
        if (chipVal === 'root') {
            newPath = project ? project.remote_path || '~' : '~';
        } else if (chipVal === '~') {
            newPath = '~';
        } else if (baseDir === '~' || baseDir === '') {
            newPath = chipVal;
        } else {
            newPath = baseDir + '/' + chipVal;
        }
        setDir(newPath);
        setActiveChip(chipVal);
        if (browserOpen) loadRemoteDirs(newPath);
    };

    const resetDir = () => {
        const defaultPath = project ? project.remote_path || '~' : '~';
        setDir(defaultPath);
        setActiveChip('root');
        if (browserOpen) loadRemoteDirs(defaultPath);
    };

    const toggleBrowser = () => {
        if (!browserOpen) {
            setBrowserOpen(true);
            loadRemoteDirs(dir.trim());
        } else {
            setBrowserOpen(false);
        }
    };

    const goToDir = (path: string) => {
        setDir(path);
        setActiveChip(null);
        loadRemoteDirs(path);
    };

    const enterFolder = (folder: string) => {
        const cur = dir.trim().replace(/\/+$/, '');
        goToDir(cur === '' || cur === '~' ? '~/' + folder : cur + '/' + folder);
    };

    const onSudoToggle = (checked: boolean) => {
        const raw = cmdText.trim();
        const hasSudo = raw.startsWith('sudo ');
        if (checked && !hasSudo) setCmdText('sudo ' + raw);
        else if (!checked && hasSudo) setCmdText(raw.replace(/^sudo\s+/, ''));
        setSudo(checked);
    };

    const onCmdInput = (value: string) => {
        setCmdText(value);
        setSudo(value.trim().startsWith('sudo '));
    };

    const appendOutput = (text: string) => {
        rawOutput.current += text;
        setOutput(rawOutput.current);
    };

    const submit = async () => {
        if (running) return;
        const proj = project;
        if (!proj) {
            toast('Please select a target project first.', 'warning');
            return;
        }
        const rawCmd = cmdText.trim();
        if (!rawCmd) {
            toast('Command text cannot be empty.', 'warning');
            cmdRef.current?.focus();
            return;
        }

        const targetDir = dir.trim() || proj.remote_path || '~';
        const isAdmin = sudo;
        const isProd = proj.environment === 'production';

        const confirmed = await confirm({
            title: isAdmin ? 'Run Command as Admin (sudo)?' : 'Confirm Remote Execution',
            subtitle: `${proj.name} [${proj.environment.toUpperCase()}] • ${proj.ssh_username}@${proj.ssh_host}`,
            message: `You are about to execute the command below in directory <code>${escapeHtml(targetDir)}</code> on server <strong>${escapeHtml(proj.ssh_username)}@${escapeHtml(proj.ssh_host)}</strong>:`,
            code: rawCmd,
            warning: isProd
                ? 'Caution: You are executing directly on PRODUCTION. Live user traffic or databases may be impacted.'
                : isAdmin
                  ? 'Notice: This command runs with elevated root privileges (sudo).'
                  : undefined,
            confirmText: isAdmin ? 'Execute with sudo' : 'Execute Command',
            confirmClass: isProd || isAdmin ? 'btn-danger' : 'btn-primary',
            icon: isAdmin ? '⚡' : '▶',
            iconVariant: isProd || isAdmin ? 'danger' : 'deploy',
        });
        if (!confirmed) return;

        setRunning(true);
        setHasRun(true);
        setTerminalVisible(true);
        rawOutput.current = '';
        appendOutput(`[SSH] Connecting to ${proj.ssh_username}@${proj.ssh_host}:${proj.ssh_port}...\n[DIR] Working directory: ${targetDir}\n`);
        setTermStatus('running');

        const startT = performance.now();
        setTimer('0.0s');
        stopTimer();
        timerInterval.current = window.setInterval(() => {
            setTimer(((performance.now() - startT) / 1000).toFixed(1) + 's');
        }, 100);

        try {
            const finalPacket = await api.streamExecute(
                proj.id,
                { mode: 'adhoc_command', adhoc_command: rawCmd, custom_directory: targetDir },
                (pkt) => {
                    switch (pkt.type) {
                        case 'init':
                        case 'connected':
                        case 'aborted':
                        case 'step_start':
                            appendOutput(String(pkt.message ?? '') + '\n');
                            break;
                        case 'chunk':
                            appendOutput(String(pkt.data || ''));
                            break;
                        case 'step_end':
                            appendOutput((rawOutput.current.endsWith('\n') ? '' : '\n') + String(pkt.message ?? '') + '\n');
                            break;
                        case 'error':
                            appendOutput('\n[SSH ERROR] ' + String(pkt.message ?? '') + '\n');
                            break;
                        case 'complete':
                            appendOutput('\n[' + String(pkt.status ?? '').toUpperCase() + '] ' + String(pkt.title ?? '') + '\n');
                            break;
                    }
                },
            );

            stopTimer();
            if (finalPacket) {
                if (finalPacket.duration_ms !== undefined) setTimer(formatDuration(finalPacket.duration_ms as number));
                setTermStatus(String(finalPacket.status));
                if (finalPacket.status === 'success') {
                    toast(`Command succeeded on "${proj.name}".`, 'success');
                } else {
                    toast(`Command failed on "${proj.name}" (exit ${finalPacket.exit_code ?? 'non-zero'}).`, 'error');
                }
            } else {
                setTermStatus('info');
            }
        } catch (err) {
            stopTimer();
            appendOutput('\n[ERROR] ' + (err as Error).message + '\n');
            setTermStatus('failed');
            toast('Execution error: ' + (err as Error).message, 'error');
        } finally {
            setRunning(false);
            onFinished();
        }
    };

    const copyOutput = async () => {
        const text = rawOutput.current || output;
        if (await copyText(text)) toast('Console output copied.', 'success');
    };

    const clearOutput = () => {
        if (running) return;
        rawOutput.current = '';
        setOutput('Cleared.\n');
        setTimer('');
        setTermStatus(null);
    };

    return (
        <ModalShell id="commandRunModal" className="command-run-modal" labelledBy="execModalTitle" onRequestClose={requestClose}>
            <div className="modal-header command-run-modal-header">
                <div className="exec-modal-title-group">
                    <div className="confirm-icon-bubble variant-deploy" id="execModalIcon">
                        ⚡
                    </div>
                    <div>
                        <h3 id="execModalTitle" className="modal-title">
                            Execute Remote Command
                        </h3>
                        <p id="execModalSubtitle" className="exec-modal-subtitle">
                            Review, edit, and confirm before running on the target server
                        </p>
                    </div>
                </div>
                <button type="button" className="icon-btn" title="Close" onClick={requestClose}>
                    &times;
                </button>
            </div>

            <div className="modal-body command-run-modal-body">
                {/* Project Target Selector */}
                <div className="form-group" style={{ marginBottom: 14 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                        <label htmlFor="execModalProjectSelect" className="form-label" style={{ marginBottom: 0 }}>
                            1. Target Project &amp; Server
                        </label>
                        <span id="execModalEnvBadge" className={project ? 'badge env-' + project.environment : 'badge'}>
                            {project?.environment}
                        </span>
                    </div>
                    <select
                        id="execModalProjectSelect"
                        className="form-select"
                        value={selectedId ?? ''}
                        onChange={(e) => changeProject(Number(e.target.value))}
                    >
                        {projects.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name} [{p.environment.toUpperCase()}] &bull; {p.ssh_username}@{p.ssh_host}
                            </option>
                        ))}
                    </select>
                    <div className="exec-project-meta" id="execProjectMeta">
                        <span id="execProjectTarget">{project ? `${project.ssh_username}@${project.ssh_host}:${project.ssh_port}` : ''}</span>
                        <span id="execProjectDefaultDir">{project ? `default: ${project.remote_path || '~'}` : ''}</span>
                    </div>
                    <div className="exec-prod-warning" id="execProdWarning" style={{ display: project?.environment === 'production' ? 'flex' : 'none' }}>
                        <span style={{ fontSize: 18 }}>⚠️</span>
                        <div>
                            <strong>CAUTION: Production Environment Selected</strong>
                            <p style={{ margin: '2px 0 0', fontSize: 12, color: '#fecaca' }}>
                                This command will execute directly against your live production server.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Working Directory / Folder Browser */}
                <div className="form-group" style={{ marginBottom: 14 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                        <label htmlFor="execModalDirInput" className="form-label" style={{ marginBottom: 0 }}>
                            2. Target Directory (Folder to run inside)
                        </label>
                        <button
                            type="button"
                            className={'btn btn-ghost btn-xs' + (browserOpen ? ' active' : '')}
                            title="List actual subdirectories on the remote server"
                            onClick={toggleBrowser}
                        >
                            📂 Browse Remote Folders
                        </button>
                    </div>
                    <div className="exec-dir-input-wrap">
                        <input
                            type="text"
                            id="execModalDirInput"
                            className="form-control"
                            placeholder="e.g. /var/www/html or ~"
                            spellCheck={false}
                            value={dir}
                            onChange={(e) => {
                                setDir(e.target.value);
                                setActiveChip(null);
                            }}
                        />
                        <button type="button" className="btn btn-secondary btn-sm" title="Reset to project default root directory" onClick={resetDir}>
                            Reset Root
                        </button>
                    </div>
                    {/* Quick Folder Chips */}
                    <div className="exec-dir-chips" id="execModalDirChips">
                        <span style={{ fontSize: 11, color: 'var(--text-muted)', alignSelf: 'center' }}>Quick pick:</span>
                        {DIR_CHIPS.map((chip) => (
                            <button
                                key={chip.value}
                                type="button"
                                className={'dir-chip' + (activeChip === chip.value ? ' active' : '')}
                                data-dir-chip={chip.value}
                                title={chip.title}
                                onClick={() => pickChip(chip.value)}
                            >
                                {chip.label}
                            </button>
                        ))}
                    </div>
                    {/* Remote Folder Browser Box (Collapsible) */}
                    <div className="exec-remote-browser-box" id="execRemoteBrowserBox" style={{ display: browserOpen ? 'block' : 'none' }}>
                        <div className="exec-browser-header">
                            <span style={{ fontSize: 11.5, fontWeight: 600, color: 'var(--text-secondary)' }}>Remote Subdirectories:</span>
                            <span id="execBrowserLoading" style={{ fontSize: 11, color: 'var(--text-muted)', display: browserLoading ? 'inline' : 'none' }}>
                                Scanning...
                            </span>
                        </div>
                        <div className="exec-browser-list" id="execBrowserList">
                            <BrowserList state={browser} onUp={goToDir} onEnter={enterFolder} />
                        </div>
                    </div>
                </div>

                {/* Command Editor & Sudo Toggle */}
                <div className="form-group" style={{ marginBottom: 14 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                        <label htmlFor="execModalCmdText" className="form-label" style={{ marginBottom: 0 }}>
                            3. Command to Run <span style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 'normal' }}>(Editable shell command)</span>
                        </label>
                        <label className="exec-sudo-toggle" title="Prefix command with sudo for administrator privileges">
                            <input type="checkbox" id="execModalSudoToggle" checked={sudo} onChange={(e) => onSudoToggle(e.target.checked)} />
                            <span>🛡️ Run as Admin (sudo)</span>
                        </label>
                    </div>
                    <textarea
                        ref={cmdRef}
                        id="execModalCmdText"
                        className="step-cmd-editor exec-cmd-editor"
                        rows={3}
                        placeholder="Enter command to execute..."
                        spellCheck={false}
                        value={cmdText}
                        onChange={(e) => onCmdInput(e.target.value)}
                    />
                    <div className="exec-cmd-desc" id="execModalCmdDesc" style={{ display: request.command.desc ? 'block' : 'none' }}>
                        {request.command.desc}
                    </div>
                </div>

                {/* Embedded Live Terminal Console */}
                <div className="exec-terminal-wrap" id="execModalTerminalWrap" style={{ display: terminalVisible ? 'block' : 'none' }}>
                    <div className="exec-terminal-header">
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <div className="terminal-dots">
                                <span className="t-dot red" />
                                <span className="t-dot yellow" />
                                <span className="t-dot green" />
                            </div>
                            <strong style={{ fontSize: 12 }}>Live Output</strong>
                            <span id="execTerminalStatus">{termStatus && <StatusBadge status={termStatus} />}</span>
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <span id="execTerminalTimer" style={{ fontFamily: 'var(--font-mono)', fontSize: 11.5, color: 'var(--text-secondary)' }}>
                                {timer}
                            </span>
                            <button type="button" className="btn btn-ghost btn-xs" title="Copy output" onClick={copyOutput}>
                                Copy
                            </button>
                            <button type="button" className="btn btn-ghost btn-xs" title="Clear output" onClick={clearOutput}>
                                Clear
                            </button>
                        </div>
                    </div>
                    <pre
                        ref={outputRef}
                        className="terminal-output exec-terminal-output"
                        id="execModalOutput"
                        dangerouslySetInnerHTML={{ __html: ansiToHtml(output) }}
                    />
                </div>
            </div>

            <div className="modal-footer command-run-modal-footer">
                <button type="button" className="btn btn-ghost" onClick={requestClose}>
                    {running ? 'Close' : hasRun ? 'Done / Close' : 'Cancel'}
                </button>
                <button type="button" className={sudo ? 'btn btn-warning' : 'btn btn-primary'} disabled={running} onClick={submit}>
                    <span>&#9654;</span>
                    <span id="execModalSubmitLabel">{sudo ? 'Run as Admin (sudo)' : 'Run Command'}</span>
                </button>
            </div>
        </ModalShell>
    );
}

function BrowserList({ state, onUp, onEnter }: { state: BrowserState; onUp: (path: string) => void; onEnter: (folder: string) => void }) {
    const muted = { fontSize: 11.5, color: 'var(--text-muted)', padding: '4px 0' } as const;
    if (state.kind === 'message') return state.text ? <span style={muted}>{state.text}</span> : null;
    if (state.kind === 'connecting') return <span style={muted}>Connecting &amp; querying subdirectories...</span>;
    if (state.kind === 'error') return <span style={{ ...muted, color: '#ef4444' }}>{state.text}</span>;
    return (
        <>
            {state.parentDir !== null && (
                <button type="button" className="browser-folder-pill parent-pill" title={`Navigate up to ${state.parentDir}`} onClick={() => onUp(state.parentDir as string)}>
                    <span>⬆ ..</span> <span style={{ opacity: 0.8, fontSize: 11 }}>(Up to {state.parentDir})</span>
                </button>
            )}
            {state.dirs.length === 0 ? (
                <span style={muted}>
                    No subdirectories found in <code>{state.currentDir}</code>
                </span>
            ) : (
                state.dirs.map((d) => (
                    <button key={d} type="button" className="browser-folder-pill" title={`Select / drill into ${d}`} onClick={() => onEnter(d)}>
                        <span>📁</span> <span>{d}</span>
                    </button>
                ))
            )}
        </>
    );
}
