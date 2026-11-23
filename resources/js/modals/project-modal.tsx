import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useToast } from '@/components/feedback';
import { ModalShell } from '@/components/modal-shell';
import { api } from '@/lib/api';
import type { Project } from '@/types';
import { type ModalProps, type ProjectModalState, useModals } from './modals-context';
import {
    applyProfile,
    BUILTIN_PRESETS,
    buildBaseline,
    buildPayload,
    contextKeyFor,
    type FormMode,
    modalDrafts,
    type RawFields,
    restoreDraft,
    serializeFields,
    setOpenModalDirty,
} from './project-modal/fields';

const PASSWORD_HINT_DEFAULT = 'Encrypted at rest with AES-256-GCM and never sent back to the browser.';

function errorMessage(err: unknown): string {
    return err instanceof Error ? err.message : String(err);
}

/**
 * Shared "Project & SSH settings" modal: create, edit or duplicate a project
 * (legacy initProjectModal / openProjectModal / openDuplicateModal + project_modal.php).
 */
export default function ProjectModal({ state, onClose }: ModalProps<ProjectModalState>) {
    const toast = useToast();
    const { availableProjects, availableProfiles, openSettingsModal } = useModals();

    // Mode / context are fixed for one opening (the modal remounts on every open).
    const [{ mode, source, contextKey, focusSsh }] = useState(() => {
        const hasProject = Boolean(state.project && state.project.id);
        const m: FormMode = hasProject ? (state.mode === 'duplicate' ? 'duplicate' : 'edit') : 'new';
        const src: Project | null = hasProject && state.project ? { ...state.project } : null;
        return { mode: m, source: src, contextKey: contextKeyFor(m, src), focusSsh: Boolean(state.focusSsh) };
    });

    // Legacy syncProjectTemplateOptions(currentSourceProject): available projects + the source project.
    const templateProjects = useMemo(() => {
        const byId = new Map<number, Project>();
        for (const p of availableProjects) {
            if (p && Number(p.id) > 0) byId.set(Number(p.id), p);
        }
        if (source && Number(source.id) > 0) byId.set(Number(source.id), source);
        return Array.from(byId.values());
    }, [availableProjects, source]);

    const profiles = useMemo(() => availableProfiles.filter((sp) => sp && Number(sp.id) > 0), [availableProfiles]);

    // 1. clean saved/default baseline, 2. restore a preserved draft for this exact context.
    const [initial] = useState(() => {
        const base = buildBaseline(mode, source, templateProjects, profiles);
        const draft = modalDrafts.get(contextKey);
        return {
            baseline: serializeFields(base),
            fields: draft ? restoreDraft(draft, templateProjects, profiles) : base,
            hadDraft: Boolean(draft),
        };
    });

    const [fields, setFields] = useState<RawFields>(initial.fields);
    const [baseline, setBaseline] = useState(initial.baseline);
    const [draftNotice, setDraftNotice] = useState(initial.hadDraft);
    const [guardShown, setGuardShown] = useState(false);
    const [nudging, setNudging] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [webhookToken, setWebhookToken] = useState(source?.webhook_token || '');
    const [regenBusy, setRegenBusy] = useState(false);
    const [healthBusy, setHealthBusy] = useState(false);
    const [testBusy, setTestBusy] = useState(false);
    const [testBanner, setTestBanner] = useState<{ variant: '' | 'ok' | 'err' | 'pending'; text: string }>({ variant: '', text: '' });
    const [saving, setSaving] = useState(false);

    const fieldsRef = useRef(fields);
    fieldsRef.current = fields;
    const nameRef = useRef<HTMLInputElement>(null);
    const hostRef = useRef<HTMLInputElement>(null);
    const keepEditingRef = useRef<HTMLButtonElement>(null);
    const nudgeTimer = useRef<number | undefined>(undefined);

    const dirty = baseline !== '' && serializeFields(fields) !== baseline;
    const dirtyRef = useRef(dirty);
    dirtyRef.current = dirty;

    // Legacy syncDirtyIndicators: keep the draft for this context in step with the form.
    useEffect(() => {
        setOpenModalDirty(dirty);
        if (dirty) {
            modalDrafts.set(contextKey, fields);
        } else {
            modalDrafts.delete(contextKey);
            setDraftNotice(false);
            setGuardShown(false);
        }
    }, [dirty, fields, contextKey]);

    useEffect(() => () => setOpenModalDirty(false), []);

    // Legacy setAvailableProfiles → syncSshProfileFields while open.
    const firstProfilesRun = useRef(true);
    useEffect(() => {
        if (firstProfilesRun.current) {
            firstProfilesRun.current = false;
            return;
        }
        setFields((f) => applyProfile(f, profiles));
    }, [profiles]);

    // Saved env vars are fetched on open when editing (unless a draft was restored).
    useEffect(() => {
        if (initial.hadDraft || mode !== 'edit' || !source || !source.has_env_vars) return;
        let alive = true;
        api.getProjectVars(source.id)
            .then((res) => {
                if (!alive || modalDrafts.has(contextKey)) return;
                if (res && typeof res.env_vars === 'string') {
                    const next = { ...fieldsRef.current, env_vars: res.env_vars };
                    setFields(next);
                    setBaseline(serializeFields(next));
                }
            })
            .catch(() => {});
        return () => {
            alive = false;
        };
    }, [initial.hadDraft, mode, source, contextKey]);

    // Initial focus.
    useEffect(() => {
        const t = window.setTimeout(() => {
            if (focusSsh && mode === 'edit') {
                hostRef.current?.focus();
            } else {
                nameRef.current?.focus();
                if (mode === 'duplicate' && !initial.hadDraft) nameRef.current?.select();
            }
        }, 40);
        return () => window.clearTimeout(t);
    }, [focusSsh, mode, initial.hadDraft]);

    useEffect(() => () => window.clearTimeout(nudgeTimer.current), []);

    const update = useCallback(<K extends keyof RawFields>(key: K, value: RawFields[K]) => {
        setGuardShown(false);
        setFields((f) => ({ ...f, [key]: value }));
    }, []);

    const nudge = useCallback(() => {
        setNudging(false);
        window.clearTimeout(nudgeTimer.current);
        requestAnimationFrame(() =>
            requestAnimationFrame(() => {
                setNudging(true);
                nudgeTimer.current = window.setTimeout(() => setNudging(false), 260);
            }),
        );
    }, []);

    // Only the X / Cancel buttons close; with unsaved changes they surface the guard bar.
    const requestClose = () => {
        if (!dirtyRef.current) {
            modalDrafts.delete(contextKey);
            onClose();
            return;
        }
        setGuardShown(true);
        nudge();
        window.setTimeout(() => keepEditingRef.current?.focus(), 0);
    };

    const keepDraftAndClose = () => {
        modalDrafts.set(contextKey, fieldsRef.current);
        onClose();
        toast('Closed — your unsaved inputs are preserved for when you reopen.', 'info');
    };

    const discardAndClose = () => {
        modalDrafts.delete(contextKey);
        setOpenModalDirty(false);
        onClose();
    };

    const resetDraft = () => {
        modalDrafts.delete(contextKey);
        const base = buildBaseline(mode, source, templateProjects, profiles);
        setFields(base);
        setBaseline(serializeFields(base));
        setDraftNotice(false);
        toast('Form reset to saved defaults.', 'info');
    };

    const onProfileChange = (value: string) => {
        setGuardShown(false);
        setFields((f) => applyProfile({ ...f, ssh_profile_id: value }, profiles));
    };

    const copyWebhookUrl = () => {
        if (!webhookUrl) return;
        navigator.clipboard.writeText(webhookUrl).then(() => toast('Webhook URL copied to clipboard.', 'success'));
    };

    const regenerateToken = async () => {
        const id = Number(fields.id);
        if (!id) {
            toast('Save project first to generate webhook URL.', 'info');
            return;
        }
        try {
            setRegenBusy(true);
            const res = await api.regenerateWebhookToken(id);
            if (res && res.token) {
                setWebhookToken(res.token);
                toast('New webhook token generated.', 'success');
            }
        } catch (err) {
            toast('Failed to regenerate token: ' + errorMessage(err), 'error');
        } finally {
            setRegenBusy(false);
        }
    };

    const testHealth = async () => {
        const target = fields.health_check_url.trim();
        if (!target) {
            toast('Enter a valid Health Check URL first.', 'warning');
            return;
        }
        setHealthBusy(true);
        try {
            const res = await api.checkHealth({ url: target });
            const r = res.result;
            if (r && r.ok) {
                toast(`Health Check OK! HTTP ${r.status_code} in ${r.duration_ms} ms`, 'success');
            } else {
                toast(`Health Check Alert: ${r ? r.error : 'Connection error'} (${r ? r.duration_ms : 0} ms)`, 'warning');
            }
        } catch (err) {
            toast('Health Check Failed: ' + errorMessage(err), 'error');
        } finally {
            setHealthBusy(false);
        }
    };

    const testSsh = async () => {
        const payload = buildPayload(fields);
        setTestBusy(true);
        setTestBanner({
            variant: 'pending',
            text: 'Connecting to ' + (payload.ssh_username || '?') + '@' + (payload.ssh_host || '?') + ':' + payload.ssh_port + '...',
        });
        try {
            const res = await api.testSsh(payload);
            const r = res.result;
            setTestBanner({
                variant: r.path_ok ? 'ok' : 'err',
                text: r.message + (r.fingerprint ? '\nFingerprint: ' + r.fingerprint : '') + (r.probe_output ? '\n' + r.probe_output : ''),
            });
            toast(r.message, r.path_ok ? 'success' : 'warning');
            // Legacy modalSaveCallback(project, false): lets the page refresh the fingerprint / status.
            if (state.onSaved && res.project) state.onSaved(res.project, res);
        } catch (err) {
            setTestBanner({ variant: 'err', text: 'SSH Connection Failed: ' + errorMessage(err) });
            toast(errorMessage(err), 'error');
        } finally {
            setTestBusy(false);
        }
    };

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        const payload = buildPayload(fields);
        const isEdit = Boolean(payload.id);
        const isDuplicate = !isEdit && mode === 'duplicate';
        setSaving(true);
        try {
            const res = isEdit ? await api.updateProject(Number(payload.id), payload) : await api.createProject(payload);
            toast(
                isEdit
                    ? 'Project & SSH settings updated securely.'
                    : isDuplicate
                      ? 'Project duplicated with cloned command template.'
                      : 'Project created with encrypted SSH credentials.',
                'success',
            );
            modalDrafts.delete(contextKey);
            setOpenModalDirty(false);
            onClose();
            state.onSaved?.(res.project, res);
        } catch (err) {
            toast(errorMessage(err), 'error');
            setSaving(false);
        }
    };

    // ---- derived view state ---------------------------------------------------------------

    const linkedProfile = fields.ssh_profile_id ? profiles.find((s) => Number(s.id) === Number(fields.ssh_profile_id)) ?? null : null;
    const isKey = fields.ssh_auth_type === 'key';
    const isSymlink = fields.deploy_strategy === 'symlink';
    const isCloningProject = mode !== 'edit' && fields.preset.startsWith('project:');
    const webhookUrl = webhookToken ? api.webhookUrl(webhookToken) : '(Generated after saving project)';

    let title = 'Add New Project';
    let saveLabel = 'Save Project';
    if (mode === 'duplicate' && source) {
        title = 'Duplicate Project — ' + source.name;
        saveLabel = 'Create Duplicate';
    } else if (mode === 'edit' && source) {
        title = (focusSsh ? 'SSH Settings — ' : 'Edit Project & SSH — ') + source.name;
    }

    // Legacy syncCredentialBadgesAndHints
    const sourceHasCreds = Boolean(source && (source.has_password || source.has_private_key));
    const reusing = mode === 'duplicate' && sourceHasCreds && fields.reuse_source_credentials;
    let pwBadge: string | null = null;
    let keyBadge: string | null = null;
    let pwPlaceholder = 'Enter SSH password';
    let pwHint = PASSWORD_HINT_DEFAULT;
    if (mode === 'edit' && source) {
        if (source.has_password) {
            pwBadge = '\u{1F512} Encrypted password stored';
            pwPlaceholder = 'Leave blank to keep saved password, or enter new password to update';
            pwHint = 'Your existing SSH password remains encrypted in the AES-256-GCM vault unless you type a new one.';
        }
        if (source.has_private_key) keyBadge = '\u{1F512} Encrypted private key stored';
    } else if (mode === 'duplicate' && source && reusing) {
        if (source.has_password) {
            pwBadge = '\u{1F512} Reusing source encrypted password';
            pwPlaceholder = 'Leave blank to reuse source password, or enter a new password to override';
            pwHint = 'Source project password will be re-encrypted with a fresh AES-256-GCM IV unless you type a new one.';
        }
        if (source.has_private_key) keyBadge = '\u{1F512} Reusing source encrypted private key';
    }

    let healthBadge: { cls: string; text: string } | null = null;
    if (source && source.last_health_status === 'healthy') {
        healthBadge = { cls: 'badge badge-up', text: '\u{1F7E2} HTTP ' + (source.last_health_response_code || 200) + ' OK' };
    } else if (source && source.last_health_status === 'unhealthy') {
        healthBadge = { cls: 'badge badge-down', text: '\u{1F534} HTTP ' + (source.last_health_response_code || 'Err') };
    }

    const bannerClass = 'ssh-test-banner' + (testBanner.variant ? ' show' : '') + (testBanner.variant === 'ok' || testBanner.variant === 'err' ? ' ' + testBanner.variant : '');

    return (
        <ModalShell
            id="projectModal"
            className={nudging ? 'modal-locked-nudge' : ''}
            labelledBy="projectModalTitle"
            onRequestClose={nudge}
        >
            <div className="modal-header">
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', minWidth: 0 }}>
                    <h2 id="projectModalTitle">{title}</h2>
                    <span className="dirty-badge unsaved" style={{ display: dirty ? 'inline-flex' : 'none' }}>
                        Unsaved
                    </span>
                </div>
                <button type="button" className="icon-btn" title="Close" onClick={requestClose}>
                    &times;
                </button>
            </div>

            {draftNotice && (
                <div className="modal-draft-notice" style={{ display: 'flex' }}>
                    <span>&#128190; Restored your unsaved inputs.</span>
                    <button type="button" className="btn btn-ghost btn-sm" onClick={resetDraft}>
                        Reset Inputs
                    </button>
                </div>
            )}

            {guardShown && (
                <div className="modal-unsaved-guard" style={{ display: 'flex' }} role="alert">
                    <div className="guard-text">
                        <strong>You have unsaved changes.</strong> Prevented accidental close—choose an action:
                    </div>
                    <div className="guard-actions">
                        <button type="button" className="btn btn-primary btn-sm" ref={keepEditingRef} onClick={() => setGuardShown(false)}>
                            Keep Editing
                        </button>
                        <button type="button" className="btn btn-secondary btn-sm" onClick={keepDraftAndClose}>
                            Close &amp; Keep Inputs
                        </button>
                        <button type="button" className="btn btn-danger btn-sm" onClick={discardAndClose}>
                            Discard &amp; Close
                        </button>
                    </div>
                </div>
            )}

            <form id="projectForm" autoComplete="off" onSubmit={submit}>
                <div className="modal-body">
                    <div className="form-section-title">Project Details</div>
                    <div className="form-grid">
                        <div className="form-group">
                            <label htmlFor="proj_name">Project Name *</label>
                            <input
                                type="text"
                                className="form-control"
                                id="proj_name"
                                ref={nameRef}
                                required
                                placeholder="e.g. Acme Customer Portal"
                                value={fields.name}
                                onChange={(e) => update('name', e.target.value)}
                            />
                        </div>
                        <div className="form-group">
                            <label htmlFor="proj_environment">Environment</label>
                            <select className="form-select" id="proj_environment" value={fields.environment} onChange={(e) => update('environment', e.target.value)}>
                                <option value="production">Production</option>
                                <option value="staging">Staging</option>
                                <option value="development">Development</option>
                                <option value="testing">Testing</option>
                            </select>
                        </div>
                        <div className="form-group">
                            <label htmlFor="proj_remote_path">Remote Working Directory</label>
                            <input
                                type="text"
                                className="form-control"
                                id="proj_remote_path"
                                placeholder="e.g. /var/www/html/my-app"
                                value={fields.remote_path}
                                onChange={(e) => update('remote_path', e.target.value)}
                            />
                            <span className="form-hint">Commands automatically execute inside this remote folder.</span>
                        </div>
                        <div className="form-group">
                            <label htmlFor="proj_branch">Default Git Branch</label>
                            <input
                                type="text"
                                className="form-control"
                                id="proj_branch"
                                placeholder="main"
                                value={fields.repo_branch}
                                onChange={(e) => update('repo_branch', e.target.value)}
                            />
                            <span className="form-hint">
                                Exposed to commands as <code>$DEPLOY_BRANCH</code>.
                            </span>
                        </div>
                        <div className="form-group full" id="presetGroup" style={mode === 'edit' ? { display: 'none' } : undefined}>
                            <label htmlFor="proj_preset">
                                <span>Deployment Commands Template</span>
                                <span className="secret-saved-hint" style={{ display: isCloningProject ? '' : 'none' }}>
                                    &#128203; Cloning command template
                                </span>
                            </label>
                            <select className="form-select" id="proj_preset" value={fields.preset} onChange={(e) => update('preset', e.target.value)}>
                                {templateProjects.length > 0 && (
                                    <optgroup label="Clone Command Template From Existing Project">
                                        {templateProjects.map((p) => {
                                            const count = Number(p.command_count ?? 0);
                                            return (
                                                <option key={p.id} value={'project:' + Number(p.id)}>
                                                    Clone "{p.name}" ({count} {count === 1 ? 'command' : 'commands'})
                                                </option>
                                            );
                                        })}
                                    </optgroup>
                                )}
                                <optgroup label="Built-in Starter Templates">
                                    {BUILTIN_PRESETS.map((p) => (
                                        <option key={p.value} value={p.value}>
                                            {p.label}
                                        </option>
                                    ))}
                                </optgroup>
                            </select>
                            <span className="form-hint">Select a starter pipeline or clone all deployment commands from an existing project.</span>
                        </div>
                        <div className="form-group full">
                            <label htmlFor="proj_description">Notes / Description (Optional)</label>
                            <input
                                type="text"
                                className="form-control"
                                id="proj_description"
                                placeholder="Optional server notes or URL"
                                value={fields.description}
                                onChange={(e) => update('description', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="form-section-title" id="sshSectionHeader">
                        SSH Connection &amp; Encrypted Credentials
                    </div>
                    <div className="form-group" style={{ marginBottom: 14 }}>
                        <label htmlFor="proj_ssh_profile_id">SSH Server Profile</label>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                            <select
                                className="form-select"
                                id="proj_ssh_profile_id"
                                style={{ flex: 1 }}
                                value={fields.ssh_profile_id}
                                onChange={(e) => onProfileChange(e.target.value)}
                            >
                                <option value="">Custom SSH Credentials (Standalone for this project)</option>
                                {profiles.map((sp) => (
                                    <option key={sp.id} value={String(Number(sp.id))}>
                                        {`${sp.name} (${sp.ssh_username}@${sp.ssh_host}:${sp.ssh_port})`}
                                    </option>
                                ))}
                            </select>
                            <button
                                type="button"
                                className="btn btn-secondary btn-sm"
                                title="Manage Shared SSH Profiles"
                                style={{ whiteSpace: 'nowrap' }}
                                onClick={() => openSettingsModal('profiles')}
                            >
                                Manage Profiles
                            </button>
                        </div>
                        <span className="form-hint">
                            Link multiple projects on the same server to one Shared SSH Profile, or enter custom credentials below.
                        </span>
                    </div>

                    {linkedProfile && (
                        <div
                            style={{
                                display: 'block',
                                padding: '10px 12px',
                                background: 'rgba(99, 102, 241, 0.1)',
                                border: '1px solid rgba(99, 102, 241, 0.3)',
                                borderRadius: 8,
                                marginBottom: 14,
                                fontSize: 12.5,
                            }}
                        >
                            🔗 Linked to <strong>{linkedProfile.name}</strong> &bull;{' '}
                            <code>
                                {linkedProfile.ssh_username}@{linkedProfile.ssh_host}:{linkedProfile.ssh_port}
                            </code>{' '}
                            ({linkedProfile.ssh_auth_type} vault)
                        </div>
                    )}

                    <div style={linkedProfile ? { display: 'none' } : undefined}>
                        <div className="form-grid-3">
                            <div className="form-group">
                                <label htmlFor="proj_ssh_host">SSH Host / IP *</label>
                                <input
                                    type="text"
                                    className="form-control"
                                    id="proj_ssh_host"
                                    ref={hostRef}
                                    required={!linkedProfile}
                                    placeholder="e.g. 203.0.113.10 or app.example.com"
                                    value={fields.ssh_host}
                                    onChange={(e) => update('ssh_host', e.target.value)}
                                />
                            </div>
                            <div className="form-group">
                                <label htmlFor="proj_ssh_port">SSH Port *</label>
                                <input
                                    type="number"
                                    className="form-control"
                                    id="proj_ssh_port"
                                    required={!linkedProfile}
                                    min={1}
                                    max={65535}
                                    value={fields.ssh_port}
                                    onChange={(e) => update('ssh_port', e.target.value)}
                                />
                            </div>
                            <div className="form-group">
                                <label htmlFor="proj_ssh_username">SSH Username *</label>
                                <input
                                    type="text"
                                    className="form-control"
                                    id="proj_ssh_username"
                                    required={!linkedProfile}
                                    placeholder="e.g. deploy or ubuntu"
                                    value={fields.ssh_username}
                                    onChange={(e) => update('ssh_username', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="form-grid" style={{ marginTop: 14 }}>
                            <div className="form-group">
                                <label htmlFor="proj_ssh_auth_type">Authentication Method</label>
                                <select
                                    className="form-select"
                                    id="proj_ssh_auth_type"
                                    value={fields.ssh_auth_type}
                                    onChange={(e) => update('ssh_auth_type', e.target.value)}
                                >
                                    <option value="password">SSH Password (Encrypted AES-256-GCM)</option>
                                    <option value="key">SSH Private Key (Encrypted AES-256-GCM)</option>
                                </select>
                            </div>
                            <div className="form-group">
                                <label htmlFor="proj_command_timeout">Command Timeout (seconds)</label>
                                <input
                                    type="number"
                                    className="form-control"
                                    id="proj_command_timeout"
                                    min={5}
                                    max={3600}
                                    value={fields.command_timeout}
                                    onChange={(e) => update('command_timeout', e.target.value)}
                                />
                            </div>

                            {mode === 'duplicate' && sourceHasCreds && (
                                <div className="form-group full duplicate-cred-box">
                                    <label
                                        className="toggle-label"
                                        htmlFor="proj_reuse_credentials"
                                        style={{ justifyContent: 'flex-start', color: 'var(--text-primary)', fontSize: 13 }}
                                    >
                                        <input
                                            type="checkbox"
                                            id="proj_reuse_credentials"
                                            checked={fields.reuse_source_credentials}
                                            onChange={(e) => update('reuse_source_credentials', e.target.checked)}
                                        />
                                        <span>Reuse saved encrypted SSH credentials from source project</span>
                                    </label>
                                    <span className="form-hint">
                                        Re-encrypts stored credentials server-side with a fresh AES-256-GCM IV without exposing secrets. Uncheck or enter a new
                                        password/key below for a different server.
                                    </span>
                                </div>
                            )}

                            <div className="form-group full" style={isKey ? { display: 'none' } : undefined}>
                                <label htmlFor="proj_ssh_password">
                                    <span>SSH Password</span>
                                    {pwBadge && <span className="secret-saved-hint">{pwBadge}</span>}
                                </label>
                                <div className="password-input-wrap">
                                    <input
                                        type={showPassword ? 'text' : 'password'}
                                        className="form-control"
                                        id="proj_ssh_password"
                                        autoComplete="new-password"
                                        placeholder={pwPlaceholder}
                                        value={fields.ssh_password}
                                        onChange={(e) => update('ssh_password', e.target.value)}
                                    />
                                    <button type="button" className="pw-toggle-btn" onClick={() => setShowPassword((v) => !v)}>
                                        {showPassword ? 'Hide' : 'Show'}
                                    </button>
                                </div>
                                <span className="form-hint">{pwHint}</span>
                            </div>

                            <div className="form-group full" style={isKey ? undefined : { display: 'none' }}>
                                <label htmlFor="proj_ssh_private_key">
                                    <span>SSH Private Key (PEM / OpenSSH)</span>
                                    {keyBadge && <span className="secret-saved-hint">{keyBadge}</span>}
                                </label>
                                <textarea
                                    className="step-cmd-editor"
                                    id="proj_ssh_private_key"
                                    rows={4}
                                    placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                                    value={fields.ssh_private_key}
                                    onChange={(e) => update('ssh_private_key', e.target.value)}
                                />
                                <div style={{ marginTop: 8 }}>
                                    <label htmlFor="proj_ssh_passphrase" style={{ marginBottom: 4 }}>
                                        Key Passphrase (Optional)
                                    </label>
                                    <input
                                        type="password"
                                        className="form-control"
                                        id="proj_ssh_passphrase"
                                        autoComplete="new-password"
                                        placeholder="Optional private key passphrase"
                                        style={{ width: '100%' }}
                                        value={fields.ssh_passphrase}
                                        onChange={(e) => update('ssh_passphrase', e.target.value)}
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className={bannerClass}>{testBanner.text}</div>

                    {/* Advanced Deployment & Automation Options */}
                    <div className="form-section-title" style={{ marginTop: 20 }}>
                        Deployment Strategy &amp; Automation
                    </div>
                    <div className="form-grid">
                        <div className="form-group">
                            <label htmlFor="proj_deploy_strategy">Deployment Strategy</label>
                            <select
                                className="form-select"
                                id="proj_deploy_strategy"
                                value={fields.deploy_strategy}
                                onChange={(e) => update('deploy_strategy', e.target.value)}
                            >
                                <option value="direct">Direct Path (Standard `cd &lt;remote_path&gt;`)</option>
                                <option value="symlink">Atomic Zero-Downtime (releases/ &bull; current symlink)</option>
                            </select>
                            <span className="form-hint">Atomic mode prepares releases in timestamped folders and flips a symlink for 0 downtime.</span>
                        </div>

                        <div className="form-group" style={isSymlink ? undefined : { display: 'none' }}>
                            <label htmlFor="proj_releases_to_keep">Releases to Keep</label>
                            <input
                                type="number"
                                className="form-control"
                                id="proj_releases_to_keep"
                                min={2}
                                max={30}
                                value={fields.releases_to_keep}
                                onChange={(e) => update('releases_to_keep', e.target.value)}
                            />
                            <span className="form-hint">Older releases are automatically pruned after successful deploys.</span>
                        </div>

                        <div className="form-group" style={isSymlink ? undefined : { display: 'none' }}>
                            <label htmlFor="proj_shared_dirs">Shared Directories (Symlink Mode)</label>
                            <input
                                type="text"
                                className="form-control"
                                id="proj_shared_dirs"
                                placeholder="storage, public/uploads"
                                value={fields.shared_dirs}
                                onChange={(e) => update('shared_dirs', e.target.value)}
                            />
                            <span className="form-hint">
                                Comma-separated folders persisted across releases in <code>shared/</code>.
                            </span>
                        </div>

                        <div className="form-group" style={isSymlink ? undefined : { display: 'none' }}>
                            <label htmlFor="proj_shared_files">Shared Files (Symlink Mode)</label>
                            <input
                                type="text"
                                className="form-control"
                                id="proj_shared_files"
                                placeholder=".env, auth.json"
                                value={fields.shared_files}
                                onChange={(e) => update('shared_files', e.target.value)}
                            />
                            <span className="form-hint">
                                Comma-separated files persisted across releases in <code>shared/</code>.
                            </span>
                        </div>

                        <div className="form-group full">
                            <label htmlFor="proj_env_vars">
                                <span>
                                    Encrypted Deployment Variables (<code>{'{{VAR}}'}</code> &amp; shell exports)
                                </span>
                                {source?.has_env_vars && <span className="secret-saved-hint">&#128274; Encrypted variables stored</span>}
                            </label>
                            <textarea
                                className="step-cmd-editor"
                                id="proj_env_vars"
                                rows={3}
                                placeholder={'API_TOKEN=secret-value\nPHP_BIN=/usr/bin/php8.3'}
                                value={fields.env_vars}
                                onChange={(e) => update('env_vars', e.target.value)}
                            />
                            <span className="form-hint">
                                One <code>KEY=VALUE</code> per line. Automatically exported to commands as <code>$KEY</code> and substituted for{' '}
                                <code>{'{{KEY}}'}</code>, while redacted from logs.
                            </span>
                        </div>

                        <div
                            className="form-group full"
                            style={{ padding: 12, background: 'var(--bg-elevated)', border: '1px solid var(--border-color)', borderRadius: 8 }}
                        >
                            <label
                                className="toggle-label"
                                htmlFor="proj_backup_db_before_deploy"
                                style={{ marginBottom: 6, color: 'var(--text-primary)', fontWeight: 600 }}
                            >
                                <input
                                    type="checkbox"
                                    id="proj_backup_db_before_deploy"
                                    checked={fields.backup_db_before_deploy}
                                    onChange={(e) => update('backup_db_before_deploy', e.target.checked)}
                                />
                                <span>🛡️ Automated Pre-Deploy Database Snapshot</span>
                            </label>
                            <span className="form-hint" style={{ marginBottom: 10, display: 'block' }}>
                                Creates a compressed SQL dump in <code>storage/backups/</code> before running deployment migrations.
                            </span>
                            <div style={{ display: fields.backup_db_before_deploy ? 'block' : 'none', maxWidth: 260 }}>
                                <label htmlFor="proj_backup_db_driver" style={{ fontSize: 11.5, marginBottom: 4 }}>
                                    Database Driver
                                </label>
                                <select
                                    className="form-select form-select-sm"
                                    id="proj_backup_db_driver"
                                    value={fields.backup_db_driver}
                                    onChange={(e) => update('backup_db_driver', e.target.value)}
                                >
                                    <option value="auto">Auto-detect from .env</option>
                                    <option value="mysql">MySQL / MariaDB</option>
                                    <option value="postgres">PostgreSQL</option>
                                    <option value="sqlite">SQLite</option>
                                </select>
                            </div>
                        </div>

                        <div className="form-group full">
                            <label htmlFor="proj_health_check_url">
                                <span>Post-Deploy Health Check URL</span>
                                {healthBadge && (
                                    <span className={healthBadge.cls} style={{ display: 'inline-flex', marginLeft: 6 }}>
                                        {healthBadge.text}
                                    </span>
                                )}
                            </label>
                            <div style={{ display: 'flex', gap: 8 }}>
                                <input
                                    type="url"
                                    className="form-control"
                                    id="proj_health_check_url"
                                    placeholder="https://app.example.com/up or /api/health"
                                    value={fields.health_check_url}
                                    onChange={(e) => update('health_check_url', e.target.value)}
                                />
                                <button
                                    type="button"
                                    className="btn btn-secondary btn-sm"
                                    style={{ whiteSpace: 'nowrap' }}
                                    disabled={healthBusy}
                                    onClick={testHealth}
                                >
                                    {healthBusy ? 'Pinging...' : 'Test Ping'}
                                </button>
                            </div>
                            <span className="form-hint">Pings this URL immediately after deployment. Returns verified green if HTTP 200 OK.</span>
                        </div>

                        <div
                            className="form-group full"
                            style={{ padding: 12, background: 'var(--bg-elevated)', border: '1px solid var(--border-color)', borderRadius: 8 }}
                        >
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                                <label
                                    className="toggle-label"
                                    htmlFor="proj_webhook_enabled"
                                    style={{ marginBottom: 0, color: 'var(--text-primary)', fontWeight: 600 }}
                                >
                                    <input
                                        type="checkbox"
                                        id="proj_webhook_enabled"
                                        checked={fields.webhook_enabled}
                                        onChange={(e) => update('webhook_enabled', e.target.checked)}
                                    />
                                    <span>🚀 Git Push Webhook ("Push to Deploy")</span>
                                </label>
                            </div>
                            <span className="form-hint" style={{ marginBottom: 10, display: 'block' }}>
                                Trigger this project's deployment pipeline automatically whenever you push to GitHub, GitLab, or Bitbucket.
                            </span>
                            <div style={{ display: fields.webhook_enabled ? 'block' : 'none' }}>
                                <div style={{ marginBottom: 8 }}>
                                    <label htmlFor="proj_webhook_branch" style={{ fontSize: 11.5, marginBottom: 4 }}>
                                        Target Webhook Branch
                                    </label>
                                    <input
                                        type="text"
                                        className="form-control form-control-sm"
                                        id="proj_webhook_branch"
                                        placeholder="main"
                                        style={{ maxWidth: 240 }}
                                        value={fields.webhook_branch}
                                        onChange={(e) => update('webhook_branch', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <label style={{ fontSize: 11.5, marginBottom: 4 }}>Webhook Payload URL</label>
                                    <div style={{ display: 'flex', gap: 8 }}>
                                        <input
                                            type="text"
                                            className="form-control form-control-sm"
                                            readOnly
                                            style={{ fontFamily: 'var(--font-mono)', fontSize: 11.5, color: 'var(--text-secondary)' }}
                                            value={webhookUrl}
                                        />
                                        <button type="button" className="btn btn-secondary btn-xs" style={{ whiteSpace: 'nowrap' }} onClick={copyWebhookUrl}>
                                            📋 Copy
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-ghost btn-xs"
                                            title="Regenerate secret token"
                                            style={{ whiteSpace: 'nowrap' }}
                                            disabled={regenBusy}
                                            onClick={regenerateToken}
                                        >
                                            🔄 New Token
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div className="modal-footer">
                    <button type="button" className="btn btn-secondary" disabled={testBusy} onClick={testSsh}>
                        {testBusy ? (
                            'Testing SSH...'
                        ) : (
                            <>
                                <span>&#9889;</span>
                                <span>Test SSH Connection</span>
                            </>
                        )}
                    </button>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button type="button" className="btn btn-ghost" onClick={requestClose}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={saving}>
                            {saving ? (mode === 'duplicate' ? 'Duplicating...' : 'Saving...') : saveLabel}
                        </button>
                    </div>
                </div>
            </form>
        </ModalShell>
    );
}
