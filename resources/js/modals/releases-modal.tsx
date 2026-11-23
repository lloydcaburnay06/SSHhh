import { useCallback, useEffect, useState } from 'react';
import { useConfirm, useToast } from '@/components/feedback';
import { ModalShell } from '@/components/modal-shell';
import { api } from '@/lib/api';
import { escapeHtml } from '@/lib/format';
import type { ModalProps, ReleasesModalState } from './modals-context';
import { ErrorState, errorMessage, type LoadStatus, LoadingState, ServerModalHeader, serverSubtitle } from './server/parts';

interface Release {
    id: string;
    date: string;
    is_active: boolean;
    [key: string]: unknown;
}

/** Legacy initReleasesModal / rollback_modal.php. */
export default function ReleasesModal({ state, onClose }: ModalProps<ReleasesModalState>) {
    const { project, onDone } = state;
    const toast = useToast();
    const confirm = useConfirm();

    const [status, setStatus] = useState<LoadStatus>('loading');
    const [error, setError] = useState('');
    const [strategy, setStrategy] = useState<string>('');
    const [releases, setReleases] = useState<Release[]>([]);
    const [rollingBack, setRollingBack] = useState<string | null>(null);

    const fetchReleases = useCallback(async () => {
        setStatus('loading');
        try {
            const res = await api.listReleases(project.id);
            if (!res || !res.result) throw new Error('Failed to query releases');
            const d = res.result;
            setStrategy(d.deploy_strategy);
            setReleases(Array.isArray(d.releases) ? d.releases : []);
            setStatus('ready');
        } catch (err) {
            setError(errorMessage(err));
            setStatus('error');
        }
    }, [project.id]);

    useEffect(() => {
        void fetchReleases();
    }, [fetchReleases]);

    const rollback = async (targetRel: string) => {
        const ok = await confirm({
            title: `Rollback to Release ${targetRel}?`,
            subtitle: `${project.name} [${project.environment.toUpperCase()}]`,
            message: `The active <code>current</code> symlink will be immediately repointed to <code>releases/${escapeHtml(targetRel)}</code> with zero downtime, and queue workers will be signaled to restart.`,
            confirmText: 'Rollback Now',
            confirmClass: 'btn-warning',
            icon: '⏪',
            iconVariant: 'danger',
        });
        if (!ok) return;

        setRollingBack(targetRel);
        try {
            const res = await api.rollbackRelease(project.id, targetRel);
            toast(res.result?.message || `Rolled back to ${targetRel}`, 'success');
            setRollingBack(null);
            await fetchReleases();
            onDone?.(res.result);
        } catch (err) {
            toast('Rollback failed: ' + errorMessage(err), 'error');
            setRollingBack(null);
        }
    };

    return (
        <ModalShell className="releases-modal" style={{ maxWidth: 680, width: '92%' }} labelledBy="releasesModalTitle" onRequestClose={onClose}>
            <ServerModalHeader
                icon="⏪"
                titleId="releasesModalTitle"
                title={`Releases & Rollback — ${project.name}`}
                subtitle={serverSubtitle(project)}
                onClose={onClose}
            />

            <div className="modal-body" style={{ padding: '16px 20px' }}>
                {status === 'loading' && <LoadingState title="Scanning Historical Releases..." hint="Querying releases folder and active symlink over SSH." />}

                {status === 'error' && <ErrorState heading="Failed to query releases:" message={error} />}

                {status === 'ready' && (
                    <div>
                        {strategy !== 'symlink' && (
                            <div
                                style={{
                                    padding: '12px 14px',
                                    background: 'rgba(99, 102, 241, 0.1)',
                                    border: '1px solid rgba(99, 102, 241, 0.25)',
                                    borderRadius: 8,
                                    marginBottom: 14,
                                    fontSize: 12,
                                    color: 'var(--text-secondary)',
                                }}
                            >
                                💡 <strong>Direct Directory Mode:</strong> This project is currently configured to deploy directly into the working directory. To
                                enable atomic zero-downtime releases with 1-click rollback, change <strong>Deployment Strategy</strong> to{' '}
                                <strong>Atomic Symlink</strong> in project settings.
                            </div>
                        )}

                        <div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                                <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-secondary)' }}>Available Historical Releases</span>
                                <button type="button" className="btn btn-ghost btn-xs" onClick={() => void fetchReleases()}>
                                    🔄 Refresh
                                </button>
                            </div>
                            <div className="releases-list" style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                {releases.length === 0 ? (
                                    <div style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)' }}>
                                        No release folders found in <code>releases/</code> yet. Run an atomic zero-downtime deployment to create your first release
                                        snapshot.
                                    </div>
                                ) : (
                                    releases.map((r, idx) => (
                                        <div
                                            key={r.id}
                                            style={{
                                                display: 'flex',
                                                justifyContent: 'space-between',
                                                alignItems: 'center',
                                                padding: '12px 14px',
                                                background: 'var(--bg-elevated)',
                                                border: '1px solid var(--border-color)',
                                                borderRadius: 8,
                                            }}
                                        >
                                            <div>
                                                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                                                    <strong style={{ fontFamily: 'var(--font-mono)', fontSize: 13, color: 'var(--text-primary)' }}>{r.id}</strong>
                                                    {r.is_active ? (
                                                        <span className="badge badge-up">🟢 CURRENT ACTIVE</span>
                                                    ) : (
                                                        <span className="badge" style={{ opacity: 0.75 }}>
                                                            Historical Release #{releases.length - idx}
                                                        </span>
                                                    )}
                                                </div>
                                                <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{r.date}</span>
                                            </div>
                                            <div>
                                                {r.is_active ? (
                                                    <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Live</span>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        className="btn btn-warning btn-xs"
                                                        disabled={rollingBack === r.id}
                                                        onClick={() => void rollback(r.id)}
                                                    >
                                                        {rollingBack === r.id ? 'Rolling back...' : 'Rollback to This ⏪'}
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <div className="modal-footer">
                <button type="button" className="btn btn-ghost btn-sm" onClick={onClose}>
                    Close
                </button>
            </div>
        </ModalShell>
    );
}
