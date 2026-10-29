<?php
/**
 * قالب چاپ فاکتور (A4، RTL، فارسی)
 * متغیرها: $invoice (آرایهٔ فاکتور) و $store (تنظیمات افزونه)
 */

if (!defined('ABSPATH')) {
    exit;
}

$t = $invoice['totals'];
$logo = $store['store_logo'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>فاکتور <?php echo esc_html($invoice['number']); ?></title>
<style>
  @page { size: A4; margin: 12mm; }
  * { box-sizing: border-box; }
  body { font-family: Vazirmatn, Tahoma, Arial, sans-serif; color: #111; background: #fff; margin: 0; padding: 18px; font-size: 13px; line-height: 1.8; }
  .sheet { max-width: 780px; margin: 0 auto; }
  header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #0d9488; padding-bottom: 10px; margin-bottom: 14px; }
  header .brand { display: flex; align-items: center; gap: 10px; }
  header img { width: 48px; height: 48px; object-fit: contain; }
  h1 { font-size: 20px; margin: 0; color: #0f766e; }
  .meta { text-align: left; direction: ltr; font-size: 12px; color: #444; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
  .box { border: 1px solid #d6dbe5; border-radius: 8px; padding: 10px 12px; }
  .box h2 { font-size: 13px; margin: 0 0 6px; color: #0f766e; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  th, td { border: 1px solid #d6dbe5; padding: 6px 8px; text-align: right; vertical-align: middle; }
  th { background: #eef6f5; font-size: 12px; }
  td.num, th.num { text-align: center; }
  .thumb { width: 42px; height: 42px; object-fit: cover; border-radius: 6px; border: 1px solid #d6dbe5; vertical-align: middle; margin-left: 6px; }
  .totals { width: 320px; margin-right: auto; }
  .totals td { border: none; border-bottom: 1px solid #eef0f4; }
  .totals tr.grand td { font-weight: 700; font-size: 15px; color: #0f766e; border-top: 2px solid #0d9488; }
  .num-fa { direction: ltr; unicode-bidi: plaintext; }
  .note { margin-top: 14px; font-size: 12px; color: #444; white-space: pre-line; }
  .stamp { display: inline-block; margin-top: 8px; padding: 2px 10px; border: 2px solid #0d9488; color: #0f766e; border-radius: 6px; font-weight: 700; }
  .actions { text-align: center; margin: 18px 0 0; }
  .actions button { padding: 10px 22px; border: 0; border-radius: 8px; background: #0d9488; color: #fff; font-size: 14px; cursor: pointer; font-family: inherit; }
  @media print { .actions { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="sheet">
  <header>
    <div class="brand">
      <?php if ($logo) : ?><img src="<?php echo esc_url($logo); ?>" alt=""><?php endif; ?>
      <div>
        <h1><?php echo esc_html($store['store_name']); ?></h1>
        <div style="font-size:12px;color:#555">فاکتور فروش</div>
      </div>
    </div>
    <div class="meta">
      <div><strong><?php echo esc_html($invoice['number']); ?></strong></div>
      <div><?php echo esc_html($invoice['date']); ?></div>
      <div class="stamp" style="direction:rtl"><?php echo esc_html($invoice['status_label']); ?></div>
    </div>
  </header>

  <div class="grid">
    <div class="box">
      <h2>مشخصات خریدار</h2>
      <div>نام: <?php echo esc_html($invoice['buyer']['name'] ?: '—'); ?></div>
      <div>موبایل: <span class="num-fa"><?php echo esc_html(wsm_to_persian_digits($invoice['buyer']['mobile'] ?: '—')); ?></span></div>
      <?php if (!empty($invoice['club_member_code']) || !empty($invoice['club_card_number'])) : ?>
        <div>کد ورزشکار: <span class="num-fa"><?php echo esc_html(wsm_to_persian_digits($invoice['club_member_code'] ?: '—')); ?></span></div>
        <?php if (!empty($invoice['club_card_number'])) : ?><div>شماره کارت: <span class="num-fa"><?php echo esc_html(wsm_to_persian_digits($invoice['club_card_number'])); ?></span></div><?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($invoice['buyer']['national_id'])) : ?>
        <div>کد ملی: <span class="num-fa"><?php echo esc_html(wsm_to_persian_digits($invoice['buyer']['national_id'])); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($invoice['buyer']['email'])) : ?>
        <div>ایمیل: <span class="num-fa"><?php echo esc_html($invoice['buyer']['email']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($invoice['buyer']['address'])) : ?>
        <div>نشانی: <?php echo esc_html($invoice['buyer']['address']); ?></div>
      <?php endif; ?>
    </div>
    <div class="box">
      <h2>اطلاعات فروشنده</h2>
      <div><?php echo esc_html($store['store_name']); ?></div>
      <div><?php echo esc_html(home_url('/')); ?></div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th class="num" style="width:36px">ردیف</th>
        <th>کالا</th>
        <th class="num">تعداد</th>
        <th>قیمت واحد</th>
        <th>تخفیف قلم</th>
        <th>جمع قلم</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($invoice['items'] as $i => $item) : ?>
        <tr>
          <td class="num"><?php echo esc_html(wsm_to_persian_digits($i + 1)); ?></td>
          <td>
            <?php if (!empty($item['image_url'])) : ?>
              <img class="thumb" src="<?php echo esc_url($item['image_url']); ?>" alt="">
            <?php endif; ?>
            <?php echo esc_html($item['name']); ?>
          </td>
          <td class="num"><?php echo esc_html(wsm_to_persian_digits($item['qty'])); ?></td>
          <td><?php echo esc_html(wsm_fa_number($item['unit_price'])); ?></td>
          <td>
            <?php
            $gross = (float) $item['gross'];
            $net   = (float) $item['total'];
            echo $gross - $net > 0 ? esc_html(wsm_fa_number($gross - $net)) : '—';
            ?>
          </td>
          <td><?php echo esc_html(wsm_fa_number($item['total'])); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <table class="totals">
    <tr><td>جمع کل</td><td><?php echo esc_html(wsm_fa_number($t['subtotal'])); ?></td></tr>
    <?php if ($t['line_discount'] > 0) : ?>
      <tr><td>تخفیف اقلم</td><td>−<?php echo esc_html(wsm_fa_number($t['line_discount'])); ?></td></tr>
    <?php endif; ?>
    <?php if ($t['order_discount'] > 0) : ?>
      <tr><td>تخفیف فاکتور<?php echo $invoice['discount']['type'] === 'percent' ? ' (' . esc_html(wsm_to_persian_digits($invoice['discount']['value'])) . '٪)' : ''; ?></td><td>−<?php echo esc_html(wsm_fa_number($t['order_discount'])); ?></td></tr>
    <?php endif; ?>
    <?php if ($t['tax'] > 0) : ?>
      <tr><td>مالیات<?php if ((float) $invoice['tax_percent'] > 0) : ?> (<?php echo esc_html(wsm_to_persian_digits($invoice['tax_percent'])); ?>٪)<?php endif; ?></td><td><?php echo esc_html(wsm_fa_number($t['tax'])); ?></td></tr>
    <?php endif; ?>
    <?php if ($t['shipping'] > 0) : ?>
      <tr><td>هزینهٔ ارسال</td><td><?php echo esc_html(wsm_fa_number($t['shipping'])); ?></td></tr>
    <?php endif; ?>
    <tr class="grand"><td>مبلغ قابل پرداخت</td><td><?php echo esc_html(wsm_fa_number($t['total'])); ?> <?php echo esc_html(wsm_currency_symbol()); ?></td></tr>
  </table>

  <?php if (!empty($invoice['note'])) : ?>
    <div class="note"><strong>یادداشت:</strong> <?php echo esc_html($invoice['note']); ?></div>
  <?php endif; ?>

  <div class="actions">
    <button type="button" onclick="window.print()">چاپ / ذخیره به PDF</button>
  </div>
</div>
</body>
</html>

