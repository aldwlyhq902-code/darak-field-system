@extends('layouts.panel')
@section('title', 'أمان الحساب')

@section('content')
<div class="grid2" style="align-items:start">
    <div class="card">
        <div class="hd">تغيير كلمة المرور</div>
        <div class="bd">
            <div class="note">بعد التغيير تُنهى جلساتك الأخرى على المتصفحات وتُلغى رموز تطبيق الجوال لهذا الحساب.</div>
            <form method="POST" action="{{ route('panel.account.password') }}">
                @csrf
                <div class="field">
                    <label for="current-password">كلمة المرور الحالية</label>
                    <input id="current-password" type="password" name="current_password" autocomplete="current-password" required>
                </div>
                <div class="field">
                    <label for="new-password">كلمة المرور الجديدة</label>
                    <input id="new-password" type="password" name="password" autocomplete="new-password" minlength="12" required>
                </div>
                <div class="field">
                    <label for="new-password-confirmation">تأكيد كلمة المرور الجديدة</label>
                    <input id="new-password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required>
                </div>
                <button class="btn">حفظ كلمة المرور</button>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="hd">رموز الاسترداد</div>
        <div class="bd">
            <div class="note">إصدار رموز جديدة يُبطل الرموز السابقة كلها. يتطلب كلمة المرور ورمزًا حاليًا من تطبيق المصادقة.</div>
            <form method="POST" action="{{ route('panel.two-factor.recovery-codes') }}">
                @csrf
                <div class="field">
                    <label for="recovery-password">كلمة المرور</label>
                    <input id="recovery-password" type="password" name="password" autocomplete="current-password" required>
                </div>
                <div class="field">
                    <label for="recovery-code">رمز التحقق الحالي</label>
                    <input id="recovery-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required style="direction:ltr;text-align:center">
                </div>
                <button class="btn ghost">إصدار رموز استرداد جديدة</button>
            </form>
        </div>
    </div>
</div>
@endsection
