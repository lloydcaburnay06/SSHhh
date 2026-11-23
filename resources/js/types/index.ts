/**
 * Shapes of the JSON the Laravel API returns. Rows come straight from the SSHhh
 * services, so unknown extra fields are allowed.
 */

export type DeployStatus = 'never' | 'running' | 'success' | 'failed' | 'warning' | 'info' | string;
export type AppStatus = 'up' | 'down' | 'unknown' | string;

export interface Project {
    id: number;
    name: string;
    environment: string;
    sort_order: number;
    description: string | null;
    repo_branch: string;
    remote_path: string;
    ssh_profile_id: number | null;
    ssh_profile_name: string | null;
    ssh_host: string;
    ssh_port: number;
    ssh_username: string;
    ssh_auth_type: 'password' | 'key';
    ssh_timeout: number;
    command_timeout: number;
    host_fingerprint: string | null;
    last_deploy_status: DeployStatus;
    last_deployed_at: string | null;
    last_deploy_duration_ms: number | null;
    app_status: AppStatus;
    app_status_updated_at: string | null;
    health_check_url: string | null;
    last_health_status: 'healthy' | 'unhealthy' | 'unknown' | string;
    last_health_checked_at: string | null;
    last_health_response_code: number | null;
    backup_db_before_deploy: 0 | 1;
    backup_db_driver: string;
    webhook_enabled: 0 | 1;
    webhook_token: string;
    webhook_branch: string;
    deploy_strategy: 'direct' | 'symlink';
    releases_to_keep: number;
    shared_dirs: string;
    shared_files: string;
    current_release_id: string | null;
    command_count: number;
    enabled_command_count: number;
    deployment_count: number;
    has_password: boolean;
    has_private_key: boolean;
    has_passphrase: boolean;
    has_env_vars: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
}

export interface ProjectCommand {
    id: number;
    project_id: number;
    name: string;
    command: string;
    sort_order: number;
    is_enabled: boolean | number;
    stop_on_error: boolean | number;
    app_state_trigger: 'none' | 'up' | 'down' | string;
    last_exit_code: number | null;
    last_run_at: string | null;
    last_duration_ms: number | null;
    [key: string]: unknown;
}

export interface SshProfile {
    id: number;
    name: string;
    ssh_host: string;
    ssh_port: number;
    ssh_username: string;
    ssh_auth_type: 'password' | 'key';
    ssh_timeout: number;
    host_fingerprint: string | null;
    [key: string]: unknown;
}

export interface EventStep {
    step_number: number;
    name: string;
    command: string;
    exit_code: number;
    duration_ms: number | null;
    [key: string]: unknown;
}

export interface EventLog {
    id: number;
    project_id: number | null;
    project_name: string;
    category: 'deployment' | 'command' | 'ssh' | 'audit' | string;
    event_type: string;
    status: DeployStatus;
    title: string;
    ssh_target: string | null;
    command_text: string | null;
    output_log: string | null;
    steps: EventStep[];
    exit_code: number | null;
    duration_ms: number | null;
    created_at: string;
    completed_at: string | null;
    [key: string]: unknown;
}

export interface EventQueryResult {
    items: EventLog[];
    total: number;
    counts: Record<string, number>;
}

export interface DashboardStats {
    total_projects: number;
    down_projects: number;
    total_deployments: number;
    successful_deployments: number;
    failed_deployments: number;
}

/** One NDJSON packet from a streamed execution (type: 'start' | 'output' | 'step_start' | 'step_end' | 'complete' | …). */
export interface ExecPacket {
    type: string;
    [key: string]: unknown;
}

/** Props shared with every Inertia page (HandleInertiaRequests::share). */
export interface SharedProps {
    app: {
        name: string;
        tagline: string;
        base_url: string;
        auth_enabled: boolean;
    };
    csrf_token: string;
    errors: Record<string, string>;
    [key: string]: unknown;
}
