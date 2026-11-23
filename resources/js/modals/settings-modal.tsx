import { useState } from 'react';
import { ModalShell } from '@/components/modal-shell';
import type { ModalProps, SettingsModalState } from './modals-context';
import { AlertsTab } from './settings/alerts-tab';
import { CheckerTab } from './settings/checker-tab';
import { NotificationsTab } from './settings/notifications-tab';
import { ProfilesTab } from './settings/profiles-tab';
import { VaultTab } from './settings/vault-tab';

type TabKey = 'profiles' | 'notifications' | 'checker' | 'alerts' | 'vault';

const TABS: { key: TabKey; label: string }[] = [
    { key: 'profiles', label: '🖥️ Shared SSH Profiles' },
    { key: 'notifications', label: '🔔 Outbound Alerts' },
    { key: 'checker', label: '🩺 Log Checker' },
    { key: 'alerts', label: '📟 Error Escalation' },
    { key: 'vault', label: '🛡️ Vault Backup & Restore' },
];

/** Accepts the legacy tab keys ('checker') plus the 'logchecker' alias from SettingsTab. */
function normalizeTab(tab: string | undefined): TabKey {
    if (tab === 'logchecker') return 'checker';
    return TABS.some((t) => t.key === tab) ? (tab as TabKey) : 'profiles';
}

/**
 * Workspace Settings, Shared SSH Profiles, Outbound Alerts & Encrypted Vault
 * (legacy initSettingsModal / window.DeployerApp.openSettingsModal(initialTab)).
 * Every pane stays mounted (hidden with display:none like the legacy view) so
 * unsaved input survives a tab switch; each pane reloads its data when shown.
 */
export default function SettingsModal({ state, onClose }: ModalProps<SettingsModalState>) {
    const [tab, setTab] = useState<TabKey>(() => normalizeTab(state.tab));
    // Bumped on every tab click so re-clicking the open tab reloads it, like legacy switchTab().
    const [loadToken, setLoadToken] = useState(0);

    return (
        <ModalShell id="settingsModal" className="settings-modal" labelledBy="settingsModalTitle" onRequestClose={onClose}>
            <div className="modal-header">
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <div className="confirm-icon-bubble variant-deploy" style={{ fontSize: 18 }}>
                        ⚙️
                    </div>
                    <div>
                        <h2 id="settingsModalTitle" style={{ margin: 0, fontSize: 16 }}>
                            Workspace Settings &amp; Vault
                        </h2>
                        <p style={{ margin: '2px 0 0', fontSize: 12, color: 'var(--text-secondary)' }}>
                            Shared SSH server profiles, outbound alerts &amp; encrypted backup/restore
                        </p>
                    </div>
                </div>
                <button type="button" className="icon-btn" id="settingsModalCloseBtn" title="Close" onClick={onClose}>
                    &times;
                </button>
            </div>

            <div className="settings-tabs-bar" role="tablist">
                {TABS.map((t) => (
                    <button
                        key={t.key}
                        type="button"
                        className={'settings-tab-btn' + (tab === t.key ? ' active' : '')}
                        data-settings-tab={t.key}
                        role="tab"
                        aria-selected={tab === t.key}
                        onClick={() => {
                            setTab(t.key);
                            setLoadToken((n) => n + 1);
                        }}
                    >
                        {t.label}
                    </button>
                ))}
            </div>

            <div className="modal-body" style={{ padding: '18px 20px' }}>
                <ProfilesTab active={tab === 'profiles'} loadToken={loadToken} />
                <NotificationsTab active={tab === 'notifications'} loadToken={loadToken} />
                <CheckerTab active={tab === 'checker'} loadToken={loadToken} />
                <AlertsTab active={tab === 'alerts'} loadToken={loadToken} />
                <VaultTab active={tab === 'vault'} />
            </div>

            <div className="modal-footer" style={{ justifyContent: 'flex-end' }}>
                <button type="button" className="btn btn-ghost btn-sm" id="settingsCloseFooterBtn" onClick={onClose}>
                    Close
                </button>
            </div>
        </ModalShell>
    );
}
