@extends('layouts.panel')
@section('title', 'إعداد التحقق بخطوتين')

@section('content')
<h1>إعداد التحقق بخطوتين</h1>
<div class="sub">مطلوب لكل حساب يدخل لوحة الإدارة.</div>

<div class="card">
    <div class="hd">1. أضف الحساب إلى تطبيق Authenticator</div>
    <div class="bd">
        <p>امسح الرمز بتطبيق Google Authenticator أو Microsoft Authenticator أو 1Password.</p>
        <div style="display:flex;justify-content:center;margin:16px 0 20px">
            <img src="{{ $qrDataUri }}" alt="رمز QR لإعداد التحقق بخطوتين" width="260" height="260" style="width:min(260px,100%);height:auto;background:#fff;padding:10px;border:1px solid var(--line);border-radius:12px">
        </div>
        <p class="sub">إذا تعذّر المسح، أدخل المفتاح يدويًا:</p>
        <div class="note" style="direction:ltr;text-align:left;word-break:break-all">
            <strong>Account:</strong> {{ auth()->user()->email }}<br>
            <strong>Key:</strong> {{ $secret }}<br>
            <strong>Type:</strong> Time based (TOTP)
        </div>
        <details style="margin-top:12px">
            <summary>رابط الإعداد الكامل</summary>
            <code style="direction:ltr;display:block;word-break:break-all">{{ $provisioningUri }}</code>
        </details>
    </div>
</div>

<div class="card">
    <div class="hd">2. أكّد الرمز الحالي</div>
    <div class="bd">
        <form method="POST" action="{{ route('panel.two-factor.confirm') }}">
            @csrf
            <div class="field">
                <label>الرمز المكوّن من 6 أرقام</label>
                <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
            </div>
            <button class="btn">تفعيل التحقق بخطوتين</button>
        </form>
    </div>
</div>
@endsection
