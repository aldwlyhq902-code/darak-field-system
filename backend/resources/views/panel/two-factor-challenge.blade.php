@extends('layouts.panel')
@section('title', 'التحقق بخطوتين')

@section('content')
<div style="max-width:440px;margin:60px auto">
    <div class="card">
        <div class="hd">التحقق بخطوتين</div>
        <div class="bd">
            <p class="sub">أدخل رمز Authenticator الحالي، أو أحد رموز الاسترداد.</p>
            <form method="POST" action="{{ route('panel.two-factor.verify') }}">
                @csrf
                <div class="field">
                    <label>رمز التحقق أو الاسترداد</label>
                    <input name="code" autocomplete="one-time-code" required autofocus>
                </div>
                <button class="btn" style="width:100%">دخول آمن</button>
            </form>
            <p style="margin-top:14px"><a href="{{ route('panel.login') }}">العودة لتسجيل الدخول</a></p>
        </div>
    </div>
</div>
@endsection
