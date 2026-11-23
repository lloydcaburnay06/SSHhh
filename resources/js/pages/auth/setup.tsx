import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AuthCard, ErrorBanner, Field, submitStyle } from '@/components/auth';
import { url } from '@/lib/api';

/**
 * First run (localhost only): create the super admin. On an upgraded install the
 * super admin takes over the existing projects and keeps the linked authenticator.
 */
export default function Setup({ keeps_authenticator }: { keeps_authenticator: boolean }) {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url('/setup'), { preserveScroll: true });
    };

    const firstError = Object.values(form.errors)[0];

    return (
        <AuthCard
            title="Create the super admin"
            badge="Initial Setup"
            maxWidth={460}
            subtitle="This account manages every other SSHhh user and can open any user's workspace."
        >
            <Head title="Setup" />
            <ErrorBanner message={firstError} />
            <form method="post" className="totp-form" onSubmit={submit}>
                <Field label="Your name" value={form.data.name} onChange={(v) => form.setData('name', v)} autoComplete="name" autoFocus />
                <Field label="Email" type="email" value={form.data.email} onChange={(v) => form.setData('email', v)} autoComplete="username" />
                <Field label="Password (at least 10 characters)" type="password" value={form.data.password} onChange={(v) => form.setData('password', v)} autoComplete="new-password" />
                <Field
                    label="Confirm password"
                    type="password"
                    value={form.data.password_confirmation}
                    onChange={(v) => form.setData('password_confirmation', v)}
                    autoComplete="new-password"
                />
                <button type="submit" className="btn btn-primary btn-lg" style={submitStyle} disabled={form.processing}>
                    {form.processing ? 'Creating...' : 'Create super admin'}
                </button>
            </form>

            <div className="totp-lockout-notice">
                <span className="totp-lockout-icon">&#128274;</span>
                <span>
                    {keeps_authenticator ? (
                        <>
                            <strong>Upgrading:</strong> your existing projects, settings and logs become this account&rsquo;s workspace, and you keep signing in with
                            the Google Authenticator you already linked.
                        </>
                    ) : (
                        <>
                            <strong>Next:</strong> you&rsquo;ll link Google Authenticator. Every account signs in with a password plus its own authenticator code.
                        </>
                    )}
                </span>
            </div>
        </AuthCard>
    );
}
