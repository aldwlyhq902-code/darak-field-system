@extends('layouts.client')
@section('title', 'عرض السعر '.$quotation->quote_no)
@section('content')
<div class="form-card" style="max-width:820px">
    <span class="pill {{ $quotation->status === 'accepted' || $quotation->status === 'converted' ? 'green' : 'amber' }}">{{ ['sent'=>'بانتظار اعتمادك','accepted'=>'معتمد','converted'=>'تحول إلى عقد'][$quotation->status] ?? $quotation->status }}</span>
    <h1>{{ $quotation->title }}</h1><p class="muted">{{ $quotation->quote_no }} · الإصدار {{ $quotation->version }} · صالح حتى {{ $quotation->valid_until->format('Y-m-d') }}</p>
    <div class="grid"><div class="card"><span class="muted">السعر للدورة قبل الضريبة</span><b>{{ number_format($quotation->price_amount,2) }} ر.س</b></div><div class="card"><span class="muted">شامل الضريبة</span><b>{{ number_format($quotation->priceInclVat(),2) }} ر.س</b></div><div class="card"><span class="muted">المدة</span><b>{{ $quotation->duration_months }} شهر</b></div></div>
    <h2>المواقع المشمولة</h2><div class="list">@foreach($quotation->sites as $site)<div class="row"><strong>{{ $site->name }}</strong><span class="muted">{{ $site->address }}</span></div>@endforeach</div>
    <h2>الشروط</h2><div class="card">@forelse($quotation->terms ?? [] as $term)<div>• {{ $term }}</div>@empty<span class="muted">لا توجد شروط إضافية.</span>@endforelse</div>
    @if($quotation->status === 'sent')<form method="POST" action="{{ route('client.quotation.accept',$quotation) }}">@csrf<label style="display:flex;gap:8px;align-items:start"><input type="checkbox" name="accept_terms" value="1" style="width:auto;margin-top:6px" required><span>قرأت تفاصيل هذا الإصدار وأوافق على السعر والمواقع والشروط الموضحة.</span></label><button class="btn" style="margin-top:16px">اعتماد عرض السعر</button></form>@endif
    @if($quotation->convertedContract)<div class="flash ok" style="margin-top:18px">تحول إلى العقد {{ $quotation->convertedContract->contract_no }}، وعدد دفعاته {{ $quotation->convertedContract->installments->count() }}.</div>@endif
</div>
@endsection
