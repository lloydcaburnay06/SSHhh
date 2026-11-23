import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useBodyClass } from '@/layouts/app-layout';
import { url } from '@/lib/api';

interface AlertView {
    project_name: string;
    created_at: string;
    issue_count: number;
    summary: string;
    status: string;
    status_text: string;
}

/**
 * Error alert decision page opened from a push notification (no sign-in needed:
 * the single-use token in the link is the permission).
 */
export default function Alert({ token, alert, error }: { token: string; alert: AlertView | null; error: string | null }) {
    useBodyClass('alert-decision-page');
    const [busy, setBusy] = useState(false);
    const [decided, setDecided] = useState(false);
    const [status, setStatus] = useState<string>(alert?.status_text ?? '');
    const [sessionUrl, setSessionUrl] = useState<string | null>(null);

    const decide = async (choice: 'proceed' | 'ignore') => {
        setBusy(true);
        setStatus(choice === 'proceed' ? 'Sending to Claude Code…' : 'Saving…');
        try {
            const res = await fetch(url('/alert/decide'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ token, choice, via: 'page' }),
            });
            const data = await res.json();
            setStatus(data.message || data.error || 'Done.');
            setSessionUrl(data.session_url || null);
            setDecided(true);
        } catch (e) {
            setStatus('Could not reach SSHhh: ' + (e as Error).message);
            setBusy(false);
        }
    };

    return (
        <div className="alert-card">
            <Head title="Error alert" />
            {error !== null || !alert ? (
                <>
                    <h1>⚠️ Alert unavailable</h1>
                    <p className="status">{error}</p>
                </>
            ) : (
                <>
                    <h1>⚠️ {alert.project_name}: new error</h1>
                    <div className="meta">
                        Found {alert.created_at} · {alert.issue_count} new issue{alert.issue_count === 1 ? '' : 's'}
                    </div>
                    <div className="summary">{alert.summary}</div>
                    {alert.status === 'pending' && !decided && (
                        <div className="actions">
                            <button type="button" className="btn btn-deploy" disabled={busy} onClick={() => decide('proceed')}>
                                🤖 Proceed — send to Claude
                            </button>
                            <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => decide('ignore')}>
                                Ignore
                            </button>
                        </div>
                    )}
                    <p className="status">
                        {status}
                        {sessionUrl && (
                            <a href={sessionUrl} target="_blank" rel="noopener">
                                {' '}
                                Open the Claude Code session
                            </a>
                        )}
                    </p>
                </>
            )}
        </div>
    );
}
