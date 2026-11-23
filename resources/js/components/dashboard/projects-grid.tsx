import { type DragEvent as ReactDragEvent, type RefObject, useEffect, useMemo, useRef, useState } from 'react';
import type { Project } from '@/types';
import { type CardAction, type CardBusy, ProjectCard } from './project-card';

interface Props {
    /** All projects in priority order. */
    projects: Project[];
    /** The projects that pass the search / environment filter (same relative order). */
    filtered: Project[];
    expandedIds: Set<number>;
    busy: Record<number, CardBusy>;
    onToggleDetails: (id: number) => void;
    onAction: (action: CardAction, project: Project, targetState?: string) => void;
    onAddFirst: () => void;
    /** Persist a new full priority order (legacy commitDomReorder). */
    onCommitOrder: (next: Project[]) => void;
    /** `.dashboard-main`, which scrolls on wide screens (drag auto-scroll). */
    scrollPaneRef: RefObject<HTMLDivElement | null>;
}

/** Merges the reordered visible ids back into the full list (legacy computePreviewProjectsFromDom). */
function mergeOrder(projects: Project[], visibleOrder: number[]): Project[] {
    const visible = new Set(visibleOrder);
    const byId = new Map(projects.map((p) => [Number(p.id), p]));
    const orderedVisible = visibleOrder.map((id) => byId.get(id)).filter(Boolean) as Project[];
    let cursor = 0;
    return projects.map((p) => (visible.has(Number(p.id)) ? orderedVisible[cursor++] : p));
}

/**
 * `#projectsContainer`: empty states and the `.projects-grid` of cards, with drag-to-reorder
 * (HTML5 drag on desktop, the grip handle on touch devices).
 */
