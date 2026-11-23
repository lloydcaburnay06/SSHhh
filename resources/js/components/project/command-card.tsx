import { formatDuration } from '@/lib/format';
import type { EditableCommand, StepState } from './types';

export interface CommandCardHandlers {
    onText: (index: number, field: 'name' | 'command', value: string) => void;
    onToggle: (index: number, field: 'is_enabled' | 'stop_on_error', checked: boolean) => void;
    onTrigger: (index: number, trigger: 'down' | 'up', checked: boolean) => void;
    onMove: (index: number, direction: -1 | 1) => void;
    onDelete: (index: number) => void;
    onRun: (index: number) => void;
}

/** One editable deployment step (legacy renderCommands() card markup). */
export function CommandCard({
    cmd,
    index,
    total,
    stepState,
    isExecuting,
    autoFocus,
    handlers,
}: {
    cmd: EditableCommand;
    index: number;
    total: number;
    stepState: StepState | undefined;
    isExecuting: boolean;
    autoFocus: boolean;
    handlers: CommandCardHandlers;
}) {
    const classes = ['command-step', !cmd.is_enabled ? 'disabled-step' : '', stepState ? stepState + '-step' : ''].filter(Boolean).join(' ');
    const linesCount = Math.max(2, Math.min(7, String(cmd.command || '').split('\n').length));

    let lastRun = <span className="step-ready-hint">{cmd.is_enabled ? 'Ready' : 'Skipped in Deploy All'}</span>;
    if (cmd.last_exit_code !== null && cmd.last_exit_code !== undefined) {
        const ok = Number(cmd.last_exit_code) === 0;
        const dur = cmd.last_duration_ms !== null ? ' • ' + formatDuration(cmd.last_duration_ms) : '';
        lastRun = (
            <span className={`last-exit-tag ${ok ? 'last-exit-ok' : 'last-exit-err'}`} title={`Last run: ${cmd.last_run_at || ''}`}>
                exit {cmd.last_exit_code}
                {dur}
            </span>
        );
    }

    return (
        <article className={classes} data-step-index={index}>
            <div className="step-top-row">
                <span className="step-num">#{index + 1}</span>
                <input
                    type="text"
                    className="step-name-input"
                    value={cmd.name}
                    placeholder="Step label (e.g. Pull latest code)"
                    onChange={(e) => handlers.onText(index, 'name', e.target.value)}
                />
                <div className="step-order-btns">
                    <button type="button" className="icon-btn" title="Move Earlier" disabled={index === 0 || isExecuting} onClick={() => handlers.onMove(index, -1)}>
                        &larr;
                    </button>
                    <button type="button" className="icon-btn" title="Move Later" disabled={index === total - 1 || isExecuting} onClick={() => handlers.onMove(index, 1)}>
                        &rarr;
                    </button>
                    <button type="button" className="icon-btn danger" title="Remove Step" disabled={isExecuting} onClick={() => handlers.onDelete(index)}>
                        &times;
                    </button>
                </div>
            </div>
            <textarea
                className="step-cmd-editor"
                rows={linesCount}
                placeholder="Enter SSH command (e.g. git pull origin main)"
                spellCheck={false}
                autoFocus={autoFocus}
                value={cmd.command}
                onChange={(e) => handlers.onText(index, 'command', e.target.value)}
            />
            <div className="step-card-footer">
                <div className="step-toggles">
                    <label className="toggle-label" title="Include this command when clicking Deploy Now (Execute All)">
                        <input type="checkbox" checked={cmd.is_enabled} onChange={(e) => handlers.onToggle(index, 'is_enabled', e.target.checked)} />
                        <span>Deploy All</span>
                    </label>
                    <label className="toggle-label" title="Stop deployment pipeline if this command exits with a non-zero error code">
                        <input type="checkbox" checked={cmd.stop_on_error} onChange={(e) => handlers.onToggle(index, 'stop_on_error', e.target.checked)} />
                        <span>Stop on error</span>
                    </label>
                    <div className="step-lifecycle-trigger">
                        <span style={{ fontSize: 11, color: 'var(--text-muted)', marginRight: 2 }}>App State:</span>
                        <label
                            className={'lifecycle-label label-down' + (cmd.app_state_trigger === 'down' ? ' active' : '')}
                            title="Mark project as DOWN (maintenance mode) when this step succeeds"
                        >
                            <input type="checkbox" checked={cmd.app_state_trigger === 'down'} onChange={(e) => handlers.onTrigger(index, 'down', e.target.checked)} />
                            <span>🔴 Sets Down</span>
                        </label>
                        <label
                            className={'lifecycle-label label-up' + (cmd.app_state_trigger === 'up' ? ' active' : '')}
                            title="Mark project as UP (live) when this step succeeds"
                        >
                            <input type="checkbox" checked={cmd.app_state_trigger === 'up'} onChange={(e) => handlers.onTrigger(index, 'up', e.target.checked)} />
                            <span>🟢 Sets Up</span>
                        </label>
                    </div>
                </div>
                <div className="step-actions">
                    {lastRun}
                    <button type="button" className="btn btn-secondary btn-sm" disabled={isExecuting} onClick={() => handlers.onRun(index)}>
                        &#9654; Run
                    </button>
                </div>
            </div>
        </article>
    );
}
