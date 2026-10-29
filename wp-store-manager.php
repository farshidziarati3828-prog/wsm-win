<?php
/**
 * Plugin Name:       مدیر فروشگاه (WP Store Manager)
 * Plugin URI:        https://rointansupplement.ir/wp-store-manager
 * Description:       مدیریت فروشگاه ووکامرس و باشگاه ورزشی با PWA فارسی، شهریه، تردد، مربیان و فروش حضوری/آنلاین.
 * Version:           2.2.17
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            WSM Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-store-manager
 * Domain Path:       /languages
 * WC requires at least: 7.0
 *
 * سازگار با PHP ۷.۴ تا ۸.۴ — وردپرس ۵.۸ تا ۷.۰ — ووکامرس ۷ تا ۹ — HPOS
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WSM_PATH', plugin_dir_path(__FILE__));

register_activation_hook(__FILE__, function () {
    require_once WSM_PATH . 'includes/bootstrap.php';
    WSM_Core::activate();
});
register_deactivation_hook(__FILE__, function () {
    require_once WSM_PATH . 'includes/bootstrap.php';
    WSM_Core::deactivate();
});

// HPOS compatibility (no class load)
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Enforce the in-person-only channel on public WooCommerce pages without loading the full app bundle.
if (function_exists('add_filter')) {
    $wsm_product_channel = function ($product_id) {
        $product_id = (int) $product_id;
        $channel = get_post_meta($product_id, '_wsm_sales_channel', true);
        if (!in_array($channel, array('both', 'online', 'in_person'), true) && function_exists('wp_get_post_parent_id')) {
            $parent_id = (int) wp_get_post_parent_id($product_id);
            if ($parent_id) {
                $channel = get_post_meta($parent_id, '_wsm_sales_channel', true);
            }
        }
        return $channel === 'in_person';
    };
    add_filter('woocommerce_product_is_visible', function ($visible, $product_id) use ($wsm_product_channel) {
        return $wsm_product_channel($product_id) ? false : $visible;
    }, 99, 2);
    add_filter('woocommerce_is_purchasable', function ($purchasable, $product) use ($wsm_product_channel) {
        $id = is_object($product) && method_exists($product, 'get_id') ? $product->get_id() : 0;
        return $id && $wsm_product_channel($id) ? false : $purchasable;
    }, 99, 2);
    add_filter('woocommerce_variation_is_purchasable', function ($purchasable, $product) use ($wsm_product_channel) {
        $id = is_object($product) && method_exists($product, 'get_id') ? $product->get_id() : 0;
        return $id && $wsm_product_channel($id) ? false : $purchasable;
    }, 99, 2);
    add_filter('woocommerce_add_to_cart_validation', function ($passed, $product_id) use ($wsm_product_channel) {
        if ($passed && $wsm_product_channel($product_id)) {
            wc_add_notice('این کالا فقط برای فروش حضوری فعال است و از فروشگاه آنلاین قابل سفارش نیست.', 'error');
            return false;
        }
        return $passed;
    }, 99, 2);
    add_action('woocommerce_check_cart_items', function () use ($wsm_product_channel) {
        if (!function_exists('WC') || !WC()->cart) { return; }
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item['product_id']) && $wsm_product_channel($item['product_id'])) {
                wc_add_notice('سبد خرید شامل کالایی است که فقط برای فروش حضوری فعال شده است؛ آن را حذف کنید.', 'error');
                break;
            }
        }
    }, 99);
}

// Record mail failures on all request types. Load the full bootstrap lazily on public pages.
// Keep a single listener: the bootstrap itself does not register another wp_mail_failed callback.
add_action('wp_mail_failed', function ($error) {
    if (!class_exists('WSM_Security', false)) {
        require_once WSM_PATH . 'includes/bootstrap.php';
    }
    WSM_Security::record_mail_error($error);
}, 10, 1);

// Performance gate: public pages load the full plugin only for its own routes or background work.
$wsm_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
if (is_admin() || stripos($wsm_uri, 'wsm') !== false || stripos($wsm_uri, 'athlete-portal') !== false || defined('DOING_CRON') || defined('WP_CLI')) {
    require_once WSM_PATH . 'includes/bootstrap.php';
}

