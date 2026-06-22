@extends('admin.layout')

@section('title', 'Результаты · ФондыКвест')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js" integrity="sha384-oNtu+d18330MVFpltUTve1DatxCkkctlpA2AC3GulbVFOSqhHdDat3qHse/Lbuek" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<style>
.gr{--card:#fff;--ink:#1a1c20;--muted:#6b7280;--line:#e9ebef;--accent:#FF0032;--accent2:#2B5BD7;--warn:#f59e0b;--bad:#ef4444;color:#1a1c20;font:15px/1.55 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}

  
  *{box-sizing:border-box}
  
  .wrap{max-width:1180px;margin:0 auto;padding:32px 20px 90px;}
  header h1{margin:0 0 6px;font-size:30px;letter-spacing:-.02em}
  header h1 .r{color:var(--accent)}
  .sub{color:var(--muted);font-size:14px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin:24px 0 6px}
  .kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:15px 17px}
  .kpi .v{font-size:27px;font-weight:700;letter-spacing:-.02em}
  .kpi .v.g{color:var(--accent)}.kpi .v.b{color:var(--accent2)}.kpi .v.w{color:var(--warn)}.kpi .v.rd{color:var(--bad)}
  .kpi .l{color:var(--muted);font-size:12.5px;margin-top:3px}
  h2{margin:44px 0 4px;font-size:21px;letter-spacing:-.01em;border-left:3px solid var(--accent);padding-left:11px}
  .note{color:var(--muted);font-size:13px;margin:2px 0 16px;padding-left:14px}
  .tldr{background:#fff;border:1px solid var(--line);
    border-left:3px solid var(--accent);border-radius:14px;padding:18px 22px;margin:24px 0 8px}
  .tldr h3{margin:0 0 10px;font-size:16px}
  .tldr ul{margin:0;padding-left:20px} .tldr li{margin:5px 0}
  .tldr b{color:#1a1c20}
  .takeaway{font-size:13px;color:#4b5563;background:#f7f8fa;border-left:2px solid var(--accent2);
    padding:9px 13px;border-radius:0 8px 8px 0;margin:12px 0 2px}
  .grid{display:grid;gap:18px}.g2{grid-template-columns:1fr 1fr}
  @@media(max-width:820px){.g2{grid-template-columns:1fr}}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px 18px 14px}
  .card h3{margin:0 0 2px;font-size:15.5px}
  .card .cap{color:var(--muted);font-size:12.5px;margin-bottom:10px}
  .chartbox{position:relative;height:300px}.chartbox.tall{height:380px}
  table{width:100%;border-collapse:collapse;font-size:12.8px}
  th,td{text-align:left;padding:6px 7px;border-bottom:1px solid var(--line)}
  th{color:var(--muted);font-weight:600}
  td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
  .qtbl td{white-space:nowrap}
  .pos{color:#16a34a}.neg{color:#dc2626}
  .tag{display:inline-block;padding:1px 7px;border-radius:6px;font-size:11px;font-weight:700;color:#fff}
  .ev-up{background:#2B5BD7}.ev-down{background:#ef4444;color:#fff}.ev-neutral{background:#64748b;color:#fff}
  .chip{display:inline-block;padding:1px 7px;border-radius:6px;font-size:11px;font-weight:700;color:#fff}
  footer{color:var(--muted);font-size:12.5px;margin-top:54px;border-top:1px solid var(--line);padding-top:16px}

</style>
@endpush

@section('content')
<div class="gr">
  <div class="page-head">
    <h1>Результаты · ФондыКвест</h1>
    <div class="meta">
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
      Данные на {{ $report['generated_full'] }} · <span class="fresh">обновлено только что</span> · {{ $report['registered'] }} регистраций · {{ $report['total_results'] }} игр · {{ $report['N'] }} игроков
    </div>
  </div>
<div class="card" style="margin-bottom:8px"><h3 style="margin-bottom:3px">Активность по дням</h3>
<div class="cap">Завершённые и начатые игры за 30 дней. Сегодня показывается всегда — 0, если игр нет.</div>
<div class="chartbox" style="height:220px"><canvas id="daily"></canvas></div>
<div class="cap" id="daily-today" style="margin-top:8px"></div></div>



<div class="tldr" id="tldr"></div>
<div class="kpis" id="kpis"></div>

<h2>Воронка</h2>
<div class="note">Уникальные пользователи на каждом шаге (по событиям игры — здесь дедупликации по «последней попытке» нет, считаем всех участников).</div>
<div class="card"><div class="chartbox"><canvas id="funnel"></canvas></div>
  <div class="takeaway" id="tk-funnel"></div></div>

<h2>Результат игры</h2>
<div class="note">По {{ $report['N'] }} уникальным игрокам — последняя попытка каждого. Бенчмарки одинаковы для всех: вклад = {{ number_format($report['BANK'], 0, '', ' ') }} ₽, идеал = {{ number_format($report['MAXB'], 0, '', ' ') }} ₽.</div>
<div class="grid g2">
  <div class="card"><h3>Распределение итогового портфеля</h3>
    <div class="cap">Пунктир: среднее (синий), медиана (фиолет), порог «вклада» (серый).</div>
    <div class="chartbox"><canvas id="scoreHist"></canvas></div></div>
  <div class="card"><h3>Распределение ratio (% от идеала)</h3>
    <div class="cap">Пунктир: среднее и медиана.</div>
    <div class="chartbox"><canvas id="ratioHist"></canvas></div></div>
</div>
<div class="grid g2" style="margin-top:18px">
  <div class="card"><h3>Обыграли ли вклад?</h3><div class="cap">score_you &gt; {{ number_format($report['BANK'], 0, '', ' ') }} ₽</div>
    <div class="chartbox"><canvas id="beat"></canvas></div></div>
  <div class="card"><h3>Игроки против бенчмарков</h3>
    <div class="cap">Средний портфель vs «всё на вкладе» vs идеал. Ось от нуля.</div>
    <div class="chartbox"><canvas id="bench"></canvas></div></div>
</div>
<div class="takeaway" id="tk-result"></div>

<h2>Рыночные условия по кварталам</h2>
<div class="note">Сценарий №{{ $report['scenario_id'] ?? '—' }}: ставка ЦБ, инфляция и фактическая доходность каждого инструмента за квартал. Зелёным — лучший по доходности инструмент квартала.</div>
<div class="card" style="overflow-x:auto"><div id="qtable"></div></div>
<div class="card" style="margin-top:18px"><h3>Доходность инструментов по кварталам (%)</h3>
  <div class="cap">Видно, как менялись «фавориты»: акции волатильны (взлёты и крахи), облигации ускорялись на снижении ставки.</div>
  <div class="chartbox tall"><canvas id="retLines"></canvas></div></div>
<div class="takeaway" id="tk-cond"></div>

<h2>Как условия влияли на выбор</h2>
<div class="note">Все ходы по {{ $report['N'] }} последним попыткам ({{ $report['total_moves'] }} решений). Сопоставляем поведение игроков с тем, что происходило на рынке.</div>
<div class="grid g2">
  <div class="card"><h3>Суммарный выбор по инструментам</h3>
    <div class="cap">Сколько раз выбран каждый инструмент за все {{ count($report['quarters']) }} кварталов.</div>
    <div class="chartbox"><canvas id="instrTotal"></canvas></div></div>
  <div class="card"><h3>«Надёжно» vs «Фонды» по кварталам</h3>
    <div class="cap">Надёжно = вклад+ден.рынок. Фонды = облигации+акции+смешанный.</div>
    <div class="chartbox"><canvas id="safeFund"></canvas></div></div>
</div>
<div class="card" style="margin-top:18px"><h3>Доля акций в выборе vs доходность акций (по кварталам)</h3>
  <div class="cap">Гонятся ли за акциями после роста и бегут ли после краха? Столбики — доходность акций, линия — доля выбравших акции.</div>
  <div class="chartbox"><canvas id="chaseStock"></canvas></div></div>
<div class="card" style="margin-top:18px"><h3>Доля облигаций в выборе vs доходность облигаций</h3>
  <div class="cap">Когда облигации шли в ралли — толпа в них заходила.</div>
  <div class="chartbox"><canvas id="chaseBond"></canvas></div></div>
<div class="card" style="margin-top:18px"><h3>Структура выбора по кварталам (100%)</h3>
  <div class="cap">Полная картина: как смещался выбор от Q1 к Q12.</div>
  <div class="chartbox tall"><canvas id="byQuarter"></canvas></div></div>
<div class="takeaway" id="tk-choice"></div>

<h2>Стиль игры и результат</h2>
<div class="note">Каждая точка — игрок (последняя попытка). Связь стиля с ratio.</div>
<div class="grid g2">
  <div class="card"><h3>Доля акций vs ratio</h3><div class="cap" id="cap-stock"></div>
    <div class="chartbox"><canvas id="scStock"></canvas></div></div>
  <div class="card"><h3>Совпадений с оптимумом vs ratio</h3>
    <div class="cap" id="cap-fwd"></div>
    <div class="chartbox"><canvas id="scFwd"></canvas></div></div>
</div>
<div class="grid g2" style="margin-top:18px">
  <div class="card"><h3>Средний ratio по доле акций</h3>
    <div class="cap">Чем больше акций — тем хуже итог.</div>
    <div class="chartbox"><canvas id="bucketStock"></canvas></div></div>
  <div class="card"><h3>Поведенческие паттерны</h3>
    <div class="cap">Сводка стиля по {{ $report['N'] }} игрокам.</div>
    <div id="patterns" style="padding-top:4px"></div></div>
</div>
<div class="takeaway" id="tk-style"></div>

<h2>Переигрывания</h2>
<div class="note">Игроки, сыгравшие больше одного раза — рос ли результат со второй попытки.</div>
<div class="grid g2">
  <div class="card"><h3>Итог переигравших</h3><div class="chartbox"><canvas id="replayPie"></canvas></div></div>
  <div class="card"><h3>Первая → последняя попытка</h3><div class="cap">Каждая пара — один игрок. Ось от нуля.</div>
    <div class="chartbox"><canvas id="replayBars"></canvas></div></div>
</div>
<div class="takeaway" id="tk-replay"></div>

<h2>Опрос</h2>
<div class="note">% от {{ $report['N'] }} уникальных игроков (последняя попытка). Доли — от числа ответивших на вопрос.</div>
<div class="grid g2" id="surveyGrid"></div>
<div class="takeaway" id="tk-survey"></div>

<h2>Ключевой эффект выступления</h2>
<div class="note">Доля позитивных ответов на два главных вопроса.</div>
<div class="grid g2">
  <div class="card"><h3>«Игра помогла понять инвестиции»</h3><div class="cap" id="helpedCap"></div>
    <div class="chartbox"><canvas id="helpedGauge"></canvas></div></div>
  <div class="card"><h3>«Стали готовее рассматривать фонды»</h3><div class="cap" id="readyCap"></div>
    <div class="chartbox"><canvas id="readyGauge"></canvas></div></div>
</div>

<h2>Кросс-срезы</h2>
<div class="note">Связи между опытом, результатом игры, приоритетами и реальным поведением.</div>
<div class="card"><h3>Опыт × польза и готовность (% позитивных)</h3>
  <div class="cap">Новички = «Нет, никогда». Опытные = инвестировали/пробовали.</div>
  <div class="chartbox"><canvas id="crossExp"></canvas></div><div id="crossExpTbl"></div></div>
<div class="card" style="margin-top:18px"><h3>Результат игры × намерения (% позитивных)</h3>
  <div class="cap">Обыграли вклад vs нет — против «планирую вложить» и «готов к фондам».</div>
  <div class="chartbox"><canvas id="crossRes"></canvas></div><div id="crossResTbl"></div></div>
<div class="card" style="margin-top:18px"><h3>Заявленный приоритет × реальный стиль игры</h3>
  <div class="cap">Слова vs дела: средняя доля акций и фондов у группы.</div>
  <div class="chartbox"><canvas id="crossPrio"></canvas></div><div id="crossPrioTbl"></div></div>

<h2>Выводы и рекомендации</h2>
<div class="tldr" id="conclusions"></div>

<h2>Игроки</h2>
<div class="note">Все игроки, по лучшему результату каждого. Виден только администратору.</div>
<div class="card" style="overflow-x:auto"><div id="leaderboard"></div></div>

<footer id="foot"></footer>

</div>
@endsection

@push('scripts')
<script>

const D = @json($report);
if(window['chartjs-plugin-annotation']) Chart.register(window['chartjs-plugin-annotation']);
Chart.defaults.color='#6b7280';
Chart.defaults.font.family="-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
Chart.defaults.borderColor='#e9ebef';
const fmt=n=>Math.round(n).toLocaleString('ru-RU');
const GREEN='#22c55e',BLUE='#0ea5e9',WARN='#f59e0b',BAD='#ef4444',GREY='#64748b',PURP='#a855f7';
const LAB=D.instr_label,COL=D.instr_color;
const pctOf=(n,d)=>d>0?Math.round(n/d*100):0;
// Escape user-/admin-supplied strings before they go into innerHTML (player names, emails, survey questions).
const ESCMAP={'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'};
const esc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>ESCMAP[c]);
// Empty current-version scenario: show a clear notice instead of a wall of zeros / fabricated narrative.
const EMPTY=!D.N;
if(EMPTY){
  const b=document.createElement('div');
  b.className='tldr';b.style.borderLeftColor='#f59e0b';
  b.innerHTML='<h3>Пока нет завершённых игр в текущем сценарии</h3><p style="margin:0;color:#4b5563">Графики наполнятся после первых результатов. Воронка и активность ниже отражают текущие события.</p>';
  const ph=document.querySelector('.gr .page-head');if(ph)ph.after(b);
}
// Narrative figures derived from data (so they stay correct when the scenario changes).
const _sr=D.ret_q&&D.ret_q.stock?D.ret_q.stock:[],_br=D.ret_q&&D.ret_q.bond?D.ret_q.bond:[];
const worstStockQ=_sr.length?_sr.indexOf(Math.min(..._sr))+1:0,worstStockV=_sr.length?Math.min(..._sr):0;
const stockMaxV=_sr.length?Math.max(..._sr):0,bondMaxV=_br.length?Math.max(..._br):0;
const safePeak=Math.round(Math.max(0,...(D.safe_q&&D.safe_q.length?D.safe_q:[0])));
const bondPeak=Math.round(Math.max(0,...(D.bond_share_q&&D.bond_share_q.length?D.bond_share_q:[0])));



// ---- TL;DR ----
if(!EMPTY) document.getElementById('tldr').innerHTML=`<h3>Коротко о главном</h3><ul>
<li><b>${D.N} человек</b> довели игру до конца; <b>${pctOf(D.beat_bank,D.N)}%</b> (${D.beat_bank}/${D.N}) обыграли вклад, средний ratio <b>${D.ratio_mean}%</b> от идеала.</li>
<li><b>Вклад почти никто не держал</b>: депозит — самый редкий выбор (${D.total_choice.bank} из ${D.total_moves} ходов), в среднем <b>${D.pat.avg_fund}%</b> портфеля игроки держали в фондах.</li>
<li><b>Гонка за акциями каралась</b>: связь доли акций с результатом — <b>${D.cors.stock}</b>; кто избегал акций, набирал ratio <b>${D.stock_buckets[0][2]}%</b> против <b>${D.stock_buckets[2][2]}%</b> у стоковых.</li>
<li><b>Толпа реагировала на рынок</b>: в худшем для акций квартале (Q${worstStockQ}) их доля в выборе падала, а на ралли облигаций игроки массово уходили в них — туда же, куда указывал оптимум.</li>
<li><b>Эффект обучения сильный</b>: <b>${pctOf(D.helped_pos,D.helped_ans)}%</b> сказали, что игра помогла понять инвестиции, и <b>${pctOf(D.ready_pos,D.ready_ans)}%</b> стали готовее рассматривать фонды.</li>
</ul>`;

// ---- KPIs ----
document.getElementById('kpis').innerHTML=[
 ['v g',D.N,'уникальных игроков'],
 ['v',D.funnel[0][1],'открыли игру'],
 ['v b',pctOf(D.beat_bank,D.N)+'%',`обыграли вклад (${D.beat_bank}/${D.N})`],
 ['v',D.ratio_mean+'%','средний ratio'],
 ['v g',pctOf(D.helped_pos,D.helped_ans)+'%','игра помогла'],
 ['v b',pctOf(D.ready_pos,D.ready_ans)+'%','готовы к фондам'],
].map(([c,v,l])=>`<div class="kpi"><div class="${c}">${v}</div><div class="l">${l}</div></div>`).join('');

const opt=(e={})=>Object.assign({responsive:true,maintainAspectRatio:false,
  plugins:{legend:{display:false}},scales:{}},e);
const vlines=obj=>{const a={};for(const k in obj){const c=obj[k];
  a[c.id]= {type:'line',xMin:c.x,xMax:c.x,borderColor:c.color,borderWidth:2,borderDash:[5,4],
    label:{display:true,content:c.t,position:'start',backgroundColor:c.color,color:'#fff',font:{size:10,weight:700}}};}
  return a;};

// ---- funnel ----
new Chart(funnel,{type:'bar',data:{labels:D.funnel.map(f=>f[0]),
  datasets:[{data:D.funnel.map(f=>f[1]),backgroundColor:[BLUE,'#3b82f6',GREEN,WARN,BAD],borderRadius:7}]},
  options:opt({indexAxis:'y',plugins:{legend:{display:false},
    tooltip:{callbacks:{label:c=>`${c.raw} чел. (${pctOf(c.raw,D.funnel[0][1])}% от открывших)`}}},
    scales:{x:{beginAtZero:true,grid:{color:'#e9ebef'}},y:{grid:{display:false}}}})});
document.getElementById('tk-funnel').textContent=
  `Что значит: из ${D.funnel[0][1]} открывших ${D.funnel[3][1]} дошли до результата и опроса (${pctOf(D.funnel[3][1],D.funnel[0][1])}%) — высокая вовлечённость для игры на выступлении. До перехода на витрину Финуслуг дошли ${D.funnel[4][1]} — основная воронка обрывается на CTA, это точка роста.`;

// ---- scoreHist with annotation lines ----
new Chart(scoreHist,{type:'bar',data:{labels:D.score_labels,
  datasets:[{data:D.score_hist,backgroundColor:BLUE,borderRadius:6}]},
  options:opt({plugins:{legend:{display:false},
    annotation:{annotations:vlines({
      mean:{id:'mean',x:D.score_lines.mean,color:BLUE,t:'среднее'},
      med:{id:'med',x:D.score_lines.med,color:PURP,t:'медиана'},
      bank:{id:'bank',x:D.score_lines.bank,color:GREY,t:'вклад'}})},
    tooltip:{callbacks:{title:c=>`${c[0].label} ₽`,label:c=>`${c.raw} игроков`}}},
    scales:{y:{beginAtZero:true,grid:{color:'#e9ebef'},title:{display:true,text:'игроков'}},
      x:{grid:{display:false},title:{display:true,text:'тыс. ₽'}}}})});

// ---- ratioHist ----
new Chart(ratioHist,{type:'bar',data:{labels:D.ratio_labels,
  datasets:[{data:D.ratio_hist,backgroundColor:GREEN,borderRadius:6}]},
  options:opt({plugins:{legend:{display:false},
    annotation:{annotations:vlines({
      mean:{id:'mean',x:D.ratio_lines.mean,color:BLUE,t:'среднее'},
      med:{id:'med',x:D.ratio_lines.med,color:PURP,t:'медиана'}})},
    tooltip:{callbacks:{label:c=>`${c.raw} игроков`}}},
    scales:{y:{beginAtZero:true,grid:{color:'#e9ebef'},title:{display:true,text:'игроков'}},
      x:{grid:{display:false},title:{display:true,text:'% от идеала'}}}})});

// ---- beat ----
new Chart(beat,{type:'doughnut',data:{labels:['Обыграли вклад','Не обыграли'],
  datasets:[{data:[D.beat_bank,D.N-D.beat_bank],backgroundColor:[GREEN,GREY],borderWidth:0}]},
  options:opt({cutout:'62%',plugins:{legend:{display:true,position:'bottom'},
    tooltip:{callbacks:{label:c=>`${c.label}: ${c.raw} (${pctOf(c.raw,D.N)}%)`}}}})});

// ---- bench (axis from zero) ----
new Chart(bench,{type:'bar',data:{labels:['Средний игрок','Вклад (бенчмарк)','Идеал (max)'],
  datasets:[{data:[D.score_mean,D.BANK,D.MAXB],backgroundColor:[BLUE,GREY,GREEN],borderRadius:7}]},
  options:opt({plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>`${fmt(c.raw)} ₽`}}},
    scales:{y:{beginAtZero:true,grid:{color:'#e9ebef'},ticks:{callback:v=>Math.round(v/1000)+'k'}},
      x:{grid:{display:false}}}})});
