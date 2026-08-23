@extends('layouts.client')
@section('title','لا توجد شبكة — دارك')
@section('content')<div class="form-card" style="text-align:center;margin-top:50px"><div style="font-size:44px">⌁</div><h1>لا توجد شبكة الآن</h1><p class="muted">يمكنك العودة للصفحات التي فُتحت سابقاً. إرسال بلاغ جديد يحتاج اتصالاً حتى يصل إلى غرفة العمليات.</p><a class="btn" href="{{ url()->current() }}">إعادة المحاولة</a></div>@endsection
