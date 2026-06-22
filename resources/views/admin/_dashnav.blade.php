<div style="max-width:1180px;margin:0 auto;padding:14px 20px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:13.5px">
  <a href="{{ route('admin.dashboards.gameresults') }}" style="color:{{ request()->routeIs('admin.dashboards.gameresults') ? '#22c55e' : '#9aa7c7' }};text-decoration:none;font-weight:700">Игра · результаты</a>
  <a href="{{ route('admin.dashboards.site') }}" style="color:{{ request()->routeIs('admin.dashboards.site') ? '#22c55e' : '#9aa7c7' }};text-decoration:none;font-weight:700">Сайт</a>
  <span style="width:1px;height:16px;background:#283150"></span>
  <a href="{{ route('admin.game.content') }}" style="color:#9aa7c7;text-decoration:none">Контент игры</a>
  <a href="{{ url('/game') }}" target="_blank" style="color:#0ea5e9;text-decoration:none;margin-left:auto">Открыть игру →</a>
</div>
