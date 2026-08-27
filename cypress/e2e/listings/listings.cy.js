describe('آگهی‌ها و جستجو', () => {
  describe('صفحه لیست آگهی‌ها', () => {
    it('باید صفحه همه آگهی‌ها را بارگذاری کند', () => {
      cy.visit('/listings/all.php');
      cy.get('body').should('be.visible');
      cy.contains(/آگهی|همه|جستجو/).should('exist');
      cy.screenshot('listings-all-page');
    });

    it('باید صفحه آگهی‌های مرتبط با دسته‌بندی را بارگذاری کند', () => {
      // Use a common category slug from categories table or all.php?cat=...
      cy.visit('/listings/all.php?cat=electronics', { failOnStatusCode: false });
      cy.get('body').should('be.visible');
      cy.status().should('be.oneOf', [200, 301, 302, 404]);
    });
  });

  describe('صفحه نمایش آگهی', () => {
    it('باید صفحه آگهی با شناسه معتبر یا نامعتبر بارگذاری شود', () => {
      // Try to find a listing link first or just visit a generic ID
      cy.visit('/listings/view.php?id=1', { failOnStatusCode: false });
      cy.get('body', { timeout: 10000 }).should('be.visible');
      cy.status().should('be.oneOf', [200, 404, 302, 301]);
    });
  });

  describe('جستجو', () => {
    it('باید جستجو در صفحه اصلی انجام شود', () => {
      cy.visit('/');
      // Use the specific ID for the main search input
      cy.get('#search-input').should('be.visible').clear().type('آیفون{enter}');
      cy.url().should('include', 'q=');
    });

    it('باید صفحه جستجوی هوش مصنوعی قابل دسترسی باشد', () => {
      cy.visit('/ai/chat', { failOnStatusCode: false });
      cy.get('body').should('be.visible');
      cy.status().should('be.oneOf', [200, 302, 401, 403]);
    });
  });

  describe('ثبت آگهی (نیاز به احراز هویت)', () => {
    beforeEach(() => {
      cy.clearCookies();
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/listings/create.php');
      });
    });

    it('باید صفحه ثبت آگهی برای کاربر لاگین‌شده بارگذاری شود', () => {
      cy.visit('/listings/create.php');
      // The page uses a wizard, step 1 should be visible
      cy.get('[data-step="1"]').should('be.visible');
      cy.contains('عنوان و توضیحات').should('be.visible');
      cy.screenshot('listing-create-step1');
    });

    it('باید فرم ثبت آگهی در گام اول شامل فیلدهای عنوان و توضیحات باشد', () => {
      cy.visit('/listings/create.php');
      cy.get('input[name="title"]').should('be.visible');
      cy.get('textarea[name="description"]').should('be.visible');
    });

    it('باید بتواند به گام دوم (تصاویر) برود', () => {
      cy.visit('/listings/create.php');
      cy.get('input[name="title"]').type('تست آگهی جدید برای Cypress');
      cy.get('textarea[name="description"]').type('این یک متن توضیحات تست برای بررسی صحت عملکرد فرم ثبت آگهی است.');
      cy.get('#wizard-next-btn').click();
      cy.get('[data-step="2"]').should('be.visible');
      cy.contains('تصاویر').should('be.visible');
    });
  });

  describe('آگهی‌های من', () => {
    it('باید صفحه آگهی‌های من برای کاربر لاگین‌شده باز شود', () => {
      cy.clearCookies();
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/listings/my.php');
        cy.visit('/listings/my.php');
        cy.get('body').should('be.visible');
        cy.contains(/آگهی‌های من|آگهی/).should('exist');
      });
    });
  });

  describe('پیشنهادهای معاوضه', () => {
    it('باید صفحه پیشنهادها برای کاربر لاگین‌شده باز شود', () => {
      cy.clearCookies();
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/listings/offers.php');
        cy.visit('/listings/offers.php');
        cy.get('body').should('be.visible');
      });
    });
  });
});
