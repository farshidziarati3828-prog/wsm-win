<?php
/**
 * بررسی دسترسی درخواست‌های REST: توکن Bearer + سطح دسترسی کاربر ووکامرس
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Permissions {

    /** @var object|null */
    private static $device = null;

    /** @var bool Prevent recursive user_has_cap calls while app grants are resolved. */
    private static $app_caps_filter_active = false;

    /** @var bool */
    private static $schema_ready = false;

    /** @var array Request-local read-through cache for normalized user permission rows. */
    private static $user_permissions_record_cache = array();

    public static function user_table() {
        global $wpdb;
        return $wpdb->prefix . 'wsm_user_permissions';
    }

    /** Create one JSON permission row per WordPress user; no parallel user table. */
    public static function ensure_schema() {
        global $wpdb;
        if (self::$schema_ready) { return true; }
        $table = self::user_table();
        if (get_option('wsm_user_permissions_schema') === '1') {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($exists === $table) { self::$schema_ready = true; return true; }
        }
        if (!defined('ABSPATH') || !file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) { return false; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            user_id bigint(20) unsigned NOT NULL,
            permissions longtext NOT NULL,
            preset varchar(40) NOT NULL DEFAULT 'custom',
            updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (user_id),
            KEY preset (preset)
        ) {$charset};");
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) { return false; }
        update_option('wsm_user_permissions_schema', '1', false);
        self::$schema_ready = true;
        return true;
    }

    public static function presets() {
        $groups = WSM_Devices::permission_groups();
        $catalog = WSM_Devices::permission_catalog();
        $all = array_keys($catalog);
        $keys = function ($group) use ($groups) { return isset($groups[$group]) ? array_keys($groups[$group]) : array(); };
        $pick = function ($group, $wanted) use ($groups) {
            if (!isset($groups[$group])) { return array(); }
            return array_values(array_intersect(array_keys($groups[$group]), (array) $wanted));
        };
        $store_manager = array_merge(
            $keys('داشبورد'), $keys('محصولات و انبار'), $keys('سفارش‌ها'), $keys('فاکتورها'),
            $keys('مشتریان و حساب‌ها'), $pick('گزارش‌ها و تحلیل', array('reports.view','reports.sales','reports.stock','reports.customers','reports.finance','reports.export')),
            $keys('فروش حضوری و POS')
        );
        $registration = array_merge(
            $pick('داشبورد', array('dashboard.view','dashboard.club.summary')),
            $keys('باشگاه: اعضا'), $keys('طرح‌ها و ثبت‌نام'), $keys('حضور و غیاب'),
            $keys('پیامک و ارتباطات'),
            array('club.billing.view','club.payments.view','club.payments.create','club.receipts.print','club.directory.view','club.phonebook.view')
        );
        $finance = array_merge(
            $keys('امور مالی و صندوق'), $keys('شهریه و پرداخت‌های باشگاه'),
            $pick('گزارش‌ها و تحلیل', array('reports.view','reports.sales','reports.finance','reports.export','club.reports.view')),
            array('dashboard.view','dashboard.sales.today','customers.view','customers.account.manage','customers.credit.view','customers.credit.collect','orders.view','orders.history','invoices.view','invoices.print','club.staff.view','club.staff.manage','club.payroll.view','club.payroll.manage','club.payroll.mark_paid')
        );
        $builtins = array(
            'super_admin' => array('label' => 'مدیر کل', 'permissions' => $all),
            'store_manager' => array('label' => 'مدیر فروشگاه', 'permissions' => array_values(array_unique($store_manager))),
            'registration' => array('label' => 'مسئول ثبت‌نام', 'permissions' => array_values(array_unique($registration))),
            'finance' => array('label' => 'مسئول مالی', 'permissions' => array_values(array_unique($finance))),
            'buffet_seller' => array('label' => 'فروشندهٔ بوفه', 'permissions' => $keys('فروش حضوری و POS')),
            'custom' => array('label' => 'سفارشی', 'permissions' => array()),
        );
        foreach (self::custom_roles() as $key => $role) { $builtins[$key] = $role; }
        return $builtins;
    }

    /** Custom role templates live in an option; employee grants stay in wsm_user_permissions. */
    public static function custom_roles() {
        $stored = get_option('wsm_custom_permission_roles', array());
        if (!is_array($stored)) { return array(); }
        $catalog = WSM_Devices::permission_catalog();
        $roles = array();
        foreach ($stored as $key => $role) {
            $key = sanitize_key((string) $key);
            if (!preg_match('/^role_[a-z0-9_-]{1,32}$/', $key) || !is_array($role) || !isset($role['label'])) { continue; }
            $label = sanitize_text_field(is_scalar($role['label']) ? (string) $role['label'] : '');
            if ($label === '') { continue; }
            $permissions = array();
            foreach ((array) (isset($role['permissions']) ? $role['permissions'] : array()) as $permission) {
                if ($permission === 'users.super_admin.assign') { continue; }
                if (is_string($permission) && isset($catalog[$permission]) && !in_array($permission, $permissions, true)) { $permissions[] = $permission; }
            }
            sort($permissions, SORT_STRING);
            $roles[$key] = array('label' => $label, 'permissions' => $permissions);
        }
        return $roles;
    }

    public static function save_custom_roles($roles) {
        if (!is_array($roles)) { return false; }
        $catalog = WSM_Devices::permission_catalog();
        $safe = array();
        foreach ($roles as $key => $role) {
            $key = sanitize_key((string) $key);
            if (!preg_match('/^role_[a-z0-9_-]{1,32}$/', $key) || !is_array($role)) { continue; }
            $label = sanitize_text_field(isset($role['label']) && is_scalar($role['label']) ? (string) $role['label'] : '');
            if ($label === '') { continue; }
            $permissions = array();
            foreach ((array) (isset($role['permissions']) ? $role['permissions'] : array()) as $permission) {
                if ($permission === 'users.super_admin.assign') { continue; }
                if (is_string($permission) && isset($catalog[$permission]) && !in_array($permission, $permissions, true)) { $permissions[] = $permission; }
            }
            sort($permissions, SORT_STRING);
            $safe[$key] = array('label' => $label, 'permissions' => $permissions);
        }
        update_option('wsm_custom_permission_roles', $safe, false);
        return self::custom_roles() === $safe;
    }

    /** @return array|null Null denotes a legacy account without an explicit row. */
    public static function user_permissions_record($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id < 1) { return null; }
        if (array_key_exists($user_id, self::$user_permissions_record_cache)) {
            return self::$user_permissions_record_cache[$user_id];
        }
        if (!self::ensure_schema()) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT permissions, preset FROM ' . self::user_table() . ' WHERE user_id = %d', $user_id), ARRAY_A);
        if (!is_array($row)) {
            self::$user_permissions_record_cache[$user_id] = null;
            return null;
        }
        $decoded = json_decode((string) $row['permissions'], true);
        $catalog = WSM_Devices::permission_catalog();
        $clean = array();
        foreach ((array) $decoded as $permission) {
            if (is_string($permission) && isset($catalog[$permission]) && !in_array($permission, $clean, true)) { $clean[] = $permission; }
        }
        $preset = (string) $row['preset'];
        if ($preset === 'super_admin') { $clean = array_keys($catalog); }
        else { $clean = array_values(array_diff($clean, array('users.super_admin.assign'))); }
        $record = array('preset' => $preset, 'permissions' => $clean);
        self::$user_permissions_record_cache[$user_id] = $record;
        return $record;
    }

    public static function save_user_permissions($user_id, $permissions, $preset = 'custom', $updated_by = 0) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id < 1 || !get_userdata($user_id) || !self::ensure_schema()) { return false; }
        $preset = sanitize_key((string) $preset);
        $presets = self::presets();
        if (!isset($presets[$preset])) { $preset = 'custom'; }
        if ($preset === 'super_admin') { $permissions = array_keys(WSM_Devices::permission_catalog()); }
        elseif (!is_array($permissions)) { $permissions = $presets[$preset]['permissions']; }
        else { $permissions = array_values(array_diff($permissions, array('users.super_admin.assign'))); }
        $catalog = WSM_Devices::permission_catalog();
        $clean = array();
        foreach ($permissions as $permission) {
            if (is_string($permission) && isset($catalog[$permission]) && !in_array($permission, $clean, true)) { $clean[] = $permission; }
        }
        sort($clean, SORT_STRING);
        $existing = $wpdb->get_var($wpdb->prepare('SELECT user_id FROM ' . self::user_table() . ' WHERE user_id = %d', $user_id));
        $now = current_time('mysql');
        $data = array('user_id'=>$user_id,'permissions'=>wp_json_encode($clean, JSON_UNESCAPED_UNICODE),'preset'=>$preset,'updated_by'=>max(0,(int)$updated_by),'updated_at'=>$now);
        if (!$existing) { $data['created_at'] = $now; }
        $result = $existing ? $wpdb->update(self::user_table(), $data, array('user_id'=>$user_id)) : $wpdb->insert(self::user_table(), $data);
        if ($result === false) { return false; }
        self::$user_permissions_record_cache[$user_id] = array(
            'preset' => $preset,
            'permissions' => $preset === 'super_admin' ? array_keys($catalog) : $clean,
        );
        if (class_exists('WSM_Devices') && !WSM_Devices::sync_user_permissions($user_id, $clean)) { return false; }
        return true;
    }

    /**
     * The WordPress capability is authoritative; a role slug is not a capability.
     * REST callers still perform Bearer/device validation before granting route access.
     */
    public static function is_wordpress_admin_user($user_or_id) {
        $user = is_object($user_or_id) ? $user_or_id : get_userdata((int) $user_or_id);
        if (!$user || !isset($user->ID) || !function_exists('user_can')) { return false; }
        return user_can($user, 'manage_options');
    }

    /** Return explicit per-user grants, a preset fallback, or null for legacy users. */
    public static function user_permissions($user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) { return null; }
        $user = get_userdata($user_id);
        $record = self::user_permissions_record($user_id);
        if (self::is_wordpress_admin_user($user) || ($record !== null && $record['preset'] === 'super_admin') || get_user_meta($user_id, 'wsm_app_role', true) === 'super_admin') {
            return array_keys(WSM_Devices::permission_catalog());
        }
        if ($record !== null) { return self::normalize_permissions($record['permissions']); }
        $role = sanitize_key((string) get_user_meta($user_id, 'wsm_app_role', true));
        $presets = self::presets();
        return ($role !== '' && isset($presets[$role])) ? self::normalize_permissions($presets[$role]['permissions']) : null;
    }

    private static function normalize_permissions($permissions) {
        $catalog = WSM_Devices::permission_catalog();
        $clean = array();
        foreach ((array) $permissions as $permission) {
            if ($permission === 'users.super_admin.assign') { continue; }
            if (is_string($permission) && isset($catalog[$permission]) && !in_array($permission, $clean, true)) { $clean[] = $permission; }
        }
        if (in_array('users.manage', $clean, true) && isset($catalog['club.users.manage']) && !in_array('club.users.manage', $clean, true)) { $clean[] = 'club.users.manage'; }
        if (in_array('club.users.manage', $clean, true) && isset($catalog['users.manage']) && !in_array('users.manage', $clean, true)) { $clean[] = 'users.manage'; }
        return $clean;
    }

    /** Device grants remain a ceiling over the account-level JSON grants. */
    public static function effective_permissions($device) {
        if (!$device || empty($device->user_id)) { return array(); }
        $user = get_userdata((int) $device->user_id);
        $record = self::user_permissions_record((int) $device->user_id);
        if (self::is_wordpress_admin_user($user) || ($record !== null && $record['preset'] === 'super_admin') || get_user_meta((int) $device->user_id, 'wsm_app_role', true) === 'super_admin') {
            return array_keys(WSM_Devices::permission_catalog());
        }
        $user_permissions = self::user_permissions((int) $device->user_id);
        $device_permissions = WSM_Devices::permissions_for($device);
        if ($user_permissions === null) { return self::normalize_permissions($device_permissions); }
        $user_permissions = self::normalize_permissions($user_permissions);
        if (!property_exists($device, 'permissions') || $device->permissions === null || $device->permissions === '') { return $user_permissions; }
        $device_permissions = self::normalize_permissions($device_permissions);
        return self::normalize_permissions(array_values(array_intersect($user_permissions, $device_permissions)));
    }

    public static function allows($device, $permission) {
        if (!isset(WSM_Devices::permission_catalog()[$permission])) { return false; }
        if ($permission === 'users.super_admin.assign') {
            $user_id = $device ? (int) $device->user_id : 0;
            $user = $user_id ? get_userdata($user_id) : false;
            $record = $user_id ? self::user_permissions_record($user_id) : null;
            return self::is_wordpress_admin_user($user) || ($record !== null && $record['preset'] === 'super_admin') || get_user_meta($user_id, 'wsm_app_role', true) === 'super_admin';
        }
        return in_array($permission, self::effective_permissions($device), true);
    }

    public static function has_current_permission($permission) {
        return self::allows(self::$device, $permission);
    }

    /** Grant only the WooCommerce capabilities expected by already-guarded app callbacks. */
    public static function filter_app_caps($allcaps, $caps, $args, $user) {
        if (self::$app_caps_filter_active || !self::$device || !is_object($user) || (int) $user->ID !== (int) self::$device->user_id) { return $allcaps; }
        // effective_permissions() checks manage_options with user_can(), which invokes
        // this filter again. Ignore only that nested capability-filter evaluation.
        self::$app_caps_filter_active = true;
        try {
            $permissions = self::effective_permissions(self::$device);
        } finally {
            self::$app_caps_filter_active = false;
        }
        $has_any = function ($keys) use ($permissions) {
            foreach ((array) $keys as $key) { if (in_array($key, $permissions, true)) { return true; } }
            return false;
        };
        if ($has_any(array('products.create','products.edit','products.delete','products.stock.edit','products.price.edit','orders.create','orders.edit','orders.change_status','orders.refund','orders.delete','invoices.create','invoices.edit','customers.account.manage','club.members.manage','club.billing.manage','club.attendance.manage','club.staff.manage','club.finance.manage','club.directory.manage','club.sms.manage','club.settings.manage','club.pos.create','club.users.manage','users.manage','settings.edit'))) {
            $allcaps['manage_woocommerce'] = true;
        }
        if ($has_any(array('products.create','products.edit','products.delete','products.stock.edit','products.price.edit'))) {
            foreach (array('edit_products','edit_others_products','publish_products','delete_products','delete_others_products') as $cap) { $allcaps[$cap] = true; }
        }
        if ($has_any(array('orders.create','orders.edit','orders.change_status','orders.refund','orders.delete','club.pos.create'))) {
            foreach (array('edit_shop_orders','read_private_shop_orders','delete_shop_orders') as $cap) { $allcaps[$cap] = true; }
        }
        if ($has_any(array('users.manage','club.users.manage'))) {
            foreach (array('list_users','create_users','edit_users') as $cap) { $allcaps[$cap] = true; }
        }
        if (!empty($user->allcaps['manage_options']) && in_array('security.manage', $permissions, true)) { $allcaps['manage_options'] = true; }
        return $allcaps;
    }

    public static function set_device($device) {
        self::$device = $device;
        if (!has_filter('user_has_cap', array(__CLASS__, 'filter_app_caps'))) {
            add_filter('user_has_cap', array(__CLASS__, 'filter_app_caps'), 10, 4);
        }
        if ($device) {
            wp_set_current_user((int) $device->user_id);
        }
    }

    public static function current_device_id() {
        return self::$device ? (int) self::$device->id : 0;
    }

    public static function current_device() {
        return self::$device;
    }

    public static function extract_token($request) {
        $header = $request->get_header('authorization');
        if (!$header && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
        }
        if (!$header && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }
        if ($header && preg_match('/^\s*Bearer\s+(\S+)/i', $header, $m)) {
            return $m[1];
        }
        // مسیر جایگزین برای هاست‌هایی که Authorization را حذف می‌کنند
        $alt = $request->get_header('x_wsm_token');
        return $alt ? trim($alt) : '';
    }

    /**
     * permission_callback همهٔ مسیرهای محافظت‌شده
     * @return true|WP_Error
     */
    public static function check_request($request) {
        if (!WSM_Util::is_woo_active()) {
            return WSM_Util::woo_required_error();
        }

        $until = WSM_Security::blocked_until();
        if ($until) {
            return new WP_Error('wsm_ip_blocked', 'IP شما موقتاً مسدود است. بعد از پایان مدت مسدودی دوباره تلاش کنید.', array('status' => 429));
        }

        $token = self::extract_token($request);
        if ($token === '') {
            return new WP_Error('wsm_no_token', 'توکن ارسال نشده است.', array('status' => 401));
        }

        $device = WSM_Auth::validate_bearer($token);
        if (is_wp_error($device)) {
            return $device;
        }

        $signed = WSM_Security::verify_signature($request, $device);
        if (is_wp_error($signed)) {
            return $signed;
        }

        $user = get_userdata((int) $device->user_id);
        if (!$user) {
            return new WP_Error('wsm_forbidden', 'دسترسی به این بخش ندارید.', array('status' => 403));
        }
        if (get_user_meta((int) $device->user_id, 'wsm_app_user_status', true) === 'inactive') {
            return new WP_Error('wsm_app_user_inactive', 'حساب کاربری این اپ غیرفعال شده است.', array('status' => 403));
        }
        $permission_record = self::user_permissions_record((int) $device->user_id);
        $app_role = (string) get_user_meta((int) $device->user_id, 'wsm_app_role', true);
        $roles = is_array($user->roles) ? $user->roles : array();
        $wp_admin = self::is_wordpress_admin_user($user);
        $legacy_manager = $wp_admin || in_array('administrator', $roles, true) || in_array('shop_manager', $roles, true) ||
            (!empty($user->allcaps['manage_woocommerce']) || !empty($user->allcaps['manage_options']));
        $app_account = get_user_meta((int) $device->user_id, 'wsm_app_user_status', true) === 'active' ||
            $app_role !== '' || $permission_record !== null;
        if (!$legacy_manager && !$app_account) {
            return new WP_Error('wsm_forbidden', 'دسترسی به این بخش ندارید.', array('status' => 403));
        }

        self::set_device($device);
        // Re-evaluate after installing the authenticated device/user context. A global
        // WordPress administrator bypasses app grants only for a mapped route; the
        // Bearer, HMAC, expiry, account and active-device checks above remain mandatory.
        $wp_admin = self::is_wordpress_admin_user($user);

        $required = self::required_permissions($request);
        if ($required === false) {
            return new WP_Error('wsm_permission_unmapped', 'برای این مسیر مجوز دستگاه تعریف نشده است.', array('status' => 403));
        }
        if ($required === null) {
            return true;
        }
        if ($wp_admin) {
            return true;
        }
        $any = is_array($required) ? $required : array($required);
        foreach ($any as $permission) {
            if (WSM_Devices::device_can($device, $permission)) {
                return true;
            }
        }
        return new WP_Error('wsm_device_permission_denied', 'این دستگاه مجوز انجام این عملیات را ندارد.', array('status' => 403, 'permission' => $any));
    }

    /**
     * Capability required per REST route. Unknown routes fail closed.
     * An array means any one of the listed capabilities is sufficient; handlers
     * still perform field-level checks for mixed settings updates.
     */
    private static function required_permissions($request) {
        $route = (string) $request->get_route();
        $route = preg_replace('#^/wsm/v1#', '', $route);
        // WordPress versions may expose either the concrete URL or the registered regex.
        $route = str_replace('(?P<id>\d+)', '0', $route);
        $method = strtoupper((string) $request->get_method());

        if ($route === '/sync/bulk') {
            return $method === 'POST' ? array('club.pos.create', 'club.members.manage') : false;
        }
        if ($route === '/me' || $route === '/logout' || $route === '/profile') {
            return null;
        }
        if ($route === '/settings') {
            return $method === 'GET' ? array('settings.view', 'security.manage') : ($method === 'PUT' ? array('settings.edit', 'security.manage') : false);
        }
        if ($route === '/dashboard') { return 'dashboard.view'; }
        if ($route === '/reports/sales' || $route === '/reports/stock') { return 'reports.view'; }
        if ($route === '/products/expiring') { return $method === 'GET' ? 'products.view' : false; }
        if ($route === '/categories') { return $method === 'GET' ? array('products.view','club.pos.create') : false; }
        if ($route === '/products') {
            return $method === 'GET' ? array('products.view','club.pos.create') : ($method === 'POST' ? 'products.create' : false);
        }
        if (preg_match('#^/products/\d+$#', $route)) {
            return $method === 'GET' ? 'products.view' : ($method === 'PUT' ? 'products.edit' : ($method === 'DELETE' ? 'products.delete' : false));
        }
        if (preg_match('#^/products/\d+/copy$#', $route)) { return 'products.create'; }
        if (preg_match('#^/products/\d+/image$#', $route)) { return 'products.edit'; }
        if ($route === '/products/barcode') { return $method === 'POST' ? array('products.create', 'products.edit') : false; }
        if ($route === '/orders') { return $method === 'GET' ? 'orders.view' : false; }
        if ($route === '/orders/manual') { return 'orders.create'; }
        if (preg_match('#^/orders/\d+$#', $route)) {
            return $method === 'GET' ? 'orders.view' : ($method === 'PUT' ? 'orders.edit' : ($method === 'DELETE' ? 'orders.delete' : false));
        }
        if (preg_match('#^/orders/\d+/invoice$#', $route)) { return 'invoices.create'; }
        if (preg_match('#^/orders/\d+/status$#', $route)) { return 'orders.change_status'; }
        if (preg_match('#^/orders/\d+/refund$#', $route)) { return 'orders.refund'; }
        if (preg_match('#^/orders/\d+/history$#', $route)) { return 'orders.history'; }
        if ($route === '/invoices') { return $method === 'GET' ? 'invoices.view' : ($method === 'POST' ? 'invoices.create' : false); }
        if (preg_match('#^/invoices/\d+$#', $route)) {
            return $method === 'GET' ? 'invoices.view' : ($method === 'PUT' ? 'invoices.edit' : ($method === 'DELETE' ? 'invoices.delete' : false));
        }
        if (preg_match('#^/invoices/\d+/status$#', $route)) { return 'invoices.change_status'; }
        if (preg_match('#^/invoices/\d+/copy$#', $route)) { return 'invoices.create'; }
        if (preg_match('#^/invoices/\d+/(?:html|label)$#', $route)) { return 'invoices.print'; }
        if ($route === '/customers') { return $method === 'GET' ? 'customers.view' : false; }
        if (preg_match('#^/customers/\d+$#', $route)) { return $method === 'GET' ? 'customers.view' : false; }
        if ($route === '/store-customers') {
            return $method === 'GET' ? array('customers.view', 'customers.account.manage') : ($method === 'POST' ? 'customers.account.manage' : false);
        }
        if (preg_match('#^/store-customers/\d+/payments$#', $route)) { return $method === 'POST' ? 'customers.account.manage' : false; }
        if (preg_match('#^/store-customers/\d+$#', $route)) {
            return $method === 'GET' ? array('customers.view', 'customers.account.manage') : ($method === 'PUT' ? 'customers.account.manage' : false);
        }
        if ($route === '/users') { return in_array($method, array('GET','POST'), true) ? 'users.super_admin.assign' : false; }
        if (preg_match('#^/users/\\d+$#', $route)) { return in_array($method, array('PUT','DELETE'), true) ? 'users.super_admin.assign' : false; }
        if ($route === '/devices') { return $method === 'GET' ? 'devices.view' : false; }
        if (preg_match('#^/devices/\d+/status$#', $route)) { return array('devices.manage', 'devices.approve', 'devices.block', 'devices.unlink', 'devices.delete'); }
        if ($route === '/activity') { return 'users.super_admin.assign'; }
        if ($route === '/security') { return 'users.super_admin.assign'; }
        if ($route === '/club/overview') { return 'club.members.view'; }
        if ($route === '/club/members') { return $method === 'GET' ? 'club.members.view' : ($method === 'POST' ? 'club.members.manage' : false); }
        if (preg_match('#^/club/members/\d+/archive$#', $route)) { return 'club.members.manage'; }
        if (preg_match('#^/club/members/\d+$#', $route)) { return $method === 'GET' ? 'club.members.view' : ($method === 'PUT' ? 'club.members.manage' : false); }
        if ($route === '/club/plans') { return $method === 'GET' ? 'club.members.view' : ($method === 'POST' ? 'club.members.manage' : false); }
        if (preg_match('#^/club/plans/\d+/archive$#', $route)) { return 'club.members.manage'; }
        if (preg_match('#^/club/plans/\d+$#', $route)) { return $method === 'PUT' ? 'club.members.manage' : false; }
        if ($route === '/club/memberships') { return $method === 'GET' ? 'club.members.view' : ($method === 'POST' ? 'club.members.manage' : false); }
        if (preg_match('#^/club/memberships/\d+/freeze$#', $route)) { return 'club.members.manage'; }
        if (preg_match('#^/club/memberships/\d+/cancel$#', $route)) { return 'club.members.manage'; }
        if ($route === '/club/payments') { return $method === 'GET' ? array('club.members.view','club.billing.view','club.payments.view') : ($method === 'POST' ? array('club.billing.manage','club.payments.create') : false); }
        if ($route === '/club/phonebook/sms/send') { return $method === 'POST' ? array('club.sms.manage','club.sms.bulk_send') : false; }
        if (in_array($route, array('/club/collections','/club/sports','/club/phonebook'), true)) {
            $kind = substr($route, strlen('/club/'));
            $view_permissions = array(
                'collections' => array('club.members.view','club.directory.view','club.directory.manage','club.collections.manage'),
                'sports' => array('club.members.view','club.directory.view','club.directory.manage','club.sports.manage'),
                'phonebook' => array('club.members.view','club.directory.view','club.directory.manage','club.phonebook.view','club.phonebook.manage'),
            );
            $manage_permissions = array(
                'collections' => array('club.directory.manage','club.collections.manage'),
                'sports' => array('club.directory.manage','club.sports.manage'),
                'phonebook' => array('club.directory.manage','club.phonebook.manage'),
            );
            return $method === 'GET' ? $view_permissions[$kind] : ($method === 'POST' ? $manage_permissions[$kind] : false);
        }
        if (preg_match('#^/club/(collections|sports|phonebook)/\d+$#', $route, $match)) {
            $manage_permissions = array(
                'collections' => array('club.directory.manage','club.collections.manage'),
                'sports' => array('club.directory.manage','club.sports.manage'),
                'phonebook' => array('club.directory.manage','club.phonebook.manage'),
            );
            return $method === 'PUT' ? $manage_permissions[$match[1]] : false;
        }
        if ($route === '/club/finance') { return array('club.finance.view','club.finance.manage','club.billing.manage'); }
        if ($route === '/club/expenses' || $route === '/club/finance/cash-removal') { return 'club.finance.manage'; }
        if (preg_match('#^/club/finance/\d+/void$#', $route)) { return 'club.finance.manage'; }
        if ($route === '/club/cards/recharge') { return 'club.billing.manage'; }
        if ($route === '/club/pos') { return array('club.pos.create','club.pos.quick_access.manage','club.settings.manage'); }
        if ($route === '/club/reports') { return 'club.reports.view'; }
        if ($route === '/club/settings') {
            if ($method === 'GET') {
                return array('club.settings.manage','club.pos.quick_access.manage','orders.create','club.sms.templates.manage','club.sms.manage','club.sms.view','club.sms.send','club.sms.bulk_send');
            }
            if ($method === 'PUT') {
                return array('club.settings.manage','club.pos.quick_access.manage','club.sms.templates.manage','club.sms.manage');
            }
            return false;
        }
        if ($route === '/club/sms/recipients') { return $method === 'GET' ? 'club.sms.recipients.export' : false; }
        if ($route === '/club/sms/log') {
            return $method === 'GET' ? array('club.sms.view','club.sms.manage','club.sms.logs.view','club.sms.send','club.sms.bulk_send') : false;
        }
        if ($route === '/club/sms/send') {
            return $method === 'POST' ? array('club.sms.manage','club.sms.send','club.sms.bulk_send') : false;
        }
        if ($route === '/club/users') { return 'club.users.manage'; }
        if (preg_match('#^/club/users/\d+$#', $route)) { return $method === 'PUT' ? 'club.users.manage' : false; }
        if ($route === '/club/attendance') { return $method === 'GET' ? 'club.attendance.view' : ($method === 'POST' ? array('club.attendance.manage','club.attendance.checkin','club.attendance.checkout') : false); }
        if ($route === '/club/staff') { return $method === 'GET' ? array('club.staff.view','club.staff.manage','club.payroll.manage') : ($method === 'POST' ? 'club.staff.manage' : false); }
        if (preg_match('#^/club/staff/\d+$#', $route)) { return $method === 'PUT' ? 'club.staff.manage' : false; }
        if ($route === '/club/payroll/accounts') { return array('club.payroll.mark_paid','club.payroll.manage','club.staff.manage'); }
        if ($route === '/club/payroll') { return $method === 'GET' ? array('club.payroll.view','club.payroll.manage','club.payroll.mark_paid','club.staff.manage') : ($method === 'POST' ? array('club.payroll.manage','club.staff.manage') : false); }
        if (preg_match('#^/club/payroll/\d+/paid$#', $route)) { return array('club.payroll.mark_paid','club.payroll.manage','club.staff.manage'); }
        // Pairing-token minting is manager-only at the account layer; the one-use token grants only club.bridge to the new local bridge.
        if ($route === '/bridge/pair-token') { return null; }
        if ($route === '/bridge/time') { return 'club.bridge'; }
        if ($route === '/bridge/access-list' || $route === '/bridge/attendance') { return 'club.bridge'; }
        if ($route === '/update-check') { return 'settings.view'; }
        return false;
    }
}

