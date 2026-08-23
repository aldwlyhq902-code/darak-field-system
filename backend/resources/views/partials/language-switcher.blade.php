<form class="language-switcher" method="POST" action="{{ route('locale.switch', app()->isLocale('ar') ? 'en' : 'ar') }}">
    @csrf
    <button type="submit" aria-label="{{ app()->isLocale('ar') ? 'Switch to English' : 'التبديل إلى العربية' }}">
        {{ app()->isLocale('ar') ? 'English' : 'العربية' }}
    </button>
</form>
<style nonce="{{ $cspNonce }}">
    .language-switcher{position:fixed;z-index:120;inset-block-start:10px;inset-inline-end:10px;margin:0}
    .language-switcher button{min-width:76px;min-height:44px;padding:7px 12px;border:1px solid rgba(15,118,110,.35);border-radius:999px;background:#fff;color:#0f766e;font:700 13px/1.2 "Segoe UI",Tahoma,system-ui,sans-serif;box-shadow:0 4px 18px rgba(0,0,0,.08);cursor:pointer}
</style>
