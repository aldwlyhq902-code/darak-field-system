const CACHE='mihwar-sales-shell-v3-{{ app()->getLocale() }}';
const OFFLINE='{{ route('sales.offline', absolute: false) }}';
const PUBLIC_SHELL=[OFFLINE,'/client-icon.svg','{{ route('sales.manifest', absolute: false) }}'];
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(PUBLIC_SHELL)).then(()=>self.skipWaiting())));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',event=>{
    if(event.request.method!=='GET')return;
    const url=new URL(event.request.url);if(url.origin!==location.origin)return;
    if(PUBLIC_SHELL.includes(url.pathname)){event.respondWith(caches.match(event.request).then(cached=>cached||fetch(event.request)));return}
    // CRM pages are deliberately network-only. Customer names, phones, values,
    // quotes and commissions must never remain in Cache Storage after logout.
    if(url.pathname.startsWith('/sales'))event.respondWith(fetch(event.request).catch(()=>caches.match(OFFLINE)));
});
self.addEventListener('push',event=>{let data={title:'{{ app()->isLocale('ar') ? 'تنبيه مبيعات' : 'Sales notification' }}',body:'{{ app()->isLocale('ar') ? 'لديك تحديث جديد' : 'You have a new update' }}',url:'{{ route('sales.home', absolute: false) }}'};try{data={...data,...event.data.json()}}catch(_){}event.waitUntil(self.registration.showNotification(data.title,{body:data.body,icon:'/client-icon.svg',badge:'/client-icon.svg',dir:'{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}',lang:'{{ app()->getLocale() }}',data:{url:data.url}}))});
self.addEventListener('notificationclick',event=>{event.notification.close();const target=event.notification.data?.url||'{{ route('sales.home', absolute: false) }}';event.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(windows=>{for(const client of windows){if(client.url.includes('/sales/')&&'focus' in client){client.navigate(target);return client.focus()}}return clients.openWindow(target)}))});
