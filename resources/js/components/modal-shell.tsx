import { type CSSProperties, type ReactNode, useEffect, useRef, useState } from 'react';

/** Open modals, newest last: Escape only closes the topmost one. */
const openStack: symbol[] = [];

/**
 * The legacy `.modal-backdrop > .modal` markup with its open animation: the backdrop
 * is displayed, then the `open` class is added on the next frame. Escape and a click
 * on the backdrop call onRequestClose (which may ask before actually closing).
 */
export function ModalShell({
    id,
    className = '',
    style,
    labelledBy,
    onRequestClose,
    closeOnBackdrop = true,
    children,
}: {
    id?: string;
    /** Extra classes for the inner `.modal`, e.g. "remote-env-modal". */
    className?: string;
    /** Inline style for the inner `.modal` (legacy views set max-width/width here). */
    style?: CSSProperties;
    labelledBy?: string;
    onRequestClose: () => void;
    closeOnBackdrop?: boolean;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const closeRef = useRef(onRequestClose);
    closeRef.current = onRequestClose;

    useEffect(() => {
        const me = Symbol('modal');
        openStack.push(me);
        const raf = requestAnimationFrame(() => setOpen(true));
        const onKey = (e: KeyboardEvent) => {
            // A confirm dialog on top handles its own Escape; otherwise only the topmost modal closes
            if (document.querySelector('.confirm-modal-backdrop')) return;
            if (e.key === 'Escape' && openStack[openStack.length - 1] === me) {
                e.preventDefault();
                closeRef.current();
            }
        };
        document.addEventListener('keydown', onKey);
        document.body.classList.add('modal-open');
        return () => {
            cancelAnimationFrame(raf);
            openStack.splice(openStack.indexOf(me), 1);
            document.removeEventListener('keydown', onKey);
            if (!document.querySelector('.modal-backdrop.open')) document.body.classList.remove('modal-open');
        };
    }, []);

    return (
        <div
            id={id}
            className={'modal-backdrop' + (open ? ' open' : '')}
            aria-hidden={!open}
            style={{ display: 'flex' }}
            onMouseDown={(e) => {
                if (closeOnBackdrop && e.target === e.currentTarget) closeRef.current();
            }}
        >
            <div className={('modal ' + className).trim()} role="dialog" aria-modal="true" aria-labelledby={labelledBy} style={style}>
                {children}
            </div>
        </div>
    );
}
