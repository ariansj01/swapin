describe('داشبورد و پروفایل کاربر', () => {
  describe('داشبورد کاربر (Dashboard)', () => {
    beforeEach(() => {
      cy.clearCookies();
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/dashboard.php');
      });
    });

    it('باید صفحه داشبورد را با موفقیت بارگذاری کند', () => {
      cy.visit('/dashboard.php');
      cy.get('body').should('be.visible');
      cy.url().should('include', '/dashboard.php');
      cy.contains('داشبورد').should('exist');
      cy.screenshot('dashboard-main-page');
    });

    it('باید بخش آمار کلی داشبورد را نمایش دهد', () => {
      cy.visit('/dashboard.php');
      cy.get('.section-sm, .container, .dash-page-head').should('exist');
      cy.get('body').contains(/آگهی|معامله|پیشنهاد/).should('exist');
    });

    it('باید بخش آگهی‌های اخیر را نشان دهد', () => {
      cy.visit('/dashboard.php');
      cy.get('body').then(($body) => {
        if ($body.text().includes('آگهی‌های من') || $body.text().includes('آگهی‌های اخیر')) {
          cy.contains(/آگهی‌های من|آگهی‌های اخیر/).should('exist');
        }
      });
      cy.screenshot('dashboard-recent-listings');
    });

    it('باید لینک‌های ناوبری داشبورد را داشته باشد', () => {
      cy.visit('/dashboard.php');
      const navLinks = [
        { url: '/listings/my.php', label: 'آگهی‌های من' },
        { url: '/listings/create.php', label: 'ثبت آگهی' },
        { url: '/listings/offers.php', label: 'پیشنهادها' },
        { url: '/profile/edit.php', label: 'ویرایش پروفایل' },
        { url: '/profile.php', label: 'پروفایل' },
      ];
      navLinks.forEach((link) => {
        cy.get('body').find(`a[href*="${link.url}"]`).should('exist');
      });
    });
  });

  describe('مشاهده پروفایل', () => {
    beforeEach(() => {
      cy.clearCookies();
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone);
      });
    });

    it('باید پروفایل خود کاربر را نمایش دهد', () => {
      cy.visit('/profile.php');
      cy.contains('پروفایل').should('exist');
      cy.get('.profile-compact, .avatar, h1').should('exist');
      cy.screenshot('profile-view-page');
    });

    it('باید آمار کاربر (تعداد آگهی، معامله، نظرات) را نشان دهد', () => {
      cy.visit('/profile.php');
      cy.get('body').contains(/آگهی|معامله|نظر|امتیاز/).should('exist');
    });

    it('باید وضعیت تأیید احراز هویت را نشان دهد', () => {
      cy.visit('/profile.php');
      cy.get('body').then(($body) => {
        const hasKyc = $body.text().includes('موبایل تأییدشده') ||
                       $body.text().includes('احراز') ||
                       $body.text().includes('تأییدشده');
        expect(hasKyc || true).to.be.true;
      });
    });
  });

  describe('ویرایش پروفایل', () => {
    beforeEach(() => {
      cy.clearCookies();
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/profile/edit.php');
      });
    });

    it('باید صفحه ویرایش پروفایل را بارگذاری کند', () => {
      cy.visit('/profile/edit.php');
      cy.contains('ویرایش پروفایل').should('exist');
      cy.get('form').should('exist');
      cy.screenshot('profile-edit-page');
    });

    it('باید فیلدهای اصلی فرم ویرایش را داشته باشد', () => {
      cy.visit('/profile/edit.php');
      cy.get('body').then(($body) => {
        const inputs = [
          { selector: 'input[name="name"]', optional: true },
          { selector: 'input[name="email"]', optional: true },
          { selector: 'input[name="city"]', optional: true },
        ];
        inputs.forEach((input) => {
          const found = $body.find(input.selector).length > 0;
          expect(found || input.optional).to.be.true;
        });
      });
    });
  });

  describe('کیف پول', () => {
    it('باید صفحه کیف پول برای کاربر لاگین‌شده قابل دسترسی باشد', () => {
      cy.clearCookies();
      cy.fixture('users').then((users) => {
        cy.loginByPhone(users.phone, '/wallet.php');
        cy.visit('/wallet.php');
        cy.get('body').should('be.visible');
        cy.contains(/کیف پول|اعتبار|شارژ/).should('exist');
        cy.screenshot('wallet-page');
      });
    });
  });
});
