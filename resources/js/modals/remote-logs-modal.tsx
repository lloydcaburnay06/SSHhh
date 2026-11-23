import { type CSSProperties, useCallback, useEffect, useRef, useState } from 'react';
import { useConfirm, useToast } from '@/components/feedback';
import { ModalShell } from '@/components/modal-shell';
import { api } from '@/lib/api';
import { copyText, escapeHtml } from '@/lib/format';
import type { ModalProps, RemoteLogsModalState } from './modals-context';
import { ErrorState, errorMessage, type LoadStatus, LoadingState, ServerModalHeader, serverSubtitle } from './server/parts';

interface AvailableLog {
    path: string;
    name: string;
    size: string;
}

/** Response of get_claude_link / save_claude_link (ClaudeLinkRepository::publicView). */
interface ClaudeLink {
    configured?: boolean;
    fire_url?: string;
    has_token?: boolean;
    merge_branch?: string;
    clear_after_send?: boolean;
    auto_watch?: boolean;
    watch_file?: string;
    last_watch_at?: string | null;
    last_watch_result?: string | null;
    prompt_template?: string;
    last_sent_at?: string | null;
    last_session_url?: string | null;
    [key: string]: unknown;
}

const CUSTOM = '__CUSTOM__';
const smallInput: CSSProperties = { fontSize: 12, padding: '4px 8px', width: '100%' };
const fieldLabel: CSSProperties = { fontSize: 11.5, color: 'var(--text-secondary)' };
const checkLabel: CSSProperties = { ...fieldLabel, display: 'flex', alignItems: 'center', gap: 6, marginTop: 16 };
const mutedItalic: CSSProperties = { color: 'var(--text-muted)', fontStyle: 'italic' };

