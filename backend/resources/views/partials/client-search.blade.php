@if($clientSearchRequired || $clientSearch !== '')
<form method="GET" action="{{ $action }}" role="search" class="field">@foreach(request()->except(['client_q']) as $key => $value)@if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
    <label for="{{ $id ?? 'client-q' }}">ابحث عن العميل بالاسم</label>
    <input id="{{ $id ?? 'client-q' }}" name="client_q" value="{{ $clientSearch }}" placeholder="اسم العميل" required>
    @if($clientResultsCapped)<div class="note" style="margin-top:6px">النتائج كثيرة؛ تظهر أول {{ \App\Support\ClientPicker::INLINE_LIMIT }} فقط — ضيّق البحث.</div>@elseif($clientSearch === '')<div class="note" style="margin-top:6px">عدد العملاء كبير؛ ابحث بالاسم لإظهارهم في القائمة.</div>@elseif($clients->isEmpty())<div class="note" style="margin-top:6px">لا يوجد عميل نشط مطابق.</div>@endif
</form>
@endif
