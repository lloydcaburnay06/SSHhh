<!-- Remote Server Error Logs Viewer & Management Modal -->
<div class="modal-backdrop" id="remoteLogsModal" aria-hidden="true" style="display:none;">
    <div class="modal remote-logs-modal" role="dialog" aria-modal="true" aria-labelledby="remoteLogsModalTitle" style="max-width:960px; width:95%;">
        <div class="modal-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="confirm-icon-bubble variant-deploy" style="font-size:18px;">📜</div>
                <div>
                    <h3 id="remoteLogsModalTitle" class="modal-title" style="margin:0; font-size:16px;">Remote Server Error Logs</h3>
                    <p id="remoteLogsModalSubtitle" style="margin:2px 0 0; font-size:12px; color:var(--text-secondary);">Direct viewer, downloader &amp; clearer for remote application logs</p>
                </div>
            </div>
            <button type="button" class="icon-btn" id="remoteLogsModalCloseBtn" title="Close">&times;</button>
        </div>

        <div class="modal-body" style="padding:16px 20px;">
            <!-- Loading indicator -->
            <div id="remoteLogsLoadingState" style="text-align:center; padding:36px 16px;">
                <div class="pulse-spinner" style="font-size:28px; margin-bottom:12px;">⚡</div>
                <strong style="display:block; font-size:14px; color:var(--text-primary); margin-bottom:4px;">Connecting &amp; Fetching Remote Logs...</strong>
                <span style="font-size:12px; color:var(--text-muted);">Scanning for log files in storage/logs, error_log, and reading latest entries over SSH.</span>
            </div>

            <!-- Error banner -->
            <div id="remoteLogsErrorState" style="display:none; padding:16px; background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); border-radius:10px; color:#fca5a5; font-size:13px; margin-bottom:12px;">
                <strong style="display:block; margin-bottom:4px; color:#fecaca;">Failed to read remote logs:</strong>
                <span id="remoteLogsErrorMessage"></span>
            </div>

            <!-- Content Area -->
            <div id="remoteLogsContentState" style="display:none;">
                <!-- Controls bar -->
                <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:space-between; margin-bottom:12px; background:rgba(255,255,255,0.03); padding:10px 12px; border-radius:8px; border:1px solid var(--border-color);">
                    <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; flex:1; min-width:260px;">
                        <label for="remoteLogSelect" style="font-size:12px; font-weight:600; color:var(--text-secondary); white-space:nowrap;">Log File:</label>
                        <select id="remoteLogSelect" class="form-select" style="min-width:200px; max-width:280px; font-size:12px; padding:4px 8px;"></select>
                        
                        <div id="remoteLogCustomInputWrap" style="display:none; align-items:center; gap:4px;">
                            <input type="text" id="remoteLogCustomInput" class="form-control" placeholder="path/to/logfile.log" style="width:180px; font-size:12px; padding:4px 8px;">
                            <button type="button" class="btn btn-secondary btn-xs" id="applyCustomLogBtn">Load</button>
                        </div>

                        <select id="remoteLogLinesSelect" class="form-select" style="width:110px; font-size:12px; padding:4px 8px;" title="Number of tail lines to load">
                            <option value="100">100 lines</option>
                            <option value="300" selected>300 lines</option>
                            <option value="500">500 lines</option>
                            <option value="1000">1,000 lines</option>
                        </select>

                        <input type="search" id="remoteLogFilterInput" class="search-input" placeholder="Filter text in log..." style="width:160px; font-size:12px; padding:4px 8px;">
                    </div>

                    <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                        <button type="button" class="btn btn-ghost btn-xs" id="refreshRemoteLogBtn" title="Reload latest log lines">🔄 Reload</button>
                        <button type="button" class="btn btn-ghost btn-xs" id="copyRemoteLogBtn" title="Copy displayed log text">📋 Copy</button>
                        <a href="#" class="btn btn-secondary btn-xs" id="downloadRemoteLogBtn" title="Download the full remote .log file directly">📥 Download .log</a>
                        <button type="button" class="btn btn-danger btn-xs" id="clearRemoteLogBtn" title="Truncate/empty this log file on the remote server">🗑️ Clear Log</button>
                    </div>
                </div>

                <!-- Log text display -->
                <div style="position:relative;">
                    <pre id="remoteLogPre" class="step-cmd-editor" style="height:440px; overflow:auto; margin:0; padding:12px; font-family:var(--font-mono); font-size:11.5px; line-height:1.5; color:#e2e8f0; background:#0b101b; border:1px solid var(--border-color); border-radius:8px; white-space:pre-wrap; word-break:break-all; user-select:text;"></pre>
                </div>

                <!-- Stats Bar -->
                <div id="remoteLogStatsBar" style="display:flex; justify-content:space-between; align-items:center; font-size:11.5px; color:var(--text-muted); margin-top:8px; padding:0 4px;">
                    <span id="remoteLogStatsText">Showing last 0 lines</span>
                    <span id="remoteLogActiveFileBadge" style="font-family:var(--font-mono); color:var(--text-secondary);"></span>
                </div>
            </div>
        </div>

        <div class="modal-footer" style="padding:12px 20px; display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:11.5px; color:var(--text-muted);">
                💡 Tip: Clearing truncates the remote file to 0 bytes so new errors are immediately visible.
            </span>
            <button type="button" class="btn btn-secondary btn-sm" id="remoteLogsCloseFooterBtn">Close</button>
        </div>
    </div>
</div>

