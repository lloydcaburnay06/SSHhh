import { useMemo, useState } from 'react';
import { EventItem } from '@/components/event-item';
import { useToast } from '@/components/feedback';
import { copyText } from '@/lib/format';
import type { EventLog } from '@/types';
import { COMMAND_DIRECTORY, COMMAND_DIRECTORY_CATEGORIES, type DirectoryCommand } from './commands-data';

type AsideTab = 'events' | 'directory';

/** Right-hand aside: recent global event log + developer command directory tabs. */
export function DashboardAside({
    events,
    onRefresh,
    onRunCommand,
}: {
    events: EventLog[];
    onRefresh: () => void;
    onRunCommand: (command: DirectoryCommand, asAdmin: boolean) => void;
}) {
    const [tab, setTab] = useState<AsideTab>('events');

    return (
        <aside className="dashboard-aside">
            <section className="panel global-events-panel aside-tabbed-panel">
                <div className="panel-header aside-tab-header">
                    <div className="aside-tab-nav" role="tablist">
                        <button
                            type="button"
                            className={'aside-tab-btn' + (tab === 'events' ? ' active' : '')}
                            id="asideTabEventsBtn"
                            role="tab"
                            aria-selected={tab === 'events'}
                            aria-controls="asidePaneEvents"
                            onClick={() => setTab('events')}
                        >
                            <span>📜 Event Logs</span>
                            <span className="aside-tab-count">{events.length}</span>
                        </button>
                        <button
                            type="button"
                            className={'aside-tab-btn' + (tab === 'directory' ? ' active' : '')}
                            id="asideTabDirectoryBtn"
                            role="tab"
                            aria-selected={tab === 'directory'}
                            aria-controls="asidePaneDirectory"
                            onClick={() => setTab('directory')}
                        >
                            <span>⚡ Commands</span>
                            <span className="aside-tab-count">{COMMAND_DIRECTORY.length}</span>
                        </button>
                    </div>
                    <div className="aside-header-actions" style={tab === 'events' ? undefined : { display: 'none' }}>
                        <button type="button" className="btn btn-ghost btn-xs" title="Refresh recent events" onClick={onRefresh}>
                            Refresh
                        </button>
                    </div>
                </div>

                {/* Tab 1: Recent Global Event Log */}
                <div
                    className={'aside-tab-pane' + (tab === 'events' ? ' active' : '')}
                    id="asidePaneEvents"
                    role="tabpanel"
                    aria-labelledby="asideTabEventsBtn"
                    style={tab === 'events' ? undefined : { display: 'none' }}
                >
                    <div className="events-list global-events-scroll">
                        {events.length === 0 ? (
                            <div style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)' }}>No events logged yet.</div>
                        ) : (
                            events.map((ev) => <EventItem key={ev.id} event={ev} showProjectName />)
                        )}
                    </div>
                </div>

                {/* Tab 2: Directory of Useful Laravel & Networking Commands */}
                <div
                    className={'aside-tab-pane' + (tab === 'directory' ? ' active' : '')}
                    id="asidePaneDirectory"
                    role="tabpanel"
                    aria-labelledby="asideTabDirectoryBtn"
                    style={tab === 'directory' ? undefined : { display: 'none' }}
                >
                    <CommandDirectory onRunCommand={onRunCommand} />
                </div>
            </section>
        </aside>
    );
}

function CommandDirectory({ onRunCommand }: { onRunCommand: (command: DirectoryCommand, asAdmin: boolean) => void }) {
    const toast = useToast();
    const [activeCat, setActiveCat] = useState('all');
    const [search, setSearch] = useState('');

    const catMap = useMemo(() => new Map(COMMAND_DIRECTORY_CATEGORIES.map((c) => [c.id, c])), []);

    const q = search.trim().toLowerCase();
    const filtered = COMMAND_DIRECTORY.filter((cmd) => {
        if (activeCat !== 'all' && cmd.category !== activeCat) return false;
        if (!q) return true;
        const hay = [cmd.title, cmd.command, cmd.desc, cmd.category, Array.isArray(cmd.tags) ? cmd.tags.join(' ') : ''].join(' ').toLowerCase();
        return hay.includes(q);
    });

    const copy = async (command: string) => {
        if (command && (await copyText(command))) toast('Command copied to clipboard.', 'success');
    };

    return (
        <>
            <div className="cmddir-toolbar">
                <div className="cmddir-search-wrap">
                    <input
                        type="search"
                        className="search-input cmddir-search-input"
                        placeholder="Search commands, artisan, nginx, git, ports..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <div className="cmddir-filters-bar">
                    {COMMAND_DIRECTORY_CATEGORIES.map((cat) => {
                        const count = cat.id === 'all' ? COMMAND_DIRECTORY.length : COMMAND_DIRECTORY.filter((c) => c.category === cat.id).length;
                        return (
                            <button
                                key={cat.id}
                                type="button"
                                className={'filter-pill ' + (activeCat === cat.id ? 'active' : '')}
                                data-cmd-cat={cat.id}
                                onClick={() => setActiveCat(cat.id)}
                            >
                                <span>{cat.icon}</span> <span>{cat.label}</span> (<span className="tab-count">{count}</span>)
                            </button>
                        );
                    })}
                </div>
            </div>
            <div className="cmddir-scroll">
                {filtered.length === 0 ? (
                    <div style={{ padding: '32px 16px', textAlign: 'center', color: 'var(--text-muted)' }}>
                        <div style={{ fontSize: 24, marginBottom: 8 }}>🔍</div>
                        <strong style={{ display: 'block', color: 'var(--text-primary)', marginBottom: 4 }}>No matching commands found</strong>
                        <span style={{ fontSize: 12 }}>Try adjusting your search query or switching to another category.</span>
                    </div>
                ) : (
                    filtered.map((cmd) => {
                        const cat = catMap.get(cmd.category);
                        const catLabel = cat ? cat.label : cmd.category;
                        const catClass = cat && cat.badgeClass ? cat.badgeClass : 'cat-' + cmd.category;
                        return (
                            <div className="cmddir-item" key={cmd.id} data-cmd-id={cmd.id}>
                                <div className="cmddir-item-header">
                                    <div className="cmddir-item-title-wrap">
                                        <span className={`cat-badge ${catClass}`}>{catLabel}</span>
                                        <strong className="cmddir-item-title">{cmd.title}</strong>
                                    </div>
                                    {cmd.suggestSudo && (
                                        <span className="sudo-tag" title="Command typically requires sudo/root access">
                                            sudo
                                        </span>
                                    )}
                                </div>
                                {cmd.desc && <p className="cmddir-item-desc">{cmd.desc}</p>}
                                <div className="cmddir-code-wrap">
                                    <code className="cmddir-code" title={cmd.command}>
                                        {cmd.command}
                                    </code>
                                    <button type="button" className="cmddir-copy-btn" title="Copy command to clipboard" onClick={() => copy(cmd.command)}>
                                        📋
                                    </button>
                                </div>
                                <div className="cmddir-item-actions">
                                    <button type="button" className="btn btn-xs cmddir-run-btn" title="Edit & Run command" onClick={() => onRunCommand(cmd, false)}>
                                        <span>▶</span> <span>Run</span>
                                    </button>
                                    <button
                                        type="button"
                                        className="btn btn-xs cmddir-admin-btn"
                                        title="Edit & Run with sudo (Admin privileges)"
                                        onClick={() => onRunCommand(cmd, true)}
                                    >
                                        <span>⚡</span> <span>Run as Admin</span>
                                    </button>
                                </div>
                            </div>
                        );
                    })
                )}
            </div>
        </>
    );
}
