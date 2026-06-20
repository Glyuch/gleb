<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>3 года за 3 минуты — ФондыКвест</title>
@verbatim
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--red:#FF0032;--red-light:#FF00320D;--dark:#1A1A1A;--g50:#F7F7F8;--g100:#F0F0F2;--g200:#E4E4E8;--g400:#9A9AA2;--g600:#62626A;--white:#fff;--green:#1A8049;--green-bg:#E8F6EE;--amber:#C8860B;--amber-bg:#FFF4DE;--blue:#2B5BD7;--blue-bg:#EAF0FF;--radius:18px;--radius-sm:13px;--shadow:0 4px 24px rgba(0,0,0,.08)}
html,body{min-height:100%;font-family:'Inter',-apple-system,sans-serif;background:#fff;color:var(--dark);-webkit-font-smoothing:antialiased}
body{display:flex;justify-content:center}
.app{width:100%;max-width:480px;min-height:100vh;background:#fff;display:flex;flex-direction:column;padding:14px 14px 24px;position:relative}
.screen{display:none;flex-direction:column;flex:1}
.screen.active{display:flex;animation:fade .35s ease}
.screen{align-items:stretch}
#s_intro1,#s_intro2,#s_intro3{justify-content:flex-start;align-items:stretch}
#s_intro1>*,#s_intro2>*,#s_intro3>*{flex:0 0 auto}
@keyframes fade{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@keyframes pop{0%{transform:scale(.9);opacity:0}100%{transform:scale(1);opacity:1}}
@keyframes count{from{opacity:.3}to{opacity:1}}
.center{flex:1;display:flex;flex-direction:column;justify-content:center}
h1{font-size:28px;font-weight:800;letter-spacing:-.6px;line-height:1.15;margin-bottom:12px}
h1 .r{color:var(--red)}
h2{font-size:20px;font-weight:800;line-height:1.3}
.lead{font-size:15px;color:var(--g600);line-height:1.5;margin-bottom:24px}
.btn{width:100%;padding:17px;border:none;border-radius:var(--radius-sm);background:var(--red);color:#fff;font-family:inherit;font-size:16px;font-weight:700;cursor:pointer;transition:transform .1s}
.btn:active{transform:scale(.98)}
.btn:disabled{opacity:.4}
.btn-ghost{background:#fff;color:var(--dark);border:1.5px solid var(--g200);font-weight:600;margin-top:10px}
.icon-badge{width:78px;height:78px;border-radius:20px;background:var(--red-light);display:grid;place-items:center;margin-bottom:20px}
.disc{font-size:12px;color:var(--g400);margin-top:22px;line-height:1.5}
.kicker{font-size:12px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--red);margin-bottom:8px}
.bar{display:flex;gap:5px;margin-bottom:20px}
.bar span{flex:1;height:5px;border-radius:99px;background:var(--g200)}
.bar span.on{background:var(--red)}
.cmp-cards{display:flex;flex-direction:column;gap:9px;margin-bottom:10px}
.cmp-card{background:#fff;border:1.5px solid var(--g200);border-radius:var(--radius-sm);padding:12px 15px}
.cmp-card.you{border-color:var(--red);background:var(--red-light)}
.cc-h{display:flex;align-items:center;gap:10px;margin-bottom:7px}
.cc-ic{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;flex:none}
.cc-ic.bank{background:var(--blue-bg)}.cc-ic.fund{background:var(--red-light)}
.cc-t{font-size:16px;font-weight:800}
.cmp-card ul{list-style:none}.cmp-card li{font-size:12.5px;color:var(--g600);line-height:1.35;padding-left:15px;position:relative;margin-bottom:3px}
.cmp-card li::before{content:"–";position:absolute;left:0;color:var(--g400);font-weight:700}
.cmp-card.you li::before{color:var(--red)}
.intro-note{background:var(--amber-bg);border-radius:var(--radius-sm);padding:11px 14px;font-size:12.5px;line-height:1.4;color:#7a5c12;margin-bottom:12px}
.fund-mini{text-align:left;background:#fff;border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:13px 15px;margin-bottom:10px;display:flex;gap:12px;align-items:flex-start;border-left:5px solid var(--g200);flex:0 0 auto;height:auto}
#s_intro2{justify-content:flex-start}
#s_intro2 .fund-mini{min-height:0;flex:0 0 auto!important}
.fund-mini.money{border-color:#34a36b}.fund-mini.bond{border-color:var(--blue)}.fund-mini.stock{border-color:var(--red)}.fund-mini.mix{border-color:var(--amber)}
.fm-ic{width:38px;height:38px;border-radius:10px;background:var(--g50);display:grid;place-items:center;flex:none}
.fm-t{font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.fm-d{font-size:12.5px;color:var(--g600);line-height:1.45;margin-top:3px;text-align:left}
.fm-r{font-size:10px;font-weight:700;padding:3px 8px;border-radius:99px}
.fm-r.low{background:var(--green-bg);color:var(--green)}.fm-r.mid{background:var(--blue-bg);color:var(--blue)}.fm-r.high{background:var(--red-light);color:var(--red)}
.how-list{margin-bottom:16px}
.how-item{display:flex;gap:12px;align-items:flex-start;margin-bottom:12px;font-size:14px;line-height:1.45}
.how-n{width:26px;height:26px;border-radius:50%;background:var(--red);color:#fff;font-weight:800;font-size:13px;display:grid;place-items:center;flex:none}
.hud{background:#fff;border-radius:14px;box-shadow:var(--shadow);padding:10px 13px;margin-bottom:9px}
.hud-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:7px;gap:8px}
.hud-year{font-size:12px;font-weight:800;color:var(--red);white-space:nowrap}
.hud-stat{font-size:10px;color:var(--g400);font-weight:700;text-transform:uppercase;letter-spacing:.2px;text-align:right;line-height:1.3}
.money-row{display:flex;gap:10px}
.money{flex:1;text-align:center;padding:6px 4px;border-radius:9px;background:var(--g50)}
.money .ml{font-size:9.5px;color:var(--g400);text-transform:uppercase;letter-spacing:.2px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:4px}
.money .mv{font-size:15px;font-weight:800;margin-top:2px;animation:count .4s}
.money.you .mv{color:var(--red)}.money.bank .mv{color:var(--g600)}
.progress{height:4px;background:var(--g100);border-radius:99px;overflow:hidden;margin-top:9px}
.progress-fill{height:100%;background:var(--red);border-radius:99px;transition:width .5s ease;width:0}
.event{background:#fff;border-radius:14px;box-shadow:var(--shadow);padding:12px 14px;margin-bottom:9px;animation:pop .35s}
.event-head{display:flex;align-items:center;gap:10px;margin-bottom:7px}
.event-ic{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;flex:none}
.event-ic.up{background:var(--green-bg)}.event-ic.down{background:var(--red-light)}.event-ic.neutral{background:var(--blue-bg)}
.event-year{font-size:12px;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.5px}
.event-title{font-size:15px;font-weight:800;line-height:1.2;margin-top:1px}
.event-text{font-size:12.5px;color:var(--g600);line-height:1.35;margin-bottom:5px}
.context{background:var(--blue-bg);border-radius:9px;padding:8px 11px;font-size:11.5px;line-height:1.35;color:#1c3d8f;margin-top:5px}
.context b{color:#0e2a6b}
.choices{display:flex;flex-direction:column;gap:8px;margin-bottom:8px}
.choice{background:#fff;border:1.5px solid var(--g200);border-radius:var(--radius-sm);padding:11px 13px;cursor:pointer;transition:all .15s;text-align:left}
.choice:active{transform:scale(.99)}
.choice:hover{border-color:var(--red)}
.choice-top{display:flex;align-items:center;gap:11px}
.choice .ci{width:38px;height:38px;border-radius:10px;flex:none;display:grid;place-items:center}
.choice .cmain{flex:1;min-width:0}
.choice .ctrow{display:flex;align-items:center;gap:8px}
.choice .ct{flex:1;min-width:0}
.choice .ct{font-size:15px;font-weight:700}
.choice .cd{font-size:11.5px;color:var(--g600);margin-top:2px;line-height:1.3}
.choice .hint{font-size:12px;color:var(--g600);line-height:1.4;margin-top:9px;padding-top:9px;border-top:1px solid var(--g100);display:flex;gap:7px;align-items:flex-start}
.htag{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.2px;padding:3px 8px;border-radius:6px;white-space:nowrap;flex:none;align-self:flex-start}
.htag.low{background:var(--green-bg);color:var(--green)}.htag.mid{background:var(--blue-bg);color:var(--blue)}.htag.high{background:var(--red-light);color:var(--red)}
.outcome{background:var(--dark);color:#fff;border-radius:var(--radius);padding:18px;margin-bottom:12px;animation:pop .3s}
.outcome-h{font-size:13px;font-weight:800;color:#FF8FA6;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;gap:8px}
.outcome-text{font-size:14px;line-height:1.55;color:#ECECEC}
.outcome-delta{display:flex;gap:10px;margin-top:12px}
.delta{flex:1;background:rgba(255,255,255,.08);border-radius:10px;padding:9px;text-align:center}
.delta .dl{font-size:10px;color:#999;text-transform:uppercase;letter-spacing:.3px}
.delta .dv{font-size:15px;font-weight:800;margin-top:2px}
.delta .dv.pos{color:#4ADE80}.delta .dv.neg{color:#FF8FA6}
.result-emoji{margin-bottom:14px}
.bars{display:flex;align-items:flex-end;gap:12px;height:160px;margin:16px 0 8px;padding:0 6px}
.barwrap{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%}
.barv{width:100%;border-radius:9px 9px 0 0;display:flex;align-items:flex-start;justify-content:center;padding-top:7px;color:#fff;font-weight:800;font-size:12px;transition:height 1s cubic-bezier(.2,.8,.2,1)}
.barv.you{background:linear-gradient(180deg,#FF0032,#FF4D74)}
.barv.bank{background:linear-gradient(180deg,#9A9AA2,#BcBcC2)}
.barv.max{background:linear-gradient(180deg,#1A8049,#34a36b)}
.barlabel{font-size:11px;font-weight:700;margin-top:7px;text-align:center}
.barlabel span{display:block;font-size:10px;color:var(--g400);font-weight:500}
.result-line{background:var(--green-bg);border:1.5px solid #B6E6C8;border-radius:var(--radius-sm);padding:14px 16px;margin:12px 0}
.result-line .rt{font-size:12px;font-weight:800;color:var(--green);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.result-line .rx{font-size:15px;line-height:1.5;font-weight:600}
.maxbox{background:#fff;border:1.5px dashed var(--green);border-radius:var(--radius-sm);padding:14px 16px;margin:12px 0}
.maxbox .mt{font-size:12px;font-weight:800;color:var(--green);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.maxbox .mx{font-size:14px;line-height:1.5}
.maxbox .mx b{color:var(--green)}
.promo{background:linear-gradient(135deg,#1A1A1A,#33000d);color:#fff;border-radius:var(--radius);padding:18px;margin:12px 0;text-align:center}
.promo .pk{font-size:11px;font-weight:800;color:#FF8FA6;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px}
.promo .pcode{font-size:26px;font-weight:800;letter-spacing:1px;background:rgba(255,255,255,.1);border:1px dashed #FF8FA6;border-radius:10px;padding:12px;margin:8px 0;font-family:monospace}
.promo .pd{font-size:12px;color:#cfcfcf;line-height:1.45;margin-top:8px}
.learned{background:#fff;border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:16px;margin-bottom:8px}
.learned h3{font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--red);margin-bottom:10px}
.learned li{list-style:none;font-size:13.5px;line-height:1.5;padding-left:22px;position:relative;margin-bottom:8px}
.learned li svg{position:absolute;left:0;top:2px}
html,body{overflow-y:auto;-webkit-overflow-scrolling:touch}
.app{padding-bottom:40px}
#nextbtn{margin-bottom:8px}
.outcome-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:flex-end;justify-content:center;z-index:50;animation:fadeo .25s}
.outcome-overlay.show{display:flex}
@keyframes fadeo{from{opacity:0}to{opacity:1}}
.outcome-sheet{width:100%;max-width:480px;background:var(--dark);color:#fff;border-radius:20px 20px 0 0;padding:22px 18px calc(18px + env(safe-area-inset-bottom));animation:slideup .3s cubic-bezier(.2,.8,.2,1)}
@keyframes slideup{from{transform:translateY(100%)}to{transform:translateY(0)}}
.outcome-sheet .outcome-h{font-size:13px;font-weight:800;color:#FF8FA6;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;gap:8px}
.outcome-sheet .outcome-text{font-size:14px;line-height:1.55;color:#ECECEC}
.outcome-sheet .outcome-delta{display:flex;gap:10px;margin:12px 0}
.outcome-sheet .delta{flex:1;background:rgba(255,255,255,.08);border-radius:10px;padding:9px;text-align:center}
.outcome-sheet .delta .dl{font-size:10px;color:#999;text-transform:uppercase;letter-spacing:.3px}
.outcome-sheet .delta .dv{font-size:15px;font-weight:800;margin-top:2px}
.outcome-sheet .delta .dv.pos{color:#4ADE80}.outcome-sheet .delta .dv.neg{color:#FF8FA6}
.outcome-sheet .sheet-btn{width:100%;padding:15px;border:none;border-radius:13px;background:var(--red);color:#fff;font-family:inherit;font-size:16px;font-weight:700;cursor:pointer;margin-top:6px}
/* topbar */
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;font-size:13px;color:var(--g600);padding-bottom:11px;border-bottom:1px solid var(--g100)}
.topbar .brand{font-weight:800;color:var(--red);text-decoration:none;font-size:15px;letter-spacing:-.2px}
.topbar-right{display:flex;align-items:center;gap:14px}
.topbar a{color:var(--g600);text-decoration:none}
.topbar .who{font-weight:700;color:var(--dark)}
/* survey */
.survey-card{background:#fff;border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:16px;margin:12px 0}
.survey-h{font-size:14px;font-weight:800;margin-bottom:12px}
.survey-q{margin-bottom:14px}
.survey-qt{font-size:13.5px;font-weight:700;line-height:1.35;margin-bottom:8px}
.survey-opts{display:flex;flex-direction:column;gap:6px}
.survey-opt{display:flex;align-items:center;gap:9px;font-size:13.5px;padding:9px 11px;border:1.5px solid var(--g200);border-radius:10px;cursor:pointer}
.survey-opt input{accent-color:var(--red);width:16px;height:16px}
.survey-opt:has(input:checked){border-color:var(--red);background:var(--red-light)}
.survey-error{display:none;color:var(--red);font-size:12.5px;font-weight:700;margin-bottom:8px}
/* leaderboard */
.lb{background:#fff;border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:16px;margin:12px 0}
.lb-h{font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--red);margin-bottom:10px}
.lb-head,.lb-row{display:flex;align-items:center;gap:10px;font-size:13px;padding:7px 9px;border-radius:9px}
.lb-head{font-size:10px;text-transform:uppercase;letter-spacing:.3px;color:var(--g400);font-weight:700}
.lb-row:nth-child(odd){background:var(--g50)}
.lb-row.lb-me{background:var(--red-light);font-weight:800}
.lb-rank{width:28px;flex:none;font-weight:800;color:var(--g600)}
.lb-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lb-score{flex:none;font-weight:800}
.lb-note{font-size:12.5px;color:var(--g600);margin-top:10px;font-weight:700}
</style>
@endverbatim
</head>
<body>
<div class="app">

  <div class="topbar">
    <a class="brand" href="{{ url('/') }}">gleb.finance</a>
    <div class="topbar-right">
      <span class="who">{{ $user['name'] }}</span>
      <a href="{{ url('/logout') }}" onclick="event.preventDefault();document.getElementById('logout-form').submit();">Выйти</a>
    </div>
  </div>
  <form id="logout-form" method="POST" action="{{ url('/logout') }}" style="display:none">@csrf</form>

  <!-- СТАРТ -->
  <section class="screen active" id="s_start">
    <div class="center">
      <div class="icon-badge"><svg width="42" height="42" viewBox="0 0 42 42" fill="none"><path d="M5 32 L15 22 L23 28 L37 12" stroke="#FF0032" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M30 12 H37 V19" stroke="#FF0032" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/><line x1="5" y1="37" x2="37" y2="37" stroke="#FF0032" stroke-width="3.5" stroke-linecap="round"/></svg></div>
      <div class="kicker">{{ $content['start']['kicker'] }}</div>
      <h1>{!! $content['start']['titleHtml'] !!}</h1>
      <div class="lead">{{ $content['start']['lead'] }}</div>
      <button class="btn click_game_start" onclick="go('s_intro1')">{{ $content['start']['btn'] }}</button>
      <div class="disc">{!! $content['start']['disc'] !!}</div>
    </div>
  </section>

  <!-- ИНТРО 1 -->
  <section class="screen" id="s_intro1">
    <div class="bar"><span class="on"></span><span></span><span></span></div>
    <div class="kicker">{{ $content['intros'][0]['kicker'] }}</div>
    <h2>{{ $content['intros'][0]['h2'] }}</h2>
    <div class="lead" style="margin-bottom:10px;font-size:14px">{{ $content['intros'][0]['lead'] }}</div>
    <div class="cmp-cards">
      <div class="cmp-card"><div class="cc-h"><div class="cc-ic bank"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M3 9l9-5 9 5" stroke="#2B5BD7" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 9v9M9 9v9M15 9v9M19 9v9" stroke="#2B5BD7" stroke-width="2.2" stroke-linecap="round"/><path d="M3 20h18" stroke="#2B5BD7" stroke-width="2.2" stroke-linecap="round"/></svg></div><div class="cc-t">Вклад</div></div>
        <ul><li>Банк сам распоряжается деньгами</li><li>Процент фиксированный, известен заранее</li><li>Застрахован государством</li><li>Забрать — в конце срока</li></ul></div>
      <div class="cmp-card you"><div class="cc-h"><div class="cc-ic fund"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="8" height="8" rx="1.5" stroke="#FF0032" stroke-width="2.1"/><rect x="13" y="4" width="8" height="8" rx="1.5" stroke="#FF0032" stroke-width="2.1"/><rect x="3" y="14" width="8" height="6" rx="1.5" stroke="#FF0032" stroke-width="2.1"/><rect x="13" y="14" width="8" height="6" rx="1.5" stroke="#FF0032" stroke-width="2.1"/></svg></div><div class="cc-t">Фонд</div></div>
        <ul><li>Вы покупаете паи — это набор активов</li><li>Доход не фиксирован: зависит от того, что внутри</li><li>Не застрахован, но потенциал выше</li><li>Можно докупать и продавать паи</li></ul></div>
    </div>
    <div class="intro-note">Проще говоря: вклад — «отдал банку под процент». Фонд — «купил готовую корзину активов, и она работает на тебя».</div>
    <button class="btn" onclick="go('s_intro2')">Дальше →</button>
  </section>

  <!-- ИНТРО 2 -->
  <section class="screen" id="s_intro2">
    <div class="bar"><span class="on"></span><span class="on"></span><span></span></div>
    <div class="kicker">{{ $content['intros'][1]['kicker'] }}</div>
    <h2>{{ $content['intros'][1]['h2'] }}</h2>
    <div class="lead" style="margin-bottom:16px">{{ $content['intros'][1]['lead'] }}</div>
    <div class="fund-mini money"><div class="fm-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="6" width="18" height="12" rx="2" stroke="#1A8049" stroke-width="2.2"/><circle cx="12" cy="12" r="2.5" stroke="#1A8049" stroke-width="2.2"/></svg></div>
      <div><div class="fm-t">Денежный рынок <span class="fm-r low">низкий риск</span></div><div class="fm-d">Почти как вклад: доход около ставки ЦБ, забрать в любой день. Напр. ВИМ Денежный рынок.</div></div></div>
    <div class="fund-mini bond"><div class="fm-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="16" height="16" rx="2" stroke="#2B5BD7" stroke-width="2.2"/><path d="M8 9h8M8 13h8M8 17h5" stroke="#2B5BD7" stroke-width="2.2" stroke-linecap="round"/></svg></div>
      <div><div class="fm-t">Облигации <span class="fm-r mid">умеренный</span></div><div class="fm-d">Даёте в долг государству и компаниям под процент. Доход обычно выше вклада. Напр. ДОХОДЪ Облигации.</div></div></div>
    <div class="fund-mini stock"><div class="fm-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 18V9M9 18v-5M14 18v-9M19 18V6" stroke="#FF0032" stroke-width="2.4" stroke-linecap="round"/></svg></div>
      <div><div class="fm-t">Акции <span class="fm-r high">высокий</span></div><div class="fm-d">Доли компаний. Колеблется сильно, но на длинном сроке потенциал максимальный.</div></div></div>
    <div class="fund-mini mix"><div class="fm-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C8860B" stroke-width="2.2"/><path d="M12 3v9l6 4" stroke="#C8860B" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      <div><div class="fm-t">Смешанный <span class="fm-r mid">умеренный</span></div><div class="fm-d">Акции и облигации в одном пае. Баланс риска и дохода.</div></div></div>
    <button class="btn" onclick="go('s_intro3')">Дальше →</button>
  </section>

  <!-- ИНТРО 3 -->
  <section class="screen" id="s_intro3">
    <div class="bar"><span class="on"></span><span class="on"></span><span class="on"></span></div>
    <div class="kicker">{{ $content['intros'][2]['kicker'] }}</div>
    <h2>{{ $content['intros'][2]['h2'] }}</h2>
    <div class="lead" style="margin-bottom:16px">{{ $content['intros'][2]['lead'] }}</div>
    <div class="how-list">
      <div class="how-item"><div class="how-n">1</div><div>Каждый квартал — событие и подсказку, что сейчас выгоднее и насколько рискованно</div></div>
      <div class="how-item"><div class="how-n">2</div><div>Выбираете, куда вложить. Сверху всегда виден ваш портфель против вклада</div></div>
      <div class="how-item"><div class="how-n">3</div><div>В конце — сколько заработали против вклада, плюс промокод на витрину</div></div>
    </div>
    <div class="intro-note">{{ $content['intros'][2]['note'] }}</div>
    <button class="btn click_game_start" onclick="startGame()">{{ $content['intros'][2]['btn'] }}</button>
  </section>

  <!-- ИГРА -->
  <section class="screen" id="s_game">
    <div class="hud">
      <div class="hud-top">
        <div class="hud-year" id="hud-year">Квартал 1 из 12</div>
        <div class="hud-stat">Ставка ЦБ (год.): <span id="hud-rate">16%</span> · Инфляция: <span id="hud-infl">9%</span></div>
      </div>
      <div class="money-row">
        <div class="money you"><div class="ml"><svg width="11" height="11" viewBox="0 0 11 11"><circle cx="5.5" cy="5.5" r="5" fill="#FF0032"/></svg>Ваш портфель</div><div class="mv" id="m-you">300 000 ₽</div></div>
        <div class="money bank"><div class="ml"><svg width="11" height="11" viewBox="0 0 11 11"><circle cx="5.5" cy="5.5" r="5" fill="#9A9AA2"/></svg>Всё на вкладе</div><div class="mv" id="m-bank">300 000 ₽</div></div>
      </div>
      <div class="progress"><div class="progress-fill" id="pfill"></div></div>
    </div>
    <div id="event-zone"></div>
    <div class="choices" id="choices"></div>
    <div id="outcome-zone"></div>
  </section>

  <!-- ФИНАЛ -->
  <section class="screen" id="s_final">
    <div class="kicker">Прошло 3 года</div>
    <h2 id="final-title">Вот что у вас получилось</h2>
    <div class="bars">
      <div class="barwrap"><div class="barv you" id="bar-you" style="height:0">—</div><div class="barlabel">Ваш портфель<span>фонды</span></div></div>
      <div class="barwrap"><div class="barv bank" id="bar-bank" style="height:0">—</div><div class="barlabel">Всё на вкладе<span>как раньше</span></div></div>
      <div class="barwrap"><div class="barv max" id="bar-max" style="height:0">—</div><div class="barlabel">Максимум<span>идеальный путь</span></div></div>
    </div>
    <div class="result-line"><div class="rt" id="rl-tag">Разница</div><div class="rx" id="rl-text"></div></div>
    <div class="maxbox"><div class="mt">Можно было ещё лучше</div><div class="mx" id="max-text"></div></div>
    <div class="learned"><h3>Что вы теперь понимаете про фонды</h3><ul id="learned-list"></ul></div>

    <!-- ОПРОС -->
    <div class="survey-card" id="survey-card">
      <h3 class="survey-h">Пара вопросов — и заберёте промокод</h3>
      <div id="survey-questions"></div>
      <div class="survey-error" id="survey-error">Пожалуйста, ответьте на все вопросы.</div>
      <button class="btn" id="survey-submit" onclick="submitResult()">Получить промокод →</button>
    </div>

    <!-- НАГРАДА (после опроса) -->
    <div id="reward" style="display:none">
      <div class="promo">
        <div class="pk">Ваш промокод на витрину</div>
        <div class="pcode" id="promo-code">{{ $promo }}</div>
        <div class="pd">Введите этот промокод в поле «Промокод» при покупке фонда на витрине Финуслуг.</div>
      </div>
      <div class="lb">
        <div class="lb-h">Лидерборд</div>
        <div class="lb-head"><span class="lb-rank">#</span><span class="lb-name">Игрок</span><span class="lb-score">Портфель</span></div>
        <div id="lb-body"></div>
        <div class="lb-note" id="lb-note"></div>
      </div>
      <button class="btn click_open_fund" onclick="toFunds()">Перейти на Финуслуги и начать инвестировать</button>
      <button class="btn btn-ghost click_game_restart" onclick="restart()">Прожить заново — побить максимум</button>
    </div>
  </section>

</div>

  <!-- ВСПЛЫВАЮЩИЙ ИТОГ КВАРТАЛА -->
  <div class="outcome-overlay" id="outcome-overlay">
    <div class="outcome-sheet">
      <div class="outcome-h"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" fill="#FF0032"/><path d="M5 8l2 2 4-4" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span id="ov-title">Итог квартала</span></div>
      <div class="outcome-text" id="ov-text"></div>
      <div class="outcome-delta">
        <div class="delta"><div class="dl">Ваш выбор</div><div class="dv" id="ov-you">+0%</div></div>
        <div class="delta"><div class="dl">Вклад за квартал</div><div class="dv" id="ov-bank">+0%</div></div>
      </div>
      <button class="sheet-btn click_year_next" onclick="nextYear()">Следующий квартал →</button>
    </div>
  </div>

@php
$gameConfig = [
  'content' => $content,
  'user' => $user,
  'promo' => $promo,
  'shopUrl' => $shopUrl,
  'startAmount' => (int) config('game.start_amount', 300000),
  'routes' => [
    'result' => route('game.result'),
    'leaderboard' => route('game.leaderboard'),
    'event' => route('game.event'),
  ],
];
@endphp
<script>
window.GAME = {!! json_encode($gameConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
</script>
@verbatim
<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;
const CONTENT = window.GAME.content;
const YEARS = CONTENT.years;
const CHOICES = CONTENT.choices;
const START = window.GAME.startAmount;
const TOTAL = YEARS.length;

const IC={
 down:'<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 8l6 6 4-4 6 6" stroke="#FF0032" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 16v-4h-4" stroke="#FF0032" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
 up:'<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 16l6-6 4 4 6-6" stroke="#1A8049" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 8v4h-4" stroke="#1A8049" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
 bank:'<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M3 9l9-5 9 5" stroke="#2B5BD7" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 9v9M9 9v9M15 9v9M19 9v9" stroke="#2B5BD7" stroke-width="2.2" stroke-linecap="round"/><path d="M3 20h18" stroke="#2B5BD7" stroke-width="2.2" stroke-linecap="round"/></svg>',
 cash:'<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="3" y="6" width="18" height="12" rx="2" stroke="#1A8049" stroke-width="2.2"/><circle cx="12" cy="12" r="2.5" stroke="#1A8049" stroke-width="2.2"/></svg>',
 bond:'<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="16" height="16" rx="2" stroke="#2B5BD7" stroke-width="2.2"/><path d="M8 9h8M8 13h8M8 17h5" stroke="#2B5BD7" stroke-width="2.2" stroke-linecap="round"/></svg>',
 stock:'<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M4 18V9M9 18v-5M14 18v-9M19 18V6" stroke="#FF0032" stroke-width="2.4" stroke-linecap="round"/></svg>',
 split:'<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C8860B" stroke-width="2.2"/><path d="M12 3v9l6 4" stroke="#C8860B" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
 check:'<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" fill="#FF0032"/><path d="M5 8l2 2 4-4" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
};
const fmt=n=>Math.round(n).toLocaleString('ru-RU')+' ₽';
const esc=s=>String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

let year=0, you=START, bank=START, chosen=null, choicesLog=[];
const learnedSet=new Set();

function computeMax(){
 let m=START;
 for(const Y of YEARS){
   const best=Math.max(Y.ret.bank,Y.ret.cash,Y.ret.bond,Y.ret.stock,(Y.ret.bond+Y.ret.stock)/2);
   m=m*(1+best);
 }
 return m;
}
const MAXV=computeMax();

function logEvent(event,payload){
 try{
  fetch(window.GAME.routes.event,{method:'POST',keepalive:true,headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},body:JSON.stringify({event:event,payload:payload||null})}).catch(()=>{});
 }catch(e){}
}

function startGame(){year=0;you=START;bank=START;chosen=null;choicesLog=[];learnedSet.clear();logEvent('start');go('s_game');renderYear();}
function show(id){document.querySelectorAll('.screen').forEach(s=>s.classList.remove('active'));document.getElementById(id).classList.add('active');window.scrollTo(0,0);}
function go(id){show(id);}

function renderYear(){
 chosen=null;
 const Y=YEARS[year];
 document.getElementById('hud-year').textContent='Квартал '+(year+1)+' из '+TOTAL;
 document.getElementById('hud-rate').textContent=Y.rate+'%';
 document.getElementById('hud-infl').textContent=Y.infl+'%';
 document.getElementById('m-you').textContent=fmt(you);
 document.getElementById('m-bank').textContent=fmt(bank);
 document.getElementById('pfill').style.width=(year/TOTAL*100)+'%';
 document.getElementById('event-zone').innerHTML=
   '<div class="event"><div class="event-head"><div class="event-ic '+Y.ev.type+'">'+(IC[Y.ev.ic]||'')+'</div>'+
   '<div><div class="event-year">Событие · квартал '+(year+1)+'</div><div class="event-title">'+esc(Y.ev.title)+'</div></div></div>'+
   '<div class="event-text">'+esc(Y.ev.text)+'</div>'+
   '<div class="context"><b>Что это значит:</b> '+esc(Y.ctx)+'</div></div>';
 const cz=document.getElementById('choices');cz.innerHTML='';
 const bg={bank:'var(--blue-bg)',cash:'var(--green-bg)',bond:'var(--blue-bg)',stock:'var(--red-light)',split:'var(--amber-bg)'};
 const rlab={low:'низкий',mid:'умеренный',high:'высокий'};
 CHOICES.forEach(c=>{
   const d=document.createElement('div');d.className='choice click_year_choice';
   d.innerHTML='<div class="choice-top"><div class="ci" style="background:'+(bg[c.k]||'var(--g50)')+'">'+(IC[c.ic]||'')+'</div>'+
     '<div class="cmain"><div class="ctrow"><span class="ct">'+esc(c.t)+'</span><span class="htag '+c.risk+'">'+(rlab[c.risk]||'')+'</span></div>'+
     '<div class="cd">'+esc(c.hintBase)+'</div></div></div>';
   d.onclick=()=>choose(c.k);cz.appendChild(d);
 });
 document.getElementById('outcome-overlay').classList.remove('show');
}

function choose(k){
 if(chosen)return;chosen=k;
 const Y=YEARS[year];
 let r = k==='split' ? (Y.ret.bond+Y.ret.stock)/2 : Y.ret[k];
 you=you*(1+r); bank=bank*(1+Y.ret.bank);
 choicesLog.push({quarter:year+1,k:k,ret:r});
 logEvent('choice',{quarter:year+1,k:k});
 if(k==='stock'&&Y.ret.stock<0) learnedSet.add('Акции могут просесть — но на дистанции отрастают, если не паниковать');
 if(k==='cash') learnedSet.add('Фонд денежного рынка — почти как вклад, но деньги доступны в любой день');
 if(k==='bond') learnedSet.add('Облигации обычно дают больше вклада, особенно когда ставка падает');
 if(k==='stock'&&Y.ret.stock>0) learnedSet.add('Фонд акций приносит больше всех на росте рынка');
 if(k==='split') learnedSet.add('Разделить деньги между облигациями и акциями — это баланс риска');
 if(k==='bank') learnedSet.add('Вклад надёжен, но при низкой ставке проигрывает инфляции и фондам');
 const noteYou=(Y.note&&Y.note[k])|| 'Половина в облигациях, половина в акциях — усреднили риск.';
 const youPct=(r*100).toFixed(1), bankPct=(Y.ret.bank*100).toFixed(1);
 document.getElementById('m-you').textContent=fmt(you);
 document.getElementById('m-bank').textContent=fmt(bank);
 document.getElementById('pfill').style.width=((year+1)/TOTAL*100)+'%';
 document.getElementById('ov-title').textContent='Итог квартала '+(year+1);
 document.getElementById('ov-text').textContent=noteYou;
 const ovYou=document.getElementById('ov-you');
 ovYou.textContent=(r>=0?'+':'')+youPct+'%'; ovYou.className='dv '+(r>=Y.ret.bank?'pos':'neg');
 document.getElementById('ov-bank').textContent='+'+bankPct+'%';
 document.getElementById('outcome-overlay').classList.add('show');
}

function nextYear(){
 document.getElementById('outcome-overlay').classList.remove('show');
 year++;
 if(year>=YEARS.length)finish();else renderYear();
}

function finish(){
 go('s_final');
 logEvent('finish');
 document.getElementById('survey-card').style.display='';
 document.getElementById('reward').style.display='none';
 const diff=you-bank;
 const maxv=Math.max(you,bank,MAXV);
 setTimeout(()=>{
   document.getElementById('bar-you').style.height=(you/maxv*100)+'%';
   document.getElementById('bar-bank').style.height=(bank/maxv*100)+'%';
   document.getElementById('bar-max').style.height=(MAXV/maxv*100)+'%';
   document.getElementById('bar-you').textContent=fmt(you);
   document.getElementById('bar-bank').textContent=fmt(bank);
   document.getElementById('bar-max').textContent=fmt(MAXV);
 },100);
 const rl=document.getElementById('rl-text');
 if(diff>0){document.getElementById('rl-tag').textContent='Вы обогнали вклад';
   rl.innerHTML='Ваш портфель принёс на <b>'+fmt(diff)+'</b> больше, чем если бы все 3 года деньги лежали на вкладе.';}
 else if(diff<0){document.getElementById('rl-tag').textContent='В этот раз вклад впереди';
   rl.innerHTML='Портфель отстал от вклада на <b>'+fmt(-diff)+'</b>. Так бывает при слишком большом риске или выходе на просадке.';}
 else rl.textContent='Вышло вровень с вкладом.';
 document.getElementById('max-text').innerHTML='Максимально на этом сценарии можно было получить <b>'+fmt(MAXV)+'</b>. Вы набрали '+Math.round(you/MAXV*100)+'% от идеала. Как именно — не покажем: попробуйте угадать, перепройдя игру.';
 const ul=document.getElementById('learned-list');ul.innerHTML='';
 if(learnedSet.size===0) learnedSet.add('Фонды бывают разные: денежный рынок, облигации, акции — под разный риск');
 learnedSet.add('Разные фонды работают по-разному в разные годы — поэтому их сочетают');
 [...learnedSet].slice(0,5).forEach(t=>{const li=document.createElement('li');li.innerHTML=IC.check+esc(t);ul.appendChild(li);});
 renderSurvey();
}

function renderSurvey(){
 const wrap=document.getElementById('survey-questions');wrap.innerHTML='';
 (CONTENT.survey||[]).forEach((q,qi)=>{
   const block=document.createElement('div');block.className='survey-q';
   block.innerHTML='<div class="survey-qt">'+(qi+1)+'. '+esc(q.question)+'</div>';
   const opts=document.createElement('div');opts.className='survey-opts';
   (q.options||[]).forEach(o=>{
     const lbl=document.createElement('label');lbl.className='survey-opt';
     lbl.innerHTML='<input type="radio" name="sv_'+esc(q.id)+'" value="'+esc(o)+'"><span>'+esc(o)+'</span>';
     opts.appendChild(lbl);
   });
   block.appendChild(opts);wrap.appendChild(block);
 });
}

function submitResult(){
 const survey={};let allAnswered=true;
 (CONTENT.survey||[]).forEach(q=>{
   const sel=document.querySelector('input[name="sv_'+(window.CSS&&CSS.escape?CSS.escape(q.id):q.id)+'"]:checked');
   if(sel)survey[q.id]=sel.value;else allAnswered=false;
 });
 if(!allAnswered){document.getElementById('survey-error').style.display='block';return;}
 document.getElementById('survey-error').style.display='none';
 const btn=document.getElementById('survey-submit');btn.disabled=true;btn.textContent='Отправляем…';
 fetch(window.GAME.routes.result,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},body:JSON.stringify({score_you:Math.round(you),choices:choicesLog,survey:survey})})
  .then(r=>{if(!r.ok)throw new Error('bad');return r.json();})
  .then(data=>{showReward(data);})
  .catch(()=>{btn.disabled=false;btn.textContent='Получить промокод →';alert('Не удалось сохранить результат. Попробуйте ещё раз.');});
}

function showReward(data){
 document.getElementById('survey-card').style.display='none';
 document.getElementById('promo-code').textContent=(data&&data.promo)||window.GAME.promo;
 renderLeaderboard((data&&data.leaderboard)||[],data&&data.rank);
 document.getElementById('reward').style.display='';
 window.scrollTo(0,document.body.scrollHeight);
}

function renderLeaderboard(rows,myRank){
 const body=document.getElementById('lb-body');body.innerHTML='';
 rows.forEach(r=>{
   const row=document.createElement('div');row.className='lb-row'+(myRank&&r.rank===myRank?' lb-me':'');
   row.innerHTML='<span class="lb-rank">'+r.rank+'</span><span class="lb-name">'+esc(r.name)+'</span><span class="lb-score">'+fmt(r.score)+'</span>';
   body.appendChild(row);
 });
 const note=document.getElementById('lb-note');
 note.textContent = myRank ? ('Вы на '+myRank+'-м месте из '+( rows.length>=myRank? rows.length : myRank )+'+ игроков') : '';
}

function toFunds(){logEvent('open_fund');window.open(window.GAME.shopUrl,'_blank');}
function restart(){logEvent('restart');startGame();}

logEvent('open');
</script>
@endverbatim
</body>
</html>
