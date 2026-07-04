/**
 * Contact form — EmailJS (client) + optional server log via api/contact.php
 */
(function () {
  const form = document.getElementById('contact-form');
  if (!form || form.dataset.emailjs !== '1') return;

  const configEl = document.getElementById('emailjs-config');
  if (!configEl) return;

  let config;
  try {
    config = JSON.parse(configEl.textContent);
  } catch {
    return;
  }
  if (!config.enabled) return;

  emailjs.init({ publicKey: config.publicKey });

  const alertBox = document.getElementById('contact-alert');
  const errorBox = document.getElementById('contact-form-error');
  const submitBtn = document.getElementById('contact-submit');
  const apiUrl = form.dataset.apiUrl || '';

  function showError(msg) {
    if (!errorBox) return;
    errorBox.textContent = msg;
    errorBox.style.display = 'block';
  }

  function hideError() {
    if (errorBox) errorBox.style.display = 'none';
  }

  function showSuccess() {
    if (alertBox) alertBox.style.display = 'flex';
    form.reset();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function logToServer(payload) {
    if (!apiUrl) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    try {
      await fetch(apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
        },
        body: JSON.stringify({ ...payload, _csrf: csrf }),
      });
    } catch {
      /* notification log is best-effort */
    }
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideError();

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    const payload = {
      name: form.name.value.trim(),
      email: form.email.value.trim(),
      subject: form.subject.value.trim(),
      message: form.message.value.trim(),
    };

    const templateParams = {
      from_name: payload.name,
      from_email: payload.email,
      reply_to: payload.email,
      subject: payload.subject,
      message: payload.message,
      to_name: 'Swapin Support',
    };

    submitBtn.disabled = true;
    const origHtml = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> در حال ارسال…';

    try {
      await emailjs.send(config.serviceId, config.templateId, templateParams);
      await logToServer(payload);
      showSuccess();
    } catch (err) {
      if (apiUrl) {
        try {
          const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
          const res = await fetch(apiUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
            },
            body: JSON.stringify({ ...payload, _csrf: csrf }),
          });
          const data = await res.json();
          if (data.ok && data.mail_sent) {
            showSuccess();
            return;
          }
          if (data.ok && !data.mail_sent) {
            showError(data.message || 'ارسال ایمیل ناموفق بود. لطفاً بعداً دوباره تلاش کنید یا به info@swaapin.ir بنویسید.');
            return;
          }
        } catch {
          /* fall through */
        }
      }
      showError('ارسال ایمیل ناموفق بود. لطفاً بعداً دوباره تلاش کنید یا به info@swaapin.ir بنویسید.');
    } finally {
      submitBtn.disabled = false;
      submitBtn.innerHTML = origHtml;
    }
  });
})();
