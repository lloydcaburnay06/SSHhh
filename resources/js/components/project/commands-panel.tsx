import { type FormEvent, useState } from 'react';
import { CommandCard, type CommandCardHandlers } from './command-card';
import type { EditableCommand, StepState } from './types';

export interface CommandPreset {
    name: string;
    cmd: string;
    trigger?: 'down' | 'up';
    className?: string;
    label: string;
}

/** Quick-add chips (legacy #presetBar data-* attributes). */
export const COMMAND_PRESETS: CommandPreset[] = [
    { name: 'Enable maintenance mode', cmd: 'php artisan down || true', trigger: 'down', className: 'preset-chip-down', label: '+ artisan down' },
    { name: 'Bring application live', cmd: 'php artisan up', trigger: 'up', className: 'preset-chip-up', label: '+ artisan up' },
    { name: 'Pull latest code', cmd: 'git pull origin $DEPLOY_BRANCH', label: '+ git pull' },
    { name: 'Check Git status', cmd: 'git status -sb', label: '+ git status' },
    { name: 'Install Composer dependencies', cmd: 'composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev', label: '+ composer install' },
    { name: 'Build frontend assets', cmd: 'npm ci && npm run build', label: '+ npm build' },
    { name: 'Run Laravel migrations', cmd: 'php artisan migrate --force', label: '+ artisan migrate' },
    { name: 'Optimize caches', cmd: 'php artisan optimize', label: '+ artisan optimize' },
    { name: 'Clear application cache', cmd: 'php artisan optimize:clear', label: '+ optimize:clear' },
    { name: 'Restart queue workers', cmd: 'php artisan queue:restart', label: '+ queue:restart' },
    { name: 'Check failed queue jobs', cmd: 'php artisan queue:failed', label: '+ queue:failed' },
    { name: 'Reload PM2', cmd: 'pm2 reload all', label: '+ pm2 reload' },
];

/** The "Deployment Commands" panel: cards, preset chips and the ad-hoc runner. */
export function CommandsPanel({
    commands,
    stepStates,
    isDirty,
    isExecuting,
    isSaving,
    focusUid,
    handlers,
    onAdd,
    onPreset,
    onSave,
    onAdhoc,
}: {
    commands: EditableCommand[];
    stepStates: Record<number, StepState>;
    isDirty: boolean;
    isExecuting: boolean;
    isSaving: boolean;
    focusUid: number | null;
    handlers: CommandCardHandlers;
    onAdd: () => void;
    onPreset: (preset: CommandPreset) => void;
    onSave: () => void;
    onAdhoc: (command: string) => void;
}) {
    const [adhoc, setAdhoc] = useState('');

    const submitAdhoc = (e: FormEvent) => {
        e.preventDefault();
        const cmd = adhoc.trim();
        if (!cmd) return;
        onAdhoc(cmd);
    };

    return (
        <section className="panel commands-panel">
            <div className="panel-header">
                <div className="panel-title-group">
                    <h2 className="panel-title">Deployment Commands</h2>
                    <span className={'dirty-badge ' + (isDirty ? 'unsaved' : 'saved')} id="commandsDirtyBadge">
                        {isDirty ? 'Unsaved edits (auto-saved on Run / Deploy)' : 'All changes saved'}
                    </span>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <button type="button" className="btn btn-secondary btn-sm" id="addCommandBtn" disabled={isExecuting} onClick={onAdd}>
                        + Add Command
                    </button>
                    <button type="button" className="btn btn-primary btn-sm" id="saveCommandsBtn" disabled={isExecuting || isSaving} onClick={onSave}>
                        {isSaving ? 'Saving...' : 'Save Commands'}
                    </button>
                </div>
            </div>
            <div className="panel-body">
                <div className="commands-grid" id="commandsList">
                    {commands.length === 0 ? (
                        <div className="commands-empty-card">
                            <strong style={{ color: 'var(--text-primary)', display: 'block', marginBottom: 6 }}>No deployment commands configured yet</strong>
                            Click <strong>+ Add Command</strong> or select one of the quick preset chips below to build your deployment pipeline.
                        </div>
                    ) : (
                        commands.map((cmd, idx) => (
                            <CommandCard
                                key={cmd.uid}
                                cmd={cmd}
                                index={idx}
                                total={commands.length}
                                stepState={stepStates[idx]}
                                isExecuting={isExecuting}
                                autoFocus={cmd.uid === focusUid}
                                handlers={handlers}
                            />
                        ))
                    )}
                </div>

                <div className="preset-bar" id="presetBar">
                    <span className="preset-label">Quick add step:</span>
                    {COMMAND_PRESETS.map((p) => (
                        <button key={p.label} type="button" className={'preset-chip' + (p.className ? ' ' + p.className : '')} onClick={() => onPreset(p)}>
                            {p.label}
                        </button>
                    ))}
                </div>

                <form className="adhoc-box" id="adhocForm" autoComplete="off" onSubmit={submitAdhoc}>
                    <input
                        type="text"
                        className="adhoc-input"
                        id="adhocCommandInput"
                        placeholder="Quick one-off SSH command (e.g. git log -n 3 --oneline or df -h)..."
                        value={adhoc}
                        onChange={(e) => setAdhoc(e.target.value)}
                    />
                    <button type="submit" className="btn btn-secondary" id="runAdhocBtn" disabled={isExecuting}>
                        &#9654; Run Once
                    </button>
                </form>
            </div>
        </section>
    );
}
