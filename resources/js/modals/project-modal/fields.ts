/**
 * Raw form state of the project modal (legacy collectRawFields / applyRawFields /
 * populateCleanBaseline / collectFormPayload in common.js).
 */
import type { Project, SshProfile } from '@/types';

/** Legacy currentMode. */
export type FormMode = 'new' | 'edit' | 'duplicate';

/** Every input as it sits in the DOM (strings / booleans), so drafts and the dirty snapshot match the legacy ones. */
export interface RawFields {
    id: string;
    source_project_id: string;
    reuse_source_credentials: boolean;
    ssh_profile_id: string;
    name: string;
    environment: string;
    remote_path: string;
    repo_branch: string;
    preset: string;
    description: string;
    ssh_host: string;
    ssh_port: string;
    ssh_username: string;
    ssh_auth_type: string;
    command_timeout: string;
    ssh_password: string;
    ssh_private_key: string;
    ssh_passphrase: string;
    env_vars: string;
    deploy_strategy: string;
    releases_to_keep: string;
    shared_dirs: string;
    shared_files: string;
    backup_db_before_deploy: boolean;
    backup_db_driver: string;
    health_check_url: string;
    webhook_enabled: boolean;
    webhook_branch: string;
}

/**
 * Unsaved inputs per context key ('new' | 'edit:<id>' | 'duplicate:<id>'). Module level so they
 * survive the modal being unmounted and reopened (legacy modalDrafts).
 */
export const modalDrafts = new Map<string, RawFields>();

/** True while the open modal has unsaved changes (legacy beforeunload check). */
let openModalDirty = false;
export function setOpenModalDirty(dirty: boolean): void {
    openModalDirty = dirty;
}

