<!doctype html><html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><head><meta charset="utf-8"><style>
body{font-family:xbriyaz,sans-serif;color:#172033;font-size:11px}h1{color:#0f766e;margin-bottom:2px}.sub{color:#64748b;margin-bottom:16px}.section{page-break-inside:avoid;margin-bottom:18px}h2{background:#0f766e;color:white;padding:7px 10px;font-size:15px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe4e8;padding:6px;text-align:right}th{background:#ecfdf5;color:#115e59}.score{font-weight:bold;color:#0f766e}.muted{color:#64748b;font-size:9px}.footer{margin-top:18px;border-top:1px solid #dbe4e8;padding-top:7px;color:#64748b}
</style></head><body>
<h1>تقرير الأداء والترتيب</h1><div class="sub">الفترة: {{ $dashboard['from']->format('Y/m/d') }} — {{ $dashboard['to']->format('Y/m/d') }} · مقارنة آلية بالفترة السابقة</div>
@foreach($dashboard['categories'] as $category => $rows)
<div class="section"><h2>{{ \App\Services\PerformanceScoreService::CATEGORY_LABELS[$category] }}</h2>
<table><thead><tr><th>#</th><th>الاسم</th><th>النطاق</th><th>الدرجة</th><th>التقدير</th><th>التغير</th><th>نقاط القوة</th><th>أولوية التطوير</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $row['rank'] }}</td><td>{{ $row['name'] }}<div class="muted">{{ $row['sufficient']?'عينة مكتملة':'بيانات أولية' }}</div></td><td>{{ $row['context'] }}</td><td class="score">{{ number_format($row['score'],1) }}/100</td><td>{{ $row['grade'] }}</td><td>{{ $row['score_delta']>0?'+':'' }}{{ number_format($row['score_delta'],1) }}</td><td>{{ implode('، ',$row['strengths']) }}</td><td>{{ implode('، ',$row['improvements']) }}</td></tr>@empty<tr><td colspan="8">لا توجد بيانات في هذه الفترة.</td></tr>@endforelse
</tbody></table></div>
@endforeach
<div class="footer">يعتمد التقييم على مؤشرات موزونة، ويأخذ حجم العينة في الحسبان. النتائج أداة إدارية للتطوير واتخاذ القرار وليست بديلًا عن مراجعة الظروف التشغيلية.</div>
@include('partials.runtime-localization')</body></html>
