@extends('layouts.client')
@section('title','تغيير كلمة المرور — دارك')
@section('content')
<h2>تغيير كلمة المرور</h2>
<div class="form-card">
    <p class="muted">بعد التغيير يُسجَّل خروج حسابك من المتصفحات الأخرى.</p>
    <form method="POST" action="{{ route('client.account.password.update') }}">
        @csrf
        <div class="field"><label>كلمة المرور الحالية</label><input name="current_password" type="password" autocomplete="current-password" required></div>
        <div class="field"><label>كلمة المرور الجديدة</label><input name="password" type="password" autocomplete="new-password" minlength="12" required></div>
        <div class="field"><label>تأكيد كلمة المرور الجديدة</label><input name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></div>
        <button class="btn" style="width:100%;margin-top:8px">حفظ كلمة المرور</button>
    </form>
</div>
@endsection
