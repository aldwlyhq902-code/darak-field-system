@extends('layouts.panel')
@section('title', 'العملاء والعقود')

@section('content')
<h1>العملاء والعقود</h1>
<div class="sub">كل عميل يمكن أن يملك أكثر من موقع — سلاسل المطاعم هدف بيعي، والنموذج يدعمها من اليوم الأول.</div>

<div class="grid2">
    <div class="card">
        <div class="hd">العملاء ({{ $clients->count() }})</div>
        @if ($clients->isEmpty())
            <div class="empty">لا يوجد عملاء بعد. أضف أولهم من النموذج المجاور.</div>
        @else
            <table>
                <thead><tr><th>الاسم</th><th style="width:90px">التصنيف</th><th style="width:70px">مواقع</th><th style="width:70px">عقود</th><th style="width:60px"></th></tr></thead>
                <tbody>
                @foreach ($clients as $client)
                    <tr>
                        <td>
                            {{ $client->name }}
                            @if ($client->requiresAdvancePayment())
                                <span class="pill amber" title="منشأة عمرها أقل من سنة">مقدم</span>
                            @endif
                        </td>
                        <td style="font-size:13px">
                            {{ ['restaurant' => 'مطعم', 'cafe' => 'مقهى', 'central_kitchen' => 'مطبخ مركزي', 'chain' => 'سلسلة'][$client->category] ?? '—' }}
                        </td>
                        <td>{{ $client->sites_count }}</td>
                        <td>{{ $client->contracts_count }}</td>
                        <td><a class="btn small ghost" href="{{ route('panel.client', $client) }}">فتح</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <div class="hd">عميل جديد</div>
        <div class="bd">
            <form method="POST" action="{{ route('panel.clients.store') }}">
                @csrf
                <div class="field"><label>اسم المنشأة</label><input name="name" value="{{ old('name') }}" required></div>
                <div class="grid2">
                    <div class="field">
                        <label>التصنيف</label>
                        <select name="category">
                            <option value="restaurant">مطعم</option>
                            <option value="cafe">مقهى</option>
                            <option value="central_kitchen">مطبخ مركزي</option>
                            <option value="chain">سلسلة فروع</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>تاريخ التأسيس</label>
                        <input type="date" name="established_on" value="{{ old('established_on') }}">
                    </div>
                </div>
                <div class="grid2">
                    <div class="field"><label>السجل التجاري</label><input name="cr_number"></div>
                    <div class="field"><label>الرقم الضريبي</label><input name="vat_number"></div>
                </div>
                <div class="field"><label>اسم الموقع الأول</label><input name="site_name" required></div>
                <div class="field"><label>عنوان الموقع</label><input name="site_address"></div>
                <button class="btn">إضافة</button>
            </form>
            <div class="note" style="margin-top:14px">
                تاريخ التأسيس ليس حقلاً شكلياً: منشأة عمرها أقل من سنة يفرض عليها النظام
                الدفع الربع سنوي مقدماً كسياسة ائتمان.
            </div>
        </div>
    </div>
</div>
@endsection
