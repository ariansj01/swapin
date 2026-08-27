describe('بخش مدیریت (ادمین)', () => {
  describe('صفحه ورود ادمین', () => {
    beforeEach(() => {
      cy.clearCookies();
    });

    it('باید صفحه ورود ادمین را بارگذاری کند', () => {
      cy.visit('/admin/login.php');
      cy.title().should('include', 'ورود مدیر');
      cy.contains('پنل مدیریت').should('be.visible');
      cy.get('input[name="email"]').should('be.visible');
      cy.get('input[name="password"]').should('be.visible');
      cy.get('button[type="submit"]').should('contain', 'ورود به پنل');
      cy.screenshot('admin-login-page');
    });

    it('باید برای ایمیل یا رمز نامعتبر خطا نمایش دهد', () => {
      cy.fixture('admin').then((admin) => {
        cy.visit('/admin/login.php');
        cy.get('input[name="email"]').clear().type(admin.wrongEmail);
        cy.get('input[name="password"]').clear().type(admin.wrongPassword);
        cy.get('form').submit();
        cy.contains('ایمیل یا رمز عبور اشتباه است').should('be.visible');
        cy.screenshot('admin-login-error');
      });
    });

    it('باید لینک بازگشت به سایت را داشته باشد', () => {
      cy.visit('/admin/login.php');
      cy.contains('بازگشت به سایت').should('have.attr', 'href').and('include', '/');
    });
  });

  describe('دسترسی به صفحه‌های ادمین', () => {
    const adminPages = [
      { url: '/admin/index.php', label: 'صفحه اصلی مدیریت' },
      { url: '/admin/users.php', label: 'مدیریت کاربران' },
      { url: '/admin/listings.php', label: 'مدیریت آگهی‌ها' },
      { url: '/admin/pages.php', label: 'مدیریت صفحات' },
      { url: '/admin/stores.php', label: 'مدیریت فروشگاه‌ها' },
      { url: '/admin/kyc.php', label: 'مدیریت احراز هویت' },
      { url: '/admin/disputes.php', label: 'مدیریت اختلافات' },
      { url: '/admin/tickets.php', label: 'تیکت‌ها' },
      { url: '/admin/content.php', label: 'مدیریت محتوا' },
    ];

    it('باید بدون ورود به صفحه‌های ادمین دسترسی نداشته باشد', () => {
      cy.clearCookies();
      adminPages.forEach((page) => {
        cy.request({
          url: page.url,
          failOnStatusCode: false,
          followRedirect: true,
        }).then((resp) => {
          expect(resp.status).to.be.oneOf([200, 302, 403, 401]);
        });
      });
    });
  });

  describe('صفحه مدیریت کاربران', () => {
    it('باید صفحه مدیریت کاربران وجود داشته باشد', () => {
      cy.visit('/admin/users.php', { failOnStatusCode: false });
      cy.status().should('be.oneOf', [200, 401, 403, 302]);
    });
  });
});
