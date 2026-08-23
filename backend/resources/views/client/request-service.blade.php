@extends('layouts.client')
@section('title','طلب موعد صيانة — دارك')
@section('content')
<a href="{{ route('client.home') }}">← العودة</a>
<div class="form-card" style="margin-top:16px"><h1>طلب موعد صيانة</h1><p class="muted">اختر موعدًا مفضلًا. العدد الظاهر تقدير للسعة المتاحة، ويصبح الموعد مؤكدًا بعد اعتماد المشرف.</p>
<form method="POST" action="{{ route('client.service-request.store') }}">@csrf
<div class="field"><label>الموقع</label><select name="site_id" required><option value="">اختر الموقع</option>@foreach($client->sites as $site)<option value="{{ $site->id }}" @selected(old('site_id')==$site->id)>{{ $site->name }}</option>@endforeach</select></div>
<div class="field"><label>الأصل (اختياري)</label><select name="asset_id"><option value="">الخدمة تخص الموقع عمومًا</option>@foreach($client->sites as $site)@foreach($site->assets as $asset)<option value="{{ $asset->id }}">{{ $site->name }} — {{ $asset->name }}</option>@endforeach @endforeach</select></div>
<div class="field"><label>نوع الطلب</label><select name="category" required><option value="reactive">عطل / إصلاح</option><option value="preventive">صيانة وقائية</option><option value="inspection">فحص وتشخيص</option></select></div>
<div class="field"><label>وصف الطلب</label><textarea name="description" rows="4" minlength="10" maxlength="2000" required>{{ old('description') }}</textarea></div>
<div class="field"><label>التاريخ المفضل</label><select name="preferred_date" required><option value="">اختر التاريخ</option>@foreach($capacity as $day)<option value="{{ $day['date']->format('Y-m-d') }}">{{ $day['date']->translatedFormat('l d/m/Y') }}</option>@endforeach</select></div>
<div class="field"><label>الفترة المفضلة</label><select name="preferred_time_slot" required><option value="morning">صباحًا 07:00–11:00</option><option value="afternoon">ظهرًا 11:00–15:00</option><option value="evening">مساءً 15:00–19:00</option></select></div>
<button class="btn" style="width:100%">إرسال طلب الموعد</button></form></div>
<h2>السعة التقديرية للأيام القادمة</h2><div class="list">@foreach($capacity as $day)<div class="row"><strong>{{ $day['date']->translatedFormat('l d/m') }}</strong><div>@foreach($day['slots'] as $slot)<span class="pill {{ $slot['available']>0?'green':'amber' }}">{{ $slot['label'] }} · {{ $slot['available']>0?$slot['available'].' متاح':'ممتلئ' }}</span> @endforeach</div></div>@endforeach</div>
@endsection
