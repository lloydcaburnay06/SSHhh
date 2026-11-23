import { Link, router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { useConfirm, useToast } from '@/components/feedback';
import { ModalShell } from '@/components/modal-shell';
import AppLayout from '@/layouts/app-layout';
import { api, url } from '@/lib/api';
import { copyText, escapeHtml } from '@/lib/format';
import type { SharedProps, UserAccount } from '@/types';

type Role = 'user' | 'super-admin';

const ROLE_LABELS: Record<string, string> = { 'super-admin': 'Super admin', user: 'User' };

/** Super admin: user accounts, roles, and stepping into a user's workspace. */
export default function Users({ users }: { users: UserAccount[] }) {
    return (
        <AppLayout title="Users">
            <UsersScreen initialUsers={users} />
        </AppLayout>
    );
}

function UsersScreen({ initialUsers }: { initialUsers: UserAccount[] }) {
    const { auth } = usePage<SharedProps>().props;
    const toast = useToast();
    const confirm = useConfirm();
    const [users, setUsers] = useState(initialUsers);
    const [editing, setEditing] = useState<UserAccount | 'new' | null>(null);
    const [secret, setSecret] = useState<{ email: string; password: string } | null>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const me = auth.user?.id;

    const run = async (id: number, fn: () => Promise<{ users: UserAccount[] }>, success: string) => {
        setBusy(id);
        try {
            const res = await fn();
            setUsers(res.users);
            toast(success, 'success');
            return res;
        } catch (e) {
            toast((e as Error).message, 'error');
            return null;
        } finally {
            setBusy(null);
        }
    };

    const resetPassword = async (u: UserAccount) => {
        const ok = await confirm({
            title: 'Reset password?',
            icon: '🔑',
            message: `<strong>${escapeHtml(u.name)}</strong> gets a new temporary password and must choose their own at the next sign-in. Remembered browsers are signed out.`,
            confirmText: 'Reset password',
        });
        if (!ok) return;
        const res = await run(u.id, () => api.adminResetPassword(u.id), 'Temporary password created.');
        if (res && 'temporary_password' in res) setSecret({ email: u.email, password: String(res.temporary_password) });
    };

    const resetTwoFactor = async (u: UserAccount) => {
        const ok = await confirm({
            title: 'Reset Google Authenticator?',
            icon: '📱',
            iconVariant: 'warning',
            message: `<strong>${escapeHtml(u.name)}</strong> will link a new authenticator at their next sign-in (for a lost phone).`,
            confirmText: 'Reset authenticator',
        });
        if (ok) await run(u.id, () => api.adminResetTwoFactor(u.id), 'Authenticator reset.');
    };

    const remove = async (u: UserAccount) => {
        const ok = await confirm({
            title: 'Delete user and workspace?',
            icon: '🗑️',
            iconVariant: 'danger',
            message: `This permanently deletes <strong>${escapeHtml(u.name)}</strong> (${escapeHtml(u.email)}) and everything in their workspace.`,
            warning: `${u.project_count ?? 0} project(s), their SSH credentials, logs, settings and push devices are deleted. This cannot be undone.`,
            confirmText: 'Delete permanently',
            confirmClass: 'btn-danger',
            focusCancel: true,
        });
        if (ok) await run(u.id, () => api.adminDeleteUser(u.id), 'User deleted.');
    };

    return (
        <>
            <main className="container users-page">
                <nav className="breadcrumb">
                    <Link href={url('/')}>&larr; All Projects</Link>
                    <span>/</span>
                    <strong>Users</strong>
                </nav>

                <section className="panel">
                    <div className="panel-header">
                        <div>
                            <h2 className="panel-title">Users &amp; workspaces</h2>
                            <span className="panel-subtitle">Every user has their own projects, SSH profiles, logs and settings. Open a workspace to inspect it or act in it.</span>
                        </div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing('new')}>
                            + New user
                        </button>
                    </div>
                    <div className="panel-body" style={{ overflowX: 'auto' }}>
                        <table className="users-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Projects</th>
                                    <th>Sign-in</th>
                                    <th>Last sign-in</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((u) => (
                                    <tr key={u.id} className={u.is_active ? '' : 'is-disabled'}>
                                        <td>
                                            <strong>{u.name}</strong>
                                            {u.id === me && <span className="form-hint"> (you)</span>}
                                            <div className="form-hint">{u.email}</div>
                                        </td>
                                        <td>
                                            <span className={`role-pill role-${u.roles[0] ?? 'user'}`}>{ROLE_LABELS[u.roles[0] ?? 'user'] ?? u.roles[0]}</span>
                                            {!u.is_active && <div className="form-hint">Disabled</div>}
                                        </td>
                                        <td>{u.project_count ?? 0}</td>
                                        <td>
                                            {u.two_factor_enabled ? '📱 2FA linked' : '⚠️ 2FA not linked'}
                                            {u.must_change_password && <div className="form-hint">Temporary password</div>}
                                        </td>
                                        <td>{u.last_login_at ?? 'Never'}</td>
                                        <td>
                                            <div className="user-actions">
                                                {u.id !== me && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-secondary btn-sm"
                                                        title="Open this user's workspace"
                                                        onClick={() => router.post(url(`/admin/workspace/${u.id}`))}
                                                    >
                                                        Open workspace
                                                    </button>
                                                )}
                                                <button type="button" className="btn btn-ghost btn-sm" disabled={busy === u.id} onClick={() => setEditing(u)}>
                                                    Edit
                                                </button>
                                                <button type="button" className="btn btn-ghost btn-sm" disabled={busy === u.id} onClick={() => resetPassword(u)}>
                                                    Reset password
                                                </button>
                                                {u.two_factor_enabled && (
                                                    <button type="button" className="btn btn-ghost btn-sm" disabled={busy === u.id} onClick={() => resetTwoFactor(u)}>
                                                        Reset 2FA
                                                    </button>
                                                )}
                                                {u.id !== me && (
                                                    <button type="button" className="btn btn-danger btn-sm" disabled={busy === u.id} onClick={() => remove(u)}>
                                                        Delete
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>

            {editing && (
                <UserModal
                    user={editing === 'new' ? null : editing}
                    isSelf={editing !== 'new' && editing.id === me}
                    onClose={() => setEditing(null)}
                    onSaved={(list, created) => {
                        setUsers(list);
                        setEditing(null);
                        if (created) setSecret(created);
                    }}
                />
            )}
            {secret && <TemporaryPasswordModal secret={secret} onClose={() => setSecret(null)} />}
        </>
    );
}

function UserModal({
    user,
    isSelf,
    onClose,
    onSaved,
}: {
    user: UserAccount | null;
    isSelf: boolean;
    onClose: () => void;
    onSaved: (users: UserAccount[], created?: { email: string; password: string }) => void;
}) {
    const toast = useToast();
    const [name, setName] = useState(user?.name ?? '');
    const [email, setEmail] = useState(user?.email ?? '');
    const [role, setRole] = useState<Role>(user?.is_super_admin ? 'super-admin' : 'user');
    const [active, setActive] = useState(user?.is_active ?? true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setError(null);
        try {
            if (user) {
                const res = await api.adminUpdateUser(user.id, { name, email, role, is_active: active });
                toast('User saved.', 'success');
                onSaved(res.users);
            } else {
                const res = await api.adminCreateUser({ name, email, role });
                toast('User created.', 'success');
                onSaved(res.users, { email: res.user.email, password: res.temporary_password });
            }
        } catch (err) {
            setError((err as Error).message);
        } finally {
            setSaving(false);
        }
    };

    return (
        <ModalShell onRequestClose={onClose} labelledBy="userModalTitle" style={{ maxWidth: 520, width: '92%' }}>
            <form onSubmit={submit}>
                <div className="modal-header">
                    <h3 id="userModalTitle" className="modal-title" style={{ margin: 0, fontSize: 16 }}>
                        {user ? `Edit ${user.name}` : 'New user'}
                    </h3>
                    <button type="button" className="icon-btn" title="Close" onClick={onClose}>
                        &times;
                    </button>
                </div>
                <div className="modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                    {error && <div className="ssh-test-banner show err">{error}</div>}
                    <div className="form-group">
                        <label>Name</label>
                        <input className="form-control" value={name} onChange={(e) => setName(e.target.value)} required autoFocus />
                    </div>
                    <div className="form-group">
                        <label>Email</label>
                        <input type="email" className="form-control" value={email} onChange={(e) => setEmail(e.target.value)} required />
                    </div>
                    <div className="form-group">
                        <label>Role</label>
                        <select className="form-select" value={role} onChange={(e) => setRole(e.target.value as Role)}>
                            <option value="user">User — their own workspace only</option>
                            <option value="super-admin">Super admin — manages users and can open every workspace</option>
                        </select>
                    </div>
                    {user && !isSelf && (
                        <label className="toggle-label">
                            <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /> Account enabled
                        </label>
                    )}
                    {!user && (
                        <span className="form-hint">
                            SSHhh creates a temporary password for you to pass on. On first sign-in the user picks their own password and links Google Authenticator.
                        </span>
                    )}
                </div>
                <div className="modal-footer">
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" className="btn btn-primary" disabled={saving}>
                        {saving ? 'Saving...' : user ? 'Save' : 'Create user'}
                    </button>
                </div>
            </form>
        </ModalShell>
    );
}

function TemporaryPasswordModal({ secret, onClose }: { secret: { email: string; password: string }; onClose: () => void }) {
    const toast = useToast();
    const copy = async () => {
        if (await copyText(secret.password)) toast('Temporary password copied.', 'success');
    };

    return (
        <ModalShell onRequestClose={onClose} closeOnBackdrop={false} style={{ maxWidth: 480, width: '92%' }}>
            <div className="modal-header">
                <h3 className="modal-title" style={{ margin: 0, fontSize: 16 }}>
                    Temporary password
                </h3>
                <button type="button" className="icon-btn" title="Close" onClick={onClose}>
                    &times;
                </button>
            </div>
            <div className="modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                <span>
                    Give this to <strong>{secret.email}</strong>. It is shown only once; they must replace it at their first sign-in.
                </span>
                <div className="temp-password-box">{secret.password}</div>
            </div>
            <div className="modal-footer">
                <button type="button" className="btn btn-secondary" onClick={copy}>
                    Copy
                </button>
                <button type="button" className="btn btn-primary" onClick={onClose}>
                    Done
                </button>
            </div>
        </ModalShell>
    );
}
