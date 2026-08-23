@extends('layouts.emergency')
@section('title','بلاغ طارئ — {{ $site->name }}')
@section('content')
<div class="card"><h1>ما المشكلة الآن؟</h1><p class="muted">لا تحتاج إلى حساب. سيصل البلاغ إلى غرفة عمليات دارك للمراجعة والتواصل.</p><div class="site"><strong>{{ $site->client->name }}</strong><br>{{ $site->name }} @if($site->address)<span class="muted">— {{ $site->address }}</span>@endif</div>
<div class="warning">إذا كان هناك حريق، تسرب غاز خطير، أو خطر مباشر على الأرواح: أخلِ المكان واتصل بخدمات الطوارئ الرسمية أولاً. هذا النموذج ليس بديلاً عنها.</div>
@if($errors->any())<div class="errors">@foreach($errors->all() as $error)<div>• {{ $error }}</div>@endforeach</div>@endif
<form method="POST" action="{{ route('emergency.store',$token) }}" enctype="multipart/form-data">@csrf
<div class="hp" aria-hidden="true"><label>الشركة<input name="company" tabindex="-1" autocomplete="off"></label></div>
<div class="grid"><div class="field"><label>اسم المبلّغ *</label><input name="reporter_name" value="{{ old('reporter_name') }}" maxlength="100" autocomplete="name" required></div><div class="field"><label>رقم الجوال للتواصل *</label><input name="reporter_phone" value="{{ old('reporter_phone') }}" inputmode="tel" autocomplete="tel" placeholder="05xxxxxxxx" required></div></div>
<div class="field"><label>الصفة أو القسم</label><input name="reporter_role" value="{{ old('reporter_role') }}" placeholder="مدير الفرع، المطبخ، الصيانة..."></div>
<div class="grid"><div class="field"><label>نوع المشكلة *</label><select name="category" required><option value="ac">تكييف</option><option value="refrigeration">تبريد أو تجميد</option><option value="electrical">كهرباء</option><option value="plumbing">سباكة</option><option value="gas">غاز</option><option value="other">أخرى</option></select></div><div class="field"><label>درجة التأثير *</label><select name="severity" required><option value="urgent">عاجل — يؤثر على التشغيل</option><option value="critical">حرج — أوقف جزءاً من الموقع</option></select></div></div>
<div class="field"><label>المعدة المعنية (اختياري)</label><select name="asset_id"><option value="">غير معروف / المشكلة عامة</option>@foreach($site->assets as $asset)<option value="{{ $asset->id }}">{{ $asset->name }} — {{ $asset->location_in_site }}</option>@endforeach</select></div>
<div class="field"><label>صف المشكلة بوضوح *</label><textarea name="description" rows="5" minlength="10" maxlength="1500" placeholder="متى بدأت؟ ماذا توقف؟ هل توجد رائحة أو صوت أو تسرب؟" required>{{ old('description') }}</textarea></div>
<div class="field"><label>صورة للمشكلة (اختياري، حتى 8MB)</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment"></div>
<button class="btn">إرسال البلاغ إلى دارك</button></form></div>
@endsection
