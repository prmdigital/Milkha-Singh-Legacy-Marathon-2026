/* ==========================================================================
   Milkha Singh Legacy Marathon 2026 — interactions
   ========================================================================== */
(function () {
  'use strict';

  /* ---------- Sticky nav ---------- */
  var nav = document.getElementById('nav');
  var onScroll = function () {
    nav.classList.toggle('is-stuck', window.scrollY > 20);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------- Mobile menu ---------- */
  var toggle = document.getElementById('navToggle');
  var links = document.getElementById('navLinks');

  var closeMenu = function () {
    links.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Open menu');
  };

  toggle.addEventListener('click', function () {
    var open = links.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  });

  links.addEventListener('click', function (e) {
    if (e.target.closest('a')) closeMenu();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMenu();
  });

  /* ---------- Countdown ----------
     Event: 22 November 2026, 06:00 IST (+05:30).
     Update this if the reporting time changes. */
  var TARGET = new Date('2026-11-22T06:00:00+05:30').getTime();
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

  /* ---------- Reveal on scroll ---------- */
  var targets = document.querySelectorAll(
    '.section__title, .section__sub, .about__copy, .about__media, .tribute__copy, ' +
    '.tribute__slogan, .cause__copy, .cause__media, .card, .info__list, .info__note, ' +
    '.reasons li, .notify, .contact__card, .route'
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

  /* ---------- Notify form ----------
     TODO: wire this to a real endpoint (Google Form, Mailchimp, or a backend
     route). Right now it validates and confirms locally — it does NOT store
     or send the submission anywhere. Do not launch without connecting it. */
  var form = document.getElementById('notify');
  var status = document.getElementById('notifyStatus');

  var setStatus = function (msg, ok) {
    status.textContent = msg;
    status.className = 'notify__status ' + (ok ? 'is-ok' : 'is-err');
  };

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    var name = form.elements.name;
    var email = form.elements.email;
    var emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim());

    [name, email].forEach(function (f) { f.removeAttribute('aria-invalid'); });

    if (!name.value.trim()) {
      name.setAttribute('aria-invalid', 'true');
      name.focus();
      setStatus('Please enter your name.', false);
      return;
    }

    if (!emailOk) {
      email.setAttribute('aria-invalid', 'true');
      email.focus();
      setStatus('Please enter a valid email address.', false);
      return;
    }

    setStatus('Thank you. We will email you the moment registration opens.', true);
    form.reset();
  });

  /* ---------- Register CTA ----------
     Once the registration URL exists, set REGISTRATION_URL below and the
     button will link straight out instead of scrolling to the notify form. */
  var REGISTRATION_URL = '';
  if (REGISTRATION_URL) {
    document.querySelectorAll('a[href="#register"], #registerBtn').forEach(function (a) {
      a.href = REGISTRATION_URL;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
    });
  }
})();
