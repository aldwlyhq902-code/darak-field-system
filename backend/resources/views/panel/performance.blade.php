@extends('layouts.panel')
@section('title', 'الأداء والترتيب')

@section('content')
<style>
    .perf-hero{background:linear-gradient(135deg,#064e3b,#0f766e 58%,#0d9488);color:#fff;border-radius:18px;padding:24px;margin-bottom:18px;overflow:hidden;position:relative}
    .perf-hero:after{content:"";position:absolute;width:240px;height:240px;border:42px solid rgba(255,255,255,.06);border-radius:50%;left:-80px;top:-110px}
    .perf-hero h1{font-size:28px}.perf-hero p{margin:3px 0 0;color:#ccfbf1;max-width:760px}.period-chip{display:inline-flex;margin-top:12px;padding:5px 12px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.18);border-radius:999px;font-size:12px}
    .filter-bar{display:grid;grid-template-columns:1.1fr 1fr 1fr 1fr auto;gap:10px;align-items:end}.filter-bar .custom-date{display:none}.filter-bar.is-custom .custom-date{display:block}
    .actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 20px}.actions .btn{white-space:nowrap}
    .summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}.summary{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;position:relative;overflow:hidden}.summary:before{content:"";position:absolute;inset-inline-start:0;top:0;bottom:0;width:4px;background:var(--teal)}.summary small{color:var(--muted)}.summary strong{display:block;font-size:25px}.summary .leader{font-size:12px;color:#115e59;margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .category-tabs{display:flex;gap:8px;overflow:auto;padding-bottom:7px;margin-bottom:12px}.category-tab{border:1px solid var(--line);background:#fff;color:#475569;border-radius:999px;padding:8px 15px;cursor:pointer;font:inherit;white-space:nowrap}.category-tab.active{background:var(--teal);border-color:var(--teal);color:#fff}
    .ranking-panel{display:none}.ranking-panel.active{display:block}.rank-list{display:grid;gap:12px}.rank-card{background:#fff;border:1px solid var(--line);border-radius:15px;overflow:hidden;transition:.2s ease}.rank-card:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(15,118,110,.09)}
    .rank-main{display:grid;grid-template-columns:70px minmax(160px,1.2fr) minmax(190px,1.7fr) 105px 120px;align-items:center;gap:14px;padding:15px 17px}.place{width:45px;height:45px;display:grid;place-items:center;border-radius:50%;background:#f1f5f9;font-size:18px;font-weight:800;color:#475569}.rank-card:nth-child(1) .place{background:#fef3c7;color:#92400e}.rank-card:nth-child(2) .place{background:#e2e8f0;color:#334155}.rank-card:nth-child(3) .place{background:#ffedd5;color:#9a3412}
    .person strong{display:block;font-size:16px}.person small{color:var(--muted)}.scorebar{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin-top:6px}.scorebar i{display:block;height:100%;width:var(--score);background:linear-gradient(90deg,#14b8a6,#0f766e);border-radius:99px;animation:grow .7s ease-out both}.score-value{font-size:24px;font-weight:800;color:#0f766e}.delta{font-size:12px}.delta.up{color:#15803d}.delta.down{color:#b91c1c}.sample{font-size:11px;color:var(--muted)}
    details.metrics{border-top:1px solid var(--line)}details.metrics summary{cursor:pointer;padding:9px 17px;color:#0f766e;font-size:13px;list-style:none}details.metrics summary::-webkit-details-marker{display:none}.metric-grid{padding:4px 17px 17px;display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.metric{border:1px solid #e2e8f0;border-radius:10px;padding:10px;background:#f8fafc}.metric-head{display:flex;justify-content:space-between;gap:8px;font-size:12px}.metric strong{color:#0f766e}.metric .scorebar{height:5px}.metric small{color:var(--muted);font-size:10px}.insights{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:8px}.insight{border-radius:10px;padding:10px;font-size:12px}.insight.good{background:#ecfdf5;color:#065f46}.insight.focus{background:#fff7ed;color:#9a3412}
    .settings-table{overflow:auto}.settings-table input{min-width:90px}.total-weight{font-weight:700;color:#0f766e}
    @keyframes grow{from{width:0}}@media(prefers-reduced-motion:reduce){.scorebar i{animation:none}.rank-card{transition:none}}
    @media(max-width:900px){.summary-grid{grid-template-columns:1fr 1fr}.rank-main{grid-template-columns:54px 1fr 80px}.rank-main .progress-cell{grid-column:2/-1;grid-row:2}.rank-main .grade-cell{display:none}.metric-grid{grid-template-columns:1fr 1fr}.filter-bar{grid-template-columns:1fr 1fr}}
    @media(max-width:560px){.perf-hero{padding:19px}.perf-hero h1{font-size:23px}.summary-grid{grid-template-columns:1fr 1fr}.summary{padding:12px}.summary strong{font-size:21px}.filter-bar{grid-template-columns:1fr}.rank-main{grid-template-columns:44px 1fr 68px;padding:13px 11px;gap:8px}.place{width:38px;height:38px}.score-value{font-size:20px}.metric-grid,.insights{grid-template-columns:1fr}.actions .btn{flex:1;text-align:center}}
</style>

<section class="perf-hero">
    <h1>مركز الأداء والترتيب</h1>
    <p>قياس عادل يربط جودة الخدمة والالتزام والربحية والتحصيل، ويقارن كل نتيجة بالفترة السابقة بدل الاعتماد على عدد المهام وحده.</p>
    <span class="period-chip">{{ $dashboard['from']->format('Y/m/d') }} — {{ $dashboard['to']->format('Y/m/d') }}</span>
</section>

<div class="card"><div class="bd">
    <form method="GET" id="performance-filter" class="filter-bar {{ $period === 'custom' ? 'is-custom' : '' }}">
        <div><label>دورية القياس</label><select name="period" id="period-select"><option value="week" @selected($period==='week')>أسبوعي</option><option value="month" @selected($period==='month')>شهري</option><option value="quarter" @selected($period==='quarter')>ربع سنوي</option><option value="custom" @selected($period==='custom')>فترة مخصصة</option></select></div>
        <div><label>التاريخ المرجعي</label><input type="date" name="reference" value="{{ $reference }}"></div>
        <div class="custom-date"><label>من</label><input type="date" name="from" value="{{ request('from',$dashboard['from']->toDateString()) }}"></div>
        <div class="custom-date"><label>إلى</label><input type="date" name="to" value="{{ request('to',$dashboard['to']->toDateString()) }}"></div>
        <button class="btn">تحديث القياس</button>
    </form>
</div></div>

<div class="actions">
    <a class="btn ghost" href="{{ route('panel.performance.csv', request()->query()) }}">تصدير Excel / CSV</a>
    <a class="btn ghost" target="_blank" href="{{ route('panel.performance.pdf', request()->query()) }}">تقرير PDF للإدارة</a>
</div>

<div class="summary-grid">
@foreach($dashboard['summaries'] as $summary)
    <div class="summary"><small>{{ $summary['label'] }}</small><strong>{{ number_format($summary['average'],1) }}<small>/100</small></strong><div class="leader">الأول: {{ $summary['leader'] }} · {{ number_format($summary['leader_score'],1) }}</div></div>
@endforeach
</div>

<div class="category-tabs" role="tablist">
@foreach($categoryLabels as $key => $label)
    <button id="tab-{{ $key }}" type="button" role="tab" aria-controls="panel-{{ $key }}" aria-selected="{{ $selectedCategory===$key ? 'true':'false' }}" class="category-tab {{ $selectedCategory===$key ? 'active':'' }}" data-category="{{ $key }}">{{ $label }} <small>({{ count($dashboard['categories'][$key]) }})</small></button>
@endforeach
</div>

@foreach($categoryLabels as $key => $label)
<section id="panel-{{ $key }}" role="tabpanel" aria-labelledby="tab-{{ $key }}" class="ranking-panel {{ $selectedCategory===$key ? 'active':'' }}" data-panel="{{ $key }}">
    @if(count($dashboard['categories'][$key]))
    <div class="rank-list">
    @foreach($dashboard['categories'][$key] as $row)
        <article class="rank-card">
            <div class="rank-main">
                <div class="place">#{{ $row['rank'] }}</div>
                <div class="person"><strong>{{ $row['name'] }}</strong><small>{{ $row['context'] }}</small></div>
                <div class="progress-cell"><span class="sample">{{ $row['sufficient'] ? 'عينة موثوقة' : 'بيانات أولية — لم تكتمل العينة' }}</span><div class="scorebar"><i style="--score:{{ $row['score'] }}%"></i></div></div>
                <div class="grade-cell"><span class="pill {{ $row['score']>=80?'green':($row['score']>=60?'amber':'red') }}">{{ $row['grade'] }}</span></div>
                <div><span class="score-value">{{ number_format($row['score'],1) }}</span><div class="delta {{ $row['score_delta']>0?'up':($row['score_delta']<0?'down':'') }}">{{ $row['score_delta']>0?'▲':($row['score_delta']<0?'▼':'—') }} {{ number_format(abs($row['score_delta']),1) }} عن السابق</div></div>
            </div>
            <details class="metrics"><summary>عرض تفاصيل احتساب الدرجة ←</summary><div class="metric-grid">
                @foreach($row['metrics'] as $metric)
                <div class="metric"><div class="metric-head"><span>{{ $metric['label'] }}</span><strong>{{ $metric['display'] }}</strong></div><div class="scorebar"><i style="--score:{{ $metric['score'] }}%"></i></div><small>درجة {{ $metric['score'] }} · وزن {{ $metric['weight'] }}% · عينة {{ $metric['samples'] }}/{{ $metric['minimum_sample'] }}</small></div>
                @endforeach
                <div class="insights"><div class="insight good"><strong>نقاط قوة:</strong> {{ implode('، ', $row['strengths']) }}</div><div class="insight focus"><strong>أولوية التطوير:</strong> {{ implode('، ', $row['improvements']) }}</div></div>
            </div></details>
        </article>
    @endforeach
    </div>
    @else <div class="card"><div class="empty">لا توجد بيانات مسجلة لهذه الفئة خلال النطاق الحالي.</div></div>
    @endif
</section>
@endforeach

<div class="note" style="margin-top:20px"><strong>كيف نحمي عدالة التقييم؟</strong> النتيجة موزونة حسب الجودة لا الحجم فقط، وتُخفّف آليًا نحو مستوى محايد عند قلة العينة. الترتيب يقدّم أصحاب العينات المكتملة أولًا، ويعرض تفاصيل كل مؤشر وهدفه وحجم بياناته.</div>

@if(auth('web')->user()->isOwner())
<details class="card" style="margin-top:18px"><summary class="hd" style="cursor:pointer">إعداد أوزان المؤشرات والأهداف</summary><div class="bd">
    <p class="sub">يمكن تعديل كل فئة بصورة مستقلة. يجب أن يساوي مجموع الأوزان 100%، وكل تغيير يُسجل في سجل التدقيق.</p>
    @foreach($settings as $category => $categorySettings)
    <form method="POST" action="{{ route('panel.performance.settings') }}" style="margin-bottom:24px">@csrf @method('PUT')<input type="hidden" name="category" value="{{ $category }}">
        <h3>{{ $categoryLabels[$category] }}</h3><div class="settings-table"><table><thead><tr><th>المؤشر</th><th>الوزن %</th><th>الهدف</th><th>الحد الأدنى للعينة</th></tr></thead><tbody>
        @foreach($categorySettings as $setting)<tr><td>{{ $setting->label_ar }}</td><td><input type="number" step="0.01" min="0" max="100" name="settings[{{ $setting->id }}][weight]" value="{{ $setting->weight }}"></td><td><input type="number" step="0.01" min="0" name="settings[{{ $setting->id }}][target]" value="{{ $setting->target }}"></td><td><input type="number" min="1" name="settings[{{ $setting->id }}][minimum_sample]" value="{{ $setting->minimum_sample }}"></td></tr>@endforeach
        </tbody></table></div><button class="btn small">حفظ إعدادات {{ $categoryLabels[$category] }}</button>
    </form>
    @endforeach
</div></details>
@endif

<script nonce="{{ $cspNonce }}">
    const filter = document.getElementById('performance-filter');
    document.getElementById('period-select').addEventListener('change', event => filter.classList.toggle('is-custom', event.target.value === 'custom'));
    document.querySelectorAll('.category-tab').forEach(tab => tab.addEventListener('click', () => {
        document.querySelectorAll('.category-tab,.ranking-panel').forEach(el => el.classList.remove('active'));
        tab.classList.add('active'); document.querySelector(`[data-panel="${tab.dataset.category}"]`).classList.add('active');
        document.querySelectorAll('.category-tab').forEach(item => item.setAttribute('aria-selected', String(item === tab)));
        const url = new URL(window.location); url.searchParams.set('category', tab.dataset.category); history.replaceState({}, '', url);
    }));
</script>
@endsection
