@extends('auth.game-layout')

@section('title', 'Вход')

@section('content')
  <h1>С возвращением</h1>
  <p class="sub">Войдите, чтобы продолжить активность по финансовой грамотности и сыграть.</p>

  @if (session('status'))
    <div class="alert" style="background:#ECFDF5;border-color:#A7F3D0;color:#047857">{{ session('status') }}</div>
  @endif
  @if ($errors->any())
    <div class="alert">Неверный email или пароль. Попробуйте ещё раз.</div>
  @endif

  <form method="POST" action="{{ url('/login') }}">
    @csrf
    <div class="field">
      <label for="email">Email</label>
      <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" placeholder="you@example.com">
      @error('email')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="password">Пароль</label>
      <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Ваш пароль">
      @error('password')<div class="err">{{ $message }}</div>@enderror
    </div>
    <button class="btn" type="submit">Войти и играть</button>
  </form>

  <div class="alt">Ещё нет аккаунта? <a href="{{ route('game.register') }}">Зарегистрироваться</a></div>
@endsection