document.getElementById('tk-result').textContent=
  `Что значит: средний игрок (${fmt(D.score_mean)} ₽) обошёл «всё на вкладе» (${fmt(D.BANK)} ₽) и подобрался к идеалу (${fmt(D.MAXB)} ₽) — в среднем ${D.ratio_mean}%. Распределение ratio плотное и высокое (медиана ${D.ratio_med}%): провальных партий почти нет, игра прощала ошибки.`;

// ---- conditions table ----
(function(){
  let h='<table class="qtbl"><tr><th>Квартал</th><th>Событие</th><th class="num">Ставка</th><th class="num">Инфл.</th>';
  D.instr.forEach(k=>h+=`<th class="num">${LAB[k]}</th>`);
  h+='<th>Топ-выбор</th><th>Оптимум*</th></tr>';
  D.qcards.forEach(c=>{
    h+=`<tr><td><b>Q${c.q}</b></td><td><span class="tag ev-${c.type}">${c.type==='up'?'рост':c.type==='down'?'спад':'нейтр.'}</span> ${c.title.split('. ').slice(1).join('. ')||c.title}</td>`;
    h+=`<td class="num">${c.rate}%</td><td class="num">${c.infl}%</td>`;
    D.instr.forEach(k=>{const v=c.ret[k];const cls=v>0?'pos':v<0?'neg':'';
      const best=k===c.cur?'style="font-weight:700;text-decoration:underline"':'';
      h+=`<td class="num ${cls}" ${best}>${v>0?'+':''}${v}%</td>`;});
    h+=`<td><span class="chip" style="background:${COL[c.top]}">${LAB[c.top]}</span></td>`;
    h+=`<td><span class="chip" style="background:${COL[c.fwd]}">${LAB[c.fwd]}</span></td></tr>`;
  });
  h+='</table><div class="cap" style="margin-top:8px">* Оптимум — инструмент, максимизирующий итог по правилам игры (доход взноса набегает в следующих кварталах). Подчёркнут — лучший по доходности самого квартала.</div>';
  document.getElementById('qtable').innerHTML=h;
})();

