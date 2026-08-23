const CACHE='darak-client-v2-{{ app()->getLocale() }}';
const SHELL=['{{ route('client.login', absolute: false) }}','{{ route('client.offline', absolute: false) }}','/client-icon.svg'];
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(SHELL)).then(()=>self.skipWaiting())));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',event=>{
  if(event.request.method!=='GET')return;
  const url=new URL(event.request.url);
  if(url.origin!==location.origin)return;
  const publicShell=SHELL.includes(url.pathname);
  if(publicShell){event.respondWith(caches.match(event.request).then(cached=>cached||fetch(event.request)));return}
  // Authenticated client pages and reports are deliberately network-only. A
  // shared phone must not reveal client data from Cache Storage after logout.
  event.respondWith(fetch(event.request).catch(()=>caches.match('{{ route('client.offline', absolute: false) }}')));
});
