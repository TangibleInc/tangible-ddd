    (function(){
      // Trace island: the trace rows region + record drawer rendered declaratively
      // with vendored Preact + htm (classic scripts, no build step). The vanilla
      // shell (dashboard.js) keeps fetching/routing/live-polling and calls in here.
      var html = htm.bind(preact.h);
      var useState = preactHooks.useState;
      var useRef = preactHooks.useRef;
      var useLayoutEffect = preactHooks.useLayoutEffect;

      function shortName(s){ if(!s) return '—'; var parts=String(s).split('\\'); return parts[parts.length-1]; }
      function shortId(s){ return s ? s.slice(0,8)+'…'+s.slice(-4) : '—'; }
      function fmtTraceSpan(s){
        s=Math.max(0,Math.floor(Number(s)||0));
        if(s===0) return '0s';
        var units=[['d',86400],['h',3600],['m',60],['s',1]], parts=[];
        units.forEach(function(u){ var n=Math.floor(s/u[1]); if(n>0&&parts.length<2){ parts.push(n+u[0]); s-=n*u[1]; } });
        return parts.join(' ');
      }
      function fmtTraceTime(s){ return '+'+fmtTraceSpan(s); }

      // ── trace rows ──

      function portStatusCls(status){
        return status==='dlq'?'p-dlq':(status==='failed'?'p-failed':(status==='completed'?'p-completed':'p-pending'));
      }

      function TraceRow(props){
        var n=props.node, prev=props.prev, maxDur=props.maxDur, isNew=props.isNew;
        var accent=n.accent||'#646970';
        var kind = n.is_workflow ? 'workflow' : n.kind;   // workflow supersedes command
        var depth=Math.min(n.depth||0,5);
        var statusCls=(n.status==='error'||n.status==='dlq')?(' s-'+n.status):'';
        var handoff=n.cross_consumer
          ? html`<span class="cross-handoff" title="handoff from ${n.parent_consumer_label||n.parent_consumer} to ${n.consumer_label||n.consumer}"><i style=${'background:'+(n.parent_accent||'#646970')}></i><b>→</b><i style=${'background:'+accent}></i></span>`
          : null;
        // Adjacency implies parentage: the from-line earns its ink only on
        // a handoff or when the parent is not the row directly above.
        var showFrom=n.parent_label && (n.cross_consumer || !prev || prev.uid!==n.parent);
        var moments=n.moments||[], reactionCount=0;
        moments.forEach(function(m){ reactionCount+=((m&&m.reactions)||[]).length; });
        var barTxt='', latBar=null;
        if(n.kind==='command' && n.raw && n.raw.duration_ms!=null){
          var ms=n.raw.duration_ms;
          barTxt=ms+'ms';
          var pct=Math.max(Math.round(ms/maxDur*100),ms>0?3:0);
          // Severity is ABSOLUTE (ms) — the slowest span in an all-fast trace must not read as failure.
          var latCls=ms>=1000?'slow':(ms>=300?'hot':'');
          latBar=html`<div class="lat-wrap"><span class="lat-track"><i class=${latCls} style=${'width:'+pct+'%'}></i></span><span class="lat-ms">${ms}ms</span></div>`;
        }
        // Ports: emitted facts docked after the bar — glyphs, never durations.
        var ports=n.ports||[];
        var portLbl=null;
        if(ports.some(function(p){return p.status==='dlq';})){ portLbl=html`<span class="sportlbl dlq">dlq!</span>`; }
        else if(ports.length===1){ portLbl=html`<span class="sportlbl">${shortName(ports[0].name)}</span>`; }
        var sports=ports.length
          ? html`<span class="sports" style=${'left:calc('+(n.start_pct+Math.max(n.width_pct,1))+'% + 9px)'}>${ports.map(function(p){
              return html`<span class="sport ${portStatusCls(p.status)}" title="${p.name} · ${p.status}"></span>`;
            })}${portLbl}</span>`
          : null;
        return html`<div
          class=${'srow is-node d'+depth+(n.unresolved?' is-unresolved':'')+(isNew?' tddd-new':'')}
          data-uid=${n.uid}
          style=${'--owner-accent:'+accent}
          ref=${props.rowRef}
          onClick=${props.onClick}>
          <div class="slabel" style=${'--owner-accent:'+accent}>
            <div class="snrow"><span class="sdot" style=${'background:'+accent}></span><span class="sname" title=${n.name}>${shortName(n.name)}</span><span class="stype">${kind}</span>${moments.length?html`<button class="mchip" data-dtab="inside" title="open: inside the act">×${moments.length}${reactionCount?' · '+reactionCount+' reactions':''}</button>`:null}</div>
            ${showFrom?html`<div class="sfrom">↳ from <b>${n.parent_label}</b>${handoff}</div>`:null}
            ${n.unresolved?html`<div class="trace-unresolved">recorded parent unresolved</div>`:null}
            ${latBar}
          </div>
          <div class="slane"><div class=${'sbar f-'+kind+statusCls} style=${'left:'+n.start_pct+'%;width:'+Math.max(n.width_pct,1)+'%'}>${barTxt}</div>${sports}</div>
        </div>`;
      }

      function GapsLane(props){
        var markers=props.markers||[];
        return html`<div class="tl-gaps"><div class="tl-gsp"></div><div class="tl-glane">${markers.map(function(marker){
          return html`<div class="tl-gap" style=${'left:'+marker.start_pct+'%'}><span class="tl-gap-label"><b>${fmtTraceTime(marker.elapsed_s)}</b>${marker.gap_s>=300?html`<i class="tl-hiatus">${fmtTraceSpan(marker.gap_s)} gap</i>`:null}</span></div>`;
        })}</div></div>`;
      }

      // Process bands: for every kind === 'process' node, a hatched vertical band
      // over its causal subtree (contiguous following rows while depth > proc depth).
      // Positions are MEASURED from the live row elements (heights vary), so the
      // bands render in a second pass after layout.
      var BAND_OPEN_STATUSES={running:1,scheduled:1,suspended:1,pending:1};
      function computeBands(nodes, rowEls){
        var bands=[];
        (nodes||[]).forEach(function(n, idx){
          if(!(n.kind==='process' && !n.is_workflow)) return;
          var el=rowEls[n.uid]; if(!el) return;
          var last=el;
          for(var j=idx+1;j<nodes.length;j++){
            if((nodes[j].depth||0)<=(n.depth||0)) break;
            var childEl=rowEls[nodes[j].uid]; if(childEl) last=childEl;
          }
          var top=el.offsetTop;
          var height=last.offsetTop+last.offsetHeight-top;
          var depth=Math.min(n.depth||0,5);
          bands.push({
            uid:n.uid,
            top:top,
            left:8+depth*14,   // aligns with the d<depth> label indent, just past the owner spine
            height:height,
            accent:n.accent||'#646970',
            open:!!BAND_OPEN_STATUSES[String(n.status||'').toLowerCase()],
            title:shortName(n.name)+' · '+(n.status||'unknown'),
          });
        });
        return bands;
      }

      function TraceRows(props){
        var d=props.data, handlers=props.handlers||{};
        var nodes=(d&&d.nodes)||[];
        var rowRefs=useRef({});
        var bandsState=useState([]);
        var bands=bandsState[0], setBands=bandsState[1];
        rowRefs.current={};
        useLayoutEffect(function(){
          setBands(computeBands(nodes, rowRefs.current));
        },[d]);
        if(!d) return null;
        if(!nodes.length) return html`<div style="padding:24px;text-align:center;color:var(--faint);font-family:var(--fm)">No spans.</div>`;
        var prevUids=handlers.prevUids||{};
        var hasPrev=Object.keys(prevUids).length>0;
        var maxDur=d.max_dur_ms||1;
        var byUid={}; nodes.forEach(function(n){ byUid[n.uid]=n; });
        function openFromEvent(n, e){
          if(!handlers.onOpenNode) return;
          var t=e.target;
          var tab = t.closest('.mchip') ? 'inside' : (t.closest('.sport,.sports') ? 'story' : undefined);
          handlers.onOpenNode(n, tab);
        }
        var out=[];
        nodes.forEach(function(n, idx){
          var prev=idx>0?nodes[idx-1]:null;
          // kind = form, consumer = color: a handoff paints a seam between rows.
          if(prev && prev.consumer!==n.consumer && !n.unresolved && !prev.unresolved){
            out.push(html`<div class="trc-seam" style=${'--sa:'+(prev.accent||'#646970')+';--sb:'+(n.accent||'#646970')}></div>`);
          }
          out.push(html`<${TraceRow}
            key=${n.uid}
            node=${n}
            prev=${prev}
            maxDur=${maxDur}
            isNew=${hasPrev && !prevUids[n.uid]}
            rowRef=${function(el){ if(el) rowRefs.current[n.uid]=el; }}
            onClick=${function(e){ openFromEvent(n, e); }}
          />`);
        });
        out.push(html`<${GapsLane} markers=${d.time_markers}/>`);
        bands.forEach(function(b){
          out.push(html`<div
            class=${'proc-band '+(b.open?'is-open':'is-closed')}
            style=${'--band-accent:'+b.accent+';top:'+b.top+'px;left:'+b.left+'px;height:'+b.height+'px'}
            title=${b.title}
            onClick=${function(){ if(handlers.onOpenNode && byUid[b.uid]) handlers.onOpenNode(byUid[b.uid], undefined); }}
          ></div>`);
        });
        return out;
      }

      // ── drawer ──

      function Json(props){
        if(props.value==null) return html`<span class="idm">—</span>`;
        return html`<pre>${JSON.stringify(props.value,null,2)}</pre>`;
      }

      function BiographyLinks(props){
        var touches=props.touches||[];
        if(!touches.length) return null;
        var ctx=props.ctx||{};
        return html`<div class="trace-biography-links">${touches.map(function(t){
          return html`<button class="trace-biography-link" style=${'--owner-accent:'+(t.accent||props.fallbackAccent||'#646970')}
            onClick=${function(){ if(ctx.onShowBiography) ctx.onShowBiography(t.aggregate, t.aggregate_id, t.consumer||props.fallbackConsumer); }}>
            <span>${t.aggregate}</span><b>${t.aggregate_id}</b><i>v${t.version} · ${t.op}</i></button>`;
        })}</div>`;
      }

      function CorrLink(props){
        var ctx=props.ctx||{}, corr=ctx.correlation;
        return html`<span class="corr-link" onClick=${function(){ if(corr && ctx.onShowTrace) ctx.onShowTrace(corr); }}>${corr||'—'}</span>`;
      }

      function FlatRecord(props){
        // process / orphan fact: the flat record view.
        var n=props.node, ctx=props.ctx||{};
        var parent=n.parent_label
          ? html`${n.parent_label} <span class="idm">(${n.parent_consumer_label||n.parent_consumer||'unknown'})</span>`
          : '—';
        return html`<h3>${n.name}</h3>
          <div class="trace-owner" style=${'--owner-accent:'+(n.accent||'#646970')}><i></i><b>${n.consumer_label||n.consumer}</b><span>${n.consumer}</span></div>
          <dl class="kv">
            <dt>kind</dt><dd>${n.kind}</dd>
            <dt>local id</dt><dd>${n.id}</dd>
            <dt>status</dt><dd>${n.status||'—'}</dd>
            <dt>parent</dt><dd>${parent}</dd>
            <dt>correlation</dt><dd><${CorrLink} ctx=${ctx}/></dd>
          </dl>
          ${(n.touches||[]).length?html`<div class="jlbl">aggregate biography</div><${BiographyLinks} touches=${n.touches} fallbackAccent=${n.accent} fallbackConsumer=${n.consumer} ctx=${ctx}/>`:null}
          <div class="jlbl">recorded data</div><${Json} value=${n.raw}/>`;
      }

      var DRAWER_TABS=[['story','Story'],['inside','Inside the act'],['touches','Touches'],['payload','Payload']];

      function CommandRecord(props){
        // Command: the restocked pantry — sticky identity + tabbed sections.
        // Identity the row already shows never repeats here.
        var n=props.node, ctx=props.ctx||{};
        var tabState=useState(props.initialTab||'story');
        var tab=tabState[0], setTab=tabState[1];
        var raw=n.raw||{}, ports=n.ports||[], moments=n.moments||[];
        var touches=(n.touches||[]).slice();
        ports.forEach(function(p){ (p.touches||[]).forEach(function(t){ touches.push(t); }); });
        var dur=raw.duration_ms!=null?raw.duration_ms:0;
        var caused=n.parent_label
          ? html`${n.parent_label}${n.cross_consumer?html` <span class="idm">(${n.parent_consumer_label||n.parent_consumer} → ${n.consumer_label||n.consumer})</span>`:null}${n.gap_before?html` <span class="idm">· after ${fmtTraceSpan(n.gap_before)} wait</span>`:null}`
          : '—';
        var factRows=ports.length?ports.map(function(p){
          return html`<div class="dfact"><span class="sport ${portStatusCls(p.status)}" style=${'--owner-accent:'+(p.accent||n.accent||'#646970')}></span><b style=${p.status==='dlq'?'color:var(--crit)':''}>${p.name}</b><span class="idm">${p.status} · ${shortId(p.id)}</span></div>`;
        }):html`<div class="idm">no facts emitted</div>`;
        var reactionSum=0;
        var portNames={};
        ports.forEach(function(p){ portNames[p.name]=p; });
        var insideRows=moments.map(function(m){
          var rows=((m&&m.reactions)||[]).map(function(r){
            reactionSum+=r.duration_ms||0;
            // Width is MEASURED (share of the act); vertical order is record order.
            var w=dur>0?Math.max(Math.round((r.duration_ms||0)/dur*100),2):2;
            return html`<div class="drx"><span class="drn">${r.handler}${r.error?html` <b style="color:var(--crit)" title=${r.error}>!</b>`:null}</span><span class="drb"><i style=${'width:'+w+'%'}></i></span><span class="drms">${r.duration_ms||0}ms</span></div>`;
          });
          // ○ = interior moment. A moment sharing a port's name IS that fact
          // (self-publisher): merge the glyphs and show its outbox status.
          var pub=portNames[m.name];
          var head=pub
            ? html`<div class="dmoment">◊ ${m.name} <span class="idm">→ published fact · ${pub.status}</span></div>`
            : html`<div class="dmoment">○ ${m.name}</div>`;
          return html`${head}${rows}`;
        });
        return html`<h3>${n.name}</h3>
          <div class="trace-owner" style=${'--owner-accent:'+(n.accent||'#646970')}><i></i><b>${n.consumer_label||n.consumer}</b><span>${n.status||''} · ${dur}ms</span></div>
          <div class="dtabs">${DRAWER_TABS.map(function(t){
            return html`<button data-dt=${t[0]} aria-current=${t[0]===tab?'true':null} onClick=${function(){ setTab(t[0]); }}>${t[1]}</button>`;
          })}</div>
          <div class="dpane" data-dp="story" hidden=${tab!=='story'}>
            <dl class="kv">
              <dt>correlation</dt><dd><${CorrLink} ctx=${ctx}/></dd>
              <dt>caused by</dt><dd>${caused}</dd>
              <dt>source</dt><dd>${raw.source||'—'}${raw.source_id?'#'+raw.source_id:''}</dd>
              <dt>started</dt><dd>${raw.started_at||'—'}${raw.ended_at?' · ended '+raw.ended_at:''}</dd>
              <dt>memory</dt><dd>${raw.peak_memory_bytes?(Math.round(raw.peak_memory_bytes/1048576*10)/10+' MB peak'):'—'}</dd>
            </dl>
            <div class="jlbl">emitted facts</div>${factRows}
          </div>
          <div class="dpane" data-dp="inside" hidden=${tab!=='inside'}>
            ${moments.length
              ? html`${insideRows}${reactionSum>0&&dur>=reactionSum?html`<div class="drx dim"><span class="drn">unaccounted (handler body & framework)</span><span class="drb"></span><span class="drms">${dur-reactionSum}ms</span></div>`:null}`
              : html`<div class="idm">no domain moments recorded in this act</div>`}
          </div>
          <div class="dpane" data-dp="touches" hidden=${tab!=='touches'}>
            ${touches.length?html`<${BiographyLinks} touches=${touches} fallbackAccent=${n.accent} fallbackConsumer=${n.consumer} ctx=${ctx}/>`:html`<div class="idm">no touches recorded</div>`}
          </div>
          <div class="dpane" data-dp="payload" hidden=${tab!=='payload'}>
            ${raw.parameters?html`<div class="jlbl">parameters</div><${Json} value=${raw.parameters}/>`:null}
            ${raw.error?html`<div class="jlbl">error</div><${Json} value=${raw.error}/>`:null}
            <div class="jlbl">audit row</div><${Json} value=${raw}/>
          </div>`;
      }

      function DrawerBody(props){
        var n=props.node;
        if(n.kind!=='command') return html`<${FlatRecord} node=${n} ctx=${props.ctx}/>`;
        return html`<${CommandRecord} node=${n} ctx=${props.ctx} initialTab=${props.initialTab}/>`;
      }

      // ── vanilla-facing contract ──

      window.TDDDTrace = {
        // Renders the trace rows region (seams, act rows, gaps lane, process bands).
        // data=null renders nothing (loading); empty nodes render the empty state.
        // handlers: { onOpenNode(node, initialTab), prevUids } — prevUids marks
        // freshly-appeared rows for the live-poll flash.
        renderRows: function(container, data, handlers){
          if(!container._tdddIsland){ container.textContent=''; container._tdddIsland=true; }
          preact.render(html`<${TraceRows} data=${data} handlers=${handlers}/>`, container);
        },
        // Renders the drawer body. Tab switching is component state — no re-wiring.
        // ctx: { correlation, onShowTrace(corr), onShowBiography(aggregate,id,consumer) }
        openDrawer: function(dbodyEl, node, initialTab, ctx){
          // Vanilla views write dbody.innerHTML directly between island renders:
          // unmount cleanly, clear whatever they left, then render fresh.
          preact.render(null, dbodyEl);
          dbodyEl.textContent='';
          preact.render(html`<${DrawerBody} node=${node} initialTab=${initialTab} ctx=${ctx}/>`, dbodyEl);
        },
        // Lets vanilla code safely reuse the drawer element afterwards.
        unmountDrawer: function(dbodyEl){
          preact.render(null, dbodyEl);
          dbodyEl.textContent='';
        },
      };
    })();