// ---- return lines ----
new Chart(retLines,{type:'line',data:{labels:D.quarters,
  datasets:D.instr.map(k=>({label:LAB[k],data:D.ret_q[k],borderColor:COL[k],
    backgroundColor:COL[k],tension:.3,borderWidth:k==='stock'?2.5:1.6,pointRadius:2}))},
  options:opt({plugins:{legend:{display:true,position:'bottom'},
    tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${c.raw>0?'+':''}${c.raw}%`}}},
    scales:{y:{grid:{color:'#e9ebef'},ticks:{callback:v=>v+'%'}},x:{grid:{display:false}}}})});
document.getElementById('tk-cond').textContent=
  `Что значит: акции (красная) — самый «качельный» инструмент: рывки до +${stockMaxV}% и обвалы до ${worstStockV}%. Облигации (зелёная) на снижении ставки доходили до +${bondMaxV}% за квартал. Вклад и денежный рынок шли ровно. Именно эта структура определила, что выгодная долгосрочная ставка была на облигации, а не на хайповые акции.`;

// ---- instr total ----
new Chart(instrTotal,{type:'bar',data:{labels:D.instr.map(k=>LAB[k]),
  datasets:[{data:D.instr.map(k=>D.total_choice[k]),backgroundColor:D.instr.map(k=>COL[k]),borderRadius:7}]},
  options:opt({plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>`${c.raw} выборов (${pctOf(c.raw,D.total_moves)}%)`}}},
    scales:{y:{beginAtZero:true,grid:{color:'#e9ebef'}},x:{grid:{display:false}}}})});

// ---- safe vs fund ----
new Chart(safeFund,{type:'line',data:{labels:D.quarters,datasets:[
  {label:'Надёжно (вклад+ден.рынок)',data:D.safe_q,borderColor:GREY,backgroundColor:'rgba(100,116,139,.15)',fill:true,tension:.3},
  {label:'Фонды (обл.+акции+смеш.)',data:D.fund_q,borderColor:GREEN,backgroundColor:'rgba(34,197,94,.15)',fill:true,tension:.3}]},
  options:opt({plugins:{legend:{display:true,position:'bottom'},
    tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${c.raw}%`}}},
    scales:{y:{beginAtZero:true,max:100,grid:{color:'#e9ebef'},ticks:{callback:v=>v+'%'}},x:{grid:{display:false}}}})});

