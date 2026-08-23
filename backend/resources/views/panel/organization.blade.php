@extends('layouts.panel')
@section('title','بيانات المؤسسة')
@section('content')
<h1>بيانات المؤسسة والشعار</h1>
<div class="sub">تُستخدم هذه البيانات لتعريف المؤسسة داخل لوحة التحكم، ويمكن لاحقًا توظيفها في العروض والعقود والفواتير والتقارير.</div>

@forelse($companies as $company)
<div class="card">
    <div class="hd">
        <span>{{ $company->name }}</span>
        <span class="pill {{ $company->is_active ? 'green' : 'grey' }}">{{ $company->is_active ? 'نشطة' : 'غير نشطة' }}</span>
        <span style="margin-inline-start:auto;color:var(--muted);font-size:12px">{{ $company->branches_count }} فرع</span>
    </div>
    <div class="bd">
        <form method="POST" action="{{ route('panel.admin.company.update', $company) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <div class="org-profile">
                <div>
                    <div class="org-logo">
                        @if($company->logo_path)
                            <img src="{{ route('panel.organization.logo', $company) }}" alt="شعار {{ $company->name }}">
                        @else
                            <span>لم يُرفع شعار بعد</span>
                        @endif
                    </div>
                    <div class="field" style="margin-top:10px">
                        <label for="logo-{{ $company->id }}">الشعار</label>
                        <input id="logo-{{ $company->id }}" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
                        <div class="org-meta">PNG أو JPG أو WebP، بحد أقصى 2MB.</div>
                    </div>
                    @if($company->logo_path)
                    <label style="color:var(--red)"><input name="remove_logo" type="checkbox" value="1" style="width:auto"> حذف الشعار الحالي</label>
                    @endif
                </div>

                <div>
                    <div class="grid2">
                        <div class="field"><label>الاسم التجاري</label><input name="name" value="{{ old('name', $company->name) }}" required maxlength="190"></div>
                        <div class="field"><label>الاسم القانوني</label><input name="legal_name" value="{{ old('legal_name', $company->legal_name) }}" maxlength="190"></div>
                    </div>
                    <div class="grid3">
                        <div class="field"><label>السجل التجاري</label><input name="cr_number" value="{{ old('cr_number', $company->cr_number) }}" maxlength="32" dir="ltr"></div>
                        <div class="field"><label>الرقم الضريبي</label><input name="vat_number" value="{{ old('vat_number', $company->vat_number) }}" maxlength="32" dir="ltr"></div>
                        <div class="field"><label>العملة</label><input name="currency" value="{{ old('currency', $company->currency) }}" maxlength="3" required dir="ltr"></div>
                    </div>
                    <div class="grid3">
                        <div class="field"><label>الهاتف</label><input name="phone" value="{{ old('phone', $company->phone) }}" maxlength="32" dir="ltr"></div>
                        <div class="field"><label>واتساب</label><input name="whatsapp" value="{{ old('whatsapp', $company->whatsapp) }}" maxlength="32" dir="ltr"></div>
                        <div class="field"><label>البريد الإلكتروني</label><input name="email" type="email" value="{{ old('email', $company->email) }}" maxlength="190" dir="ltr"></div>
                    </div>
                    <div class="grid2">
                        <div class="field"><label>الموقع الإلكتروني</label><input name="website" type="url" value="{{ old('website', $company->website) }}" maxlength="255" placeholder="https://example.com" dir="ltr"></div>
                        <div class="field"><label>الدولة</label><input name="country" value="{{ old('country', $company->country ?? 'السعودية') }}" maxlength="100"></div>
                    </div>
                    <div class="grid3">
                        <div class="field" style="grid-column:span 2"><label>العنوان</label><input name="address" value="{{ old('address', $company->address) }}" maxlength="500"></div>
                        <div class="field"><label>المدينة</label><input name="city" value="{{ old('city', $company->city) }}" maxlength="100"></div>
                    </div>
                    <div class="field" style="max-width:240px"><label>الرمز البريدي</label><input name="postal_code" value="{{ old('postal_code', $company->postal_code) }}" maxlength="20" dir="ltr"></div>
                    <button class="btn">حفظ بيانات المؤسسة</button>
                </div>
            </div>
        </form>
    </div>
</div>
@empty
<div class="card"><div class="empty">لا توجد شركة تشغيلية بعد. أضف الشركة أولًا من صفحة الإدارة وCRM.</div></div>
@endforelse
@endsection
