@extends('layouts.panel')
@section('title', 'تأكيد كلمة المرور')

@section('content')
<div style="max-width:460px;margin:clamp(24px,8vh,72px) auto">
    <div class="card">
        <div class="hd" style="font-size:18px">تأكيد هويتك</div>
        <div class="bd">
            <p class="sub">لحماية حسابك، أعد إدخال كلمة المرور قبل عرض مفتاح التحقق بخطوتين الجديد.</p>
            <form method="POST" action="{{ route('panel.password.confirm') }}">
                @csrf
                <div class="field">
                    <label for="confirm-password">كلمة المرور</label>
                    <input id="confirm-password" type="password" name="password" autocomplete="current-password" required autofocus>
                </div>
                <button class="btn" style="width:100%;font-weight:700">متابعة</button>
            </form>
        </div>
    </div>
</div>
@endsection
