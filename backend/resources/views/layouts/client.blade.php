<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#075e56">
    <meta name="description" content="بوابة عملاء دارك لمتابعة الصيانة والبلاغات.">
    <link rel="manifest" href="{{ route('client.manifest') }}">
    <title>@yield('title', 'دارك للعملاء')</title>
    <style nonce="{{ $cspNonce }}">
        :root { --teal:#075e56;--dark:#052f2c;--mint:#e9faf6;--ink:#152321;--muted:#64706f;--line:#dfe9e7;--red:#b73535;--amber:#a86906;--green:#16745d; }
        *{box-sizing:border-box} body{margin:0;background:#f7faf9;color:var(--ink);font-family:Tahoma,"Segoe UI",sans-serif;line-height:1.7} a{color:var(--teal);text-decoration:none}
        .top{position:sticky;top:0;z-index:20;background:rgba(5,47,44,.96);color:#fff;box-shadow:0 8px 28px rgba(5,47,44,.15)}
        .bar{width:min(1080px,calc(100% - 28px));min-height:68px;margin:auto;display:flex;align-items:center;gap:18px}.logo{font-size:20px;font-weight:900}.logo small{display:block;color:#acd5d0;font-size:10px;font-weight:400}.nav{display:flex;gap:14px;margin-inline-start:auto;align-items:center}.nav a{color:#d8ece9;font-size:13px}.logout{border:1px solid rgba(255,255,255,.25);border-radius:10px;background:transparent;color:#fff;padding:7px 12px;font-family:inherit}
        main{width:min(1080px,calc(100% - 28px));margin:0 auto;padding:28px 0 80px}.hero{padding:28px;border-radius:24px;background:linear-gradient(135deg,var(--dark),var(--teal));color:#fff;box-shadow:0 22px 60px rgba(5,47,44,.18)}.hero h1{margin:0 0 5px;font-size:clamp(25px,5vw,42px)}.hero p{margin:0;color:#bce0dc}.hero .emergency{display:inline-flex;margin-top:20px;padding:11px 18px;border-radius:13px;background:#fff;color:var(--red);font-weight:800}
        h2{font-size:20px;margin:30px 0 12px}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:18px}.card b{display:block;font-size:24px;color:var(--teal)}.muted{color:var(--muted);font-size:12px}.list{display:grid;gap:10px}.row{display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center;background:#fff;border:1px solid var(--line);border-radius:17px;padding:16px}.row strong{display:block}.pill{display:inline-block;padding:3px 9px;border-radius:999px;background:#edf2f1;color:#51605e;font-size:10px;font-weight:800}.pill.green{background:#e1f7ee;color:var(--green)}.pill.amber{background:#fff1d4;color:var(--amber)}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;border:0;border-radius:12px;background:var(--teal);color:#fff;padding:10px 15px;font:inherit;cursor:pointer}.btn.ghost{background:#fff;color:var(--teal);border:1px solid var(--teal)}.logout{min-height:44px}
        .flash{padding:12px 15px;border-radius:12px;margin-bottom:15px}.flash.ok{background:#dff8ee;color:#145b49}.flash.err{background:#fee7e7;color:#7d2020}.field{margin-bottom:14px}label{display:block;color:var(--muted);font-size:12px;margin-bottom:4px}input,select,textarea{width:100%;padding:12px;border:1px solid var(--line);border-radius:12px;background:#fff;font:inherit}textarea{resize:vertical}.form-card{max-width:620px;margin:auto;background:#fff;border:1px solid var(--line);border-radius:22px;padding:24px}.brand-login{text-align:center;margin:28px 0}.brand-login strong{font-size:27px;color:var(--dark)}.install{display:none;position:fixed;inset:auto 14px 14px;z-index:30;padding:12px 16px;border:0;border-radius:14px;background:#fff;color:var(--dark);box-shadow:0 15px 50px rgba(0,0,0,.2);font:inherit;font-weight:800}.install.show{display:block}
        @media(max-width:700px){.bar{min-height:62px}.nav a{display:none}.grid{grid-template-columns:1fr 1fr}.grid .card:last-child{grid-column:1/-1}.row{grid-template-columns:1fr}.row .btn{width:100%}.hero{padding:22px}.form-card{padding:19px}main{padding-top:18px}}
    </style>
</head>
<body>
@include('partials.language-switcher')
@auth('client')
<header class="top"><div class="bar"><div class="logo">دارك<small>بوابة العميل</small></div><nav class="nav"><a href="{{ route('client.home') }}">الرئيسية</a><form method="POST" action="{{ route('client.logout') }}">@csrf<button class="logout">خروج</button></form></nav></div></header>
@endauth
<main>
    @if(session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
    @if($errors->any())<div class="flash err">@foreach($errors->all() as $error)<div>• {{ $error }}</div>@endforeach</div>@endif
    @yield('content')
</main>
<button id="installApp" class="install">تثبيت بوابة دارك</button>
<script nonce="{{ $cspNonce }}">
let installPrompt;
const installButton=document.getElementById('installApp');
window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();installPrompt=event;installButton.classList.add('show')});
installButton.addEventListener('click',async()=>{if(!installPrompt)return;installPrompt.prompt();await installPrompt.userChoice;installPrompt=null;installButton.classList.remove('show')});
if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('{{ route('client.service-worker') }}',{scope:'/client'}))}
</script>
@include('partials.form-accessibility')
@include('partials.runtime-localization')
</body>
</html>
