@extends('admin.layout')

@section('title', $profile->displayName())

@push('head')
<style>
  .nshow .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:0 0 18px}
  .nshow .kpi{background:#fff;border:1px solid #e9ebef;border-radius:12px;padding:13px 15px}
  .nshow .kpi .v{font-size:23px;font-weight:800;letter-spacing:-.01em}
  .nshow .kpi .v.red{color:#FF0032}.nshow .kpi .v.blue{color:#2B5BD7}
  .nshow .kpi .l{font-size:12px;color:#6b7280;margin-top:3px}
  .nshow .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
  @@media(max-width:820px){.nshow .grid2{grid-template-columns:1fr}}
  .nshow .card{background:#fff;border:1px solid #e9ebef;border-radius:14px;padding:16px 18px}
  .nshow .card h3{margin:0 0 12px;font-size:14px;font-weight:700}
  .nshow .pill{display:inline-block;padding:3px 11px;border-radius:999px;font-size:12.5px;font-weight:700;vertical-align:middle;margin-left:8px}
  .nshow .pill.active{background:#EAF7EE;color:#1a7f37}
  .nshow .pill.paused{background:#FFF4E5;color:#b25e00}
  .nshow .pill.onboarding{background:#EAF0FB;color:#2B5BD7}
  .nshow table.meals{width:100%;border-collapse:collapse;font-size:13px}
  .nshow table.meals th,.nshow table.meals td{text-align:left;padding:7px 8px;border-bottom:1px solid #f0f0f2}
  .nshow table.meals th{font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#6b7280;font-weight:700}
  .nshow table.meals .num{text-align:right;font-variant-numeric:tabular-nums}
  .nshow .fld{margin-bottom:12px}
  .nshow label{display:block;font-size:12.5px;font-weight:700;color:#3a4150;margin-bottom:5px}
  .nshow input[type=text],.nshow input[type=time],.nshow input[type=number]{width:100%;padding:9px 11px;border:1.5px solid #e2e2e8;border-radius:8px;font-size:14px}
  .nshow textarea.ai{width:100%;min-height:180px;padding:11px;border:1.5px solid #e2e2e8;border-radius:10px;font-size:13px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;line-height:1.5;resize:vertical}
  .nshow .row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .nshow .back{color:#2B5BD7;text-decoration:none;font-weight:600;font-size:13px}
  .nshow .muted{color:#9aa0ab}
  .nshow .sig{color:#2B5BD7;font-weight:700;text-decoration:none}
</style>
@endpush

@section('content')
<div class="nshow">
  <p><a class="back" href="{{ route('admin.nutrition.index') }}">← Все профили</a></p>

  <div class="page-head">
    <h1 style="display:inline">{{ $profile->displayName() }}</h1>
    <span class="pill {{ $profile->status }}">{{ ['active'=>'активен','paused'=>'на паузе','onboarding'=>'онбординг'][$profile->status] ?? $profile->status }}</span>
    <div class="meta">{{ $profile->username ? '@'.$profile->username : 'id '.$profile->telegram_user_id }} · {{ $profile->phase === 'maintenance' ? 'поддержка' : 'программа' }}</div>
  </div>

  <div class="kpis">
    <div class="kpi"><div class="v">{{ $day > 0 ? $day : '—' }}</div><div class="l">день программы</div></div>
    <div class="kpi"><div class="v blue">{{ $avgScore !== null ? number_format($avgScore, 1, ',', '') : '—' }}</div><div class="l">средний балл 7д</div></div>
    <div class="kpi"><div class="v {{ ($weightDelta ?? 0) > 0 ? 'red' : '' }}">{{ $weightDelta !== null ? ($weightDelta > 0 ? '+' : '').number_format($weightDelta, 1, ',', '') : '—' }}</div><div class="l">вес Δ 30д, кг</div></div>
    <div class="kpi"><div class="v">{{ $weightSeries->isNotEmpty() ? number_format((float) $weightSeries->last()->value, 1, ',', '').' кг' : '—' }}</div><div class="l">текущий вес</div></div>
  </div>

  <div class="grid2">
    <div>
      <div class="card" style="margin-bottom:16px">
        <h3>Динамика веса (30 дней)</h3>
        @if ($weightSeries->count() >= 2)
          @php
            $vals = $weightSeries->map(fn ($m) => (float) $m->value)->all();
            $min = min($vals); $max = max($vals); $span = ($max - $min) ?: 1;
            $n = count($vals); $w = 520; $h = 120; $pad = 8;
            $pts = [];
            foreach ($vals as $i => $v) {
              $x = $pad + ($n === 1 ? 0 : ($i / ($n - 1)) * ($w - 2 * $pad));
              $y = $pad + (1 - ($v - $min) / $span) * ($h - 2 * $pad);
              $pts[] = round($x, 1).','.round($y, 1);
            }
          @endphp
          <svg viewBox="0 0 {{ $w }} {{ $h }}" style="width:100%;height:auto" preserveAspectRatio="none" aria-hidden="true">
            <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="#2B5BD7" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
          </svg>
          <div class="meta" style="margin-top:6px">{{ number_format($min, 1, ',', '') }}–{{ number_format($max, 1, ',', '') }} кг · {{ $weightSeries->count() }} замеров</div>
        @else
          <p class="muted">Недостаточно замеров для графика.</p>
        @endif
      </div>

      <div class="card">
        <h3>Приёмы (14 дней)</h3>
        @if ($recentMeals->isNotEmpty())
          <table class="meals">
            <thead><tr><th>Дата</th><th>Приём</th><th>Статус</th><th class="num">Балл</th></tr></thead>
            <tbody>
              @foreach ($recentMeals as $m)
                <tr>
                  <td>{{ $m->date->format('d.m') }}</td>
                  <td>{{ ['breakfast'=>'Завтрак','lunch'=>'Обед','snack'=>'Перекус','dinner'=>'Ужин'][$m->type] ?? $m->type }}</td>
                  <td>{{ ['eaten'=>'съеден','skipped'=>'пропущен','missed'=>'пропущен','pending'=>'ожидание'][$m->status] ?? $m->status }}</td>
                  <td class="num">{{ $m->score !== null ? $m->score : '—' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @else
          <p class="muted">Нет приёмов за период.</p>
        @endif
      </div>
    </div>

    <div>
      <div class="card" style="margin-bottom:16px">
        <h3>Управление</h3>
        @if (in_array($profile->status, ['active', 'paused'], true))
          <form method="POST" action="{{ route('admin.nutrition.pause', $profile) }}">
            @csrf
            <button type="submit" class="btn ghost" style="margin-top:0">
              {{ $profile->status === 'paused' ? '▶ Возобновить' : '⏸ Поставить на паузу' }}
            </button>
          </form>
        @else
          <p class="muted">Профиль на онбординге — пауза недоступна.</p>
        @endif

        @if (\Illuminate\Support\Facades\Route::has('nutrition.stats'))
          <p style="margin-top:14px"><a class="sig" href="{{ \Illuminate\Support\Facades\URL::signedRoute('nutrition.stats', ['profile' => $profile->id]) }}" target="_blank">Открыть страницу клиента ↗</a></p>
        @endif
      </div>

      <div class="card">
        <h3>Настройки и профиль ИИ</h3>
        <form method="POST" action="{{ route('admin.nutrition.update', $profile) }}">
          @csrf
          @method('PUT')
          <div class="row2">
            <div class="fld">
              <label for="wake_time">Подъём</label>
              <input type="time" id="wake_time" name="wake_time" value="{{ old('wake_time', $settings['wake_time']) }}" required>
            </div>
            <div class="fld">
              <label for="sleep_time">Отбой</label>
              <input type="time" id="sleep_time" name="sleep_time" value="{{ old('sleep_time', $settings['sleep_time']) }}" required>
            </div>
          </div>
          <div class="row2">
            <div class="fld">
              <label for="steps_target">Цель по шагам</label>
              <input type="number" id="steps_target" name="steps_target" min="3000" max="30000" step="500" value="{{ old('steps_target', $settings['steps_target']) }}" required>
            </div>
            <div class="fld">
              <label for="portion_adjustment">Коррекция порций (−3…3)</label>
              <input type="number" id="portion_adjustment" name="portion_adjustment" min="-3" max="3" step="1" value="{{ old('portion_adjustment', $settings['portion_adjustment']) }}" required>
            </div>
          </div>
          <div class="fld">
            <label for="ai_profile">Профиль ИИ (ai_profile)</label>
            <textarea class="ai" id="ai_profile" name="ai_profile">{{ old('ai_profile', $profile->ai_profile) }}</textarea>
          </div>
          <button type="submit" class="btn" style="margin-top:4px">Сохранить</button>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection
