/**
 * Typed client for the SSHhh JSON API (routes/web.php, prefix /api).
 * Every endpoint answers { ok: true, ... } or { ok: false, error }; failures throw an Error
 * carrying that message. 401/428 (signed out / not set up) reload the page so the
 * server can show the sign-in screen. Each function notes the pre-Laravel api.php action.
 */
import type { DashboardStats, EventLog, EventQueryResult, ExecPacket, Project, ProjectCommand, SshProfile } from '@/types';

type Json = Record<string, unknown>;
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Any = any;

export function baseUrl(): string {
    const el = document.getElementById('app');
    if (el?.dataset.page) {
        try {
            const page = JSON.parse(el.dataset.page);
            if (page?.props?.app?.base_url) return String(page.props.app.base_url);
        } catch {
            /* fall through */
        }
    }
    return window.location.origin;
}

let cachedBase: string | null = null;

/** Absolute URL for an app path, e.g. url('/api/projects') → http://localhost/deployer/api/projects */
export function url(path: string, params?: Record<string, string | number | null | undefined>): string {
    cachedBase ??= baseUrl();
    let out = cachedBase.replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '');
    if (params) {
        const qs = new URLSearchParams();
        for (const [k, v] of Object.entries(params)) {
            if (v !== null && v !== undefined && v !== '') qs.set(k, String(v));
        }
        const s = qs.toString();
        if (s) out += '?' + s;
    }
    return out;
}

/** Laravel's XSRF-TOKEN cookie (always current), else the token rendered into the page. */
function csrfHeaders(): Record<string, string> {
    const fromCookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    if (fromCookie) return { 'X-XSRF-TOKEN': decodeURIComponent(fromCookie.slice('XSRF-TOKEN='.length)) };
    const meta = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
    return { 'X-CSRF-TOKEN': meta };
}

function headers(json = true): HeadersInit {
    const h: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...csrfHeaders(),
    };
    if (json) h['Content-Type'] = 'application/json';
    return h;
}

function handleAuthStatus(res: Response): void {
    if (res.status === 401 || res.status === 428) {
        window.location.reload();
        throw new Error('Session expired. Reloading…');
    }
}

async function parse<T>(res: Response): Promise<T> {
    handleAuthStatus(res);
    const data = await res.json().catch(() => ({ ok: false, error: `Request failed with status ${res.status}` }));
    if (!res.ok || !data.ok) {
        throw new Error(data.error || `Request failed with status ${res.status}`);
    }
    return data as T;
}

export async function get<T = Any>(path: string, params?: Record<string, string | number | null | undefined>): Promise<T> {
    return parse<T>(await fetch(url(path, params), { method: 'GET', headers: headers(false), credentials: 'same-origin' }));
}

export async function send<T = Any>(method: 'POST' | 'PUT' | 'DELETE', path: string, body: Json = {}): Promise<T> {
    return parse<T>(
        await fetch(url(path), { method, headers: headers(), credentials: 'same-origin', body: JSON.stringify(body) }),
    );
}

const post = <T = Any>(path: string, body: Json = {}) => send<T>('POST', path, body);
const put = <T = Any>(path: string, body: Json = {}) => send<T>('PUT', path, body);
const del = <T = Any>(path: string, body: Json = {}) => send<T>('DELETE', path, body);

/**
 * Runs a command / pipeline and streams NDJSON packets to onPacket as they arrive.
 * Resolves with the final `complete` packet (or null). (legacy: execute with stream: true)
 */
