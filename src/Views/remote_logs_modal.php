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
                        <span style="width:1px; height:18px; background:var(--border-color); margin:0 2px;"></span>
                        <button type="button" class="btn btn-ghost btn-xs" id="claudeLinkToggleBtn" title="Link this project to a Claude Code scheduled task">🔗 Claude link</button>
                        <button type="button" class="btn btn-deploy btn-xs" id="sendLogToClaudeBtn" title="Send this log's errors to Claude Code to fix, then clear what was sent">🤖 Send to Claude</button>
                    </div>
                </div>

                <!-- Claude Code link (per project) -->
                <div id="claudeLinkPanel" style="display:none; margin-bottom:12px; padding:12px 14px; border:1px solid var(--border-color); border-radius:8px; background:rgba(255,255,255,0.03);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <strong style="font-size:13px;">🔗 Claude Code link for this project</strong>
                        <span id="claudeLinkStatus" style="font-size:11.5px; color:var(--text-muted);"></span>
                    </div>
                    <p style="font-size:11.5px; color:var(--text-secondary); margin:0 0 10px;">
                        Create a scheduled task for this project's repository at
                        <a href="https://claude.ai/code/routines" target="_blank" rel="noopener">claude.ai/code/routines</a>,
                        paste the prompt below into it, add an <strong>API</strong> trigger, then copy its Fire URL and token here.
                    </p>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                        <label style="font-size:11.5px; color:var(--text-secondary);">Fire URL
                            <input type="url" id="claudeFireUrlInput" class="form-control" placeholder="https://api.anthropic.com/v1/claude_code/routines/trig_…/fire" style="font-size:12px; padding:4px 8px; width:100%;">
                        </label>
                        <label style="font-size:11.5px; color:var(--text-secondary);">API token
                            <input type="password" id="claudeTokenInput" class="form-control" placeholder="sk-ant-oat01-…" autocomplete="new-password" style="font-size:12px; padding:4px 8px; width:100%;">
                        </label>
                        <label style="font-size:11.5px; color:var(--text-secondary);">Merge fixes into branch
                            <input type="text" id="claudeMergeBranchInput" class="form-control" placeholder="main" style="font-size:12px; padding:4px 8px; width:100%;">
                        </label>
                        <label style="font-size:11.5px; color:var(--text-secondary); display:flex; align-items:center; gap:6px; margin-top:16px;">
                            <input type="checkbox" id="claudeClearAfterSendInput" checked>
                            Clear sent lines from the log after Claude Code receives them
                        </label>
                        <label style="font-size:11.5px; color:var(--text-secondary);">Log file to check automatically
                            <input type="text" id="claudeWatchFileInput" class="form-control" placeholder="auto" title="auto = every storage/logs/laravel*.log changed in the last 2 days (laravel.log and daily laravel-YYYY-MM-DD.log). Or enter one file, e.g. storage/logs/custom.log" style="font-size:12px; padding:4px 8px; width:100%;">
                            <span style="display:block; font-size:10.5px; color:var(--text-muted); margin-top:2px;">auto = all recent storage/logs/laravel*.log files (incl. daily logs)</span>
                        </label>
                        <label style="font-size:11.5px; color:var(--text-secondary); display:flex; align-items:center; gap:6px; margin-top:16px;" title="Used by cli/log_watch.php (Windows Task Scheduler, every 10 minutes)">
                            <input type="checkbox" id="claudeAutoWatchInput">
                            Send new errors to Claude automatically (every project's log is checked every 5 minutes; without this, you only get a notification)
                        </label>
                    </div>
                    <div id="claudeWatchStatus" style="font-size:11.5px; color:var(--text-muted); margin-top:6px;"></div>
                    <label style="display:block; font-size:11.5px; color:var(--text-secondary); margin-top:10px;">Prompt for the scheduled task
                        <textarea id="claudePromptTemplate" readonly rows="6" class="form-control" style="width:100%; font-family:var(--font-mono); font-size:11px; padding:6px 8px; resize:vertical;"></textarea>
                    </label>
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:8px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-ghost btn-xs" id="copyClaudePromptBtn">📋 Copy prompt</button>
                        <div style="display:flex; gap:6px;">
                            <button type="button" class="btn btn-ghost btn-xs" id="removeClaudeLinkBtn" style="display:none;">Remove link</button>
                            <button type="button" class="btn btn-primary btn-xs" id="saveClaudeLinkBtn">Save link</button>
                        </div>
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

