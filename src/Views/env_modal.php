<!-- Remote Environment (.env) Manager & Diff Modal -->
<div class="modal-backdrop" id="remoteEnvModal" aria-hidden="true" style="display:none;">
    <div class="modal remote-env-modal" role="dialog" aria-modal="true" aria-labelledby="envModalTitle" style="max-width:760px; width:92%;">
        <div class="modal-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="confirm-icon-bubble variant-deploy" style="font-size:18px;">📝</div>
                <div>
                    <h3 id="envModalTitle" class="modal-title" style="margin:0; font-size:16px;">Remote .env Configuration</h3>
                    <p id="envModalSubtitle" style="margin:2px 0 0; font-size:12px; color:var(--text-secondary);">Direct editor &amp; .env.example difference checker</p>
                </div>
            </div>
            <button type="button" class="icon-btn" id="envModalCloseBtn" title="Close">&times;</button>
        </div>

        <div class="modal-body" style="padding:16px 20px;">
            <!-- Loading indicator -->
            <div id="envLoadingState" style="text-align:center; padding:36px 16px;">
                <div class="pulse-spinner" style="font-size:28px; margin-bottom:12px;">⚡</div>
                <strong style="display:block; font-size:14px; color:var(--text-primary); margin-bottom:4px;">Connecting &amp; Fetching .env...</strong>
                <span style="font-size:12px; color:var(--text-muted);">Reading remote .env and comparing with .env.example over secure SSH.</span>
            </div>

            <!-- Error banner -->
            <div id="envErrorState" style="display:none; padding:16px; background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); border-radius:10px; color:#fca5a5; font-size:13px; margin-bottom:12px;">
                <strong style="display:block; margin-bottom:4px; color:#fecaca;">Failed to read remote .env:</strong>
                <span id="envErrorMessage"></span>
            </div>

            <!-- Content Area -->
            <div id="envContentState" style="display:none;">
                <!-- Missing Keys Alert Banner -->
                <div id="envMissingKeysAlert" style="display:none; padding:12px 14px; background:rgba(245, 158, 11, 0.12); border:1px solid rgba(245, 158, 11, 0.3); border-radius:8px; margin-bottom:14px;">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                        <span style="font-size:16px;">⚠️</span>
                        <strong style="font-size:12.5px; color:#fde68a;">Missing Environment Variables Detected!</strong>
                    </div>
                    <p style="font-size:11.5px; color:#fef3c7; margin:0 0 8px 0;">
                        The following key(s) exist in <code>.env.example</code> but are missing from your remote <code>.env</code>:
                    </p>
                    <div id="envMissingPills" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <label for="envTextarea" class="form-label" style="margin-bottom:0;">
                        <span>Remote .env Contents</span>
                        <span id="envFilePathBadge" style="font-size:11px; color:var(--text-muted); font-weight:normal; margin-left:6px;"></span>
                    </label>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn btn-ghost btn-xs" id="copyEnvBtn" title="Copy .env to clipboard">📋 Copy</button>
                        <button type="button" class="btn btn-ghost btn-xs" id="refreshEnvBtn" title="Reload from server">🔄 Reload</button>
                    </div>
                </div>

                <textarea
                    id="envTextarea"
                    class="step-cmd-editor"
                    rows="14"
                    placeholder="APP_NAME=Laravel&#10;APP_ENV=production..."
                    spellcheck="false"
                    style="font-family:var(--font-mono); font-size:12px; line-height:1.5; tab-size:4;"
                ></textarea>
                <span class="form-hint" style="margin-top:6px; display:block;">
                    🛡️ Saving creates an automatic timestamped backup (<code>.env.backup.YYYYMMDD_HHMMSS</code>) on the remote server before changes are committed.
                </span>
            </div>
        </div>

        <div class="modal-footer" style="justify-content:space-between;">
            <button type="button" class="btn btn-ghost btn-sm" id="envCloseFooterBtn">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="saveEnvBtn" disabled>
                <span>💾</span> <span>Backup &amp; Save .env</span>
            </button>
        </div>
    </div>
</div>

