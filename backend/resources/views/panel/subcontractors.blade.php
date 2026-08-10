@extends('layouts.panel')
@section('title', 'المقاولون من الباطن')

@section('content')
<h1>المقاولون من الباطن</h1>
<div class="sub">الهود والغاز والتمديدات تمر من هنا. بدون تسجيل تكلفة الشريك يصبح كل هامش في النظام خاطئاً بمقدارها.</div>

<div class="card">
    <div class="hd">إسناد أمر باطن</div>
    <div class="bd">
        <form method="POST" action="{{ route('panel.sub.assign') }}">
            @csrf
            <div class="grid2">
                <div class="field">
                    <label>الشريك</label>
                    <select name="subcontractor_id" required>
                        @foreach ($partners->where('is_active', true) as $partner)
                            <option value="{{ $partner->id }}">
                                {{ $partner->name }}@if ($partner->specialties) — {{ implode('، ', $partner->specialties) }}@endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>أمر العمل</label>
                    <select name="work_order_id" required>
                        @foreach ($openWorkOrders as $wo)
                            <option value="{{ $wo->id }}">{{ $wo->wo_number }} — {{ $wo->client?->name }} — {{ $wo->title }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="grid3">
                <div class="field">
                    <label>تكلفة الشريك (ما ندفعه)</label>
                    <input name="purchase_cost" id="cost" type="number" step="0.01" required oninput="calcMargin()">
                </div>
                <div class="field">
                    <label>سعر البيع (ما يدفعه العميل)</label>
                    <input name="sale_price" id="sale" type="number" step="0.01" required oninput="calcMargin()">
                </div>
                <div class="field">
                    <label>الموعد</label>
                    <input type="datetime-local" name="scheduled_for">
                </div>
            </div>

            <div id="marginBox" class="note" style="display:none;margin-bottom:14px"></div>

            <div class="field"><label>ملاحظة</label><input name="note"></div>
            <label style="display:inline-flex;gap:6px;align-items:center;color:var(--ink);margin-bottom:12px">
                <input type="checkbox" name="confirm_negative_margin" value="1" style="width:auto">
                أؤكد الإسناد بهامش سالب
            </label>
            <div><button class="btn">إسناد</button></div>
        </form>
        <div class="note" style="margin-top:14px">
            الهامش يُحسب ويُعرض <strong>قبل</strong> التأكيد. اكتشاف أن الشريك يتقاضى أكثر
            مما يدفعه العميل بعد التنفيذ هو بالضبط ما يمنعه هذا الحقل.
        </div>
    </div>
</div>

<div class="card">
    <div class="hd">أوامر الباطن</div>
    @if ($orders->isEmpty())
        <div class="empty">لا أوامر بعد.</div>
    @else
        <table>
            <thead>
            <tr>
                <th style="width:110px">الرقم</th><th>الشريك وأمر العمل</th>
                <th style="width:100px">التكلفة</th><th style="width:100px">البيع</th>
                <th style="width:110px">الهامش</th><th style="width:100px">الحالة</th>
                <th style="width:170px">المستندات</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($orders as $order)
                <tr>
                    <td style="font-size:13px">{{ $order->order_no }}</td>
                    <td>
                        <strong>{{ $order->subcontractor?->name }}</strong><br>
                        <span style="font-size:12px;color:var(--muted)">
                            {{ $order->workOrder?->wo_number }} — {{ $order->workOrder?->client?->name }}
                        </span>
                    </td>
                    <td>{{ number_format($order->purchase_cost, 2) }}</td>
                    <td>{{ number_format($order->sale_price, 2) }}</td>
                    <td>
                        @php $pct = $order->marginPercent(); @endphp
                        <span class="pill {{ $order->margin() < 0 ? 'red' : (($pct ?? 0) < 20 ? 'amber' : 'green') }}">
                            {{ number_format($order->margin(), 2) }}@if ($pct !== null) · {{ $pct }}%@endif
                        </span>
                    </td>
                    <td>
                        @php $states = ['assigned' => 'مُسند', 'in_progress' => 'جارٍ', 'delivered' => 'مُنفَّذ', 'cancelled' => 'ملغى']; @endphp
                        <span class="pill {{ $order->status === 'delivered' ? 'green' : ($order->status === 'cancelled' ? 'grey' : 'amber') }}">
                            {{ $states[$order->status] ?? $order->status }}
                        </span>
                    </td>
                    <td>
                        @foreach ($order->documents ?? [] as $i => $doc)
                            <div style="font-size:12px;margin-bottom:3px">
                                <a href="{{ route('panel.sub.doc', [$order, $i]) }}">{{ $doc['title'] }}</a>
                                <span style="color:var(--muted)">— صادر عن {{ $doc['issued_by'] }}</span>
                            </div>
                        @endforeach
                        <details>
                            <summary style="cursor:pointer;color:var(--teal);font-size:12px">إرفاق</summary>
                            <form method="POST" action="{{ route('panel.sub.doc.upload', $order) }}" enctype="multipart/form-data" style="margin-top:6px">
                                @csrf
                                <input type="file" name="file" required style="font-size:12px;margin-bottom:5px">
                                <input name="title" placeholder="اسم المستند" required style="font-size:12px;margin-bottom:5px">
                                <input name="issued_by" placeholder="الجهة المُصدِرة" required style="font-size:12px;margin-bottom:5px">
                                <button class="btn small">رفع</button>
                            </form>
                        </details>
                        @if ($order->status === 'assigned')
                            <form method="POST" action="{{ route('panel.sub.delivered', $order) }}" style="margin-top:6px">
                                @csrf
                                <button class="btn small ghost">وسم كمُنفَّذ</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
    <div class="bd">
        <div class="note">
            المستندات تُحفظ <strong>باسم الجهة المُصدِرة</strong>. دارك تنسّق الجهات المعتمدة
            ولا تعتمد؛ حفظ شهادة كأن دارك أصدرتها تضليل حقيقي لا تفصيل شكلي.
        </div>
    </div>
</div>

<div class="card">
    <div class="hd">الشركاء ({{ $partners->count() }})</div>
    <table>
        <thead><tr><th>الاسم</th><th>التخصصات</th><th style="width:130px">يصدر شهادات</th><th style="width:80px">أوامر</th><th style="width:90px">الحالة</th></tr></thead>
        <tbody>
        @foreach ($partners as $partner)
            <tr>
                <td>{{ $partner->name }}<br><span style="font-size:12px;color:var(--muted)">{{ $partner->phone }}</span></td>
                <td style="font-size:13px">{{ implode('، ', $partner->specialties ?? []) ?: '—' }}</td>
                <td>@if ($partner->issues_official_certificates)<span class="pill green">نعم</span>@else — @endif</td>
                <td>{{ $partner->orders_count }}</td>
                <td><span class="pill {{ $partner->is_active ? 'green' : 'grey' }}">{{ $partner->is_active ? 'نشط' : 'موقوف' }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="bd">
        <details>
            <summary style="cursor:pointer;color:var(--teal)">إضافة شريك</summary>
            <form method="POST" action="{{ route('panel.sub.partner') }}" style="margin-top:10px">
                @csrf
                <div class="grid3">
                    <div class="field"><label>الاسم</label><input name="name" required></div>
                    <div class="field"><label>السجل التجاري</label><input name="cr_number"></div>
                    <div class="field"><label>الجوال</label><input name="phone"></div>
                </div>
                <div class="field">
                    <label>التخصصات</label>
                    @foreach (['hood' => 'هود', 'gas' => 'غاز', 'electrical' => 'كهرباء', 'plumbing' => 'سباكة', 'hvac' => 'تكييف'] as $key => $label)
                        <label style="display:inline-flex;gap:5px;align-items:center;color:var(--ink);margin-inline-end:14px">
                            <input type="checkbox" name="specialties[]" value="{{ $key }}" style="width:auto">{{ $label }}
                        </label>
                    @endforeach
                </div>
                <label style="display:inline-flex;gap:6px;align-items:center;color:var(--ink);margin-bottom:12px">
                    <input type="checkbox" name="issues_official_certificates" value="1" style="width:auto">
                    يصدر شهادات رسمية باسمه
                </label>
                <div class="field"><label>ملاحظات</label><input name="notes"></div>
                <button class="btn small">إضافة</button>
            </form>
        </details>
    </div>
</div>

<script>
function calcMargin() {
    var cost = parseFloat(document.getElementById('cost').value);
    var sale = parseFloat(document.getElementById('sale').value);
    var box = document.getElementById('marginBox');

    if (isNaN(cost) || isNaN(sale)) { box.style.display = 'none'; return; }

    var margin = sale - cost;
    var pct = sale > 0 ? (margin / sale * 100).toFixed(1) : null;

    box.style.display = 'block';
    box.style.background = margin < 0 ? '#fee2e2' : (pct !== null && pct < 20 ? '#fffbeb' : '#dcfce7');
    box.style.borderColor = margin < 0 ? '#fca5a5' : '#fde68a';
    box.textContent = 'الهامش: ' + margin.toFixed(2) + ' ريال' + (pct !== null ? ' (' + pct + '%)' : '')
        + (margin < 0 ? ' — هذا الأمر يخسر.' : (pct !== null && pct < 20 ? ' — أقل من 20%، راجع السعر.' : ''));
}
</script>
@endsection
