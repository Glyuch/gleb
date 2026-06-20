<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Админка · ФондыКвест</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;background:#f5f5f7;color:#1a1a1a;line-height:1.5}
.wrap{max-width:860px;margin:0 auto;padding:24px 18px 60px}
h1{font-size:22px;margin:0 0 14px}
h2{font-size:16px;margin:26px 0 10px}
.tabs{display:flex;gap:6px;border-bottom:1px solid #e2e2e8;margin-bottom:18px;flex-wrap:wrap}
.tabs a{padding:9px 14px;text-decoration:none;color:#62626a;font-weight:600;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-1px}
.tabs a.active{color:#FF0032;border-bottom-color:#FF0032}
.tabs a.right{margin-left:auto;color:#2B5BD7}
.flash{padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:14px;font-weight:600;white-space:pre-line}
.flash.ok{background:#E8F6EE;color:#1A8049}
.flash.err{background:#FFE8EC;color:#FF0032}
.hint{font-size:13px;color:#62626a;margin:0 0 12px}
.btn{display:inline-block;padding:11px 20px;border:none;border-radius:10px;background:#FF0032;color:#fff;font-size:15px;font-weight:700;cursor:pointer;margin-top:14px}
.btn.ghost{background:#fff;color:#1a1a1a;border:1.5px solid #e2e2e8;margin-right:8px}
textarea.json{width:100%;height:60vh;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;line-height:1.45;padding:14px;border:1.5px solid #e2e2e8;border-radius:12px;background:#fff;resize:vertical}
table{width:100%;border-collapse:collapse;font-size:14px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.05)}
th,td{text-align:left;padding:10px 14px;border-bottom:1px solid #f0f0f2}
th{font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#9a9aa2}
.cards{display:flex;gap:12px;flex-wrap:wrap}
.card{flex:1;min-width:160px;background:#fff;border-radius:12px;padding:14px 16px;box-shadow:0 2px 12px rgba(0,0,0,.05)}
.card-l{font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#9a9aa2;font-weight:700}
.card-v{font-size:22px;font-weight:800;margin-top:4px}
.card-v small{font-size:12px;color:#9a9aa2;font-weight:600}
.funnel{background:#fff;border-radius:12px;padding:14px 16px;box-shadow:0 2px 12px rgba(0,0,0,.05)}
.frow{display:flex;align-items:center;gap:12px;margin-bottom:10px}
.flabel{width:200px;flex:none;font-size:13.5px;font-weight:600}
.fbarwrap{flex:1;background:#f0f0f2;border-radius:7px;height:22px;overflow:hidden}
.fbar{height:100%;background:linear-gradient(90deg,#FF0032,#FF4D74);border-radius:7px;min-width:2px}
.fcount{width:90px;flex:none;text-align:right;font-weight:800;font-size:14px}
.fcount small{color:#1A8049;font-weight:700;font-size:12px}
.survey-block{background:#fff;border-radius:12px;padding:14px 16px;margin-bottom:12px;box-shadow:0 2px 12px rgba(0,0,0,.05)}
.sq{font-weight:700;font-size:14px;margin-bottom:10px}
.sq small{color:#9a9aa2;font-weight:600}
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
.matrix .qparams{font-weight:600;color:#9a9aa2;font-size:10.5px;margin-top:3px;line-height:1.35}
.matrix th.corner,.matrix td.rowhead{position:sticky;left:0;text-align:left;font-weight:700;background:#fff;box-shadow:1px 0 0 #ececed}
.matrix th.corner{background:#fafafb;z-index:2;color:#62626a;font-size:11px;text-transform:uppercase;letter-spacing:.3px}
.matrix td.rowhead{min-width:150px;max-width:190px;white-space:normal;color:#1a1a1a;z-index:1}
.matrix td.cell{font-variant-numeric:tabular-nums;font-weight:700;color:#1a1a1a}
.matrix td.cell .pct{display:block;font-size:10px;color:#9a9aa2;font-weight:600;margin-top:1px}
.matrix td.cell.zero{color:#cbcbd2}
.matrix tbody tr:nth-child(even) td{background:#fcfcfd}
.matrix tbody tr:nth-child(even) td.rowhead{background:#fcfcfd}
</style>
</head>
<body>
<div class="wrap">
  <h1>ФондыКвест · Админка</h1>
  <nav class="tabs">
    <a href="{{ route('admin.game.content') }}" class="{{ request()->routeIs('admin.game.content') ? 'active' : '' }}">Контент</a>
    <a href="{{ route('admin.game.survey') }}" class="{{ request()->routeIs('admin.game.survey') ? 'active' : '' }}">Опрос</a>
    <a href="{{ route('admin.game.stats') }}" class="{{ request()->routeIs('admin.game.stats') ? 'active' : '' }}">Статистика</a>
    <a href="{{ url('/game') }}" class="right" target="_blank">Открыть игру →</a>
  </nav>

  @if (session('status'))
    <div class="flash ok">{{ session('status') }}</div>
  @endif
  @if ($errors->any())
    <div class="flash err">@foreach ($errors->all() as $e){{ $e }}
@endforeach</div>
  @endif

  @yield('content')
</div>
</body>
</html>
