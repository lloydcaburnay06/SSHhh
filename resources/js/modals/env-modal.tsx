import { useCallback, useEffect, useRef, useState } from 'react';
import { useConfirm, useToast } from '@/components/feedback';
import { ModalShell } from '@/components/modal-shell';
import { api } from '@/lib/api';
import { copyText } from '@/lib/format';
import type { ModalProps, ProjectRefState } from './modals-context';
import { ErrorState, errorMessage, type LoadStatus, LoadingState, ServerModalHeader, serverSubtitle } from './server/parts';

/** Legacy initRemoteEnvModal / env_modal.php. */
export default function EnvModal({ state, onClose }: ModalProps<ProjectRefState>) {
    const { project } = state;
    const toast = useToast();
    const confirm = useConfirm();

    const [status, setStatus] = useState<LoadStatus>('loading');
    const [error, setError] = useState('');
    const [content, setContent] = useState('');
    const [original, setOriginal] = useState('');
    const [currentDir, setCurrentDir] = useState('');
    const [missingKeys, setMissingKeys] = useState<string[]>([]);
    const [insertedKeys, setInsertedKeys] = useState<Set<string>>(new Set());
    /** Legacy saveBtn.disabled: true until the text differs from what was loaded (or a key was inserted). */
    const [saveDisabled, setSaveDisabled] = useState(true);
    const [saving, setSaving] = useState(false);

    // Close checks read the latest values (also from the Escape / backdrop handler).
    const latest = useRef({ content, original, saveDisabled, saving });
    latest.current = { content, original, saveDisabled, saving };

    const requestClose = useCallback(() => {
        const { content: value, original: orig, saveDisabled, saving: busy } = latest.current;
        if (!saveDisabled && !busy && value !== orig) {
            if (!window.confirm('You have unsaved changes in .env. Discard and close?')) return;
        }
        onClose();
    }, [onClose]);

    const fetchEnv = useCallback(async () => {
        setStatus('loading');
        setSaveDisabled(true);
        try {
            const res = await api.getEnv(project.id);
            if (!res || !res.result) throw new Error('Failed to query remote .env');
            const d = res.result;
            const env = d.env || '';
            setOriginal(env);
            setContent(env);
            setCurrentDir(d.current_dir || '');
            setMissingKeys(Array.isArray(d.missing_keys) ? d.missing_keys : []);
            setInsertedKeys(new Set());
            setSaveDisabled(true);
            setStatus('ready');
        } catch (err) {
            setError(errorMessage(err));
            setStatus('error');
        }
    }, [project.id]);

    useEffect(() => {
        void fetchEnv();
    }, [fetchEnv]);

    const insertKey = (key: string) => {
        setContent((value) => (value.endsWith('\n') || value === '' ? value + `${key}=\n` : value + `\n${key}=\n`));
        setInsertedKeys((prev) => new Set(prev).add(key));
        setSaveDisabled(false);
        toast(`Appended ${key}= to .env editor.`, 'info');
    };

    const copyEnv = async () => {
        if (content && (await copyText(content))) toast('.env copied to clipboard.', 'success');
    };

    const save = async () => {
        const isProd = project.environment === 'production';
        const ok = await confirm({
            title: 'Save Remote .env?',
            subtitle: `${project.name} [${project.environment.toUpperCase()}]`,
            message: 'A timestamped backup of the current <code>.env</code> will be created on the remote server before overwriting.',
            warning: isProd ? 'Caution: Updating secrets on PRODUCTION may immediately affect live database connections and services.' : undefined,
            confirmText: 'Create Backup & Save',
            confirmClass: isProd ? 'btn-danger' : 'btn-primary',
            icon: '💾',
            iconVariant: isProd ? 'danger' : 'deploy',
        });
        if (!ok) return;

        setSaving(true);
        try {
            const res = await api.saveEnv(project.id, content);
            toast(res.result?.message || 'Remote .env saved successfully.', 'success');
            onClose(); // saved content is now the original, so no unsaved-changes prompt
        } catch (err) {
            toast('Failed to save .env: ' + errorMessage(err), 'error');
            setSaving(false);
        }
    };

    const visibleMissing = missingKeys.filter((k) => !insertedKeys.has(k));

    return (
        <ModalShell className="remote-env-modal" style={{ maxWidth: 760, width: '92%' }} labelledBy="envModalTitle" onRequestClose={requestClose}>
            <ServerModalHeader
                icon="📝"
                titleId="envModalTitle"
                title={`.env Secrets — ${project.name}`}
                subtitle={serverSubtitle(project)}
                onClose={requestClose}
            />

            <div className="modal-body" style={{ padding: '16px 20px' }}>
                {status === 'loading' && (
                    <LoadingState title="Connecting & Fetching .env..." hint="Reading remote .env and comparing with .env.example over secure SSH." />
                )}

                {status === 'error' && <ErrorState heading="Failed to read remote .env:" message={error} style={{ marginBottom: 12 }} />}

                {status === 'ready' && (
                    <div>
                        {missingKeys.length > 0 && (
                            <div
                                style={{
                                    padding: '12px 14px',
                                    background: 'rgba(245, 158, 11, 0.12)',
                                    border: '1px solid rgba(245, 158, 11, 0.3)',
                                    borderRadius: 8,
                                    marginBottom: 14,
                                }}
                            >
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
                                    <span style={{ fontSize: 16 }}>⚠️</span>
                                    <strong style={{ fontSize: 12.5, color: '#fde68a' }}>Missing Environment Variables Detected!</strong>
                                </div>
                                <p style={{ fontSize: 11.5, color: '#fef3c7', margin: '0 0 8px 0' }}>
                                    The following key(s) exist in <code>.env.example</code> but are missing from your remote <code>.env</code>:
                                </p>
                                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                                    {visibleMissing.map((k) => (
                                        <button
                                            key={k}
                                            type="button"
                                            className="btn btn-xs"
                                            style={{
                                                background: '#b45309',
                                                color: '#fef3c7',
                                                border: 'none',
                                                borderRadius: 4,
                                                fontFamily: 'var(--font-mono)',
                                                fontSize: 11,
                                                padding: '2px 6px',
                                            }}
                                            onClick={() => insertKey(k)}
                                        >
                                            + {k}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                            <label htmlFor="envTextarea" className="form-label" style={{ marginBottom: 0 }}>
                                <span>Remote .env Contents</span>
                                <span style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 'normal', marginLeft: 6 }}>
                                    {currentDir ? `(${currentDir}/.env)` : ''}
                                </span>
                            </label>
                            <div style={{ display: 'flex', gap: 6 }}>
                                <button type="button" className="btn btn-ghost btn-xs" title="Copy .env to clipboard" onClick={() => void copyEnv()}>
                                    📋 Copy
                                </button>
                                <button type="button" className="btn btn-ghost btn-xs" title="Reload from server" onClick={() => void fetchEnv()}>
                                    🔄 Reload
                                </button>
                            </div>
                        </div>

                        <textarea
                            id="envTextarea"
                            className="step-cmd-editor"
                            rows={14}
                            placeholder={'APP_NAME=Laravel\nAPP_ENV=production...'}
                            spellCheck={false}
                            style={{ fontFamily: 'var(--font-mono)', fontSize: 12, lineHeight: 1.5, tabSize: 4 }}
                            value={content}
                            onChange={(e) => {
                                setContent(e.target.value);
                                setSaveDisabled(e.target.value === original);
                            }}
                        />
                        <span className="form-hint" style={{ marginTop: 6, display: 'block' }}>
                            🛡️ Saving creates an automatic timestamped backup (<code>.env.backup.YYYYMMDD_HHMMSS</code>) on the remote server before changes are
                            committed.
                        </span>
                    </div>
                )}
            </div>

            <div className="modal-footer" style={{ justifyContent: 'space-between' }}>
                <button type="button" className="btn btn-ghost btn-sm" onClick={requestClose}>
                    Cancel
                </button>
                <button type="button" className="btn btn-primary btn-sm" disabled={saveDisabled || saving} onClick={() => void save()}>
                    {saving ? (
                        <>
                            <span>⏳</span>
                            <span>Saving...</span>
                        </>
                    ) : (
                        <>
                            <span>💾</span> <span>Backup &amp; Save .env</span>
                        </>
                    )}
                </button>
            </div>
        </ModalShell>
    );
}
