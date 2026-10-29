<?php
/**
 * محصولات ووکامرس: فهرست با جستجوی فازی فارسی، ایجاد، ویرایش، حذف، کپی، تصویر
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Products {

    const STATUSES = array('publish', 'draft', 'private', 'pending');
    const BARCODE_META = '_wsm_barcode';
    const PURCHASE_COST_META = '_wsm_purchase_cost';
    const POS_CACHE_PREFIX = 'wsm_pos_products_v3_';
    const POS_CACHE_REGISTRY = 'wsm_pos_products_v2_registry';
    const POS_CACHE_EPOCH = 'wsm_pos_products_v2_epoch';

    /** مقدار بارکد اختصاصی یا شناسهٔ یکتای استاندارد ووکامرس، در صورت پشتیبانی نسخه. */
    public static function barcode(WC_Product $p) {
        $value = trim((string) $p->get_meta(self::BARCODE_META, true));
        if ($value === '' && method_exists($p, 'get_global_unique_id')) {
            $value = trim((string) $p->get_global_unique_id());
        }
        return self::normalize_barcode($value);
    }

    /** Purchase cost is nullable: an empty value is unknown, while 0 is an explicit value. */
    public static function purchase_cost(WC_Product $p) {
        $raw = $p->get_meta(self::PURCHASE_COST_META, true);
        if ($raw !== '' && $raw !== null && is_numeric((string) $raw) && is_finite((float) $raw) && (float) $raw >= 0) {
            return (string) wc_format_decimal((string) $raw, wc_get_price_decimals());
        }
        if (method_exists($p, 'get_cogs_value')) {
            $native = $p->get_cogs_value();
            if ($native !== '' && $native !== null && is_numeric((string) $native) && is_finite((float) $native) && (float) $native >= 0) {
                return (string) wc_format_decimal((string) $native, wc_get_price_decimals());
            }
        }
        return '';
    }

    private static function normalize_barcode($value) {
        if (!is_scalar($value)) { return ''; }
        $digits = array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9');
        return trim(strtr((string) $value, $digits));
    }

    public static function sales_channel(WC_Product $p) {
        $channel = $p->get_meta('_wsm_sales_channel', true);
        if (!in_array($channel, array('both', 'online', 'in_person'), true)) {
            $parent_id = method_exists($p, 'get_parent_id') ? (int) $p->get_parent_id() : 0;
            if (!$parent_id) { $parent_id = (int) wp_get_post_parent_id($p->get_id()); }
            $channel = $parent_id ? get_post_meta($parent_id, '_wsm_sales_channel', true) : '';
        }
        return in_array($channel, array('both', 'online', 'in_person'), true) ? $channel : 'both';
    }

    /** Return an already-generated local WebP thumbnail only; never transcode on a REST request. */
    private static function existing_webp_thumbnail_url($image_url) {
        $image_url = (string) $image_url;
        if ($image_url === '' || !function_exists('wp_upload_dir') || !function_exists('wp_parse_url')) { return ''; }
        $image_parts = wp_parse_url($image_url);
        if (!is_array($image_parts) || empty($image_parts['path'])) { return ''; }
        if (preg_match('/\\.webp$/i', $image_parts['path'])) { return $image_url; }

        $uploads = wp_upload_dir();
        if (!is_array($uploads) || empty($uploads['baseurl']) || empty($uploads['basedir'])) { return ''; }
        $base_parts = wp_parse_url((string) $uploads['baseurl']);
        if (!is_array($base_parts)) { return ''; }
        $image_host = strtolower((string) ($image_parts['host'] ?? ''));
        $base_host = strtolower((string) ($base_parts['host'] ?? ''));
        if ($image_host !== $base_host) { return ''; } // CDN rewrites are left to the CDN.

        $base_path = rtrim(rawurldecode((string) ($base_parts['path'] ?? '')), '/');
        $image_path = rawurldecode((string) $image_parts['path']);
        if ($base_path !== '' && strpos($image_path, $base_path . '/') !== 0) { return ''; }
        $relative = ltrim($base_path === '' ? $image_path : substr($image_path, strlen($base_path)), '/');
        if ($relative === '' || strpos($relative, '..') !== false || !preg_match('/\\.(?:jpe?g|png)$/i', $relative)) { return ''; }

        $root = realpath((string) $uploads['basedir']);
        if ($root === false) { return ''; }
        $source = wp_normalize_path(rtrim($root, '/\\') . '/' . $relative);
        $source_real = realpath($source);
        $root_normalized = rtrim(wp_normalize_path($root), '/') . '/';
        if ($source_real === false || strpos(wp_normalize_path($source_real), $root_normalized) !== 0) { return ''; }

        $webp_relative = preg_replace('/\\.(?:jpe?g|png)$/i', '.webp', $relative);
        $candidates = array(
            array($webp_relative, wp_normalize_path(rtrim($root, '/\\') . '/' . $webp_relative)),
            array($relative . '.webp', $source . '.webp'),
        );
        foreach ($candidates as $candidate) {
            $candidate_real = realpath($candidate[1]);
            if ($candidate_real === false || !is_file($candidate_real) || !is_readable($candidate_real)) { continue; }
            if (strpos(wp_normalize_path($candidate_real), $root_normalized) !== 0) { continue; }
            $webp_url = rtrim((string) $uploads['baseurl'], '/') . '/' . str_replace('%2F', '/', rawurlencode($candidate[0]));
            if (!empty($image_parts['scheme']) && function_exists('set_url_scheme')) {
                $webp_url = set_url_scheme($webp_url, (string) $image_parts['scheme']);
            }
            return $webp_url;
        }
        return '';
    }

    public static function format(WC_Product $p) {
        $img_id = (int) $p->get_image_id();
        $img_src = $img_id ? wp_get_attachment_image_src($img_id, 'woocommerce_thumbnail') : false;
        $img_url = is_array($img_src) && isset($img_src[0]) ? (string) $img_src[0] : '';
        $img_webp_url = self::existing_webp_thumbnail_url($img_url);
        $img_srcset = $img_id && function_exists('wp_get_attachment_image_srcset')
            ? (string) wp_get_attachment_image_srcset($img_id, 'woocommerce_thumbnail')
            : '';
        $img_sizes = $img_id && function_exists('wp_get_attachment_image_sizes')
            ? (string) wp_get_attachment_image_sizes($img_id, 'woocommerce_thumbnail')
            : '';
        $img_width = is_array($img_src) && isset($img_src[1]) ? (int) $img_src[1] : 0;
        $img_height = is_array($img_src) && isset($img_src[2]) ? (int) $img_src[2] : 0;
        $cats = array();
        foreach ((array) $p->get_category_ids() as $cid) {
            $term = get_term((int) $cid, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $cats[] = array('id' => (int) $cid, 'name' => $term->name);
            }
        }
        $created  = $p->get_date_created();
        $modified = $p->get_date_modified();

        return array(
            'id'                => (int) $p->get_id(),
            'type'              => $p->get_type(),
            'name'              => $p->get_name(),
            'sku'               => $p->get_sku(),
            'barcode'           => self::barcode($p),
            'sales_channel'     => self::sales_channel($p),
            'status'            => $p->get_status(),
            'price'             => (string) $p->get_regular_price(),
            'sale_price'        => (string) $p->get_sale_price(),
            'purchase_cost'     => self::purchase_cost($p),
            'price_display'     => wsm_fa_money($p->get_price() !== '' ? $p->get_price() : 0),
            'manage_stock'      => (bool) $p->get_manage_stock(),
            'stock_quantity'    => $p->get_stock_quantity() === null ? null : (int) $p->get_stock_quantity(),
            'stock_status'      => $p->get_stock_status(),
            'low_stock_amount'  => $p->get_low_stock_amount() === '' ? null : (int) $p->get_low_stock_amount(),
            'description'       => $p->get_description(),
            'short_description' => $p->get_short_description(),
            'image_id'          => $img_id,
            'image_url'         => $img_url,
            'image_webp_url'    => $img_webp_url,
            'image_srcset'      => $img_srcset,
            'image_sizes'       => $img_sizes,
            'image_width'       => $img_width,
            'image_height'      => $img_height,
            'categories'        => $cats,
            'permalink'         => get_permalink($p->get_id()),
            'date_created'      => wsm_fa_date($created ? $created->date('Y-m-d H:i:s') : ''),
            'date_modified'     => wsm_fa_date($modified ? $modified->date('Y-m-d H:i:s') : ''),
            'custom_fields'     => WSM_Util::custom_field_values($p, 'products'),
        ) + WSM_Expiry::product_fields($p->get_id());
    }

    /** Compact card payload used by the POS; deliberately excludes descriptions and admin-only fields. */
    public static function format_pos(WC_Product $p) {
        $image_id = (int) $p->get_image_id();
        $image_url = $image_id && function_exists('wp_get_attachment_image_url')
            ? (string) wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail')
            : '';
        if ($image_url === '' && $image_id && function_exists('wp_get_attachment_url')) {
            $image_url = (string) wp_get_attachment_url($image_id);
        }
        $quantity = $p->get_stock_quantity();
        return array(
            'id'             => (int) $p->get_id(),
            'type'           => (string) $p->get_type(),
            'name'           => (string) $p->get_name(),
            'sku'            => (string) $p->get_sku(),
            'barcode'        => self::barcode($p),
            'sales_channel'  => self::sales_channel($p),
            'status'         => (string) $p->get_status(),
            'price'          => (string) $p->get_regular_price(),
            'sale_price'     => (string) $p->get_sale_price(),
            'manage_stock'   => (bool) $p->get_manage_stock(),
            'stock_quantity' => $quantity === null || $quantity === '' ? null : (int) $quantity,
            'stock_status'   => (string) $p->get_stock_status(),
            'image_url'      => $image_url,
            'categories'     => array_values(array_unique(array_map('absint', (array) $p->get_category_ids()))),
        );
    }

    /**
     * اگر SKU دقیق پیدا شد، باید فیلترهای فعال فهرست را هم رعایت کند.
     * @return WC_Product|false
     */
    private static function channel_matches(WC_Product $product, $channel) {
        if ($channel === '') { return true; }
        $actual = self::sales_channel($product);
        if ($channel === 'online') { return $actual !== 'in_person'; }
        if ($channel === 'in_person') { return $actual !== 'online'; }
        return true;
    }

    /**
     * WooCommerce may ignore a custom meta_query in wc_get_products(). Page through the
     * normal product query and apply the effective channel (including variation parents)
     * before calculating totals or slicing the requested page.
     * @return stdClass|WP_Error
     */
    private static function query_channel_products($query, $channel, $page, $limit) {
        $page = max(1, (int) $page);
        $limit = max(1, (int) $limit);
        $batch_size = 250;
        $query['limit'] = $batch_size;
        $query['page'] = 1;
        $query['paginate'] = true;
        unset($query['meta_query']);

        $first = wc_get_products($query);
        if (!is_object($first) || !isset($first->products) || !isset($first->total)) {
            return new WP_Error('wsm_product_channel_query_failed', 'فهرست کالاها با فیلتر کانال بارگیری نشد.', array('status' => 500));
        }
        $batch_pages = isset($first->max_num_pages)
            ? max(0, (int) $first->max_num_pages)
            : (int) ceil(max(0, (int) $first->total) / $batch_size);
        $offset = ($page - 1) * $limit;
        $matched = 0;
        $page_products = array();

        for ($batch_page = 1; $batch_page <= $batch_pages; $batch_page++) {
            $batch = $first;
            if ($batch_page > 1) {
                $query['page'] = $batch_page;
                $batch = wc_get_products($query);
                if (!is_object($batch) || !isset($batch->products)) {
                    return new WP_Error('wsm_product_channel_query_failed', 'فهرست کالاها با فیلتر کانال بارگیری نشد.', array('status' => 500));
                }
            }
            foreach ((array) $batch->products as $product) {
                if (!($product instanceof WC_Product) || !self::channel_matches($product, $channel)) { continue; }
                if ($matched >= $offset && $matched < $offset + $limit) { $page_products[] = $product; }
                $matched++;
            }
        }

        return (object) array(
            'products' => $page_products,
            'total' => $matched,
            'max_num_pages' => (int) ceil($matched / $limit),
        );
    }

    private static function exact_sku_product($id, $status, $stock_status, $category_id, $category_filter_ids, $channel = '') {
        $product = $id ? wc_get_product((int) $id) : false;
        if (!$product || !in_array($product->get_status(), self::STATUSES, true)) { return false; }
        if ($status !== '' && $product->get_status() !== $status) { return false; }
        if ($stock_status !== '' && $product->get_stock_status() !== $stock_status) { return false; }
        if (!self::channel_matches($product, $channel)) { return false; }
        if ($category_id) {
            $matches = has_term($category_filter_ids, 'product_cat', (int) $product->get_id());
            $parent_id = (int) wp_get_post_parent_id($product->get_id());
            if (!$matches && $parent_id) { $matches = has_term($category_filter_ids, 'product_cat', $parent_id); }
            if (!$matches) { return false; }
        }
        return $product;
    }

    private static function exact_barcode_product($code, $status, $stock_status, $category_id, $category_filter_ids, $channel = '') {
        global $wpdb;
        $code = self::normalize_barcode($code);
        if ($code === '') { return false; }
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type IN ('product','product_variation')
               AND p.post_status IN ('publish','draft','private','pending')
               AND pm.meta_key IN (%s,%s) AND pm.meta_value = %s
             ORDER BY CASE WHEN pm.meta_key = %s THEN 0 ELSE 1 END, p.ID DESC LIMIT 1",
            self::BARCODE_META, '_global_unique_id', $code, self::BARCODE_META
        ));
        return self::exact_sku_product($id, $status, $stock_status, $category_id, $category_filter_ids, $channel);
    }

    private static function barcode_owner($barcode, $exclude_id = 0) {
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key IN (%s,%s) AND meta_value = %s AND post_id <> %d
             ORDER BY CASE WHEN meta_key = %s THEN 0 ELSE 1 END LIMIT 1",
            self::BARCODE_META, '_global_unique_id', $barcode, (int) $exclude_id, self::BARCODE_META
        ));
        if ($id) { return $id; }
        $sku_id = function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($barcode) : 0;
        return $sku_id && $sku_id !== (int) $exclude_id ? $sku_id : 0;
    }

    /** ساخت یک EAN-13 داخلی با پیشوند رزروشدهٔ فروشگاهی (۲۰) و رقم کنترل. */
    public static function generate_barcode($args = array()) {
        $exclude_id = isset($args['exclude_id']) && is_scalar($args['exclude_id']) ? absint($args['exclude_id']) : 0;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $body = '20';
            for ($i = 0; $i < 10; $i++) { $body .= (string) random_int(0, 9); }
            $sum = 0;
            for ($i = 0; $i < 12; $i++) { $sum += (int) $body[$i] * ($i % 2 ? 3 : 1); }
            $barcode = $body . (string) ((10 - ($sum % 10)) % 10);
            if (!self::barcode_owner($barcode, $exclude_id)) {
                return array('barcode' => $barcode, 'symbology' => 'EAN-13');
            }
        }
        return new WP_Error('wsm_barcode_generation_failed', 'بارکد یکتای داخلی ساخته نشد؛ دوباره تلاش کنید.', array('status' => 500));
    }

    /** Add a bounded POS transient key to the eviction registry. */
    private static function remember_pos_cache_key($cache_key) {
        $keys = get_option(self::POS_CACHE_REGISTRY, array());
        if (!is_array($keys)) { $keys = array(); }
        $keys = array_values(array_filter($keys, function ($key) {
            return is_string($key) && strpos($key, self::POS_CACHE_PREFIX) === 0;
        }));
        if (!in_array($cache_key, $keys, true)) { $keys[] = $cache_key; }
        while (count($keys) > 200) {
            $oldest = array_shift($keys);
            delete_transient($oldest);
        }
        update_option(self::POS_CACHE_REGISTRY, $keys, false);
    }

    /** Invalidate every registered POS page after product creation, stock change, or sale. */
    public static function invalidate_pos_cache() {
        $keys = get_option(self::POS_CACHE_REGISTRY, array());
        if (is_array($keys)) {
            foreach ($keys as $cache_key) {
                if (is_string($cache_key) && strpos($cache_key, self::POS_CACHE_PREFIX) === 0) {
                    delete_transient($cache_key);
                }
            }
        }
        delete_option(self::POS_CACHE_REGISTRY);
        $epoch = max(1, absint(get_option(self::POS_CACHE_EPOCH, 1))) + 1;
        if ($epoch > 2147483640) { $epoch = 1; }
        update_option(self::POS_CACHE_EPOCH, $epoch, false);
    }

    /**
     * Fast, paginated POS-only catalog query. Stock filtering goes through WooCommerce's
     * product query API (lookup tables), never a postmeta stock_status meta_query.
     */
    public static function pos_list($args = array()) {
        $page = max(1, min(10000, absint(isset($args['page']) ? $args['page'] : 1)));
        $raw_limit = isset($args['limit']) ? $args['limit'] : (isset($args['per_page']) ? $args['per_page'] : 50);
        $limit = max(1, min(50, absint($raw_limit)));
        $search = WSM_Util::clean_text(isset($args['search']) ? $args['search'] : '', 100);
        $channel = isset($args['channel']) && in_array($args['channel'], array('online', 'in_person'), true) ? $args['channel'] : '';
        $stock_status = isset($args['stock_status']) && in_array($args['stock_status'], array('instock', 'outofstock', 'onbackorder'), true)
            ? $args['stock_status'] : 'instock';
        $category_id = isset($args['category']) ? absint($args['category']) : 0;
        $category_term = $category_id ? get_term($category_id, 'product_cat') : false;
        if (!$category_term || is_wp_error($category_term)) { $category_id = 0; }
        $category_filter_ids = array();
        if ($category_id) {
            $children = get_term_children($category_id, 'product_cat');
            $category_filter_ids = array_merge(array($category_id), is_wp_error($children) ? array() : array_map('intval', (array) $children));
        }

        // Exact scans return one lightweight record and avoid broad fuzzy/meta scans.
        if ($search !== '') {
            $exact_sku_id = function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($search) : 0;
            $exact_sku = self::exact_sku_product($exact_sku_id, 'publish', $stock_status, $category_id, $category_filter_ids, $channel);
            $exact_barcode = self::exact_barcode_product($search, 'publish', $stock_status, $category_id, $category_filter_ids, $channel);
            $exact = $exact_barcode ?: $exact_sku;
            if ($exact) {
                $items = $page === 1 ? array(self::format_pos($exact)) : array();
                return array('items' => $items, 'total' => 1, 'total_pages' => 1, 'page' => $page, 'fuzzy' => false);
            }
        }

        $device_id = class_exists('WSM_Permissions') ? (int) WSM_Permissions::current_device_id() : 0;
        $epoch = max(1, absint(get_option(self::POS_CACHE_EPOCH, 1)));
        $cacheable = $search === '';
        $cache_context = array(
            'epoch' => $epoch,
            'device_id' => $device_id,
            'page' => $page,
            'limit' => $limit,
            'stock_status' => $stock_status,
            'category' => $category_id,
            'channel' => $channel,
        );
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($cache_context) : json_encode($cache_context);
        $cache_key = $cacheable && is_string($encoded) && $encoded !== ''
            ? self::POS_CACHE_PREFIX . md5($encoded)
            : '';
        if ($cache_key !== '') {
            $cached = get_transient($cache_key);
            if (is_array($cached)) { return $cached; }
        }

        $query = array(
            'limit'       => $limit,
            'page'        => $page,
            'paginate'    => true,
            'status'      => 'publish',
            'stock_status'=> $stock_status,
            'orderby'     => 'date',
            'order'       => 'DESC',
        );
        if ($search !== '') { $query['s'] = $search; }
        if ($category_id) { $query['category'] = array((string) $category_term->slug); }
        if ($channel !== '') {
            $allowed_channels = $channel === 'online' ? array('online', 'both') : array('in_person', 'both');
            $query['meta_query'] = array(
                'relation' => 'OR',
                array('key' => '_wsm_sales_channel', 'value' => $allowed_channels, 'compare' => 'IN'),
                array('key' => '_wsm_sales_channel', 'value' => '', 'compare' => '='),
                array('key' => '_wsm_sales_channel', 'compare' => 'NOT EXISTS'),
            );
        }
        $result = $channel !== ''
            ? self::query_channel_products($query, $channel, $page, $limit)
            : wc_get_products($query);
        if (is_wp_error($result)) { return $result; }
        $products = is_object($result) && isset($result->products) ? (array) $result->products : array();
        $items = array();
        foreach ($products as $product) {
            if ($product instanceof WC_Product) { $items[] = self::format_pos($product); }
        }
        $total = is_object($result) && isset($result->total) ? max(0, (int) $result->total) : count($items);
        $total_pages = is_object($result) && isset($result->max_num_pages)
            ? max(0, (int) $result->max_num_pages)
            : (int) ceil($total / $limit);
        $response = array('items' => $items, 'total' => $total, 'total_pages' => $total_pages, 'page' => $page, 'fuzzy' => false);
        if ($cache_key !== '') {
            set_transient($cache_key, $response, 900);
            self::remember_pos_cache_key($cache_key);
        }
        return $response;
    }

    /**
     * فهرست با صفحه‌بندی و جستجو.
     * اگر جستجوی دقیق نتیجه نداد، جستجوی فازی (با غلط املایی) روی نام و SKU اجرا می‌شود.
     */
    public static function list($args = array()) {
        global $wpdb;
        $page     = max(1, (int) (isset($args['page']) ? $args['page'] : 1));
        $per_page = max(1, min(100, (int) (isset($args['per_page']) ? $args['per_page'] : 20)));
        $search   = WSM_Util::clean_text(isset($args['search']) ? $args['search'] : '', 100);
        $status   = isset($args['status']) && in_array($args['status'], self::STATUSES, true) ? $args['status'] : '';
        $stock_status = isset($args['stock_status']) && in_array($args['stock_status'], array('instock', 'outofstock', 'onbackorder'), true)
            ? $args['stock_status'] : '';
        $channel = isset($args['channel']) && in_array($args['channel'], array('online', 'in_person'), true) ? $args['channel'] : '';
        $category_id = isset($args['category']) ? absint($args['category']) : 0;
        $category_term = $category_id ? get_term($category_id, 'product_cat') : false;
        if (!$category_term || is_wp_error($category_term)) {
            $category_id = 0;
        }
        $category_filter_ids = array();
        if ($category_id) {
            $children = get_term_children($category_id, 'product_cat');
            $category_filter_ids = array_merge(array($category_id), is_wp_error($children) ? array() : array_map('intval', (array) $children));
        }

        $query = array(
            'limit'    => $per_page,
            'page'     => $page,
            'paginate' => true,
            'orderby'  => 'date',
            'order'    => 'DESC',
        );
        // «همه» در رابط مدیریت باید پیش‌نویس‌ها را هم نشان دهد تا چرخهٔ حذف دومرحله‌ای کامل باشد.
        $query['status'] = $status ? $status : self::STATUSES;
        if ($search !== '') {
            $query['s'] = $search;
        }
        if ($stock_status !== '') {
            $query['stock_status'] = $stock_status;
        }
        if ($category_id) {
            // WooCommerce accepts category slugs in its product query API.
            $query['category'] = array($category_term->slug);
        }
        if ($channel !== '') {
            $allowed_channels = $channel === 'online' ? array('online', 'both') : array('in_person', 'both');
            $query['meta_query'] = array(
                'relation' => 'OR',
                array('key' => '_wsm_sales_channel', 'value' => $allowed_channels, 'compare' => 'IN'),
                array('key' => '_wsm_sales_channel', 'value' => '', 'compare' => '='),
                array('key' => '_wsm_sales_channel', 'compare' => 'NOT EXISTS'),
            );
        }

        $exact_sku_id = $search !== '' && function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($search) : 0;
        $exact_sku_product = self::exact_sku_product($exact_sku_id, $status, $stock_status, $category_id, $category_filter_ids, $channel);
        $exact_barcode_product = self::exact_barcode_product($search, $status, $stock_status, $category_id, $category_filter_ids, $channel);
        $exact_product = $exact_barcode_product ?: $exact_sku_product;
        $result = $channel !== ''
            ? self::query_channel_products($query, $channel, $page, $per_page)
            : wc_get_products($query);
        if (is_wp_error($result)) { return $result; }
        $fuzzy = false;

        if ($search !== '' && (int) $result->total === 0) {
            $rows = $wpdb->get_results(
                "SELECT p.ID, p.post_title, pm.meta_value AS sku, pb.meta_value AS barcode
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
                 LEFT JOIN {$wpdb->postmeta} pb ON pb.post_id = p.ID AND pb.meta_key = '_wsm_barcode'
                 WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','private','pending')
                 ORDER BY p.ID DESC LIMIT 3000"
            );
            $scored = array();
            foreach ((array) $rows as $row) {
                if ($status && get_post_status($row->ID) !== $status) {
                    continue;
                }
                if ($stock_status !== '') {
                    $candidate = wc_get_product((int) $row->ID);
                    if (!$candidate || $candidate->get_stock_status() !== $stock_status) {
                        continue;
                    }
                }
                if ($category_id && !has_term($category_filter_ids, 'product_cat', (int) $row->ID)) {
                    continue;
                }
                $candidate = wc_get_product((int) $row->ID);
                if (!$candidate || !self::channel_matches($candidate, $channel)) {
                    continue;
                }
                $score = max(
                    WSM_Persian_Normalizer::score($search, $row->post_title),
                    $row->sku ? WSM_Persian_Normalizer::score($search, $row->sku) : 0,
                    $row->barcode ? WSM_Persian_Normalizer::score($search, $row->barcode) : 0
                );
                if ($score >= 0.7) {
                    $scored[(int) $row->ID] = $score;
                }
            }
            if ($exact_sku_product) {
                $scored[(int) $exact_sku_product->get_id()] = 2.0;
            }
            if ($exact_barcode_product) {
                $scored[(int) $exact_barcode_product->get_id()] = 2.1;
            }
            arsort($scored);
            $ids   = array_keys($scored);
            $total = count($ids);
            $slice = array_slice($ids, ($page - 1) * $per_page, $per_page);
            $items = array();
            foreach ($slice as $pid) {
                $p = wc_get_product($pid);
                if ($p) {
                    $items[] = self::format($p);
                }
            }
            $fuzzy = true;
            return array(
                'items'       => $items,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $per_page),
                'page'        => $page,
                'fuzzy'       => $fuzzy,
            );
        }

        $products = (array) $result->products;
        if ($page === 1 && $exact_product) {
            $exact_id = (int) $exact_product->get_id();
            $products = array_values(array_filter($products, function ($candidate) use ($exact_id) {
                return (int) $candidate->get_id() !== $exact_id;
            }));
            array_unshift($products, $exact_product);
            $products = array_slice($products, 0, $per_page);
        }
        $items = array();
        foreach ($products as $p) {
            $items[] = self::format($p);
        }
        return array(
            'items'       => $items,
            'total'       => (int) $result->total,
            'total_pages' => (int) $result->max_num_pages,
            'page'        => $page,
            'fuzzy'       => $fuzzy,
        );
    }

    public static function get($id) {
        $p = wc_get_product((int) $id);
        if (!$p) {
            return new WP_Error('wsm_not_found', 'محصول پیدا نشد.', array('status' => 404));
        }
        return self::format($p);
    }

    private static function decimal_input($value, $label) {
        if (!is_scalar($value)) {
            return new WP_Error('wsm_product_value_invalid', $label . ' نامعتبر است.', array('status' => 400));
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        $raw = str_replace(array('٫', '٬'), array('.', ''), self::normalize_digits($raw));
        $decimal = wc_format_decimal($raw);
        if (!is_numeric($decimal) || !is_finite((float) $decimal) || (float) $decimal < 0 || (float) $decimal > 1000000000000) {
            return new WP_Error('wsm_product_value_invalid', $label . ' باید عددی و نامنفی باشد.', array('status' => 400));
        }
        return $decimal;
    }

    private static function integer_input($value, $label, $min, $max) {
        if (!is_scalar($value)) {
            return new WP_Error('wsm_product_value_invalid', $label . ' باید عدد صحیح باشد.', array('status' => 400));
        }
        $normalized = self::normalize_digits(trim((string) $value));
        if (!preg_match('/^-?\d+$/D', $normalized)) {
            return new WP_Error('wsm_product_value_invalid', $label . ' باید عدد صحیح باشد.', array('status' => 400));
        }
        $negative = strpos($normalized, '-') === 0;
        $digits = ltrim($negative ? substr($normalized, 1) : $normalized, '0');
        $normalized = $digits === '' ? '0' : (($negative ? '-' : '') . $digits);
        if (filter_var($normalized, FILTER_VALIDATE_INT) === false) {
            return new WP_Error('wsm_product_value_invalid', $label . ' باید عدد صحیح باشد.', array('status' => 400));
        }
        $number = (int) $normalized;
        if ($number < $min || $number > $max) {
            return new WP_Error('wsm_product_value_invalid', $label . ' خارج از محدودهٔ مجاز است.', array('status' => 400));
        }
        return $number;
    }

    /**
     * اعمال داده‌های ورودی روی محصول
     * @return true|WP_Error
     */
    private static function apply(WC_Product $p, $data, $is_new) {
        if (!is_array($data)) {
            return new WP_Error('wsm_product_data_invalid', 'اطلاعات کالا نامعتبر است.', array('status' => 400));
        }
        if (array_key_exists('expiry_date', $data)) {
            $expiry_ok = WSM_Expiry::validate_input($data['expiry_date']);
            if (is_wp_error($expiry_ok)) {
                return $expiry_ok;
            }
        }
        if (isset($data['name'])) {
            $name = WSM_Util::clean_text($data['name'], 255);
            if ($name === '') {
                return new WP_Error('wsm_name_required', 'نام محصول الزامی است.', array('status' => 400));
            }
            $p->set_name($name);
        } elseif ($is_new) {
            return new WP_Error('wsm_name_required', 'نام محصول الزامی است.', array('status' => 400));
        }

        if (array_key_exists('sku', $data)) {
            if (!is_scalar($data['sku'])) {
                return new WP_Error('wsm_sku_invalid', 'SKU کالا نامعتبر است.', array('status' => 400));
            }
            $sku = wc_clean((string) $data['sku']);
            if ($sku !== '') {
                $owner = wc_get_product_id_by_sku($sku);
                if ($owner && (int) $owner !== (int) $p->get_id()) {
                    return new WP_Error('wsm_sku_exists', 'این SKU قبلاً استفاده شده است.', array('status' => 409));
                }
                $barcode_owner = self::barcode_owner($sku, $p->get_id());
                if ($barcode_owner) {
                    return new WP_Error('wsm_sku_barcode_exists', 'این SKU قبلاً به‌عنوان بارکد کالای دیگری ثبت شده است.', array('status' => 409));
                }
            }
            $p->set_sku($sku);
        }
        if (array_key_exists('price', $data)) {
            $price = self::decimal_input($data['price'], 'قیمت');
            if (is_wp_error($price)) { return $price; }
            $p->set_regular_price($price);
        }
        if (array_key_exists('sale_price', $data)) {
            $sale_price = self::decimal_input($data['sale_price'], 'قیمت فروش ویژه');
            if (is_wp_error($sale_price)) { return $sale_price; }
            $p->set_sale_price($sale_price);
        }
        if (array_key_exists('purchase_cost', $data)) {
            $purchase_cost = self::decimal_input($data['purchase_cost'], 'بهای خرید');
            if (is_wp_error($purchase_cost)) { return $purchase_cost; }
            if ($purchase_cost === '') {
                $p->delete_meta_data(self::PURCHASE_COST_META);
                if (method_exists($p, 'set_cogs_value')) { $p->set_cogs_value(null); }
            } else {
                $purchase_cost = wc_format_decimal($purchase_cost, wc_get_price_decimals());
                $p->update_meta_data(self::PURCHASE_COST_META, $purchase_cost);
                // Keep native WooCommerce COGS in step when supported; custom meta remains the fallback.
                if (method_exists($p, 'set_cogs_value')) { $p->set_cogs_value($purchase_cost); }
            }
        }
        if (array_key_exists('manage_stock', $data)) {
            if (!is_scalar($data['manage_stock'])) {
                return new WP_Error('wsm_product_value_invalid', 'تنظیم مدیریت موجودی نامعتبر است.', array('status' => 400));
            }
            $p->set_manage_stock(rest_sanitize_boolean($data['manage_stock']));
        }
        if (array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null && $data['stock_quantity'] !== '') {
            $quantity = self::integer_input($data['stock_quantity'], 'موجودی', 0, 1000000000);
            if (is_wp_error($quantity)) { return $quantity; }
            $p->set_manage_stock(true);
            $p->set_stock_quantity($quantity);
        }
        if (array_key_exists('stock_status', $data) && !$p->get_manage_stock()) {
            if (!is_scalar($data['stock_status'])) {
                return new WP_Error('wsm_product_value_invalid', 'وضعیت موجودی نامعتبر است.', array('status' => 400));
            }
            if (in_array($data['stock_status'], array('instock', 'outofstock', 'onbackorder'), true)) {
                $p->set_stock_status($data['stock_status']);
            }
        }
        if (array_key_exists('low_stock_amount', $data) && $data['low_stock_amount'] !== null && $data['low_stock_amount'] !== '') {
            $low_stock = self::integer_input($data['low_stock_amount'], 'آستانهٔ موجودی کم', 0, 1000000000);
            if (is_wp_error($low_stock)) { return $low_stock; }
            $p->set_low_stock_amount($low_stock);
        }
        if (isset($data['status'])) {
            if (!is_scalar($data['status']) || !in_array($data['status'], self::STATUSES, true)) {
                return new WP_Error('wsm_bad_status', 'وضعیت محصول نامعتبر است.', array('status' => 400));
            }
            $p->set_status($data['status']);
        }
        if (array_key_exists('description', $data)) {
            if (!is_scalar($data['description'])) {
                return new WP_Error('wsm_product_value_invalid', 'توضیحات کالا نامعتبر است.', array('status' => 400));
            }
            $p->set_description(wp_kses_post((string) $data['description']));
        }
        if (array_key_exists('short_description', $data)) {
            if (!is_scalar($data['short_description'])) {
                return new WP_Error('wsm_product_value_invalid', 'توضیح کوتاه کالا نامعتبر است.', array('status' => 400));
            }
            $p->set_short_description(wp_kses_post((string) $data['short_description']));
        }
        if (array_key_exists('categories', $data)) {
            if (!is_array($data['categories'])) {
                return new WP_Error('wsm_product_categories_invalid', 'فهرست دسته‌بندی نامعتبر است.', array('status' => 400));
            }
            $category_ids = array();
            foreach ($data['categories'] as $category_id) {
                if (!is_scalar($category_id) || filter_var((string) $category_id, FILTER_VALIDATE_INT) === false) {
                    return new WP_Error('wsm_product_categories_invalid', 'شناسهٔ دسته‌بندی نامعتبر است.', array('status' => 400));
                }
                if ((int) $category_id > 0) { $category_ids[] = (int) $category_id; }
            }
            $p->set_category_ids(array_values(array_unique($category_ids)));
        }
        if (array_key_exists('image_id', $data)) {
            $image_id = self::integer_input($data['image_id'], 'شناسهٔ تصویر', 0, PHP_INT_MAX);
            if (is_wp_error($image_id)) { return $image_id; }
            $p->set_image_id($image_id);
        }
        if (array_key_exists('barcode', $data)) {
            if (!is_scalar($data['barcode'])) {
                return new WP_Error('wsm_barcode_invalid', 'بارکد کالا نامعتبر است.', array('status' => 400));
            }
            $barcode = self::normalize_barcode($data['barcode']);
            if (strlen($barcode) > 64 || ($barcode !== '' && preg_match('/[^\x20-\x7E]/', $barcode))) {
                return new WP_Error('wsm_barcode_invalid', 'بارکد باید حداکثر ۶۴ نویسهٔ قابل‌چاپ لاتین باشد.', array('status' => 400));
            }
            if ($barcode !== '' && self::barcode_owner($barcode, $p->get_id())) {
                return new WP_Error('wsm_barcode_duplicate', 'این بارکد قبلاً برای کالای دیگری ثبت شده است.', array('status' => 409));
            }
            $p->update_meta_data(self::BARCODE_META, $barcode);
        }
        if (array_key_exists('sales_channel', $data)) {
            if (!is_scalar($data['sales_channel'])) {
                return new WP_Error('wsm_sales_channel_invalid', 'کانال فروش کالا نامعتبر است.', array('status' => 400));
            }
            $channel = sanitize_key((string) $data['sales_channel']);
            if (!in_array($channel, array('both', 'online', 'in_person'), true)) {
                return new WP_Error('wsm_sales_channel_invalid', 'کانال فروش باید آنلاین و حضوری، فقط آنلاین یا فقط حضوری باشد.', array('status' => 400));
            }
            $p->update_meta_data('_wsm_sales_channel', $channel);
        }
        $regular_price = $p->get_regular_price();
        $sale_price = $p->get_sale_price();
        if ($sale_price !== '' && $sale_price !== null && $regular_price !== '' && $regular_price !== null
            && (float) $sale_price > (float) $regular_price) {
            return new WP_Error('wsm_sale_price_invalid', 'قیمت فروش ویژه نمی‌تواند بیشتر از قیمت اصلی باشد.', array('status' => 400));
        }
        return true;
    }

    public static function create($data) {
        $p = new WC_Product_Simple();
        $ok = self::apply($p, $data, true);
        if (is_wp_error($ok)) {
            return $ok;
        }
        $p->set_status(isset($data['status']) ? $data['status'] : 'publish');
        $id = $p->save();
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            WSM_Util::save_custom_field_values($p, 'products', $data['custom_fields']);
            $p->save();
        }
        if (array_key_exists('expiry_date', $data)) {
            WSM_Expiry::save_product_date($id, $data['expiry_date']);
        }
        WSM_Activity_Log::log('create', 'product', $id, $p->get_name(), '', self::snapshot($p), 'success');
        return self::format(wc_get_product($id));
    }

    public static function update($id, $data) {
        $p = wc_get_product((int) $id);
        if (!$p) {
            return new WP_Error('wsm_not_found', 'محصول پیدا نشد.', array('status' => 404));
        }
        $before = self::snapshot($p);
        $ok = self::apply($p, $data, false);
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            WSM_Util::save_custom_field_values($p, 'products', $data['custom_fields']);
        }
        $p->save();
        if (array_key_exists('expiry_date', $data)) {
            WSM_Expiry::save_product_date($p->get_id(), $data['expiry_date']);
        }
        WSM_Activity_Log::log('update', 'product', $p->get_id(), $p->get_name(), $before, self::snapshot($p), 'success');
        return self::format(wc_get_product($p->get_id()));
    }

    /**
     * چرخهٔ حذف کالا: حذف اول کالا را پیش‌نویس می‌کند؛ حذف بعدی از پیش‌نویس آن را به زباله‌دان می‌برد.
     * پاک‌سازی نهاییِ زباله‌دان مطابق EMPTY_TRASH_DAYS وردپرس انجام می‌شود.
     */
    public static function delete($id, $force = false) {
        $p = wc_get_product((int) $id);
        if (!$p) {
            return new WP_Error('wsm_not_found', 'محصول پیدا نشد.', array('status' => 404));
        }
        $before = self::snapshot($p);
        $name = $p->get_name();
        if ($p->get_status() !== 'draft') {
            $p->set_status('draft');
            $p->save();
            WSM_Activity_Log::log('update', 'product', (int) $id, $name, $before, self::snapshot($p), 'success');
            return array(
                'deleted' => false,
                'id'      => (int) $id,
                'status'  => 'draft',
                'message' => 'کالا از فروشگاه خارج و به پیش‌نویس منتقل شد. برای انتقال به زباله‌دان، حذف را دوباره بزنید.',
            );
        }

        $trash_days = defined('EMPTY_TRASH_DAYS') ? max(0, (int) EMPTY_TRASH_DAYS) : 30;
        if ($trash_days < 1) {
            return new WP_Error('wsm_trash_disabled', 'سطل زبالهٔ وردپرس غیرفعال است؛ مقدار EMPTY_TRASH_DAYS را روی حداقل ۱ روز تنظیم کنید.', array('status' => 409));
        }
        $p->delete(false);
        if (get_post_status((int) $id) !== 'trash') {
            return new WP_Error('wsm_trash_failed', 'کالا به زباله‌دان منتقل نشد؛ تنظیمات زباله‌دان وردپرس را بررسی کنید.', array('status' => 500));
        }
        WSM_Activity_Log::log('delete', 'product', (int) $id, $name, $before, 'trash', 'success');
        return array(
            'deleted'         => true,
            'id'              => (int) $id,
            'status'          => 'trash',
            'purge_after_days' => $trash_days,
            'message'         => 'کالا به زباله‌دان منتقل شد.',
        );
    }

    public static function copy($id) {
        $src = wc_get_product((int) $id);
        if (!$src) {
            return new WP_Error('wsm_not_found', 'محصول پیدا نشد.', array('status' => 404));
        }
        $new = new WC_Product_Simple();
        $new->set_name($src->get_name() . ' (کپی)');
        $new->set_status('draft');
        $new->update_meta_data('_wsm_sales_channel', self::sales_channel($src));
        $new->set_regular_price($src->get_regular_price());
        $new->set_sale_price($src->get_sale_price());
        $purchase_cost = self::purchase_cost($src);
        if ($purchase_cost !== '') {
            $new->update_meta_data(self::PURCHASE_COST_META, $purchase_cost);
            if (method_exists($new, 'set_cogs_value')) { $new->set_cogs_value($purchase_cost); }
        }
        $new->set_description($src->get_description());
        $new->set_short_description($src->get_short_description());
        $new->set_category_ids($src->get_category_ids());
        $new->set_image_id($src->get_image_id());
        $new->set_manage_stock($src->get_manage_stock());
        if ($src->get_manage_stock()) {
            $new->set_stock_quantity($src->get_stock_quantity());
        } else {
            $new->set_stock_status($src->get_stock_status());
        }
        $new_id = $new->save();
        WSM_Activity_Log::log('copy', 'product', $new_id, $new->get_name(), $src->get_name(), self::snapshot($new), 'success');
        return self::format(wc_get_product($new_id));
    }

    /**
     * آپلود تصویر (فیلد multipart با نام file)
     */
    public static function upload_image($id, $files) {
        $p = wc_get_product((int) $id);
        if (!$p) {
            return new WP_Error('wsm_not_found', 'محصول پیدا نشد.', array('status' => 404));
        }
        if (empty($files['file'])) {
            return new WP_Error('wsm_file_required', 'فایل تصویر ارسال نشده است.', array('status' => 400));
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload('file', 0);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        $p->set_image_id((int) $attachment_id);
        $p->save();
        WSM_Activity_Log::log('update', 'product', $p->get_id(), $p->get_name(), '', 'تصویر جدید', 'success');
        return self::format(wc_get_product($p->get_id()));
    }

    private static function snapshot(WC_Product $p) {
        return array(
            'name'       => $p->get_name(),
            'sku'        => $p->get_sku(),
            'barcode'    => self::barcode($p),
            'sales_channel' => self::sales_channel($p),
            'price'      => $p->get_regular_price(),
            'sale_price' => $p->get_sale_price(),
            'purchase_cost' => self::purchase_cost($p),
            'stock'      => $p->get_manage_stock() ? $p->get_stock_quantity() : $p->get_stock_status(),
            'status'     => $p->get_status(),
        );
    }
}