// ---- chase stock (bar return + line share) ----
new Chart(chaseStock,{data:{labels:D.quarters,datasets:[
  {type:'bar',label:'Доходность акций, %',data:D.ret_q.stock,yAxisID:'y',
    backgroundColor:D.ret_q.stock.map(v=>v>=0?'rgba(239,68,68,.45)':'rgba(239,68,68,.9)'),borderRadius:5},
  {type:'line',label:'Доля выбравших акции, %',data:D.stock_share_q,yAxisID:'y1',
    borderColor:'#2B5BD7',backgroundColor:'#2B5BD7',tension:.3,pointRadius:3,borderWidth:2}]},
  options:opt({plugins:{legend:{display:true,position:'bottom'},
    tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${c.raw}%`}}},
    scales:{y:{position:'left',grid:{color:'#e9ebef'},ticks:{callback:v=>v+'%'},title:{display:true,text:'доходность'}},
      y1:{position:'right',grid:{display:false},min:0,max:60,ticks:{callback:v=>v+'%'},title:{display:true,text:'доля выбора'}},
      x:{grid:{display:false}}}})});

// ---- chase bond ----
new Chart(chaseBond,{data:{labels:D.quarters,datasets:[
  {type:'bar',label:'Доходность облигаций, %',data:D.ret_q.bond,yAxisID:'y',
    backgroundColor:'rgba(34,197,94,.45)',borderRadius:5},
  {type:'line',label:'Доля выбравших облигации, %',data:D.bond_share_q,yAxisID:'y1',
    borderColor:'#2B5BD7',backgroundColor:'#2B5BD7',tension:.3,pointRadius:3,borderWidth:2}]},
  options:opt({plugins:{legend:{display:true,position:'bottom'},
    tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${c.raw}%`}}},
    scales:{y:{position:'left',grid:{color:'#e9ebef'},ticks:{callback:v=>v+'%'},title:{display:true,text:'доходность'}},
      y1:{position:'right',grid:{display:false},min:0,max:60,ticks:{callback:v=>v+'%'},title:{display:true,text:'доля выбора'}},
      x:{grid:{display:false}}}})});

