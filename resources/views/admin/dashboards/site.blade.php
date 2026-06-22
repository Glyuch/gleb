@extends('admin.layout')

@section('title', 'Главная')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
  .site .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:0 0 8px}
  .site .kpi{background:#fff;border:1px solid #e9ebef;border-radius:12px;padding:13px 15px}
  .site .kpi .v{font-size:24px;font-weight:800;letter-spacing:-.01em}
  .site .kpi .v.red{color:#FF0032}.site .kpi .v.blue{color:#2B5BD7}
  .site .kpi .l{font-size:12px;color:#6b7280;margin-top:3px}
  .site h2{font-size:16px;font-weight:700;margin:26px 0 12px}
  .site .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @@media(max-width:760px){.site .grid2{grid-template-columns:1fr}}
  .site .card{background:#fff;border:1px solid #e9ebef;border-radius:14px;padding:16px 18px;box-shadow:none}
  .site .card h3{margin:0 0 12px;font-size:14px;font-weight:700}
  .site .chartbox{position:relative;height:260px}
  .site table{width:100%;border-collapse:collapse;font-size:13.5px;background:transparent;box-shadow:none;border-radius:0}
  .site th,.site td{text-align:left;padding:10px 8px;border-bottom:1px solid #eef0f3}
  .site th{font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#9aa1ac;font-weight:700}
  .site .num{text-align:right;font-variant-numeric:tabular-nums}
  .site a.proj{color:#2B5BD7;text-decoration:none;font-weight:700}
</style>
@endpush

@section('content')
<div class="site">
  <div class="page-head">
    <h1>Сводка по сайту</h1>
    <div class="meta">
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
      Данные на {{ now()->format('d.m.Y, H:i') }} · <span class="fresh">обновлено только что</span>
    </div>
  </div>

  <div class="kpis">
    <div class="kpi"><div class="v red">{{ $report['users_total'] }}</div><div class="l">пользователей</div></div>
    <div class="kpi"><div class="v">{{ $report['users_verified'] }}</div><div class="l">подтвердили email</div></div>
    <div class="kpi"><div class="v">{{ $report['users_admin'] }}</div><div class="l">админов</div></div>
    <div class="kpi"><div class="v blue">{{ $report['sessions_active'] }}</div><div class="l">активны (15 мин)</div></div>
    <div class="kpi"><div class="v">{{ $report['reg_7d'] }}</div><div class="l">регистраций за 7 дней</div></div>
    <div class="kpi"><div class="v">{{ $report['reg_30d'] }}</div><div class="l">за 30 дней</div></div>
  </div>

  <h2>Регистрации</h2>
  <div class="grid2">
    <div class="card"><h3>Новые пользователи по дням (30 дней)</h3>
      <div class="chartbox"><canvas id="reg"></canvas></div></div>
    <div class="card"><h3>Подтверждение email</h3>
      <div class="chartbox"><canvas id="verified"></canvas></div></div>
  </div>

  <h2>Проекты</h2>
  <div class="card"><table>
    <tr><th>Проект</th><th class="num">Игроков</th><th class="num">Игр</th><th class="num">Событий</th></tr>
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
@endsection

@push('scripts')
<script>
const R = @json($report);
Chart.defaults.color = '#6b7280';
Chart.defaults.borderColor = '#eef0f3';
Chart.defaults.font.family = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
new Chart(document.getElementById('reg'), {type:'bar',
  data:{labels:R.reg_labels, datasets:[{data:R.reg_counts, backgroundColor:'#2B5BD7', borderRadius:5}]},
  options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true, ticks:{precision:0}, grid:{color:'#eef0f3'}}, x:{grid:{display:false}}}}});
new Chart(document.getElementById('verified'), {type:'doughnut',
  data:{labels:['Подтвердили','Не подтвердили'],
    datasets:[{data:[R.users_verified, Math.max(R.users_total - R.users_verified, 0)], backgroundColor:['#2B5BD7','#e9ebef'], borderWidth:0}]},
  options:{responsive:true, maintainAspectRatio:false, cutout:'62%', plugins:{legend:{position:'bottom'}}}});
</script>
@endpush
