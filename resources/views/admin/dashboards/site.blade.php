<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>gleb.finance — дашборд сайта</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
  :root{--bg:#0b1020;--card:#141a2e;--ink:#e8ecf6;--muted:#9aa7c7;--line:#283150;--accent:#22c55e;--accent2:#0ea5e9}
  *{box-sizing:border-box}
  body{margin:0;background:linear-gradient(160deg,#0b1020,#0e1530);color:var(--ink);
    font:15px/1.55 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
  .wrap{max-width:1180px;margin:0 auto;padding:20px 20px 90px}
  h1{margin:14px 0 2px;font-size:28px;letter-spacing:-.02em}
  .sub{color:var(--muted);font-size:14px;margin-bottom:20px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin:16px 0}
  .kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:15px 17px}
  .kpi .v{font-size:27px;font-weight:700}.kpi .v.g{color:var(--accent)}.kpi .v.b{color:var(--accent2)}
  .kpi .l{color:var(--muted);font-size:12.5px;margin-top:3px}
  h2{margin:34px 0 10px;font-size:20px;border-left:3px solid var(--accent);padding-left:11px}
  .grid{display:grid;gap:18px}.g2{grid-template-columns:1fr 1fr}
  @@media(max-width:820px){.g2{grid-template-columns:1fr}}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px}
  .card h3{margin:0 0 10px;font-size:15.5px}
  .chartbox{position:relative;height:300px}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  th,td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line)}
  th{color:var(--muted);font-weight:600}.num{text-align:right;font-variant-numeric:tabular-nums}
  a.proj{color:var(--accent2);text-decoration:none;font-weight:700}
</style>
</head>
<body>
@include('admin._dashnav')
<div class="wrap">
  <h1>gleb.finance — сайт</h1>
  <div class="sub">Сводка по сайту в реальном времени.</div>

  <div class="kpis">
    <div class="kpi"><div class="v g">{{ $report['users_total'] }}</div><div class="l">пользователей</div></div>
    <div class="kpi"><div class="v">{{ $report['users_verified'] }}</div><div class="l">подтвердили email</div></div>
    <div class="kpi"><div class="v">{{ $report['users_admin'] }}</div><div class="l">админов</div></div>
    <div class="kpi"><div class="v b">{{ $report['sessions_active'] }}</div><div class="l">активны (15 мин)</div></div>
    <div class="kpi"><div class="v">{{ $report['reg_7d'] }}</div><div class="l">регистраций за 7 дней</div></div>
    <div class="kpi"><div class="v">{{ $report['reg_30d'] }}</div><div class="l">за 30 дней</div></div>
  </div>

  <h2>Регистрации</h2>
  <div class="grid g2">
    <div class="card"><h3>Новые пользователи по дням (30 дней)</h3>
      <div class="chartbox"><canvas id="reg"></canvas></div></div>
    <div class="card"><h3>Подтверждение email</h3>
      <div class="chartbox"><canvas id="verified"></canvas></div></div>
  </div>

  <h2>Проекты</h2>
  <div class="card"><table>
    <tr><th>Проект</th><th class="num">Игроков</th><th class="num">Сессий/игр</th><th class="num">Событий</th></tr>
    @foreach ($report['projects'] as $p)
    <tr>
      <td><a class="proj" href="{{ $p['href'] }}">{{ $p['title'] }}</a></td>
      <td class="num">{{ $p['players'] }}</td>
      <td class="num">{{ $p['games'] }}</td>
      <td class="num">{{ $p['events'] }}</td>
    </tr>
    @endforeach
  </table></div>
</div>
<script>
const R = @json($report);
Chart.defaults.color='#9aa7c7';Chart.defaults.borderColor='#283150';
new Chart(document.getElementById('reg'),{type:'bar',
  data:{labels:R.reg_labels,datasets:[{data:R.reg_counts,backgroundColor:'#0ea5e9',borderRadius:5}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true,ticks:{precision:0},grid:{color:'#283150'}},x:{grid:{display:false}}}}});
new Chart(document.getElementById('verified'),{type:'doughnut',
  data:{labels:['Подтвердили','Не подтвердили'],
    datasets:[{data:[R.users_verified,Math.max(R.users_total-R.users_verified,0)],backgroundColor:['#22c55e','#283150'],borderWidth:0}]},
  options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'bottom'}}}});
</script>
</body>
</html>