// ---- by quarter stacked ----
new Chart(byQuarter,{type:'bar',data:{labels:D.quarters,
  datasets:D.instr.map(k=>({label:LAB[k],data:D.byq_pct[k],backgroundColor:COL[k]}))},
  options:opt({plugins:{legend:{display:true,position:'bottom'},
    tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${c.raw}%`}}},
    scales:{x:{stacked:true,grid:{display:false}},
      y:{stacked:true,max:100,grid:{color:'#e9ebef'},ticks:{callback:v=>v+'%'}}}})});
document.getElementById('tk-choice').textContent=
  `Что значит: выбор не был случайным. В худшем для акций квартале (Q${worstStockQ}, ${worstStockV}%) их доля в выборе резко падала — классическое бегство в защиту (надёжное достигало ${safePeak}%). Когда облигации шли в ралли, их доля в выборе доходила до ${bondPeak}% — игроки улавливали тренд. Депозит (серый) почти не использовали ни в одном квартале.`;

// ---- scatter helper ----
function scatter(canvas,xs,ys,color,xlabel){
  new Chart(canvas,{type:'scatter',data:{datasets:[{data:xs.map((x,i)=>({x:x,y:ys[i]})),
    backgroundColor:color,pointRadius:5,pointHoverRadius:7}]},
    options:opt({plugins:{legend:{display:false},
      tooltip:{callbacks:{label:c=>`${xlabel}: ${c.raw.x}, ratio: ${c.raw.y}%`}}},
      scales:{x:{grid:{color:'#e9ebef'},title:{display:true,text:xlabel}},
        y:{grid:{color:'#e9ebef'},title:{display:true,text:'ratio, %'}}}})});
}
scatter(scStock,D.players.map(p=>Math.round(p.stock*100)),D.players.map(p=>p.ratio),'rgba(239,68,68,.8)','доля акций, %');
scatter(scFwd,D.players.map(p=>p.fwdbest),D.players.map(p=>p.ratio),'rgba(34,197,94,.8)',`совпадений с оптимумом (из ${D.quarters.length})`);
document.getElementById('cap-stock').textContent=`Корреляция: ${D.cors.stock} (чем больше акций — тем ниже ratio).`;
document.getElementById('cap-fwd').textContent=`Корреляция: ${D.cors.fwdbest} (попадание в оптимум поднимает ratio).`;

// ---- bucket stock ----
new Chart(bucketStock,{type:'bar',data:{labels:D.stock_buckets.map(b=>`${b[0]} (n=${b[1]})`),
  datasets:[{data:D.stock_buckets.map(b=>b[2]),backgroundColor:[GREEN,WARN,BAD],borderRadius:7}]},
  options:opt({plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>`средний ratio ${c.raw}%`}}},
    scales:{y:{beginAtZero:true,max:100,grid:{color:'#e9ebef'},ticks:{callback:v=>v+'%'}},x:{grid:{display:false}}}})});

// ---- patterns ----
document.getElementById('patterns').innerHTML=`<table>
<tr><td>Средн. число переключений за ${D.quarters.length} кв.</td><td class="num"><b>${D.pat.avg_switches}</b></td></tr>
<tr><td>Средн. число разных инструментов</td><td class="num"><b>${D.pat.avg_distinct}</b> из ${D.instr.length}</td></tr>
<tr><td>Доля в фондах (среднее)</td><td class="num"><b>${D.pat.avg_fund}%</b></td></tr>
<tr><td>«Всегда только вклад/защита»</td><td class="num"><b>${D.pat.always_bank}</b></td></tr>
<tr><td>«Всё в акции»</td><td class="num"><b>${D.pat.all_stock}</b></td></tr>
<tr><td>Совпадений с оптимумом (среднее)</td><td class="num"><b>${D.pat.avg_fwdbest}</b> из ${D.quarters.length}</td></tr></table>`;
document.getElementById('tk-style').textContent=
  `Что значит: побеждала умеренность. Игроки активно управляли (≈${D.pat.avg_switches} переключений, ${D.pat.avg_distinct} инструмента), а крайностей было мало (${D.pat.all_stock} «всё в акции», ${D.pat.always_bank} «только защита»). Лучшие результаты — у тех, кто меньше брал акции и чаще попадал в оптимум. Перебор с акциями и частые метания результат снижали.`;

// ---- replays ----
new Chart(replayPie,{type:'doughnut',data:{labels:['Улучшили','Без изменений','Ухудшили'],
  datasets:[{data:[D.improved,D.same,D.worsened],backgroundColor:[GREEN,GREY,BAD],borderWidth:0}]},
  options:opt({cutout:'60%',plugins:{legend:{display:true,position:'bottom'},
    subtitle:{display:true,position:'top',text:`${D.replays.length} переигравших`,color:'#6b7280',padding:4}}})});
new Chart(replayBars,{type:'bar',data:{labels:D.replays.map(r=>'#'+r.uid),
  datasets:[{label:'Первая',data:D.replays.map(r=>r.first),backgroundColor:GREY,borderRadius:4},
    {label:'Последняя',data:D.replays.map(r=>r.last),backgroundColor:GREEN,borderRadius:4}]},
  options:opt({plugins:{legend:{display:true,position:'bottom'},
    tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${fmt(c.raw)} ₽`}}},
    scales:{y:{beginAtZero:true,grid:{color:'#e9ebef'},ticks:{callback:v=>Math.round(v/1000)+'k'}},x:{grid:{display:false}}}})});
