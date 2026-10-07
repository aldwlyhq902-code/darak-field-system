@extends('layouts.panel')
@section('title', 'الإشعارات')

@section('content')
<h1>الإشعارات</h1>
<div class="sub">تنبيهات التشغيل والتحصيل والامتثال: الزيارات وSLA والتقارير والعقود والدفعات ووثائق الموظفين والسيارات والصيانة.</div>

<div class="note" style="margin-bottom:18px">
    رسائل واتساب تظهر هنا كرابط جاهز يرسله المشرف بنفسه.
    السبب: واجهة واتساب للأعمال تشترط اعتماد القوالب مسبقاً من المزود، وهذا لا يتم
    قبل تسجيل المنشأة. فبدل طابور يوهم بالإرسال، النظام يكتب الرسالة بدقة ويترك
    الإرسال لإنسان — وهذا مسار حقيقي لا التفاف.
</div>

@if (auth()->user()->isPlatformAdmin())
    <form method="POST" action="{{ route('panel.notifications.run') }}" style="margin-bottom:18px">
        @csrf
        <button class="btn">تشغيل الطابور</button>
    </form>
@endif

<div class="card">
    <div class="hd">في الطابور ({{ $queued->total() }})</div>
    @if ($queued->isEmpty())
        <div class="empty">لا شيء بانتظار الإرسال.</div>
    @else
        <table>
            <thead><tr><th style="width:130px">النوع</th><th>الرسالة</th><th style="width:120px">القناة</th><th style="width:150px"></th></tr></thead>
            <tbody>
            @foreach ($queued as $message)
                <tr>
                    <td><span class="pill grey">{{ \App\Support\Labels::of('notification_type', $message->type) }}</span></td>
                    <td style="font-size:13px;white-space:pre-line">{{ $message->body }}</td>
                    <td style="font-size:13px">
                        {{ \App\Support\Labels::of('notification_channel', $message->channel) }}
                    </td>
                    <td>
                        @if ($message->whatsappLink())
                            <a class="btn small" target="_blank" href="{{ $message->whatsappLink() }}">فتح واتساب</a>
                        @endif
                        <form method="POST" action="{{ route('panel.notifications.sent', $message) }}" style="display:inline">
                            @csrf
                            <button class="btn small ghost">وسم كمُرسلة</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @include('partials.pager', ['paginator' => $queued, 'label' => 'صفحات رسائل الطابور'])
    @endif
</div>

@if ($dead->isNotEmpty())
    <div class="card">
        <div class="hd" style="color:var(--red)">فشلت نهائياً ({{ $dead->total() }})</div>
        <div class="bd" style="padding-bottom:0">
            <div class="note">استنفدت محاولاتها. لا تختفي رسالة صامتة — تظهر هنا حتى يتصرف أحد.</div>
        </div>
        <table>
            <thead><tr><th>النوع</th><th>الخطأ</th><th style="width:80px">محاولات</th><th style="width:110px"></th></tr></thead>
            <tbody>
            @foreach ($dead as $message)
                <tr>
                    <td style="font-size:13px">{{ \App\Support\Labels::of('notification_type', $message->type) }}</td>
                    <td style="font-size:13px;color:var(--red)">{{ $message->last_error }}</td>
                    <td>{{ $message->attempts }}</td>
                    <td>
                        <form method="POST" action="{{ route('panel.notifications.retry', $message) }}">
                            @csrf
                            <button class="btn small ghost">إعادة</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @include('partials.pager', ['paginator' => $dead, 'label' => 'صفحات الرسائل الفاشلة'])
    </div>
@endif

<div class="card">
    <div class="hd">ما أُرسل ({{ $sent->total() }})</div>
    @if ($sent->isEmpty())
        <div class="empty">لا شيء بعد.</div>
    @else
        <table>
            <thead><tr><th style="width:130px">النوع</th><th>الرسالة</th><th style="width:150px">وقت الإرسال</th></tr></thead>
            <tbody>
            @foreach ($sent as $message)
                <tr>
                    <td style="font-size:13px">{{ \App\Support\Labels::of('notification_type', $message->type) }}</td>
                    <td style="font-size:13px">{{ Str::limit($message->body, 90) }}</td>
                    <td style="font-size:13px;color:var(--muted)">{{ $message->sent_at?->format('Y-m-d H:i') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @include('partials.pager', ['paginator' => $sent, 'label' => 'صفحات الرسائل المرسلة'])
    @endif
</div>
@endsection
