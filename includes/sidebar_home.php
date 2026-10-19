<aside class="home-sidebar card home-sidebar--mobile-collapsible" id="home-mobile-sidebar"
       aria-label="دسته‌بندی‌ها، فیلترها و منوها"
       style="background: transparent;border: none;box-shadow: none;width: 102%;">

  <!-- دکمه باز/بسته سایدبار (فقط در موبایل) -->
  <button type="button"
          class="home-sidebar__mobile-toggle"
          id="home-sidebar-mobile-toggle"
          aria-expanded="false"
          aria-controls="home-mobile-sidebar">
    <span class="home-sidebar__mobile-toggle-icon"><i class="bi bi-sliders2"></i></span>
    <span class="home-sidebar__mobile-toggle-label">فیلترها و دسته‌بندی‌ها</span>
    <span class="home-sidebar__mobile-toggle-caret"><i class="bi bi-chevron-down"></i></span>
  </button>

  <div class="home-sidebar__inner home-sidebar__inner--collapsible">

    <!-- Title + Count -->
    <div class="home-sidebar__header">
      <h3 class="home-sidebar__title">دسته‌بندی‌های محبوب</h3>
      <!-- <span class="home-sidebar__count"><?= fmt_num($total ?? 0) ?> دسته</span> -->
    </div>

    <!-- Categories List (Real Filter Links) -->
    <nav class="home-sidebar-cats" aria-label="دسته‌بندی‌ها">
      <?php
      $sidebarCats = [
          ['slug' => 'real-estate',     'name' => 'املاک',              'icon' => 'bi-house',          'img'  => 'real-estate.png'],
          ['slug' => 'vehicles',        'name' => 'وسایل نقلیه',        'icon' => 'bi-car-front',      'img'  => 'vehicles.png'],
          ['slug' => 'electronics',     'name' => 'کالای دیجیتال',      'icon' => 'bi-phone',          'img'  => 'electronic-devices.png'],
          ['slug' => 'home-garden',     'name' => 'خانه و آشپزخانه',    'icon' => 'bi-chef-hat',       'img'  => 'home-kitchen.png'],
          ['slug' => 'services',        'name' => 'خدمات',              'icon' => 'bi-cursor',         'img'  => 'services.png'],
          ['slug' => 'clothing',        'name' => 'پوشاک',              'icon' => 'bi-t-shirt',        'img'  => 'personal.png'],
          ['slug' => 'sports',          'name' => 'ورزش و سرگرمی',     'icon' => 'bi-soccer',         'img'  => 'leisure-hobbies.png'],
          ['slug' => 'jobs',            'name' => 'استخدام و کاریابی',  'icon' => 'bi-briefcase',      'img'  => 'jobs.png'],
      ];
      $catImgBase = APP_URL . '/src/img/category/';
      
      // Ensure variables are defined for the anonymous function
      $f_search   = $search ?? '';
      $f_city     = $city ?? 'تهران';
      $f_wantType = $wantType ?? '';
      $f_sort     = $sort ?? 'new';
      $f_timeAgo  = $timeAgo ?? '';
      $f_pmin     = $pmin ?? 0;
      $f_pmax     = $pmax ?? 0;
      $f_catSlug  = $catSlug ?? '';

      $buildSidebarQuery = function($overrides = []) use ($f_search, $f_city, $f_wantType, $f_sort, $f_timeAgo, $f_pmin, $f_pmax, $f_catSlug) {
          $params = array_filter([
              'cat'       => $overrides['cat']       ?? $f_catSlug,
              'q'         => $overrides['q']         ?? $f_search,
              'city'      => $overrides['city']      ?? $f_city,
              'want'      => $overrides['want']      ?? $f_wantType,
              'sort'      => $overrides['sort']      ?? $f_sort,
              'time_ago'  => $overrides['time_ago']  ?? $f_timeAgo,
              'price_min' => $overrides['price_min'] ?? ($f_pmin > 0 ? $f_pmin : null),
              'price_max' => $overrides['price_max'] ?? ($f_pmax > 0 ? $f_pmax : null),
          ]);
          $queryString = $params ? '?' . http_build_query($params) : '';
          return APP_URL . '/listings/map' . $queryString;
      };
      ?>
      <?php foreach ($sidebarCats as $sc):
        $isCatActive = ($f_catSlug === $sc['slug']);
      ?>
        <a href="<?= $buildSidebarQuery(['cat' => $sc['slug']]) ?>" class="home-sidebar-cats__item <?= $isCatActive ? 'is-active' : '' ?>">
          <span class="home-sidebar-cats__icon">
            <img src="<?= $catImgBase . $sc['img'] ?>" alt="<?= h($sc['name']) ?>" class="home-sidebar-cats__icon-img"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
            <i class="bi <?= $sc['icon'] ?> home-sidebar-cats__icon-fallback" style="display:none"></i>
          </span>
          <span class="home-sidebar-cats__label"><?= h($sc['name']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <!-- Filters: Real Dropdowns (همه شهرها، انواع معامله، جدیدترین) + جستجو -->
    <form id="sidebar-filters-form" method="GET" action="<?= APP_URL ?>/listings/map" class="home-sidebar-filters-form">
      <input type="hidden" name="cat" value="<?= h($f_catSlug) ?>">
      <input type="hidden" name="time_ago" value="<?= h($f_timeAgo) ?>">

      <!-- جستجو -->
      <div class="home-sidebar-field">
        <label class="home-sidebar-field__label" for="sidebar-search-input">
          <i class="bi bi-search"></i> جستجوی کالا
        </label>
        <input type="search" id="sidebar-search-input" name="q" class="form-control"
               value="<?= h($f_search) ?>" placeholder="دنبال چه کالایی هستید؟">
      </div>

      <!-- همه شهرها -->
      <div class="home-sidebar-field">
        <label class="home-sidebar-field__label" for="sidebar-city-select">
          <i class="bi bi-geo-alt"></i> همه استان‌ها
        </label>
        <select id="sidebar-city-select" name="city" class="form-control">
          <option value="">همه استان‌ها</option>
          <?php foreach (iran_provinces() as $prov): ?>
            <option value="<?= h($prov) ?>" <?= $f_city === $prov ? 'selected' : '' ?>><?= h($prov) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- انواع معامله + مرتب‌سازی کنار هم -->
      <div class="home-sidebar-field-row">
        <!-- انواع معامله -->
        <div class="home-sidebar-field">
          <label class="home-sidebar-field__label" for="sidebar-want-select">
            <i class="bi bi-arrow-left-right"></i> انواع معامله
          </label>
          <select id="sidebar-want-select" name="want" class="form-control">
            <option value=""    <?= $f_wantType === '' ? 'selected' : '' ?>>همه انواع معامله</option>
            <option value="item"    <?= $f_wantType === 'item' ? 'selected' : '' ?>>کالا با کالا</option>
            <option value="service" <?= $f_wantType === 'service' ? 'selected' : '' ?>>خدمات</option>
            <option value="credit"  <?= $f_wantType === 'credit' ? 'selected' : '' ?>>اعتبار</option>
          </select>
        </div>

        <!-- مرتب‌سازی -->
        <div class="home-sidebar-field">
          <label class="home-sidebar-field__label" for="sidebar-sort-select">
            <i class="bi bi-sort-down-alt"></i> مرتب‌سازی
          </label>
          <select id="sidebar-sort-select" name="sort" class="form-control">
            <option value="new"   <?= $f_sort === 'new'   ? 'selected' : '' ?>>جدیدترین</option>
            <option value="old"   <?= $f_sort === 'old'   ? 'selected' : '' ?>>قدیمی‌ترین</option>
            <option value="value" <?= $f_sort === 'value' ? 'selected' : '' ?>>بالاترین ارزش</option>
          </select>
        </div>
      </div>


      <!-- Price Min/Max -->
      <div class="home-sidebar-field">
        <label class="home-sidebar-field__label">
          <i class="bi bi-tag"></i> محدوده قیمت
        </label>
        <div class="home-sidebar-field__row">
          <div class="home-sidebar-field__col">
            <span class="home-sidebar-field__sub">از</span>
            <input type="number" name="price_min" class="form-control" min="0" step="1000"
                   value="<?= $f_pmin > 0 ? (int)$f_pmin : '' ?>" placeholder="قیمت کمینه">
          </div>
          <div class="home-sidebar-field__col">
            <span class="home-sidebar-field__sub">تا</span>
            <input type="number" name="price_max" class="form-control" min="0" step="1000"
                   value="<?= $f_pmax > 0 ? (int)$f_pmax : '' ?>" placeholder="قیمت بیشینه">
          </div>
        </div>
      </div>

      <!-- Apply Filter Button -->
      <button type="submit" class="btn btn-primary w-100 home-sidebar-apply-btn">
        <i class="bi bi-funnel"></i> اعمال فیلترها
      </button>
    </form>

    <!-- Time Chips -->
    <div class="home-sidebar-time">
      <div class="home-sidebar-time__header">
        <i class="bi bi-question-circle"></i>
        <h4>زمان انتشار آگهی:</h4>
      </div>
      <div class="home-sidebar-time__chips">
        <?php
        $timeChipList = [
          ['value' => '',    'label' => 'همه'],
          ['value' => '1d',  'label' => 'اروزه'],
          ['value' => '3h',  'label' => '۳ساعته'],
          ['value' => '12h', 'label' => '۱۲ساعته'],
          ['value' => '1w',  'label' => 'اهفته'],
          ['value' => '3d',  'label' => '۳روزه'],
        ];
        foreach ($timeChipList as $tc):
          $isActive = ($f_timeAgo === $tc['value']);
          $chipQuery = [
            'cat'       => $f_catSlug,
            'q'         => $f_search,
            'city'      => $f_city,
            'want'      => $f_wantType,
            'sort'      => $f_sort,
            'time_ago'  => $tc['value'],
            'price_min' => $f_pmin > 0 ? (int)$f_pmin : null,
            'price_max' => $f_pmax > 0 ? (int)$f_pmax : null,
          ];
          $chipQuery = array_filter($chipQuery);
          $chipUrl = APP_URL . '/listings/map' . ($chipQuery ? '?' . http_build_query($chipQuery) : '');
        ?>
          <a href="<?= $chipUrl ?>" class="time-chip <?= $isActive ? 'time-chip--active' : '' ?>">
            <?= $tc['label'] ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Menus -->
    <div class="home-sidebar-menu">
      <a href="<?= APP_URL ?>/about">درباره ما</a>
      <span class="home-sidebar-menu__dot"></span>
      <a href="<?= APP_URL ?>/contact">تماس با ما</a>
      <span class="home-sidebar-menu__dot"></span>
      <a href="<?= APP_URL ?>/faq">سوالات متداول</a>
    </div>
    <div class="home-sidebar-menu">
      <a href="<?= APP_URL ?>/pwa">نصب اپلیکیشن</a>
      <span class="home-sidebar-menu__dot"></span>
      <a href="<?= APP_URL ?>/blog">بلاگ</a>
      <span class="home-sidebar-menu__dot"></span>
      <a href="<?= APP_URL ?>/ai/chat">جستجوی هوشمند</a>
    </div>

    <!-- Social Networks (Real Links) -->
    <div class="home-sidebar-social">
      <a href="https://www.linkedin.com/company/swaapin" class="social-btn" target="_blank" rel="noopener" aria-label="LinkedIn">
        <i class="bi bi-linkedin"></i>
      </a>
      <!-- <a href="https://wa.me/989000000000?text=<?= urlencode('سلام. از سایت سواَپین  دیدن کردم...') ?>" class="social-btn" target="_blank" rel="noopener" aria-label="WhatsApp">
        <i class="bi bi-whatsapp"></i>
      </a> -->
      <a href="#" class="social-btn" target="_blank" rel="noopener" aria-label="Telegram">
        <i class="bi bi-telegram"></i>
      </a>
      <a href="https://www.instagram.com/swaapin.official/" class="social-btn" target="_blank" rel="noopener" aria-label="Instagram">
        <i class="bi bi-instagram"></i>
      </a>
    </div>

    <!-- Enamad --> 
    <a referrerpolicy='origin' target='_blank' href='https://trustseal.enamad.ir/?id=755927&Code=Io4wGYGFQ4YQdD53jiYDAKvPgKHr8sGM' style="display: flex;justify-content: center;"><img referrerpolicy='origin' src='https://trustseal.enamad.ir/logo.aspx?id=755927&Code=Io4wGYGFQ4YQdD53jiYDAKvPgKHr8sGM' alt='' style='cursor:pointer' code='Io4wGYGFQ4YQdD53jiYDAKvPgKHr8sGM'></a>

  </div>
</aside>