    (function(){
      var R = window.TDDD;
      var TRACE_WARM_S   = 25;   // seconds — a trace is LIVE if last_at is within this window
      var TRACE_LINGER_S = 120;  // seconds — finished traces linger this long before dropping
      var SPAN_STEPS = ['1h','3h','6h','12h','1d','2d','3d','5d','7d','10d','14d','21d','30d'];
      var BACK_STEPS = ['now','1h','6h','1d','3d','1w','2w','1mo','3mo'];
      var keys = Object.keys(R.consumers);
      var requested = new URLSearchParams(window.location.search);
      var requestedConsumer = requested.get('consumer');
      var requestedCorrelation = requested.get('correlation');
      // Persist the selected consumer across reloads (it used to reset to keys[0]).
      var savedConsumer = (function(){ try { return localStorage.getItem('tddd_consumer'); } catch(e){ return null; } })();
      var initialConsumer = (requestedConsumer && keys.indexOf(requestedConsumer) !== -1)
        ? requestedConsumer
        : ((savedConsumer && keys.indexOf(savedConsumer) !== -1) ? savedConsumer : (keys[0] || 'datastream'));
      var state = { consumer: initialConsumer, status:'', source:'', search:'', from:'', to:'', orderby:'started_at', order:'desc', page:1 };
      var tablesState = { status:'', from:'', to:'' };
      var $ = function(s){ return document.querySelector(s); };
      var rowsEl=$('#tddd-rows'), countEl=$('#tddd-count'), pagerEl=$('#tddd-pager'), pagerBotEl=$('#tddd-pager-bot');
      var lastRows = [];

      var cz = $('#tddd-consumers');
      keys.forEach(function(k){
        var b=document.createElement('button');
        b.textContent=R.consumers[k];
        b.setAttribute('aria-selected', k===state.consumer);
        b.addEventListener('click', function(){ state.consumer=k; state.page=1; try{ localStorage.setItem('tddd_consumer', k); }catch(e){} syncConsumers(); refreshActive(); });
        cz.appendChild(b);
      });
      function syncConsumers(){ [].forEach.call(cz.children, function(b,i){ b.setAttribute('aria-selected', keys[i]===state.consumer); }); }

      function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
      function shortId(s){ return s ? esc(s.slice(0,8))+'&hellip;'+esc(s.slice(-4)) : '&mdash;'; }
      function shortCorr(s){ return s ? esc(s.slice(0,8)) : '&mdash;'; }
      function meter(ms){ var w=Math.min(100,Math.round(ms/8)); var cls=ms>800?'bad':(ms>300?'hot':''); return '<span class="meter"><span class="bar"><i class="'+cls+'" style="width:'+w+'%"></i></span><span class="mv">'+(ms||0)+'ms</span></span>'; }
      function rel(ts){ if(!ts) return '&mdash;'; var d=new Date(ts.replace(' ','T')+'Z'), s=(Date.now()-d.getTime())/1000; if(s<60)return Math.max(0,Math.floor(s))+'s'; if(s<3600)return Math.floor(s/60)+'m'; if(s<86400)return Math.floor(s/3600)+'h'; return Math.floor(s/86400)+'d'; }
      function shortName(s){ if(!s) return '&mdash;'; var parts=String(s).split('\\'); return esc(parts[parts.length-1]); }

      function load(){
        rowsEl.innerHTML='<tr><td colspan="7" class="empty">Loading&hellip;</td></tr>';
        var p={consumer:state.consumer,status:state.status,source:state.source,search:state.search,orderby:state.orderby,order:'desc',page:state.page,per_page:25};
        if(state.from) p.from=state.from;
        if(state.to)   p.to=state.to;
        var qs=new URLSearchParams(p);
        fetch(R.rest+'/audit?'+qs.toString(),{headers:{'X-WP-Nonce':R.nonce}})
          .then(function(r){ return r.json(); })
          .then(render)
          .catch(function(e){ rowsEl.innerHTML='<tr><td colspan="7" class="empty">Error: '+esc(e.message)+'</td></tr>'; });
      }

      function render(d){
        lastRows = d.rows || [];
        if(!lastRows.length){ rowsEl.innerHTML='<tr><td colspan="7" class="empty">No commands match.</td></tr>'; }
        else {
          rowsEl.innerHTML = lastRows.map(function(r,i){
            return '<tr class="r-'+esc(r.status)+'" data-i="'+i+'">'
              +'<td><div class="cn" title="'+esc(r.command_name)+'">'+shortName(r.command_name)+'</div></td>'
              +'<td class="idm">'+shortId(r.command_id)+'</td>'
              +'<td class="dotm"><span class="corrcell" data-corr="'+esc(r.correlation_id||'')+'" title="open trace">'+shortCorr(r.correlation_id)+'</span></td>'
              +'<td><span class="src">'+esc(r.source)+(r.source_id?('#'+esc(r.source_id)):'')+'</span></td>'
              +'<td><span class="badge b-'+esc(r.status)+'">'+esc(r.status)+'</span></td>'
              +'<td>'+meter(r.duration_ms)+'</td>'
              +'<td class="idm">'+rel(r.started_at)+'</td></tr>';
          }).join('');
        }
        countEl.innerHTML = d.total+' commands';
        var fromN=d.total?((d.page-1)*d.per_page+1):0, toN=Math.min(d.page*d.per_page,d.total);
        var rangeEl2=$('#tddd-range'); if(rangeEl2) rangeEl2.innerHTML=fromN+'&ndash;'+toN+' of '+d.total;
        renderPager(d.page, d.pages);
      }

      function renderPager(page, pages){
        var html='';
        if(pages>1){
          html='<button '+(page<=1?'disabled':'')+' data-p="'+(page-1)+'">&lsaquo;</button>';
          var win=[]; for(var p=Math.max(1,page-1); p<=Math.min(pages,page+1); p++) win.push(p);
          if(win[0]>1){ html+='<button data-p="1">1</button>'; if(win[0]>2) html+='<button disabled>&hellip;</button>'; }
          win.forEach(function(p){ html+='<button '+(p===page?'aria-current="true"':'')+' data-p="'+p+'">'+p+'</button>'; });
          if(win[win.length-1]<pages){ if(win[win.length-1]<pages-1) html+='<button disabled>&hellip;</button>'; html+='<button data-p="'+pages+'">'+pages+'</button>'; }
          html+='<button '+(page>=pages?'disabled':'')+' data-p="'+(page+1)+'">&rsaquo;</button>';
        }
        pagerEl.innerHTML=html; pagerBotEl.innerHTML=html;
      }

      function auditPagerClick(e){ var b=e.target.closest('button[data-p]'); if(!b||b.disabled)return; state.page=+b.dataset.p; load(); }
      pagerEl.addEventListener('click', auditPagerClick);
      pagerBotEl.addEventListener('click', auditPagerClick);

      // Status / source selects
      var selStatus=$('#tddd-sel-status'), selSource=$('#tddd-sel-source');
      selStatus.addEventListener('change', function(){ state.status=selStatus.value; state.page=1; load(); });
      selSource.addEventListener('change', function(){ state.source=selSource.value; state.page=1; load(); });
      // Date range inputs
      var dateFrom=$('#tddd-from'), dateTo=$('#tddd-to');
      dateFrom.addEventListener('change', function(){ state.from=dateFrom.value; state.page=1; load(); });
      dateTo.addEventListener('change', function(){ state.to=dateTo.value; state.page=1; load(); });

      // Sort — date column is locked DESC; other columns retain asc/desc toggle
      document.querySelectorAll('th[data-sort]').forEach(function(th){ th.addEventListener('click', function(){
        var col=th.dataset.sort;
        if(state.orderby===col){ state.order=state.order==='desc'?'asc':'desc'; } else { state.orderby=col; state.order='desc'; }
        document.querySelectorAll('th[data-sort]').forEach(function(x){ x.classList.remove('sorted-desc','sorted-asc'); });
        th.classList.add(state.order==='desc'?'sorted-desc':'sorted-asc');
        state.page=1; load();
      }); });
      var st=$('#tddd-search'), t;
      st.addEventListener('input', function(){ clearTimeout(t); t=setTimeout(function(){ state.search=st.value.trim(); state.page=1; load(); }, 280); });

      var drawer=$('#tddd-drawer'), dbody=$('#tddd-drawer-body'), drawerLabel=$('#tddd-drawer-label');
      rowsEl.addEventListener('click', function(e){
        var cc=e.target.closest('.corrcell'); if(cc){ e.stopPropagation(); showTrace(cc.dataset.corr); return; }
        var tr=e.target.closest('tr[data-i]'); if(!tr)return; openDrawer(lastRows[+tr.dataset.i]);
      });
      drawer.addEventListener('click', function(e){ if(e.target.hasAttribute('data-close')) closeDrawer(); });
      document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeDrawer(); });
      function j(o){ return o==null ? '<span class="idm">&mdash;</span>' : '<pre>'+esc(JSON.stringify(o,null,2))+'</pre>'; }
      function setDrawerLabel(label){ drawerLabel.textContent=label; }
      function openDrawer(r){
        setDrawerLabel('command');
        dbody.innerHTML='<h3>'+esc(r.command_name)+'</h3>'
          +'<dl class="kv">'
          +'<dt>command_id</dt><dd>'+esc(r.command_id)+'</dd>'
          +'<dt>correlation</dt><dd><span class="corr-link" data-corr="'+esc(r.correlation_id||'')+'">'+esc(r.correlation_id||'—')+'</span></dd>'
          +'<dt>status</dt><dd>'+esc(r.status)+'</dd>'
          +'<dt>source</dt><dd>'+esc(r.source)+(r.source_id?('#'+esc(r.source_id)):'')+'</dd>'
          +'<dt>causation</dt><dd>'+esc(r.causation_id||'—')+(r.causation_type?(' · '+esc(r.causation_type)):'')+'</dd>'
          +'<dt>duration</dt><dd>'+(r.duration_ms||0)+'ms</dd>'
          +'<dt>peak mem</dt><dd>'+Math.round((r.peak_memory_bytes||0)/1048576)+' MB</dd>'
          +'<dt>started</dt><dd>'+esc(r.started_at||'—')+'</dd>'
          +'</dl>'
          +'<div class="jlbl">parameters</div>'+j(r.parameters)
          +'<div class="jlbl">events</div>'+j(r.events)
          +(r.error ? '<div class="jlbl">error</div><pre class="err">'+esc(JSON.stringify(r.error,null,2))+'</pre>' : '');
        drawer.hidden=false;
        var cl=dbody.querySelector('.corr-link');
        if(cl) cl.addEventListener('click', function(){ showTrace(this.dataset.corr); });
      }
      function closeDrawer(){ drawer.hidden=true; }

      // ── views / nav ──
      var tablesEl=$('#tddd-view-tables');
      var views={ flow:$('#tddd-view-flow'), audit:$('#tddd-view-audit'), trace:$('#tddd-view-trace'), biography:$('#tddd-view-biography'), proc:$('#tddd-view-proc'), dlq:tablesEl, outbox:tablesEl };
      var navBtns=document.querySelectorAll('#tddd-nav button');
      var traceRows=$('#tddd-trace-rows'), traceHead=$('#tddd-trace-head'), ruler=$('#tddd-ruler'), traceWf=$('#tddd-trace-workflows');
      var traceRecent=$('#tddd-trace-recent'), traceOpen=$('#tddd-trace-open');
      var trcList=$('#tddd-trc-list'), trcNewbar=$('#tddd-trc-newbar');
      var typeColor={command:'#6359D6',workflow:'#7C4DE0',event:'#2E7D8A',process:'#507F06'};
      var auditLoaded=false, currentCorr=null, liveStarted=false, liveCursor=0, heartbeatSpeed=60;
      var biographyState={search:'',page:1,per_page:25}, biographyRows=[], currentBiography=null;
      // recent-traces state
      var _recentCorrs=[], _pendingNewCorrs=[], _prevTraceNodes={}, _recentBuckets={};
      function fmtDur(ms){ ms=Math.round(ms); if(ms<1000)return ms+'ms'; return (ms/1000).toFixed(1)+'s'; }
      function fmtTraceSpan(s){
        s=Math.max(0,Math.floor(Number(s)||0));
        if(s===0) return '0s';
        var units=[['d',86400],['h',3600],['m',60],['s',1]], parts=[];
        units.forEach(function(u){ var n=Math.floor(s/u[1]); if(n>0&&parts.length<2){ parts.push(n+u[0]); s-=n*u[1]; } });
        return parts.join(' ');
      }
      function fmtTraceTime(s){ return '+'+fmtTraceSpan(s); }
      function fmtBytes(b){ if(b<1024)return b+' B'; if(b<1048576)return (b/1024).toFixed(0)+' KB'; return (b/1048576).toFixed(1)+' MB'; }

      // ── trace recent-list helpers ──
      function parseLastAt(ts){ if(!ts) return 0; return new Date(ts.replace(' ','T')+'Z').getTime(); }

      // ── temporal scrubber helpers ──
      function pad2(n){ return n<10?'0'+n:String(n); }
      function labelToMs(s){
        var m={h:3600000,d:86400000,w:604800000,mo:2592000000};
        var match=s.match(/^(\d+)(h|d|w|mo)$/);
        return match?parseInt(match[1])*m[match[2]]:86400000;
      }
      function backLabelToMs(s){ if(s==='now') return 0; return labelToMs(s); }
      function msToDateStr(ms){
        var d=new Date(ms);
        return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate());
      }
      function msToAbsLabel(ms){
        var d=new Date(ms);
        var MONTHS=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return MONTHS[d.getMonth()]+' '+d.getDate()+' '+pad2(d.getHours())+':'+pad2(d.getMinutes());
      }
      function scrubWindow(spanLabel, backLabel){
        var now=Date.now();
        var spanMs=labelToMs(spanLabel);
        var backMs=backLabelToMs(backLabel);
        var toMs=now-backMs;
        var fromMs=toMs-spanMs;
        return {
          from:msToDateStr(fromMs),
          to:msToDateStr(toMs),
          label:spanLabel+' window · '+(backLabel==='now'?'now':backLabel+' back')
            +'  →  '+msToAbsLabel(fromMs)+' – '+msToAbsLabel(toMs)
        };
      }
      // resolve Warm-Blueprint palette from the live root (CSS vars) once
      function scrubColors(){
        var root=document.querySelector('.tddd-root')||document.body;
        var cs=getComputedStyle(root);
        function v(n,fb){ var x=cs.getPropertyValue(n); return (x&&x.trim())||fb; }
        return { line:v('--line','#D9D1C5'), faint:v('--faint','#9a948b'),
          accent:v('--accent','#6359D6'), accent2:v('--accent2','#433BA5'),
          soft:v('--soft','#EFECFB'), warn:v('--warn','#936C21'),
          warnbg:v('--warnbg','#F6EAD4'), ink:v('--ink','#16131c') };
      }
      function makeScrubber(initSpanIdx, initBackIdx, onApply){
        var spanIdx=initSpanIdx, backIdx=initBackIdx;
        var DPR=Math.max(1, window.devicePixelRatio||1);
        var COL=scrubColors();
        var el=document.createElement('div');
        el.className='tddd-scrubber';
        var SQ=32, BW=132, BH=27;            // span dial is square; back is a wide horizontal strip
        el.innerHTML=
          '<div class="scr-row">'
            +'<div class="scr-gauge" data-axis="span" role="slider" tabindex="0" aria-label="time span" aria-valuetext="'+SPAN_STEPS[spanIdx]+'" title="span of time — drag sideways, scroll, or arrow keys">'
              +'<canvas class="scr-cv"></canvas>'
              +'<span class="scr-cap"><b class="scr-val" data-axis="span">'+SPAN_STEPS[spanIdx]+'</b><i>span</i></span>'
            +'</div>'
            +'<span class="scr-sep"></span>'
            +'<div class="scr-gauge scr-h" data-axis="back" role="slider" tabindex="0" aria-label="offset back" aria-valuetext="'+BACK_STEPS[backIdx]+'" title="how far back — drag along the strip, scroll, or arrow keys">'
              +'<span class="scr-cap scr-cap-l"><b class="scr-val" data-axis="back">'+BACK_STEPS[backIdx]+'</b><i>back</i></span>'
              +'<canvas class="scr-cv"></canvas>'
            +'</div>'
          +'</div>'
          +'<div class="scr-readout"></div>';
        var readout=el.querySelector('.scr-readout');
        var canv={ span:el.querySelector('.scr-gauge[data-axis=span] .scr-cv'),
                   back:el.querySelector('.scr-gauge[data-axis=back] .scr-cv') };
        canv.span.width=SQ*DPR; canv.span.height=SQ*DPR; canv.span.style.width=SQ+'px'; canv.span.style.height=SQ+'px';
        canv.back.width=BW*DPR; canv.back.height=BH*DPR; canv.back.style.width=BW+'px'; canv.back.style.height=BH+'px';
        var TAU=Math.PI*2;
        var MOON_DAYS=[1,2,3,5,7,10,14,21,30];   // matches SPAN_STEPS[4..]; fullness ∝ days
        function getSteps(axis){ return axis==='span'?SPAN_STEPS:BACK_STEPS; }
        function getIdx(axis){ return axis==='span'?spanIdx:backIdx; }

        // ── span dial: clock (≤12h) morphing into a waxing moon (≥1d) ──
        function drawSpan(){
          var ctx=canv.span.getContext('2d'); ctx.setTransform(DPR,0,0,DPR,0,0); ctx.clearRect(0,0,SQ,SQ);
          var cx=SQ/2, cy=SQ/2, R=SQ/2-2.5;
          if(spanIdx<=3){ // 1h,3h,6h,12h → clock face + sweeping hand
            var frac=[1,3,6,12][spanIdx]/12;
            ctx.lineWidth=1.3; ctx.strokeStyle=COL.line; ctx.beginPath(); ctx.arc(cx,cy,R,0,TAU); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(cx,cy); ctx.arc(cx,cy,R-1.5,-Math.PI/2,-Math.PI/2+frac*TAU,false); ctx.closePath();
            ctx.fillStyle='rgba(99,89,214,.17)'; ctx.fill();
            ctx.strokeStyle=COL.faint; ctx.lineWidth=1;
            for(var i=0;i<4;i++){ var a=-Math.PI/2+i*Math.PI/2; ctx.beginPath();
              ctx.moveTo(cx+Math.cos(a)*(R-1), cy+Math.sin(a)*(R-1)); ctx.lineTo(cx+Math.cos(a)*(R-3.5), cy+Math.sin(a)*(R-3.5)); ctx.stroke(); }
            var ha=-Math.PI/2+frac*TAU; ctx.strokeStyle=COL.accent2; ctx.lineWidth=1.6; ctx.lineCap='round';
            ctx.beginPath(); ctx.moveTo(cx,cy); ctx.lineTo(cx+Math.cos(ha)*(R-3.5), cy+Math.sin(ha)*(R-3.5)); ctx.stroke();
            ctx.fillStyle=COL.accent2; ctx.beginPath(); ctx.arc(cx,cy,1.7,0,TAU); ctx.fill();
          } else { // 1d..30d → waxing moon; fullness ∝ days (3d≈thin, 30d=full), floored so it's always visible
            var days=MOON_DAYS[spanIdx-4], f=0.10+0.90*(days/30), rr=R-1, t=Math.cos(f*Math.PI);
            ctx.fillStyle=COL.soft; ctx.beginPath(); ctx.arc(cx,cy,rr,0,TAU); ctx.fill();
            ctx.beginPath(); ctx.arc(cx,cy,rr,-Math.PI/2,Math.PI/2,false);
            ctx.ellipse(cx,cy,Math.abs(rr*t),rr,0,Math.PI/2,-Math.PI/2,(t>0)); ctx.closePath();
            ctx.fillStyle=COL.accent; ctx.fill();
            ctx.lineWidth=1.2; ctx.strokeStyle=COL.line; ctx.beginPath(); ctx.arc(cx,cy,rr,0,TAU); ctx.stroke();
          }
        }
        // ── back gauge: 70s horizontal strip speedometer; pointer sweeps now → 3mo ──
        function drawBack(){
          var ctx=canv.back.getContext('2d'); ctx.setTransform(DPR,0,0,DPR,0,0); ctx.clearRect(0,0,BW,BH);
          var PAD=9, x0=PAD, x1=BW-PAD, y=15, h=5;
          var n=BACK_STEPS.length, frac=backIdx/(n-1), nx=x0+frac*(x1-x0);
          // instrument-panel backing
          if(ctx.roundRect){ ctx.beginPath(); ctx.roundRect(1,2,BW-2,BH-4,4); ctx.fillStyle=COL.warnbg; ctx.fill(); }
          else { ctx.fillStyle=COL.warnbg; ctx.fillRect(1,2,BW-2,BH-4); }
          // track + amber fill to pointer
          ctx.lineCap='round'; ctx.lineWidth=h; ctx.strokeStyle=COL.line;
          ctx.beginPath(); ctx.moveTo(x0,y); ctx.lineTo(x1,y); ctx.stroke();
          ctx.strokeStyle=COL.warn; ctx.beginPath(); ctx.moveTo(x0,y); ctx.lineTo(nx,y); ctx.stroke();
          // gradations under the bar
          ctx.strokeStyle=COL.faint; ctx.lineWidth=1;
          for(var i=0;i<n;i++){ var tx=x0+(i/(n-1))*(x1-x0); ctx.beginPath(); ctx.moveTo(tx,y+5); ctx.lineTo(tx,y+8); ctx.stroke(); }
          // pointer: downward triangle + stem at current position
          ctx.fillStyle=COL.warn;
          ctx.beginPath(); ctx.moveTo(nx,y-4); ctx.lineTo(nx-3.5,y-10); ctx.lineTo(nx+3.5,y-10); ctx.closePath(); ctx.fill();
          ctx.strokeStyle=COL.warn; ctx.lineWidth=2; ctx.lineCap='round';
          ctx.beginPath(); ctx.moveTo(nx,y-4); ctx.lineTo(nx,y+8); ctx.stroke();
        }
        function redraw(){ drawSpan(); drawBack(); }
        function updateReadout(){ var w=scrubWindow(SPAN_STEPS[spanIdx],BACK_STEPS[backIdx]); if(readout) readout.textContent=w.label; }
        function fireApply(){ var w=scrubWindow(SPAN_STEPS[spanIdx],BACK_STEPS[backIdx]); onApply(w.from,w.to,spanIdx,backIdx); }
        function setIdx(axis,idx){
          var steps=getSteps(axis); idx=Math.max(0,Math.min(steps.length-1, idx));
          if(axis==='span') spanIdx=idx; else backIdx=idx;
          var val=el.querySelector('.scr-val[data-axis="'+axis+'"]'); if(val) val.textContent=steps[idx];
          var g=el.querySelector('.scr-gauge[data-axis='+axis+']'); if(g) g.setAttribute('aria-valuetext', steps[idx]);
          redraw(); updateReadout();
        }
        function bindGauge(axis, opts){
          opts=opts||{};
          var g=el.querySelector('.scr-gauge[data-axis='+axis+']'), n=getSteps(axis).length;
          if(opts.absolute){            // horizontal strip — drag/click maps to a position
            var cvEl=g.querySelector('.scr-cv'), dragA=false;
            function idxFromX(clientX){ var r=cvEl.getBoundingClientRect(), PAD=9;
              var f=Math.max(0,Math.min(1,(clientX-(r.left+PAD))/(r.width-2*PAD))); return Math.round(f*(n-1)); }
            g.addEventListener('mousedown',function(e){ dragA=true; setIdx(axis, idxFromX(e.clientX)); e.preventDefault(); });
            document.addEventListener('mousemove',function(e){ if(!dragA) return; setIdx(axis, idxFromX(e.clientX)); });
            document.addEventListener('mouseup',function(){ if(!dragA) return; dragA=false; fireApply(); });
            g.addEventListener('touchstart',function(e){ dragA=true; setIdx(axis, idxFromX(e.touches[0].clientX)); e.preventDefault(); },{passive:false});
            g.addEventListener('touchmove',function(e){ if(!dragA) return; setIdx(axis, idxFromX(e.touches[0].clientX)); },{passive:true});
            g.addEventListener('touchend',function(){ if(!dragA) return; dragA=false; fireApply(); });
          } else {                       // dial — relative sideways scrub
            var PX=13, dragR=false, sx=0, sidx=0, moved=false;
            g.addEventListener('mousedown',function(e){ dragR=true; moved=false; sx=e.clientX; sidx=getIdx(axis); e.preventDefault(); });
            document.addEventListener('mousemove',function(e){ if(!dragR) return; var d=Math.round((e.clientX-sx)/PX); if(d) moved=true; setIdx(axis, sidx+d); });
            document.addEventListener('mouseup',function(){ if(!dragR) return; dragR=false; if(moved) fireApply(); });
            g.addEventListener('touchstart',function(e){ dragR=true; sx=e.touches[0].clientX; sidx=getIdx(axis); e.preventDefault(); },{passive:false});
            g.addEventListener('touchmove',function(e){ if(!dragR) return; setIdx(axis, sidx+Math.round((e.touches[0].clientX-sx)/PX)); },{passive:true});
            g.addEventListener('touchend',function(){ if(!dragR) return; dragR=false; fireApply(); });
          }
          g.addEventListener('wheel',function(e){ e.preventDefault(); setIdx(axis, getIdx(axis)+(e.deltaY<0?1:-1)); fireApply(); },{passive:false});
          g.addEventListener('keydown',function(e){
            if(e.key==='ArrowRight'||e.key==='ArrowUp'){ setIdx(axis,getIdx(axis)+1); fireApply(); e.preventDefault(); }
            else if(e.key==='ArrowLeft'||e.key==='ArrowDown'){ setIdx(axis,getIdx(axis)-1); fireApply(); e.preventDefault(); }
          });
        }
        bindGauge('span'); bindGauge('back',{absolute:true});
        redraw(); updateReadout();
        return el;
      }
      function setNav(name){ navBtns.forEach(function(b){ b.setAttribute('aria-selected', b.dataset.view===name); }); }
      function only(name){ var shown=views[name]; Object.keys(views).forEach(function(k){ if(views[k]) views[k].hidden=(views[k]!==shown); }); setNav(name); }

      function showView(name){
        only(name);
        if(name!=='flow' && window.wp && wp.heartbeat){ wp.heartbeat.interval(60); }
        if(name==='audit'){ if(!auditLoaded){ auditLoaded=true; load(); } }
        else if(name==='flow'){ loadFlow(); startLive('fast'); }
        else if(name==='proc'){ loadProc(); }
        else if(name==='biography'){ currentBiography=null; showBiographyRecent(); }
        else if(name==='dlq'){ tablesSub='dlq'; tablesPage=1; loadTables(); }
        else if(name==='outbox'){ tablesSub='outbox'; tablesPage=1; loadTables(); }
        else if(name==='trace' && !currentCorr){ showTraceRecent(); }
      }
      navBtns.forEach(function(b){ b.addEventListener('click', function(){
        var v=b.dataset.view; location.hash = (v==='trace' && currentCorr) ? ('trace/'+currentCorr) : v;
      }); });
      $('#tddd-trace-back').addEventListener('click', function(){
        if(currentCorr){ currentCorr=null; _prevTraceNodes={}; location.hash='trace'; }
        else { location.hash='audit'; }
      });

      // ── vitals strip ──
      var vEls={
        cmds24:$('#tddd-v-cmds24'), succ:$('#tddd-v-succ'), err:$('#tddd-v-err'),
        p95:$('#tddd-v-p95'), dlq:$('#tddd-v-dlq'), obx:$('#tddd-v-obx'), ifl:$('#tddd-v-ifl')
      };
      function fmtAge(s){
        if(!s||s<=0) return '';
        if(s<60) return s+'s';
        if(s<3600) return Math.floor(s/60)+'m';
        return Math.floor(s/3600)+'h';
      }
      function renderVitals(d){
        var cn=vEls.cmds24&&vEls.cmds24.querySelector('.vt-n'); if(cn) cn.textContent=d.commands_24h!=null?d.commands_24h:'—';
        var sn=vEls.succ&&vEls.succ.querySelector('.vt-n'); if(sn) sn.textContent=d.success_rate!=null?d.success_rate.toFixed(1):'—';
        var en=vEls.err&&vEls.err.querySelector('.vt-n'); if(en) en.textContent=d.errors_24h!=null?d.errors_24h:'—';
        if(vEls.err) vEls.err.classList.toggle('alarm', !!(d.errors_24h>0));
        var pn=vEls.p95&&vEls.p95.querySelector('.vt-n'); if(pn) pn.textContent=d.p95_ms!=null?d.p95_ms+'ms':'—';
        var dn=vEls.dlq&&vEls.dlq.querySelector('.vt-n'); if(dn) dn.textContent=d.dlq_depth!=null?d.dlq_depth:'—';
        if(vEls.dlq) vEls.dlq.classList.toggle('alarm', !!(d.dlq_depth>0));
        // outbox: pending count + oldest age when pending>0
        if(vEls.obx){
          var on=vEls.obx.querySelector('.vt-n'); if(on) on.textContent=d.outbox_pending!=null?d.outbox_pending:'—';
          var existing=vEls.obx.querySelector('.vt-age'); if(existing) existing.remove();
          if(d.outbox_pending>0 && d.outbox_oldest_age_s>0){
            var aged=document.createElement('span'); aged.className='vt-age'; aged.textContent=fmtAge(d.outbox_oldest_age_s);
            vEls.obx.appendChild(aged);
          }
        }
        var fn=vEls.ifl&&vEls.ifl.querySelector('.vt-n'); if(fn) fn.textContent=d.in_flight!=null?d.in_flight:'—';
      }
      function loadVitals(){
        fetch(R.rest+'/overview?consumer='+encodeURIComponent(state.consumer),{headers:{'X-WP-Nonce':R.nonce}})
          .then(function(r){ return r.json(); }).then(function(d){ renderVitals(d); if(!views.flow.hidden) renderFlow(d); })
          .catch(function(){}); // silent — it's a decorative strip
      }
      loadVitals();
      // Refresh vitals on heartbeat tick (piggyback on the existing heartbeat-tick event)
      // — done in startLive() via onTick → but vitals need a separate /overview hit because
      //   LiveQuery doesn't return throughput_1m / errors_1m. A periodic fallback covers non-flow views.
      var _vitalsT; function scheduleVitals(){ clearTimeout(_vitalsT); _vitalsT=setTimeout(function(){ loadVitals(); scheduleVitals(); },30000); }
      scheduleVitals();

      // ── flow ──
      function loadFlow(){
        fetch(R.rest+'/overview?consumer='+encodeURIComponent(state.consumer),{headers:{'X-WP-Nonce':R.nonce}})
          .then(function(r){ return r.json(); }).then(function(d){ renderFlow(d); renderVitals(d); })
          .catch(function(){}); // silent — vitals strip already shows state
      }
      function shortTable(t){ return t.replace(/^.*?(command_audit|integration_outbox|integration_dlq|long_processes|behaviour_workflow_items|behaviour_workflows)$/, '$1'); }
      function arow(name, meta, corr){ return '<div class="arow"'+(corr?(' data-corr="'+esc(corr)+'"'):'')+'><span class="an" title="'+esc(name)+'">'+shortName(name)+'</span><span class="as">'+esc(meta)+'</span></div>'; }
      function darow(name, meta, corr, id){ return '<div class="arow"'+(corr?(' data-corr="'+esc(corr)+'"'):'')+'><span class="an" title="'+esc(name)+'">'+shortName(name)+'</span><span class="as">'+esc(meta)+'</span>'
        +'<span class="acts"><button class="abtn" data-act="replay" data-id="'+esc(id)+'">Replay</button><button class="abtn danger" data-act="discard" data-id="'+esc(id)+'">Discard</button></span></div>'; }
      function renderFlow(d){
        var att='';
        att+='<details class="acoll"><summary class="band fail">failed commands<span class="c">'+d.failed.length+'</span></summary>'+d.failed.map(function(f){ return arow(f.command_name, rel(f.started_at), f.correlation_id); }).join('')+'</details>';
        att+='<details class="acoll"><summary class="band crit">dead letters<span class="c">'+d.dead.length+'</span></summary>'+d.dead.map(function(x){ return darow(x.event_type, rel(x.moved_at), x.correlation_id, x.id); }).join('')+'</details>';
        att+='<details class="acoll"><summary class="band warn">stuck / suspended processes<span class="c">'+d.stuck.length+'</span></summary>'+d.stuck.map(function(p){ return arow(p.process_class+' #'+p.id, esc(p.status), null); }).join('')+'</details>';
        if(!d.failed.length && !d.dead.length && !d.stuck.length){ att='<div class="band ok">all clear<span class="c">nominal</span></div>'; }
        $('#tddd-attention').innerHTML=att;
        var maxB=Math.max.apply(null, d.storage.map(function(s){return s.bytes;}).concat([1]));
        $('#tddd-storage').innerHTML=d.storage.map(function(s){
          return '<div class="srow2"><span class="sn">'+esc(shortTable(s.t))+'</span><span class="sv">'+fmtBytes(s.bytes)+' &middot; '+s.rows+' rows</span><span class="sb"><i style="width:'+Math.max(2,Math.round(s.bytes/maxB*100))+'%"></i></span></div>';
        }).join('');
        var tc=d.top_commands||[];
        $('#tddd-topcmds').innerHTML = tc.length ? tc.map(function(t){
          return '<div class="tcmd"><span class="tn" title="'+esc(t.name)+'">'+shortName(t.name)+'</span><span class="tc">'+t.n+'</span><span class="te'+(t.errs?'':' zero')+'">'+(t.errs?(t.errs+' err'):'0')+'</span></div>';
        }).join('') : '<div class="tcmd"><span class="tn" style="color:var(--faint)">no commands in 24h</span><span></span><span></span></div>';
      }
      // ── actions (dispatch framework commands, self-audited) ──
      var confirmEl=$('#tddd-confirm'), confirmMsg=$('#tddd-confirm-msg'), confirmOk=$('#tddd-confirm-ok'), toastEl=$('#tddd-toast'), toastT, pendingAction=null;
      function showToast(html, err){ toastEl.innerHTML=html; toastEl.classList.toggle('err',!!err); toastEl.hidden=false; clearTimeout(toastT); toastT=setTimeout(function(){ toastEl.hidden=true; }, 4200); }
      function askConfirm(msg, onok){ confirmMsg.innerHTML=msg; pendingAction=onok; confirmEl.hidden=false; }
      function closeConfirm(){ confirmEl.hidden=true; pendingAction=null; }
      confirmEl.addEventListener('click', function(e){ if(e.target.hasAttribute('data-cancel')) closeConfirm(); });
      confirmOk.addEventListener('click', function(){ var fn=pendingAction; closeConfirm(); if(fn) fn(); });
      function doAction(action, params, okMsg){
        var body=new URLSearchParams(Object.assign({consumer:state.consumer}, params));
        fetch(R.rest+'/actions/'+action, { method:'POST', headers:{'X-WP-Nonce':R.nonce,'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString() })
          .then(function(r){ return r.json().then(function(j){ return {ok:r.ok, j:j}; }); })
          .then(function(res){
            if(res.ok && res.j && res.j.ok){ showToast(esc(okMsg)+' &middot; <span class="tcorr">audited in tangible_ddd</span>'); refreshActive(); }
            else { showToast('Action failed: '+esc((res.j && res.j.message) || 'error'), true); }
          })
          .catch(function(e){ showToast('Action failed: '+esc(e.message), true); });
      }
      function refreshActive(){
        if(!views.flow.hidden){ loadFlow(); }
        else if(!tablesEl.hidden){ loadTables(); }
        else if(!views.audit.hidden){ load(); }
        else if(!views.biography.hidden){ currentBiography=null; showBiographyRecent(); }
      }

      // ── tables (DLQ / Outbox browser) ──
      var tablesSub='dlq', tablesPage=1;
      document.querySelectorAll('#tddd-view-tables .subtabs button').forEach(function(b){ b.addEventListener('click', function(){
        tablesSub=b.dataset.tsub; tablesPage=1;
        document.querySelectorAll('#tddd-view-tables .subtabs button').forEach(function(x){ x.setAttribute('aria-selected', x===b); });
        loadTables();
      }); });
      // Wire tables filter inputs
      var selTablesStatus=$('#tddd-sel-tables-status'), tblFrom=$('#tddd-tables-from'), tblTo=$('#tddd-tables-to');
      if(selTablesStatus) selTablesStatus.addEventListener('change', function(){ tablesState.status=selTablesStatus.value; tablesPage=1; loadTables(); });
      if(tblFrom) tblFrom.addEventListener('change', function(){ tablesState.from=tblFrom.value; tablesPage=1; loadTables(); });
      if(tblTo)   tblTo.addEventListener('change',   function(){ tablesState.to=tblTo.value;     tablesPage=1; loadTables(); });

      // ── Temporal scrubbers ──
      var auditSpanIdx=4, auditBackIdx=0;   // default 1d/now
      var tablesSpanIdx=4, tablesBackIdx=0;
      var auditUseScrubber=true, tablesUseScrubber=true;

      function applyAuditScrub(from, to){
        state.from=from; state.to=to;
        if(dateFrom) dateFrom.value=from;
        if(dateTo)   dateTo.value=to;
        state.page=1; load();
      }
      function applyTablesScrub(from, to){
        tablesState.from=from; tablesState.to=to;
        if(tblFrom) tblFrom.value=from;
        if(tblTo)   tblTo.value=to;
        tablesPage=1; loadTables();
      }

      var auditScrubEl=makeScrubber(auditSpanIdx, auditBackIdx, function(f,t,si,bi){ auditSpanIdx=si; auditBackIdx=bi; applyAuditScrub(f,t); });
      var tablesScrubEl=makeScrubber(tablesSpanIdx, tablesBackIdx, function(f,t,si,bi){ tablesSpanIdx=si; tablesBackIdx=bi; applyTablesScrub(f,t); });
      var auditSWrap=$('#tddd-audit-scrubber-wrap');
      var tablesSWrap=$('#tddd-tables-scrubber-wrap');
      if(auditSWrap)  auditSWrap.appendChild(auditScrubEl);
      if(tablesSWrap) tablesSWrap.appendChild(tablesScrubEl);

      function syncScrubVisibility(){
        var afrom=$('.tddd-fg-from'), ato=$('.tddd-fg-to');
        if(afrom) afrom.style.display=auditUseScrubber?'none':'';
        if(ato)   ato.style.display=auditUseScrubber?'none':'';
        if(auditSWrap) auditSWrap.style.display=auditUseScrubber?'':'none';
        var tfrom=$('.tddd-fg-tables-from'), tto=$('.tddd-fg-tables-to');
        if(tfrom) tfrom.style.display=tablesUseScrubber?'none':'';
        if(tto)   tto.style.display=tablesUseScrubber?'none':'';
        if(tablesSWrap) tablesSWrap.style.display=tablesUseScrubber?'':'none';
      }
      syncScrubVisibility();

      // Seed state + inputs with the default 1d/now window WITHOUT fetching — the
      // lazy-load paths (showView's auditLoaded guard, the DLQ/Outbox tab handlers)
      // pick this up when those views are first shown, so Flow (the default view)
      // doesn't fire two stray requests on page load.
      (function(){
        var w=scrubWindow(SPAN_STEPS[auditSpanIdx], BACK_STEPS[auditBackIdx]);
        state.from=w.from; state.to=w.to;
        if(dateFrom) dateFrom.value=w.from;
        if(dateTo)   dateTo.value=w.to;
        var wt=scrubWindow(SPAN_STEPS[tablesSpanIdx], BACK_STEPS[tablesBackIdx]);
        tablesState.from=wt.from; tablesState.to=wt.to;
        if(tblFrom) tblFrom.value=wt.from;
        if(tblTo)   tblTo.value=wt.to;
      })();

      var auditToggle=$('#tddd-audit-scrub-toggle');
      var tablesToggle=$('#tddd-tables-scrub-toggle');
      if(auditToggle) auditToggle.addEventListener('click', function(){
        auditUseScrubber=!auditUseScrubber; syncScrubVisibility();
        if(!auditUseScrubber){ state.from=''; state.to=''; if(dateFrom) dateFrom.value=''; if(dateTo) dateTo.value=''; state.page=1; load(); }
        else { var w=scrubWindow(SPAN_STEPS[auditSpanIdx],BACK_STEPS[auditBackIdx]); applyAuditScrub(w.from,w.to); }
      });
      if(tablesToggle) tablesToggle.addEventListener('click', function(){
        tablesUseScrubber=!tablesUseScrubber; syncScrubVisibility();
        if(!tablesUseScrubber){ tablesState.from=''; tablesState.to=''; if(tblFrom) tblFrom.value=''; if(tblTo) tblTo.value=''; tablesPage=1; loadTables(); }
        else { var w=scrubWindow(SPAN_STEPS[tablesSpanIdx],BACK_STEPS[tablesBackIdx]); applyTablesScrub(w.from,w.to); }
      });

      function syncTablesFilters(){ var w=$('#tddd-tables-status-wrap'); if(w) w.style.display=(tablesSub==='outbox')?'':'none'; }
      function loadTables(){
        var body=$('#tddd-tables-body'); body.innerHTML='<tr><td class="empty">Loading&hellip;</td></tr>';
        syncTablesFilters();
        var ep = tablesSub==='outbox' ? 'outbox' : 'dlq';
        var tqs=new URLSearchParams({consumer:state.consumer, page:tablesPage});
        if(tablesSub==='outbox' && tablesState.status) tqs.set('status', tablesState.status);
        if(tablesState.from) tqs.set('from', tablesState.from);
        if(tablesState.to)   tqs.set('to',   tablesState.to);
        fetch(R.rest+'/'+ep+'?'+tqs.toString(),{headers:{'X-WP-Nonce':R.nonce}})
          .then(function(r){return r.json();}).then(function(d){ tablesSub==='outbox'?renderOutbox(d):renderDlq(d); })
          .catch(function(e){ body.innerHTML='<tr><td class="empty">Error: '+esc(e.message)+'</td></tr>'; });
      }
      function tablesPager(d){
        var el=$('#tddd-tables-pager'); $('#tddd-tables-range').textContent=(d.total?((d.page-1)*d.per_page+1):0)+'–'+Math.min(d.page*d.per_page,d.total)+' of '+d.total;
        if(d.pages<=1){ el.innerHTML=''; return; }
        var h='<button '+(d.page<=1?'disabled':'')+' data-tp="'+(d.page-1)+'">‹</button>';
        for(var p=Math.max(1,d.page-1);p<=Math.min(d.pages,d.page+1);p++) h+='<button '+(p===d.page?'aria-current="true"':'')+' data-tp="'+p+'">'+p+'</button>';
        h+='<button '+(d.page>=d.pages?'disabled':'')+' data-tp="'+(d.page+1)+'">›</button>'; el.innerHTML=h;
      }
      function corrCell(c){ return c ? '<span class="corrcell" data-corr="'+esc(c)+'">'+esc(c.slice(0,8))+'</span>' : '<span class="idm">&mdash;</span>'; }
      function renderDlq(d){
        $('#tddd-tables-label').textContent='integration_dlq'; $('#tddd-tables-count').textContent=d.total+' dead letters';
        $('#tddd-tables-head').innerHTML='<tr><th>event</th><th>correlation</th><th>attempts</th><th>final error</th><th>moved</th><th></th></tr>';
        var b=$('#tddd-tables-body');
        b.innerHTML = d.rows.length ? d.rows.map(function(r){
          return '<tr><td class="cn">'+esc(r.event_type)+'</td><td class="dotm">'+corrCell(r.correlation_id)+'</td><td class="idm">'+r.attempts+'</td>'
            +'<td class="idm" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+esc(r.final_error||'')+'">'+esc(r.final_error||'—')+'</td>'
            +'<td class="idm">'+rel(r.moved_at)+'</td>'
            +'<td style="text-align:right;white-space:nowrap"><button class="abtn" data-act="replay" data-id="'+r.id+'">Replay</button> <button class="abtn danger" data-act="discard" data-id="'+r.id+'">Discard</button></td></tr>';
        }).join('') : '<tr><td class="empty">No dead letters.</td></tr>';
        tablesPager(d);
      }
      function renderOutbox(d){
        $('#tddd-tables-label').textContent='integration_outbox'; $('#tddd-tables-count').textContent=d.total+' outbox rows';
        $('#tddd-tables-head').innerHTML='<tr><th>event</th><th>status</th><th>attempts</th><th>correlation</th><th>next attempt</th><th>created</th><th></th></tr>';
        var b=$('#tddd-tables-body');
        b.innerHTML = d.rows.length ? d.rows.map(function(r){
          var canRetry = (r.status==='failed' || r.status==='dlq');
          var sb = r.status==='completed'?'success':((r.status==='failed'||r.status==='dlq')?'error':'in_progress');
          return '<tr><td class="cn">'+esc(r.event_type)+'</td><td><span class="badge b-'+sb+'">'+esc(r.status)+'</span></td>'
            +'<td class="idm">'+r.attempts+'/'+r.max_attempts+'</td><td class="dotm">'+corrCell(r.correlation_id)+'</td>'
            +'<td class="idm">'+(r.next_attempt_at?rel(r.next_attempt_at):'—')+'</td><td class="idm">'+rel(r.created_at)+'</td>'
            +'<td style="text-align:right;white-space:nowrap">'+(canRetry?'<button class="abtn" data-act="retry" data-id="'+r.id+'">Retry</button>':'')+'</td></tr>';
        }).join('') : '<tr><td class="empty">No outbox rows.</td></tr>';
        tablesPager(d);
      }
      $('#tddd-view-tables').addEventListener('click', function(e){
        var p=e.target.closest('button[data-tp]'); if(p && !p.disabled){ tablesPage=+p.dataset.tp; loadTables(); return; }
        var b=e.target.closest('.abtn'); if(b){ var act=b.dataset.act, id=b.dataset.id;
          if(act==='replay'){ doAction('replay',{id:id},'Replayed dead-letter #'+id); }
          else if(act==='retry'){ doAction('retry',{id:id},'Re-queued outbox #'+id); }
          else if(act==='discard'){ askConfirm('Permanently discard dead-letter <b>#'+esc(id)+'</b>? This cannot be undone.', function(){ doAction('discard',{id:id},'Discarded dead-letter #'+id); }); }
          return;
        }
        var c=e.target.closest('.corrcell'); if(c && c.dataset.corr) location.hash='trace/'+encodeURIComponent(c.dataset.corr);
      });
      $('#tddd-attention').addEventListener('click', function(e){
        var b=e.target.closest('.abtn');
        if(b){ e.stopPropagation(); var act=b.dataset.act, id=b.dataset.id;
          if(act==='replay'){ doAction('replay', {id:id}, 'Replayed dead-letter #'+id); }
          else if(act==='discard'){ askConfirm('Permanently discard dead-letter <b>#'+esc(id)+'</b>? This cannot be undone.', function(){ doAction('discard', {id:id}, 'Discarded dead-letter #'+id); }); }
          return;
        }
        var a=e.target.closest('.arow[data-corr]'); if(a && a.dataset.corr) location.hash='trace/'+encodeURIComponent(a.dataset.corr);
      });
      var purgeBtn=$('#tddd-purge');
      if(purgeBtn){ purgeBtn.addEventListener('click', function(){ askConfirm('Purge <b>completed</b> outbox rows older than <b>30 days</b> for <b>'+esc(state.consumer)+'</b>? Delivered rows only.', function(){ doAction('purge', {days:30}, 'Purged completed outbox'); }); }); }

      // ── recent-traces list ──
      function showTraceRecent(){
        startLive(60);
        traceRecent.hidden=false; traceOpen.hidden=true;
        _pendingNewCorrs=[];
        if(trcNewbar){ trcNewbar.classList.remove('visible'); trcNewbar.textContent=''; }
        if(!_recentCorrs.length){
          if(trcList) trcList.innerHTML='<div style="padding:18px 14px;font-family:var(--fm);font-size:.72rem;color:var(--faint)">Loading&hellip;</div>';
        }
        loadRecentTraces();
      }
      function loadRecentTraces(){
        fetch(R.rest+'/traces?consumer='+encodeURIComponent(state.consumer),{headers:{'X-WP-Nonce':R.nonce}})
          .then(function(r){ return r.json(); }).then(function(rows){ renderRecentTraces(rows, false); })
          .catch(function(e){ if(trcList) trcList.innerHTML='<div style="padding:18px 14px;font-family:var(--fm);font-size:.72rem;color:var(--crit)">Error: '+esc(e.message)+'</div>'; });
      }
      function renderRecentTraces(rows, flash){
        if(!trcList) return;
        if(!rows||!rows.length){
          trcList.innerHTML='<div class="trc-section-hdr live-hdr">&#9679; LIVE</div><div class="trc-empty-bucket">No live traces</div>'
            +'<div class="trc-section-hdr">RECENTLY FINISHED</div><div class="trc-empty-bucket">None</div>';
          _recentCorrs=[]; _recentBuckets={}; return;
        }
        var nowMs=Date.now();
        var prevSet={};
        _recentCorrs.forEach(function(c){ prevSet[c]=true; });
        var prevLive=_recentBuckets.live||{};
        var prevRecent=_recentBuckets.recent||{};
        var liveRows=[], recentRows=[];
        rows.forEach(function(r){
          var age=nowMs-parseLastAt(r.last_at);
          var isLive=r.in_progress===true || age<=TRACE_WARM_S*1000;
          var isRecent=!isLive && age<=TRACE_LINGER_S*1000;
          if(isLive) liveRows.push(r);
          else if(isRecent) recentRows.push(r);
          // aged-out rows are simply dropped
        });
        // Color-coded count chips (only non-zero) — matches the trace legend hues.
        function trcChips(r){
          function c(n,color,label){ return n>0?'<span class="trc-ct" title="'+n+' '+label+'"><i style="background:'+color+'"></i>'+n+'</span>':''; }
          return '<span class="trc-cts">'
            +c(r.spans,'#6359D6','commands')+c(r.workflows,'#7C4DE0','workflows')
            +c(r.events,'#2E7D8A','events')+c(r.processes,'#507F06','processes')+'</span>';
        }
        function trcRowInner(r){
          var errPill=r.errs>0?'<span class="trc-pill err">'+r.errs+' err</span>':'';
          var livePill=r.in_progress?'<span class="trc-pill live">live</span>':'';
          return '<div class="trc-cmd" title="'+esc(r.root_command)+'">'+esc(r.root_command||'—')+'</div>'
            +'<div class="trc-corr">'+esc(r.correlation_id.slice(0,8))+'</div>'
            +trcChips(r)
            +(r.dur_ms?'<span class="trc-dur">'+fmtDur(r.dur_ms)+'</span>':'')
            +errPill+livePill
            +'<span class="trc-age">'+rel(r.last_at)+'</span>';
        }
        function rowHtml(r, extraCls){
          var isNew=flash && !prevSet[r.correlation_id];
          var wasRecent=prevRecent[r.correlation_id];
          var reactivated=wasRecent && !prevLive[r.correlation_id]; // moved recent→live
          var cls='trc-row'+(isNew?' tddd-new':'')+(reactivated?' tddd-reactivated':'')+( extraCls?' '+extraCls:'');
          return '<div class="'+cls+'" data-corr="'+esc(r.correlation_id)+'">'+trcRowInner(r)+'</div>';
        }
        var html='';
        html+='<div class="trc-section-hdr live-hdr">&#9679; LIVE</div>';
        if(liveRows.length){ html+=liveRows.map(function(r){ return rowHtml(r,''); }).join(''); }
        else { html+='<div class="trc-empty-bucket">No live traces</div>'; }
        html+='<div class="trc-section-hdr">RECENTLY FINISHED</div>';
        if(recentRows.length){ html+=recentRows.map(function(r){ return rowHtml(r,''); }).join(''); }
        else { html+='<div class="trc-empty-bucket">None</div>'; }
        trcList.innerHTML=html;
        // Update tracking state
        var newLive={}, newRecent={};
        liveRows.forEach(function(r){ newLive[r.correlation_id]=true; });
        recentRows.forEach(function(r){ newRecent[r.correlation_id]=true; });
        _recentBuckets={ live:newLive, recent:newRecent };
        _recentCorrs=liveRows.concat(recentRows).map(function(r){ return r.correlation_id; });
      }
      // Live-prepend new correlations to the list (heartbeat path, no-scroll disruption).
      function prependNewToRecentList(newRows){
        if(!trcList || !newRows || !newRows.length) return;
        var existingSet={};
        _recentCorrs.forEach(function(c){ existingSet[c]=true; });
        var genuinelyNew=newRows.filter(function(r){ return !existingSet[r.correlation_id]; });
        if(!genuinelyNew.length) return;
        // Buffer if user is mid-scroll (scrollTop>0 means they scrolled down).
        var container=trcList.parentElement;
        var isScrolled=container && container.scrollTop>60;
        if(isScrolled){
          _pendingNewCorrs=genuinelyNew.concat(_pendingNewCorrs);
          var n=_pendingNewCorrs.length;
          if(trcNewbar){ trcNewbar.textContent='▲ '+n+' new trace'+(n!==1?'s':''); trcNewbar.classList.add('visible'); }
          return;
        }
        // Prepend directly.
        genuinelyNew.forEach(function(r){
          var el=document.createElement('div');
          el.className='trc-row tddd-new'; el.dataset.corr=r.correlation_id;
          el.innerHTML=trcRowInner(r);
          trcList.insertBefore(el, trcList.firstChild);
        });
        genuinelyNew.forEach(function(r){ _recentCorrs.unshift(r.correlation_id); });
        // Cap at 60.
        while(_recentCorrs.length>60 && trcList.children.length>60){
          _recentCorrs.pop(); if(trcList.lastChild) trcList.removeChild(trcList.lastChild);
        }
      }
      if(trcNewbar){ trcNewbar.addEventListener('click', function(){
        _pendingNewCorrs=[]; trcNewbar.classList.remove('visible'); trcNewbar.textContent='';
        loadRecentTraces();
      }); }
      if(trcList){ trcList.addEventListener('click', function(e){
        var row=e.target.closest('.trc-row[data-corr]'); if(row&&row.dataset.corr) showTrace(row.dataset.corr);
      }); }

      // ── trace ──
      function showTrace(corr){
        if(!corr) return;
        if(currentCorr===corr && !views.trace.hidden) return; // guard hashchange echo
        currentCorr=corr; closeDrawer(); only('trace');
        traceRecent.hidden=true; traceOpen.hidden=false;
        _prevTraceNodes={};
        startLive(60);
        if(location.hash!=='#trace/'+corr) location.hash='trace/'+corr;
        traceHead.innerHTML='<div class="corr">'+esc(corr)+'</div><div class="meta">loading&hellip;</div>';
        traceRows.innerHTML=''; ruler.innerHTML=''; traceWf.innerHTML='';
        fetch(R.rest+'/trace/'+encodeURIComponent(corr)+'?consumer='+encodeURIComponent(state.consumer),{headers:{'X-WP-Nonce':R.nonce}})
          .then(function(r){ return r.json(); }).then(function(d){ _prevTraceNodes={}; renderTrace(d); })
          .catch(function(e){ traceHead.innerHTML='<div class="meta">Error: '+esc(e.message)+'</div>'; });
      }
      function cfgLabelTrace(c){ return (typeof c==='string')?c:((c&&(c.type||c._class||c['class']))||'behaviour'); }
      function renderTrace(d){
        var inProgressNow = !!(d.in_progress);
        // Derive in_progress from workflows/nodes when not explicitly set by the endpoint.
        if(d.in_progress===undefined){
          inProgressNow = !!(d.workflows && d.workflows.some(function(w){ return !w.is_complete && !w.is_failed; }));
        }
        var liveBadge=inProgressNow?'<span class="trace-live-badge">live</span>':'';
        var participantMap=d.participants||{};
        var participantKeys=Object.keys(participantMap);
        var participants=participantKeys.map(function(key){
          var p=participantMap[key]||{};
          return '<span class="trace-participant"><i style="background:'+esc(p.accent||'#646970')+'"></i>'
            +'<b>'+esc(p.label||key)+'</b>'+(p.ghost?'<small>ghost</small>':'')+'</span>';
        }).join('');
        var warningCount=(d.warnings||[]).length;
        traceHead.innerHTML='<div class="corr">'+esc(d.correlation_id)+liveBadge+'</div>'
          +'<div class="meta">'+d.span_count+' spans &middot; '+d.event_count+' events &middot; '+d.process_count+' processes'
          +(d.workflow_count?' &middot; '+d.workflow_count+' workflow'+(d.workflow_count!==1?'s':''):'')
          +' &middot; '+fmtDur(d.total_ms)
          +(d.has_error?' &middot; <span class="err">has error</span>':'')+'</div>'
          +(participants?'<div class="trace-participants">'+participants+'</div>':'')
          +(warningCount?'<div class="trace-warning">'+warningCount+' recorded parent link'+(warningCount!==1?'s':'')+' could not be resolved exactly</div>':'');
        // The X-axis is COMPRESSED (durations to scale, async waits elided), so proportional
        // wall-time ticks would lie. The ruler is a short note; timing lives on gap markers.
        ruler.innerHTML='<div class="rt-note">durations to scale &middot; sparse gap markers show cumulative elapsed time</div>';
        if(!d.nodes||!d.nodes.length){ traceRows.innerHTML='<div style="padding:24px;text-align:center;color:var(--faint);font-family:var(--fm)">No spans.</div>'; ruler.parentNode.style.minWidth=''; traceRows.style.minWidth=''; }
        else {
          var maxDur = d.max_dur_ms || 1;
          var newUids={}; d.nodes.forEach(function(n){ newUids[n.uid]=true; });
          var gaps=(d.time_markers||[]).map(function(marker){
            var hiatus=marker.gap_s>=300?'<i class="tl-hiatus">'+esc(fmtTraceSpan(marker.gap_s))+' gap</i>':'';
            return '<div class="tl-gap" style="left:'+marker.start_pct+'%"><span class="tl-gap-label"><b>'
              +esc(fmtTraceTime(marker.elapsed_s))+'</b>'+hiatus+'</span></div>';
          }).join('');
          traceRows.innerHTML=d.nodes.map(function(n){
            var kind = n.is_workflow ? 'workflow' : n.kind;   // workflow supersedes command
            var depth=Math.min(n.depth||0,5);
            var statusCls=(n.status==='error'||n.status==='dlq')?(' s-'+n.status):'';
            var dot=typeColor[kind]||'#6359D6';
            var handoff=n.cross_consumer
              ? '<span class="cross-handoff" title="handoff from '+esc(n.parent_consumer_label||n.parent_consumer)+' to '+esc(n.consumer_label||n.consumer)+'"><i style="background:'+esc(n.parent_accent||'#646970')+'"></i><b>&rarr;</b><i style="background:'+esc(n.accent||'#646970')+'"></i></span>'
              : '';
            var from=n.parent_label?'<div class="sfrom">&#8627; from <b>'+esc(n.parent_label)+'</b>'+handoff+'</div>':'';
            if(n.unresolved){ from+='<div class="trace-unresolved">recorded parent unresolved</div>'; }
            var barTxt=''; var latBar='';
            if(n.kind==='command' && n.raw && n.raw.duration_ms!=null){
              var ms=n.raw.duration_ms;
              barTxt=ms+'ms';
              var pct=Math.max(Math.round(ms/maxDur*100),ms>0?3:0);
              // Severity is ABSOLUTE (ms) — the slowest span in an all-fast trace must not read as failure.
              var latCls=ms>=1000?'slow':(ms>=300?'hot':'');
              latBar='<div class="lat-wrap"><span class="lat-track"><i class="'+latCls+'" style="width:'+pct+'%"></i></span><span class="lat-ms">'+ms+'ms</span></div>';
            }
            var isNew=Object.keys(_prevTraceNodes).length>0 && !_prevTraceNodes[n.uid];
            return '<div class="srow is-node d'+depth+(n.unresolved?' is-unresolved':'')+(isNew?' tddd-new':'')+'" data-uid="'+esc(n.uid)+'">'
              +'<div class="slabel" style="--owner-accent:'+esc(n.accent||'#646970')+'"><div class="snrow"><span class="sdot" style="background:'+dot+'"></span>'
              +'<span class="sname" title="'+esc(n.name)+'">'+shortName(n.name)+'</span><span class="stype">'+esc(kind)+'</span></div>'+from+latBar+'</div>'
              +'<div class="slane"><div class="sbar k-'+esc(kind)+statusCls+'" style="left:'+n.start_pct+'%;width:'+Math.max(n.width_pct,1)+'%">'+esc(barTxt)+'</div></div></div>';
          }).join('')
            + '<div class="tl-gaps"><div class="tl-gsp"></div><div class="tl-glane">'+gaps+'</div></div>';
          traceRows._nodes={}; d.nodes.forEach(function(n){ traceRows._nodes[n.uid]=n; });
          _prevTraceNodes=newUids;
          // Widen the lane when there are many spans so bars stay legible → horizontal scroll.
          var tlW = d.nodes.length*44 + 360;
          traceRows.style.minWidth = tlW+'px';
          ruler.parentNode.style.minWidth = tlW+'px';
        }
        // ── Render workflows nested in this trace ──
        var wfs=d.workflows||[];
        if(!wfs.length){ traceWf.innerHTML=''; return; }
        traceWf.innerHTML=wfs.map(function(w){
          var statusTxt=w.is_failed?'failed':(w.is_complete?'complete':'running');
          var badgeCls=w.is_failed?'failed':(w.is_complete?'complete':'running');
          var configs=(w.behaviour_configs||[]).map(cfgLabelTrace);
          var steps=configs.map(function(nm,i){
            var s=i<w.current_idx?'done':(i===w.current_idx&&!w.is_complete?'active':'pending');
            if(w.is_complete && i<configs.length) s='done';
            return '<div class="wft-step '+s+'"><div class="wsn">'+esc(nm)+'</div><div class="wss">phase '+(i+1)+'</div></div>';
          }).join('');
          var isFork=w.root_workflow_id?(' &middot; fork of #'+w.root_workflow_id):'';
          return '<div class="wf-in-trace" style="--owner-accent:'+esc(w.accent||'#646970')+'">'
            +'<div class="wft-head">'
            +'<span class="wft-label">workflow in trace</span>'
            +'<span class="trace-participant"><i style="background:'+esc(w.accent||'#646970')+'"></i><b>'+esc(w.consumer_label||w.consumer)+'</b></span>'
            +'<span class="wft-title">'+esc(w.ref_type)+' #'+w.ref_id+isFork+'</span>'
            +'<span class="wft-badge '+badgeCls+'">'+statusTxt+'</span>'
            +'<span class="wft-meta">wf id #'+w.id+' &middot; idx '+w.current_idx+'/'+configs.length+'</span>'
            +'</div>'
            +'<div class="wft-body">'
            +'<div class="lane-lbl" style="font-family:var(--fm);font-size:.56rem;text-transform:uppercase;letter-spacing:.1em;color:var(--faint);margin-bottom:8px">behaviour chain</div>'
            +'<div class="wft-chain">'+steps+'</div>'
            +'<div class="wft-kv"><span>id <b>#'+w.id+'</b></span><span>ref_type <b>'+esc(w.ref_type)+'</b></span><span>ref_id <b>'+w.ref_id+'</b></span>'
            +'<span>behaviours <b>'+configs.length+'</b></span>'
            +'<span>phase <b>'+w.current_phase+'</b></span>'
            +(w.root_workflow_id?'<span>root <b>#'+w.root_workflow_id+'</b></span>':'')
            +'</div>'
            +'</div>'
            +'<div class="wft-connector"><span class="wft-arrow">&#8594;</span> triggered by command in this trace &middot; correlation_id anchors the workflow</div>'
            +'</div>';
        }).join('');
      }
      function openTraceNode(n){
        var parent=n.parent_label
          ? esc(n.parent_label)+' <span class="idm">('+esc(n.parent_consumer_label||n.parent_consumer||'unknown')+')</span>'
          : '&mdash;';
        var touchLinks=(n.touches||[]).map(function(t){
          return '<button class="trace-biography-link" style="--owner-accent:'+esc(t.accent||n.accent||'#646970')+'" data-aggregate="'+esc(t.aggregate)+'" data-aggregate-id="'+esc(t.aggregate_id)+'" data-consumer="'+esc(t.consumer||n.consumer)+'">'
            +'<span>'+esc(t.aggregate)+'</span><b>'+esc(t.aggregate_id)+'</b><i>v'+t.version+' &middot; '+esc(t.op)+'</i></button>';
        }).join('');
        setDrawerLabel(n.kind);
        dbody.innerHTML='<h3>'+esc(n.name)+'</h3>'
          +'<div class="trace-owner" style="--owner-accent:'+esc(n.accent||'#646970')+'"><i></i><b>'+esc(n.consumer_label||n.consumer)+'</b><span>'+esc(n.consumer)+'</span></div>'
          +'<dl class="kv">'
          +'<dt>kind</dt><dd>'+esc(n.kind)+'</dd>'
          +'<dt>local id</dt><dd>'+esc(n.id)+'</dd>'
          +'<dt>status</dt><dd>'+esc(n.status||'&mdash;')+'</dd>'
          +'<dt>parent</dt><dd>'+parent+'</dd>'
          +'<dt>correlation</dt><dd><span class="corr-link" data-corr="'+esc(currentCorr||'')+'">'+esc(currentCorr||'&mdash;')+'</span></dd>'
          +'</dl>'
          +(touchLinks?'<div class="jlbl">aggregate biography</div><div class="trace-biography-links">'+touchLinks+'</div>':'')
          +'<div class="jlbl">recorded data</div>'+j(n.raw);
        drawer.hidden=false;
        var cl=dbody.querySelector('.corr-link');
        if(cl) cl.addEventListener('click', function(){ showTrace(this.dataset.corr); });
        dbody.querySelectorAll('.trace-biography-link').forEach(function(link){
          link.addEventListener('click',function(){ showBiography(this.dataset.aggregate,this.dataset.aggregateId,this.dataset.consumer); });
        });
      }
      traceRows.addEventListener('click', function(e){ var row=e.target.closest('.srow.is-node'); if(!row)return; var n=traceRows._nodes&&traceRows._nodes[row.dataset.uid]; if(n) openTraceNode(n); });

      // ── aggregate biography ──
      var biographyList=$('#tddd-biography-list'), biographyDetail=$('#tddd-biography-detail');
      var biographyRowsEl=$('#tddd-biography-rows'), biographyHead=$('#tddd-biography-head');
      var biographyTimeline=$('#tddd-biography-timeline'), biographyPager=$('#tddd-biography-pager');
      var biographyCount=$('#tddd-biography-count'), biographyRange=$('#tddd-biography-range');

      function biographyPagerHtml(page,pages){
        if(pages<=1) return '';
        var html='<button '+(page<=1?'disabled':'')+' data-bp="'+(page-1)+'">&lsaquo;</button>';
        for(var p=Math.max(1,page-1);p<=Math.min(pages,page+1);p++){
          html+='<button '+(p===page?'aria-current="true"':'')+' data-bp="'+p+'">'+p+'</button>';
        }
        return html+'<button '+(page>=pages?'disabled':'')+' data-bp="'+(page+1)+'">&rsaquo;</button>';
      }

      function biographyHash(consumer,aggregate,aggregateId){
        return 'biography/'+encodeURIComponent(consumer)+'/'+encodeURIComponent(aggregate)+'/'+encodeURIComponent(aggregateId);
      }

      function showBiographyRecent(){
        currentBiography=null;
        only('biography');
        biographyList.hidden=false;
        biographyDetail.hidden=true;
        loadBiographies();
      }

      function loadBiographies(){
        biographyRowsEl.innerHTML='<tr><td colspan="6" class="empty">Loading&hellip;</td></tr>';
        var qs=new URLSearchParams({
          consumer:state.consumer,
          search:biographyState.search,
          page:biographyState.page,
          per_page:biographyState.per_page
        });
        fetch(R.rest+'/biographies?'+qs.toString(),{headers:{'X-WP-Nonce':R.nonce}})
          .then(function(r){ return r.json(); })
          .then(renderBiographies)
          .catch(function(e){ biographyRowsEl.innerHTML='<tr><td colspan="6" class="empty">Error: '+esc(e.message)+'</td></tr>'; });
      }

      function renderBiographies(d){
        biographyRows=d.rows||[];
        if(d.available===false){
          biographyRowsEl.innerHTML='<tr><td colspan="6" class="empty">Biography data is not available for this consumer.</td></tr>';
        } else if(!biographyRows.length){
          biographyRowsEl.innerHTML='<tr><td colspan="6" class="empty">No aggregate touches match.</td></tr>';
        } else {
          biographyRowsEl.innerHTML=biographyRows.map(function(r,i){
            var versions=r.first_version===r.last_version ? ('v'+r.last_version) : ('v'+r.first_version+'&ndash;v'+r.last_version);
            return '<tr data-bi="'+i+'" tabindex="0">'
              +'<td><div class="bio-aggregate" title="'+esc(r.aggregate)+'">'+esc(r.aggregate)+'</div></td>'
              +'<td class="idm">'+esc(r.aggregate_id)+'</td>'
              +'<td class="idm">'+versions+'</td>'
              +'<td><span class="bio-count">'+r.touch_count+'</span></td>'
              +'<td><span class="bio-op op-'+esc(r.last_op)+'">'+esc(r.last_op)+'</span></td>'
              +'<td class="idm">'+rel(r.last_at)+'</td></tr>';
          }).join('');
        }
        biographyCount.textContent=d.total+' aggregates';
        var from=d.total?((d.page-1)*d.per_page+1):0, to=Math.min(d.page*d.per_page,d.total);
        biographyRange.innerHTML=from+'&ndash;'+to+' of '+d.total;
        biographyPager.innerHTML=biographyPagerHtml(d.page,d.pages);
      }

      function showBiography(aggregate,aggregateId,ownerConsumer,page){
        if(!aggregate||!aggregateId) return;
        page=Math.max(+page||1,1);
        if(ownerConsumer && keys.indexOf(ownerConsumer)!==-1 && ownerConsumer!==state.consumer){
          state.consumer=ownerConsumer;
          try{ localStorage.setItem('tddd_consumer',ownerConsumer); }catch(e){}
          syncConsumers();
        }
        if(currentBiography && currentBiography.consumer===state.consumer && currentBiography.aggregate===aggregate && currentBiography.aggregate_id===aggregateId && currentBiography.page===page && !views.biography.hidden) return;
        var biographyConsumer=state.consumer;
        currentBiography={consumer:biographyConsumer,aggregate:aggregate,aggregate_id:aggregateId,page:page};
        closeDrawer(); only('biography');
        biographyList.hidden=true; biographyDetail.hidden=false;
        var hash=biographyHash(biographyConsumer,aggregate,aggregateId);
        if(location.hash!=='#'+hash) location.hash=hash;
        biographyHead.innerHTML='<div class="bio-name">'+esc(aggregate)+'</div><div class="bio-id">'+esc(aggregateId)+'</div>';
        biographyTimeline.innerHTML='<div class="empty2">Loading&hellip;</div>';
        var qs=new URLSearchParams({consumer:state.consumer,aggregate:aggregate,aggregate_id:aggregateId,page:page});
        fetch(R.rest+'/biography?'+qs.toString(),{headers:{'X-WP-Nonce':R.nonce}})
          .then(function(r){ return r.json(); })
          .then(function(d){
            if(!currentBiography || currentBiography.consumer!==biographyConsumer || currentBiography.aggregate!==aggregate || currentBiography.aggregate_id!==aggregateId || currentBiography.page!==page) return;
            renderBiography(d);
          })
          .catch(function(e){ biographyTimeline.innerHTML='<div class="empty2">Error: '+esc(e.message)+'</div>'; });
      }

      function renderBiography(d){
        var s=d.summary||{};
        biographyHead.innerHTML='<div class="bio-heading"><div><div class="bio-name">'+esc(d.aggregate)+'</div><div class="bio-id">'+esc(d.aggregate_id)+'</div></div>'
          +'<div class="bio-stats"><span><b>'+esc(s.touch_count||0)+'</b> touches</span>'
          +'<span><b>'+(s.first_version==null?'&mdash;':('v'+s.first_version+'&ndash;v'+s.last_version))+'</b> retained</span>'
          +'<span><b>'+rel(s.last_at)+'</b> last change</span></div></div>';
        var entries=d.entries||[];
        biographyTimeline._entries=entries;
        if(d.available===false){ biographyTimeline.innerHTML='<div class="empty2">Biography data is not available for this consumer.</div>'; return; }
        if(!entries.length){ biographyTimeline.innerHTML='<div class="empty2">No retained touches for this aggregate.</div>'; return; }
        // Ledger pager — server pages the touch history in version order.
        var pager='';
        if((d.pages||0)>1){
          var pg=d.page||1, fromE=(pg-1)*(d.per_page||entries.length)+1, toE=fromE+entries.length-1;
          pager='<div class="bio-ledger-pager"><span class="blp-range">touches '+fromE+'&ndash;'+toE+' of '+(s.touch_count||0)+'</span>'
            +'<button '+(pg<=1?'disabled':'')+' data-bdp="'+(pg-1)+'">&lsaquo;</button>';
          for(var p=Math.max(1,pg-1);p<=Math.min(d.pages,pg+1);p++) pager+='<button '+(p===pg?'aria-current="true"':'')+' data-bdp="'+p+'">'+p+'</button>';
          pager+='<button '+(pg>=d.pages?'disabled':'')+' data-bdp="'+(pg+1)+'">&rsaquo;</button></div>';
        }
        biographyTimeline.innerHTML=pager+entries.map(function(e,i){
          var fact=e.event_type||e.event_name;
          var command=e.command_name||'command record unavailable';
          var trace=e.correlation_id?'<button class="bio-link" data-bio-trace="'+esc(e.correlation_id)+'">Trace</button>':'';
          return '<div class="biography-entry" data-be="'+i+'" tabindex="0">'
            +'<div class="bio-version"><b>v'+e.version+'</b><span>'+esc(e.op)+'</span></div>'
            +'<div class="bio-change"><div class="bio-event" title="'+esc(fact)+'">'+shortName(fact)+'</div>'
            +'<div class="bio-records"><span title="'+esc(e.event_id||'')+'">fact '+shortId(e.event_id)+'</span>'
            +'<span title="'+esc(e.command_id||'')+'">command '+shortName(command)+' &middot; '+shortId(e.command_id)+'</span></div></div>'
            +'<div class="bio-entry-meta"><span class="bio-op op-'+esc(e.op)+'">'+esc(e.op)+'</span><time>'+esc(e.occurred_at||'')+'</time>'+trace+'</div>'
            +'</div>';
        }).join('')+pager;
      }

      function openBiographyEntry(entry){
        var fact=entry.event_type||entry.event_name;
        setDrawerLabel('biography entry');
        dbody.innerHTML='<h3>'+esc(fact)+'</h3><dl class="kv">'
          +'<dt>version</dt><dd>v'+entry.version+' &middot; '+esc(entry.op)+'</dd>'
          +'<dt>fact</dt><dd>'+esc(fact)+'<br><span class="idm">'+esc(entry.event_id||'record unavailable')+'</span></dd>'
          +'<dt>fact status</dt><dd>'+esc(entry.event_status||'record unavailable')+'</dd>'
          +'<dt>command</dt><dd>'+esc(entry.command_name||'record unavailable')+'<br><span class="idm">'+esc(entry.command_id||'record unavailable')+'</span></dd>'
          +'<dt>command status</dt><dd>'+esc(entry.command_status||'record unavailable')+'</dd>'
          +'<dt>correlation</dt><dd>'+(entry.correlation_id?'<span class="corr-link" data-corr="'+esc(entry.correlation_id)+'">'+esc(entry.correlation_id)+'</span>':'&mdash;')+'</dd>'
          +'<dt>occurred</dt><dd>'+esc(entry.occurred_at||'&mdash;')+'</dd></dl>'
          +'<div class="jlbl">touch record</div>'+j(entry);
        drawer.hidden=false;
        var corr=dbody.querySelector('.corr-link'); if(corr) corr.addEventListener('click',function(){ showTrace(this.dataset.corr); });
      }

      function openBiographyRow(row){
        var record=biographyRows[+row.dataset.bi];
        if(record) location.hash=biographyHash(state.consumer,record.aggregate,record.aggregate_id);
      }
      biographyRowsEl.addEventListener('click',function(e){
        var row=e.target.closest('tr[data-bi]'); if(row) openBiographyRow(row);
      });
      biographyRowsEl.addEventListener('keydown',function(e){
        if(e.key!=='Enter'&&e.key!==' ') return;
        var row=e.target.closest('tr[data-bi]'); if(!row) return;
        e.preventDefault(); openBiographyRow(row);
      });
      biographyPager.addEventListener('click',function(e){
        var b=e.target.closest('button[data-bp]'); if(!b||b.disabled) return;
        biographyState.page=+b.dataset.bp; loadBiographies();
      });
      var biographySearch=$('#tddd-biography-search'), biographySearchTimer;
      biographySearch.addEventListener('input',function(){
        clearTimeout(biographySearchTimer);
        biographySearchTimer=setTimeout(function(){ biographyState.search=biographySearch.value.trim(); biographyState.page=1; loadBiographies(); },280);
      });
      $('#tddd-biography-back').addEventListener('click',function(){ currentBiography=null; location.hash='biography'; });
      biographyTimeline.addEventListener('click',function(e){
        var pageBtn=e.target.closest('button[data-bdp]');
        if(pageBtn){ if(!pageBtn.disabled && currentBiography){ showBiography(currentBiography.aggregate,currentBiography.aggregate_id,currentBiography.consumer,+pageBtn.dataset.bdp); } return; }
        var trace=e.target.closest('[data-bio-trace]');
        if(trace){ e.stopPropagation(); location.hash='trace/'+encodeURIComponent(trace.dataset.bioTrace); return; }
        var row=e.target.closest('.biography-entry[data-be]'); if(!row) return;
        var entry=biographyTimeline._entries&&biographyTimeline._entries[+row.dataset.be];
        if(entry) openBiographyEntry(entry);
      });
      biographyTimeline.addEventListener('keydown',function(e){
        if(e.key!=='Enter'&&e.key!==' ' || e.target.closest('button,a,input,select,textarea')) return;
        var row=e.target.closest('.biography-entry[data-be]'); if(!row) return;
        var entry=biographyTimeline._entries&&biographyTimeline._entries[+row.dataset.be];
        if(entry){ e.preventDefault(); openBiographyEntry(entry); }
      });

      // ── processes (sagas / workflows) ──
      var procSub='sagas';
      document.querySelectorAll('#tddd-view-proc .subtabs button').forEach(function(b){ b.addEventListener('click', function(){
        procSub=b.dataset.sub;
        document.querySelectorAll('#tddd-view-proc .subtabs button').forEach(function(x){ x.setAttribute('aria-selected', x===b); });
        $('#tddd-sub-sagas').hidden = procSub!=='sagas';
        $('#tddd-sub-workflows').hidden = procSub!=='workflows';
        loadProc();
      }); });
      function loadProc(){ if(procSub==='workflows') loadWorkflows(); else loadSagas(); }

      function sagaStepState(i, sidx, status){ if(i<sidx) return 'done'; if(i===sidx) return (status==='suspended'||status==='scheduled')?'wait':'active'; return 'pending'; }
      function loadSagas(){
        var el=$('#tddd-sagas'); el.innerHTML='<div class="empty2">Loading&hellip;</div>';
        fetch(R.rest+'/processes?consumer='+encodeURIComponent(state.consumer)+'&per_page=40',{headers:{'X-WP-Nonce':R.nonce}})
          .then(function(r){return r.json();}).then(function(d){ renderSagas(d); $('#tddd-proc-count').textContent=d.total+' sagas'; })
          .catch(function(e){ el.innerHTML='<div class="empty2">Error: '+esc(e.message)+'</div>'; });
      }
      function renderSagas(d){
        var el=$('#tddd-sagas');
        if(!d.rows.length){ el.innerHTML='<div class="empty2">No sagas.</div>'; return; }
        el.innerHTML=d.rows.map(function(p){
          var sj=p.steps||{}; var st=sj.steps||[]; var sidx=(sj.step_index!=null)?sj.step_index:p.step_index;
          var comps=sj.compensations||{}; var checks=sj.checkpoints||[];
          var seg=st.map(function(_,i){ var s=sagaStepState(i,sidx,p.status); return '<i class="'+(s==='done'?'done':((s==='active'||s==='wait')?'active':''))+'"></i>'; }).join('');
          var fwd=st.map(function(nm,i){ var s=sagaStepState(i,sidx,p.status); var ck=checks.indexOf(i)>=0?'<div class="ck">&#9873; checkpoint</div>':''; return '<div class="step '+s+'"><div class="sn">'+esc(nm)+'</div><div class="ss">'+s+'</div>'+ck+'</div>'; }).join('');
          var compNames=Object.keys(comps).map(function(k){return comps[k];});
          var comp = compNames.length ? '<div class="lane-lbl" style="margin-top:18px">compensation lane &middot; saga rollback</div><div class="flow comp">'+compNames.map(function(nm){return '<div class="step"><div class="sn">'+esc(nm)+'</div><div class="ss">undo</div></div>';}).join('')+'</div>' : '';
          var mc=p.match_criteria?esc(JSON.stringify(p.match_criteria)):'—';
          var sb=p.status==='failed'?'error':(p.status==='completed'?'success':'in_progress');
          return '<div class="prow" data-corr="'+esc(p.correlation_id||'')+'">'
            +'<div class="phead"><span class="chev">&#9656;</span>'
            +'<span class="badge b-'+sb+'">'+esc(p.status)+'</span>'
            +'<span class="pname">'+esc(p.process_class)+' #'+p.id+'</span>'
            +'<span class="seg">'+seg+'</span>'
            +'<span class="pmeta"><span>step '+sidx+'/'+st.length+'</span>'+(p.waiting_for?'<span>waiting: '+esc(p.waiting_for)+'</span>':'')+'<span>'+rel(p.updated_at)+'</span>'+(p.correlation_id?'<span class="pcorr" data-corr="'+esc(p.correlation_id)+'">'+esc(p.correlation_id.slice(0,8))+'</span>':'')+'</span></div>'
            +'<div class="pbody"><div class="lane-lbl">forward path</div><div class="flow">'+fwd+'</div>'+comp
            +'<div class="pkv"><span>type <b>long_process / saga</b></span><span>checkpoints <b>'+checks.length+'</b></span>'+(p.waiting_for?'<span>awaiting <b>'+esc(p.waiting_for)+'</b></span>':'')+'<span>match <b>'+mc+'</b></span>'+(p.last_error?'<span style="color:var(--crit)">error <b>'+esc(p.last_error)+'</b></span>':'')+'</div></div></div>';
        }).join('');
      }

      function cfgLabel(c){ return (typeof c==='string')?c:((c&&(c._class||c['class']||c.type))||'behaviour'); }
      function loadWorkflows(){
        var el=$('#tddd-workflows'); el.innerHTML='<div class="empty2">Loading&hellip;</div>';
        fetch(R.rest+'/workflows?consumer='+encodeURIComponent(state.consumer)+'&per_page=40',{headers:{'X-WP-Nonce':R.nonce}})
          .then(function(r){return r.json();}).then(function(d){ renderWorkflows(d); $('#tddd-proc-count').textContent=d.total+' workflows'; })
          .catch(function(e){ el.innerHTML='<div class="empty2">Error: '+esc(e.message)+'</div>'; });
      }
      function renderWorkflows(d){
        var el=$('#tddd-workflows');
        if(!d.rows.length){ el.innerHTML='<div class="empty2">No workflows.</div>'; return; }
        el.innerHTML=d.rows.map(function(w){
          var configs=(w.behaviour_configs||[]).map(cfgLabel);
          var statusTxt=w.is_failed?'failed':(w.is_complete?'complete':'running');
          var sb=w.is_failed?'error':(w.is_complete?'success':'in_progress');
          var its=w.items||[]; var idone=its.filter(function(i){return i.status==='done';}).length; var itot=its.length; var ipct=itot?Math.round(idone/itot*100):0;
          var steps=configs.map(function(nm,i){ var s=i<w.current_idx?'done':(i===w.current_idx?'active':'pending'); return '<div class="step '+s+'"><div class="sn">'+esc(nm)+'</div><div class="ss">phase '+(i+1)+'</div></div>'; }).join('');
          var items=(w.items||[]).map(function(it){ return '<span class="item"><span class="idot '+esc(it.status)+'"></span><span class="ik">'+esc(it.item_key)+'</span>'+(it.attempts?'<span style="color:var(--faint)">&times;'+it.attempts+'</span>':'')+'</span>'; }).join('');
          var forks=(w.forks||[]).map(function(f){ return '<div class="fork">&#8627; fork #'+f.id+' &middot; '+(f.is_failed?'failed':(f.is_complete?'complete':'running'))+' &middot; idx '+f.current_idx+'</div>'; }).join('');
          var isFork=w.root_workflow_id?(' <span style="color:var(--coral-ink)">(fork of #'+w.root_workflow_id+')</span>'):'';
          return '<div class="prow">'
            +'<div class="phead"><span class="chev">&#9656;</span>'
            +'<span class="badge b-'+sb+'">'+statusTxt+'</span>'
            +'<span class="pname">'+esc(w.ref_type)+' #'+w.ref_id+isFork+'</span>'
            +'<span class="wbar" title="work-items done"><span class="wbar-t"><i style="width:'+ipct+'%"></i></span><span class="wbar-n">'+idone+'/'+itot+'</span></span>'
            +'<span class="pmeta"><span>idx '+w.current_idx+'/'+configs.length+'</span><span>phase '+w.current_phase+'</span>'+(w.forks.length?'<span>'+w.forks.length+' fork'+(w.forks.length>1?'s':'')+'</span>':'')+'<span>'+rel(w.updated_at)+'</span></span></div>'
            +'<div class="pbody"><div class="lane-lbl">behaviour chain</div><div class="flow">'+steps+'</div>'
            +'<div class="lane-lbl" style="margin-top:14px">work-item ledger ('+(w.items||[]).length+')</div><div class="items">'+(items||'<span style="color:var(--faint)">no items</span>')+'</div>'
            +(forks?'<div class="lane-lbl" style="margin-top:14px">forks</div><div class="forks">'+forks+'</div>':'')
            +'</div></div>';
        }).join('');
      }

      $('#tddd-view-proc').addEventListener('click', function(e){
        if(e.target.closest('.subtabs')) return;
        var corr=e.target.closest('.pcorr'); if(corr&&corr.dataset.corr){ e.stopPropagation(); location.hash='trace/'+encodeURIComponent(corr.dataset.corr); return; }
        var head=e.target.closest('.phead'); if(head){ head.parentElement.classList.toggle('open'); }
      });

      // ── live (heartbeat) ──
      function liveLine(r){
        var t=(r.started_at||'').slice(11);
        return '<div class="tl new"><span class="tt">'+esc(t)+'</span><span class="co" data-corr="'+esc(r.correlation_id||'')+'">'+esc((r.correlation_id||'').slice(0,8))+'</span><span class="nm" title="'+esc(r.command_name)+'">'+shortName(r.command_name)+'</span><span class="st '+esc(r.status)+'">'+esc(r.status)+'</span><span class="dr">'+(r.duration_ms||0)+'ms</span></div>';
      }
      var _vitalsHbCount=0;
      function onTick(d){
        var lines=$('#tddd-live-lines'), badge=$('#tddd-live-badge');
        if(d.counts) badge.innerHTML='dlq '+d.counts.dlq+' &middot; outbox '+d.counts.outbox;
        if(d.cursor) liveCursor=Math.max(liveCursor, d.cursor);
        // Only update the live bus console when Flow view is active.
        if(!views.flow.hidden && d.rows&&d.rows.length){ lines.insertAdjacentHTML('afterbegin', d.rows.map(liveLine).join('')); while(lines.children.length>60) lines.removeChild(lines.lastChild); }
        // Refresh vitals on every 3rd heartbeat tick (fast≈5s → ~15s cadence)
        _vitalsHbCount++; if(_vitalsHbCount%3===0){ loadVitals(); }

        // ── B. Live-poll open trace ──
        if(!views.trace.hidden && currentCorr && traceOpen && !traceOpen.hidden){
          var pollCorr=currentCorr;
          fetch(R.rest+'/trace/'+encodeURIComponent(pollCorr)+'?consumer='+encodeURIComponent(state.consumer),{headers:{'X-WP-Nonce':R.nonce}})
            .then(function(r){ return r.json(); })
            .then(function(data){
              if(pollCorr!==currentCorr) return; // navigation happened during fetch
              // Preserve scroll position.
              var scrollEl=traceRows.closest('.panel')||traceRows.parentElement;
              var savedScroll=scrollEl?scrollEl.scrollTop:0;
              // Preserve open <details> (currently none in trace, but defensive).
              var openDetails=[];
              traceRows.querySelectorAll('details[open]').forEach(function(dt,i){ openDetails.push(dt.dataset.key||i); });
              renderTrace(data);
              // Restore scroll.
              if(scrollEl) scrollEl.scrollTop=savedScroll;
              // Restore details (key-matched).
              traceRows.querySelectorAll('details').forEach(function(dt,i){
                var key=dt.dataset.key||i; if(openDetails.indexOf(key)>=0) dt.open=true;
              });
            })
            .catch(function(){}); // silent — polling; next tick will retry
        }

        // ── C. Live-update recent-traces list ──
        // Re-bucket the whole list each tick (LIVE / RECENTLY FINISHED) against the clock so
        // traces age out, finished→live re-activations flash, and new corrs appear. Skip the
        // full re-render while the user is mid-scroll to avoid yanking their position.
        if(!views.trace.hidden && !currentCorr && traceRecent && !traceRecent.hidden){
          fetch(R.rest+'/traces?consumer='+encodeURIComponent(state.consumer),{headers:{'X-WP-Nonce':R.nonce}})
            .then(function(r){ return r.json(); })
            .then(function(rows){
              var container=trcList?trcList.parentElement:null;
              if(container && container.scrollTop>60){ prependNewToRecentList(rows); }
              else { renderRecentTraces(rows, true); }
            })
            .catch(function(){}); // silent
        }
      }
      function startLive(speed){
        if(speed!==undefined) heartbeatSpeed=speed;
        var badge=$('#tddd-live-badge');
        if(!(window.jQuery && window.wp && wp.heartbeat)){
          // Heartbeat enqueues in the footer; on first paint it may not be ready yet — retry briefly.
          startLive._t = (startLive._t || 0) + 1;
          if(startLive._t < 25){ setTimeout(function(){ startLive(); }, 300); return; }
          badge.textContent='(heartbeat unavailable)'; return;
        }
        startLive._t = 0;
        if(!liveStarted){
          liveStarted=true;
          jQuery(document).on('heartbeat-send', function(e,data){
            // Always send a minimal payload so onTick fires (trace/recent-list polling also needs it).
            data.tangible_ddd={consumer:state.consumer, cursor: (!views.flow.hidden ? liveCursor : 0)};
          });
          jQuery(document).on('heartbeat-tick', function(e,data){ if(data.tangible_ddd) onTick(data.tangible_ddd); });
        }
        liveCursor=0; $('#tddd-live-lines').innerHTML='';
        wp.heartbeat.interval(heartbeatSpeed);
        wp.heartbeat.connectNow();
      }
      $('#tddd-live-lines').addEventListener('click', function(e){ var c=e.target.closest('.co[data-corr]'); if(c && c.dataset.corr) location.hash='trace/'+encodeURIComponent(c.dataset.corr); });

      // consumer switch → reset + re-render current view + refresh vitals
      cz.addEventListener('click', function(e){ if(!e.target.closest('button')) return; auditLoaded=false; liveCursor=0; var ll=$('#tddd-live-lines'); if(ll) ll.innerHTML=''; loadVitals(); setTimeout(function(){
        if(!views.audit.hidden){ auditLoaded=true; load(); }
        else if(!views.flow.hidden){ loadFlow(); startLive('fast'); }
        else if(!views.proc.hidden){ loadProc(); }
        else if(!tablesEl.hidden){ loadTables(); }
        else if(!views.biography.hidden){ currentBiography=null; biographyState.page=1; location.hash='biography'; showBiographyRecent(); }
        else if(!views.trace.hidden && currentCorr){ var c=currentCorr; currentCorr=null; _prevTraceNodes={}; showTrace(c); }
        else if(!views.trace.hidden && !currentCorr){ _recentCorrs=[]; _pendingNewCorrs=[]; showTraceRecent(); }
      },0); });

      function routeHash(){
        var m=/^#trace\/(.+)$/.exec(location.hash);
        if(m){ showTrace(decodeURIComponent(m[1])); return; }
        var biographyOwnedMatch=/^#biography\/([^/]+)\/([^/]+)\/(.+)$/.exec(location.hash);
        if(biographyOwnedMatch && keys.indexOf(decodeURIComponent(biographyOwnedMatch[1]))!==-1){
          showBiography(
            decodeURIComponent(biographyOwnedMatch[2]),
            decodeURIComponent(biographyOwnedMatch[3]),
            decodeURIComponent(biographyOwnedMatch[1])
          );
          return;
        }
        var biographyMatch=/^#biography\/([^/]+)\/(.+)$/.exec(location.hash);
        if(biographyMatch){ showBiography(decodeURIComponent(biographyMatch[1]),decodeURIComponent(biographyMatch[2])); return; }
        var v=(location.hash||'#flow').replace(/^#/, '');
        // #trace with no corr → show recent list
        if(v==='trace'){ only('trace'); setNav('trace'); if(!currentCorr){ showTraceRecent(); } return; }
        if(v==='biography'){ currentBiography=null; showBiographyRecent(); return; }
        if(!views[v]) v='flow';
        showView(v);
      }
      window.addEventListener('hashchange', routeHash);
      if(requestedCorrelation && !location.hash){ location.hash='trace/'+encodeURIComponent(requestedCorrelation); }
      routeHash();
    })();
