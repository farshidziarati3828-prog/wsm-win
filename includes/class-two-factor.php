<?php
/**
 * تأیید دو مرحله‌ای (TOTP، RFC 6238) برای پنل مدیریت افزونه.
 * - وقتی سیاست wsm_settings.totp_required روشن باشد، مدیری که هنوز ثبت نکرده پیش از هر صفحهٔ افزونه باید برنامهٔ احراز هویت را متصل کند.
 * - هر کد یک‌بار قابل استفاده است (شمارهٔ گام آخر ذخیره می‌شود).
 * - ۵ کد اشتباه در ۱۵ دقیقه ⇒ قفل موقت ۱۵ دقیقه‌ای.
 * - اپ موبایل با امضای دستگاه محافظت می‌شود و از این بخش عبور نمی‌کند.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_TOTP {

    const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const STEP     = 30;
    const DIGITS   = 6;

    public static function generate_secret() {
        return self::base32_encode(random_bytes(20));
    }

    public static function base32_encode($bin) {
        $bits = '';
        for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
            $bits .= sprintf('%08b', ord($bin[$i]));
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0');
            $out  .= self::ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    public static function base32_decode($text) {
        $text = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string) $text));
        $bits = '';
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $idx = strpos(self::ALPHABET, $text[$i]);
            if ($idx === false) {
                return '';
            }
            $bits .= sprintf('%05b', $idx);
        }
        $out = '';
        for ($i = 0, $n = strlen($bits); $i + 8 <= $n; $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }

    /** کد برای یک شمارهٔ گام */
    public static function code_at($key_bin, $counter) {
        $hi  = (int) floor($counter / 4294967296);
        $lo  = (int) ($counter % 4294967296);
        $raw = hash_hmac('sha1', pack('NN', $hi, $lo), $key_bin, true);
        $off = ord($raw[19]) & 0x0f;
        $bin = ((ord($raw[$off]) & 0x7f) << 24)
            | ((ord($raw[$off + 1]) & 0xff) << 16)
            | ((ord($raw[$off + 2]) & 0xff) << 8)
            | (ord($raw[$off + 3]) & 0xff);
        return str_pad((string) ($bin % 1000000), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * پذیرش کد در پنجرهٔ ±۱ گام؛ گام‌های مصرف‌شده (<= $last) رد می‌شوند.
     * @return int|false شمارهٔ گام تطابق‌یافته
     */
    public static function verify($secret_b32, $code, $last) {
        $key = self::base32_decode($secret_b32);
        if ($key === '' || !preg_match('/^\d{6}$/', (string) $code)) {
            return false;
        }
        $step = (int) floor(time() / self::STEP);
        for ($d = -1; $d <= 1; $d++) {
            $counter = $step + $d;
            if ($counter <= (int) $last) {
                continue;
            }
            if (hash_equals(self::code_at($key, $counter), (string) $code)) {
                return $counter;
            }
        }
        return false;
    }
}

class WSM_Two_Factor {

    const META_SECRET  = 'wsm_totp_secret';
    const META_COUNTER = 'wsm_totp_counter';
    const MAX_FAILS    = 5;
    const FAIL_WINDOW  = 900;
    const SESSION_TTL  = 43200; // ۱۲ ساعت

    public static function init() {
        add_action('admin_init', array(__CLASS__, 'gate'), 2);
    }

    private static function session_key() {
        $token = function_exists('wp_get_session_token') ? wp_get_session_token() : '';
        return md5((string) $token);
    }

    public static function is_enrolled($uid) {
        return (string) get_user_meta($uid, self::META_SECRET, true) !== '';
    }

    public static function is_verified() {
        return (bool) get_transient('wsm_2fa_ok_' . self::session_key());
    }

    /**
     * دروازهٔ ورود به صفحات افزونه. اگر ثبت یا تأیید نشده باشد، صفحهٔ مربوط نشان داده و اجرا متوقف می‌شود.
     */
    public static function gate() {
        if (!is_admin() || wp_doing_ajax() || !is_user_logged_in()) {
            return;
        }
        $page = isset($_GET['page']) && is_scalar($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'wsm') !== 0) {
            return;
        }
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return;
        }

        $settings = WSM_Util::settings();
        if (empty($settings['totp_required'])) {
            return;
        }

        $uid = get_current_user_id();

        if (!WSM_Crypto::available()) {
            $html = '<h2>نیاز به OpenSSL</h2><p>برای ذخیرهٔ امن اطلاعات تأیید دو مرحله‌ای، افزونهٔ OpenSSL در PHP باید فعال باشد. آن را در تنظیمات هاست فعال کنید و سپس صفحه را دوباره باز کنید.</p><p><a href="' . esc_url(admin_url('plugins.php')) . '">بازگشت به افزونه‌ها</a></p>';
            self::page('نیاز به OpenSSL', $html);
        }

        if (isset($_POST['wsm_2fa_action'])) {
            self::handle_post($uid, $page);
        }

        if (!self::is_enrolled($uid)) {
            self::render_enroll($uid, $page, '');
        }
        if (!self::is_verified()) {
            self::render_verify($page, '');
        }
    }

    /* ---------------- ورود کد ---------------- */

    private static function fa_digits($text) {
        return strtr((string) $text, array(
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ));
    }

    private static function locked($uid) {
        return (int) get_transient('wsm_totp_fail_' . $uid) >= self::MAX_FAILS;
    }

    private static function fail($uid, $page, $mode) {
        $count = (int) get_transient('wsm_totp_fail_' . $uid) + 1;
        set_transient('wsm_totp_fail_' . $uid, $count, self::FAIL_WINDOW);
        WSM_Security::log('totp_failed', 'warning', 0, 'کد نادرست (' . $count . ' از ' . self::MAX_FAILS . ')');
        if ($count === self::MAX_FAILS && class_exists('WSM_Security')) {
            $ip = class_exists('WSM_Util') ? WSM_Util::client_ip() : '';
            WSM_Security::notify_security_sms('هشدار امنیتی: تلاش‌های ناموفق TOTP برای کاربر ' . (int)$uid, array('login_count'=>$count,'ip_address'=>$ip,'lock_until'=>'۱۵ دقیقه'));
        }
        if ($mode === 'enroll') {
            self::render_enroll($uid, $page, 'کد نادرست است.');
        }
        self::render_verify($page, 'کد نادرست است.');
    }

    private static function handle_post($uid, $page) {
        check_admin_referer('wsm_2fa');
        $action_raw = isset($_POST['wsm_2fa_action']) && is_scalar($_POST['wsm_2fa_action']) ? wp_unslash($_POST['wsm_2fa_action']) : '';
        $code_raw   = isset($_POST['wsm_code']) && is_scalar($_POST['wsm_code']) ? wp_unslash($_POST['wsm_code']) : '';
        $action = sanitize_key($action_raw);
        $code   = self::fa_digits($code_raw);
        $code   = preg_replace('/\s+/', '', $code);

        if (self::locked($uid)) {
            if ($action === 'enroll') {
                self::render_enroll($uid, $page, 'به‌دلیل تلاش‌های ناموفق، ۱۵ دقیقه صبر کنید.');
            }
            self::render_verify($page, 'به‌دلیل تلاش‌های ناموفق، ۱۵ دقیقه صبر کنید.');
        }

        if ($action === 'enroll') {
            $pending = get_transient('wsm_totp_pending_' . $uid);
            $secret  = $pending ? WSM_Crypto::decrypt($pending) : false;
            if ($secret === false) {
                self::render_enroll($uid, $page, 'مهلت ثبت به پایان رسیده است. صفحه را دوباره باز کنید.');
            }
            $counter = WSM_TOTP::verify($secret, $code, -1);
            if ($counter === false) {
                self::fail($uid, $page, 'enroll');
            }
            $enc = WSM_Crypto::encrypt($secret);
            if ($enc === false) {
                self::render_enroll($uid, $page, 'رمزنگاری در این سرور فعال نیست (افزونهٔ openssl).');
            }
            update_user_meta($uid, self::META_SECRET, $enc);
            update_user_meta($uid, self::META_COUNTER, (int) $counter);
            delete_transient('wsm_totp_pending_' . $uid);
            delete_transient('wsm_totp_fail_' . $uid);
            set_transient('wsm_2fa_ok_' . self::session_key(), 1, self::SESSION_TTL);
            WSM_Security::log('totp_enrolled', 'info', 0, 'کاربر ' . $uid . ' تأیید دو مرحله‌ای را فعال کرد');
            wp_safe_redirect(admin_url('admin.php?page=' . $page));
            exit;
        }

        if ($action === 'verify') {
            $enc    = (string) get_user_meta($uid, self::META_SECRET, true);
            $secret = $enc !== '' ? WSM_Crypto::decrypt($enc) : false;
            if ($secret === false) {
                self::render_verify($page, 'کلید تأیید دو مرحله‌ای قابل خواندن نیست. با مدیر سیستم تماس بگیرید.');
            }
            $last    = (int) get_user_meta($uid, self::META_COUNTER, true);
            $counter = WSM_TOTP::verify($secret, $code, $last);
            if ($counter === false) {
                self::fail($uid, $page, 'verify');
            }
            // One conditional SQL update is the atomic compare-and-swap; update_user_meta() alone
            // checks the old value before writing and is not sufficient against parallel requests.
            global $wpdb;
            $counter_saved = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s AND meta_value = %s LIMIT 1",
                (string) $counter, (int) $uid, self::META_COUNTER, (string) $last
            ));
            if ($counter_saved !== 1) {
                self::fail($uid, $page, 'verify');
            }
            delete_transient('wsm_totp_fail_' . $uid);
            set_transient('wsm_2fa_ok_' . self::session_key(), 1, self::SESSION_TTL);
            WSM_Security::log('totp_ok', 'info', 0, 'ورود به پنل تأیید شد');
            wp_safe_redirect(admin_url('admin.php?page=' . $page));
            exit;
        }

        wp_die('درخواست نامعتبر است.');
    }

    /* ---------------- صفحه‌ها ---------------- */

    private static function page($title, $html) {
        nocache_headers();
        $out = '<div dir="rtl" style="font-family:Tahoma,Vazirmatn,sans-serif;max-width:560px;margin:40px auto;line-height:1.9">'
            . $html . '</div>';
        wp_die($out, esc_html($title), array('response' => 200, 'back_link' => false));
    }

    private static function notice($text) {
        if ($text === '') {
            return '';
        }
        return '<p style="background:#fde8e8;color:#9b1c1c;padding:10px;border-radius:6px">' . esc_html($text) . '</p>';
    }

    private static function render_enroll($uid, $page, $error) {
        $pending = get_transient('wsm_totp_pending_' . $uid);
        $secret  = $pending ? WSM_Crypto::decrypt($pending) : false;
        if ($secret === false) {
            $secret = WSM_TOTP::generate_secret();
            $enc    = WSM_Crypto::encrypt($secret);
            if ($enc === false) {
                self::page('نیاز به OpenSSL', '<h2>خطای رمزنگاری</h2><p>افزونهٔ OpenSSL را در PHP فعال کنید و صفحه را دوباره باز کنید.</p>');
            }
            set_transient('wsm_totp_pending_' . $uid, $enc, 15 * MINUTE_IN_SECONDS);
        }
        $user   = wp_get_current_user();
        $issuer = get_bloginfo('name');
        $uri    = 'otpauth://totp/' . rawurlencode($issuer . ':' . $user->user_login)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
        $grouped = trim(chunk_split($secret, 4, ' '));

        $html  = '<h2>تأیید دو مرحله‌ای اجباری</h2>';
        $html .= '<p>برای ادامه، یک برنامهٔ احراز هویت مانند Google Authenticator یا Microsoft Authenticator را باز کنید و این کلید را وارد کنید:</p>';
        $html .= '<p style="font-size:18px;direction:ltr;text-align:center;background:#f3f4f6;padding:10px;border-radius:6px"><code>' . esc_html($grouped) . '</code></p>';
        $html .= '<p style="font-size:12px;word-break:break-all;direction:ltr">' . esc_html($uri) . '</p>';
        $html .= self::notice($error);
        $html .= '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . $page)) . '">';
        $html .= wp_nonce_field('wsm_2fa', '_wpnonce', true, false);
        $html .= '<input type="hidden" name="wsm_2fa_action" value="enroll">';
        $html .= '<label>کد ۶ رقمی برنامه: <input type="text" name="wsm_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required style="direction:ltr;font-size:18px;width:160px"></label> ';
        $html .= '<button type="submit" class="button button-primary">تأیید و ادامه</button>';
        $html .= '</form>';
        self::page('تأیید دو مرحله‌ای', $html);
    }

    private static function render_verify($page, $error) {
        $html  = '<h2>تأیید هویت</h2>';
        $html .= '<p>کد ۶ رقمی برنامهٔ احراز هویت را وارد کنید.</p>';
        $html .= self::notice($error);
        $html .= '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . $page)) . '">';
        $html .= wp_nonce_field('wsm_2fa', '_wpnonce', true, false);
        $html .= '<input type="hidden" name="wsm_2fa_action" value="verify">';
        $html .= '<label>کد: <input type="text" name="wsm_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required style="direction:ltr;font-size:18px;width:160px"></label> ';
        $html .= '<button type="submit" class="button button-primary">ورود به پنل</button>';
        $html .= '</form>';
        self::page('تأیید هویت', $html);
    }
}

