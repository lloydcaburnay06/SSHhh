import { type FormEvent, useCallback, useEffect, useState } from 'react';
import { useConfirm, useToast } from '@/components/feedback';
import { api } from '@/lib/api';
import { emitDataChanged } from '@/lib/events';
import { escapeHtml } from '@/lib/format';
import type { SshProfile } from '@/types';
import { useModals } from '../modals-context';

interface ProfileForm {
    id: number | null;
    name: string;
    ssh_host: string;
    ssh_port: string;
    ssh_username: string;
    ssh_auth_type: 'password' | 'key';
    ssh_timeout: string;
    ssh_password: string;
    ssh_private_key: string;
    ssh_passphrase: string;
    hasPassword: boolean;
    hasKey: boolean;
    title: string;
}

function formFor(prof: SshProfile | null): ProfileForm {
    return {
        id: prof ? Number(prof.id) : null,
        name: prof ? prof.name || '' : '',
        ssh_host: prof ? prof.ssh_host || '' : '',
        ssh_port: String(prof ? prof.ssh_port || 22 : 22),
        ssh_username: prof ? prof.ssh_username || '' : '',
        ssh_auth_type: prof ? prof.ssh_auth_type || 'password' : 'password',
        ssh_timeout: String(prof ? prof.ssh_timeout || 15 : 15),
        ssh_password: '',
        ssh_private_key: '',
        ssh_passphrase: '',
        hasPassword: Boolean(prof && prof.has_password),
        hasKey: Boolean(prof && prof.has_private_key),
        title: prof ? `Edit Profile — ${prof.name}` : 'Create Shared Server Profile',
    };
}

