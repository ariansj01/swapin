<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();

$categories = [
    ['name' => 'موبایل', 'icon' => 'bi-phone'],
    ['name' => 'لپ‌تاپ', 'icon' => 'bi-laptop'],
    ['name' => 'خودرو', 'icon' => 'bi-car-front'],
    ['name' => 'موتور', 'icon' => 'bi-bicycle'],
    ['name' => 'کنسول بازی', 'icon' => 'bi-controller'],
    ['name' => 'ساعت', 'icon' => 'bi-watch'],
    ['name' => 'دوربین', 'icon' => 'bi-camera'],
    ['name' => 'کتاب', 'icon' => 'bi-book'],
    ['name' => 'لوازم خانگی', 'icon' => 'bi-tv'],
    ['name' => 'پوشاک', 'icon' => 'bi-bag'],
    ['name' => 'طلا و جواهر', 'icon' => 'bi-gem'],
    ['name' => 'لوازم تحریر', 'icon' => 'bi-pencil'],
    ['name' => 'اسباب بازی', 'icon' => 'bi-joystick'],
    ['name' => 'میز و صندلی', 'icon' => 'bi-building'],
    ['name' => 'لوازم ورزشی', 'icon' => 'bi-activity'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['preferred_categories'] = $_POST['categories'] ?? [];
    header('Location: ' . APP_URL . '/listings/create');
    exit;
}

render_head('به سواپین خوش آمدید', '', ['robots' => 'noindex']);
render_navbar($user);
?>

<div class="wizard-page">
  <div class="wizard-container welcome-page">
    <form method="POST" class="welcome-content">
      <h1 class="welcome-title">برای شروع، چند علاقه‌مندی را انتخاب کنید</h1>
      <p class="welcome-subtitle">برای اینکه تجربه بهتری در سواپین داشته باشید.</p>

      <div class="category-chips">
        <?php foreach ($categories as $cat): ?>
          <div class="category-chip" data-category="<?= h($cat['name']) ?>" 
               onclick="toggleCategory(this, '<?= h($cat['name']) ?>')">
            <span class="chip-icon"><i class="bi <?= $cat['icon'] ?>"></i></span>
            <?= h($cat['name']) ?>
          </div>
        <?php endforeach; ?>
      </div>

      <input type="hidden" id="selected-categories" name="categories[]" multiple>

      <button type="submit" id="continue-btn" class="wizard-btn wizard-btn-primary" disabled>
        ادامه
      </button>
    </form>
  </div>
</div>

<link rel="stylesheet" href="<?= APP_URL ?>/src/css/listing-wizard.css">
<script>
let selectedCategories = new Set();

function toggleCategory(el, cat) {
  if (selectedCategories.has(cat)) {
    selectedCategories.delete(cat);
    el.classList.remove('selected');
  } else {
    selectedCategories.add(cat);
    el.classList.add('selected');
  }
  
  updateSelectedInput();
  updateButtonState();
}

function updateSelectedInput() {
  const hiddenInput = document.getElementById('selected-categories');
  hiddenInput.value = JSON.stringify(Array.from(selectedCategories));
}

function updateButtonState() {
  const btn = document.getElementById('continue-btn');
  btn.disabled = selectedCategories.size === 0;
}
</script>

<?php render_footer(); ?>
