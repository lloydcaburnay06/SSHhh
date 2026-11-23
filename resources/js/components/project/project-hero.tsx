import { StatusBadge } from '@/components/event-item';
import { formatDuration } from '@/lib/format';
import type { Project } from '@/types';

export interface HeroActions {
    onToggleAppState: () => void;
    onPulse: () => void;
    onEnv: () => void;
    onRemoteLogs: () => void;
    onReleases: () => void;
    onDuplicate: () => void;
    onTestSsh: () => void;
    onEditSsh: () => void;
    onDeployAll: () => void;
}

function AppStateBadge({ status }: { status: string | null | undefined }) {
    const st = status || 'unknown';
    if (st === 'down') {
        return (
            <span className="app-status-badge state-down">
                <span className="down-warning-dot" aria-hidden="true"></span> App is DOWN (Maintenance)
            </span>
        );
    }
    if (st === 'up') return <span className="app-status-badge state-up">&#9679; App is UP (Live)</span>;
    return <span className="app-status-badge state-unknown">&#9675; App State Unknown</span>;
}

/** Legacy renderHero() + the .project-hero markup of project.php. */
export function ProjectHero({
    project,
    isExecuting,
    togglingState,
    testingSsh,
    actions,
}: {
    project: Project;
    isExecuting: boolean;
    togglingState: boolean;
    testingSsh: boolean;
    actions: HeroActions;
}) {
    const isDown = project.app_status === 'down';
    const vaultChip =
        project.ssh_auth_type === 'key'
            ? project.has_private_key
                ? '\u{1F511} SSH Key Encrypted (AES-256-GCM)'
                : '⚠ No SSH Key Stored'
            : project.has_password
              ? '\u{1F512} Password Encrypted (AES-256-GCM)'
              : '⚠ No Password Stored';

    return (
        <section className="project-hero">
            <div className="hero-info">
                <h1>
                    <span id="heroProjectName">{project.name}</span>{' '}
                    <span className={'badge env-' + project.environment} id="heroEnvBadge">
                        {project.environment}
                    </span>{' '}
                    <span id="heroStatusBadge">
                        <StatusBadge status={project.last_deploy_status} />
                    </span>
                </h1>
                <div className="hero-app-state-wrap" style={{ marginTop: 6, marginBottom: 8 }}>
                    <span id="heroAppStateBadge">
                        <AppStateBadge status={project.app_status} />
                    </span>{' '}
                    <button
                        type="button"
                        className="btn btn-ghost btn-xs"
                        id="toggleAppStateBtn"
                        title={isDown ? 'Manually mark app as live/UP' : 'Manually mark app as DOWN/maintenance'}
                        disabled={togglingState}
                        onClick={actions.onToggleAppState}
                    >
                        {isDown ? 'Mark as UP 🟢' : 'Mark as DOWN 🔴'}
                    </button>
                </div>
                <div className="hero-chips" id="heroChips">
                    <span className="mono-chip" title="SSH Target">
                        {project.ssh_username}@{project.ssh_host}:{project.ssh_port}
                    </span>
                    <span className="mono-chip" title="Remote Working Directory">
                        dir: {project.remote_path || '~ ($HOME)'}
                    </span>
                    <span className="mono-chip" title="Git Branch">
                        branch: {project.repo_branch || 'main'}
                    </span>
                    <span className="mono-chip" style={{ color: '#34d399' }}>
                        {vaultChip}
                    </span>
                    {project.host_fingerprint && (
                        <span
                            className="mono-chip"
                            title="Verified Server Host Key Fingerprint"
                            style={{ maxWidth: 260, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                        >
                            {project.host_fingerprint}
                        </span>
                    )}
                    {project.last_deployed_at && (
                        <span>
                            Last deploy: {project.last_deployed_at}
                            {project.last_deploy_duration_ms ? ' (' + formatDuration(project.last_deploy_duration_ms) + ')' : ''}
                        </span>
                    )}
                </div>
            </div>
            <div className="hero-actions">
                <button type="button" className="btn btn-secondary" id="pulseHeroBtn" title="Inspect live CPU, RAM, Disk and Services" disabled={isExecuting} onClick={actions.onPulse}>
                    <span>🩺</span>
                    <span>Pulse</span>
                </button>
                <button type="button" className="btn btn-secondary" id="envHeroBtn" title="Directly inspect & edit remote .env" disabled={isExecuting} onClick={actions.onEnv}>
                    <span>📝</span>
                    <span>.env</span>
                </button>
                <button
                    type="button"
                    className="btn btn-secondary"
                    id="remoteLogsHeroBtn"
                    title="Inspect, download & clear remote application error logs (e.g. laravel.log)"
                    onClick={actions.onRemoteLogs}
                >
                    <span>📜</span>
                    <span>Remote Logs</span>
                </button>
                <button type="button" className="btn btn-secondary" id="releasesHeroBtn" title="View historical releases & 1-click rollback" disabled={isExecuting} onClick={actions.onReleases}>
                    <span>⏪</span>
                    <span>Releases</span>
                </button>
                <button type="button" className="btn btn-secondary" id="duplicateHeroBtn" title="Duplicate this project and its command template" disabled={isExecuting} onClick={actions.onDuplicate}>
                    <span>&#128203;</span>
                    <span>Duplicate</span>
                </button>
                <button type="button" className="btn btn-secondary" id="testSshHeroBtn" disabled={isExecuting || testingSsh} onClick={actions.onTestSsh}>
                    {testingSsh ? (
                        'Testing SSH...'
                    ) : (
                        <>
                            <span>&#9889;</span>
                            <span>Test SSH</span>
                        </>
                    )}
                </button>
                <button type="button" className="btn btn-secondary" id="editSshHeroBtn" onClick={actions.onEditSsh}>
                    <span>&#128274;</span>
                    <span>SSH &amp; Project Settings</span>
                </button>
                <button type="button" className="btn btn-deploy btn-lg" id="deployAllBtn" disabled={isExecuting} onClick={actions.onDeployAll}>
                    <span>&#9654;</span>
                    <span>Deploy Now (Execute All)</span>
                </button>
            </div>
        </section>
    );
}
