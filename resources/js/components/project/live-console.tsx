import { type Ref, useLayoutEffect, useRef } from 'react';
import { StatusBadge } from '@/components/event-item';
import { ansiToHtml } from '@/lib/format';

export const CONSOLE_READY_TEXT = 'Ready. Click "Deploy Now (Execute All)" to run the full pipeline or "▶ Run" on any individual command card.';

/** Right-hand "Live SSH Console" panel. `text` null shows the initial ready hint. */
export function LiveConsole({
    text,
    status,
    timer,
    panelRef,
    onCopy,
    onClear,
}: {
    text: string | null;
    status: string | null;
    timer: string;
    panelRef: Ref<HTMLDivElement>;
    onCopy: () => void;
    onClear: () => void;
}) {
    const outRef = useRef<HTMLPreElement>(null);

    // Keep the newest output in view (legacy outEl.scrollTop = outEl.scrollHeight)
    useLayoutEffect(() => {
        const el = outRef.current;
        if (el && text !== null) el.scrollTop = el.scrollHeight;
    }, [text]);

    return (
        <aside className="project-console-aside">
            <div className="panel terminal-panel" id="liveTerminalPanel" ref={panelRef}>
                <div className="terminal-header">
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                        <div className="terminal-dots">
                            <span className="t-dot red"></span>
                            <span className="t-dot yellow"></span>
                            <span className="t-dot green"></span>
                        </div>
                        <strong style={{ fontSize: 13, whiteSpace: 'nowrap' }}>Live SSH Console</strong>
                        <span id="consoleStateBadge">{status && <StatusBadge status={status} />}</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
                        <span id="consoleTimer" style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-secondary)' }}>
                            {timer}
                        </span>
                        <button type="button" className="btn btn-ghost btn-sm" id="copyConsoleBtn" onClick={onCopy}>
                            Copy
                        </button>
                        <button type="button" className="btn btn-ghost btn-sm" id="clearConsoleBtn" onClick={onClear}>
                            Clear
                        </button>
                    </div>
                </div>
                <pre
                    className="terminal-output"
                    id="terminalOutput"
                    ref={outRef}
                    dangerouslySetInnerHTML={{ __html: ansiToHtml(text ?? CONSOLE_READY_TEXT) }}
                />
            </div>
        </aside>
    );
}
