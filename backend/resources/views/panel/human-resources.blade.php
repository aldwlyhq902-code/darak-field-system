@extends('layouts.panel')
@section('title','شؤون الموظفين')
@section('content')
@php
    $documentLabels=['iqama'=>'الإقامة','passport'=>'جواز السفر','work_permit'=>'رخصة العمل','medical_insurance'=>'التأمين الطبي','contract'=>'عقد العمل','other'=>'أخرى'];
    $leaveLabels=['annual'=>'سنوية','sick'=>'مرضية','unpaid'=>'دون راتب','emergency'=>'طارئة','other'=>'أخرى'];
@endphp
<h1>شؤون الموظفين والامتثال</h1>
<div class="sub">ملفات الموظفين، الإقامات والوثائق، أرصدة الإجازات والاعتماد والتنبيه المبكر قبل الانتهاء.</div>

<div class="kpis">
    <div class="kpi"><div class="v">{{ $users->count() }}</div><div class="l">موظف مسجل</div></div>
    <div class="kpi"><div class="v">{{ $expiringDocuments->where('expires_on','<',now()->startOfDay())->count() }}</div><div class="l">وثائق منتهية</div></div>
    <div class="kpi"><div class="v">{{ $expiringDocuments->where('expires_on','>=',now()->startOfDay())->count() }}</div><div class="l">تنتهي خلال 90 يومًا</div></div>
    <div class="kpi"><div class="v">{{ $pendingLeaves->count() }}</div><div class="l">طلبات إجازة معلقة</div></div>
</div>

<div class="card">
    <div class="hd">تنبيهات الوثائق</div>
    <div style="overflow:auto"><table><thead><tr><th>الموظف</th><th>الوثيقة</th><th>الرقم</th><th>الانتهاء</th><th>الحالة</th><th></th></tr></thead><tbody>
    @forelse($expiringDocuments as $document)
        @php $days=now()->startOfDay()->diffInDays($document->expires_on,false); @endphp
        <tr><td><strong>{{ $document->user->name }}</strong></td><td>{{ $documentLabels[$document->type] ?? $document->type }}</td><td dir="ltr">{{ $document->document_number ?? '—' }}</td><td>{{ $document->expires_on->format('Y-m-d') }}</td><td><span class="pill {{ $days<0?'red':($days<=30?'amber':'grey') }}">{{ $days<0?'منتهية منذ '.abs($days).' يوم':($days===0?'تنتهي اليوم':'بعد '.$days.' يوم') }}</span></td><td>@if($document->file_path)<a class="btn small ghost" href="{{ route('panel.hr.document.download',$document) }}">تنزيل</a>@endif</td></tr>
    @empty<tr><td colspan="6" class="empty">لا توجد وثائق منتهية أو قريبة من الانتهاء.</td></tr>@endforelse
    </tbody></table></div>
</div>

