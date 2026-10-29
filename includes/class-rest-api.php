<?php
/**
 * REST API: /wp-json/wsm/v1/...
 * همهٔ مسیرها به‌جز handshake و refresh با توکن Bearer محافظت می‌شوند.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Rest_API {

    const NS = 'wsm/v1';

    private static $app_init_shutdown_armed = false;
    private static $app_init_auth_verified = false;
    private static $app_init_output_level = null;
    private static $app_init_memory_reserve = null;

    public static function register() {
        add_filter('rest_post_dispatch', array(__CLASS__, 'disable_wsm_rest_caching'), 10, 3);
        // WooCommerce writes are invalidated centrally by WSM_Core; this REST filter
        // handles WSM-specific mutations after their callbacks have completed.
        // check_request() deliberately fails closed for unmapped routes. app-init runs
        // that complete Bearer/HMAC/account check, then authorizes each returned section below.
        register_rest_route(self::NS, '/app-init', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'app_init'),
            'permission_callback' => array(__CLASS__, 'app_init_permission'),
        ));
        // اتصال و نشست
        self::route('/auth/login', 'POST', 'auth_login', true);
        self::route('/auth/otp/request', 'POST', 'auth_login_otp_request', true);
        self::route('/auth/otp/verify', 'POST', 'auth_login_otp_verify', true);
        self::route('/auth/recovery/request', 'POST', 'auth_recovery_request', true);
        self::route('/auth/recovery/reset', 'POST', 'auth_recovery_reset', true);
        self::route('/handshake', 'POST', 'handshake', true);
        self::route('/refresh', 'POST', 'refresh', true);
        self::route('/logout', 'POST', 'logout');
        // Offline actions are authenticated here and each action is authorized by the processor.
        register_rest_route(self::NS, '/sync/bulk', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'sync_bulk'),
            'permission_callback' => array(__CLASS__, 'sync_bulk_permission'),
        ));
        // Internal messages have a separate fail-closed account check and scoped inbox rules.
        register_rest_route(self::NS, '/messages/send', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'messages_send'),
            'permission_callback' => array(__CLASS__, 'messages_permission'),
        ));
        register_rest_route(self::NS, '/messages/inbox', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'messages_inbox'),
            'permission_callback' => array(__CLASS__, 'messages_permission'),
        ));
        register_rest_route(self::NS, '/messages/recipients', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'messages_recipients'),
            'permission_callback' => array(__CLASS__, 'messages_permission'),
        ));
        register_rest_route(self::NS, '/messages/read', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'messages_read'),
            'permission_callback' => array(__CLASS__, 'messages_permission'),
        ));
        register_rest_route(self::NS, '/messages/delete', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'messages_delete'),
            'permission_callback' => array(__CLASS__, 'messages_permission'),
        ));
        register_rest_route(self::NS, '/messages/audit', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'messages_audit_list'),
            'permission_callback' => array(__CLASS__, 'messages_permission'),
        ));
        // Delta pull uses the same Bearer/HMAC validation with scoped read permissions.
        register_rest_route(self::NS, '/sync/pull', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'sync_pull'),
            'permission_callback' => array(__CLASS__, 'sync_pull_permission'),
        ));
        self::route('/me', 'GET', 'me');
        self::route('/profile', 'GET', 'profile_get');
        self::route('/profile', 'PUT', 'profile_update');
        self::route('/settings', 'GET', 'settings');
        self::route('/settings', 'PUT', 'settings_update');
        self::route('/security', 'GET', 'security_get');
        self::route('/security', 'PUT', 'security_update');

        // داشبورد و گزارش
        self::route('/dashboard', 'GET', 'dashboard');
        self::route('/reports/sales', 'GET', 'report_sales');
        self::route('/reports/stock', 'GET', 'report_stock');

        // محصولات
        self::route('/products', 'GET', 'products_list');
        self::route('/products', 'POST', 'products_create');
        self::route('/products/barcode', 'POST', 'products_barcode');
        self::route('/products/expiring', 'GET', 'products_expiring');
        self::route('/categories', 'GET', 'categories_list');
        self::route('/products/(?P<id>\d+)', 'GET', 'products_get');
        self::route('/products/(?P<id>\d+)', 'PUT', 'products_update');
        self::route('/products/(?P<id>\d+)', 'DELETE', 'products_delete');
        self::route('/products/(?P<id>\d+)/copy', 'POST', 'products_copy');
        self::route('/products/(?P<id>\d+)/image', 'POST', 'products_image');

        // سفارش‌ها
        self::route('/orders', 'GET', 'orders_list');
        self::route('/orders/manual', 'POST', 'orders_manual');
        self::route('/orders/(?P<id>\d+)', 'GET', 'orders_get');
        self::route('/orders/(?P<id>\d+)', 'PUT', 'orders_update');
        self::route('/orders/(?P<id>\d+)', 'DELETE', 'orders_delete');
        self::route('/orders/(?P<id>\d+)/invoice', 'POST', 'orders_invoice');
        self::route('/orders/(?P<id>\d+)/status', 'POST', 'orders_status');
        self::route('/orders/(?P<id>\d+)/refund', 'POST', 'orders_refund');
        self::route('/orders/(?P<id>\d+)/history', 'GET', 'orders_history');

        // فاکتورها
        self::route('/invoices', 'GET', 'invoices_list');
        self::route('/invoices', 'POST', 'invoices_create');
        self::route('/invoices/(?P<id>\d+)', 'GET', 'invoices_get');
        self::route('/invoices/(?P<id>\d+)', 'PUT', 'invoices_update');
        self::route('/invoices/(?P<id>\d+)', 'DELETE', 'invoices_delete');
        self::route('/invoices/(?P<id>\d+)/status', 'POST', 'invoices_status');
        self::route('/invoices/(?P<id>\d+)/copy', 'POST', 'invoices_copy');
        self::route('/invoices/(?P<id>\d+)/html', 'GET', 'invoices_html');
        self::route('/invoices/(?P<id>\d+)/label', 'GET', 'invoices_label');

        // مشتریان، دستگاه‌ها، فعالیت
        self::route('/customers', 'GET', 'customers_list');
        self::route('/customers/(?P<id>\d+)', 'GET', 'customers_get');
        self::route('/store-customers', 'GET', 'store_customers_list');
        self::route('/store-customers', 'POST', 'store_customers_create');
        self::route('/store-customers/(?P<id>\d+)', 'GET', 'store_customer_get');
        self::route('/store-customers/(?P<id>\d+)', 'PUT', 'store_customer_update');
        self::route('/store-customers/(?P<id>\d+)/payments', 'POST', 'store_customer_payment');
        self::route('/users', 'GET', 'app_users');
        self::route('/users', 'POST', 'app_user_create');
        self::route('/users/(?P<id>\\d+)', 'PUT', 'app_user_update');
        self::route('/users/(?P<id>\\d+)', 'DELETE', 'app_user_delete');
        self::route('/devices', 'GET', 'devices_list');
        self::route('/devices/(?P<id>\d+)/status', 'POST', 'devices_status');
        self::route('/activity', 'GET', 'activity');

        // مدیریت باشگاه
        self::route('/club/overview', 'GET', 'club_overview');
        self::route('/club/members', 'GET', 'club_members');
        self::route('/club/members', 'POST', 'club_member_create');
        self::route('/club/members/(?P<id>\d+)', 'GET', 'club_member_get');
        self::route('/club/members/(?P<id>\d+)', 'PUT', 'club_member_update');
        self::route('/club/members/(?P<id>\d+)/archive', 'POST', 'club_member_archive');
        self::route('/club/plans', 'GET', 'club_plans');
        self::route('/club/plans', 'POST', 'club_plan_create');
        self::route('/club/plans/(?P<id>\d+)', 'PUT', 'club_plan_update');
        self::route('/club/plans/(?P<id>\d+)/archive', 'POST', 'club_plan_archive');
        self::route('/club/memberships', 'GET', 'club_memberships');
        self::route('/club/memberships', 'POST', 'club_membership_create');
        self::route('/club/payments', 'GET', 'club_payments');
        self::route('/club/payments', 'POST', 'club_payment_create');
        self::route('/club/attendance', 'GET', 'club_attendance');
        self::route('/club/attendance', 'POST', 'club_attendance_create');
        self::route('/club/staff', 'GET', 'club_staff');
        self::route('/club/staff', 'POST', 'club_staff_create');
        self::route('/club/staff/(?P<id>\d+)', 'PUT', 'club_staff_update');
        self::route('/club/payroll', 'GET', 'club_payroll');
        self::route('/club/payroll', 'POST', 'club_payroll_create');
        self::route('/club/payroll/accounts', 'GET', 'club_payroll_accounts');
        self::route('/club/payroll/(?P<id>\d+)/paid', 'POST', 'club_payroll_paid');
        self::route('/club/memberships/(?P<id>\d+)/freeze', 'POST', 'club_membership_freeze');
        self::route('/club/memberships/(?P<id>\d+)/cancel', 'POST', 'club_membership_cancel');
        self::route('/club/collections', 'GET', 'club_collections');
        self::route('/club/collections', 'POST', 'club_collections_create');
        self::route('/club/collections/(?P<id>\d+)', 'PUT', 'club_collections_update');
        self::route('/club/sports', 'GET', 'club_sports');
        self::route('/club/sports', 'POST', 'club_sports_create');
        self::route('/club/sports/(?P<id>\d+)', 'PUT', 'club_sports_update');
        self::route('/club/phonebook', 'GET', 'club_phonebook');
        self::route('/club/phonebook', 'POST', 'club_phonebook_create');
        self::route('/club/phonebook/(?P<id>\d+)', 'PUT', 'club_phonebook_update');
        self::route('/club/phonebook/sms/send', 'POST', 'club_phonebook_sms_send');
        self::route('/club/finance', 'GET', 'club_finance');
        self::route('/club/finance/(?P<id>\d+)/void', 'POST', 'club_finance_void');
        self::route('/club/expenses', 'POST', 'club_expense_create');
        self::route('/club/finance/cash-removal', 'POST', 'club_cash_removal');
        self::route('/club/cards/recharge', 'POST', 'club_card_recharge');
        self::route('/club/pos', 'POST', 'club_pos_create');
        self::route('/club/reports', 'GET', 'club_reports');
        self::route('/club/settings', 'GET', 'club_settings');
        self::route('/club/settings', 'PUT', 'club_settings_update');
        self::route('/club/sms/log', 'GET', 'club_sms_log');
        self::route('/club/sms/send', 'POST', 'club_sms_send');
        self::route('/club/users', 'GET', 'club_users');
        self::route('/club/users', 'POST', 'club_user_create');
        self::route('/club/users/(?P<id>\d+)', 'PUT', 'club_user_update');

        // پل سخت‌افزار: جفت‌سازی مدیر، زمان سرور، فهرست اعضا و رخدادهای تردد
        self::route('/bridge/pair-token', 'POST', 'bridge_pair_token');
        self::route('/bridge/pair', 'POST', 'bridge_pair', true);
        self::route('/bridge/time', 'GET', 'bridge_time');
        self::route('/bridge/access-list', 'GET', 'bridge_access_list');
        self::route('/bridge/attendance', 'GET', 'bridge_attendance_list');
        self::route('/bridge/attendance', 'POST', 'bridge_attendance');

        // به‌روزرسانی
        self::route('/update-check', 'GET', 'update_check');
    }

    private static function route($path, $method, $callback, $public = false) {
        register_rest_route(self::NS, $path, array(
            'methods'             => $method,
            'callback'            => array(__CLASS__, $callback),
            'permission_callback' => $public ? '__return_true' : array('WSM_Permissions', 'check_request'),
        ));
    }

    /** Prevent browser and intermediary caches from retaining any WSM REST response. */
    public static function disable_wsm_rest_caching($response, $server, $request) {
        if (!$request || !method_exists($request, 'get_route')) { return $response; }
        $route = '/' . ltrim((string) $request->get_route(), '/');
        $prefix = '/' . trim(self::NS, '/') . '/';
        if (strpos($route, $prefix) !== 0 && rtrim($route, '/') !== rtrim($prefix, '/')) { return $response; }

        if (!in_array(strtoupper((string) $request->get_method()), array('GET', 'HEAD'), true)) {
            self::invalidate_stats_cache();
        }

        $cache_control = 'no-cache, no-store, must-revalidate, max-age=0';
        if (is_object($response) && method_exists($response, 'header')) {
            $response->header('Cache-Control', $cache_control);
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
        }
        return $response;
    }

    /** Arm a JSON-only shutdown response for fatal errors inside app-init. */
    private static function arm_app_init_shutdown($request) {
        $expected_route = '/' . trim(self::NS, '/') . '/app-init';
        if (!$request || !method_exists($request, 'get_route') ||
            (string) $request->get_route() !== $expected_route ||
            strtoupper((string) $request->get_method()) !== 'GET' ||
            self::$app_init_shutdown_armed) {
            return;
        }

        self::$app_init_shutdown_armed = true;
        self::$app_init_auth_verified = false;
        self::$app_init_output_level = function_exists('ob_get_level') ? ob_get_level() : 0;
        if (!headers_sent()) {
            header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0', true);
            header('Pragma: no-cache', true);
            header('Expires: 0', true);
        }
        if (function_exists('register_shutdown_function')) {
            register_shutdown_function(array(__CLASS__, 'handle_app_init_shutdown'));
        }
        if (function_exists('ob_start')) {
            @ob_start();
        }
        // Free this small reserve before JSON encoding if PHP runs out of memory.
        try {
            self::$app_init_memory_reserve = str_repeat('x', 262144);
        } catch (Throwable $exception) {
            self::$app_init_memory_reserve = null;
        }
    }

    /** Convert a fatal app-init shutdown into the normal WordPress REST error shape. */
    public static function handle_app_init_shutdown() {
        if (!self::$app_init_shutdown_armed || !function_exists('error_get_last')) {
            return;
        }
        $last_error = error_get_last();
        if (!is_array($last_error) || !isset($last_error['type']) || !in_array((int) $last_error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR), true)) {
            return;
        }

        self::$app_init_memory_reserve = null;
        $message = self::$app_init_auth_verified && isset($last_error['message'])
            ? (string) $last_error['message']
            : 'Fatal server error while validating the authenticated app request.';

        // Keep PHP's displayed fatal HTML and any nested buffers from corrupting JSON.
        if (function_exists('ob_get_level') && function_exists('ob_clean') && self::$app_init_output_level !== null) {
            while (ob_get_level() > self::$app_init_output_level + 1) {
                if (!@ob_end_flush()) { break; }
            }
            if (ob_get_level() > self::$app_init_output_level) {
                @ob_clean();
            }
        }
        if (!headers_sent()) {
            if (function_exists('status_header')) {
                status_header(500);
            } elseif (function_exists('http_response_code')) {
                http_response_code(500);
            }
            header('Content-Type: application/json; charset=UTF-8', true);
            header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0', true);
            header('Pragma: no-cache', true);
            header('Expires: 0', true);
        }

        $body = array(
            'code'    => 'wsm_app_init_fatal',
            'message' => $message,
            'data'    => array('status' => 500),
        );
        $json = function_exists('wp_json_encode') ? wp_json_encode($body) : json_encode($body);
        if (!is_string($json) || $json === '') {
            $json = '{"code":"wsm_app_init_fatal","message":"Fatal server error in app-init","data":{"status":500}}';
        }
        echo $json;
    }

    /**
     * Authentication for the aggregate bootstrap endpoint. The normal permission
     * callback verifies Bearer, HMAC, device and account state before returning the
     * expected unmapped-route error; only that exact error is accepted here.
     */
    public static function app_init_permission($request) {
        self::arm_app_init_shutdown($request);
        $expected_route = '/' . trim(self::NS, '/') . '/app-init';
        if (!$request || !method_exists($request, 'get_route') ||
            (string) $request->get_route() !== $expected_route ||
            strtoupper((string) $request->get_method()) !== 'GET') {
            return new WP_Error('wsm_permission_unmapped', 'برای این مسیر مجوز دستگاه تعریف نشده است.', array('status' => 403));
        }

        $permission = WSM_Permissions::check_request($request);
        if (WSM_Permissions::current_device()) {
            self::$app_init_auth_verified = true;
        }
        if (is_wp_error($permission) && $permission->get_error_code() === 'wsm_permission_unmapped') {
            return true;
        }
        return $permission;
    }

    /** Cache store-wide aggregates; dashboard callers can use a short, epoch-scoped TTL. */
    private static function cached_stats($cache_key, $callback, $expiration = 900) {
        $expiration = max(1, absint($expiration));
        $cached = wp_cache_get($cache_key, 'wsm');
        if (!is_array($cached)) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                wp_cache_set($cache_key, $cached, 'wsm', $expiration);
            }
        }
        if (is_array($cached)) {
            return $cached;
        }

        $result = call_user_func($callback);
        if (!is_wp_error($result) && is_array($result)) {
            wp_cache_set($cache_key, $result, 'wsm', $expiration);
            set_transient($cache_key, $result, $expiration);
        }
        return $result;
    }

    /**
     * Cache selected authenticated, read-only GET payloads for fifteen minutes.
     * Keys are scoped to site + device + route + complete request params + a write epoch.
     * Delta sync, orders, invoices, finance, and per-user data deliberately do not use this cache.
     */
    private static function cached_rest_get($bucket, $route, $params, $callback, $rate_checked = false) {
        $device_id = WSM_Permissions::current_device_id();
        if ($device_id < 1 || !is_array($params)) {
            return call_user_func($callback);
        }

        $bucket = sanitize_key($bucket);
        if (!in_array($bucket, array('products', 'settings'), true)) {
            return call_user_func($callback);
        }
        if (!$rate_checked) {
            $rate_limit = self::check_rest_read_rate_limit($bucket, $device_id);
            if (is_wp_error($rate_limit)) { return $rate_limit; }
        }
        $epoch_option = 'wsm_rest_' . $bucket . '_cache_epoch_v1';
        $context = array(
            'version' => 2,
            'blog_id' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
            'device_id' => $device_id,
            'route' => (string) $route,
            'params' => $params,
            'epoch' => max(1, absint(get_option($epoch_option, 1))),
        );
        $encoded = wp_json_encode($context);
        if (!is_string($encoded) || $encoded === '') {
            return call_user_func($callback);
        }

        $cache_key = 'wsm_rest_get_' . $bucket . '_v2_' . md5($encoded);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $result = call_user_func($callback);
        if (!is_wp_error($result) && is_array($result)) {
            set_transient($cache_key, $result, 900);
        }
        return $result;
    }

    /**
     * Apply a generous per-device request ceiling to the selected expensive read routes.
     * Use a persistent object cache when configured; otherwise use a short-lived transient.
     * Login/recovery have separate, stricter IP-based protections in WSM_Auth/WSM_Security.
     */
    private static function check_rest_read_rate_limit($bucket, $device_id) {
        $limit = 300;
        $window = 60;
        $now = time();
        $window_start = (int) (floor($now / $window) * $window);
        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        $bucket = sanitize_key($bucket);
        $identity = $blog_id . '|' . (int) $device_id . '|' . $bucket . '|' . $window_start;
        $key = 'wsm_rest_read_rl_v1_' . md5($identity);

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            $added = wp_cache_add($key, 1, 'wsm_rest_rate_limit', $window + 5);
            if ($added) { return true; }
            $count = wp_cache_incr($key, 1, 'wsm_rest_rate_limit');
            if ($count === false) {
                wp_cache_set($key, 1, 'wsm_rest_rate_limit', $window + 5);
                return true;
            }
        } else {
            $count = absint(get_transient($key));
            if ($count >= $limit) {
                $count = $limit + 1;
            } else {
                $count++;
                $remaining = max(1, $window_start + $window + 5 - $now);
                set_transient($key, $count, $remaining);
            }
        }

        if ((int) $count > $limit) {
            $retry_after = max(1, $window_start + $window - $now);
            return new WP_Error(
                'wsm_rest_rate_limited',
                'تعداد درخواست‌های این دستگاه در یک دقیقه از حد مجاز بیشتر شد. کمی بعد دوباره تلاش کنید.',
                array('status' => 429, 'retry_after' => $retry_after)
            );
        }
        return true;
    }

    /** Rotate a read-cache namespace after a relevant write; old transients expire naturally. */
    private static function advance_rest_cache_epoch($bucket) {
        $bucket = sanitize_key($bucket);
        if (!in_array($bucket, array('products', 'settings'), true)) { return; }
        $option = 'wsm_rest_' . $bucket . '_cache_epoch_v1';
        $next = max(1, absint(get_option($option, 1))) + 1;
        if ($next > 2147483640) { $next = 1; }
        update_option($option, $next, false);
    }

    /** Evict product/category/expiry GET snapshots after a WooCommerce product write. */
    public static function invalidate_rest_product_cache($arg1 = null, $arg2 = null, $arg3 = null, $arg4 = null, $arg5 = null, $arg6 = null) {
        self::advance_rest_cache_epoch('products');
        if (class_exists('WSM_Products') && method_exists('WSM_Products', 'invalidate_pos_cache')) {
            WSM_Products::invalidate_pos_cache();
        }
    }

    /** Evict cached settings only when one of the settings they contain changes. */
    public static function invalidate_rest_settings_cache_option($option, $old_value = null, $new_value = null) {
        $option = (string) $option;
        if (in_array($option, array('wsm_settings', 'woocommerce_currency', 'admin_email', 'woocommerce_notify_low_stock_amount'), true)) {
            self::advance_rest_cache_epoch('settings');
        }
        if ($option === 'woocommerce_currency') {
            // Currency symbols are embedded in product, POS, order, and report display payloads.
            self::invalidate_rest_product_cache();
            self::invalidate_stats_cache();
        } elseif ($option === 'woocommerce_notify_low_stock_amount') {
            self::invalidate_stats_cache();
        }
    }

    /** Route every REST-side stats eviction through the shared generation-aware invalidator. */
    public static function invalidate_stats_cache($arg1 = null, $arg2 = null, $arg3 = null, $arg4 = null) {
        if (class_exists('WSM_Reports') && is_callable(array('WSM_Reports', 'flush_sale_caches'))) {
            return WSM_Reports::flush_sale_caches();
        }
        return false;
    }

    /** Daily bounded retention for terminal inbox rows; never delete unprocessed received work. */
    public static function cleanup_sync_logs() {
        global $wpdb;
        $table = $wpdb->prefix . 'wsm_sync_logs';
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        $now = self::sync_now();
        $batch_size = 1000;
        $max_batches = 10;
        $deleted_total = 0;

        for ($batch = 0; $batch < $max_batches; $batch++) {
            $sql = $wpdb->prepare(
                'DELETE FROM ' . $table_sql . " WHERE status IN (%s, %s) AND created_at < DATE_SUB(%s, INTERVAL 30 DAY) ORDER BY id ASC LIMIT %d",
                'processed', 'failed', $now, $batch_size
            );
            $deleted = $wpdb->query($sql);
            if ($deleted === false) {
                error_log('[WSM Sync] Daily retention cleanup could not delete old terminal rows.');
                return $deleted_total;
            }
            $deleted_total += (int) $deleted;
            if ((int) $deleted < $batch_size) {
                break;
            }
        }
        return $deleted_total;
    }

    private static function body($request) {
        $json = $request->get_json_params();
        if (is_array($json)) {
            return $json;
        }
        $body = $request->get_body_params();
        return is_array($body) ? $body : array();
    }

    private static function id($request) {
        $value = $request->get_param('id');
        return is_scalar($value) ? (int) $value : 0;
    }

    private static function scalar_param($request, $key, $default = '') {
        $value = $request->get_param($key);
        return is_scalar($value) ? (string) $value : (string) $default;
    }

    /** Authenticated outbox access; the processor enforces grants for each non-message action. */
    public static function sync_bulk_permission($request) {
        $checked = WSM_Permissions::check_request($request);
        if (is_wp_error($checked) && $checked->get_error_code() !== 'wsm_device_permission_denied') { return $checked; }
        if ($checked === false) { return new WP_Error('wsm_sync_bulk_auth_failed', 'احراز هویت درخواست همگام‌سازی ناموفق بود.', array('status' => 403)); }
        $device = WSM_Permissions::current_device();
        if (!$device) { return new WP_Error('wsm_sync_device_required', 'دستگاه احراز هویت‌شده در دسترس نیست.', array('status' => 401)); }
        $rate_limit = self::check_rest_read_rate_limit('sync', WSM_Permissions::current_device_id());
        if (is_wp_error($rate_limit)) { return $rate_limit; }
        return true;
    }

    /** Messages intentionally bypass the generic unknown-route map only after Bearer/HMAC/account validation. */
    public static function messages_permission($request) {
        $checked = WSM_Permissions::check_request($request);
        if (is_wp_error($checked) && $checked->get_error_code() !== 'wsm_permission_unmapped') { return $checked; }
        if ($checked === false) { return new WP_Error('wsm_message_auth_failed', 'احراز هویت پیام‌رسان ناموفق بود.', array('status' => 403)); }
        $device = WSM_Permissions::current_device();
        if (!$device || empty($device->user_id)) { return new WP_Error('wsm_message_device_required', 'دستگاه احرازشده برای پیام‌رسان در دسترس نیست.', array('status' => 401)); }
        $rate_limit = self::check_rest_read_rate_limit('messages', WSM_Permissions::current_device_id());
        if (is_wp_error($rate_limit)) { return $rate_limit; }
        return true;
    }

    private static function messages_current_user_id() {
        $device = WSM_Permissions::current_device();
        return $device && isset($device->user_id) ? absint($device->user_id) : 0;
    }

    /** Oversight is restricted to the literal WordPress administrator role, not app presets or device grants. */
    private static function messages_is_wordpress_administrator() {
        $user_id = self::messages_current_user_id();
        $user = $user_id ? get_userdata($user_id) : false;
        return $user && in_array('administrator', (array) $user->roles, true);
    }

    private static function messages_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'wsm_messages';
    }

    private static function messages_audit_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'wsm_message_audit';
    }

    /** Add moderation tombstones and an audit table without creating or editing plugin files. */
    private static function ensure_message_moderation_schema() {
        static $schema_status = null;
        if ($schema_status === true) { return true; }
        if (is_wp_error($schema_status)) { return $schema_status; }
        global $wpdb;
        $messages_table = self::messages_table_name();
        $messages_sql = '`' . str_replace('`', '``', $messages_table) . '`';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($messages_table)));
        if ($exists !== $messages_table && class_exists('WSM_Core') && is_callable(array('WSM_Core', 'ensure_messages_table'))) {
            WSM_Core::ensure_messages_table();
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($messages_table)));
        }
        if ($exists !== $messages_table) {
            $schema_status = new WP_Error('wsm_message_schema_unavailable', 'جدول پیام‌های داخلی در دسترس نیست.', array('status' => 500));
            return $schema_status;
        }
        if (get_option('wsm_message_moderation_schema_version') === '1') {
            $deleted_at_column = $wpdb->get_var("SHOW COLUMNS FROM {$messages_sql} LIKE 'deleted_at'");
            $deleted_by_column = $wpdb->get_var("SHOW COLUMNS FROM {$messages_sql} LIKE 'deleted_by'");
            $audit_table = self::messages_audit_table_name();
            $audit_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($audit_table)));
            if ($deleted_at_column === 'deleted_at' && $deleted_by_column === 'deleted_by' && $audit_exists === $audit_table) {
                $schema_status = true;
                return true;
            }
        }
        foreach (array(
            'deleted_at' => "ALTER TABLE {$messages_sql} ADD COLUMN deleted_at datetime NULL DEFAULT NULL",
            'deleted_by' => "ALTER TABLE {$messages_sql} ADD COLUMN deleted_by bigint(20) unsigned NULL DEFAULT NULL",
        ) as $column_name => $alter_sql) {
            $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$messages_sql} LIKE %s", $column_name));
            if ($column !== $column_name && $wpdb->query($alter_sql) === false) {
                // A concurrent request may have added the same column after our initial check.
                $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$messages_sql} LIKE %s", $column_name));
                if ($column !== $column_name) {
                    $schema_status = new WP_Error('wsm_message_moderation_schema_failed', 'ساختار حذف امن پیام‌ها آماده نشد.', array('status' => 500));
                    return $schema_status;
                }
            }
        }
        if (!function_exists('dbDelta') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if (!function_exists('dbDelta')) {
            $schema_status = new WP_Error('wsm_message_audit_schema_failed', 'ساختار گزارش ممیزی پیام‌ها آماده نشد.', array('status' => 500));
            return $schema_status;
        }
        $audit_table = self::messages_audit_table_name();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$audit_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            message_uuid varchar(36) NOT NULL DEFAULT '',
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(20) NOT NULL,
            item_count int(10) unsigned NOT NULL DEFAULT 0,
            acted_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY message_uuid (message_uuid),
            KEY actor_action_time (actor_user_id, action, acted_at)
        ) ENGINE=InnoDB {$charset};");
        $audit_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($audit_table)));
        $deleted_at_column = $wpdb->get_var("SHOW COLUMNS FROM {$messages_sql} LIKE 'deleted_at'");
        $deleted_by_column = $wpdb->get_var("SHOW COLUMNS FROM {$messages_sql} LIKE 'deleted_by'");
        if ($audit_exists !== $audit_table || $deleted_at_column !== 'deleted_at' || $deleted_by_column !== 'deleted_by') {
            $schema_status = new WP_Error('wsm_message_audit_schema_failed', 'ساختار حذف و گزارش ممیزی پیام‌ها کامل نشد.', array('status' => 500));
            return $schema_status;
        }
        update_option('wsm_message_moderation_schema_version', '1', false);
        $schema_status = true;
        return true;
    }

    private static function record_message_audit($action, $message_uuid = '', $item_count = 0) {
        global $wpdb;
        $user_id = self::messages_current_user_id();
        if (!$user_id) { return false; }
        return $wpdb->insert(self::messages_audit_table_name(), array(
            'message_uuid' => (string) $message_uuid,
            'actor_user_id' => $user_id,
            'action' => sanitize_key((string) $action),
            'item_count' => max(0, absint($item_count)),
            'acted_at' => current_time('mysql'),
        ), array('%s', '%d', '%s', '%d', '%s')) === 1;
    }

    private static function messages_deleted_uuids() {
        global $wpdb;
        $table_sql = '`' . str_replace('`', '``', self::messages_table_name()) . '`';
        $rows = $wpdb->get_col("SELECT uuid FROM {$table_sql} WHERE deleted_at IS NOT NULL");
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_message_tombstone_lookup_failed', 'دریافت وضعیت پیام‌های حذف‌شده انجام نشد.', array('status' => 500));
        }
        return array_values(array_filter(array_map('strval', (array) $rows)));
    }

    private static function messages_unread_count($user_id) {
        global $wpdb;
        $table_sql = '`' . str_replace('`', '``', self::messages_table_name()) . '`';
        $direct = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_sql} WHERE deleted_at IS NULL AND receiver_id = %d AND sender_id <> %d AND is_read = 0",
            (int) $user_id, (int) $user_id
        ));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_message_unread_count_failed', 'شمارش پیام‌های خوانده‌نشده انجام نشد.', array('status' => 500));
        }
        $groups = $wpdb->get_results("SELECT sender_id, read_by FROM {$table_sql} WHERE deleted_at IS NULL AND receiver_id = 0", ARRAY_A);
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_message_unread_count_failed', 'شمارش پیام‌های گروهی خوانده‌نشده انجام نشد.', array('status' => 500));
        }
        $group_unread = 0;
        foreach ((array) $groups as $row) {
            if (absint($row['sender_id']) === (int) $user_id) { continue; }
            $read_by = json_decode(isset($row['read_by']) ? (string) $row['read_by'] : '', true);
            $read_by = is_array($read_by) ? array_map('absint', $read_by) : array();
            if (!in_array((int) $user_id, $read_by, true)) { $group_unread++; }
        }
        return $direct + $group_unread;
    }

    private static function messages_admin_inbox_page($limit, $before_id) {
        global $wpdb;
        // This also ensures the existing thread columns are upgraded before the admin query.
        $ready = WSM_Club_Operations::list_internal_messages(1);
        if (is_wp_error($ready)) { return $ready; }
        $table_sql = '`' . str_replace('`', '``', self::messages_table_name()) . '`';
        $columns = 'id, uuid, thread_uuid, reply_to_uuid, sender_id, receiver_id, subject, message, created_at';
        if ((int) $before_id > 0) {
            $sql = $wpdb->prepare("SELECT {$columns} FROM {$table_sql} WHERE deleted_at IS NULL AND id < %d ORDER BY id DESC LIMIT %d", (int) $before_id, (int) $limit + 1);
        } else {
            $sql = $wpdb->prepare("SELECT {$columns} FROM {$table_sql} WHERE deleted_at IS NULL ORDER BY id DESC LIMIT %d", (int) $limit + 1);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_message_admin_read_failed', 'دریافت صفحهٔ نظارت پیام‌ها انجام نشد.', array('status' => 500));
        }
        $has_more = count((array) $rows) > (int) $limit;
        if ($has_more) { array_pop($rows); }
        $user_ids = array();
        foreach ((array) $rows as $row) {
            $user_ids[] = absint($row['sender_id']);
            if (absint($row['receiver_id']) > 0) { $user_ids[] = absint($row['receiver_id']); }
        }
        $names = array();
        foreach (array_unique($user_ids) as $id) {
            $user = get_userdata((int) $id);
            $names[(int) $id] = $user ? (string) $user->display_name : 'کاربر ' . (int) $id;
        }
        $items = array();
        foreach ((array) $rows as $row) {
            $sender_id = absint($row['sender_id']);
            $receiver_id = absint($row['receiver_id']);
            $uuid = strtolower((string) $row['uuid']);
            $thread_uuid = isset($row['thread_uuid']) && preg_match('/^[a-f0-9-]{36}$/i', (string) $row['thread_uuid']) ? strtolower((string) $row['thread_uuid']) : $uuid;
            $reply_uuid = isset($row['reply_to_uuid']) && preg_match('/^[a-f0-9-]{36}$/i', (string) $row['reply_to_uuid']) ? strtolower((string) $row['reply_to_uuid']) : '';
            $items[] = array(
                'id' => absint($row['id']), 'uuid' => $uuid, 'thread_uuid' => $thread_uuid, 'reply_to_uuid' => $reply_uuid,
                'sender_id' => $sender_id, 'receiver_id' => $receiver_id, 'subject' => (string) $row['subject'],
                'message' => (string) $row['message'], 'is_read' => false, 'created_at' => (string) $row['created_at'],
                'sender_name' => isset($names[$sender_id]) ? $names[$sender_id] : 'کاربر ' . $sender_id,
                'receiver_name' => $receiver_id === 0 ? 'همهٔ کارمندان' : (isset($names[$receiver_id]) ? $names[$receiver_id] : 'کاربر ' . $receiver_id),
            );
        }
        if (!self::record_message_audit('view_all', '', count($items))) {
            return new WP_Error('wsm_message_audit_write_failed', 'ثبت دسترسی مدیر به پیام‌ها در گزارش ممیزی انجام نشد.', array('status' => 500));
        }
        $user_id = self::messages_current_user_id();
        $unread = self::messages_unread_count($user_id);
        if (is_wp_error($unread)) { return $unread; }
        return array(
            'items' => $items, 'unread_count' => $unread, 'is_wordpress_admin' => true,
            'view_mode' => 'admin', 'has_more' => $has_more,
            'next_before_id' => $has_more && $items ? (int) end($items)['id'] : 0,
        );
    }

    public static function messages_send($request) {
        $payload = self::body($request);
        $reply_uuid = isset($payload['reply_to_uuid']) && is_scalar($payload['reply_to_uuid']) ? strtolower(trim((string) $payload['reply_to_uuid'])) : '';
        if ($reply_uuid !== '' && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $reply_uuid)) {
            $schema = self::ensure_message_moderation_schema();
            if (is_wp_error($schema)) { return $schema; }
            global $wpdb;
            $table_sql = '`' . str_replace('`', '``', self::messages_table_name()) . '`';
            $deleted = $wpdb->get_var($wpdb->prepare("SELECT deleted_at FROM {$table_sql} WHERE uuid = %s LIMIT 1", $reply_uuid));
            if (!empty($wpdb->last_error)) { return new WP_Error('wsm_message_reply_lookup_failed', 'بررسی پیام مورد پاسخ انجام نشد.', array('status' => 500)); }
            if ($deleted !== null) { return new WP_Error('wsm_message_reply_target_deleted', 'پاسخ به پیام حذف‌شده ممکن نیست.', array('status' => 404)); }
        }
        return WSM_Club_Operations::create_internal_message($payload);
    }

    public static function messages_inbox($request) {
        $view_mode = strtolower(trim(self::scalar_param($request, 'view_mode', 'inbox')));
        if (!in_array($view_mode, array('', 'inbox', 'admin'), true)) {
            return new WP_Error('wsm_message_view_mode_invalid', 'حالت نمایش پیام‌ها معتبر نیست.', array('status' => 400));
        }
        $is_admin = self::messages_is_wordpress_administrator();
        if ($view_mode === 'admin' && (!$is_admin || !current_user_can('manage_options'))) {
            return new WP_Error('wsm_message_admin_view_forbidden', 'نمای نظارت پیام‌ها فقط برای مدیر WordPress دارای manage_options در دسترس است.', array('status' => 403));
        }
        $schema = self::ensure_message_moderation_schema();
        if (is_wp_error($schema)) { return $schema; }
        $limit = $request->get_param('limit');
        $limit = is_scalar($limit) ? max(1, min(200, absint($limit) ?: 100)) : 100;
        if ($view_mode === 'admin') {
            $before_raw = $request->get_param('before_id');
            if ($before_raw !== null && (!is_scalar($before_raw) || !preg_match('/^[0-9]+$/', (string) $before_raw))) {
                return new WP_Error('wsm_message_cursor_invalid', 'نشانگر صفحهٔ پیام‌ها معتبر نیست.', array('status' => 400));
            }
            $page = self::messages_admin_inbox_page($limit, $before_raw === null ? 0 : absint($before_raw));
            if (is_wp_error($page)) { return $page; }
            $deleted = self::messages_deleted_uuids();
            if (is_wp_error($deleted)) { return $deleted; }
            $page['deleted_uuids'] = $deleted;
            return $page;
        }
        $result = WSM_Club_Operations::list_internal_messages($limit);
        if (is_wp_error($result)) { return $result; }
        $user_id = self::messages_current_user_id();
        $deleted = self::messages_deleted_uuids();
        if (is_wp_error($deleted)) { return $deleted; }
        $deleted_map = array_fill_keys($deleted, true);
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : array();
        $result['items'] = array_values(array_filter($items, function ($item) use ($user_id, $deleted_map) {
            if (!is_array($item) || empty($item['uuid']) || isset($deleted_map[(string) $item['uuid']])) { return false; }
            $sender_id = isset($item['sender_id']) ? absint($item['sender_id']) : 0;
            $receiver_id = isset($item['receiver_id']) ? absint($item['receiver_id']) : -1;
            return $sender_id === (int) $user_id || $receiver_id === (int) $user_id || $receiver_id === 0;
        }));
        $unread = self::messages_unread_count($user_id);
        if (is_wp_error($unread)) { return $unread; }
        $result['unread_count'] = $unread;
        $result['deleted_uuids'] = $deleted;
        $result['is_wordpress_admin'] = $is_admin;
        $result['view_mode'] = 'inbox';
        $result['has_more'] = false;
        return $result;
    }

    public static function messages_recipients($request) {
        $device = WSM_Permissions::current_device();
        return WSM_Club_Operations::list_internal_message_recipients($device && isset($device->user_id) ? (int) $device->user_id : 0);
    }

    public static function messages_read($request) {
        if (self::messages_is_wordpress_administrator()) {
            return array('updated' => 0, 'uuids' => array(), 'read_receipts_suppressed' => true);
        }
        $body = self::body($request);
        $uuids = isset($body['uuids']) ? $body['uuids'] : array();
        if (!is_array($uuids) || count($uuids) > 200) {
            return WSM_Club_Operations::mark_internal_messages_read($uuids);
        }
        $schema = self::ensure_message_moderation_schema();
        if (is_wp_error($schema)) { return $schema; }
        $clean = array();
        foreach ($uuids as $uuid) {
            if (is_scalar($uuid)) {
                $uuid = strtolower(trim((string) $uuid));
                if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid)) { $clean[$uuid] = $uuid; }
            }
        }
        if (!$clean) { return array('updated' => 0, 'uuids' => array()); }
        global $wpdb;
        $table_sql = '`' . str_replace('`', '``', self::messages_table_name()) . '`';
        $placeholders = implode(',', array_fill(0, count($clean), '%s'));
        $sql = $wpdb->prepare("SELECT uuid FROM {$table_sql} WHERE deleted_at IS NULL AND uuid IN ({$placeholders})", array_values($clean));
        $active = $wpdb->get_col($sql);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_message_read_lookup_failed', 'بررسی وضعیت پیام‌های فعال انجام نشد.', array('status' => 500)); }
        return WSM_Club_Operations::mark_internal_messages_read(array_values(array_map('strval', (array) $active)));
    }

    public static function messages_delete($request) {
        if (!self::messages_is_wordpress_administrator()) {
            return new WP_Error('wsm_message_delete_forbidden', 'حذف پیام فقط برای نقش administrator مجاز است.', array('status' => 403));
        }
        $body = self::body($request);
        $uuid = isset($body['uuid']) && is_scalar($body['uuid']) ? strtolower(trim((string) $body['uuid'])) : '';
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid)) {
            return new WP_Error('wsm_message_uuid_invalid', 'شناسهٔ پیام معتبر نیست.', array('status' => 400));
        }
        $schema = self::ensure_message_moderation_schema();
        if (is_wp_error($schema)) { return $schema; }
        global $wpdb;
        $messages_table = self::messages_table_name();
        $audit_table = self::messages_audit_table_name();
        $messages_sql = '`' . str_replace('`', '``', $messages_table) . '`';
        $row = $wpdb->get_row($wpdb->prepare("SELECT uuid, deleted_at FROM {$messages_sql} WHERE uuid = %s LIMIT 1", $uuid), ARRAY_A);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_message_delete_lookup_failed', 'بررسی پیام برای حذف انجام نشد.', array('status' => 500)); }
        if (!is_array($row)) { return new WP_Error('wsm_message_not_found', 'پیام پیدا نشد.', array('status' => 404)); }
        if (!empty($row['deleted_at'])) { return array('deleted' => true, 'already_deleted' => true, 'uuid' => $uuid); }
        foreach (array($messages_table, $audit_table) as $table_name) {
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table_name), ARRAY_A);
            if (!empty($wpdb->last_error) || !is_array($status) || strtolower((string) $status['Engine']) !== 'innodb') {
                return new WP_Error('wsm_message_delete_engine_required', 'حذف با گزارش ممیزی فقط روی جدول‌های تراکنشی InnoDB انجام می‌شود.', array('status' => 409));
            }
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('wsm_message_delete_transaction_failed', 'شروع تراکنش حذف پیام انجام نشد.', array('status' => 500));
        }
        $deleted_at = current_time('mysql');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$messages_sql} SET deleted_at = %s, deleted_by = %d WHERE uuid = %s AND deleted_at IS NULL",
            $deleted_at, self::messages_current_user_id(), $uuid
        ));
        if ($updated !== 1 || !self::record_message_audit('delete', $uuid, 1)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_message_delete_failed', 'حذف امن پیام یا ثبت گزارش ممیزی انجام نشد.', array('status' => 500));
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_message_delete_commit_failed', 'ثبت نهایی حذف پیام انجام نشد.', array('status' => 500));
        }
        return array('deleted' => true, 'soft_deleted' => true, 'uuid' => $uuid, 'deleted_at' => $deleted_at);
    }

    public static function messages_audit_list($request) {
        if (!self::messages_is_wordpress_administrator() || !current_user_can('manage_options')) {
            return new WP_Error('wsm_message_audit_forbidden', 'گزارش ممیزی پیام‌ها فقط برای مدیر WordPress دارای manage_options مجاز است.', array('status' => 403));
        }
        $schema = self::ensure_message_moderation_schema();
        if (is_wp_error($schema)) { return $schema; }
        global $wpdb;
        $limit = max(1, min(100, absint($request->get_param('limit')) ?: 50));
        $table_sql = '`' . str_replace('`', '``', self::messages_audit_table_name()) . '`';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, message_uuid, actor_user_id, action, item_count, acted_at FROM {$table_sql} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_message_audit_read_failed', 'خواندن گزارش ممیزی پیام‌ها انجام نشد.', array('status' => 500)); }
        $user_ids = array_values(array_unique(array_map('absint', array_column((array) $rows, 'actor_user_id'))));
        $names = array();
        foreach ($user_ids as $id) { $user = get_userdata($id); $names[$id] = $user ? (string) $user->display_name : 'کاربر ' . $id; }
        $items = array();
        foreach ((array) $rows as $row) {
            $actor_id = absint($row['actor_user_id']);
            $items[] = array(
                'id' => absint($row['id']), 'message_uuid' => (string) $row['message_uuid'],
                'actor_user_id' => $actor_id, 'actor_name' => isset($names[$actor_id]) ? $names[$actor_id] : 'کاربر ' . $actor_id,
                'action' => (string) $row['action'], 'item_count' => absint($row['item_count']), 'acted_at' => (string) $row['acted_at'],
            );
        }
        return array('items' => $items, 'limit' => $limit);
    }

    /**
     * Pull route reuses the existing Bearer/HMAC/account checks. The generic
     * permission map deliberately fails closed for unknown routes, so authorize
     * this route's data scopes here after check_request has authenticated it.
     */
    public static function sync_pull_permission($request) {
        $checked = WSM_Permissions::check_request($request);
        if (is_wp_error($checked) && !in_array($checked->get_error_code(), array('wsm_permission_unmapped', 'wsm_device_permission_denied'), true)) {
            return $checked;
        }
        if ($checked === false) {
            return new WP_Error('wsm_sync_pull_auth_failed', 'احراز هویت درخواست همگام‌سازی ناموفق بود.', array('status' => 403));
        }
        $device = WSM_Permissions::current_device();
        if (!$device) {
            return new WP_Error('wsm_sync_pull_device_required', 'دستگاه احرازشده در دسترس نیست.', array('status' => 401));
        }
        $rate_limit = self::check_rest_read_rate_limit('sync', WSM_Permissions::current_device_id());
        if (is_wp_error($rate_limit)) { return $rate_limit; }
        foreach (array('products.view', 'club.pos.create', 'club.members.view', 'settings.view', 'security.manage') as $permission) {
            if (WSM_Devices::device_can($device, $permission)) { return true; }
        }
        return new WP_Error('wsm_sync_pull_forbidden', 'این دستگاه مجوز دریافت داده‌های همگام‌سازی را ندارد.', array('status' => 403));
    }

    /** Parse the client watermark (Unix seconds; milliseconds/ISO are accepted defensively). */
    private static function sync_pull_parse_last_sync($value) {
        if ($value === null || $value === '') { return 0; }
        if (!is_scalar($value)) {
            return new WP_Error('wsm_sync_pull_last_sync_invalid', 'پارامتر last_sync معتبر نیست.', array('status' => 400));
        }
        $value = trim((string) $value);
        if ($value === '' || $value === '0') { return 0; }
        if (is_numeric($value)) {
            $number = (float) $value;
            if (!is_finite($number) || $number < 0) {
                return new WP_Error('wsm_sync_pull_last_sync_invalid', 'پارامتر last_sync باید timestamp معتبر باشد.', array('status' => 400));
            }
            if ($number > 9999999999) { $number = floor($number / 1000); }
            return (int) floor($number);
        }
        $parsed = strtotime($value);
        if ($parsed === false) {
            return new WP_Error('wsm_sync_pull_last_sync_invalid', 'پارامتر last_sync باید timestamp یا تاریخ معتبر باشد.', array('status' => 400));
        }
        return max(0, (int) $parsed);
    }

    /** GET /sync/pull — only return data visible to the authenticated device. */
    public static function sync_pull($request) {
        global $wpdb;
        if (!WSM_Util::is_woo_active()) { return WSM_Util::woo_required_error(); }
        $parsed_last_sync = self::sync_pull_parse_last_sync($request->get_param('last_sync'));
        if (is_wp_error($parsed_last_sync)) { return $parsed_last_sync; }

        $server_now = time();
        // Keep a one-second overlap for second-precision MySQL timestamps and clock skew.
        $sync_time = max(0, $server_now - 1);
        $cutoff = max(0, min((int) $parsed_last_sync, $sync_time) - 1);
        $cutoff_gmt = gmdate('Y-m-d H:i:s', $cutoff);
        $end_gmt = gmdate('Y-m-d H:i:s', $server_now);
        $cutoff_local = get_date_from_gmt($cutoff_gmt, 'Y-m-d H:i:s');
        $end_local = get_date_from_gmt($end_gmt, 'Y-m-d H:i:s');

        $device = WSM_Permissions::current_device();
        $can_products = $device && (WSM_Devices::device_can($device, 'products.view') || WSM_Devices::device_can($device, 'club.pos.create'));
        $can_members = $device && WSM_Devices::device_can($device, 'club.members.view');
        $can_settings = $device && (WSM_Devices::device_can($device, 'settings.view') || WSM_Devices::device_can($device, 'security.manage'));
        $products = array();
        $members = array();
        $deleted_by_key = array();

        if ($can_products) {
            $product_sql = $wpdb->prepare(
                "SELECT ID, post_modified_gmt FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status NOT IN (%s, %s, %s)
                   AND post_modified_gmt > %s AND post_modified_gmt <= %s
                 ORDER BY post_modified_gmt ASC, ID ASC",
                'product', 'trash', 'auto-draft', 'inherit', $cutoff_gmt, $end_gmt
            );
            $product_rows = $wpdb->get_results($product_sql);
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_sync_pull_products_failed', 'دریافت تغییرات کالاها انجام نشد.', array('status' => 500));
            }
            foreach ((array) $product_rows as $row) {
                $product = wc_get_product((int) $row->ID);
                if (!$product) { continue; }
                $formatted = WSM_Products::format($product);
                $formatted['updated_at'] = (string) $row->post_modified_gmt;
                $formatted['post_modified_gmt'] = (string) $row->post_modified_gmt;
                $products[] = $formatted;
            }

            // Keep tombstones in a non-autoloaded option so every device can pull them,
            // including devices that were offline when a product was permanently deleted.
            $tombstones = get_option('wsm_sync_deleted_products', array());
            if (is_array($tombstones)) {
                foreach ($tombstones as $raw_id => $deleted_at) {
                    $product_id = absint($raw_id);
                    $deleted_at = (int) $deleted_at;
                    if ($product_id < 1 || $deleted_at <= $cutoff || $deleted_at > $server_now) { continue; }
                    $existing_post = get_post($product_id);
                    if ($existing_post && $existing_post->post_type === 'product' && $existing_post->post_status !== 'trash') {
                        // A restored/published product must not be deleted by a stale tombstone.
                        continue;
                    }
                    $deleted_by_key['products:' . $product_id] = array('type' => 'products', 'id' => $product_id);
                }
            }
            // Trash is a soft delete in this plugin; include recent trashed products too.
            $trash_sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status = %s
                   AND post_modified_gmt > %s AND post_modified_gmt <= %s
                 ORDER BY post_modified_gmt ASC, ID ASC",
                'product', 'trash', $cutoff_gmt, $end_gmt
            );
            $trashed_ids = $wpdb->get_col($trash_sql);
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_sync_pull_deletions_failed', 'دریافت فهرست کالاهای حذف‌شده انجام نشد.', array('status' => 500));
            }
            foreach ((array) $trashed_ids as $trashed_id) {
                $trashed_id = absint($trashed_id);
                if ($trashed_id > 0) { $deleted_by_key['products:' . $trashed_id] = array('type' => 'products', 'id' => $trashed_id); }
            }
        }

        if ($can_members) {
            $members_table = WSM_Club::table('members');
            $member_sql = $wpdb->prepare(
                "SELECT id, updated_at FROM {$members_table}
                 WHERE updated_at > %s AND updated_at <= %s
                 ORDER BY updated_at ASC, id ASC",
                $cutoff_local, $end_local
            );
            $member_rows = $wpdb->get_results($member_sql);
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_sync_pull_members_failed', 'دریافت تغییرات اعضا انجام نشد.', array('status' => 500));
            }
            $member_ids = array();
            foreach ((array) $member_rows as $row) { $member_ids[] = (int) $row->id; }
            $offline_uuids_by_member = array();
            if ($member_ids) {
                $map_table = $wpdb->prefix . 'wsm_sync_member_map';
                $found_map_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($map_table)));
                if (!empty($wpdb->last_error) || $found_map_table !== $map_table) {
                    return new WP_Error('wsm_sync_pull_member_map_unavailable', 'پیوند اعضای آفلاین برای همگام‌سازی در دسترس نیست.', array('status' => 500));
                }
                $map_table_sql = '`' . str_replace('`', '``', $map_table) . '`';
                $placeholders = implode(',', array_fill(0, count($member_ids), '%d'));
                $map_sql = $wpdb->prepare("SELECT member_id, offline_uuid FROM {$map_table_sql} WHERE member_id IN ({$placeholders})", $member_ids);
                $map_rows = $wpdb->get_results($map_sql);
                if (!empty($wpdb->last_error)) {
                    return new WP_Error('wsm_sync_pull_member_map_unavailable', 'خواندن پیوندهای اعضای آفلاین انجام نشد.', array('status' => 500));
                }
                foreach ((array) $map_rows as $map_row) {
                    $offline_uuids_by_member[(int) $map_row->member_id][] = (string) $map_row->offline_uuid;
                }
            }
            foreach ((array) $member_rows as $row) {
                $member = WSM_Club::get_member((int) $row->id);
                if (is_wp_error($member)) { return $member; }
                if (!is_array($member)) {
                    return new WP_Error('wsm_sync_pull_member_format_invalid', 'قالب عضو تغییرکرده معتبر نیست.', array('status' => 500));
                }
                $member['updated_at'] = (string) $row->updated_at;
                $member['offline_uuids'] = isset($offline_uuids_by_member[(int) $row->id])
                    ? array_values(array_unique($offline_uuids_by_member[(int) $row->id]))
                    : array();
                $members[] = $member;
            }
        }

        $settings_updated_at = max(0, (int) get_option('wsm_sync_settings_updated_at', 0));
        $settings = null;
        if ($can_settings && ((int) $parsed_last_sync === 0 || ($settings_updated_at > $cutoff && $settings_updated_at <= $server_now))) {
            $settings = self::settings();
        }

        return array(
            'products' => $products,
            'members' => $members,
            'deleted_ids' => array_values($deleted_by_key),
            'settings' => $settings,
            'settings_updated_at' => $settings_updated_at,
            'sync_time' => $sync_time,
        );
    }

    /** Record global settings changes without adding a database table or schema. */
    public static function sync_pull_track_settings_change($option, $old_value = null, $new_value = null) {
        if (!in_array((string) $option, array('wsm_settings', 'woocommerce_currency'), true)) { return; }
        update_option('wsm_sync_settings_updated_at', time(), false);
    }

    /** Persist product tombstones before a hard delete; trashed products are also queried directly. */
    public static function sync_pull_track_deleted_product($post_id, $post = null) {
        $post_id = absint($post_id);
        if ($post_id < 1) { return; }
        if (!is_object($post) || !isset($post->post_type)) { $post = get_post($post_id); }
        if (!$post || !isset($post->post_type) || $post->post_type !== 'product') { return; }
        self::invalidate_rest_product_cache();
        $tombstones = get_option('wsm_sync_deleted_products', array());
        if (!is_array($tombstones)) { $tombstones = array(); }
        $tombstones[(string) $post_id] = time();
        update_option('wsm_sync_deleted_products', $tombstones, false);
    }

    /** Remove a stale tombstone if a product is restored from the trash. */
    public static function sync_pull_clear_product_tombstone($post_id, $previous_status = null) {
        $post_id = absint($post_id);
        if ($post_id < 1) { return; }
        $post = get_post($post_id);
        if (!$post || !isset($post->post_type) || $post->post_type !== 'product') { return; }
        self::invalidate_rest_product_cache();
        $tombstones = get_option('wsm_sync_deleted_products', array());
        if (!is_array($tombstones) || !isset($tombstones[(string) $post_id])) { return; }
        unset($tombstones[(string) $post_id]);
        update_option('wsm_sync_deleted_products', $tombstones, false);
    }

    /** Register option/post hooks on all requests, including dashboard deletions and settings edits. */
    public static function register_sync_pull_watchers() {
        add_action('updated_option', array(__CLASS__, 'sync_pull_track_settings_change'), 10, 3);
        add_action('added_option', array(__CLASS__, 'sync_pull_track_settings_change'), 10, 2);
        add_action('updated_option', array(__CLASS__, 'invalidate_rest_settings_cache_option'), 5, 3);
        add_action('added_option', array(__CLASS__, 'invalidate_rest_settings_cache_option'), 5, 2);
        add_action('deleted_option', array(__CLASS__, 'invalidate_rest_settings_cache_option'), 5, 1);
        add_action('woocommerce_new_product', array(__CLASS__, 'invalidate_rest_product_cache'), 10, 2);
        add_action('woocommerce_update_product', array(__CLASS__, 'invalidate_rest_product_cache'), 10, 2);
        add_action('woocommerce_new_product_variation', array(__CLASS__, 'invalidate_rest_product_cache'), 10, 2);
        add_action('woocommerce_update_product_variation', array(__CLASS__, 'invalidate_rest_product_cache'), 10, 2);
        add_action('woocommerce_product_set_stock', array(__CLASS__, 'invalidate_rest_product_cache'), 10, 1);
        add_action('woocommerce_variation_set_stock', array(__CLASS__, 'invalidate_rest_product_cache'), 10, 1);
        add_action('set_object_terms', array(__CLASS__, 'invalidate_rest_product_cache_terms'), 10, 6);
        add_action('created_product_cat', array(__CLASS__, 'invalidate_rest_product_cache'), 10, 1);
        add_action('edited_product_cat', array(__CLASS__, 'invalidate_rest_product_cache'), 10, 1);
        add_action('delete_product_cat', array(__CLASS__, 'invalidate_rest_product_cache'), 10, 1);
        add_action('before_delete_post', array(__CLASS__, 'sync_pull_track_deleted_product'), 10, 2);
        add_action('trashed_post', array(__CLASS__, 'sync_pull_track_deleted_product'), 10, 2);
        add_action('untrashed_post', array(__CLASS__, 'sync_pull_clear_product_tombstone'), 10, 2);
    }

    /** Invalidate category caches only for product/category assignments. */
    public static function invalidate_rest_product_cache_terms($object_id, $terms = null, $tt_ids = null, $taxonomy = '', $append = false, $old_tt_ids = null) {
        if ((string) $taxonomy !== 'product_cat') { return; }
        $post_type = get_post_type(absint($object_id));
        if (in_array($post_type, array('product', 'product_variation'), true)) {
            self::invalidate_rest_product_cache();
        }
    }

    /**
     * Receive an offline queue batch, persist it, then replay received rows safely.
     * The route's permission_callback performs the existing Bearer/HMAC checks.
     */
    public static function sync_bulk($request) {
        /*
         * This is a WordPress REST callback: return the REST payload and let WP_REST_Server
         * serialize/terminate the response. Do not emit a second response or exit here.
         * Capture and discard accidental output so notices cannot corrupt the JSON response.
         */
        $initial_buffer_level = ob_get_level();
        ob_start();
        try {
            try {
                $result = self::sync_bulk_process($request);
            } catch (Throwable $exception) {
                // A fatal/exception here used to escape the REST callback and make WordPress
                // print an HTML error page, which the app reported as "wsm_sync_invalid_response".
                error_log('[WSM Sync] Unhandled exception while processing a sync batch: ' . $exception->getMessage());
                $result = new WP_Error(
                    'wsm_sync_internal_error',
                    'سرور هنگام پردازش همگام‌سازی خطای داخلی داد: ' . $exception->getMessage(),
                    array('status' => 500)
                );
            }
            if (ob_get_level() > $initial_buffer_level) { ob_clean(); }
            return $result;
        } finally {
            while (ob_get_level() > $initial_buffer_level) { @ob_end_clean(); }
        }
    }

    private static function sync_bulk_process($request) {
        global $wpdb;

        $raw_body = method_exists($request, 'get_body') ? $request->get_body() : '';
        if (!is_string($raw_body) || trim($raw_body) === '') {
            return new WP_Error('wsm_sync_bulk_invalid_json', 'بدنهٔ درخواست باید JSON معتبر باشد.', array('status' => 400));
        }

        $decoded_body = json_decode($raw_body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('wsm_sync_bulk_invalid_json', 'بدنهٔ درخواست باید JSON معتبر باشد.', array('status' => 400));
        }

        if (is_array($decoded_body)) {
            // Also accept a top-level JSON array for simple sync clients.
            $items = $decoded_body;
        } elseif (is_object($decoded_body) && isset($decoded_body->items) && is_array($decoded_body->items)) {
            $items = $decoded_body->items;
        } else {
            return new WP_Error('wsm_sync_bulk_invalid_items', 'فهرست items در درخواست معتبر نیست.', array('status' => 400));
        }

        $total_received = count($items);
        if ($total_received > 50) {
            return new WP_Error('wsm_sync_bulk_limit_exceeded', 'حداکثر ۵۰ آیتم در هر بسته پذیرفته می‌شود.', array('status' => 400));
        }

        $device = WSM_Permissions::current_device();
        if (!$device) {
            return new WP_Error('wsm_sync_device_required', 'دستگاه احراز هویت‌شده در دسترس نیست.', array('status' => 401));
        }

        // Validate the entire batch and all item-level grants before writing any row.
        $validated_items = array();
        foreach ($items as $index => $item) {
            $item_number = (int) $index + 1;
            if (!is_object($item)) {
                return new WP_Error('wsm_sync_bulk_item_invalid', 'ساختار یکی از آیتم‌های همگام‌سازی معتبر نیست (ردیف ' . $item_number . ').', array('status' => 400));
            }

            if (!isset($item->queue_id) || !is_string($item->queue_id)) {
                return new WP_Error('wsm_sync_bulk_queue_id_invalid', 'queue_id یکی از آیتم‌ها معتبر نیست (ردیف ' . $item_number . ').', array('status' => 400));
            }
            $queue_id = strtolower(trim($item->queue_id));
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $queue_id)) {
                return new WP_Error('wsm_sync_bulk_queue_id_invalid', 'queue_id یکی از آیتم‌ها باید UUID معتبر باشد (ردیف ' . $item_number . ').', array('status' => 400));
            }

            if (!isset($item->action_type) || !is_string($item->action_type) || !in_array($item->action_type, array('CREATE_SALE', 'CREATE_MEMBER', 'CREATE_MEMBERSHIP', 'CREATE_MESSAGE'), true)) {
                return new WP_Error('wsm_sync_bulk_action_invalid', 'action_type یکی از آیتم‌ها پشتیبانی نمی‌شود (ردیف ' . $item_number . ').', array('status' => 400));
            }

            if (!isset($item->payload) || !is_string($item->payload) || trim($item->payload) === '') {
                return new WP_Error('wsm_sync_bulk_payload_invalid', 'payload یکی از آیتم‌ها باید یک شیء JSON معتبر باشد (ردیف ' . $item_number . ').', array('status' => 400));
            }
            $payload_object = json_decode($item->payload);
            if (json_last_error() !== JSON_ERROR_NONE || !is_object($payload_object)) {
                return new WP_Error('wsm_sync_bulk_payload_invalid', 'payload یکی از آیتم‌ها باید یک شیء JSON معتبر باشد (ردیف ' . $item_number . ').', array('status' => 400));
            }

            $required_permission = $item->action_type === 'CREATE_SALE' ? 'club.pos.create' : (in_array($item->action_type, array('CREATE_MEMBER', 'CREATE_MEMBERSHIP'), true) ? 'club.members.manage' : '');
            if ($required_permission !== '' && !WSM_Devices::device_can($device, $required_permission)) {
                return new WP_Error('wsm_sync_item_forbidden', 'دستگاه برای یکی از عملیات‌های این بسته مجوز لازم را ندارد.', array('status' => 403, 'permission' => $required_permission));
            }

            $validated_items[] = array(
                'queue_id' => $queue_id,
                'action_type' => $item->action_type,
                'payload' => $item->payload,
            );
        }

        $device_id = isset($device->id) ? (int) $device->id : 0;
        if ($device_id < 1) {
            return new WP_Error('wsm_sync_device_required', 'شناسهٔ دستگاه احراز هویت‌شده معتبر نیست.', array('status' => 401));
        }

        $lock_name = self::acquire_sync_lock(10);
        if (is_wp_error($lock_name)) { return $lock_name; }

        try {
            $table = $wpdb->prefix . 'wsm_sync_logs';
            $table_sql = '`' . str_replace('`', '``', $table) . '`';
            $received = 0;
            $replayed = 0;
            $duplicates_ignored = 0;
            $new_queue_ids = array();

            foreach ($validated_items as $item) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    'SELECT id, status, action_type, payload FROM ' . $table_sql . ' WHERE queue_id = %s LIMIT 1',
                    $item['queue_id']
                ));
                if (!empty($wpdb->last_error)) {
                    return new WP_Error('wsm_sync_logs_unavailable', 'خواندن جدول لاگ همگام‌سازی انجام نشد.', array('status' => 500));
                }
                if (is_object($existing) && !empty($existing->id)) {
                    if (!self::sync_queue_payload_matches($existing, $item)) {
                        return new WP_Error('wsm_sync_queue_id_conflict', 'این queue_id قبلاً با نوع عملیات یا payload دیگری ثبت شده است.', array('status' => 409));
                    }
                    $existing_status = isset($existing->status) ? strtolower((string) $existing->status) : '';
                    if ($existing_status === 'processed') {
                        // Only a row that already succeeded is a real duplicate.
                        $duplicates_ignored++;
                        continue;
                    }
                    // 'failed' or 'received': reopen the row so an explicit retry can actually run again.
                    $reopened = $wpdb->update($table, array('status' => 'received', 'error_message' => null),
                        array('id' => (int) $existing->id), array('%s', '%s'), array('%d'));
                    if ($reopened === false || !empty($wpdb->last_error)) {
                        return new WP_Error('wsm_sync_logs_unavailable', 'بازگشایی آیتم ناموفق قبلی انجام نشد.', array('status' => 500));
                    }
                    $replayed++;
                    $received++;
                    $new_queue_ids[$item['queue_id']] = true;
                    continue;
                }

                $inserted = $wpdb->insert($table, array(
                    'queue_id' => $item['queue_id'],
                    'device_id' => $device_id,
                    'action_type' => $item['action_type'],
                    'status' => 'received',
                    'payload' => $item['payload'],
                    'created_at' => self::sync_now(),
                ), array('%s', '%d', '%s', '%s', '%s', '%s'));
                if ($inserted === 1) {
                    $received++;
                    $new_queue_ids[$item['queue_id']] = true;
                    continue;
                }

                // UNIQUE(queue_id) is authoritative even if a concurrent non-REST writer races us.
                $insert_error = (string) $wpdb->last_error;
                $existing_after_insert = $wpdb->get_row($wpdb->prepare(
                    'SELECT id, status, action_type, payload FROM ' . $table_sql . ' WHERE queue_id = %s LIMIT 1',
                    $item['queue_id']
                ));
                if (!empty($wpdb->last_error)) {
                    return new WP_Error('wsm_sync_logs_unavailable', 'بررسی نتیجهٔ درج در جدول لاگ انجام نشد.', array('status' => 500));
                }
                if (is_object($existing_after_insert) && !empty($existing_after_insert->id)) {
                    if (!self::sync_queue_payload_matches($existing_after_insert, $item)) {
                        return new WP_Error('wsm_sync_queue_id_conflict', 'این queue_id قبلاً با نوع عملیات یا payload دیگری ثبت شده است.', array('status' => 409));
                    }
                    $existing_status = isset($existing_after_insert->status) ? strtolower((string) $existing_after_insert->status) : '';
                    if ($existing_status === 'processed') {
                        $duplicates_ignored++;
                        continue;
                    }
                    $wpdb->update($table, array('status' => 'received', 'error_message' => null),
                        array('id' => (int) $existing_after_insert->id), array('%s', '%s'), array('%d'));
                    if (!empty($wpdb->last_error)) {
                        return new WP_Error('wsm_sync_logs_unavailable', 'بازگشایی آیتم ناموفق قبلی انجام نشد.', array('status' => 500));
                    }
                    $replayed++;
                    $received++;
                    $new_queue_ids[$item['queue_id']] = true;
                    continue;
                }
                if ($insert_error !== '') { error_log('[WSM Sync] Failed to insert an item into the sync inbox.'); }
                return new WP_Error('wsm_sync_log_write_failed', 'ثبت آیتم در جدول لاگ همگام‌سازی انجام نشد.', array('status' => 500));
            }

            // The same lock covers insertion and replay, so no second request can claim the same received row.
            $processor = self::process_sync_batch_locked($device);
            if (is_wp_error($processor)) { return $processor; }
            $batch_processed = 0;
            $batch_failed = 0;
            $outcomes = array();
            $failed_items = array();
            foreach (array_keys($new_queue_ids) as $queue_id) {
                $outcome = isset($processor['outcomes'][$queue_id]) ? $processor['outcomes'][$queue_id] : array(
                    'queue_id' => $queue_id,
                    'action_type' => '',
                    'status' => 'failed',
                    'code' => 'wsm_sync_processing_incomplete',
                    'message' => 'پردازش این آیتم روی سرور کامل نشد؛ دوباره تلاش کنید.',
                    'http_status' => 500,
                );
                if (!isset($outcome['queue_id'])) { $outcome['queue_id'] = $queue_id; }
                $outcomes[$queue_id] = $outcome;
                if (isset($outcome['status']) && $outcome['status'] === 'processed') {
                    $batch_processed++;
                } else {
                    $batch_failed++;
                    $failed_items[] = $outcome;
                }
            }
            foreach ($validated_items as $item) {
                if (isset($outcomes[$item['queue_id']])) { continue; }
                $duplicate_outcome = array(
                    'queue_id' => $item['queue_id'],
                    'action_type' => $item['action_type'],
                    'status' => 'duplicate',
                    'code' => '',
                    'message' => 'این آیتم پیش‌تر روی سرور با موفقیت ثبت شده است.',
                    'http_status' => 200,
                );
                $duplicate_data = self::sync_duplicate_result($item);
                if (is_array($duplicate_data)) { $duplicate_outcome['data'] = $duplicate_data; }
                $outcomes[$item['queue_id']] = $duplicate_outcome;
            }
            $first_error_message = !empty($failed_items) ? (string) $failed_items[0]['message'] : '';
            if ($first_error_message === '') { $first_error_message = 'دلیل خطا در جدول لاگ همگام‌سازی ثبت شد.'; }

            return array(
                'success' => true,
                'total_received' => $total_received,
                'received' => $received,
                'replayed' => $replayed,
                'processed' => $batch_processed,
                'failed' => $batch_failed,
                'duplicates_ignored' => $duplicates_ignored,
                'outcomes' => $outcomes,
                'failed_items' => $failed_items,
                'message' => $batch_failed > 0
                    ? 'پردازش ' . $batch_failed . ' مورد ناموفق بود: ' . $first_error_message
                    : 'داده‌ها دریافت و پردازش شدند.',
            );
        } finally {
            self::release_sync_lock($lock_name);
        }
    }

    /** A queue UUID is an idempotency key for one exact operation, not a reusable token. */
    private static function sync_queue_payload_matches($existing, $item) {
        if (!is_object($existing) || !is_array($item) || !isset($existing->action_type, $existing->payload)) { return false; }
        if ((string) $existing->action_type !== (string) $item['action_type']) { return false; }
        $stored_payload = (string) $existing->payload;
        $incoming_payload = isset($item['payload']) && is_string($item['payload']) ? $item['payload'] : '';
        return $incoming_payload !== '' && hash_equals(hash('sha256', $stored_payload), hash('sha256', $incoming_payload));
    }

    /** Return only the canonical ID needed to reconcile a duplicate offline member locally. */
    private static function sync_duplicate_result($item) {
        global $wpdb;
        if (!is_array($item) || (string) $item['action_type'] !== 'CREATE_MEMBER' || !class_exists('WSM_Club_Operations')) { return null; }
        $payload = json_decode((string) $item['payload'], true);
        if (!is_array($payload)) { return null; }
        $offline_uuid = isset($payload['member_uuid']) && is_string($payload['member_uuid']) ? strtolower(trim($payload['member_uuid'])) : (isset($payload['uuid']) && is_string($payload['uuid']) ? strtolower(trim($payload['uuid'])) : '');
        if (!self::valid_sync_uuid($offline_uuid)) { return null; }
        $wpdb->last_error = '';
        $member_id = WSM_Club_Operations::find_sync_member_id($offline_uuid);
        if (is_wp_error($member_id) || (int) $member_id < 1) { return null; }
        $wpdb->last_error = '';
        $member = $wpdb->get_row($wpdb->prepare('SELECT id, member_code FROM ' . WSM_Club::table('members') . ' WHERE id = %d LIMIT 1', (int) $member_id));
        if (!empty($wpdb->last_error) || !is_object($member) || (int) $member->id < 1) { return null; }
        return array('id' => (int) $member->id, 'member_code' => isset($member->member_code) ? (string) $member->member_code : '', 'merged' => true, 'idempotent_replay' => true);
    }

    /** MySQL advisory lock; the name stays below MySQL's 64-character limit. */
    private static function acquire_sync_lock($timeout = 10) {
        global $wpdb;
        $lock_name = 'wsm_sync_' . substr(hash('sha256', (string) $wpdb->prefix), 0, 48);
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, max(0, (int) $timeout)));
        if ((string) $result === '1') { return $lock_name; }
        $status = ((string) $result === '0') ? 409 : 503;
        return new WP_Error('wsm_sync_processor_busy', 'پردازش همگام‌سازی در حال اجرا است؛ دوباره تلاش کنید.', array('status' => $status));
    }

    private static function release_sync_lock($lock_name) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', (string) $lock_name));
    }

    /** Public internal entry point for retrying all received rows under the same lock. */
    public static function process_sync_batch() {
        $lock_name = self::acquire_sync_lock(10);
        if (is_wp_error($lock_name)) { return $lock_name; }
        try {
            $fallback_device = WSM_Permissions::current_device();
            return self::process_sync_batch_locked($fallback_device);
        } finally {
            self::release_sync_lock($lock_name);
        }
    }

    /** Caller must hold the advisory lock. Every received row gets an isolated outcome. */
    private static function process_sync_batch_locked($fallback_device = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'wsm_sync_logs';
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, queue_id, device_id, action_type, payload FROM ' . $table_sql . ' WHERE status = %s ORDER BY id ASC',
            'received'
        ));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_sync_logs_unavailable', 'واکشی آیتم‌های دریافتی انجام نشد.', array('status' => 500));
        }

        $processed = 0;
        $failed = 0;
        $outcomes = array();
        foreach ((array) $rows as $row) {
            $row_id = is_object($row) && isset($row->id) ? (int) $row->id : 0;
            $queue_id = is_object($row) && isset($row->queue_id) ? strtolower((string) $row->queue_id) : '';
            $action_type = is_object($row) && isset($row->action_type) ? (string) $row->action_type : '';
            $failure = null;
            $result = null;

            try {
                $result = self::process_sync_log_record($row, $fallback_device);
            } catch (Throwable $exception) {
                $failure = array(
                    'code' => 'wsm_sync_processing_exception',
                    'message' => self::sync_error_message($exception),
                    'status' => 500,
                );
            }

            if ($failure === null && is_wp_error($result)) {
                // Keep the WP_Error code so the app can explain the real cause instead of a generic sentence.
                $error_data = $result->get_error_data();
                $failure = array(
                    'code' => (string) $result->get_error_code(),
                    'message' => (string) $result->get_error_message(),
                    'status' => is_array($error_data) && isset($error_data['status']) ? (int) $error_data['status'] : 400,
                );
            }

            if ($failure !== null) {
                if (!isset($failure['message']) || trim((string) $failure['message']) === '') {
                    $failure['message'] = 'پردازش آیتم همگام‌سازی ناموفق بود.';
                }
                $updated = $wpdb->update($table, array('status' => 'failed', 'error_message' => self::sync_failure_storage_message($failure)),
                    array('id' => $row_id, 'status' => 'received'),
                    array('%s', '%s'), array('%d', '%s'));
                if ($updated === false || (int) $updated < 1) {
                    error_log('[WSM Sync] Could not persist a failed processing state.');
                }
                $failed++;
                $outcomes[$queue_id] = array(
                    'queue_id' => $queue_id,
                    'action_type' => $action_type,
                    'status' => 'failed',
                    'code' => (string) $failure['code'],
                    'message' => (string) $failure['message'],
                    'http_status' => (int) $failure['status'],
                );
                continue;
            }

            $updated = $wpdb->update($table, array('status' => 'processed', 'error_message' => null),
                array('id' => $row_id, 'status' => 'received'),
                array('%s', '%s'), array('%d', '%s'));
            if ($updated === false || (int) $updated < 1) {
                $failed++;
                $outcomes[$queue_id] = array(
                    'queue_id' => $queue_id,
                    'action_type' => $action_type,
                    'status' => 'failed',
                    'code' => 'wsm_sync_log_status_failed',
                    'message' => 'به‌روزرسانی وضعیت آیتم به processed انجام نشد.',
                    'http_status' => 500,
                );
                continue;
            }
            $processed++;
            $outcomes[$queue_id] = array(
                'queue_id' => $queue_id,
                'action_type' => $action_type,
                'status' => 'processed',
                'code' => '',
                'message' => '',
                'http_status' => 200,
                'data' => is_array($result) ? $result : array(),
            );
        }
        return array('processed' => $processed, 'failed' => $failed, 'outcomes' => $outcomes);
    }

    /** Readable, bounded text stored in wsm_sync_logs.error_message (code is kept for later diagnosis). */
    private static function sync_failure_storage_message($failure) {
        $code = is_array($failure) && isset($failure['code']) ? trim((string) $failure['code']) : '';
        $message = is_array($failure) && isset($failure['message']) ? trim((string) $failure['message']) : '';
        $stored = $code !== '' ? '[' . $code . '] ' . $message : $message;
        if ($stored === '') { $stored = 'پردازش آیتم همگام‌سازی ناموفق بود.'; }
        if (function_exists('mb_substr')) { return mb_substr($stored, 0, 4000, 'UTF-8'); }
        return substr($stored, 0, 12000);
    }

    /**
     * Timestamp for the sync inbox/log tables.
     * WSM_Core never had a now() method (it lives in WSM_Util), so the old
     * WSM_Core::now() call raised "Call to undefined method" and killed the request
     * with an HTTP 500 before the queue row could even be written.
     */
    private static function sync_now() {
        if (function_exists('current_time')) {
            return current_time('mysql');
        }
        return gmdate('Y-m-d H:i:s');
    }

    private static function sync_error_message($exception) {
        $message = sanitize_textarea_field((string) $exception->getMessage());
        if ($message === '') { $message = 'پردازش آیتم همگام‌سازی ناموفق بود.'; }
        if (function_exists('mb_substr')) { return mb_substr($message, 0, 4000, 'UTF-8'); }
        return substr($message, 0, 12000);
    }

    private static function process_sync_log_record($row, $fallback_device = null) {
        if (!is_object($row) || !isset($row->action_type, $row->payload)) {
            return new WP_Error('wsm_sync_record_invalid', 'ساختار رکورد لاگ همگام‌سازی معتبر نیست.', array('status' => 400));
        }
        $payload = json_decode((string) $row->payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            return new WP_Error('wsm_sync_payload_invalid', 'JSON ذخیره‌شدهٔ آیتم معتبر نیست.', array('status' => 400));
        }

        $device_id = isset($row->device_id) ? (int) $row->device_id : 0;
        $device = $device_id > 0 ? WSM_Devices::find($device_id) : $fallback_device;
        if (!$device || !is_object($device) || empty($device->id)) {
            return new WP_Error('wsm_sync_device_missing', 'دستگاه مبدأ این آیتم پیدا نشد.', array('status' => 403));
        }
        if (!isset($device->status) || $device->status !== 'active') {
            return new WP_Error('wsm_sync_device_inactive', 'دستگاه مبدأ دیگر فعال نیست.', array('status' => 403));
        }

        $previous_device = WSM_Permissions::current_device();
        $previous_user_id = get_current_user_id();
        try {
            WSM_Permissions::set_device($device);
            $user_id = isset($device->user_id) ? (int) $device->user_id : 0;
            if ($user_id < 1 || !get_userdata($user_id) || get_user_meta($user_id, 'wsm_app_user_status', true) === 'inactive') {
                return new WP_Error('wsm_sync_account_inactive', 'حساب کاربر دستگاه مبدأ فعال نیست.', array('status' => 403));
            }
            if ($row->action_type === 'CREATE_SALE') {
                if (!WSM_Devices::device_can($device, 'club.pos.create')) {
                    return new WP_Error('wsm_sync_permission_denied', 'دستگاه مبدأ مجوز ثبت فروش ندارد.', array('status' => 403));
                }
                return self::process_sync_sale($payload, (string) $row->queue_id);
            }
            if ($row->action_type === 'CREATE_MEMBER') {
                if (!WSM_Devices::device_can($device, 'club.members.manage')) {
                    return new WP_Error('wsm_sync_permission_denied', 'دستگاه مبدأ مجوز ثبت عضو ندارد.', array('status' => 403));
                }
                return self::process_sync_member($payload);
            }
            if ($row->action_type === 'CREATE_MEMBERSHIP') {
                if (!WSM_Devices::device_can($device, 'club.members.manage')) {
                    return new WP_Error('wsm_sync_permission_denied', 'دستگاه مبدأ مجوز ثبت عضویت ندارد.', array('status' => 403));
                }
                $received = isset($payload['received_amount']) && is_scalar($payload['received_amount']) ? (float) $payload['received_amount'] : 0;
                if ($received > 0 && !WSM_Devices::device_can($device, 'club.billing.manage') && !WSM_Devices::device_can($device, 'club.payments.create')) {
                    return new WP_Error('wsm_sync_payment_permission_denied', 'دستگاه مبدأ مجوز ثبت دریافت شهریه را ندارد.', array('status' => 403));
                }
                return self::process_sync_membership($payload);
            }
            if ($row->action_type === 'CREATE_MESSAGE') {
                return WSM_Club_Operations::create_internal_message($payload, $user_id);
            }
            return new WP_Error('wsm_sync_action_unknown', 'نوع عملیات ثبت‌شده پشتیبانی نمی‌شود.', array('status' => 400));
        } finally {
            WSM_Permissions::set_device($previous_device);
            wp_set_current_user($previous_user_id);
        }
    }

    private static function valid_sync_uuid($value) {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function process_sync_membership($payload) {
        if (!is_array($payload)) {
            return new WP_Error('wsm_sync_membership_invalid', 'اطلاعات عضویت آفلاین معتبر نیست.', array('status' => 400));
        }
        return WSM_Club::create_membership_from_sync($payload);
    }

    private static function process_sync_member($payload) {
        $offline_uuid = '';
        if (array_key_exists('member_uuid', $payload) && $payload['member_uuid'] !== null && $payload['member_uuid'] !== '') {
            if (!is_string($payload['member_uuid'])) {
                return new WP_Error('wsm_sync_member_uuid_invalid', 'member_uuid عضو آفلاین معتبر نیست.', array('status' => 400));
            }
            $offline_uuid = $payload['member_uuid'];
        } elseif (isset($payload['uuid']) && is_string($payload['uuid'])) {
            $offline_uuid = $payload['uuid'];
        }
        if (!self::valid_sync_uuid($offline_uuid)) {
            return new WP_Error('wsm_sync_member_uuid_invalid', 'member_uuid عضو آفلاین معتبر نیست.', array('status' => 400));
        }
        return WSM_Club_Operations::save_member_from_sync($payload, strtolower($offline_uuid));
    }

    private static function sync_order_id_by_meta($meta_key, $meta_value) {
        global $wpdb;
        $ids = WSM_Util::order_ids_with_meta($meta_key, $meta_value);
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_sync_order_lookup_failed', 'بررسی سفارش همگام‌شده انجام نشد.', array('status' => 500));
        }
        return !empty($ids) ? absint(reset($ids)) : 0;
    }

    private static function ensure_sync_invoice($order_id) {
        $order = wc_get_order((int) $order_id);
        if (!$order || $order->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_sync_order_missing', 'سفارش برای صدور فاکتور پیدا نشد.', array('status' => 404));
        }
        $linked_id = absint($order->get_meta('_wsm_linked_invoice_id'));
        if ($linked_id) {
            $invoice = wc_get_order($linked_id);
            if ($invoice && $invoice->get_meta('_wsm_invoice') && absint($invoice->get_meta('_wsm_source_order_id')) === (int) $order_id) {
                return true;
            }
        }

        // Recover a saved invoice if a crash happened before its source-order link was persisted.
        global $wpdb;
        $invoice_ids = WSM_Util::order_ids_with_meta('_wsm_source_order_id', (string) (int) $order_id);
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_sync_invoice_lookup_failed', 'بررسی فاکتور قبلی فروش انجام نشد.', array('status' => 500));
        }
        foreach ((array) $invoice_ids as $invoice_id) {
            $candidate = wc_get_order((int) $invoice_id);
            if ($candidate && $candidate->get_meta('_wsm_invoice') && absint($candidate->get_meta('_wsm_source_order_id')) === (int) $order_id) {
                $order->update_meta_data('_wsm_linked_invoice_id', (int) $invoice_id);
                $order->save_meta_data();
                return true;
            }
        }
        $created = WSM_Invoices::create_from_order((int) $order_id);
        if (is_wp_error($created)) { return $created; }
        $order = wc_get_order((int) $order_id);
        $linked_id = $order ? absint($order->get_meta('_wsm_linked_invoice_id')) : 0;
        $invoice = $linked_id ? wc_get_order($linked_id) : false;
        if (!$invoice || !$invoice->get_meta('_wsm_invoice') || absint($invoice->get_meta('_wsm_source_order_id')) !== (int) $order_id) {
            return new WP_Error('wsm_sync_invoice_failed', 'فاکتور فروش آفلاین ساخته نشد.', array('status' => 500));
        }
        return true;
    }

    private static function process_sync_sale($payload, $queue_id) {
        global $wpdb;
        $sale_uuid = isset($payload['sale_uuid']) && is_string($payload['sale_uuid']) ? strtolower(trim($payload['sale_uuid'])) : '';
        $queue_id = strtolower(trim((string) $queue_id));
        if (!self::valid_sync_uuid($queue_id) || !self::valid_sync_uuid($sale_uuid)) {
            return new WP_Error('wsm_sync_sale_uuid_invalid', 'queue_id یا sale_uuid فروش آفلاین معتبر نیست.', array('status' => 400));
        }

        if (array_key_exists('create_invoice', $payload) && !is_scalar($payload['create_invoice'])) {
            return new WP_Error('wsm_sync_invoice_flag_invalid', 'پرچم صدور فاکتور معتبر نیست.', array('status' => 400));
        }
        if (array_key_exists('paid', $payload) && !is_scalar($payload['paid'])) {
            return new WP_Error('wsm_sync_payment_flag_invalid', 'وضعیت پرداخت فروش آفلاین معتبر نیست.', array('status' => 400));
        }
        if (array_key_exists('discount_approval_token', $payload) && (!is_string($payload['discount_approval_token']) || strlen($payload['discount_approval_token']) > 8192)) {
            return new WP_Error('wsm_sync_discount_token_invalid', 'مجوز امضاشدهٔ تخفیف فروش آفلاین معتبر نیست.', array('status' => 400));
        }
        if (array_key_exists('manager_password', $payload) || array_key_exists('manager_username', $payload)) {
            return new WP_Error('wsm_sync_finance_credentials_forbidden', 'اطلاعات ورود مدیر هرگز در صف آفلاین پذیرفته نمی‌شود.', array('status' => 400));
        }
        $create_invoice = isset($payload['create_invoice']) && rest_sanitize_boolean($payload['create_invoice']);
        if ($create_invoice && !WSM_Devices::device_can(WSM_Permissions::current_device(), 'invoices.create')) {
            return new WP_Error('wsm_sync_invoice_forbidden', 'دستگاه مبدأ مجوز صدور فاکتور ندارد.', array('status' => 403));
        }

        // Recover a previously-created order if the request died before its log status changed.
        $order_id = self::sync_order_id_by_meta('_wsm_offline_queue_id', $queue_id);
        if (is_wp_error($order_id)) { return $order_id; }
        if (!$order_id) {
            $order_id = self::sync_order_id_by_meta('_wsm_offline_sale_uuid', $sale_uuid);
            if (is_wp_error($order_id)) { return $order_id; }
        }
        if ($order_id) {
            $existing_order = wc_get_order((int) $order_id);
            if (!$existing_order || !rest_sanitize_boolean($existing_order->get_meta('_wsm_offline_sync_complete'))) {
                return new WP_Error('wsm_sync_sale_replay_incomplete', 'برای این UUID سفارش ناقص موجود است؛ از ساخت سفارش تکراری جلوگیری شد و بررسی دستی لازم است.', array('status' => 409));
            }
            if ($create_invoice) {
                $invoice = self::ensure_sync_invoice($order_id);
                if (is_wp_error($invoice)) { return $invoice; }
            }
            $replay_result = array('order_id' => (int) $order_id, 'idempotent_replay' => true);
            $replay_member_id = absint($existing_order->get_meta('_wsm_club_member_id'));
            if ($replay_member_id > 0) {
                $replay_result['member_id'] = $replay_member_id;
                $replay_result['member_balance_due'] = (float) WSM_Club::balance_due($replay_member_id);
                $replay_pos_debts = WSM_Club_Operations::pos_debt_by_member(array($replay_member_id));
                if (is_wp_error($replay_pos_debts)) {
                    return new WP_Error('wsm_sync_sale_replay_debt_failed', 'فروش قبلی شناسایی شد، اما بازخوانی ماندهٔ بدهی عضو ناموفق بود؛ همگام‌سازی باید دوباره تلاش شود.', array('status' => 500));
                }
                $replay_result['member_pos_debt_due'] = isset($replay_pos_debts[$replay_member_id]) ? (float) $replay_pos_debts[$replay_member_id] : 0.0;
                $replay_result['member_total_debt_due'] = $replay_result['member_balance_due'] + $replay_result['member_pos_debt_due'];
            }
            return $replay_result;
        }

        if (!isset($payload['items']) || !is_array($payload['items']) || !$payload['items']) {
            return new WP_Error('wsm_sync_sale_items_invalid', 'فهرست اقلام فروش آفلاین خالی یا نامعتبر است.', array('status' => 400));
        }
        $items = array();
        foreach ($payload['items'] as $item) {
            if (!is_array($item)) {
                return new WP_Error('wsm_sync_sale_item_invalid', 'یکی از اقلام فروش آفلاین معتبر نیست.', array('status' => 400));
            }
            $sku = isset($item['sku']) && is_scalar($item['sku']) ? (string) $item['sku'] : '';
            $raw_product_id = isset($item['product_id']) && is_scalar($item['product_id']) ? (string) $item['product_id'] : '';
            $product_id = preg_match('/^\d+$/D', $raw_product_id) ? absint($raw_product_id) : 0;
            if (!isset($item['qty']) || !is_scalar($item['qty'])) {
                return new WP_Error('wsm_sync_sale_quantity_invalid', 'تعداد یکی از اقلام فروش آفلاین معتبر نیست.', array('status' => 400));
            }
            $items[] = array('product_id' => $product_id, 'sku' => $sku, 'qty' => $item['qty']);
        }

        $member_identifier = isset($payload['member_identifier']) && is_scalar($payload['member_identifier']) ? trim((string) $payload['member_identifier']) : '';
        $member_uuid = '';
        if (array_key_exists('member_uuid', $payload) && $payload['member_uuid'] !== null && $payload['member_uuid'] !== '') {
            if (!is_string($payload['member_uuid'])) {
                return new WP_Error('wsm_sync_member_uuid_invalid', 'member_uuid فروش آفلاین معتبر نیست.', array('status' => 400));
            }
            $member_uuid = trim($payload['member_uuid']);
        }
        if ($member_uuid !== '') {
            if (!self::valid_sync_uuid($member_uuid)) {
                return new WP_Error('wsm_sync_member_uuid_invalid', 'member_uuid فروش آفلاین معتبر نیست.', array('status' => 400));
            }
            $mapped_member_id = WSM_Club_Operations::find_sync_member_id(strtolower($member_uuid));
            if (is_wp_error($mapped_member_id)) { return $mapped_member_id; }
            if ((int) $mapped_member_id > 0) {
                $member = $wpdb->get_row($wpdb->prepare(
                    'SELECT member_code, status FROM ' . WSM_Club::table('members') . ' WHERE id = %d LIMIT 1',
                    (int) $mapped_member_id
                ));
                if (!empty($wpdb->last_error)) {
                    return new WP_Error('wsm_sync_member_lookup_failed', 'خواندن عضو مرتبط با فروش انجام نشد.', array('status' => 500));
                }
                if (!$member || $member->status !== 'active') {
                    return new WP_Error('wsm_sync_member_inactive', 'عضو پیوندخورده با فروش آفلاین فعال نیست.', array('status' => 409));
                }
                $member_identifier = (string) $member->member_code;
            }
        }

        $method = isset($payload['method']) && is_scalar($payload['method']) ? (string) $payload['method'] : '';
        $sync_data = array(
            'items' => $items,
            'method' => $method,
            'paid' => array_key_exists('paid', $payload) ? $payload['paid'] : true,
            'discount_amount' => array_key_exists('discount_amount', $payload) ? $payload['discount_amount'] : 0,
            'discount_approval_token' => isset($payload['discount_approval_token']) && is_string($payload['discount_approval_token']) ? $payload['discount_approval_token'] : '',
            'sale_uuid' => $sale_uuid,
            'reference' => isset($payload['reference']) && is_scalar($payload['reference']) ? (string) $payload['reference'] : '',
            'create_invoice' => $create_invoice,
        );
        if ($member_identifier !== '') { $sync_data['member_identifier'] = $member_identifier; }

        $result = WSM_Club_Operations::create_pos_order($sync_data, array('queue_id' => $queue_id, 'sale_uuid' => $sale_uuid));
        if (is_wp_error($result)) { return $result; }
        if (!is_array($result) || empty($result['order_id'])) {
            return new WP_Error('wsm_sync_sale_create_failed', 'ساخت سفارش فروش آفلاین نتیجهٔ معتبری برنگرداند.', array('status' => 500));
        }
        if ($create_invoice) {
            $invoice = self::ensure_sync_invoice((int) $result['order_id']);
            if (is_wp_error($invoice)) { return $invoice; }
        }
        return $result;
    }

    /* ---------------- نشست ---------------- */

    public static function auth_recovery_request($request) {
        $b = self::body($request);
        return WSM_Auth::request_password_reset(isset($b['identifier']) ? $b['identifier'] : '');
    }

    public static function auth_recovery_reset($request) {
        $b = self::body($request);
        return WSM_Auth::reset_password(isset($b['identifier']) ? $b['identifier'] : '', isset($b['code']) ? $b['code'] : '', isset($b['new_password']) ? $b['new_password'] : '');
    }

    public static function auth_login($request) {
        $b = self::body($request);
        $remember = isset($b['remember']) && is_scalar($b['remember']) ? rest_sanitize_boolean($b['remember']) : false;
        $identifier = isset($b['identifier']) && is_scalar($b['identifier']) && trim((string) $b['identifier']) !== '' ? $b['identifier'] : (isset($b['username']) ? $b['username'] : '');
        return WSM_Auth::login($identifier, isset($b['password']) ? $b['password'] : '', array(
            'client_id' => isset($b['client_id']) ? $b['client_id'] : '',
            'device_name' => isset($b['device_name']) ? $b['device_name'] : '',
            'platform' => isset($b['platform']) ? $b['platform'] : '',
            'os_version' => isset($b['os_version']) ? $b['os_version'] : '',
            'app_version' => isset($b['app_version']) ? $b['app_version'] : '',
        ), $remember);
    }

    public static function auth_login_otp_request($request) {
        $b = self::body($request);
        $phone = isset($b['phone']) ? $b['phone'] : (isset($b['identifier']) ? $b['identifier'] : '');
        return WSM_Auth::request_login_otp($phone);
    }

    public static function auth_login_otp_verify($request) {
        $b = self::body($request);
        $remember = isset($b['remember']) && is_scalar($b['remember']) ? rest_sanitize_boolean($b['remember']) : false;
        return WSM_Auth::verify_login_otp(isset($b['phone']) ? $b['phone'] : '', isset($b['code']) ? $b['code'] : '', array(
            'client_id' => isset($b['client_id']) ? $b['client_id'] : '',
            'device_name' => isset($b['device_name']) ? $b['device_name'] : '',
            'platform' => isset($b['platform']) ? $b['platform'] : '',
            'os_version' => isset($b['os_version']) ? $b['os_version'] : '',
            'app_version' => isset($b['app_version']) ? $b['app_version'] : '',
        ), $remember);
    }

    public static function handshake($request) {
        if (!WSM_Util::is_woo_active()) {
            return WSM_Util::woo_required_error();
        }
        $b = self::body($request);
        return WSM_Auth::handshake(
            isset($b['access_key']) ? (string) $b['access_key'] : '',
            array(
                'device_name' => isset($b['device_name']) ? $b['device_name'] : '',
                'platform'    => isset($b['platform']) ? $b['platform'] : '',
                'os_version'  => isset($b['os_version']) ? $b['os_version'] : '',
                'app_version' => isset($b['app_version']) ? $b['app_version'] : '',
            )
        );
    }

    public static function refresh($request) {
        $b = self::body($request);
        return WSM_Auth::refresh(isset($b['refresh_token']) ? (string) $b['refresh_token'] : '');
    }

    public static function logout() {
        $device = WSM_Permissions::current_device();
        if ($device) {
            $result = WSM_Devices::set_status((int) $device->id, 'logout');
            if (is_wp_error($result)) { return $result; }
        }
        return array('ok' => true);
    }

    public static function app_users($request) { return WSM_Auth::list_app_users(); }

    public static function app_user_create($request) { return WSM_Auth::create_app_user(self::body($request)); }

    public static function app_user_update($request) { return WSM_Auth::update_app_user(self::id($request), self::body($request)); }

    public static function app_user_delete($request) { return WSM_Auth::delete_app_user(self::id($request)); }

    public static function profile_get() { return WSM_Auth::profile(); }

    public static function profile_update($request) { return WSM_Auth::update_profile(self::body($request)); }

    /** Return a single permission-scoped payload for first app load. */
    /**
     * Build the bootstrap profile without repeated permission-table reads.
     * This only serializes grants for the UI; check_request and each route's
     * permission callback remain the authorization source of truth.
     */
    private static function normalize_app_init_permissions($permissions, $catalog) {
        $clean = array();
        foreach ((array) $permissions as $permission) {
            if ($permission === 'users.super_admin.assign') { continue; }
            if (is_string($permission) && isset($catalog[$permission]) && !in_array($permission, $clean, true)) {
                $clean[] = $permission;
            }
        }
        if (in_array('users.manage', $clean, true) && isset($catalog['club.users.manage']) && !in_array('club.users.manage', $clean, true)) {
            $clean[] = 'club.users.manage';
        }
        if (in_array('club.users.manage', $clean, true) && isset($catalog['users.manage']) && !in_array('users.manage', $clean, true)) {
            $clean[] = 'users.manage';
        }
        return $clean;
    }

    private static function app_init_profile($device, $settings) {
        $user_id = (int) $device->user_id;
        // check_request() already loaded and installed the authenticated user. Reuse that
        // WP_User object; the fallback get_userdata() path uses WordPress's users cache.
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : false;
        if (!$user || !is_object($user) || (int) $user->ID !== $user_id) {
            $user = get_userdata($user_id);
        }
        $wp_admin = WSM_Permissions::is_wordpress_admin_user($user);
        $record = $wp_admin ? null : WSM_Permissions::user_permissions_record($user_id);
        $app_role = sanitize_key((string) get_user_meta($user_id, 'wsm_app_role', true));
        $presets = WSM_Permissions::presets();
        $catalog = WSM_Devices::permission_catalog();
        $all_permissions = array_keys($catalog);
        $is_super_admin = $wp_admin || ($record !== null && $record['preset'] === 'super_admin') || $app_role === 'super_admin';

        if ($is_super_admin) {
            $permissions = $all_permissions;
            $device_permissions = $all_permissions;
        } else {
            if ($record !== null) {
                $user_permissions = self::normalize_app_init_permissions($record['permissions'], $catalog);
            } elseif ($app_role !== '' && isset($presets[$app_role])) {
                $user_permissions = self::normalize_app_init_permissions($presets[$app_role]['permissions'], $catalog);
            } else {
                $user_permissions = null;
            }

            $has_device_permissions = property_exists($device, 'permissions') && $device->permissions !== null && $device->permissions !== '';
            if (!$has_device_permissions) {
                $legacy = array('dashboard.view','products.view','products.create','products.edit','products.delete',
                    'orders.view','orders.create','orders.edit','orders.change_status','orders.refund','orders.delete','orders.history',
                    'invoices.view','invoices.create','invoices.edit','invoices.change_status','invoices.delete','invoices.print',
                    'customers.view','reports.view','devices.view','devices.manage','activity.view','settings.view','settings.edit');
                $device_permissions = array_values(array_intersect($legacy, array_keys($catalog)));
            } else {
                $raw = is_array($device->permissions) ? $device->permissions : json_decode((string) $device->permissions, true);
                if (!is_array($raw)) {
                    $device_permissions = WSM_Devices::default_permissions();
                } else {
                    $device_permissions = array();
                    foreach ($raw as $permission) {
                        if (is_string($permission) && isset($catalog[$permission]) && !in_array($permission, $device_permissions, true)) {
                            $device_permissions[] = $permission;
                        }
                    }
                }
            }

            if ($user_permissions === null) {
                $permissions = self::normalize_app_init_permissions($device_permissions, $catalog);
            } elseif (!$has_device_permissions) {
                $permissions = $user_permissions;
            } else {
                $normalized_device_permissions = self::normalize_app_init_permissions($device_permissions, $catalog);
                $permissions = self::normalize_app_init_permissions(array_intersect($user_permissions, $normalized_device_permissions), $catalog);
            }
        }

        $preset = $wp_admin ? 'super_admin' : ($record !== null ? $record['preset'] : $app_role);
        $is_super_admin = $preset === 'super_admin' || $wp_admin;
        $permission_count = $is_super_admin ? count($catalog) : count($permissions);
        $device_profile = array(
            'id' => (int) $device->id,
            'user_id' => $user_id,
            'user_login' => $user ? (string) $user->user_login : '',
            'display_name' => $user ? (string) $user->display_name : '',
            'device_name' => (string) $device->device_name,
            'platform' => (string) $device->platform,
            'os_version' => (string) $device->os_version,
            'app_version' => (string) $device->app_version,
            'ip_address' => (string) $device->ip_address,
            'status' => (string) $device->status,
            'remember_me' => isset($device->remember_me) ? (bool) $device->remember_me : false,
            'permissions' => $device_permissions,
            'block_reason' => (string) $device->block_reason,
            'last_seen' => wsm_fa_date(isset($device->last_seen) ? $device->last_seen : ''),
            'created_at' => wsm_fa_date(isset($device->created_at) ? $device->created_at : ''),
        );
        return array(
            'user' => $user ? $user->display_name : '',
            'user_id' => $user ? (int) $user->ID : 0,
            'role' => $preset,
            'role_label' => isset($presets[$preset]['label']) ? $presets[$preset]['label'] : 'سفارشی',
            'is_super_admin' => $is_super_admin,
            'is_wordpress_admin' => $user && in_array('administrator', (array) $user->roles, true),
            'permission_count' => $permission_count,
            'device' => $device_profile,
            'device_permissions' => $permissions,
            'permissions' => $permissions,
            'capabilities' => $permissions,
            'store_name' => isset($settings['store_name']) ? $settings['store_name'] : '',
        );
    }

    public static function app_init($request) {
        self::arm_app_init_shutdown($request);
        $device = WSM_Permissions::current_device();
        if (!$device) {
            return new WP_Error('wsm_forbidden', 'دسترسی به این بخش ندارید.', array('status' => 401));
        }
        self::$app_init_auth_verified = true;

        // Keep the bootstrap small: profile and only the configuration needed to render the shell.
        // Reuse the computed profile grants instead of repeating permission-table lookups.
        $settings = WSM_Util::settings();
        $me = self::app_init_profile($device, $settings);
        $payload = array('me' => $me);
        $me_permissions = isset($me['device_permissions']) && is_array($me['device_permissions']) ? $me['device_permissions'] : array();
        if (in_array('settings.view', $me_permissions, true) || in_array('security.manage', $me_permissions, true)) {
            $payload['settings'] = array(
                'store_name'      => isset($settings['store_name']) ? $settings['store_name'] : '',
                'store_logo'      => isset($settings['store_logo']) ? $settings['store_logo'] : '',
                'ui_config'       => WSM_Util::clean_ui_config(isset($settings['ui_config']) ? $settings['ui_config'] : array()),
                'currency'        => get_woocommerce_currency(),
                'currency_symbol' => wsm_currency_symbol(),
                'version'         => WSM_VERSION,
            );
            // This is deliberately partial; the full /settings route remains lazy-loaded.
            $payload['settings_partial'] = true;
        }

        return $payload;
    }

    public static function me() {
        $device = WSM_Permissions::current_device();
        $user   = get_userdata((int) $device->user_id);
        $permissions = WSM_Permissions::effective_permissions($device);
        $record = WSM_Permissions::user_permissions_record((int) $device->user_id);
        $wp_admin = WSM_Permissions::is_wordpress_admin_user($user);
        $preset = $wp_admin ? 'super_admin' : ($record ? $record['preset'] : sanitize_key((string) get_user_meta((int) $device->user_id, 'wsm_app_role', true)));
        $is_super_admin = $preset === 'super_admin' || $wp_admin;
        if ($is_super_admin) {
            $permissions = array_keys(WSM_Devices::permission_catalog());
        }
        $permission_count = $is_super_admin ? count(WSM_Devices::permission_catalog()) : count($permissions);
        if (!WSM_Devices::device_can($device, 'security.manage')) {
            $permissions = array_values(array_diff($permissions, array('security.manage')));
        }
        $presets = WSM_Permissions::presets();
        return array(
            'user'               => $user ? $user->display_name : '',
            'user_id'            => $user ? (int) $user->ID : 0,
            'role'               => $preset,
            'role_label'         => isset($presets[$preset]['label']) ? $presets[$preset]['label'] : 'سفارشی',
            'is_super_admin'     => $is_super_admin,
            'is_wordpress_admin' => $user && in_array('administrator', (array) $user->roles, true),
            'permission_count'   => $permission_count,
            'device'             => WSM_Devices::format($device),
            'device_permissions' => $permissions,
            'permissions'        => $permissions,
            'capabilities'       => $permissions,
            'store_name'         => WSM_Util::settings()['store_name'],
        );
    }

    public static function settings() {
        return self::cached_rest_get('settings', '/settings', array(), function () {
            return self::settings_uncached();
        });
    }

    private static function settings_uncached() {
        $s = WSM_Util::settings();
        return array(
            'store_name'      => $s['store_name'],
            'store_logo'      => $s['store_logo'],
            'ui_config'       => WSM_Util::clean_ui_config(isset($s['ui_config']) ? $s['ui_config'] : array()),
            'postal_sender'   => array(
                'name'       => $s['postal_sender_name'],
                'company'    => $s['postal_sender_company'],
                'phone'      => $s['postal_sender_phone'],
                'email'      => $s['postal_sender_email'],
                'address_1'  => $s['postal_sender_address_1'],
                'address_2'  => $s['postal_sender_address_2'],
                'city'       => $s['postal_sender_city'],
                'state'      => $s['postal_sender_state'],
                'postcode'   => $s['postal_sender_postcode'],
                'country'    => $s['postal_sender_country'],
            ),
            'currency'        => get_woocommerce_currency(),
            'currency_symbol' => wsm_currency_symbol(),
            // Settings-center values are isolated from live POS/WooCommerce behavior until a later integration phase.
            'settings_center' => self::public_settings_center($s),
            'order_statuses'  => WSM_Orders::statuses(),
            'invoice_statuses' => array(
                array('key' => 'draft', 'label' => 'پیش‌نویس'),
                array('key' => 'sent', 'label' => 'ارسال‌شده'),
                array('key' => 'paid', 'label' => 'پرداخت‌شده'),
                array('key' => 'cancelled', 'label' => 'لغوشده'),
            ),
            'version'                    => WSM_VERSION,
            'expiry_alert_days'           => max(1, min(365, (int) $s['expiry_alert_days'])),
            'expiry_alert_email'          => !empty($s['expiry_alert_email']),
            'expiry_alert_sms'            => !empty($s['expiry_alert_sms']),
            'expiry_alert_email_to'       => !empty($s['expiry_alert_email_to']) ? $s['expiry_alert_email_to'] : get_option('admin_email'),
            'expiry_meta_key'             => WSM_Expiry::custom_meta_key(),
            'expiry_meta_key_in_use'      => WSM_Expiry::meta_key_in_use(),
            'expiry_meta_key_detected'    => WSM_Expiry::detected_meta_key(),
            'totp_required'               => !empty($s['totp_required']),
            // Reuse WooCommerce's existing global low-stock threshold; do not duplicate it in wsm_settings.
            'low_stock_threshold'         => max(0, min(1000000, (int) get_option('woocommerce_notify_low_stock_amount', 2))),
        );
    }

    /** Register the existing wsm_settings option with WordPress Settings API. */
    public static function register_settings_api_option() {
        if (!function_exists('register_setting')) { return; }
        register_setting('wsm_app_settings_group', 'wsm_settings', array(
            'type' => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize_registered_settings_option'),
        ));
    }

    /** Preserve legacy option keys while sanitizing the Settings API option payload. */
    public static function sanitize_registered_settings_option($value) {
        $existing = get_option('wsm_settings', array());
        $existing = is_array($existing) ? $existing : array();
        if (!is_array($value)) { return $existing; }
        $merged = array_merge($existing, $value);
        if (array_key_exists('settings_center', $merged)) {
            $sanitized = self::sanitize_settings_center($merged['settings_center'], $merged);
            if (is_wp_error($sanitized)) {
                if (isset($existing['settings_center']) && is_array($existing['settings_center'])) { $merged['settings_center'] = $existing['settings_center']; }
                else { unset($merged['settings_center']); }
            } else { $merged = $sanitized; }
        }
        return $merged;
    }

    /** Return a safe, allow-listed view of settings-center fields stored inside wsm_settings. */
    private static function public_settings_center($settings) {
        $settings = is_array($settings) ? $settings : array();
        $saved = isset($settings['settings_center']) && is_array($settings['settings_center']) ? $settings['settings_center'] : array();
        $site_timezone = function_exists('wp_timezone_string') ? (string) wp_timezone_string() : 'UTC';
        if ($site_timezone === '') { $site_timezone = 'Asia/Tehran'; }
        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
        $currency_default = $currency === 'IRR' ? 'rial' : 'toman';
        $default_store_name = isset($settings['store_name']) && is_scalar($settings['store_name']) ? sanitize_text_field((string) $settings['store_name']) : get_bloginfo('name');
        $date_format = isset($saved['date_format']) && is_scalar($saved['date_format']) ? sanitize_key((string) $saved['date_format']) : 'jalali';
        $saved_currency = isset($saved['currency']) && is_scalar($saved['currency']) ? sanitize_key((string) $saved['currency']) : $currency_default;
        $timezone = isset($saved['timezone']) && is_scalar($saved['timezone']) ? trim(sanitize_text_field((string) $saved['timezone'])) : $site_timezone;
        $valid_timezones = timezone_identifiers_list(DateTimeZone::ALL_WITH_BC);
        $valid_offset = (bool) preg_match('/^[+-](?:(?:0[0-9]|1[0-3]):[0-5][0-9]|14:00)$/', $timezone);
        if ($timezone === '' || (!in_array($timezone, $valid_timezones, true) && $timezone !== 'UTC' && !$valid_offset)) { $timezone = $site_timezone; }
        $discount = isset($saved['pos_discount_limit']) && is_scalar($saved['pos_discount_limit']) && is_numeric($saved['pos_discount_limit']) && is_finite((float) $saved['pos_discount_limit']) ? (float) $saved['pos_discount_limit'] : 0.0;
        $read_bool = static function ($key, $default = false) use ($saved) {
            return array_key_exists($key, $saved) && is_scalar($saved[$key]) ? rest_sanitize_boolean($saved[$key]) : (bool) $default;
        };
        $read_number = static function ($key, $default, $minimum, $maximum, $integer = true) use ($saved) {
            if (!array_key_exists($key, $saved) || !is_scalar($saved[$key]) || !is_numeric((string) $saved[$key]) || !is_finite((float) $saved[$key])) { return $default; }
            $value = (float) $saved[$key];
            if ($integer) { $value = (int) $value; }
            else { $value = round($value, 2); }
            return max($minimum, min($maximum, $value));
        };
        $read_text = static function ($key, $default, $maximum) use ($saved) {
            if (!array_key_exists($key, $saved) || !is_scalar($saved[$key])) { return $default; }
            $value = sanitize_text_field((string) $saved[$key]);
            if (function_exists('mb_strlen') && mb_strlen($value) > $maximum) { $value = mb_substr($value, 0, $maximum); }
            elseif (strlen($value) > $maximum) { $value = substr($value, 0, $maximum); }
            return $value;
        };
        $members_expiry_behavior = isset($saved['members_expiry_behavior']) && is_scalar($saved['members_expiry_behavior']) ? sanitize_key((string) $saved['members_expiry_behavior']) : 'inactive';
        $default_payment_mode = isset($saved['pos_default_payment_mode']) && is_scalar($saved['pos_default_payment_mode']) ? sanitize_key((string) $saved['pos_default_payment_mode']) : 'cash';
        $printer_type = isset($saved['hardware_printer_type']) && is_scalar($saved['hardware_printer_type']) ? sanitize_key((string) $saved['hardware_printer_type']) : 'auto';
        return array(
            'store_name' => isset($saved['store_name']) && is_scalar($saved['store_name']) ? sanitize_text_field((string) $saved['store_name']) : $default_store_name,
            'currency' => in_array($saved_currency, array('toman', 'rial'), true) ? $saved_currency : $currency_default,
            'date_format' => in_array($date_format, array('jalali', 'gregorian'), true) ? $date_format : 'jalali',
            'timezone' => $timezone,
            'pos_discount_limit' => max(0, min(100, round($discount, 2))),
            'pos_allow_price_edit' => !empty($saved['pos_allow_price_edit']),
            'pos_default_payment_mode' => in_array($default_payment_mode, array('cash', 'credit'), true) ? $default_payment_mode : 'cash',
            'pos_auto_barcode_scan' => $read_bool('pos_auto_barcode_scan', true),
            'pos_allow_zero_stock_sale' => $read_bool('pos_allow_zero_stock_sale', false),
            'finance_blind_count_enabled' => $read_bool('finance_blind_count_enabled', true),
            'finance_auto_cogs' => $read_bool('finance_auto_cogs', true),
            'finance_tax_rate' => $read_number('finance_tax_rate', 0, 0, 100, false),
            'members_code_prefix' => $read_text('members_code_prefix', '', 24),
            'members_expiry_behavior' => in_array($members_expiry_behavior, array('warning', 'inactive'), true) ? $members_expiry_behavior : 'inactive',
            'members_allow_login_with_debt' => $read_bool('members_allow_login_with_debt', false),
            'members_max_freeze_days' => $read_number('members_max_freeze_days', 365, 1, 365, true),
            'products_hide_out_of_stock_pos' => $read_bool('products_hide_out_of_stock_pos', false),
            'products_auto_sku' => $read_bool('products_auto_sku', false),
            'hardware_printer_type' => in_array($printer_type, array('auto', 'thermal', 'label', 'a4', 'none'), true) ? $printer_type : 'auto',
            'hardware_auto_print_invoice' => $read_bool('hardware_auto_print_invoice', false),
            'hardware_barcode_prefix' => $read_text('hardware_barcode_prefix', '', 32),
            'hardware_barcode_suffix' => $read_text('hardware_barcode_suffix', '', 32),
            'hardware_local_bridge_port' => $read_number('hardware_local_bridge_port', 8765, 1, 65535, true),
            'security_require_admin_password' => $read_bool('security_require_admin_password', true),
            'security_auto_logout_minutes' => $read_number('security_auto_logout_minutes', 60, 0, 1440, true),
            'security_sync_interval_minutes' => $read_number('security_sync_interval_minutes', 15, 1, 1440, true),
        );
    }

    /** Validate only settings-center fields; they are stored but intentionally not wired to module behavior yet. */
    private static function sanitize_settings_center($input, $settings) {
        if (!is_array($input)) {
            return new WP_Error('wsm_bad_settings_center', 'ساختار تنظیمات مرکز کنترل نامعتبر است.', array('status' => 400));
        }
        $current = self::public_settings_center($settings);
        $clean = $current;
        if (array_key_exists('store_name', $input)) {
            if (!is_scalar($input['store_name'])) { return new WP_Error('wsm_bad_settings_center_store', 'نام فروشگاه نامعتبر است.', array('status' => 400)); }
            $clean['store_name'] = sanitize_text_field((string) $input['store_name']);
            if (function_exists('mb_strlen') && mb_strlen($clean['store_name']) > 120) { $clean['store_name'] = mb_substr($clean['store_name'], 0, 120); }
        }
        if (array_key_exists('currency', $input)) {
            if (!is_scalar($input['currency'])) { return new WP_Error('wsm_bad_settings_center_currency', 'واحد پول نامعتبر است.', array('status' => 400)); }
            $currency = sanitize_key((string) $input['currency']);
            if (!in_array($currency, array('toman', 'rial'), true)) { return new WP_Error('wsm_bad_settings_center_currency', 'واحد پول باید تومان یا ریال باشد.', array('status' => 400)); }
            $clean['currency'] = $currency;
        }
        if (array_key_exists('date_format', $input)) {
            if (!is_scalar($input['date_format'])) { return new WP_Error('wsm_bad_settings_center_date', 'فرمت تاریخ نامعتبر است.', array('status' => 400)); }
            $date_format = sanitize_key((string) $input['date_format']);
            if (!in_array($date_format, array('jalali', 'gregorian'), true)) { return new WP_Error('wsm_bad_settings_center_date', 'فرمت تاریخ باید شمسی یا میلادی باشد.', array('status' => 400)); }
            $clean['date_format'] = $date_format;
        }
        if (array_key_exists('timezone', $input)) {
            if (!is_scalar($input['timezone'])) { return new WP_Error('wsm_bad_settings_center_timezone', 'منطقهٔ زمانی نامعتبر است.', array('status' => 400)); }
            $timezone = trim(sanitize_text_field((string) $input['timezone']));
            $valid_timezones = timezone_identifiers_list(DateTimeZone::ALL_WITH_BC);
            $valid_offset = (bool) preg_match('/^[+-](?:(?:0[0-9]|1[0-3]):[0-5][0-9]|14:00)$/', $timezone);
            if ($timezone === '' || (!in_array($timezone, $valid_timezones, true) && $timezone !== 'UTC' && !$valid_offset)) { return new WP_Error('wsm_bad_settings_center_timezone', 'یک شناسهٔ معتبر منطقهٔ زمانی مانند Asia/Tehran وارد کنید.', array('status' => 400)); }
            $clean['timezone'] = $timezone;
        }
        if (array_key_exists('pos_discount_limit', $input)) {
            if (!is_scalar($input['pos_discount_limit']) || !is_numeric((string) $input['pos_discount_limit']) || !is_finite((float) $input['pos_discount_limit'])) { return new WP_Error('wsm_bad_settings_center_discount', 'حد تخفیف باید عددی باشد.', array('status' => 400)); }
            $discount = (float) $input['pos_discount_limit'];
            if ($discount < 0 || $discount > 100) { return new WP_Error('wsm_bad_settings_center_discount', 'حد تخفیف باید بین صفر تا ۱۰۰ درصد باشد.', array('status' => 400)); }
            $clean['pos_discount_limit'] = round($discount, 2);
        }
        if (array_key_exists('pos_allow_price_edit', $input)) {
            if (!is_scalar($input['pos_allow_price_edit'])) { return new WP_Error('wsm_bad_settings_center_price_edit', 'وضعیت ویرایش قیمت نامعتبر است.', array('status' => 400)); }
            $clean['pos_allow_price_edit'] = rest_sanitize_boolean($input['pos_allow_price_edit']);
        }
        $boolean_fields = array(
            'pos_auto_barcode_scan', 'pos_allow_zero_stock_sale', 'finance_blind_count_enabled', 'finance_auto_cogs',
            'members_allow_login_with_debt', 'products_hide_out_of_stock_pos', 'products_auto_sku',
            'hardware_auto_print_invoice', 'security_require_admin_password',
        );
        foreach ($boolean_fields as $field) {
            if (!array_key_exists($field, $input)) { continue; }
            if (!is_scalar($input[$field])) { return new WP_Error('wsm_bad_settings_center_boolean', 'یکی از گزینه‌های روشن/خاموش نامعتبر است.', array('status' => 400)); }
            $clean[$field] = rest_sanitize_boolean($input[$field]);
        }
        $numeric_fields = array(
            'finance_tax_rate' => array(0, 100, false),
            'members_max_freeze_days' => array(1, 365, true),
            'hardware_local_bridge_port' => array(1, 65535, true),
            'security_auto_logout_minutes' => array(0, 1440, true),
            'security_sync_interval_minutes' => array(1, 1440, true),
        );
        foreach ($numeric_fields as $field => $rule) {
            if (!array_key_exists($field, $input)) { continue; }
            if (!is_scalar($input[$field]) || !is_numeric((string) $input[$field]) || !is_finite((float) $input[$field])) { return new WP_Error('wsm_bad_settings_center_number', 'یکی از مقدارهای عددی تنظیمات معتبر نیست.', array('status' => 400)); }
            $number = (float) $input[$field];
            if ($number < $rule[0] || $number > $rule[1] || ($rule[2] && floor($number) !== $number)) { return new WP_Error('wsm_bad_settings_center_number', 'یکی از مقدارهای عددی تنظیمات خارج از محدودهٔ مجاز است.', array('status' => 400)); }
            $clean[$field] = $rule[2] ? (int) $number : round($number, 2);
        }
        $text_fields = array('members_code_prefix' => 24, 'hardware_barcode_prefix' => 32, 'hardware_barcode_suffix' => 32);
        foreach ($text_fields as $field => $maximum) {
            if (!array_key_exists($field, $input)) { continue; }
            if (!is_scalar($input[$field])) { return new WP_Error('wsm_bad_settings_center_text', 'یکی از متن‌های تنظیمات معتبر نیست.', array('status' => 400)); }
            $value = sanitize_text_field((string) $input[$field]);
            if (function_exists('mb_strlen') && mb_strlen($value) > $maximum) { $value = mb_substr($value, 0, $maximum); }
            elseif (strlen($value) > $maximum) { $value = substr($value, 0, $maximum); }
            $clean[$field] = $value;
        }
        $enum_fields = array(
            'pos_default_payment_mode' => array('cash', 'credit'),
            'members_expiry_behavior' => array('warning', 'inactive'),
            'hardware_printer_type' => array('auto', 'thermal', 'label', 'a4', 'none'),
        );
        foreach ($enum_fields as $field => $allowed) {
            if (!array_key_exists($field, $input)) { continue; }
            if (!is_scalar($input[$field]) || !in_array(sanitize_key((string) $input[$field]), $allowed, true)) { return new WP_Error('wsm_bad_settings_center_choice', 'یکی از انتخاب‌های تنظیمات معتبر نیست.', array('status' => 400)); }
            $clean[$field] = sanitize_key((string) $input[$field]);
        }
        $stored = isset($settings['settings_center']) && is_array($settings['settings_center']) ? $settings['settings_center'] : array();
        // Remove the assistant-added draft field superseded by the requested cash/credit default.
        unset($stored['pos_default_customer_id']);
        $settings['settings_center'] = array_merge($stored, $clean);
        return $settings;
    }

    public static function settings_update($request) {
        $body = self::body($request);
        $device = WSM_Permissions::current_device();
        $wp_admin = $device && WSM_Permissions::is_wordpress_admin_user((int) $device->user_id);
        $security_change = array_key_exists('totp_required', $body);
        if ($security_change && !is_scalar($body['totp_required'])) {
            return new WP_Error('wsm_bad_totp_setting', 'مقدار الزام TOTP نامعتبر است.', array('status' => 400));
        }
        $settings_keys = array('expiry_alert_days', 'expiry_alert_email', 'expiry_alert_sms', 'expiry_alert_email_to', 'expiry_meta_key', 'ui_config', 'postal_sender', 'settings_center', 'low_stock_threshold');
        $settings_change = false;
        foreach ($settings_keys as $key) {
            if (array_key_exists($key, $body)) {
                $settings_change = true;
                break;
            }
        }
        if ($settings_change && !$wp_admin && !WSM_Devices::device_can($device, 'settings.edit')) {
            return new WP_Error('wsm_device_permission_denied', 'این دستگاه مجوز ویرایش تنظیمات عمومی را ندارد.', array('status' => 403));
        }
        if ($security_change && !$wp_admin && !WSM_Devices::device_can($device, 'security.manage')) {
            return new WP_Error('wsm_device_permission_denied', 'این دستگاه مجوز مدیریت امنیت را ندارد.', array('status' => 403));
        }
        $security_enabled = $security_change ? rest_sanitize_boolean($body['totp_required']) : false;
        $low_stock_threshold = null;
        if (array_key_exists('low_stock_threshold', $body)) {
            if (!is_scalar($body['low_stock_threshold']) || !is_numeric((string) $body['low_stock_threshold']) || !is_finite((float) $body['low_stock_threshold'])) {
                return new WP_Error('wsm_bad_low_stock_threshold', 'آستانهٔ موجودی کم باید یک عدد صحیح باشد.', array('status' => 400));
            }
            $threshold = (float) $body['low_stock_threshold'];
            if ($threshold < 0 || $threshold > 1000000 || floor($threshold) !== $threshold) {
                return new WP_Error('wsm_bad_low_stock_threshold', 'آستانهٔ موجودی کم باید عددی صحیح بین صفر تا یک میلیون باشد.', array('status' => 400));
            }
            $low_stock_threshold = (int) $threshold;
        }
        if ($security_enabled && !WSM_Crypto::available()) {
            return new WP_Error('wsm_totp_crypto_unavailable', 'برای فعال‌کردن TOTP، افزونهٔ OpenSSL در PHP باید در دسترس باشد.', array('status' => 500));
        }
        $settings = WSM_Util::settings();
        if ($security_change) {
            $settings['totp_required'] = $security_enabled ? 1 : 0;
        }
        if (array_key_exists('expiry_alert_days', $body)) {
            if (!is_scalar($body['expiry_alert_days']) || !is_numeric((string) $body['expiry_alert_days']) || !is_finite((float) $body['expiry_alert_days'])) {
                return new WP_Error('wsm_bad_expiry_days', 'تعداد روز هشدار انقضا باید عددی باشد.', array('status' => 400));
            }
            $settings['expiry_alert_days'] = max(1, min(365, (int) $body['expiry_alert_days']));
        }
        if (array_key_exists('expiry_alert_email', $body)) {
            if (!is_scalar($body['expiry_alert_email'])) {
                return new WP_Error('wsm_bad_expiry_email_flag', 'تنظیم هشدار ایمیل نامعتبر است.', array('status' => 400));
            }
            $settings['expiry_alert_email'] = rest_sanitize_boolean($body['expiry_alert_email']) ? 1 : 0;
        }
        if (array_key_exists('expiry_alert_sms', $body)) {
            if (!is_scalar($body['expiry_alert_sms'])) {
                return new WP_Error('wsm_bad_expiry_sms_flag', 'تنظیم هشدار پیامک نامعتبر است.', array('status' => 400));
            }
            $settings['expiry_alert_sms'] = rest_sanitize_boolean($body['expiry_alert_sms']) ? 1 : 0;
        }
        if (array_key_exists('expiry_alert_email_to', $body)) {
            if (!is_scalar($body['expiry_alert_email_to'])) {
                return new WP_Error('wsm_bad_expiry_email', 'نشانی ایمیل هشدار معتبر نیست.', array('status' => 400));
            }
            $email = sanitize_email((string) $body['expiry_alert_email_to']);
            if ($email !== '' && !is_email($email)) {
                return new WP_Error('wsm_bad_expiry_email', 'نشانی ایمیل هشدار معتبر نیست.', array('status' => 400));
            }
            $settings['expiry_alert_email_to'] = $email !== '' ? $email : get_option('admin_email');
        }
        if (array_key_exists('expiry_meta_key', $body)) {
            if (!is_scalar($body['expiry_meta_key'])) {
                return new WP_Error('wsm_bad_expiry_meta_key', 'کلید متای سفارشی نامعتبر است.', array('status' => 400));
            }
            $key = trim(sanitize_text_field((string) $body['expiry_meta_key']));
            if ($key !== '' && !preg_match('/^[A-Za-z0-9_:\-.]{1,120}$/', $key)) {
                return new WP_Error('wsm_bad_expiry_meta_key', 'کلید متای سفارشی نامعتبر است.', array('status' => 400));
            }
            $settings['expiry_meta_key'] = $key;
            WSM_Expiry::invalidate_detection();
        }
        if (array_key_exists('ui_config', $body)) {
            if (!is_array($body['ui_config'])) {
                return new WP_Error('wsm_bad_ui_config', 'تنظیمات رابط کاربری نامعتبر است.', array('status' => 400));
            }
            $settings['ui_config'] = WSM_Util::clean_ui_config($body['ui_config']);
        }
        if (array_key_exists('settings_center', $body)) {
            $center_settings = self::sanitize_settings_center($body['settings_center'], $settings);
            if (is_wp_error($center_settings)) { return $center_settings; }
            $settings = $center_settings;
        }
        if (array_key_exists('postal_sender', $body) && !is_array($body['postal_sender'])) {
            return new WP_Error('wsm_bad_sender', 'مشخصات فرستنده نامعتبر است.', array('status' => 400));
        }
        if (isset($body['postal_sender']) && is_array($body['postal_sender'])) {
            foreach (array('name', 'company', 'phone', 'email', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country') as $sender_field) {
                if (!array_key_exists($sender_field, $body['postal_sender'])) {
                    continue;
                }
                $setting_key = 'postal_sender_' . $sender_field;
                if (!is_scalar($body['postal_sender'][$sender_field]) && $body['postal_sender'][$sender_field] !== null) {
                    return new WP_Error('wsm_bad_sender', 'یکی از مشخصات فرستنده نامعتبر است.', array('status' => 400));
                }
                $sender_value = is_scalar($body['postal_sender'][$sender_field]) ? (string) $body['postal_sender'][$sender_field] : '';
                if ($sender_field === 'email') {
                    $sender_email = sanitize_email($sender_value);
                    if ($sender_email !== '' && !is_email($sender_email)) {
                        return new WP_Error('wsm_bad_sender_email', 'ایمیل فرستنده معتبر نیست.', array('status' => 400));
                    }
                    $settings[$setting_key] = $sender_email;
                } elseif (in_array($sender_field, array('address_1', 'address_2'), true)) {
                    $settings[$setting_key] = sanitize_textarea_field($sender_value);
                } else {
                    $settings[$setting_key] = sanitize_text_field($sender_value);
                }
            }
        }
        $updated = update_option('wsm_settings', $settings, false);
        if (!$updated && get_option('wsm_settings', array()) !== $settings) {
            return new WP_Error('wsm_settings_save_failed', 'ذخیرهٔ تنظیمات در پایگاه داده انجام نشد.', array('status' => 500));
        }
        if ($low_stock_threshold !== null) {
            update_option('woocommerce_notify_low_stock_amount', $low_stock_threshold);
            if ((int) get_option('woocommerce_notify_low_stock_amount', 2) !== $low_stock_threshold) {
                return new WP_Error('wsm_low_stock_threshold_save_failed', 'ذخیرهٔ آستانهٔ موجودی کم در تنظیمات WooCommerce انجام نشد.', array('status' => 500));
            }
        }
        if ($security_change) {
            WSM_Security::log('totp_policy_changed', 'warning', (int) $device->id, 'الزام TOTP پنل افزونه از اپ تغییر کرد.');
        }
        WSM_Activity_Log::log('update', 'settings', 0, 'تنظیمات اپ و برچسب پستی', '', 'ذخیره شد', 'success');
        return self::settings();
    }

    /* ---------------- داشبورد و گزارش ---------------- */

    public static function security_get() {
        $device = WSM_Permissions::current_device();
        if (!WSM_Devices::device_can($device, 'users.super_admin.assign')) { return new WP_Error('wsm_security_forbidden', 'فقط مدیر کل می‌تواند تنظیمات امنیت را مشاهده کند.', array('status' => 403)); }
        global $wpdb;
        $settings = WSM_Util::settings();
        $policy = get_option('wsm_security_policy', array());
        if (!is_array($policy)) { $policy = array(); }
        $policy = array_merge(array('max_attempts' => 5, 'window_minutes' => 15, 'lock_minutes' => 30), $policy);
        $rows = $wpdb->get_results('SELECT event,severity,device_id,ip_address,user_agent,details,created_at FROM ' . WSM_Security::table('security_log') . ' ORDER BY id DESC LIMIT 50', ARRAY_A);
        foreach ((array) $rows as &$row) { $row['created_at_fa'] = wsm_fa_date($row['created_at']); }
        unset($row);
        return array(
            'token_ttl_days' => max(1, min(365, (int) $settings['token_ttl_days'])),
            'key_ttl_days' => max(1, min(365, (int) $settings['key_ttl_days'])),
            'totp_required' => !empty($settings['totp_required']),
            'max_attempts' => max(1, min(20, (int) $policy['max_attempts'])),
            'window_minutes' => max(1, min(1440, (int) $policy['window_minutes'])),
            'lock_minutes' => max(1, min(1440, (int) $policy['lock_minutes'])),
            'auto_approve_devices' => (bool) get_option('wsm_auto_approve_devices', 1),
            'events' => (array) $rows,
        );
    }

    public static function security_update($request) {
        $device = WSM_Permissions::current_device();
        if (!WSM_Devices::device_can($device, 'users.super_admin.assign')) { return new WP_Error('wsm_security_forbidden', 'فقط مدیر کل می‌تواند سیاست امنیت را تغییر دهد.', array('status' => 403)); }
        $body = self::body($request);
        $settings = WSM_Util::settings();
        $changed = false;
        foreach (array('token_ttl_days' => array(1, 365), 'key_ttl_days' => array(1, 365)) as $key => $bounds) {
            if (!array_key_exists($key, $body)) { continue; }
            if (!is_scalar($body[$key])) { return new WP_Error('wsm_security_value_invalid', 'مقدار سیاست امنیت معتبر نیست.', array('status' => 400)); }
            $settings[$key] = max($bounds[0], min($bounds[1], (int) $body[$key]));
            $changed = true;
        }
        if (array_key_exists('totp_required', $body)) {
            if (!is_scalar($body['totp_required']) || !WSM_Devices::device_can($device, 'security.manage')) { return new WP_Error('wsm_totp_admin_only', 'تغییر الزام TOTP فقط برای مدیر کل وردپرس مجاز است.', array('status' => 403)); }
            $totp = rest_sanitize_boolean($body['totp_required']);
            if ($totp && !WSM_Crypto::available()) { return new WP_Error('wsm_totp_crypto_unavailable', 'برای فعال‌کردن TOTP، افزونهٔ OpenSSL در PHP باید در دسترس باشد.', array('status' => 500)); }
            $settings['totp_required'] = $totp ? 1 : 0;
            $changed = true;
        }
        if ($changed) { update_option('wsm_settings', $settings, false); }
        $policy = get_option('wsm_security_policy', array());
        if (!is_array($policy)) { $policy = array(); }
        $policy = array_merge(array('max_attempts' => 5, 'window_minutes' => 15, 'lock_minutes' => 30), $policy);
        $policy_changed = false;
        foreach (array('max_attempts' => array(1, 20), 'window_minutes' => array(1, 1440), 'lock_minutes' => array(1, 1440)) as $key => $bounds) {
            if (!array_key_exists($key, $body)) { continue; }
            if (!is_scalar($body[$key])) { return new WP_Error('wsm_security_value_invalid', 'مقدار سیاست امنیت معتبر نیست.', array('status' => 400)); }
            $policy[$key] = max($bounds[0], min($bounds[1], (int) $body[$key]));
            $policy_changed = true;
        }
        if ($policy_changed) { update_option('wsm_security_policy', $policy, false); }
        if (array_key_exists('auto_approve_devices', $body)) {
            if (!is_scalar($body['auto_approve_devices'])) { return new WP_Error('wsm_security_value_invalid', 'مقدار تأیید خودکار معتبر نیست.', array('status' => 400)); }
            update_option('wsm_auto_approve_devices', rest_sanitize_boolean($body['auto_approve_devices']) ? 1 : 0, false);
        }
        WSM_Security::log('security_policy_changed', 'warning', (int) $device->id, 'سیاست امنیت اپ از طریق تنظیمات مدیرکل تغییر کرد.');
        return self::security_get();
    }

    public static function dashboard() {
        $cache_key = 'wsm_api_dashboard_v6_' . md5(WSM_Reports::sales_cache_epoch());
        return self::cached_stats($cache_key, array('WSM_Reports', 'dashboard'), 60);
    }

    public static function report_sales($request) {
        return WSM_Reports::sales(self::scalar_param($request, 'from'), self::scalar_param($request, 'to'));
    }

    public static function report_stock() {
        return WSM_Reports::stock();
    }

    /* ---------------- محصولات ---------------- */

    public static function products_list($request) {
        $params = $request->get_params();
        $rate_limit = self::check_rest_read_rate_limit('products', WSM_Permissions::current_device_id());
        if (is_wp_error($rate_limit)) { return $rate_limit; }
        // POS uses a dedicated 50-row lightweight WooCommerce query and 15-minute transient.
        if (isset($params['pos']) && rest_sanitize_boolean($params['pos'])) {
            return WSM_Products::pos_list($params);
        }
        // Avoid creating a transient for every arbitrary search string or unbounded page.
        $search = isset($params['search']) ? trim((string) $params['search']) : '';
        $page = isset($params['page']) ? absint($params['page']) : 1;
        $per_page = isset($params['per_page']) ? absint($params['per_page']) : 20;
        if ($search !== '' || $page > 10 || $per_page > 50) {
            return WSM_Products::list($params);
        }
        return self::cached_rest_get('products', '/products', $params, function () use ($params) {
            return WSM_Products::list($params);
        }, true);
    }

    public static function products_create($request) {
        return WSM_Products::create(self::body($request));
    }

    public static function products_barcode($request) {
        return WSM_Products::generate_barcode(self::body($request));
    }

    public static function products_expiring($request) {
        $per_page = (int) $request->get_param('per_page');
        $page = (int) $request->get_param('page');
        $per_page = $per_page > 0 ? min(50, $per_page) : 5;
        $page = $page > 0 ? min(10, $page) : 1;
        $params = array('per_page' => $per_page, 'page' => $page);
        return self::cached_rest_get('products', '/products/expiring', $params, function () use ($per_page, $page) {
            return WSM_Expiry::list($per_page, $page);
        });
    }

    public static function categories_list() {
        return self::cached_rest_get('products', '/categories', array(), function () {
            return self::categories_list_uncached();
        });
    }

    private static function categories_list_uncached() {
        if (!taxonomy_exists('product_cat')) {
            return array('items' => array());
        }
        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'term_order',
            'order'      => 'ASC',
        ));
        if (is_wp_error($terms) || !$terms) {
            return array('items' => array());
        }
        $parents = array();
        foreach ($terms as $term) {
            $parents[(int) $term->term_id] = (int) $term->parent;
        }
        $items = array();
        foreach ($terms as $term) {
            $level = 0;
            $parent = (int) $term->parent;
            while ($parent && isset($parents[$parent]) && $level < 8) {
                $level++;
                $parent = $parents[$parent];
            }
            $items[] = array(
                'id'     => (int) $term->term_id,
                'name'   => (string) $term->name,
                'slug'   => (string) $term->slug,
                'parent' => (int) $term->parent,
                'level'  => $level,
                'count'  => (int) $term->count,
            );
        }
        return array('items' => $items);
    }

    public static function products_get($request) {
        $id = self::id($request);
        return self::cached_rest_get('products', '/products/' . $id, array('id' => $id), function () use ($id) {
            return WSM_Products::get($id);
        });
    }

    public static function products_update($request) {
        return WSM_Products::update(self::id($request), self::body($request));
    }

    public static function products_delete($request) {
        return WSM_Products::delete(self::id($request));
    }

    public static function products_copy($request) {
        return WSM_Products::copy(self::id($request));
    }

    public static function products_image($request) {
        return WSM_Products::upload_image(self::id($request), $request->get_file_params());
    }

    /* ---------------- سفارش‌ها ---------------- */

    public static function orders_list($request) {
        return WSM_Orders::list($request->get_params());
    }

    public static function orders_manual($request) {
        return WSM_Orders::create_manual(self::body($request));
    }

    public static function orders_get($request) {
        return WSM_Orders::get(self::id($request));
    }

    public static function orders_update($request) {
        $id = self::id($request);
        $body = self::body($request);
        $device = WSM_Permissions::current_device();
        if (array_key_exists('status', $body)) {
            if (!is_scalar($body['status'])) {
                return new WP_Error('wsm_bad_status', 'وضعیت سفارش نامعتبر است.', array('status' => 400));
            }
            $order = wc_get_order($id);
            if ($order) {
                $next = sanitize_key((string) $body['status']);
                if (strpos($next, 'wc-') === 0) {
                    $next = substr($next, 3);
                }
                $changed = $next !== $order->get_status();
                if ($changed && !WSM_Devices::device_can($device, 'orders.change_status')) {
                    return new WP_Error('wsm_device_permission_denied', 'این دستگاه مجوز تغییر وضعیت سفارش را ندارد.', array('status' => 403));
                }
                if ($changed && !absint($order->get_meta('_wsm_linked_invoice_id')) && !WSM_Devices::device_can($device, 'invoices.create')) {
                    return new WP_Error('wsm_device_permission_denied', 'تغییر این وضعیت به‌طور خودکار فاکتور می‌سازد؛ مجوز ایجاد فاکتور لازم است.', array('status' => 403));
                }
            }
        }
        return WSM_Orders::update($id, $body);
    }

    public static function orders_delete($request) {
        return WSM_Orders::delete(self::id($request));
    }

    public static function orders_invoice($request) {
        return WSM_Invoices::create_from_order(self::id($request));
    }

    public static function orders_status($request) {
        $b = self::body($request);
        $id = self::id($request);
        $order = wc_get_order($id);
        if (array_key_exists('status', $b) && !is_scalar($b['status'])) {
            return new WP_Error('wsm_bad_status', 'وضعیت سفارش نامعتبر است.', array('status' => 400));
        }
        if ($order && isset($b['status'])) {
            $next = sanitize_key((string) $b['status']);
            if (strpos($next, 'wc-') === 0) {
                $next = substr($next, 3);
            }
            if ($next !== $order->get_status() && !absint($order->get_meta('_wsm_linked_invoice_id')) && !WSM_Devices::device_can(WSM_Permissions::current_device(), 'invoices.create')) {
                return new WP_Error('wsm_device_permission_denied', 'تغییر این وضعیت به‌طور خودکار فاکتور می‌سازد؛ مجوز ایجاد فاکتور لازم است.', array('status' => 403));
            }
        }
        return WSM_Orders::set_status($id, isset($b['status']) ? $b['status'] : '', isset($b['note']) ? $b['note'] : '');
    }

    public static function orders_refund($request) {
        return WSM_Club_Operations::refund_order(self::id($request), self::body($request));
    }

    public static function orders_history($request) {
        return WSM_Orders::history(self::id($request));
    }

    /* ---------------- فاکتورها ---------------- */

    public static function invoices_list($request) {
        return WSM_Invoices::list($request->get_params());
    }

    public static function invoices_create($request) {
        return WSM_Invoices::create(self::body($request));
    }

    public static function invoices_get($request) {
        return WSM_Invoices::get(self::id($request));
    }

    public static function invoices_update($request) {
        $id = self::id($request);
        $body = self::body($request);
        if (array_key_exists('status', $body)) {
            if (!is_scalar($body['status'])) {
                return new WP_Error('wsm_bad_status', 'وضعیت فاکتور نامعتبر است.', array('status' => 400));
            }
            $invoice = wc_get_order($id);
            if ($invoice && $invoice->get_meta('_wsm_invoice')) {
                $formatted = WSM_Invoices::format($invoice);
                $current = isset($formatted['status']) ? sanitize_key((string) $formatted['status']) : '';
                $next = sanitize_key((string) $body['status']);
                if ($next !== $current && !WSM_Devices::device_can(WSM_Permissions::current_device(), 'invoices.change_status')) {
                    return new WP_Error('wsm_device_permission_denied', 'این دستگاه مجوز تغییر وضعیت فاکتور را ندارد.', array('status' => 403));
                }
            }
        }
        return WSM_Invoices::update($id, $body);
    }

    public static function invoices_delete($request) {
        return WSM_Invoices::delete(self::id($request));
    }

    public static function invoices_status($request) {
        $b = self::body($request);
        if (isset($b['status']) && !is_scalar($b['status'])) {
            return new WP_Error('wsm_bad_status', 'وضعیت فاکتور نامعتبر است.', array('status' => 400));
        }
        return WSM_Invoices::set_status(self::id($request), isset($b['status']) ? (string) $b['status'] : '');
    }

    public static function invoices_copy($request) {
        return WSM_Invoices::copy(self::id($request));
    }

    public static function invoices_html($request) {
        $html = WSM_Invoices::render_html(self::id($request));
        if (is_wp_error($html)) {
            return $html;
        }
        return array('html' => $html);
    }

    public static function invoices_label($request) {
        $html = WSM_Invoices::render_label(self::id($request));
        if (is_wp_error($html)) {
            return $html;
        }
        return array('html' => $html);
    }

    /* ---------------- مشتریان، دستگاه‌ها، فعالیت ---------------- */

    public static function customers_list($request) {
        return WSM_Customers::list($request->get_params());
    }

    public static function customers_get($request) {
        return WSM_Customers::get(self::id($request), $request->get_params());
    }

    public static function store_customers_list($request) {
        return WSM_Customers::accounts_list($request->get_params());
    }

    public static function store_customers_create($request) {
        return WSM_Customers::account_create(self::body($request));
    }

    public static function store_customer_get($request) {
        return WSM_Customers::account_get(self::id($request));
    }

    public static function store_customer_update($request) {
        return WSM_Customers::account_update(self::id($request), self::body($request));
    }

    public static function store_customer_payment($request) {
        return WSM_Customers::collect_payment(self::id($request), self::body($request));
    }

    public static function devices_list($request) {
        $rows = WSM_Devices::query(array(
            'status' => self::scalar_param($request, 'status'),
            'search' => WSM_Util::clean_text(self::scalar_param($request, 'search'), 100),
            'limit'  => (int) $request->get_param('limit') ?: 100,
        ));
        return array('items' => array_map(array('WSM_Devices', 'format'), $rows), 'counts' => WSM_Devices::count_by_status());
    }

    public static function devices_status($request) {
        $b = self::body($request);
        $action = isset($b['action']) && is_scalar($b['action']) ? (string) $b['action'] : '';
        $reason = isset($b['reason']) && is_scalar($b['reason']) ? (string) $b['reason'] : '';
        $needed = array('approve' => 'devices.approve', 'block' => 'devices.block', 'unblock' => 'devices.block', 'unlink' => 'devices.unlink', 'delete' => 'devices.delete');
        $device = WSM_Permissions::current_device();
        if (!isset($needed[$action])) { return new WP_Error('wsm_bad_action', 'عملیات نامعتبر است.', array('status' => 400)); }
        if (!WSM_Devices::device_can($device, 'devices.manage') && !WSM_Devices::device_can($device, $needed[$action])) {
            return new WP_Error('wsm_device_permission_denied', 'برای این عملیات مجوز اختصاصی دستگاه لازم است.', array('status' => 403, 'permission' => $needed[$action]));
        }
        $result = WSM_Devices::set_status(self::id($request), $action, $reason);
        return is_wp_error($result) ? $result : WSM_Devices::format($result);
    }

    /* ---------------- باشگاه ---------------- */

    public static function club_overview() {
        return self::cached_stats('wsm_api_club_overview_v2', array('WSM_Club', 'overview'));
    }

    public static function club_members($request) {
        return WSM_Club::list_members($request->get_params());
    }

    public static function club_member_create($request) {
        return WSM_Club::save_member(self::body($request));
    }

    public static function club_member_get($request) {
        return WSM_Club::get_member(self::id($request));
    }

    public static function club_member_update($request) {
        return WSM_Club::save_member(self::body($request), self::id($request));
    }

    public static function club_member_archive($request) {
        return WSM_Club::archive_member(self::id($request));
    }

    public static function club_plans($request) {
        return WSM_Club::list_plans(!empty($request->get_param('all')));
    }

    public static function club_plan_create($request) {
        return WSM_Club::save_plan(self::body($request));
    }

    public static function club_plan_update($request) {
        return WSM_Club::save_plan(self::body($request), self::id($request));
    }

    public static function club_plan_archive($request) {
        return WSM_Club::archive_plan(self::id($request));
    }

    public static function club_memberships($request) {
        return WSM_Club::list_memberships($request->get_params());
    }

    public static function club_membership_create($request) {
        return WSM_Club::create_membership(self::body($request));
    }

    public static function club_payments($request) {
        return WSM_Club::list_payments($request->get_params());
    }

    public static function club_payment_create($request) {
        return WSM_Club::record_payment(self::body($request));
    }

    public static function club_attendance($request) {
        return WSM_Club::list_attendance($request->get_params());
    }

    public static function club_attendance_create($request) {
        return WSM_Club::checkin(self::body($request));
    }

    public static function club_staff($request) {
        return WSM_Club::list_staff($request->get_params());
    }

    public static function club_staff_create($request) {
        $result = WSM_Club::save_staff(self::body($request));
        return is_wp_error($result) ? $result : WSM_Club::list_staff(array('per_page' => 100));
    }

    public static function club_staff_update($request) {
        $result = WSM_Club::save_staff(self::body($request), self::id($request));
        return is_wp_error($result) ? $result : WSM_Club::list_staff(array('per_page' => 100));
    }

    public static function club_payroll($request) {
        return WSM_Club::list_payroll($request->get_params());
    }

    public static function club_payroll_create($request) {
        return WSM_Club::create_payroll(self::body($request));
    }

    public static function club_payroll_paid($request) {
        return WSM_Club::mark_payroll_paid(self::id($request), self::body($request));
    }

    public static function club_payroll_accounts($request) {
        return WSM_Club_Operations::payroll_bank_accounts();
    }

    public static function club_membership_freeze($request) {
        return WSM_Club_Operations::freeze_membership(self::id($request), self::body($request));
    }

    public static function club_membership_cancel($request) {
        return WSM_Club_Operations::cancel_membership(self::id($request), self::body($request));
    }

    private static function club_entity_list($kind, $request) {
        return WSM_Club_Operations::list_entities($kind, $request->get_params());
    }

    private static function club_entity_save($kind, $request, $id = 0) {
        return WSM_Club_Operations::save_entity($kind, self::body($request), $id);
    }

    public static function club_collections($request) { return self::club_entity_list('collections', $request); }
    public static function club_collections_create($request) { return self::club_entity_save('collections', $request); }
    public static function club_collections_update($request) { return self::club_entity_save('collections', $request, self::id($request)); }
    public static function club_sports($request) { return self::club_entity_list('sports', $request); }
    public static function club_sports_create($request) { return self::club_entity_save('sports', $request); }
    public static function club_sports_update($request) { return self::club_entity_save('sports', $request, self::id($request)); }
    public static function club_phonebook($request) { return self::club_entity_list('phonebook', $request); }
    public static function club_phonebook_create($request) { return self::club_entity_save('phonebook', $request); }
    public static function club_phonebook_update($request) { return self::club_entity_save('phonebook', $request, self::id($request)); }
    public static function club_phonebook_sms_send($request) {
        $can_view_phonebook = WSM_Permissions::has_current_permission('club.members.view') ||
            WSM_Permissions::has_current_permission('club.directory.view') ||
            WSM_Permissions::has_current_permission('club.directory.manage') ||
            WSM_Permissions::has_current_permission('club.phonebook.view') ||
            WSM_Permissions::has_current_permission('club.phonebook.manage');
        if (!$can_view_phonebook) {
            return new WP_Error('wsm_phonebook_sms_forbidden', 'برای ارسال از دفتر تلفن، مجوز مشاهدهٔ مخاطبان لازم است.', array('status' => 403));
        }
        $can_send_bulk = WSM_Permissions::has_current_permission('club.sms.bulk_send') ||
            WSM_Permissions::has_current_permission('club.sms.manage');
        if (!$can_send_bulk) {
            return new WP_Error('wsm_phonebook_sms_forbidden', 'مجوز ارسال گروهی پیامک ندارید.', array('status' => 403));
        }
        return WSM_Club_Operations::send_phonebook_sms(self::body($request));
    }
    public static function club_finance($request) {
        $result = WSM_Club_Operations::list_finance($request->get_params());
        if (is_wp_error($result) || !is_array($result)) { return $result; }
        $unique_rows = static function ($rows, $key = 'id') {
            if (!is_array($rows)) { return array(); }
            $out = array();
            $seen = array();
            foreach ($rows as $row) {
                if (!is_array($row)) { continue; }
                if (!isset($row[$key]) || !is_scalar($row[$key]) || (string) $row[$key] === '') { $out[] = $row; continue; }
                $id = (string) $row[$key];
                if (isset($seen[$id])) { continue; }
                $seen[$id] = true;
                $out[] = $row;
            }
            return $out;
        };
        foreach (array('items', 'accounts', 'cash_sessions', 'daily_reconciliation') as $key) {
            if (isset($result[$key])) { $result[$key] = $unique_rows($result[$key]); }
        }
        foreach (array('receivables', 'payables') as $key) {
            if (isset($result[$key]) && is_array($result[$key]) && isset($result[$key]['items'])) {
                $result[$key]['items'] = $unique_rows($result[$key]['items']);
            }
        }
        if (isset($result['finance_controls']) && is_array($result['finance_controls'])) {
            foreach (array('devices', 'gateways') as $key) {
                if (isset($result['finance_controls'][$key])) { $result['finance_controls'][$key] = $unique_rows($result['finance_controls'][$key]); }
            }
        }
        return $result;
    }
    public static function club_finance_void($request) { return WSM_Club_Operations::void_finance(self::id($request), self::body($request)); }
    public static function club_expense_create($request) { return WSM_Club_Operations::create_expense(self::body($request), 'expense'); }
    public static function club_cash_removal($request) { return WSM_Club_Operations::create_expense(self::body($request), 'cash_removal'); }
    public static function club_card_recharge($request) { return WSM_Club_Operations::recharge_card(self::body($request)); }
    public static function club_pos_create($request) {
        $body = self::body($request);
        $device = WSM_Permissions::current_device();
        $quick_action = isset($body['quick_access_action']) && is_scalar($body['quick_access_action']) ? sanitize_key((string) $body['quick_access_action']) : '';
        if ($quick_action !== '') {
            if (!in_array($quick_action, array('get', 'save', 'authorize_discount'), true)) {
                return new WP_Error('wsm_pos_quick_access_action_invalid', 'درخواست دسترسی سریع نامعتبر است.', array('status' => 400));
            }
            if ($quick_action === 'authorize_discount' && !WSM_Devices::device_can($device, 'club.pos.create')) {
                return new WP_Error('wsm_device_permission_denied', 'برای تأیید فروش تخفیف‌دار، مجوز ایجاد فروش POS لازم است.', array('status' => 403));
            }
            if ($quick_action === 'save' && !WSM_Devices::device_can($device, 'club.pos.quick_access.manage') && !WSM_Devices::device_can($device, 'club.settings.manage')) {
                return new WP_Error('wsm_device_permission_denied', 'برای مدیریت دسترسی سریع، مجوز باشگاه لازم است.', array('status' => 403));
            }
            if ($quick_action === 'get' && !WSM_Devices::device_can($device, 'club.pos.create') && !WSM_Devices::device_can($device, 'club.pos.quick_access.manage') && !WSM_Devices::device_can($device, 'club.settings.manage')) {
                return new WP_Error('wsm_device_permission_denied', 'برای مشاهدهٔ دسترسی سریع، مجوز POS لازم است.', array('status' => 403));
            }
            return WSM_Club_Operations::create_pos_order($body);
        }
        if (!WSM_Devices::device_can($device, 'club.pos.create')) {
            return new WP_Error('wsm_device_permission_denied', 'برای ثبت فروش، مجوز club.pos.create لازم است.', array('status' => 403));
        }
        if (isset($body['create_invoice']) && rest_sanitize_boolean($body['create_invoice']) && !WSM_Devices::device_can($device, 'invoices.create')) {
            return new WP_Error('wsm_device_permission_denied', 'برای صدور فاکتور، مجوز invoices.create لازم است.', array('status' => 403));
        }
        $result = WSM_Club_Operations::create_pos_order($body);
        if (!is_wp_error($result)) {
            self::invalidate_rest_product_cache();
        }
        return $result;
    }
    public static function club_reports($request) {
        global $wpdb;

        $diagnostic_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('wsm-report-', true);
        $params = array();
        $report_type = 'unknown';
        $base_buffer_level = ob_get_level();
        $captured_output = '';
        $diagnostics = array();
        $diagnostic_count = 0;
        $exception_details = '';
        $result = null;

        ob_start();
        set_error_handler(function ($severity, $message, $file, $line) use (&$diagnostics, &$diagnostic_count) {
            if (!(error_reporting() & $severity)) { return false; }
            $safe_message = str_replace(array("\r", "\n", "\t"), ' ', (string) $message);
            $diagnostic_count++;
            if (count($diagnostics) < 25) {
                $diagnostics[] = array(
                    'file' => basename((string) $file),
                    'line' => (int) $line,
                    'severity' => (int) $severity,
                    'message' => substr($safe_message, 0, 500),
                );
            }
            return true;
        }, E_ALL);

        try {
            $params = is_object($request) && method_exists($request, 'get_params') ? $request->get_params() : array();
            $params = is_array($params) ? $params : array();
            $report_type = isset($params['type']) && is_scalar($params['type']) ? sanitize_key((string) $params['type']) : 'debtors';

            $date_error = null;
            foreach (array('from', 'to') as $date_key) {
                if (!array_key_exists($date_key, $params)) { continue; }
                if (!class_exists('WSM_Reports') || !is_callable(array('WSM_Reports', 'normalize_report_date'))) {
                    $date_error = new WP_Error('wsm_club_report_date_service', 'سرویس تبدیل تاریخ گزارش در دسترس نیست.', array('status' => 503));
                    break;
                }
                $normalized_date = WSM_Reports::normalize_report_date($params[$date_key]);
                if ($normalized_date === false) {
                    $date_error = new WP_Error('wsm_club_report_date_invalid', 'تاریخ گزارش باید شمسی یا میلادی معتبر باشد.', array('status' => 400));
                    break;
                }
                if ($normalized_date === '') { unset($params[$date_key]); }
                else { $params[$date_key] = $normalized_date; }
            }

            if (is_wp_error($date_error)) {
                $result = $date_error;
            } else {
                // Isolate any SQL error generated by this report from earlier requests/hooks.
                if (isset($wpdb->last_error)) { $wpdb->last_error = ''; }
                $result = WSM_Club_Operations::report(is_array($params) ? $params : array());
            }

            // Keep response normalization inside the guarded/output-buffered block too.
            if (!is_wp_error($result)) {
                if (!is_array($result)) {
                    $result = new WP_Error('wsm_club_report_invalid_payload', 'ساختار پاسخ گزارش معتبر نیست.', array('status' => 500));
                } elseif (!isset($result['items']) || !is_array($result['items'])) {
                    $result = new WP_Error('wsm_club_report_invalid_items', 'فهرست ردیف‌های گزارش معتبر نیست.', array('status' => 500));
                } else {
                    $result['items'] = array_values($result['items']);
                    $result['summary'] = isset($result['summary']) && is_array($result['summary']) ? $result['summary'] : array();
                    $result['charts'] = isset($result['charts']) && is_array($result['charts']) ? $result['charts'] : array();
                    if ($report_type === 'overview') {
                        foreach (array('trend', 'finance', 'attendance', 'trainers') as $chart_key) {
                            if (!isset($result['charts'][$chart_key]) || !is_array($result['charts'][$chart_key])) { $result['charts'][$chart_key] = array(); }
                        }
                    }
                    $result['count'] = isset($result['count']) && is_numeric($result['count']) ? max(0, (int) $result['count']) : count($result['items']);
                }
            }
        } catch (\Throwable $exception) {
            $exception_message = str_replace(array("\r", "\n", "\t"), ' ', (string) $exception->getMessage());
            $exception_details = get_class($exception) . ': ' . substr($exception_message, 0, 1000)
                . ' at ' . basename((string) $exception->getFile()) . ':' . (int) $exception->getLine();
            $result = new WP_Error('wsm_club_report_exception', 'تهیهٔ گزارش با خطای داخلی مواجه شد؛ گزارش خطای سرور را بررسی کنید.', array('status' => 500));
        } finally {
            restore_error_handler();
            while (ob_get_level() > $base_buffer_level) {
                $part = ob_get_clean();
                if (is_string($part)) { $captured_output = $part . $captured_output; }
            }
        }

        $log_from = isset($params['from']) && is_scalar($params['from']) ? str_replace(array("\r", "\n", "\t"), ' ', (string) $params['from']) : '';
        $log_to = isset($params['to']) && is_scalar($params['to']) ? str_replace(array("\r", "\n", "\t"), ' ', (string) $params['to']) : '';
        $request_context = 'type=' . $report_type . ' from=' . $log_from . ' to=' . $log_to;

        foreach ($diagnostics as $diagnostic) {
            error_log('[WSM Reports][' . $diagnostic_id . '] PHP diagnostic severity=' . (int) $diagnostic['severity']
                . ' at ' . $diagnostic['file'] . ':' . (int) $diagnostic['line'] . ': ' . (string) $diagnostic['message']
                . '; ' . $request_context);
        }
        if ($diagnostic_count > count($diagnostics)) {
            error_log('[WSM Reports][' . $diagnostic_id . '] Additional PHP diagnostics omitted: '
                . (int) ($diagnostic_count - count($diagnostics)) . '; ' . $request_context);
        }
        if (trim($captured_output) !== '') {
            error_log('[WSM Reports][' . $diagnostic_id . '] Suppressed unexpected output (' . strlen($captured_output) . ' bytes); ' . $request_context);
        }
        if ($exception_details !== '') {
            error_log('[WSM Reports][' . $diagnostic_id . '] Uncaught ' . $exception_details . '; ' . $request_context);
        }

        $database_error = isset($wpdb->last_error) ? trim(str_replace(array("\r", "\n", "\t"), ' ', (string) $wpdb->last_error)) : '';
        if ($database_error !== '') {
            error_log('[WSM Reports][' . $diagnostic_id . '] SQL error: ' . substr($database_error, 0, 2000) . '; ' . $request_context);
        }

        $can_view_diagnostics = function_exists('current_user_can') && current_user_can('manage_options');
        $decorate_server_error = function ($error) use ($diagnostic_id, $can_view_diagnostics, $exception_details, $database_error, $request_context) {
            if (!is_wp_error($error)) { return $error; }
            $error_code = $error->get_error_code();
            $error_data = $error->get_error_data($error_code);
            $error_data = is_array($error_data) ? $error_data : array();
            $status = isset($error_data['status']) ? (int) $error_data['status'] : 0;
            if ($status < 500) { return $error; }

            error_log('[WSM Reports][' . $diagnostic_id . '] WP_Error code=' . (string) $error_code . '; ' . $request_context);
            $error_message = $error->get_error_message($error_code);
            $error_message .= ' (شناسهٔ پیگیری: ' . $diagnostic_id . ')';
            if ($can_view_diagnostics) {
                $details = array();
                if ($exception_details !== '') { $details[] = 'PHP: ' . $exception_details; }
                if ($database_error !== '') { $details[] = 'SQL: ' . substr($database_error, 0, 1000); }
                if ($details) { $error_message .= ' [' . implode(' | ', $details) . ']'; }
            }
            $error_data['diagnostic_id'] = $diagnostic_id;
            return new WP_Error($error_code, $error_message, $error_data);
        };

        if (is_wp_error($result)) { return $decorate_server_error($result); }
        return $result;
    }
    public static function club_settings($request) { return WSM_Club_Operations::public_settings(); }
    public static function club_settings_update($request) { return WSM_Club_Operations::update_settings(self::body($request)); }
    public static function club_sms_log($request) { return WSM_Club_Operations::list_sms($request->get_params()); }
    public static function club_sms_send($request) { return WSM_Club_Operations::send_sms(self::body($request)); }
    public static function club_users($request = null) { return WSM_Club_Operations::list_users(); }
    public static function club_user_create($request) { return WSM_Club_Operations::create_user(self::body($request)); }
    public static function club_user_update($request) { return WSM_Club_Operations::update_user(self::id($request), self::body($request)); }

    public static function bridge_pair_token($request) {
        $b = self::body($request);
        return WSM_Auth::create_bridge_pairing_token(isset($b['origin']) ? (string) $b['origin'] : '');
    }

    public static function bridge_pair($request) {
        $b = self::body($request);
        return WSM_Auth::pair_bridge(
            isset($b['pairing_token']) ? (string) $b['pairing_token'] : '',
            isset($b['origin']) ? (string) $b['origin'] : '',
            array(
                'device_name' => isset($b['device_name']) ? $b['device_name'] : 'WP Store Manager Local Bridge',
                'platform' => isset($b['platform']) ? $b['platform'] : 'Desktop',
                'os_version' => isset($b['os_version']) ? $b['os_version'] : '',
                'app_version' => isset($b['app_version']) ? $b['app_version'] : '',
            )
        );
    }

    public static function bridge_time($request) {
        return array('server_time' => current_time('mysql'), 'timezone' => wp_timezone_string());
    }

    public static function bridge_access_list($request) {
        return WSM_Club::access_list($request->get_params());
    }

    public static function bridge_attendance_list($request) {
        return WSM_Club::bridge_attendance_list($request->get_params());
    }

    public static function bridge_attendance($request) {
        return WSM_Club::ingest_events(self::body($request));
    }

    public static function update_check($request) {
        return WSM_Updater::remote_info(!empty($request->get_param('force')));
    }

    public static function activity($request) {
        $device = WSM_Permissions::current_device();
        $can_all = $device && (WSM_Devices::device_can($device, 'activity.view') || WSM_Devices::device_can($device, 'activity.view.all'));
        $can_mine = $device && WSM_Devices::device_can($device, 'activity.view.mine');
        if (!$can_all && !$can_mine) {
            return new WP_Error('wsm_activity_forbidden', 'مجوز مشاهدهٔ فعالیت را ندارید.', array('status' => 403));
        }
        $args = array(
            'device_id' => (int) $request->get_param('device_id'),
            'action'    => self::scalar_param($request, 'action'),
            'result'    => self::scalar_param($request, 'result'),
            'date_from' => self::scalar_param($request, 'date_from'),
            'date_to'   => self::scalar_param($request, 'date_to'),
            'limit'     => (int) $request->get_param('limit') ?: 100,
        );
        // A mine-only grant is always scoped to the account, regardless of caller-supplied filters.
        if (!$can_all || self::scalar_param($request, 'scope') === 'mine') {
            unset($args['device_id']);
            $args['user_id'] = (int) $device->user_id;
        }
        return array('items' => WSM_Activity_Log::query($args));
    }
}

// Register the existing option with WordPress Settings API early enough for admin_init.
// The app's authenticated REST writes are independently protected by Bearer/HMAC timestamp+nonce.
add_action('admin_init', array('WSM_Rest_API', 'register_settings_api_option'), 5);

// Register delta-sync observers as soon as the REST class is loaded.
WSM_Rest_API::register_sync_pull_watchers();
