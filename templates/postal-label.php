<?php
/**
 * برچسب پستی مستقل، راست‌چین و آمادهٔ چاپ روی کاغذ/لیبل ۱۰۰×۱۵۰ میلی‌متر.
 * داده‌ها فقط از فاکتور و تنظیمات فرستندهٔ فروشگاه می‌آیند.
 */
if (!defined('ABSPATH')) {
    exit;
}

$recipient = isset($invoice['recipient']) && is_array($invoice['recipient']) ? $invoice['recipient'] : array();
$buyer     = isset($invoice['buyer']) && is_array($invoice['buyer']) ? $invoice['buyer'] : array();
$recipient_name = trim(isset($recipient['name']) ? (string) $recipient['name'] : '');
if ($recipient_name === '') {
    $recipient_name = trim(isset($buyer['name']) ? (string) $buyer['name'] : '');
}
$recipient_phone = trim(isset($recipient['phone']) ? (string) $recipient['phone'] : '');
$sender_name = trim(isset($sender['name']) ? (string) $sender['name'] : '');
$sender_company = trim(isset($sender['company']) ? (string) $sender['company'] : '');

$woocommerce = function_exists('WC') ? WC() : null;
$country_names = array();
if ($woocommerce && isset($woocommerce->countries)) {
    $country_names = $woocommerce->countries->get_countries();
}
$localize_address = function ($row) use ($country_names, $woocommerce) {
    $country = trim(isset($row['country']) ? (string) $row['country'] : '');
    $state   = trim(isset($row['state']) ? (string) $row['state'] : '');
    if ($country !== '' && isset($country_names[$country])) {
        $country_name = $country_names[$country];
        if ($state !== '' && $woocommerce && isset($woocommerce->countries)) {
            $states = $woocommerce->countries->get_states($country);
            if (is_array($states) && isset($states[$state])) {
                $state = $states[$state];
            }
        }
        $country = $country_name;
    }
    $parts = array(
        trim(isset($row['address_1']) ? (string) $row['address_1'] : ''),
        trim(isset($row['address_2']) ? (string) $row['address_2'] : ''),
        trim(isset($row['city']) ? (string) $row['city'] : ''),
        $state,
        $country,
    );
    return implode('، ', array_filter($parts, function ($value) { return $value !== ''; }));
};
$recipient_address = $localize_address($recipient);
$sender_address = $localize_address($sender);
$recipient_postcode = trim(isset($recipient['postcode']) ? (string) $recipient['postcode'] : '');
$sender_postcode = trim(isset($sender['postcode']) ? (string) $sender['postcode'] : '');
$sender_phone = trim(isset($sender['phone']) ? (string) $sender['phone'] : '');
$sender_email = trim(isset($sender['email']) ? (string) $sender['email'] : '');
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>برچسب پستی <?php echo esc_html($invoice['number']); ?></title>
<style>
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:#eef1f4;color:#111;font-family:Tahoma,Arial,sans-serif}
  body{padding:18px}
  .toolbar{max-width:100mm;margin:0 auto 12px;display:flex;justify-content:flex-start;gap:8px}
  .toolbar button{border:0;border-radius:7px;background:#173a65;color:#fff;padding:9px 16px;font:inherit;cursor:pointer}
  .print-sheet{width:100mm;min-height:150mm;margin:0 auto}
  .label{width:100mm;min-height:150mm;margin:0 auto;background:#fff;padding:5mm;border:1px solid #d5dbe3;display:flex;flex-direction:column;gap:5mm}
  .title{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #111;padding:0 0 3mm;font-size:10pt;color:#333}
  .tracking{font-size:9pt;direction:ltr;text-align:left}
  .block{border:1px solid #777;border-radius:2mm;padding:4mm;min-height:42mm;overflow-wrap:anywhere}
  .block.recipient{min-height:63mm;border:1.5px solid #222}
  .block h1,.block h2{padding:0;margin:0 0 3mm;font-size:10pt;color:#333}
  .recipient-name{font-size:15pt;font-weight:700;line-height:1.6;margin-bottom:2mm}
  .company{font-size:10pt;font-weight:600;margin-bottom:2mm}
  .address{font-size:11pt;line-height:1.9;white-space:pre-line;overflow-wrap:anywhere}
  .contact{margin-top:2mm;font-size:10pt;line-height:1.8;display:flex;flex-wrap:wrap;gap:2mm 5mm}
  .contact span{white-space:nowrap}
  .postcode{margin-top:2mm;font-size:11pt;font-weight:700}
  .divider{border:0;border-top:1px dashed #666;margin:0}
  .footer{margin-top:auto;padding-top:2mm;font-size:8pt;color:#444;display:flex;justify-content:space-between;gap:4mm}
  @page{size:100mm 150mm;margin:0}
  @media print{
    html,body{width:100mm;height:150mm;min-height:150mm;max-height:150mm;margin:0!important;padding:0!important;background:#fff;overflow:hidden!important}
    body{position:relative}
    .toolbar{display:none!important}
    .print-sheet{position:absolute;top:0;left:0;display:flex;justify-content:center;align-items:flex-start;width:100mm;height:150mm;min-height:150mm;margin:0!important;overflow:hidden!important;page-break-inside:avoid;break-inside:avoid}
    .label{flex:none;width:100mm;min-height:150mm;margin:0;border:0;padding:4mm;gap:3mm;page-break-after:avoid;page-break-inside:avoid;break-inside:avoid;transform-origin:top center}
    .title{padding-bottom:2mm;font-size:9pt}
    .block{min-height:0;padding:3mm}
    .block.recipient{min-height:0}
    .block h1,.block h2{margin-bottom:2mm;font-size:9pt}
    .recipient-name{font-size:13pt;line-height:1.35;margin-bottom:1mm}
    .company{font-size:9pt;margin-bottom:1mm}
    .address{font-size:10pt;line-height:1.5}
    .contact{margin-top:1mm;font-size:9pt;line-height:1.5;gap:1mm 3mm}
    .postcode{margin-top:1mm;font-size:10pt}
    .footer{padding-top:1mm;font-size:7.5pt}
    *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  }
  @media screen and (max-width:520px){body{padding:8px}.print-sheet{width:min(100%,100mm);min-height:150mm}.label{width:100%;min-height:150mm}}
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">چاپ / ذخیرهٔ PDF</button></div>
<div class="print-sheet">
<main class="label">
  <header class="title">
    <strong>برچسب پستی</strong>
    <span class="tracking"><?php echo esc_html($invoice['number']); ?><?php if (!empty($invoice['source_order_number'])) : ?> · سفارش <?php echo esc_html($invoice['source_order_number']); ?><?php endif; ?></span>
  </header>

  <section class="block recipient">
    <h1>گیرنده</h1>
    <div class="recipient-name"><?php echo esc_html($recipient_name !== '' ? $recipient_name : 'نام گیرنده ثبت نشده'); ?></div>
    <?php if (!empty($recipient['company'])) : ?><div class="company"><?php echo esc_html($recipient['company']); ?></div><?php endif; ?>
    <div class="address"><?php echo esc_html($recipient_address !== '' ? $recipient_address : 'نشانی گیرنده ثبت نشده است.'); ?></div>
    <?php if ($recipient_postcode !== '') : ?><div class="postcode">کدپستی: <span dir="ltr"><?php echo esc_html($recipient_postcode); ?></span></div><?php endif; ?>
    <?php if ($recipient_phone !== '') : ?><div class="contact"><span>تلفن:</span><span dir="ltr"><?php echo esc_html($recipient_phone); ?></span></div><?php endif; ?>
  </section>

  <hr class="divider">

  <section class="block sender">
    <h2>فرستنده</h2>
    <div class="company"><?php echo esc_html($sender_company !== '' ? $sender_company : $sender_name); ?></div>
    <?php if ($sender_name !== '' && $sender_name !== $sender_company) : ?><div><?php echo esc_html($sender_name); ?></div><?php endif; ?>
    <div class="address"><?php echo esc_html($sender_address !== '' ? $sender_address : 'نشانی فرستنده در تنظیمات ثبت نشده است.'); ?></div>
    <?php if ($sender_postcode !== '') : ?><div class="postcode">کدپستی: <span dir="ltr"><?php echo esc_html($sender_postcode); ?></span></div><?php endif; ?>
    <div class="contact">
      <?php if ($sender_phone !== '') : ?><span>تلفن: <span dir="ltr"><?php echo esc_html($sender_phone); ?></span></span><?php endif; ?>
      <?php if ($sender_email !== '') : ?><span dir="ltr"><?php echo esc_html($sender_email); ?></span><?php endif; ?>
    </div>
  </section>

  <footer class="footer"><span><?php echo esc_html($invoice['number']); ?></span><span><?php echo esc_html($invoice['date']); ?></span></footer>
</main>
</div>
<script>
(function () {
  var page = document.querySelector('.print-sheet');
  var label = document.querySelector('.label');
  if (!page || !label) { return; }
  function fitLabelToPage() {
    label.style.transform = 'none';
    var pageWidth = page.clientWidth || (100 * 96 / 25.4);
    var pageHeight = page.clientHeight || (150 * 96 / 25.4);
    var contentWidth = Math.max(label.scrollWidth, label.offsetWidth);
    var contentHeight = Math.max(label.scrollHeight, label.offsetHeight);
    var scale = Math.min(1, pageWidth / contentWidth, pageHeight / contentHeight);
    label.style.transformOrigin = 'top center';
    label.style.transform = scale < 1 ? 'scale(' + scale + ')' : 'none';
  }
  window.addEventListener('beforeprint', fitLabelToPage);
  window.addEventListener('afterprint', function () { label.style.transform = ''; });
})();
</script>
</body>
</html>
