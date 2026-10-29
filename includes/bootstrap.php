<?php
/**
 * Bootstrap for WP Store Manager.
 *
 * @package WP_Store_Manager
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WSM_BOOTSTRAPPED')) {
    define('WSM_BOOTSTRAPPED', true);
}
if (!defined('WSM_PLUGIN_FILE')) {
    define('WSM_PLUGIN_FILE', dirname(__DIR__) . '/wp-store-manager.php');
}
if (!defined('WSM_PATH')) {
    define('WSM_PATH', trailingslashit(plugin_dir_path(WSM_PLUGIN_FILE)));
}
if (!defined('WSM_URL')) {
    define('WSM_URL', plugin_dir_url(WSM_PLUGIN_FILE));
}
if (!defined('WSM_VERSION')) {
    define('WSM_VERSION', '2.2.17');
}
if (!defined('WSM_DB_VERSION')) {
    define('WSM_DB_VERSION', '2.2.6');
}

$wsm_classes = array(
    'class-fa-helpers.php',
    'class-persian-normalizer.php',
    'class-crypto.php',
    'class-core.php',
    'class-club.php',
    'class-club-operations.php',
    'class-activity-log.php',
    'class-devices.php',
    'class-permissions.php',
    'class-security.php',
    'class-sms.php',
    'class-auth.php',
    'class-two-factor.php',
    'class-products.php',
    'class-orders.php',
    'class-invoices.php',
    'class-customers.php',
    'class-reports.php',
    'class-updater.php',
    'class-rest-api.php',
    'class-admin-menu.php',
);

foreach ($wsm_classes as $wsm_class_file) {
    require_once __DIR__ . '/' . $wsm_class_file;
}
unset($wsm_classes, $wsm_class_file);

WSM_Core::boot();
