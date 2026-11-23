import { type CSSProperties, useCallback, useEffect, useState } from 'react';
import { ModalShell } from '@/components/modal-shell';
import { api } from '@/lib/api';
import type { ModalProps, ProjectRefState } from './modals-context';
import { ErrorState, errorMessage, type LoadStatus, LoadingState, ServerModalHeader, serverSubtitle } from './server/parts';

/* eslint-disable @typescript-eslint/no-explicit-any */
type Pulse = any;

const cardStyle: CSSProperties = {
    padding: 14,
    background: 'var(--bg-elevated)',
    border: '1px solid var(--border-color)',
    borderRadius: 10,
};
const cardHeadStyle: CSSProperties = { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 };
const cardLabelStyle: CSSProperties = { fontSize: 12, fontWeight: 600, color: 'var(--text-secondary)' };
const bigValueStyle = (color: string): CSSProperties => ({
    fontSize: 20,
    fontWeight: 700,
    fontFamily: 'var(--font-mono)',
    color,
    marginBottom: 4,
});
const gridStyle = (marginBottom: number): CSSProperties => ({
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
    gap: 12,
    marginBottom,
});
const sectionHeadStyle: CSSProperties = {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 10,
    borderBottom: '1px solid var(--border-subtle)',
    paddingBottom: 6,
};
const sysLabelStyle: CSSProperties = { fontSize: 11, textTransform: 'uppercase', color: 'var(--text-muted)', fontWeight: 600, display: 'block' };
const loadPillStyle: CSSProperties = { background: 'rgba(255,255,255,0.06)', padding: '2px 6px', borderRadius: 4 };
const trackStyle: CSSProperties = { height: 6, background: 'rgba(255,255,255,0.08)', borderRadius: 999, overflow: 'hidden' };

function barStyle(pct: number): CSSProperties {
    return {
        height: '100%',
        width: Math.min(100, Math.max(0, pct)) + '%',
        background: pct > 85 ? 'var(--danger)' : pct > 70 ? 'var(--warning)' : 'var(--brand-accent)',
        borderRadius: 999,
        transition: 'width 0.4s ease',
    };
}

/** Legacy initServerPulseModal / server_pulse_modal.php. */
export default function ServerPulseModal({ state, onClose }: ModalProps<ProjectRefState>) {
    const { project } = state;
    const [status, setStatus] = useState<LoadStatus>('loading');
    const [error, setError] = useState('');
    const [data, setData] = useState<Pulse>(null);

    const fetchPulse = useCallback(async () => {
        setStatus('loading');
        try {
            const res = await api.serverPulse(project.id);
            if (!res || !res.result) throw new Error('Failed to query server vitals');
            setData(res.result);
            setStatus('ready');
        } catch (err) {
            setError(errorMessage(err));
            setStatus('error');
        }
    }, [project.id]);

    useEffect(() => {
        void fetchPulse();
    }, [fetchPulse]);

    return (
        <ModalShell className="server-pulse-modal" labelledBy="pulseModalTitle" onRequestClose={onClose}>
            <ServerModalHeader
                icon="🩺"
                titleId="pulseModalTitle"
                title={`Server Pulse — ${project.name}`}
                subtitle={serverSubtitle(project, true)}
                onClose={onClose}
            />

            <div className="modal-body" style={{ padding: '16px 20px' }}>
                {status === 'loading' && (
                    <LoadingState title="Connecting to Remote Server..." hint="Querying system vitals, memory, storage, and services over SSH." />
                )}
                {status === 'error' && (
                    <ErrorState heading="Failed to query server vitals:" message={error} style={{ padding: 20 }} headingMargin={6} />
                )}
                {status === 'ready' && data && <PulseContent d={data} />}
            </div>

            <div className="modal-footer" style={{ justifyContent: 'space-between' }}>
                <button type="button" className="btn btn-secondary btn-sm" disabled={status === 'loading'} onClick={() => void fetchPulse()}>
                    <span>🔄</span> <span>Refresh Vitals</span>
                </button>
                <button type="button" className="btn btn-ghost btn-sm" onClick={onClose}>
                    Close
                </button>
            </div>
        </ModalShell>
    );
}

function PulseContent({ d }: { d: Pulse }) {
    const acct = d.account || {};
    const host = d.host_node || d;
    const procs: Pulse[] = Array.isArray(acct.processes) ? acct.processes : [];

    const loads: Pulse[] = Array.isArray(host.cpu_load) ? host.cpu_load : [0, 0, 0];
    const uptime = host.uptime_raw ? String(host.uptime_raw).split(',')[0].replace(/^.*up\s+/i, 'up ') : '-';

    const ram = host.ram || {};
    const ramPct = Number(ram.used_percent ?? 0);
    const hostTotalGb = ram.total_mb ? Math.round(ram.total_mb / 1024) + ' GB' : '0 MB';
    const hostUsedGb = ram.used_mb ? Math.round(ram.used_mb / 1024) + ' GB' : '0 MB';

    const disk = host.disk || {};
    const diskPct = Number(disk.used_percent ?? 0);

    const svcs: Pulse[] = Array.isArray(host.services) ? host.services : [];

    return (
        <div>
            {/* Shared Hosting / Container Detection Banner */}
            <div
                style={{
                    display: d.is_shared_hosting ? 'flex' : 'none',
                    alignItems: 'flex-start',
                    gap: 10,
                    padding: '10px 14px',
                    background: 'rgba(59, 130, 246, 0.12)',
                    border: '1px solid rgba(59, 130, 246, 0.3)',
                    borderRadius: 8,
                    marginBottom: 14,
                }}
            >
                <span style={{ fontSize: 16, lineHeight: 1.2 }}>☁️</span>
                <div style={{ fontSize: 12, lineHeight: 1.4 }}>
                    <strong style={{ color: '#93c5fd', display: 'block', marginBottom: 2 }}>Shared / Cloud Container Plan Detected</strong>
                    <span style={{ color: 'var(--text-secondary)' }}>
                        Metrics are separated below: <strong>Your Account / Plan Usage</strong> shows your actual isolated files, RAM, and tasks.{' '}
                        <strong>Parent Host Node</strong> shows the underlying shared hardware cluster.
                    </span>
                </div>
            </div>

            {/* Section 1: Your Account / Plan Usage */}
            <div style={{ marginBottom: 18 }}>
                <div style={sectionHeadStyle}>
                    <span style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-primary)' }}>
                        👤 Your Account / Plan Usage
                    </span>
                    <span className="badge badge-up" style={{ fontSize: 11 }}>
                        {acct.username ? `@${acct.username}` : 'Isolated Account'}
                    </span>
                </div>

                <div className="pulse-metrics-grid" style={gridStyle(12)}>
                    <div className="pulse-metric-card" style={cardStyle}>
                        <div style={cardHeadStyle}>
                            <span style={cardLabelStyle}>Account Storage</span>
                            <span style={{ fontSize: 14 }}>📁</span>
                        </div>
                        <div style={bigValueStyle('#34d399')}>{acct.disk_used || '0 MB'}</div>
                        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>Real usage in home directory &amp; sites</div>
                    </div>

                    <div className="pulse-metric-card" style={cardStyle}>
                        <div style={cardHeadStyle}>
                            <span style={cardLabelStyle}>Active RAM (RSS)</span>
                            <span style={{ fontSize: 14 }}>🧠</span>
                        </div>
                        <div style={bigValueStyle('#a5b4fc')}>{`${acct.ram_used_mb || 0} MB`}</div>
                        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>Consumed by your PHP workers &amp; tasks</div>
                    </div>

                    <div className="pulse-metric-card" style={cardStyle}>
                        <div style={cardHeadStyle}>
                            <span style={cardLabelStyle}>Account Processes</span>
                            <span style={{ fontSize: 14 }}>⚡</span>
                        </div>
                        <div style={bigValueStyle('var(--text-primary)')}>{`${acct.process_count || 0} running`}</div>
                        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>Active tasks running under your user</div>
                    </div>
                </div>

                {procs.length > 0 && (
                    <div style={{ padding: '10px 12px', background: 'var(--bg-input)', border: '1px solid var(--border-subtle)', borderRadius: 8 }}>
                        <span style={{ fontSize: 11, fontWeight: 600, color: 'var(--text-muted)', textTransform: 'uppercase', display: 'block', marginBottom: 6 }}>
                            Active User Processes:
                        </span>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                            {procs.map((p, i) => (
                                <span
                                    key={`${p.pid}-${i}`}
                                    style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: 5,
                                        background: 'rgba(255,255,255,0.06)',
                                        border: '1px solid var(--border-subtle)',
                                        padding: '3px 8px',
                                        borderRadius: 5,
                                        fontSize: 11.5,
                                        fontFamily: 'var(--font-mono)',
                                    }}
                                >
                                    <strong style={{ color: '#e2e8f0' }}>{p.command}</strong>
                                    <span style={{ color: 'var(--text-muted)', fontSize: 10.5 }}>
                                        PID {p.pid} &bull; {p.rss_mb} MB &bull; {p.cpu_pct}% CPU
                                    </span>
                                </span>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Section 2: Parent Host Node Hardware */}
            <div>
                <div style={sectionHeadStyle}>
                    <span style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-secondary)' }}>
                        🖥️ Parent Host Node (Shared Cluster Hardware)
                    </span>
                    <span className="badge" style={{ fontSize: 11, opacity: 0.8 }}>
                        Host Cluster
                    </span>
                </div>

                <div
                    className="pulse-system-bar"
                    style={{
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        flexWrap: 'wrap',
                        gap: 8,
                        padding: '10px 14px',
                        background: 'var(--bg-elevated)',
                        border: '1px solid var(--border-color)',
                        borderRadius: 8,
                        marginBottom: 12,
                    }}
                >
                    <div>
                        <span style={sysLabelStyle}>Operating System</span>
                        <span style={{ fontSize: 12.5, fontWeight: 600, fontFamily: 'var(--font-mono)', color: 'var(--text-primary)' }}>{host.os || 'Linux'}</span>
                    </div>
                    <div>
                        <span style={sysLabelStyle}>PHP Runtime</span>
                        <span style={{ fontSize: 12.5, fontWeight: 600, fontFamily: 'var(--font-mono)', color: 'var(--brand-accent)' }}>
                            {host.php_version || 'PHP'}
                        </span>
                    </div>
                    <div>
                        <span style={sysLabelStyle}>Uptime</span>
                        <span style={{ fontSize: 12, color: 'var(--text-secondary)' }}>{uptime}</span>
                    </div>
                </div>

                <div className="pulse-metrics-grid" style={gridStyle(16)}>
                    <div className="pulse-metric-card" style={cardStyle}>
                        <div style={cardHeadStyle}>
                            <span style={cardLabelStyle}>Host CPU Load</span>
                            <span style={{ fontSize: 14 }}>⚙️</span>
                        </div>
                        <div style={bigValueStyle('var(--text-primary)')}>{Number(loads[0]).toFixed(2)}</div>
                        <div style={{ fontSize: 11, color: 'var(--text-muted)', marginBottom: 8 }}>Load average across host cores</div>
                        <div style={{ fontSize: 11, fontFamily: 'var(--font-mono)', color: 'var(--text-secondary)', display: 'flex', gap: 6 }}>
                            <span style={loadPillStyle}>1m: {String(loads[0])}</span>
                            <span style={loadPillStyle}>5m: {String(loads[1])}</span>
                            <span style={loadPillStyle}>15m: {String(loads[2])}</span>
                        </div>
                    </div>

                    <div className="pulse-metric-card" style={cardStyle}>
                        <div style={cardHeadStyle}>
                            <span style={cardLabelStyle}>Host RAM (Hardware)</span>
                            <span style={{ fontSize: 14 }}>🧠</span>
                        </div>
                        <div style={bigValueStyle('var(--text-primary)')}>{ramPct + '%'}</div>
                        <div style={{ fontSize: 11, color: 'var(--text-muted)', marginBottom: 8 }}>
                            {`${hostUsedGb} / ${hostTotalGb} used (Cluster total: ${ram.total_mb || 0} MB)`}
                        </div>
                        <div className="pulse-progress-track" style={trackStyle}>
                            <div style={barStyle(ramPct)} />
                        </div>
                    </div>

                    <div className="pulse-metric-card" style={cardStyle}>
                        <div style={cardHeadStyle}>
                            <span style={cardLabelStyle}>Host Storage Pool</span>
                            <span style={{ fontSize: 14 }}>💾</span>
                        </div>
                        <div style={bigValueStyle('var(--text-primary)')}>{diskPct + '%'}</div>
                        <div style={{ fontSize: 11, color: 'var(--text-muted)', marginBottom: 8 }}>
                            {`${disk.used || '0'} / ${disk.size || '0'} used (${disk.avail || '0'} free on ${disk.mount || '/'})`}
                        </div>
                        <div className="pulse-progress-track" style={trackStyle}>
                            <div style={barStyle(diskPct)} />
                        </div>
                    </div>
                </div>

                <div>
                    <label className="form-label" style={{ fontSize: 12, marginBottom: 8, display: 'block' }}>
                        Managed Host Services
                    </label>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                        {svcs.length === 0 ? (
                            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Managed at the host level (restricted inside shared tenant accounts).</span>
                        ) : (
                            svcs.map((s, i) => {
                                const isAct = s.status === 'active';
                                return (
                                    <span key={`${s.name}-${i}`} className={`badge ${isAct ? 'badge-up' : 'badge-down'}`} style={{ fontSize: 12, padding: '4px 8px' }}>
                                        {isAct ? '🟢' : '🔴'} {s.name}: {s.status}
                                    </span>
                                );
                            })
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