document.getElementById('tk-replay').textContent=
  `Что значит: из ${D.replays.length} переигравших ${D.improved} улучшили результат и лишь ${D.worsened} ухудшили — повторная попытка почти всегда работает как обучение. Игра мотивирует попробовать снова и сделать лучше.`;

// ---- survey ----
const PAL=[GREEN,'#5ee08a',WARN,BAD,BLUE,PURP];
const sg=document.getElementById('surveyGrid');
D.survey_stats.forEach((q,i)=>{
  const d=document.createElement('div');d.className='card';
  d.innerHTML=`<h3>${esc(q.question)}</h3><div class="cap">ответили: ${q.answered} из ${D.N}</div>
    <div class="chartbox"><canvas id="sv${i}"></canvas></div>`;
  sg.appendChild(d);
  const o=q.options,c=o.map(x=>q.counts[x]||0);
  new Chart(d.querySelector('canvas'),{type:'bar',data:{labels:o,
    datasets:[{data:c,backgroundColor:o.map((_,j)=>PAL[j%PAL.length]),borderRadius:6}]},
    options:opt({indexAxis:'y',plugins:{legend:{display:false},
      tooltip:{callbacks:{label:cc=>`${cc.raw} чел. (${pctOf(cc.raw,q.answered)}%)`}}},
      scales:{x:{beginAtZero:true,grid:{color:'#e9ebef'}},y:{grid:{display:false}}}})});
});
document.getElementById('tk-survey').textContent=
  `Что значит: аудитория тепло приняла формат — преобладают позитивные ответы про пользу и готовность к фондам. Распределение по приоритетам и опыту помогает понять, на кого опираться в продукте.`;

