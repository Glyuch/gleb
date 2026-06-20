@extends('admin.game.layout')

@php
$labels = [
  'bank' => 'Вклад',
  'cash' => 'Ден. рынок',
  'bond' => 'Облигации',
  'stock' => 'Акции',
  'mix' => 'Смешанный',
];
@endphp

@section('content')
  <p class="hint">Квартальная доходность каждого инструмента (в&nbsp;процентах за квартал, можно с&nbsp;минусом) плюс ставка ЦБ и инфляция (годовые&nbsp;%). Сохранение публикует новую активную версию контента; тексты событий и прочее не меняются.</p>

  <style>
    .ret-in{width:74px;padding:6px 7px;border:1.5px solid #e2e2e8;border-radius:7px;font-size:13px;text-align:right;font-variant-numeric:tabular-nums}
    .ret-in:focus{outline:none;border-color:#FF0032}
    .matrix td.cell-in{padding:6px 8px}
  </style>

  <form method="POST" action="{{ route('admin.game.returns.update') }}">
    @csrf
    @method('PUT')
    <div class="matrix-wrap">
      <table class="matrix">
        <thead>
          <tr>
            <th class="corner">Квартал</th>
            @foreach ($instr as $k)
              <th>{{ $labels[$k] ?? $k }},&nbsp;%</th>
            @endforeach
            <th>Ставка,&nbsp;%</th>
            <th>Инфляция,&nbsp;%</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($years as $i => $y)
            <tr>
              <td class="rowhead">Квартал {{ $i + 1 }}</td>
              @foreach ($instr as $k)
                <td class="cell-in"><input class="ret-in" type="number" step="0.1" name="years[{{ $i }}][{{ $k }}]" value="{{ round((($y['ret'][$k] ?? 0)) * 100, 4) }}"></td>
              @endforeach
              <td class="cell-in"><input class="ret-in" type="number" step="1" name="years[{{ $i }}][rate]" value="{{ (int) ($y['rate'] ?? 0) }}"></td>
              <td class="cell-in"><input class="ret-in" type="number" step="1" name="years[{{ $i }}][infl]" value="{{ (int) ($y['infl'] ?? 0) }}"></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <button type="submit" class="btn">Сохранить доходности</button>
  </form>
@endsection
