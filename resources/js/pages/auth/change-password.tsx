import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AuthCard, ErrorBanner, Field, submitStyle } from '@/components/auth';
import { url } from '@/lib/api';

/** Replace the temporary password an admin set (also reachable from the profile page). */
export default function ChangePassword({ forced }: { forced: boolean }) {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(url('/password'), { preserveScroll: true, onError: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <AuthCard
            title={forced ? 'Choose your own password' : 'Change password'}
            badge={forced ? 'Required' : undefined}
            subtitle={forced ? 'Your account was set up with a temporary password. Replace it to continue.' : undefined}
        >
            <Head title="Change password" />
            <ErrorBanner message={form.errors.current_password || form.errors.password} />
            <form method="post" className="totp-form" onSubmit={submit}>
                <Field
                    label={forced ? 'Temporary password' : 'Current password'}
                    type="password"
                    value={form.data.current_password}
                    onChange={(v) => form.setData('current_password', v)}
                    autoComplete="current-password"
                    autoFocus
                />
                <Field label="New password (at least 10 characters)" type="password" value={form.data.password} onChange={(v) => form.setData('password', v)} autoComplete="new-password" />
                <Field
                    label="Confirm new password"
                    type="password"
                    value={form.data.password_confirmation}
                    onChange={(v) => form.setData('password_confirmation', v)}
                    autoComplete="new-password"
                />
                <button type="submit" className="btn btn-primary btn-lg" style={submitStyle} disabled={form.processing}>
                    {form.processing ? 'Saving...' : 'Save password'}
                </button>
            </form>
            {forced && (
                <p style={{ textAlign: 'center', marginTop: 14, fontSize: 12.5 }}>
                    <button type="button" className="btn btn-ghost btn-sm" onClick={() => router.post(url('/logout'))}>
                        Sign out
                    </button>
                </p>
            )}
        </AuthCard>
    );
}
