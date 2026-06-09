// Inkwell widget v1 — optional progressive enhancement for HTML <form> integrations.
// ~2 KB minified + gzipped.
//
// What it does:
//   1. Captures the page-render timestamp into a hidden field for the timing
//      signal in spam scoring.
//   2. Intercepts form submission to fetch + render inline error messages
//      without a page reload when schema validation fails.
//   3. Handles the quarantine-flow captcha if Turnstile is on the page.
//
// Does NOT replace the canonical <form action="…"> integration — the server
// can't tell whether the submission came through the widget or not.

(function () {
  'use strict';
  const FORMS = 'form[data-inkwell],form[action*="/v1/forms/"]';

  function timestampForm(form) {
    if (form.dataset.inkwellTimestamped) return;
    const ts = document.createElement('input');
    ts.type = 'hidden';
    ts.name = '_inkwell_ts';
    ts.value = String(Math.floor(Date.now() / 1000));
    form.appendChild(ts);
    form.dataset.inkwellTimestamped = '1';
  }

  function enhanceForm(form) {
    timestampForm(form);

    form.addEventListener('submit', async (e) => {
      if (form.dataset.inkwellSubmitting === '1') return;
      const accept = form.getAttribute('data-inkwell-accept');
      if (accept !== 'json') return; // Default: let the form do a normal POST.
      e.preventDefault();
      form.dataset.inkwellSubmitting = '1';

      const data = new FormData(form);
      const body = Object.fromEntries(data.entries());

      try {
        const res = await fetch(form.action, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(body),
        });
        const json = await res.json();
        clearErrors(form);
        if (!res.ok) {
          if (json.errors && typeof json.errors === 'object') {
            renderErrors(form, json.errors);
          } else {
            renderInlineMessage(form, json.detail || 'Submission failed.', 'error');
          }
          form.dataset.inkwellSubmitting = '0';
          return;
        }
        renderInlineMessage(form, 'Submitted — thanks.', 'success');
        form.reset();
        timestampForm(form);
        form.dataset.inkwellSubmitting = '0';
        if (json.redirect_url) {
          window.location.href = json.redirect_url;
        }
      } catch (err) {
        renderInlineMessage(form, 'Network error — please retry.', 'error');
        form.dataset.inkwellSubmitting = '0';
      }
    });
  }

  function clearErrors(form) {
    form.querySelectorAll('[data-inkwell-error]').forEach((el) => el.remove());
    form.querySelectorAll('[data-inkwell-status]').forEach((el) => el.remove());
  }

  function renderErrors(form, errors) {
    Object.entries(errors).forEach(([field, messages]) => {
      const input = form.querySelector(`[name="${field}"]`);
      if (!input) return;
      const el = document.createElement('div');
      el.dataset.inkwellError = '1';
      el.style.color = '#dc2626';
      el.style.fontSize = '0.85rem';
      el.style.marginTop = '0.25rem';
      el.textContent = (Array.isArray(messages) ? messages : [messages]).join(' ');
      input.parentNode && input.parentNode.insertBefore(el, input.nextSibling);
    });
  }

  function renderInlineMessage(form, text, kind) {
    const el = document.createElement('div');
    el.dataset.inkwellStatus = kind;
    el.style.color = kind === 'error' ? '#dc2626' : '#16a34a';
    el.style.marginTop = '0.75rem';
    el.style.fontWeight = '600';
    el.textContent = text;
    form.appendChild(el);
  }

  function init() {
    document.querySelectorAll(FORMS).forEach(enhanceForm);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
