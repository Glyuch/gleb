@extends('admin.game.layout')

@section('content')
  <div class="cards">
    <div class="card"><div class="card-l">Партий сыграно</div><div class="card-v">{{ $playsTotal }}</div></div>
    <div class="card"><div class="card-l">Средний портфель</div><div class="card-v">{{ number_format($avgScore, 0, '', ' ') }} ₽</div></div>
    <div class="card"><div class="card-l">Кликов на Финуслуги</div><div class="card-v">{{ $fundClicksTotal }} <small>({{ $fundClicksUnique }} уник.)</small></div></div>
  </div>

  <h2>Воронка</h2>
  @php($funnelTop = max(1, $funnel[0]['count']))
  <div class="funnel">
    @foreach ($funnel as $i => $st)
      <div class="frow">
        <div class="flabel">{{ $st['label'] }}</div>
        <div class="fbarwrap"><div class="fbar" style="width: {{ round($st['count'] / $funnelTop * 100) }}%"></div></div>
        <div class="fcount">{{ $st['count'] }}@if ($i > 0 && $funnel[$i - 1]['count'] > 0) <small>{{ round($st['count'] / $funnel[$i - 1]['count'] * 100) }}%</small>@endif</div>
      </div>
    @endforeach
  </div>

  <h2>Решения игроков по инструментам</h2>
  <p class="hint">«Завершённые» — выборы в доигранных партиях. «Все попытки» — каждый ход (включая брошенные партии).</p>
  <table>
    <tr><th>Инструмент</th><th>Завершённые</th><th>Все попытки</th></tr>
    @foreach ($choiceLabels as $k => $label)
      <tr><td>{{ $label }}</td><td>{{ $choiceFromResults[$k] ?? 0 }}</td><td>{{ $choiceFromEvents[$k] ?? 0 }}</td></tr>
    @endforeach
  </table>

  <h2>Решения по ходам (по кварталам)</h2>
  <p class="hint">Что игроки выбирали в каждом квартале при его условиях — по всем ходам, включая незавершённые партии.</p>
  @foreach ($years as $qi => $y)
    @php($qn = $qi + 1)
    @php($qCounts = $perQuarter[$qn] ?? [])
    @php($qSum = array_sum($qCounts))
    @php($qTotal = max(1, $qSum))
    <div class="survey-block">
      <div class="sq">Квартал {{ $qn }} — {{ $y['ev']['title'] ?? '' }}
        <small>ставка {{ $y['rate'] }}%, инфляция {{ $y['infl'] }}% · {{ $qSum }} ходов</small>
      </div>
      @foreach ($choiceLabels as $k => $label)
        <div class="srow">
          <div class="sopt">{{ $label }}</div>
          <div class="sbarwrap"><div class="sbar" style="width: {{ round(($qCounts[$k] ?? 0) / $qTotal * 100) }}%"></div></div>
          <div class="scnt">{{ $qCounts[$k] ?? 0 }}</div>
        </div>
      @endforeach
    </div>
  @endforeach

  <h2>Ответы на опрос</h2>
  @forelse ($surveyStats as $q)
    <div class="survey-block">
      <div class="sq">{{ $q['question'] }} <small>({{ $q['total'] }} ответов)</small></div>
      @php($qTotal = max(1, $q['total']))
      @foreach ($q['counts'] as $opt => $cnt)
        <div class="srow">
          <div class="sopt">{{ $opt }}</div>
          <div class="sbarwrap"><div class="sbar" style="width: {{ round($cnt / $qTotal * 100) }}%"></div></div>
          <div class="scnt">{{ $cnt }}</div>
        </div>
      @endforeach
    </div>
  @empty
    <p class="hint">Пока нет данных опроса.</p>
  @endforelse
@endsection
