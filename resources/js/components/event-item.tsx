import { useState } from 'react';
import { useToast } from '@/components/feedback';
import { api } from '@/lib/api';
import { ansiToHtml, copyText, formatDuration } from '@/lib/format';
import type { EventLog } from '@/types';

const STATUS_LABELS: Record<string, string> = {
    success: 'Succeeded',
    failed: 'Failed',
    running: 'Running',
    warning: 'Warning',
    info: 'Info',
    never: 'Never Deployed',
};

/** Legacy statusBadgeHtml(). */
export function StatusBadge({ status }: { status: string | null | undefined }) {
    const s = (status || 'never').toLowerCase();
    return (
        <span className={`status-pill status-${s}`}>
            <span className={'dot' + (s === 'running' ? ' dot-pulse' : '')} />
            {STATUS_LABELS[s] ?? s}
        </span>
    );
}

/** Terminal-style output with ANSI colours. */
export function AnsiPre({ text, className = 'event-log-pre' }: { text: string | null | undefined; className?: string }) {
    return <pre className={className} dangerouslySetInnerHTML={{ __html: ansiToHtml(text) }} />;
}

/** Legacy renderEventItemHtml(): one expandable event-log row. */
export function EventItem({ event: ev, showProjectName = false }: { event: EventLog; showProjectName?: boolean }) {
    const [expanded, setExpanded] = useState(false);
    const toast = useToast();
    const steps = Array.isArray(ev.steps) ? ev.steps : [];
    const sub = [ev.ssh_target, ev.event_type].filter(Boolean).join(' • ');
    const downloadUrl = api.eventDownloadUrl(ev.id);

    const copyOutput = async (e: React.MouseEvent) => {
        e.stopPropagation();
        if (ev.output_log && (await copyText(ev.output_log))) {
            toast('Event transcript copied to clipboard.', 'success');
        }
    };

    return (
        <div className={'event-item' + (expanded ? ' expanded' : '')} data-event-id={ev.id}>
            <div className="event-summary-row" onClick={() => setExpanded((v) => !v)}>
                <div className="event-left">
                    <StatusBadge status={ev.status} />
                    <span className={`cat-tag cat-${ev.category}`}>{ev.category}</span>
                    <div className="event-title-wrap">
                        <div className="event-title">
                            {showProjectName && ev.project_name && (
                                <>
                                    <strong style={{ color: '#a5b4fc' }}>[{ev.project_name}]</strong>{' '}
                                </>
                            )}
                            {ev.title}
                        </div>
                        <div className="event-sub">{sub}</div>
                    </div>
                </div>
                <div className="event-right">
                    {ev.exit_code !== null && ev.exit_code !== undefined && (
                        <span className={`last-exit-tag ${ev.exit_code === 0 ? 'last-exit-ok' : 'last-exit-err'}`}>exit {ev.exit_code}</span>
                    )}
                    {ev.duration_ms !== null && ev.duration_ms !== undefined && <span>{formatDuration(ev.duration_ms)}</span>}
                    <span>{ev.created_at}</span>
                    <a className="btn btn-ghost btn-sm" href={downloadUrl} title="Download .log file" onClick={(e) => e.stopPropagation()}>
                        .log
                    </a>
                </div>
            </div>
            {expanded && (
                <div className="event-details">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 12, gap: 8, flexWrap: 'wrap' }}>
                        <span style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                            Event #{ev.id} • Started: {ev.created_at}
                            {ev.completed_at ? ' • Completed: ' + ev.completed_at : ''}
                        </span>
                        <div style={{ display: 'flex', gap: 6 }}>
                            <button type="button" className="btn btn-secondary btn-sm" onClick={copyOutput}>
                                Copy Output
                            </button>
                            <a className="btn btn-ghost btn-sm" href={downloadUrl}>
                                Download .log
                            </a>
                        </div>
                    </div>
                    {steps.length > 0 && (
                        <div className="event-steps-grid">
                            {steps.map((step, i) => (
                                <div className="event-step-card" key={i}>
                                    <div style={{ minWidth: 0 }}>
                                        <strong>
                                            #{step.step_number} {step.name}
                                        </strong>
                                        <div style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-muted)', fontSize: 11.5, marginTop: 2 }}>
                                            $ {step.command}
                                        </div>
                                    </div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
                                        <span style={{ color: 'var(--text-secondary)' }}>{formatDuration(step.duration_ms)}</span>
                                        <span className={`last-exit-tag ${step.exit_code === 0 ? 'last-exit-ok' : 'last-exit-err'}`}>exit {step.exit_code}</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    {ev.output_log && <AnsiPre text={ev.output_log} />}
                </div>
            )}
        </div>
    );
}
