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
            ${n.pass?html`<div class="spass"><span class=${'pass-chip'+(n.pass.errors?' err':'')} title=${'workflow #'+n.pass.wf+' · pass '+n.pass.n+' of '+n.pass.of+' · '+n.pass.note+(n.pass.cut?' · stopped with work remaining':'')+(n.pass.errors?' · '+n.pass.errors+' failed':'')}>pass ${n.pass.n}/${n.pass.of} · ${n.pass.note}${n.pass.cut?' ⌁':''}${n.pass.errors?' ×'+n.pass.errors:''}</span></div>`:null}
            ${n.kind==='process'?html`<div class="spass"><span class="pass-chip" title=${'long process #'+n.id+' · '+(n.status||'')+(n.raw&&n.raw.step_name?' · step: '+n.raw.step_name:'')+(n.raw&&n.raw.waiting_for?' · awaits '+n.raw.waiting_for:'')}>trajectory #${n.id} · ${n.status||'?'}${n.raw&&n.raw.step_name?' · '+n.raw.step_name:''}${n.raw&&n.raw.waiting_for?' · awaits ⧗':''}</span></div>`:null}
            ${showFrom?html`<div class="sfrom">↳ from <b>${n.parent_label}</b>${handoff}</div>`:null}
            ${n.unresolved?html`<div class="trace-unresolved">recorded parent unresolved</div>`:null}
            ${latBar}
          </div>
          <div class="slane">${n.kind==='process'
            ? html`<span class="proc-tick" style=${'left:'+n.start_pct+'%'} title="ignited here">▸</span>`
            : html`<div class=${'sbar f-'+kind+statusCls} style=${'left:'+n.start_pct+'%;width:'+Math.max(n.width_pct,1)+'%'}>${barTxt}</div>`}${sports}</div>
        </div>`;
      }

      function GapsLane(props){
        var markers=props.markers||[];
        var laneRef=useRef(null);
        // Label thinning: with the lane wider than its min-width the px value
        // of the layout's 130-unit spacing floats — when two labels would land
        // within 64px, keep both dashed lines but drop the EARLIER label
        // (cumulative elapsed: the later one subsumes it).
        useLayoutEffect(function(){
          var lane=laneRef.current; if(!lane) return;
          var w=lane.offsetWidth; if(!w) return;
          var els=lane.querySelectorAll('.tl-gap');
          var kept=Infinity;
          // Walk right-to-left so the LATEST of any crowded cluster survives.
          for(var i=els.length-1;i>=0;i--){
            var px=parseFloat(els[i].style.left)/100*w;
            var label=els[i].querySelector('.tl-gap-label');
            if(!label) continue;
            if(kept-px>=64){ label.style.visibility=''; kept=px; }
            else { label.style.visibility='hidden'; }
          }
        },[markers]);
        return html`<div class="tl-gaps"><div class="tl-gsp"></div><div class="tl-glane" ref=${laneRef}>${markers.map(function(marker){
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
          // The trellis covers the trajectory's OWN STEPS — rows whose parent
          // is this process (the commands it sequenced) — NOT every downstream
          // consequence in the subtree. Subscribers to facts the steps raised
          // read via ports and from-lines; wrapping them overstated the span.
          // Each step gets an elbow off the rail (the espalier).
          var last=el, elbows=[];
          for(var j=idx+1;j<nodes.length;j++){
            if((nodes[j].depth||0)<=(n.depth||0)) break;
            if(nodes[j].parent!==n.uid) continue;
            var stepEl=rowEls[nodes[j].uid]; if(!stepEl) continue;
            last=stepEl;
            elbows.push(stepEl.offsetTop+15);   // ~the step's name line
          }
          // Two geometries: the GUTTER band insets (start at the process row's
          // dot, end at the last step's bar) so adjacent art never merges; the
          // temporal REGION in the lane keeps full row coverage.
          var fullTop=el.offsetTop;
          var fullHeight=last.offsetTop+last.offsetHeight-fullTop;
          var top=fullTop+12;
          var height=fullHeight-26;
          var depth=Math.min(n.depth||0,5);
          // Temporal region: the trajectory's spacetime in the LANE — from its
          // ignition x to its last step's activity end, over the same rows.
          // Percent coords are lane-relative; convert to px via a sample lane.
          var laneEl=el.querySelector('.slane');
          var region=null;
          if(laneEl){
            var laneLeft=laneEl.offsetLeft, laneW=laneEl.offsetWidth;
            var x1=n.start_pct||0, x2=x1+1.5;
            for(var k=idx+1;k<nodes.length;k++){
              if((nodes[k].depth||0)<=(n.depth||0)) break;
              if(nodes[k].parent!==n.uid) continue;
              x2=Math.max(x2,(nodes[k].start_pct||0)+Math.max(nodes[k].width_pct||0,1));
            }
            region={
              left:laneLeft+x1/100*laneW,
              width:Math.max((x2-x1)/100*laneW+14,26),
            };
          }
          bands.push({
            uid:n.uid,
            top:top,
            left:8+depth*14,   // aligns with the d<depth> label indent, just past the owner spine
            height:height,
            rtop:fullTop,
            rheight:fullHeight,
            elbows:elbows,
            region:region,
            accent:n.accent||'#646970',
            open:!!BAND_OPEN_STATUSES[String(n.status||'').toLowerCase()],
            title:shortName(n.name)+' · '+(n.status||'unknown'),
          });
        });
        return bands;
      }

      // Workflow rails: the trellis, in-row. A workflow's passes are chained
      // commands (no parent node to hang a subtree band from), so the rail
      // groups rows sharing pass.wf — one vertical band, one TICK per pass
      // (the rules land on pass boundaries; a stripe is never decoration).
      function computeWorkflowRails(nodes, rowEls){
        var byWf={};
        (nodes||[]).forEach(function(n){
          if(!n.pass) return;
          var el=rowEls[n.uid]; if(!el) return;
          var g=byWf[n.pass.wf]=byWf[n.pass.wf]||{rows:[],accent:n.accent||'#646970',depth:n.depth||0,of:n.pass.of,wf:n.pass.wf,cut:0,err:0};
          g.rows.push({top:el.offsetTop,height:el.offsetHeight,cut:!!n.pass.cut,err:!!n.pass.errors});
          g.depth=Math.min(g.depth,n.depth||0);
          if(n.pass.cut) g.cut++;
          if(n.pass.errors) g.err++;
        });
        var rails=[];
        Object.keys(byWf).forEach(function(k){
          var g=byWf[k];
          g.rows.sort(function(a,b){ return a.top-b.top; });
          // Inset: start at the first pass's command dot, end at the last
          // pass's duration bar — consecutive workflows' rails never touch.
          var top=g.rows[0].top+12;
          var bottom=g.rows[g.rows.length-1].top+g.rows[g.rows.length-1].height-14;
          rails.push({
            wf:g.wf, accent:g.accent,
            top:top, height:bottom-top,
            left:Math.max(2, 8+Math.min(g.depth,5)*14-6),
            ticks:g.rows.map(function(r){ return {top:r.top+15, cut:r.cut, err:r.err}; }),
            title:'workflow #'+g.wf+' · '+g.rows.length+' passes in trace'+(g.cut?' · '+g.cut+' budget cuts':'')+(g.err?' · '+g.err+' with failures':''),
          });
        });
        return rails;
      }

      // ── the collapsed trellis band: N pass rows become one row ──
      // Artifact §03b: the workflow's vertical-stripe identity promoted from
      // texture to data — the band spans first→last pass in lane coords, every
      // internal rule IS a pass boundary. ⌁ = budget cut, ✂ = causation break,
      // red = failures. Click a segment → that pass's drawer; chevron → rows.
      var COLLAPSE_THRESHOLD=5;
      function TrellisBand(props){
        var passes=props.passes, onExpand=props.onExpand, handlers=props.handlers||{};
        var first=passes[0], accent=first.accent||'#646970';
        var x0=Math.min.apply(null,passes.map(function(p){ return p.start_pct; }));
        var x1=Math.max.apply(null,passes.map(function(p){ return p.start_pct+Math.max(p.width_pct,0.6); }));
        var cuts=0, errs=0;
        passes.forEach(function(p){ if(p.pass&&p.pass.cut) cuts++; if(p.pass&&p.pass.errors) errs++; });
        var segs=passes.map(function(p,i){
          var sx=p.start_pct, ex=(i+1<passes.length)?passes[i+1].start_pct:x1;
          var broke=p.unresolved||(!p.parent&&p.pass&&p.pass.n>1);
          return html`<span
            class=${'tband-seg'+(p.pass&&p.pass.errors?' err':'')+(broke?' broke':'')+(String(p.status||'')==='in_progress'?' live':'')}
            style=${'left:'+((sx-x0)/(x1-x0)*100)+'%;width:'+Math.max((ex-sx)/(x1-x0)*100,1.5)+'%'}
            title=${'pass '+(p.pass?p.pass.n:'?')+(p.pass?' · '+p.pass.note:'')+(p.pass&&p.pass.cut?' ⌁':'')+(p.pass&&p.pass.errors?' · '+p.pass.errors+' failed':'')+(broke?' · ✂ arrived without cause':'')}
            onClick=${function(ev){ ev.stopPropagation(); if(handlers.onOpenNode) handlers.onOpenNode(p,'workflow'); }}>
            ${p.pass&&p.pass.cut?html`<i class="tband-cut">⌁</i>`:null}${broke?html`<i class="tband-scissor">✂</i>`:null}
          </span>`;
        });
        var wfId=first.pass?first.pass.wf:'?';
        return html`<div class="srow is-node d${Math.min(first.depth||0,5)}" style=${'--owner-accent:'+accent}>
          <div class="slabel" style=${'--owner-accent:'+accent}>
            <div class="snrow">
              <button class="tband-chev" title="expand into pass rows" onClick=${onExpand}>▸</button>
              <span class="sdot" style=${'background:'+accent}></span>
              <span class="sname" title=${first.name}>${shortName(first.name)}</span>
              <span class="stype">workflow</span>
            </div>
            <div class="sfrom">wf #${wfId} · ${passes.length} passes collapsed${cuts?' · '+cuts+' ⌁':''}${errs?html` · <b style="color:var(--crit)">${errs} with failures</b>`:null}</div>
          </div>
          <div class="slane"><div class="tband" style=${'left:'+x0+'%;width:'+Math.max(x1-x0,2)+'%'}>${segs}</div></div>
        </div>`;
      }

      function TraceRows(props){
        var d=props.data, handlers=props.handlers||{};
        var allNodes=(d&&d.nodes)||[];
        var expandedState=useState({});
        var expandedWfs=expandedState[0], setExpandedWfs=expandedState[1];
        // Group pass rows per workflow; big cascades collapse by default.
        var passesByWf={};
        allNodes.forEach(function(n){ if(n.pass) (passesByWf[n.pass.wf]=passesByWf[n.pass.wf]||[]).push(n); });
        var collapsed={};
        Object.keys(passesByWf).forEach(function(wf){
          if(passesByWf[wf].length>COLLAPSE_THRESHOLD && !expandedWfs[wf]) collapsed[wf]=true;
        });
        var nodes=[], bandAt={};
        allNodes.forEach(function(n){
          if(n.pass && collapsed[n.pass.wf]){
            if(!bandAt[n.pass.wf]){ bandAt[n.pass.wf]=true; nodes.push({__band:n.pass.wf}); }
            return;
          }
          nodes.push(n);
        });
        var rowRefs=useRef({});
        var bandsState=useState([]);
        var bands=bandsState[0], setBands=bandsState[1];
        var railsState=useState([]);
        var rails=railsState[0], setRails=railsState[1];
        rowRefs.current={};
        useLayoutEffect(function(){
          var real=nodes.filter(function(n){ return !n.__band; });
          setBands(computeBands(real, rowRefs.current));
          setRails(computeWorkflowRails(real, rowRefs.current));
        },[d, expandedWfs]);
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
          if(n.__band){
            var wfKey=n.__band;
            out.push(html`<${TrellisBand}
              key=${'band-'+wfKey}
              passes=${passesByWf[wfKey]}
              handlers=${handlers}
              onExpand=${function(ev){ ev.stopPropagation(); var next={}; Object.keys(expandedWfs).forEach(function(k){ next[k]=expandedWfs[k]; }); next[wfKey]=true; setExpandedWfs(next); }}
            />`);
            return;
          }
          var prev=idx>0&&!nodes[idx-1].__band?nodes[idx-1]:null;
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
        // Gutter-dwelling art (bands, elbows, rails, ticks) lives in a
        // zero-size sticky layer so it pins with the label column instead of
        // sliding away on horizontal scroll. Temporal regions stay in the
        // scrolling content — they mark real x-positions in the lane.
        var gutter=[];
        bands.forEach(function(b){
          gutter.push(html`<div
            class=${'proc-band '+(b.open?'is-open':'is-closed')}
            style=${'--band-accent:'+b.accent+';top:'+b.top+'px;left:'+b.left+'px;height:'+b.height+'px'}
            title=${b.title}
            onClick=${function(){ if(handlers.onOpenNode && byUid[b.uid]) handlers.onOpenNode(byUid[b.uid], undefined); }}
          ></div>`);
          (b.elbows||[]).forEach(function(top){
            gutter.push(html`<div class="proc-elbow" style=${'--band-accent:'+b.accent+';top:'+top+'px;left:'+(b.left+5)+'px'}></div>`);
          });
          if(b.region){
            out.push(html`<div
              class=${'proc-region '+(b.open?'is-open':'is-closed')}
              style=${'--band-accent:'+b.accent+';top:'+b.rtop+'px;left:'+b.region.left+'px;width:'+b.region.width+'px;height:'+b.rheight+'px'}
              title=${b.title}
            ></div>`);
          }
        });
        rails.forEach(function(r){
          var canCollapse=(passesByWf[r.wf]||[]).length>COLLAPSE_THRESHOLD;
          gutter.push(html`<div class="wf-rail" style=${'--band-accent:'+r.accent+';top:'+r.top+'px;left:'+r.left+'px;height:'+r.height+'px'+(canCollapse?';cursor:pointer':'')}
            title=${r.title+(canCollapse?' · click to collapse':'')}
            onClick=${canCollapse?function(){ var next={}; Object.keys(expandedWfs).forEach(function(k){ next[k]=expandedWfs[k]; }); next[r.wf]=false; setExpandedWfs(next); }:null}></div>`);
          r.ticks.forEach(function(t){
            gutter.push(html`<div class=${'wf-rail-tick'+(t.err?' err':'')} style=${'--band-accent:'+r.accent+';top:'+t.top+'px;left:'+r.left+'px'}>${t.cut?html`<i>⌁</i>`:null}</div>`);
          });
        });
        out.unshift(html`<div class="trace-gutter">${gutter}</div>`);
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
        // A workflow pass gains the Workflow tab: the full loom + pass ledger,
        // rendered shell-side (ctx.loomHtml) — the loom renderer lives there.
        var loomHtml=(n.pass && ctx.loomHtml)?ctx.loomHtml(n.pass.wf):'';
        var tabs=loomHtml?DRAWER_TABS.concat([['workflow','Workflow']]):DRAWER_TABS;
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
          <div class="dtabs">${tabs.map(function(t){
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
          </div>
          ${loomHtml?html`<div class="dpane" data-dp="workflow" hidden=${tab!=='workflow'}>
            ${n.pass?html`<div class="jlbl">this pass</div><div class="idm" style="margin-bottom:10px">pass ${n.pass.n} of ${n.pass.of} · ${n.pass.note}${n.pass.cut?' · stopped with work remaining ⌁':''}${n.pass.errors?html` · <b style="color:var(--crit)">${n.pass.errors} failed</b>`:null}</div>`:null}
            <div dangerouslySetInnerHTML=${{__html:loomHtml}}></div>
          </div>`:null}`;
      }

      function DrawerBody(props){
        var n=props.node;
        if(n.kind!=='command') return html`<${FlatRecord} node=${n} ctx=${props.ctx}/>`;
        return html`<${CommandRecord} node=${n} ctx=${props.ctx} initialTab=${props.initialTab}/>`;
      }

      // ── vanilla-facing contract ──

      // ── The Vine: causation-topology projection of the SAME trace payload ──
      // (artifact 44adfca9). Rank = causal depth, spine = heaviest subtree,
      // siblings ordered by first-timestamp (append-mostly layout under live
      // polling). Workflow pass chains collapse into capsules with a self-loop;
      // processes render as hatched capsules; ✂ marks arrivals without cause.
      function buildVine(d){
        var nodes=(d&&d.nodes)||[];
        // 1. Collapse workflow passes into capsule nodes.
        var byWf={}, vnodes=[], vByUid={}, uidToCapsule={};
        nodes.forEach(function(n){ if(n.pass) (byWf[n.pass.wf]=byWf[n.pass.wf]||[]).push(n); });
        var emittedCapsule={};
        nodes.forEach(function(n){
          if(n.pass){
            var wf=n.pass.wf;
            uidToCapsule[n.uid]='cap-'+wf;
            if(emittedCapsule[wf]) return;
            emittedCapsule[wf]=true;
            var passes=byWf[wf];
            var errs=0,cuts=0,broke=false;
            passes.forEach(function(p){ if(p.pass.errors)errs++; if(p.pass.cut)cuts++; if(p.unresolved||(!p.parent&&p.pass.n>1))broke=true; });
            var cap={uid:'cap-'+wf, vkind:'capsule', name:shortName(n.name), consumer:n.consumer, accent:n.accent,
              meta:'workflow #'+wf+' · ×'+passes.length+' passes'+(cuts?' · '+cuts+' ⌁':'')+(errs?' · '+errs+' failed':''),
              err:errs>0, broke:broke, parent:passes[0].parent, ts:passes[0].elapsed_s||0, first:passes[0]};
            vnodes.push(cap); vByUid[cap.uid]=cap;
            return;
          }
          var v={uid:n.uid, vkind:(n.kind==='process'?'process':(n.kind==='event'?'fact':'act')),
            name:shortName(n.name), consumer:n.consumer, accent:n.accent, meta:(n.status||''),
            err:n.status==='error'||n.status==='dlq', broke:!!n.unresolved, parent:n.parent, ts:n.elapsed_s||0, node:n};
          vnodes.push(v); vByUid[v.uid]=v;
        });
        // Re-parent through collapsed passes; drop self-parenting inside a capsule.
        vnodes.forEach(function(v){
          if(v.parent && uidToCapsule[v.parent]) v.parent=uidToCapsule[v.parent];
          if(v.parent===v.uid) v.parent=null;
        });
        // Consumed facts live in the payload as PORTS docked on their raising
        // act, not as nodes — so a process ignited by one (or a command caused
        // by one) pointed at a missing parent and floated as its own tree.
        // PROMOTE such facts to chain nodes: act → ◆fact → ignited process.
        var portIndex={};
        nodes.forEach(function(n){
          (n.ports||[]).forEach(function(p){
            portIndex[p.uid]={port:p, act:uidToCapsule[n.uid]||n.uid};
          });
        });
        vnodes.slice().forEach(function(v){
          if(!v.parent||vByUid[v.parent]||!portIndex[v.parent]) return;
          var pi=portIndex[v.parent];
          if(!vByUid[v.parent]){
            var f={uid:pi.port.uid, vkind:'fact', name:shortName(pi.port.name),
              consumer:pi.port.consumer, accent:pi.port.accent||'#646970',
              meta:(pi.port.status||''), err:pi.port.status==='dlq'||pi.port.status==='failed',
              broke:false, parent:pi.act, ts:v.ts};
            vnodes.push(f); vByUid[f.uid]=f;
          }
        });
        // 2. Ranks (longest path from root) + children map.
        var kids={};
        vnodes.forEach(function(v){ if(v.parent&&vByUid[v.parent]) (kids[v.parent]=kids[v.parent]||[]).push(v); });
        function rank(v){ if(v.__r!=null) return v.__r; v.__r=0; if(v.parent&&vByUid[v.parent]) v.__r=rank(vByUid[v.parent])+1; return v.__r; }
        vnodes.forEach(rank);
        // 3. Subtree weight for spine election; sibling order = weight desc (spine first), then ts.
        function weight(v){ if(v.__w!=null) return v.__w; v.__w=1; (kids[v.uid]||[]).forEach(function(c){ v.__w+=weight(c); }); return v.__w; }
        vnodes.forEach(weight);
        Object.keys(kids).forEach(function(k){ kids[k].sort(function(a,b){ return (b.__w-a.__w)||(a.ts-b.ts); }); });
        // 4. Lanes: tidy-tree (Reingold–Tilford style) — leaves take
        // consecutive lanes, every parent CENTERS on its children's extent,
        // and each node's branches are ordered to FLANK the spine child
        // (heaviest first, alternating above/below), so the main descent
        // runs through the middle with limbs shooting both ways.
        var nextLane=0;
        function layout(v){
          if(v.__c!=null||v.__visiting) return;
          v.__visiting=true;
          var ks=kids[v.uid]||[];
          if(!ks.length){ v.__c=nextLane++; v.__visiting=false; return; }
          var spineChild=ks[0], above=[], below=[];
          for(var i=1;i<ks.length;i++){ (i%2?above:below).push(ks[i]); }
          var ordered=above.reverse().concat([spineChild]).concat(below);
          ordered.forEach(layout);
          var lanes=ordered.map(function(c){ return c.__c; }).filter(function(l){ return l!=null; });
          v.__c=lanes.length?(lanes[0]+lanes[lanes.length-1])/2:nextLane++;
          v.__visiting=false;
        }
        var roots=vnodes.filter(function(v){ return !v.parent||!vByUid[v.parent]; });
        roots.sort(function(a,b){ return (b.__w-a.__w)||(a.ts-b.ts); });
        roots.forEach(layout);
        // Cycle survivors: nodes unreachable from any root (the stitcher
        // preserves recorded cycles as evidence). Park them in fresh lanes
        // rather than letting them collapse onto NaN coordinates.
        vnodes.forEach(function(v){ if(v.__c==null){ v.__cyc=true; layout(v); if(v.__c==null) v.__c=nextLane++; } });
        return {nodes:vnodes, byUid:vByUid, cols:nextLane};
      }

      function VineView(props){
        // HORIZONTAL flow (owner 2026-07-25): causal depth runs left→right —
        // after capsule collapse a trace is deeper than it is branchy, so
        // depth belongs on the wide axis. Siblings stack vertically.
        var d=props.data, handlers=props.handlers||{};
        if(!d||!(d.nodes||[]).length) return html`<div style="padding:24px;text-align:center;color:var(--faint);font-family:var(--fm)">No spans.</div>`;
        var g=buildVine(d);
        var RANKW=210, LANEH=76, X0=36, Y0=30, NW=18;
        var maxRank=0; g.nodes.forEach(function(v){ maxRank=Math.max(maxRank,v.__r); });
        var W=X0+(maxRank+1)*RANKW+120, H=Y0+g.cols*LANEH+50;
        function nx(v){ return X0+v.__r*RANKW; }
        function ny(v){ return Y0+v.__c*LANEH; }
        function glyphW(v){ return v.vkind==='capsule'||v.vkind==='process'?NW+30:NW; }
        // A process SEQUENCES its children (they are its steps, not a causal
        // fan-out) — its out-pipes speak step language: orthogonal elbows off
        // the rail with 'step N' ordinals, instead of organic cause-curves.
        var stepOrdinal={};
        g.nodes.forEach(function(v){
          if(!v.parent||!g.byUid[v.parent]) return;
          if(g.byUid[v.parent].vkind==='process'){
            var sibs=(function(){ var s=[]; g.nodes.forEach(function(o){ if(o.parent===v.parent) s.push(o); }); s.sort(function(a,b){ return a.ts-b.ts; }); return s; })();
            stepOrdinal[v.uid]=sibs.indexOf(v)+1;
          }
        });
        var pipes=[], marks=[];
        g.nodes.forEach(function(v){
          if(!v.parent||!g.byUid[v.parent]) return;
          var p=g.byUid[v.parent];
          var x1=nx(p)+glyphW(p), y1=ny(p)+NW/2, x2=nx(v)-3, y2=ny(v)+NW/2;
          var spine=p.__c===v.__c;
          var isStep=!!stepOrdinal[v.uid];
          var path;
          if(isStep&&y1!==y2){
            // Elbow: out of the rail, drop/rise, run to the step.
            var mx=x1+18;
            path='M'+x1+','+y1+' L'+mx+','+y1+' L'+mx+','+y2+' L'+x2+','+y2;
          } else {
            path=y1===y2
              ? 'M'+x1+','+y1+' L'+x2+','+y2
              : 'M'+x1+','+y1+' C'+(x1+(x2-x1)*0.55)+','+y1+' '+(x1+(x2-x1)*0.45)+','+y2+' '+x2+','+y2;
          }
          pipes.push(html`<path class=${'vine-pipe'+(v.broke?' broke':'')+(spine?' spine':'')+(isStep?' step':'')} d=${path} style=${'stroke:'+(v.accent||'#646970')}/>`);
          var wait=Math.max(0,(v.ts||0)-(p.ts||0));
          var lbl=(isStep?'step '+stepOrdinal[v.uid]:'')+((wait>=2)?((isStep?' · ':'')+fmtTraceSpan(wait)):'');
          if(lbl) marks.push(html`<text class="vine-elabel" x=${(x1+x2)/2} y=${(y1+y2)/2-5} text-anchor="middle">${lbl}</text>`);
        });
        var shapes=g.nodes.map(function(v){
          var x=nx(v), y=ny(v), a=v.accent||'#646970';
          var glyph;
          if(v.vkind==='fact'){
            glyph=html`<rect x=${x+2} y=${y+2} width=${NW-4} height=${NW-4} transform=${'rotate(45 '+(x+NW/2)+' '+(y+NW/2)+')'} class="vine-fact" style=${'stroke:'+a}/>`;
          } else if(v.vkind==='capsule'){
            // Workflow capsule: the 90° stripe identity + the continuation self-loop.
            glyph=html`<g>
              <rect x=${x} y=${y} width=${NW+30} height=${NW} rx="9" style=${'fill:'+a+';opacity:.14'}/>
              <rect x=${x} y=${y} width=${NW+30} height=${NW} rx="9" fill="url(#vine-vstripe)" style=${'stroke:'+a+';stroke-width:2'}/>
              <path class="vine-loop" d=${'M'+(x+NW+30)+','+(y+3)+' c 16,-14 -14,-16 -12,-2'} style=${'stroke:'+a}/>
            </g>`;
          } else if(v.vkind==='process'){
            glyph=html`<g>
              <rect x=${x} y=${y} width=${NW+30} height=${NW} rx="9" style=${'fill:'+a+';opacity:.08'}/>
              <rect x=${x} y=${y} width=${NW+30} height=${NW} rx="9" fill="url(#vine-hatch)" style=${'stroke:'+a+';stroke-width:2'}/>
            </g>`;
          } else {
            // Act square + its emitted facts docked as diamonds (≤3, then ×n) —
            // the artifact's fact vocabulary, back in the picture.
            var ports=(v.node&&v.node.ports)||[];
            var shown=ports.slice(0,3);
            glyph=html`<g>
              <rect x=${x} y=${y} width=${NW} height=${NW} rx="4" style=${'fill:'+a+(v.err?';stroke:var(--crit);stroke-width:2.5':'')}/>
              ${shown.map(function(p,pi){
                var px=x+NW+6+pi*13, bad=p.status==='dlq'||p.status==='failed';
                return html`<rect x=${px} y=${y+5} width="8" height="8" transform=${'rotate(45 '+(px+4)+' '+(y+9)+')'} class="vine-fact" style=${'stroke:'+(bad?'var(--crit)':(p.accent||a))+(bad?';fill:var(--critbg)':'')}><title>${p.name+' · '+p.status}</title></rect>`;
              })}
              ${ports.length>3?html`<text class="vine-meta" x=${x+NW+6+3*13} y=${y+13}>◆×${ports.length}</text>`:null}
            </g>`;
          }
          return html`<g class="vine-node" onClick=${function(){ if(handlers.onOpenNode){ handlers.onOpenNode(v.node||v.first, v.vkind==='capsule'?'workflow':undefined); } }}>
            ${glyph}
            ${v.broke?html`<text class="vine-scissor" x=${x-4} y=${y-4}>✂</text>`:null}
            <text class="vine-label" x=${x} y=${y+NW+13}>${v.name}</text>
            <text class="vine-meta" x=${x} y=${y+NW+24}>${v.meta||''}</text>
          </g>`;
        });
        return html`<div class="vine-wrap"><svg width=${W} height=${H} viewBox=${'0 0 '+W+' '+H}>
          <defs>
            <pattern id="vine-vstripe" width="5" height="8" patternUnits="userSpaceOnUse"><line x1="1" y1="0" x2="1" y2="8" stroke="#8a8578" stroke-width="2" opacity=".5"/></pattern>
            <pattern id="vine-hatch" width="7" height="7" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="7" stroke="#8a8578" stroke-width="2" opacity=".35"/></pattern>
          </defs>
          ${pipes}${marks}${shapes}</svg></div>`;
      }

      window.TDDDTrace = {
        // The Vine: same payload, topology projection. Toggle is pure presentation.
        renderVine: function(container, data, handlers){
          if(!container._tdddIsland){ container.textContent=''; container._tdddIsland=true; }
          preact.render(html`<${VineView} data=${data} handlers=${handlers}/>`, container);
        },
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
