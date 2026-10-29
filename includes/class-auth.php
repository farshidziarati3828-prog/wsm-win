<?php
/**
 * احراز هویت چندلایه:
 * 1) Access Key: bin2hex(random_bytes(32)) با پیشوند wsm_ak_؛ ذخیره با Argon2id (یا bcrypt)؛ نمایش یک‌باره؛ چرخش خودکار پس از ۹۰ روز
 * 2) امضای HMAC هر درخواست با کلید امضای مخصوص دستگاه (در handshake یک‌بار برگردانده می‌شود)
 * 3) Access token دو‌ساعته؛ refresh دو‌ساعته یا ۳۰روزه با Remember Me
 * 4) ثبت دستگاه (قابل قطع، مسدود یا حذف)
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Auth {

    const KEY_PREFIX     = 'wsm_ak_';
    const TOKEN_PREFIX   = 'wsmt_';
    const REFRESH_PREFIX = 'wsmr_';
    const PENDING_TTL    = 86400; // کلید تازه ساخته‌شده، ۲۴ ساعت قابل نمایش یک‌باره
    const SESSION_TTL    = 7200;
    const REMEMBER_TTL   = 2592000;
    const RESET_TTL      = 600;

    public static function init() {
        add_action('admin_init', array(__CLASS__, 'ensure_runtime_schema'), 2);
        add_action('admin_init', array(__CLASS__, 'maybe_rotate_key'), 3);
        add_action('rest_api_init', array(__CLASS__, 'ensure_runtime_schema'), 3);
        add_action('admin_notices', array(__CLASS__, 'admin_notices'));
        add_action('wsm_daily_cleanup', array(__CLASS__, 'maybe_rotate_key'));
    }

    public static function ensure_runtime_schema() {
        try {
            if (class_exists('WSM_Devices')) { WSM_Devices::ensure_schema(); }
            if (class_exists('WSM_Permissions')) { WSM_Permissions::ensure_schema(); }
        } catch (Throwable $e) {
            // These idempotent schema checks retry on the next request; do not let a DB exception blank wp-admin.
            error_log('[WSM Auth] WSM_Auth::ensure_runtime_schema failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    public static function password_algo() {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /**
     * ساخت کلید دسترسی جدید (کلید قبلی باطل می‌شود)
     * @return array ['plain' => string, 'expires' => string]
     */
    public static function generate_access_key($user_id) {
        global $wpdb;
        $settings = WSM_Util::settings();
        $plain    = self::KEY_PREFIX . bin2hex(random_bytes(32));
        $expires  = WSM_Util::add_days($settings['key_ttl_days']);
        $algo     = self::password_algo();

        update_option('wsm_access_key_hash', password_hash($plain, $algo), false);
        update_option('wsm_access_key_algo', $algo === PASSWORD_BCRYPT ? 'bcrypt' : 'argon2id', false);
        update_option('wsm_access_key_prefix', substr($plain, 0, 12), false);
        update_option('wsm_access_key_expires', $expires, false);
        update_option('wsm_access_key_user', (int) $user_id, false);
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}wsm_access_keys SET revoked_at=%s WHERE user_id=%d AND key_hash='' AND revoked_at IS NULL", WSM_Util::now(), (int) $user_id));

        $wpdb->insert($wpdb->prefix . 'wsm_access_keys', array(
            'user_id'    => (int) $user_id,
            'key_prefix' => substr($plain, 0, 12),
            'created_at' => WSM_Util::now(),
            'expires_at' => $expires,
        ));

        return array('plain' => $plain, 'expires' => $expires);
    }

    /** کلید تازه را رمزنگاری‌شده برای نمایش یک‌باره نگه می‌دارد */
    public static function stash_key($user_id, $plain) {
        $enc = WSM_Crypto::encrypt($plain);
        if ($enc === false) {
            return false;
        }
        set_transient('wsm_pending_key_' . (int) $user_id, $enc, self::PENDING_TTL);
        return true;
    }

    /** کلید در انتظار نمایش را یک‌بار برمی‌گرداند و پاک می‌کند */
    public static function reveal_key($user_id) {
        $name = 'wsm_pending_key_' . (int) $user_id;
        $enc  = get_transient($name);
        if (!$enc) {
            return false;
        }
        delete_transient($name);
        return WSM_Crypto::decrypt($enc);
    }

    public static function access_key_user_id($plain) {
        global $wpdb;
        if (!is_string($plain) || strpos($plain, self::KEY_PREFIX) !== 0) { return 0; }
        $hash = get_option('wsm_access_key_hash', '');
        $expires = get_option('wsm_access_key_expires', '');
        $owner = (int) get_option('wsm_access_key_user', 0);
        if ($hash && (!$expires || strtotime($expires) >= current_time('timestamp')) && password_verify($plain, $hash)) {
            $user = get_userdata($owner);
            if ($user && get_user_meta($owner, 'wsm_app_user_status', true) !== 'inactive') { return $owner; }
        }
        $prefix = substr($plain, 0, 12);
        $table = $wpdb->prefix . 'wsm_access_keys';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id,key_hash,expires_at FROM {$table} WHERE key_prefix=%s AND key_hash<>'' AND revoked_at IS NULL ORDER BY id DESC LIMIT 50", $prefix));
        foreach ((array) $rows as $row) {
            if (!empty($row->expires_at) && strtotime($row->expires_at) < current_time('timestamp')) { continue; }
            if (!password_verify($plain, (string) $row->key_hash)) { continue; }
            $user = get_userdata((int) $row->user_id);
            if (!$user || get_user_meta((int) $row->user_id, 'wsm_app_user_status', true) === 'inactive') { return 0; }
            return (int) $row->user_id;
        }
        return 0;
    }

    public static function verify_access_key($plain) {
        return self::access_key_user_id($plain) > 0;
    }

    /** کلید جداگانه و یک‌بارنمایش برای حساب اپلیکیشن؛ کلید مدیرکل را باطل نمی‌کند. */
    public static function generate_user_access_key($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        if (!$user_id || !get_userdata($user_id)) { return new WP_Error('wsm_user_missing', 'کاربر پیدا نشد.', array('status' => 404)); }
        $plain = self::KEY_PREFIX . bin2hex(random_bytes(32));
        $expires = WSM_Util::add_days((int) WSM_Util::settings()['key_ttl_days']);
        $table = $wpdb->prefix . 'wsm_access_keys';
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET revoked_at=%s WHERE user_id=%d AND revoked_at IS NULL AND key_hash<>''", WSM_Util::now(), $user_id));
        $ok = $wpdb->insert($table, array('user_id' => $user_id, 'key_prefix' => substr($plain, 0, 12), 'key_hash' => password_hash($plain, self::password_algo()), 'created_at' => WSM_Util::now(), 'expires_at' => $expires, 'revoked_at' => null));
        if ($ok === false) { return new WP_Error('wsm_user_key_failed', 'کلید کاربر ساخته نشد.', array('status' => 500)); }
        return array('plain' => $plain, 'expires' => $expires);
    }

    /**
     * چرخش خودکار کلید پس از انقضا: کلید جدید ساخته، کلید جدید برای یک‌بار نمایش آماده و مدیر مطلع می‌شود.
     * @return bool آیا چرخش انجام شد
     */
    public static function maybe_rotate_key() {
        if (!WSM_Crypto::available()) {
            return false;
        }
        $expires = get_option('wsm_access_key_expires', '');
        if (!$expires || strtotime($expires) > current_time('timestamp')) {
            return false;
        }
        $owner = (int) get_option('wsm_access_key_user', 0);
        if (!get_userdata($owner)) {
            $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
            $owner  = $admins ? (int) $admins[0] : 0;
        }
        if ($owner <= 0) {
            return false;
        }

        $key = self::generate_access_key($owner);
        self::stash_key($owner, $key['plain']);
        WSM_Security::log('key_rotated', 'warning', 0, 'کلید پس از انقضا به‌صورت خودکار چرخش یافت');
        wp_mail(
            get_option('admin_email'),
            'مدیر فروشگاه: کلید دسترسی جدید ساخته شد',
            'کلید قبلی منقضی شد و یک کلید جدید ساخته شد. برای دیدن کلید (فقط یک‌بار و تا ۲۴ ساعت) به صفحهٔ «کلید دسترسی» در پیشخوان وردپرس بروید. دستگاه‌های متصل تا پایان نشست فعلی کار می‌کنند؛ برای اتصال دستگاه جدید از کلید جدید استفاده کنید.'
        );
        return true;
    }

    public static function admin_notices() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return;
        }
        $uid = get_current_user_id();
        if (get_transient('wsm_pending_key_' . $uid)) {
            echo '<div class="notice notice-warning" dir="rtl"><p>کلید جدید مدیر فروشگاه ساخته شده است. برای دیدن آن (فقط یک‌بار) به <a href="' .
                esc_url(admin_url('admin.php?page=wsm-access-key')) . '">صفحهٔ کلید دسترسی</a> بروید.</p></div>';
        }
        $expires = get_option('wsm_access_key_expires', '');
        if ($expires && strtotime($expires) > current_time('timestamp') && strtotime($expires) - current_time('timestamp') < 7 * DAY_IN_SECONDS) {
            echo '<div class="notice notice-warning" dir="rtl"><p>کلید دسترسی اپ تا ' . esc_html(wsm_fa_date_short($expires)) .
                ' معتبر است و پس از آن به‌صورت خودکار چرخش می‌یابد.</p></div>';
        }
    }

    /** Verify the app-specific password hash; migrate eligible legacy WP passwords once. */
    private static function verify_app_password($user, $password) {
        $user_id = (int) $user->ID;
        $hash = (string) get_user_meta($user_id, 'wsm_app_password_hash', true);
        if ($hash !== '') {
            if (!password_verify((string) $password, $hash)) { return false; }
            if (password_needs_rehash($hash, self::password_algo())) {
                update_user_meta($user_id, 'wsm_app_password_hash', password_hash((string) $password, self::password_algo()));
            }
            return true;
        }
        if (!function_exists('wp_check_password') || !wp_check_password((string) $password, (string) $user->user_pass, $user_id)) { return false; }
        update_user_meta($user_id, 'wsm_app_password_hash', password_hash((string) $password, self::password_algo()));
        return true;
    }

    /** Staff sign-in; valid credentials register or reactivate this device automatically. */
    /** Shared preflight for password and SMS-code staff sign-in. */
    private static function login_preflight() {
        if (!WSM_Crypto::available()) { return new WP_Error('wsm_crypto_unavailable', 'رمزنگاری امن سرور فعال نیست.', array('status' => 500)); }
        if (!WSM_Devices::ensure_schema()) { return new WP_Error('wsm_device_schema_unavailable', 'ساختار دستگاه‌ها آماده نیست.', array('status' => 500)); }
        if (WSM_Security::blocked_until() || self::staff_login_blocked_until()) {
            return new WP_Error('wsm_ip_blocked', 'IP شما موقتاً مسدود است. بعداً دوباره تلاش کنید.', array('status' => 429));
        }
        return true;
    }

    /**
     * Normalize an explicitly entered employee login alias without applying WordPress's ASCII-only username rules.
     * WordPress keeps its technical user_login; this exact alias lets employees sign in with the requested Persian name.
     */
    private static function normalize_employee_login_alias($value) {
        if (!is_scalar($value)) { return ''; }
        $alias = sanitize_text_field(trim((string) $value));
        if (class_exists('WSM_Persian_Normalizer')) {
            $alias = WSM_Persian_Normalizer::normalize($alias);
        } else {
            $collapsed = preg_replace('/\s+/u', ' ', $alias);
            if (is_string($collapsed)) { $alias = trim($collapsed); }
        }
        if ($alias === '' || self::app_password_length($alias) > 80 || is_email($alias)) { return ''; }
        $phone_alias = self::normalize_employee_phone($alias);
        if ($phone_alias !== '' && strlen($phone_alias) >= 7) { return ''; }
        return $alias;
    }

    /** Return only exact, normalized alias matches; ambiguous aliases are later rejected by the resolver. */
    private static function employee_login_alias_matches($identifier) {
        $alias = self::normalize_employee_login_alias($identifier);
        if ($alias === '') { return array(); }
        $ids = get_users(array(
            'fields' => 'ID',
            'number' => 100,
            'meta_query' => array(array('key' => 'wsm_app_login_alias', 'value' => $alias, 'compare' => '=')),
        ));
        $matches = array();
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id < 1 || self::normalize_employee_login_alias(get_user_meta($id, 'wsm_app_login_alias', true)) !== $alias) { continue; }
            $user = get_userdata($id);
            if ($user) { $matches[$id] = $user; }
            if (count($matches) > 1) { break; }
        }
        return array_values($matches);
    }

    /** Check aliases and real WordPress logins before assigning an alias to a different employee. */
    private static function employee_login_alias_is_taken($value, $exclude_id = 0) {
        $alias = self::normalize_employee_login_alias($value);
        if ($alias === '') { return false; }
        $exclude_id = (int) $exclude_id;
        foreach (self::employee_login_alias_matches($alias) as $match) {
            if ((int) $match->ID !== $exclude_id) { return true; }
        }
        $by_login = get_user_by('login', $alias);
        if ($by_login && (int) $by_login->ID !== $exclude_id) { return true; }
        $strict_login = sanitize_user($alias, true);
        if ($strict_login !== '' && $strict_login !== $alias) {
            $by_sanitized_login = get_user_by('login', $strict_login);
            if ($by_sanitized_login && (int) $by_sanitized_login->ID !== $exclude_id) { return true; }
        }
        return false;
    }

    /** Resolve a unique account by email, exact username, exact employee alias, or registered phone. */
    private static function identity_matches($identifier, $eligible_phone_only = false) {
        $identifier = is_scalar($identifier) ? trim((string) $identifier) : '';
        if ($identifier === '') { return array(); }
        $matches = array();
        foreach (self::employee_login_alias_matches($identifier) as $by_alias) {
            $matches[(int) $by_alias->ID] = $by_alias;
        }
        if (is_email($identifier)) {
            $by_email = get_user_by('email', sanitize_email($identifier));
            if ($by_email) { $matches[(int) $by_email->ID] = $by_email; }
        }
        $login = sanitize_user($identifier, true);
        if ($login !== '') {
            $by_login = get_user_by('login', $login);
            if ($by_login) { $matches[(int) $by_login->ID] = $by_login; }
        }
        foreach (self::employee_phone_matches($identifier, 0, $eligible_phone_only) as $by_phone) {
            $matches[(int) $by_phone->ID] = $by_phone;
        }
        return array_values($matches);
    }

    private static function resolve_login_user($identifier) {
        $matches = self::identity_matches($identifier, true);
        return count($matches) === 1 ? $matches[0] : false;
    }

    /** True only for an active app employee or an eligible legacy WordPress manager. */
    private static function app_login_eligible($user) {
        if (!$user || !isset($user->ID)) { return false; }
        $user_id = (int) $user->ID;
        $status = (string) get_user_meta($user_id, 'wsm_app_user_status', true);
        if ($status === 'inactive' || get_user_meta($user_id, 'wsm_app_deleted_at', true) !== '') { return false; }
        $app_role = (string) get_user_meta($user_id, 'wsm_app_role', true);
        $record = WSM_Permissions::user_permissions_record($user_id);
        $roles = is_array($user->roles) ? $user->roles : array();
        $legacy_manager = WSM_Permissions::is_wordpress_admin_user($user)
            || in_array('administrator', $roles, true)
            || in_array('shop_manager', $roles, true)
            || !empty($user->allcaps['manage_woocommerce'])
            || !empty($user->allcaps['manage_options']);
        return $status === 'active' || $app_role !== '' || $record !== null || $legacy_manager;
    }

    /** Staff sign-in; valid credentials register or reactivate this device automatically. */
    public static function login($identifier, $password, $info = array(), $remember = false) {
        $ready = self::login_preflight();
        if (is_wp_error($ready)) { return $ready; }
        $identifier = is_scalar($identifier) ? trim((string) $identifier) : '';
        $password = is_scalar($password) ? (string) $password : '';
        $user = self::resolve_login_user($identifier);
        if (!self::app_login_eligible($user) || $password === '' || !self::verify_app_password($user, $password)) {
            self::register_staff_login_failure();
            WSM_Security::log('staff_login_failed', 'warning', 0, 'ورود ناموفق با شناسهٔ کارکنان');
            WSM_Activity_Log::log('auth', 'staff_login', 0, $user ? (string) $user->user_login : 'شناسهٔ نامعتبر', '', 'ورود ناموفق', 'failed', 0, $user ? (int) $user->ID : 0);
            return new WP_Error('wsm_login_invalid', 'شناسه یا گذرواژه اشتباه است.', array('status' => 401));
        }
        return self::complete_staff_login($user, $info, $remember);
    }

    /** Issue the normal device-bound session after either password or SMS verification. */
    private static function complete_staff_login($user, $info, $remember) {
        if (!self::app_login_eligible($user)) {
            self::register_staff_login_failure();
            return new WP_Error('wsm_login_invalid', 'حساب کارمند فعال نیست.', array('status' => 401));
        }
        $user_id = (int) $user->ID;
        $status = (string) get_user_meta($user_id, 'wsm_app_user_status', true);
        $app_role = (string) get_user_meta($user_id, 'wsm_app_role', true);
        $record = WSM_Permissions::user_permissions_record($user_id);
        $roles = is_array($user->roles) ? $user->roles : array();
        $wp_admin = WSM_Permissions::is_wordpress_admin_user($user);
        $legacy_manager = $wp_admin || in_array('administrator', $roles, true) || in_array('shop_manager', $roles, true) || !empty($user->allcaps['manage_woocommerce']) || !empty($user->allcaps['manage_options']);
        $permissions = WSM_Permissions::user_permissions($user_id);
        if ($permissions === null) {
            $preset = $wp_admin ? 'super_admin' : ($legacy_manager ? 'store_manager' : 'custom');
            $presets = WSM_Permissions::presets();
            $permissions = isset($presets[$preset]) ? $presets[$preset]['permissions'] : WSM_Devices::default_permissions();
            if ($preset !== 'custom') { WSM_Permissions::save_user_permissions($user_id, $permissions, $preset, $user_id); }
        }
        if ($wp_admin) {
            $permissions = array_keys(WSM_Devices::permission_catalog());
        }
        $client_id = is_array($info) && isset($info['client_id']) && is_scalar($info['client_id']) ? trim((string) $info['client_id']) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $client_id)) {
            return new WP_Error('wsm_client_id_invalid', 'شناسهٔ امن دستگاه نامعتبر است؛ صفحه را تازه کنید.', array('status' => 400));
        }
        $fingerprint = hash_hmac('sha256', $client_id, wp_salt('auth'));
        // A login is scoped to this user's browser fingerprint. New fingerprints get new rows;
        // never revoke or overwrite tokens belonging to other device rows during sign-in.
        $device = WSM_Devices::find_by_fingerprint($user_id, $fingerprint);
        $auto_approve = get_option('wsm_auto_approve_devices', 1) ? true : false;
        $newly_registered = false;
        if (!$device) {
            // register() repeats the fingerprint lookup while holding a DB advisory lock,
            // so concurrent first logins converge on one row rather than racing two INSERTs.
            $device = WSM_Devices::register($user_id, is_array($info) ? $info : array(), $permissions, $auto_approve ? 'active' : 'pending', $fingerprint);
            if (is_wp_error($device)) { return $device; }
            $newly_registered = true;
        }
        if ($device->status === 'blocked') { return new WP_Error('wsm_device_blocked', 'این دستگاه مسدود است؛ با مدیر تماس بگیرید.', array('status' => 403)); }
        if (in_array($device->status, array('pending', 'unlinked'), true)) {
            if (!$auto_approve) {
                if ($newly_registered) { WSM_Activity_Log::log('auth', 'device', (int) $device->id, $device->device_name, '', 'در انتظار فعال‌سازی مدیر', 'success', (int) $device->id, $user_id); }
                return new WP_Error('wsm_device_pending', 'فعال‌سازی خودکار دستگاه خاموش است؛ دستگاه برای تأیید مدیر ثبت شد.', array('status' => 403));
            }
            $device = WSM_Devices::activate_after_login((int) $device->id);
            if (is_wp_error($device)) { return $device; }
        }
        if ($device->status !== 'active') { return new WP_Error('wsm_device_unavailable', 'این دستگاه قابل استفاده نیست.', array('status' => 403)); }
        delete_transient(self::staff_login_transient_key());
        $name = is_array($info) && isset($info['device_name']) ? WSM_Util::clean_text($info['device_name'], 100) : '';
        if ($name !== '' && $name !== $device->device_name) {
            global $wpdb;
            $wpdb->update(WSM_Devices::table(), array('device_name' => $name, 'updated_at' => WSM_Util::now()), array('id' => (int) $device->id));
        }
        $signing_key = bin2hex(random_bytes(32));
        if (!WSM_Devices::save_signing_key((int) $device->id, $signing_key)) { return new WP_Error('wsm_signing_key_failed', 'ذخیرهٔ کلید امضای دستگاه انجام نشد.', array('status' => 500)); }
        $tokens = self::issue_tokens((int) $device->id, (bool) $remember);
        if (is_wp_error($tokens)) { WSM_Devices::set_status((int) $device->id, 'logout'); return $tokens; }
        WSM_Devices::touch((int) $device->id, WSM_Util::client_ip());
        update_user_meta($user_id, 'wsm_app_last_login', WSM_Util::now());
        WSM_Activity_Log::log('auth', 'device', (int) $device->id, $device->device_name, '', 'ورود موفق', 'success', (int) $device->id, $user_id);
        $record = WSM_Permissions::user_permissions_record($user_id);
        $preset = $wp_admin ? 'super_admin' : ($record ? $record['preset'] : sanitize_key((string) get_user_meta($user_id, 'wsm_app_role', true)));
        $preset_data = WSM_Permissions::presets();
        $is_super_admin = $preset === 'super_admin' || $wp_admin;
        $login_permissions = WSM_Permissions::effective_permissions($device);
        if ($is_super_admin) {
            $login_permissions = array_keys(WSM_Devices::permission_catalog());
        }
        $login_permissions = array_values($login_permissions);
        return array('token' => $tokens['token'], 'refresh_token' => $tokens['refresh'], 'token_expires' => $tokens['expires'], 'signing_key' => $signing_key, 'device_id' => (int) $device->id, 'store_name' => WSM_Util::settings()['store_name'], 'user' => $user->display_name, 'user_id' => $user_id, 'remember_me' => (bool) $remember, 'preset' => $preset, 'role_label' => isset($preset_data[$preset]['label']) ? $preset_data[$preset]['label'] : 'سفارشی', 'is_super_admin' => $is_super_admin, 'permission_count' => count($login_permissions), 'device_permissions' => $login_permissions, 'permissions' => $login_permissions, 'capabilities' => $login_permissions);
    }

    /** Start passwordless staff login by SMS. Responses deliberately do not reveal whether a phone is registered. */
    public static function request_login_otp($phone) {
        $phone = is_scalar($phone) ? trim((string) $phone) : '';
        $generic = array('message' => 'اگر شمارهٔ واردشده متعلق به کارمند فعال باشد و سرویس پیامک افزونه پیکربندی و در دسترس باشد، کد ارسال می‌شود.');
        if ($phone === '') { return $generic; }
        if (WSM_Security::blocked_until() || self::staff_login_blocked_until()) {
            return new WP_Error('wsm_ip_blocked', 'IP شما موقتاً مسدود است. بعداً دوباره تلاش کنید.', array('status' => 429));
        }
        if (!self::auth_code_rate_allowed('login', $phone)) {
            return new WP_Error('wsm_otp_rate_limited', 'برای جلوگیری از سوءاستفاده، تعداد درخواست‌های کد محدود شده است؛ بعداً دوباره تلاش کنید.', array('status' => 429));
        }
        $matches = self::employee_phone_matches($phone, 0, true);
        if (count($matches) !== 1) { return $generic; }
        $user = $matches[0];
        $user_id = (int) $user->ID;
        $stored_phone = self::normalize_employee_phone(get_user_meta($user_id, 'wsm_app_phone', true));
        if ($stored_phone === '' || !class_exists('WSM_SMS') || !WSM_SMS::is_configured()) {
            WSM_Security::log('staff_otp_sms_unavailable', 'warning', 0, 'سرویس پیامک برای ورود کارمند پیکربندی نشده است.');
            return $generic;
        }
        try { $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); }
        catch (Exception $e) { return $generic; }
        $hash = password_hash($code, self::password_algo());
        if (!is_string($hash) || $hash === '') { return $generic; }
        $key = self::staff_otp_transient_key($user_id);
        $state = array('hash' => $hash, 'expires' => time() + self::RESET_TTL, 'attempts' => 0);
        if (!set_transient($key, $state, self::RESET_TTL)) { return $generic; }
        $result = WSM_SMS::dispatch_to('کد ورود کارکنان WSM: ' . $code . ' — اعتبار تا ۱۰ دقیقه.', $stored_phone);
        if (empty($result['ok'])) {
            delete_transient($key);
            WSM_Security::log('staff_otp_sms_failed', 'warning', 0, isset($result['detail']) ? (string) $result['detail'] : 'ارسال پیامک ورود ناموفق بود.');
            return $generic;
        }
        WSM_Security::log('staff_otp_sent', 'info', 0, 'کد ورود پیامکی برای کارمند ارسال شد.');
        return $generic;
    }

    /** Verify one six-digit SMS code and issue the same device-bound session as password login. */
    public static function verify_login_otp($phone, $code, $info = array(), $remember = false) {
        $ready = self::login_preflight();
        if (is_wp_error($ready)) { return $ready; }
        $phone = is_scalar($phone) ? trim((string) $phone) : '';
        $code = is_scalar($code) ? trim((string) $code) : '';
        if (class_exists('WSM_Persian_Normalizer')) { $code = WSM_Persian_Normalizer::normalize($code); }
        if (!preg_match('/^[0-9]{6}$/D', $code)) { $code = ''; }
        $matches = $phone !== '' ? self::employee_phone_matches($phone, 0, true) : array();
        $user = count($matches) === 1 ? $matches[0] : false;
        $state = $user ? get_transient(self::staff_otp_transient_key((int) $user->ID)) : false;
        $valid_state = is_array($state) && !empty($state['hash']) && !empty($state['expires']) && (int) $state['expires'] >= time() && (int) $state['attempts'] < 5;
        if (!$user || !$valid_state || $code === '' || !password_verify($code, (string) $state['hash'])) {
            if ($user && $valid_state) {
                $state['attempts'] = (int) $state['attempts'] + 1;
                if ((int) $state['attempts'] >= 5) { delete_transient(self::staff_otp_transient_key((int) $user->ID)); }
                else { set_transient(self::staff_otp_transient_key((int) $user->ID), $state, max(1, (int) $state['expires'] - time())); }
            }
            self::register_staff_login_failure();
            WSM_Security::log('staff_otp_failed', 'warning', 0, 'تأیید کد ورود پیامکی ناموفق بود.');
            return new WP_Error('wsm_login_otp_invalid', 'کد پیامکی معتبر نیست یا منقضی شده است.', array('status' => 401));
        }
        delete_transient(self::staff_otp_transient_key((int) $user->ID));
        return self::complete_staff_login($user, $info, $remember);
    }

    private static function staff_otp_transient_key($user_id) {
        return 'wsm_staff_otp_' . absint($user_id);
    }

    /** At most three verification-code sends per identifier and source IP per hour. */
    private static function auth_code_rate_allowed($purpose, $identifier) {
        $purpose = sanitize_key((string) $purpose);
        $salt = wp_salt('auth');
        $normalized = $purpose === 'login' ? self::normalize_employee_phone($identifier) : '';
        if ($normalized === '') { $normalized = strtolower(trim((string) $identifier)); }
        $ip = WSM_Util::client_ip();
        $keys = array(
            'wsm_authcode_' . $purpose . '_id_' . substr(hash_hmac('sha256', $normalized, $salt), 0, 32),
            'wsm_authcode_' . $purpose . '_ip_' . substr(hash_hmac('sha256', $ip, $salt), 0, 32),
        );
        foreach ($keys as $key) { if ((int) get_transient($key) >= 3) { return false; } }
        foreach ($keys as $key) { set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS); }
        return true;
    }

    private static function staff_login_policy() {
        $policy = get_option('wsm_security_policy', array());
        if (!is_array($policy)) { $policy = array(); }
        return array(
            'max_attempts' => max(1, min(20, (int) (isset($policy['max_attempts']) ? $policy['max_attempts'] : 5))),
            'window_minutes' => max(1, min(1440, (int) (isset($policy['window_minutes']) ? $policy['window_minutes'] : 15))),
            'lock_minutes' => max(1, min(1440, (int) (isset($policy['lock_minutes']) ? $policy['lock_minutes'] : 30))),
        );
    }

    private static function staff_login_transient_key() {
        $ip = WSM_Util::client_ip();
        return 'wsm_staff_login_' . substr(hash_hmac('sha256', (string) $ip, wp_salt('auth')), 0, 40);
    }

    private static function staff_login_blocked_until() {
        $state = get_transient(self::staff_login_transient_key());
        if (!is_array($state) || empty($state['blocked_until'])) { return false; }
        return (int) $state['blocked_until'] > time() ? (int) $state['blocked_until'] : false;
    }

    private static function register_staff_login_failure() {
        $policy = self::staff_login_policy();
        $key = self::staff_login_transient_key();
        $state = get_transient($key);
        $now = time();
        if (!is_array($state) || empty($state['window_start']) || $now - (int) $state['window_start'] >= $policy['window_minutes'] * 60) {
            $state = array('window_start' => $now, 'count' => 0, 'blocked_until' => 0);
        }
        $state['count'] = (int) $state['count'] + 1;
        if ($state['count'] >= $policy['max_attempts']) { $state['blocked_until'] = $now + $policy['lock_minutes'] * 60; }
        set_transient($key, $state, max(60, ($policy['window_minutes'] + $policy['lock_minutes']) * 60));
        if (!empty($state['blocked_until'])) { WSM_Security::log('staff_login_locked', 'warning', 0, 'ورود کارکنان برای این IP موقتاً قفل شد.'); }
    }

    /** Normalize phone digits and common Iranian +98/0098 forms for safe matching. */
    private static function normalize_employee_phone($value) {
        if (!is_scalar($value)) { return ''; }
        $text = trim((string) $value);
        if ($text === '' || is_email($text) || preg_match('/[\p{L}@]/u', $text)) { return ''; }
        if (class_exists('WSM_Persian_Normalizer')) { $text = WSM_Persian_Normalizer::normalize($text); }
        else {
            $text = strtr($text, array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'));
        }
        $digits = preg_replace('/[^0-9]+/', '', $text);
        if (!is_string($digits) || $digits === '') { return ''; }
        if (strpos($digits, '0098') === 0 && strlen($digits) === 14) { $digits = '0' . substr($digits, 4); }
        elseif (strpos($digits, '98') === 0 && strlen($digits) === 12) { $digits = '0' . substr($digits, 2); }
        elseif (strlen($digits) === 10 && $digits[0] === '9') { $digits = '0' . $digits; }
        return $digits;
    }

    /** @return array<int, WP_User> Users carrying the matching WSM employee phone. */
    private static function employee_phone_matches($value, $exclude_id = 0, $eligible_only = false) {
        $normalized = self::normalize_employee_phone($value);
        if ($normalized === '' || strlen($normalized) < 7) { return array(); }
        $ids = get_users(array(
            'fields' => 'ID',
            'number' => -1,
            'meta_query' => array(array('key' => 'wsm_app_phone', 'compare' => 'EXISTS')),
        ));
        $matches = array();
        foreach ((array) $ids as $raw_id) {
            $user_id = absint($raw_id);
            if (!$user_id || $user_id === absint($exclude_id)) { continue; }
            if (self::normalize_employee_phone(get_user_meta($user_id, 'wsm_app_phone', true)) !== $normalized) { continue; }
            $user = get_userdata($user_id);
            if (!$user || ($eligible_only && !self::app_login_eligible($user))) { continue; }
            $matches[] = $user;
            if (count($matches) > 1) { break; }
        }
        return $matches;
    }

    private static function employee_phone_is_taken($value, $exclude_id = 0) {
        return count(self::employee_phone_matches($value, $exclude_id, false)) > 0;
    }

    /** Make a unique ASCII WordPress login; collisions across all WP accounts receive a numeric suffix. */
    private static function unique_employee_login($requested, $email, $phone, $name) {
        $requested = is_scalar($requested) ? trim((string) $requested) : '';
        $sources = array();
        if ($requested !== '') { $sources[] = $requested; }
        if (is_string($email) && $email !== '' && strpos($email, '@') !== false) { $sources[] = substr($email, 0, strpos($email, '@')); }
        if (is_string($name) && $name !== '') { $sources[] = $name; }
        $phone_digits = self::normalize_employee_phone($phone);
        if ($phone_digits !== '') { $sources[] = 'staff'; }
        $base = '';
        foreach ($sources as $source) {
            $candidate = sanitize_user((string) $source, true);
            if ($candidate !== '' && validate_username($candidate)) { $base = $candidate; break; }
        }
        if ($base === '') { $base = 'employee'; }
        $base = substr($base, 0, 52);
        $login = $base;
        $suffix = 1;
        while (username_exists($login) && $suffix < 100000) {
            $suffix++;
            $tail = '-' . $suffix;
            $login = substr($base, 0, 60 - strlen($tail)) . $tail;
        }
        return validate_username($login) ? $login : '';
    }

    private static function app_password_length($password) {
        $password = (string) $password;
        if (function_exists('mb_strlen')) { return (int) mb_strlen($password, 'UTF-8'); }
        $chars = preg_split('//u', $password, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? count($chars) : strlen($password);
    }

    private static function lookup_recovery_user($identifier) {
        $matches = self::identity_matches($identifier, false);
        return count($matches) === 1 ? $matches[0] : false;
    }

    /** Rate-limit every identifier and source-IP pair to three requests per hour. */
    private static function recovery_rate_allowed($identifier) {
        $normalized = self::normalize_employee_phone($identifier);
        if ($normalized === '') { $normalized = strtolower(trim((string) $identifier)); }
        $salt = wp_salt('auth');
        $ip = WSM_Util::client_ip();
        $keys = array(
            'wsm_pwreset_id_' . substr(hash_hmac('sha256', $normalized, $salt), 0, 32),
            'wsm_pwreset_ip_' . substr(hash_hmac('sha256', $ip, $salt), 0, 32),
        );
        foreach ($keys as $key) { if ((int) get_transient($key) >= 3) { return false; } }
        foreach ($keys as $key) { set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS); }
        return true;
    }

    /** Send a six-digit reset code; only its password_hash() verifier is persisted. */
    public static function request_password_reset($identifier) {
        $identifier = is_scalar($identifier) ? trim((string) $identifier) : '';
        $generic = array('message' => 'اگر حساب فعال و راه ارتباطی ثبت‌شده از طریق سرویس مربوط در دسترس باشد، کد بازیابی ارسال می‌شود.');
        if ($identifier === '') { return $generic; }
        if (!self::recovery_rate_allowed($identifier)) {
            return new WP_Error('wsm_recovery_rate_limited', 'حداکثر سه درخواست بازیابی در هر ساعت مجاز است.', array('status' => 429));
        }
        $user = self::lookup_recovery_user($identifier);
        if (!$user) { return $generic; }
        $user_id = (int) $user->ID;
        if (!self::app_login_eligible($user)) { return $generic; }
        try { $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); }
        catch (Exception $e) { return $generic; }
        $hash = password_hash($code, self::password_algo());
        if (!is_string($hash) || $hash === '') { return $generic; }
        $expires_at = time() + self::RESET_TTL;
        update_user_meta($user_id, 'wsm_app_reset_hash', $hash);
        update_user_meta($user_id, 'wsm_app_reset_expires', $expires_at);
        update_user_meta($user_id, 'wsm_app_reset_attempts', 0);
        if (!hash_equals($hash, (string) get_user_meta($user_id, 'wsm_app_reset_hash', true))
            || (int) get_user_meta($user_id, 'wsm_app_reset_expires', true) !== $expires_at
            || (int) get_user_meta($user_id, 'wsm_app_reset_attempts', true) !== 0) {
            delete_user_meta($user_id, 'wsm_app_reset_hash');
            delete_user_meta($user_id, 'wsm_app_reset_expires');
            delete_user_meta($user_id, 'wsm_app_reset_attempts');
            return $generic;
        }

        $phone = self::normalize_employee_phone(get_user_meta($user_id, 'wsm_app_phone', true));
        $phone_channel = !is_email($identifier) && self::normalize_employee_phone($identifier) !== ''
            && self::normalize_employee_phone($phone) === self::normalize_employee_phone($identifier);
        if ($phone_channel) {
            $sent = false;
            if (class_exists('WSM_SMS') && WSM_SMS::is_configured()) {
                $result = WSM_SMS::dispatch_to('کد بازیابی گذرواژهٔ کارکنان WSM: ' . $code . ' — اعتبار تا ۱۰ دقیقه.', $phone);
                $sent = !empty($result['ok']);
                if (!$sent) { WSM_Security::log('staff_recovery_sms_failed', 'warning', 0, isset($result['detail']) ? (string) $result['detail'] : 'ارسال پیامک بازیابی ناموفق بود.'); }
            }
        } else {
            $email = sanitize_email((string) $user->user_email);
            $subject = 'کد بازیابی ورود کارکنان';
            $message = "کد یک‌بارمصرف شما: {$code}

این کد تا ۱۰ دقیقه معتبر است و پس از استفاده باطل می‌شود. اگر این درخواست را شما انجام نداده‌اید، این پیام را نادیده بگیرید.";
            $sent = is_email($email) && wp_mail($email, $subject, $message);
        }
        if (empty($sent)) {
            delete_user_meta($user_id, 'wsm_app_reset_hash');
            delete_user_meta($user_id, 'wsm_app_reset_expires');
            delete_user_meta($user_id, 'wsm_app_reset_attempts');
        }
        return $generic;
    }

    public static function reset_password($identifier, $code, $new_password) {
        $identifier = is_scalar($identifier) ? trim((string) $identifier) : '';
        $code = is_scalar($code) ? trim((string) $code) : '';
        if (class_exists('WSM_Persian_Normalizer')) { $code = WSM_Persian_Normalizer::normalize($code); }
        $new_password = is_scalar($new_password) ? (string) $new_password : '';
        if ($identifier === '' || !preg_match('/^\d{6}$/', $code)) { return new WP_Error('wsm_recovery_code_invalid', 'کد بازیابی معتبر نیست یا منقضی شده است.', array('status' => 400)); }
        if (self::app_password_length($new_password) < 6) { return new WP_Error('wsm_password_weak', 'گذرواژهٔ تازه باید دست‌کم ۶ نویسه داشته باشد.', array('status' => 400)); }
        $user = self::lookup_recovery_user($identifier);
        $hash = $user ? (string) get_user_meta((int) $user->ID, 'wsm_app_reset_hash', true) : '';
        $expires = $user ? (int) get_user_meta((int) $user->ID, 'wsm_app_reset_expires', true) : 0;
        $attempts = $user ? (int) get_user_meta((int) $user->ID, 'wsm_app_reset_attempts', true) : 0;
        if (!$user || $hash === '' || $expires < time() || $attempts >= 5 || !password_verify($code, $hash)) {
            if ($user && $hash !== '' && $expires >= time() && $attempts < 5) {
                update_user_meta((int) $user->ID, 'wsm_app_reset_attempts', $attempts + 1);
                if ($attempts + 1 >= 5) { delete_user_meta((int) $user->ID, 'wsm_app_reset_hash'); delete_user_meta((int) $user->ID, 'wsm_app_reset_expires'); }
            }
            return new WP_Error('wsm_recovery_code_invalid', 'کد بازیابی معتبر نیست یا منقضی شده است.', array('status' => 400));
        }
        $password_hash = password_hash($new_password, self::password_algo());
        if (!is_string($password_hash) || $password_hash === '') { return new WP_Error('wsm_password_hash_failed', 'هش گذرواژه ساخته نشد.', array('status' => 500)); }
        $user_id = (int) $user->ID;
        // Atomically consume the one-time code before changing credentials so concurrent reset
        // requests cannot both succeed. If a later step fails, the user can request a fresh code.
        if (update_user_meta($user_id, 'wsm_app_reset_attempts', 5) === false) {
            return new WP_Error('wsm_recovery_code_used', 'این کد بازیابی قبلاً مصرف شده است؛ کد تازه درخواست کنید.', array('status' => 400));
        }
        if (!self::revoke_user_sessions($user_id)) {
            return new WP_Error('wsm_session_revoke_failed', 'ابطال نشست‌های قبلی انجام نشد و گذرواژه تغییر نکرد؛ کد تازه درخواست کنید.', array('status' => 500));
        }
        global $wpdb;
        $key_result = $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'wsm_access_keys SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL AND key_hash <> %s', WSM_Util::now(), $user_id, ''));
        if ($key_result === false) {
            return new WP_Error('wsm_access_keys_revoke_failed', 'باطل‌کردن کلیدهای دسترسی انجام نشد و گذرواژه تغییر نکرد؛ کد تازه درخواست کنید.', array('status' => 500));
        }
        if (update_user_meta($user_id, 'wsm_app_password_hash', $password_hash) === false) {
            return new WP_Error('wsm_password_save_failed', 'ذخیرهٔ گذرواژه انجام نشد؛ برای تلاش دوباره کد تازه درخواست کنید.', array('status' => 500));
        }
        wp_set_password(wp_generate_password(64, true, true), $user_id);
        delete_user_meta($user_id, 'wsm_app_reset_hash');
        delete_user_meta($user_id, 'wsm_app_reset_expires');
        delete_user_meta($user_id, 'wsm_app_reset_attempts');
        WSM_Activity_Log::log('auth', 'password_reset', $user_id, $user->user_login, '', 'گذرواژه بازیابی شد', 'success', 0, $user_id);
        return array('ok' => true, 'message' => 'گذرواژه تغییر کرد. دوباره وارد شوید.');
    }

    /** Revoke all active app sessions, optionally keeping the current device. */
    public static function revoke_user_sessions($user_id, $except_device_id = 0) {
        global $wpdb;
        if (!WSM_Devices::ensure_schema()) { return false; }
        $sql = 'UPDATE ' . WSM_Devices::table() . ' SET token_hash = %s, refresh_hash = %s, token_expires = NULL, refresh_expires = NULL, remember_me = 0, signing_key = %s, updated_at = %s WHERE user_id = %d';
        $args = array('', '', '', WSM_Util::now(), (int) $user_id);
        if ((int) $except_device_id > 0) { $sql .= ' AND id <> %d'; $args[] = (int) $except_device_id; }
        return $wpdb->query($wpdb->prepare($sql, $args)) !== false;
    }

    private static function actor_permissions() {
        $device = WSM_Permissions::current_device();
        if ($device) { return WSM_Permissions::effective_permissions($device); }
        $user = get_userdata(get_current_user_id());
        if ($user && (in_array('administrator', (array) $user->roles, true) || !empty($user->allcaps['manage_options']))) { return array_keys(WSM_Devices::permission_catalog()); }
        return array();
    }

    private static function actor_can_assign_super_admin() {
        return in_array('users.super_admin.assign', self::actor_permissions(), true);
    }

    private static function user_preset_and_permissions($user_id) {
        $user = get_userdata((int) $user_id);
        $record = WSM_Permissions::user_permissions_record((int) $user_id);
        if (($user && user_can($user, 'manage_options')) || ($record !== null && $record['preset'] === 'super_admin') || get_user_meta((int) $user_id, 'wsm_app_role', true) === 'super_admin') {
            return array('preset' => 'super_admin', 'permissions' => array_keys(WSM_Devices::permission_catalog()));
        }
        if ($record !== null) { return $record; }
        $preset = sanitize_key((string) get_user_meta((int) $user_id, 'wsm_app_role', true));
        if ($preset === '' && $user) {
            if (in_array('administrator', (array) $user->roles, true) || !empty($user->allcaps['manage_options'])) { $preset = 'super_admin'; }
            elseif (in_array('shop_manager', (array) $user->roles, true) || !empty($user->allcaps['manage_woocommerce'])) { $preset = 'store_manager'; }
            else { $preset = 'custom'; }
        }
        $presets = WSM_Permissions::presets();
        if (!isset($presets[$preset])) { $preset = 'custom'; }
        return array('preset' => $preset, 'permissions' => $presets[$preset]['permissions']);
    }

    private static function clean_user_permission_list($permissions) {
        $catalog = WSM_Devices::permission_catalog();
        $clean = array();
        foreach ((array) $permissions as $permission) {
            if (is_string($permission) && isset($catalog[$permission]) && !in_array($permission, $clean, true)) { $clean[] = $permission; }
        }
        sort($clean, SORT_STRING);
        return $clean;
    }

    private static function app_user_card($user) {
        $user_id = (int) $user->ID;
        $record = self::user_preset_and_permissions($user_id);
        $presets = WSM_Permissions::presets();
        $preset = isset($presets[$record['preset']]) ? $record['preset'] : 'custom';
        $permission_list = self::clean_user_permission_list($record['permissions']);
        if ($preset === 'super_admin' || user_can($user, 'manage_options')) { $permission_list = array_keys(WSM_Devices::permission_catalog()); }
        global $wpdb;
        $device_count = 0;
        if (WSM_Devices::ensure_schema()) {
            $device_count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . WSM_Devices::table() . ' WHERE user_id = %d AND status <> %s', $user_id, 'deleted'));
        }
        return array(
            'id' => $user_id,
            'user_login' => (string) $user->user_login,
            'user_login_alias' => (string) get_user_meta($user_id, 'wsm_app_login_alias', true),
            'display_name' => trim((string) $user->display_name) !== '' ? (string) $user->display_name : (string) $user->user_login,
            'user_email' => (string) $user->user_email,
            'status' => get_user_meta($user_id, 'wsm_app_user_status', true) === 'inactive' ? 'inactive' : 'active',
            'deleted_at' => (string) get_user_meta($user_id, 'wsm_app_deleted_at', true),
            'preset' => $preset,
            'role_label' => $presets[$preset]['label'],
            'permissions' => $permission_list,
            'permission_count' => count($permission_list),
            'last_login' => (string) get_user_meta($user_id, 'wsm_app_last_login', true),
            'phone' => (string) get_user_meta($user_id, 'wsm_app_phone', true),
            'department' => (string) get_user_meta($user_id, 'wsm_app_department', true),
            'business_area' => (string) get_user_meta($user_id, 'wsm_app_business_area', true),
            'card_number' => (string) get_user_meta($user_id, 'wsm_app_card_number', true),
            'address' => (string) get_user_meta($user_id, 'wsm_app_address', true),
            'description' => (string) get_user_meta($user_id, 'wsm_app_description', true),
            'avatar' => (string) get_user_meta($user_id, 'wsm_app_avatar_data', true),
            'device_count' => $device_count,
        );
    }

    public static function list_app_users() {
        $actor = self::actor_permissions();
        if (!in_array('users.view', $actor, true) && !in_array('users.manage', $actor, true)) {
            return new WP_Error('wsm_users_forbidden', 'مجوز مشاهدهٔ کاربران را ندارید.', array('status' => 403));
        }
        $items = array();
        foreach ((array) get_users(array('number' => 500, 'orderby' => 'display_name', 'order' => 'ASC')) as $user) {
            $user_id = (int) $user->ID;
            $status = (string) get_user_meta($user_id, 'wsm_app_user_status', true);
            $role = (string) get_user_meta($user_id, 'wsm_app_role', true);
            $roles = is_array($user->roles) ? $user->roles : array();
            $manager = in_array('administrator', $roles, true) || in_array('shop_manager', $roles, true) || !empty($user->allcaps['manage_woocommerce']) || !empty($user->allcaps['manage_options']);
            if ($status === '' && $role === '' && !$manager && WSM_Permissions::user_permissions_record($user_id) === null) { continue; }
            $items[] = self::app_user_card($user);
        }
        return array(
            'items' => $items,
            'can_create' => in_array('users.manage', $actor, true),
            'can_edit' => in_array('users.manage', $actor, true),
            'can_delete' => in_array('users.delete', $actor, true),
            'permission_groups' => WSM_Devices::permission_groups(),
            'presets' => WSM_Permissions::presets(),
        );
    }

    public static function create_app_user($data) {
        $actor = self::actor_permissions();
        if (!in_array('users.manage', $actor, true)) { return new WP_Error('wsm_users_forbidden', 'مجوز مدیریت کاربران را ندارید.', array('status' => 403)); }
        if (!is_array($data)) { return new WP_Error('wsm_user_invalid', 'اطلاعات کاربر معتبر نیست.', array('status' => 400)); }
        $requested_login = isset($data['user_login']) && is_scalar($data['user_login']) ? trim((string) $data['user_login']) : '';
        $email_raw = isset($data['user_email']) && is_scalar($data['user_email']) ? trim((string) $data['user_email']) : '';
        $email = $email_raw !== '' ? sanitize_email($email_raw) : '';
        $name = sanitize_text_field(isset($data['display_name']) && is_scalar($data['display_name']) ? (string) $data['display_name'] : '');
        $password = isset($data['password']) && is_scalar($data['password']) ? (string) $data['password'] : '';
        $phone = substr(sanitize_text_field(isset($data['phone']) && is_scalar($data['phone']) ? (string) $data['phone'] : ''), 0, 40);
        $business_area = sanitize_key(isset($data['business_area']) && is_scalar($data['business_area']) ? (string) $data['business_area'] : '');
        $address = substr(sanitize_textarea_field(isset($data['address']) && is_scalar($data['address']) ? (string) $data['address'] : ''), 0, 500);
        $card_number = substr(sanitize_text_field(isset($data['card_number']) && is_scalar($data['card_number']) ? (string) $data['card_number'] : ''), 0, 80);
        $description = substr(sanitize_textarea_field(isset($data['description']) && is_scalar($data['description']) ? (string) $data['description'] : ''), 0, 2000);
        $avatar = self::clean_avatar_data(isset($data['avatar']) && is_scalar($data['avatar']) ? (string) $data['avatar'] : '');
        if (is_wp_error($avatar)) { return $avatar; }
        if ($email_raw !== '' && !is_email($email)) { return new WP_Error('wsm_user_email_invalid', 'ایمیل معتبر نیست.', array('status' => 400)); }
        if ($email !== '' && email_exists($email)) { return new WP_Error('wsm_user_email_exists', 'این ایمیل قبلاً در وردپرس ثبت شده است.', array('status' => 400)); }
        if (self::employee_phone_is_taken($phone)) { return new WP_Error('wsm_user_phone_exists', 'این شمارهٔ موبایل برای کارمند دیگری ثبت شده است.', array('status' => 409)); }
        $login = self::unique_employee_login($requested_login, $email, $phone, $name);
        if ($login === '') { return new WP_Error('wsm_user_login_invalid', 'ساخت نام کاربری یکتا انجام نشد؛ نام دیگری وارد کنید.', array('status' => 400)); }
        $login_alias = '';
        if ($requested_login !== '' && sanitize_user($requested_login, true) === '') {
            $login_alias = self::normalize_employee_login_alias($requested_login);
            if ($login_alias === '') { return new WP_Error('wsm_user_login_invalid', 'نام کاربری انتخابی معتبر نیست؛ نام دیگری وارد کنید.', array('status' => 400)); }
            if (self::employee_login_alias_is_taken($login_alias)) { return new WP_Error('wsm_user_login_alias_exists', 'این نام کاربری فارسی قبلاً برای حساب دیگری ثبت شده است.', array('status' => 409)); }
        }
        if (!in_array($business_area, array('', 'club', 'store', 'both'), true)) { return new WP_Error('wsm_user_business_area_invalid', 'حوزهٔ کاری معتبر نیست.', array('status' => 400)); }
        if (self::app_password_length($password) < 6) { return new WP_Error('wsm_password_weak', 'رمز عبور باید دست‌کم ۶ نویسه داشته باشد.', array('status' => 400)); }
        $presets = WSM_Permissions::presets();
        $preset = sanitize_key(isset($data['preset']) && is_scalar($data['preset']) ? (string) $data['preset'] : 'custom');
        if (!isset($presets[$preset])) { return new WP_Error('wsm_user_preset_invalid', 'قالب دسترسی معتبر نیست.', array('status' => 400)); }
        $grants = array_key_exists('permissions', $data) && is_array($data['permissions']) ? self::clean_user_permission_list($data['permissions']) : $presets[$preset]['permissions'];
        if ($preset === 'super_admin') { $grants = array_keys(WSM_Devices::permission_catalog()); }
        $can_super = self::actor_can_assign_super_admin();
        if (($preset === 'super_admin' || in_array('users.super_admin.assign', $grants, true)) && !$can_super) { return new WP_Error('wsm_super_admin_forbidden', 'واگذاری نقش مدیرکل فقط برای مدیرکل مجاز است.', array('status' => 403)); }
        if (!$can_super && array_diff($grants, $actor)) { return new WP_Error('wsm_permission_escalation', 'نمی‌توانید مجوزی فراتر از دسترسی‌های خود واگذار کنید.', array('status' => 403)); }
        $hash = password_hash($password, self::password_algo());
        if (!is_string($hash) || $hash === '') { return new WP_Error('wsm_password_hash_failed', 'ساخت هش گذرواژه انجام نشد.', array('status' => 500)); }
        $insert_args = array(
            'user_login' => $login,
            'user_email' => $email,
            'display_name' => $name !== '' ? $name : $login,
            'user_pass' => wp_generate_password(64, true, true),
            'role' => 'subscriber',
        );
        $id = 0;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $id = wp_insert_user($insert_args);
            if (!is_wp_error($id)) { break; }
            if (!in_array('existing_user_login', $id->get_error_codes(), true)) { return $id; }
            $login = self::unique_employee_login($login . '-' . ($attempt + 2), $email, $phone, $name);
            if ($login === '') { return $id; }
            $insert_args['user_login'] = $login;
            if ($name === '') { $insert_args['display_name'] = $login; }
        }
        if (is_wp_error($id)) { return $id; }
        $meta_results = array(
            update_user_meta((int) $id, 'wsm_app_user_status', 'active'),
            update_user_meta((int) $id, 'wsm_app_role', $preset),
            update_user_meta((int) $id, 'wsm_app_password_hash', $hash),
            update_user_meta((int) $id, 'wsm_app_phone', $phone),
            update_user_meta((int) $id, 'wsm_app_department', substr(sanitize_text_field(isset($data['department']) && is_scalar($data['department']) ? (string) $data['department'] : ''), 0, 80)),
            update_user_meta((int) $id, 'wsm_app_business_area', $business_area),
            update_user_meta((int) $id, 'wsm_app_card_number', $card_number),
            update_user_meta((int) $id, 'wsm_app_address', $address),
            update_user_meta((int) $id, 'wsm_app_description', $description),
            update_user_meta((int) $id, 'wsm_app_avatar_data', $avatar),
        );
        if ($login_alias !== '') { $meta_results[] = update_user_meta((int) $id, 'wsm_app_login_alias', $login_alias); }
        if (in_array(false, $meta_results, true)) {
            global $wpdb;
            if (WSM_Permissions::ensure_schema()) { $wpdb->delete(WSM_Permissions::user_table(), array('user_id' => (int) $id)); }
            if (file_exists(ABSPATH . 'wp-admin/includes/user.php')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
            if (function_exists('wp_delete_user')) { wp_delete_user((int) $id); }
            return new WP_Error('wsm_user_profile_save_failed', 'ذخیرهٔ اطلاعات کاربر انجام نشد؛ حساب ناقص حذف شد.', array('status' => 500));
        }
        $actor_device = WSM_Permissions::current_device();
        $updated_by = $actor_device ? (int) $actor_device->user_id : (int) get_current_user_id();
        if (!WSM_Permissions::save_user_permissions((int) $id, $grants, $preset, $updated_by)) {
            global $wpdb;
            $wpdb->delete(WSM_Permissions::user_table(), array('user_id' => (int) $id));
            if (file_exists(ABSPATH . 'wp-admin/includes/user.php')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
            if (function_exists('wp_delete_user')) { wp_delete_user((int) $id); }
            return new WP_Error('wsm_user_permissions_save_failed', 'ذخیرهٔ مجوزهای کاربر انجام نشد.', array('status' => 500));
        }
        $user = get_userdata((int) $id);
        WSM_Activity_Log::log('create', 'app_user', (int) $id, $login, '', array('preset' => $preset, 'permission_count' => count($grants)), 'success');
        return self::app_user_card($user);
    }

    public static function update_app_user($id, $data) {
        $actor = self::actor_permissions();
        if (!in_array('users.manage', $actor, true)) { return new WP_Error('wsm_users_forbidden', 'مجوز مدیریت کاربران را ندارید.', array('status' => 403)); }
        $id = (int) $id;
        $user = $id > 0 ? get_userdata($id) : false;
        if (!$user || !is_array($data)) { return new WP_Error('wsm_user_missing', 'کاربر پیدا نشد.', array('status' => 404)); }
        $roles = is_array($user->roles) ? $user->roles : array();
        $manager = in_array('administrator', $roles, true) || in_array('shop_manager', $roles, true) || !empty($user->allcaps['manage_woocommerce']) || !empty($user->allcaps['manage_options']);
        if (get_user_meta($id, 'wsm_app_user_status', true) === '' && get_user_meta($id, 'wsm_app_role', true) === '' && !$manager && WSM_Permissions::user_permissions_record($id) === null) { return new WP_Error('wsm_user_missing', 'حساب کارمند پیدا نشد.', array('status' => 404)); }
        $device = WSM_Permissions::current_device();
        $actor_id = $device ? (int) $device->user_id : (int) get_current_user_id();
        if (isset($data['status']) && !in_array($data['status'], array('active', 'inactive'), true)) { return new WP_Error('wsm_user_status_invalid', 'وضعیت کاربر معتبر نیست.', array('status' => 400)); }
        if ($actor_id === $id && isset($data['status']) && $data['status'] === 'inactive') { return new WP_Error('wsm_user_self_deactivate', 'نمی‌توانید حساب خود را غیرفعال کنید.', array('status' => 403)); }
        $existing = self::user_preset_and_permissions($id);
        $presets = WSM_Permissions::presets();
        $preset = isset($data['preset']) && is_scalar($data['preset']) ? sanitize_key((string) $data['preset']) : $existing['preset'];
        if (!isset($presets[$preset])) { return new WP_Error('wsm_user_preset_invalid', 'قالب دسترسی معتبر نیست.', array('status' => 400)); }
        $grants = isset($data['permissions']) && is_array($data['permissions']) ? self::clean_user_permission_list($data['permissions']) : (($preset !== $existing['preset']) ? $presets[$preset]['permissions'] : $existing['permissions']);
        $can_super = self::actor_can_assign_super_admin();
        $target_super = $existing['preset'] === 'super_admin' || $preset === 'super_admin' || in_array('users.super_admin.assign', $existing['permissions'], true)
            || in_array('administrator', $roles, true) || !empty($user->allcaps['manage_options']);
        if ($target_super) {
            $preset = 'super_admin';
            $grants = array_keys(WSM_Devices::permission_catalog());
            if (isset($data['status']) && $data['status'] === 'inactive') { return new WP_Error('wsm_user_super_admin_locked', 'حساب مدیر کل و دسترسی‌های کامل آن قابل غیرفعال‌سازی نیست.', array('status' => 403)); }
        }
        if (($preset === 'super_admin' || in_array('users.super_admin.assign', $grants, true) || ($target_super && $preset !== 'super_admin')) && !$can_super) { return new WP_Error('wsm_super_admin_forbidden', 'تغییر نقش مدیرکل فقط برای مدیرکل مجاز است.', array('status' => 403)); }
        if (!$can_super && array_diff($grants, $actor)) { return new WP_Error('wsm_permission_escalation', 'نمی‌توانید مجوزی فراتر از دسترسی‌های خود واگذار کنید.', array('status' => 403)); }
        $update = array('ID' => $id);
        $login_alias_update = null;
        if (array_key_exists('login_alias', $data)) {
            $raw_login_alias = is_scalar($data['login_alias']) ? trim((string) $data['login_alias']) : '';
            if ($raw_login_alias !== '') {
                $login_alias_update = self::normalize_employee_login_alias($raw_login_alias);
                if ($login_alias_update === '') { return new WP_Error('wsm_user_login_alias_invalid', 'نام جایگزین ورود معتبر نیست؛ ایمیل و شمارهٔ موبایل را در فیلدهای مربوط وارد کنید.', array('status' => 400)); }
                if (self::employee_login_alias_is_taken($login_alias_update, $id)) { return new WP_Error('wsm_user_login_alias_exists', 'این نام جایگزین قبلاً برای حساب دیگری ثبت شده است.', array('status' => 409)); }
            } else {
                $login_alias_update = '';
            }
        }
        if (array_key_exists('display_name', $data)) {
            $name = sanitize_text_field(is_scalar($data['display_name']) ? (string) $data['display_name'] : '');
            if ($name === '') { $name = (string) $user->user_login; }
            if (strlen($name) > 120) { return new WP_Error('wsm_profile_name_invalid', 'نام نمایشی حداکثر ۱۲۰ نویسه است.', array('status' => 400)); }
            $update['display_name'] = $name;
        }
        if (array_key_exists('user_email', $data)) {
            $email_raw = is_scalar($data['user_email']) ? trim((string) $data['user_email']) : '';
            $email = $email_raw !== '' ? sanitize_email($email_raw) : '';
            $owner = $email !== '' ? email_exists($email) : false;
            if (($email_raw !== '' && !is_email($email)) || ($owner && (int) $owner !== $id)) { return new WP_Error('wsm_profile_email_invalid', 'ایمیل نامعتبر یا تکراری است.', array('status' => 400)); }
            $update['user_email'] = $email;
        }
        $phone = null;
        if (array_key_exists('phone', $data)) {
            $phone = substr(sanitize_text_field(is_scalar($data['phone']) ? (string) $data['phone'] : ''), 0, 40);
            if (self::employee_phone_is_taken($phone, $id)) { return new WP_Error('wsm_user_phone_exists', 'این شمارهٔ موبایل برای کارمند دیگری ثبت شده است.', array('status' => 409)); }
        }
        $optional_meta = array();
        if (array_key_exists('business_area', $data)) {
            $area = sanitize_key(is_scalar($data['business_area']) ? (string) $data['business_area'] : '');
            if (!in_array($area, array('', 'club', 'store', 'both'), true)) { return new WP_Error('wsm_user_business_area_invalid', 'حوزهٔ کاری معتبر نیست.', array('status' => 400)); }
            $optional_meta['wsm_app_business_area'] = $area;
        }
        if (array_key_exists('address', $data)) { $optional_meta['wsm_app_address'] = substr(sanitize_textarea_field(is_scalar($data['address']) ? (string) $data['address'] : ''), 0, 500); }
        if (array_key_exists('card_number', $data)) { $optional_meta['wsm_app_card_number'] = substr(sanitize_text_field(is_scalar($data['card_number']) ? (string) $data['card_number'] : ''), 0, 80); }
        if (array_key_exists('description', $data)) { $optional_meta['wsm_app_description'] = substr(sanitize_textarea_field(is_scalar($data['description']) ? (string) $data['description'] : ''), 0, 2000); }
        if (array_key_exists('avatar', $data)) {
            $avatar = self::clean_avatar_data(is_scalar($data['avatar']) ? (string) $data['avatar'] : '');
            if (is_wp_error($avatar)) { return $avatar; }
            $optional_meta['wsm_app_avatar_data'] = $avatar;
        }
        $password = isset($data['password']) && is_scalar($data['password']) ? (string) $data['password'] : '';
        $password_hash = '';
        if ($password !== '') {
            if (self::app_password_length($password) < 6) { return new WP_Error('wsm_password_weak', 'گذرواژه باید دست‌کم ۶ نویسه داشته باشد.', array('status' => 400)); }
            $password_hash = password_hash($password, self::password_algo());
            if (!is_string($password_hash) || $password_hash === '') { return new WP_Error('wsm_password_hash_failed', 'هش گذرواژه ساخته نشد.', array('status' => 500)); }
        }
        if ($password_hash !== '' && !self::revoke_user_sessions($id)) {
            return new WP_Error('wsm_session_revoke_failed', 'ابطال نشست‌های کاربر انجام نشد؛ گذرواژه تغییر نکرد.', array('status' => 500));
        }
        if (count($update) > 1) { $result = wp_update_user($update); if (is_wp_error($result)) { return $result; } }
        if (!WSM_Permissions::save_user_permissions($id, $grants, $preset, $actor_id)) { return new WP_Error('wsm_user_permissions_save_failed', 'ذخیرهٔ مجوزهای کاربر انجام نشد.', array('status' => 500)); }
        $old_preset = (string) get_user_meta($id, 'wsm_app_role', true);
        $old_status = (string) get_user_meta($id, 'wsm_app_user_status', true);
        $new_status = isset($data['status']) ? $data['status'] : ($old_status !== '' ? $old_status : 'active');
        $preset_saved = update_user_meta($id, 'wsm_app_role', $preset);
        $status_saved = update_user_meta($id, 'wsm_app_user_status', $new_status);
        if (($preset_saved === false && $old_preset !== $preset) || ($status_saved === false && $old_status !== $new_status)) {
            return new WP_Error('wsm_user_meta_save_failed', 'ذخیرهٔ نقش یا وضعیت کاربر انجام نشد.', array('status' => 500));
        }
        if ($login_alias_update !== null) {
            if ($login_alias_update === '') {
                delete_user_meta($id, 'wsm_app_login_alias');
            } else {
                update_user_meta($id, 'wsm_app_login_alias', $login_alias_update);
            }
            if ((string) get_user_meta($id, 'wsm_app_login_alias', true) !== $login_alias_update) {
                return new WP_Error('wsm_user_login_alias_save_failed', 'ذخیرهٔ نام جایگزین ورود انجام نشد.', array('status' => 500));
            }
        }
        if (isset($data['status']) && $data['status'] === 'active' && get_user_meta($id, 'wsm_app_deleted_at', true) !== '') {
            delete_user_meta($id, 'wsm_app_deleted_at');
            if (get_user_meta($id, 'wsm_app_deleted_at', true) !== '') {
                return new WP_Error('wsm_user_restore_failed', 'حساب فعال شد، اما خروج از بایگانی در پایگاه داده انجام نشد.', array('status' => 500));
            }
        }
        if ($phone !== null) { update_user_meta($id, 'wsm_app_phone', $phone); }
        if (array_key_exists('department', $data)) { update_user_meta($id, 'wsm_app_department', substr(sanitize_text_field(is_scalar($data['department']) ? (string) $data['department'] : ''), 0, 80)); }
        foreach ($optional_meta as $meta_key => $meta_value) {
            if ($meta_key === 'wsm_app_avatar_data' && $meta_value === '') { delete_user_meta($id, $meta_key); }
            else { update_user_meta($id, $meta_key, $meta_value); }
        }
        if ($password_hash !== '') {
            if (update_user_meta($id, 'wsm_app_password_hash', $password_hash) === false) { return new WP_Error('wsm_password_save_failed', 'ذخیرهٔ گذرواژه انجام نشد.', array('status' => 500)); }
            wp_set_password(wp_generate_password(64, true, true), $id);
        }
        if (isset($data['status']) && $data['status'] === 'inactive') {
            self::revoke_user_sessions($id);
            global $wpdb;
            $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'wsm_access_keys SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL', WSM_Util::now(), $id));
        }
        WSM_Activity_Log::log('update', 'app_user', $id, $user->user_login, '', array('preset' => $preset, 'status' => get_user_meta($id, 'wsm_app_user_status', true), 'permission_count' => count($grants), 'password_changed' => $password_hash !== ''), 'success');
        return self::app_user_card(get_userdata($id));
    }

    public static function delete_app_user($id) {
        $actor = self::actor_permissions();
        if (!in_array('users.delete', $actor, true)) { return new WP_Error('wsm_users_delete_forbidden', 'مجوز حذف نرم کاربر را ندارید.', array('status' => 403)); }
        $id = (int) $id;
        $user = $id > 0 ? get_userdata($id) : false;
        if (!$user) { return new WP_Error('wsm_user_missing', 'کاربر پیدا نشد.', array('status' => 404)); }
        $roles = is_array($user->roles) ? $user->roles : array();
        $is_app_user = get_user_meta($id, 'wsm_app_user_status', true) !== ''
            || get_user_meta($id, 'wsm_app_role', true) !== ''
            || WSM_Permissions::user_permissions_record($id) !== null
            || in_array('administrator', $roles, true)
            || in_array('shop_manager', $roles, true)
            || !empty($user->allcaps['manage_woocommerce'])
            || !empty($user->allcaps['manage_options']);
        if (!$is_app_user) { return new WP_Error('wsm_user_missing', 'حساب کارمند پیدا نشد.', array('status' => 404)); }
        $record = self::user_preset_and_permissions($id);
        if (user_can($user, 'manage_options') || $record['preset'] === 'super_admin' || get_user_meta($id, 'wsm_app_role', true) === 'super_admin') { return new WP_Error('wsm_user_super_admin_locked', 'حساب مدیر کل قابل غیرفعال‌سازی نیست.', array('status' => 403)); }
        $device = WSM_Permissions::current_device();
        $actor_id = $device ? (int) $device->user_id : (int) get_current_user_id();
        if ($id === $actor_id) { return new WP_Error('wsm_user_self_delete', 'نمی‌توانید حساب خود را حذف کنید.', array('status' => 403)); }
        $target_super = $record['preset'] === 'super_admin' || in_array('users.super_admin.assign', $record['permissions'], true)
            || in_array('administrator', $roles, true) || !empty($user->allcaps['manage_options']);
        if ($target_super && !self::actor_can_assign_super_admin()) { return new WP_Error('wsm_super_admin_forbidden', 'حذف نرم مدیرکل فقط برای مدیرکل مجاز است.', array('status' => 403)); }
        $old_status = (string) get_user_meta($id, 'wsm_app_user_status', true);
        $old_deleted_at = (string) get_user_meta($id, 'wsm_app_deleted_at', true);
        if (update_user_meta($id, 'wsm_app_user_status', 'inactive') === false && get_user_meta($id, 'wsm_app_user_status', true) !== 'inactive') {
            return new WP_Error('wsm_user_deactivate_failed', 'غیرفعال‌کردن حساب در پایگاه داده انجام نشد.', array('status' => 500));
        }
        if ($old_deleted_at === '') {
            update_user_meta($id, 'wsm_app_deleted_at', current_time('mysql'));
            if (get_user_meta($id, 'wsm_app_deleted_at', true) === '') {
                return new WP_Error('wsm_user_archive_failed', 'ثبت حذف نرم کارمند در پایگاه داده انجام نشد.', array('status' => 500));
            }
        }
        if (!self::revoke_user_sessions($id)) {
            return new WP_Error('wsm_user_session_revoke_failed', 'حساب غیرفعال شد، اما باطل‌کردن نشست‌ها انجام نشد؛ با مدیر سامانه تماس بگیرید.', array('status' => 500));
        }
        global $wpdb;
        $key_result = $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'wsm_access_keys SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL', WSM_Util::now(), $id));
        if ($key_result === false) {
            return new WP_Error('wsm_user_keys_revoke_failed', 'حساب غیرفعال شد، اما باطل‌کردن کلیدهای دسترسی انجام نشد.', array('status' => 500));
        }
        WSM_Activity_Log::log('delete', 'app_user', $id, $user->user_login, 'active', 'inactive', 'success');
        return array('ok' => true, 'id' => $id, 'status' => 'inactive', 'deleted_at' => (string) get_user_meta($id, 'wsm_app_deleted_at', true));
    }

    /** Return the current caller's own staff profile. */
    public static function profile() {
        $device = WSM_Permissions::current_device();
        $user_id = $device ? (int) $device->user_id : (int) get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user) { return new WP_Error('wsm_profile_missing', 'حساب کاربری پیدا نشد.', array('status' => 404)); }
        $record = WSM_Permissions::user_permissions_record($user_id);
        return array(
            'user_id' => (int) $user->ID,
            'user_login' => (string) $user->user_login,
            'display_name' => (string) $user->display_name,
            'first_name' => (string) get_user_meta($user_id, 'first_name', true),
            'last_name' => (string) get_user_meta($user_id, 'last_name', true),
            'email' => (string) $user->user_email,
            'phone' => (string) get_user_meta($user_id, 'wsm_app_phone', true),
            'department' => (string) get_user_meta($user_id, 'wsm_app_department', true),
            'avatar' => (string) get_user_meta($user_id, 'wsm_app_avatar_data', true),
            'preset' => $record !== null ? (string) $record['preset'] : (string) get_user_meta($user_id, 'wsm_app_role', true),
        );
    }

    private static function clean_avatar_data($value) {
        if ($value === '' || $value === null) { return ''; }
        if (!is_string($value) || strlen($value) > 360000 || !preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $value, $match)) {
            return new WP_Error('wsm_profile_avatar_invalid', 'تصویر باید JPEG، PNG یا WebP و حداکثر ۲۵۶ کیلوبایت باشد.', array('status' => 400));
        }
        $binary = base64_decode($match[2], true);
        if (!is_string($binary) || strlen($binary) > 262144 || !function_exists('getimagesizefromstring')) {
            return new WP_Error('wsm_profile_avatar_invalid', 'تصویر معتبر نیست یا بیش از اندازه است.', array('status' => 400));
        }
        $image = @getimagesizefromstring($binary);
        $types = array('image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/webp' => 'webp');
        $mime = is_array($image) && isset($image['mime']) ? (string) $image['mime'] : '';
        if (!$image || !isset($types[$mime]) || (int) $image[0] > 2048 || (int) $image[1] > 2048 || $types[$mime] !== strtolower((string) $match[1])) {
            return new WP_Error('wsm_profile_avatar_invalid', 'نوع یا ابعاد تصویر معتبر نیست.', array('status' => 400));
        }
        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }

    /** Update only caller-owned profile fields; validate every input before writing. */
    public static function update_profile($data) {
        if (!is_array($data)) { return new WP_Error('wsm_profile_invalid', 'اطلاعات پروفایل معتبر نیست.', array('status' => 400)); }
        $device = WSM_Permissions::current_device();
        $user_id = $device ? (int) $device->user_id : (int) get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user) { return new WP_Error('wsm_profile_missing', 'حساب کاربری پیدا نشد.', array('status' => 404)); }
        $update = array('ID' => $user_id);
        $before = array('display_name' => (string) $user->display_name, 'email' => (string) $user->user_email);
        if (array_key_exists('display_name', $data)) {
            $name = sanitize_text_field(is_scalar($data['display_name']) ? (string) $data['display_name'] : '');
            if ($name === '' || strlen($name) > 120) { return new WP_Error('wsm_profile_name_invalid', 'نام نمایشی باید ۱ تا ۱۲۰ نویسه باشد.', array('status' => 400)); }
            $update['display_name'] = $name;
        }
        if (array_key_exists('email', $data)) {
            $email = sanitize_email(is_scalar($data['email']) ? (string) $data['email'] : '');
            $owner = $email !== '' ? email_exists($email) : false;
            if (!is_email($email) || ($owner && (int) $owner !== $user_id)) { return new WP_Error('wsm_profile_email_invalid', 'ایمیل نامعتبر یا متعلق به حساب دیگری است.', array('status' => 400)); }
            $update['user_email'] = $email;
        }
        if (array_key_exists('first_name', $data)) { $update['first_name'] = sanitize_text_field(is_scalar($data['first_name']) ? (string) $data['first_name'] : ''); }
        if (array_key_exists('last_name', $data)) { $update['last_name'] = sanitize_text_field(is_scalar($data['last_name']) ? (string) $data['last_name'] : ''); }
        $phone = null;
        if (array_key_exists('phone', $data)) {
            $phone = substr(sanitize_text_field(is_scalar($data['phone']) ? (string) $data['phone'] : ''), 0, 40);
            if (self::employee_phone_is_taken($phone, $user_id)) { return new WP_Error('wsm_user_phone_exists', 'این شمارهٔ موبایل برای کارمند دیگری ثبت شده است.', array('status' => 409)); }
        }
        $department = null;
        if (array_key_exists('department', $data)) {
            $department = sanitize_text_field(is_scalar($data['department']) ? (string) $data['department'] : '');
            if (strlen($department) > 80) { return new WP_Error('wsm_profile_department_invalid', 'نام بخش بیش از حد طولانی است.', array('status' => 400)); }
        }
        $avatar = null;
        if (array_key_exists('avatar', $data)) {
            $avatar = self::clean_avatar_data(is_scalar($data['avatar']) ? (string) $data['avatar'] : '');
            if (is_wp_error($avatar)) { return $avatar; }
        }
        $new_password = isset($data['new_password']) && is_scalar($data['new_password']) ? (string) $data['new_password'] : '';
        $password_hash = '';
        if ($new_password !== '') {
            $current = isset($data['current_password']) && is_scalar($data['current_password']) ? (string) $data['current_password'] : '';
            if (self::app_password_length($new_password) < 6) { return new WP_Error('wsm_password_weak', 'گذرواژهٔ تازه باید دست‌کم ۶ نویسه داشته باشد.', array('status' => 400)); }
            if ($current === '' || !self::verify_app_password($user, $current)) { return new WP_Error('wsm_profile_password_invalid', 'گذرواژهٔ فعلی نادرست است.', array('status' => 403)); }
            $password_hash = password_hash($new_password, self::password_algo());
            if (!is_string($password_hash) || $password_hash === '') { return new WP_Error('wsm_password_hash_failed', 'ذخیرهٔ گذرواژه انجام نشد.', array('status' => 500)); }
        }
        if ($password_hash !== '' && !self::revoke_user_sessions($user_id, $device ? (int) $device->id : 0)) {
            return new WP_Error('wsm_session_revoke_failed', 'ابطال نشست‌های دیگر انجام نشد؛ گذرواژه تغییر نکرد.', array('status' => 500));
        }
        if (count($update) > 1) {
            $result = wp_update_user($update);
            if (is_wp_error($result)) { return $result; }
        }
        if ($phone !== null) { update_user_meta($user_id, 'wsm_app_phone', $phone); }
        if ($department !== null) { update_user_meta($user_id, 'wsm_app_department', $department); }
        if ($avatar !== null) {
            if ($avatar === '') { delete_user_meta($user_id, 'wsm_app_avatar_data'); }
            else { update_user_meta($user_id, 'wsm_app_avatar_data', $avatar); }
        }
        $password_changed = false;
        if ($password_hash !== '') {
            if (update_user_meta($user_id, 'wsm_app_password_hash', $password_hash) === false) {
                return new WP_Error('wsm_password_save_failed', 'ذخیرهٔ گذرواژه انجام نشد.', array('status' => 500));
            }
            wp_set_password(wp_generate_password(64, true, true), $user_id);
            $password_changed = true;
        }
        $fresh = get_userdata($user_id);
        $after = array('display_name' => $fresh ? (string) $fresh->display_name : '', 'email' => $fresh ? (string) $fresh->user_email : '', 'password_changed' => $password_changed);
        WSM_Activity_Log::log('update', 'profile', $user_id, $user->user_login, $before, $after, 'success', $device ? (int) $device->id : 0);
        return self::profile();
    }

    /* ---------------- اتصال دستگاه ---------------- */

    /**
     * اتصال دستگاه با کلید دسترسی
     * @return array|WP_Error
     */
    public static function handshake($plain_key, $info) {
        if (!WSM_Crypto::available()) {
            return new WP_Error('wsm_crypto_unavailable', 'رمزنگاری (openssl) در این سرور فعال نیست.', array('status' => 500));
        }

        $until = WSM_Security::blocked_until();
        if ($until) {
            return new WP_Error('wsm_ip_blocked', 'IP شما موقتاً مسدود است. ۵ دقیقه بعد دوباره تلاش کنید.', array('status' => 429));
        }

        $user_id = self::access_key_user_id($plain_key);
        if (!$user_id) {
            WSM_Security::register_failure('handshake_invalid_key');
            WSM_Activity_Log::log('auth', 'device', 0, 'تلاش ناموفق اتصال', '', '', 'failed', 0);
            return new WP_Error('wsm_invalid_key', 'کلید دسترسی نامعتبر، باطل یا منقضی است.', array('status' => 401));
        }
        $user = get_userdata($user_id);
        if (!$user || (!user_can($user, 'manage_woocommerce') && !user_can($user, 'manage_options'))) {
            return new WP_Error('wsm_user_invalid', 'کاربر کلید دسترسی دیگر دسترسی ندارد.', array('status' => 403));
        }
        try {
            // The Access Key binds the site only; it is never an employee login or an admin session.
            return array(
                'token' => 'wsm_bootstrap_' . bin2hex(random_bytes(32)),
                'refresh_token' => 'wsm_bootstrap_refresh_' . bin2hex(random_bytes(32)),
                'token_expires' => null,
                'signing_key' => '',
                'device_id' => 0,
                'store_name' => WSM_Util::settings()['store_name'],
                'login_required' => true,
            );
        } catch (Exception $e) {
            return new WP_Error('wsm_bootstrap_random_failed', 'آماده‌سازی ورود کارمند انجام نشد.', array('status' => 500));
        }
    }

    /** Issue a short-lived, one-use pairing grant for an authenticated store manager. */
    public static function create_bridge_pairing_token($origin) {
        $origin = self::normalize_origin($origin);
        if (!$origin || $origin !== self::site_api_origin()) {
            return new WP_Error('wsm_bridge_origin_invalid', 'مبدأ اتصال پل با REST API این سایت یکسان نیست.', array('status' => 403));
        }
        $device = WSM_Permissions::current_device();
        if (!$device || $device->status !== 'active') {
            return new WP_Error('wsm_bridge_pair_device_invalid', 'دستگاه درخواست‌کننده فعال نیست.', array('status' => 403));
        }
        $user_id = (int) $device->user_id;
        $user = get_userdata($user_id);
        if (!$user || get_user_meta($user_id, 'wsm_app_user_status', true) === 'inactive' ||
            (!user_can($user, 'manage_woocommerce') && !user_can($user, 'manage_options'))) {
            return new WP_Error('wsm_bridge_pair_forbidden', 'جفت‌سازی پل فقط برای مدیر فروشگاه مجاز است.', array('status' => 403));
        }
        try {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (Exception $e) {
            return new WP_Error('wsm_bridge_pair_random_failed', 'ساخت کد جفت‌سازی امن انجام نشد.', array('status' => 500));
        }
        $record = array(
            'user_id' => $user_id,
            'request_device_id' => (int) $device->id,
            'origin' => $origin,
            'created_at' => time(),
        );
        set_transient('wsm_bridge_pair_' . self::hash($token), $record, 5 * MINUTE_IN_SECONDS);
        return array('pairing_token' => $token, 'site_origin' => $origin, 'expires_in' => 300);
    }

    /** Consume the one-use pairing grant and issue a hardware bridge with only club.bridge. */
    public static function pair_bridge($token, $origin, $info) {
        $token = is_string($token) ? trim($token) : '';
        $origin = self::normalize_origin($origin);
        if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token) || !$origin || $origin !== self::site_api_origin()) {
            return new WP_Error('wsm_bridge_pair_invalid', 'کد جفت‌سازی یا مبدأ نامعتبر است.', array('status' => 401));
        }
        $key = 'wsm_bridge_pair_' . self::hash($token);
        $record = get_transient($key);
        if (!is_array($record) || empty($record['user_id']) || empty($record['request_device_id'])) {
            return new WP_Error('wsm_bridge_pair_expired', 'کد جفت‌سازی منقضی یا قبلاً مصرف شده است.', array('status' => 401));
        }
        if (!hash_equals((string) $record['origin'], $origin)) {
            return new WP_Error('wsm_bridge_pair_origin_denied', 'کد جفت‌سازی برای این مبدأ صادر نشده است.', array('status' => 403));
        }
        $request_device = WSM_Devices::find((int) $record['request_device_id']);
        $user_id = (int) $record['user_id'];
        $user = get_userdata($user_id);
        if (!$request_device || $request_device->status !== 'active' || (int) $request_device->user_id !== $user_id ||
            !$user || get_user_meta($user_id, 'wsm_app_user_status', true) === 'inactive' ||
            (!user_can($user, 'manage_woocommerce') && !user_can($user, 'manage_options'))) {
            delete_transient($key);
            return new WP_Error('wsm_bridge_pair_forbidden', 'مجوز مدیر یا دستگاه درخواست‌کننده دیگر فعال نیست.', array('status' => 403));
        }
        delete_transient($key);
        // Re-pairing from the same authenticated WSM device reuses its dedicated bridge row.
        // A separate namespace prevents the bridge token from replacing the staff-login session.
        $bridge_fingerprint = hash_hmac('sha256', 'wsm-bridge:' . $user_id . ':' . (int) $request_device->id, wp_salt('auth'));
        return self::complete_handshake($user_id, $info, array('club.bridge'), $bridge_fingerprint);
    }

    private static function normalize_origin($origin) {
        if (!is_string($origin) || trim($origin) === '') { return ''; }
        $parts = wp_parse_url(trim($origin));
        if (!$parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            (isset($parts['path']) && !in_array($parts['path'], array('', '/'), true)) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, array('https', 'http'), true)) { return ''; }
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        if ($port && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $host .= ':' . $port;
        }
        return $scheme . '://' . $host;
    }

    private static function site_api_origin() {
        $parts = wp_parse_url(rest_url('wsm/v1/'));
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) { return ''; }
        $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        if ($port && !(($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80))) {
            $origin .= ':' . $port;
        }
        return $origin;
    }

    private static function complete_handshake($user_id, $info, $permissions = null, $fingerprint = '') {
        if (!WSM_Crypto::available()) {
            return new WP_Error('wsm_crypto_unavailable', 'رمزنگاری (openssl) در این سرور فعال نیست.', array('status' => 500));
        }
        $user = get_userdata((int) $user_id);
        if (!$user || (!user_can($user, 'manage_woocommerce') && !user_can($user, 'manage_options'))) {
            return new WP_Error('wsm_user_invalid', 'کاربر اتصال دیگر دسترسی مدیریت ندارد.', array('status' => 403));
        }
        $device = WSM_Devices::register((int) $user_id, is_array($info) ? $info : array(), $permissions, 'active', $fingerprint);
        if (is_wp_error($device)) { return $device; }
        if ($device->status === 'blocked') {
            return new WP_Error('wsm_bridge_device_blocked', 'دستگاه پل محلی مسدود است؛ ابتدا آن را در پنل مدیریت بررسی کنید.', array('status' => 403));
        }
        if (in_array($device->status, array('pending', 'unlinked'), true)) {
            $device = WSM_Devices::activate_after_login((int) $device->id);
            if (is_wp_error($device)) { return $device; }
        }
        if ($device->status !== 'active') {
            return new WP_Error('wsm_bridge_device_unavailable', 'دستگاه پل محلی قابل اتصال نیست.', array('status' => 403));
        }
        global $wpdb;
        $permission_update = $wpdb->update(WSM_Devices::table(), array(
            'permissions' => wp_json_encode((array) $permissions),
            'status' => 'active',
            'block_reason' => '',
            'updated_at' => WSM_Util::now(),
        ), array('id' => (int) $device->id));
        if ($permission_update === false) {
            return new WP_Error('wsm_bridge_device_update_failed', 'به‌روزرسانی مجوز دستگاه پل انجام نشد.', array('status' => 500));
        }
        $signing_key = bin2hex(random_bytes(32));
        if (!WSM_Devices::save_signing_key((int) $device->id, $signing_key)) {
            WSM_Devices::set_status((int) $device->id, 'delete', 'کلید امضای دستگاه ذخیره نشد');
            return new WP_Error('wsm_crypto_unavailable', 'کلید امضای دستگاه ذخیره نشد.', array('status' => 500));
        }
        $tokens = self::issue_tokens((int) $device->id);
        if (is_wp_error($tokens)) {
            WSM_Devices::set_status((int) $device->id, 'delete', 'ذخیرهٔ توکن دستگاه ناموفق بود');
            return $tokens;
        }
        WSM_Activity_Log::log('auth', 'device', (int) $device->id, $device->device_name, '', 'اتصال موفق', 'success', (int) $device->id, (int) $user_id);
        return array(
            'token' => $tokens['token'],
            'refresh_token' => $tokens['refresh'],
            'token_expires' => $tokens['expires'],
            'signing_key' => $signing_key,
            'device_id' => (int) $device->id,
            'store_name' => WSM_Util::settings()['store_name'],
            'user' => $user->display_name,
        );
    }

    public static function hash($value) {
        return hash_hmac('sha256', (string) $value, wp_salt('auth'));
    }

    /** Short access token with a fixed two-hour lifetime and an optional 30-day refresh window. */
    public static function issue_tokens($device_id, $remember = null, $refresh_expires = null) {
        if (!WSM_Devices::ensure_schema()) { return new WP_Error('wsm_token_schema_unavailable', 'ساختار نشست دستگاه‌ها آماده نیست.', array('status' => 500)); }
        $device = WSM_Devices::find((int) $device_id);
        if (!$device) { return new WP_Error('wsm_device_missing', 'دستگاه نشست پیدا نشد.', array('status' => 404)); }
        if ($remember === null) { $remember = isset($device->remember_me) ? ((int) $device->remember_me === 1) : false; }
        $remember = (bool) $remember;
        $now = current_time('timestamp');
        $settings = WSM_Util::settings();
        $token_ttl_days = max(1, min(365, (int) (isset($settings['token_ttl_days']) ? $settings['token_ttl_days'] : 90)));
        $access_ttl = $token_ttl_days * DAY_IN_SECONDS;
        $expires = date('Y-m-d H:i:s', $now + $access_ttl);
        if ($refresh_expires === null || $refresh_expires === '') {
            $refresh_ttl = $remember ? min(self::REMEMBER_TTL, $access_ttl) : $access_ttl;
            $refresh_expires = date('Y-m-d H:i:s', $now + $refresh_ttl);
        }
        if (strtotime((string) $refresh_expires) <= $now) { return new WP_Error('wsm_refresh_expired', 'نشست به پایان رسیده است؛ دوباره وارد شوید.', array('status' => 401)); }
        $token = self::TOKEN_PREFIX . wp_generate_password(40, false, false);
        $refresh = self::REFRESH_PREFIX . wp_generate_password(40, false, false);
        if (!WSM_Devices::save_tokens((int) $device_id, self::hash($token), self::hash($refresh), $expires, $remember, (string) $refresh_expires)) {
            return new WP_Error('wsm_token_store_failed', 'ذخیرهٔ نشست دستگاه انجام نشد.', array('status' => 500));
        }
        return array('token' => $token, 'refresh' => $refresh, 'expires' => $expires, 'refresh_expires' => (string) $refresh_expires, 'remember_me' => $remember);
    }

    /**
     * اعتبارسنجی توکن Bearer
     * @return object|WP_Error
     */
    public static function validate_bearer($token) {
        if (strpos((string) $token, self::TOKEN_PREFIX) !== 0) {
            return new WP_Error('wsm_token_invalid', 'توکن نامعتبر است.', array('status' => 401));
        }
        $device = WSM_Devices::find_by_token_hash(self::hash($token));
        if (!$device) {
            return new WP_Error('wsm_token_invalid', 'توکن نامعتبر است. دوباره متصل شوید.', array('status' => 401));
        }
        // App-permission bypasses are deliberately not handled here: Bearer
        // validation still requires a non-blocked, active, unexpired device session.
        if ($device->status === 'blocked') {
            return new WP_Error('wsm_device_blocked', 'این دستگاه مسدود شده است.', array('status' => 403));
        }
        if ($device->status !== 'active') {
            return new WP_Error('wsm_device_unlinked', 'دسترسی این دستگاه قطع شده است.', array('status' => 401));
        }
        if ($device->token_expires && strtotime($device->token_expires) < current_time('timestamp')) {
            return new WP_Error('wsm_token_expired', 'نشست منقضی شده است. دوباره متصل شوید.', array('status' => 401));
        }
        WSM_Security::check_new_ip($device, WSM_Util::client_ip());
        WSM_Devices::touch((int) $device->id, WSM_Util::client_ip());
        return $device;
    }

    /**
     * تمدید توکن با refresh token (چرخش هر دو توکن)
     * @return array|WP_Error
     */
    public static function refresh($refresh_token) {
        if (strpos((string) $refresh_token, self::REFRESH_PREFIX) !== 0) {
            return new WP_Error('wsm_refresh_invalid', 'توکن تمدید نامعتبر است.', array('status' => 401));
        }
        $device = WSM_Devices::find_by_refresh_hash(self::hash($refresh_token));
        if (!$device || $device->status !== 'active') {
            return new WP_Error('wsm_refresh_invalid', 'توکن تمدید نامعتبر است. دوباره وارد شوید.', array('status' => 401));
        }
        $user_id = (int) $device->user_id;
        $user = get_userdata($user_id);
        if (!$user) {
            WSM_Devices::set_status((int) $device->id, 'delete', 'حساب کاربری حذف شده است');
            return new WP_Error('wsm_user_missing', 'حساب کاربری نشست پیدا نشد.', array('status' => 403));
        }
        if (get_user_meta($user_id, 'wsm_app_user_status', true) === 'inactive') {
            return new WP_Error('wsm_app_user_inactive', 'حساب کاربری غیرفعال است.', array('status' => 403));
        }
        $permission_record = WSM_Permissions::user_permissions_record($user_id);
        $app_role = (string) get_user_meta($user_id, 'wsm_app_role', true);
        $roles = is_array($user->roles) ? $user->roles : array();
        $legacy_manager = in_array('administrator', $roles, true) || in_array('shop_manager', $roles, true) ||
            !empty($user->allcaps['manage_woocommerce']) || !empty($user->allcaps['manage_options']);
        $app_account = get_user_meta($user_id, 'wsm_app_user_status', true) === 'active' || $app_role !== '' || $permission_record !== null;
        if (!$legacy_manager && !$app_account) {
            WSM_Devices::set_status((int) $device->id, 'delete', 'حساب دیگر به‌عنوان کارمند فعال نیست');
            return new WP_Error('wsm_app_user_inactive', 'حساب کاربری دیگر مجوز ورود ندارد.', array('status' => 403));
        }
        $remember = isset($device->remember_me) ? ((int) $device->remember_me === 1) : false;
        $refresh_expires = !empty($device->refresh_expires) ? (string) $device->refresh_expires : (string) $device->token_expires;
        $now = current_time('timestamp');
        if (empty($device->refresh_expires) && $remember) {
            // Cap legacy sessions with no explicit refresh expiry at the new 30-day maximum.
            $legacy_cap = date('Y-m-d H:i:s', $now + self::REMEMBER_TTL);
            if ($refresh_expires === '' || strtotime($refresh_expires) > $now + self::REMEMBER_TTL) { $refresh_expires = $legacy_cap; }
        }
        if ($refresh_expires === '' || strtotime($refresh_expires) <= $now) {
            return new WP_Error('wsm_refresh_expired', 'نشست به پایان رسیده است؛ دوباره وارد شوید.', array('status' => 401));
        }
        $tokens = self::issue_tokens((int) $device->id, $remember, $refresh_expires);
        if (is_wp_error($tokens)) { return $tokens; }
        return array('token' => $tokens['token'], 'refresh_token' => $tokens['refresh'], 'token_expires' => $tokens['expires'], 'device_id' => (int) $device->id);
    }

}

