<?php
/**
 * Discount code helpers — Swapin (سواَپین)
 *
 * Business logic + small UI renderers for the "کد تخفیف" (discount code)
 * feature. Codes are managed from the admin panel (admin/discounts.php) and
 * can be applied before a payment is sent to the SEP gateway.
 */

/** Normalize a user-entered code: trim, strip whitespace, uppercase. */
function discount_code_normalize(string $code): string {
    $code = trim($code);
    $code = preg_replace('/\s+/u', '', $code) ?? $code;
    return mb_strtoupper($code, 'UTF-8');
}

/** Fetch a discount code row by its (normalized) code. */
function discount_code_find(string $code): ?array {
    $code = discount_code_normalize($code);
    if ($code === '' || !db_has_table('discount_codes')) {
        return null;
    }
    try {
        $row = DB::fetch('SELECT * FROM discount_codes WHERE code = ? LIMIT 1', [$code]);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Compute the discount amount (Toman) for a payable amount. */
function discount_code_calculate(array $row, int $amount): int {
    if ($amount <= 0) {
        return 0;
    }
    $value = (float)($row['value'] ?? 0);
    if (($row['type'] ?? '') === 'percent') {
        $discount = (int)floor($amount * $value / 100);
        $cap = $row['max_discount'] ?? null;
        if ($cap !== null && (float)$cap > 0) {
            $discount = min($discount, (int)$cap);
        }
    } else {
        $discount = (int)$value;
    }
    if ($discount < 0) {
        $discount = 0;
    }
    if ($discount > $amount) {
        $discount = $amount;
    }
    return $discount;
}

/** Human description of a code, e.g. «۱۰٪ تخفیف (حداکثر ۵۰٬۰۰۰ تومان)». */
function discount_code_description(array $row): string {
    if (($row['type'] ?? '') === 'percent') {
        $text = fmt_num((float)$row['value']) . '٪ تخفیف';
        if (!empty($row['max_discount'])) {
            $text .= ' (حداکثر ' . fmt_credit((float)$row['max_discount']) . ')';
        }
        return $text;
    }
    return fmt_credit((float)$row['value']) . ' تخفیف';
}

/**
 * Validate a code against a payable amount.
 *
 * @return array{ok:bool,error:string,row:?array,discount:int,final:int}
 */
function discount_code_validate(string $code, int $amount): array {
    $amount = max(0, $amount);
    $fail = static fn(string $msg): array => [
        'ok' => false, 'error' => $msg, 'row' => null, 'discount' => 0, 'final' => $amount,
    ];

    $row = discount_code_find($code);
    if (!$row) {
        return $fail('کد تخفیف نامعتبر است.');
    }
    if ((int)$row['is_active'] !== 1) {
        return $fail('این کد تخفیف غیرفعال است.');
    }
    $now = time();
    if (!empty($row['starts_at']) && strtotime((string)$row['starts_at']) > $now) {
        return $fail('این کد تخفیف هنوز فعال نشده است.');
    }
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < $now) {
        return $fail('این کد تخفیف منقضی شده است.');
    }
    $maxUses = $row['max_uses'];
    if ($maxUses !== null && (int)$maxUses > 0 && (int)$row['used_count'] >= (int)$maxUses) {
        return $fail('ظرفیت استفاده از این کد تخفیف تکمیل شده است.');
    }

    $discount = discount_code_calculate($row, $amount);
    if ($discount <= 0) {
        return $fail('این کد تخفیف برای این مبلغ قابل اعمال نیست.');
    }

    return ['ok' => true, 'error' => '', 'row' => $row, 'discount' => $discount, 'final' => $amount - $discount];
}

/** Increment the usage counter (call once, after a successful payment). */
function discount_code_mark_used(int $id): void {
    if ($id <= 0) {
        return;
    }
    try {
        DB::query('UPDATE discount_codes SET used_count = used_count + 1 WHERE id = ?', [$id]);
    } catch (Throwable $e) {
        swapin_debug_log('discount-code-mark-used-failed', ['id' => $id, 'msg' => $e->getMessage()]);
    }
}

/** Whether any currently usable discount code exists (drives the modal). */
function discount_codes_available(): bool {
    if (!db_has_table('discount_codes')) {
        return false;
    }
    try {
        return (bool) DB::fetch(
            'SELECT id FROM discount_codes
             WHERE is_active = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (expires_at IS NULL OR expires_at >= NOW())
               AND (max_uses IS NULL OR max_uses = 0 OR used_count < max_uses)
             LIMIT 1'
        );
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Extract discount info from a validated result for storage in payments.meta.
 */
function discount_meta_from_result(array $result, int $originalAmount): array {
    return [
        'discount_code_id' => (int)$result['row']['id'],
        'discount' => [
            'code'            => (string)$result['row']['code'],
            'amount'          => (int)$result['discount'],
            'original_amount' => $originalAmount,
        ],
    ];
}

/**
 * Inline discount box (used on the wallet page, always visible).
 *
 * @param string $amountInputSelector CSS selector for the amount input inside the form.
 */
function render_discount_box(string $amountInputSelector = ''): string {
    $sel = h($amountInputSelector);
    return <<<HTML
<div class="discount-box" data-discount-box data-amount-input="{$sel}">
  <label class="form-label"><i class="bi bi-ticket-perforated"></i> کد تخفیف</label>
  <div class="discount-box__row">
    <input type="text" class="form-control" name="discount_code" data-discount-input
           placeholder="اگر کد تخفیف دارید وارد کنید" autocomplete="off" dir="ltr">
    <button type="button" class="btn btn-outline" data-discount-apply>بررسی</button>
  </div>
  <div class="discount-feedback" data-discount-feedback hidden></div>
</div>
HTML;
}

/** Shared discount modal (used on direct-to-gateway pages: promotion, subscription). */
function render_discount_modal(): string {
    return <<<HTML
<div class="discount-modal" id="discountModal" hidden>
  <div class="discount-modal__backdrop" data-discount-dismiss></div>
  <div class="discount-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="discountModalTitle">
    <div class="discount-modal__head">
      <h3 id="discountModalTitle"><i class="bi bi-ticket-perforated"></i> کد تخفیف</h3>
      <button type="button" class="discount-modal__x" data-discount-dismiss aria-label="بستن">&times;</button>
    </div>
    <p class="discount-modal__hint">اگر کد تخفیف دارید، آن را وارد کنید تا مبلغ نهایی کاهش یابد.</p>
    <div class="discount-modal__amount" data-discount-amount-info></div>
    <input type="text" class="form-control discount-modal__input" data-discount-input
           placeholder="مثلاً SWAPIN10" autocomplete="off" dir="ltr">
    <div class="discount-feedback" data-discount-feedback hidden></div>
    <div class="discount-modal__actions">
      <button type="button" class="btn btn-primary" data-discount-confirm>اعمال و پرداخت</button>
      <button type="button" class="btn btn-outline" data-discount-skip>ادامه بدون کد</button>
    </div>
  </div>
</div>
HTML;
}

/** Styles + bootstrap config for the discount UI. Rendered once per page. */
function render_discount_assets(): void {
    $api = h(APP_URL . '/api/discount.php');
    $apiJs = json_encode(APP_URL . '/api/discount.php', JSON_UNESCAPED_SLASHES);
    echo <<<HTML
<style>
.discount-box{margin:var(--sp-4) 0;padding:var(--sp-3);border:1px dashed var(--border);border-radius:12px;background:var(--bg-soft,#fafafa)}
.discount-box__row{display:flex;gap:var(--sp-2);align-items:stretch}
.discount-box__row .form-control{flex:1}
.discount-feedback{margin-top:8px;font-size:.8125rem;line-height:1.7}
.discount-feedback--ok{color:var(--success,#10b981)}
.discount-feedback--error{color:var(--danger,#ef4444)}
.discount-modal{position:fixed;inset:0;z-index:1200;display:flex;align-items:center;justify-content:center;padding:var(--sp-4)}
.discount-modal[hidden]{display:none}
.discount-modal__backdrop{position:absolute;inset:0;background:rgba(7,26,51,.55);backdrop-filter:blur(3px)}
.discount-modal__dialog{position:relative;width:min(440px,100%);background:var(--surface,#fff);border-radius:18px;padding:var(--sp-6);box-shadow:0 24px 60px rgba(7,26,51,.25);display:grid;gap:var(--sp-3)}
.discount-modal__head{display:flex;align-items:center;justify-content:space-between;gap:var(--sp-3)}
.discount-modal__head h3{margin:0;font-size:1.125rem}
.discount-modal__x{border:0;background:none;font-size:1.5rem;line-height:1;color:var(--text-muted);cursor:pointer}
.discount-modal__hint{margin:0;font-size:.8125rem;color:var(--text-muted)}
.discount-modal__amount{font-size:.875rem;font-weight:600;color:var(--primary)}
.discount-modal__input{text-align:center;letter-spacing:.05em}
.discount-modal__actions{display:flex;gap:var(--sp-2);flex-wrap:wrap}
.discount-modal__actions .btn{flex:1;min-width:140px}
</style>
<script>window.SWAAPIN_DISCOUNT_API={$apiJs};</script>
HTML;
}
