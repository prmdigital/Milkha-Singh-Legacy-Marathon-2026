/* ==========================================================================
   Milkha Singh Legacy Marathon 2026 — registration + Razorpay checkout

   The prices below are for DISPLAY ONLY. api/create-order.php holds the real
   table and never trusts an amount sent from here, so editing these in the
   browser changes the label and nothing else.
   ========================================================================== */
(function () {
  'use strict';

  var form = document.getElementById('regForm');
  if (!form) return;

  var API = 'api/';

  /* Online payment is not live for this event: the runner submits their
     details and the team collects the fee. The button must not promise a
     payment step that never comes. */
  var SUBMIT_LABEL = 'Submit registration';

  var CATEGORIES = {
    half:  { label: 'Half Marathon',     base: 150000 },
    mini:  { label: 'Mini Marathon',     base: 100000 },
    cause: { label: 'Run for Cause',     base: 65000  },
    para:  { label: 'Disabled Category', base: 0      }
  };
  var EARLY_PERCENT = 20;
  var EARLY_UNTIL   = '2026-11-07T23:59:59+05:30';

  var summary   = document.getElementById('regSummary');
  var amountEl  = document.getElementById('regAmount');
  var noteEl    = document.getElementById('regNote');
  var statusEl  = document.getElementById('regStatus');
  var submitBtn = document.getElementById('regSubmit');
  var doneBox   = document.getElementById('regDone');
  var doneId    = document.getElementById('regDoneId');

  /* ---------- Pricing (display) ---------- */

  function earlyActive() {
    return Date.now() <= new Date(EARLY_UNTIL).getTime();
  }

  function priceFor(key) {
    var c = CATEGORIES[key];
    if (!c) return null;
    var early = c.base > 0 && earlyActive();
    var payable = early ? Math.round(c.base * (100 - EARLY_PERCENT) / 100) : c.base;
    return { base: c.base, payable: payable, early: early, label: c.label };
  }

  function rupees(paise) {
    return '₹' + (paise / 100).toLocaleString('en-IN');
  }

  /* Fill the fee chip on each category tile. */
  Object.keys(CATEGORIES).forEach(function (key) {
    var el = document.querySelector('[data-fee="' + key + '"]');
    if (!el) return;
    var p = priceFor(key);
    if (p.base === 0) {
      el.textContent = 'Free entry';
      return;
    }
    el.innerHTML = p.early
      ? '<s>' + rupees(p.base) + '</s> ' + rupees(p.payable)
      : rupees(p.payable);
  });

  function selectedCategory() {
    var picked = form.querySelector('input[name="category"]:checked');
    return picked ? picked.value : '';
  }

  function refreshSummary() {
    var key = selectedCategory();
    var p = key && priceFor(key);
    if (!p) {
      summary.hidden = true;
      submitBtn.textContent = SUBMIT_LABEL;
      return;
    }
    summary.hidden = false;
    amountEl.textContent = p.base === 0 ? 'Free' : rupees(p.payable);
    noteEl.textContent = p.base === 0
      ? 'No payment needed for the 1 KM category.'
      : (p.early ? 'Early entry price, 20% off until 7 November 2026.' : 'Standard entry price.')
        + ' Our team will contact you to collect it.';
    submitBtn.textContent = SUBMIT_LABEL;
  }

  form.addEventListener('change', function (e) {
    if (e.target.name === 'category') refreshSummary();
  });
  /* A reset (or a browser restoring form state on back-navigation) clears the
     radio without firing change, which would leave a stale fee on screen. */
  form.addEventListener('reset', function () { setTimeout(refreshSummary, 0); });
  window.addEventListener('pageshow', refreshSummary);
  refreshSummary();

  /* ---------- Errors ---------- */

  function clearErrors() {
    form.querySelectorAll('[data-err]').forEach(function (el) { el.textContent = ''; });
    form.querySelectorAll('[aria-invalid]').forEach(function (el) {
      el.removeAttribute('aria-invalid');
    });
  }

  function showErrors(fields) {
    var first = null;
    Object.keys(fields || {}).forEach(function (name) {
      var slot = form.querySelector('[data-err="' + name + '"]');
      if (slot) slot.textContent = fields[name];
      var input = form.elements[name];
      if (input && input.setAttribute) {
        input.setAttribute('aria-invalid', 'true');
        if (!first) first = input;
      }
    });
    if (first && first.focus) first.focus();
  }

  function setStatus(msg, kind) {
    statusEl.textContent = msg || '';
    statusEl.className = 'reg__status' + (kind ? ' is-' + kind : '');
  }

  function busy(on, label) {
    submitBtn.disabled = on;
    submitBtn.textContent = on ? (label || 'Please wait…') : SUBMIT_LABEL;
  }

  /* ---------- Payload ---------- */

  /* ---------- Validation ----------
     Mirrors validate_runner() in api/lib.php. The server still re-checks
     everything — this only saves a round trip and points at the bad field. */

  var MIN_AGE = { half: 18, mini: 18, cause: 12, para: 0 };

  /* Accepts 9876543210, 09876543210, +91 98765 43210 — returns the bare 10
     digits, or '' when it is not a valid Indian mobile. */
  /* Race day. Age is quoted "on race day", not today: someone whose birthday
     falls between now and December would otherwise be told the wrong age, and
     could be turned away at bib collection for a category they do qualify for. */
  var RACE_DAY = new Date(2026, 11, 20);

  function ageOnRaceDay(dobString) {
    if (!dobString) return null;
    var parts = dobString.split('-');
    if (parts.length !== 3) return null;

    var y = +parts[0], m = +parts[1], d = +parts[2];
    var dob = new Date(y, m - 1, d);
    // Rejects 31 February and friends, which the date input allows when typed.
    if (dob.getFullYear() !== y || dob.getMonth() !== m - 1 || dob.getDate() !== d) {
      return null;
    }
    if (dob > RACE_DAY) return null;

    var age = RACE_DAY.getFullYear() - y;
    var monthDiff = RACE_DAY.getMonth() - (m - 1);
    if (monthDiff < 0 || (monthDiff === 0 && RACE_DAY.getDate() < d)) {
      age--;                       // birthday has not landed by race day
    }
    return age;
  }

  /* Shows the age back the moment a date is picked, so nobody has to work out
     what they will be in December. */
  var dobInput = document.getElementById('regDob');
  var ageOut   = document.getElementById('regAgeOut');

  function refreshAge() {
    if (!dobInput || !ageOut) return;
    var age = ageOnRaceDay(dobInput.value);
    ageOut.textContent = age === null ? '—' : age + (age === 1 ? ' year' : ' years');
    ageOut.classList.toggle('is-set', age !== null);
  }

  if (dobInput) {
    dobInput.addEventListener('change', refreshAge);
    dobInput.addEventListener('input', refreshAge);
    refreshAge();
  }

  /* Mobile: digits only, and never more than ten. maxlength alone does not stop
     a paste of "+91 98765 43210", which would silently lose the last digits. */
  function digitsOnly(el, max) {
    if (!el) return;
    var clean = function () {
      var before = el.value;
      var after  = before.replace(/\D/g, '');

      /* Drop a country or trunk prefix BEFORE truncating. Someone pasting
         "+91 98765 43210" would otherwise be left with "9198765432" — the 91
         kept and the last two digits cut off, a wrong number saved in silence.
         Only applied when the value is too long, so a genuine 10-digit number
         beginning 91 is untouched. */
      if (after.length > max) {
        if (after.indexOf('91') === 0 && after.length >= max + 2) {
          after = after.slice(2);
        } else if (after.charAt(0) === '0') {
          after = after.slice(1);
        }
      }

      after = after.slice(0, max);
      if (after !== before) {
        var atEnd = el.selectionStart === before.length;
        el.value = after;
        if (!atEnd && el.setSelectionRange) {
          try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) {}
        }
      }
    };
    el.addEventListener('input', clean);
    el.addEventListener('paste', function () { setTimeout(clean, 0); });
  }

  digitsOnly(document.getElementById('regMobile'), 10);
  digitsOnly(document.getElementById('regEmPhone'), 10);

  function normaliseMobile(raw) {
    var d = String(raw || '').replace(/[^0-9]/g, '');
    if (/^(91|0)\d{10}$/.test(d)) d = d.replace(/^(91|0)/, '');
    return /^[6-9]\d{9}$/.test(d) ? d : '';
  }

  function validate(d) {
    var f = {};

    if (!d.category) f.category = 'Please choose a race category.';

    if (d.fullName.length < 2 || d.fullName.length > 120) {
      f.fullName = 'Please enter your full name.';
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(d.email)) {
      f.email = 'Please enter a valid email address.';
    }

    var digits = String(d.mobile).replace(/[^0-9]/g, '');
    if (!d.mobile) {
      f.mobile = 'Please enter your mobile number.';
    } else if (!normaliseMobile(d.mobile)) {
      f.mobile = digits.length === 10
        ? 'An Indian mobile number starts with 6, 7, 8 or 9.'
        : 'Please enter a 10-digit mobile number (you entered ' + digits.length + ').';
    }

    var age = ageOnRaceDay(d.dob);
    if (!d.dob) {
      f.dob = 'Please enter your date of birth.';
    } else if (age === null) {
      f.dob = 'Please enter a valid date of birth.';
    } else if (age < 5 || age > 100) {
      f.dob = 'Runners must be between 5 and 100 on race day.';
    } else if (d.category && age < MIN_AGE[d.category]) {
      f.age = CATEGORIES[d.category].label + ' is open to runners aged ' +
              MIN_AGE[d.category] + ' and over.';
    }

    if (!d.gender) f.gender = 'Please select a gender.';
    if (!d.city) f.city = 'Please enter your city.';
    if (!d.tshirtSize) f.tshirtSize = 'Please choose a T-shirt size.';
    if (!d.idProofType) f.idProofType = 'Please select an ID proof type.';

    var file = form.elements.idProofFile.files[0];
    if (!file) {
      f.idProofFile = 'Please attach a photo or PDF of your ID proof.';
    } else if (file.size > 5 * 1024 * 1024) {
      f.idProofFile = 'That file is ' + (file.size / 1048576).toFixed(1) +
                      ' MB. Please upload one under 5 MB.';
    } else if (['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
                 .indexOf(file.type) === -1) {
      f.idProofFile = 'Please upload a JPG, PNG, WEBP or PDF.';
    }

    /* Emergency number is the one optional field, but if it is filled in it
       still has to be a real number. */
    if (d.emergencyPhone && !normaliseMobile(d.emergencyPhone)) {
      f.emergencyPhone = 'Please enter a 10-digit number, or leave this blank.';
    }

    if (!d.declaration) f.declaration = 'Please confirm the health declaration.';

    return f;
  }

  function payload() {
    var d = {};
    ['fullName', 'email', 'mobile', 'dob', 'gender', 'city', 'tshirtSize',
     'idProofType', 'emergencyPhone'].forEach(function (n) {
      var el = form.elements[n];
      d[n] = el ? el.value.trim() : '';
    });
    d.category = selectedCategory();
    d.declaration = form.elements.declaration.checked ? 1 : 0;
    return d;
  }

  function postJSON(path, data) {
    return fetch(API + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        return { status: res.status, body: body };
      });
    });
  }

  /* Registration carries a file, so it goes as multipart/form-data. Do NOT set
     Content-Type by hand — the browser has to add its own multipart boundary. */
  function postForm(path, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });

    var file = form.elements.idProofFile.files[0];
    if (file) fd.append('idProofFile', file, file.name);

    return fetch(API + path, { method: 'POST', body: fd }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        return { status: res.status, body: body };
      });
    });
  }

  function succeed(registrationId, amountDue) {
    form.hidden = true;
    doneId.textContent = registrationId;

    /* Say plainly what happens next. Someone who has just filled in a long form
       should not be left wondering whether they still owe money. */
    var dueBox = document.getElementById('regDoneDue');
    if (dueBox) {
      if (amountDue > 0) {
        dueBox.textContent = 'Entry fee ' + rupees(amountDue) +
          ' is still to be paid. Our team will contact you on the mobile number ' +
          'you gave to arrange it.';
        dueBox.hidden = false;
      } else {
        dueBox.hidden = true;
      }
    }

    doneBox.hidden = false;
    doneBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  /* ---------- Razorpay ---------- */

  var rzpLoading = null;

  function loadRazorpay() {
    if (window.Razorpay) return Promise.resolve(true);
    if (rzpLoading) return rzpLoading;

    rzpLoading = new Promise(function (resolve) {
      var s = document.createElement('script');
      s.src = 'https://checkout.razorpay.com/v1/checkout.js';
      s.onload = function () { resolve(true); };
      s.onerror = function () { resolve(false); };
      document.head.appendChild(s);
    });
    return rzpLoading;
  }

  function openCheckout(order, runner) {
    var rzp = new window.Razorpay({
      key: order.keyId,
      order_id: order.orderId,
      amount: order.amount,
      currency: order.currency,
      name: 'Milkha Singh Legacy Marathon 2026',
      description: order.category,
      prefill: {
        name: runner.fullName,
        email: runner.email,
        contact: runner.mobile
      },
      notes: { registration_id: order.registrationId },
      theme: { color: '#E8650B' },
      handler: function (response) {
        setStatus('Payment received. Confirming your registration…', 'ok');
        busy(true, 'Confirming…');

        postJSON('verify-payment.php', {
          razorpay_order_id:   response.razorpay_order_id,
          razorpay_payment_id: response.razorpay_payment_id,
          razorpay_signature:  response.razorpay_signature
        }).then(function (r) {
          if (r.status === 200 && r.body.ok) {
            succeed(r.body.registrationId || order.registrationId);
            return;
          }
          /* Money is taken but we could not confirm. The webhook will still
             record it server-side, so reassure rather than alarm. */
          setStatus(
            'Your payment went through, but we could not show your confirmation. ' +
            'It is recorded against payment ' + response.razorpay_payment_id +
            '. Please keep this reference and contact us if you do not receive an email.',
            'warn'
          );
          busy(false);
        }).catch(function () {
          setStatus(
            'Your payment went through. Reference ' + response.razorpay_payment_id +
            '. Please keep it safe; your confirmation email may take a few minutes.',
            'warn'
          );
          busy(false);
        });
      },
      modal: {
        ondismiss: function () {
          busy(false);
          setStatus('Payment cancelled. Your details are saved, you can try again.', '');
        }
      }
    });

    rzp.on('payment.failed', function (resp) {
      var d = (resp && resp.error && resp.error.description) || 'The payment did not go through.';
      setStatus(d + ' Please try again.', 'err');
      busy(false);
    });

    rzp.open();
  }

  /* ---------- Submit ---------- */

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    clearErrors();
    setStatus('');

    var data = payload();

    var problems = validate(data);
    if (Object.keys(problems).length) {
      showErrors(problems);
      setStatus('Please correct the highlighted fields.', 'err');
      return;
    }

    // Send the normalised 10-digit form, not whatever was typed.
    data.mobile = normaliseMobile(data.mobile);
    if (data.emergencyPhone) data.emergencyPhone = normaliseMobile(data.emergencyPhone);

    busy(true, 'Saving…');

    /* Everything goes through register.php. It stores the entry and, if online
       payment is ever switched back on, answers usePaymentFlow so the Razorpay
       path below takes over without this file needing to change. */
    postForm('register.php', data).then(function (r) {
      if (r.status === 200 && r.body.ok) {
        succeed(r.body.registrationId, r.body.amountDue);
        return;
      }
      if (r.body && r.body.usePaymentFlow) {
        startPayment(data);
        return;
      }
      if (r.status === 422) showErrors(r.body.fields);
      setStatus(r.body.error || 'Could not save your registration.', 'err');
      busy(false);
    }).catch(function () {
      setStatus('Network problem. Please check your connection and try again.', 'err');
      busy(false);
    });
  });

  /* ---------- Online payment (dormant while PAYMENTS_ENABLED is false) ---- */

  function startPayment(data) {
    /* The free category has nothing to charge for, and create-order.php refuses
       it outright. Without this it would dead-end the moment payments are
       switched back on. */
    if (priceFor(data.category).base === 0) {
      busy(true, 'Saving…');
      postForm('register-free.php', data).then(function (r) {
        if (r.status === 200 && r.body.ok) {
          succeed(r.body.registrationId, 0);
          return;
        }
        if (r.status === 422) showErrors(r.body.fields);
        setStatus(r.body.error || 'Could not save your registration.', 'err');
        busy(false);
      }).catch(function () {
        setStatus('Network problem. Please check your connection and try again.', 'err');
        busy(false);
      });
      return;
    }

    busy(true, 'Preparing payment…');

    loadRazorpay().then(function (loaded) {
      if (!loaded) {
        setStatus('Could not load the payment gateway. Please try again.', 'err');
        busy(false);
        return;
      }

      return postForm('create-order.php', data).then(function (r) {
        if (r.status === 200 && r.body.ok) {
          setStatus('');
          openCheckout(r.body, data);
          return;
        }
        if (r.status === 422) showErrors(r.body.fields);
        setStatus(r.body.error || 'Could not start the payment.', 'err');
        busy(false);
      });
    }).catch(function () {
      setStatus('Network problem. Please check your connection and try again.', 'err');
      busy(false);
    });
  }
})();
