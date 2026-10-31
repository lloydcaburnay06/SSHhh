<!-- Server Pulse & Resource Inspector Modal -->
<div class="modal-backdrop" id="serverPulseModal" aria-hidden="true" style="display:none;">
    <div class="modal server-pulse-modal" role="dialog" aria-modal="true" aria-labelledby="pulseModalTitle">
        <div class="modal-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="confirm-icon-bubble variant-deploy" style="font-size:18px;">🩺</div>
                <div>
                    <h3 id="pulseModalTitle" class="modal-title" style="margin:0; font-size:16px;">Server Pulse</h3>
                    <p id="pulseModalSubtitle" style="margin:2px 0 0; font-size:12px; color:var(--text-secondary);">Live Resource &amp; Service Health</p>
                </div>
            </div>
            <button type="button" class="icon-btn" id="pulseModalCloseBtn" title="Close">&times;</button>
        </div>

        <div class="modal-body" style="padding:16px 20px;">
            <!-- Loading State -->
            <div id="pulseLoadingState" style="text-align:center; padding:36px 16px;">
                <div class="pulse-spinner" style="font-size:28px; margin-bottom:12px;">⚡</div>
                <strong style="display:block; font-size:14px; color:var(--text-primary); margin-bottom:4px;">Connecting to Remote Server...</strong>
                <span style="font-size:12px; color:var(--text-muted);">Querying system vitals, memory, storage, and services over SSH.</span>
            </div>

            <!-- Error State -->
            <div id="pulseErrorState" style="display:none; padding:20px; background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); border-radius:10px; color:#fca5a5; font-size:13px;">
                <strong style="display:block; margin-bottom:6px; color:#fecaca;">Failed to query server vitals:</strong>
                <span id="pulseErrorMessage"></span>
            </div>

            <!-- Content State -->
            <div id="pulseContentState" style="display:none;">
                <!-- System Info Bar -->
                <div class="pulse-system-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; padding:10px 14px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:8px; margin-bottom:16px;">
                    <div>
                        <span style="font-size:11px; text-transform:uppercase; color:var(--text-muted); font-weight:600; display:block;">Operating System</span>
                        <span id="pulseOs" style="font-size:12.5px; font-weight:600; font-family:var(--font-mono); color:var(--text-primary);">Linux</span>
                    </div>
                    <div>
                        <span style="font-size:11px; text-transform:uppercase; color:var(--text-muted); font-weight:600; display:block;">PHP Runtime</span>
                        <span id="pulsePhp" style="font-size:12.5px; font-weight:600; font-family:var(--font-mono); color:var(--brand-accent);">PHP</span>
                    </div>
                    <div>
                        <span style="font-size:11px; text-transform:uppercase; color:var(--text-muted); font-weight:600; display:block;">Uptime</span>
                        <span id="pulseUptime" style="font-size:12px; color:var(--text-secondary);">-</span>
                    </div>
                </div>

                <!-- 3 Metric Cards Grid -->
                <div class="pulse-metrics-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:18px;">
                    <!-- CPU Load -->
                    <div class="pulse-metric-card" style="padding:14px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:10px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">CPU Load</span>
                            <span style="font-size:14px;">⚙️</span>
                        </div>
                        <div id="pulseCpuValue" style="font-size:20px; font-weight:700; font-family:var(--font-mono); color:var(--text-primary); margin-bottom:4px;">0.00</div>
                        <div style="font-size:11px; color:var(--text-muted); margin-bottom:8px;">Load average (1m, 5m, 15m)</div>
                        <div id="pulseCpuLoadPills" style="font-size:11px; font-family:var(--font-mono); color:var(--text-secondary); display:flex; gap:6px;"></div>
                    </div>

                    <!-- RAM / Memory -->
                    <div class="pulse-metric-card" style="padding:14px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:10px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Memory (RAM)</span>
                            <span style="font-size:14px;">🧠</span>
                        </div>
                        <div id="pulseRamValue" style="font-size:20px; font-weight:700; font-family:var(--font-mono); color:var(--text-primary); margin-bottom:4px;">0%</div>
                        <div id="pulseRamText" style="font-size:11px; color:var(--text-muted); margin-bottom:8px;">0 MB / 0 MB used</div>
                        <div class="pulse-progress-track" style="height:6px; background:rgba(255,255,255,0.08); border-radius:999px; overflow:hidden;">
                            <div id="pulseRamBar" style="height:100%; width:0%; background:var(--brand-accent); border-radius:999px; transition:width 0.4s ease;"></div>
                        </div>
                    </div>

                    <!-- Disk Storage -->
                    <div class="pulse-metric-card" style="padding:14px; background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:10px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Disk Storage</span>
                            <span style="font-size:14px;">💾</span>
                        </div>
                        <div id="pulseDiskValue" style="font-size:20px; font-weight:700; font-family:var(--font-mono); color:var(--text-primary); margin-bottom:4px;">0%</div>
                        <div id="pulseDiskText" style="font-size:11px; color:var(--text-muted); margin-bottom:8px;">0 / 0 used</div>
                        <div class="pulse-progress-track" style="height:6px; background:rgba(255,255,255,0.08); border-radius:999px; overflow:hidden;">
                            <div id="pulseDiskBar" style="height:100%; width:0%; background:var(--brand-accent); border-radius:999px; transition:width 0.4s ease;"></div>
                        </div>
                    </div>
                </div>

                <!-- Active Services Section -->
                <div>
                    <label class="form-label" style="font-size:12px; margin-bottom:8px; display:block;">Active Web &amp; Background Services</label>
                    <div id="pulseServicesList" style="display:flex; flex-wrap:wrap; gap:8px;"></div>
                </div>
            </div>
        </div>

        <div class="modal-footer" style="justify-content:space-between;">
            <button type="button" class="btn btn-secondary btn-sm" id="pulseRefreshBtn">
                <span>🔄</span> <span>Refresh Vitals</span>
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="pulseCloseFooterBtn">Close</button>
        </div>
    </div>
</div>

