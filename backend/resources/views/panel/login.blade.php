@extends('layouts.panel')
@section('title', 'دخول لوحة دارك')

@section('content')
<div style="max-width:400px;margin:60px auto">
    <div style="text-align:center;margin-bottom:22px">
        <div style="font-size:34px;font-weight:700;color:var(--teal)">دارك</div>
        <div class="sub" style="margin:0">دخول الإدارة والمشرفين وفريق المبيعات</div>
    </div>

    <div class="card">
        <div class="bd">
            <form method="POST" action="{{ route('panel.login') }}">
                @csrf
                <div class="field">
                    <label for="panel-email">البريد الإلكتروني</label>
                    <input id="panel-email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                </div>
                <div class="field">
                    <label for="panel-password">كلمة المرور</label>
                    <input id="panel-password" type="password" name="password" autocomplete="current-password" required>
                </div>
                <button class="btn" style="width:100%">دخول</button>
            </form>
        </div>
    </div>

    <div class="note">
        الفنيون لا يدخلون من هنا — لهم تطبيق الجوال. المسوق ينتقل تلقائيًا إلى تطبيق المبيعات بعد التحقق بخطوتين.
    </div>
</div>
@endsection
