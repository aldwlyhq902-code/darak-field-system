@extends('layouts.client')
@section('title','دخول العميل — دارك')
@section('content')
<div class="brand-login"><strong>دارك للعملاء</strong><div class="muted">متابعة الأصول والزيارات والتقارير</div></div>
<div class="form-card">
    <form method="POST" action="{{ route('client.login') }}">
        @csrf
        <div class="field"><label>البريد الإلكتروني</label><input name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus></div>
        <div class="field"><label>كلمة المرور</label><input name="password" type="password" autocomplete="current-password" required></div>
        <label style="display:flex;align-items:center;gap:8px;color:var(--ink)"><input type="checkbox" name="remember" value="1" style="width:auto"> تذكرني على هذا الجهاز</label>
        <button class="btn" style="width:100%;margin-top:17px">دخول آمن</button>
    </form>
</div>
@endsection
