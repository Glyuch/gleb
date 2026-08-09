@extends('admin.layout')

@section('title', $title)

@push('head')
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  .doc{background:#fff;border:1px solid #e9ebef;border-radius:14px;padding:22px 26px;max-width:820px;font-size:16px;line-height:1.62;color:#1f2937}
  .doc .back{display:inline-block;margin:0 0 14px;font-size:13px;color:#6b7280;text-decoration:none}
  .doc .back:hover{color:#2B5BD7}
  .doc h1{font-size:25px;line-height:1.25;margin:0 0 18px;letter-spacing:-.01em}
  .doc h2{font-size:19px;margin:30px 0 10px;padding-top:14px;border-top:1px solid #eef0f3}
  .doc h3{font-size:16px;margin:22px 0 8px}
  .doc p{margin:0 0 13px}
  .doc ul,.doc ol{margin:0 0 13px;padding-left:22px}
  .doc li{margin:0 0 6px}
  .doc code{background:#f3f4f6;border-radius:4px;padding:1.5px 5px;font-size:.88em}
  .doc pre{background:#f8f9fb;border:1px solid #eef0f3;border-radius:10px;padding:13px 15px;overflow-x:auto;margin:0 0 15px}
  .doc pre code{background:none;padding:0;font-size:13px}
  .doc blockquote{margin:0 0 15px;padding:2px 0 2px 16px;border-left:3px solid #e5e7eb;color:#4b5563}
  .doc table{width:100%;border-collapse:collapse;font-size:14px;margin:0 0 15px;display:block;overflow-x:auto}
  .doc th,.doc td{text-align:left;padding:8px 10px;border-bottom:1px solid #eef0f3}
  .doc hr{border:0;border-top:1px solid #eef0f3;margin:26px 0}
  .doc a{color:#2B5BD7}
  @@media(max-width:760px){.doc{padding:16px 17px;border-radius:0;border-left:0;border-right:0;font-size:16.5px}}
</style>
@endpush

@section('content')
<article class="doc">
  <a class="back" href="{{ route('admin.docs.index') }}">← все документы</a>
  {!! $html !!}
</article>
@endsection