/**
 * تاریخ انقضای شمسی کالا؛ در نبود دادهٔ داخلی، متای افزونه‌های انقضا را هم می‌خواند.
 * این کلاس عمداً در فایل موجود محصولات نگهداری شده تا بسته فایل تازه‌ای لازم نداشته باشد.
 */
class WSM_Expiry {
    const INTERNAL_KEY = '_wsm_expiry_date';
    const DISABLED_KEY = '_wsm_expiry_external_disabled';
    const DETECT_CACHE = 'wsm_expiry_meta_key_detected';
    const ITEMS_CACHE = 'wsm_expiry_items_v2';

    /** کلید سفارشی (در صورت تنظیم)؛ مقدار خالی یعنی تشخیص خودکار. */
    public static function custom_meta_key() {
        $settings = WSM_Util::settings();
        $key = isset($settings['expiry_meta_key']) ? trim((string) $settings['expiry_meta_key']) : '';
        if ($key === '' || !preg_match('/^[A-Za-z0-9_:\-.]{1,120}$/', $key)) {
            return '';
        }
        return $key;
    }

    /** کلید متای خارجی که از نام و مقدار تاریخ شناسایی شده است. */
    public static function detected_meta_key() {
        $cached = get_transient(self::DETECT_CACHE);
        if ($cached !== false) {
            return $cached === '__none__' ? '' : (string) $cached;
        }

        global $wpdb;
        $statuses = array('publish', 'draft', 'private', 'pending');
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $priority = array(
            '_expiry_date', 'expiry_date', '_product_expiry_date', 'product_expiry_date',
            '_expiration_date', 'expiration_date', '_product_expiration_date', 'product_expiration_date',
            '_wc_expiry_date', 'wc_expiry_date', '_expiry', 'expiry', '_expiration', 'expiration',
        );
        // کلیدهای شناخته‌شده بررسی می‌شوند ابتدا (با ایندکس meta_key)، پیش از جستجوی عمومی REGEXP.
        $fast_placeholders = implode(',', array_fill(0, count($priority), '%s'));
        $fast_sql = "SELECT pm.meta_key, COUNT(DISTINCT pm.post_id) AS total
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE p.post_type = 'product'
                       AND p.post_status IN ($status_placeholders)
                       AND pm.meta_key IN ($fast_placeholders)
                     GROUP BY pm.meta_key";
        $fast_rows = (array) $wpdb->get_results($wpdb->prepare($fast_sql, array_merge($statuses, $priority)), ARRAY_A);
        usort($fast_rows, function ($a, $b) use ($priority) {
            $pa = array_search((string) $a['meta_key'], $priority, true);
            $pb = array_search((string) $b['meta_key'], $priority, true);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return (int) $b['total'] <=> (int) $a['total'];
        });
        foreach ($fast_rows as $row) {
            $key = (string) $row['meta_key'];
            $values = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_id DESC LIMIT 40",
                $key
            ));
            foreach ((array) $values as $value) {
                if (self::parse_date($value) !== '') {
                    set_transient(self::DETECT_CACHE, $key, 900);
                    return $key;
                }
            }
        }
        $sql = "SELECT pm.meta_key, COUNT(DISTINCT pm.post_id) AS total
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type = 'product'
                  AND p.post_status IN ($status_placeholders)
                  AND pm.meta_key REGEXP %s
                  AND pm.meta_key <> %s
                GROUP BY pm.meta_key
                ORDER BY total DESC
                LIMIT 80";
        $params = array_merge($statuses, array('(expir|expiry|expiration|expire|best.?before)', self::INTERNAL_KEY));
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $priority = array(
            '_expiry_date', 'expiry_date', '_product_expiry_date', 'product_expiry_date',
            '_expiration_date', 'expiration_date', '_product_expiration_date', 'product_expiration_date',
            '_wc_expiry_date', 'wc_expiry_date', '_expiry', 'expiry', '_expiration', 'expiration',
        );
        usort($rows, function ($a, $b) use ($priority) {
            $pa = array_search((string) $a['meta_key'], $priority, true);
            $pb = array_search((string) $b['meta_key'], $priority, true);
            $pa = $pa === false ? 999 : $pa;
            $pb = $pb === false ? 999 : $pb;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return (int) $b['total'] <=> (int) $a['total'];
        });

        $found = '';
        foreach ($rows as $row) {
            $key = (string) $row['meta_key'];
            $values = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_id DESC LIMIT 40",
                $key
            ));
            foreach ((array) $values as $value) {
                if (self::parse_date($value) !== '') {
                    $found = $key;
                    break 2;
                }
            }
        }
        set_transient(self::DETECT_CACHE, $found !== '' ? $found : '__none__', 900);
        return $found;
    }

    /** کلید قابل خواندن: ابتدا تنظیم سفارشی، سپس تشخیص خودکار. */
    public static function meta_key_in_use() {
        $custom = self::custom_meta_key();
        return $custom !== '' ? $custom : self::detected_meta_key();
    }

    public static function invalidate_detection() {
        delete_transient(self::DETECT_CACHE);
        self::invalidate_items_cache();
    }

    /** پاک کردن فهرست محاسبه‌شده پس از تغییر محصول/موجودی یا تنظیم کلید تاریخ. */
    public static function invalidate_items_cache($arg1 = null, $arg2 = null, $arg3 = null) {
        delete_transient(self::ITEMS_CACHE);
    }

    /** پاک‌سازی کش هنگام انتقال محصول به/از زباله‌دان یا حذف آن. */
    public static function invalidate_items_cache_for_post($post_id, $post = null) {
        $post_type = is_object($post) && isset($post->post_type)
            ? (string) $post->post_type
            : get_post_type((int) $post_id);
        if (in_array($post_type, array('product', 'product_variation'), true)) {
            self::invalidate_items_cache();
        }
    }

    /** تبدیل رقم‌های فارسی و عربی به انگلیسی. */
    private static function normalize_digits($value) {
        return strtr((string) $value, array(
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ));
    }

    /** تبدیل جلالی به میلادی بر پایهٔ الگوریتم استاندارد. */
    public static function jalali_to_gregorian($jy, $jm, $jd) {
        $jy = (int) $jy - 979;
        $jm = (int) $jm;
        $jd = (int) $jd;
        $days = (365 * $jy) + (int) floor($jy / 33) * 8 + (int) floor((($jy % 33) + 3) / 4);
        $days += $jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186;
        $days += $jd - 1;
        $gdn = $days + 79;
        $gy = 1600 + 400 * (int) floor($gdn / 146097);
        $gdn %= 146097;
        $leap = true;
        if ($gdn >= 36525) {
            $gdn--;
            $gy += 100 * (int) floor($gdn / 36524);
            $gdn %= 36524;
            if ($gdn >= 365) {
                $gdn++;
            } else {
                $leap = false;
            }
        }
        $gy += 4 * (int) floor($gdn / 1461);
        $gdn %= 1461;
        if ($gdn >= 366) {
            $leap = false;
            $gdn--;
            $gy += (int) floor($gdn / 365);
            $gdn %= 365;
        }
        $gd = $gdn + 1;
        $month_days = array(0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
        $gm = 1;
        while ($gm <= 12 && $gd > $month_days[$gm]) {
            $gd -= $month_days[$gm];
            $gm++;
        }
        return array((int) $gy, (int) $gm, (int) $gd);
    }

    /** تاریخ شمسی معتبر را به YYYY-MM-DD تبدیل می‌کند؛ در خطا false برمی‌گرداند. */
    private static function jalali_parts_to_iso($jy, $jm, $jd) {
        $jy = (int) $jy;
        $jm = (int) $jm;
        $jd = (int) $jd;
        if ($jy < 1200 || $jy > 1600 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > ($jm <= 6 ? 31 : ($jm <= 11 ? 30 : 30))) {
            return false;
        }
        list($gy, $gm, $gd) = self::jalali_to_gregorian($jy, $jm, $jd);
        if (!checkdate($gm, $gd, $gy)) {
            return false;
        }
        $round_trip = wsm_gregorian_to_jalali($gy, $gm, $gd);
        if ((int) $round_trip[0] !== $jy || (int) $round_trip[1] !== $jm || (int) $round_trip[2] !== $jd) {
            return false;
        }
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /** ورودی فرم/API: تاریخ شمسی مانند ۱۴۰۵/۰۷/۳۰. */
    public static function normalize_input($value) {
        $raw = trim(self::normalize_digits(is_scalar($value) ? $value : ''));
        if ($raw === '') {
            return '';
        }
        if (!preg_match('/^(\d{4})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{1,2})$/', $raw, $m)) {
            return false;
        }
        return self::jalali_parts_to_iso((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    public static function validate_input($value) {
        $date = self::normalize_input($value);
        if ($date === false) {
            return new WP_Error('wsm_bad_expiry_date', 'تاریخ انقضا را به‌صورت شمسی و معتبر، مانند ۱۴۰۵/۰۷/۳۰، وارد کنید.', array('status' => 400));
        }
        return true;
    }

    /** تشخیص و تبدیل تاریخ‌های متداول ذخیره‌شده توسط افزونه‌های دیگر. */
    public static function parse_date($value) {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach (array('expiry_date', 'expiration_date', 'expiry', 'expiration', 'date', 'end_date', 'value', 'timestamp') as $key) {
                if (isset($value[$key])) {
                    $date = self::parse_date($value[$key]);
                    if ($date !== '') {
                        return $date;
                    }
                }
            }
            if (isset($value['year'], $value['month'], $value['day'])) {
                $year = (int) self::normalize_digits($value['year']);
                $month = (int) self::normalize_digits($value['month']);
                $day = (int) self::normalize_digits($value['day']);
                if ($year >= 1200 && $year <= 1600) {
                    $date = self::jalali_parts_to_iso($year, $month, $day);
                    return $date === false ? '' : $date;
                }
                return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
            }
            return '';
        }
        if (!is_scalar($value)) {
            return '';
        }
        $raw = trim(self::normalize_digits((string) $value));
        if ($raw === '' || $raw === '0' || strpos($raw, '0000-00-00') === 0) {
            return '';
        }
        if (function_exists('maybe_unserialize')) {
            $unserialized = maybe_unserialize($raw);
            if (is_array($unserialized) || is_object($unserialized)) {
                return self::parse_date($unserialized);
            }
        }
        if (preg_match('/^\d{10,13}$/', $raw)) {
            $timestamp = (int) $raw;
            if (strlen($raw) === 13) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            if ($timestamp > 0) {
                return wp_date('Y-m-d', $timestamp);
            }
        }
        // ISO datetimeها باید پیش از الگوی تاریخ عمومی خوانده شوند؛ وگرنه ساعت/offset دور انداخته
        // می‌شود و timestamp نزدیک نیمه‌شب ممکن است در تاریخ محلی سایت یک روز جابه‌جا شود.
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})[T ](.+)$/i', $raw, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            if ($year >= 1200 && $year <= 1600) {
                $date = self::jalali_parts_to_iso($year, $month, $day);
                return $date === false ? '' : $date;
            }
            if (!checkdate($month, $day, $year)) {
                return '';
            }
            try {
                $date = new DateTimeImmutable($raw, wp_timezone());
                return $date->setTimezone(wp_timezone())->format('Y-m-d');
            } catch (Exception $e) {
                // حفظ رفتار قبلی برای زمان‌های محلیِ بدون timezone که PHP نتواند تجزیه کند.
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        if (preg_match('/^(\d{4})[\/.\-](\d{1,2})[\/.\-](\d{1,2})(?:[ T].*)?$/', $raw, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            if ($year >= 1200 && $year <= 1600) {
                $date = self::jalali_parts_to_iso($year, $month, $day);
                return $date === false ? '' : $date;
            }
            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $raw, $m)) {
            $first = (int) $m[1];
            $second = (int) $m[2];
            $year = (int) $m[3];
            if ($year >= 1200 && $year <= 1600) {
                $date = self::jalali_parts_to_iso($year, $second, $first);
                return $date === false ? '' : $date;
            }
            // ابتدا روز/ماه/سال؛ اگر معتبر نبود، قالب ماه/روز/سال نیز پذیرفته می‌شود.
            if (checkdate($second, $first, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $second, $first);
            }
            return checkdate($first, $second, $year) ? sprintf('%04d-%02d-%02d', $year, $first, $second) : '';
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            if ($year >= 1200 && $year <= 1600) {
                $date = self::jalali_parts_to_iso($year, $month, $day);
                return $date === false ? '' : $date;
            }
            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
        }
        return '';
    }

    /** @return array{date:string,source:string} */
    public static function get_product_expiry($product_id, $meta_key_override = null) {
        $product_id = (int) $product_id;
        $internal = get_post_meta($product_id, self::INTERNAL_KEY, true);
        if ($internal !== '') {
            $date = self::parse_date($internal);
            if ($date !== '') {
                return array('date' => $date, 'source' => self::INTERNAL_KEY);
            }
        }
        if (get_post_meta($product_id, self::DISABLED_KEY, true)) {
            return array('date' => '', 'source' => '');
        }
        $key = $meta_key_override === null ? self::meta_key_in_use() : (string) $meta_key_override;
        if ($key !== '' && $key !== self::INTERNAL_KEY) {
            $date = self::parse_date(get_post_meta($product_id, $key, true));
            if ($date !== '') {
                return array('date' => $date, 'source' => $key);
            }
        }
        return array('date' => '', 'source' => '');
    }

    public static function product_fields($product_id) {
        $expiry = self::get_product_expiry($product_id);
        $jalali = '';
        if ($expiry['date'] !== '') {
            list($gy, $gm, $gd) = array_map('intval', explode('-', $expiry['date']));
            list($jy, $jm, $jd) = wsm_gregorian_to_jalali($gy, $gm, $gd);
            $jalali = wsm_to_persian_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
        }
        return array(
            'expiry_date'        => $expiry['date'],
            'expiry_date_jalali' => $jalali,
            'expiry_date_source' => $expiry['source'],
        );
    }

    /** بعد از ذخیرهٔ WC_Product اجرا می‌شود؛ مقدار خالی، متای خارجی را در اپ پنهان می‌کند. */
    public static function save_product_date($product_id, $value) {
        $product_id = (int) $product_id;
        $date = self::normalize_input($value);
        if ($date !== false && $date !== '') {
            update_post_meta($product_id, self::INTERNAL_KEY, $date);
            delete_post_meta($product_id, self::DISABLED_KEY);
            self::invalidate_items_cache();
            return;
        }
        delete_post_meta($product_id, self::INTERNAL_KEY);
        $external_key = self::meta_key_in_use();
        $external_value = ($external_key !== '' && $external_key !== self::INTERNAL_KEY)
            ? get_post_meta($product_id, $external_key, true)
            : '';
        if ($external_value !== '' && $external_value !== null) {
            update_post_meta($product_id, self::DISABLED_KEY, 1);
        } else {
            delete_post_meta($product_id, self::DISABLED_KEY);
        }
        self::invalidate_items_cache();
    }

    private static function days_remaining($date) {
        try {
            $timezone = wp_timezone();
            $today = current_datetime()->setTime(0, 0, 0);
            $expiry = new DateTimeImmutable($date . ' 00:00:00', $timezone);
            return (int) $today->diff($expiry)->format('%r%a');
        } catch (Exception $e) {
            return 0;
        }
    }

    /** همهٔ کالاهای تاریخ‌دار؛ دادهٔ کامل کوتاه‌مدت کش می‌شود چون API برای هر صفحه همین مجموعه را می‌سازد. */
    private static function all_items() {
        $cached = get_transient(self::ITEMS_CACHE);
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $statuses = array('publish', 'draft', 'private', 'pending');
        $keys = array(self::INTERNAL_KEY);
        $meta_key = self::meta_key_in_use();
        if ($meta_key !== '' && !in_array($meta_key, $keys, true)) {
            $keys[] = $meta_key;
        }
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $key_placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $sql = "SELECT p.*
                FROM {$wpdb->posts} p
                INNER JOIN (
                    SELECT DISTINCT post_id
                    FROM {$wpdb->postmeta}
                    WHERE meta_key IN ($key_placeholders)
                ) expiry_meta ON expiry_meta.post_id = p.ID
                WHERE p.post_type = 'product'
                  AND p.post_status IN ($status_placeholders)
                ORDER BY p.ID DESC";
        $posts = (array) $wpdb->get_results($wpdb->prepare($sql, array_merge($keys, $statuses)));
        $ids = array();
        foreach ($posts as $post) {
            $ids[] = (int) $post->ID;
        }
        if (!$ids) {
            set_transient(self::ITEMS_CACHE, array(), 60);
            return array();
        }

        // Prime both WordPress caches in batches: avoid one post/meta query for every expiry row.
        update_post_cache($posts);
        foreach (array_chunk($ids, 500) as $id_batch) {
            update_meta_cache('post', $id_batch);
        }

        $items = array();
        foreach ($ids as $id) {
            $expiry = self::get_product_expiry($id, $meta_key);
            if ($expiry['date'] === '') {
                continue;
            }
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }
            list($gy, $gm, $gd) = array_map('intval', explode('-', $expiry['date']));
            list($jy, $jm, $jd) = wsm_gregorian_to_jalali($gy, $gm, $gd);
            $manage_stock = $product->get_manage_stock();
            $quantity = $product->get_stock_quantity();
            $in_stock = $manage_stock && $quantity !== null
                ? (int) $quantity > 0
                : $product->get_stock_status() === 'instock';
            $items[] = array(
                'id'                 => $id,
                'name'               => $product->get_name(),
                'sku'                => $product->get_sku(),
                'expiry_date'        => $expiry['date'],
                'expiry_date_jalali' => wsm_to_persian_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd)),
                'expiry_date_source' => $expiry['source'],
                'days_remaining'     => self::days_remaining($expiry['date']),
                'in_stock'           => (bool) $in_stock,
            );
        }
        usort($items, function ($a, $b) {
            // موجودها جلوتر می‌آیند؛ سپس تاریخ نزدیک‌تر و بعد وضعیت انقضا تعیین‌کننده است.
            if ($a['in_stock'] !== $b['in_stock']) {
                return $a['in_stock'] ? -1 : 1;
            }
            $group_a = $a['days_remaining'] < 0 ? 1 : 0;
            $group_b = $b['days_remaining'] < 0 ? 1 : 0;
            if ($group_a !== $group_b) {
                return $group_a <=> $group_b;
            }
            $date_order = strcmp($a['expiry_date'], $b['expiry_date']);
            return $date_order !== 0 ? $date_order : strcasecmp($a['name'], $b['name']);
        });
        set_transient(self::ITEMS_CACHE, $items, 60);
        return $items;
    }

    public static function list($per_page = 5, $page = 1) {
        $per_page = max(1, min(100, (int) $per_page));
        $page = max(1, (int) $page);
        $items = self::all_items();
        $total = count($items);
        $settings = WSM_Util::settings();
        return array(
            'items'             => array_slice($items, ($page - 1) * $per_page, $per_page),
            'total'             => $total,
            'total_pages'       => (int) ceil($total / $per_page),
            'page'              => $page,
            'alert_days'        => max(1, min(365, (int) $settings['expiry_alert_days'])),
            'meta_key_in_use'   => self::meta_key_in_use(),
            'meta_key_detected' => self::detected_meta_key(),
        );
    }

    /** خلاصهٔ روزانهٔ کالاهایی که از امروز تا آستانهٔ تعیین‌شده منقضی می‌شوند. */
    public static function send_daily_alerts() {
        $settings = WSM_Util::settings();
        $send_email = !empty($settings['expiry_alert_email']);
        $send_sms = !empty($settings['expiry_alert_sms']);
        if (!$send_email && !$send_sms) {
            return;
        }
        $today = current_datetime()->format('Y-m-d');
        if (get_option('wsm_expiry_alerts_last_run', '') === $today) {
            return;
        }
        $threshold = max(1, min(365, (int) $settings['expiry_alert_days']));
        $items = array_values(array_filter(self::all_items(), function ($item) use ($threshold) {
            return $item['days_remaining'] >= 0 && $item['days_remaining'] <= $threshold;
        }));
        if (!$items) {
            return;
        }
        // ثبت روز اجرا جلوی ارسال تکراری در صورت اجرای چندبارهٔ رویداد WP-Cron را می‌گیرد.
        update_option('wsm_expiry_alerts_last_run', $today, false);
        $store = get_bloginfo('name');
        $lines = array();
        foreach ($items as $item) {
            $remaining = $item['days_remaining'] === 0 ? 'امروز' : wsm_to_persian_digits((string) $item['days_remaining']) . ' روز مانده';
            $lines[] = '• ' . $item['name'] . ' — انقضا: ' . $item['expiry_date_jalali'] . ' — ' . $remaining;
        }
        $message = "هشدار تاریخ انقضای کالا در فروشگاه «{$store}»\n" .
            'تعداد کالاهای در آستانهٔ انقضا: ' . wsm_to_persian_digits((string) count($items)) . "\n\n" .
            implode("\n", $lines);
        if ($send_email) {
            $to = isset($settings['expiry_alert_email_to']) ? sanitize_email($settings['expiry_alert_email_to']) : '';
            if (!is_email($to)) {
                $to = get_option('admin_email');
            }
            $sent = wp_mail($to, 'هشدار تاریخ انقضای کالا — ' . $store, $message);
            if (!$sent) {
                WSM_Security::log('expiry_email_failed', 'warning', 0, 'ارسال ایمیل هشدار تاریخ انقضا ناموفق بود');
            } else {
                WSM_Security::log('expiry_email_sent', 'info', 0, 'ایمیل هشدار تاریخ انقضا ارسال شد؛ تعداد کالا: ' . count($items));
            }
        }
        if ($send_sms) {
            $sms_lines = array();
            foreach (array_slice($items, 0, 5) as $item) {
                $sms_lines[] = $item['name'] . ' ' . $item['expiry_date_jalali'];
            }
            if (count($items) > 5) {
                $sms_lines[] = 'و ' . wsm_to_persian_digits((string) (count($items) - 5)) . ' کالای دیگر';
            }
            WSM_Security::notify_sms('هشدار انقضای کالا: ' . implode('؛ ', $sms_lines));
        }
    }
}

