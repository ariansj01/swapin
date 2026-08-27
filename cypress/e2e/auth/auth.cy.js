describe('Authentication — احراز هویت کاربران', () => {
  describe('صفحه ورود / ثبت‌نام', () => {
    beforeEach(() => {
      cy.clearCookies();
      cy.clearLocalStorage();
    });

    it('باید صفحه ورود را با موفقیت بارگذاری کند', () => {
      cy.visit('/auth/login.php');
      cy.title().should('include', 'ورود / ثبت‌نام');
      cy.contains('ورود / ثبت‌نام').should('be.visible');
      cy.contains('شماره تلفن خود را وارد کنید').should('be.visible');
      cy.get('input[name="phone"]').should('be.visible');
      cy.get('button#sendBtn').should('contain', 'ارسال کد');
      cy.screenshot('auth-login-page');
    });

    it('باید برای شماره نامعتبر خطا نمایش دهد', () => {
      cy.fixture('users').then((users) => {
        cy.visit('/auth/login.php');
        cy.get('input[name="phone"]').clear().type(users.invalidPhone);
        cy.get('form#phoneForm').submit();
        cy.contains('شماره تلفن معتبر').should('be.visible');
        cy.screenshot('auth-invalid-phone-error');
      });
    });

    it('باید برای شماره معتبر به مرحله OTP برود', () => {
      cy.fixture('users').then((users) => {
        cy.visit('/auth/login.php');
        cy.get('input[name="phone"]').clear().type(users.phone);
        cy.get('form#phoneForm').submit();
        cy.url().should('include', 'step=otp');
        cy.url().should('include', 'phone=');
        cy.contains('کد تأیید را وارد کنید').should('be.visible');
        cy.get('.otp-group__digit').should('have.length', 6);
        cy.contains('تأیید و ادامه').should('be.visible');
        cy.screenshot('auth-otp-page');
      });
    });

    it('باید از صفحه OTP بتواند به مرحله شماره تلفن بازگشت', () => {
      cy.fixture('users').then((users) => {
        cy.visit('/auth/login.php');
        cy.get('input[name="phone"]').clear().type(users.phone);
        cy.get('form#phoneForm').submit();
        cy.url().should('include', 'step=otp');
        // Wait for timer to finish (max 5s) and click
        cy.get('#resendLink', { timeout: 10000 }).should('not.contain', '(').click();
        cy.url().should('include', 'step=phone');
        cy.contains('شماره تلفن خود را وارد کنید').should('be.visible');
      });
    });

    it('باید کاربر را با OTP صحیح وارد کند یا ثبت کند', () => {
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone);
        cy.url().should('satisfy', (url) => 
          url === Cypress.config('baseUrl') + '/' || 
          url === Cypress.config('baseUrl') ||
          url.includes('welcome=1') || 
          url.includes('/dashboard')
        );
        cy.screenshot('auth-successful-login');
      });
    });

    it('باید کاربر وارد شده به صفحه ورود دسترسی نداشته باشد', () => {
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone);
        cy.visit('/auth/login.php');
        cy.url().should('satisfy', (url) => 
          url === Cypress.config('baseUrl') + '/' || url === Cypress.config('baseUrl')
        );
      });
    });
  });

  describe('خروج از حساب', () => {
    it('باید کاربر را با موفقیت خارج کند', () => {
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone);
        cy.url().should('not.include', '/auth/login');
        cy.logout();
        cy.url().should('satisfy', (url) => 
          url === Cypress.config('baseUrl') + '/' || url === Cypress.config('baseUrl')
        );
        cy.screenshot('auth-after-logout');
      });
    });
  });

  describe('بازگردانی (Redirect) پس از ورود', () => {
    it('باید پس از ورود به صفحه درخواستی هدایت کند', () => {
      cy.visit('/auth/login.php?redirect=%2Fdashboard.php');
      cy.fixture('users').then((users) => {
        cy.get('input[name="phone"]').clear().type(users.phone);
        cy.get('form#phoneForm').submit();
        cy.url().should('include', 'step=otp');
        
        // Use the common digit typing logic
        const otpInputs = cy.get('.otp-group__digit');
        const digits = (Cypress.env('testOTPCode') || '123456').split('');
        otpInputs.each((input, index) => {
          cy.wrap(input).type(digits[index], { force: true });
        });
        
        cy.get('form#otpForm').submit();
        cy.url().should('include', '/dashboard.php');
      });
    });
  });

  describe('دسترسی بدون احراز هویت', () => {
    it('باید به داشبورد بدون ورود دسترسی نداشته باشد', () => {
      cy.clearCookies();
      cy.visit('/dashboard.php');
      cy.url().should('include', 'auth/login.php');
    });

    it('باید به ایجاد آگهی بدون ورود دسترسی نداشته باشد', () => {
      cy.clearCookies();
      cy.visit('/listings/create.php');
      cy.url().should('include', 'auth/login.php');
    });
  });
});
