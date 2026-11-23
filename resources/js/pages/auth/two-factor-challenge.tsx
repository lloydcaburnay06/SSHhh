import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AuthCard, CodeInput, ErrorBanner, submitStyle } from '@/components/auth';
import { url } from '@/lib/api';

/** Step 2 of sign-in: the code from the user's own Google Authenticator. */
export default function TwoFactorChallenge({ email }: { email: string }) {
    const form = useForm({ code: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url('/two-factor/challenge'), { preserveScroll: true, onError: () => form.setData('code', '') });
    };

    return (
        <AuthCard title="Unlock SSHhh" subtitle={<>Enter the 6-digit code from Google Authenticator for <strong>{email}</strong>.</>}>
            <Head title="Authenticator code" />
            <ErrorBanner message={form.errors.code} />
            <form method="post" autoComplete="off" className="totp-form" onSubmit={submit}>
                <div className="form-group" style={{ textAlign: 'center', marginBottom: 16 }}>
                    <label htmlFor="login_code" style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-secondary)', marginBottom: 8, display: 'block' }}>
                        Authenticator Code
                    </label>
                    <CodeInput id="login_code" value={form.data.code} onChange={(v) => form.setData('code', v)} />
                </div>
                <button type="submit" className="btn btn-primary btn-lg" style={submitStyle} disabled={form.processing}>
                    {form.processing ? 'Unlocking...' : 'Unlock SSHhh'}
                </button>
            </form>
            <p style={{ textAlign: 'center', marginTop: 14, fontSize: 12.5 }}>
                <Link href={url('/login')}>Use a different account</Link>
            </p>
        </AuthCard>
    );
}
