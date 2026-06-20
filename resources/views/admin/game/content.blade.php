@extends('admin.game.layout')

@section('content')
  <p class="hint">Сценарий игры (кварталы), тексты экранов и варианты вложения — в формате JSON. Опрос редактируется на вкладке «Опрос» и здесь не показывается. Каждое сохранение создаёт новую версию (можно откатиться через БД).</p>
  <form method="POST" action="{{ route('admin.game.content.update') }}">
    @csrf
    @method('PUT')
    <textarea name="data" class="json" spellcheck="false">{{ old('data', $json) }}</textarea>
    <button class="btn" type="submit">Сохранить контент</button>
  </form>
@endsection
