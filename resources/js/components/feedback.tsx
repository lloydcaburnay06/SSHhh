import { createContext, type ReactNode, useCallback, useContext, useEffect, useRef, useState } from 'react';

/* ------------------------------------------------------------------------- */
/* Toasts                                                                    */
/* ------------------------------------------------------------------------- */

export type ToastType = 'info' | 'success' | 'error' | 'warning';
type ToastFn = (message: string, type?: ToastType, durationMs?: number) => void;

interface ToastItem {
    id: number;
    message: string;
    type: ToastType;
    leaving: boolean;
}

/* ------------------------------------------------------------------------- */
/* Confirm dialog (legacy confirmAction)                                     */
/* ------------------------------------------------------------------------- */

export interface ConfirmOptions {
    title?: string;
    subtitle?: string;
    /** Emoji / short text shown in the bubble. */
    icon?: string;
    /** e.g. 'danger' | 'warning' | 'success' → class variant-<x> on the icon bubble */
    iconVariant?: string;
    /** Trusted HTML (built by our own code, never from server text without escaping). */
    message?: string;
    /** Trusted HTML. */
    warning?: string;
    /** Shown as plain preformatted text. */
    code?: string;
    confirmText?: string;
    /** Button class, e.g. 'btn-primary' | 'btn-danger' | 'btn-deploy' */
    confirmClass?: string;
    cancelText?: string;
    focusCancel?: boolean;
}

type ConfirmFn = (options?: ConfirmOptions) => Promise<boolean>;

const FeedbackContext = createContext<{ toast: ToastFn; confirm: ConfirmFn } | null>(null);

export function useToast(): ToastFn {
    const ctx = useContext(FeedbackContext);
    if (!ctx) throw new Error('useToast must be used inside <FeedbackProvider>');
    return ctx.toast;
}

export function useConfirm(): ConfirmFn {
    const ctx = useContext(FeedbackContext);
    if (!ctx) throw new Error('useConfirm must be used inside <FeedbackProvider>');
    return ctx.confirm;
}

export function FeedbackProvider({ children }: { children: ReactNode }) {
    const [toasts, setToasts] = useState<ToastItem[]>([]);
    const nextId = useRef(1);

    const toast = useCallback<ToastFn>((message, type = 'info', durationMs = 4200) => {
        const id = nextId.current++;
        setToasts((list) => [...list, { id, message, type, leaving: false }]);
        window.setTimeout(() => {
            setToasts((list) => list.map((t) => (t.id === id ? { ...t, leaving: true } : t)));
            window.setTimeout(() => setToasts((list) => list.filter((t) => t.id !== id)), 220);
        }, durationMs);
    }, []);

    const [dialog, setDialog] = useState<{ options: ConfirmOptions; resolve: (ok: boolean) => void } | null>(null);
    const [shown, setShown] = useState(false);

    const confirm = useCallback<ConfirmFn>(
        (options = {}) =>
            new Promise<boolean>((resolve) => {
                setDialog({ options, resolve });
                requestAnimationFrame(() => setShown(true));
            }),
        [],
    );

    const close = useCallback(
        (result: boolean) => {
            if (!dialog) return;
            setShown(false);
            const { resolve } = dialog;
            window.setTimeout(() => setDialog(null), 180);
            resolve(result);
        },
        [dialog],
    );

    return (
        <FeedbackContext.Provider value={{ toast, confirm }}>
            {children}
            <div className="toast-stack" id="toastStack">
                {toasts.map((t) => (
                    <div
                        key={t.id}
                        className={`toast ${t.type}`}
                        style={t.leaving ? { opacity: 0, transition: 'opacity 0.2s ease' } : undefined}
                    >
                        {t.message}
                    </div>
                ))}
            </div>
            {dialog && <ConfirmDialog options={dialog.options} shown={shown} onClose={close} />}
        </FeedbackContext.Provider>
    );
}

function ConfirmDialog({ options, shown, onClose }: { options: ConfirmOptions; shown: boolean; onClose: (ok: boolean) => void }) {
    const confirmRef = useRef<HTMLButtonElement>(null);
    const cancelRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        (options.focusCancel ? cancelRef : confirmRef).current?.focus();
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                onClose(false);
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [options.focusCancel, onClose]);

    return (
        <div
            className={`modal-backdrop confirm-modal-backdrop${shown ? ' show' : ''}`}
            aria-hidden={!shown}
            style={{ display: 'flex' }}
            onClick={(e) => e.target === e.currentTarget && onClose(false)}
        >
            <div className="modal confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
                <div className="confirm-modal-header">
                    <div className={'confirm-icon-bubble' + (options.iconVariant ? ' variant-' + options.iconVariant : '')}>
                        {options.icon || '⚡'}
                    </div>
                    <div style={{ minWidth: 0, flex: 1 }}>
                        <h3 id="confirmModalTitle" className="confirm-modal-title">
                            {options.title || 'Confirm Action'}
                        </h3>
                        {options.subtitle && <p className="confirm-modal-subtitle">{options.subtitle}</p>}
                    </div>
                    <button type="button" className="icon-btn" title="Close" onClick={() => onClose(false)}>
                        &times;
                    </button>
                </div>
                <div className="confirm-modal-body">
                    {options.message && <div className="confirm-modal-message" dangerouslySetInnerHTML={{ __html: options.message }} />}
                    {options.warning && (
                        <div className="confirm-modal-warning" style={{ display: 'flex' }} dangerouslySetInnerHTML={{ __html: options.warning }} />
                    )}
                    {options.code && <pre className="confirm-modal-code">{options.code}</pre>}
                </div>
                <div className="confirm-modal-footer">
                    <button type="button" className="btn btn-ghost" ref={cancelRef} onClick={() => onClose(false)}>
                        {options.cancelText || 'Cancel'}
                    </button>
                    <button type="button" className={'btn ' + (options.confirmClass || 'btn-primary')} ref={confirmRef} onClick={() => onClose(true)}>
                        {options.confirmText || 'Confirm'}
                    </button>
                </div>
            </div>
        </div>
    );
}