// ---- gauges ----
const centerText=(t,col)=>({id:'ct'+col+t,afterDraw(ch){
  const{ctx,chartArea:{left,right,top,bottom}}=ch;ctx.save();
  ctx.font='700 34px -apple-system,sans-serif';ctx.fillStyle=col;ctx.textAlign='center';ctx.textBaseline='middle';
  ctx.fillText(t,(left+right)/2,(top+bottom)/2);ctx.restore();}});
document.getElementById('helpedCap').textContent=`${D.helped_pos} из ${D.helped_ans} ответивших`;
document.getElementById('readyCap').textContent=`${D.ready_pos} из ${D.ready_ans} ответивших`;
new Chart(helpedGauge,{type:'doughnut',data:{labels:['Да/Скорее да','Остальные'],
  datasets:[{data:[D.helped_pos,D.helped_ans-D.helped_pos],backgroundColor:[GREEN,'#e9ebef'],borderWidth:0}]},
  options:opt({cutout:'72%',plugins:{legend:{display:true,position:'bottom'},tooltip:{callbacks:{label:c=>`${c.label}: ${c.raw}`}}}}),
  plugins:[centerText(pctOf(D.helped_pos,D.helped_ans)+'%',GREEN)]});
new Chart(readyGauge,{type:'doughnut',data:{labels:['Да/Скорее да','Остальные'],
  datasets:[{data:[D.ready_pos,D.ready_ans-D.ready_pos],backgroundColor:[BLUE,'#e9ebef'],borderWidth:0}]},
  options:opt({cutout:'72%',plugins:{legend:{display:true,position:'bottom'},tooltip:{callbacks:{label:c=>`${c.label}: ${c.raw}`}}}}),
  plugins:[centerText(pctOf(D.ready_pos,D.ready_ans)+'%',BLUE)]});

