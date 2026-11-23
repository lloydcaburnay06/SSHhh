import { useCallback, useEffect, useRef, useState } from 'react';
import { EventItem } from '@/components/event-item';
import { useConfirm, useToast } from '@/components/feedback';
import { api } from '@/lib/api';
import { escapeHtml } from '@/lib/format';
import type { EventQueryResult, Project } from '@/types';

const CATEGORIES: { cat: string; label: string }[] = [
    { cat: 'all', label: 'All Events' },
    { cat: 'deployment', label: 'Deployments' },
    { cat: 'command', label: 'Commands' },
    { cat: 'ssh', label: 'SSH Tests' },
    { cat: 'audit', label: 'Config & Audit' },
];

interface Filters {
    category: string;
    status: string;
    search: string;
}

/**
 * Event-log state for one project (legacy eventsState + loadEvents()).
 * `loadEvents` always uses the current filters, so the page can call it after any action.
 */
export function useProjectEvents(projectId: number, initial: EventQueryResult | null | undefined) {
    const toast = useToast();
    const [events, setEvents] = useState<EventQueryResult>(initial || { items: [], total: 0, counts: {} });
    const [filters, setFiltersState] = useState<Filters>({ category: 'all', status: 'all', search: '' });
    const filtersRef = useRef(filters);

    const loadEvents = useCallback(async () => {
        const f = filtersRef.current;
        try {
            const res = await api.listEvents({ project_id: projectId, category: f.category, status: f.status, search: f.search, limit: 80 });
            setEvents(res);
        } catch (err) {
            toast('Failed to refresh event logs: ' + (err as Error).message, 'error');
        }
    }, [projectId, toast]);

    const setFilters = useCallback(
        (patch: Partial<Filters>) => {
            filtersRef.current = { ...filtersRef.current, ...patch };
            setFiltersState(filtersRef.current);
            void loadEvents();
        },
        [loadEvents],
    );

    return { events, filters, setFilters, loadEvents };
}

/** The "Complete Event Log" panel of the project page. */
export function EventLogPanel({ project, events: eventsApi }: { project: Project; events: ReturnType<typeof useProjectEvents> }) {
    const { events, filters, setFilters, loadEvents } = eventsApi;
    const toast = useToast();
    const confirm = useConfirm();
    const [searchInput, setSearchInput] = useState('');
    const debounce = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(debounce.current), []);

    const onSearch = (value: string) => {
        setSearchInput(value);
        window.clearTimeout(debounce.current);
        debounce.current = window.setTimeout(() => setFilters({ search: value.trim() }), 250);
    };

    const clearEvents = async () => {
        const isFailedOnly = filters.status === 'failed';
        const name = escapeHtml(project.name);
        const confirmed = await confirm({
            title: isFailedOnly ? 'Clear Failed Event Logs Only?' : 'Clear Historical Event Logs?',
            subtitle: project.name,
            message: isFailedOnly
                ? `Are you sure you want to clear all <strong>failed / error</strong> event logs for <strong>${name}</strong>? Succeeded events and deployment history will be kept.`
                : `Are you sure you want to clear <strong>all</strong> historical deployment and audit event logs for <strong>${name}</strong>?`,
            warning: 'Past logs and console output records cannot be recovered.',
            confirmText: isFailedOnly ? 'Clear Error Logs' : 'Clear All Logs',
            confirmClass: 'btn-danger',
            icon: '🗑',
            iconVariant: 'danger',
        });
        if (!confirmed) return;

        try {
            const res = await api.clearEvents(project.id, isFailedOnly ? 'failed' : 'all');
            toast(`Cleared ${res.deleted} event log entries.`, 'info');
            await loadEvents();
        } catch (err) {
            toast((err as Error).message, 'error');
        }
    };

    const counts = events.counts || {};
    const items = events.items || [];

    return (
        <section className="panel project-events-panel">
            <div className="panel-header event-log-header">
                <div>
                    <h2 className="panel-title">Complete Event Log</h2>
                    <span className="panel-subtitle">Full audit &amp; execution history: deployments, individual commands, SSH tests, and settings updates</span>
                </div>
                <div className="event-filter-controls">
                    <select className="form-select" id="eventStatusFilter" value={filters.status} onChange={(e) => setFilters({ status: e.target.value })}>
                        <option value="all">All Statuses</option>
                        <option value="success">Succeeded</option>
                        <option value="failed">Failed</option>
                        <option value="warning">Warning</option>
                        <option value="info">Info / Audit</option>
                    </select>
                    <input
                        type="search"
                        className="search-input event-search-input"
                        id="eventSearchInput"
                        placeholder="Search logs, commands, output..."
                        value={searchInput}
                        onChange={(e) => onSearch(e.target.value)}
                    />
                    <div className="event-filter-btns">
                        <button type="button" className="btn btn-ghost btn-sm" id="refreshEventsBtn" onClick={() => void loadEvents()}>
                            Refresh
                        </button>
                        <a
                            href={api.projectEventsDownloadUrl(project.id, 'all')}
                            className="btn btn-ghost btn-sm"
                            id="downloadEventsBtn"
                            title="Download deployer event & error history as .log"
                        >
                            📥 Download Logs
                        </a>
                        <button type="button" className="btn btn-danger btn-sm" id="clearEventsBtn" onClick={clearEvents}>
                            Clear Logs
                        </button>
                    </div>
                </div>
            </div>
            <div className="event-tabs-bar">
                <div className="event-tabs" id="eventCategoryTabs">
                    {CATEGORIES.map(({ cat, label }) => (
                        <button
                            key={cat}
                            type="button"
                            className={'event-tab' + (filters.category === cat ? ' active' : '')}
                            onClick={() => setFilters({ category: cat })}
                        >
                            {label}{' '}
                            <span className="tab-count" id={'cnt-' + cat}>
                                {counts[cat] ?? 0}
                            </span>
                        </button>
                    ))}
                </div>
            </div>
            <div className="events-list" id="projectEventsList">
                {items.length === 0 ? (
                    <div style={{ padding: '32px 20px', textAlign: 'center', color: 'var(--text-muted)' }}>No matching event logs found for this filter.</div>
                ) : (
                    items.map((ev) => <EventItem key={ev.id} event={ev} showProjectName={false} />)
                )}
            </div>
        </section>
    );
}
