import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppLayout from '@/layouts/app-layout';
import { url } from '@/lib/api';
import type { UserAccount } from '@/types';

/** The signed-in user's own account. */
export default function Profile({ account }: { account: UserAccount }) {
    return (
        <AppLayout title="My account">
            <main className="container page-scroll">
                <div className="page-narrow">
                    <nav className="breadcrumb">
                        <Link href={url('/')}>&larr; All Projects</Link>
                        <span>/</span>
                        <strong>My account</strong>
                    </nav>
                    <DetailsPanel account={account} />
                    <SecurityPanel account={account} />
                </div>
            </main>
        </AppLayout>
    );
}

function DetailsPanel({ account }: { account: UserAccount }) {
    const form = useForm({ name: account.name, email: account.email });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(url('/profile'), { preserveScroll: true });
    };

    return (
        <section className="panel page-panel">
            <div className="panel-header">
                <div className="panel-title-group">
                    <h2 className="panel-title">Account</h2>
                    <span className={`role-pill role-${account.is_super_admin ? 'super-admin' : 'user'}`}>{account.is_super_admin ? 'Super admin' : 'User'}</span>
                </div>
            </div>
            <form className="panel-body" onSubmit={submit}>
                <div className="form-grid">
                    <div className="form-group">
                        <label>Name</label>
                        <input className="form-control" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        {form.errors.name && <span className="form-hint" style={{ color: '#fca5a5' }}>{form.errors.name}</span>}
                    </div>
                    <div className="form-group">
                        <label>Email</label>
                        <input type="email" className="form-control" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required />
                        {form.errors.email && <span className="form-hint" style={{ color: '#fca5a5' }}>{form.errors.email}</span>}
                    </div>
                </div>
                <div>
                    <button type="submit" className="btn btn-primary btn-sm" disabled={form.processing || !form.isDirty}>
                        Save
                    </button>
                </div>
            </form>
        </section>
    );
}

function SecurityPanel({ account }: { account: UserAccount }) {
    const form = useForm({ current_password: '' });
    const replace = (e: FormEvent) => {
        e.preventDefault();
        form.post(url('/profile/two-factor'), { preserveScroll: true, onFinish: () => form.reset() });
    };

    return (
        <section className="panel page-panel">
            <div className="panel-header">
                <h2 className="panel-title">Sign-in security</h2>
            </div>
            <div className="panel-body">
                <div className="account-row">
                    <div>
                        <strong>Password</strong>
                        <div className="form-hint">Signing in needs your password and a code from your authenticator app.</div>
                    </div>
                    <Link href={url('/password/change')} className="btn btn-secondary btn-sm">
                        Change password
                    </Link>
                </div>

                <form onSubmit={replace} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                    <div>
                        <strong>Google Authenticator</strong>{' '}
                        <span className={'badge ' + (account.two_factor_enabled ? 'badge-up' : 'badge-down')}>{account.two_factor_enabled ? 'Linked' : 'Not linked'}</span>
                        <div className="form-hint">Moving to a new phone? Confirm your password, then scan a new QR code.</div>
                    </div>
                    <div className="account-inline-form">
                        <input
                            type="password"
                            className="form-control"
                            placeholder="Current password"
                            autoComplete="current-password"
                            value={form.data.current_password}
                            onChange={(e) => form.setData('current_password', e.target.value)}
                            required
                        />
                        <button type="submit" className="btn btn-secondary btn-sm" disabled={form.processing}>
                            Replace authenticator
                        </button>
                    </div>
                    {form.errors.current_password && <span className="form-hint" style={{ color: '#fca5a5' }}>{form.errors.current_password}</span>}
                </form>
            </div>
        </section>
    );
}
