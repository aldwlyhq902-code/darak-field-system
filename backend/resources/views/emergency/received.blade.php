@extends('layouts.emergency')
@section('title','تم استلام البلاغ — دارك')
@section('content')<div class="card success"><div class="success-icon">✓</div><h1>تم استلام البلاغ</h1><p>سيُراجع فريق العمليات التفاصيل ويتواصل عبر الرقم المسجل.</p><p class="muted">احتفظ برقم المتابعة:</p><div class="ref">{{ $report->public_reference }}</div><p class="muted" style="margin-top:18px">إرسال البلاغ لا يعني قبول تكلفة أو تحديد موعد تلقائيًا؛ يراجع المشرف العقد والحالة أولاً.</p></div>@endsection
