import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useState } from 'react';
import { FeedbackProvider, useToast } from '@/components/feedback';
import { url } from '@/lib/api';
import { ModalsProvider, useModals } from '@/modals/modals-context';
import type { SharedProps } from '@/types';

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

/** Signed-in pages: topbar, toasts, confirm dialog and the shared modals. */
export default function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    useBodyClass('app-shell');

    return (
        <FeedbackProvider>
            <ModalsProvider>
                <Head title={title} />
                <Topbar />
                {children}
                <StandaloneNavigationTrap />
            </ModalsProvider>
        </FeedbackProvider>
    );
}

export function useBodyClass(className: string): void {
    useEffect(() => {
        document.body.className = className;
    }, [className]);
}

function Topbar() {
    const { app } = usePage<SharedProps>().props;
    const { openSettingsModal } = useModals();
    const toast = useToast();
    const [installPrompt, setInstallPrompt] = useState<BeforeInstallPromptEvent | null>(null);

    useEffect(() => {
        registerServiceWorker();
        const onPrompt = (e: Event) => {
            e.preventDefault();
            setInstallPrompt(e as BeforeInstallPromptEvent);
        };
        const onInstalled = () => {
            setInstallPrompt(null);
            toast('SSHhh installed! Launch it anytime from your home screen.', 'success');
        };
        window.addEventListener('beforeinstallprompt', onPrompt);
        window.addEventListener('appinstalled', onInstalled);
        return () => {
            window.removeEventListener('beforeinstallprompt', onPrompt);
            window.removeEventListener('appinstalled', onInstalled);
        };
    }, [toast]);

    const install = async () => {
        if (!installPrompt) return;
        await installPrompt.prompt();
        const choice = await installPrompt.userChoice.catch(() => null);
        if (choice?.outcome === 'accepted') setInstallPrompt(null);
    };

    return (
        <header className="topbar">
            <div className="topbar-inner">
                <Link href={url('/')} className="brand" aria-label={`${app.name} — ${app.tagline}`}>
                    <img src={url('/assets/img/icon-192.png')} alt={app.name} className="brand-logo-img" width={38} height={38} />
                    <div className="brand-text-wrap">
                        <span className="brand-wordmark">
                            {app.name === 'SSHhh' ? (
                                <>
                                    SSH<span className="brand-hush">hh</span>
                                </>
                            ) : (
                                app.name
                            )}
                        </span>
                        <span className="brand-tagline">{app.tagline}</span>
                    </div>
                </Link>
                <div className="topbar-meta">
                    {installPrompt && (
                        <button type="button" className="btn btn-secondary btn-sm pwa-install-btn" style={{ display: 'inline-flex' }} title="Install SSHhh as an app" onClick={install}>
                            <span>&#11015;</span>
                            <span className="hide-xs">Install App</span>
                        </button>
                    )}
                    <span className="security-pill" title="SSH passwords and private keys are encrypted at rest with AES-256-GCM authenticated encryption">
                        <span>&#128274;</span>
                        <span className="security-pill-full">AES-256-GCM Vault</span>
                        <span className="security-pill-short">AES-256</span>
                    </span>
                    <button type="button" className="btn btn-secondary btn-sm" title="Shared SSH Profiles, Outbound Alerts & Vault Backup" onClick={() => openSettingsModal('profiles')}>
                        <span>&#9881;&#65039;</span>
                        <span className="hide-xs">Settings</span>
                    </button>
                    {app.auth_enabled && (
                        <button type="button" className="btn btn-ghost btn-sm" onClick={() => router.post(url('/logout'))}>
                            Sign Out
                        </button>
                    )}
                </div>
            </div>
        </header>
    );
}

let swRegistered = false;

export function registerServiceWorker(): void {
    if (swRegistered || !('serviceWorker' in navigator)) return;
    swRegistered = true;
    // Service workers need localhost or HTTPS; ignore failures elsewhere
    navigator.serviceWorker.register(url('/sw.js'), { scope: url('/').replace(/\/*$/, '/') }).catch(() => {});
}

/** Keeps in-app link clicks inside the installed PWA window on iOS / Android. */
function StandaloneNavigationTrap() {
    useEffect(() => {
        const nav = navigator as Navigator & { standalone?: boolean };
        const standalone = nav.standalone || window.matchMedia('(display-mode: standalone)').matches || window.matchMedia('(display-mode: fullscreen)').matches;
        if (!standalone) return;
        const onClick = (e: MouseEvent) => {
            const a = (e.target as HTMLElement).closest('a');
            if (!a || !a.href || a.target === '_blank' || a.hasAttribute('download') || e.defaultPrevented) return;
            try {
                const dest = new URL(a.href, window.location.href);
                if (dest.origin === window.location.origin && dest.href.startsWith(url('/'))) {
                    e.preventDefault();
                    window.location.href = dest.href;
                }
            } catch {
                /* ignore */
            }
        };
        document.addEventListener('click', onClick);
        return () => document.removeEventListener('click', onClick);
    }, []);
    return null;
}
