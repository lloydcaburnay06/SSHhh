<!-- Releases & Instant Rollback Modal -->
<div class="modal-backdrop" id="releasesModal" aria-hidden="true" style="display:none;">
    <div class="modal releases-modal" role="dialog" aria-modal="true" aria-labelledby="releasesModalTitle" style="max-width:680px; width:92%;">
        <div class="modal-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="confirm-icon-bubble variant-deploy" style="font-size:18px;">⏪</div>
                <div>
                    <h3 id="releasesModalTitle" class="modal-title" style="margin:0; font-size:16px;">Releases &amp; 1-Click Rollback</h3>
                    <p id="releasesModalSubtitle" style="margin:2px 0 0; font-size:12px; color:var(--text-secondary);">Switch active release symlink with zero downtime</p>
                </div>
            </div>
            <button type="button" class="icon-btn" id="releasesModalCloseBtn" title="Close">&times;</button>
        </div>

        <div class="modal-body" style="padding:16px 20px;">
            <!-- Loading -->
            <div id="releasesLoadingState" style="text-align:center; padding:36px 16px;">
                <div class="pulse-spinner" style="font-size:28px; margin-bottom:12px;">⚡</div>
                <strong style="display:block; font-size:14px; color:var(--text-primary); margin-bottom:4px;">Scanning Historical Releases...</strong>
                <span style="font-size:12px; color:var(--text-muted);">Querying releases folder and active symlink over SSH.</span>
            </div>

            <!-- Error -->
            <div id="releasesErrorState" style="display:none; padding:16px; background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); border-radius:10px; color:#fca5a5; font-size:13px;">
                <strong style="display:block; margin-bottom:4px; color:#fecaca;">Failed to query releases:</strong>
                <span id="releasesErrorMessage"></span>
            </div>

            <!-- Content -->
            <div id="releasesContentState" style="display:none;">
                <div id="releasesDirectNotice" style="display:none; padding:12px 14px; background:rgba(99, 102, 241, 0.1); border:1px solid rgba(99, 102, 241, 0.25); border-radius:8px; margin-bottom:14px; font-size:12px; color:var(--text-secondary);">
                    💡 <strong>Direct Directory Mode:</strong> This project is currently configured to deploy directly into the working directory. To enable atomic zero-downtime releases with 1-click rollback, change <strong>Deployment Strategy</strong> to <strong>Atomic Symlink</strong> in project settings.
                </div>

                <div id="releasesTableWrap">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Available Historical Releases</span>
                        <button type="button" class="btn btn-ghost btn-xs" id="refreshReleasesBtn">🔄 Refresh</button>
                    </div>
                    <div class="releases-list" id="releasesList" style="display:flex; flex-direction:column; gap:8px;"></div>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-ghost btn-sm" id="releasesCloseFooterBtn">Close</button>
        </div>
    </div>
</div>

