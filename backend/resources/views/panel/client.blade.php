@extends('layouts.panel')
@section('title', $client->name)

@section('content')
<h1>{{ $client->name }}</h1>
<div class="sub">
    <a href="{{ route('panel.clients') }}">العملاء</a> ·
    {{ $client->sites->count() }} موقع · {{ $client->contracts->count() }} عقد
    @if ($client->requiresAdvancePayment())
        · <span class="pill amber">دفع ربع سنوي مقدماً (منشأة &lt; سنة)</span>
    @endif
</div>

<div class="card">
    <div class="hd">المواقع والأصول</div>
    @forelse ($client->sites as $site)
        <div class="bd" style="border-bottom:1px solid var(--line)">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <strong>{{ $site->name }}</strong>
                <span style="color:var(--muted);font-size:13px">{{ $site->address }}</span>
                <span class="pill grey">{{ $site->qr_code }}</span>
            </div>

            @if ($site->assets->isNotEmpty())
                <table style="margin-top:10px">
                    <thead><tr><th>الأصل</th><th style="width:110px">النوع</th><th style="width:130px">الملصق</th><th style="width:120px">الضمان</th></tr></thead>
                    <tbody>
                    @foreach ($site->assets as $asset)
                        <tr>
                            <td>{{ $asset->name }}</td>
                            <td style="font-size:13px">{{ $asset->type }}</td>
                            <td style="font-size:12px">{{ $asset->qr_code }}</td>
                            <td>
                                @if ($asset->isUnderWarranty())
                                    <span class="pill amber">حتى {{ $asset->warranty_until->format('Y-m-d') }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            <details style="margin-top:10px">
                <summary style="cursor:pointer;color:var(--teal);font-size:14px">إضافة أصل لهذا الموقع</summary>
                <form method="POST" action="{{ route('panel.site.asset', $site) }}" style="margin-top:10px">
                    @csrf
                    <div class="grid3">
                        <div class="field"><label>الاسم</label><input name="name" required></div>
                        <div class="field">
                            <label>النوع</label>
                            <select name="type">
                                <option value="split_ac">سبليت</option>
                                <option value="package_ac">مركزي</option>
                                <option value="chiller">ثلاجة</option>
                                <option value="freezer">فريزر</option>
                                <option value="hood">هود</option>
                                <option value="electrical">كهرباء</option>
                                <option value="plumbing">سباكة</option>
                            </select>
                        </div>
                        <div class="field"><label>الموقع داخل الفرع</label><input name="location_in_site"></div>
                    </div>
                    <div class="grid3">
                        <div class="field"><label>الشركة</label><input name="manufacturer"></div>
                        <div class="field"><label>الرقم التسلسلي</label><input name="serial_number"></div>
                        <div class="field"><label>الضمان حتى</label><input type="date" name="warranty_until"></div>
                    </div>
                    <button class="btn small">إضافة الأصل</button>
                </form>
            </details>
        </div>
    @empty
        <div class="empty">لا مواقع بعد.</div>
    @endforelse

    <div class="bd">
        <details>
            <summary style="cursor:pointer;color:var(--teal)">إضافة موقع</summary>
            <form method="POST" action="{{ route('panel.client.site', $client) }}" style="margin-top:10px">
                @csrf
                <div class="grid2">
                    <div class="field"><label>اسم الموقع</label><input name="name" required></div>
                    <div class="field"><label>العنوان</label><input name="address"></div>
                </div>
                <div class="grid3">
                    <div class="field"><label>خط العرض</label><input name="lat" type="number" step="any"></div>
                    <div class="field"><label>خط الطول</label><input name="lng" type="number" step="any"></div>
                    <div class="field"><label>تعليمات الدخول</label><input name="access_notes"></div>
                </div>
                <button class="btn small">إضافة</button>
            </form>
        </details>
    </div>
</div>

<div class="grid2">
    <div class="card">
        <div class="hd">العقود</div>
        @if ($client->contracts->isEmpty())
            <div class="empty">لا عقود بعد.</div>
        @else
            <table>
                <thead><tr><th>الرقم</th><th>الباقة</th><th>القيمة</th><th>SLA</th><th>الحالة</th></tr></thead>
                <tbody>
                @foreach ($client->contracts as $contract)
                    <tr>
                        <td>{{ $contract->contract_no }}</td>
                        <td>{{ $contract->package_code === 'basic' ? 'أساسي' : 'شامل' }}</td>
                        <td>
                            {{ number_format($contract->price_amount, 0) }}
                            <span style="font-size:11px;color:var(--muted)">قبل الضريبة</span><br>
                            <span style="font-size:12px;color:var(--muted)">{{ number_format($contract->priceInclVat(), 2) }} شاملة</span>
                        </td>
                        <td>{{ $contract->sla_minutes }} د<br>
                            <span style="font-size:11px;color:var(--muted)">
                                {{ substr($contract->service_window_start, 0, 5) }}–{{ substr($contract->service_window_end, 0, 5) }}
                            </span>
                        </td>
                        <td>
                            <span class="pill {{ $contract->isActive() ? 'green' : 'grey' }}">{{ $contract->status }}</span>
                            @if ($contract->is_trial)
                                <div style="font-size:11px;color:var(--amber)">تجريبي — حسم {{ $contract->decision_due_on?->format('Y-m-d') }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
        <div class="bd">
            <details>
                <summary style="cursor:pointer;color:var(--teal)">عقد جديد</summary>
                <form method="POST" action="{{ route('panel.client.contract', $client) }}" style="margin-top:10px">
                    @csrf
                    <div class="grid2">
                        <div class="field">
                            <label>الباقة</label>
                            <select name="package_code" id="pkg">
                                <option value="basic">أساسي</option>
                                <option value="comprehensive">شامل</option>
                            </select>
                        </div>
                        <div class="field"><label>القيمة الشهرية (قبل الضريبة)</label><input name="price_amount" type="number" step="0.01" value="1200" required></div>
                    </div>
                    <div class="grid3">
                        <div class="field"><label>البداية</label><input type="date" name="starts_on" value="{{ now()->toDateString() }}" required></div>
                        <div class="field"><label>النهاية</label><input type="date" name="ends_on"></div>
                        <div class="field"><label>SLA بالدقائق</label><input name="sla_minutes" type="number" value="240" required></div>
                    </div>
                    <div class="grid3">
                        <div class="field"><label>بداية نافذة الخدمة</label><input name="service_window_start" value="07:00" required></div>
                        <div class="field"><label>نهايتها</label><input name="service_window_end" value="23:00" required></div>
                        <div class="field" style="display:flex;align-items:end;gap:8px">
                            <input type="checkbox" name="is_trial" value="1" style="width:auto" checked>
                            <label style="margin:0">تجريبي 90 يوماً</label>
                        </div>
                    </div>
                    <div class="field">
                        <label>المواقع المشمولة</label>
                        @foreach ($client->sites as $site)
                            <div><label style="display:inline-flex;gap:6px;align-items:center;color:var(--ink)">
                                <input type="checkbox" name="site_ids[]" value="{{ $site->id }}" style="width:auto" checked>
                                {{ $site->name }}
                            </label></div>
                        @endforeach
                    </div>
                    <button class="btn small">إنشاء العقد</button>
                </form>
                <div class="note" style="margin-top:12px">
                    القيمة تُخزَّن قبل الضريبة دائماً، والشامل يُحسب. عداد الـSLA يعمل داخل
                    نافذة الخدمة فقط ويتوقف خارجها.
                </div>
            </details>
        </div>
    </div>

    <div class="card">
        <div class="hd">زيارة جديدة</div>
        <div class="bd">
            <form method="POST" action="{{ route('panel.client.visit', $client) }}">
                @csrf
                <div class="field">
                    <label>الموقع</label>
                    <select name="site_id" required>
                        @foreach ($client->sites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>العقد (اختياري)</label>
                    <select name="contract_id">
                        <option value="">بلا عقد — عمل خارج العقد</option>
                        @foreach ($client->contracts as $contract)
                            <option value="{{ $contract->id }}">{{ $contract->contract_no }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid2">
                    <div class="field">
                        <label>النوع</label>
                        <select name="type">
                            <option value="preventive">صيانة وقائية</option>
                            <option value="reactive">بلاغ</option>
                            <option value="out_of_contract">عمل خارج العقد</option>
                        </select>
                    </div>
                    <div class="field"><label>الموعد</label><input type="datetime-local" name="scheduled_start" required></div>
                </div>
                <div class="field"><label>العنوان</label><input name="title" required></div>
                <div class="field"><label>الوصف</label><textarea name="description" rows="2"></textarea></div>
                <button class="btn">إنشاء أمر العمل والزيارة</button>
            </form>
        </div>
    </div>
</div>
@endsection
