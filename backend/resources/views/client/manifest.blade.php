{!! json_encode([
    'name' => app()->isLocale('ar') ? 'دارك للعملاء' : 'Darak for clients',
    'short_name' => app()->isLocale('ar') ? 'دارك' : 'Darak',
    'description' => app()->isLocale('ar') ? 'متابعة الصيانة والبلاغات لعملاء دارك' : 'Track maintenance and reports for Darak clients',
    'start_url' => route('client.home', absolute: false),
    'scope' => '/client',
    'display' => 'standalone',
    'background_color' => '#f7faf9',
    'theme_color' => '#075e56',
    'lang' => app()->getLocale(),
    'dir' => app()->isLocale('ar') ? 'rtl' : 'ltr',
    'icons' => [
        ['src' => '/client-icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
