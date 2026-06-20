@extends('auth.game-layout')

@section('title', 'Регистрация')

@section('content')
  <h1>Создайте аккаунт</h1>
  <p class="sub">Зарегистрируйтесь, чтобы получить доступ к активности по финансовой грамотности.</p>

  @if ($errors->any())
    <div class="alert">Проверьте поля формы и попробуйте ещё раз.</div>
  @endif

  <form method="POST" action="{{ url('/register') }}">
    @csrf
    <div class="field">
      <label for="name">Имя или ник</label>
      <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" placeholder="Как показывать вас в рейтинге">
      @error('name')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="email">Email</label>
      <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" placeholder="you@example.com">
      @error('email')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="password">Пароль</label>
      <input id="password" type="password" name="password" required autocomplete="new-password" placeholder="Придумайте пароль">
      <div class="hint">Минимум 12 символов: буквы, цифры и хотя бы один символ.</div>
      @error('password')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="password_confirmation">Повторите пароль</label>
      <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Ещё раз тот же пароль">
    </div>
    <button class="btn" type="submit">Зарегистрироваться и играть</button>
  </form>

  <div class="alt">Уже есть аккаунт? <a href="{{ route('game.login') }}">Войти</a></div>
@endsection
