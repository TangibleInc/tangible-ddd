<?php
/**
 * Plugin Name: TangibleDDDash (prototype)
 * Description: Non-hydrated multi-lens trace/observatory dashboard for tangible-ddd. Tools → TangibleDDDash. Mock data; JS renderer mirrors a real trace query so hydration is a drop-in later.
 * Version: 0.0.2-proto
 */

if (!defined('ABSPATH')) { exit; }

add_action('admin_menu', function () {
  add_management_page('TangibleDDDash','TangibleDDDash','manage_options','tangible-dddash','tgbl_dddash_render_page');
});

function tgbl_dddash_render_page() {
  echo <<<'DDDASH'
<div id="dddash">
  <style>
  #dddash{
    --ground:#0c111e;--panel:#141b2d;--panel2:#1a2236;--line:#28324c;--line2:#1e2740;
    --text:#e7ebf6;--muted:#97a1c0;--faint:#646f8f;
    --command:#f5b840;--event:#4cc4f0;--process:#b08cf8;--workflow:#3ddc97;
    --corr:#f871a0;--error:#fb6f6f;--external:#7d8aa8;
    --mono:ui-monospace,"SF Mono",Menlo,"Cascadia Code",monospace;
    --sans:-apple-system,BlinkMacSystemFont,"SF Pro Text",system-ui,sans-serif;
    position:relative;margin:-10px -20px -65px -22px;min-height:calc(100vh - 32px);
    background:radial-gradient(1100px 520px at 82% -8%,#15203a 0,transparent 60%),radial-gradient(820px 460px at -8% 8%,#161427 0,transparent 55%),var(--ground);
    color:var(--text);font-family:var(--sans);font-size:14px;line-height:1.55;-webkit-font-smoothing:antialiased;
  }
  #dddash *{box-sizing:border-box;}
  #dddash code,#dddash .mono{font-family:var(--mono);}
  #dddash a{color:inherit;}

  /* top bar */
  #dddash .bar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:16px 26px 0;}
  #dddash h1{font-size:18px;font-weight:800;letter-spacing:-.02em;margin:0;display:flex;align-items:center;gap:11px;}
  #dddash h1 .thread{width:11px;height:11px;border-radius:3px;background:var(--corr);box-shadow:0 0 10px var(--corr);}
  #dddash .proto{font-family:var(--mono);font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--faint);border:1px solid var(--line);border-radius:999px;padding:3px 9px;}
  #dddash .stats{display:flex;gap:20px;margin-left:auto;flex-wrap:wrap;}
  #dddash .stat{display:flex;flex-direction:column;gap:1px;cursor:pointer;}
  #dddash .stat b{font-family:var(--mono);font-size:16px;font-weight:700;font-variant-numeric:tabular-nums;}
  #dddash .stat span{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);}
  #dddash .stat.dlq b{color:var(--error);}

  /* nav */
  #dddash .nav{display:flex;gap:4px;padding:14px 26px 0;border-bottom:1px solid var(--line);}
  #dddash .nav button{background:none;border:none;border-bottom:2px solid transparent;color:var(--muted);font-family:var(--sans);font-size:13.5px;font-weight:600;padding:8px 14px 12px;cursor:pointer;display:flex;align-items:center;gap:7px;}
  #dddash .nav button:hover{color:var(--text);}
  #dddash .nav button.on{color:var(--text);border-bottom-color:var(--corr);}
  #dddash .nav button .d{width:8px;height:8px;border-radius:2px;}

  #dddash .view{padding:18px 26px 40px;}
  #dddash .crumbs{font-family:var(--mono);font-size:11px;color:var(--faint);margin-bottom:14px;}
  #dddash .crumbs a{color:var(--corr);cursor:pointer;text-decoration:none;}
  #dddash .crumbs a:hover{text-decoration:underline;}

  /* generic cards */
  #dddash .cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px;}
  #dddash .card{background:var(--panel);border:1px solid var(--line);border-radius:11px;padding:16px 18px;display:flex;flex-direction:column;gap:12px;}
  #dddash .card .ch{display:flex;align-items:center;gap:9px;}
  #dddash .card .ch .d{width:9px;height:9px;border-radius:2px;}
  #dddash .card .ch .t{font-weight:700;font-size:13.5px;letter-spacing:-.01em;}
  #dddash .card .ch .more{margin-left:auto;font-family:var(--mono);font-size:10.5px;color:var(--corr);cursor:pointer;}
  #dddash .card .ch .more:hover{text-decoration:underline;}
  #dddash .figs{display:flex;gap:18px;flex-wrap:wrap;}
  #dddash .fig{display:flex;flex-direction:column;}
  #dddash .fig b{font-family:var(--mono);font-size:20px;font-weight:700;font-variant-numeric:tabular-nums;letter-spacing:-.02em;}
  #dddash .fig span{font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);}
  #dddash .fig.bad b{color:var(--error);} #dddash .fig.warn b{color:var(--command);} #dddash .fig.good b{color:var(--workflow);}
  #dddash .spark{display:flex;align-items:flex-end;gap:2px;height:30px;}
  #dddash .spark i{width:5px;background:linear-gradient(180deg,var(--event),#28618a);border-radius:1px;opacity:.85;}

  /* mini rows inside cards */
  #dddash .mini{display:flex;flex-direction:column;gap:2px;}
  #dddash .mr{display:flex;align-items:center;gap:9px;padding:6px 8px;border-radius:7px;cursor:pointer;font-size:12.5px;}
  #dddash .mr:hover{background:var(--panel2);}
  #dddash .mr .nm{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  #dddash .mr .sp{margin-left:auto;display:flex;gap:9px;align-items:center;}
  #dddash .mono-s{font-family:var(--mono);font-size:11px;color:var(--faint);font-variant-numeric:tabular-nums;}
  #dddash .cid{font-family:var(--mono);font-size:11px;color:var(--corr);}
  #dddash .ref{font-family:var(--mono);font-size:11px;color:var(--process);}
  #dddash .pill{font-family:var(--mono);font-size:9.5px;letter-spacing:.04em;padding:2px 7px;border-radius:999px;font-weight:600;white-space:nowrap;}
  #dddash .pill.ok{color:#9af0c6;background:rgba(61,220,151,.12);border:1px solid rgba(61,220,151,.35);}
  #dddash .pill.fail{color:#ffb3b3;background:rgba(251,111,111,.12);border:1px solid rgba(251,111,111,.4);}
  #dddash .pill.run{color:#ffe0a3;background:rgba(245,184,64,.12);border:1px solid rgba(245,184,64,.38);}
  #dddash .pill.susp{color:#cdbcf7;background:rgba(176,140,248,.12);border:1px solid rgba(176,140,248,.4);}

  /* TRACE mode */
  #dddash .tgrid{display:grid;grid-template-columns:268px 1fr;gap:0;border:1px solid var(--line);border-radius:11px;overflow:hidden;}
  @media (max-width:1080px){#dddash .tgrid{grid-template-columns:1fr;}}
  #dddash .rail{border-right:1px solid var(--line);padding:12px 10px;display:flex;flex-direction:column;gap:7px;background:rgba(20,27,45,.4);}
  #dddash .rail .rh{font-family:var(--mono);font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--faint);padding:4px 8px 6px;}
  #dddash .trace-item{text-align:left;cursor:pointer;background:var(--panel);border:1px solid var(--line);border-radius:9px;padding:10px 12px;color:var(--text);font-family:var(--sans);display:flex;flex-direction:column;gap:6px;}
  #dddash .trace-item:hover{border-color:#3a4869;background:var(--panel2);}
  #dddash .trace-item.active{border-color:var(--corr);background:linear-gradient(180deg,rgba(248,113,160,.08),transparent);}
  #dddash .trace-item .root{font-weight:650;font-size:12.5px;letter-spacing:-.01em;}
  #dddash .trace-item .meta{display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
  #dddash .trace-item .nums{font-family:var(--mono);font-size:10.5px;color:var(--faint);display:flex;gap:10px;}

  #dddash .main{min-width:0;display:flex;flex-direction:column;}
  #dddash .main-head{padding:14px 20px 10px;border-bottom:1px solid var(--line2);display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;}
  #dddash .main-head .tt{font-size:15px;font-weight:700;letter-spacing:-.01em;}
  #dddash .main-head .sub{font-family:var(--mono);font-size:11px;color:var(--faint);}
  #dddash .legend{display:flex;gap:5px 14px;flex-wrap:wrap;margin-left:auto;}
  #dddash .lk{display:inline-flex;align-items:center;gap:6px;font-size:10.5px;color:var(--muted);}
  #dddash .lk i{width:8px;height:8px;border-radius:2px;}
  #dddash .wf-scroll{overflow-x:auto;padding:8px 0 18px;}
  #dddash .wf{min-width:740px;}
  #dddash .axis{display:grid;grid-template-columns:var(--lw) 1fr;--lw:320px;padding:6px 20px 8px;}
  #dddash .axis .ticks{position:relative;height:14px;border-bottom:1px dashed var(--line);}
  #dddash .axis .ticks span{position:absolute;transform:translateX(-50%);font-family:var(--mono);font-size:10px;color:var(--faint);}
  #dddash .lane-head{display:flex;align-items:center;gap:8px;padding:8px 20px 3px;margin-top:5px;}
  #dddash .lane-head .lh-dot{width:10px;height:10px;border-radius:3px;background:var(--workflow);}
  #dddash .lane-head .lh-t{font-weight:700;font-size:12px;}
  #dddash .lane-head .lh-s{font-family:var(--mono);font-size:10.5px;color:var(--faint);}
  #dddash .lane{border-left:2px solid rgba(61,220,151,.4);margin:0 20px 4px;background:linear-gradient(90deg,rgba(61,220,151,.05),transparent 60%);border-radius:0 8px 8px 0;}
  #dddash .row{display:grid;grid-template-columns:var(--lw) 1fr;--lw:320px;align-items:center;padding:2px 20px;min-height:29px;cursor:pointer;border-radius:6px;}
  #dddash .lane .row{padding:2px 14px;--lw:312px;}
  #dddash .row:hover{background:rgba(255,255,255,.025);}
  #dddash .row.sel{background:rgba(248,113,160,.09);box-shadow:inset 2px 0 0 var(--corr);}
  #dddash .lbl{display:flex;align-items:center;gap:7px;white-space:nowrap;overflow:hidden;padding-right:12px;}
  #dddash .lbl .tag{width:8px;height:8px;border-radius:2px;flex:none;}
  #dddash .lbl .nm{font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;}
  #dddash .lbl .sid{font-family:var(--mono);font-size:10px;color:var(--faint);flex:none;}
  #dddash .lbl .ob{font-family:var(--mono);font-size:9px;letter-spacing:.08em;color:var(--external);border:1px solid var(--line2);border-radius:4px;padding:0 4px;flex:none;}
  #dddash .track{position:relative;height:19px;}
  #dddash .b{position:absolute;top:2px;height:15px;border-radius:4px;display:flex;align-items:center;padding:0 7px;font-family:var(--mono);font-size:9.5px;font-weight:700;color:#0a0e17;overflow:hidden;white-space:nowrap;}
  #dddash .b.command{background:var(--command);}
  #dddash .b.event{background:linear-gradient(90deg,var(--event),#2f9fcc);color:#04131c;}
  #dddash .b.workflow{background:var(--workflow);color:#052418;}
  #dddash .b.external{background:repeating-linear-gradient(45deg,#34405c,#34405c 5px,#2b364f 5px,#2b364f 10px);color:#c5cee6;border:1px solid var(--line);}
  #dddash .b.wait{background:repeating-linear-gradient(45deg,#233049,#233049 6px,#1b2740 6px,#1b2740 12px);color:var(--muted);border:1px solid var(--line);font-weight:600;}
  #dddash .b.dlq{background:var(--error);color:#2a0808;}
  #dddash .b.warn{box-shadow:0 0 0 1.5px var(--command),0 0 9px rgba(245,184,64,.5);}
  #dddash .b.fail{box-shadow:0 0 0 1.5px var(--error),0 0 9px rgba(251,111,111,.55);}
  #dddash .b .beh{margin-left:7px;opacity:.78;font-weight:600;}
  #dddash .cause{grid-column:2;font-family:var(--mono);font-size:10px;color:var(--faint);padding:0 0 3px 1px;}
  #dddash .cause b{color:var(--muted);font-weight:600;} #dddash .cause .ar{color:var(--faint);}
  #dddash .drawer{border-top:1px solid var(--line);background:var(--panel);padding:15px 20px 20px;min-height:108px;}
  #dddash .drawer .dh{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap;}
  #dddash .drawer .dh .tag{width:11px;height:11px;border-radius:3px;}
  #dddash .drawer .dh .nm{font-weight:700;font-size:13.5px;}
  #dddash .drawer .dh .lnk{margin-left:auto;display:flex;gap:8px;}
  #dddash .xlink{font-family:var(--mono);font-size:10.5px;color:var(--corr);border:1px solid rgba(248,113,160,.35);border-radius:6px;padding:3px 9px;cursor:pointer;}
  #dddash .xlink.ent{color:var(--process);border-color:rgba(176,140,248,.4);}
  #dddash .xlink:hover{background:rgba(255,255,255,.04);}
  #dddash .kv{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:9px 20px;}
  #dddash .kv .k{font-family:var(--mono);font-size:9.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);}
  #dddash .kv .v{font-family:var(--mono);font-size:11.5px;color:var(--text);word-break:break-word;}
  #dddash .kv .v.q{color:var(--workflow);} #dddash .kv .v.warn{color:var(--command);}
  #dddash .drawer .empty{color:var(--faint);font-size:12.5px;}

  /* ENTITY mode */
  #dddash .ent-pick{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;}
  #dddash .ent-pick button{background:var(--panel);border:1px solid var(--line);border-radius:9px;padding:9px 13px;color:var(--text);cursor:pointer;font-family:var(--sans);display:flex;flex-direction:column;gap:3px;text-align:left;}
  #dddash .ent-pick button.on{border-color:var(--process);background:linear-gradient(180deg,rgba(176,140,248,.1),transparent);}
  #dddash .ent-pick .r{font-family:var(--mono);font-size:12px;color:var(--process);font-weight:700;}
  #dddash .ent-pick .s{font-size:11px;color:var(--faint);}
  #dddash .ent-head{display:flex;align-items:center;gap:14px;padding:16px 18px;background:var(--panel);border:1px solid var(--line);border-radius:11px;margin-bottom:16px;flex-wrap:wrap;}
  #dddash .ent-head .big{font-family:var(--mono);font-size:18px;font-weight:800;color:var(--process);}
  #dddash .ent-head .meta{font-size:12px;color:var(--muted);}
  #dddash .ent-cols{display:grid;grid-template-columns:1.4fr 1fr;gap:16px;}
  @media (max-width:1080px){#dddash .ent-cols{grid-template-columns:1fr;}}
  #dddash .timeline{display:flex;flex-direction:column;gap:0;position:relative;padding-left:18px;}
  #dddash .timeline:before{content:"";position:absolute;left:5px;top:6px;bottom:6px;width:2px;background:var(--line);}
  #dddash .tl{position:relative;padding:11px 0 11px 14px;cursor:pointer;border-radius:7px;}
  #dddash .tl:hover{background:var(--panel2);}
  #dddash .tl:before{content:"";position:absolute;left:-16px;top:16px;width:10px;height:10px;border-radius:50%;background:var(--corr);box-shadow:0 0 0 3px var(--ground);}
  #dddash .tl.fail:before{background:var(--error);} #dddash .tl.ok:before{background:var(--workflow);}
  #dddash .tl .when{font-family:var(--mono);font-size:10.5px;color:var(--faint);}
  #dddash .tl .what{font-weight:650;font-size:13px;margin:2px 0 4px;}
  #dddash .tl .det{display:flex;gap:9px;align-items:center;flex-wrap:wrap;}

  /* TABLES mode */
  #dddash .tabnav{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;}
  #dddash .tabnav button{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:7px 12px;color:var(--muted);font-family:var(--mono);font-size:11.5px;cursor:pointer;}
  #dddash .tabnav button.on{color:var(--text);border-color:var(--corr);}
  #dddash .gridscroll{overflow-x:auto;border:1px solid var(--line);border-radius:11px;}
  #dddash table{border-collapse:collapse;width:100%;min-width:720px;font-size:12.5px;}
  #dddash thead th{text-align:left;font-family:var(--mono);font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);font-weight:600;padding:11px 14px;background:var(--panel2);border-bottom:1px solid var(--line);white-space:nowrap;}
  #dddash tbody td{padding:10px 14px;border-bottom:1px solid var(--line2);font-family:var(--mono);font-size:11.5px;white-space:nowrap;}
  #dddash tbody tr:hover{background:rgba(255,255,255,.02);}
  #dddash td .lk{color:var(--corr);cursor:pointer;} #dddash td .lk:hover{text-decoration:underline;}
  #dddash td .lk.ref{color:var(--process);} #dddash td .lk.cmd{color:var(--command);}
  #dddash .hint{font-family:var(--mono);font-size:10.5px;color:var(--faint);margin-top:10px;}

  /* ---- jazz: transitions, guided toasts, hover popovers, arrival pulse ---- */
  #dddash .view{animation:dd-fade .26s cubic-bezier(.2,.7,.3,1);}
  @keyframes dd-fade{from{opacity:0;transform:translateY(7px);}to{opacity:1;transform:none;}}
  #dddash .nav button .d{transition:transform .15s, box-shadow .15s;}
  #dddash .nav button.on .d{transform:scale(1.25);box-shadow:0 0 9px currentColor;}

  /* toast that narrates a hop */
  #dd-toast{position:fixed;left:50%;bottom:34px;transform:translateX(-50%) translateY(20px);z-index:99999;
    display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:12px;
    background:rgba(16,22,38,.92);backdrop-filter:blur(8px);border:1px solid var(--line);
    box-shadow:0 12px 40px rgba(0,0,0,.5);color:#e7ebf6;font-family:var(--mono);font-size:12px;
    opacity:0;pointer-events:none;transition:opacity .22s, transform .22s cubic-bezier(.2,.8,.2,1);}
  #dd-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
  #dd-toast .beam{width:9px;height:9px;border-radius:50%;animation:dd-beam 1s ease-in-out infinite;}
  @keyframes dd-beam{0%,100%{box-shadow:0 0 0 0 currentColor;opacity:.6;}50%{box-shadow:0 0 0 5px transparent;opacity:1;}}
  #dd-toast b{font-weight:700;} #dd-toast .arr{color:var(--faint);}

  /* hover popover */
  #dd-pop{position:fixed;z-index:99998;max-width:300px;padding:9px 11px;border-radius:9px;
    background:rgba(16,22,38,.96);backdrop-filter:blur(6px);border:1px solid var(--line);
    box-shadow:0 10px 30px rgba(0,0,0,.5);font-family:var(--mono);font-size:11px;color:var(--text);
    opacity:0;pointer-events:none;transform:translateY(4px);transition:opacity .12s, transform .12s;}
  #dd-pop.show{opacity:1;transform:none;}
  #dd-pop .pt{font-weight:700;margin-bottom:3px;display:flex;align-items:center;gap:6px;}
  #dd-pop .pt i{width:8px;height:8px;border-radius:2px;}
  #dd-pop .pl{color:var(--faint);}
  #dd-pop .pl b{color:var(--muted);font-weight:600;}

  /* arrival pulse */
  @keyframes dd-pulse{0%{box-shadow:0 0 0 0 rgba(248,113,160,.55);}70%{box-shadow:0 0 0 12px rgba(248,113,160,0);}100%{box-shadow:0 0 0 0 rgba(248,113,160,0);}}
  #dddash .pulse{animation:dd-pulse 1.1s ease-out 1;border-radius:9px;}
  @keyframes dd-pulse-v{0%{box-shadow:0 0 0 0 rgba(176,140,248,.5);}70%{box-shadow:0 0 0 14px rgba(176,140,248,0);}100%{box-shadow:0 0 0 0 rgba(176,140,248,0);}}
  #dddash .pulse-v{animation:dd-pulse-v 1.1s ease-out 1;border-radius:9px;}
  @media (prefers-reduced-motion:reduce){#dddash .view{animation:none;}#dd-toast{transition:opacity .15s;}#dddash .pulse,#dddash .pulse-v{animation:none;}}
  </style>

  <div class="bar">
    <h1><span class="thread"></span> TangibleDDDash <span class="proto">prototype · mock</span></h1>
    <div class="stats" id="dd-stats"></div>
  </div>
  <div class="nav" id="dd-nav"></div>
  <div class="view" id="dd-view"></div>

  <script>
  (function(){
    var $=function(t,c,h){var e=document.createElement(t);if(c)e.className=c;if(h!=null)e.innerHTML=h;return e;};
    var COLORS={command:'var(--command)',event:'var(--event)',workflow:'var(--workflow)',external:'var(--external)',wait:'var(--external)',dlq:'var(--error)'};
    function pill(s){return '<span class="pill '+s+'">'+(s==='ok'?'done':s==='fail'?'failed':s==='run'?'running':s==='susp'?'suspended':s)+'</span>';}
    function spark(arr){var m=Math.max.apply(null,arr);var s=$('div','spark');arr.forEach(function(v){var i=$('i');i.style.height=Math.max(3,Math.round(v/m*30))+'px';s.appendChild(i);});return s;}
    // jazz helpers
    var _toast=null,_pop=null,_tt=null;
    function toast(msg,color){if(!_toast){_toast=document.createElement('div');_toast.id='dd-toast';document.body.appendChild(_toast);}var c=color||'var(--corr)';_toast.innerHTML='<span class="beam" style="background:'+c+';color:'+c+'"></span><span>'+msg+'</span>';_toast.classList.add('show');clearTimeout(_tt);_tt=setTimeout(function(){_toast.classList.remove('show');},2600);}
    function popEl(){if(!_pop){_pop=document.createElement('div');_pop.id='dd-pop';document.body.appendChild(_pop);}return _pop;}
    function showPop(html,x,y){var e=popEl();e.innerHTML=html;e.style.left=Math.min(x+14,window.innerWidth-320)+'px';e.style.top=(y+16)+'px';e.classList.add('show');}
    function movePop(x,y){if(_pop&&_pop.classList.contains('show')){_pop.style.left=Math.min(x+14,window.innerWidth-320)+'px';_pop.style.top=(y+16)+'px';}}
    function hidePop(){if(_pop)_pop.classList.remove('show');}
    function pulse(el,variant){if(!el)return;var v=variant||'pulse';el.classList.add(v);setTimeout(function(){el.classList.remove('pulse');el.classList.remove('pulse-v');},1200);}

    /* ===================== MOCK DATA ===================== */
    var STATS=[{n:'142',l:'traces·24h',go:['flow']},{n:'1,884',l:'commands',go:['tables','command_audit']},{n:'2,310',l:'events',go:['tables','integration_outbox']},{n:'7',l:'workflows',go:['flow']},{n:'3',l:'in DLQ',dlq:true,go:['flow']}];

    // traces keyed by id; cid = correlation, ref entities touched
    var TRACES={
      t1:{id:'t1',cid:'7f3a…c20a',root:'Earning #4821 → endpoint report',status:'ok',plugin:'tgbl_cred',dur:'9.2s',spans:13,refs:['earning#4821','request#3391'],
        sub:'correlation 7f3a-…-c20a · 13 spans · 1 workflow',
        nodes:[
          {kind:'event',name:'EarningIssued',sid:'earning_issued',t:0,d:45,depth:0,parent:'course completion',status:'ok',tag:'Earning #4821',ref:'earning#4821',detail:{event_id:'e0·9af1',correlation_id:'7f3a…c20a',parent:'— root (course completion)',payload:'{ user_id:208, content_id:55 }'}},
          {kind:'command',name:'ReportEarningToEndpointsOnTheFlyCommand',sid:'C1',t:120,d:300,depth:1,parent:'EarningIssued',status:'ok',tag:'on-the-fly',detail:{command_id:'c1·4b20',correlation_id:'7f3a…c20a',causation:'EarningIssued (e0·9af1)',duration_ms:300,emitted:'EndpointRequestSent, EndpointAuthRefresh',params:'{ earning_id:4821 }'}},
          {kind:'external',name:'POST /v2/report → CE-Broker',sid:'http',t:175,d:165,depth:2,parent:'C1',status:'ok',tag:'endpoint #12'},
          {kind:'event',name:'EndpointAuthRefresh',sid:'endpoint_auth_refresh',t:360,d:30,depth:2,parent:'C1',status:'ok',ob:true,tag:'token stale'},
          {kind:'command',name:'RefreshEndpointAuthCommand',sid:'C2',t:520,d:160,depth:3,parent:'EndpointAuthRefresh',status:'ok',ob:true,tag:'OAuth refresh'},
          {kind:'event',name:'EndpointRequestSent',sid:'endpoint_request_sent',t:430,d:30,depth:2,parent:'C1',status:'ok',tag:'request #3391',ref:'request#3391',detail:{event_id:'e1·2c5d',correlation_id:'7f3a…c20a',causation:'ReportEarning… (C1)','outbox.command_id':'C1 ✓',payload:'{ request_id:3391 }'}},
          {kind:'workflow',name:'BehaviourWorkflow W1',sid:'wf 5582',laneHead:true,wf:'W1',ref:'request#3391',tag:'ref=request#3391 · Retry→Notify→Stop'},
          {kind:'command',name:'ProcessEndpointResponseBehaviourCommand',sid:'C3',t:720,d:95,depth:3,parent:'EndpointRequestSent',status:'ok',wf:'W1',beh:'idx0·Retry#1',ref:'request#3391',detail:{command_id:'c3·8810',correlation_id:'7f3a…c20a',causation:'EndpointRequestSent (e1·2c5d)','params.workflow_id':'5582',note:'creates W1, behaviour[0]=Retry → reschedule +2s'}},
          {kind:'wait',name:'BehaviourWorkflowReschedule',sid:'+2s',t:815,d:1935,depth:3,parent:'C3',status:'wait',wf:'W1',tag:'delay 2s'},
          {kind:'command',name:'ProcessEndpointResponseBehaviourCommand',sid:'C4',t:2750,d:240,depth:3,parent:'reschedule',status:'warn',wf:'W1',beh:'idx0·Retry#2→503',ref:'request#3391',detail:{command_id:'c4·9931',correlation_id:'7f3a…c20a','params.workflow_id':'5582',result:'HTTP 503 — cursor holds at idx0'}},
          {kind:'wait',name:'BehaviourWorkflowReschedule',sid:'+6s',t:2990,d:5800,depth:3,parent:'C4',status:'wait',wf:'W1',tag:'delay 6s'},
          {kind:'command',name:'ProcessEndpointResponseBehaviourCommand',sid:'C5',t:8790,d:300,depth:3,parent:'reschedule',status:'ok',wf:'W1',beh:'idx0→1→2·Retry#3 OK·Notify·Stop',ref:'request#3391',detail:{command_id:'c5·1f4e',correlation_id:'7f3a…c20a','params.workflow_id':'5582',result:'200 OK',note:'idx0→1 execute_notification(), 1→2 execute_stop() → finish()'}},
          {kind:'event',name:'NotificationSent',sid:'mail',t:9000,d:30,depth:4,parent:'C5',status:'ok',wf:'W1',ob:true,tag:'behaviour[1]·mail'},
          {kind:'event',name:'BehaviourWorkflowCompleted',sid:'behaviour_workflow_complete',t:9095,d:30,depth:3,parent:'C5',status:'ok',wf:'W1',tag:'done'},
          {kind:'command',name:'MaybeFinishRequestOnWorkflowCompletion',sid:'H1',t:9160,d:75,depth:4,parent:'BehaviourWorkflowCompleted',status:'ok',ref:'request#3391',detail:{handler:'event handler (in-process)',action:'finish() → request#3391.is_finished = true'}}
        ]},
      t2:{id:'t2',cid:'b91e…7d10',root:'Earning #4830 → report (attempt 3, FAILED)',status:'fail',plugin:'tgbl_cred',dur:'8.8s',spans:9,refs:['earning#4830','request#3392'],
        sub:'correlation b91e-…-7d10 · 9 spans · workflow failed · 1 DLQ',
        nodes:[
          {kind:'event',name:'EarningIssued',sid:'earning_issued',t:0,d:45,depth:0,parent:'(re-report attempt 3)',status:'ok',tag:'Earning #4830',ref:'earning#4830'},
          {kind:'command',name:'ReportEarningToEndpointsOnTheFlyCommand',sid:'C1',t:120,d:280,depth:1,parent:'EarningIssued',status:'ok',tag:'on-the-fly'},
          {kind:'event',name:'EndpointRequestSent',sid:'endpoint_request_sent',t:410,d:30,depth:2,parent:'C1',status:'ok',tag:'request #3392',ref:'request#3392'},
          {kind:'workflow',name:'BehaviourWorkflow W2',sid:'wf 5583',laneHead:true,wf:'W2',ref:'request#3392',tag:'ref=request#3392 · Retry(3)'},
          {kind:'command',name:'ProcessEndpointResponseBehaviourCommand',sid:'C2',t:640,d:230,depth:3,parent:'EndpointRequestSent',status:'fail',wf:'W2',beh:'idx0·Retry#1→500',ref:'request#3392'},
          {kind:'wait',name:'BehaviourWorkflowReschedule',sid:'+2s',t:870,d:1900,depth:3,parent:'C2',status:'wait',wf:'W2',tag:'delay 2s'},
          {kind:'command',name:'ProcessEndpointResponseBehaviourCommand',sid:'C3',t:2770,d:230,depth:3,parent:'reschedule',status:'fail',wf:'W2',beh:'idx0·Retry#2→500',ref:'request#3392'},
          {kind:'wait',name:'BehaviourWorkflowReschedule',sid:'+6s',t:3000,d:5400,depth:3,parent:'C3',status:'wait',wf:'W2',tag:'delay 6s'},
          {kind:'dlq',name:'EndpointRequestSent → DLQ',sid:'dead',t:8400,d:240,depth:3,parent:'C3',status:'fail',wf:'W2',tag:'max_attempts(5) → workflow.fail()',ref:'request#3392',detail:{event_id:'e·dead',correlation_id:'b91e…7d10','dlq.command_id':'C1 ✓ carried','dlq.outbox_id':'88123',final_error:'HTTP 500 — endpoint #12 unreachable'}}
        ]},
      t3:{id:'t3',cid:'4d20…aa31',root:'Bulk replay · 1,240 deliveries',status:'run',plugin:'tgbl_datastream',dur:'running',spans:11,refs:['destination#4'],
        sub:'correlation 4d20-…-aa31 · batch workflow · 1 fork',
        nodes:[
          {kind:'command',name:'BulkReplayCommand',sid:'C1',t:0,d:120,depth:0,parent:'admin · Replay button',status:'ok',tag:'destination #4',ref:'destination#4'},
          {kind:'workflow',name:'BehaviourWorkflow W7 (replay_batch)',sid:'wf 6610',laneHead:true,wf:'W7',ref:'destination#4',tag:'ref=destination#4 · 1,240 items · batch 200'},
          {kind:'command',name:'BulkReplayCommand',sid:'C2',t:160,d:480,depth:1,parent:'BulkReplayCommand',status:'ok',wf:'W7',beh:'chunk1·1–200·12 fail',ref:'destination#4',detail:{command_id:'c2·a1',correlation_id:'4d20…aa31','params.workflow_id':'6610',items:'200 · 188 done · 12 failed',status:'batched → cursor holds'}},
          {kind:'wait',name:'reschedule (batched)',sid:'as',t:640,d:520,depth:1,parent:'C2',status:'wait',wf:'W7',tag:'next chunk'},
          {kind:'command',name:'BulkReplayCommand',sid:'C3',t:1160,d:470,depth:1,parent:'reschedule',status:'ok',wf:'W7',beh:'chunk2·201–400·9 fail',ref:'destination#4'},
          {kind:'wait',name:'reschedule (batched)',sid:'as',t:1630,d:500,depth:1,parent:'C3',status:'wait',wf:'W7',tag:'next chunk'},
          {kind:'command',name:'BulkReplayCommand',sid:'C4',t:2130,d:460,depth:1,parent:'reschedule',status:'warn',wf:'W7',beh:'chunk3·200·17 fail→threshold',ref:'destination#4'},
          {kind:'workflow',name:'fork → BehaviourWorkflow W8',sid:'wf 6611',laneHead:true,wf:'W8',ref:'destination#4',tag:'root_workflow_id=6610 · 38 failed items'},
          {kind:'command',name:'BulkReplayCommand',sid:'C5',t:2700,d:380,depth:2,parent:'C4',status:'ok',wf:'W8',beh:'child·retry 38 items',ref:'destination#4',detail:{command_id:'c5·d4',correlation_id:'4d20…aa31','params.workflow_id':'6611',root_workflow_id:'6610',note:'fork_workflow() — child carries only failed behaviour + items'}},
          {kind:'wait',name:'reschedule',sid:'as',t:3080,d:700,depth:1,parent:'C4',status:'wait',wf:'W7',tag:'chunks 4–7 pending'},
          {kind:'command',name:'BulkReplayCommand',sid:'C6',t:3780,d:60,depth:1,parent:'reschedule',status:'run',wf:'W7',beh:'chunk4·running…',ref:'destination#4'}
        ]}
    };
    var TRACE_ORDER=['t1','t2','t3'];
    var CID2T={}; TRACE_ORDER.forEach(function(id){CID2T[TRACES[id].cid]=id;});

    var FLOW={
      outbox:{figs:[['18','pending','warn'],['2','processing',''],['5','failed','bad'],['2,287','done·24h','good']],spark:[12,18,9,22,15,30,21,17,25,14,28,19]},
      dlq:[{ev:'endpoint_request_sent',ref:'request#3392',err:'HTTP 500 unreachable',cid:'b91e…7d10'},{ev:'subscription_matched',ref:'destination#9',err:'timeout after 30s',cid:'c014…1a2'},{ev:'delivery_scheduled',ref:'destination#9',err:'TLS handshake',cid:'c014…1a2'}],
      workflows:[{id:'W1',ref:'request#3391',st:'ok',idx:'3/3',cid:'7f3a…c20a'},{id:'W2',ref:'request#3392',st:'fail',idx:'1/3',cid:'b91e…7d10'},{id:'W7',ref:'destination#4',st:'run',idx:'chunk 4/7',cid:'4d20…aa31'},{id:'W8',ref:'destination#4',st:'run',idx:'fork',cid:'4d20…aa31'}],
      processes:[{id:'P1',cls:'DestinationCutoverProcess',st:'susp',step:'verify · waiting DestinationVerified',cid:'a18b…44f0'},{id:'P2',cls:'DestinationCutoverProcess',st:'run',step:'activate',cid:'9c2d…77b1'}],
      slowest:[{nm:'DeliverToDestinationCommand',ms:'1,420',cid:'4d20…aa31'},{nm:'ProcessEndpointResponseBehaviourCommand',ms:'300',cid:'7f3a…c20a'},{nm:'ReportEarningToEndpointsOnTheFlyCommand',ms:'300',cid:'7f3a…c20a'}],
      cmdspark:[40,55,48,62,70,58,81,66,90,72,84,77,95,69,88]
    };

    var ENTITIES={
      'request#3392':{type:'EndpointRequest',id:'3392',state:'reporting · retrying',statePill:'fail',created:'2026-06-23 09:14',meta:'endpoint #12 (CE-Broker) · 4 earnings · is_finished=false',
        timeline:[
          {when:'2026-06-23 09:14',what:'Attempt #1 — HTTP 500',st:'fail',cid:null,wf:'W (transient)',note:'system retry behaviours scheduled'},
          {when:'2026-06-24 02:00',what:'Attempt #2 — HTTP 500 (scheduled retry)',st:'fail',cid:null,wf:'W (transient)'},
          {when:'2026-06-25 14:05',what:'Attempt #3 — workflow W2 failed → DLQ',st:'fail',cid:'b91e…7d10',wf:'W2',note:'max_attempts exhausted'}
        ],
        workflows:[{id:'W2',st:'fail',cfg:'Retry(3)',cid:'b91e…7d10'}],
        traces:[{cid:'b91e…7d10',tid:'t2',when:'06-25 14:05',st:'fail'}]},
      'destination#4':{type:'Destination',id:'4',state:'replaying',statePill:'run',created:'2026-06-25 14:09',meta:'datastream · 1,240 deliveries queued · batch 200',
        timeline:[
          {when:'2026-06-25 14:09',what:'Bulk replay started — workflow W7',st:'run',cid:'4d20…aa31',wf:'W7'},
          {when:'2026-06-25 14:09',what:'Chunk 3 batch threshold → fork W8 (38 items)',st:'run',cid:'4d20…aa31',wf:'W8'}
        ],
        workflows:[{id:'W7',st:'run',cfg:'replay_batch · 1,240',cid:'4d20…aa31'},{id:'W8',st:'run',cfg:'fork · 38 items · root=6610',cid:'4d20…aa31'}],
        traces:[{cid:'4d20…aa31',tid:'t3',when:'06-25 14:09',st:'run'}]},
      'earning#4821':{type:'Earning',id:'4821',state:'reported ✓',statePill:'ok',created:'2026-06-25 14:02',meta:'user #208 · content #55 · accreditation #12',
        timeline:[{when:'2026-06-25 14:02',what:'Issued → reported on-the-fly → request#3391 finished',st:'ok',cid:'7f3a…c20a',wf:'W1'}],
        workflows:[{id:'W1',st:'ok',cfg:'Retry→Notify→Stop',cid:'7f3a…c20a'}],
        traces:[{cid:'7f3a…c20a',tid:'t1',when:'06-25 14:02',st:'ok'}]}
    };
    var ENT_ORDER=['request#3392','destination#4','earning#4821'];

    var TABLES={
      command_audit:{cols:['command_id','command_name','correlation_id','status','duration_ms','source'],
        rows:[
          ['c1·4b20','ReportEarningToEndpointsOnTheFlyCommand',{cid:'7f3a…c20a'},'completed','300','event'],
          ['c5·1f4e','ProcessEndpointResponseBehaviourCommand',{cid:'7f3a…c20a'},'completed','300','async'],
          ['c·9931','ProcessEndpointResponseBehaviourCommand',{cid:'b91e…7d10'},'failed','230','async'],
          ['c2·a1','BulkReplayCommand',{cid:'4d20…aa31'},'completed','480','async'],
          ['c5·d4','BulkReplayCommand',{cid:'4d20…aa31'},'completed','380','async']
        ]},
      integration_outbox:{cols:['event_id','event_type','correlation_id','command_id','status'],
        rows:[
          ['e1·2c5d','endpoint_request_sent',{cid:'7f3a…c20a'},{cmd:'c1·4b20'},'completed'],
          ['e3·a0','behaviour_workflow_reschedule',{cid:'7f3a…c20a'},{cmd:'c3·8810'},'completed'],
          ['e·dead','endpoint_request_sent',{cid:'b91e…7d10'},{cmd:'c1·4b20'},'dlq'],
          ['ev·22','subscription_matched',{cid:'4d20…aa31'},{cmd:'c1·a0'},'pending']
        ]}
    };

    /* ===================== NAV + ROUTER ===================== */
    var NAV=[['flow','Flow','var(--event)'],['trace','Trace','var(--corr)'],['entity','Entity','var(--process)'],['tables','Tables','var(--command)']];
    var cur={mode:'flow',arg:null};

    function renderStats(){var s=document.getElementById('dd-stats');s.innerHTML='';STATS.forEach(function(x){var d=$('div','stat'+(x.dlq?' dlq':''));d.appendChild($('b',null,x.n));d.appendChild($('span',null,x.l));d.onclick=function(){go.apply(null,x.go);};s.appendChild(d);});}
    function renderNav(){var n=document.getElementById('dd-nav');n.innerHTML='';NAV.forEach(function(x){var b=$('button',cur.mode===x[0]?'on':'');b.innerHTML='<span class="d" style="background:'+x[2]+'"></span>'+x[1];b.onclick=function(){go(x[0]);};n.appendChild(b);});}
    function go(mode,arg,via){cur={mode:mode,arg:arg||null};renderNav();hidePop();var v=document.getElementById('dd-view');v.innerHTML='';if(mode==='flow')renderFlow(v);else if(mode==='trace')renderTrace(v,arg);else if(mode==='entity')renderEntity(v,arg);else if(mode==='tables')renderTables(v,arg);if(via)toast(via.msg,via.color);}

    /* ---------- LENS A: FLOW ---------- */
    function card(dot,title,more){var c=$('div','card');var h=$('div','ch');h.appendChild($('span','d')).style.background=dot;h.appendChild($('span','t',title));if(more){var m=$('span','more',more.label);m.onclick=more.fn;h.appendChild(m);}c.appendChild(h);return c;}
    function miniRow(html,fn){var r=$('div','mr',html);if(fn)r.onclick=fn;return r;}
    function renderFlow(v){
      var grid=$('div','cards');
      // outbox
      var c1=card('var(--event)','Integration outbox',{label:'browse table →',fn:function(){go('tables','integration_outbox');}});
      var f=$('div','figs');FLOW.outbox.figs.forEach(function(x){var fg=$('div','fig'+(x[2]?' '+x[2]:''));fg.appendChild($('b',null,x[0]));fg.appendChild($('span',null,x[1]));f.appendChild(fg);});c1.appendChild(f);c1.appendChild(spark(FLOW.outbox.spark));grid.appendChild(c1);
      // dlq
      var c2=card('var(--error)','Dead-letter queue',{label:'3 need attention',fn:function(){}});var mn=$('div','mini');
      FLOW.dlq.forEach(function(d){mn.appendChild(miniRow('<span class="nm">'+d.ev+'</span><span class="sp"><span class="ref">'+d.ref+'</span><span class="mono-s">'+d.err+'</span><span class="cid">'+d.cid+'</span></span>',function(){go('trace',CID2T[d.cid]||'t2',{msg:'dead event · correlation '+d.cid+' <span class="arr">→</span> <b>Trace</b>',color:'var(--error)'});}));});c2.appendChild(mn);grid.appendChild(c2);
      // workflows
      var c3=card('var(--workflow)','Behaviour workflows');var mn3=$('div','mini');
      FLOW.workflows.forEach(function(w){mn3.appendChild(miniRow('<span class="nm">'+w.id+'</span><span class="sp"><span class="ref">'+w.ref+'</span><span class="mono-s">'+w.idx+'</span>'+pill(w.st)+'</span>',function(){go('entity',w.ref,{msg:'workflow '+w.id+' is about <b>'+w.ref+'</b> <span class="arr">→</span> Entity',color:'var(--process)'});}));});c3.appendChild(mn3);grid.appendChild(c3);
      // processes
      var c4=card('var(--process)','Long processes (sagas)');var mn4=$('div','mini');
      FLOW.processes.forEach(function(p){mn4.appendChild(miniRow('<span class="nm">'+p.cls+'</span><span class="sp"><span class="mono-s">'+p.step+'</span>'+pill(p.st)+'</span>',function(){go('trace',CID2T[p.cid]||null);}));});c4.appendChild(mn4);grid.appendChild(c4);
      // commands
      var c5=card('var(--command)','Commands · throughput',{label:'browse table →',fn:function(){go('tables','command_audit');}});c5.appendChild(spark(FLOW.cmdspark));var mn5=$('div','mini');
      FLOW.slowest.forEach(function(s){mn5.appendChild(miniRow('<span class="nm">'+s.nm+'</span><span class="sp"><span class="mono-s">'+s.ms+' ms</span><span class="cid">'+s.cid+'</span></span>',function(){go('trace',CID2T[s.cid]||null,{msg:'slowest span’s trace '+s.cid+' <span class="arr">→</span> <b>Trace</b>',color:'var(--command)'});}));});c5.appendChild(mn5);grid.appendChild(c5);
      // recent traces
      var c6=card('var(--corr)','Recent traces',{label:'open Trace lens →',fn:function(){go('trace');}});var mn6=$('div','mini');
      TRACE_ORDER.forEach(function(id){var t=TRACES[id];mn6.appendChild(miniRow('<span class="nm">'+t.root+'</span><span class="sp"><span class="mono-s">'+t.spans+' spans · '+t.dur+'</span>'+pill(t.status)+'</span>',function(){go('trace',id,{msg:'opening trace '+t.cid,color:'var(--corr)'});}));});c6.appendChild(mn6);grid.appendChild(c6);
      v.appendChild(grid);
    }

    /* ---------- LENS B: TRACE ---------- */
    function axisMax(tr){var m=0;tr.nodes.forEach(function(n){if(n.t!=null){var e=n.t+(n.d||0);if(e>m)m=e;}});return m||1000;}
    function renderTrace(v,traceId){
      v.appendChild($('div','crumbs','Trace lens — one correlation, the causal burst'));
      var grid=$('div','tgrid');
      var rail=$('div','rail');rail.appendChild($('div','rh','Recent traces'));
      var main=$('div','main');
      grid.appendChild(rail);grid.appendChild(main);v.appendChild(grid);
      var sel=traceId&&TRACES[traceId]?traceId:TRACE_ORDER[0];
      function paint(id){
        sel=id;
        [].forEach.call(rail.querySelectorAll('.trace-item'),function(e){e.classList.toggle('active',e.dataset.id===id);});
        drawTrace(main,TRACES[id]);
      }
      TRACE_ORDER.forEach(function(id){var t=TRACES[id];var it=$('button','trace-item');it.dataset.id=id;
        it.innerHTML='<div class="root">'+t.root+'</div><div class="meta"><span class="cid">'+t.cid+'</span>'+pill(t.status)+'</div><div class="nums"><span>'+t.spans+' spans</span><span>'+t.dur+'</span><span>'+t.plugin+'</span></div>';
        it.onclick=function(){paint(id);};rail.appendChild(it);});
      paint(sel);
      if(traceId)pulse(rail.querySelector('.trace-item.active'),'pulse');
    }
    function drawTrace(main,tr){
      main.innerHTML='';
      var mh=$('div','main-head');mh.innerHTML='<div><div class="tt">'+tr.root+'</div><div class="sub">'+tr.sub+'</div></div><div class="legend"><span class="lk"><i style="background:var(--command)"></i>command</span><span class="lk"><i style="background:var(--event)"></i>event</span><span class="lk"><i style="background:var(--workflow)"></i>workflow</span><span class="lk"><i style="background:var(--external)"></i>wait</span><span class="lk"><i style="background:var(--error)"></i>DLQ</span></div>';
      main.appendChild(mh);
      var sc=$('div','wf-scroll');var wf=$('div','wf');sc.appendChild(wf);main.appendChild(sc);
      var dr=$('div','drawer');dr.innerHTML='<div class="empty">Select a span — id, correlation, causation, params, and links to its entity.</div>';main.appendChild(dr);
      var max=axisMax(tr);
      var ax=$('div','axis');ax.appendChild($('div'));var tk=$('div','ticks');for(var p=0;p<=4;p++){var s=$('span',null,Math.round(max*p/4/100)/10+'s');s.style.left=(p*25)+'%';tk.appendChild(s);}ax.appendChild(tk);wf.appendChild(ax);
      var laneEl=null,laneOpen=false;
      tr.nodes.forEach(function(n){
        if(n.laneHead){var lh=$('div','lane-head');lh.innerHTML='<span class="lh-dot"></span><span class="lh-t">'+n.name+'</span><span class="lh-s">'+n.sid+' · '+n.tag+'</span>';wf.appendChild(lh);laneEl=$('div','lane');wf.appendChild(laneEl);laneOpen=true;return;}
        var host=(n.wf&&laneOpen)?laneEl:wf;
        var row=$('div','row');var lbl=$('div','lbl');var ind=Math.min(n.depth||0,5)*12;if(ind){var sp=$('span');sp.style.width=ind+'px';sp.style.flex='none';lbl.appendChild(sp);}
        var tg=$('span','tag');tg.style.background=COLORS[n.kind]||'var(--muted)';lbl.appendChild(tg);lbl.appendChild($('span','nm',n.name));if(n.ob)lbl.appendChild($('span','ob','off'));lbl.appendChild($('span','sid',n.sid));row.appendChild(lbl);
        var track=$('div','track');var b=$('div','b '+n.kind+(n.status==='warn'?' warn':'')+(n.status==='fail'?' fail':''));b.style.left=Math.max(0,(n.t/max)*100)+'%';b.style.width=Math.max((n.d||40)/max*100,1.6)+'%';var inner=n.tag?('<span>'+n.tag+'</span>'):'';if(n.beh)inner+='<span class="beh">'+n.beh+'</span>';b.innerHTML=inner||'&nbsp;';track.appendChild(b);row.appendChild(track);
        if(n.parent)row.appendChild($('div','cause','<span class="ar">↳</span> from <b>'+n.parent+'</b>'));
        row.onclick=function(){[].forEach.call(wf.querySelectorAll('.row.sel'),function(r){r.classList.remove('sel');});row.classList.add('sel');drawDrawer(dr,n,tr);};
        row.onmouseenter=function(ev){showPop('<div class="pt"><i style="background:'+(COLORS[n.kind]||'#999')+'"></i>'+n.name+'</div><div class="pl">'+(n.sid?'<b>id</b> '+n.sid+'  ':'')+(n.t!=null?'<b>t</b> '+n.t+'ms  <b>dur</b> '+(n.d||0)+'ms':'')+'</div>'+(n.beh?'<div class="pl"><b>step</b> '+n.beh+'</div>':'')+(n.ref?'<div class="pl"><b>entity</b> '+n.ref+' · click span to hop</div>':''),ev.clientX,ev.clientY);};
        row.onmousemove=function(ev){movePop(ev.clientX,ev.clientY);};
        row.onmouseleave=hidePop;
        host.appendChild(row);
      });
    }
    function drawDrawer(dr,n,tr){
      dr.innerHTML='';var h=$('div','dh');var t=$('span','tag');t.style.background=COLORS[n.kind]||'var(--muted)';h.appendChild(t);h.appendChild($('span','nm',n.name));
      var lnk=$('div','lnk');var lt=$('span','xlink','↳ this trace');lt.onclick=function(){};lnk.appendChild(lt);
      if(n.ref){var le=$('span','xlink ent','▤ entity '+n.ref);le.onclick=function(){go('entity',n.ref,{msg:'this span belongs to <b>'+n.ref+'</b> <span class="arr">→</span> Entity lens',color:'var(--process)'});};lnk.appendChild(le);}
      h.appendChild(lnk);dr.appendChild(h);
      if(!n.detail){dr.appendChild($('div','empty','No extra row mocked. A hydrated build shows the full audit / outbox row here. (trace '+tr.cid+')'));return;}
      var kv=$('div','kv');Object.keys(n.detail).forEach(function(k){var cell=$('div');cell.appendChild($('div','k',k));var cls='v';if(/workflow_id|outbox|emitted|action|root_workflow/.test(k))cls='v q';if(/causation|final_error|result/.test(k))cls='v warn';cell.appendChild($('div',cls,String(n.detail[k])));kv.appendChild(cell);});dr.appendChild(kv);
    }

    /* ---------- LENS C: ENTITY ---------- */
    function renderEntity(v,refKey){
      v.appendChild($('div','crumbs','Entity lens — one domain object across all its traces (the horizontal stitch)'));
      var pick=$('div','ent-pick');var sel=refKey&&ENTITIES[refKey]?refKey:ENT_ORDER[0];
      ENT_ORDER.forEach(function(k){var e=ENTITIES[k];var b=$('button',k===sel?'on':'');b.innerHTML='<span class="r">'+k+'</span><span class="s">'+e.type+' · '+e.state+'</span>';b.onclick=function(){go('entity',k);};pick.appendChild(b);});
      v.appendChild(pick);
      var e=ENTITIES[sel];
      var head=$('div','ent-head');head.innerHTML='<span class="big">'+sel+'</span>'+pill(e.statePill)+'<span class="meta">'+e.meta+' · created '+e.created+'</span>';v.appendChild(head);if(refKey)pulse(head,'pulse-v');
      var cols=$('div','ent-cols');
      // left: timeline of attempts/bursts
      var left=$('div','card');left.appendChild($('div','ch','<span class="d" style="background:var(--corr)"></span><span class="t">Lifecycle — attempts across time</span>'));
      var tl=$('div','timeline');
      e.timeline.forEach(function(x){var r=$('div','tl '+(x.st==='fail'?'fail':x.st==='ok'?'ok':''));var cidHtml=x.cid?('<span class="cid">'+x.cid+'</span>'):'<span class="mono-s">no trace (scheduled retry, different burst)</span>';r.innerHTML='<div class="when">'+x.when+'</div><div class="what">'+x.what+'</div><div class="det"><span class="mono-s">'+(x.wf||'')+'</span>'+cidHtml+(x.note?'<span class="mono-s">'+x.note+'</span>':'')+'</div>';if(x.cid&&CID2T[x.cid])r.onclick=function(){go('trace',CID2T[x.cid],{msg:'this attempt’s burst · '+x.cid+' <span class="arr">→</span> Trace',color:'var(--corr)'});};tl.appendChild(r);});
      left.appendChild(tl);cols.appendChild(left);
      // right: workflows + traces
      var right=$('div','card');right.appendChild($('div','ch','<span class="d" style="background:var(--workflow)"></span><span class="t">Workflows &amp; traces touching this object</span>'));
      var mn=$('div','mini');
      e.workflows.forEach(function(w){mn.appendChild(miniRow('<span class="nm">'+w.id+'</span><span class="sp"><span class="mono-s">'+w.cfg+'</span>'+pill(w.st)+'</span>',function(){go('trace',CID2T[w.cid]||null);}));});
      var div=$('div','hint','— '+e.traces.length+' trace(s) over this object’s life —');right.appendChild(mn);right.appendChild(div);
      var mn2=$('div','mini');
      e.traces.forEach(function(t){mn2.appendChild(miniRow('<span class="cid">'+t.cid+'</span><span class="sp"><span class="mono-s">'+t.when+'</span>'+pill(t.st)+'</span>',function(){go('trace',t.tid,{msg:'trace '+t.cid+' <span class="arr">→</span> Trace lens',color:'var(--corr)'});}));});
      right.appendChild(mn2);cols.appendChild(right);
      v.appendChild(cols);
    }

    /* ---------- TABLES (raw substrate, keys = links) ---------- */
    function renderTables(v,name){
      v.appendChild($('div','crumbs','Raw tables — the substrate. Every key cell is a hop into another lens.'));
      var sel=name&&TABLES[name]?name:'command_audit';
      var tn=$('div','tabnav');['command_audit','integration_outbox'].forEach(function(k){var b=$('button',k===sel?'on':'');b.textContent='wp_{prefix}_'+k;b.onclick=function(){go('tables',k);};tn.appendChild(b);});
      ['integration_dlq','long_processes','behaviour_workflows','behaviour_workflow_items'].forEach(function(k){var b=$('button');b.textContent='wp_{prefix}_'+k;b.style.opacity='.45';b.onclick=function(){};tn.appendChild(b);});
      v.appendChild(tn);
      var T=TABLES[sel];var sc=$('div','gridscroll');var tbl=$('table');var thead='<thead><tr>';T.cols.forEach(function(c){thead+='<th>'+c+'</th>';});thead+='</tr></thead>';
      var body='<tbody>';T.rows.forEach(function(r){body+='<tr>';r.forEach(function(cell){if(cell&&cell.cid){body+='<td><span class="lk" data-cid="'+cell.cid+'">'+cell.cid+'</span></td>';}else if(cell&&cell.cmd){body+='<td><span class="lk cmd" data-cmd="'+cell.cmd+'">'+cell.cmd+'</span></td>';}else if(typeof cell==='string'&&/#/.test(cell)){body+='<td><span class="lk ref">'+cell+'</span></td>';}else{body+='<td>'+cell+'</td>';}});body+='</tr>';});body+='</tbody>';
      tbl.innerHTML=thead+body;sc.appendChild(tbl);v.appendChild(sc);
      v.appendChild($('div','hint','correlation_id → Trace lens · command_id → its audit row · ref → Entity lens. (greyed tables not mocked in this prototype)'));
      [].forEach.call(tbl.querySelectorAll('.lk[data-cid]'),function(e){e.onclick=function(){go('trace',CID2T[e.dataset.cid]||null,{msg:'correlation '+e.dataset.cid+' <span class="arr">→</span> Trace',color:'var(--corr)'});};});
    }

    renderStats();go('flow');
  })();
  </script>
</div>
DDDASH;
}
