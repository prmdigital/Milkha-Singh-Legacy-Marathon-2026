/* Sponsorship enquiry form.
 *
 * Deliberately much smaller than register.js: there is no payment, no file
 * upload and no price to keep in step with the server. It validates the four
 * fields we need in order to call somebody back, posts JSON, and swaps the
 * form for a thank-you panel.
 */
(function () {
  'use strict';

  var form = document.getElementById('sponsorForm');
  if (!form) return;

  var API    = 'api/';
  var status = document.getElementById('spStatus');
  var submit = document.getElementById('spSubmit');
  var done   = document.getElementById('spDone');

  /* Digits only, and never more than ten. maxlength alone does not stop a paste
     of "+91 98765 43210", which would silently lose the last digits. */
  var mobile = form.elements.mobile;
  if (mobile) {
    mobile.addEventListener('input', function () {
      var digits = mobile.value.replace(/[^0-9]/g, '');
      digits = digits.replace(/^(91|0)(?=\d{10}$)/, '');
      if (digits.length > 10) digits = digits.slice(0, 10);
      if (digits !== mobile.value) mobile.value = digits;
    });
  }

  function normaliseMobile(v) {
    var d = String(v || '').replace(/[^0-9]/g, '').replace(/^(91|0)(?=\d{10}$)/, '');
    return /^[6-9]\d{9}$/.test(d) ? d : '';
  }

  function showErrors(fields) {
    Array.prototype.forEach.call(form.querySelectorAll('.reg__err'), function (el) {
      el.textContent = '';
    });
    Object.keys(fields).forEach(function (name) {
      var el = form.querySelector('[data-err="' + name + '"]');
      if (el) el.textContent = fields[name];
    });

    // Put the cursor on the first thing that is wrong, rather than leaving
    // someone to hunt for a red line further up a form they have scrolled past.
    var first = Object.keys(fields)[0];
    var input = first && form.elements[first];
    if (input && input.focus) {
      input.focus();
      if (input.scrollIntoView) input.scrollIntoView({ block: 'center' });
    }
  }

  function validate(d) {
    var f = {};
    if (!d.company)     f.company     = 'Please enter your company or organisation name.';
    if (!d.contactName) f.contactName = 'Please enter your name.';

    if (!d.email) {
      f.email = 'Please enter your email address.';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(d.email)) {
      f.email = 'Please enter a valid email address.';
    }

    var digits = String(d.mobile || '').replace(/[^0-9]/g, '');
    if (!d.mobile) {
      f.mobile = 'Please enter your mobile number.';
    } else if (!normaliseMobile(d.mobile)) {
      f.mobile = digits.length === 10
        ? 'An Indian mobile number starts with 6, 7, 8 or 9.'
        : 'Please enter a 10-digit mobile number (you entered ' + digits.length + ').';
    }

    if (!d.tier) f.tier = 'Please choose the kind of sponsorship you have in mind.';
    return f;
  }

  function payload() {
    var d = {};
    ['company', 'website', 'city', 'contactName', 'designation', 'email',
     'mobile', 'tier', 'budget', 'message'].forEach(function (n) {
      var el = form.elements[n];
      d[n] = el ? el.value.trim() : '';
    });
    return d;
  }

  function setStatus(text, kind) {
    status.textContent = text;
    status.className = 'reg__status' + (kind ? ' is-' + kind : '');
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    var data     = payload();
    var problems = validate(data);

    if (Object.keys(problems).length) {
      showErrors(problems);
      setStatus('Please correct the highlighted fields.', 'err');
      return;
    }

    showErrors({});
    submit.disabled = true;
    setStatus('Sending your enquiry\u2026');

    fetch(API + 'sponsor.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        return { status: res.status, body: body };
      });
    }).then(function (r) {
      if (r.status === 422 && r.body.fields) {
        showErrors(r.body.fields);
        setStatus('Please correct the highlighted fields.', 'err');
        submit.disabled = false;
        return;
      }
      if (!r.body.ok) {
        setStatus(r.body.error || 'Something went wrong. Please try again.', 'err');
        submit.disabled = false;
        return;
      }

      form.hidden = true;
      document.getElementById('spDoneRef').textContent = r.body.reference;
      done.hidden = false;
      done.scrollIntoView({ block: 'center' });
    }).catch(function () {
      setStatus('We could not reach the server. Please check your connection and try again.', 'err');
      submit.disabled = false;
    });
  });
}());
