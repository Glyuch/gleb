<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Админка') · gleb.finance</title>
<style>
  :root{--red:#FF0032;--blue:#2B5BD7;--ink:#1a1c20;--muted:#6b7280;--faint:#6b7280;
    --bg:#f6f7f9;--surface:#fff;--line:#e9ebef;--line2:#f1f2f5}
  /* --faint darkened to #6b7280 for WCAG AA contrast on white */
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);line-height:1.5;font-size:14px;
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
  a{color:var(--blue)}
  .app{display:flex;min-height:100vh}
  .sidebar{width:230px;flex:none;background:var(--surface);border-right:1px solid var(--line);
    padding:16px 12px;position:sticky;top:0;align-self:flex-start;height:100vh;overflow-y:auto}
  .brand{display:flex;align-items:center;gap:9px;padding:6px 8px 10px}
  .brand .mark{width:28px;height:28px;border-radius:8px;background:var(--red);color:#fff;
    display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px}
  .brand .name{font-weight:700;font-size:15px;letter-spacing:-.01em}
  .nav-group{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);
    font-weight:700;padding:14px 8px 5px}
  .nav{display:flex;flex-direction:column}
  .nav a{display:flex;align-items:center;gap:10px;padding:8px 9px;border-radius:8px;color:#3a4150;
    text-decoration:none;font-weight:500;font-size:13.5px;margin-bottom:1px}
  .nav a:hover{background:var(--line2)}
  .nav a.active{background:#fff0f3;color:var(--red)}
  .nav a svg{width:18px;height:18px;flex:none;stroke:#8a909b;fill:none;stroke-width:2;
    stroke-linecap:round;stroke-linejoin:round}
  .nav a.active svg{stroke:var(--red)}
  .nav a .ext{margin-left:auto;color:var(--faint);font-size:12px}
  .main{flex:1;min-width:0}
  .page{max-width:1200px;margin:0 auto;padding:22px 30px 72px}
  .page-head{margin-bottom:18px}
  .page-head h1{font-size:23px;font-weight:800;margin:0 0 4px;letter-spacing:-.015em}
  .page-head .meta{color:var(--muted);font-size:13px;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
  .page-head .meta .fresh{color:var(--blue);font-weight:600}
  .page-head .meta svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;
    stroke-linecap:round;stroke-linejoin:round;opacity:.85}
  @media(max-width:860px){
    .app{flex-direction:column}
    .sidebar{width:auto;height:auto;position:static;border-right:0;border-bottom:1px solid var(--line);
      display:flex;flex-wrap:wrap;gap:3px;align-items:center;padding:8px 10px}
    .brand{padding:4px 8px;margin-right:6px}.nav-group{display:none}
    .nav{flex-direction:row}.nav a{margin:0}
    .page{padding:18px 16px 60px}
  }

  /* ---- editor styles (carried verbatim from the former game admin layout) ---- */
  h2{font-size:16px;margin:26px 0 10px}
  .flash{padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:14px;font-weight:600;white-space:pre-line}
  .flash.ok{background:#EAF0FB;color:#2B5BD7}
  .flash.err{background:#FFE8EC;color:#FF0032}
  .hint{font-size:13px;color:#62626a;margin:0 0 12px}
  .btn{display:inline-block;padding:11px 20px;border:none;border-radius:10px;background:#FF0032;color:#fff;font-size:15px;font-weight:700;cursor:pointer;margin-top:14px}
  .btn.ghost{background:#fff;color:#1a1a1a;border:1.5px solid #e2e2e8;margin-right:8px}
  textarea.json{width:100%;height:60vh;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;line-height:1.45;padding:14px;border:1.5px solid #e2e2e8;border-radius:12px;background:#fff;resize:vertical}
  .cards{display:flex;gap:12px;flex-wrap:wrap}
  .card{flex:1;min-width:160px;background:#fff;border-radius:12px;padding:14px 16px;box-shadow:0 2px 12px rgba(0,0,0,.05)}
  .card-l{font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#6b7280;font-weight:700}
  .card-v{font-size:22px;font-weight:800;margin-top:4px}
  .card-v small{font-size:12px;color:#6b7280;font-weight:600}
  .funnel{background:#fff;border-radius:12px;padding:14px 16px;box-shadow:0 2px 12px rgba(0,0,0,.05)}
  .frow{display:flex;align-items:center;gap:12px;margin-bottom:10px}
  .flabel{width:200px;flex:none;font-size:13.5px;font-weight:600}
  .fbarwrap{flex:1;background:#f0f0f2;border-radius:7px;height:22px;overflow:hidden}
  .fbar{height:100%;background:linear-gradient(90deg,#FF0032,#FF4D74);border-radius:7px;min-width:2px}
  .fcount{width:90px;flex:none;text-align:right;font-weight:800;font-size:14px}
  .fcount small{color:#6b7280;font-weight:700;font-size:12px}
  .survey-block{background:#fff;border-radius:12px;padding:14px 16px;margin-bottom:12px;box-shadow:0 2px 12px rgba(0,0,0,.05)}
  .sq{font-weight:700;font-size:14px;margin-bottom:10px}
  .sq small{color:#6b7280;font-weight:600}
  .srow{display:flex;align-items:center;gap:10px;margin-bottom:6px}
  .sopt{width:170px;flex:none;font-size:13px}
  .sbarwrap{flex:1;background:#f0f0f2;border-radius:6px;height:16px;overflow:hidden}
  .sbar{height:100%;background:#2B5BD7;border-radius:6px;min-width:2px}
  .scnt{width:44px;flex:none;text-align:right;font-weight:700;font-size:13px}
  .q-block{background:#fff;border:1.5px solid #e2e2e8;border-radius:12px;padding:14px;margin-bottom:12px}
  .q-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
  .q-title{font-weight:800;font-size:14px}
  .q-block input[type=text]{width:100%;padding:9px 11px;border:1.5px solid #e2e2e8;border-radius:8px;font-size:14px;margin-bottom:6px}
  .q-block .qtext{font-weight:600}
  .opt-row{display:flex;gap:8px;align-items:center}
  .opt-row input{flex:1;margin-bottom:6px}
  .x{background:#fff;border:1.5px solid #e2e2e8;border-radius:8px;padding:6px 10px;cursor:pointer;color:#62626a;font-size:13px}
  .addopt{background:none;border:none;color:#2B5BD7;cursor:pointer;font-size:13px;font-weight:600;padding:2px 0}
  .matrix-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid #e2e2e8;border-radius:12px;background:#fff;box-shadow:0 2px 12px rgba(0,0,0,.05)}
  .matrix{border-collapse:separate;border-spacing:0;font-size:13px}
  .matrix th,.matrix td{padding:9px 12px;text-align:center;border-bottom:1px solid #f0f0f2;white-space:nowrap}
  .matrix thead th{font-weight:700;background:#fafafb;vertical-align:bottom}
  .matrix .qnum{font-weight:800;color:#1a1a1a;font-size:13px}
  .matrix .qparams{font-weight:600;color:#6b7280;font-size:10.5px;margin-top:3px;line-height:1.35}
  .matrix th.corner,.matrix td.rowhead{position:sticky;left:0;text-align:left;font-weight:700;background:#fff;box-shadow:1px 0 0 #ececed}
  .matrix th.corner{background:#fafafb;z-index:2;color:#62626a;font-size:11px;text-transform:uppercase;letter-spacing:.3px}
  .matrix td.rowhead{min-width:150px;max-width:190px;white-space:normal;color:#1a1a1a;z-index:1}
  .matrix td.cell{font-variant-numeric:tabular-nums;font-weight:700;color:#1a1a1a}
  .matrix td.cell .pct{display:block;font-size:10px;color:#6b7280;font-weight:600;margin-top:1px}
  .matrix td.cell.zero{color:#cbcbd2}
  .matrix tbody tr:nth-child(even) td{background:#fcfcfd}
  .matrix tbody tr:nth-child(even) td.rowhead{background:#fcfcfd}
  .yes{color:#2B5BD7;font-weight:700}
  .no{color:#6b7280}
  .matrix td.cell.lb-ans{white-space:normal;min-width:96px;font-weight:600;color:#62626a;font-size:12.5px}
  .matrix th.lb-user,.matrix td.rowhead.lb-user{min-width:170px;max-width:230px}
  .lb-email{display:block;font-size:11px;color:#6b7280;font-weight:600;margin-top:2px;word-break:break-all}
</style>
@stack('head')
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div class="mark">g</div><div class="name">gleb.finance</div></div>

    <div class="nav-group">Обзор</div>
    <nav class="nav">
      <a href="{{ route('admin.dashboards.site') }}" class="{{ request()->routeIs('admin.dashboards.site') ? 'active' : '' }}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>Главная</a>
    </nav>

    <div class="nav-group">ФондыКвест</div>
    <nav class="nav">
      <a href="{{ route('admin.dashboards.gameresults') }}" class="{{ request()->routeIs('admin.dashboards.gameresults') ? 'active' : '' }}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><line x1="6" y1="20" x2="6" y2="14"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="18" y1="20" x2="18" y2="10"/></svg>Результаты</a>
      <a href="{{ route('admin.game.content') }}" class="{{ request()->routeIs('admin.game.content') ? 'active' : '' }}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>Контент</a>
      <a href="{{ route('admin.game.survey') }}" class="{{ request()->routeIs('admin.game.survey') ? 'active' : '' }}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="8" y="3" width="8" height="4" rx="1"/><path d="M9 5H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3"/></svg>Опрос</a>
      <a href="{{ route('admin.game.returns') }}" class="{{ request()->routeIs('admin.game.returns') ? 'active' : '' }}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><polyline points="3 17 9 11 13 15 21 7"/><polyline points="15 7 21 7 21 13"/></svg>Доходности</a>
      <a href="{{ url('/game') }}" target="_blank">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>Открыть игру<span class="ext">↗</span></a>
    </nav>
  </aside>

  <main class="main">
    <div class="page">
      @if (session('status'))
        <div class="flash ok">{{ session('status') }}</div>
      @endif
      @if ($errors->any())
        <div class="flash err">@foreach ($errors->all() as $e){{ $e }}
@endforeach</div>
      @endif

      @yield('content')
    </div>
  </main>
</div>
@stack('scripts')
</body>
</html>
