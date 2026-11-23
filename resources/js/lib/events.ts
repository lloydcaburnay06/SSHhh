import { useEffect, useRef } from 'react';

/**
 * App-wide "data changed" signal (legacy: window event `deployer:data-changed`).
 * Fired by the settings modal after SSH profiles change or a vault import, so
 * pages can refresh their project lists. `detail` is the API response.
 */
const EVENT = 'deployer:data-changed';

export function emitDataChanged(detail: unknown = null): void {
    window.dispatchEvent(new CustomEvent(EVENT, { detail }));
}

export function useDataChanged(handler: (detail: unknown) => void): void {
    const ref = useRef(handler);
    ref.current = handler;
    useEffect(() => {
        const listener = (e: Event) => ref.current((e as CustomEvent).detail);
        window.addEventListener(EVENT, listener);
        return () => window.removeEventListener(EVENT, listener);
    }, []);
}