/** Legacy initRemoteLogsModal / remote_logs_modal.php. */
export default function RemoteLogsModal({ state, onClose }: ModalProps<RemoteLogsModalState>) {
    const { project, preferredFile = null } = state;
    const toast = useToast();
    const confirm = useConfirm();

    // ---------------- Log viewer ----------------
    const [status, setStatus] = useState<LoadStatus>('loading');
    const [error, setError] = useState('');
    const fileRef = useRef<string | null>(preferredFile);
    const [currentFile, setCurrentFile] = useState<string | null>(preferredFile);
    const [logs, setLogs] = useState<AvailableLog[]>([]);
    const [selectValue, setSelectValue] = useState('');
    const [showCustom, setShowCustom] = useState(false);
    const [customPath, setCustomPath] = useState('');
    const linesRef = useRef(300);
    const [lines, setLines] = useState(300);
    const [filter, setFilter] = useState('');
    const [raw, setRaw] = useState('');
    const [stats, setStats] = useState({ lineCount: 0, fileSize: '0B' });
    const [clearing, setClearing] = useState(false);
    const [loadSeq, setLoadSeq] = useState(0);
    const preRef = useRef<HTMLPreElement>(null);
    const customInputRef = useRef<HTMLInputElement>(null);

    const setFile = (file: string | null) => {
        fileRef.current = file;
        setCurrentFile(file);
    };

    const fetchLogs = useCallback(
        async (file: string | null = null, numLines: number | null = null) => {
            setStatus('loading');
            try {
                const res = await api.getRemoteLogs(project.id, file || fileRef.current, numLines || linesRef.current);
                if (!res || !res.result) throw new Error('Failed to fetch remote logs');
                const d = res.result;

                const selected: string | null = d.selected_file || null;
                fileRef.current = selected;
                setCurrentFile(selected);
                setRaw(d.content || '');

                const available: AvailableLog[] = Array.isArray(d.available_logs) ? d.available_logs : [];
                const found = available.some((l) => l.path === selected);
                setLogs(available);
                if (found && selected) {
                    setSelectValue(selected);
                    setShowCustom(false);
                } else if (selected) {
                    setSelectValue(CUSTOM);
                    setShowCustom(true);
                    setCustomPath(selected);
                } else {
                    // No `selected` option in the legacy markup: the browser shows the first one.
                    setSelectValue(available[0]?.path ?? CUSTOM);
                    setShowCustom(false);
                }

                setStats({ lineCount: d.line_count || 0, fileSize: d.file_size || '0B' });
                setStatus('ready');
                setLoadSeq((n) => n + 1);
            } catch (err) {
                setError(errorMessage(err));
                setStatus('error');
            }
        },
        [project.id],
    );

    // Scroll the log to the newest lines after every successful load.
    useEffect(() => {
        if (loadSeq > 0 && preRef.current) preRef.current.scrollTop = preRef.current.scrollHeight;
    }, [loadSeq]);

    // Focus the custom path input once it is visible (legacy: after picking "Custom path...").
    const [focusCustom, setFocusCustom] = useState(0);
    useEffect(() => {
        if (focusCustom > 0) customInputRef.current?.focus();
    }, [focusCustom]);

    const onSelectChange = (val: string) => {
        setSelectValue(val);
        if (val === CUSTOM) {
            setShowCustom(true);
            setFocusCustom((n) => n + 1);
        } else {
            setShowCustom(false);
            setFile(val);
            void fetchLogs(val);
        }
    };

    const applyCustom = () => {
        const p = customPath.trim();
        if (!p) return;
        setFile(p);
        void fetchLogs(p);
    };

    // What the <pre> shows (legacy renderLogContent); `text` is its textContent for Copy.
    const filterLc = filter.trim().toLowerCase();
    let display: { text: string; placeholder: boolean };
    if (!raw || raw.trim() === '') {
        display = { text: '(Log file is empty — no error records found)', placeholder: true };
    } else if (!filterLc) {
        display = { text: raw, placeholder: false };
    } else {
        const matched = raw.split('\n').filter((l) => l.toLowerCase().includes(filterLc));
        display = matched.length === 0 ? { text: `(No lines match filter "${filterLc}")`, placeholder: true } : { text: matched.join('\n'), placeholder: false };
    }

    const copyLog = async () => {
        if (!display.text) return;
        if (await copyText(display.text)) toast('Log content copied to clipboard!', 'info');
        else toast('Failed to copy to clipboard', 'error');
    };

    const clearLog = async () => {
        const file = fileRef.current;
        if (!file) return;
        const ok = await confirm({
            title: 'Clear / Truncate Remote Log?',
            subtitle: `${project.name} — ${file}`,
            message: `Are you sure you want to clear <strong>${escapeHtml(file)}</strong> on the remote server?<br><br>The file will be truncated to 0 bytes so new incoming errors are easily spotted.`,
            warning: 'This action is immediate and cannot be undone on the server.',
            confirmText: 'Yes, Clear Log',
            confirmClass: 'btn-danger',
            icon: '🗑️',
            iconVariant: 'danger',
        });
        if (!ok) return;

        setClearing(true);
        try {
            const res = await api.clearRemoteLog(project.id, file);
            toast(res.result && res.result.message ? res.result.message : 'Log file cleared successfully.', 'info');
            await fetchLogs(file);
        } catch (err) {
            toast('Failed to clear remote log: ' + errorMessage(err), 'error');
        } finally {
            setClearing(false);
        }
    };

    // ---------------- Claude Code link & "Send to Claude" ----------------
    const [link, setLink] = useState<ClaudeLink | null>(null);
    const [panelOpen, setPanelOpen] = useState(false);
    const [fireUrl, setFireUrl] = useState('');
    const [token, setToken] = useState('');
    const [mergeBranch, setMergeBranch] = useState('');
    const [clearAfterSend, setClearAfterSend] = useState(true);
    const [autoWatch, setAutoWatch] = useState(false);
    const [watchFile, setWatchFile] = useState('');
    const [promptTemplate, setPromptTemplate] = useState('');
    const [savingLink, setSavingLink] = useState<'save' | 'remove' | null>(null);
    const [sending, setSending] = useState(false);

    const renderClaudeLink = useCallback((l: ClaudeLink | null) => {
        setLink(l);
        if (!l) return;
        setFireUrl(l.fire_url || '');
        setToken('');
        setMergeBranch(l.merge_branch || '');
        setClearAfterSend(!!l.clear_after_send);
        setAutoWatch(!!l.auto_watch);
        setWatchFile(l.watch_file || '');
        setPromptTemplate(l.prompt_template || '');
    }, []);

    const loadClaudeLink = useCallback(async () => {
        setLink(null);
        setPanelOpen(false);
        try {
            const res = await api.getClaudeLink(project.id);
            renderClaudeLink(res.result);
        } catch {
            // Non-fatal: log viewer still works without the Claude link
        }
    }, [project.id, renderClaudeLink]);

    useEffect(() => {
        void loadClaudeLink();
        void fetchLogs(preferredFile);
        // Load once on mount (legacy openRemoteLogsModal).
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const copyPrompt = async () => {
        if (await copyText(promptTemplate)) toast('Prompt copied. Paste it into the scheduled task.', 'info');
        else toast('Failed to copy to clipboard', 'error');
    };

    const saveClaudeLink = async (remove = false) => {
        setSavingLink(remove ? 'remove' : 'save');
        try {
            const res = await api.saveClaudeLink(project.id, {
                fire_url: fireUrl.trim(),
                token: token.trim(),
                merge_branch: mergeBranch.trim(),
                clear_after_send: clearAfterSend,
                auto_watch: autoWatch,
                watch_file: watchFile.trim(),
                remove,
            });
            renderClaudeLink(res.result);
            toast(remove ? 'Claude Code link removed.' : 'Claude Code link saved.', 'success');
        } catch (err) {
            toast(errorMessage(err), 'error');
        } finally {
            setSavingLink(null);
        }
    };

    const removeClaudeLink = async () => {
        const ok = await confirm({
            title: 'Remove Claude Code link?',
            message: 'The saved fire URL and token for this project will be deleted from SSHhh.',
            confirmText: 'Remove',
            confirmClass: 'btn-danger',
            icon: '🔗',
            iconVariant: 'danger',
        });
        if (ok) void saveClaudeLink(true);
    };

    const sendToClaude = async () => {
        const file = fileRef.current;
        if (!file) return;
        if (!link || !link.configured) {
            setPanelOpen(true);
            toast('Link this project to a Claude Code scheduled task first.', 'info');
            return;
        }
        const clearText = link.clear_after_send
            ? 'After Claude Code confirms it received the report, the sent lines are removed from the log (newer lines are kept).'
            : 'The log will be kept as is.';
        const ok = await confirm({
            title: 'Send log to Claude Code?',
            subtitle: `${project.name} — ${file}`,
            message: `The errors in <strong>${escapeHtml(file)}</strong> are grouped, secrets are masked, and the report starts this project's Claude Code task.<br><br>Claude will fix the bugs, run the tests and merge into <strong>${escapeHtml(link.merge_branch)}</strong> (or open a pull request if unsure).<br><br>${clearText}`,
            confirmText: 'Send to Claude',
            icon: '🤖',
            iconVariant: 'deploy',
        });
        if (!ok) return;

        setSending(true);
        try {
            const res = await api.sendLogToClaude(project.id, file);
            const r = res.result || {};
            toast(r.message || 'Sent to Claude Code.', 'success');
            if (r.session_url) window.open(r.session_url, '_blank', 'noopener');
            await loadClaudeLink();
            await fetchLogs(file);
        } catch (err) {
            toast('Send to Claude failed: ' + errorMessage(err), 'error');
        } finally {
            setSending(false);
        }
    };

    let linkStatus = '';
    if (link) {
        linkStatus = link.configured ? '✅ Linked' : 'Not linked yet';
        if (link.last_sent_at) linkStatus += ` • last sent ${link.last_sent_at}`;
    }
    const watchStatus = link?.auto_watch
        ? link.last_watch_at
            ? `Automatic check: last run ${link.last_watch_at} — ${link.last_watch_result || ''}`
            : 'Automatic check is on, but the checker has not run yet. Set up cli/log_watch.php in Windows Task Scheduler (see README).'
        : '';
    const sendTitle =
        !link || link.configured ? "Send this log's errors to Claude Code to fix, then clear what was sent" : 'Link this project to Claude Code first (🔗 Claude link)';

    return (
        <ModalShell className="remote-logs-modal" style={{ maxWidth: 960, width: '95%' }} labelledBy="remoteLogsModalTitle" onRequestClose={onClose}>
            <ServerModalHeader
                icon="📜"
                titleId="remoteLogsModalTitle"
                title={`Remote Server Logs — ${project.name}`}
                subtitle={serverSubtitle(project)}
                onClose={onClose}
            />

            <div className="modal-body" style={{ padding: '16px 20px' }}>
                {status === 'loading' && (
                    <LoadingState
                        title="Connecting & Fetching Remote Logs..."
                        hint="Scanning for log files in storage/logs, error_log, and reading latest entries over SSH."
                    />
                )}

                {status === 'error' && <ErrorState heading="Failed to read remote logs:" message={error} style={{ marginBottom: 12 }} />}

                {status === 'ready' && (
                    <div>
                        {/* Controls bar */}
                        <div
                            style={{
                                display: 'flex',
                                flexWrap: 'wrap',
                                gap: 8,
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                marginBottom: 12,
                                background: 'rgba(255,255,255,0.03)',
                                padding: '10px 12px',
                                borderRadius: 8,
                                border: '1px solid var(--border-color)',
                            }}
                        >
                            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'center', flex: 1, minWidth: 260 }}>
                                <label htmlFor="remoteLogSelect" style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-secondary)', whiteSpace: 'nowrap' }}>
                                    Log File:
                                </label>
                                <select
                                    id="remoteLogSelect"
                                    className="form-select"
                                    style={{ minWidth: 200, maxWidth: 280, fontSize: 12, padding: '4px 8px' }}
                                    value={selectValue}
                                    onChange={(e) => onSelectChange(e.target.value)}
                                >
                                    {logs.map((l) => (
                                        <option key={l.path} value={l.path}>
                                            {l.name} ({l.size})
                                        </option>
                                    ))}
                                    <option value={CUSTOM}>Custom path...</option>
                                </select>

                                <div style={{ display: showCustom ? 'inline-flex' : 'none', alignItems: 'center', gap: 4 }}>
                                    <input
                                        ref={customInputRef}
                                        type="text"
                                        className="form-control"
                                        placeholder="path/to/logfile.log"
                                        style={{ width: 180, fontSize: 12, padding: '4px 8px' }}
                                        value={customPath}
                                        onChange={(e) => setCustomPath(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                applyCustom();
                                            }
                                        }}
                                    />
                                    <button type="button" className="btn btn-secondary btn-xs" onClick={applyCustom}>
                                        Load
                                    </button>
                                </div>

                                <select
                                    className="form-select"
                                    style={{ width: 110, fontSize: 12, padding: '4px 8px' }}
                                    title="Number of tail lines to load"
                                    value={lines}
                                    onChange={(e) => {
                                        const n = parseInt(e.target.value, 10);
                                        linesRef.current = n;
                                        setLines(n);
                                        void fetchLogs(fileRef.current, n);
                                    }}
                                >
                                    <option value={100}>100 lines</option>
                                    <option value={300}>300 lines</option>
                                    <option value={500}>500 lines</option>
                                    <option value={1000}>1,000 lines</option>
                                </select>

                                <input
                                    type="search"
                                    className="search-input"
                                    placeholder="Filter text in log..."
                                    style={{ width: 160, fontSize: 12, padding: '4px 8px' }}
                                    value={filter}
                                    onChange={(e) => setFilter(e.target.value)}
                                />
                            </div>

                            <div style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
                                <button type="button" className="btn btn-ghost btn-xs" title="Reload latest log lines" onClick={() => void fetchLogs(fileRef.current)}>
                                    🔄 Reload
                                </button>
                                <button type="button" className="btn btn-ghost btn-xs" title="Copy displayed log text" onClick={() => void copyLog()}>
                                    📋 Copy
                                </button>
                                <a
                                    href={currentFile ? api.remoteLogDownloadUrl(project.id, currentFile) : '#'}
                                    className="btn btn-secondary btn-xs"
                                    title="Download the full remote .log file directly"
                                >
                                    📥 Download .log
                                </a>
                                <button
                                    type="button"
                                    className="btn btn-danger btn-xs"
                                    title="Truncate/empty this log file on the remote server"
                                    disabled={clearing}
                                    onClick={() => void clearLog()}
                                >
                                    {clearing ? 'Clearing...' : '🗑️ Clear Log'}
                                </button>
                                <span style={{ width: 1, height: 18, background: 'var(--border-color)', margin: '0 2px' }} />
                                <button
                                    type="button"
                                    className="btn btn-ghost btn-xs"
                                    title="Link this project to a Claude Code scheduled task"
                                    onClick={() => setPanelOpen((v) => !v)}
                                >
                                    🔗 Claude link
                                </button>
                                <button type="button" className="btn btn-deploy btn-xs" title={sendTitle} disabled={sending} onClick={() => void sendToClaude()}>
                                    {sending ? 'Sending...' : '🤖 Send to Claude'}
                                </button>
                            </div>
                        </div>

                        {/* Claude Code link (per project) */}
                        <div
                            style={{
                                display: panelOpen ? 'block' : 'none',
                                marginBottom: 12,
                                padding: '12px 14px',
                                border: '1px solid var(--border-color)',
                                borderRadius: 8,
                                background: 'rgba(255,255,255,0.03)',
                            }}
                        >
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                                <strong style={{ fontSize: 13 }}>🔗 Claude Code link for this project</strong>
                                <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                                    {linkStatus}
                                    {link?.last_session_url && (
                                        <>
                                            {' • '}
                                            <a href={link.last_session_url} target="_blank" rel="noopener">
                                                open last session
                                            </a>
                                        </>
                                    )}
                                </span>
                            </div>
                            <p style={{ fontSize: 11.5, color: 'var(--text-secondary)', margin: '0 0 10px' }}>
                                Create a scheduled task for this project&apos;s repository at{' '}
                                <a href="https://claude.ai/code/routines" target="_blank" rel="noopener">
                                    claude.ai/code/routines
                                </a>
                                , paste the prompt below into it, add an <strong>API</strong> trigger, then copy its Fire URL and token here.
                            </p>
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                                <label style={fieldLabel}>
                                    Fire URL
                                    <input
                                        type="url"
                                        className="form-control"
                                        placeholder="https://api.anthropic.com/v1/claude_code/routines/trig_…/fire"
                                        style={smallInput}
                                        value={fireUrl}
                                        onChange={(e) => setFireUrl(e.target.value)}
                                    />
                                </label>
                                <label style={fieldLabel}>
                                    API token
                                    <input
                                        type="password"
                                        className="form-control"
                                        placeholder={link?.has_token ? '•••••••• saved (leave blank to keep)' : 'sk-ant-oat01-…'}
                                        autoComplete="new-password"
                                        style={smallInput}
                                        value={token}
                                        onChange={(e) => setToken(e.target.value)}
                                    />
                                </label>
                                <label style={fieldLabel}>
                                    Merge fixes into branch
                                    <input
                                        type="text"
                                        className="form-control"
                                        placeholder="main"
                                        style={smallInput}
                                        value={mergeBranch}
                                        onChange={(e) => setMergeBranch(e.target.value)}
                                    />
                                </label>
                                <label style={checkLabel}>
                                    <input type="checkbox" checked={clearAfterSend} onChange={(e) => setClearAfterSend(e.target.checked)} />
                                    Clear sent lines from the log after Claude Code receives them
                                </label>
                                <label style={fieldLabel}>
                                    Log file to check automatically
                                    <input
                                        type="text"
                                        className="form-control"
                                        placeholder="auto"
                                        title="auto = every storage/logs/laravel*.log changed in the last 2 days (laravel.log and daily laravel-YYYY-MM-DD.log). Or enter one file, e.g. storage/logs/custom.log"
                                        style={smallInput}
                                        value={watchFile}
                                        onChange={(e) => setWatchFile(e.target.value)}
                                    />
                                    <span style={{ display: 'block', fontSize: 10.5, color: 'var(--text-muted)', marginTop: 2 }}>
                                        auto = all recent storage/logs/laravel*.log files (incl. daily logs)
                                    </span>
                                </label>
                                <label style={checkLabel} title="Used by cli/log_watch.php (Windows Task Scheduler, every 10 minutes)">
                                    <input type="checkbox" checked={autoWatch} onChange={(e) => setAutoWatch(e.target.checked)} />
                                    Send new errors to Claude automatically (every project&apos;s log is checked every 5 minutes; without this, you only get a
                                    notification)
                                </label>
                            </div>
                            <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 6 }}>{watchStatus}</div>
                            <label style={{ display: 'block', fontSize: 11.5, color: 'var(--text-secondary)', marginTop: 10 }}>
                                Prompt for the scheduled task
                                <textarea
                                    readOnly
                                    rows={6}
                                    className="form-control"
                                    style={{ width: '100%', fontFamily: 'var(--font-mono)', fontSize: 11, padding: '6px 8px', resize: 'vertical' }}
                                    value={promptTemplate}
                                />
                            </label>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, marginTop: 8, flexWrap: 'wrap' }}>
                                <button type="button" className="btn btn-ghost btn-xs" onClick={() => void copyPrompt()}>
                                    📋 Copy prompt
                                </button>
                                <div style={{ display: 'flex', gap: 6 }}>
                                    {link?.configured && (
                                        <button type="button" className="btn btn-ghost btn-xs" disabled={savingLink === 'remove'} onClick={() => void removeClaudeLink()}>
                                            Remove link
                                        </button>
                                    )}
                                    <button type="button" className="btn btn-primary btn-xs" disabled={savingLink === 'save'} onClick={() => void saveClaudeLink(false)}>
                                        Save link
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* Log text display */}
                        <div style={{ position: 'relative' }}>
                            <pre
                                ref={preRef}
                                className="step-cmd-editor"
                                style={{
                                    height: 440,
                                    overflow: 'auto',
                                    margin: 0,
                                    padding: 12,
                                    fontFamily: 'var(--font-mono)',
                                    fontSize: 11.5,
                                    lineHeight: 1.5,
                                    color: '#e2e8f0',
                                    background: '#0b101b',
                                    border: '1px solid var(--border-color)',
                                    borderRadius: 8,
                                    whiteSpace: 'pre-wrap',
                                    wordBreak: 'break-all',
                                    userSelect: 'text',
                                }}
                            >
                                {display.placeholder ? <span style={mutedItalic}>{display.text}</span> : display.text}
                            </pre>
                        </div>

                        {/* Stats Bar */}
                        <div
                            style={{
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'center',
                                fontSize: 11.5,
                                color: 'var(--text-muted)',
                                marginTop: 8,
                                padding: '0 4px',
                            }}
                        >
                            <span>{`Showing last ${stats.lineCount} lines • File size: ${stats.fileSize}`}</span>
                            <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-secondary)' }}>{currentFile ? `File: ${currentFile}` : ''}</span>
                        </div>
                    </div>
                )}
            </div>

            <div className="modal-footer" style={{ padding: '12px 20px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>💡 Tip: Clearing truncates the remote file to 0 bytes so new errors are immediately visible.</span>
                <button type="button" className="btn btn-secondary btn-sm" onClick={onClose}>
                    Close
                </button>
            </div>
        </ModalShell>
    );
}
