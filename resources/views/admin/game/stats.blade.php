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

  <h2>Игроки · лидерборд и опрос</h2>
  <p class="hint">Одна строка на игрока. Ранг — по лучшему портфелю (макс. результат игрока). «Сыграл» — число завершённых партий, «Запусков» — число стартов игры (вкл. брошенные). Ответы опроса — из последней попытки игрока.</p>
  <div class="matrix-wrap">
    <table class="matrix lb">
      <thead>
        <tr>
          <th class="corner">#</th>
          <th class="lb-user">Игрок</th>
          <th>Лучший</th>
          <th>Ratio</th>
          <th>Обыграл вклад</th>
          <th title="Число завершённых партий">Сыграл</th>
          <th title="Число стартов игры (включая брошенные)">Запусков</th>
          @foreach ($surveyColumns as $col)
            <th title="{{ $col['question'] }}">{{ $col['label'] }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @forelse ($leaderboard as $i => $row)
          <tr>
            <td class="cell">{{ $i + 1 }}</td>
            <td class="rowhead lb-user">
              {{ $row['name'] ?? 'Игрок #'.$row['user_id'] }}
              @if ($row['email'])<span class="lb-email">{{ $row['email'] }}</span>@endif
            </td>
            <td class="cell">{{ number_format($row['best_score'], 0, '', ' ') }} ₽</td>
            <td class="cell">{{ $row['ratio'] }}%</td>
            <td class="cell">@if ($row['beat_bank'])<span class="yes">Да</span>@else<span class="no">Нет</span>@endif</td>
            <td class="cell">{{ $row['plays'] }}</td>
            <td class="cell">{{ $row['starts'] }}</td>
            @foreach ($surveyColumns as $col)
              <td class="cell lb-ans">{{ $row['survey'][$col['id']] ?? '—' }}</td>
            @endforeach
          </tr>
        @empty
          <tr><td class="cell" colspan="{{ 7 + count($surveyColumns) }}">Пока нет сыгранных партий.</td></tr>
        @endforelse
      </tbody>
    </table>
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
