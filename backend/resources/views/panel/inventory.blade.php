@extends('layouts.panel')
@section('title', 'المخزون')

@section('content')
<h1>المخزون</h1>
<div class="sub">خمس حركات فقط في هذه المرحلة: استلام، تزويد سيارة، صرف لزيارة، إرجاع، تسوية جرد.</div>

@foreach ($locations as $entry)
    <div class="card">
        <div class="hd">
            {{ $entry['location']->name }}
            <span class="pill grey">{{ $entry['location']->type === 'warehouse' ? 'مستودع' : 'سيارة' }}</span>
        </div>
        @if (empty($entry['balances']))
            <div class="empty">لا رصيد في هذا الموقع.</div>
        @else
            <table>
                <thead><tr><th style="width:130px">الرمز</th><th>الصنف</th><th style="width:100px">الرصيد</th><th style="width:120px">حد الطلب</th></tr></thead>
                <tbody>
                @foreach ($entry['balances'] as $balance)
                    <tr>
                        <td style="font-size:13px">{{ $balance['sku'] }}</td>
                        <td>{{ $balance['name'] }}</td>
                        <td>
                            <span class="pill {{ $balance['below_reorder'] ? 'red' : 'green' }}">
                                {{ rtrim(rtrim(number_format($balance['qty'], 3), '0'), '.') }}
                            </span>
                        </td>
                        <td style="font-size:13px;color:var(--muted)">{{ rtrim(rtrim(number_format($balance['reorder_level'], 2), '0'), '.') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endforeach

<div class="grid2">
    <div class="card">
        <div class="hd">استلام من مورد</div>
        <div class="bd">
            <form method="POST" action="{{ route('panel.inventory.receipt') }}">
                @csrf
                <div class="field">
                    <label>الصنف</label>
                    <select name="part_id" required>
                        @foreach ($parts as $part)
                            <option value="{{ $part->id }}">{{ $part->name }} ({{ $part->sku }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid2">
                    <div class="field"><label>الكمية</label><input name="qty" type="number" step="0.001" required></div>
                    <div class="field">
                        <label>إلى</label>
                        <select name="to_location_id" required>
                            @foreach ($locations as $entry)
                                <option value="{{ $entry['location']->id }}">{{ $entry['location']->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field"><label>ملاحظة</label><input name="note"></div>
                <button class="btn">تسجيل الاستلام</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="hd">تزويد سيارة</div>
        <div class="bd">
            <form method="POST" action="{{ route('panel.inventory.transfer') }}">
                @csrf
                <div class="field">
                    <label>الصنف</label>
                    <select name="part_id" required>
                        @foreach ($parts as $part)
                            <option value="{{ $part->id }}">{{ $part->name }} ({{ $part->sku }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid2">
                    <div class="field">
                        <label>من</label>
                        <select name="from_location_id" required>
                            @foreach ($locations as $entry)
                                <option value="{{ $entry['location']->id }}" @selected($entry['location']->type === 'warehouse')>{{ $entry['location']->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label>إلى</label>
                        <select name="to_location_id" required>
                            @foreach ($locations as $entry)
                                <option value="{{ $entry['location']->id }}" @selected($entry['location']->type === 'vehicle')>{{ $entry['location']->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field"><label>الكمية</label><input name="qty" type="number" step="0.001" required></div>
                <button class="btn">تزويد</button>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="hd">تسوية جرد</div>
    <div class="bd">
        <form method="POST" action="{{ route('panel.inventory.adjust') }}">
            @csrf
            <div class="grid3">
                <div class="field">
                    <label>الصنف</label>
                    <select name="part_id" required>
                        @foreach ($parts as $part)
                            <option value="{{ $part->id }}">{{ $part->name }} ({{ $part->sku }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>الموقع</label>
                    <select name="location_id" required>
                        @foreach ($locations as $entry)
                            <option value="{{ $entry['location']->id }}">{{ $entry['location']->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label>الكمية المعدودة فعلياً</label><input name="counted_qty" type="number" step="0.001" required></div>
            </div>
            <div class="field"><label>السبب (إلزامي)</label><input name="reason" required placeholder="جرد شهري، تلف، فقد..."></div>
            <button class="btn ghost">تسجيل التسوية</button>
        </form>
        <div class="note" style="margin-top:12px">
            التسوية هي الحركة الوحيدة المسموح لها بتخفيض الرصيد إلى الواقع، ولهذا تطلب سبباً
            وتُسجَّل كاملة في سجل التدقيق. الفرق يُقيَّد كحركة مستقلة — لا يُعدَّل التاريخ.
        </div>
    </div>
</div>

<div class="card">
    <div class="hd">كتالوج القطع ({{ $parts->count() }})</div>
    <table>
        <thead>
        <tr><th style="width:130px">الرمز</th><th>الصنف</th><th style="width:110px">تكلفة الشراء</th><th style="width:110px">سعر البيع</th><th style="width:110px">الهامش</th><th style="width:120px">حساس للحرارة</th></tr>
        </thead>
        <tbody>
        @foreach ($parts as $part)
            @php
                $margin = (float) $part->sale_price > 0
                    ? round((((float) $part->sale_price - (float) $part->purchase_cost) / (float) $part->sale_price) * 100, 1)
                    : null;
            @endphp
            <tr>
                <td style="font-size:13px">{{ $part->sku }}</td>
                <td>{{ $part->name }}</td>
                <td>{{ number_format($part->purchase_cost, 2) }}</td>
                <td>{{ number_format($part->sale_price, 2) }}</td>
                <td>
                    @if ($margin !== null)
                        <span class="pill {{ $margin >= 40 ? 'green' : ($margin >= 20 ? 'amber' : 'red') }}">{{ $margin }}%</span>
                    @endif
                </td>
                <td style="font-size:13px">
                    @if ($part->heat_sensitive)
                        <span class="pill amber">
                            {{ $part->max_storage_temp_c ? 'حتى ' . rtrim(rtrim(number_format($part->max_storage_temp_c, 1), '0'), '.') . '°م' : 'نعم' }}
                        </span>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="bd">
        <details>
            <summary style="cursor:pointer;color:var(--teal)">إضافة صنف</summary>
            <form method="POST" action="{{ route('panel.inventory.part') }}" style="margin-top:10px">
                @csrf
                <div class="grid3">
                    <div class="field"><label>الرمز SKU</label><input name="sku" required></div>
                    <div class="field"><label>الاسم</label><input name="name" required></div>
                    <div class="field"><label>الوحدة</label><input name="unit" value="pcs" required></div>
                </div>
                <div class="grid3">
                    <div class="field"><label>تكلفة الشراء</label><input name="purchase_cost" type="number" step="0.01" required></div>
                    <div class="field"><label>سعر البيع</label><input name="sale_price" type="number" step="0.01" required></div>
                    <div class="field"><label>سعر العضو</label><input name="member_price" type="number" step="0.01"></div>
                </div>
                <div class="grid3">
                    <div class="field"><label>حد إعادة الطلب</label><input name="reorder_level" type="number" step="0.01" value="2" required></div>
                    <div class="field" style="display:flex;align-items:end;gap:8px">
                        <input type="checkbox" name="heat_sensitive" value="1" style="width:auto">
                        <label style="margin:0">حساس للحرارة</label>
                    </div>
                    <div class="field"><label>أقصى حرارة تخزين (°م)</label><input name="max_storage_temp_c" type="number" step="0.1"></div>
                </div>
                <button class="btn small">إضافة</button>
            </form>
            <div class="note" style="margin-top:12px">
                تكلفة الشراء إلزامية — بدونها لا يُحسب هامش على أي قطعة.
                وحد الحرارة لكل صنف من ورقة المصنع، لا عتبة موحدة: بعض المكثفات مصنفة حتى 105°م.
            </div>
        </details>
    </div>
</div>
@endsection
