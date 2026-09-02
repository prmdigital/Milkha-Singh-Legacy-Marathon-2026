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

    // Roll the figure only when it actually changes. Seconds are set directly:
    // a flourish every second stops being a flourish.
    setUnit(fields.days,  String(Math.floor(s / 86400)), true);
    setUnit(fields.hours, pad(Math.floor(s % 86400 / 3600)), true);
    setUnit(fields.mins,  pad(Math.floor(s % 3600 / 60)), true);
    fields.secs.textContent = pad(s % 60);
  };

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  function setUnit(el, value, animate) {
    if (el.textContent === value) return;
    el.textContent = value;
    if (!animate || reduceMotion.matches) return;
    el.classList.remove('is-rolling');
    void el.offsetWidth;                 // restart the animation
    el.classList.add('is-rolling');
  }

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
    '.section__eyebrow, .section__title, .section__sub, .about__copy, .about__media, .tribute__copy, ' +
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

  /* Registration itself lives in register.js — the form and Razorpay flow. */
})();

/* ==========================================================================
   Hero dust motes

   Echoes the dust the runner kicks up in the artwork. Deliberately plain
   canvas rather than a 3D library: the visual is identical and it costs ~2KB
   instead of ~150KB on what is a registration funnel.

   Guards: skipped entirely under prefers-reduced-motion, paused when the hero
   scrolls away or the tab is hidden, so it never burns battery off-screen.
   ========================================================================== */
(function () {
  'use strict';

  var canvas = document.getElementById('heroDust');
  if (!canvas || !canvas.getContext) return;

  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (reduce.matches) return;

  var ctx = canvas.getContext('2d');
  var motes = [];
  var raf = null;
  var w = 0, h = 0, dpr = 1;

  function size() {
    var r = canvas.getBoundingClientRect();
    dpr = Math.min(window.devicePixelRatio || 1, 2);   // cap: 3x costs 2.25x pixels for no gain
    w = r.width; h = r.height;
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function seed() {
    // Fewer on small screens: same visual density, less work per frame.
    var count = w < 700 ? 18 : 38;
    motes = [];
    for (var i = 0; i < count; i++) {
      motes.push({
        x: Math.random() * w,
        y: Math.random() * h,
        r: 0.6 + Math.random() * 1.7,
        vx: 0.06 + Math.random() * 0.20,        // drifts right, like the dust trail
        vy: -0.05 - Math.random() * 0.16,       // and slowly rises
        a: 0.10 + Math.random() * 0.28,
        phase: Math.random() * Math.PI * 2
      });
    }
  }

  function frame(t) {
    ctx.clearRect(0, 0, w, h);

    for (var i = 0; i < motes.length; i++) {
      var m = motes[i];
      m.x += m.vx;
      m.y += m.vy;

      // Gentle lateral sway so it reads as drifting air, not falling snow.
      var sway = Math.sin((t / 2600) + m.phase) * 0.35;

      if (m.y < -12) { m.y = h + 8; m.x = Math.random() * w; }
      if (m.x > w + 12) { m.x = -8; }

      ctx.beginPath();
      ctx.arc(m.x + sway, m.y, m.r, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(232, 152, 68, ' + m.a + ')';   // warm, matches the artwork
      ctx.fill();
    }

    raf = requestAnimationFrame(frame);
  }

  function start() { if (!raf) raf = requestAnimationFrame(frame); }
  function stop()  { if (raf) { cancelAnimationFrame(raf); raf = null; } }

  size(); seed(); start();

  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    // start() too: if the loop was stopped while off-screen, a resize (phone
    // rotation) would otherwise leave the canvas blank for good.
    resizeTimer = setTimeout(function () { size(); seed(); start(); }, 200);
  }, { passive: true });

  // Stop drawing once the hero is off-screen.
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      entries[0].isIntersecting ? start() : stop();
    }, { threshold: 0 }).observe(canvas);
  }

  document.addEventListener('visibilitychange', function () {
    document.hidden ? stop() : start();
  });

  // Honour the preference if it is switched on while the page is open.
  var onPref = function (e) { if (e.matches) { stop(); ctx.clearRect(0, 0, w, h); } else { start(); } };
  reduce.addEventListener ? reduce.addEventListener('change', onPref) : reduce.addListener(onPref);
})();
