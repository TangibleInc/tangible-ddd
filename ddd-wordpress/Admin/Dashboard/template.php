    <div class="tddd-root" data-theme="warm-blueprint">
      <svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
        <symbol id="tddd-hex" viewBox="0 0 39 39">
          <polygon points="37.5,19.5 28.5,35.1 10.5,35.1 1.5,19.5 10.5,3.9 28.5,3.9" fill="none" stroke="#6359D6" stroke-width="2.1" stroke-linejoin="round"/>
          <rect x="9" y="9" width="7" height="7" rx="1" fill="#6359D6"/><rect x="16" y="9" width="7" height="7" rx="1" fill="#6359D6"/><rect x="23" y="9" width="7" height="7" rx="1" fill="#6359D6"/>
          <rect x="9" y="16" width="7" height="7" rx="1" fill="#6359D6"/><rect x="23" y="16" width="7" height="7" rx="1" fill="#6359D6"/><rect x="16" y="23" width="7" height="7" rx="1" fill="#FD9597"/>
          <circle r="2.1" fill="#FD9597"><animateMotion dur="2.6s" repeatCount="indefinite" path="M12.5 12.5 L19.5 12.5 L26.5 12.5 L26.5 19.5 L19.5 26.5 L12.5 19.5 Z"/></circle>
        </symbol>
      </defs></svg>

      <div class="tddd-app">
        <!-- Row 1: brand + vitals strip (left-grouped, right of logo) -->
        <div class="tb">
          <div class="tb-brand"><svg class="hx"><use href="#tddd-hex"/></svg><div><div class="bn">Tangible Dash</div><div class="bs">DDD OBSERVABILITY</div></div></div>
          <div class="tb-vitals" id="tddd-vitals">
            <span class="vt-pulse" id="tddd-v-pulse"><span class="live-pulse">live</span></span>
            <span class="vt-sep"></span>
            <span class="vt-stat" id="tddd-v-cmds24" title="commands dispatched in last 24h"><span class="vt-n">—</span><span class="vt-u">cmds 24h</span></span>
            <span class="vt-stat" id="tddd-v-succ" title="success rate 24h"><span class="vt-n">—</span><span class="vt-u">%ok</span></span>
            <span class="vt-stat vt-err" id="tddd-v-err" title="errors in last 24h"><span class="vt-n">—</span><span class="vt-u">err 24h</span></span>
            <span class="vt-stat" id="tddd-v-p95" title="P95 command duration 24h"><span class="vt-n">—</span><span class="vt-u">p95</span></span>
            <span class="vt-sep"></span>
            <span class="vt-stat vt-dlq" id="tddd-v-dlq" title="DLQ depth"><span class="vt-u">DLQ</span><span class="vt-n">—</span></span>
            <span class="vt-stat vt-obx" id="tddd-v-obx" title="outbox pending · oldest age"><span class="vt-u">outbox</span><span class="vt-n">—</span></span>
            <span class="vt-stat vt-ifl" id="tddd-v-ifl" title="in-flight: in_progress commands + running workflows + suspended processes"><span class="vt-n">—</span><span class="vt-u">in-flight</span></span>
          </div>
        </div>
        <!-- Row 2: consumer selector -->
        <div class="scope">
          <div class="sl">Consumer</div>
          <div class="cz" id="tddd-consumers"></div>
        </div>
        <!-- Row 3: view nav -->
        <nav class="tnav" id="tddd-nav">
          <div class="sl">Lens</div>
          <button data-view="flow" aria-selected="true">Flow</button>
          <button data-view="audit">Command Audit</button>
          <button data-view="trace">Trace</button>
          <button data-view="biography">Biography</button>
          <button data-view="proc">Processes</button>
          <button data-view="dlq">DLQ</button>
          <button data-view="outbox">Outbox</button>
        </nav>

        <div id="tddd-view-flow" class="tview">
          <div class="flow-grid">
            <div class="flow-left">
              <div class="panel"><div class="ph"><span class="lbl">top commands &middot; 24h</span></div><div id="tddd-topcmds"></div></div>
              <div class="panel"><div class="ph"><span class="lbl">attention</span></div><div id="tddd-attention"></div></div>
            </div>
            <div class="flow-right">
              <div class="live-head"><span class="lbl">command bus</span><span class="live-badge" id="tddd-live-badge"></span><span class="live-pulse" id="tddd-live-pulse">live</span></div>
              <div class="console"><div class="tlines" id="tddd-live-lines"></div></div>
              <div class="panel"><div class="ph"><span class="lbl">storage &middot; operational tables</span><button class="abtn danger" id="tddd-purge" title="GC completed outbox rows older than 30 days">Purge completed</button></div><div id="tddd-storage" class="storage"></div></div>
            </div>
          </div>
        </div>

        <div id="tddd-view-audit" class="tview" hidden>
        <div class="toolbar">
          <span class="search"><span class="mag">&#8981;</span><input id="tddd-search" placeholder="command, command_id, or correlation&hellip;"></span>
          <span class="fg"><span class="fl">status</span>
            <select id="tddd-sel-status" class="fsel">
              <option value="">all</option>
              <option value="success">success</option>
              <option value="error">error</option>
              <option value="in_progress">in_progress</option>
            </select>
          </span>
          <span class="fg"><span class="fl">source</span>
            <select id="tddd-sel-source" class="fsel">
              <option value="">all</option>
              <option value="user">user</option>
              <option value="system">system</option>
              <option value="cli">cli</option>
              <option value="action_scheduler">action_scheduler</option>
            </select>
          </span>
          <span class="fg tddd-fg-from"><span class="fl">from</span>
            <input type="date" id="tddd-from" class="fdate">
          </span>
          <span class="fg tddd-fg-to"><span class="fl">to</span>
            <input type="date" id="tddd-to" class="fdate">
          </span>
          <span class="tddd-scrubber-wrap" id="tddd-audit-scrubber-wrap"><!-- scrubber injected here by JS --></span>
          <button class="tddd-scrub-toggle abtn ghost" id="tddd-audit-scrub-toggle" title="Toggle scrubber / date inputs">&#8644;</button>
          <span class="count" id="tddd-count"></span>
        </div>
        <div class="tpager-bar"><span id="tddd-range"></span><span class="pager" id="tddd-pager"></span></div>

        <div class="panel">
          <table class="t">
            <thead><tr>
              <th data-sort="command_name">command</th>
              <th>command_id</th>
              <th>correlation</th>
              <th data-sort="source">source</th>
              <th data-sort="status">status</th>
              <th data-sort="duration_ms">duration</th>
              <th class="sorted-desc">started</th>
            </tr></thead>
            <tbody id="tddd-rows"><tr><td colspan="7" class="empty">Loading&hellip;</td></tr></tbody>
          </table>
          <div class="tfoot"><span class="pager" id="tddd-pager-bot"></span></div>
        </div>
        </div><!-- /view-audit -->

        <div id="tddd-view-trace" class="tview" hidden>
          <div class="tbreadcrumb"><button class="bck" id="tddd-trace-back">&lsaquo; Command Audit</button><span class="bsep">&#9656;</span><span class="bcur">Trace</span></div>
          <!-- Recent-traces list (shown when no trace is selected) -->
          <div id="tddd-trace-recent" hidden>
            <div class="panel">
              <div class="ph"><span class="lbl">recent traces &middot; 2 min window</span><span class="live-pulse" style="margin-left:auto">live</span></div>
              <div id="tddd-trc-newbar" class="trc-newbar" role="button" tabindex="0"></div>
              <div class="trc-list" id="tddd-trc-list"></div>
            </div>
          </div>
          <!-- Open-trace view (shown when a trace is selected) -->
          <div id="tddd-trace-open" hidden>
          <div class="trace-head" id="tddd-trace-head"></div>
          <div class="trace-legend">
            <span class="lg"><span class="sw" style="background:#6359D6"></span>command</span>
            <span class="lg"><span class="sw" style="background:#7C4DE0"></span>workflow</span>
            <span class="lg"><span class="sw" style="background:#2E7D8A"></span>event</span>
            <span class="lg"><span class="sw" style="background:#507F06"></span>process</span>
            <span class="lg"><span class="sw" style="background:#C22F32"></span>failed</span>
            <span class="lg lg-note">&#9474; real duration &middot; &#8942; async gap (elapsed shown)</span>
          </div>
          <div class="panel trace-panel">
            <div class="trace-scroll">
              <div class="ruler"><div class="rl"><span class="lbl">span</span></div><div class="rt" id="tddd-ruler"></div></div>
              <div class="trows" id="tddd-trace-rows"></div>
            </div>
          </div>
          <div id="tddd-trace-workflows"></div>
          </div><!-- /tddd-trace-open -->
        </div>

        <div id="tddd-view-biography" class="tview" hidden>
          <div id="tddd-biography-list">
            <div class="toolbar bio-toolbar">
              <span class="search"><span class="mag">&#8981;</span><input id="tddd-biography-search" placeholder="aggregate name or id&hellip;"></span>
              <span class="count" id="tddd-biography-count"></span>
            </div>
            <div class="tpager-bar"><span id="tddd-biography-range"></span><span class="pager" id="tddd-biography-pager"></span></div>
            <div class="panel bio-list-panel">
              <div class="twrap"><table class="t biography-table">
                <thead><tr><th>aggregate</th><th>id</th><th>versions</th><th>touches</th><th>last change</th><th>when</th></tr></thead>
                <tbody id="tddd-biography-rows"><tr><td colspan="6" class="empty">Loading&hellip;</td></tr></tbody>
              </table></div>
            </div>
          </div>
          <div id="tddd-biography-detail" hidden>
            <div class="tbreadcrumb"><button class="bck" id="tddd-biography-back">&lsaquo; All aggregates</button><span class="bsep">&#9656;</span><span class="bcur">Biography</span></div>
            <div class="bio-head" id="tddd-biography-head"></div>
            <div class="bio-timeline" id="tddd-biography-timeline"></div>
          </div>
        </div>

        <div id="tddd-view-proc" class="tview" hidden>
          <div class="subtabs">
            <button data-sub="sagas" aria-selected="true">Sagas <span class="sublbl">long_processes</span></button>
            <button data-sub="workflows">Workflows <span class="sublbl">behaviour_workflows</span></button>
            <span class="subcount" id="tddd-proc-count"></span>
          </div>
          <div id="tddd-sub-sagas"><div class="plist" id="tddd-sagas"></div></div>
          <div id="tddd-sub-workflows" hidden><div class="plist" id="tddd-workflows"></div></div>
        </div>

        <div id="tddd-view-tables" class="tview" hidden>
          <div class="toolbar">
            <span class="lbl" id="tddd-tables-label" style="font-size:.75rem">integration_dlq</span>
            <span class="fg" id="tddd-tables-status-wrap"><span class="fl">status</span>
              <select id="tddd-sel-tables-status" class="fsel">
                <option value="">all</option>
                <option value="pending">pending</option>
                <option value="processing">processing</option>
                <option value="completed">completed</option>
                <option value="failed">failed</option>
                <option value="dlq">dlq</option>
                <option value="cancelled">cancelled</option>
              </select>
            </span>
            <span class="fg tddd-fg-tables-from"><span class="fl">from</span>
              <input type="date" id="tddd-tables-from" class="fdate">
            </span>
            <span class="fg tddd-fg-tables-to"><span class="fl">to</span>
              <input type="date" id="tddd-tables-to" class="fdate">
            </span>
            <span class="tddd-scrubber-wrap" id="tddd-tables-scrubber-wrap"><!-- scrubber injected here by JS --></span>
            <button class="tddd-scrub-toggle abtn ghost" id="tddd-tables-scrub-toggle" title="Toggle scrubber / date inputs">&#8644;</button>
            <span class="subcount" id="tddd-tables-count" style="margin-left:auto"></span>
          </div>
          <div class="tpager-bar"><span id="tddd-tables-range"></span><span class="pager" id="tddd-tables-pager"></span></div>
          <div class="panel" style="margin:0 14px 14px">
            <div class="twrap"><table class="t"><thead id="tddd-tables-head"></thead><tbody id="tddd-tables-body"><tr><td class="empty">Loading&hellip;</td></tr></tbody></table></div>
          </div>
        </div>
      </div>

      <div class="tddd-confirm" id="tddd-confirm" hidden>
        <div class="cback" data-cancel></div>
        <div class="cbox"><div class="cmsg" id="tddd-confirm-msg"></div><div class="cact"><button class="abtn" id="tddd-confirm-ok">Confirm</button><button class="abtn ghost" data-cancel>Cancel</button></div></div>
      </div>
      <div class="tddd-toast" id="tddd-toast" hidden></div>

      <div class="tddd-drawer" id="tddd-drawer" hidden>
        <div class="dback" data-close></div>
        <aside class="dpanel">
          <div class="dh"><span class="lbl" id="tddd-drawer-label">record</span><button class="dx" data-close>&times;</button></div>
          <div class="dbody" id="tddd-drawer-body"></div>
        </aside>
      </div>
    </div>
