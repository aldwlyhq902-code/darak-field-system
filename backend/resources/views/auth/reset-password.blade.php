@extends($layout)
@section('title', 'تعيين كلمة مرور جديدة')

@section('content')
<div style="max-width:460px;margin:clamp(24px,8vh,72px) auto">
    <div class="card form-card">
        <div class="hd" style="font-size:18px">تعيين كلمة مرور جديدة</div>
        <div class="bd">
            <p class="sub muted">12 حرفًا على الأقل، تجمع حروفًا كبيرة وصغيرة وأرقامًا ورموزًا. ستُنهى جميع الجلسات الأخرى لهذا الحساب.</p>
            <form method="POST" action="{{ route($update) }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="field">
                    <label for="reset-email">البريد الإلكتروني</label>
                    <input id="reset-email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                </div>
                <div class="field">
                    <label for="reset-password">كلمة المرور الجديدة</label>
                    <input id="reset-password" type="password" name="password" autocomplete="new-password" minlength="12" required>
                </div>
                <div class="field">
                    <label for="reset-password-confirmation">تأكيد كلمة المرور</label>
                    <input id="reset-password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required>
                </div>
                <button class="btn" style="width:100%;font-weight:700">حفظ كلمة المرور</button>
            </form>
            <p style="margin:18px 0 0;text-align:center;font-size:13px"><a href="{{ route($request) }}">طلب رابط جديد</a></p>
        </div>
    </div>
</div>
@endsection
