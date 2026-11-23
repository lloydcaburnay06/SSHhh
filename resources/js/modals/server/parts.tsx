import type { CSSProperties, ReactNode } from 'react';
import type { Project } from '@/types';

/** Shared markup for the live-server modals (legacy server_pulse / env / rollback / remote_logs views). */

export type LoadStatus = 'loading' | 'error' | 'ready';

/** `user@host (ENV)`, optionally with `:port` (legacy subtitle text). */
export function serverSubtitle(project: Project, withPort = false): string {
    const target = `${project.ssh_username}@${project.ssh_host}` + (withPort ? `:${project.ssh_port}` : '');
    return `${target} (${(project.environment || '').toUpperCase()})`;
}

export function errorMessage(err: unknown): string {
    return err instanceof Error ? err.message : String(err);
}

export function ServerModalHeader({
    icon,
    titleId,
    title,
    subtitle,
    onClose,
}: {
    icon: string;
    titleId: string;
    title: string;
    subtitle: string;
    onClose: () => void;
}) {
    return (
        <div className="modal-header">
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <div className="confirm-icon-bubble variant-deploy" style={{ fontSize: 18 }}>
                    {icon}
                </div>
                <div>
                    <h3 id={titleId} className="modal-title" style={{ margin: 0, fontSize: 16 }}>
                        {title}
                    </h3>
                    <p style={{ margin: '2px 0 0', fontSize: 12, color: 'var(--text-secondary)' }}>{subtitle}</p>
                </div>
            </div>
            <button type="button" className="icon-btn" title="Close" onClick={onClose}>
                &times;
            </button>
        </div>
    );
}

export function LoadingState({ title, hint, hidden = false }: { title: string; hint: string; hidden?: boolean }) {
    return (
        <div style={{ textAlign: 'center', padding: '36px 16px', display: hidden ? 'none' : undefined }}>
            <div className="pulse-spinner" style={{ fontSize: 28, marginBottom: 12 }}>
                ⚡
            </div>
            <strong style={{ display: 'block', fontSize: 14, color: 'var(--text-primary)', marginBottom: 4 }}>{title}</strong>
            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{hint}</span>
        </div>
    );
}

export function ErrorState({
    heading,
    message,
    hidden = false,
    style,
    headingMargin = 4,
}: {
    heading: ReactNode;
    message: string;
    hidden?: boolean;
    style?: CSSProperties;
    headingMargin?: number;
}) {
    return (
        <div
            style={{
                display: hidden ? 'none' : undefined,
                padding: 16,
                background: 'rgba(239, 68, 68, 0.1)',
                border: '1px solid rgba(239, 68, 68, 0.3)',
                borderRadius: 10,
                color: '#fca5a5',
                fontSize: 13,
                ...style,
            }}
        >
            <strong style={{ display: 'block', marginBottom: headingMargin, color: '#fecaca' }}>{heading}</strong>
            <span>{message}</span>
        </div>
    );
}
