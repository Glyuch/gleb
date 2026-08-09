@extends('admin.layout')

@section('title', 'Документы')

@push('head')
<style>
  .docs ul{list-style:none;margin:0 0 22px;padding:0}
  .docs li{border-bottom:1px solid #eef0f3}
  .docs li:last-child{border-bottom:0}
  .docs a{display:flex;justify-content:space-between;gap:14px;align-items:baseline;padding:12px 2px;color:#111827;text-decoration:none}
  .docs a:hover{color:#2B5BD7}
  .docs .t{font-weight:600;font-size:14.5px}
  .docs .d{font-size:12px;color:#9ca3af;white-space:nowrap;font-variant-numeric:tabular-nums}
  .docs h2{font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#6b7280;font-weight:700;margin:24px 0 6px}
  .docs .card{background:#fff;border:1px solid #e9ebef;border-radius:14px;padding:4px 18px}
</style>
@endpush

@section('content')
<div class="docs">
  @foreach ($groups as $group => $docs)
    <h2>{{ $group }}</h2>
    <div class="card">
      <ul>
        @foreach ($docs as $doc)
          <li>
            <a href="{{ route('admin.docs.show', ['slug' => $doc['slug']]) }}">
              <span class="t">{{ $doc['title'] }}</span>
              <span class="d">{{ $doc['updated_at'] }}</span>
            </a>
          </li>
        @endforeach
      </ul>
    </div>
  @endforeach
</div>
@endsection
