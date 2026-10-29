<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * آداپتر پیامک ملی‌پیامک برای فیلتر wsm_send_sms.
 *
 * حالت دستی در گزینهٔ wsm_sms ذخیره می‌شود و رمز افزونه فقط به‌صورت رمزنگاری‌شده (AES-256-GCM) نگه داشته می‌شود.
 * حالت خودکار فقط منبعی را می‌خواند که درگاه ملی‌پیامکِ انتخاب‌شده یا زیرشاخهٔ صریح melipayamak داشته باشد؛ در نبود آن به تنظیم دستیِ کامل برمی‌گردد.
 *
 * مستندات رسمی ملی‌پیامک:
 *  - ارسال ساده: POST فرم (form-urlencoded) به https://rest.payamak-panel.com/api/SendSMS/SendSMS
 *  - متن پیامک باید با عبارت «لغو11» تمام شود (الزام سازمان تنظیم مقررات).
 *  - نتیجهٔ ۱ یعنی موفق؛ کدهای ۰ تا ۱۸ و -108 تا -110 خطا هستند؛ عدد بزرگ (شناسهٔ پیامک) هم موفقیت است.
 */
class WSM_SMS {
    const OPTION   = 'wsm_sms';
    const ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
    const NUMBERS_ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/GetUserNumbers';
    const SUFFIX   = 'لغو11';

    private static $theme_discovery_cache = null;
    private static $plugin_discovery_cache = null;

