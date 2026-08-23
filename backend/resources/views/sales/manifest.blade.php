{!! json_encode([
    'id' => '/sales/',
    'name' => app()->isLocale('ar') ? 'محور للمبيعات' : 'Mihwar Sales',
    'short_name' => app()->isLocale('ar') ? 'محور مبيعات' : 'Mihwar Sales',
    'description' => app()->isLocale('ar') ? 'إدارة العملاء المحتملين والمتابعات والعروض والأداء من الجوال' : 'Manage leads, follow-ups, quotations, and performance on mobile',
    'start_url' => route('sales.home', absolute: false),
    'scope' => '/sales/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#f7f8f2',
    'theme_color' => '#0b3b35',
    'lang' => app()->getLocale(),
    'dir' => app()->isLocale('ar') ? 'rtl' : 'ltr',
    'categories' => ['business', 'productivity'],
    'icons' => [
        ['src' => '/client-icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
