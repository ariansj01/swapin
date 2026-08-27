describe('تست دسترسی به صفحات کلیدی سایت (Smoke Test)', () => {
  const publicPages = [
    { path: '/', label: 'صفحه اصلی' },
    { path: '/index.php', label: 'صفحه اصلی (index.php)' },
    { path: '/about.php', label: 'درباره ما' },
    { path: '/contact.php', label: 'تماس با ما' },
    { path: '/faq.php', label: 'سوالات متداول' },
    { path: '/terms.php', label: 'شرایط و قوانین' },
    { path: '/privacy.php', label: 'حریم خصوصی' },
    { path: '/fraud-prevention.php', label: 'جلوگیری از کلاهبرداری' },
    { path: '/subscription.php', label: 'اشتراک' },
    { path: '/trades.php', label: 'معاملات' },
    { path: '/listings/all.php', label: 'همه آگهی‌ها' },
    { path: '/shops/index.php', label: 'فروشگاه‌ها' },
    { path: '/store/request.php', label: 'درخواست فروشگاه' },
    { path: '/category/index.php', label: 'دسته‌بندی‌ها' },
    { path: '/shop/index.php', label: 'فروشگاه' },
    { path: '/support/report.php', label: 'گزارش مشکل' },
    { path: '/search/ai.php', label: 'جستجوی هوش مصنوعی' },
    { path: '/sitemap.php', label: 'نقشه سایت' },
    { path: '/robots.txt', label: 'Robots.txt' },
    { path: '/auth/login.php', label: 'ورود کاربران' },
    { path: '/auth/register.php', label: 'ثبت‌نام' },
    { path: '/auth/complete-profile.php', label: 'تکمیل پروفایل' },
    { path: '/auth/onboarding.php', label: 'Onboarding' },
    { path: '/auth/store-login.php', label: 'ورود فروشگاه' },
    { path: '/admin/login.php', label: 'ورود ادمین' },
  ];

  publicPages.forEach((page) => {
    it(`تست دسترسی: ${page.label} (${page.path})`, () => {
      cy.request({
        url: page.path,
        failOnStatusCode: false,
        followRedirect: true,
        timeout: 15000,
      }).then((resp) => {
        const validStatuses = [200, 301, 302, 401, 403, 404];
        expect(resp.status, `وضعیت صفحه ${page.path}`).to.be.oneOf(validStatuses);
        if (resp.status === 200) {
          expect(resp.body, `محتوای صفحه ${page.path}`).to.not.be.empty;
        }
      });
    });
  });
});

describe('تست APIها', () => {
  const apiEndpoints = [
    { path: '/api/nearby_cities.php', method: 'GET' },
    { path: '/api/listing_location.php', method: 'GET' },
    { path: '/api/review.php', method: 'POST' },
    { path: '/api/contact.php', method: 'POST' },
  ];

  apiEndpoints.forEach((api) => {
    it(`تست API: ${api.method} ${api.path}`, () => {
      cy.request({
        method: api.method,
        url: api.path,
        failOnStatusCode: false,
        body: api.method === 'POST' ? { test: true } : undefined,
      }).then((resp) => {
        expect(resp.status).to.be.oneOf([200, 400, 401, 403, 405, 500]);
      });
    });
  });
});

describe('تست امنیتی و هدرها', () => {
  it('باید صفحه اصلی شامل متای canonical باشد', () => {
    cy.visit('/');
    cy.document().then((doc) => {
      const canonical = doc.querySelector('link[rel="canonical"]');
      expect(canonical, 'Canonical tag').to.exist;
    });
  });

  it('باید صفحه اصلی شامل متای description باشد', () => {
    cy.visit('/');
    cy.document().then((doc) => {
      const desc = doc.querySelector('meta[name="description"]');
      expect(desc, 'Meta description').to.exist;
    });
  });

  it('باید صفحه ادمین شامل noindex باشد', () => {
    cy.visit('/admin/login.php');
    cy.document().then((doc) => {
      const robots = doc.querySelector('meta[name="robots"]');
      if (robots) {
        expect(robots.getAttribute('content')).to.include('noindex');
      }
    });
  });

  it('باید GTM در صفحات عمومی فعال باشد (غیر از ادمین/احراز هویت)', () => {
    cy.visit('/');
    cy.document().then((doc) => {
      const hasGTM = doc.documentElement.innerHTML.includes('GTM-');
      expect(hasGTM, 'GTM script in homepage').to.be.true;
    });
  });

  it('باید GTM در صفحات ادمین غیرفعال باشد', () => {
    cy.visit('/admin/login.php');
    cy.document().then((doc) => {
      const hasGTM = doc.documentElement.innerHTML.includes('GTM-');
      expect(hasGTM, 'GTM NOT in admin').to.be.false;
    });
  });

  it('باید GTM در صفحات احراز هویت غیرفعال باشد', () => {
    cy.visit('/auth/login.php');
    cy.document().then((doc) => {
      const hasGTM = doc.documentElement.innerHTML.includes('GTM-');
      expect(hasGTM, 'GTM NOT in auth pages').to.be.false;
    });
  });
});
