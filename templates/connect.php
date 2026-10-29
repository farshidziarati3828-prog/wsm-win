<?php
/**
 * صفحهٔ اتصال موبایل: /wsm-connect
 * کاربر کلید دسترسی را وارد می‌کند؛ پس از اعتبارسنجی، اپ برای ورود کارکنان باز می‌شود.
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings  = WSM_Util::settings();
$store     = $settings['store_name'] ?: get_bloginfo('name');
$built     = WSM_Util::app_built();
$app_url   = WSM_Util::app_url();
$rest_url  = WSM_Util::rest_base();
$has_key   = (bool) get_option('wsm_access_key_hash');
$logo_url  = $settings['store_logo'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#070c17">
<meta name="robots" content="noindex, nofollow">
<title>اتصال به مدیر فروشگاه</title>
<style>
  *{box-sizing:border-box}
  html,body{margin:0;min-height:100%}
  body{font-family:Vazirmatn,Tahoma,system-ui,sans-serif;color:#e9efff;background:radial-gradient(1200px 600px at 50% -10%,#14305a 0%,#0a1224 45%,#070c17 100%);display:flex;align-items:center;justify-content:center;padding:24px 16px;min-height:100vh}
  .card{width:100%;max-width:420px;background:rgba(17,27,49,.82);border:1px solid #1c2b4c;border-radius:24px;padding:32px 24px;box-shadow:0 24px 70px -20px rgba(0,0,0,.65);text-align:center}
  .icon{width:76px;height:76px;margin:0 auto 18px;border-radius:22px;background:linear-gradient(135deg,#2dd4bf,#0d9488);display:flex;align-items:center;justify-content:center;box-shadow:0 0 40px rgba(45,212,191,.35)}
  .icon img{width:52px;height:52px;object-fit:contain;border-radius:12px}
  h1{font-size:20px;line-height:1.7;margin:0 0 6px;font-weight:700}
  .sub{color:#8ba0c6;font-size:13px;margin:0 0 22px}
  .alert{border-radius:14px;padding:14px 16px;font-size:13px;line-height:1.9;margin:0 0 16px;text-align:right}
  .alert-err{background:rgba(127,29,29,.25);border:1px solid rgba(248,113,113,.45);color:#fecaca}
  .alert-warn{background:rgba(120,53,15,.25);border:1px solid rgba(245,177,76,.45);color:#fde68a}
  .alert-ok{background:rgba(6,78,59,.3);border:1px solid rgba(65,217,154,.45);color:#a7f3d0}
  label{display:block;text-align:right;font-size:13px;color:#8ba0c6;margin:14px 0 6px}
  input{width:100%;padding:14px 14px;border-radius:14px;border:1px solid #1c2b4c;background:#0a1120;color:#e9efff;font-size:15px;outline:none;direction:ltr;text-align:left;font-family:ui-monospace,Menlo,Consolas,monospace}
  input:focus{border-color:#2dd4bf}
  button{margin-top:18px;width:100%;padding:15px;border:0;border-radius:14px;background:linear-gradient(135deg,#2dd4bf,#0d9488);color:#031f1d;font-size:16px;font-weight:700;cursor:pointer;font-family:inherit}
  button:disabled{opacity:.6;cursor:wait}
  .msg{min-height:20px;margin-top:14px;font-size:13px;line-height:1.8}
  .foot{margin-top:22px;font-size:12px;color:#5e6f94;line-height:1.8}
  a{color:#7dd3c4}
</style>
</head>
<body>
<main class="card">
  <div class="icon">
    <?php if ($logo_url) : ?>
      <img src="<?php echo esc_url($logo_url); ?>" alt="">
    <?php else : ?>
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#031f1d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v0a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z"/><path d="M5 12v8h14v-8"/><path d="M10 20v-4h4v4"/></svg>
    <?php endif; ?>
  </div>

  <h1><?php echo esc_html($store); ?></h1>
  <p class="sub">مدیریت فروشگاه از موبایل — بدون ورود به پیشخوان وردپرس</p>

  <?php if (!$built) : ?>
    <div class="alert alert-err">
      بیلد اپ هنوز در پوشهٔ <code>/wsm-app/</code> قرار نگرفته است.
      فایل <code>index.html</code> خروجی Vite را در
      <code>wp-content/plugins/wp-store-manager/wsm-app</code> کپی کنید.
    </div>
  <?php elseif (!$has_key) : ?>
    <div class="alert alert-warn">
      هنوز کلید دسترسی ساخته نشده است. از پیشخوان وردپرس بخش
      «مدیر فروشگاه ← کلید دسترسی» را باز کنید و کلید بسازید.
    </div>
  <?php endif; ?>

  <?php if ($built) : ?>
    <form id="wsm-form" autocomplete="off" novalidate>
      <label for="wsm-key">کلید دسترسی</label>
      <input id="wsm-key" name="access_key" type="text" placeholder="wsm_ak_..." spellcheck="false" autocapitalize="off" required>

      <label for="wsm-name">نام دستگاه (اختیاری)</label>
      <input id="wsm-name" name="device_name" type="text" maxlength="100" placeholder="مثلاً گوشی فروشگاه" style="direction:rtl;text-align:right;font-family:inherit">

      <button type="submit" id="wsm-btn">اتصال</button>
      <div class="msg" id="wsm-msg" role="status" aria-live="polite"></div>
    </form>
  <?php endif; ?>

  <p class="foot">
    کلید فقط یک‌بار نمایش داده می‌شود. اتصال هر دستگاه از پیشخوان قابل قطع یا مسدودسازی است.
  </p>
</main>

<script>
(function () {
  var REST = <?php echo wp_json_encode($rest_url); ?>;
  var APP  = <?php echo wp_json_encode($app_url); ?>;
  var form = document.getElementById('wsm-form');
  if (!form) { return; }

  var ua = navigator.userAgent || '';
  var platform = /Android/i.test(ua) ? 'Android' : (/iPhone|iPad|iPod/i.test(ua) ? 'iOS' : (/Windows/i.test(ua) ? 'Windows' : (/Mac/i.test(ua) ? 'macOS' : 'Web')));
  var osMatch = ua.match(/Android\s([\d.]+)|OS\s([\d_]+)/i);
  var osVersion = osMatch ? (osMatch[1] || (osMatch[2] || '').replace(/_/g, '.')) : '';
  var nameInput = document.getElementById('wsm-name');
  if (nameInput && !nameInput.value) { nameInput.placeholder = platform + ' — دستگاه من'; }

  function setMsg(text, kind) {
    var el = document.getElementById('wsm-msg');
    el.textContent = text;
    el.style.color = kind === 'ok' ? '#41d99a' : (kind === 'err' ? '#f87171' : '#8ba0c6');
  }

  function clearAppSession() {
    try {
      ['wsm_token', 'wsm_refresh', 'wsm_device_id', 'wsm_sign_key', 'wsm_store', 'wsm_role', 'wsm_pending_login'].forEach(function (key) { localStorage.removeItem(key); });
      Object.keys(localStorage).forEach(function (key) { if (key.indexOf('wsm_cache:') === 0) localStorage.removeItem(key); });
    } catch (err) {}
  }

  function apiRequest(path, options) {
    options = options || {};
    var isPublic = options.public === true;
    var init = {};
    Object.keys(options).forEach(function (key) { if (key !== 'public') init[key] = options[key]; });
    var headers = new Headers(options.headers || {});
    if (isPublic) {
      headers.delete('Authorization');
      headers.delete('X-WSM-Token');
    } else {
      var token = '';
      try { token = localStorage.getItem('wsm_token') || ''; } catch (err) {}
      if (token) {
        headers.set('Authorization', 'Bearer ' + token);
        headers.set('X-WSM-Token', token);
      } else {
        headers.delete('Authorization');
        headers.delete('X-WSM-Token');
      }
    }
    init.headers = headers;
    init.cache = 'no-store';
    return fetch(REST + path, init).then(function (response) {
      if (response.status === 401) clearAppSession();
      return response;
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var key = document.getElementById('wsm-key').value.trim();
    var btn = document.getElementById('wsm-btn');
    if (!key) { setMsg('کلید دسترسی را وارد کنید.', 'err'); return; }
    btn.disabled = true;
    setMsg('در حال اتصال…', 'info');

    apiRequest('handshake', {
      method: 'POST',
      public: true,
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        access_key: key,
        device_name: (nameInput && nameInput.value.trim()) || (platform + ' — دستگاه من'),
        platform: platform,
        os_version: osVersion,
        app_version: '1.0.0'
      })
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) { return { ok: r.ok, data: data }; });
    }).then(function (res) {
      if (!res.ok || !res.data.token) {
        var msg = (res.data && res.data.message) || 'اتصال انجام نشد. دوباره تلاش کنید.';
        setMsg(msg, 'err');
        btn.disabled = false;
        return;
      }
      try {
        // Access Key validates the site, not an employee session. Never persist its bootstrap token.
        ['wsm_token', 'wsm_refresh', 'wsm_device_id', 'wsm_sign_key', 'wsm_store', 'wsm_role', 'wsm_pending_login'].forEach(function (key) { localStorage.removeItem(key); });
        localStorage.setItem('wsm_api', REST);
        localStorage.setItem('wsm_store', res.data.store_name || '');
        if (res.data.login_required === true || String(res.data.device_id) === '0' || !res.data.signing_key) {
          localStorage.setItem('wsm_pending_login', '1');
        } else {
          localStorage.setItem('wsm_token', res.data.token);
          localStorage.setItem('wsm_refresh', res.data.refresh_token);
          localStorage.setItem('wsm_device_id', String(res.data.device_id));
          localStorage.setItem('wsm_sign_key', res.data.signing_key);
        }
      } catch (err) {
        setMsg('مرورگر اجازهٔ ذخیرهٔ اطلاعات را نمی‌دهد (حالت ناشناس؟).', 'err');
        btn.disabled = false;
        return;
      }
      setMsg('اتصال موفق بود. در حال باز کردن اپ…', 'ok');
      window.location.replace(APP);
    }).catch(function () {
      setMsg('ارتباط با سرور برقرار نشد. اینترنت را بررسی کنید.', 'err');
      btn.disabled = false;
    });
  });
})();
</script>
</body>
</html>

