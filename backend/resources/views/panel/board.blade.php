@extends('layouts.panel')
@section('title', 'لوحة اليوم')

@section('content')
<h1>لوحة اليوم</h1>
<div class="sub">{{ $date->translatedFormat('l، j F Y') }}</div>

<div class="kpis">
    <div class="kpi"><div class="v">{{ $counts['total'] }}</div><div class="l">زيارات اليوم</div></div>
    <div class="kpi"><div class="v">{{ $counts['open'] }}</div><div class="l">مفتوحة</div></div>
    <div class="kpi"><div class="v">{{ $counts['done'] }}</div><div class="l">مقفلة</div></div>
    <div class="kpi">
        <div class="v" style="color:{{ $counts['red'] > 0 ? 'var(--red)' : 'inherit' }}">{{ $counts['red'] }}</div>
        <div class="l">تجاوزت SLA</div>
        <div class="n">داخل نافذة الخدمة</div>
    </div>
    <div class="kpi">
        <div class="v" style="color:{{ $counts['unassigned'] > 0 ? 'var(--amber)' : 'inherit' }}">{{ $counts['unassigned'] }}</div>
        <div class="l">بلا إسناد</div>
    </div>
    <div class="kpi">
        <div class="v">{{ $firstTimeFix['strict'] }}%</div>
        <div class="l">حل من أول زيارة</div>
        <div class="n">المعدّل بعد الاستثناءات: {{ $firstTimeFix['adjusted'] }}%</div>
    </div>
</div>

<div class="card">
    <div class="hd">الزيارات</div>
    @if ($rows->isEmpty())
        <div class="empty">لا توجد زيارات مجدولة في هذا اليوم.</div>
    @else
        <table>
            <thead>
            <tr>
                <th style="width:70px">الوقت</th>
                <th>العميل والموقع</th>
                <th style="width:120px">النوع</th>
                <th style="width:130px">الحالة</th>
                <th style="width:140px">الفني</th>
                <th style="width:130px">SLA</th>
                <th style="width:70px"></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                @php $visit = $row['visit']; @endphp
                <tr>
                    <td>{{ $visit->scheduled_start?->format('H:i') ?? '—' }}</td>
                    <td>
                        <strong>{{ $visit->workOrder?->client?->name }}</strong><br>
                        <span style="color:var(--muted);font-size:13px">{{ $visit->site?->name }} — {{ $visit->workOrder?->title }}</span>
                        @if ($visit->is_rework)
                            <span class="pill red" style="margin-inline-start:6px">إعادة زيارة</span>
                        @endif
                    </td>
                    <td>
                        @php $types = ['preventive' => 'وقائية', 'reactive' => 'بلاغ', 'out_of_contract' => 'خارج العقد']; @endphp
                        <span class="pill grey">{{ $types[$visit->workOrder?->type] ?? '—' }}</span>
                    </td>
                    <td>
                        @php
                            $states = [
                                'scheduled' => 'مجدولة', 'en_route' => 'في الطريق', 'started' => 'قيد التنفيذ',
                                'paused' => 'متوقفة', 'awaiting_close' => 'بانتظار الإقفال',
                                'completed' => 'مقفلة', 'reopened' => 'أُعيد فتحها',
                            ];
                        @endphp
                        <span class="pill {{ $visit->state === 'completed' ? 'green' : 'grey' }}">
                            {{ $states[$visit->state] ?? $visit->state }}
                        </span>
                    </td>
                    <td>{{ $visit->technician?->name ?? '—' }}</td>
                    <td>
                        @if ($row['sla_status'])
                            <span class="pill {{ $row['sla_status'] }}">
                                @if ($row['sla_remaining'] < 0)
                                    تأخر {{ abs($row['sla_remaining']) }} د
                                @else
                                    {{ $row['sla_remaining'] }} د
                                @endif
                            </span>
                            @unless ($row['in_window'])
                                <div style="font-size:11px;color:var(--muted)">خارج نافذة الخدمة — العداد متوقف</div>
                            @endunless
                        @else
                            —
                        @endif
                    </td>
                    <td><a class="btn small ghost" href="{{ route('panel.visit', $visit) }}">فتح</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="card">
    <div class="hd">الأجهزة وآخر مزامنة</div>
    <div class="bd" style="padding-bottom:6px">
        <div class="note" style="margin-bottom:14px">
            جهاز لم يزامن منذ فترة طويلة قد يكون خارج التغطية أو التطبيق مغلق.
            البيانات المعروضة عنه قديمة بقدر هذا الرقم — لا تُقرأ كأنها لحظية.
        </div>
    </div>
    @if ($devices->isEmpty())
        <div class="empty">لا توجد أجهزة مسجلة بعد. يسجّل الفني دخوله من التطبيق فيظهر جهازه هنا.</div>
    @else
        <table>
            <thead>
            <tr><th>الفني</th><th style="width:150px">آخر مزامنة</th><th style="width:140px">انحراف الساعة</th><th>الإصدار</th></tr>
            </thead>
            <tbody>
            @foreach ($devices as $entry)
                @php $minutes = $entry['minutes_since_sync']; @endphp
                <tr>
                    <td>{{ $entry['device']->user?->name ?? '—' }}</td>
                    <td>
                        @if ($minutes === null)
                            <span class="pill grey">لم يزامن بعد</span>
                        @else
                            <span class="pill {{ $minutes > 180 ? 'red' : ($minutes > 60 ? 'amber' : 'green') }}">
                                قبل {{ (int) $minutes }} دقيقة
                            </span>
                        @endif
                    </td>
                    <td>
                        @if ($entry['device']->clock_skew_seconds === null)
                            —
                        @else
                            <span class="pill {{ abs($entry['device']->clock_skew_seconds) > 120 ? 'red' : 'grey' }}">
                                {{ $entry['device']->clock_skew_seconds }} ثانية
                            </span>
                        @endif
                    </td>
                    <td style="color:var(--muted);font-size:13px">{{ $entry['device']->app_version ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
