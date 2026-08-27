Cypress.Commands.add('loginByPhone', (phone, redirect = '/') => {
  const phoneClean = phone.replace(/\D/g, '');
  cy.visit(`/auth/login.php?redirect=${encodeURIComponent(redirect)}`);

  cy.get('input[name="phone"]').should('be.visible').clear().type(phoneClean);
  cy.get('form#phoneForm').submit();

  cy.url().should('include', 'step=otp');

  const otpInputs = cy.get('.otp-group__digit');
  const digits = (Cypress.env('testOTPCode') || '123456').split('');
  
  // Type each digit into its corresponding input to handle the custom OTP UI
  otpInputs.each((input, index) => {
    cy.wrap(input).type(digits[index], { force: true });
  });

  cy.get('form#otpForm').submit();
  cy.url().should('not.include', '/auth/login');
});

Cypress.Commands.add('loginAsAdmin', (email, password) => {
  const adminEmail = email || Cypress.env('testAdminEmail');
  const adminPass = password || Cypress.env('testAdminPassword');

  cy.visit('/admin/login.php');

  cy.get('input[name="email"]').should('be.visible').clear().type(adminEmail);
  cy.get('input[name="password"]').should('be.visible').clear().type(adminPass);

  cy.get('form').submit();

  cy.url().should('include', '/admin/');
});

Cypress.Commands.add('logout', () => {
  // Check if we are logged in by looking for a common element or just try to logout
  cy.window().then((win) => {
    const csrfMeta = win.document.querySelector('meta[name="csrf-token"]');
    if (!csrfMeta) return; // Can't logout without CSRF

    const form = win.document.createElement('form');
    form.method = 'POST';
    form.action = Cypress.config('baseUrl') + '/auth/logout.php';

    const csrfToken = csrfMeta.getAttribute('content');
    const csrfInput = win.document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = csrfToken;
    form.appendChild(csrfInput);

    win.document.body.appendChild(form);
    form.submit();
  });

  // After logout, we should be on the home page
  cy.url().should('satisfy', (url) => {
    return url === Cypress.config('baseUrl') + '/' || url === Cypress.config('baseUrl');
  });
});

Cypress.Commands.add('visitAuthenticated', (url, phone) => {
  cy.loginByPhone(phone || Cypress.env('testUserPhone'), url);
  cy.visit(url);
});

Cypress.Commands.add('extractCSRF', () => {
  cy.get('meta[name="csrf-token"]').then(($meta) => {
    return $meta.attr('content');
  });
});

Cypress.Commands.add('checkPageAccess', (url, { requireAuth = false, requireAdmin = false, shouldContain = [] } = {}) => {
  if (requireAdmin) {
    cy.loginAsAdmin();
  } else if (requireAuth) {
    cy.loginByPhone();
  }

  cy.visit(url);
  cy.get('body').should('be.visible');
  shouldContain.forEach((text) => {
    cy.contains(text).should('exist');
  });
});

Cypress.Commands.add('verifyLink', (selector) => {
  cy.get(selector).first().then(($link) => {
    const href = $link.attr('href');
    if (href && href.startsWith('/')) {
      cy.request({
        url: href,
        failOnStatusCode: false,
        followRedirect: true,
      }).then((resp) => {
        expect(resp.status).to.be.oneOf([200, 301, 302, 401, 403]);
      });
    }
  });
});

Cypress.Commands.add('persistSession', (callback) => {
  const sessionName = 'swaapin-test-session';
  cy.session(sessionName, callback, {
    validate() {
      cy.getCookie('PHPSESSID').should('exist');
    },
    cacheAcrossSpecs: true,
  });
});

Cypress.Commands.add('status', { prevSubject: 'optional' }, (subject, expectedStatus) => {
  const url = subject && (subject.href || (typeof subject === 'string' ? subject : undefined))
    || cy.state('window').location.href;

  return cy.request({
    url,
    failOnStatusCode: false,
    followRedirect: true,
  }).then((resp) => {
    if (expectedStatus !== undefined) {
      expect(resp.status).to.be.oneOf(
        Array.isArray(expectedStatus) ? expectedStatus : [expectedStatus]
      );
    }
    return resp.status;
  });
});

Cypress.Commands.add('checkStatus', (url, expected = [200, 301, 302, 401, 403, 404]) => {
  return cy.request({
    url,
    failOnStatusCode: false,
    followRedirect: true,
  }).its('status').should('be.oneOf', expected);
});
