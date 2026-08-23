@extends('layouts.panel')
@section('title', 'رموز الاسترداد')

@section('content')
<div style="max-width:680px;margin:18px auto">
<div style="text-align:center;margin-bottom:22px"><div style="width:54px;height:54px;display:grid;place-items:center;margin:0 auto 12px;border-radius:16px;background:#dcfce7;color:var(--green);font-size:25px" aria-hidden="true">✓</div><h1>تم تأمين الحساب</h1>
<div class="sub">الخطوة الأخيرة: احفظ رموز الاسترداد للطوارئ في مكان آمن.</div></div>
<div class="card">
    <div class="bd">
        <div class="note"><strong>مهم:</strong> كل رمز يعمل مرة واحدة ولن تُعرض هذه المجموعة مجددًا. احفظها خارج الهاتف الذي يحمل تطبيق المصادقة.</div>
        <pre style="direction:ltr;text-align:center;font-size:18px;line-height:1.9;letter-spacing:.08em;background:#f8fafc;border:1px dashed #94a3b8;padding:20px;border-radius:12px;white-space:pre-wrap">{{ implode("\n", $codes) }}</pre>
        <a class="btn" style="width:100%;font-weight:700" href="{{ route($homeRoute) }}">حفظت الرموز — الانتقال إلى اللوحة</a>
    </div>
</div>
</div>
@endsection
