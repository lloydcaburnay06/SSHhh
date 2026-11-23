import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useBodyClass } from '@/layouts/app-layout';
import { url } from '@/lib/api';
import type { SharedProps } from '@/types';

export function useAuthBodyClass(): void {
    useBodyClass('totp-auth-page');
}

export function AuthBrand({ marginBottom = 20 }: { marginBottom?: number }) {
    const { app } = usePage<SharedProps>().props;
    return (
        <div className="brand" style={{ justifyContent: 'center', marginBottom }}>
            <img src={url('/assets/img/icon-192.png')} alt="SSHhh" className="brand-logo-img" width={44} height={44} />
            <div className="brand-text-wrap" style={{ textAlign: 'left' }}>
                <span className="brand-wordmark">
                    SSH<span className="brand-hush">hh</span>
                </span>
                <span className="brand-tagline">{app.tagline}</span>
            </div>
        </div>
    );
}

/** 6-digit code field, shown as "123 456". */
export function CodeInput({ value, onChange, id }: { value: string; onChange: (v: string) => void; id?: string }) {
    return (
        <input
            type="text"
            id={id}
            className="form-control totp-code-input"
            pattern="[0-9 ]*"
            inputMode="numeric"
            maxLength={7}
            placeholder="000 000"
            autoFocus
            required
            autoComplete="one-time-code"
            value={value}
            onChange={(e) => {
                let v = e.target.value.replace(/\D/g, '').slice(0, 6);
                if (v.length > 3) v = v.slice(0, 3) + ' ' + v.slice(3);
                onChange(v);
            }}
        />
    );
}

export default function Login() {
    useAuthBodyClass();
    const form = useForm({ code: '', remember_device: true });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url('/login'), { preserveScroll: true, onError: () => form.setData('code', '') });
    };

    return (
        <div className="totp-auth-container" style={{ maxWidth: 420 }}>
            <Head title="Unlock" />
            <AuthBrand />

            <div className="panel totp-card">
                <div className="totp-card-header">
                    <h1 className="totp-heading">Unlock SSHhh</h1>
                    <p className="totp-subheading">Enter the 6-digit code from Google Authenticator on your phone.</p>
                </div>

                {form.errors.code && (
                    <div className="ssh-test-banner show err" style={{ marginBottom: 16, fontFamily: 'var(--font-sans)' }}>
                        {form.errors.code}
                    </div>
                )}

                <form method="post" autoComplete="off" className="totp-form" onSubmit={submit}>
                    <div className="form-group" style={{ textAlign: 'center', marginBottom: 16 }}>
                        <label htmlFor="login_code" style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-secondary)', marginBottom: 8, display: 'block' }}>
                            Authenticator Code
                        </label>
                        <CodeInput id="login_code" value={form.data.code} onChange={(v) => form.setData('code', v)} />
                    </div>

                    <label className="remember-device-toggle">
                        <input type="checkbox" checked={form.data.remember_device} onChange={(e) => form.setData('remember_device', e.target.checked)} />
                        <span>Remember this device for 30 days</span>
                    </label>

                    <button type="submit" className="btn btn-primary btn-lg" style={{ width: '100%', justifyContent: 'center', marginTop: 16 }} disabled={form.processing}>
                        {form.processing ? 'Unlocking...' : 'Unlock SSHhh'}
                    </button>
                </form>
            </div>
        </div>
    );
}