export function ProjectsGrid({ projects, filtered, expandedIds, busy, onToggleDetails, onAction, onAddFirst, onCommitOrder, scrollPaneRef }: Props) {
    const containerRef = useRef<HTMLElement>(null);
    const cardEls = useRef(new Map<number, HTMLElement>());

    /** Visible card ids in their in-progress drag order (null when not dragging). */
    const [previewOrder, setPreviewOrder] = useState<number[] | null>(null);
    const [draggingId, setDraggingId] = useState<number | null>(null);
    const [reordering, setReordering] = useState(false);

    const drag = useRef<{ id: number | null; touch: boolean; initialIds: number[]; order: number[] }>({ id: null, touch: false, initialIds: [], order: [] });

    const visible = useMemo(() => {
        if (!previewOrder) return filtered;
        const byId = new Map(projects.map((p) => [Number(p.id), p]));
        return previewOrder.map((id) => byId.get(id)).filter(Boolean) as Project[];
    }, [previewOrder, filtered, projects]);

    const rankById = useMemo(() => {
        const list = previewOrder ? mergeOrder(projects, previewOrder) : projects;
        return new Map(list.map((p, i) => [Number(p.id), i + 1]));
    }, [previewOrder, projects]);

    // Latest values for the native touch listeners and drag handlers.
    const latest = useRef({ projects, filtered, onCommitOrder });
    latest.current = { projects, filtered, onCommitOrder };

    const startDrag = (id: number, touch: boolean) => {
        const ids = latest.current.filtered.map((p) => Number(p.id));
        drag.current = { id, touch, initialIds: ids, order: ids };
        setPreviewOrder(ids);
        setReordering(true);
    };

    const autoScrollNearEdges = (clientY: number) => {
        const edgeThreshold = 68;
        const maxStep = 16;
        const pane = scrollPaneRef.current;
        if (pane && window.innerWidth > 1180) {
            const rect = pane.getBoundingClientRect();
            if (clientY < rect.top + edgeThreshold) {
                const ratio = Math.min(1, (rect.top + edgeThreshold - clientY) / edgeThreshold);
                pane.scrollTop -= Math.ceil(maxStep * ratio);
            } else if (clientY > rect.bottom - edgeThreshold) {
                const ratio = Math.min(1, (clientY - (rect.bottom - edgeThreshold)) / edgeThreshold);
                pane.scrollTop += Math.ceil(maxStep * ratio);
            }
        } else if (clientY < edgeThreshold) {
            window.scrollBy(0, -12);
        } else if (clientY > window.innerHeight - edgeThreshold) {
            window.scrollBy(0, 12);
        }
    };

    const maybeSwapCards = (overEl: Element | null, clientX: number, clientY: number) => {
        const activeId = drag.current.id;
        const overCard = overEl?.closest('.project-card') as HTMLElement | null;
        if (activeId === null || !overCard) return;
        const overId = Number(overCard.dataset.projectId);
        if (overId === activeId) return;

        const order = drag.current.order;
        const activeIdx = order.indexOf(activeId);
        const overIdx = order.indexOf(overId);
        const activeCard = cardEls.current.get(activeId);
        if (activeIdx === -1 || overIdx === -1 || !activeCard) return;

        const activeRect = activeCard.getBoundingClientRect();
        const overRect = overCard.getBoundingClientRect();
        const sameRow = Math.abs(activeRect.top - overRect.top) < overRect.height * 0.45;

        let placeAfter: boolean | null = null;
        if (sameRow) {
            const midX = overRect.left + overRect.width * 0.5;
            if (activeIdx < overIdx && clientX > midX) placeAfter = true;
            else if (activeIdx > overIdx && clientX < midX) placeAfter = false;
        } else {
            const midY = overRect.top + overRect.height * 0.5;
            if (activeIdx < overIdx && clientY > midY) placeAfter = true;
            else if (activeIdx > overIdx && clientY < midY) placeAfter = false;
        }
        if (placeAfter === null) return;

        const next = order.filter((id) => id !== activeId);
        const at = next.indexOf(overId);
        next.splice(placeAfter ? at + 1 : at, 0, activeId);
        drag.current.order = next;
        setPreviewOrder(next);
    };

    const finishDrag = () => {
        const { initialIds, order } = drag.current;
        drag.current = { id: null, touch: false, initialIds: [], order: [] };
        setDraggingId(null);
        setReordering(false);
        setPreviewOrder(null);
        if (initialIds.length === 0 || order.length !== initialIds.length || initialIds.join(',') === order.join(',')) return;
        latest.current.onCommitOrder(mergeOrder(latest.current.projects, order));
    };

    // Touch drag via the grip handle (native listeners: touchmove must be non-passive to block scrolling).
    const touchHandlers = useRef({ startDrag, autoScrollNearEdges, maybeSwapCards, finishDrag });
    touchHandlers.current = { startDrag, autoScrollNearEdges, maybeSwapCards, finishDrag };

    useEffect(() => {
        const container = containerRef.current;
        if (!container) return;
        const onTouchStart = (e: TouchEvent) => {
            const grip = (e.target as Element).closest('.project-drag-grip');
            if (!grip || e.touches.length !== 1) return;
            const card = grip.closest('.project-card') as HTMLElement | null;
            if (!card) return;
            const id = Number(card.dataset.projectId);
            touchHandlers.current.startDrag(id, true);
            setDraggingId(id);
        };
        const onTouchMove = (e: TouchEvent) => {
            if (drag.current.id === null || !drag.current.touch || e.touches.length !== 1) return;
            e.preventDefault();
            const touch = e.touches[0];
            touchHandlers.current.autoScrollNearEdges(touch.clientY);
            touchHandlers.current.maybeSwapCards(document.elementFromPoint(touch.clientX, touch.clientY), touch.clientX, touch.clientY);
        };
        const onTouchEnd = () => {
            if (drag.current.id === null || !drag.current.touch) return;
            touchHandlers.current.finishDrag();
        };
        container.addEventListener('touchstart', onTouchStart, { passive: true });
        container.addEventListener('touchmove', onTouchMove, { passive: false });
        container.addEventListener('touchend', onTouchEnd);
        container.addEventListener('touchcancel', onTouchEnd);
        return () => {
            container.removeEventListener('touchstart', onTouchStart);
            container.removeEventListener('touchmove', onTouchMove);
            container.removeEventListener('touchend', onTouchEnd);
            container.removeEventListener('touchcancel', onTouchEnd);
        };
    }, []);

    const onDragStart = (e: ReactDragEvent<HTMLElement>) => {
        const card = (e.target as Element).closest('.project-card') as HTMLElement | null;
        if (!card || card.getAttribute('draggable') !== 'true') {
            e.preventDefault();
            return;
        }
        const id = Number(card.dataset.projectId);
        startDrag(id, false);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(id));
        requestAnimationFrame(() => {
            if (drag.current.id === id) setDraggingId(id);
        });
    };

    const onDragOver = (e: ReactDragEvent<HTMLElement>) => {
        if (drag.current.id === null || drag.current.touch) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        autoScrollNearEdges(e.clientY);
        maybeSwapCards(e.target as Element, e.clientX, e.clientY);
    };

    const onDragEnd = () => {
        const id = drag.current.id;
        if (id === null || drag.current.touch) return;
        cardEls.current.get(id)?.removeAttribute('draggable');
        finishDrag();
    };

    const onMouseUp = (e: React.MouseEvent<HTMLElement>) => {
        const card = (e.target as Element).closest('.project-card');
        if (card && drag.current.id === null) card.removeAttribute('draggable');
    };

    let content;
    if (projects.length === 0) {
        content = (
            <div className="empty-state">
                <h3>No SSH deployment projects configured yet</h3>
                <p>
                    Add your first project with its remote SSH host, working directory, and password (encrypted automatically with AES-256-GCM) to manage
                    1-click deployments.
                </p>
                <button type="button" className="btn btn-primary btn-lg" onClick={onAddFirst}>
                    + Create First Project
                </button>
            </div>
        );
    } else if (visible.length === 0) {
        content = (
            <div className="empty-state" style={{ padding: '32px 20px' }}>
                <h3>No matching projects</h3>
                <p>Try clearing your search query or switching the environment filter.</p>
            </div>
        );
    } else {
        content = (
            <div className="projects-grid">
                {visible.map((p) => (
                    <ProjectCard
                        key={p.id}
                        project={p}
                        rank={rankById.get(Number(p.id)) || 1}
                        expanded={expandedIds.has(Number(p.id))}
                        dragging={draggingId === Number(p.id)}
                        busy={busy[p.id] ?? {}}
                        onToggleDetails={onToggleDetails}
                        onAction={onAction}
                        cardRef={(el) => {
                            if (el) cardEls.current.set(Number(p.id), el);
                            else cardEls.current.delete(Number(p.id));
                        }}
                    />
                ))}
            </div>
        );
    }

    return (
        <section
            id="projectsContainer"
            ref={containerRef}
            className={reordering ? 'is-reordering' : undefined}
            onMouseUp={onMouseUp}
            onDragStart={onDragStart}
            onDragOver={onDragOver}
            onDrop={(e) => {
                if (drag.current.id !== null) e.preventDefault();
            }}
            onDragEnd={onDragEnd}
        >
            {content}
        </section>
    );
}
