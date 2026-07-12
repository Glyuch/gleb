@extends('admin.layout')

@section('title', 'Нутрициолог')

@push('head')
<style>
  .nutri .toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
  .nutri table{width:100%;border-collapse:collapse;font-size:13.5px;background:#fff;border:1px solid #e9ebef;border-radius:14px;overflow:hidden}
  .nutri th,.nutri td{text-align:left;padding:11px 12px;border-bottom:1px solid #eef0f3}
  .nutri th{font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#6b7280;font-weight:700;background:#fafafb}
  .nutri tbody tr{cursor:pointer}
  .nutri tbody tr:hover td{background:#fff0f3}
  .nutri .num{text-align:right;font-variant-numeric:tabular-nums}
  .nutri .who{font-weight:700}
  .nutri .who small{display:block;color:#6b7280;font-weight:600;font-size:12px}
  .nutri .pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700}
  .nutri .pill.active{background:#EAF7EE;color:#1a7f37}
  .nutri .pill.paused{background:#FFF4E5;color:#b25e00}
  .nutri .pill.onboarding{background:#EAF0FB;color:#2B5BD7}
  .nutri .up{color:#b25e00;font-weight:700}
  .nutri .down{color:#1a7f37;font-weight:700}
  .nutri .muted{color:#9aa0ab}
  .nutri form.inline{margin:0}
</style>
@endpush

@section('content')
<div class="nutri">
  <div class="page-head">
    <h1>Нутрициолог</h1>
    <div class="meta">{{ $rows->count() }} {{ trans_choice('профиль|профиля|профилей', $rows->count()) }}</div>
  </div>

  <div class="toolbar">
    <form class="inline" method="POST" action="{{ route('admin.nutrition.invite') }}">
      @csrf
      <button type="submit" class="btn" style="margin-top:0">Сгенерировать инвайт</button>
    </form>
  </div>

  <table>
    <thead>
      <tr>
        <th>Клиент</th>
        <th>Статус</th>
        <th>Фаза</th>
        <th class="num">День</th>
        <th class="num">Балл 7д</th>
        <th class="num">Вес Δ30д</th>
        <th>Последний визит</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($rows as $row)
        @php $p = $row['profile']; @endphp
        <tr onclick="location.href='{{ route('admin.nutrition.show', $p) }}'">
          <td class="who">{{ $p->displayName() }}
            <small>{{ $p->username ? '@'.$p->username : 'id '.$p->telegram_user_id }}</small>
          </td>
          <td><span class="pill {{ $p->status }}">{{ ['active'=>'активен','paused'=>'на паузе','onboarding'=>'онбординг'][$p->status] ?? $p->status }}</span></td>
          <td>{{ $p->phase === 'maintenance' ? 'поддержка' : 'программа' }}</td>
          <td class="num">{{ $row['day'] > 0 ? $row['day'] : '—' }}</td>
          <td class="num">{{ $row['avg_score'] !== null ? number_format($row['avg_score'], 1, ',', '') : '—' }}</td>
          <td class="num">
            @if ($row['weight_delta'] === null)
              <span class="muted">—</span>
            @elseif ($row['weight_delta'] > 0)
              <span class="up">+{{ number_format($row['weight_delta'], 1, ',', '') }}</span>
            @elseif ($row['weight_delta'] < 0)
              <span class="down">{{ number_format($row['weight_delta'], 1, ',', '') }}</span>
            @else
              <span class="muted">0</span>
            @endif
          </td>
          <td>{{ $p->last_seen_at ? $p->last_seen_at->timezone('Europe/Moscow')->format('d.m.Y H:i') : '—' }}</td>
        </tr>
      @empty
        <tr><td colspan="7" class="muted" style="text-align:center;padding:26px">Пока нет профилей.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
@endsection
