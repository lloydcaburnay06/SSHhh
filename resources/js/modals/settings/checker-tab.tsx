import { type CSSProperties, type ReactNode, useCallback, useEffect, useState } from 'react';
import { useToast } from '@/components/feedback';
import { api } from '@/lib/api';

interface CheckerProject {
    id?: number;
    name: string;
    linked?: boolean | number;
    auto_watch?: boolean | number;
    watch_file: string;
    seconds_since_check: number | null;
    last_watch_result?: string | null;
}

interface CheckerRun {
    id: number;
    status: string;
    started_at: string;
    trigger_type: string;
    duration_ms: number;
    summary: string | null;
    details: string | null;
}

interface CheckerResult {
    health: { state: 'ok' | 'error' | 'stopped' | 'never' | string; text: string };
    last_24h?: { runs?: number; checks?: number; alerts?: number; sent?: number; errors?: number };
    projects?: CheckerProject[];
    runs?: CheckerRun[];
}

const TONES: Record<string, [string, string, string]> = {
    ok: ['✅', 'rgba(34,197,94,.10)', 'rgba(34,197,94,.45)'],
    error: ['⚠️', 'rgba(234,179,8,.10)', 'rgba(234,179,8,.45)'],
    stopped: ['❌', 'rgba(239,68,68,.10)', 'rgba(239,68,68,.45)'],
    never: ['❌', 'rgba(239,68,68,.10)', 'rgba(239,68,68,.45)'],
};

const RUN_ICON: Record<string, string> = { ok: '✅', error: '❌', running: '⏳' };

const ago = (s: number | null | undefined) =>
    s === null || s === undefined ? 'never' : s < 90 ? `${s}s ago` : s < 5400 ? `${Math.round(s / 60)} min ago` : `${Math.round(s / 3600)} h ago`;