export async function streamExecute(projectId: number, payload: Json, onPacket?: (p: ExecPacket) => void): Promise<ExecPacket | null> {
    const res = await fetch(url(`/api/projects/${projectId}/execute`), {
        method: 'POST',
        headers: headers(),
        credentials: 'same-origin',
        body: JSON.stringify({ ...payload, stream: true }),
    });
    handleAuthStatus(res);

    const contentType = res.headers.get('Content-Type') || '';
    if (!res.ok || contentType.includes('application/json') || !res.body) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.error || `Execution request failed (${res.status})`);
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let buffer = '';
    let finalPacket: ExecPacket | null = null;
    const handle = (line: string) => {
        if (!line) return;
        try {
            const packet = JSON.parse(line) as ExecPacket;
            if (packet.type === 'complete') finalPacket = packet;
            onPacket?.(packet);
        } catch {
            /* ignore a malformed chunk boundary */
        }
    };

    for (;;) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        let idx: number;
        while ((idx = buffer.indexOf('\n')) !== -1) {
            handle(buffer.slice(0, idx).trim());
            buffer = buffer.slice(idx + 1);
        }
    }
    handle(buffer.trim());

    return finalPacket;
}

// ---------------------------------------------------------------------------
// Endpoints
// ---------------------------------------------------------------------------

