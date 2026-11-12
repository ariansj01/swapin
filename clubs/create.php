<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/clubs.php';

$user = require_auth();
clubs_ensure_tables();

$errors = [];
$vals = clubs_form_defaults();
$created = false;

if (empty($_SESSION['club_form_token'])) {
    $_SESSION['club_form_token'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('club_create', 8, 3600);

    $postedToken = (string)($_POST['form_token'] ?? '');
    $sessionToken = (string)($_SESSION['club_form_token'] ?? '');
    $vals = clubs_fields_from_input($_POST);

    if ($postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        $lastId = (int)($_SESSION['club_created_id'] ?? 0);
        if ($lastId > 0) {
            $mine = clubs_get($lastId);
            if ($mine && (int)$mine['owner_id'] === (int)$user['id']) {
                $created = true;
            }
        }
        if (!$created) {
            $errors['_form'] = 'ارسال فرم نامعتبر بود. لطفاً دوباره تلاش کنید.';
        }
    } else {
        $result = clubs_create((int)$user['id'], $vals, $_FILES);
        if (!empty($result['ok'])) {
            $_SESSION['club_form_token'] = bin2hex(random_bytes(16));
            $_SESSION['club_created_id'] = (int)$result['id'];
            $created = true;
        } else {
            $errors = $result['errors'] ?? ['_form' => 'ثبت کلاب ناموفق بود.'];
        }
    }
}

$categoryOptions = render_wizard_category_options([], (int)$vals['category_id']);
$formToken = h((string)$_SESSION['club_form_token']);
$cssVer = @filemtime(__DIR__ . '/../src/css/clubs-create.css') ?: time();

render_head('ساخت کلاب | ' . APP_NAME, 'کلاب خودت رو بساز؛ آدم‌های هم‌علاقه را دور هم جمع کن.', [
    'canonical'  => APP_URL . '/clubs/create',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'page-club-create',
]);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/clubs-create.css?v=<?= $cssVer ?>">

<main id="main-content" class="club-create">
  <div class="club-create__mhead">
    <a href="<?= APP_URL ?>/clubs" aria-label="بازگشت"><i class="bi bi-chevron-right"></i></a>
    ساخت کلاب
  </div>

  <nav class="club-create__crumb" aria-label="مسیر صفحه">
    <a href="<?= APP_URL ?>/">خانه</a>
    <i class="bi bi-chevron-left"></i>
    <a href="<?= APP_URL ?>/clubs">کلاب‌های سواپین</a>
    <i class="bi bi-chevron-left"></i>
    <span>ساخت کلاب</span>
  </nav>

  <header class="club-create__hero">
    <div class="club-create__badge"><i class="bi bi-shield-check"></i> یک جامعه، هزار فرصت معامله</div>
    <h1 class="club-create__title">کلاب خودت رو بساز</h1>
    <p class="club-create__lead">آدم‌های هم‌علاقه را دور هم جمع کن؛ خرید، فروش و معاوضه را شروع کن.</p>
  </header>

  <div class="club-create__mtips">
    <i class="bi bi-lightbulb"></i>
    <span>نام و تصویر مرتبط انتخاب کن؛ بعد از ساخت، دوستانت را به کلاب دعوت کن.</span>
  </div>

  <?php if ($created): ?>
  <div class="club-success">
    <i class="bi bi-check-circle-fill"></i>
    <h2>درخواست ساخت کلاب ثبت شد</h2>
    <p>کلاب شما پس از بررسی و تأیید ادمین فعال می‌شود. نتیجه از طریق اعلان به شما اطلاع داده می‌شود.</p>
    <a href="<?= APP_URL ?>/clubs" class="btn btn-accent">بازگشت به کلاب‌ها</a>
  </div>
  <?php else: ?>

  <div class="club-create__layout">
    <form method="POST" enctype="multipart/form-data" class="club-create__form" id="club-create-form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="form_token" value="<?= $formToken ?>">
      <input type="hidden" name="MAX_FILE_SIZE" value="5242880">

      <?php if (!empty($errors['_form'])): ?>
      <div class="club-alert"><?= h($errors['_form']) ?></div>
      <?php endif; ?>

      <div class="club-create__section-head">
        <h2 class="club-create__section-title">هویت کلاب</h2>
        <i class="bi bi-pencil-square" style="color:#9ca3af"></i>
      </div>

      <label class="club-cover<?= !empty($errors['cover_image']) ? ' is-invalid' : '' ?>" id="club-cover-box">
        <input type="file" name="cover_image" id="club-cover-input" accept="image/jpeg,image/png,.jpg,.jpeg,.png" hidden>
        <div class="club-cover__placeholder">
          <div class="club-cover__icon"><i class="bi bi-image"></i></div>
          <div class="club-cover__title">افزودن تصویر کاور</div>
          <div class="club-cover__meta">حداکثر ۵ مگابایت · JPG یا PNG<br>اندازه پیشنهادی: ۱۲۰۰ × ۴۰۰ پیکسل</div>
        </div>
        <img id="club-cover-preview" alt="" hidden>
      </label>
      <?php if (!empty($errors['cover_image'])): ?>
      <div class="invalid-feedback" style="margin:-8px 0 14px"><?= h($errors['cover_image']) ?></div>
      <?php endif; ?>

      <div class="club-avatar-row">
        <label class="club-avatar" id="club-avatar-box">
          <input type="file" name="avatar_image" id="club-avatar-input" accept="image/jpeg,image/png,.jpg,.jpeg,.png" hidden>
          <i class="bi bi-camera" id="club-avatar-icon"></i>
          <img id="club-avatar-preview" alt="" hidden>
        </label>
        <div class="club-avatar__meta">
          <div class="club-avatar__title">تصویر پروفایل کلاب</div>
          <button type="button" class="club-avatar__link" id="club-avatar-pick">انتخاب تصویر</button>
          <div class="club-avatar__hint">تصویر مربعی · حداقل ۲۰۰ × ۲۰۰ پیکسل</div>
          <?php if (!empty($errors['avatar_image'])): ?>
          <div class="invalid-feedback"><?= h($errors['avatar_image']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="club-create__grid2">
        <div class="club-field">
          <label for="club-name">نام کلاب <span class="club-req">*</span></label>
          <input type="text" class="form-control<?= !empty($errors['name']) ? ' is-invalid' : '' ?>" id="club-name" name="name" maxlength="50" value="<?= h($vals['name']) ?>" placeholder="کلاب دوستداران تکنولوژی">
          <div class="form-hint">یک نام کوتاه و مشخص، حداکثر ۵۰ کاراکتر</div>
          <?php if (!empty($errors['name'])): ?><div class="invalid-feedback"><?= h($errors['name']) ?></div><?php endif; ?>
        </div>
        <div class="club-field">
          <label for="club-category">دسته‌بندی <span class="club-req">*</span></label>
          <select class="form-control<?= !empty($errors['category_id']) ? ' is-invalid' : '' ?>" id="club-category" name="category_id">
            <?= $categoryOptions ?>
          </select>
          <?php if (!empty($errors['category_id'])): ?><div class="invalid-feedback"><?= h($errors['category_id']) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="club-field">
        <label for="club-about">درباره کلاب <span class="club-req">*</span></label>
        <textarea class="form-control<?= !empty($errors['description']) ? ' is-invalid' : '' ?>" id="club-about" name="description" maxlength="500" rows="4" placeholder="جایی برای خرید، فروش و معاوضه موبایل، لپ‌تاپ و گجت‌ها؛ تجربه‌هامون رو به اشتراک می‌گذاریم و با خیال راحت معامله می‌کنیم."><?= h($vals['description']) ?></textarea>
        <div class="form-hint">کلابت را معرفی کن و بگو اعضا اینجا چه چیزی پیدا می‌کنند. حداکثر ۵۰۰ کاراکتر</div>
        <?php if (!empty($errors['description'])): ?><div class="invalid-feedback"><?= h($errors['description']) ?></div><?php endif; ?>
      </div>

      <div class="club-field">
        <label for="club-rules">قوانین کلاب</label>
        <textarea class="form-control<?= !empty($errors['rules']) ? ' is-invalid' : '' ?>" id="club-rules" name="rules" maxlength="2000" rows="4" placeholder="قوانین عضویت و معامله در کلاب را بنویس تا اعضا از ابتدا بدانند چه رفتاری انتظار می‌رود."><?= h($vals['rules']) ?></textarea>
        <div class="form-hint">قوانینی که سازنده کلاب مشخص می‌کند. حداکثر ۲۰۰۰ کاراکتر</div>
        <?php if (!empty($errors['rules'])): ?><div class="invalid-feedback"><?= h($errors['rules']) ?></div><?php endif; ?>
      </div>

      <div class="club-create__divider"></div>

      <div class="club-privacy-head">
        <i class="bi bi-shield-lock"></i>
        <div>
          <h2>حریم خصوصی و عضویت</h2>
          <p>مشخص کن چه کسانی کلابت را ببینند و عضو شوند.</p>
        </div>
      </div>

      <div class="club-privacy-grid">
        <label class="club-privacy<?= $vals['privacy'] === 'public' ? ' is-on' : '' ?>" data-privacy="public">
          <input type="radio" name="privacy" value="public"<?= $vals['privacy'] === 'public' ? ' checked' : '' ?>>
          <span class="club-privacy__radio"></span>
          <span class="club-privacy__body">
            <span class="club-privacy__title">کلاب عمومی <i class="bi bi-globe2"></i></span>
            <span class="club-privacy__desc">همه می‌توانند کلاب و آگهی‌ها را ببینند.</span>
          </span>
        </label>
        <label class="club-privacy<?= $vals['privacy'] === 'private' ? ' is-on' : '' ?>" data-privacy="private">
          <input type="radio" name="privacy" value="private"<?= $vals['privacy'] === 'private' ? ' checked' : '' ?>>
          <span class="club-privacy__radio"></span>
          <span class="club-privacy__body">
            <span class="club-privacy__title">کلاب خصوصی <i class="bi bi-lock"></i></span>
            <span class="club-privacy__desc">فقط اعضا به آگهی‌ها و کاتالوگ دسترسی دارند.</span>
          </span>
        </label>
      </div>

      <div class="club-approve">
        <div class="club-approve__text">
          <h3>تأیید درخواست عضویت توسط مدیر</h3>
          <p>اعضای جدید پس از تأیید شما به کلاب می‌پیوندند.</p>
        </div>
        <label class="club-switch">
          <input type="checkbox" name="require_approval" value="1"<?= !empty($vals['require_approval']) ? ' checked' : '' ?>>
          <span></span>
        </label>
      </div>

      <label class="club-terms<?= !empty($vals['accept_terms']) ? ' is-on' : '' ?>" id="club-terms">
        <input type="checkbox" name="accept_terms" value="1"<?= !empty($vals['accept_terms']) ? ' checked' : '' ?>>
        <span class="club-terms__box"><i class="bi bi-check-lg"></i></span>
        <span>قوانین سواپین را می‌پذیرم و مسئولیت مدیریت محتوای کلاب را بر عهده می‌گیرم.</span>
      </label>
      <?php if (!empty($errors['accept_terms'])): ?>
      <div class="invalid-feedback" style="margin:-8px 0 14px"><?= h($errors['accept_terms']) ?></div>
      <?php endif; ?>

      <div class="club-create__actions">
        <a href="<?= APP_URL ?>/clubs" class="btn btn-outline">انصراف</a>
        <button type="submit" class="btn btn-accent" id="club-submit">
          <i class="bi bi-plus-lg"></i> ساخت کلاب
        </button>
      </div>
    </form>

    <aside class="club-side">
      <div class="club-preview" id="club-preview">
        <div class="club-preview__head">
          <span>کلاب شما در سواپین</span>
          <span class="club-preview__tag">پیش‌نمایش</span>
        </div>
        <div class="club-preview__cover" id="preview-cover">
          <span class="club-preview__cover-icon"><i class="bi bi-image"></i></span>
        </div>
        <div class="club-preview__avatar" id="preview-avatar"><i class="bi bi-person"></i></div>
        <div class="club-preview__body">
          <h3 class="club-preview__name" id="preview-name">کلاب دوستداران تکنولوژی</h3>
          <div class="club-preview__cat" id="preview-cat">موبایل و لوازم دیجیتال</div>
          <span class="club-preview__privacy" id="preview-privacy"><i class="bi bi-globe2"></i> کلاب عمومی</span>
        </div>
      </div>

      <div class="club-tips">
        <div class="club-tips__title"><span class="club-tips__bulb"><i class="bi bi-lightbulb"></i></span> یک شروع خوب برای کلابت</div>
        <ul>
          <li><i class="bi bi-check2"></i> نام و تصویر مرتبط انتخاب کن.</li>
          <li><i class="bi bi-check2"></i> درباره موضوع و قوانین کلاب شفاف بنویس.</li>
          <li><i class="bi bi-check2"></i> بعد از ساخت، دوستانت را دعوت کن.</li>
        </ul>
      </div>

      <div class="club-side-note">
        <i class="bi bi-info-circle"></i>
        <span>بعداً می‌تونی اطلاعات و تنظیمات کلاب را ویرایش کنی.</span>
      </div>
    </aside>
  </div>

  <p class="club-mnote"><i class="bi bi-info-circle"></i> بعداً می‌تونی اطلاعات و تنظیمات کلاب را ویرایش کنی.</p>
  <p class="club-create__footline">سواپین؛ جایی برای معامله‌های بهتر، در کنار هم.</p>
  <?php endif; ?>
</main>

<script>
(function () {
  var form = document.getElementById('club-create-form');
  if (!form) return;

  var nameInput = document.getElementById('club-name');
  var catInput = document.getElementById('club-category');
  var previewName = document.getElementById('preview-name');
  var previewCat = document.getElementById('preview-cat');
  var previewPrivacy = document.getElementById('preview-privacy');
  var previewBox = document.getElementById('club-preview');
  var submitted = false;

  function syncPreview() {
    if (previewName) {
      previewName.textContent = (nameInput.value || '').trim() || 'کلاب دوستداران تکنولوژی';
    }
    if (previewCat && catInput) {
      var opt = catInput.options[catInput.selectedIndex];
      var label = opt && opt.value ? opt.text : 'موبایل و لوازم دیجیتال';
      previewCat.textContent = label;
    }
  }

  function setPrivacy(val) {
    document.querySelectorAll('.club-privacy').forEach(function (el) {
      el.classList.toggle('is-on', el.getAttribute('data-privacy') === val);
    });
    if (previewBox) previewBox.classList.toggle('is-private', val === 'private');
    if (previewPrivacy) {
      if (val === 'private') {
        previewPrivacy.innerHTML = '<i class="bi bi-lock"></i> کلاب خصوصی';
      } else {
        previewPrivacy.innerHTML = '<i class="bi bi-globe2"></i> کلاب عمومی';
      }
    }
  }

  nameInput && nameInput.addEventListener('input', syncPreview);
  catInput && catInput.addEventListener('change', syncPreview);
  syncPreview();

  document.querySelectorAll('.club-privacy input').forEach(function (inp) {
    inp.addEventListener('change', function () { setPrivacy(inp.value); });
    if (inp.checked) setPrivacy(inp.value);
  });

  var terms = document.getElementById('club-terms');
  var termsInput = terms ? terms.querySelector('input') : null;
  function syncTerms() {
    if (terms && termsInput) terms.classList.toggle('is-on', termsInput.checked);
  }
  if (termsInput) {
    termsInput.addEventListener('change', syncTerms);
    syncTerms();
  }

  function previewFile(input, img, extra) {
    if (!input || !input.files || !input.files[0]) return;
    var file = input.files[0];
    if (file.size > 5 * 1024 * 1024) {
      alert(extra === 'cover' ? 'حجم تصویر کاور نباید بیشتر از ۵ مگابایت باشد.' : 'حجم تصویر پروفایل نباید بیشتر از ۵ مگابایت باشد.');
      input.value = '';
      return;
    }
    var reader = new FileReader();
    reader.onload = function (e) {
      img.src = e.target.result;
      img.hidden = false;
      if (extra === 'cover') {
        document.getElementById('club-cover-box').classList.add('is-filled');
        var pc = document.getElementById('preview-cover');
        pc.innerHTML = '';
        var im = document.createElement('img');
        im.src = e.target.result;
        im.alt = '';
        pc.appendChild(im);
      }
      if (extra === 'avatar') {
        document.getElementById('club-avatar-icon').style.display = 'none';
        var pa = document.getElementById('preview-avatar');
        pa.innerHTML = '';
        var im2 = document.createElement('img');
        im2.src = e.target.result;
        im2.alt = '';
        pa.appendChild(im2);
      }
    };
    reader.readAsDataURL(file);
  }

  var coverInput = document.getElementById('club-cover-input');
  var avatarInput = document.getElementById('club-avatar-input');
  coverInput && coverInput.addEventListener('change', function () {
    previewFile(coverInput, document.getElementById('club-cover-preview'), 'cover');
  });
  avatarInput && avatarInput.addEventListener('change', function () {
    previewFile(avatarInput, document.getElementById('club-avatar-preview'), 'avatar');
  });
  var pick = document.getElementById('club-avatar-pick');
  pick && pick.addEventListener('click', function (e) {
    e.preventDefault();
    avatarInput && avatarInput.click();
  });

  form.addEventListener('submit', function (e) {
    if (submitted) {
      e.preventDefault();
      return;
    }
    if (termsInput && !termsInput.checked) {
      e.preventDefault();
      terms.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    submitted = true;
    var btn = document.getElementById('club-submit');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = 'در حال ثبت...';
    }
  });
})();
</script>
<?php
render_footer();