/** Automatic log checker status & run history (legacy settingsPaneChecker). Refreshes every 30s while visible. */
export function CheckerTab({ active, loadToken }: { active: boolean; loadToken: number }) {
    const toast = useToast();
    const [data, setData] = useState<CheckerResult | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [openRuns, setOpenRuns] = useState<Set<string>>(() => new Set());
    const [running, setRunning] = useState(false);

    const loadLogChecker = useCallback(async () => {
        try {
            const res = await api.getLogChecker(60);
            setData(res.result as CheckerResult);
            setLoadError(null);
        } catch (err) {
            setLoadError('Could not load checker status: ' + (err as Error).message);
        }
    }, []);

    useEffect(() => {
        if (!active) return;
        void loadLogChecker();
        const timer = window.setInterval(() => void loadLogChecker(), 30000);
        return () => window.clearInterval(timer);
    }, [active, loadToken, loadLogChecker]);

    const toggleRun = (id: string) =>
        setOpenRuns((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });

    const runNow = async () => {
        setRunning(true);
        try {
            const res = await api.runLogCheckNow();
            setOpenRuns((prev) => new Set(prev).add(String(res.result.id)));
            toast(res.result.summary, res.result.errors > 0 ? 'error' : 'success');
            await loadLogChecker();
        } catch (err) {
            toast((err as Error).message, 'error');
        } finally {
            setRunning(false);
        }
    };

    const healthStyle: CSSProperties = { padding: '12px 14px', borderRadius: 8, border: '1px solid var(--border-color)', marginBottom: 10, fontSize: 13 };
    let healthContent: ReactNode = 'Loading…';
    if (data) {
        const tone = TONES[data.health.state] || TONES.never;
        healthStyle.background = tone[1];
        healthStyle.borderColor = tone[2];
        if (!loadError) {
            const label = data.health.state === 'ok' ? 'Working' : data.health.state === 'error' ? 'Working, with errors' : 'Not running';
            healthContent = (
                <>
                    <strong>
                        {tone[0]} {label}
                    </strong>
                    <br />
                    <span style={{ color: 'var(--text-secondary)' }}>{data.health.text}</span>
                </>
            );
        }
    }
    if (loadError) healthContent = loadError;

    const t = data?.last_24h || {};
    const projects = data?.projects || [];
    const runs = data?.runs || [];

    return (
        <div className={'settings-tab-pane' + (active ? ' active' : '')} id="settingsPaneChecker" style={{ display: active ? 'block' : 'none' }}>
            <div id="checkerHealth" style={healthStyle}>
                {healthContent}
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 10 }}>
                <span id="checkerToday" style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                    {data
                        ? `Last 24 hours: ${t.runs || 0} runs · ${t.checks || 0} log checks · ${t.alerts || 0} alerts · ${t.sent || 0} sent to Claude · ${t.errors || 0} errors`
                        : ''}
                </span>
                <span style={{ display: 'flex', gap: 6 }}>
                    <button type="button" className="btn btn-ghost btn-sm" id="checkerRefreshBtn" onClick={() => void loadLogChecker()}>
                        ↻ Refresh
                    </button>
                    <button type="button" className="btn btn-primary btn-sm" id="checkerRunNowBtn" disabled={running} onClick={() => void runNow()}>
                        {running ? 'Checking all projects…' : '▶ Run check now'}
                    </button>
                </span>
            </div>
            <h4 style={{ margin: '6px 0', fontSize: 13.5 }}>Projects</h4>
            <div id="checkerProjects" style={{ fontSize: 12.5, marginBottom: 12 }}>
                {data &&
                    (projects.length === 0 ? (
                        <span style={{ color: 'var(--text-muted)' }}>No projects yet.</span>
                    ) : (
                        projects.map((p, i) => (
                            <div key={p.id ?? i} style={{ padding: '6px 0', borderBottom: '1px solid var(--border-subtle)' }}>
                                <strong>{p.name}</strong> ·{' '}
                                {p.linked && p.auto_watch ? (
                                    <span style={{ color: '#4ade80' }}>Monitored → Claude</span>
                                ) : (
                                    <span style={{ color: '#93c5fd' }}>Monitored · notification only</span>
                                )}
                                <div style={{ color: 'var(--text-secondary)' }}>
                                    {p.watch_file === 'auto' ? 'all laravel*.log' : p.watch_file} · last check {ago(p.seconds_since_check)}
                                    {p.last_watch_result ? ' — ' + p.last_watch_result : ''}
                                </div>
                            </div>
                        ))
                    ))}
            </div>
            <h4 style={{ margin: '6px 0', fontSize: 13.5 }}>
                Run history <span style={{ fontWeight: 400, color: 'var(--text-muted)', fontSize: 11.5 }}>(tap a run for details)</span>
            </h4>
            <div id="checkerRuns" style={{ fontSize: 12, maxHeight: 340, overflow: 'auto' }}>
                {data &&
                    (runs.length === 0 ? (
                        <span style={{ color: 'var(--text-muted)' }}>No runs recorded yet.</span>
                    ) : (
                        runs.map((run) => {
                            const open = openRuns.has(String(run.id));
                            return (
                                <div
                                    key={run.id}
                                    className="checker-run"
                                    data-run={run.id}
                                    style={{ padding: '5px 4px', borderBottom: '1px solid var(--border-subtle)', cursor: 'pointer' }}
                                    onClick={() => toggleRun(String(run.id))}
                                >
                                    {RUN_ICON[run.status] || '•'} <strong>{run.started_at}</strong>{' '}
                                    <span style={{ color: 'var(--text-muted)' }}>
                                        {run.trigger_type === 'manual' ? '(run now)' : ''} {run.duration_ms} ms
                                    </span>
                                    <br />
                                    <span style={{ color: 'var(--text-secondary)' }}>{run.summary || 'running…'}</span>
                                    <pre
                                        style={{
                                            display: open ? 'block' : 'none',
                                            whiteSpace: 'pre-wrap',
                                            margin: '6px 0 2px',
                                            padding: 8,
                                            background: 'rgba(0,0,0,.25)',
                                            borderRadius: 6,
                                            fontSize: 11.5,
                                        }}
                                    >
                                        {run.details || ''}
                                    </pre>
                                </div>
                            );
                        })
                    ))}
            </div>
        </div>
    );
}
