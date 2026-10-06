@extends($layout)
@section('title', 'استعادة كلمة المرور')

@section('content')
<div style="max-width:460px;margin:clamp(24px,8vh,72px) auto">
    <div class="card form-card">
        <div class="hd" style="font-size:18px">نسيت كلمة المرور؟</div>
        <div class="bd">
            <p class="sub muted">أدخل بريدك المسجل وسنرسل إليه رابطًا لتعيين كلمة مرور جديدة. الرابط صالح لمدة 60 دقيقة.</p>
            <form method="POST" action="{{ route($email) }}">
                @csrf
                <div class="field">
                    <label for="reset-email">البريد الإلكتروني</label>
                    <input id="reset-email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                </div>
                <button class="btn" style="width:100%;font-weight:700">إرسال رابط التعيين</button>
            </form>
            <p style="margin:18px 0 0;text-align:center;font-size:13px"><a href="{{ route($login) }}">العودة لتسجيل الدخول</a></p>
        </div>
    </div>
</div>
@endsection
