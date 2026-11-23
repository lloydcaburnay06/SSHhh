import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AuthCard, ErrorBanner, Field, submitStyle } from '@/components/auth';
import { url } from '@/lib/api';

/** Step 1 of sign-in: email and password (step 2 is the authenticator code). */
export default function Login() {
    const form = useForm({ email: '', password: '', remember: true });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url('/login'), { preserveScroll: true, onError: () => form.setData('password', '') });
    };

    return (
        <AuthCard title="Sign in to SSHhh" subtitle="Use the email and password your SSHhh admin gave you.">
            <Head title="Sign in" />
            <ErrorBanner message={form.errors.email} />
            <form method="post" className="totp-form" onSubmit={submit}>
                <Field label="Email" type="email" value={form.data.email} onChange={(v) => form.setData('email', v)} autoComplete="username" autoFocus />
                <Field label="Password" type="password" value={form.data.password} onChange={(v) => form.setData('password', v)} autoComplete="current-password" />

                <label className="remember-device-toggle">
                    <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                    <span>Remember this device for 30 days</span>
                </label>

                <button type="submit" className="btn btn-primary btn-lg" style={submitStyle} disabled={form.processing}>
                    {form.processing ? 'Checking...' : 'Continue'}
                </button>
            </form>
        </AuthCard>
    );
}
