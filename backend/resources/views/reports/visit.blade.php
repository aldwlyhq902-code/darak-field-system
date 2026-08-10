@php
    /** @var \App\Models\Visit $visit */
    $builder = app(\App\Services\VisitReportBuilder::class);
    $client = $visit->site->client;
    $contract = $visit->workOrder?->contract;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: xbriyaz, dejavusans, sans-serif; font-size: 11pt; color: #1a1a1a; direction: rtl; }
        h1 { font-size: 16pt; margin: 0 0 2mm; }
        .muted { color: #666; font-size: 9pt; }
        .head { border-bottom: 2px solid #0f766e; padding-bottom: 3mm; margin-bottom: 4mm; }
        .brand { color: #0f766e; font-weight: bold; font-size: 18pt; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
        th, td { border: 1px solid #d4d4d4; padding: 2mm 2.5mm; text-align: right; font-size: 10pt; }
        th { background: #f0fdfa; font-weight: bold; }
        .section { font-size: 12pt; font-weight: bold; margin: 5mm 0 2mm; color: #0f766e; }
        .status-ok { color: #15803d; font-weight: bold; }
        .status-followup { color: #b45309; font-weight: bold; }
        .status-fault { color: #b91c1c; font-weight: bold; }
        .photo { width: 78mm; margin: 0 0 3mm 3mm; border: 1px solid #ddd; }
        .sig { width: 60mm; border: 1px solid #ddd; padding: 2mm; }
        .note { background: #fffbeb; border: 1px solid #fde68a; padding: 3mm; font-size: 9pt; margin-top: 6mm; }
        .foot { margin-top: 8mm; border-top: 1px solid #ddd; padding-top: 2mm; font-size: 8pt; color: #777; }
    </style>
</head>
<body>

<div class="head">
    <span class="brand">دارك</span>
    <h1>تقرير حالة فنية وسجل صيانة</h1>
    <div class="muted">
        رقم الزيارة: {{ $visit->id }} ·
        أمر العمل: {{ $visit->workOrder?->wo_number }} ·
        تاريخ الإصدار: {{ now()->format('Y-m-d H:i') }}
    </div>
</div>

<div class="section">بيانات الموقع</div>
<table>
    <tr><th style="width:28%">العميل</th><td>{{ $client->name }}</td></tr>
    <tr><th>الموقع</th><td>{{ $visit->site->name }} — {{ $visit->site->address }}</td></tr>
    <tr><th>نوع الزيارة</th><td>
        {{ ['preventive' => 'صيانة وقائية', 'reactive' => 'بلاغ', 'out_of_contract' => 'عمل خارج العقد'][$visit->workOrder?->type] ?? '—' }}
        @if($visit->is_rework) <strong>(زيارة إعادة — غير مفوترة)</strong> @endif
    </td></tr>
    <tr><th>العقد</th><td>{{ $contract?->contract_no ?? 'بلا عقد' }}</td></tr>
    <tr><th>الفني</th><td>{{ $visit->technician?->name ?? '—' }}</td></tr>
    <tr><th>بدء العمل</th><td>{{ $visit->started_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
    <tr><th>الإقفال</th><td>{{ $visit->closed_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
    <tr><th>زمن العمل في الموقع</th><td>{{ intdiv($visit->on_site_seconds, 60) }} دقيقة</td></tr>
</table>

<div class="section">الأصول المفحوصة</div>
<table>
    <thead>
    <tr>
        <th style="width:26%">الأصل</th>
        <th style="width:16%">النوع</th>
        <th style="width:16%">الحالة</th>
        <th>الملاحظات</th>
    </tr>
    </thead>
    <tbody>
    @forelse($visit->checklistInstances as $item)
        <tr>
            <td>{{ $item->asset?->name }}</td>
            <td>{{ $item->asset?->type }}</td>
            <td class="status-{{ ['ok' => 'ok', 'needs_followup' => 'followup', 'fault' => 'fault'][$item->status] ?? 'ok' }}">
                {{ ['ok' => 'سليم', 'needs_followup' => 'يحتاج متابعة', 'fault' => 'عطل'][$item->status] ?? '—' }}
            </td>
            <td>{{ $item->note ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="4">لا توجد أصول مسجلة في هذه الزيارة.</td></tr>
    @endforelse
    </tbody>
</table>

<div class="section">قطع الغيار المستخدمة</div>
<table>
    <thead>
    <tr><th style="width:20%">الرمز</th><th>الصنف</th><th style="width:14%">الكمية</th></tr>
    </thead>
    <tbody>
    @forelse($parts as $part)
        <tr><td>{{ $part['sku'] }}</td><td>{{ $part['name'] }}</td><td>{{ rtrim(rtrim(number_format($part['qty'], 3), '0'), '.') }}</td></tr>
    @empty
        <tr><td colspan="3">لم تُستخدم قطع غيار في هذه الزيارة.</td></tr>
    @endforelse
    </tbody>
</table>

@if($photos->isNotEmpty())
    <div class="section">الأدلة المصورة</div>
    <div>
        @foreach($photos as $photo)
            @php $uri = $builder->imageDataUri($photo->derived_path ?? $photo->original_path); @endphp
            @if($uri)
                <img class="photo" src="{{ $uri }}" alt="evidence">
            @endif
        @endforeach
    </div>
@endif

@if($signature)
    <div class="section">اعتماد مسؤول الموقع</div>
    @php $sigUri = $builder->imageDataUri($signature->original_path); @endphp
    @if($sigUri)
        <img class="sig" src="{{ $sigUri }}" alt="signature">
    @endif
    <div class="muted">وقّع بتاريخ {{ $signature->captured_at?->format('Y-m-d H:i') ?? '—' }}</div>
@endif

<div class="note">
    هذا التقرير سجل فني لأعمال الصيانة المنفذة في الموقع أعلاه. الشهادات الرسمية
    (السلامة، أنظمة الغاز، تنظيف الهود) تصدر عن الجهات المعتمدة لدى الجهات المختصة،
    وتُرفق منفصلة باسم الجهة المُصدِرة، وتتولى دارك تنسيقها فقط.
</div>

<div class="foot">
    نسخة القالب: {{ $templateVersion }} · وُلّد آلياً من نظام دارك · الصور مختومة بوقت الالتقاط والإحداثيات.
</div>

</body>
</html>
