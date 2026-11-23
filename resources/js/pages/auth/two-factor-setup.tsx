import { Head, router, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { AuthCard, CodeInput, ErrorBanner, submitStyle } from '@/components/auth';
import { url } from '@/lib/api';
import { copyText } from '@/lib/format';

/** Link (or replace) the signed-in user's own Google Authenticator. */
export default function TwoFactorSetup({ secret, secret_display, qr, replacing }: { secret: string; secret_display: string; qr: string; replacing: boolean }) {
    const form = useForm({ code: '' });
    const [copied, setCopied] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url('/two-factor/setup'), { preserveScroll: true, onError: () => form.setData('code', '') });
    };

    const copy = async () => {
        if (await copyText(secret)) {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <AuthCard
            title="Google Authenticator"
            badge={replacing ? 'Replace authenticator' : 'Required'}
            maxWidth={460}
            subtitle={
                replacing
                    ? 'Scan the new code with your phone. Your old authenticator stops working once you confirm.'
                    : 'Every SSHhh account signs in with its password plus a code from its own authenticator app.'
            }
        >
            <Head title="Authenticator Setup" />
            <ErrorBanner message={form.errors.code} />

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
                <button type="submit" className="btn btn-primary btn-lg" style={submitStyle} disabled={form.processing}>
                    {form.processing ? 'Activating...' : 'Verify & Activate Authenticator'}
                </button>
            </form>

            <p style={{ textAlign: 'center', marginTop: 14, fontSize: 12.5 }}>
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => router.post(url('/logout'))}>
                    Sign out
                </button>
            </p>
        </AuthCard>
    );
}
