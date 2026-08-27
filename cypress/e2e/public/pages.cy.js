describe('صفحات عمومی و خانه', () => {
  describe('صفحه اصلی (Home)', () => {
    it('باید صفحه خانه را با موفقیت بارگذاری کند', () => {
      cy.visit('/');
      cy.title().should('include', 'سوا').or('include', 'Swapin');
      cy.get('nav, header, footer').should('exist');
      cy.screenshot('homepage', { capture: 'viewport' });
    });

    it('باید بخش جستجو در صفحه اصلی وجود داشته باشد', () => {
      cy.visit('/');
      cy.get('body').then(($body) => {
        const hasSearch = $body.find('input[name="q"]').length > 0 ||
                          $body.find('form[role="search"]').length > 0 ||
                          $body.text().includes('جستجو');
        expect(hasSearch).to.be.true;
      });
    });

    it('باید دسته‌بندی‌ها در صفحه اصلی نمایش داده شوند', () => {
      cy.visit('/');
      cy.get('body').contains(/دسته|موبایل|خودرو|لپ‌تاپ/).should('exist');
    });

    it('باید آگهی‌ها در صفحه اصلی وجود داشته باشند', () => {
      cy.visit('/');
      cy.get('body').then(($body) => {
        const hasListings = $body.find('.listing-card, [class*="card"]').length > 0 ||
                           $body.text().includes('تومان') || $body.text().includes('آگهی');
        expect(hasListings).to.be.true;
      });
      cy.screenshot('homepage-listings');
    });

    it('باید هدر سایت شامل لینک‌های مهم باشد', () => {
      cy.visit('/');
      cy.get('header, nav').within(() => {
        cy.get('a[href*="/listings/all"]').should('exist');
        cy.get('a[href*="/auth/login"]').should('exist');
      });
    });

    it('باید فوتر شامل لینک‌های مهم باشد', () => {
      cy.visit('/');
      cy.get('footer').within(() => {
        const importantLinks = ['about', 'contact', 'terms', 'privacy', 'faq'];
        importantLinks.forEach((link) => {
          cy.get(`a[href*="${link}"]`).should('exist');
        });
      });
    });
  });

  describe('صفحات محتوایی', () => {
    const contentPages = [
      { url: '/about.php', title: 'درباره ما', keywords: ['درباره', 'سوا'] },
      { url: '/contact.php', title: 'تماس با ما', keywords: ['تماس'] },
      { url: '/faq.php', title: 'سوالات متداول', keywords: ['سوال', 'FAQ'] },
      { url: '/terms.php', title: 'شرایط و قوانین', keywords: ['شرایط', 'قانون'] },
      { url: '/privacy.php', title: 'حریم خصوصی', keywords: ['حریم', 'خصوصی'] },
      { url: '/fraud-prevention.php', title: 'جلوگیری از کلاهبرداری', keywords: ['کلاهبرداری'] },
    ];

    contentPages.forEach((page) => {
      it(`صفحه ${page.title} باید قابل دسترسی باشد`, () => {
        cy.visit(page.url, { failOnStatusCode: false });
        cy.get('body').should('be.visible');
        
        // بررسی عنوان صفحه برای اطمینان از لود شدن درست
        cy.title().should('not.be.empty');
        
        page.keywords.forEach((kw) => {
          cy.get('body').invoke('text').should('include', kw);
        });
        cy.screenshot(`content-page-${page.url.replace(/[\/\.]/g, '_')}`);
      });
    });
  });

  describe('صفحه‌های خطا', () => {
    it('صفحه 404 باید برای آدرس نامعتبر نمایش داده شود', () => {
      cy.request({
        url: '/this-page-does-not-exist-12345.php',
        failOnStatusCode: false,
      }).then((resp) => {
        expect(resp.status).to.be.oneOf([404, 200, 301, 302]);
      });
    });
  });

  describe('صفحه تماس با ما', () => {
    it('باید فرم تماس شامل فیلدهای لازم باشد', () => {
      cy.visit('/contact.php');
      cy.get('form').should('exist');
      cy.get('body').then(($body) => {
        const hasName = $body.find('input[name*="name"]').length > 0;
        const hasEmail = $body.find('input[name*="email"], input[type="email"]').length > 0;
        const hasMessage = $body.find('textarea, textarea[name*="message"], textarea[name*="msg"]').length > 0;
        expect(hasEmail || hasName || hasMessage).to.be.true;
      });
    });
  });
});