export const api = {
    // Projects
    listProjects: () => get<{ projects: Project[]; stats: DashboardStats }>('/api/projects'), // list_projects
    getProject: (id: number) => get<{ project: Project; commands: ProjectCommand[] }>(`/api/projects/${id}`), // get_project
    createProject: (data: Json) => post<{ project: Project; stats: DashboardStats }>('/api/projects', data), // create_project
    updateProject: (id: number, data: Json) => put<{ project: Project; stats: DashboardStats }>(`/api/projects/${id}`, data), // update_project
    deleteProject: (id: number) => del<{ stats: DashboardStats }>(`/api/projects/${id}`), // delete_project
    reorderProjects: (orderedIds: number[]) => post<{ projects: Project[] }>('/api/projects/reorder', { ordered_ids: orderedIds }), // reorder_projects
    saveCommands: (id: number, commands: Json[]) =>
        put<{ commands: ProjectCommand[]; project: Project }>(`/api/projects/${id}/commands`, { commands }), // save_commands
    setAppState: (id: number, appState: string) =>
        post<{ project: Project; projects: Project[]; stats: DashboardStats }>(`/api/projects/${id}/app-state`, { app_state: appState }), // set_app_state
    getProjectVars: (id: number) => get<{ env_vars: string }>(`/api/projects/${id}/vars`), // get_project_vars
    regenerateWebhookToken: (id: number) => post<{ token: string }>(`/api/projects/${id}/webhook-token`), // regenerate_webhook_token
    getLiveRun: (id: number) => get<{ project: Project; event: EventLog | null }>(`/api/projects/${id}/live-run`), // get_live_run

    // Running
    execute: (id: number, payload: Json) => post<{ result: Any }>(`/api/projects/${id}/execute`, payload), // execute (non-streamed)
    streamExecute,
    testSsh: (data: Json) => post<{ result: Any; project: Project | null }>('/api/ssh/test', data), // test_ssh
    listRemoteDirs: (id: number, path?: string | null) => get<{ result: Any }>(`/api/projects/${id}/remote-dirs`, { path }), // list_remote_dirs

    // Events
    listEvents: (params: { project_id?: number | null; category?: string; status?: string; search?: string; limit?: number; offset?: number }) =>
        get<EventQueryResult & { ok: true }>('/api/events', params), // list_events
    getEvent: (id: number) => get<{ event: EventLog }>(`/api/events/${id}`), // get_event
    clearEvents: (projectId: number, status = 'all') => del<{ deleted: number; status: string }>(`/api/projects/${projectId}/events`, { status }), // clear_events
    eventDownloadUrl: (id: number) => url(`/api/events/${id}/download`), // download_event
    projectEventsDownloadUrl: (projectId: number, status = 'all') => url(`/api/projects/${projectId}/events/download`, { status }), // download_project_events

    // Live server (SSH)
    serverPulse: (id: number) => get<{ result: Any }>(`/api/projects/${id}/pulse`), // server_pulse
    getEnv: (id: number) => get<{ result: Any }>(`/api/projects/${id}/env`), // get_env
    saveEnv: (id: number, content: string) => put<{ result: Any }>(`/api/projects/${id}/env`, { content }), // save_env
    getRemoteLogs: (id: number, file?: string | null, lines?: number) => post<{ result: Any }>(`/api/projects/${id}/logs`, { file, lines }), // get_remote_logs
    clearRemoteLog: (id: number, file: string) => del<{ result: Any }>(`/api/projects/${id}/logs`, { file }), // clear_remote_log
    sendLogToClaude: (id: number, file: string) => post<{ result: Any }>(`/api/projects/${id}/logs/claude`, { file }), // send_log_to_claude
    remoteLogDownloadUrl: (id: number, file: string) => url(`/api/projects/${id}/logs/download`, { file }), // download_remote_log
    listReleases: (id: number) => get<{ result: Any }>(`/api/projects/${id}/releases`), // list_releases
    rollbackRelease: (id: number, targetRelease?: string | null) => post<{ result: Any }>(`/api/projects/${id}/rollback`, { target_release: targetRelease }), // rollback_release

    // Health
    checkHealth: (data: { url?: string; project_id?: number }) => post<{ result: Any }>('/api/health/check', data), // check_health
    checkAllHealth: () =>
        post<{ checked: number; healthy: number; unhealthy: number; results: Any[]; projects: Project[]; stats: DashboardStats }>('/api/health/check-all'), // check_all_health

    // Shared SSH profiles
    listSshProfiles: () => get<{ profiles: SshProfile[] }>('/api/ssh-profiles'), // list_ssh_profiles
    createSshProfile: (data: Json) => post<{ profile: SshProfile; profiles: SshProfile[] }>('/api/ssh-profiles', data), // create_ssh_profile
    updateSshProfile: (id: number, data: Json) =>
        put<{ profile: SshProfile; profiles: SshProfile[]; projects: Project[] }>(`/api/ssh-profiles/${id}`, data), // update_ssh_profile
    deleteSshProfile: (id: number) => del<{ profiles: SshProfile[]; projects: Project[] }>(`/api/ssh-profiles/${id}`), // delete_ssh_profile

    // Deploy notifications & vault
    getNotificationSettings: () => get<{ settings: Any }>('/api/settings/notifications'), // get_notification_settings
    saveNotificationSettings: (data: Json) => put<{ settings: Any }>('/api/settings/notifications', data), // save_notification_settings
    testNotification: () => post<{ message: string }>('/api/settings/notifications/test'), // test_notification
    exportVault: (passphrase: string) => post<{ filename: string; bundle: Any }>('/api/vault/export', { passphrase }), // export_vault
    importVault: (data: { passphrase: string; bundle: unknown; overwrite?: boolean }) =>
        post<{ summary: Any; projects: Project[]; profiles: SshProfile[]; stats: DashboardStats }>('/api/vault/import', data), // import_vault

    // Error escalation, push & log checker
    getAlertSettings: () => get<{ result: Any }>('/api/escalation'), // get_alert_settings
    saveAlertSettings: (data: Json) => put<{ result: Any }>('/api/escalation', data), // save_alert_settings
    testPush: () => post<{ result: { message: string } }>('/api/escalation/test-push'), // test_push
    testSummary: () => post<{ result: Any }>('/api/escalation/test-summary'), // test_summary
    pushSubscribe: (subscription: unknown) => post<{ result: Any }>('/api/push/subscribe', { subscription }), // push_subscribe
    pushRemoveDevice: (id: number) => del<{ result: Any }>(`/api/push/devices/${id}`), // push_remove_device
    getLogChecker: (limit = 40) => get<{ result: Any }>('/api/log-checker', { limit }), // get_log_checker
    runLogCheckNow: () => post<{ result: Any }>('/api/log-checker/run'), // run_log_check_now

    // Claude Code link per project
    getClaudeLink: (projectId: number) => get<{ result: Any }>(`/api/projects/${projectId}/claude-link`), // get_claude_link
    saveClaudeLink: (projectId: number, data: Json) => put<{ result: Any }>(`/api/projects/${projectId}/claude-link`, data), // save_claude_link

    /** Webhook URL to paste into GitHub / GitLab / Bitbucket. */
    webhookUrl: (token: string) => url(`/webhook/${encodeURIComponent(token)}`),
};
