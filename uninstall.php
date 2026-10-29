<?php
/**
 * حذف افزونه — فقط در صورتی داده‌ها پاک می‌شوند که کاربر در تنظیمات
 * گزینهٔ «حذف داده‌ها هنگام حذف افزونه» را فعال کرده باشد (پیش‌فرض: خاموش — داده‌ها امن می‌مانند).
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('wsm_settings', array());
$settings = is_array($settings) ? $settings : array();

if (empty($settings['delete_data_on_uninstall'])) {
    return;
}

global $wpdb;

$tables = array(
    // Core device, audit, security, sync, customer, and app-data tables.
    $wpdb->prefix . 'wsm_devices',
    $wpdb->prefix . 'wsm_activity_log',
    $wpdb->prefix . 'wsm_access_keys',
    $wpdb->prefix . 'wsm_auth_attempts',
    $wpdb->prefix . 'wsm_blocked_ips',
    $wpdb->prefix . 'wsm_used_nonces',
    $wpdb->prefix . 'wsm_security_log',
    $wpdb->prefix . 'wsm_migration_log',
    $wpdb->prefix . 'wsm_sync_logs',
    $wpdb->prefix . 'wsm_sync_member_map',
    $wpdb->prefix . 'wsm_store_customers',
    $wpdb->prefix . 'wsm_customer_ledger',
    $wpdb->prefix . 'wsm_user_permissions',
    $wpdb->prefix . 'wsm_messages',
    $wpdb->prefix . 'wsm_message_audit',
    $wpdb->prefix . 'wsm_backup_logs',

    // Club membership, operations, messaging, and financial tables.
    $wpdb->prefix . 'wsm_club_members',
    $wpdb->prefix . 'wsm_club_plans',
    $wpdb->prefix . 'wsm_club_memberships',
    $wpdb->prefix . 'wsm_club_payments',
    $wpdb->prefix . 'wsm_club_attendance',
    $wpdb->prefix . 'wsm_club_staff',
    $wpdb->prefix . 'wsm_club_payroll',
    $wpdb->prefix . 'wsm_club_collections',
    $wpdb->prefix . 'wsm_club_sports',
    $wpdb->prefix . 'wsm_club_phonebook',
    $wpdb->prefix . 'wsm_club_sms_log',
    $wpdb->prefix . 'wsm_club_payables',
    $wpdb->prefix . 'wsm_club_receivables',
    $wpdb->prefix . 'wsm_club_cash_sessions',
    $wpdb->prefix . 'wsm_club_financial_ledger',
    $wpdb->prefix . 'wsm_club_financial_accounts',

    // Legacy table copied into wsm_club_financial_ledger by older-version migrations.
    $wpdb->prefix . 'wsm_club_finance',
);

// Remove only plugin-created backup archives whose logged primary directory resolves
// inside this site's uploads tree. Custom/external storage outside uploads is retained.
$backup_table = $wpdb->prefix . 'wsm_backup_logs';
$backup_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($backup_table)));
if ($backup_table_exists === $backup_table && function_exists('wp_upload_dir') && function_exists('wp_normalize_path')) {
    $uploads = wp_upload_dir(null, false);
    $uploads_root = is_array($uploads) && empty($uploads['error']) && !empty($uploads['basedir'])
        ? realpath((string) $uploads['basedir'])
        : false;
    if ($uploads_root !== false && is_dir($uploads_root)) {
        $uploads_root = untrailingslashit(wp_normalize_path($uploads_root));
        $uploads_prefix = trailingslashit($uploads_root);
        $backup_table_sql = '`' . str_replace('`', '``', $backup_table) . '`';
        $backup_rows = $wpdb->get_results("SELECT backup_id, filename, storage_path FROM {$backup_table_sql}", ARRAY_A);
        foreach ((array) $backup_rows as $backup_row) {
            $backup_id = isset($backup_row['backup_id']) && is_scalar($backup_row['backup_id']) ? (string) $backup_row['backup_id'] : '';
            $filename = isset($backup_row['filename']) && is_scalar($backup_row['filename']) ? (string) $backup_row['filename'] : '';
            if (!preg_match('/^[a-f0-9]{32}$/D', $backup_id) || $filename !== 'wsm-backup-' . $backup_id . '.wsmbackup') {
                continue;
            }
            $storage_path = isset($backup_row['storage_path']) && is_scalar($backup_row['storage_path']) ? trim((string) $backup_row['storage_path']) : '';
            if ($storage_path === '' || is_link($storage_path) || !is_dir($storage_path)) {
                continue;
            }
            $directory_real = realpath($storage_path);
            if ($directory_real === false || !is_dir($directory_real)) {
                continue;
            }
            $directory = untrailingslashit(wp_normalize_path($directory_real));
            $directory_prefix = trailingslashit($directory);
            $inside_uploads = DIRECTORY_SEPARATOR === '\\'
                ? stripos($directory_prefix, $uploads_prefix) === 0
                : strpos($directory_prefix, $uploads_prefix) === 0;
            if ($directory === $uploads_root || !$inside_uploads) {
                continue;
            }
            $backup_path = trailingslashit($directory) . $filename;
            if (is_link($backup_path) || !is_file($backup_path)) {
                continue;
            }
            $backup_real = realpath($backup_path);
            if ($backup_real === false) {
                continue;
            }
            $backup_real = wp_normalize_path($backup_real);
            if (untrailingslashit(wp_normalize_path(dirname($backup_real))) !== $directory || basename($backup_real) !== $filename) {
                continue;
            }
            if (!@unlink($backup_real)) {
                error_log('[WSM] Could not remove a logged backup archive during opted-in plugin uninstall.');
            }
        }
    }
}

foreach ($tables as $table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

$options = array(
    // Explicit list documents long-lived and legacy settings; the prefix sweep below
    // also catches options added by newer modules without another uninstall edit.
    'wsm_settings',
    'wsm_sms',
    'wsm_last_mail_error',
    'wsm_db_version',
    'wsm_access_key_hash',
    'wsm_access_key_prefix',
    'wsm_access_key_expires',
    'wsm_access_key_algo',
    'wsm_access_key_user',
    'wsm_last_rotation',
    'wsm_version',
    'wsm_upgrade_failed_to',
    'wsm_last_migration',
    'wsm_expiry_alerts_last_run',
    'wsm_finance_control_config',
    'wsm_custom_permission_roles',
    'wsm_user_permissions_schema',
    'wsm_backup_log_schema_version',
    'wsm_backup_settings',
    'wsm_club_financial_core_version',
    'wsm_devices_dedupe_v1',
    'wsm_finance_pos_account_dedupe_v2',
    'wsm_message_moderation_schema_version',
    'wsm_messages_schema_version',
    'wsm_pos_products_v1_epoch',
    'wsm_pos_products_v1_registry',
    'wsm_pos_products_v2_epoch',
    'wsm_pos_products_v2_registry',
    'wsm_pos_quick_access',
    'wsm_product_delta_index_version',
    'wsm_rest_products_cache_epoch_v1',
    'wsm_rest_settings_cache_epoch_v1',
    'wsm_security_policy',
    'wsm_auto_approve_devices',
    'wsm_sync_deleted_products',
    'wsm_sync_settings_updated_at',
);
$options = array_values(array_unique($options));

// Delete registered POS transients before removing their option registries.
foreach (array(
    'wsm_pos_products_v1_registry' => 'wsm_pos_products_v1_',
    'wsm_pos_products_v2_registry' => 'wsm_pos_products_v2_',
) as $registry_option => $key_prefix) {
    $registered_keys = get_option($registry_option, array());
    if (!is_array($registered_keys)) { continue; }
    foreach ($registered_keys as $cache_key) {
        if (is_string($cache_key) && strpos($cache_key, $key_prefix) === 0) {
            delete_transient($cache_key);
        }
    }
}

foreach ($options as $option) {
    delete_option($option);
    if (is_multisite()) {
        delete_site_option($option);
    }
}

// Catch unknown/future plugin options through WordPress APIs so option caches are invalidated.
$option_names = $wpdb->get_col($wpdb->prepare(
    'SELECT option_name FROM ' . $wpdb->options . ' WHERE option_name LIKE %s',
    $wpdb->esc_like('wsm_') . '%'
));
foreach ((array) $option_names as $option_name) {
    if (is_string($option_name) && strpos($option_name, 'wsm_') === 0) {
        delete_option($option_name);
    }
}
if (is_multisite() && isset($wpdb->sitemeta)) {
    $site_option_names = $wpdb->get_col($wpdb->prepare(
        'SELECT meta_key FROM ' . $wpdb->sitemeta . ' WHERE meta_key LIKE %s',
        $wpdb->esc_like('wsm_') . '%'
    ));
    foreach ((array) $site_option_names as $option_name) {
        if (is_string($option_name) && strpos($option_name, 'wsm_') === 0) {
            delete_site_option($option_name);
        }
    }
}

// Remove WSM-managed employee metadata, password hashes, and TOTP metadata, but leave
// the WordPress user accounts themselves intact.
$user_meta_keys = $wpdb->get_col($wpdb->prepare(
    'SELECT DISTINCT meta_key FROM ' . $wpdb->usermeta . ' WHERE meta_key LIKE %s',
    $wpdb->esc_like('wsm_app_') . '%'
));
$user_meta_keys = array_merge((array) $user_meta_keys, array('wsm_totp_secret', 'wsm_totp_counter'));
foreach (array_unique($user_meta_keys) as $meta_key) {
    if (is_string($meta_key) && (strpos($meta_key, 'wsm_app_') === 0 || in_array($meta_key, array('wsm_totp_secret', 'wsm_totp_counter'), true))) {
        delete_metadata('user', 0, $meta_key, '', true);
    }
}

foreach (array('wsm_daily_cleanup', 'wsm_club_daily_tasks', 'wsm_scheduled_backup') as $hook) {
    wp_clear_scheduled_hook($hook);
}

// Keep WooCommerce orders/products and the _wsm_invoice marker; clear only the three
// product metadata keys this uninstall routine already removed explicitly.
foreach (array('_wsm_expiry_date', '_wsm_expiry_external_disabled', '_wsm_sales_channel') as $meta_key) {
    delete_metadata('post', 0, $meta_key, '', true);
}

// Clear stable cache keys and this plugin's object-cache group where the backend supports it.
$transient_keys = array(
    'wsm_expiry_meta_key_detected',
    'wsm_expiry_items_v2',
    'wsm_reports_sales_epoch_v1',
    'wsm_orders_list_epoch_v1',
    'wsm_api_dashboard_v3',
    'wsm_api_dashboard_v4',
    'wsm_api_club_overview_v1',
    'wsm_api_club_overview_v2',
    'wsm_reports_dashboard_v2',
    'wsm_reports_dashboard_v3',
    'wsm_reports_dashboard_v4',
    'wsm_reports_stock_v1',
    'wsm_reports_low_stock_count_v1',
    'wsm_reports_low_stock_count_v2',
    'wsm_reports_low_stock_count_v3',
    'wsm_club_overview_v2',
    'wsm_club_overview_v3',
    'wsm_upgrade_notice',
    'wsm_update_info',
    'wsm_sms_theme_config_notice_v1',
);
foreach ($transient_keys as $transient_key) {
    wp_cache_delete($transient_key, 'wsm');
    delete_transient($transient_key);
}
if (function_exists('wp_cache_flush_group')) {
    wp_cache_flush_group('wsm');
    wp_cache_flush_group('wsm_rest_rate_limit');
}

// Do not DELETE transient rows with raw SQL: a transient may live in an external object
// cache. Read database-backed names, then delete each one through the WordPress API.
$transient_option_names = $wpdb->get_col($wpdb->prepare(
    'SELECT option_name FROM ' . $wpdb->options . ' WHERE option_name LIKE %s OR option_name LIKE %s',
    $wpdb->esc_like('_transient_wsm_') . '%',
    $wpdb->esc_like('_transient_timeout_wsm_') . '%'
));
$transient_names = array();
foreach ((array) $transient_option_names as $option_name) {
    if (!is_string($option_name)) { continue; }
    if (strpos($option_name, '_transient_timeout_') === 0) {
        $transient_key = substr($option_name, strlen('_transient_timeout_'));
    } elseif (strpos($option_name, '_transient_') === 0) {
        $transient_key = substr($option_name, strlen('_transient_'));
    } else {
        continue;
    }
    if (strpos($transient_key, 'wsm_') === 0) { $transient_names[$transient_key] = true; }
}
foreach (array_keys($transient_names) as $transient_key) {
    delete_transient($transient_key);
}
// Dynamic transients present only in a persistent object-cache backend cannot be enumerated
// safely by WordPress. Known/registered keys above are deleted; remaining TTL-bound entries expire.
