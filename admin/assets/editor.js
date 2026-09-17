/* ==========================================================================
   Website editor: click-to-edit on the real page.

   Loaded only by admin/editor.php. Every editable element carries a data-e
   key; clicking one opens the right editor for it:
     text          edited in place, with bold / italic / link buttons
     text + link   the same, plus the link address
     image         replace the file, change the description
     icon link     the address and its name for screen readers
   Saving posts to admin/content-api.php, which cleans the value and publishes
   it straight away.
   ========================================================================== */
(function () {
  'use strict';

  var C = window.CMS_EDITOR;
  if (!C) return;

  var CONTENT = window.SITE_CONTENT || {};
  var active = null;
  var statusTimer = null;

  /* ---------- Small helpers ---------- */

  function make(tag, attrs, children) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') el.textContent = attrs[k];
      else if (k === 'className') el.className = attrs[k];
      else el.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) el.appendChild(c); });
    return el;
  }

  function api(action, data, file) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf', C.csrf);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    if (file) fd.append('file', file);
    return fetch(C.api, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () {
          return { ok: false, error: 'The server sent an unexpected reply (' + r.status + ').' };
        });
      })
      .catch(function () {
        return { ok: false, error: 'Network problem. Check your connection and try again.' };
      });
  }

  function status(msg, kind) {
    statusEl.textContent = msg || '';
    statusEl.className = 'cms-bar__status' + (kind ? ' is-' + kind : '');
    clearTimeout(statusTimer);
    if (kind === 'ok') statusTimer = setTimeout(function () { status(''); }, 6000);
  }

  function keyOf(el) { return el.getAttribute('data-e'); }

  function kindOf(el) {
    if (el.tagName === 'IMG') return 'image';
    if (el.tagName === 'A') return el.textContent.trim() ? 'text-link' : 'link';
    return 'text';
  }

  function remember(key, fields) {
    CONTENT[key] = CONTENT[key] || {};
    Object.keys(fields).forEach(function (f) { CONTENT[key][f] = fields[f]; });
  }

  function markEdited() {
    Object.keys(CONTENT).forEach(function (k) {
      var el = document.querySelector('[data-e="' + k.replace(/"/g, '') + '"]');
      if (el) el.classList.add('cms-edited');
    });
  }

  /* Restoring an original reloads the page so it is read from the page file;
     this brings the editor back to the same place. */
  var scrollKey = 'cms-scroll-' + C.page;
  function reloadHere() {
    try { sessionStorage.setItem(scrollKey, String(window.scrollY)); } catch (e) {}
    window.location.reload();
  }
  window.addEventListener('load', function () {
    var y = null;
    try { y = sessionStorage.getItem(scrollKey); sessionStorage.removeItem(scrollKey); } catch (e) {}
    if (y !== null) window.scrollTo(0, +y);
    markEdited();
  });

  /* ---------- The bar along the bottom ---------- */

  var pagePicker = make('select', { 'aria-label': 'Page to edit', className: 'cms-select' });
  C.pages.forEach(function (p) {
    var o = make('option', { value: p.key, text: p.label });
    if (p.key === C.page) o.selected = true;
    pagePicker.appendChild(o);
  });
  pagePicker.addEventListener('change', function () {
    if (active && !confirm('Leave without saving your change?')) {
      pagePicker.value = C.page;
      return;
    }
    active = null;
    window.location.href = 'admin/editor.php?page=' + encodeURIComponent(pagePicker.value);
  });

  var statusEl = make('p', { className: 'cms-bar__status', role: 'status', 'aria-live': 'polite' });
  var seoBtn = make('button', { type: 'button', className: 'cms-btn cms-btn--ghost', text: 'Page title & description' });
  seoBtn.addEventListener('click', openPageSettings);

  var logosBtn = null;
  if (C.page === 'index') {
    logosBtn = make('button', { type: 'button', className: 'cms-btn cms-btn--ghost', text: 'Sponsor logos' });
    logosBtn.addEventListener('click', function () { openLogos(); });
  }

  var bar = make('div', { className: 'cms-bar cms-ui' }, [
    make('div', { className: 'cms-bar__left' }, [
      make('b', { text: 'Website editor' }),
      pagePicker,
      make('span', { className: 'cms-bar__hint', text: 'Click any text, image or link to change it.' })
    ]),
    statusEl,
    make('div', { className: 'cms-bar__right' }, [
      logosBtn,
      seoBtn,
      make('a', { className: 'cms-btn cms-btn--ghost', href: C.events, text: 'Event, fees & media' }),
      make('a', { className: 'cms-btn cms-btn--ghost', href: C.page + '.html', target: '_blank', rel: 'noopener', text: 'View live page' }),
      make('a', { className: 'cms-btn cms-btn--ghost', href: C.hub, text: 'Back to admin' })
    ])
  ]);
  document.body.appendChild(bar);
  document.body.classList.add('cms-editing');

  window.addEventListener('beforeunload', function (e) {
    if (active && active.dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  /* ---------- Clicks ---------- */

  document.addEventListener('click', function (e) {
    if (e.target.closest('.cms-ui')) return;

    // Inside the element being edited: place the caret, never follow a link.
    if (active && active.el && active.el.contains(e.target)) {
      if (e.target.closest('a')) e.preventDefault();
      return;
    }

    // Either logo area opens the sponsor logo list.
    if (e.target.closest('[data-e-list]')) {
      e.preventDefault();
      e.stopPropagation();
      openLogos();
      return;
    }

    var el = e.target.closest('[data-e]');
    if (!el) {
      // Let the menu button and other page controls work; links go nowhere.
      if (e.target.closest('a')) e.preventDefault();
      return;
    }

    e.preventDefault();
    e.stopPropagation();

    if (active) {
      if (active.dirty && !confirm('You have an unsaved change. Discard it?')) return;
      active.cancel();
    }
    open(el);
  }, true);

  // Nothing on the page may submit while editing: no test registrations.
  document.addEventListener('submit', function (e) { e.preventDefault(); }, true);

  function open(el) {
    var kind = kindOf(el);
    if (kind === 'image') openImage(el);
    else if (kind === 'link') openLink(el);
    else openText(el, kind);
  }

  /* ---------- Text, edited in place ---------- */

  function openText(el, kind) {
    var key = keyOf(el);
    var original = el.innerHTML;

    el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'true');
    el.classList.add('cms-active');

    function toolButton(label, title, fn) {
      var b = make('button', { type: 'button', className: 'cms-tool', title: title, 'aria-label': title, text: label });
      // mousedown, not click: a click would take the selection away first.
      b.addEventListener('mousedown', function (ev) { ev.preventDefault(); fn(); });
      return b;
    }

    var hrefInput = null;
    if (kind === 'text-link') {
      hrefInput = make('input', {
        type: 'text', className: 'cms-input', value: el.getAttribute('href') || '',
        'aria-label': 'Link address', placeholder: 'https://…  tel:…  mailto:…  #section'
      });
      hrefInput.addEventListener('input', function () { state.dirty = true; });
    }

    var saveBtn = make('button', { type: 'button', className: 'cms-btn cms-btn--primary', text: 'Save' });
    var cancelBtn = make('button', { type: 'button', className: 'cms-btn cms-btn--ghost', text: 'Cancel' });
    var restoreBtn = CONTENT[key]
      ? make('button', { type: 'button', className: 'cms-btn cms-btn--link', text: 'Restore original' })
      : null;

    var tools = make('div', { className: 'cms-tools cms-ui', role: 'toolbar', 'aria-label': 'Text editing' }, [
      toolButton('B', 'Bold', function () { document.execCommand('bold'); state.dirty = true; }),
      toolButton('I', 'Italic', function () { document.execCommand('italic'); state.dirty = true; }),
      toolButton('Link', 'Make the selected words a link', function () {
        var url = window.prompt('Link address: https://…, mailto:…, tel:… or #section', 'https://');
        if (url) { document.execCommand('createLink', false, url.trim()); state.dirty = true; }
      }),
      toolButton('Unlink', 'Remove the link from the selected words', function () {
        document.execCommand('unlink'); state.dirty = true;
      }),
      hrefInput ? make('span', { className: 'cms-tools__sep' }) : null,
      hrefInput,
      make('span', { className: 'cms-tools__spacer' }),
      restoreBtn, cancelBtn, saveBtn
    ]);
    document.body.appendChild(tools);

    var state = {
      el: el, dirty: false,
      cancel: function () { el.innerHTML = original; close(); }
    };
    active = state;

    function place() {
      var r = el.getBoundingClientRect();
      var h = tools.offsetHeight;
      var top = r.top + window.scrollY - h - 10;
      if (r.top - h - 10 < 8) top = r.bottom + window.scrollY + 10;
      var left = Math.max(8, Math.min(r.left + window.scrollX, window.scrollX + document.documentElement.clientWidth - tools.offsetWidth - 8));
      tools.style.top = top + 'px';
      tools.style.left = left + 'px';
    }
    place();
    window.addEventListener('scroll', place, { passive: true });
    window.addEventListener('resize', place);

    function onKey(ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        if (!document.execCommand('insertLineBreak')) document.execCommand('insertHTML', false, '<br>');
        state.dirty = true;
      } else if (ev.key === 'Escape') {
        ev.preventDefault();
        state.cancel();
      } else if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') {
        ev.preventDefault();
        save();
      }
    }
    // Pasted text arrives as plain text, never with another site's styling.
    function onPaste(ev) {
      ev.preventDefault();
      var text = (ev.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, text);
      state.dirty = true;
    }
    function onInput() { state.dirty = true; }

    el.addEventListener('keydown', onKey);
    el.addEventListener('paste', onPaste);
    el.addEventListener('input', onInput);

    function close() {
      el.removeAttribute('contenteditable');
      el.removeAttribute('spellcheck');
      el.classList.remove('cms-active');
      el.removeEventListener('keydown', onKey);
      el.removeEventListener('paste', onPaste);
      el.removeEventListener('input', onInput);
      window.removeEventListener('scroll', place);
      window.removeEventListener('resize', place);
      if (tools.parentNode) tools.parentNode.removeChild(tools);
      if (active === state) active = null;
    }

    function save() {
      var fields = { html: el.innerHTML };
      if (hrefInput) fields.href = hrefInput.value.trim();
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
      api('save', { page: C.page, key: key, fields: JSON.stringify(fields) }).then(function (res) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (!res.ok) {
          status(res.error || 'Could not save.', 'err');
          return;
        }
        if (res.fields.html !== undefined) el.innerHTML = res.fields.html;
        if (res.fields.href !== undefined) el.setAttribute('href', res.fields.href);
        remember(key, res.fields);
        el.classList.add('cms-edited');
        close();
        status('Saved. It is live on the website now.', 'ok');
      });
    }

    saveBtn.addEventListener('click', save);
    cancelBtn.addEventListener('click', function () { state.cancel(); });
    if (restoreBtn) {
      restoreBtn.addEventListener('click', function () {
        if (!confirm('Put the original text back on the website?')) return;
        restore(key, restoreBtn);
      });
    }

    el.focus();
    var range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function restore(key, btn) {
    if (btn) { btn.disabled = true; btn.textContent = 'Restoring…'; }
    api('restore', { page: C.page, key: key }).then(function (res) {
      if (!res.ok) {
        if (btn) { btn.disabled = false; btn.textContent = 'Restore original'; }
        status(res.error || 'Could not restore.', 'err');
        return;
      }
      if (active) active.dirty = false;
      reloadHere();
    });
  }

  /* ---------- Dialogs: images, icon links, page settings ---------- */

  function dialog(title, body, actions, wide) {
    var err = make('p', { className: 'cms-modal__err', role: 'alert' });
    var box = make('div', {
      className: 'cms-modal__box' + (wide ? ' cms-modal__box--wide' : ''),
      role: 'dialog', 'aria-modal': 'true', 'aria-label': title
    }, [
      make('h2', { text: title }),
      body,
      err,
      make('div', { className: 'cms-modal__actions' }, actions)
    ]);
    var wrap = make('div', { className: 'cms-modal cms-ui' }, [box]);
    document.body.appendChild(wrap);

    var state = {
      el: null, dirty: false, err: err,
      cancel: function () { shut(); }
    };
    active = state;

    function shut() {
      document.removeEventListener('keydown', onEsc);
      if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
      if (active === state) active = null;
    }
    function onEsc(ev) {
      if (ev.key === 'Escape' && (!state.dirty || confirm('Close without saving?'))) shut();
    }
    document.addEventListener('keydown', onEsc);
    wrap.addEventListener('mousedown', function (ev) {
      if (ev.target === wrap && (!state.dirty || confirm('Close without saving?'))) shut();
    });

    state.close = shut;
    var first = box.querySelector('input, textarea, select, button');
    if (first) first.focus();
    return state;
  }

  function field(labelText, input, hint) {
    return make('label', { className: 'cms-field' }, [
      make('span', { text: labelText }),
      input,
      hint ? make('small', { text: hint }) : null
    ]);
  }

  function buttons(key) {
    return {
      save: make('button', { type: 'button', className: 'cms-btn cms-btn--primary', text: 'Save' }),
      cancel: make('button', { type: 'button', className: 'cms-btn cms-btn--ghost', text: 'Cancel' }),
      restore: key && CONTENT[key] ? make('button', { type: 'button', className: 'cms-btn cms-btn--link', text: 'Restore original' }) : null
    };
  }

  function openImage(el) {
    var key = keyOf(el);
    var newSrc = null;

    var preview = make('img', { className: 'cms-modal__preview', src: el.currentSrc || el.src, alt: '' });
    var file = make('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif' });
    var alt = make('textarea', { rows: '3', className: 'cms-input' });
    alt.value = el.getAttribute('alt') || '';

    var b = buttons(key);
    var body = make('div', {}, [
      preview,
      field('Replace with a new image', file,
        'JPG, PNG, WebP or GIF, up to 8 MB. A picture the same shape as this one keeps the layout unchanged.'),
      field('Description (read aloud to blind visitors, and used by Google)', alt,
        el.getAttribute('alt') === '' ? 'This image is decorative, so this can stay empty.' : '')
    ]);
    var d = dialog('Change image', body, [b.restore, b.cancel, b.save]);

    file.addEventListener('change', function () {
      if (!file.files[0]) return;
      d.err.textContent = '';
      b.save.disabled = true;
      b.save.textContent = 'Uploading…';
      api('upload', { kind: 'image' }, file.files[0]).then(function (res) {
        b.save.disabled = false;
        b.save.textContent = 'Save';
        if (!res.ok) { d.err.textContent = res.error; file.value = ''; return; }
        newSrc = res.path;
        preview.src = res.path;
        d.dirty = true;
      });
    });
    alt.addEventListener('input', function () { d.dirty = true; });

    b.cancel.addEventListener('click', d.close);
    b.save.addEventListener('click', function () {
      var fields = { alt: alt.value };
      if (newSrc) fields.src = newSrc;
      b.save.disabled = true;
      b.save.textContent = 'Saving…';
      api('save', { page: C.page, key: key, fields: JSON.stringify(fields) }).then(function (res) {
        b.save.disabled = false;
        b.save.textContent = 'Save';
        if (!res.ok) { d.err.textContent = res.error; return; }
        if (res.fields.src) { el.setAttribute('src', res.fields.src); el.removeAttribute('srcset'); }
        if (res.fields.alt !== undefined) el.setAttribute('alt', res.fields.alt);
        remember(key, res.fields);
        el.classList.add('cms-edited');
        d.close();
        status('Image saved. It is live on the website now.', 'ok');
      });
    });
    if (b.restore) {
      b.restore.addEventListener('click', function () {
        if (confirm('Put the original image back on the website?')) restore(key, b.restore);
      });
    }
  }

  function openLink(el) {
    var key = keyOf(el);
    var href = make('input', { type: 'text', className: 'cms-input', value: el.getAttribute('href') || '' });
    var label = make('input', { type: 'text', className: 'cms-input', value: el.getAttribute('aria-label') || '', maxlength: '80' });

    var b = buttons(key);
    var d = dialog('Change link', make('div', {}, [
      field('Link address', href, 'For example https://www.instagram.com/yourpage. Leave "#" to link nowhere.'),
      field('Name (read aloud to blind visitors)', label)
    ]), [b.restore, b.cancel, b.save]);

    [href, label].forEach(function (i) { i.addEventListener('input', function () { d.dirty = true; }); });
    b.cancel.addEventListener('click', d.close);
    b.save.addEventListener('click', function () {
      b.save.disabled = true;
      api('save', {
        page: C.page, key: key,
        fields: JSON.stringify({ href: href.value.trim(), label: label.value })
      }).then(function (res) {
        b.save.disabled = false;
        if (!res.ok) { d.err.textContent = res.error; return; }
        if (res.fields.href !== undefined) el.setAttribute('href', res.fields.href);
        if (res.fields.label !== undefined) el.setAttribute('aria-label', res.fields.label);
        remember(key, res.fields);
        el.classList.add('cms-edited');
        d.close();
        status('Link saved. It is live on the website now.', 'ok');
      });
    });
    if (b.restore) {
      b.restore.addEventListener('click', function () {
        if (confirm('Put the original link back?')) restore(key, b.restore);
      });
    }
  }

  /* ---------- Sponsor logos: one list for the strip and the Sponsors grid ---------- */

  var STYLE_LABELS = { logo: 'Wide logo', mark: 'Square logo', org: 'Full tile (green)' };

  function openLogos() {
    if (C.page !== 'index' || !C.logos) return;
    if (active) {
      if (active.dirty && !confirm('You have an unsaved change. Discard it?')) return;
      active.cancel();
    }

    var items = JSON.parse(JSON.stringify(C.logos.logos || []));
    var list = make('div', { className: 'cms-logos' });
    var d;

    function touched() { if (d) d.dirty = true; }

    function row(item, i) {
      var name = make('input', { type: 'text', className: 'cms-input', maxlength: '120', placeholder: 'Company name' });
      name.value = item.name || '';
      name.addEventListener('input', function () { item.name = name.value; touched(); });

      var badge = make('input', { type: 'text', className: 'cms-input', maxlength: '40', placeholder: 'e.g. Title Sponsor' });
      badge.value = item.badge || '';
      badge.addEventListener('input', function () { item.badge = badge.value; touched(); });

      var style = make('select', { className: 'cms-input' });
      Object.keys(STYLE_LABELS).forEach(function (k) {
        var o = make('option', { value: k, text: STYLE_LABELS[k] });
        if (item.style === k) o.selected = true;
        style.appendChild(o);
      });
      style.addEventListener('change', function () { item.style = style.value; touched(); });

      function check(label, prop) {
        var box = make('input', { type: 'checkbox' });
        box.checked = !!item[prop];
        box.addEventListener('change', function () { item[prop] = box.checked; touched(); });
        return make('label', { className: 'cms-check' }, [box, make('span', { text: label })]);
      }

      function move(delta) {
        var j = i + delta;
        if (j < 0 || j >= items.length) return;
        items.splice(j, 0, items.splice(i, 1)[0]);
        touched();
        redraw();
      }

      var up = make('button', { type: 'button', className: 'cms-tool', title: 'Move up', 'aria-label': 'Move ' + (item.name || 'logo') + ' up', text: '↑' });
      var down = make('button', { type: 'button', className: 'cms-tool', title: 'Move down', 'aria-label': 'Move ' + (item.name || 'logo') + ' down', text: '↓' });
      var remove = make('button', { type: 'button', className: 'cms-btn cms-btn--link', text: 'Remove' });
      up.disabled = i === 0;
      down.disabled = i === items.length - 1;
      up.addEventListener('click', function () { move(-1); });
      down.addEventListener('click', function () { move(1); });
      remove.addEventListener('click', function () {
        if (!confirm('Remove ' + (item.name || 'this logo') + ' from the website?')) return;
        items.splice(i, 1);
        touched();
        redraw();
      });

      return make('div', { className: 'cms-logo' }, [
        make('div', { className: 'cms-logo__thumb' }, [make('img', { src: item.src, alt: '' })]),
        make('div', { className: 'cms-logo__fields' }, [
          make('label', { className: 'cms-field' }, [make('span', { text: 'Company name' }), name]),
          make('label', { className: 'cms-field' }, [make('span', { text: 'Label on the tile' }), badge]),
          make('label', { className: 'cms-field' }, [make('span', { text: 'Shape' }), style]),
          make('div', { className: 'cms-logo__where' }, [
            check('Show in the scrolling strip', 'slider'),
            check('Show in Sponsors & Partners', 'section')
          ])
        ]),
        make('div', { className: 'cms-logo__order' }, [up, down, remove])
      ]);
    }

    function redraw() {
      list.innerHTML = '';
      items.forEach(function (item, i) { list.appendChild(row(item, i)); });
      if (!items.length) list.appendChild(make('p', { className: 'cms-logos__empty', text: 'No logos yet. Add one below.' }));
    }
    redraw();

    var file = make('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp' });
    file.addEventListener('change', function () {
      var f = file.files[0];
      if (!f) return;
      d.err.textContent = '';
      addHint.textContent = 'Uploading…';
      api('upload', { kind: 'image' }, f).then(function (res) {
        file.value = '';
        if (!res.ok) { addHint.textContent = ''; d.err.textContent = res.error; return; }
        // Pick the tile shape from the picture itself: tall or square artwork
        // needs the larger square tile to read at the same weight as a wordmark.
        var probe = new Image();
        probe.onload = function () {
          var ratio = probe.naturalWidth / Math.max(1, probe.naturalHeight);
          add(ratio < 1.6 ? 'mark' : 'logo');
        };
        probe.onerror = function () { add('logo'); };
        probe.src = res.path;

        function add(shape) {
          items.push({ src: res.path, name: '', badge: 'Sponsor', style: shape, slider: true, section: true });
          touched();
          redraw();
          addHint.textContent = 'Added at the end. Type the company name, then Save.';
          var inputs = list.querySelectorAll('.cms-logo:last-child input[type="text"]');
          if (inputs[0]) inputs[0].focus();
        }
      });
    });
    var addHint = make('small', { className: 'cms-logos__hint', role: 'status' });

    var placeholders = make('input', { type: 'number', className: 'cms-input cms-input--short', min: '0', max: '8' });
    placeholders.value = String(C.logos.placeholders || 0);
    placeholders.addEventListener('input', touched);

    var b = {
      save: make('button', { type: 'button', className: 'cms-btn cms-btn--primary', text: 'Save' }),
      cancel: make('button', { type: 'button', className: 'cms-btn cms-btn--ghost', text: 'Cancel' }),
      restore: C.logos.custom ? make('button', { type: 'button', className: 'cms-btn cms-btn--link', text: 'Restore original logos' }) : null
    };

    d = dialog('Sponsor & partner logos', make('div', {}, [
      make('p', { className: 'cms-modal__intro', text: 'One list for the scrolling logo strip under the hero and the Sponsors & Partners section. Use the arrows to change the order.' }),
      list,
      make('div', { className: 'cms-logos__add' }, [
        make('label', { className: 'cms-field' }, [
          make('span', { text: 'Add a logo' }),
          file,
          make('small', { text: 'PNG with a transparent background works best. Up to 8 MB.' })
        ]),
        addHint
      ]),
      make('label', { className: 'cms-field cms-field--inline' }, [
        make('span', { text: 'Empty "Sponsor Logo" tiles to show before the logos' }),
        placeholders
      ])
    ]), [b.restore, b.cancel, b.save], true);

    b.cancel.addEventListener('click', d.close);

    b.save.addEventListener('click', function () {
      d.err.textContent = '';
      for (var i = 0; i < items.length; i++) {
        if (!String(items[i].name || '').trim()) {
          d.err.textContent = 'Give every logo a company name.';
          return;
        }
      }
      b.save.disabled = true;
      b.save.textContent = 'Saving…';
      api('save_logos', {
        logos: JSON.stringify(items),
        placeholders: String(Math.max(0, Math.min(8, parseInt(placeholders.value, 10) || 0)))
      }).then(function (res) {
        b.save.disabled = false;
        b.save.textContent = 'Save';
        if (!res.ok) { d.err.textContent = res.error; return; }
        C.logos = { logos: res.logos, placeholders: res.placeholders, custom: true };
        if (window.SITE_RENDER_LOGOS) window.SITE_RENDER_LOGOS(res.logos, res.placeholders);
        d.close();
        status('Sponsor logos saved. They are live on the website now.', 'ok');
      });
    });

    if (b.restore) {
      b.restore.addEventListener('click', function () {
        if (!confirm('Put the original sponsor logos back on the website?')) return;
        b.restore.disabled = true;
        api('reset_logos', {}).then(function (res) {
          if (!res.ok) { b.restore.disabled = false; d.err.textContent = res.error; return; }
          d.dirty = false;
          reloadHere();
        });
      });
    }
  }

  function openPageSettings() {
    if (active) {
      if (active.dirty && !confirm('You have an unsaved change. Discard it?')) return;
      active.cancel();
    }
    var meta = document.querySelector('meta[name="description"]');

    var title = make('input', { type: 'text', className: 'cms-input', maxlength: '120' });
    title.value = document.title;
    var desc = make('textarea', { rows: '4', className: 'cms-input', maxlength: '300' });
    desc.value = meta ? meta.getAttribute('content') || '' : '';

    var titleCount = make('small', {});
    var descCount = make('small', {});
    function counts() {
      titleCount.textContent = title.value.length + ' characters. Google shows about 60.';
      descCount.textContent = desc.value.length + ' characters. Google shows about 155.';
    }
    counts();

    var b = buttons(null);
    var d = dialog('Page title & description', make('div', {}, [
      make('p', { className: 'cms-modal__intro', text: 'What Google and social media show for this page. Visitors see the title on the browser tab.' }),
      make('label', { className: 'cms-field' }, [make('span', { text: 'Title' }), title, titleCount]),
      make('label', { className: 'cms-field' }, [make('span', { text: 'Description' }), desc, descCount])
    ]), [b.cancel, b.save]);

    [title, desc].forEach(function (i) {
      i.addEventListener('input', function () { d.dirty = true; counts(); });
    });
    b.cancel.addEventListener('click', d.close);
    b.save.addEventListener('click', function () {
      b.save.disabled = true;
      b.save.textContent = 'Saving…';
      api('save', { page: C.page, key: 'meta.title', fields: JSON.stringify({ html: title.value }) })
        .then(function (r1) {
          if (!r1.ok) return r1;
          document.title = r1.fields.html;
          remember('meta.title', r1.fields);
          return api('save', { page: C.page, key: 'meta.description', fields: JSON.stringify({ content: desc.value }) });
        })
        .then(function (r2) {
          b.save.disabled = false;
          b.save.textContent = 'Save';
          if (!r2.ok) { d.err.textContent = r2.error; return; }
          if (r2.fields && r2.fields.content !== undefined) {
            if (meta) meta.setAttribute('content', r2.fields.content);
            remember('meta.description', r2.fields);
          }
          d.close();
          status('Title and description saved.', 'ok');
        });
    });
  }
})();
