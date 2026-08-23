@extends('layouts.panel')
@section('title','خطط الصيانة الوقائية')
@section('content')
<h1>خطط الصيانة الوقائية</h1>
<div class="sub">توليد زيارات دورية آليًا حسب الموقع أو الأصل، مع موعد وتكرار وفني مفضل اختياري.</div>
<div class="grid2">
<div class="card"><div class="hd">إضافة خطة</div><div class="bd">
<form method="POST" action="{{ route('panel.maintenance.store') }}">@csrf
<div class="field"><label>العميل</label><select name="client_id" required><option value="">اختر</option>@foreach($clients as $client)<option value="{{ $client->id }}">{{ $client->name }}</option>@endforeach</select></div>
<div class="field"><label>الموقع</label><select name="site_id" required><option value="">اختر</option>@foreach($clients as $client)@foreach($client->sites as $site)<option value="{{ $site->id }}">{{ $client->name }} — {{ $site->name }}</option>@endforeach @endforeach</select></div>
<div class="field"><label>العقد النشط (اختياري)</label><select name="contract_id"><option value="">بدون عقد</option>@foreach($clients as $client)@foreach($client->contracts->where('status','active') as $contract)<option value="{{ $contract->id }}">{{ $client->name }} — {{ $contract->contract_no }}</option>@endforeach @endforeach</select></div>
<div class="field"><label>الأصل (اختياري؛ تركه فارغًا يشمل أصول الموقع)</label><select name="asset_id"><option value="">كل أصول الموقع</option>@foreach($clients as $client)@foreach($client->sites as $site)@foreach($site->assets as $asset)<option value="{{ $asset->id }}">{{ $site->name }} — {{ $asset->name }}</option>@endforeach @endforeach @endforeach</select></div>
<div class="field"><label>اسم الخطة</label><input name="title" maxlength="190" required></div>
<div class="grid3"><div class="field"><label>كل كم يومًا</label><input type="number" name="frequency_days" min="1" max="730" value="30" required></div><div class="field"><label>مدة الزيارة بالدقائق</label><input type="number" name="duration_minutes" min="30" max="720" value="120" required></div><div class="field"><label>وقت البدء</label><input type="time" name="preferred_start" value="09:00"></div></div>
<div class="field"><label>أول استحقاق</label><input type="date" name="next_due_on" min="{{ today()->format('Y-m-d') }}" required></div>
<div class="field"><label>فني مفضل (اختياري)</label><select name="preferred_user_id"><option value="">يُسند لاحقًا</option>@foreach($technicians as $tech)<option value="{{ $tech->id }}">{{ $tech->name }}</option>@endforeach</select></div>
<button class="btn">حفظ الخطة</button>
</form></div></div>
<div class="card"><div class="hd">التشغيل الآلي</div><div class="bd"><p>يفحص النظام الخطط يوميًا الساعة 05:00 ويولّد زيارة واحدة لكل استحقاق دون تكرار.</p><form method="POST" action="{{ route('panel.maintenance.generate') }}">@csrf<button class="btn ghost">توليد المستحق الآن</button></form></div></div>
</div>
<div class="card"><div class="hd">الخطط الحالية</div><div style="overflow:auto"><table><thead><tr><th>الخطة</th><th>العميل والموقع</th><th>التكرار</th><th>الاستحقاق القادم</th><th>الفني المفضل</th><th>الحالة</th></tr></thead><tbody>@forelse($plans as $plan)<tr><td><strong>{{ $plan->title }}</strong><br><small>{{ $plan->asset?->name ?? 'كل أصول الموقع' }}</small></td><td>{{ $plan->client->name }}<br><small>{{ $plan->site->name }}{{ $plan->contract ? ' · '.$plan->contract->contract_no : '' }}</small></td><td>كل {{ $plan->frequency_days }} يومًا<br><small>{{ $plan->duration_minutes }} دقيقة</small></td><td>{{ $plan->next_due_on->format('Y-m-d') }}<br><small>آخر توليد: {{ $plan->last_generated_on?->format('Y-m-d') ?? '—' }}</small></td><td>{{ $plan->preferredTechnician?->name ?? 'غير محدد' }}</td><td><span class="pill {{ $plan->is_active?'green':'grey' }}">{{ $plan->is_active?'نشطة':'متوقفة' }}</span><form method="POST" action="{{ route('panel.maintenance.toggle',$plan) }}" style="margin-top:5px">@csrf<button class="btn small ghost">{{ $plan->is_active?'إيقاف':'تفعيل' }}</button></form></td></tr>@empty<tr><td colspan="6" class="empty">لا توجد خطط بعد.</td></tr>@endforelse</tbody></table></div></div>
@endsection
