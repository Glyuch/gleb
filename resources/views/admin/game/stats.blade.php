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

  <h2>Решения по ходам</h2>
  <p class="hint">Слева — варианты вложения (одинаковые для всех ходов), сверху — каждый ход с его условиями. Таблицу можно листать вбок. Параметры берутся напрямую из активного контента игры. В ячейке — число выборов и доля от всех ходов этого квартала.</p>
  <div class="matrix-wrap">
    <table class="matrix">
      <thead>
        <tr>
          <th class="corner">Вариант / ход</th>
          @foreach ($years as $qi => $y)
            @php($qn = $qi + 1)
            <th title="{{ $y['ev']['title'] ?? '' }}">
              <div class="qnum">Кв {{ $qn }}</div>
              <div class="qparams">ставка {{ $y['rate'] }}%<br>инфл {{ $y['infl'] }}%</div>
              <div class="qparams">{{ array_sum($perQuarter[$qn] ?? []) }} ходов</div>
            </th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach ($choiceLabels as $k => $label)
          <tr>
            <td class="rowhead">{{ $label }}</td>
            @foreach ($years as $qi => $y)
              @php($qn = $qi + 1)
              @php($qCounts = $perQuarter[$qn] ?? [])
              @php($qSum = array_sum($qCounts))
              @php($cnt = $qCounts[$k] ?? 0)
              <td class="cell {{ $cnt === 0 ? 'zero' : '' }}">{{ $cnt }}<span class="pct">{{ $qSum ? round($cnt / $qSum * 100) : 0 }}%</span></td>
            @endforeach
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

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
