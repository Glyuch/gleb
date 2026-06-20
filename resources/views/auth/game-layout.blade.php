<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Вход') · gleb.finance</title>
@verbatim
<style>
:root{--primary:#3B82F6;--secondary:#60A5FA;--grad:linear-gradient(135deg,#3B82F6 0%,#60A5FA 100%);--text:#1E293B;--muted:#64748B;--border:rgba(59,130,246,.18);--card:#fff}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html,body{min-height:100%}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:var(--text);background:linear-gradient(135deg,#EFF6FF 0%,#DBEAFE 50%,#EFF6FF 100%);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px 16px}
.brand{font-size:18px;font-weight:800;letter-spacing:-.3px;margin-bottom:18px;background:var(--grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.card{width:100%;max-width:430px;background:var(--card);border:1px solid var(--border);border-radius:20px;box-shadow:0 20px 60px rgba(59,130,246,.18);padding:30px 26px}
.badge{width:54px;height:54px;border-radius:15px;background:rgba(59,130,246,.10);display:grid;place-items:center;margin-bottom:16px}
h1{font-size:23px;font-weight:800;line-height:1.2;letter-spacing:-.4px;margin-bottom:8px}
.sub{font-size:14.5px;color:var(--muted);line-height:1.5;margin-bottom:22px}
.field{margin-bottom:14px}
label{display:block;font-size:13px;font-weight:700;margin-bottom:6px;color:var(--text)}
input{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:11px;font-family:inherit;font-size:15px;color:var(--text);background:#fff;transition:border-color .15s,box-shadow .15s}
input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.hint{font-size:12px;color:var(--muted);margin-top:5px}
.err{font-size:12.5px;color:#DC2626;margin-top:5px;font-weight:600}
.alert{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:11px;padding:10px 13px;font-size:13px;margin-bottom:16px;line-height:1.45}
.btn{width:100%;padding:14px;border:none;border-radius:12px;background:var(--grad);color:#fff;font-family:inherit;font-size:15.5px;font-weight:700;cursor:pointer;transition:transform .1s,box-shadow .2s;margin-top:6px}
.btn:hover{box-shadow:0 10px 26px rgba(59,130,246,.35)}
.btn:active{transform:translateY(1px)}
.alt{text-align:center;font-size:13.5px;color:var(--muted);margin-top:18px}
.alt a{color:var(--primary);font-weight:700;text-decoration:none}
.alt a:hover{text-decoration:underline}
.disc{text-align:center;font-size:11.5px;color:#94A3B8;margin-top:18px;line-height:1.5}
</style>
@endverbatim
</head>
<body>
  <div class="brand">gleb.finance</div>
  <div class="card">
    <div class="badge">
      <svg width="30" height="30" viewBox="0 0 42 42" fill="none"><path d="M5 32 L15 22 L23 28 L37 12" stroke="#3B82F6" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M30 12 H37 V19" stroke="#3B82F6" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/><line x1="5" y1="37" x2="37" y2="37" stroke="#3B82F6" stroke-width="3.5" stroke-linecap="round"/></svg>
    </div>
    @yield('content')
  </div>
  <div class="disc">Финуслуги · Московская Биржа · образовательная активность</div>
</body>
</html>
