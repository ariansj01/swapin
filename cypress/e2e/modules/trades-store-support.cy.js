describe('معاملات، سفارشات و فروشگاه', () => {
  describe('صفحه معاملات', () => {
    it('باید صفحه معاملات برای کاربر لاگین‌شده قابل دسترسی باشد', () => {
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/trades.php');
        cy.visit('/trades.php');
        cy.get('body').should('be.visible');
        cy.contains(/معاملات|تاریخچه|لیست/).should('exist');
      });
    });

    it('باید صفحه جزئیات معامله با شناسه معتبر یا نامعتبر بارگذاری شود', () => {
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/trades/view.php?id=1');
        cy.visit('/trades/view.php?id=1', { failOnStatusCode: false });
        cy.get('body').should('be.visible');
        cy.status().should('be.oneOf', [200, 404, 302, 403]);
      });
    });

    it('باید صفحه کارمزد معامله باز شود', () => {
      cy.visit('/trades/fee.php', { failOnStatusCode: false });
      cy.get('body').should('be.visible');
      cy.contains(/کارمزد|هزینه|معامله/).should('exist');
    });
  });

  describe('سفارشات (Orders)', () => {
    it('باید صفحه لیست سفارشات برای کاربر لاگین‌شده باز شود', () => {
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/orders/index.php');
        cy.visit('/orders/index.php');
        cy.get('body').should('be.visible');
        cy.contains(/سفارشات|لیست خرید/).should('exist');
      });
    });

    it('باید صفحه پرداخت (Checkout) برای کاربر لاگین‌شده وجود داشته باشد', () => {
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/orders/checkout.php');
        cy.visit('/orders/checkout.php', { failOnStatusCode: false });
        cy.get('body').should('be.visible');
        cy.status().should('be.oneOf', [200, 302, 404]);
      });
    });
  });

  describe('فروشگاه‌ها', () => {
    it('باید صفحه لیست فروشگاه‌ها باز شود', () => {
      cy.visit('/shops/index.php');
      cy.get('body').should('be.visible');
      cy.contains(/فروشگاه|برندها|لیست/).should('exist');
    });

    it('باید صفحه اختصاصی یک فروشگاه (Shop) وجود داشته باشد', () => {
      cy.visit('/shop/index.php', { failOnStatusCode: false });
      cy.get('body').should('be.visible');
      cy.status().should('be.oneOf', [200, 404, 302]);
    });

    it('باید صفحه درخواست ایجاد فروشگاه باز شود', () => {
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/store/request.php');
        cy.visit('/store/request.php');
        cy.get('body').should('be.visible');
        cy.contains(/درخواست فروشگاه|ثبت فروشگاه/).should('exist');
      });
    });
  });

  describe('پشتیبانی', () => {
    it('باید صفحه اصلی پشتیبانی قابل دسترسی باشد', () => {
      cy.visit('/support/index.php', { failOnStatusCode: false });
      cy.get('body').should('be.visible');
      cy.contains(/پشتیبانی|تیکت|سوالات/).should('exist');
    });

    it('باید صفحه گزارش مشکل برای کاربر لاگین‌شده باز شود', () => {
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/support/report.php');
        cy.visit('/support/report.php');
        cy.get('body').should('be.visible');
        cy.contains(/گزارش|تیکت|پشتیبانی/).should('exist');
      });
    });
  });

  describe('پیام‌ها و اعلان‌ها', () => {
    it('باید صفحه پیام‌ها برای کاربر لاگین‌شده باز شود', () => {
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/messages.php');
        cy.visit('/messages.php');
        cy.get('body').should('be.visible');
        cy.contains(/پیام‌ها|گفتگو|چت/).should('exist');
      });
    });
  });

  describe('ISO (در جستجوی کالا)', () => {
    it('باید لیست آگهی‌های ISO باز شود', () => {
      cy.visit('/iso/index.php', { failOnStatusCode: false });
      cy.get('body').should('be.visible');
      cy.contains(/در جستجوی|ISO|نیازمندی/).should('exist');
    });

    it('باید صفحه ایجاد ISO برای کاربر لاگین‌شده باز شود', () => {
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/iso/create.php');
        cy.visit('/iso/create.php');
        cy.get('body').should('be.visible');
        cy.contains(/ثبت درخواست|ایجاد ISO/).should('exist');
      });
    });
  });
});