<div class="grid2">
    <div class="card"><div class="hd">طلب إجازة</div><div class="bd"><form method="POST" action="{{ route('panel.hr.leave') }}">@csrf
        <div class="field"><label>الموظف</label><select name="user_id" required>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div>
        <div class="grid3"><div class="field"><label>النوع</label><select name="type">@foreach($leaveLabels as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div><div class="field"><label>من</label><input type="date" name="starts_on" required></div><div class="field"><label>إلى</label><input type="date" name="ends_on" required></div></div>
        <div class="field"><label>السبب أو الملاحظة</label><textarea name="reason"></textarea></div><button class="btn">إرسال للاعتماد</button>
    </form></div></div>
    <div class="card"><div class="hd">طلبات تنتظر القرار</div>
        @forelse($pendingLeaves as $leave)<div class="bd" style="border-bottom:1px solid var(--line)"><strong>{{ $leave->user->name }}</strong> · {{ $leaveLabels[$leave->type] ?? $leave->type }} · {{ $leave->days }} يوم<br><small>{{ $leave->starts_on->format('Y-m-d') }} — {{ $leave->ends_on->format('Y-m-d') }} · {{ $leave->reason }}</small>
            <form method="POST" action="{{ route('panel.hr.leave.decision',$leave) }}" style="margin-top:8px">@csrf<div class="grid2"><select name="decision"><option value="approve">اعتماد</option><option value="reject">رفض</option></select><input name="response_note" placeholder="ملاحظة القرار"></div><button class="btn small">حفظ القرار</button></form>
        </div>@empty<div class="empty">لا توجد طلبات معلقة.</div>@endforelse
    </div>
</div>

<div class="card"><div class="hd">سجل الإجازات</div><div style="overflow:auto"><table><thead><tr><th>الموظف</th><th>النوع</th><th>الفترة</th><th>الأيام</th><th>الحالة</th></tr></thead><tbody>@forelse($recentLeaves as $leave)<tr><td>{{ $leave->user->name }}</td><td>{{ $leaveLabels[$leave->type] ?? $leave->type }}</td><td>{{ $leave->starts_on->format('Y-m-d') }} — {{ $leave->ends_on->format('Y-m-d') }}</td><td>{{ $leave->days }}</td><td><span class="pill {{ $leave->status==='approved'?'green':($leave->status==='rejected'?'red':'amber') }}">{{ ['pending'=>'معلق','approved'=>'معتمد','rejected'=>'مرفوض'][$leave->status] ?? $leave->status }}</span></td></tr>@empty<tr><td colspan="5" class="empty">لا توجد إجازات.</td></tr>@endforelse</tbody></table></div></div>

<h2 style="font-size:19px">ملفات الموظفين</h2>
@foreach($users as $user)
@php $balance=$balances[$user->id]; @endphp
<div class="card">
    <div class="hd"><strong>{{ $user->name }}</strong><span class="pill grey">{{ $user->trade ?? $user->role }}</span><span style="margin-inline-start:auto;font-size:12px;color:var(--muted)">المتبقي السنوي: {{ $balance['remaining'] }} من {{ $balance['entitlement'] }} يوم</span></div>
    <div class="bd"><div class="grid2">
        <details><summary style="cursor:pointer;color:var(--teal);font-weight:600">الملف الوظيفي</summary><form method="POST" action="{{ route('panel.hr.profile',$user) }}" style="margin-top:12px">@csrf @method('PUT')
            <div class="grid3"><div class="field"><label>الرقم الوظيفي</label><input name="employee_no" value="{{ $user->employeeProfile?->employee_no }}"></div><div class="field"><label>الجنسية</label><input name="nationality" value="{{ $user->employeeProfile?->nationality }}"></div><div class="field"><label>تاريخ التعيين</label><input type="date" name="hired_on" value="{{ $user->employeeProfile?->hired_on?->format('Y-m-d') }}"></div></div>
            <div class="grid3"><div class="field"><label>الرصيد السنوي</label><input type="number" step="0.5" name="annual_leave_days" value="{{ $user->employeeProfile?->annual_leave_days ?? 21 }}" required></div><div class="field"><label>اسم اتصال الطوارئ</label><input name="emergency_contact_name" value="{{ $user->employeeProfile?->emergency_contact_name }}"></div><div class="field"><label>جوال الطوارئ</label><input name="emergency_contact_phone" value="{{ $user->employeeProfile?->emergency_contact_phone }}" dir="ltr"></div></div>
            <div class="field"><label>ملاحظات</label><textarea name="notes">{{ $user->employeeProfile?->notes }}</textarea></div><button class="btn small">حفظ الملف</button>
        </form></details>
        <details><summary style="cursor:pointer;color:var(--teal);font-weight:600">إضافة أو تجديد وثيقة</summary><form method="POST" action="{{ route('panel.hr.document',$user) }}" enctype="multipart/form-data" style="margin-top:12px">@csrf
            <div class="grid3"><div class="field"><label>النوع</label><select name="type">@foreach($documentLabels as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div><div class="field"><label>رقم الوثيقة</label><input name="document_number" dir="ltr"></div><div class="field"><label>جهة الإصدار</label><input name="issuer"></div></div>
            <div class="grid3"><div class="field"><label>تاريخ الإصدار</label><input type="date" name="issued_on"></div><div class="field"><label>تاريخ الانتهاء</label><input type="date" name="expires_on"></div><div class="field"><label>المرفق</label><input type="file" name="file" accept="application/pdf,image/png,image/jpeg,image/webp"></div></div>
            <div class="field"><label>ملاحظات</label><input name="notes"></div><button class="btn small">حفظ الوثيقة</button>
        </form></details>
    </div></div>
    @if($user->employeeDocuments->isNotEmpty())<div style="overflow:auto"><table><thead><tr><th>الوثيقة</th><th>الرقم</th><th>الانتهاء</th><th></th></tr></thead><tbody>@foreach($user->employeeDocuments as $document)<tr><td>{{ $documentLabels[$document->type] ?? $document->type }}</td><td dir="ltr">{{ $document->document_number ?? '—' }}</td><td>{{ $document->expires_on?->format('Y-m-d') ?? 'غير محدد' }}</td><td>@if($document->file_path)<a href="{{ route('panel.hr.document.download',$document) }}">تنزيل</a>@endif</td></tr>@endforeach</tbody></table></div>@endif
</div>
@endforeach
@endsection