    public static function boot() {
        add_filter('wsm_send_sms', array(__CLASS__, 'send'), 10, 2);
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'notify_new_order'), 10, 3);
        add_action('rest_api_init', array(__CLASS__, 'register_app_settings_routes'));
    }

    /** هشدار سفارش جدید به مدیر فروشگاه (فقط پس از ثبت سفارش در checkout). */
    public static function notify_new_order($order_id, $posted_data = array(), $order = null) {
        $settings = WSM_Util::settings();
        if (empty($settings['notify_new_order'])) {
            return;
        }
        if (!$order || !is_a($order, 'WC_Order')) {
            $order = wc_get_order((int) $order_id);
        }
        if (!$order || $order->get_meta('_wsm_invoice')) {
            return;
        }
        $message = 'سفارش جدید #' . $order->get_order_number() . ' ثبت شد؛ مبلغ ' . $order->get_total() . ' ' . wsm_currency_symbol();
        WSM_Security::notify_sms($message);
    }

    /** Raw, encrypted plugin settings. Theme credentials are resolved only for sending. */
    public static function config() {
        $cfg = get_option(self::OPTION, array());
        return is_array($cfg) ? $cfg : array();
    }

    private static function reset_discovery_cache() {
        self::$theme_discovery_cache = null;
        self::$plugin_discovery_cache = null;
    }

    /** Default to automatic provider discovery while preserving prior manual credentials as fallback. */
    private static function source_mode($config = null) {
        if (!is_array($config)) { $config = self::config(); }
        return isset($config['mode']) && $config['mode'] === 'manual' ? 'manual' : 'auto';
    }

    private static function password_value($config) {
        if (!is_array($config)) { return ''; }
        if (array_key_exists('_password_plain', $config) && is_scalar($config['_password_plain'])) {
            return (string) $config['_password_plain'];
        }
        if (!empty($config['password_enc']) && class_exists('WSM_Crypto')) {
            $password = WSM_Crypto::decrypt((string) $config['password_enc']);
            return is_string($password) ? $password : '';
        }
        return '';
    }

    /** Fingerprint the exact provider configuration tested, without storing the secret itself. */
    private static function test_fingerprint($config) {
        $parts = array(
            isset($config['_effective_source']) ? (string) $config['_effective_source'] : '',
            isset($config['username']) && is_scalar($config['username']) ? (string) $config['username'] : '',
            self::password_value($config),
            isset($config['from']) && is_scalar($config['from']) ? (string) $config['from'] : '',
            isset($config['admin_mobile']) && is_scalar($config['admin_mobile']) ? (string) $config['admin_mobile'] : '',
        );
        $payload = function_exists('wp_json_encode') ? wp_json_encode($parts) : json_encode($parts);
        if (!is_string($payload)) { $payload = serialize($parts); }
        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    /** Username, password and sender are required for the MeliPayamak API. */
    private static function credentials_ready($config) {
        if (!is_array($config)) { return false; }
        $username = isset($config['username']) && is_scalar($config['username']) ? trim((string) $config['username']) : '';
        $from = isset($config['from']) && is_scalar($config['from']) ? trim((string) $config['from']) : '';
        return $username !== '' && $from !== '' && self::password_value($config) !== '';
    }

    /** A discovered sender belongs only to this exact provider option and credential pair. */
    private static function auto_sender_fingerprint($theme) {
        $credentials = isset($theme['credentials']) && is_array($theme['credentials']) ? $theme['credentials'] : array();
        if (empty($theme['option_name']) || !self::sms_discovery_value_usable($credentials['username'] ?? '') || !self::sms_discovery_value_usable($credentials['password'] ?? '')) { return ''; }
        $parts = array(
            (string) $theme['option_name'], (string) ($theme['scope'] ?? ''),
            (string) $credentials['username'], (string) $credentials['password'],
        );
        $payload = function_exists('wp_json_encode') ? wp_json_encode($parts) : json_encode($parts);
        if (!is_string($payload)) { return ''; }
        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    /** Reuse a line only when its signature still matches the discovered option and credentials. */
    private static function with_cached_auto_sender($theme, $stored) {
        if (!is_array($theme) || !is_array($stored) || !empty($theme['complete']) || !empty($theme['ambiguous'])) { return $theme; }
        $credentials = isset($theme['credentials']) && is_array($theme['credentials']) ? $theme['credentials'] : array();
        if (self::sms_discovery_value_usable($credentials['from'] ?? '')) { return $theme; }
        $fingerprint = self::auto_sender_fingerprint($theme);
        $sender = isset($stored['auto_sender']) && is_scalar($stored['auto_sender']) ? trim((string) $stored['auto_sender']) : '';
        $signature = isset($stored['auto_sender_sig']) && is_string($stored['auto_sender_sig']) ? $stored['auto_sender_sig'] : '';
        $has_cached_sender = array_key_exists('auto_sender', $stored) || array_key_exists('auto_sender_sig', $stored);
        if ($has_cached_sender && ($fingerprint === '' || $signature === '' || !hash_equals($fingerprint, $signature))) {
            $theme['sender_signature_invalid'] = true;
            return $theme;
        }
        if ($fingerprint === '' || $sender === '' || $signature === '' || !hash_equals($fingerprint, $signature) || !preg_match('/^[0-9]{4,20}$/D', $sender)) { return $theme; }
        $theme['credentials']['from'] = $sender;
        if (!isset($theme['keys']) || !is_array($theme['keys'])) { $theme['keys'] = array(); }
        $theme['keys']['from'] = 'GetUserNumbers (single account line)';
        $theme['complete'] = true;
        $theme['sender_autodetected'] = true;
        return $theme;
    }

    /** Resolve effective SMS credentials without ever returning them from a public API response. */
    private static function resolve_config($stored = null, $theme = null, $log_fallback = true, $plugin = null) {
        if (!is_array($stored)) { $stored = self::config(); }
        $mode = self::source_mode($stored);
        if ($mode === 'manual') {
            $stored['_effective_source'] = 'manual';
            return $stored;
        }
        if (!is_array($plugin)) { $plugin = self::discover_plugin_sms_config(); }
        if (!is_array($theme)) {
            // Avoid a full theme-options scan for plugin-only installations unless the named target option exists.
            if (empty($plugin['complete']) || get_option('digit_melipayamak', null) !== null) {
                $theme = self::discover_theme_sms_config();
            }
        }
        if (is_array($theme)) { $theme = self::with_cached_auto_sender($theme, $stored); }
        $digit_source_selected = self::discovery_uses_digit_melipayamak($theme);
        if ($digit_source_selected && !empty($theme['complete']) && !empty($theme['credentials']) && is_array($theme['credentials'])) {
            $effective = $stored;
            $effective['username'] = (string) $theme['credentials']['username'];
            $effective['from'] = (string) $theme['credentials']['from'];
            $effective['_password_plain'] = (string) $theme['credentials']['password'];
            if ((!isset($effective['admin_mobile']) || !is_scalar($effective['admin_mobile']) || trim((string) $effective['admin_mobile']) === '') && !empty($theme['credentials']['admin_mobile'])) {
                $effective['admin_mobile'] = (string) $theme['credentials']['admin_mobile'];
            }
            $effective['_effective_source'] = 'theme';
            return $effective;
        }
        if ($digit_source_selected && (!empty($theme['ambiguous']) || !empty($theme['sender_signature_invalid']))) {
            if ($log_fallback) { self::log_discovery_fallback($plugin, $theme, false); }
            $stored['_effective_source'] = 'none';
            return $stored;
        }
        if (!$digit_source_selected && !empty($plugin['complete']) && !empty($plugin['credentials']) && is_array($plugin['credentials'])) {
            $effective = $stored;
            $effective['username'] = (string) $plugin['credentials']['username'];
            $effective['from'] = (string) $plugin['credentials']['from'];
            $effective['_password_plain'] = (string) $plugin['credentials']['password'];
            if ((!isset($effective['admin_mobile']) || !is_scalar($effective['admin_mobile']) || trim((string) $effective['admin_mobile']) === '') && !empty($plugin['credentials']['admin_mobile'])) {
                $effective['admin_mobile'] = (string) $plugin['credentials']['admin_mobile'];
            }
            $effective['_effective_source'] = 'plugin';
            return $effective;
        }
        if (is_array($theme) && !empty($theme['complete']) && !empty($theme['credentials']) && is_array($theme['credentials'])) {
            $effective = $stored;
            $effective['username'] = (string) $theme['credentials']['username'];
            $effective['from'] = (string) $theme['credentials']['from'];
            $effective['_password_plain'] = (string) $theme['credentials']['password'];
            if ((!isset($effective['admin_mobile']) || !is_scalar($effective['admin_mobile']) || trim((string) $effective['admin_mobile']) === '') && !empty($theme['credentials']['admin_mobile'])) {
                $effective['admin_mobile'] = (string) $theme['credentials']['admin_mobile'];
            }
            $effective['_effective_source'] = 'theme';
            return $effective;
        }
        if (is_array($theme) && !empty($theme['sender_signature_invalid'])) {
            if ($log_fallback) { self::log_discovery_fallback($plugin, $theme, false); }
            $stored['_effective_source'] = 'none';
            return $stored;
        }
        $manual_ready = self::credentials_ready($stored);
        if ($log_fallback) { self::log_discovery_fallback($plugin, $theme, $manual_ready); }
        $stored['_effective_source'] = $manual_ready ? 'manual_fallback' : 'none';
        return $stored;
    }

    public static function effective_config() {
        return self::resolve_config();
    }

    /** Explicit administrator disable wins; otherwise complete discovered credentials enable SMS effectively. */
    private static function enabled_value($value) {
        if (is_bool($value)) { return $value; }
        if (is_int($value) || is_float($value)) { return (int) $value === 1; }
        if (is_string($value)) { return in_array(strtolower(trim($value)), array('1', 'true', 'yes', 'on'), true); }
        return false;
    }

    /** آیا هشدار پیامکی روشن است؟ */
    public static function is_enabled() {
        $c = self::config();
        if (array_key_exists('enabled', $c)) { return self::enabled_value($c['enabled']); }
        return self::credentials_ready(self::resolve_config($c, null, false));
    }

    /** Credentials are considered configured even when the optional manager destination is blank. */
    public static function is_configured() {
        return self::credentials_ready(self::effective_config());
    }

    /** آیا هشدار هم روشن است و هم پیکربندی کامل؟ */
    public static function is_active() {
        return self::is_enabled() && self::is_configured();
    }

    /** Register a small, permission-checked settings API for the app SMS tab. */
    public static function register_app_settings_routes() {
        if (!function_exists('register_rest_route')) { return; }
        $namespace = class_exists('WSM_Rest_API') ? WSM_Rest_API::NS : 'wsm/v1';
        register_rest_route($namespace, '/sms/config', array(
            array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'app_config_get'),
                'permission_callback' => array(__CLASS__, 'app_config_permission'),
            ),
            array(
                'methods' => 'PUT',
                'callback' => array(__CLASS__, 'app_config_update'),
                'permission_callback' => array(__CLASS__, 'app_config_permission'),
            ),
        ));
        register_rest_route($namespace, '/sms/test', array(
            array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'app_config_test'),
                'permission_callback' => array(__CLASS__, 'app_config_permission'),
            ),
        ));
    }

    /** Bearer + HMAC authentication and settings permission for the custom SMS route. */
    public static function app_config_permission($request) {
        if (!class_exists('WSM_Util') || !WSM_Util::is_woo_active()) {
            return class_exists('WSM_Util') ? WSM_Util::woo_required_error() : new WP_Error('wsm_unavailable', 'افزونه آماده نیست.', array('status' => 503));
        }
        if (class_exists('WSM_Security') && WSM_Security::blocked_until()) {
            return new WP_Error('wsm_ip_blocked', 'IP شما موقتاً مسدود است. بعد از پایان مدت مسدودی دوباره تلاش کنید.', array('status' => 429));
        }
        $token = WSM_Permissions::extract_token($request);
        if ($token === '') { return new WP_Error('wsm_no_token', 'توکن ارسال نشده است.', array('status' => 401)); }
        $device = WSM_Auth::validate_bearer($token);
        if (is_wp_error($device)) { return $device; }
        $signed = WSM_Security::verify_signature($request, $device);
        if (is_wp_error($signed)) { return $signed; }
        $user = get_userdata((int) $device->user_id);
        if (!$user) { return new WP_Error('wsm_forbidden', 'دسترسی به این بخش ندارید.', array('status' => 403)); }
        if (get_user_meta((int) $device->user_id, 'wsm_app_user_status', true) === 'inactive') {
            return new WP_Error('wsm_app_user_inactive', 'حساب کاربری این اپ غیرفعال شده است.', array('status' => 403));
        }
        $permission_record = WSM_Permissions::user_permissions_record((int) $device->user_id);
        $app_role = (string) get_user_meta((int) $device->user_id, 'wsm_app_role', true);
        $roles = is_array($user->roles) ? $user->roles : array();
        $wp_admin = WSM_Permissions::is_wordpress_admin_user($user);
        $legacy_manager = $wp_admin || in_array('administrator', $roles, true) || in_array('shop_manager', $roles, true) ||
            (!empty($user->allcaps['manage_woocommerce']) || !empty($user->allcaps['manage_options']));
        $app_account = get_user_meta((int) $device->user_id, 'wsm_app_user_status', true) === 'active' || $app_role !== '' || $permission_record !== null;
        if (!$legacy_manager && !$app_account) { return new WP_Error('wsm_forbidden', 'دسترسی به این بخش ندارید.', array('status' => 403)); }
        WSM_Permissions::set_device($device);
        $method = strtoupper((string) $request->get_method());
        $route = method_exists($request, 'get_route') ? untrailingslashit((string) $request->get_route()) : '';
        $can_club_sms_view = WSM_Devices::device_can($device, 'club.sms.view') ||
            WSM_Devices::device_can($device, 'club.sms.manage') ||
            WSM_Devices::device_can($device, 'club.sms.send') ||
            WSM_Devices::device_can($device, 'club.sms.bulk_send') ||
            WSM_Devices::device_can($device, 'club.sms.templates.manage') ||
            WSM_Devices::device_can($device, 'club.sms.logs.view') ||
            WSM_Devices::device_can($device, 'club.sms.recipients.export');
        $can_edit = WSM_Devices::device_can($device, 'settings.edit') ||
            WSM_Devices::device_can($device, 'club.sms.manage') ||
            WSM_Devices::device_can($device, 'club.settings.manage');
        $can_view = WSM_Devices::device_can($device, 'settings.view') || $can_edit || $can_club_sms_view;
        $is_test_route = substr($route, -9) === '/sms/test';
        $allowed = $method === 'GET' ? $can_view : ($is_test_route ? $can_edit : WSM_Devices::device_can($device, 'settings.edit'));
        if (!$wp_admin && !$allowed) {
            $needed = $method === 'GET' ? array('settings.view', 'settings.edit', 'club.sms.view') : ($is_test_route ? array('settings.edit', 'club.sms.manage') : 'settings.edit');
            return new WP_Error('wsm_device_permission_denied', 'برای تنظیم سرویس پیامک، مجوز ویرایش تنظیمات عمومی لازم است.', array('status' => 403, 'permission' => $needed));
        }
        return true;
    }

    /** Send one explicitly requested test SMS to the configured manager phone. */
    public static function app_config_test($request) {
        $result = self::send_test();
        if (empty($result['ok'])) {
            return new WP_Error(
                'wsm_sms_test_failed',
                isset($result['detail']) && $result['detail'] !== '' ? (string) $result['detail'] : 'ارسال پیامک آزمایشی ناموفق بود.',
                array('status' => 400)
            );
        }
        return array(
            'ok' => true,
            'sent' => true,
            'detail' => isset($result['detail']) ? (string) $result['detail'] : 'پیامک آزمایشی ارسال شد.',
            'message' => isset($result['detail']) ? (string) $result['detail'] : 'پیامک آزمایشی ارسال شد.',
            'provider_ref' => isset($result['provider_ref']) ? (string) $result['provider_ref'] : '',
        );
    }

    /** Return safe status and field paths; never return a password value. */
    public static function app_config_get($request) {
        $stored = self::config();
        $mode = self::source_mode($stored);
        $plugin = self::discover_plugin_sms_config();
        $theme = self::with_cached_auto_sender(self::discover_theme_sms_config(), $stored);
        $effective = self::resolve_config($stored, $theme, true, $plugin);
        $device = WSM_Permissions::current_device();
        $user = $device ? get_userdata((int) $device->user_id) : false;
        $can_edit = $user && (WSM_Permissions::is_wordpress_admin_user($user) || WSM_Devices::device_can($device, 'settings.edit'));
        $manual = array(
            'username' => '',
            'username_set' => $can_edit && isset($stored['username']) && is_scalar($stored['username']) && trim((string) $stored['username']) !== '',
            'from' => '',
            'from_set' => $can_edit && isset($stored['from']) && is_scalar($stored['from']) && trim((string) $stored['from']) !== '',
            'admin_mobile' => '',
            'admin_mobile_set' => $can_edit && isset($stored['admin_mobile']) && is_scalar($stored['admin_mobile']) && trim((string) $stored['admin_mobile']) !== '',
            'password_set' => self::password_value($stored) !== '',
            'configured' => self::credentials_ready($stored),
        );
        return array(
            'mode' => $mode,
            'enabled' => self::is_enabled(),
            'enabled_explicit' => array_key_exists('enabled', $stored),
            'manual' => $manual,
            'plugin' => self::public_discovery_status($plugin, 'افزونهٔ پیامک'),
            'theme' => self::public_discovery_status($theme, 'قالب'),
            'effective_source' => isset($effective['_effective_source']) ? $effective['_effective_source'] : 'none',
            'fallback_used' => isset($effective['_effective_source']) && $effective['_effective_source'] === 'manual_fallback',
            'configured' => self::credentials_ready($effective),
            'active' => self::is_enabled() && self::credentials_ready($effective),
            'verified' => self::is_enabled() && self::credentials_ready($effective) && get_transient('wsm_sms_provider_test_v1') === self::test_fingerprint($effective), // Provider acceptance only; not delivery confirmation.
        );
    }

    /** Save only allow-listed settings; the caller must authenticate before reaching this method. */
    public static function app_config_update($request) {
        $body = method_exists($request, 'get_json_params') ? $request->get_json_params() : array();
        if (!is_array($body)) { $body = method_exists($request, 'get_body_params') ? $request->get_body_params() : array(); }
        return self::save_config($body);
    }

    /** Shared wp-admin/app save path. Callers must perform their own nonce or Bearer/HMAC checks. */
    public static function save_config($body) {
        if (!is_array($body)) { return new WP_Error('wsm_sms_bad_config', 'ساختار تنظیمات پیامک نامعتبر است.', array('status' => 400)); }
        $stored = self::config();
        $old_effective = self::resolve_config($stored, null, false);
        $old_fingerprint = self::test_fingerprint($old_effective);
        $mode = isset($body['mode']) && is_scalar($body['mode']) ? sanitize_key((string) $body['mode']) : self::source_mode($stored);
        if (!in_array($mode, array('auto', 'manual'), true)) { return new WP_Error('wsm_sms_bad_mode', 'حالت پیامک باید خودکار یا دستی باشد.', array('status' => 400)); }
        $stored['mode'] = $mode;
        if (array_key_exists('enabled', $body)) {
            $enabled_value = $body['enabled'];
            if (is_bool($enabled_value)) {
                $stored['enabled'] = $enabled_value ? 1 : 0;
            } elseif (is_int($enabled_value) && in_array($enabled_value, array(0, 1), true)) {
                $stored['enabled'] = $enabled_value;
            } elseif (is_string($enabled_value) && in_array(strtolower(trim($enabled_value)), array('0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'), true)) {
                $stored['enabled'] = rest_sanitize_boolean($enabled_value) ? 1 : 0;
            } else {
                return new WP_Error('wsm_sms_bad_enabled', 'وضعیت فعال‌سازی سرویس پیامک نامعتبر است.', array('status' => 400));
            }
        } elseif (!array_key_exists('enabled', $stored)) {
            // The first explicit save must persist a real checkbox state.
            $stored['enabled'] = 1;
        }
        if (array_key_exists('admin_mobile', $body)) {
            if (!is_scalar($body['admin_mobile'])) { return new WP_Error('wsm_sms_bad_mobile', 'شمارهٔ مدیر معتبر نیست.', array('status' => 400)); }
            $admin_mobile = preg_replace('/[^0-9+]/', '', sanitize_text_field((string) $body['admin_mobile']));
            if ($admin_mobile !== '' && (strlen($admin_mobile) < 7 || strlen($admin_mobile) > 20)) {
                return new WP_Error('wsm_sms_bad_mobile', 'شمارهٔ مدیر معتبر نیست.', array('status' => 400));
            }
            $stored['admin_mobile'] = $admin_mobile;
        }
        if ($mode === 'manual') {
            foreach (array('username', 'from') as $key) {
                if (!array_key_exists($key, $body)) { continue; }
                if (!is_scalar($body[$key])) { return new WP_Error('wsm_sms_bad_field', 'یکی از فیلدهای تنظیمات دستی پیامک نامعتبر است.', array('status' => 400)); }
                $value = sanitize_text_field((string) $body[$key]);
                if ($key === 'username' && strlen($value) > 191) { return new WP_Error('wsm_sms_bad_username', 'نام کاربری پیامک بیش از حد طولانی است.', array('status' => 400)); }
                if ($key === 'from') {
                    $value = preg_replace('/[^0-9+]/', '', $value);
                    if (strlen($value) > 100) { return new WP_Error('wsm_sms_bad_sender', 'شمارهٔ ارسال‌کننده بیش از حد طولانی است.', array('status' => 400)); }
                }
                $stored[$key] = $value;
            }
            if (array_key_exists('password', $body)) {
                if (!is_scalar($body['password'])) { return new WP_Error('wsm_sms_bad_password', 'رمز/کلید ملی‌پیامک نامعتبر است.', array('status' => 400)); }
                $password = (string) $body['password'];
                if (strlen($password) > 512) { return new WP_Error('wsm_sms_bad_password', 'رمز/کلید ملی‌پیامک بیش از حد طولانی است.', array('status' => 400)); }
                if (trim($password) !== '') {
                    if (!class_exists('WSM_Crypto')) { return new WP_Error('wsm_sms_crypto_unavailable', 'رمزنگاری امن رمز پیامک در دسترس نیست.', array('status' => 500)); }
                    $encrypted = WSM_Crypto::encrypt($password);
                    if (!is_string($encrypted) || $encrypted === '') { return new WP_Error('wsm_sms_crypto_unavailable', 'رمزنگاری امن رمز پیامک در دسترس نیست.', array('status' => 500)); }
                    $stored['password_enc'] = $encrypted;
                }
            }
            if (!self::credentials_ready($stored) && trim((string) ($stored['username'] ?? '')) !== '' && self::password_value($stored) !== '' && trim((string) ($stored['from'] ?? '')) === '') {
                $sender = self::discover_sender_number((string) $stored['username'], self::password_value($stored));
                if (empty($sender['ok'])) { return new WP_Error('wsm_sms_sender_required', $sender['detail'], array('status' => 400)); }
                $stored['from'] = $sender['from'];
            }
            if (!self::credentials_ready($stored) && !empty($stored['enabled'])) {
                return new WP_Error('wsm_sms_manual_incomplete', 'برای فعال نگه‌داشتن حالت دستی، نام کاربری، رمز و شمارهٔ ارسال‌کننده را وارد کنید. برای حفظ رمز ذخیره‌شده، فیلد رمز را خالی بگذارید.', array('status' => 400));
            }
        }
        if ($mode === 'auto') {
            $theme = self::with_cached_auto_sender(self::discover_theme_sms_config(), $stored);
            $credentials = isset($theme['credentials']) && is_array($theme['credentials']) ? $theme['credentials'] : array();
            $has_from = self::sms_discovery_value_usable($credentials['from'] ?? '');
            $has_user = self::sms_discovery_value_usable($credentials['username'] ?? '');
            $has_password = self::sms_discovery_value_usable($credentials['password'] ?? '');
            if (!empty($stored['enabled']) && empty($theme['complete']) && empty($theme['ambiguous']) && !$has_from && $has_user && $has_password) {
                $sender = self::discover_sender_number((string) $credentials['username'], (string) $credentials['password']);
                if (empty($sender['ok'])) { return new WP_Error('wsm_sms_auto_sender_required', $sender['detail'], array('status' => 400)); }
                $signature = self::auto_sender_fingerprint($theme);
                if ($signature === '') { return new WP_Error('wsm_sms_auto_sender_required', 'تنظیمات منبع ملی‌پیامک برای ذخیرهٔ امن خط فرستنده معتبر نیست.', array('status' => 400)); }
                $stored['auto_sender'] = $sender['from'];
                $stored['auto_sender_sig'] = $signature;
            }
        }
        update_option(self::OPTION, $stored, false);
        $saved = get_option(self::OPTION, array());
        if (!is_array($saved) || self::source_mode($saved) !== $mode) {
            return new WP_Error('wsm_sms_save_failed', 'ذخیرهٔ تنظیمات پیامک انجام نشد.', array('status' => 500));
        }
        if (array_key_exists('enabled', $stored) && (int) (!empty($saved['enabled'])) !== (int) (!empty($stored['enabled']))) {
            return new WP_Error('wsm_sms_save_failed', 'ذخیرهٔ وضعیت فعال‌سازی پیامک تأیید نشد.', array('status' => 500));
        }
        if ($mode === 'auto' && isset($stored['auto_sender_sig']) && (
            (string) ($saved['auto_sender'] ?? '') !== (string) ($stored['auto_sender'] ?? '') ||
            (string) ($saved['auto_sender_sig'] ?? '') !== (string) $stored['auto_sender_sig'])) {
            return new WP_Error('wsm_sms_save_failed', 'ذخیرهٔ خط شناسایی‌شدهٔ ملی‌پیامک تأیید نشد.', array('status' => 500));
        }
        if (array_key_exists('admin_mobile', $body) && (string) ($saved['admin_mobile'] ?? '') !== (string) ($stored['admin_mobile'] ?? '')) {
            return new WP_Error('wsm_sms_save_failed', 'ذخیرهٔ شمارهٔ مدیر تأیید نشد.', array('status' => 500));
        }
        if ($mode === 'manual') {
            foreach (array('username', 'from') as $key) {
                if (array_key_exists($key, $stored) && (!array_key_exists($key, $saved) || (string) $saved[$key] !== (string) $stored[$key])) {
                    return new WP_Error('wsm_sms_save_failed', 'ذخیرهٔ یکی از فیلدهای دستی پیامک تأیید نشد.', array('status' => 500));
                }
            }
            if (isset($body['password']) && is_scalar($body['password']) && trim((string) $body['password']) !== '' && self::password_value($saved) !== (string) $body['password']) {
                return new WP_Error('wsm_sms_save_failed', 'ذخیرهٔ رمز ملی‌پیامک تأیید نشد.', array('status' => 500));
            }
        }
        self::reset_discovery_cache();
        $new_effective = self::resolve_config($saved, null, false);
        if ($old_fingerprint !== self::test_fingerprint($new_effective)) {
            delete_transient('wsm_sms_provider_test_v1');
        }
        return self::app_config_get(null);
    }

    private static function public_discovery_status($discovery, $label) {
        $keys = isset($discovery['keys']) && is_array($discovery['keys']) ? $discovery['keys'] : array();
        $candidates = array();
        foreach ((array) (isset($discovery['candidates']) ? $discovery['candidates'] : array()) as $candidate) {
            if (!is_array($candidate)) { continue; }
            $candidates[] = array(
                'option_name' => isset($candidate['option_name']) ? (string) $candidate['option_name'] : '',
                'keys' => isset($candidate['keys']) && is_array($candidate['keys']) ? $candidate['keys'] : array(),
                'complete' => !empty($candidate['complete']),
            );
        }
        if (!empty($discovery['ambiguous'])) {
            $message = 'چند گزینهٔ ' . $label . ' هم‌زمان اطلاعات پیامک دارند؛ برای جلوگیری از انتخاب اشتباه، هیچ‌کدام خودکار استفاده نمی‌شود.';
        } elseif (!empty($discovery['digit_source_present']) && empty($discovery['keys'])) {
            $message = 'گزینهٔ digit_melipayamak موجود است، اما فیلدهای منبع از روی این نسخه به‌طور امن شناسایی نشدند؛ ساختار واقعی را بررسی کنید.';
        } elseif (!empty($discovery['sender_signature_invalid'])) {
            $message = 'خط فرستندهٔ کشف‌شده با منبع یا اعتبارنامهٔ فعلی تطبیق ندارد؛ برای امنیت، تنظیمات خودکار را دوباره ذخیره کنید.';
        } elseif (!empty($discovery['sender_autodetected'])) {
            $message = 'اطلاعات ملی‌پیامک از وردپرس خوانده شد و تنها خط فرستنده با ذخیرهٔ صریح تنظیمات شناسایی شده است.';
        } elseif (!empty($discovery['complete'])) {
            $message = 'اطلاعات کامل پیامک در ' . $label . ' پیدا شد.';
        } elseif (!empty($discovery['option_name'])) {
            $message = 'گزینهٔ ' . $label . ' پیدا شد، اما اطلاعات نام کاربری، رمز و فرستنده کامل نیست.';
        } else {
            $message = 'گزینهٔ پیامک در ' . $label . ' پیدا نشد.';
        }
        return array(
            'provider' => (string) $label,
            'found' => !empty($discovery['option_name']),
            'complete' => !empty($discovery['complete']),
            'ambiguous' => !empty($discovery['ambiguous']),
            'sender_autodetected' => !empty($discovery['sender_autodetected']),
            'sender_signature_invalid' => !empty($discovery['sender_signature_invalid']),
            'digit_source_present' => !empty($discovery['digit_source_present']),
            'option_name' => isset($discovery['option_name']) ? (string) $discovery['option_name'] : '',
            'keys' => $keys,
            'fields' => array_keys($keys),
            'candidates' => $candidates,
            'message' => $message,
        );
    }

    /** Finalize candidates using a deterministic score and fail closed on tied, different credentials. */
    private static function finalize_discovery_candidates($candidates) {
        $empty = array('complete' => false, 'ambiguous' => false, 'option_name' => '', 'scope' => '', 'keys' => array(), 'credentials' => array(), 'candidates' => array(), 'digit_source_present' => false);
        if (!$candidates) { return $empty; }
        usort($candidates, array(__CLASS__, 'compare_theme_candidates'));
        $digit_candidates = array_values(array_filter($candidates, function ($candidate) { return strtolower((string) ($candidate['option_name'] ?? '')) === 'digit_melipayamak'; }));
        $complete_digit_candidates = array_values(array_filter($digit_candidates, function ($candidate) { return !empty($candidate['complete']); }));
        $complete_candidates = array_values(array_filter($candidates, function ($candidate) { return !empty($candidate['complete']); }));
        $pool = $digit_candidates ? ($complete_digit_candidates ? $complete_digit_candidates : $digit_candidates) : ($complete_candidates ? $complete_candidates : $candidates);
        $top = $pool[0];
        $top_score = isset($top['score']) ? (int) $top['score'] : 0;
        $safe_candidates = array();
        $ambiguous = false;
        foreach ($pool as $candidate) {
            if ((int) ($candidate['score'] ?? 0) !== $top_score) { break; }
            if (self::discovery_credentials_conflict($top['credentials'] ?? array(), $candidate['credentials'] ?? array())) { $ambiguous = true; }
            if (count($safe_candidates) < 5) {
                $safe_candidates[] = array(
                    'option_name' => isset($candidate['option_name']) ? (string) $candidate['option_name'] : '',
                    'keys' => isset($candidate['keys']) && is_array($candidate['keys']) ? $candidate['keys'] : array(),
                    'complete' => !empty($candidate['complete']),
                );
            }
        }
        if ($ambiguous) {
            return array('complete' => false, 'ambiguous' => true, 'option_name' => '', 'keys' => array(), 'credentials' => array(), 'candidates' => $safe_candidates);
        }
        return array(
            'complete' => !empty($top['complete']),
            'ambiguous' => false,
            'option_name' => isset($top['option_name']) ? (string) $top['option_name'] : '',
            'scope' => isset($top['scope']) ? (string) $top['scope'] : '',
            'keys' => isset($top['keys']) && is_array($top['keys']) ? $top['keys'] : array(),
            'credentials' => isset($top['credentials']) && is_array($top['credentials']) ? $top['credentials'] : array(),
            'candidates' => array(array(
                'option_name' => isset($top['option_name']) ? (string) $top['option_name'] : '',
                'keys' => isset($top['keys']) && is_array($top['keys']) ? $top['keys'] : array(),
                'complete' => !empty($top['complete']),
            )),
        );
    }

    private static function discovery_uses_digit_melipayamak($discovery) {
        if (!is_array($discovery)) { return false; }
        if (!empty($discovery['digit_source_present']) || strtolower((string) ($discovery['option_name'] ?? '')) === 'digit_melipayamak') { return true; }
        foreach ((array) ($discovery['candidates'] ?? array()) as $candidate) {
            if (is_array($candidate) && strtolower((string) ($candidate['option_name'] ?? '')) === 'digit_melipayamak') { return true; }
        }
        return false;
    }

    private static function discovery_credentials_conflict($left, $right) {
        foreach (array('username', 'password', 'from', 'admin_mobile') as $field) {
            $a = isset($left[$field]) && is_scalar($left[$field]) ? (string) $left[$field] : '';
            $b = isset($right[$field]) && is_scalar($right[$field]) ? (string) $right[$field] : '';
            if ($a !== $b) { return true; }
        }
        return false;
    }

    private static function is_melipayamak_provider($provider) {
        $key = self::normalized_option_key($provider);
        return strpos($key, 'melipayamak') !== false || strpos($key, 'mellipayamak') !== false;
    }

    /** Read only an explicitly selected provider field; never infer it from the presence of credentials. */
    private static function selected_sms_provider($value) {
        if (is_object($value)) { $value = get_object_vars($value); }
        if (!is_array($value)) { return ''; }
        foreach (array('sms_gateway', 'gateway_name', 'provider', 'gateway') as $key) {
            if (isset($value[$key]) && is_scalar($value[$key])) {
                $provider = trim((string) $value[$key]);
                if ($provider !== '') { return $provider; }
            }
        }
        return '';
    }

    /** Discover only the selected MeliPayamak source inside active-theme options. */
    private static function discover_theme_sms_config() {
        if (is_array(self::$theme_discovery_cache)) { return self::$theme_discovery_cache; }
        $theme_slugs = array();
        $raw_slugs = array(get_option('stylesheet', ''), get_option('template', ''));
        if (function_exists('wp_get_theme')) {
            $theme_object = wp_get_theme();
            if ($theme_object) {
                $raw_slugs[] = $theme_object->get_stylesheet();
                $raw_slugs[] = $theme_object->get_template();
                $raw_slugs[] = $theme_object->get('Name');
            }
        }
        foreach ($raw_slugs as $slug) {
            if (!is_scalar($slug)) { continue; }
            $slug = sanitize_title((string) $slug);
            if ($slug !== '') { $theme_slugs[$slug] = true; }
            foreach (preg_split('/[-_]+/', $slug) as $part) {
                if (strlen($part) >= 5) { $theme_slugs[$part] = true; }
            }
        }
        $generic_names = array('theme_options', 'my_theme_options', 'theme-options', 'theme_settings', 'theme_options_data', 'theme_option', 'digit_melipayamak');
        $option_names = array();
        foreach ($generic_names as $name) { $option_names[$name] = true; }
        foreach (array_keys($theme_slugs) as $slug) {
            $base = str_replace('-', '_', $slug);
            foreach (array($base . '_options', $base . '_theme_options', $base . '_settings', $base . 'options') as $name) { $option_names[$name] = true; }
        }
        $all = function_exists('wp_load_alloptions') ? wp_load_alloptions() : array();
        if (is_array($all)) {
            foreach ($all as $name => $unused) {
                if (self::is_theme_option_candidate_name((string) $name, $theme_slugs, $generic_names)) { $option_names[(string) $name] = true; }
            }
        }
        global $wpdb;
        if (isset($wpdb) && isset($wpdb->options) && method_exists($wpdb, 'get_col') && method_exists($wpdb, 'prepare') && method_exists($wpdb, 'esc_like')) {
            $patterns = array('%theme%');
            foreach (array_keys($theme_slugs) as $slug) { if (strlen($slug) >= 5) { $patterns[] = '%' . $wpdb->esc_like($slug) . '%'; } }
            foreach (array_unique($patterns) as $pattern) {
                $sql = $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT 250", $pattern);
                $found_names = $wpdb->get_col($sql);
                foreach ((array) $found_names as $name) {
                    if (self::is_theme_option_candidate_name((string) $name, $theme_slugs, $generic_names)) { $option_names[(string) $name] = true; }
                }
            }
        }
        $option_names = array_slice(array_keys($option_names), 0, 500);
        $candidates = array();
        $digit_source_present = false;
        foreach ($option_names as $option_name) {
            if ($option_name === self::OPTION || strpos($option_name, 'theme_mods_') === 0 || strpos($option_name, '_transient_') === 0 || strpos($option_name, 'wsm_') === 0) { continue; }
            $value = get_option($option_name, null);
            if ($option_name === 'digit_melipayamak' && $value !== null) { $digit_source_present = true; }
            if (is_string($value)) {
                $trimmed = ltrim($value);
                if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) { $value = $decoded; }
                }
            }
            if (!is_array($value) && !is_object($value)) { continue; }
            $provider = self::selected_sms_provider($value);
            if ($provider !== '' && !self::is_melipayamak_provider($provider)) { continue; }
            $provider_is_meli = self::is_melipayamak_provider($provider);
            $explicit_option = self::is_melipayamak_provider($option_name);
            $groups = array();
            self::walk_theme_sms_fields($value, $option_name, array(), '', self::is_sms_context_key($option_name), $groups, 0);
            foreach ($groups as $scope => $fields) {
                if (!is_array($fields) || !$fields) { continue; }
                $scope_key = self::normalized_option_key($scope);
                $explicit_scope = self::is_melipayamak_provider($scope);
                $allowed_scope = $explicit_scope || ($explicit_option && $scope === 'root') || ($provider_is_meli && in_array($scope_key, array('root', 'sms'), true));
                if (!$allowed_scope) { continue; }
                $keys = array();
                $credentials = array();
                foreach ($fields as $field => $entry) {
                    if (!is_array($entry) || !isset($entry['path'])) { continue; }
                    $keys[$field] = (string) $entry['path'];
                    if (isset($entry['value'])) { $credentials[$field] = (string) $entry['value']; }
                }
                if (!self::sms_discovery_value_usable($credentials['password'] ?? '') && self::sms_discovery_value_usable($credentials['api_key'] ?? '')) {
                    $credentials['password'] = $credentials['api_key'];
                    $keys['password'] = isset($keys['api_key']) ? $keys['api_key'] : '';
                }
                unset($credentials['api_key'], $keys['api_key']);
                $complete = self::sms_discovery_value_usable($credentials['username'] ?? '') && self::sms_discovery_value_usable($credentials['password'] ?? '') && self::sms_discovery_value_usable($credentials['from'] ?? '');
                $score = self::theme_option_name_score($option_name, $theme_slugs) + (count($credentials) * 10) + ($complete ? 60 : 0);
                if ($provider_is_meli) { $score += 35; }
                if ($explicit_option || $explicit_scope) { $score += 35; }
                $candidates[] = array('option_name' => $option_name, 'scope' => $scope, 'keys' => $keys, 'credentials' => $credentials, 'complete' => $complete, 'score' => $score);
            }
        }
        if ($digit_source_present) {
            $candidates = array_values(array_filter($candidates, function ($candidate) { return strtolower((string) ($candidate['option_name'] ?? '')) === 'digit_melipayamak'; }));
        }
        self::$theme_discovery_cache = self::finalize_discovery_candidates($candidates);
        self::$theme_discovery_cache['digit_source_present'] = $digit_source_present;
        if ($digit_source_present && empty(self::$theme_discovery_cache['option_name'])) {
            self::$theme_discovery_cache['option_name'] = 'digit_melipayamak';
        }
        return self::$theme_discovery_cache;
    }

    /** Discover only known SMS schemas after their selected gateway is confirmed as MeliPayamak. */
    private static function discover_plugin_sms_config() {
        if (is_array(self::$plugin_discovery_cache)) { return self::$plugin_discovery_cache; }
        $known_names = array(
            'sms_main_settings', 'wpsms_settings', 'wp_sms_settings', 'wp_sms', 'wpmpsms', 'wpmpsms_options',
            'sms_settings', 'sms_options', 'sms_gateway_settings', 'sms_provider', 'woocommerce_sms',
            'woocommerce_sms_settings', 'woo_sms', 'melipayamak', 'melipayamak_options', 'melipayamak_settings',
            'meli_payamak', 'melli_payamak',
        );
        $option_names = array();
        foreach ($known_names as $name) { $option_names[$name] = true; }
        $all = function_exists('wp_load_alloptions') ? wp_load_alloptions() : array();
        if (is_array($all)) {
            foreach ($all as $name => $unused) {
                if (self::is_sms_plugin_option_candidate_name((string) $name)) { $option_names[(string) $name] = true; }
            }
        }
        global $wpdb;
        if (isset($wpdb) && isset($wpdb->options) && method_exists($wpdb, 'get_col') && method_exists($wpdb, 'prepare') && method_exists($wpdb, 'esc_like')) {
            foreach (array('%melipayamak%', '%mellipayamak%') as $pattern) {
                $sql = $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT 250", $pattern);
                $found_names = $wpdb->get_col($sql);
                foreach ((array) $found_names as $name) {
                    if (self::is_sms_plugin_option_candidate_name((string) $name)) { $option_names[(string) $name] = true; }
                }
            }
        }
        $option_names = array_slice(array_keys($option_names), 0, 500);
        $candidates = array();

        // The legacy plugin stores these as three exact, separate options. Do not combine arbitrary keys.
        $legacy_names = array('username' => 'melipayamak_username', 'password' => 'melipayamak_password', 'from' => 'melipayamak_tel');
        $legacy_credentials = array();
        $legacy_keys = array();
        foreach ($legacy_names as $field => $option_name) {
            $value = get_option($option_name, null);
            if (self::sms_discovery_value_usable($value)) {
                $legacy_credentials[$field] = (string) $value;
                $legacy_keys[$field] = $option_name;
            }
        }
        if ($legacy_credentials) {
            $complete = self::sms_discovery_value_usable($legacy_credentials['username'] ?? '') && self::sms_discovery_value_usable($legacy_credentials['password'] ?? '') && self::sms_discovery_value_usable($legacy_credentials['from'] ?? '');
            $candidates[] = array(
                'option_name' => 'melipayamak_username + melipayamak_password + melipayamak_tel',
                'scope' => 'legacy_scalars', 'keys' => $legacy_keys, 'credentials' => $legacy_credentials,
                'complete' => $complete, 'score' => 180 + (count($legacy_credentials) * 10) + ($complete ? 60 : 0),
            );
        }

        foreach ($option_names as $option_name) {
            if ($option_name === self::OPTION || strpos($option_name, '_transient_') === 0 || strpos($option_name, 'theme_mods_') === 0 || strpos($option_name, 'wsm_') === 0) { continue; }
            $value = get_option($option_name, null);
            if (is_string($value)) {
                $trimmed = ltrim($value);
                if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) { $value = $decoded; }
                }
            }
            if (!is_array($value) && !is_object($value)) { continue; }
            $provider = self::selected_sms_provider($value);
            if ($provider !== '' && !self::is_melipayamak_provider($provider)) { continue; }
            $provider_is_meli = self::is_melipayamak_provider($provider);
            $explicit_option = self::is_melipayamak_provider($option_name);
            $groups = array();
            self::walk_theme_sms_fields($value, $option_name, array(), '', self::is_sms_context_key($option_name), $groups, 0);
            foreach ($groups as $scope => $fields) {
                if (!is_array($fields) || !$fields) { continue; }
                $scope_key = self::normalized_option_key($scope);
                $explicit_scope = self::is_melipayamak_provider($scope);
                $allowed_scope = $explicit_scope || ($explicit_option && $scope === 'root') || ($provider_is_meli && in_array($scope_key, array('root', 'sms'), true));
                if (!$allowed_scope) { continue; }
                $keys = array();
                $credentials = array();
                foreach ($fields as $field => $entry) {
                    if (!is_array($entry) || !isset($entry['path'])) { continue; }
                    $keys[$field] = (string) $entry['path'];
                    if (isset($entry['value'])) { $credentials[$field] = (string) $entry['value']; }
                }
                if (!self::sms_discovery_value_usable($credentials['password'] ?? '') && self::sms_discovery_value_usable($credentials['api_key'] ?? '')) {
                    $credentials['password'] = $credentials['api_key'];
                    $keys['password'] = isset($keys['api_key']) ? $keys['api_key'] : '';
                }
                unset($credentials['api_key'], $keys['api_key']);
                $complete = self::sms_discovery_value_usable($credentials['username'] ?? '') && self::sms_discovery_value_usable($credentials['password'] ?? '') && self::sms_discovery_value_usable($credentials['from'] ?? '');
                $score = 100 + (count($credentials) * 10) + ($complete ? 60 : 0);
                if ($provider_is_meli) { $score += 40; }
                if ($explicit_option || $explicit_scope) { $score += 60; }
                $candidates[] = array('option_name' => $option_name, 'scope' => $scope, 'keys' => $keys, 'credentials' => $credentials, 'complete' => $complete, 'score' => $score);
            }
        }
        self::$plugin_discovery_cache = self::finalize_discovery_candidates($candidates);
        return self::$plugin_discovery_cache;
    }

    private static function is_sms_plugin_option_candidate_name($name) {
        $name = strtolower(trim((string) $name));
        if ($name === '' || $name === self::OPTION || $name === 'digit_melipayamak' || strpos($name, '_transient_') === 0 || strpos($name, 'theme_mods_') === 0 || strpos($name, 'wsm_') === 0) { return false; }
        $normalized = self::normalized_option_key($name);
        if (strpos($normalized, 'melipayamak') !== false || strpos($normalized, 'mellipayamak') !== false) { return true; }
        return in_array($name, array(
            'sms_main_settings', 'wpsms_settings', 'wp_sms_settings', 'wp_sms', 'wpmpsms', 'wpmpsms_options',
            'sms_settings', 'sms_options', 'sms_gateway_settings', 'sms_provider', 'woocommerce_sms',
            'woocommerce_sms_settings', 'woo_sms',
        ), true);
    }

    private static function is_theme_option_candidate_name($name, $theme_slugs, $generic_names) {
        $name = strtolower(trim((string) $name));
        if ($name === '' || strpos($name, '_transient_') === 0 || strpos($name, 'theme_mods_') === 0 || strpos($name, 'wsm_') === 0) { return false; }
        if (in_array($name, $generic_names, true)) { return true; }
        if (strpos($name, 'theme') !== false) { return true; }
        $normalized = self::normalized_option_key($name);
        foreach ((array) $theme_slugs as $slug => $enabled) {
            $slug = self::normalized_option_key($slug);
            if (strlen($slug) >= 5 && strpos($normalized, $slug) !== false) { return true; }
        }
        return false;
    }

    private static function theme_option_name_score($name, $theme_slugs) {
        $name = strtolower((string) $name);
        $normalized = self::normalized_option_key($name);
        $score = 0;
        foreach ((array) $theme_slugs as $slug => $enabled) {
            $slug = self::normalized_option_key($slug);
            if (strlen($slug) >= 5 && strpos($normalized, $slug) !== false) { $score = max($score, 120); }
        }
        if (in_array($name, array('theme_options', 'my_theme_options', 'theme-options', 'theme_settings', 'theme_options_data', 'theme_option'), true)) { $score = max($score, 85); }
        elseif (strpos($name, 'theme') !== false) { $score = max($score, 55); }
        if (strpos($normalized, 'sms') !== false) { $score += 5; }
        return $score;
    }

    public static function compare_theme_candidates($a, $b) {
        $score_a = isset($a['score']) ? (int) $a['score'] : 0;
        $score_b = isset($b['score']) ? (int) $b['score'] : 0;
        if ($score_a === $score_b) { return strcmp((string) $a['option_name'] . ':' . (string) $a['scope'], (string) $b['option_name'] . ':' . (string) $b['scope']); }
        return $score_a > $score_b ? -1 : 1;
    }

    private static function normalized_option_key($key) {
        $key = strtolower((string) $key);
        if (function_exists('remove_accents')) { $key = remove_accents($key); }
        $normalized = preg_replace('/[^a-z0-9]+/', '', $key);
        return is_string($normalized) ? $normalized : '';
    }

    private static function sms_discovery_value_usable($value) {
        if (!is_scalar($value)) { return false; }
        $value = trim((string) $value);
        if ($value === '') { return false; }
        $lower = strtolower($value);
        if (in_array($lower, array('null','none','false','hidden','(hidden)','not set','not configured','********','••••'), true)) { return false; }
        $masked = preg_match('/^[*#xX•·]{3,}$/u', $value);
        return $masked === 0;
    }

    private static function is_sms_context_key($key) {
        $key = self::normalized_option_key($key);
        return strpos($key, 'sms') !== false || strpos($key, 'melipayamak') !== false || strpos($key, 'mellipayamak') !== false || strpos($key, 'payamak') !== false || strpos($key, 'meli') === 0 || strpos($key, 'melli') === 0 || (strpos($key, 'national') === 0 && self::theme_field_for_key($key) !== '');
    }

    private static function theme_field_for_key($key) {
        $stripped = self::normalized_option_key($key);
        $prefixes = array('melipayamak', 'mellipayamak', 'smsgateway', 'gateway', 'national', 'payamak', 'melli', 'meli', 'sms');
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($prefixes as $prefix) {
                if (strpos($stripped, $prefix) === 0) {
                    $stripped = substr($stripped, strlen($prefix));
                    $changed = true;
                    break;
                }
            }
        }
        if (in_array($stripped, array('username', 'user', 'login', 'uname', 'userid', 'apiusername', 'apiuser', 'accountusername'), true)) { return 'username'; }
        if (in_array($stripped, array('password', 'pass', 'passwd', 'pwd', 'apipassword', 'apipass'), true)) { return 'password'; }
        if (in_array($stripped, array('key', 'apikey', 'apiapikey'), true)) { return 'api_key'; }
        if (in_array($stripped, array('from', 'sender', 'sendernumber', 'senderid', 'fromnumber', 'line', 'linenumber', 'smsline', 'origin', 'number', 'tel'), true)) { return 'from'; }
        if (in_array($stripped, array('adminmobile', 'adminphone', 'managerphone', 'managermobile', 'adminnumber'), true)) { return 'admin_mobile'; }
        return '';
    }

    private static function walk_theme_sms_fields($value, $option_name, $path, $scope, $context, &$groups, $depth) {
        if ($depth > 10) { return; }
        if (is_object($value)) { $value = get_object_vars($value); }
        if (!is_array($value)) { return; }
        foreach ($value as $key => $child) {
            if (!is_string($key) && !is_int($key)) { continue; }
            $key = (string) $key;
            $next_path = $path;
            $next_path[] = $key;
            $key_context = self::is_sms_context_key($key);
            if (is_array($child) || is_object($child)) {
                $next_scope = $key_context ? implode('.', $next_path) : $scope;
                self::walk_theme_sms_fields($child, $option_name, $next_path, $next_scope, $context || $key_context, $groups, $depth + 1);
                continue;
            }
            $field = self::theme_field_for_key($key);
            if ($field === '' || !($context || $key_context) || !is_scalar($child)) { continue; }
            $value_string = (string) $child;
            if (trim($value_string) === '') { continue; }
            $group = $scope !== '' ? $scope : 'root';
            if (!isset($groups[$group])) { $groups[$group] = array(); }
            $priority = strlen(self::normalized_option_key($key));
            if (!isset($groups[$group][$field]) || $priority > (int) $groups[$group][$field]['priority']) {
                $groups[$group][$field] = array('value' => $value_string, 'path' => implode('.', $next_path), 'priority' => $priority);
            }
        }
    }

    private static function log_theme_fallback($theme, $manual_ready) {
        $transient = 'wsm_sms_theme_config_notice_v1';
        if (get_transient($transient)) { return; }
        $option_name = isset($theme['option_name']) ? sanitize_text_field((string) $theme['option_name']) : '';
        if (!empty($theme['ambiguous'])) {
            $action = 'sms_theme_settings_ambiguous';
            $detail = 'چند گزینهٔ قالب برای پیامک هم‌زمان پیدا شد؛ پیکربندی خودکار به‌دلیل ابهام استفاده نشد.' . ($manual_ready ? ' تنظیمات دستی افزونه به‌عنوان جایگزین استفاده شد.' : ' تنظیمات دستی کامل هم موجود نیست.');
        } elseif ($manual_ready) {
            $action = 'sms_theme_settings_fallback';
            $detail = 'اطلاعات کامل پیامک در تنظیمات قالب پیدا نشد؛ از تنظیمات دستی افزونه به‌عنوان جایگزین استفاده شد.';
        } else {
            $action = 'sms_theme_settings_missing';
            $detail = 'اطلاعات کامل پیامک در تنظیمات قالب یا تنظیمات دستی افزونه موجود نیست؛ ارسال انجام نمی‌شود.';
        }
        if ($option_name !== '') { $detail .= ' Option Name=' . $option_name . '.'; }
        $paths = isset($theme['keys']) && is_array($theme['keys']) ? $theme['keys'] : array();
        if ($paths) {
            $safe_paths = array();
            foreach ($paths as $field => $path) { $safe_paths[] = sanitize_key((string) $field) . '=' . sanitize_text_field((string) $path); }
            $detail .= ' Key paths: ' . implode(', ', $safe_paths) . '.';
        }
        if (class_exists('WSM_Security')) { WSM_Security::log($action, 'warning', 0, $detail); }
        set_transient($transient, 1, 600);
    }

    private static function log_discovery_fallback($plugin, $theme, $manual_ready) {
        $transient = !empty($theme['sender_signature_invalid']) ? 'wsm_sms_auto_sender_invalid_notice_v1' : 'wsm_sms_auto_discovery_notice_v2';
        if (get_transient($transient)) { return; }
        $ambiguous = !empty($plugin['ambiguous']) || !empty($theme['ambiguous']);
        if (!empty($theme['sender_signature_invalid'])) {
            $action = 'sms_auto_sender_invalidated';
            $detail = 'امضای خط فرستندهٔ خودکار با گزینه یا اعتبارنامهٔ فعلی تطبیق ندارد؛ تا ذخیرهٔ صریح مجدد تنظیمات، ارسال متوقف شد.';
        } elseif ($ambiguous) {
            $action = 'sms_auto_settings_ambiguous';
            $detail = 'چند گزینهٔ پیامک هم‌زمان پیدا شد؛ به‌دلیل ابهام هیچ اعتبارنامهٔ خودکاری استفاده نشد.';
        } elseif ($manual_ready) {
            $action = 'sms_auto_settings_fallback';
            $detail = 'اعتبارنامهٔ کامل از گزینه‌های پیامک افزونه یا قالب پیدا نشد؛ تنظیمات دستی به‌عنوان جایگزین استفاده شد.';
        } else {
            $action = 'sms_auto_settings_missing';
            $detail = 'اعتبارنامهٔ کامل از گزینه‌های پیامک افزونه، قالب یا تنظیمات دستی پیدا نشد؛ ارسال انجام نمی‌شود.';
        }
        foreach (array('plugin' => $plugin, 'theme' => $theme) as $kind => $discovery) {
            if (!is_array($discovery) || empty($discovery['option_name'])) { continue; }
            $detail .= ' ' . $kind . '_option=' . sanitize_text_field((string) $discovery['option_name']) . '.';
            $paths = isset($discovery['keys']) && is_array($discovery['keys']) ? $discovery['keys'] : array();
            if ($paths) {
                $safe_paths = array();
                foreach ($paths as $field => $path) { $safe_paths[] = sanitize_key((string) $field) . '=' . sanitize_text_field((string) $path); }
                $detail .= ' key_paths=' . implode(', ', $safe_paths) . '.';
            }
        }
        if (class_exists('WSM_Security')) { WSM_Security::log($action, 'warning', 0, $detail); }
        set_transient($transient, 1, 600);
    }

    /** Called only by an authorized explicit settings save when sender is empty; never guesses among multiple lines. */
    private static function discover_sender_number($username, $password) {
        $url = apply_filters('wsm_sms_numbers_endpoint', self::NUMBERS_ENDPOINT);
        $response = wp_remote_post($url, array(
            'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 8192,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => http_build_query(array('username' => $username, 'password' => $password), '', '&'),
        ));
        $required = 'خط ارسال‌کننده پیدا نشد؛ شمارهٔ خط را از پنل ملی‌پیامک در فیلد فرستنده وارد کنید.';
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return array('ok' => false, 'detail' => 'اتصال برای یافتن خط ارسال‌کننده برقرار نشد. ' . $required);
        }
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($payload) || (int) ($payload['RetStatus'] ?? 0) !== 1 || !array_key_exists('Value', $payload)) {
            return array('ok' => false, 'detail' => $required);
        }
        $value = $payload['Value'];
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : $value;
        }
        $numbers = array();
        $collect = function ($node) use (&$numbers, &$collect) {
            if (count($numbers) > 20) { return; }
            if (is_array($node)) {
                foreach ($node as $key => $entry) {
                    if (is_string($key) && preg_match('/^(number|sender|from|line|tel)$/i', $key) && is_scalar($entry)) {
                        $collect((string) $entry);
                    } elseif (is_array($entry) || is_object($entry)) {
                        $collect($entry);
                    } elseif (is_int($key) && is_scalar($entry)) {
                        $collect((string) $entry);
                    }
                }
            } elseif (is_object($node)) {
                $collect(get_object_vars($node));
            } elseif (is_scalar($node)) {
                $number = trim((string) $node);
                if (preg_match('/^[0-9]{4,20}$/D', $number)) { $numbers[$number] = true; }
            }
        };
        $collect($value);
        if (count($numbers) === 1) {
            reset($numbers);
            return array('ok' => true, 'from' => (string) key($numbers));
        }
        return array('ok' => false, 'detail' => count($numbers) > 1
            ? 'چند خط ارسال‌کننده در ملی‌پیامک وجود دارد؛ خط موردنظر را خودتان انتخاب و وارد کنید.' : $required);
    }

    /** آدرس سرویس؛ فقط برای تست با سرور شبیه‌ساز قابل تغییر است. */
    public static function endpoint() {
        return apply_filters('wsm_sms_endpoint', self::ENDPOINT);
    }

    /**
     * @param bool   $sent    اگر یک افزونهٔ دیگر قبلاً ارسال کرده باشد، همان را برمی‌گردانیم.
     * @param string $message متن پیامک
     * @return bool
     */
    public static function send($sent, $message) {
        if ($sent) {
            return true;
        }
        if (!self::is_active()) {
            return false;
        }
        $result = self::dispatch((string) $message);
        if (!$result['ok']) {
            WSM_Security::log('sms_failed', 'warning', 0, $result['detail']);
        }
        return $result['ok'];
    }

    /**
     * پیام آزمایشی از صفحهٔ تنظیمات (حتی وقتی هشدار خاموش است).
     * @return array { ok: bool, detail: string, provider_ref?: string }
     */
    public static function send_test() {
        delete_transient('wsm_sms_provider_test_v1');
        $config = self::effective_config();
        if (!self::credentials_ready($config)) {
            return array('ok' => false, 'detail' => 'نام کاربری، رمز و شمارهٔ ارسال‌کنندهٔ ملی‌پیامک کامل نیست.');
        }
        $mobile = isset($config['admin_mobile']) ? (string) $config['admin_mobile'] : '';
        if ($mobile === '') {
            return array('ok' => false, 'detail' => 'شمارهٔ مدیر برای دریافت پیامک آزمایشی ثبت نشده است.');
        }
        $name = get_bloginfo('name');
        $message = 'پیام آزمایشی هشدار امنیتی ' . ($name !== '' ? $name : 'فروشگاه');
        $result = self::dispatch_to_internal($message, $mobile);
        if (!empty($result['ok'])) {
            set_transient('wsm_sms_provider_test_v1', self::test_fingerprint($config), 30 * DAY_IN_SECONDS);
        } else {
            delete_transient('wsm_sms_provider_test_v1');
        }
        WSM_Security::log(!empty($result['ok']) ? 'sms_test_ok' : 'sms_test_failed', !empty($result['ok']) ? 'info' : 'warning', 0, isset($result['detail']) ? (string) $result['detail'] : '');
        // Do not persist the test recipient in the club SMS log; it is a private admin number.
        return $result;
    }

    /**
     * ارسال واقعی یک پیام و تفسیر پاسخ. هیچ‌وقت رمز یا شماره در نتیجه برنمی‌گردد.
     * @return array { ok: bool, detail: string, provider_ref?: string }
     */
    public static function dispatch($text) {
        $c = self::effective_config();
        return self::dispatch_to($text, isset($c['admin_mobile']) ? $c['admin_mobile'] : '');
    }

    /** ارسال به شمارهٔ مشخص؛ برای هشدارهای عضویت فقط پس از رضایت ثبت‌شدهٔ عضو فراخوانی شود.
     * @return array { ok: bool, detail: string, provider_ref?: string }
     */
    public static function dispatch_to($text, $to) {
        if (!self::is_active()) {
            return array('ok' => false, 'detail' => 'سرویس پیامک غیرفعال یا ناقص است.');
        }
        return self::dispatch_to_internal($text, $to);
    }

    /** Private path reserved for the explicitly confirmed administrator test. */
    private static function dispatch_to_internal($text, $to) {
        $c = self::effective_config();
        $to = preg_replace('/[^0-9+]/', '', is_scalar($to) ? (string) $to : '');
        if ($to === '' || strlen($to) < 7 || strlen($to) > 20) {
            return array('ok' => false, 'detail' => 'شمارهٔ مقصد معتبر نیست.');
        }
        if (!self::credentials_ready($c)) {
            return array('ok' => false, 'detail' => 'نام کاربری، رمز و شمارهٔ ارسال‌کنندهٔ ملی‌پیامک کامل نیست.');
        }
        $password = self::password_value($c);
        if (!is_string($password) || $password === '') {
            return array('ok' => false, 'detail' => 'رمز ملی‌پیامک قابل خواندن نیست؛ دوباره در حالت دستی ذخیره کنید.');
        }

        $text = trim((string) $text);
        if (substr($text, -strlen(self::SUFFIX)) !== self::SUFFIX) {
            $text = rtrim($text) . ' ' . self::SUFFIX;
        }

        $body = http_build_query(array(
            'username' => (string) $c['username'],
            'password' => $password,
            'to'       => $to,
            'from'     => (string) $c['from'],
            'text'     => $text,
            'isflash'  => 'false',
        ), '', '&');

        $res = wp_remote_post(self::endpoint(), array(
            'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 8192,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => $body,
        ));
        if (is_wp_error($res)) {
            return array('ok' => false, 'detail' => 'خطای اتصال به سرویس پیامک.');
        }

        $http   = (int) wp_remote_retrieve_response_code($res);
        $parsed = self::parse_response((string) wp_remote_retrieve_body($res));
        $ok     = $parsed['ok'] && $http === 200;
        $detail = 'پاسخ سرویس پیامک: ' . $parsed['detail'] . ' (HTTP ' . $http . ')';
        return array(
            'ok' => $ok,
            'detail' => $detail,
            'provider_ref' => isset($parsed['provider_ref']) ? substr(sanitize_text_field((string) $parsed['provider_ref']), 0, 100) : '',
        );
    }

    /**
     * تفسیر پاسخ ملی‌پیامک.
     * @return array { ok: bool, detail: string, provider_ref?: string }
     */
    public static function parse_response($body) {
        $body = trim((string) $body);
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['RetStatus'])) {
            $status = (int) $data['RetStatus'];
            $ref = isset($data['Value']) && is_scalar($data['Value']) ? substr(sanitize_text_field(trim((string) $data['Value'])), 0, 100) : '';
            if ($status === 1 && $ref !== '' && preg_match('/^-?\d+$/D', $ref) && (int) $ref <= 0) {
                return array('ok' => false, 'detail' => 'کد خطای سرویس ' . (int) $ref, 'provider_ref' => '');
            }
            if ($status === 1) {
                return array('ok' => true, 'detail' => 'ارسال شد', 'provider_ref' => $ref);
            }
            return array('ok' => false, 'detail' => 'RetStatus=' . $status, 'provider_ref' => '');
        }
        if (preg_match('/^-?\d+$/', $body)) {
            $n = (int) $body;
            if ($n === 1 || $n > 100) {
                return array(
                    'ok' => true,
                    'detail' => $n > 100 ? 'ارسال شد (شناسه ' . $n . ')' : 'ارسال شد',
                    'provider_ref' => $n > 100 ? (string) $n : '',
                );
            }
            return array('ok' => false, 'detail' => 'کد خطا ' . $n . ': ' . self::error_text($n), 'provider_ref' => '');
        }
        return array('ok' => false, 'detail' => 'پاسخ نامشخص', 'provider_ref' => '');
    }

    /** متن فارسی کدهای خطای مستندات رسمی */
    public static function error_text($code) {
        $map = array(
            0   => 'نام کاربری یا رمز اشتباه است',
            2   => 'اعتبار پنل پیامکی کافی نیست',
            3   => 'محدودیت تعداد ارسال روزانه',
            4   => 'محدودیت حجم هر ارسال',
            5   => 'شمارهٔ ارسال‌کننده معتبر نیست',
            6   => 'سامانه در حال به‌روزرسانی است',
            7   => 'متن شامل کلمات فیلترشده است',
            9   => 'ارسال از خط عمومی از طریق وب‌سرویس مجاز نیست',
            10  => 'پنل پیامکی فعال نیست',
            11  => 'درخواست در وضعیت ارسال نشده است',
            12  => 'احراز هویت پنل کامل نیست',
            14  => 'متن شامل لینک است',
            15  => 'عبارت «لغو11» در انتهای متن نیست',
            18  => 'شمارهٔ گیرنده نامعتبر است',
            108 => 'آی‌پی سرور مسدود شده است',
            109 => 'تنظیم آی‌پی مجاز در پنل ملی‌پیامک الزامی است',
            110 => 'استفاده از APIKey به‌جای رمز الزامی است',
        );
        $key = abs((int) $code);
        return isset($map[$key]) ? $map[$key] : 'خطای ناشناخته';
    }
}

