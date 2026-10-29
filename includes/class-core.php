<?php
/**
 * هستهٔ افزونه: نصب جدول‌ها، مسیر /wsm-connect، تنظیمات و کمک‌های عمومی
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Core {

    public static function activate() {
        self::create_tables();

        // نصب تازه: نسخهٔ افزونه ثبت می‌شود. نصب قدیمی (wsm_db_version هست) دست‌نخورده می‌ماند
        // تا به‌روزرسانی‌ها در admin_init اجرا شوند.
        if (get_option('wsm_db_version') === false && get_option(WSM_Updater::VERSION_OPTION) === false) {
            update_option(WSM_Updater::VERSION_OPTION, WSM_VERSION, false);
        }

        update_option('wsm_db_version', WSM_DB_VERSION, false);

        if (!get_option('wsm_settings')) {
            update_option('wsm_settings', WSM_Util::default_settings(), false);
        }

        // کلید دسترسی خودکار در نخستین فعال‌سازی ساخته می‌شود (فقط یک‌بار نمایش داده می‌شود)
        if (!get_option('wsm_access_key_hash') && WSM_Crypto::available()) {
            $user_id = get_current_user_id() ?: 1;
            $key = WSM_Auth::generate_access_key($user_id);
            WSM_Auth::stash_key($user_id, $key['plain']);
        }

        self::add_rewrite_rules();
        flush_rewrite_rules();

        if (!wp_next_scheduled('wsm_daily_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wsm_daily_cleanup');
        }
        if (!wp_next_scheduled('wsm_club_daily_tasks')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wsm_club_daily_tasks');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('wsm_daily_cleanup');
        wp_clear_scheduled_hook('wsm_club_daily_tasks');
        wp_clear_scheduled_hook('wsm_scheduled_backup');
        flush_rewrite_rules();
    }

    public static function boot() {
        load_plugin_textdomain('wp-store-manager', false, dirname(plugin_basename(WSM_PLUGIN_FILE)) . '/languages');
        WSM_SMS::boot();

        add_action('init', array(__CLASS__, 'add_rewrite_rules'));
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('template_redirect', array(__CLASS__, 'maybe_connect_page'));
        // The desktop shell uses signed X-WSM-* headers from its own origin; permit them
        // through WordPress REST preflight checks without weakening API authentication.
        add_filter('rest_allowed_cors_headers', array(__CLASS__, 'allow_client_cors_headers'));
        // Ensure an upgraded schema is ready before the first mobile/API call, even if wp-admin
        // has not been opened since plugin files were replaced.
        add_action('rest_api_init', array(__CLASS__, 'maybe_upgrade'), 1);
        add_action('rest_api_init', array('WSM_Updater', 'maybe_upgrade'), 2);
        add_action('rest_api_init', array('WSM_Rest_API', 'register'), 10);
        add_action('woocommerce_order_status_changed', array('WSM_Invoices', 'sync_source_order_status'), 10, 4);
        add_action('woocommerce_new_order', array('WSM_Reports', 'invalidate_dashboard_cache'), 10, 1);
        add_action('woocommerce_update_order', array('WSM_Reports', 'invalidate_dashboard_cache'), 10, 1);
        add_action('woocommerce_order_status_changed', array('WSM_Reports', 'invalidate_dashboard_cache'), 10, 4);
        add_action('woocommerce_delete_order', array('WSM_Reports', 'invalidate_dashboard_cache'), 10, 1);
        add_action('woocommerce_payment_complete', array('WSM_Reports', 'invalidate_dashboard_cache'), 40, 1);
        add_action('woocommerce_order_refunded', array('WSM_Reports', 'invalidate_dashboard_cache'), 40, 2);
        add_action('woocommerce_order_status_changed', array('WSM_Customers', 'sync_credit_order_status'), 25, 4);
        add_action('woocommerce_new_order_item', array('WSM_Club_Operations', 'snapshot_order_item_purchase_cost'), 10, 3);
        add_action('woocommerce_payment_complete', array('WSM_Club_Operations', 'record_online_order_finance'), 30, 1);
        add_action('woocommerce_order_status_changed', array('WSM_Club_Operations', 'record_online_order_finance'), 30, 4);
        add_action('woocommerce_order_status_changed', array('WSM_Club_Operations', 'sync_pos_debt_order_status'), 40, 4);
        add_action('save_post_product', array('WSM_Expiry', 'invalidate_items_cache'), 10, 3);
        add_action('save_post_product_variation', array('WSM_Expiry', 'invalidate_items_cache'), 10, 3);
        add_action('save_post_product', array('WSM_Reports', 'invalidate_stock_cache'), 10, 3);
        add_action('save_post_product_variation', array('WSM_Reports', 'invalidate_stock_cache'), 10, 3);
        add_action('woocommerce_product_set_stock', array('WSM_Expiry', 'invalidate_items_cache'));
        add_action('woocommerce_variation_set_stock', array('WSM_Expiry', 'invalidate_items_cache'));
        add_action('woocommerce_product_set_stock_status', array('WSM_Expiry', 'invalidate_items_cache'), 10, 3);
        add_action('woocommerce_variation_set_stock_status', array('WSM_Expiry', 'invalidate_items_cache'), 10, 3);
        add_action('woocommerce_product_set_stock', array('WSM_Reports', 'invalidate_stock_cache'));
        add_action('woocommerce_variation_set_stock', array('WSM_Reports', 'invalidate_stock_cache'));
        add_action('woocommerce_product_set_stock_status', array('WSM_Reports', 'invalidate_stock_cache'), 10, 3);
        add_action('woocommerce_variation_set_stock_status', array('WSM_Reports', 'invalidate_stock_cache'), 10, 3);
        add_action('trashed_post', array('WSM_Expiry', 'invalidate_items_cache_for_post'));
        add_action('untrashed_post', array('WSM_Expiry', 'invalidate_items_cache_for_post'));
        add_action('deleted_post', array('WSM_Expiry', 'invalidate_items_cache_for_post'));
        add_action('trashed_post', array('WSM_Reports', 'invalidate_stock_cache_for_post'));
        add_action('untrashed_post', array('WSM_Reports', 'invalidate_stock_cache_for_post'));
        add_action('deleted_post', array('WSM_Reports', 'invalidate_stock_cache_for_post'));
        add_action('wsm_daily_cleanup', array('WSM_Activity_Log', 'cleanup'));
        add_action('wsm_daily_cleanup', array('WSM_Security', 'cleanup'));
        add_action('wsm_daily_cleanup', array('WSM_Expiry', 'send_daily_alerts'));
        add_action('wsm_daily_cleanup', array('WSM_Rest_API', 'cleanup_sync_logs'));
        // Retry the optional core-table index on the next scheduled run if DDL was denied during upgrade.
        add_action('wsm_daily_cleanup', array(__CLASS__, 'ensure_product_delta_index'));
        add_action('wsm_club_daily_tasks', array('WSM_Club', 'daily_tasks'));
        if (!wp_next_scheduled('wsm_daily_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wsm_daily_cleanup');
        }
        if (!wp_next_scheduled('wsm_club_daily_tasks')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wsm_club_daily_tasks');
        }
        add_action('admin_notices', array(__CLASS__, 'woocommerce_notice'));
        add_action('admin_init', array(__CLASS__, 'maybe_upgrade'), 1);
        WSM_Updater::init();
        WSM_Auth::init();
        WSM_Two_Factor::init();

        if (is_admin()) {
            WSM_Admin_Menu::init();
        }
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule('^wsm-connect/?$', 'index.php?wsm_connect=1', 'top');
    }

    /** Add the desktop client's signed headers to WordPress REST CORS preflight responses. */
    public static function allow_client_cors_headers($headers) {
        $headers = is_array($headers) ? $headers : array();
        $present = array();
        foreach ($headers as $header) {
            if (is_string($header)) { $present[strtolower($header)] = true; }
        }
        foreach (array('X-WSM-Token', 'X-WSM-Timestamp', 'X-WSM-Nonce', 'X-WSM-Signature', 'X-WSM-Local-Key') as $header) {
            $normalized = strtolower($header);
            if (!isset($present[$normalized])) {
                $headers[] = $header;
                $present[$normalized] = true;
            }
        }
        return $headers;
    }

    public static function query_vars($vars) {
        $vars[] = 'wsm_connect';
        return $vars;
    }

    /**
     * صفحهٔ اتصال موبایل: /wsm-connect
     */
    public static function maybe_connect_page() {
        if (get_query_var('wsm_connect') === '' || get_query_var('wsm_connect') === null) {
            return;
        }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        include WSM_PATH . 'templates/connect.php';
        exit;
    }

    public static function woocommerce_notice() {
        if (class_exists('WooCommerce')) {
            return;
        }
        echo '<div class="notice notice-error"><p>' .
            esc_html__('مدیر فروشگاه برای کار کردن به افزونهٔ ووکامرس نیاز دارد. لطفاً ووکامرس را نصب و فعال کنید.', 'wp-store-manager') .
            '</p></div>';
    }

    public static function maybe_upgrade() {
        try {
            if (get_option('wsm_db_version') !== WSM_DB_VERSION) {
                self::create_tables();
                update_option('wsm_db_version', WSM_DB_VERSION, false);
            }
            // The message table has its own marker so it can be added without changing WSM_DB_VERSION.
            self::ensure_messages_table();
        } catch (Throwable $e) {
            // Schema upgrades are idempotent; log and retry on the next request instead of blanking wp-admin.
            error_log('[WSM] WSM_Core::maybe_upgrade failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /** Create/upgrade the internal messaging table independently from the plugin-wide schema version. */
    public static function ensure_messages_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'wsm_messages';
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        if (get_option('wsm_messages_schema_version') === '2') {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($exists === $table) { return true; }
        }
        if (!defined('ABSPATH') || !file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) { return false; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid varchar(36) NOT NULL,
            sender_id bigint(20) unsigned NOT NULL,
            receiver_id bigint(20) unsigned NOT NULL DEFAULT 0,
            subject varchar(200) NOT NULL DEFAULT '',
            message longtext NOT NULL,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            read_by longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid (uuid),
            KEY receiver_created (receiver_id, created_at),
            KEY receiver_read (receiver_id, is_read),
            KEY sender_created (sender_id, created_at)
        ) {$charset};");
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) { return false; }
        $read_by_column = $wpdb->get_var("SHOW COLUMNS FROM {$table_sql} LIKE 'read_by'");
        if ($read_by_column !== 'read_by') { return false; }
        update_option('wsm_messages_schema_version', '2', false);
        return true;
    }

    /** Add a narrow index for product Delta Sync without changing WordPress rows or core queries. */
    public static function ensure_product_delta_index() {
        global $wpdb;
        if (get_option('wsm_product_delta_index_version') === '1') {
            return true;
        }
        $table = isset($wpdb->posts) ? (string) $wpdb->posts : '';
        if ($table === '') {
            return false;
        }
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        $index_name = 'wsm_product_delta';

        $wpdb->last_error = '';
        $existing = $wpdb->get_var($wpdb->prepare(
            'SHOW INDEX FROM ' . $table_sql . ' WHERE Key_name = %s',
            $index_name
        ));
        if (!empty($wpdb->last_error)) {
            return false;
        }
        if ($existing !== null && $existing !== false) {
            update_option('wsm_product_delta_index_version', '1', false);
            return true;
        }

        $wpdb->last_error = '';
        $created = $wpdb->query(
            'ALTER TABLE ' . $table_sql . ' ADD KEY `wsm_product_delta` (`post_type`, `post_modified_gmt`, `ID`)'
        );
        if ($created === false) {
            error_log('[WSM] Product Delta Sync index could not be ensured; the daily retry will try again.');
            return false;
        }
        update_option('wsm_product_delta_index_version', '1', false);
        return true;
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}wsm_devices (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            device_name varchar(100) NOT NULL DEFAULT '',
            platform varchar(40) NOT NULL DEFAULT '',
            os_version varchar(40) NOT NULL DEFAULT '',
            app_version varchar(20) NOT NULL DEFAULT '',
            ip_address varchar(45) NOT NULL DEFAULT '',
            token_hash varchar(64) NOT NULL DEFAULT '',
            refresh_hash varchar(64) NOT NULL DEFAULT '',
            token_expires datetime NULL DEFAULT NULL,
            signing_key text NULL,
            permissions longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            block_reason text NULL,
            last_seen datetime NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY token_hash (token_hash),
            KEY refresh_hash (refresh_hash)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wsm_activity_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            device_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action_type varchar(20) NOT NULL DEFAULT '',
            entity_type varchar(30) NOT NULL DEFAULT '',
            entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
            entity_name varchar(255) NOT NULL DEFAULT '',
            before_data longtext NULL,
            after_data longtext NULL,
            result varchar(10) NOT NULL DEFAULT 'success',
            ip_address varchar(45) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY device_id (device_id),
            KEY created_at (created_at),
            KEY entity (entity_type, entity_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wsm_access_keys (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            key_prefix varchar(20) NOT NULL DEFAULT '',
            key_hash varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            expires_at datetime NULL DEFAULT NULL,
            revoked_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wsm_auth_attempts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL DEFAULT '',
            reason varchar(40) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY ip_created (ip_address, created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wsm_blocked_ips (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL DEFAULT '',
            reason varchar(255) NOT NULL DEFAULT '',
            blocked_until datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY ip_until (ip_address, blocked_until)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wsm_used_nonces (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            nonce_hash varchar(64) NOT NULL DEFAULT '',
            device_id bigint(20) unsigned NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY nonce_hash (nonce_hash),
            KEY expires_at (expires_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wsm_security_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event varchar(40) NOT NULL DEFAULT '',
            severity varchar(10) NOT NULL DEFAULT 'info',
            device_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ip_address varchar(45) NOT NULL DEFAULT '',
            user_agent varchar(255) NOT NULL DEFAULT '',
            details text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY event_device (event, device_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wsm_migration_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            version_from varchar(20) NOT NULL DEFAULT '',
            version_to varchar(20) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT '',
            error_message text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wsm_store_customers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL DEFAULT '',
            phone varchar(40) NOT NULL DEFAULT '',
            phone_key varchar(24) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY phone_key (phone_key),
            KEY name (name),
            KEY updated_at (updated_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wsm_customer_ledger (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            entry_type varchar(20) NOT NULL DEFAULT '',
            amount decimal(26,8) NOT NULL DEFAULT 0,
            payment_method varchar(24) NOT NULL DEFAULT '',
            batch_id varchar(36) NOT NULL DEFAULT '',
            note text NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY customer_created (customer_id, created_at),
            KEY order_id (order_id),
            KEY batch_id (batch_id)
        ) $charset;");

        // Durable inbox for idempotent offline replay. The REST processor consumes
        // received rows and records per-item success/failure details here.
        dbDelta("CREATE TABLE {$p}wsm_sync_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            queue_id varchar(36) NOT NULL,
            device_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action_type varchar(40) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'received',
            payload longtext NOT NULL,
            error_message text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY queue_id (queue_id),
            KEY status_created (status, created_at),
            KEY action_type (action_type),
            KEY device_id (device_id)
        ) $charset;");

        // Keep multiple local UUID aliases when offline members from different devices merge.
        dbDelta("CREATE TABLE {$p}wsm_sync_member_map (
            offline_uuid varchar(36) NOT NULL,
            member_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (offline_uuid),
            KEY member_id (member_id)
        ) $charset;");

        if (class_exists('WSM_Club')) {
            WSM_Club::create_tables();
        }
        if (class_exists('WSM_Club_Operations')) {
            WSM_Club_Operations::create_tables();
        }
        self::ensure_messages_table();
        self::ensure_product_delta_index();
    }

    /**
     * Backward-compatible timestamp helper.
     * Older callers (REST sync, cron jobs, third-party snippets) call WSM_Core::now();
     * the canonical implementation lives in WSM_Util::now(), which is delegated to here.
     */
    public static function now() {
        return function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
    }
}

class WSM_Util {

    /**
     * شناسه‌های سفارش‌هایی که یک متای مشخص دارند.
     * wc_get_orders روی ذخیرهٔ کلاسیک (نوشته‌ها) از meta_query پشتیبانی نمی‌کند و هشدار می‌دهد،
     * پس این فهرست مستقیم از جدول متا خوانده می‌شود (سازگار با HPOS و ذخیرهٔ کلاسیک).
     * @return int[]
     */
    public static function order_ids_with_meta($key, $value = null) {
        global $wpdb;
        $hpos = class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
        $col   = $hpos ? 'order_id' : 'post_id';
        if ($value === null) {
            $sql  = "SELECT $col FROM $table WHERE meta_key = %s";
            $args = array($key);
        } else {
            $sql  = "SELECT $col FROM $table WHERE meta_key = %s AND meta_value = %s";
            $args = array($key, (string) $value);
        }
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $args)));
    }

    public static function default_settings() {
        return array(
            'token_ttl_days'            => 90,
            'key_ttl_days'              => 90,
            'log_retention_days'        => 90,
            'notify_new_order'          => 0,
            'delete_data_on_uninstall'  => 0,
            'store_name'                => get_bloginfo('name'),
            'store_logo'                => '',
            'update_server_url'         => '',
            'auto_update'               => 0,
            'expiry_alert_days'          => 30,
            'expiry_alert_email'         => 1,
            'expiry_alert_sms'           => 0,
            'expiry_alert_email_to'      => get_option('admin_email'),
            'expiry_meta_key'            => '',
            'postal_sender_name'         => get_bloginfo('name'),
            'postal_sender_company'      => get_bloginfo('name'),
            'postal_sender_phone'        => '',
            'postal_sender_email'        => '',
            'postal_sender_address_1'    => '',
            'postal_sender_address_2'    => '',
            'postal_sender_city'         => '',
            'postal_sender_state'        => '',
            'postal_sender_postcode'     => '',
            'postal_sender_country'      => '',
            'ui_config'                  => self::default_ui_config(),
            'totp_required'              => 0,
        );
    }

    public static function settings() {
        $saved = get_option('wsm_settings', array());
        return wp_parse_args(is_array($saved) ? $saved : array(), self::default_settings());
    }

    /** تنظیم پیش‌فرضِ اجزای قابل مدیریت در پنج تب اپ. */
    public static function default_ui_config() {
        return array(
            'version' => 1,
            'tabs' => array(
                'dashboard' => array(
                    'sections' => array('kpis' => true, 'expiry' => true, 'sales_chart' => true, 'quick_actions' => true, 'recent_orders' => true, 'custom_content' => true, 'custom_actions' => true),
                    'fields' => array('today_sales' => true, 'month_sales' => true, 'last30_sales' => true, 'low_stock' => true, 'recent_customer' => true, 'recent_date' => true, 'recent_total' => true, 'recent_status' => true),
                    'custom_fields' => array(), 'blocks' => array(), 'actions' => array(),
                ),
                'products' => array(
                    'sections' => array('search' => true, 'filters' => true, 'categories' => true, 'product_list' => true, 'create_action' => true, 'record_actions' => true, 'description' => true, 'custom_fields' => true, 'custom_content' => true, 'custom_actions' => true),
                    'fields' => array('image' => true, 'name' => true, 'status' => true, 'price' => true, 'sale_price' => true, 'sales_channel' => true, 'stock' => true, 'stock_quantity' => true, 'stock_status' => true, 'low_stock_amount' => true, 'sku' => true, 'expiry' => true, 'short_description' => true, 'description' => true),
                    'custom_fields' => array(), 'blocks' => array(), 'actions' => array(),
                ),
                'orders' => array(
                    'sections' => array('search' => true, 'status_filter' => true, 'date_filter' => true, 'order_list' => true, 'overview' => true, 'items' => true, 'order_totals' => true, 'note' => true, 'history' => true, 'header_actions' => true, 'status_actions' => true, 'refund_actions' => true, 'custom_fields' => true, 'custom_content' => true, 'custom_actions' => true),
                    'fields' => array('customer' => true, 'date' => true, 'status' => true, 'total' => true, 'shipping' => true, 'payment' => true, 'billing_first' => true, 'billing_last' => true, 'billing_phone' => true, 'billing_email' => true, 'billing_address' => true, 'billing_city' => true, 'billing_postcode' => true, 'customer_note' => true, 'item_count' => true, 'item_image' => true, 'item_name' => true, 'item_quantity' => true, 'item_total' => true),
                    'custom_fields' => array(), 'blocks' => array(), 'actions' => array(),
                ),
                'invoices' => array(
                    'sections' => array('search' => true, 'status_filter' => true, 'invoice_list' => true, 'create_action' => true, 'record_actions' => true, 'buyer' => true, 'recipient' => true, 'items' => true, 'totals' => true, 'note' => true, 'print_actions' => true, 'custom_fields' => true, 'custom_content' => true, 'custom_actions' => true),
                    'fields' => array('date' => true, 'buyer' => true, 'total' => true, 'status' => true, 'buyer_name' => true, 'buyer_mobile' => true, 'buyer_email' => true, 'buyer_national_id' => true, 'buyer_address' => true, 'recipient_name' => true, 'recipient_company' => true, 'recipient_phone' => true, 'recipient_city' => true, 'recipient_state' => true, 'recipient_country' => true, 'recipient_address' => true, 'recipient_postcode' => true, 'invoice_status' => true, 'invoice_note' => true, 'item_search' => true, 'item_manual_add' => true, 'item_image' => true, 'item_name' => true, 'item_remove' => true, 'item_quantity' => true, 'item_price' => true, 'item_line_total' => true, 'item_discount_type' => true, 'item_discount_value' => true, 'invoice_discount_type' => true, 'invoice_discount_value' => true, 'invoice_tax' => true, 'invoice_shipping' => true, 'invoice_totals_preview' => true),
                    'custom_fields' => array(), 'blocks' => array(), 'actions' => array(),
                ),
                'more' => array(
                    'sections' => array('sale' => true, 'customers' => true, 'reports' => true, 'devices' => true, 'activity' => true, 'club' => true, 'settings' => true, 'custom_content' => true, 'custom_actions' => true),
                    'fields' => array(),
                    'custom_fields' => array(), 'blocks' => array(), 'actions' => array(),
                ),
            ),
        );
    }

    /**
     * پاک‌سازی تنظیم UI؛ فقط کلیدهای از پیش تعریف‌شده و مسیرهای امن داخلی پذیرفته می‌شوند.
     */
    public static function clean_ui_config($input) {
        $defaults = self::default_ui_config();
        $input = is_array($input) ? $input : array();
        $input_tabs = isset($input['tabs']) && is_array($input['tabs']) ? $input['tabs'] : array();
        $allowed_types = array('text', 'textarea', 'number', 'date', 'email');
        $allowed_routes = array('/', '/products', '/products/new', '/orders', '/invoices', '/invoices/new', '/sale', '/reports', '/customers', '/devices', '/settings');
        $tabs = array();

        foreach ($defaults['tabs'] as $tab => $tab_default) {
            $raw = isset($input_tabs[$tab]) && is_array($input_tabs[$tab]) ? $input_tabs[$tab] : array();
            $tabs[$tab] = array();
            foreach (array('sections', 'fields') as $group) {
                $tabs[$tab][$group] = array();
                $raw_group = isset($raw[$group]) && is_array($raw[$group]) ? $raw[$group] : array();
                foreach ($tab_default[$group] as $key => $default_value) {
                    $tabs[$tab][$group][$key] = array_key_exists($key, $raw_group)
                        ? (function_exists('rest_sanitize_boolean') ? rest_sanitize_boolean($raw_group[$key]) : (bool) $raw_group[$key])
                        : (bool) $default_value;
                }
            }

            $tabs[$tab]['custom_fields'] = array();
            if (in_array($tab, array('products', 'orders', 'invoices'), true) && !empty($raw['custom_fields']) && is_array($raw['custom_fields'])) {
                $seen = array();
                foreach (array_slice($raw['custom_fields'], 0, 20) as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $id = sanitize_key(isset($field['id']) ? (string) $field['id'] : '');
                    $label = WSM_Util::clean_text(isset($field['label']) ? $field['label'] : '', 60);
                    $type = isset($field['type']) ? sanitize_key((string) $field['type']) : 'text';
                    if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $id) || $label === '' || !in_array($type, $allowed_types, true) || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $tabs[$tab]['custom_fields'][] = array('id' => $id, 'label' => $label, 'type' => $type);
                }
            }

            $tabs[$tab]['blocks'] = array();
            if (!empty($raw['blocks']) && is_array($raw['blocks'])) {
                foreach (array_slice($raw['blocks'], 0, 10) as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $id = sanitize_key(isset($block['id']) ? (string) $block['id'] : '');
                    $title = WSM_Util::clean_text(isset($block['title']) ? $block['title'] : '', 60);
                    $text = WSM_Util::clean_text(isset($block['text']) ? $block['text'] : '', 240);
                    if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $id) || $title === '') {
                        continue;
                    }
                    $tabs[$tab]['blocks'][] = array('id' => $id, 'title' => $title, 'text' => $text);
                }
            }

            $tabs[$tab]['actions'] = array();
            if (!empty($raw['actions']) && is_array($raw['actions'])) {
                foreach (array_slice($raw['actions'], 0, 10) as $action) {
                    if (!is_array($action)) {
                        continue;
                    }
                    $id = sanitize_key(isset($action['id']) ? (string) $action['id'] : '');
                    $label = WSM_Util::clean_text(isset($action['label']) ? $action['label'] : '', 45);
                    $route = isset($action['route']) ? sanitize_text_field((string) $action['route']) : '';
                    if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $id) || $label === '' || !in_array($route, $allowed_routes, true)) {
                        continue;
                    }
                    $tabs[$tab]['actions'][] = array('id' => $id, 'label' => $label, 'route' => $route);
                }
            }
        }
        return array('version' => 1, 'tabs' => $tabs);
    }

    /** خواندن مقادیر فیلدهای سفارشیِ یک محصول/سفارش/فاکتور. */
    public static function custom_field_values($object, $tab) {
        if (!is_object($object) || !in_array($tab, array('products', 'orders', 'invoices'), true)) {
            return array();
        }
        $settings = self::settings();
        $config = self::clean_ui_config(isset($settings['ui_config']) ? $settings['ui_config'] : array());
        $definitions = $config['tabs'][$tab]['custom_fields'];
        $values = array();
        foreach ($definitions as $field) {
            $key = '_wsm_custom_' . $tab . '_' . $field['id'];
            $values[$field['id']] = (string) $object->get_meta($key, true);
        }
        return $values;
    }

    /** ذخیرهٔ امن مقادیر فیلدهای سفارشی به‌صورت متای محصول/سفارش. */
    public static function save_custom_field_values($object, $tab, $values) {
        if (!is_object($object) || !in_array($tab, array('products', 'orders', 'invoices'), true) || !is_array($values)) {
            return;
        }
        $settings = self::settings();
        $config = self::clean_ui_config(isset($settings['ui_config']) ? $settings['ui_config'] : array());
        foreach ($config['tabs'][$tab]['custom_fields'] as $field) {
            if (!array_key_exists($field['id'], $values) || !is_scalar($values[$field['id']])) {
                continue;
            }
            $value = (string) $values[$field['id']];
            if ($field['type'] === 'textarea') {
                $value = sanitize_textarea_field($value);
            } elseif ($field['type'] === 'email') {
                $value = sanitize_email($value);
                if ($value !== '' && !is_email($value)) {
                    $value = '';
                }
            } elseif ($field['type'] === 'number') {
                $value = $value === '' ? '' : (string) wc_format_decimal($value);
            } elseif ($field['type'] === 'date') {
                $value = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
            } else {
                $value = sanitize_text_field($value);
            }
            $object->update_meta_data('_wsm_custom_' . $tab . '_' . $field['id'], $value);
        }
    }

    public static function app_dir() {
        return WSM_PATH . 'wsm-app/';
    }

    public static function app_url() {
        return WSM_URL . 'wsm-app/';
    }

    /**
     * آیا بیلد اپ (index.html خروجی Vite) در پوشهٔ wsm-app قرار دارد؟
     */
    public static function app_built() {
        $file = self::app_dir() . 'index.html';
        if (!is_readable($file)) {
            return false;
        }
        // بیلد تک‌فایلی Vite ممکن است بیش از ۵۰۰ کیلوبایت باشد و <div id="root"> در انتهای سند است.
        $content = file_get_contents($file, false, null, 0, 8 * 1024 * 1024); // phpcs:ignore
        return $content !== false && strpos($content, '<div id="root"') !== false;
    }

    public static function rest_base() {
        return rest_url('wsm/v1/');
    }

    public static function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return substr($ip, 0, 45);
    }

    public static function now() {
        return current_time('mysql');
    }

    public static function add_days($days) {
        return date('Y-m-d H:i:s', current_time('timestamp') + ((int) $days * DAY_IN_SECONDS));
    }

    public static function is_woo_active() {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    public static function woo_required_error() {
        return new WP_Error('wsm_woo_missing', 'ووکامرس فعال نیست.', array('status' => 500));
    }

    public static function clean_text($value, $max = 255) {
        if (!is_scalar($value)) {
            $value = '';
        }
        $value = sanitize_text_field((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }
}

