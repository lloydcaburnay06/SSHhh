import { type FormEvent, useRef, useState } from 'react';
import { useToast } from '@/components/feedback';
import { api } from '@/lib/api';
import { emitDataChanged } from '@/lib/events';
import { useModals } from '../modals-context';

const CARD_STYLE = { padding: 14, background: 'var(--bg-elevated)', border: '1px solid var(--border-color)', borderRadius: 10 } as const;

/** Tab 3: Encrypted vault backup & restore (legacy settingsPaneVault). */
export function VaultTab({ active }: { active: boolean }) {
    const toast = useToast();
    const { setAvailableProfiles } = useModals();
    const [exportPass, setExportPass] = useState('');
    const [exporting, setExporting] = useState(false);
    const [importPass, setImportPass] = useState('');
    const [overwrite, setOverwrite] = useState(false);
    const [importing, setImporting] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);

    const onExport = async (e: FormEvent) => {
        e.preventDefault();
        setExporting(true);
        try {
            const res = await api.exportVault(exportPass);
            const blob = new Blob([JSON.stringify(res.bundle, null, 2)], { type: 'application/json' });
            const href = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = href;
            a.download = res.filename || 'sshhh-vault-backup.json';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(href);
            setExportPass('');
            toast('Encrypted vault backup downloaded.', 'success');
        } catch (err) {
            toast((err as Error).message, 'error');
        } finally {
            setExporting(false);
        }
    };

    const onImport = async (e: FormEvent) => {
        e.preventDefault();
        const file = fileRef.current?.files?.[0];
        if (!file) {
            toast('Select a vault backup .json file first.', 'warning');
            return;
        }
        setImporting(true);
        try {
            const parsed = JSON.parse(await file.text());
            const res = await api.importVault({ bundle: parsed, passphrase: importPass, overwrite });
            const s = res.summary || {};
            if (res.profiles) setAvailableProfiles(res.profiles);
            emitDataChanged(res);
            setImportPass('');
            setOverwrite(false);
            if (fileRef.current) fileRef.current.value = '';
            toast(
                `Vault restored: ${s.profiles_imported || 0} profile(s), ${s.projects_imported || 0} project(s) imported (${s.projects_skipped || 0} skipped).`,
                'success',
                5500,
            );
        } catch (err) {
            toast('Restore failed: ' + (err as Error).message, 'error');
        } finally {
            setImporting(false);
        }
    };

    return (
        <div className={'settings-tab-pane' + (active ? ' active' : '')} id="settingsPaneVault" style={{ display: active ? 'block' : 'none' }}>
            <div className="vault-section-card" style={{ ...CARD_STYLE, marginBottom: 14 }}>
                <h4 style={{ margin: '0 0 6px', fontSize: 14 }}>📤 Export Encrypted Vault Backup</h4>
                <p style={{ margin: '0 0 10px', fontSize: 12, color: 'var(--text-secondary)' }}>
                    Exports all SSH profiles, projects, encrypted credentials, variables, command pipelines, and notification settings into a portable
                    file encrypted with PBKDF2-SHA256 + AES-256-GCM.
                </p>
                <form id="vaultExportForm" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }} onSubmit={onExport}>
                    <input
                        type="password"
                        className="form-control"
                        id="vault_export_passphrase"
                        required
                        minLength={8}
                        placeholder="Backup passphrase (min 8 chars)"
                        style={{ flex: 1, minWidth: 220 }}
                        autoComplete="new-password"
                        value={exportPass}
                        onChange={(e) => setExportPass(e.target.value)}
                    />
                    <button type="submit" className="btn btn-primary btn-sm" id="exportVaultBtn" disabled={exporting}>
                        Download .json Backup
                    </button>
                </form>
            </div>

            <div className="vault-section-card" style={CARD_STYLE}>
                <h4 style={{ margin: '0 0 6px', fontSize: 14 }}>📥 Restore From Encrypted Backup</h4>
                <p style={{ margin: '0 0 10px', fontSize: 12, color: 'var(--text-secondary)' }}>
                    Select a previously exported <code>sshhh-vault-*.json</code> file and enter its passphrase to restore profiles, projects, and commands.
                </p>
                <form id="vaultImportForm" style={{ display: 'flex', flexDirection: 'column', gap: 10 }} onSubmit={onImport}>
                    <input type="file" className="form-control" id="vault_import_file" accept=".json,application/json" required ref={fileRef} />
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                        <input
                            type="password"
                            className="form-control"
                            id="vault_import_passphrase"
                            required
                            placeholder="Enter backup passphrase"
                            style={{ flex: 1, minWidth: 220 }}
                            autoComplete="off"
                            value={importPass}
                            onChange={(e) => setImportPass(e.target.value)}
                        />
                        <button type="submit" className="btn btn-secondary btn-sm" id="importVaultBtn" disabled={importing}>
                            Restore Vault
                        </button>
                    </div>
                    <label className="toggle-label" htmlFor="vault_import_overwrite" style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                        <input type="checkbox" id="vault_import_overwrite" checked={overwrite} onChange={(e) => setOverwrite(e.target.checked)} />
                        <span>Overwrite existing profiles/projects with the same name &amp; environment</span>
                    </label>
                </form>
            </div>
        </div>
    );
}
