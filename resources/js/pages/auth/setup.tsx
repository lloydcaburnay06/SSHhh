import { Head, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { url } from '@/lib/api';
import { copyText } from '@/lib/format';
import { AuthBrand, CodeInput, useAuthBodyClass } from './login';

export default function Setup({ secret, secret_display, qr }: { secret: string; secret_display: string; qr: string }) {
    useAuthBodyClass();
    const form = useForm({ code: '', remember_device: true });
    const [copied, setCopied] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url('/setup'), { preserveScroll: true });
    };

    const copy = async () => {
        if (await copyText(secret)) {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <div className="totp-auth-container">
            <Head title="Authenticator Setup" />
            <AuthBrand marginBottom={16} />

            <div className="panel totp-card">
                <div className="totp-card-header">
                    <span className="badge badge-totp-setup">Initial Setup</span>
                    <h1 className="totp-heading">Google Authenticator</h1>
                    <p className="totp-subheading">Link your mobile phone to unlock SSHhh securely without needing passwords.</p>
                </div>

                {form.errors.code && (
                    <div className="ssh-test-banner show err" style={{ marginBottom: 16, fontFamily: 'var(--font-sans)' }}>
                        {form.errors.code}
                    </div>
                )}

                <div className="totp-step-box">
                    <div className="totp-step-title">
                        <span className="totp-step-num">1</span>
                        <span>Scan this QR code with Google Authenticator</span>
                    </div>
                    <div className="totp-qr-wrapper">
                        <img src={qr} alt="Scan with Google Authenticator" className="totp-qr-img" width={180} height={180} />
                    </div>
                    <div className="totp-manual-fallback">
                        <span className="totp-manual-label">Can't scan? Enter this key manually:</span>
                        <div className="totp-secret-row">
                            <code className="totp-secret-code">{secret_display}</code>
                            <button type="button" className="btn btn-secondary btn-sm" onClick={copy}>
                                {copied ? 'Copied!' : 'Copy'}
                            </button>
                        </div>
                    </div>
                </div>

                <form method="post" autoComplete="off" className="totp-form" onSubmit={submit}>
                    <div className="totp-step-title" style={{ marginTop: 16, marginBottom: 8 }}>
                        <span className="totp-step-num">2</span>
                        <span>Enter the 6-digit code from your phone</span>
                    </div>
                    <div className="form-group" style={{ marginBottom: 14 }}>
                        <CodeInput value={form.data.code} onChange={(v) => form.setData('code', v)} />
                    </div>

                    <label className="remember-device-toggle">
                        <input type="checkbox" checked={form.data.remember_device} onChange={(e) => form.setData('remember_device', e.target.checked)} />
                        <span>Remember this device for 30 days</span>
                    </label>

                    <button type="submit" className="btn btn-primary btn-lg" style={{ width: '100%', justifyContent: 'center', marginTop: 14 }} disabled={form.processing}>
                        {form.processing ? 'Activating...' : 'Verify & Activate Authenticator'}
                    </button>
                </form>

                <div className="totp-lockout-notice">
                    <span className="totp-lockout-icon">&#128274;</span>
                    <span>
                        <strong>One-Time Setup:</strong> Once verified, this setup screen and secret key will never be shown again.
                    </span>
                </div>
            </div>
        </div>
    );
}
