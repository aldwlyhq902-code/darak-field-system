<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'لوحة دارك')</title>
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
        header .brand { font-size: 21px; font-weight: 700; padding: 14px 0; }
        header nav a {
            color: #d7f5f0; padding: 16px 2px; display: inline-block;
            border-bottom: 3px solid transparent; font-size: 14px;
        }
        header nav a.active, header nav a:hover { color: #fff; border-bottom-color: #99f6e4; }
        header nav { display: flex; gap: 18px; flex-wrap: wrap; }
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
        th, td { padding: 10px 14px; text-align: right; border-bottom: 1px solid var(--line); font-size: 14px; }
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
            display: inline-block; background: var(--teal); color: #fff; border: 0;
            padding: 9px 16px; border-radius: 8px; font-size: 14px; cursor: pointer; font-family: inherit;
        }
        .btn.ghost { background: #fff; color: var(--teal); border: 1px solid var(--teal); }
        .btn.small { padding: 5px 11px; font-size: 13px; }
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
        @media (max-width: 760px) { .grid2, .grid3 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

@auth('web')
<header>
    <div class="brand">دارك</div>
    <nav>
        <a href="{{ route('panel.board') }}" class="{{ request()->routeIs('panel.board') ? 'active' : '' }}">لوحة اليوم</a>
        <a href="{{ route('panel.clients') }}" class="{{ request()->routeIs('panel.clients*') ? 'active' : '' }}">العملاء والعقود</a>
        <a href="{{ route('panel.inventory') }}" class="{{ request()->routeIs('panel.inventory*') ? 'active' : '' }}">المخزون</a>
        <a href="{{ route('panel.sub') }}" class="{{ request()->routeIs('panel.sub*') ? 'active' : '' }}">الباطن</a>
        <a href="{{ route('panel.team') }}" class="{{ request()->routeIs('panel.team') ? 'active' : '' }}">الفريق والأجهزة</a>
        <a href="{{ route('panel.notifications') }}" class="{{ request()->routeIs('panel.notifications') ? 'active' : '' }}">الإشعارات</a>
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

</body>
</html>
