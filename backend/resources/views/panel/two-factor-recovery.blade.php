@extends('layouts.panel')
@section('title', 'رموز الاسترداد')

@section('content')
<h1>احفظ رموز الاسترداد الآن</h1>
<div class="sub">كل رمز يعمل مرة واحدة. لن تُعرض هذه المجموعة مرة أخرى.</div>

<div class="card">
    <div class="bd">
        <div class="note">احفظها في مدير كلمات مرور منفصل عن الهاتف الذي يحمل Authenticator.</div>
        <pre style="direction:ltr;text-align:left;font-size:18px;line-height:1.8;background:#f8fafc;padding:18px;border-radius:8px">{{ implode("\n", $codes) }}</pre>
        <a class="btn" href="{{ route($homeRoute) }}">فهمت وحفظت الرموز</a>
    </div>
</div>
@endsection
