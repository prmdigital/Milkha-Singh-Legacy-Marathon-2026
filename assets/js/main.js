/* ==========================================================================
   Milkha Singh Legacy Marathon 2026 — interactions
   ========================================================================== */
(function () {
  'use strict';

  /* ---------- Sticky nav + back-to-top ---------- */
  var nav = document.getElementById('nav');
  var toTop = document.getElementById('toTop');

  var onScroll = function () {
    var y = window.scrollY;
    nav.classList.toggle('is-stuck', y > 20);
    toTop.classList.toggle('is-visible', y > window.innerHeight);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  toTop.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  /* ---------- Mobile menu ---------- */
  var toggle = document.getElementById('navToggle');
  var links = document.getElementById('navLinks');

  var setMenu = function (open) {
    links.classList.toggle('is-open', open);
    document.body.classList.toggle('is-nav-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  };

  var closeMenu = function () { setMenu(false); };

  toggle.addEventListener('click', function () {
    setMenu(!links.classList.contains('is-open'));
  });

  links.addEventListener('click', function (e) {
    if (e.target.closest('a')) closeMenu();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMenu();
  });

  /* ---------- Countdown ----------
     Event: 20 December 2026, 06:00 IST (+05:30).
     Update this if the reporting time changes. */
  var TARGET = new Date('2026-12-20T06:00:00+05:30').getTime();
  var cd = document.getElementById('countdown');
  var fields = {
    days:  cd.querySelector('[data-cd="days"]'),
    hours: cd.querySelector('[data-cd="hours"]'),
    mins:  cd.querySelector('[data-cd="mins"]'),
    secs:  cd.querySelector('[data-cd="secs"]')
  };

  var pad = function (n) { return String(n).padStart(2, '0'); };

  var tick = function () {
    var diff = TARGET - Date.now();

    if (diff <= 0) {
      cd.innerHTML = '<p style="grid-column:1/-1;font-family:var(--font-display);' +
        'letter-spacing:.12em;text-transform:uppercase;color:var(--accent);margin:0">' +
        'Race day is here</p>';
      clearInterval(timer);
      return;
    }

    var s = Math.floor(diff / 1000);
    fields.days.textContent  = Math.floor(s / 86400);
    fields.hours.textContent = pad(Math.floor(s % 86400 / 3600));
    fields.mins.textContent  = pad(Math.floor(s % 3600 / 60));
    fields.secs.textContent  = pad(s % 60);
  };

  tick();
  var timer = setInterval(tick, 1000);

  /* ---------- Scroll spy ----------
     Highlights the nav link for whatever section is currently under the top
     third of the viewport, so you always know where you are on the page. */
  var spyMap = {};
  Array.prototype.forEach.call(
    links.querySelectorAll('a[href^="#"]:not(.btn)'),
    function (a) { spyMap[a.getAttribute('href').slice(1)] = a; }
  );

  /* Every section is observed, not just the linked ones. Sections such as
     Registration have no nav link — landing on one must clear the highlight
     rather than leave the previous link lit, which would point at the wrong
     part of the page. */
  var allSections = document.querySelectorAll('main section[id]');

  if (allSections.length && 'IntersectionObserver' in window) {
    var onScreen = {};

    var paintSpy = function () {
      var current = '';
      Array.prototype.forEach.call(allSections, function (s) {
        if (!current && onScreen[s.id]) current = s.id;
      });
      Object.keys(spyMap).forEach(function (id) {
        if (id === current) spyMap[id].setAttribute('aria-current', 'true');
        else spyMap[id].removeAttribute('aria-current');
      });
    };

    var spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { onScreen[e.target.id] = e.isIntersecting; });
      paintSpy();
    }, { rootMargin: '-30% 0px -60% 0px' });

    Array.prototype.forEach.call(allSections, function (s) { spy.observe(s); });
  }

  /* ---------- Reveal on scroll ---------- */
  var targets = document.querySelectorAll(
    '.section__title, .section__sub, .about__copy, .about__media, .tribute__copy, ' +
    '.tribute__slogan, .cause__copy, .cause__media, .card, .info__list, .info__note, ' +
    '.reasons li, .contact__card, .route, .race-card, .prize, .ambassador-card, ' +
    '.sponsor-tile, .press-card, .sponsors__cta'
  );

  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        io.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.1 });

    targets.forEach(function (el, i) {
      el.classList.add('reveal');
      el.style.transitionDelay = (i % 4) * 70 + 'ms';
      io.observe(el);
    });
  }

  /* ---------- Register CTA ----------
     Once the registration URL exists, set REGISTRATION_URL below and the
     button will link straight out instead of scrolling to the contact section. */
  var REGISTRATION_URL = '';
  if (REGISTRATION_URL) {
    document.querySelectorAll('a[href="#register"], #registerBtn').forEach(function (a) {
      a.href = REGISTRATION_URL;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
    });
  }
})();
