import { type FormEvent, useCallback, useEffect, useState } from 'react';
import { useToast } from '@/components/feedback';
import { api } from '@/lib/api';

interface PushDevice {
    id: number;
    device: string;
    created_at: string;
    last_ok_at?: string | null;
}

interface RecentAlert {
    id?: number;
    project_name: string;
    created_at: string;
    status: string;
    session_url?: string | null;
    summary: string;
    push_result?: string | null;
    error?: string | null;
}

const STATUS_LABEL: Record<string, string> = {
    pending: '⏳ waiting',
    processing: '⏳ sending',
    proceeded: '🤖 proceeded',
    auto_sent: '🤖 auto-sent',
    ignored: '🙈 ignored',
    failed: '❌ failed',
    expired: '⌛ expired',
    notified: '🔔 notified',
};

function urlB64ToUint8Array(b64: string): Uint8Array<ArrayBuffer> {
    const pad = '='.repeat((4 - (b64.length % 4)) % 4);
    const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}

/** Error escalation: push devices → Claude Code, Gemini summary (legacy settingsPaneAlerts). */
export function AlertsTab({ active, loadToken }: { active: boolean; loadToken: number }) {
    const toast = useToast();
    const [loaded, setLoaded] = useState(false);
    const [vapidKey, setVapidKey] = useState('');
    const [enabled, setEnabled] = useState(false);
    const [pushWaitMin, setPushWaitMin] = useState('10');
    const [geminiKey, setGeminiKey] = useState('');
    const [geminiModel, setGeminiModel] = useState('');
    const [hasGeminiKey, setHasGeminiKey] = useState(false);
    const [devices, setDevices] = useState<PushDevice[] | null>(null);
    const [alerts, setAlerts] = useState<RecentAlert[] | null>(null);
    const [saving, setSaving] = useState(false);
    const [testingPush, setTestingPush] = useState(false);
    const [summaryPreview, setSummaryPreview] = useState('');

    const loadAlertSettings = useCallback(async () => {
        try {
            const res = await api.getAlertSettings();
            const r = res.result;
            const c = r.config;
            setVapidKey(r.vapid_key);
            setEnabled(!!c.enabled);
            setPushWaitMin(String(c.push_wait_min));
            setGeminiModel(c.gemini_model || '');
            setGeminiKey('');
            setHasGeminiKey(!!c.has_gemini_key);
            setDevices(r.devices || []);
            setAlerts(r.alerts || []);
            setLoaded(true);
        } catch (err) {
            toast('Could not load escalation settings: ' + (err as Error).message, 'error');
        }
    }, [toast]);

    useEffect(() => {
        if (active) void loadAlertSettings();
    }, [active, loadToken, loadAlertSettings]);

    const onSubmit = async (e: FormEvent) => {
        e.preventDefault();
        setSaving(true);
        try {
            await api.saveAlertSettings({
                enabled,
                push_wait_min: parseInt(pushWaitMin, 10) || 10,
                gemini_key: geminiKey.trim(),
                gemini_model: geminiModel.trim(),
            });
            toast('Escalation settings saved.', 'success');
            await loadAlertSettings();
        } catch (err) {
            toast((err as Error).message, 'error');
        } finally {
            setSaving(false);
        }
    };

    const enablePush = async () => {
        try {
            if (!('serviceWorker' in navigator) || !('PushManager' in window))
                throw new Error('This browser does not support push notifications. On iPhone, add SSHhh to the Home Screen first.');
            const perm = await Notification.requestPermission();
            if (perm !== 'granted') throw new Error('Notifications are blocked for this site in the browser settings.');
            const reg = await navigator.serviceWorker.ready;
            let sub = await reg.pushManager.getSubscription();
            if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8Array(vapidKey) });
            const res = await api.pushSubscribe(sub.toJSON());
            setDevices(res.result.devices || []);
            toast('Notifications are on for this device.', 'success');
        } catch (err) {
            toast((err as Error).message, 'error');
        }
    };

    const removeDevice = async (id: number) => {
        try {
            const res = await api.pushRemoveDevice(id);
            setDevices(res.result.devices || []);
        } catch (err) {
            toast((err as Error).message, 'error');
        }
    };

    const testPush = async () => {
        setTestingPush(true);
        try {
            const res = await api.testPush();
            toast(res.result.message || 'Done.', 'success');
        } catch (err) {
            toast((err as Error).message, 'error');
        } finally {
            setTestingPush(false);
        }
    };

    const testSummary = async () => {
        setSummaryPreview('Writing…');
        try {
            const res = await api.testSummary();
            const r = res.result;
            setSummaryPreview(`"${r.text}" (${r.length}/160, ${r.source}${r.note ? ' — ' + r.note : ''})`);
        } catch (err) {
            setSummaryPreview((err as Error).message);
        }
    };

    const secureOk = window.isSecureContext;
    const pushDisabled = loaded && (!secureOk || !('PushManager' in window));

    return (
        <div className={'settings-tab-pane' + (active ? ' active' : '')} id="settingsPaneAlerts" style={{ display: active ? 'block' : 'none' }}>
            <form id="alertSettingsForm" autoComplete="off" onSubmit={onSubmit}>
                <div style={{ padding: '12px 14px', background: 'var(--bg-elevated)', border: '1px solid var(--border-color)', borderRadius: 8, marginBottom: 12 }}>
                    <label className="toggle-label" htmlFor="alert_enabled" style={{ color: 'var(--text-primary)', fontWeight: 600, margin: 0 }}>
                        <input type="checkbox" id="alert_enabled" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
                        <span>Ask me before sending new errors to Claude Code</span>
                    </label>
                    <p className="form-hint" style={{ margin: '6px 0 0' }}>
                        When the automatic checker finds a new error: <strong>push notification</strong> (Proceed / Ignore) → no answer in{' '}
                        <input
                            type="number"
                            id="alert_push_wait_min"
                            className="form-control inline-number"
                            min={1}
                            max={120}
                            value={pushWaitMin}
                            onChange={(e) => setPushWaitMin(e.target.value)}
                        />{' '}
                        min → <strong>sent to Claude Code</strong>. Turn this off to send new errors to Claude right away.
                    </p>
                </div>

                <h4 style={{ margin: '14px 0 6px', fontSize: 13.5 }}>📱 Push notifications</h4>
                <div
                    id="alertPushInsecure"
                    className="form-hint"
                    style={{ display: loaded && !secureOk ? 'block' : 'none', color: '#fca5a5', marginBottom: 6 }}
                >
                    This page is not open over HTTPS, so browsers on other devices can't turn on notifications here. Open SSHhh through your HTTPS
                    tunnel address on your phone (see README).
                </div>
                <div id="alertDevicesList" style={{ fontSize: 12.5, marginBottom: 8 }}>
                    {devices &&
                        (devices.length === 0 ? (
                            <span style={{ color: 'var(--text-muted)' }}>No devices have notifications turned on yet.</span>
                        ) : (
                            devices.map((d) => (
                                <div
                                    key={d.id}
                                    style={{ display: 'flex', justifyContent: 'space-between', gap: 8, padding: '4px 0', borderBottom: '1px solid var(--border-subtle)' }}
                                >
                                    <span>
                                        📱 {d.device}{' '}
                                        <span style={{ color: 'var(--text-muted)' }}>
                                            since {d.created_at}
                                            {d.last_ok_at ? ' · last delivered ' + d.last_ok_at : ''}
                                        </span>
                                    </span>
                                    <button type="button" className="btn btn-ghost btn-xs" onClick={() => void removeDevice(Number(d.id))}>
                                        Remove
                                    </button>
                                </div>
                            ))
                        ))}
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 6 }}>
                    <button type="button" className="btn btn-primary btn-sm" id="alertEnablePushBtn" disabled={pushDisabled} onClick={() => void enablePush()}>
                        🔔 Turn on notifications on this device
                    </button>
                    <button type="button" className="btn btn-secondary btn-sm" id="alertTestPushBtn" disabled={testingPush} onClick={() => void testPush()}>
                        Send test notification
                    </button>
                </div>

                <h4 style={{ margin: '16px 0 6px', fontSize: 13.5 }}>✨ Gemini summary (free tier)</h4>
                <div className="form-grid">
                    <div className="form-group">
                        <label htmlFor="alert_gemini_key">
                            <span>Gemini API key</span>{' '}
                            <span id="alertGeminiSaved" className="secret-saved-hint" style={hasGeminiKey ? undefined : { display: 'none' }}>
                                &#128274; Stored
                            </span>
                        </label>
                        <input
                            type="password"
                            className="form-control"
                            id="alert_gemini_key"
                            autoComplete="new-password"
                            placeholder="From aistudio.google.com/apikey"
                            value={geminiKey}
                            onChange={(e) => setGeminiKey(e.target.value)}
                        />
                    </div>
                    <div className="form-group">
                        <label htmlFor="alert_gemini_model">Model</label>
                        <input
                            type="text"
                            className="form-control"
                            id="alert_gemini_model"
                            placeholder="gemini-flash-latest"
                            value={geminiModel}
                            onChange={(e) => setGeminiModel(e.target.value)}
                        />
                    </div>
                </div>
                <span className="form-hint">
                    Only the masked one-line headline of each error is sent to Gemini, never stack traces. On the free tier Google may use and review what
                    you send. Without a key, SSHhh writes the summary itself.
                </span>
                <div style={{ marginTop: 6 }}>
                    <button type="button" className="btn btn-secondary btn-sm" id="alertTestSummaryBtn" onClick={() => void testSummary()}>
                        Preview a summary
                    </button>{' '}
                    <span id="alertSummaryPreview" style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                        {summaryPreview}
                    </span>
                </div>

                <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', marginTop: 14, gap: 8 }}>
                    <button type="submit" className="btn btn-primary btn-sm" id="alertSaveBtn" disabled={saving}>
                        Save escalation settings
                    </button>
                </div>

                <h4 style={{ margin: '18px 0 6px', fontSize: 13.5 }}>Recent alerts</h4>
                <div id="alertRecentList" style={{ fontSize: 12 }}>
                    {alerts &&
                        (alerts.length === 0 ? (
                            <span style={{ color: 'var(--text-muted)' }}>No alerts yet.</span>
                        ) : (
                            alerts.map((a, i) => (
                                <div key={a.id ?? i} style={{ padding: '5px 0', borderBottom: '1px solid var(--border-subtle)' }}>
                                    <strong>{a.project_name}</strong> · {a.created_at} · {STATUS_LABEL[a.status] || a.status}
                                    {a.session_url && (
                                        <>
                                            {' · '}
                                            <a href={a.session_url} target="_blank" rel="noopener">
                                                session
                                            </a>
                                        </>
                                    )}
                                    <div style={{ color: 'var(--text-secondary)' }}>{a.summary}</div>
                                    <div style={{ color: 'var(--text-muted)' }}>
                                        {a.push_result || ''}
                                        {a.error ? ' · ' + a.error : ''}
                                    </div>
                                </div>
                            ))
                        ))}
                </div>
            </form>
        </div>
    );
}
