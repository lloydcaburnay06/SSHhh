import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useBodyClass } from '@/layouts/app-layout';
import { url } from '@/lib/api';
import type { SharedProps } from '@/types';

/** Shared pieces of the sign-in, setup and onboarding screens. */

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

/** Centered card used by every auth screen. */
export function AuthCard({ title, subtitle, badge, maxWidth = 420, children }: { title: string; subtitle?: ReactNode; badge?: string; maxWidth?: number; children: ReactNode }) {
    useAuthBodyClass();
    return (
        <div className="totp-auth-container" style={{ maxWidth }}>
            <AuthBrand />
            <div className="panel totp-card">
                <div className="totp-card-header">
                    {badge && <span className="badge badge-totp-setup">{badge}</span>}
                    <h1 className="totp-heading">{title}</h1>
                    {subtitle && <p className="totp-subheading">{subtitle}</p>}
                </div>
                {children}
            </div>
        </div>
    );
}

export function ErrorBanner({ message }: { message?: string }) {
    if (!message) return null;
    return (
        <div className="ssh-test-banner show err" style={{ marginBottom: 16, fontFamily: 'var(--font-sans)' }}>
            {message}
        </div>
    );
}

/** Labelled text input in the auth card style. */
export function Field({
    label,
    type = 'text',
    value,
    onChange,
    error,
    autoComplete,
    autoFocus,
}: {
    label: string;
    type?: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
    autoComplete?: string;
    autoFocus?: boolean;
}) {
    return (
        <div className="form-group" style={{ marginBottom: 12 }}>
            <label style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-secondary)', marginBottom: 6, display: 'block' }}>{label}</label>
            <input
                type={type}
                className="form-control"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                autoComplete={autoComplete}
                autoFocus={autoFocus}
                required
            />
            {error && <span className="form-hint" style={{ color: '#fca5a5' }}>{error}</span>}
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

export const submitStyle = { width: '100%', justifyContent: 'center', marginTop: 14 } as const;
