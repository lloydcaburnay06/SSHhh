import { Link } from '@inertiajs/react';
import type { MouseEvent as ReactMouseEvent } from 'react';
import { StatusBadge } from '@/components/event-item';
import { api, url } from '@/lib/api';
import { formatDuration } from '@/lib/format';
import type { Project } from '@/types';

export type CardAction =
    | 'run'
    | 'toggle-state'
    | 'edit'
    | 'pulse'
    | 'env'
    | 'releases'
    | 'remote-logs'
    | 'duplicate'
    | 'ssh'
    | 'test-ssh'
    | 'delete';

/** Per-card in-flight flags (legacy disabled the clicked button / swapped its label). */
export interface CardBusy {
    run?: boolean;
    testSsh?: boolean;
    toggleState?: boolean;
    delete?: boolean;
}

interface Props {
    project: Project;
    rank: number;
    expanded: boolean;
    dragging: boolean;
    busy: CardBusy;
    onToggleDetails: (id: number) => void;
    onAction: (action: CardAction, project: Project, targetState?: string) => void;
    cardRef: (el: HTMLElement | null) => void;
}

/** One `.project-card` (legacy renderProjects() card template). */
export function ProjectCard({ project: p, rank, expanded, dragging, busy, onToggleDetails, onAction, cardRef }: Props) {
    const isDown = p.app_status === 'down';
    const target = `${p.ssh_username}@${p.ssh_host}:${p.ssh_port}`;
    const projectUrl = url('/projects/' + p.id);

    const authLabel =
        p.ssh_auth_type === 'key'
            ? p.has_private_key
                ? '\u{1F511} SSH Key Encrypted'
                : '⚠ Missing Key'
            : p.has_password
              ? '\u{1F512} Password Encrypted'
              : '⚠ Missing Password';

    const lastDeployText = p.last_deployed_at
        ? `${p.last_deployed_at}${p.last_deploy_duration_ms ? ' (' + formatDuration(p.last_deploy_duration_ms) + ')' : ''}`
        : 'No deployments run yet';

    // Native drag is only enabled when the press starts outside interactive / selectable areas.
    const onMouseDown = (e: ReactMouseEvent<HTMLElement>) => {
        const card = e.currentTarget;
        const interactive = Boolean(
            (e.target as Element).closest('a, button, input, select, textarea, .meta-box, .project-card-accordion, .project-card-summary'),
        );
        if (interactive) card.removeAttribute('draggable');
        else card.setAttribute('draggable', 'true');
    };

    const btn = (action: CardAction) => () => onAction(action, p);

    return (
        <article
            ref={cardRef}
            className={`project-card ${isDown ? 'app-down' : ''} ${expanded ? 'is-expanded' : ''}${dragging ? ' is-dragging' : ''}`}
            data-project-id={p.id}
            onMouseDown={onMouseDown}
        >
            {isDown && (
                <div className="card-down-strip">
                    <div className="card-down-msg">
                        <span className="down-warning-dot" aria-hidden="true" />
                        <strong>APP IS CURRENTLY DOWN</strong>
                        <span>(Maintenance mode active)</span>
                    </div>
                    <button
                        type="button"
                        className="btn btn-xs btn-outline-down"
                        title="Manually mark application as UP"
                        disabled={busy.toggleState}
                        onClick={() => onAction('toggle-state', p, 'up')}
                    >
                        Mark Up 🟢
                    </button>
                </div>
            )}
            <div className="project-card-header">
                <div className="project-card-heading-left">
                    <div className="project-drag-grip" title="Drag to arrange project priority" aria-label="Drag to arrange project priority">
                        <svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor" aria-hidden="true">
                            <circle cx="2.5" cy="2.5" r="1.3" />
                            <circle cx="7.5" cy="2.5" r="1.3" />
                            <circle cx="2.5" cy="7" r="1.3" />
                            <circle cx="7.5" cy="7" r="1.3" />
                            <circle cx="2.5" cy="11.5" r="1.3" />
                            <circle cx="7.5" cy="11.5" r="1.3" />
                        </svg>
                        <span className="priority-rank" data-priority-rank="">
                            #{rank}
                        </span>
                    </div>
                    <div style={{ minWidth: 0 }}>
                        <h3 className="project-card-title">
                            <Link href={projectUrl}>{p.name}</Link>
                        </h3>
                        {p.description && <p className="project-card-desc">{p.description}</p>}
                    </div>
                </div>
                <div className="project-card-badges">
                    {isDown && (
                        <span className="badge badge-down">
                            <span className="down-warning-dot" aria-hidden="true" /> App Down
                        </span>
                    )}
                    {p.health_check_url &&
                        (p.last_health_status === 'healthy' ? (
                            <span className="badge badge-up" title={`Health URL verified: ${p.health_check_url}`}>
                                🟢 Health OK ({p.last_health_response_code || 200})
                            </span>
                        ) : p.last_health_status === 'unhealthy' ? (
                            <span className="badge badge-down" title={`Health URL alert: ${p.health_check_url}`}>
                                🔴 Health Err ({p.last_health_response_code || 'Fail'})
                            </span>
                        ) : (
                            <span className="badge" style={{ opacity: 0.75 }} title={`Health URL: ${p.health_check_url}`}>
                                ⚪ Health
                            </span>
                        ))}
                    {p.deploy_strategy === 'symlink' && (
                        <span
                            className="badge"
                            style={{ background: 'rgba(99,102,241,0.15)', color: '#a5b4fc', border: '1px solid rgba(99,102,241,0.3)' }}
                            title="Atomic Zero-Downtime Releases"
                        >
                            🔄 Zero-Downtime
                        </span>
                    )}
                    {Boolean(p.webhook_enabled) && (
                        <span
                            className="badge"
                            style={{ background: 'rgba(52,211,153,0.15)', color: '#6ee7b7', border: '1px solid rgba(52,211,153,0.3)' }}
                            title={`Push-to-deploy webhook enabled on branch '${p.webhook_branch || 'main'}`}
                        >
                            🚀 Push-to-Deploy
                        </span>
                    )}
                    {Boolean(p.backup_db_before_deploy) && (
                        <span
                            className="badge"
                            style={{ background: 'rgba(245,158,11,0.15)', color: '#fde68a', border: '1px solid rgba(245,158,11,0.3)' }}
                            title="Pre-deploy database snapshot enabled"
                        >
                            🛡️ Auto DB Backup
                        </span>
                    )}
                </div>
            </div>

            {/* Compact Primary Summary (Always visible on card) */}
            <div className="project-card-summary">
                <div className="project-summary-target">
                    <span className="summary-target-text" title={target}>
                        💻{' '}
                        <strong>
                            {p.ssh_username}@{p.ssh_host}
                        </strong>
                    </span>
                    <span className="summary-divider">&bull;</span>
                    <span className="summary-branch-text" title={`Branch: ${p.repo_branch}`}>
                        🌿 <code>{p.repo_branch}</code>
                    </span>
                </div>
                <div className="project-summary-status">
                    <StatusBadge status={busy.run ? 'running' : p.last_deploy_status} />
                    <span className="project-summary-time">{lastDeployText}</span>
                </div>
            </div>

            {/* Accordion Collapsible (Reveals details & secondary actions) */}
            <div className="project-card-accordion" id={`accordion-proj-${p.id}`}>
                <div className="meta-box">
                    <div className="meta-row">
                        <span className="meta-key">Environment</span>
                        <span className="meta-val">
                            <span className={`badge env-${p.environment}`}>{p.environment}</span>
                        </span>
                    </div>
                    <div className="meta-row">
                        <span className="meta-key">SSH Target</span>
                        <span className="meta-val" title={target}>
                            {p.ssh_profile_name && (
                                <span className="badge" style={{ fontSize: 10, padding: '1px 6px', marginRight: 4 }}>
                                    🔗 {p.ssh_profile_name}
                                </span>
                            )}
                            {target}
                        </span>
                    </div>
                    <div className="meta-row">
                        <span className="meta-key">Remote Directory</span>
                        <span className="meta-val" title={p.remote_path || '$HOME'}>
                            {p.remote_path || '~ ($HOME)'}
                        </span>
                    </div>
                    <div className="meta-row">
                        <span className="meta-key">Branch &amp; Vault</span>
                        <span className="meta-val" style={{ color: '#34d399' }}>
                            {p.repo_branch} &bull; {authLabel}
                        </span>
                    </div>
                    <div className="meta-row">
                        <span className="meta-key">App State</span>
                        <span className="meta-val">
                            {isDown ? (
                                <span style={{ color: '#ef4444', fontWeight: 600 }}>🔴 Down (Maintenance)</span>
                            ) : p.app_status === 'up' ? (
                                <span style={{ color: '#10b981', fontWeight: 600 }}>🟢 Up (Live)</span>
                            ) : (
                                <span style={{ color: 'var(--text-muted)' }}>Unknown / Untracked</span>
                            )}
                            {p.app_status_updated_at && (
                                <span style={{ color: 'var(--text-muted)', fontSize: 11 }}> &bull; since {p.app_status_updated_at}</span>
                            )}
                        </span>
                    </div>
                    <div className="meta-row">
                        <span className="meta-key">Pipeline</span>
                        <span className="meta-val">
                            {p.enabled_command_count} active / {p.command_count} commands &bull; {p.deployment_count} deploys
                        </span>
                    </div>
                    {p.health_check_url && (
                        <div className="meta-row">
                            <span className="meta-key">Health URL</span>
                            <span className="meta-val" title={p.health_check_url}>
                                {p.health_check_url}
                            </span>
                        </div>
                    )}
                </div>

                <div className="project-accordion-actions">
                    <span className="accordion-actions-title">Additional Actions</span>
                    <div className="accordion-actions-grid">
                        <button type="button" className="btn btn-ghost btn-xs" onClick={btn('edit')}>
                            ✏️ Edit
                        </button>
                        <button type="button" className="btn btn-ghost btn-xs" title="Inspect live CPU, RAM, Disk & Services" onClick={btn('pulse')}>
                            🩺 Pulse
                        </button>
                        <button type="button" className="btn btn-ghost btn-xs" title="Directly inspect & edit remote .env" onClick={btn('env')}>
                            📝 .env
                        </button>
                        <button type="button" className="btn btn-ghost btn-xs" title="View historical releases & 1-click rollback" onClick={btn('releases')}>
                            ⏪ Releases
                        </button>
                        <button
                            type="button"
                            className="btn btn-ghost btn-xs"
                            title="Inspect, download & clear remote application error logs (e.g. laravel.log)"
                            onClick={btn('remote-logs')}
                        >
                            📜 Remote Logs
                        </button>
                        <a
                            href={api.projectEventsDownloadUrl(p.id, 'failed')}
                            className="btn btn-ghost btn-xs"
                            title="Download deployer execution error history (.log)"
                            onClick={(e) => e.stopPropagation()}
                        >
                            📥 Error Logs
                        </a>
                        <button type="button" className="btn btn-ghost btn-xs" title="Duplicate project & command template" onClick={btn('duplicate')}>
                            📋 Duplicate
                        </button>
                        <button type="button" className="btn btn-ghost btn-xs" onClick={btn('ssh')}>
                            🔑 SSH Settings
                        </button>
                        <button type="button" className="btn btn-ghost btn-xs" disabled={busy.testSsh} onClick={btn('test-ssh')}>
                            {busy.testSsh ? 'Testing...' : '⚡ Test SSH'}
                        </button>
                        <button type="button" className="btn btn-danger btn-xs" disabled={busy.delete} onClick={btn('delete')}>
                            🗑️ Delete
                        </button>
                    </div>
                </div>
            </div>

            {/* Important Primary Buttons Only */}
            <div className="project-card-footer">
                <div className="card-actions-left">
                    <button
                        type="button"
                        className={`btn btn-ghost btn-sm accordion-toggle-btn ${expanded ? 'active' : ''}`}
                        aria-expanded={expanded}
                        title={expanded ? 'Hide additional project details & actions' : 'Show additional project details & actions'}
                        onClick={() => onToggleDetails(p.id)}
                    >
                        <span className="accordion-toggle-text">{expanded ? 'Less' : 'Details'}</span>
                        <span className="accordion-chevron">{expanded ? '▴' : '▾'}</span>
                    </button>
                </div>
                <div className="card-actions-right">
                    <button
                        type="button"
                        className="btn btn-deploy btn-sm"
                        title="Quick run all enabled deployment commands for this project"
                        disabled={busy.run}
                        onClick={btn('run')}
                    >
                        {busy.run ? (
                            <>
                                <span>&#9203;</span>
                                <span>Running...</span>
                            </>
                        ) : (
                            <>
                                <span>&#9654;</span>
                                <span>Run</span>
                            </>
                        )}
                    </button>
                    <Link href={projectUrl} className="btn btn-primary btn-sm">
                        Manage &amp; Deploy &rarr;
                    </Link>
                </div>
            </div>
        </article>
    );
}
