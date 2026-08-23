<aside class="app-sidebar" id="panel-navigation" aria-label="التنقل الرئيسي">
    <div class="sidebar-head">
        <a class="brand" href="{{ route('panel.board') }}" aria-label="{{ $panelCompany?->name ?? 'دارك' }} — لوحة التحكم">
            @if($panelCompany?->logo_path)
                <img src="{{ route('panel.organization.logo', $panelCompany) }}" alt="">
            @else
                <span class="brand-fallback" aria-hidden="true">{{ mb_substr($panelCompany?->name ?? 'دارك', 0, 1) }}</span>
            @endif
            <span class="brand-copy"><strong>{{ $panelCompany?->name ?? 'دارك' }}</strong><small>لوحة التحكم</small></span>
        </a>
        <button type="button" class="sidebar-close" data-sidebar-close aria-label="إغلاق قائمة التنقل">×</button>
    </div>

    <nav class="sidebar-nav">
        @if($panelUser->canPanel('operations'))
            <section class="nav-section" aria-labelledby="nav-daily">
                <h2 id="nav-daily">اليوم والتشغيل</h2>
                <a href="{{ route('panel.board') }}" class="nav-link {{ request()->routeIs('panel.board') ? 'active' : '' }}" @if(request()->routeIs('panel.board')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">▦</span><span>لوحة اليوم</span></a>
                <a href="{{ route('panel.operations') }}" class="nav-link {{ request()->routeIs('panel.operations') ? 'active' : '' }}" @if(request()->routeIs('panel.operations')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">◷</span><span>الجدولة والمؤشرات</span></a>
                <a href="{{ route('panel.maintenance') }}" class="nav-link {{ request()->routeIs('panel.maintenance*') ? 'active' : '' }}" @if(request()->routeIs('panel.maintenance*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">◉</span><span>الصيانة الوقائية</span></a>
                <a href="{{ route('panel.emergencies') }}" class="nav-link {{ request()->routeIs('panel.emergenc*') ? 'active' : '' }}" @if(request()->routeIs('panel.emergenc*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">!</span><span>البلاغات الطارئة</span></a>
                <a href="{{ route('panel.notifications') }}" class="nav-link {{ request()->routeIs('panel.notifications') ? 'active' : '' }}" @if(request()->routeIs('panel.notifications')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">●</span><span>الإشعارات</span></a>
            </section>
        @endif

        @if($panelUser->canPanel('clients') || $panelUser->canPanel('commercial') || $panelUser->canPanel('sales'))
            <section class="nav-section" aria-labelledby="nav-commercial">
                <h2 id="nav-commercial">العملاء والمبيعات</h2>
                @if($panelUser->canPanel('clients'))<a href="{{ route('panel.clients') }}" class="nav-link {{ request()->routeIs('panel.clients*') ? 'active' : '' }}" @if(request()->routeIs('panel.clients*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">♙</span><span>العملاء والعقود</span></a>@endif
                @if($panelUser->canPanel('commercial'))<a href="{{ route('panel.commercial') }}" class="nav-link {{ request()->routeIs('panel.commercial') || request()->routeIs('panel.quotation*') || request()->routeIs('panel.installment*') ? 'active' : '' }}" @if(request()->routeIs('panel.commercial') || request()->routeIs('panel.quotation*') || request()->routeIs('panel.installment*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">◇</span><span>العروض والتحصيل</span></a>@endif
                @if($panelUser->canPanel('sales'))<a href="{{ route('sales.home') }}" class="nav-link {{ request()->routeIs('sales.*') ? 'active' : '' }}" @if(request()->routeIs('sales.*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">↗</span><span>تطبيق المسوق</span></a>@endif
            </section>
        @endif

        @if($panelUser->canPanel('finance') || $panelUser->canPanel('inventory'))
            <section class="nav-section" aria-labelledby="nav-resources">
                <h2 id="nav-resources">المالية والموارد</h2>
                @if($panelUser->canPanel('finance'))<a href="{{ route('panel.finance') }}" class="nav-link {{ request()->routeIs('panel.finance*') ? 'active' : '' }}" @if(request()->routeIs('panel.finance*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">﷼</span><span>الربحية</span></a>@endif
                @if($panelUser->canPanel('inventory'))
                    <a href="{{ route('panel.inventory') }}" class="nav-link {{ request()->routeIs('panel.inventory*') ? 'active' : '' }}" @if(request()->routeIs('panel.inventory*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">□</span><span>المخزون</span></a>
                    <a href="{{ route('panel.procurement') }}" class="nav-link {{ request()->routeIs('panel.procurement*') ? 'active' : '' }}" @if(request()->routeIs('panel.procurement*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">⇄</span><span>المشتريات والحجوزات</span></a>
                @endif
            </section>
        @endif

        @if($panelUser->canPanel('team') || $panelUser->canPanel('hr') || $panelUser->canPanel('fleet') || $panelUser->canPanel('operations'))
            <section class="nav-section" aria-labelledby="nav-workforce">
                <h2 id="nav-workforce">الفرق والأصول</h2>
                @if($panelUser->canPanel('team'))<a href="{{ route('panel.team') }}" class="nav-link {{ request()->routeIs('panel.team') ? 'active' : '' }}" @if(request()->routeIs('panel.team')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">♟</span><span>الفريق والأجهزة</span></a>@endif
                @if($panelUser->canPanel('hr'))<a href="{{ route('panel.hr') }}" class="nav-link {{ request()->routeIs('panel.hr*') ? 'active' : '' }}" @if(request()->routeIs('panel.hr*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">◎</span><span>شؤون الموظفين</span></a>@endif
                @if($panelUser->canPanel('fleet'))<a href="{{ route('panel.fleet') }}" class="nav-link {{ request()->routeIs('panel.fleet*') ? 'active' : '' }}" @if(request()->routeIs('panel.fleet*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">▰</span><span>إدارة الأسطول</span></a>@endif
                @if($panelUser->canPanel('operations'))<a href="{{ route('panel.sub') }}" class="nav-link {{ request()->routeIs('panel.sub*') ? 'active' : '' }}" @if(request()->routeIs('panel.sub*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">⌘</span><span>المقاولون من الباطن</span></a>@endif
            </section>
        @endif

        @if((config('darak.experimental_analytics') && ($panelUser->canPanel('performance') || $panelUser->canPanel('intelligence'))) || $panelUser->canPanel('admin'))
            <section class="nav-section" aria-labelledby="nav-admin">
                <h2 id="nav-admin">التحليلات والإدارة</h2>
                @if(config('darak.experimental_analytics') && $panelUser->canPanel('performance'))<a href="{{ route('panel.performance') }}" class="nav-link {{ request()->routeIs('panel.performance*') ? 'active' : '' }}" @if(request()->routeIs('panel.performance*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">⌁</span><span>الأداء والترتيب</span></a>@endif
                @if(config('darak.experimental_analytics') && $panelUser->canPanel('intelligence'))<a href="{{ route('panel.intelligence') }}" class="nav-link {{ request()->routeIs('panel.intelligence*') ? 'active' : '' }}" @if(request()->routeIs('panel.intelligence*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">✦</span><span>ذكاء الأعطال</span></a>@endif
                @if($panelUser->canPanel('admin'))
                    <a href="{{ route('panel.admin-operations') }}" class="nav-link {{ request()->routeIs('panel.admin-operations') ? 'active' : '' }}" @if(request()->routeIs('panel.admin-operations')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">⚙</span><span>الإدارة وCRM</span></a>
                    <a href="{{ route('panel.admin.organization') }}" class="nav-link {{ request()->routeIs('panel.admin.organization') ? 'active' : '' }}" @if(request()->routeIs('panel.admin.organization')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">⌂</span><span>بيانات المؤسسة</span></a>
                @endif
            </section>
        @endif
    </nav>
</aside>