// ---- cross ----
function grouped(cid,tid,defs){
  const labels=Object.keys(defs[0].map);
  const ds=defs.map(s=>({label:s.name,backgroundColor:s.color,borderRadius:6,
    data:labels.map(l=>{const d=s.map[l];return d?Math.round(d[0]/d[1]*100):0;})}));
  new Chart(document.getElementById(cid),{type:'bar',data:{labels,datasets:ds},
    options:opt({plugins:{legend:{display:true,position:'bottom'},
      tooltip:{callbacks:{label:c=>{const d=defs[c.datasetIndex].map[c.label];return `${c.dataset.label}: ${c.raw}% (${d[0]}/${d[1]})`;}}}},
      scales:{y:{beginAtZero:true,max:100,grid:{color:'#e9ebef'},ticks:{callback:v=>v+'%'}},x:{grid:{display:false}}}})});
  let h='<table><tr><th>Группа</th>'+defs.map(s=>`<th class="num">${s.name}</th>`).join('')+'</tr>';
  labels.forEach(l=>{h+=`<tr><td>${l}</td>`+defs.map(s=>{const d=s.map[l];
    return `<td class="num">${d?Math.round(d[0]/d[1]*100)+'% ('+d[0]+'/'+d[1]+')':'—'}</td>`;}).join('')+'</tr>';});
  document.getElementById(tid).innerHTML=h+'</table>';
}
grouped('crossExp','crossExpTbl',[{name:'Игра помогла',map:D.exp_helped,color:GREEN},{name:'Готовы к фондам',map:D.exp_ready,color:BLUE}]);
grouped('crossRes','crossResTbl',[{name:'Планируют вложить',map:D.plan_pos,color:WARN},{name:'Готовы к фондам',map:D.readyR_pos,color:BLUE}]);
(function(){
  const labels=D.prio_rows.map(r=>`${r.prio} (n=${r.n})`);
  new Chart(crossPrio,{type:'bar',data:{labels,datasets:[
    {label:'Доля акций (рисковые)',data:D.prio_rows.map(r=>r.stock),backgroundColor:BAD,borderRadius:6},
    {label:'Доля фондов всего',data:D.prio_rows.map(r=>r.fund),backgroundColor:GREEN,borderRadius:6}]},
    options:opt({plugins:{legend:{display:true,position:'bottom'},tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${c.raw}%`}}},
      scales:{y:{beginAtZero:true,max:100,grid:{color:'#e9ebef'},ticks:{callback:v=>v+'%'}},x:{grid:{display:false}}}})});
  let h='<table><tr><th>Приоритет</th><th class="num">Игроков</th><th class="num">Доля акций</th><th class="num">Доля фондов</th></tr>';
  D.prio_rows.forEach(r=>h+=`<tr><td>${esc(r.prio)}</td><td class="num">${r.n}</td><td class="num">${r.stock}%</td><td class="num">${r.fund}%</td></tr>`);
  document.getElementById('crossPrioTbl').innerHTML=h+'</table>';
})();

// ---- conclusions ----
if(!EMPTY) document.getElementById('conclusions').innerHTML=`<h3>Что сработало и что показывают данные</h3><ul>
<li><b>Игра доносит главный месседж.</b> ${pctOf(D.beat_bank,D.N)}% обыграли вклад, средний портфель выше депозита — игроки на себе почувствовали, что фонды на длинном горизонте обгоняют вклад. ${pctOf(D.helped_pos,D.helped_ans)}% подтвердили, что стало понятнее.</li>
<li><b>Поведение реалистичное и поучительное.</b> Игроки гнались за доходностью и обжигались на акциях (связь доли акций с ratio ${D.cors.stock}), бежали в защиту после краха и заходили в облигации на ралли. Это ровно те ошибки и реакции, которые важно показать новичку.</li>
<li><b>Победила диверсификация, а не хайп.</b> Лучшие ratio — у умеренных (смешанный фонд и облигации), а не у любителей акций. Стоит усилить этот вывод в финале игры: «скучное» часто выигрывает.</li>
<li><b>Опрос: тёплый приём и готовность действовать.</b> ${pctOf(D.ready_pos,D.ready_ans)}% стали готовее рассматривать фонды; среди обыгравших вклад готовность ещё выше — успех в игре конвертируется в намерение.</li>
<li><b>Точка роста — переход на витрину.</b> До «Финуслуг» дошли лишь ${D.funnel[4][1]} из ${D.funnel[0][1]}. Рекомендация: усилить CTA в финале (промокод GAME1, явная кнопка, объяснение следующего шага), вести A/B по тексту кнопки.</li>
<li><b>Переигрывание учит.</b> ${D.improved} из ${D.replays.length} переигравших улучшили результат — стоит прямо предлагать «сыграй ещё раз и побей свой результат».</li>
</ul>`;

document.getElementById('foot').innerHTML=
  `Источник: gleb.finance · game_results / game_events / game_contents (сценарий №${D.scenario_id??'—'}) · опрос в game_results.survey_answers.<br>`+
  `Дедупликация: последняя попытка каждого игрока (${D.N} из ${D.total_results} игр). Доходности и условия — учебные (из сценария). Только чтение БД; код и репозиторий не затрагивались.`;

// ---- daily activity (freshness) ----
(function(){
  const dd=D.daily;
  new Chart(document.getElementById('daily'),{data:{labels:dd.labels,datasets:[
    {type:'bar',label:'Завершено',data:dd.completed,backgroundColor:'#2B5BD7',borderRadius:4},
    {type:'line',label:'Начато',data:dd.started,borderColor:'#f59e0b',backgroundColor:'#f59e0b',tension:.3,pointRadius:2,borderWidth:2}]},
    options:opt({plugins:{legend:{display:true,position:'bottom'}},
      scales:{y:{beginAtZero:true,ticks:{precision:0},grid:{color:'#e9ebef'}},x:{grid:{display:false}}}})});
  const li=dd.labels.length-1;
  document.getElementById('daily-today').innerHTML=`Сегодня, ${dd.labels[li]} — <b>${dd.today_completed}</b> завершённых, <b>${dd.today_started}</b> начатых`;
})();
// ---- leaderboard (admin-only) ----
(function(){
  const rows=D.leaderboard||[];
  if(!rows.length){document.getElementById('leaderboard').innerHTML='<div class="cap">Пока нет игроков.</div>';return;}
  let h='<table><tr><th>#</th><th>Игрок</th><th class="num">Лучший счёт</th><th class="num">Ratio</th><th>Вклад</th><th class="num">Игр</th></tr>';
  rows.forEach(r=>{h+=`<tr><td>${r.rank}</td><td>${r.name?esc(r.name):'—'}<span style="display:block;color:#6b7280;font-size:11px">${esc(r.email)}</span></td>`+
    `<td class="num">${fmt(r.best_score)} ₽</td><td class="num">${r.ratio}%</td>`+
    `<td>${r.beat_bank?'<span style="color:#2B5BD7;font-weight:700">да</span>':'<span style="color:#FF0032;font-weight:700">нет</span>'}</td><td class="num">${r.plays}</td></tr>`;});
  document.getElementById('leaderboard').innerHTML=h+'</table>';
})();

</script>
@endpush
