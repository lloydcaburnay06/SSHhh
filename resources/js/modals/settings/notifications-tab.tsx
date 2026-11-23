import { type FormEvent, useCallback, useEffect, useState } from 'react';
import { useToast } from '@/components/feedback';
import { api } from '@/lib/api';

interface NotifyForm {
    enabled: boolean;
    channel: string;
    on_deploy_success: boolean;
    on_deploy_failure: boolean;
    on_health_down: boolean;
    webhook_url: string;
    telegram_token: string;
    telegram_chat_id: string;
}

const DEFAULT_FORM: NotifyForm = {
    enabled: false,
    channel: 'discord',
    on_deploy_success: true,
    on_deploy_failure: true,
    on_health_down: true,
    webhook_url: '',
    telegram_token: '',
    telegram_chat_id: '',
};

/** Tab 2: Outbound deployment & health notifications (legacy settingsPaneNotifications). */
export function NotificationsTab({ active, loadToken }: { active: boolean; loadToken: number }) {
    const toast = useToast();
    const [form, setForm] = useState<NotifyForm>(DEFAULT_FORM);
    const [hasWebhook, setHasWebhook] = useState(false);
    const [hasTgToken, setHasTgToken] = useState(false);
    const [hint, setHint] = useState('Encrypted at rest with AES-256-GCM. Leave blank to keep existing saved webhook URL.');
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);

    const loadNotifications = useCallback(async () => {
        try {
            const res = await api.getNotificationSettings();
            const s = (res && res.settings) || {};
            setForm({
                enabled: Boolean(s.enabled),
                channel: s.channel || 'discord',
                on_deploy_success: s.on_deploy_success !== false,
                on_deploy_failure: s.on_deploy_failure !== false,
                on_health_down: s.on_health_down !== false,
                webhook_url: '',
                telegram_token: '',
                telegram_chat_id: s.telegram_chat_id || '',
            });
            setHasWebhook(Boolean(s.has_webhook_url));
            setHasTgToken(Boolean(s.has_telegram_token));
            setHint(s.has_webhook_url ? `Stored encrypted (${s.webhook_url_hint}). Leave blank to keep existing URL.` : 'Encrypted at rest with AES-256-GCM.');
        } catch (err) {
            toast('Failed to load notification settings: ' + (err as Error).message, 'error');
        }
    }, [toast]);

    useEffect(() => {
        if (active) void loadNotifications();
    }, [active, loadToken, loadNotifications]);

    const set = <K extends keyof NotifyForm>(key: K, value: NotifyForm[K]) => setForm((f) => ({ ...f, [key]: value }));

    const onSubmit = async (e: FormEvent) => {
        e.preventDefault();
        setSaving(true);
        try {
            await api.saveNotificationSettings({
                enabled: form.enabled,
                channel: form.channel,
                on_deploy_success: form.on_deploy_success,
                on_deploy_failure: form.on_deploy_failure,
                on_health_down: form.on_health_down,
                webhook_url: form.webhook_url.trim(),
                telegram_token: form.telegram_token.trim(),
                telegram_chat_id: form.telegram_chat_id.trim(),
            });
            toast('Notification settings saved securely.', 'success');
            await loadNotifications();
        } catch (err) {
            toast((err as Error).message, 'error');
        } finally {
            setSaving(false);
        }
    };

    const onTest = async () => {
        setTesting(true);
        try {
            const res = await api.testNotification();
            toast(res.message || 'Test alert sent!', 'success');
        } catch (err) {
            toast((err as Error).message, 'error');
        } finally {
            setTesting(false);
        }
    };

    const isTg = form.channel === 'telegram';

    return (
        <div className={'settings-tab-pane' + (active ? ' active' : '')} id="settingsPaneNotifications" style={{ display: active ? 'block' : 'none' }}>
            <form id="notificationSettingsForm" autoComplete="off" onSubmit={onSubmit}>
                <div style={{ padding: '12px 14px', background: 'var(--bg-elevated)', border: '1px solid var(--border-color)', borderRadius: 8, marginBottom: 14 }}>
                    <label className="toggle-label" htmlFor="notify_enabled" style={{ color: 'var(--text-primary)', fontWeight: 600, margin: 0 }}>
                        <input type="checkbox" id="notify_enabled" checked={form.enabled} onChange={(e) => set('enabled', e.target.checked)} />
                        <span>Enable Outbound Deployment &amp; Health Notifications</span>
                    </label>
                </div>

                <div className="form-grid">
                    <div className="form-group">
                        <label htmlFor="notify_channel">Notification Channel</label>
                        <select className="form-select" id="notify_channel" value={form.channel} onChange={(e) => set('channel', e.target.value)}>
                            <option value="discord">Discord Webhook</option>
                            <option value="slack">Slack Incoming Webhook</option>
                            <option value="telegram">Telegram Bot API</option>
                            <option value="webhook">Custom JSON Webhook</option>
                        </select>
                    </div>
                    <div className="form-group">
                        <label>Notify On Events</label>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, paddingTop: 4 }}>
                            <label className="toggle-label" htmlFor="notify_on_deploy_success" style={{ fontSize: 12.5 }}>
                                <input
                                    type="checkbox"
                                    id="notify_on_deploy_success"
                                    checked={form.on_deploy_success}
                                    onChange={(e) => set('on_deploy_success', e.target.checked)}
                                />
                                <span>✅ Deployment succeeded</span>
                            </label>
                            <label className="toggle-label" htmlFor="notify_on_deploy_failure" style={{ fontSize: 12.5 }}>
                                <input
                                    type="checkbox"
                                    id="notify_on_deploy_failure"
                                    checked={form.on_deploy_failure}
                                    onChange={(e) => set('on_deploy_failure', e.target.checked)}
                                />
                                <span>🚨 Deployment failed</span>
                            </label>
                            <label className="toggle-label" htmlFor="notify_on_health_down" style={{ fontSize: 12.5 }}>
                                <input
                                    type="checkbox"
                                    id="notify_on_health_down"
                                    checked={form.on_health_down}
                                    onChange={(e) => set('on_health_down', e.target.checked)}
                                />
                                <span>🔴 Health URL alert / recovery</span>
                            </label>
                        </div>
                    </div>

                    <div className="form-group full" id="notifyWebhookUrlWrap" style={isTg ? { display: 'none' } : undefined}>
                        <label htmlFor="notify_webhook_url">
                            <span>Webhook URL</span>
                            <span id="notifySavedWebhookBadge" className="secret-saved-hint" style={hasWebhook ? undefined : { display: 'none' }}>
                                &#128274; Stored
                            </span>
                        </label>
                        <input
                            type="url"
                            className="form-control"
                            id="notify_webhook_url"
                            placeholder="https://discord.com/api/webhooks/..."
                            value={form.webhook_url}
                            onChange={(e) => set('webhook_url', e.target.value)}
                        />
                        <span className="form-hint" id="notifyWebhookHint">
                            {hint}
                        </span>
                    </div>

                    <div className="form-group" id="notifyTelegramTokenWrap" style={isTg ? undefined : { display: 'none' }}>
                        <label htmlFor="notify_telegram_token">
                            <span>Telegram Bot Token</span>
                            <span id="notifySavedTgBadge" className="secret-saved-hint" style={hasTgToken ? undefined : { display: 'none' }}>
                                &#128274; Stored
                            </span>
                        </label>
                        <input
                            type="password"
                            className="form-control"
                            id="notify_telegram_token"
                            autoComplete="new-password"
                            placeholder="123456:ABC-DEF..."
                            value={form.telegram_token}
                            onChange={(e) => set('telegram_token', e.target.value)}
                        />
                    </div>
                    <div className="form-group" id="notifyTelegramChatWrap" style={isTg ? undefined : { display: 'none' }}>
                        <label htmlFor="notify_telegram_chat_id">Telegram Chat ID</label>
                        <input
                            type="text"
                            className="form-control"
                            id="notify_telegram_chat_id"
                            placeholder="-1001234567890"
                            value={form.telegram_chat_id}
                            onChange={(e) => set('telegram_chat_id', e.target.value)}
                        />
                    </div>
                </div>

                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 16, gap: 8, flexWrap: 'wrap' }}>
                    <button type="button" className="btn btn-secondary btn-sm" id="testNotificationBtn" disabled={testing} onClick={() => void onTest()}>
                        {testing ? 'Sending...' : '🔔 Send Test Alert'}
                    </button>
                    <button type="submit" className="btn btn-primary btn-sm" id="saveNotificationsBtn" disabled={saving}>
                        Save Notification Settings
                    </button>
                </div>
            </form>
        </div>
    );
}
