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
                        1. Target Project &amp; Server
                    </label>
                    <span id="execModalEnvBadge" class="badge"></span>
                </div>
                <select id="execModalProjectSelect" class="form-select"></select>
                <div class="exec-project-meta" id="execProjectMeta">
                    <span id="execProjectTarget"></span>
                    <span id="execProjectDefaultDir"></span>
                </div>
                <div class="exec-prod-warning" id="execProdWarning" style="display:none;">
                    <span style="font-size:18px;">⚠️</span>
                    <div>
                        <strong>CAUTION: Production Environment Selected</strong>
                        <p style="margin:2px 0 0; font-size:12px; color:#fecaca;">This command will execute directly against your live production server.</p>
                    </div>
                </div>
            </div>

            <!-- Working Directory / Folder Browser -->
            <div class="form-group" style="margin-bottom: 14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label for="execModalDirInput" class="form-label" style="margin-bottom:0;">
                        2. Target Directory (Folder to run inside)
                    </label>
                    <button type="button" class="btn btn-ghost btn-xs" id="execModalBrowseDirsBtn" title="List actual subdirectories on the remote server">
                        📂 Browse Remote Folders
                    </button>
                </div>
                <div class="exec-dir-input-wrap">
                    <input
                        type="text"
                        id="execModalDirInput"
                        class="form-control"
                        placeholder="e.g. /var/www/html or ~"
                        spellcheck="false"
                    >
                    <button type="button" class="btn btn-secondary btn-sm" id="execModalResetDirBtn" title="Reset to project default root directory">
                        Reset Root
                    </button>
                </div>
                <!-- Quick Folder Chips -->
                <div class="exec-dir-chips" id="execModalDirChips">
                    <span style="font-size:11px; color:var(--text-muted); align-self:center;">Quick pick:</span>
                    <button type="button" class="dir-chip active" data-dir-chip="root" title="Use project root path">Project Root</button>
                    <button type="button" class="dir-chip" data-dir-chip="public">public</button>
                    <button type="button" class="dir-chip" data-dir-chip="storage">storage</button>
                    <button type="button" class="dir-chip" data-dir-chip="storage/logs">storage/logs</button>
                    <button type="button" class="dir-chip" data-dir-chip="bootstrap/cache">bootstrap/cache</button>
                    <button type="button" class="dir-chip" data-dir-chip="database">database</button>
                    <button type="button" class="dir-chip" data-dir-chip="~">~ ($HOME)</button>
                </div>
                <!-- Remote Folder Browser Box (Collapsible) -->
                <div class="exec-remote-browser-box" id="execRemoteBrowserBox" style="display:none;">
                    <div class="exec-browser-header">
                        <span style="font-size:11.5px; font-weight:600; color:var(--text-secondary);">Remote Subdirectories:</span>
                        <span id="execBrowserLoading" style="font-size:11px; color:var(--text-muted); display:none;">Scanning...</span>
                    </div>
                    <div class="exec-browser-list" id="execBrowserList"></div>
                </div>
            </div>

            <!-- Command Editor & Sudo Toggle -->
            <div class="form-group" style="margin-bottom: 14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label for="execModalCmdText" class="form-label" style="margin-bottom:0;">
                        3. Command to Run
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

