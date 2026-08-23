@extends('layouts.panel')
@section('title', 'دخول لوحة دارك')

@section('content')
<style nonce="{{ $cspNonce }}">
    .auth-shell{min-height:calc(100vh - 90px);display:grid;place-items:center;padding:28px 0}
    .auth-panel{width:min(920px,100%);display:grid;grid-template-columns:minmax(0,1.05fr) minmax(340px,.95fr);overflow:hidden;background:#fff;border:1px solid var(--line);border-radius:22px;box-shadow:0 24px 70px rgba(15,76,70,.12)}
    .auth-intro{padding:48px;background:linear-gradient(145deg,#064e49 0%,var(--teal) 65%,#14b8a6 140%);color:#fff;display:flex;flex-direction:column;justify-content:space-between;min-height:500px}
    .auth-mark{width:54px;height:54px;display:grid;place-items:center;border-radius:16px;background:#fff;color:var(--teal);font-size:26px;font-weight:900;box-shadow:0 10px 24px rgba(0,0,0,.14)}
    .auth-intro h1{font-size:34px;line-height:1.25;margin:28px 0 10px}.auth-intro p{color:#ccfbf1;max-width:38ch;margin:0}
    .auth-benefits{display:grid;gap:10px;margin-top:34px}.auth-benefits span{display:flex;gap:9px;align-items:center;font-size:13px;color:#e6fffb}.auth-benefits span::before{content:'✓';display:grid;place-items:center;width:22px;height:22px;border-radius:999px;background:rgba(255,255,255,.14);font-weight:900}
    .auth-form{padding:48px;display:flex;flex-direction:column;justify-content:center}.auth-form h2{font-size:25px;margin:0 0 6px}.auth-form .sub{margin-bottom:28px}
    .auth-form input{min-height:48px}.auth-submit{width:100%;margin-top:4px;font-weight:700}.auth-help{margin:22px 0 0;padding-top:18px;border-top:1px solid var(--line);color:var(--muted);font-size:12px}
    @media(max-width:760px){.auth-shell{padding:12px 0;place-items:start}.auth-panel{grid-template-columns:1fr;border-radius:16px}.auth-intro{min-height:auto;padding:26px}.auth-intro h1{font-size:27px;margin:18px 0 7px}.auth-benefits{display:none}.auth-form{padding:28px 24px}}
</style>
<div class="auth-shell">
    <section class="auth-panel" aria-labelledby="login-title">
        <div class="auth-intro">
            <div>
                <div class="auth-mark" aria-hidden="true">د</div>
                <h1>تشغيل الصيانة<br>من مكان واحد</h1>
                <p>تابع البلاغات والزيارات والفريق والمخزون واتخذ القرار من لوحة واضحة وآمنة.</p>
            </div>
            <div class="auth-benefits" aria-label="مزايا المنصة">
                <span>متابعة لحظية لأعمال اليوم</span>
                <span>صلاحيات منفصلة لكل دور</span>
                <span>حماية إلزامية بالتحقق بخطوتين</span>
            </div>
        </div>
        <div class="auth-form">
            <h2 id="login-title">مرحبًا بعودتك</h2>
            <p class="sub">سجّل الدخول إلى لوحة الإدارة</p>
            <form method="POST" action="{{ route('panel.login') }}">
                @csrf
                <div class="field">
                    <label for="panel-email">البريد الإلكتروني</label>
                    <input id="panel-email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                </div>
                <div class="field">
                    <label for="panel-password">كلمة المرور</label>
                    <input id="panel-password" type="password" name="password" autocomplete="current-password" required>
                </div>
                <button class="btn auth-submit">دخول إلى اللوحة</button>
            </form>
            <p class="auth-help">الفنيون يستخدمون تطبيق الجوال. ينتقل فريق المبيعات تلقائيًا إلى مساحة المبيعات بعد التحقق.</p>
        </div>
    </section>
</div>
@endsection
