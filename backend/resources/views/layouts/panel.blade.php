<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'لوحة '.($panelCompany?->name ?? 'دارك'))</title>
    <style>
        :root {
            --teal: #0f766e; --teal-50: #f0fdfa; --ink: #1a1a1a; --muted: #6b7280;
            --line: #e5e7eb; --bg: #f8fafc; --red: #b91c1c; --amber: #b45309; --green: #15803d;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font-family: "Segoe UI", Tahoma, system-ui, sans-serif; font-size: 15px; line-height: 1.6;
        }
        a { color: var(--teal); text-decoration: none; }
        header {
            background: var(--teal); color: #fff; padding: 0 20px;
            display: flex; align-items: center; gap: 22px; flex-wrap: wrap;
        }
        header .brand { display:flex;align-items:center;gap:10px;font-size:21px;font-weight:700;padding:10px 0;color:#fff; }
        header .brand img,.brand-fallback { width:42px;height:42px;border-radius:10px;object-fit:contain;background:#fff;padding:4px;flex:0 0 42px; }
        .brand-fallback { display:grid;place-items:center;color:var(--teal);font-size:20px;font-weight:900; }
        header .brand small { display:block;color:#b9ece5;font-size:10px;font-weight:500;line-height:1.2; }
        header nav a {
            color: #d7f5f0; padding: 16px 2px; display: inline-block;
            border-bottom: 3px solid transparent; font-size: 14px;
        }
        header nav a.active, header nav a:hover { color: #fff; border-bottom-color: #99f6e4; }
        header nav { display: flex; gap: 18px; flex-wrap: wrap; }
        .nav-toggle { display:none;background:transparent;color:#fff;border:1px solid rgba(255,255,255,.45);border-radius:8px;min-width:44px;min-height:44px;font:inherit;cursor:pointer; }
        header .who { margin-inline-start: auto; font-size: 13px; color: #b9ece5; }
        main { max-width: 1180px; margin: 0 auto; padding: 22px 20px 60px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .sub { color: var(--muted); font-size: 13px; margin-bottom: 20px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; margin-bottom: 18px; }
        .card > .hd {
            padding: 12px 16px; border-bottom: 1px solid var(--line);
            font-weight: 600; display: flex; align-items: center; gap: 10px;
        }
        .card > .bd { padding: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 14px; text-align: start; border-bottom: 1px solid var(--line); font-size: 14px; }
        th { background: var(--teal-50); font-weight: 600; color: #115e59; }
        tr:last-child td { border-bottom: 0; }
        .pill { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .pill.green { background: #dcfce7; color: var(--green); }
        .pill.amber { background: #fef3c7; color: var(--amber); }
        .pill.red { background: #fee2e2; color: var(--red); }
        .pill.grey { background: #f1f5f9; color: #475569; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .kpi { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; }
        .kpi .v { font-size: 26px; font-weight: 700; }
        .kpi .l { font-size: 12px; color: var(--muted); }
        .kpi .n { font-size: 11px; color: var(--muted); margin-top: 4px; }
        .btn {
            display: inline-flex; align-items:center; justify-content:center; min-height:44px; background: var(--teal); color: #fff; border: 0;
            padding: 9px 16px; border-radius: 8px; font-size: 14px; cursor: pointer; font-family: inherit;
        }
        .btn.ghost { background: #fff; color: var(--teal); border: 1px solid var(--teal); }
        .btn.small { padding: 5px 11px; font-size: 13px; min-height:44px; }
        input, select, textarea {
            width: 100%; padding: 9px 11px; border: 1px solid var(--line);
            border-radius: 8px; font-family: inherit; font-size: 14px; background: #fff;
        }
        label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 4px; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        .field { margin-bottom: 14px; }
        .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 14px; }
        .flash.ok { background: #dcfce7; color: #14532d; }
        .flash.err { background: #fee2e2; color: #7f1d1d; }
        .empty { padding: 30px; text-align: center; color: var(--muted); }
        .note { background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 12px 14px; font-size: 13px; }
        .org-profile { display:grid;grid-template-columns:150px 1fr;gap:20px;align-items:start; }
        .org-logo { min-height:130px;border:1px dashed #99d8d1;border-radius:12px;background:var(--teal-50);display:grid;place-items:center;padding:12px;text-align:center;color:var(--muted); }
        .org-logo img { width:100%;height:105px;object-fit:contain; }
        .org-meta { font-size:12px;color:var(--muted);margin-top:7px; }
        @media (max-width: 760px) {
            .grid2, .grid3 { grid-template-columns: 1fr; }
            header { gap:8px;padding:6px 14px; }
            header .brand { padding:4px 0; }
            .nav-toggle { display:inline-grid;place-items:center;margin-inline-start:auto; }
            header nav { display:none;flex:1 0 100%;flex-direction:column;gap:0;padding:4px 0 10px; }
            header nav.open { display:flex; }
            header nav a { min-height:44px;padding:9px 4px;border-bottom-width:1px; }
            header .who { width:100%;margin:0;padding:0 0 8px;display:flex;align-items:center;justify-content:space-between; }
        }
        @media (max-width: 760px) { .org-profile { grid-template-columns:1fr; }.org-logo{min-height:110px}.org-logo img{height:90px} }
    </style>
</head>
<body>
@include('partials.language-switcher')

@auth('web')
@php($panelUser = auth('web')->user())
<header>
    <a class="brand" href="{{ route('panel.board') }}">
        @if($panelCompany?->logo_path)
            <img src="{{ route('panel.organization.logo', $panelCompany) }}" alt="شعار {{ $panelCompany->name }}">
        @else
            <span class="brand-fallback">{{ mb_substr($panelCompany?->name ?? 'دارك', 0, 1) }}</span>
        @endif
        <span>{{ $panelCompany?->name ?? 'دارك' }}<small>لوحة التحكم</small></span>
    </a>
    <button type="button" class="nav-toggle" aria-label="فتح قائمة التنقل" aria-controls="panel-navigation" aria-expanded="false">☰</button>
    <nav id="panel-navigation" aria-label="التنقل الرئيسي">
        @if($panelUser->canPanel('operations'))
        <a href="{{ route('panel.board') }}" class="{{ request()->routeIs('panel.board') ? 'active' : '' }}">لوحة اليوم</a>
        <a href="{{ route('panel.operations') }}" class="{{ request()->routeIs('panel.operations') ? 'active' : '' }}">الجدولة والمؤشرات</a>
        <a href="{{ route('panel.maintenance') }}" class="{{ request()->routeIs('panel.maintenance*') ? 'active' : '' }}">الصيانة الوقائية</a>
        <a href="{{ route('panel.sub') }}" class="{{ request()->routeIs('panel.sub*') ? 'active' : '' }}">الباطن</a>
        <a href="{{ route('panel.notifications') }}" class="{{ request()->routeIs('panel.notifications') ? 'active' : '' }}">الإشعارات</a>
        <a href="{{ route('panel.emergencies') }}" class="{{ request()->routeIs('panel.emergenc*') ? 'active' : '' }}">البلاغات الطارئة</a>
        @endif
        @if(config('darak.experimental_analytics') && $panelUser->canPanel('performance'))<a href="{{ route('panel.performance') }}" class="{{ request()->routeIs('panel.performance*') ? 'active' : '' }}">الأداء والترتيب</a>@endif
        @if($panelUser->canPanel('sales'))<a href="{{ route('sales.home') }}" class="{{ request()->routeIs('sales.*') ? 'active' : '' }}">تطبيق المسوق</a>@endif
        @if(config('darak.experimental_analytics') && $panelUser->canPanel('intelligence'))
        <a href="{{ route('panel.intelligence') }}" class="{{ request()->routeIs('panel.intelligence*') ? 'active' : '' }}">ذكاء الأعطال</a>
        @endif
        @if($panelUser->canPanel('clients'))
        <a href="{{ route('panel.clients') }}" class="{{ request()->routeIs('panel.clients*') ? 'active' : '' }}">العملاء والعقود</a>
        @endif
        @if($panelUser->canPanel('commercial'))
        <a href="{{ route('panel.commercial') }}" class="{{ request()->routeIs('panel.commercial') || request()->routeIs('panel.quotation*') || request()->routeIs('panel.installment*') ? 'active' : '' }}">العروض والتحصيل</a>
        @endif
        @if($panelUser->canPanel('finance'))
        <a href="{{ route('panel.finance') }}" class="{{ request()->routeIs('panel.finance*') ? 'active' : '' }}">الربحية</a>
        @endif
        @if($panelUser->canPanel('inventory'))
        <a href="{{ route('panel.inventory') }}" class="{{ request()->routeIs('panel.inventory*') ? 'active' : '' }}">المخزون</a>
        <a href="{{ route('panel.procurement') }}" class="{{ request()->routeIs('panel.procurement*') ? 'active' : '' }}">المشتريات والحجوزات</a>
        @endif
        @if($panelUser->canPanel('team'))
        <a href="{{ route('panel.team') }}" class="{{ request()->routeIs('panel.team') ? 'active' : '' }}">الفريق والأجهزة</a>
        @endif
        @if($panelUser->canPanel('hr'))<a href="{{ route('panel.hr') }}" class="{{ request()->routeIs('panel.hr*') ? 'active' : '' }}">شؤون الموظفين</a>@endif
        @if($panelUser->canPanel('fleet'))<a href="{{ route('panel.fleet') }}" class="{{ request()->routeIs('panel.fleet*') ? 'active' : '' }}">إدارة الأسطول</a>@endif
        @if($panelUser->canPanel('admin'))<a href="{{ route('panel.admin-operations') }}" class="{{ request()->routeIs('panel.admin-operations') ? 'active' : '' }}">الإدارة وCRM</a><a href="{{ route('panel.admin.organization') }}" class="{{ request()->routeIs('panel.admin.organization') ? 'active' : '' }}">بيانات المؤسسة</a>@endif
    </nav>
    <div class="who">
        {{ auth('web')->user()->name }}
        <form method="POST" action="{{ route('panel.logout') }}" style="display:inline">
            @csrf
            <button class="btn small ghost" style="margin-inline-start:8px">خروج</button>
        </form>
    </div>
</header>
@endauth

<main>
    @if (session('ok'))   <div class="flash ok">{{ session('ok') }}</div>   @endif
    @if (session('err'))  <div class="flash err">{{ session('err') }}</div> @endif

    @if ($errors->any())
        <div class="flash err">
            @foreach ($errors->all() as $error) <div>• {{ $error }}</div> @endforeach
        </div>
    @endif

    @yield('content')
</main>

@include('partials.form-accessibility')
<script nonce="{{ $cspNonce }}">
const navToggle=document.querySelector('.nav-toggle');const panelNav=document.getElementById('panel-navigation');
navToggle?.addEventListener('click',()=>{const open=panelNav.classList.toggle('open');navToggle.setAttribute('aria-expanded',String(open));navToggle.setAttribute('aria-label',open?'إغلاق قائمة التنقل':'فتح قائمة التنقل')});
</script>
@include('partials.runtime-localization')

</body>
</html>
