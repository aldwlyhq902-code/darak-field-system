(() => {
  const whatsapp = document.createElement('a');
  whatsapp.className = 'whatsapp-float';
  whatsapp.href = 'https://wa.me/966558048004?text=' + encodeURIComponent('مرحبًا، أرغب في عرض وشراء مشروع مِحور وإجراء مشاهدة عملية للنظام.');
  whatsapp.target = '_blank';
  whatsapp.rel = 'noopener noreferrer';
  whatsapp.setAttribute('aria-label', 'تواصل معنا عبر واتساب على الرقم 0558048004');
  whatsapp.innerHTML = '<span class="whatsapp-icon" aria-hidden="true">☎</span><span class="whatsapp-copy"><small>تواصل معنا عبر واتساب</small><b dir="ltr">055 804 8004</b></span>';
  document.body.appendChild(whatsapp);

  const pageProfiles = {
    'control-center.html': { badge: 'لوحة الإدارة · 21 شاشة تشغيلية', chips: ['21 شاشة', '264 اختبار خادم', 'عزل شركات وفروع'] },
    'supervisor.html': { badge: 'موقع المشرف · 11 مساحة عمل', chips: ['11 مساحة عمل', 'SLA وETA', 'تقويم متجاوب'] },
    'technician.html': { badge: 'Flutter · 10 شاشات · Offline-first', chips: ['10 شاشات مستقلة', '39 اختبارًا', 'قاعدة محلية مشفرة'] },
    'client.html': { badge: 'PWA للعميل وQR · 10 تجارب جوال', chips: ['10 تجارب', 'PWA قابلة للتثبيت', 'بلاغ عام من QR'] }
  };
  const currentPage = location.pathname.split('/').pop() || 'index.html';
  const profile = pageProfiles[currentPage];
  if (profile) {
    const badge = document.querySelector('.page-hero .page-badge');
    if (badge) badge.textContent = profile.badge;
    const actions = document.querySelector('.page-hero .hero-actions');
    if (actions && !actions.nextElementSibling?.classList.contains('role-hero-count')) {
      const chips = document.createElement('div');
      chips.className = 'role-hero-count';
      chips.innerHTML = profile.chips.map((item) => `<span>${item}</span>`).join('');
      actions.insertAdjacentElement('afterend', chips);
    }
  }

  const header = document.querySelector('.site-header');
  const progress = document.querySelector('.progress span');
  const menu = document.querySelector('.menu-button');
  const links = document.querySelector('.nav-links');

  const updateScroll = () => {
    const max = document.documentElement.scrollHeight - innerHeight;
    if (progress) progress.style.width = `${max > 0 ? (scrollY / max) * 100 : 0}%`;
    header?.classList.toggle('scrolled', scrollY > 22);
  };
  addEventListener('scroll', updateScroll, { passive: true });
  updateScroll();

  menu?.addEventListener('click', () => {
    const open = links?.classList.toggle('open') ?? false;
    menu.setAttribute('aria-expanded', String(open));
  });
  links?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
    links.classList.remove('open');
    menu?.setAttribute('aria-expanded', 'false');
  }));

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: .12 });
  document.querySelectorAll('.reveal').forEach((node) => observer.observe(node));

  const counters = document.querySelectorAll('[data-count]');
  const countObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      const node = entry.target;
      const target = Number(node.dataset.count || 0);
      const suffix = node.dataset.suffix || '';
      const duration = 1000;
      const start = performance.now();
      const tick = (now) => {
        const t = Math.min(1, (now - start) / duration);
        node.textContent = `${Math.round(target * (1 - Math.pow(1 - t, 3)))}${suffix}`;
        if (t < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
      countObserver.unobserve(node);
    });
  }, { threshold: .7 });
  counters.forEach((node) => countObserver.observe(node));
})();