/** Tab 1: Shared SSH server profiles (legacy settingsPaneProfiles). */
export function ProfilesTab({ active, loadToken }: { active: boolean; loadToken: number }) {
    const toast = useToast();
    const confirm = useConfirm();
    const { availableProfiles, setAvailableProfiles } = useModals();
    const [form, setForm] = useState<ProfileForm | null>(null);
    const [saving, setSaving] = useState(false);

    const loadProfiles = useCallback(async () => {
        try {
            const res = await api.listSshProfiles();
            if (res && Array.isArray(res.profiles)) setAvailableProfiles(res.profiles);
        } catch (err) {
            toast('Failed to load SSH profiles: ' + (err as Error).message, 'error');
        }
    }, [setAvailableProfiles, toast]);

    useEffect(() => {
        if (active) void loadProfiles();
    }, [active, loadToken, loadProfiles]);

    const set = <K extends keyof ProfileForm>(key: K, value: ProfileForm[K]) => setForm((f) => (f ? { ...f, [key]: value } : f));

    const deleteProfile = async (prof: SshProfile) => {
        const ok = await confirm({
            title: 'Delete Shared SSH Profile?',
            subtitle: prof.name,
            message: `Delete <strong>${escapeHtml(prof.name)}</strong>? Any linked projects will automatically retain their own standalone copy of the SSH credentials.`,
            confirmText: 'Delete Profile',
            confirmClass: 'btn-danger',
            icon: '🗑️',
            iconVariant: 'danger',
        });
        if (!ok) return;
        try {
            const res = await api.deleteSshProfile(Number(prof.id));
            if (res.profiles) setAvailableProfiles(res.profiles);
            emitDataChanged(res);
            toast('SSH profile deleted.', 'info');
        } catch (err) {
            toast((err as Error).message, 'error');
        }
    };

    const onSubmit = async (e: FormEvent) => {
        e.preventDefault();
        if (!form) return;
        const idVal = form.id || 0;
        const payload = {
            id: idVal || undefined,
            name: form.name.trim(),
            ssh_host: form.ssh_host.trim(),
            ssh_port: Number(form.ssh_port) || 22,
            ssh_username: form.ssh_username.trim(),
            ssh_auth_type: form.ssh_auth_type,
            ssh_timeout: Number(form.ssh_timeout) || 15,
            ssh_password: form.ssh_password,
            ssh_private_key: form.ssh_private_key.trim(),
            ssh_passphrase: form.ssh_passphrase,
        };
        setSaving(true);
        try {
            const res = idVal ? await api.updateSshProfile(idVal, payload) : await api.createSshProfile(payload);
            if (res.profiles) setAvailableProfiles(res.profiles);
            setForm(null);
            emitDataChanged(res);
            toast(idVal ? 'Shared SSH profile updated (linked projects synced).' : 'Shared SSH profile created.', 'success');
        } catch (err) {
            toast((err as Error).message, 'error');
        } finally {
            setSaving(false);
        }
    };

    const isKey = form?.ssh_auth_type === 'key';

    return (
        <div className={'settings-tab-pane' + (active ? ' active' : '')} id="settingsPaneProfiles" style={{ display: active ? 'block' : 'none' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginBottom: 12 }}>
                <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-secondary)' }}>
                    Store SSH credentials once and link multiple projects/domains on the same server. Updating a profile updates all linked projects.
                </p>
                <button type="button" className="btn btn-primary btn-sm" id="newSshProfileBtn" onClick={() => setForm(formFor(null))}>
                    + New Server Profile
                </button>
            </div>

            <div id="sshProfilesList" style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 14 }}>
                {availableProfiles.length === 0 ? (
                    <div
                        style={{
                            padding: 18,
                            textAlign: 'center',
                            background: 'var(--bg-input)',
                            border: '1px dashed var(--border-subtle)',
                            borderRadius: 8,
                            color: 'var(--text-muted)',
                            fontSize: 12.5,
                        }}
                    >
                        No shared SSH server profiles yet. Create one to share credentials across multiple sites on the same server.
                    </div>
                ) : (
                    availableProfiles.map((sp) => (
                        <div
                            key={sp.id}
                            className="ssh-profile-item"
                            style={{
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'center',
                                gap: 10,
                                padding: '10px 12px',
                                background: 'var(--bg-input)',
                                border: '1px solid var(--border-subtle)',
                                borderRadius: 8,
                                flexWrap: 'wrap',
                            }}
                        >
                            <div style={{ minWidth: 0 }}>
                                <div style={{ fontWeight: 600, fontSize: 13, color: 'var(--text-primary)' }}>
                                    {sp.name}
                                    <span className="badge" style={{ marginLeft: 6, fontSize: 10.5 }}>
                                        {Number(sp.project_count || 0)} project(s)
                                    </span>
                                </div>
                                <div style={{ fontFamily: 'var(--font-mono)', fontSize: 11.5, color: 'var(--text-secondary)', marginTop: 2 }}>
                                    {sp.ssh_username}@{sp.ssh_host}:{sp.ssh_port} &bull; {sp.ssh_auth_type}
                                </div>
                            </div>
                            <div style={{ display: 'flex', gap: 6 }}>
                                <button type="button" className="btn btn-ghost btn-xs" onClick={() => setForm(formFor(sp))}>
                                    Edit
                                </button>
                                <button type="button" className="btn btn-danger btn-xs" onClick={() => void deleteProfile(sp)}>
                                    Delete
                                </button>
                            </div>
                        </div>
                    ))
                )}
            </div>

            {form && (
                <form
                    id="sshProfileForm"
                    style={{ display: 'block', padding: 14, background: 'var(--bg-elevated)', border: '1px solid var(--border-color)', borderRadius: 10 }}
                    autoComplete="off"
                    onSubmit={onSubmit}
                >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                        <strong id="sshProfileFormTitle" style={{ fontSize: 13.5 }}>
                            {form.title}
                        </strong>
                        <button type="button" className="btn btn-ghost btn-xs" id="cancelSshProfileFormBtn" onClick={() => setForm(null)}>
                            Cancel
                        </button>
                    </div>
                    <div className="form-grid">
                        <div className="form-group full">
                            <label htmlFor="prof_name">Profile Label *</label>
                            <input
                                type="text"
                                className="form-control"
                                id="prof_name"
                                required
                                placeholder="e.g. Hostinger Production Cluster 1"
                                value={form.name}
                                onChange={(e) => set('name', e.target.value)}
                            />
                        </div>
                    </div>
                    <div className="form-grid-3" style={{ marginTop: 10 }}>
                        <div className="form-group">
                            <label htmlFor="prof_ssh_host">SSH Host / IP *</label>
                            <input
                                type="text"
                                className="form-control"
                                id="prof_ssh_host"
                                required
                                placeholder="145.79.25.231"
                                value={form.ssh_host}
                                onChange={(e) => set('ssh_host', e.target.value)}
                            />
                        </div>
                        <div className="form-group">
                            <label htmlFor="prof_ssh_port">Port *</label>
                            <input
                                type="number"
                                className="form-control"
                                id="prof_ssh_port"
                                required
                                min={1}
                                max={65535}
                                value={form.ssh_port}
                                onChange={(e) => set('ssh_port', e.target.value)}
                            />
                        </div>
                        <div className="form-group">
                            <label htmlFor="prof_ssh_username">Username *</label>
                            <input
                                type="text"
                                className="form-control"
                                id="prof_ssh_username"
                                required
                                placeholder="u812193548"
                                value={form.ssh_username}
                                onChange={(e) => set('ssh_username', e.target.value)}
                            />
                        </div>
                    </div>
                    <div className="form-grid" style={{ marginTop: 10 }}>
                        <div className="form-group">
                            <label htmlFor="prof_ssh_auth_type">Auth Method</label>
                            <select
                                className="form-select"
                                id="prof_ssh_auth_type"
                                value={form.ssh_auth_type}
                                onChange={(e) => set('ssh_auth_type', e.target.value as 'password' | 'key')}
                            >
                                <option value="password">SSH Password</option>
                                <option value="key">SSH Private Key</option>
                            </select>
                        </div>
                        <div className="form-group">
                            <label htmlFor="prof_ssh_timeout">Connect Timeout (sec)</label>
                            <input
                                type="number"
                                className="form-control"
                                id="prof_ssh_timeout"
                                min={3}
                                max={120}
                                value={form.ssh_timeout}
                                onChange={(e) => set('ssh_timeout', e.target.value)}
                            />
                        </div>
                        <div className="form-group full" id="profPasswordWrap" style={isKey ? { display: 'none' } : undefined}>
                            <label htmlFor="prof_ssh_password">
                                SSH Password{' '}
                                <span id="profSavedPwBadge" className="secret-saved-hint" style={form.hasPassword ? undefined : { display: 'none' }}>
                                    &#128274; Stored
                                </span>
                            </label>
                            <input
                                type="password"
                                className="form-control"
                                id="prof_ssh_password"
                                autoComplete="new-password"
                                placeholder="Enter SSH password"
                                value={form.ssh_password}
                                onChange={(e) => set('ssh_password', e.target.value)}
                            />
                        </div>
                        <div className="form-group full" id="profKeyWrap" style={isKey ? undefined : { display: 'none' }}>
                            <label htmlFor="prof_ssh_private_key">
                                SSH Private Key{' '}
                                <span id="profSavedKeyBadge" className="secret-saved-hint" style={form.hasKey ? undefined : { display: 'none' }}>
                                    &#128274; Stored
                                </span>
                            </label>
                            <textarea
                                className="step-cmd-editor"
                                id="prof_ssh_private_key"
                                rows={3}
                                placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                                value={form.ssh_private_key}
                                onChange={(e) => set('ssh_private_key', e.target.value)}
                            />
                            <input
                                type="password"
                                className="form-control"
                                id="prof_ssh_passphrase"
                                autoComplete="new-password"
                                placeholder="Optional key passphrase"
                                style={{ marginTop: 6 }}
                                value={form.ssh_passphrase}
                                onChange={(e) => set('ssh_passphrase', e.target.value)}
                            />
                        </div>
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 12 }}>
                        <button type="submit" className="btn btn-primary btn-sm" id="saveSshProfileBtn" disabled={saving}>
                            Save Server Profile
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}