if (typeof window !== 'undefined') {
    window.addEventListener('beforeunload', (e) => {
        if (openModalDirty || modalDrafts.size > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
}

export const BUILTIN_PRESETS: { value: string; label: string }[] = [
    { value: 'git_basic', label: 'Git Status + Git Pull (Recommended starter)' },
    { value: 'php_composer', label: 'PHP + Git Pull + Composer Install' },
    { value: 'laravel', label: 'Laravel (Maintenance Mode, Git Pull, Composer, Migrate, Optimize)' },
    { value: 'node_pm2', label: 'Node.js (Git Pull, NPM CI, Build, PM2 Reload)' },
    { value: 'empty', label: 'Empty (Add custom commands manually)' },
];

export function serializeFields(fields: RawFields): string {
    return JSON.stringify(fields);
}

export function contextKeyFor(mode: FormMode, project: Project | null): string {
    if (mode === 'duplicate' && project) return 'duplicate:' + project.id;
    if (mode === 'edit' && project) return 'edit:' + project.id;
    return 'new';
}

/** Options of the preset select (template projects first, then the built-ins). */
export function presetValues(templateProjects: Project[]): string[] {
    return [...templateProjects.map((p) => 'project:' + Number(p.id)), ...BUILTIN_PRESETS.map((p) => p.value)];
}

/** Legacy syncSshProfileFields: a linked profile overwrites host / port / username. */
export function applyProfile(fields: RawFields, profiles: SshProfile[]): RawFields {
    const pid = Number(fields.ssh_profile_id || 0);
    if (pid <= 0) return fields;
    const prof = profiles.find((s) => Number(s.id) === pid);
    if (!prof) return { ...fields, ssh_profile_id: '' }; // option missing → select falls back to "Custom"
    return {
        ...fields,
        ssh_host: prof.ssh_host || '',
        ssh_port: String(prof.ssh_port || 22),
        ssh_username: prof.ssh_username || '',
    };
}

/** Legacy populateCleanBaseline: the saved / default values for this context. */
export function buildBaseline(
    mode: FormMode,
    project: Project | null,
    templateProjects: Project[],
    profiles: SshProfile[],
): RawFields {
    const p = mode === 'new' ? null : project;
    let fields: RawFields = {
        id: '',
        source_project_id: '',
        reuse_source_credentials: false,
        ssh_profile_id: p && p.ssh_profile_id ? String(p.ssh_profile_id) : '',
        name: '',
        environment: 'production',
        remote_path: '',
        repo_branch: 'main',
        preset: 'git_basic',
        description: '',
        ssh_host: '',
        ssh_port: '22',
        ssh_username: '',
        ssh_auth_type: 'password',
        command_timeout: '300',
        ssh_password: '',
        ssh_private_key: '',
        ssh_passphrase: '',
        env_vars: '',
        deploy_strategy: p ? p.deploy_strategy || 'direct' : 'direct',
        releases_to_keep: String(p ? p.releases_to_keep || 5 : 5),
        shared_dirs: p ? (p.shared_dirs ?? 'storage') : 'storage',
        shared_files: p ? (p.shared_files ?? '.env') : '.env',
        backup_db_before_deploy: p ? Boolean(p.backup_db_before_deploy) : false,
        backup_db_driver: p ? p.backup_db_driver || 'auto' : 'auto',
        health_check_url: p ? p.health_check_url || '' : '',
        webhook_enabled: p ? Boolean(p.webhook_enabled) : false,
        webhook_branch: p ? p.webhook_branch || 'main' : 'main',
    };

    if (p) {
        fields = {
            ...fields,
            name: p.name || '',
            environment: p.environment || 'production',
            remote_path: p.remote_path || '',
            repo_branch: p.repo_branch || 'main',
            description: p.description || '',
            ssh_host: p.ssh_host || '',
            ssh_port: String(p.ssh_port || 22),
            ssh_username: p.ssh_username || '',
            ssh_auth_type: p.ssh_auth_type || 'password',
            command_timeout: String(p.command_timeout || 300),
        };
        if (mode === 'edit') {
            fields.id = String(p.id);
        } else {
            fields.source_project_id = String(p.id);
            fields.reuse_source_credentials = Boolean(p.has_password || p.has_private_key);
            fields.name = (p.name || 'Project') + ' (Copy)';
            const clonePreset = 'project:' + Number(p.id);
            fields.preset = presetValues(templateProjects).includes(clonePreset) ? clonePreset : 'git_basic';
        }
    }

    return applyProfile(fields, profiles);
}

/** Legacy applyRawFields: restore a draft (unknown presets fall back to git_basic). */
export function restoreDraft(draft: RawFields, templateProjects: Project[], profiles: SshProfile[]): RawFields {
    const preset = draft.preset || 'git_basic';
    return applyProfile(
        {
            ...draft,
            environment: draft.environment || 'production',
            preset: presetValues(templateProjects).includes(preset) ? preset : 'git_basic',
            ssh_auth_type: draft.ssh_auth_type || 'password',
            deploy_strategy: draft.deploy_strategy || 'direct',
            backup_db_driver: draft.backup_db_driver || 'auto',
            webhook_branch: draft.webhook_branch || 'main',
        },
        profiles,
    );
}

/** Legacy collectFormPayload: what create_project / update_project / test_ssh receive. */
export function buildPayload(f: RawFields): Record<string, unknown> {
    const id = f.id ? Number(f.id) : undefined;
    const isClone = !f.id && Boolean(f.source_project_id);
    return {
        id,
        project_id: id,
        source_project_id: isClone ? Number(f.source_project_id) : undefined,
        reuse_source_credentials: isClone ? f.reuse_source_credentials : false,
        ssh_profile_id: f.ssh_profile_id ? Number(f.ssh_profile_id) : null,
        name: f.name.trim(),
        environment: f.environment,
        remote_path: f.remote_path.trim(),
        repo_branch: f.repo_branch.trim() || 'main',
        preset: f.preset,
        description: f.description.trim(),
        ssh_host: f.ssh_host.trim(),
        ssh_port: Number(f.ssh_port) || 22,
        ssh_username: f.ssh_username.trim(),
        ssh_auth_type: f.ssh_auth_type,
        command_timeout: Number(f.command_timeout) || 300,
        ssh_password: f.ssh_password,
        ssh_private_key: f.ssh_private_key.trim(),
        ssh_passphrase: f.ssh_passphrase,
        env_vars: f.env_vars,
        deploy_strategy: f.deploy_strategy,
        releases_to_keep: Number(f.releases_to_keep) || 5,
        shared_dirs: f.shared_dirs.trim(),
        shared_files: f.shared_files.trim(),
        backup_db_before_deploy: f.backup_db_before_deploy,
        backup_db_driver: f.backup_db_driver,
        health_check_url: f.health_check_url.trim(),
        webhook_enabled: f.webhook_enabled,
        webhook_branch: f.webhook_branch.trim(),
    };
}
