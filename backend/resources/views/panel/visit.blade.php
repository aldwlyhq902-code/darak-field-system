@extends('layouts.panel')
@section('title', 'زيارة ' . $visit->id)

@section('content')
<h1>{{ $visit->workOrder?->client?->name }} — {{ $visit->site?->name }}</h1>
<div class="sub">
    أمر العمل {{ $visit->workOrder?->wo_number }} ·
    زيارة #{{ $visit->id }} ·
    <a href="{{ route('panel.board') }}">رجوع للوحة</a>
</div>

<div class="grid2">
    <div>
        <div class="card">
            <div class="hd">التنفيذ</div>
            <table>
                <tr><th style="width:40%">الحالة</th><td>{{ $visit->state }}</td></tr>
                <tr><th>الفني</th><td>{{ $visit->technician?->name ?? 'بلا إسناد' }}</td></tr>
                <tr><th>الموعد</th><td>{{ $visit->scheduled_start?->format('Y-m-d H:i') }}</td></tr>
                <tr><th>بدء العمل</th><td>{{ $visit->started_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
                <tr><th>الإقفال</th><td>{{ $visit->closed_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
                <tr><th>زمن الموقع</th><td>{{ intdiv($visit->on_site_seconds, 60) }} دقيقة</td></tr>
                <tr>
                    <th>التصنيف</th>
                    <td>
                        @if ($visit->is_rework)
                            <span class="pill red">إعادة زيارة</span>
                            @if ($visit->rework_system_flagged)
                                <span style="font-size:12px;color:var(--muted)">وسمها النظام آلياً</span>
                            @endif
                        @else
                            <span class="pill green">زيارة عادية</span>
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        <div class="card">
            <div class="hd">الأصول وقائمة الفحص</div>
            @if ($visit->checklistInstances->isEmpty())
                <div class="empty">لم تُسجَّل حالة أي أصل بعد.</div>
            @else
                <table>
                    <thead><tr><th>الأصل</th><th style="width:110px">الحالة</th><th>ملاحظة</th></tr></thead>
                    <tbody>
                    @foreach ($visit->checklistInstances as $item)
                        @php $labels = ['ok' => ['سليم','green'], 'needs_followup' => ['يحتاج متابعة','amber'], 'fault' => ['عطل','red']]; @endphp
                        <tr>
                            <td>{{ $item->asset?->name }}</td>
                            <td><span class="pill {{ $labels[$item->status][1] ?? 'grey' }}">{{ $labels[$item->status][0] ?? '—' }}</span></td>
                            <td style="font-size:13px">{{ $item->note ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card">
            <div class="hd">قطع الغيار</div>
            @if (empty($consumption))
                <div class="empty">لم تُستخدم قطع في هذه الزيارة.</div>
            @else
                <table>
                    <thead><tr><th>الصنف</th><th style="width:90px">الكمية</th><th style="width:110px">التكلفة</th><th style="width:110px">سعر البيع</th></tr></thead>
                    <tbody>
                    @foreach ($consumption as $row)
                        <tr>
                            <td>{{ $row['name'] }} <span style="color:var(--muted);font-size:12px">({{ $row['sku'] }})</span></td>
                            <td>{{ rtrim(rtrim(number_format($row['qty'], 3), '0'), '.') }}</td>
                            <td>{{ number_format($row['unit_cost'], 2) }}</td>
                            <td>{{ number_format($row['unit_price'], 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div>
        <div class="card">
            <div class="hd">بوابة الإقفال</div>
            <div class="bd">
                @if (empty($blockers))
                    <span class="pill green">مستوفاة — يمكن الإقفال</span>
                @else
                    <div style="margin-bottom:10px"><span class="pill amber">{{ count($blockers) }} نواقص</span></div>
                    @foreach ($blockers as $blocker)
                        <div style="padding:6px 0;border-bottom:1px solid var(--line);font-size:14px">
                            • {{ $blocker['message_ar'] }}
                        </div>
                    @endforeach
                @endif
            </div>
        </div>

        <div class="card">
            <div class="hd">الأدلة</div>
            <div class="bd">
                @php
                    $photos = $visit->mediaFiles->filter(fn ($m) => str_starts_with($m->kind, 'photo'));
                    $signature = $visit->mediaFiles->firstWhere('kind', 'signature');
                @endphp
                <div>الصور المرفوعة: <strong>{{ $photos->where('upload_state', 'complete')->count() }}</strong>
                    من {{ $photos->count() }}</div>
                <div style="margin-top:6px">
                    التوقيع:
                    @if ($signature && $signature->upload_state === 'complete')
                        <span class="pill green">موجود</span>
                    @else
                        <span class="pill grey">غير موجود</span>
                    @endif
                </div>
                @if ($visit->state === 'completed')
                    <a class="btn small" style="margin-top:12px" target="_blank"
                       href="{{ url('/api/v1/visits/' . $visit->id . '/report.pdf') }}">تقرير PDF</a>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="hd">الإسناد</div>
            <div class="bd">
                <form method="POST" action="{{ route('panel.visit.assign', $visit) }}">
                    @csrf
                    <div class="field">
                        <label>الفني</label>
                        <select name="user_id" required>
                            @foreach ($technicians as $technician)
                                <option value="{{ $technician->id }}" @selected($visit->assigned_user_id === $technician->id)>
                                    {{ $technician->name }} ({{ substr($technician->shift_start ?? '', 0, 5) }}–{{ substr($technician->shift_end ?? '', 0, 5) }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn">إسناد</button>
                </form>
                <div class="note" style="margin-top:12px">
                    الإسناد يدوي عمداً. يرفض النظام الإسناد خارج الوردية أو مع تداخل زمني
                    أو لتخصص غير مسجل للفني، ويقترح توجيه المهمة لمقاول باطن.
                </div>
            </div>
        </div>

        @if ($visit->is_rework && $visit->rework_system_flagged)
            <div class="card">
                <div class="hd">إعادة تصنيف</div>
                <div class="bd">
                    <form method="POST" action="{{ route('panel.visit.rework', $visit) }}">
                        @csrf
                        <div class="field">
                            <label>السبب</label>
                            <select name="reason">
                                <option value="client_request">طلب جديد من العميل</option>
                                <option value="unrelated">عطل غير مرتبط</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>ملاحظة (تُسجَّل في سجل التدقيق)</label>
                            <textarea name="note" rows="2" required></textarea>
                        </div>
                        <button class="btn ghost">إعادة التصنيف</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="hd">آخر الأحداث</div>
            <div class="bd" style="max-height:320px;overflow:auto">
                @forelse ($visit->events as $event)
                    <div style="padding:6px 0;border-bottom:1px solid var(--line);font-size:13px">
                        <strong>{{ $event->event_type }}</strong>
                        <span style="color:var(--muted)">
                            · {{ $event->server_received_at?->format('m-d H:i') }}
                            @if ($event->clock_suspect)
                                <span class="pill red">ساعة مشبوهة</span>
                            @endif
                        </span>
                    </div>
                @empty
                    <div class="empty">لا أحداث بعد.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
