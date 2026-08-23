@extends('layouts.panel')
@section('title', 'إعداد التحقق بخطوتين')

@section('content')
<style nonce="{{ $cspNonce }}">
    .mfa-wrap{max-width:940px;margin:12px auto}.mfa-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:24px}.mfa-icon{width:48px;height:48px;flex:0 0 48px;display:grid;place-items:center;background:var(--teal-50);color:var(--teal);border-radius:14px;font-size:23px}.mfa-head h1{margin:0}.mfa-grid{display:grid;grid-template-columns:minmax(300px,.9fr) minmax(0,1.1fr);gap:18px;align-items:start}.mfa-step{display:flex;align-items:center;gap:9px;font-weight:700}.mfa-step b{width:27px;height:27px;display:grid;place-items:center;background:var(--teal);color:#fff;border-radius:999px;font-size:13px}.qr-frame{display:grid;place-items:center;padding:18px;background:linear-gradient(180deg,var(--teal-50),#fff)}.qr-frame img{width:min(260px,100%);height:auto;background:#fff;padding:10px;border:1px solid var(--line);border-radius:14px}.manual-key{direction:ltr;text-align:left;word-break:break-all}.otp-code{font:700 25px/1.2 ui-monospace,Consolas,monospace!important;letter-spacing:.3em;text-align:center;direction:ltr;min-height:54px}.mfa-submit{width:100%;font-weight:700}.mfa-security{margin-top:12px;color:var(--muted);font-size:12px}.mfa-security::before{content:'🔒';margin-inline-end:5px}@media(max-width:760px){.mfa-grid{grid-template-columns:1fr}.mfa-wrap{margin:0}.mfa-head{margin-bottom:16px}}
</style>
<div class="mfa-wrap">
<div class="mfa-head"><span class="mfa-icon" aria-hidden="true">◈</span><div><h1>حماية حسابك بخطوتين</h1>
<div class="sub" style="margin:2px 0 0">لن يكتمل الدخول حتى تربط تطبيق المصادقة. يستغرق الإعداد أقل من دقيقة.</div></div></div>

<div class="mfa-grid">
<div class="card">
    <div class="hd mfa-step"><b>1</b> امسح رمز QR</div>
    <div class="bd">
        <p style="margin-top:0">افتح Google أو Microsoft Authenticator أو 1Password ثم اختر إضافة حساب.</p>
        <div class="qr-frame">
            <img src="{{ $qrDataUri }}" alt="رمز QR لإعداد التحقق بخطوتين" width="260" height="260">
        </div>
        <details style="margin-top:14px"><summary>تعذّر المسح؟ استخدم المفتاح اليدوي</summary>
        <div class="note manual-key" style="margin-top:10px">
            <strong>Account:</strong> {{ auth()->user()->email }}<br>
            <strong>Key:</strong> {{ $secret }}<br>
            <strong>Type:</strong> Time based (TOTP)
        </div>
        </details>
    </div>
</div>

<div class="card">
    <div class="hd mfa-step"><b>2</b> أدخل الرمز الظاهر</div>
    <div class="bd">
        <p style="margin-top:0">يتغير الرمز كل 30 ثانية. أدخل الأرقام الستة الحالية لإكمال الحماية.</p>
        <form method="POST" action="{{ route('panel.two-factor.confirm') }}">
            @csrf
            <div class="field">
                <label for="mfa-code">رمز التحقق</label>
                <input id="mfa-code" class="otp-code" name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" maxlength="6" placeholder="••••••" required autofocus>
            </div>
            <button class="btn mfa-submit">تفعيل وإكمال الدخول</button>
        </form>
        <p class="mfa-security">لا ترسل المفتاح أو صورة QR لأي شخص.</p>
    </div>
</div>
</div>
</div>
@endsection
