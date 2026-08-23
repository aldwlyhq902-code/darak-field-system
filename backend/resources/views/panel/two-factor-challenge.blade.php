@extends('layouts.panel')
@section('title', 'التحقق بخطوتين')

@section('content')
<div style="max-width:460px;margin:clamp(24px,8vh,72px) auto">
    <div class="card">
        <div class="hd" style="font-size:18px">تأكيد هويتك</div>
        <div class="bd">
            <div style="width:48px;height:48px;display:grid;place-items:center;border-radius:14px;background:var(--teal-50);color:var(--teal);font-size:22px;margin-bottom:14px" aria-hidden="true">✓</div>
            <p class="sub">أدخل الرمز الحالي من تطبيق المصادقة. يمكنك أيضًا استخدام رمز استرداد لمرة واحدة.</p>
            <form method="POST" action="{{ route('panel.two-factor.verify') }}">
                @csrf
                <div class="field">
                    <label for="challenge-code">رمز التحقق أو الاسترداد</label>
                    <input id="challenge-code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus style="min-height:52px;font:700 22px ui-monospace,Consolas,monospace;letter-spacing:.18em;text-align:center;direction:ltr">
                </div>
                <button class="btn" style="width:100%;font-weight:700">تحقق وأكمل الدخول</button>
            </form>
            <p style="margin:18px 0 0;text-align:center;font-size:13px"><a href="{{ route('panel.login') }}">العودة لتسجيل الدخول</a></p>
        </div>
    </div>
</div>
@endsection
