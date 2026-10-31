<!-- Command Execution & Confirmation Modal -->
<div class="modal-backdrop command-run-modal-backdrop" id="commandRunModal" aria-hidden="true" style="display:none;">
    <div class="modal command-run-modal" role="dialog" aria-modal="true" aria-labelledby="execModalTitle">
        <div class="modal-header command-run-modal-header">
            <div class="exec-modal-title-group">
                <div class="confirm-icon-bubble variant-deploy" id="execModalIcon">⚡</div>
                <div>
                    <h3 id="execModalTitle" class="modal-title">Execute Remote Command</h3>
                    <p id="execModalSubtitle" class="exec-modal-subtitle">Review, edit, and confirm before running on the target server</p>
                </div>
            </div>
            <button type="button" class="icon-btn" id="execModalCloseBtn" title="Close">&times;</button>
        </div>

        <div class="modal-body command-run-modal-body">
            <!-- Project Target Selector -->
            <div class="form-group" style="margin-bottom: 14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label for="execModalProjectSelect" class="form-label" style="margin-bottom:0;">
                        Target Project &amp; Host
                    </label>
                    <span id="execModalEnvBadge" class="badge"></span>
                </div>
                <select id="execModalProjectSelect" class="form-select"></select>
                <div class="exec-project-meta" id="execProjectMeta">
                    <span id="execProjectTarget"></span>
                    <span id="execProjectDir"></span>
                </div>
                <div class="exec-prod-warning" id="execProdWarning" style="display:none;">
                    <span style="font-size:18px;">⚠️</span>
                    <div>
                        <strong>CAUTION: Production Environment Selected</strong>
                        <p style="margin:2px 0 0; font-size:12px; color:#fecaca;">This command will execute directly against your live production server.</p>
                    </div>
                </div>
            </div>

            <!-- Command Editor & Sudo Toggle -->
            <div class="form-group" style="margin-bottom: 14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label for="execModalCmdText" class="form-label" style="margin-bottom:0;">
                        Command to Run
                        <span style="font-size:11px; color:var(--text-muted); font-weight:normal;">(Editable shell command)</span>
                    </label>
                    <label class="exec-sudo-toggle" title="Prefix command with sudo for administrator privileges">
                        <input type="checkbox" id="execModalSudoToggle">
                        <span>🛡️ Run as Admin (sudo)</span>
                    </label>
                </div>
                <textarea
                    id="execModalCmdText"
                    class="step-cmd-editor exec-cmd-editor"
                    rows="3"
                    placeholder="Enter command to execute..."
                    spellcheck="false"
                ></textarea>
                <div class="exec-cmd-desc" id="execModalCmdDesc" style="display:none;"></div>
            </div>

            <!-- Embedded Live Terminal Console -->
            <div class="exec-terminal-wrap" id="execModalTerminalWrap" style="display:none;">
                <div class="exec-terminal-header">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <div class="terminal-dots">
                            <span class="t-dot red"></span>
                            <span class="t-dot yellow"></span>
                            <span class="t-dot green"></span>
                        </div>
                        <strong style="font-size:12px;">Live Output</strong>
                        <span id="execTerminalStatus"></span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span id="execTerminalTimer" style="font-family:var(--font-mono); font-size:11.5px; color:var(--text-secondary);"></span>
                        <button type="button" class="btn btn-ghost btn-xs" id="execCopyOutputBtn" title="Copy output">Copy</button>
                        <button type="button" class="btn btn-ghost btn-xs" id="execClearOutputBtn" title="Clear output">Clear</button>
                    </div>
                </div>
                <pre class="terminal-output exec-terminal-output" id="execModalOutput">Ready to execute.</pre>
            </div>
        </div>

        <div class="modal-footer command-run-modal-footer">
            <button type="button" class="btn btn-ghost" id="execModalCancelBtn">Cancel</button>
            <button type="button" class="btn btn-primary" id="execModalSubmitBtn">
                <span>&#9654;</span>
                <span id="execModalSubmitLabel">Run Command</span>
            </button>
        </div>
    </div>
</div>

