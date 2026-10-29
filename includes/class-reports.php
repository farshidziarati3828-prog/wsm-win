<?php
/**
 * داشبورد و گزارش‌ها: KPI، فروش روزانه، موجودی انبار
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Reports {

    const SALE_STATUSES = array('processing', 'completed');

    /** A changing generation token scopes every sale/dashboard aggregate cache key. */
    public static function sales_cache_epoch() {
        $cache_epoch = get_transient('wsm_reports_sales_epoch_v1');
        if (!is_string($cache_epoch) || $cache_epoch === '') {
            $cache_epoch = wp_generate_uuid4();
            set_transient('wsm_reports_sales_epoch_v1', $cache_epoch, 900);
        }
        return $cache_epoch;
    }

    /**
     * جمع فروش و تعداد سفارش در بازهٔ زمانی (بر اساس وقت محلی سایت)
     */
    private static function sales_between($start_ts, $end_ts) {
        global $wpdb;

        $timezone = wp_timezone();
        $start_dt = (new DateTimeImmutable('@' . (int) $start_ts))->setTimezone($timezone);
        $end_dt   = (new DateTimeImmutable('@' . (int) $end_ts))->setTimezone($timezone);
        $start_local = $start_dt->format('Y-m-d H:i:s');
        $end_local   = $end_dt->format('Y-m-d H:i:s');

        // Dashboard/report reads are cached by a rotating epoch; order-write hooks
        // replace the epoch so later reads use a fresh aggregate snapshot.
        $cache_epoch = self::sales_cache_epoch();
        $cache_key = 'wsm_reports_sales_aggregate_v4_' . md5($cache_epoch . '|' . (int) $start_ts . '|' . (int) $end_ts);
        $cached = wp_cache_get($cache_key, 'wsm');
        if (!is_array($cached)) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                wp_cache_set($cache_key, $cached, 'wsm', 900);
            }
        }
        if (is_array($cached) && isset($cached['total'], $cached['count'], $cached['daily'], $cached['channels'])) {
            return $cached;
        }

        // Use the authoritative order store, not wc_order_stats: Analytics lookup
        // rows can be absent or still pending regeneration after imports/upgrades.
        // OrderUtil is the supported HPOS check; the option is a compatibility fallback.
        $order_util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
        $hpos_enabled = class_exists($order_util)
            && is_callable(array($order_util, 'custom_orders_table_usage_is_enabled'))
            && (bool) call_user_func(array($order_util, 'custom_orders_table_usage_is_enabled'));
        if (!$hpos_enabled && get_option('woocommerce_custom_orders_table_enabled') === 'yes') {
            $hpos_enabled = true;
        }

        if ($hpos_enabled) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            $meta_table = $wpdb->prefix . 'wc_orders_meta';
            $order_id_column = 'o.id';
            $meta_order_column = 'order_id';
            $order_type_filter = "o.type = 'shop_order'";
            $order_status_filter = "o.status IN ('wc-processing', 'wc-completed')";
            $parent_filter = 'COALESCE(o.parent_order_id, 0) = 0';
            $created_column = 'o.date_created_gmt';
            $start = gmdate('Y-m-d H:i:s', (int) $start_ts);
            $end = gmdate('Y-m-d H:i:s', (int) $end_ts);
            $total_expression = 'COALESCE(o.total_amount, 0)';
        } else {
            $orders_table = $wpdb->posts;
            $meta_table = $wpdb->postmeta;
            $order_id_column = 'o.ID';
            $meta_order_column = 'post_id';
            $order_type_filter = "o.post_type = 'shop_order'";
            $order_status_filter = "o.post_status IN ('wc-processing', 'wc-completed')";
            $parent_filter = 'o.post_parent = 0';
            $created_column = 'o.post_date';
            $start = $start_local;
            $end = $end_local;
            $total_expression = "COALESCE((
                SELECT CAST(order_total.meta_value AS DECIMAL(26, 8))
                FROM {$meta_table} order_total
                WHERE order_total.{$meta_order_column} = {$order_id_column}
                  AND order_total.meta_key = '_order_total'
                ORDER BY order_total.meta_id DESC
                LIMIT 1
            ), 0)";
        }

        $manual_sale = "EXISTS (
            SELECT 1 FROM {$meta_table} manual_meta
            WHERE manual_meta.{$meta_order_column} = {$order_id_column}
              AND manual_meta.meta_key = '_wsm_manual_sale'
              AND manual_meta.meta_value <> ''
              AND manual_meta.meta_value <> '0'
        )";
        $not_invoice = "NOT EXISTS (
            SELECT 1 FROM {$meta_table} invoice_meta
            WHERE invoice_meta.{$meta_order_column} = {$order_id_column}
              AND invoice_meta.meta_key = '_wsm_invoice'
              AND invoice_meta.meta_value <> ''
              AND invoice_meta.meta_value <> '0'
        )";

        $sql = $wpdb->prepare(
            "SELECT {$created_column} AS created_at,
                    {$total_expression} AS total_sales,
                    CASE WHEN {$manual_sale} THEN 1 ELSE 0 END AS is_manual
             FROM {$orders_table} o
             WHERE {$order_type_filter}
               AND {$order_status_filter}
               AND {$parent_filter}
               AND {$created_column} >= %s
               AND {$created_column} < %s
               AND {$not_invoice}",
            $start,
            $end
        );

        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error !== '') {
            error_log('[WSM Reports] Sales aggregate SQL error: ' . trim((string) $wpdb->last_error));
            return new WP_Error('wsm_sales_aggregate_failed', 'محاسبهٔ گزارش فروش انجام نشد.', array('status' => 500));
        }

        $sum = 0.0;
        $count = 0;
        $daily = array();
        $channels = array(
            'online' => array('total' => 0.0, 'count' => 0),
            'in_person' => array('total' => 0.0, 'count' => 0),
        );
        $utc = new DateTimeZone('UTC');
        foreach ((array) $rows as $row) {
            $created_at = isset($row['created_at']) ? trim((string) $row['created_at']) : '';
            if ($created_at === '') {
                continue;
            }
            if ($hpos_enabled) {
                $created_dt = date_create_immutable($created_at, $utc);
                if (!$created_dt) {
                    continue;
                }
                $day = $created_dt->setTimezone($timezone)->format('Y-m-d');
            } else {
                // wp_posts.post_date is stored in the site's local timezone.
                $day = substr($created_at, 0, 10);
            }
            if (!self::valid_date($day)) {
                continue;
            }

            $day_total = isset($row['total_sales']) && is_numeric($row['total_sales'])
                ? (float) $row['total_sales']
                : 0.0;
            $is_manual = !empty($row['is_manual']);
            if (!isset($daily[$day])) {
                $daily[$day] = array(
                    'date' => $day,
                    'label' => wsm_fa_date_short($day . ' 00:00:00'),
                    'total' => 0.0,
                    'count' => 0,
                );
            }
            $daily[$day]['total'] += $day_total;
            $daily[$day]['count']++;
            $sum += $day_total;
            $count++;
            if ($is_manual) {
                $channels['in_person']['total'] += $day_total;
                $channels['in_person']['count']++;
            } else {
                $channels['online']['total'] += $day_total;
                $channels['online']['count']++;
            }
        }

        ksort($daily);
        $response = array('total' => $sum, 'count' => $count, 'daily' => $daily, 'channels' => $channels);
        wp_cache_set($cache_key, $response, 'wsm', 900);
        set_transient($cache_key, $response, 900);
        return $response;
    }

    public static function dashboard() {
        // Scope this outer snapshot to the same write epoch as the sales aggregate.
        $cache_key = 'wsm_reports_dashboard_v6_' . md5(self::sales_cache_epoch());
        $cached = wp_cache_get($cache_key, 'wsm');
        if (!is_array($cached)) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                wp_cache_set($cache_key, $cached, 'wsm', 60);
            }
        }
        if (is_array($cached) && isset($cached['today'], $cached['month'], $cached['last30'])) {
            return $cached;
        }
        $timezone = wp_timezone();
        $now_dt = new DateTimeImmutable('now', $timezone);
        $now = $now_dt->getTimestamp();
        $month_start_dt = $now_dt->modify('first day of this month')->setTime(0, 0, 0);
        $days30_start_dt = $now_dt->modify('-29 days')->setTime(0, 0, 0);
        $month_s = $month_start_dt->getTimestamp();
        $days30_s = $days30_start_dt->getTimestamp();
        // یک بازهٔ مشترک جای سه پیمایش مستقل سفارش‌ها را می‌گیرد؛ از اول ماه/۳۰ روز اخیر شروع می‌شود.
        $window = self::sales_between(min($month_s, $days30_s), $now);
        if (is_wp_error($window)) {
            return $window;
        }

        $today_day = $now_dt->format('Y-m-d');
        $month_day = $now_dt->format('Y-m-01');
        $days30_day = $days30_start_dt->format('Y-m-d');
        $today = isset($window['daily'][$today_day])
            ? $window['daily'][$today_day]
            : array('total' => 0.0, 'count' => 0);
        $month = array('total' => 0.0, 'count' => 0);
        $last30 = array('total' => 0.0, 'count' => 0, 'daily' => $window['daily']);
        foreach ($window['daily'] as $day => $totals) {
            if ($day >= $month_day && $day <= $today_day) {
                $month['total'] += (float) $totals['total'];
                $month['count'] += (int) $totals['count'];
            }
            if ($day >= $days30_day && $day <= $today_day) {
                $last30['total'] += (float) $totals['total'];
                $last30['count'] += (int) $totals['count'];
            }
        }

        // سری روزانهٔ ۳۰ روز اخیر
        $series = array();
        for ($i = 29; $i >= 0; $i--) {
            $day = $now_dt->modify('-' . $i . ' days')->format('Y-m-d');
            $series[$day] = array('date' => $day, 'label' => wsm_fa_date_short($day . ' 00:00:00'), 'total' => 0.0, 'count' => 0);
        }
        foreach ($last30['daily'] as $day => $totals) {
            if (isset($series[$day])) {
                $series[$day]['total'] = (float) $totals['total'];
                $series[$day]['count'] = (int) $totals['count'];
            }
        }

        $counts = array();
        foreach (WSM_Orders::statuses() as $s) {
            $counts[$s['key']] = array('label' => $s['label'], 'count' => 0);
        }
        // 'exclude' is supported by both order datastores; meta_query is not portable to legacy CPT.
        $recent_invoice_ids = WSM_Util::order_ids_with_meta('_wsm_invoice');
        $recent_result = wc_get_orders(array(
            'type' => 'shop_order', 'limit' => 50, 'page' => 1, 'paginate' => true,
            'orderby' => 'date', 'order' => 'DESC',
            'exclude' => $recent_invoice_ids,
        ));
        $all_recent = is_wp_error($recent_result)
            ? array()
            : (is_object($recent_result) && isset($recent_result->orders) ? (array) $recent_result->orders : (array) $recent_result);
        foreach ($all_recent as $o) {
            if (isset($counts[$o->get_status()])) {
                $counts[$o->get_status()]['count']++;
            }
        }

        $recent = array();
        foreach (array_slice($all_recent, 0, 8) as $o) {
            $recent[] = WSM_Orders::format($o);
        }

        $low_stock_count = self::low_stock_count();
        if (is_wp_error($low_stock_count)) {
            return $low_stock_count;
        }

        $response = array(
            'today'        => array('total' => $today['total'], 'count' => $today['count'], 'display' => wsm_fa_money($today['total'])),
            'month'        => array('total' => $month['total'], 'count' => $month['count'], 'display' => wsm_fa_money($month['total'])),
            'last30'       => array('total' => $last30['total'], 'count' => $last30['count'], 'display' => wsm_fa_money($last30['total'])),
            'low_stock'    => $low_stock_count,
            'pending'      => (int) ($counts['pending']['count'] ?? 0) + (int) ($counts['on-hold']['count'] ?? 0),
            'series'       => array_values($series),
            'status_counts'=> $counts,
            'recent_orders'=> $recent,
        );
        wp_cache_set($cache_key, $response, 'wsm', 60);
        set_transient($cache_key, $response, 60);
        return $response;
    }

    /**
     * فروش بین دو تاریخ (Y-m-d)
     */
    private static function valid_date($value) {
        if (!is_scalar($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            return false;
        }
        $parts = array_map('intval', explode('-', (string) $value));
        return count($parts) === 3 && checkdate($parts[1], $parts[2], $parts[0]);
    }

    /** Accept Gregorian/Jalali dates and ISO-like date-time strings, returning Gregorian Y-m-d. */
    public static function normalize_report_date($value) {
        if ($value === null || $value === '') { return ''; }
        if (!is_scalar($value)) { return false; }
        $raw = trim((string) $value);
        if ($raw === '') { return ''; }
        $raw = strtr($raw, array(
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ));
        // A report filter is day-granular: accept a valid time suffix but retain the entered calendar day.
        $date_only = preg_replace('/(?:\s+|T)(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d+)?)?(?:Z|[+\-](?:0\d|1\d|2[0-3]):?[0-5]\d)?$/u', '', $raw);
        if (!is_string($date_only)) { return false; }
        $raw = trim($date_only);
        if (!preg_match('/^(\d{4})\s*([\/.\-])\s*(\d{1,2})\s*\2\s*(\d{1,2})$/u', $raw, $parts)) {
            return false;
        }
        $year = (int) $parts[1];
        $month = (int) $parts[3];
        $day = (int) $parts[4];
        if ($year > 1600) {
            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : false;
        }
        if ($year >= 1200 && $year <= 1600 && class_exists('WSM_Expiry') && is_callable(array('WSM_Expiry', 'normalize_input'))) {
            // Jalali date normalization lives on WSM_Expiry, not WSM_Products.
            $gregorian = WSM_Expiry::normalize_input(sprintf('%04d/%02d/%02d', $year, $month, $day));
            return is_string($gregorian) && self::valid_date($gregorian) ? $gregorian : false;
        }
        return false;
    }

    public static function sales($from, $to) {
        $from = self::normalize_report_date($from);
        $to   = self::normalize_report_date($to);
        if ($from === false || $to === false) {
            return new WP_Error('wsm_bad_range', 'تاریخ گزارش را به‌صورت شمسی یا میلادی معتبر وارد کنید.', array('status' => 400));
        }
        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        if ($from === '') {
            $from = $now->modify('-29 days')->format('Y-m-d');
        } elseif (!self::valid_date($from)) {
            return new WP_Error('wsm_bad_range', 'تاریخ شروع گزارش نامعتبر است.', array('status' => 400));
        }
        if ($to === '') {
            $to = $now->format('Y-m-d');
        } elseif (!self::valid_date($to)) {
            return new WP_Error('wsm_bad_range', 'تاریخ پایان گزارش نامعتبر است.', array('status' => 400));
        }
        $from_date = new DateTimeImmutable($from . ' 00:00:00', $timezone);
        $to_date = new DateTimeImmutable($to . ' 00:00:00', $timezone);
        if ($from_date > $to_date) {
            return new WP_Error('wsm_bad_range', 'بازهٔ زمانی نامعتبر است.', array('status' => 400));
        }
        $start = $from_date->getTimestamp();
        if ($start > $now->getTimestamp()) {
            return new WP_Error('wsm_bad_range', 'تاریخ شروع گزارش در آینده است.', array('status' => 400));
        }
        $range_days = (int) $from_date->setTime(0, 0, 0)->diff($to_date->setTime(0, 0, 0))->format('%a') + 1;
        if ($range_days > 366) {
            return new WP_Error('wsm_bad_range', 'برای گزارش فروش، بازهٔ انتخابی حداکثر ۳۶۶ روز است.', array('status' => 400));
        }
        $requested_end = $to_date->modify('+1 day')->getTimestamp();
        // Keep date ranges day-inclusive but clamp their upper timestamp to now; at midnight this may be zero-width.
        $end = max($start, min($requested_end, $now->getTimestamp()));

        // The shared generation token makes date-range snapshots unreachable after any order write.
        $cache_epoch = self::sales_cache_epoch();
        $cache_key = 'wsm_reports_sales_v4_' . md5($cache_epoch . '|' . $from . '|' . $to);
        $cached = wp_cache_get($cache_key, 'wsm');
        if (!is_array($cached)) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                wp_cache_set($cache_key, $cached, 'wsm', 900);
            }
        }
        if (is_array($cached)) {
            return $cached;
        }

        $data = self::sales_between($start, $end);
        if (is_wp_error($data)) {
            return $data;
        }
        $days = array_values($data['daily']);
        $response = array(
            'from'    => $from,
            'to'      => $to,
            'total'   => $data['total'],
            'count'   => $data['count'],
            'display' => wsm_fa_money($data['total']),
            'channels' => array(
                'online' => array(
                    'total' => $data['channels']['online']['total'],
                    'count' => $data['channels']['online']['count'],
                    'display' => wsm_fa_money($data['channels']['online']['total']),
                ),
                'in_person' => array(
                    'total' => $data['channels']['in_person']['total'],
                    'count' => $data['channels']['in_person']['count'],
                    'display' => wsm_fa_money($data['channels']['in_person']['total']),
                ),
            ),
            'days' => array_values($days),
        );
        wp_cache_set($cache_key, $response, 'wsm', 900);
        set_transient($cache_key, $response, 900);
        return $response;
    }

    /** Single invalidation point for dashboard, sales, stock, club overview, and order-list snapshots. */
    public static function flush_sale_caches($arg1 = null, $arg2 = null, $arg3 = null, $arg4 = null) {
        // Rotate, rather than delete, so in-flight readers can only populate obsolete generation keys.
        set_transient('wsm_reports_sales_epoch_v1', wp_generate_uuid4(), 900);
        set_transient('wsm_orders_list_epoch_v1', wp_generate_uuid4(), 900);

        // Retire legacy fixed keys in both cache layers. Date-range aggregates use the new epoch,
        // so there is no unsafe wildcard DELETE against wp_options or a persistent object cache.
        foreach (array(
            'wsm_api_dashboard_v4',
            'wsm_api_dashboard_v3',
            'wsm_api_club_overview_v1',
            'wsm_api_club_overview_v2',
            'wsm_reports_dashboard_v4',
            'wsm_reports_dashboard_v3',
            'wsm_reports_dashboard_v2',
            'wsm_reports_stock_v1',
            'wsm_reports_low_stock_count_v3',
            'wsm_reports_low_stock_count_v2',
            'wsm_reports_low_stock_count_v1',
            'wsm_club_overview_v2',
            'wsm_club_overview_v3',
        ) as $cache_key) {
            wp_cache_delete($cache_key, 'wsm');
            delete_transient($cache_key);
        }
        return true;
    }

    public static function invalidate_dashboard_cache($arg1 = null, $arg2 = null, $arg3 = null, $arg4 = null) {
        return self::flush_sale_caches();
    }

    public static function invalidate_stock_cache($arg1 = null, $arg2 = null, $arg3 = null) {
        return self::flush_sale_caches();
    }

    public static function invalidate_stock_cache_for_post($post_id, $post = null) {
        $post_type = is_object($post) && isset($post->post_type)
            ? (string) $post->post_type
            : get_post_type((int) $post_id);
        if (in_array($post_type, array('product', 'product_variation'), true)) {
            self::invalidate_stock_cache();
        }
    }

    private static function low_stock_count() {
        global $wpdb;

        $cache_key = 'wsm_reports_low_stock_count_v3';
        $cached = wp_cache_get($cache_key, 'wsm');
        if ($cached === false) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                wp_cache_set($cache_key, $cached, 'wsm', 900);
            }
        }
        if ($cached !== false) {
            return max(0, (int) $cached);
        }

        $default_threshold = get_option('woocommerce_notify_low_stock_amount', 2);
        if (!is_numeric($default_threshold)) {
            $default_threshold = 2;
        }
        $default_threshold = (string) (int) $default_threshold;
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} manage_meta
                ON manage_meta.post_id = p.ID
               AND manage_meta.meta_key = '_manage_stock'
               AND manage_meta.meta_value IN ('yes', '1')
             INNER JOIN {$wpdb->postmeta} stock_meta
                ON stock_meta.post_id = p.ID
               AND stock_meta.meta_key = '_stock'
               AND stock_meta.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} low_meta
                ON low_meta.post_id = p.ID
               AND low_meta.meta_key = '_low_stock_amount'
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status = 'publish'
               AND CAST(stock_meta.meta_value AS DECIMAL(20, 6))
                    <= CAST(COALESCE(NULLIF(low_meta.meta_value, ''), %s) AS DECIMAL(20, 6))",
            $default_threshold
        );

        $wpdb->last_error = '';
        $count = $wpdb->get_var($sql);
        if ($wpdb->last_error !== '') {
            error_log('[WSM Reports] Low-stock count SQL error: ' . trim((string) $wpdb->last_error));
            return new WP_Error('wsm_low_stock_count_failed', 'شمارش کالاهای کم‌موجودی انجام نشد.', array('status' => 500));
        }
        $count = max(0, (int) $count);
        wp_cache_set($cache_key, $count, 'wsm', 900);
        set_transient($cache_key, $count, 900);
        return $count;
    }

    /**
     * گزارش موجودی انبار (کم‌موجودها اول)
     */
    public static function stock() {
        global $wpdb;

        $cache_key = 'wsm_reports_stock_v1';
        $cached = wp_cache_get($cache_key, 'wsm');
        if (!is_array($cached)) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                wp_cache_set($cache_key, $cached, 'wsm', 120);
            }
        }
        if (is_array($cached) && isset($cached['items'], $cached['low_count'])) {
            return $cached;
        }

        // Ask WooCommerce only for the bounded ID page; avoid hydrating hundreds of product objects.
        $products = wc_get_products(array(
            'limit' => 300,
            'orderby' => 'title',
            'order' => 'ASC',
            'return' => 'ids',
        ));
        if (is_wp_error($products)) {
            return $products;
        }
        $ids = array();
        foreach ((array) $products as $product) {
            $id = is_object($product) && is_callable(array($product, 'get_id'))
                ? absint($product->get_id())
                : absint($product);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        $rows = array();

        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $own_manage = "MAX(CASE WHEN own_meta.meta_key = '_manage_stock' THEN own_meta.meta_value END)";
            $own_quantity = "MAX(CASE WHEN own_meta.meta_key = '_stock' THEN own_meta.meta_value END)";
            $own_status = "MAX(CASE WHEN own_meta.meta_key = '_stock_status' THEN own_meta.meta_value END)";
            $parent_manage = "MAX(CASE WHEN parent_meta.meta_key = '_manage_stock' THEN parent_meta.meta_value END)";
            $parent_quantity = "MAX(CASE WHEN parent_meta.meta_key = '_stock' THEN parent_meta.meta_value END)";
            $parent_status = "MAX(CASE WHEN parent_meta.meta_key = '_stock_status' THEN parent_meta.meta_value END)";
            $inherited_stock = "(p.post_type = 'product_variation' AND {$own_manage} = 'parent')";
            $manage_expression = "CASE WHEN {$inherited_stock} THEN {$parent_manage} ELSE {$own_manage} END";
            $quantity_expression = "CASE WHEN {$inherited_stock} THEN {$parent_quantity} ELSE {$own_quantity} END";
            $status_expression = "CASE WHEN {$inherited_stock} THEN {$parent_status} ELSE {$own_status} END";
            $query = "SELECT p.ID AS id,
                             CASE WHEN p.post_type = 'product_variation'
                                  THEN CONCAT(COALESCE(NULLIF(parent.post_title, ''), p.post_title), ' — #', p.ID)
                                  ELSE p.post_title END AS name,
                             MAX(CASE WHEN own_meta.meta_key = '_sku' THEN own_meta.meta_value END) AS sku,
                             {$manage_expression} AS manage_stock,
                             {$quantity_expression} AS stock_quantity,
                             {$status_expression} AS stock_status,
                             MAX(CASE WHEN own_meta.meta_key = '_price' THEN own_meta.meta_value END) AS price
                      FROM {$wpdb->posts} p
                      LEFT JOIN {$wpdb->postmeta} own_meta
                        ON own_meta.post_id = p.ID
                       AND own_meta.meta_key IN ('_sku', '_manage_stock', '_stock', '_stock_status', '_price')
                      LEFT JOIN {$wpdb->posts} parent
                        ON parent.ID = p.post_parent
                       AND parent.post_type = 'product'
                      LEFT JOIN {$wpdb->postmeta} parent_meta
                        ON parent_meta.post_id = parent.ID
                       AND parent_meta.meta_key IN ('_manage_stock', '_stock', '_stock_status')
                      WHERE p.ID IN ({$placeholders})
                      GROUP BY p.ID, p.post_title, p.post_type, p.post_parent, parent.post_title
                      ORDER BY p.post_title ASC, p.ID ASC";
            $sql = $wpdb->prepare($query, ...$ids);
            $wpdb->last_error = '';
            $db_rows = $wpdb->get_results($sql, ARRAY_A);
            if ($wpdb->last_error !== '') {
                error_log('[WSM Reports] Stock report SQL error: ' . trim((string) $wpdb->last_error));
                return new WP_Error('wsm_stock_report_failed', 'گزارش موجودی انبار دریافت نشد.', array('status' => 500));
            }

            foreach ((array) $db_rows as $row) {
                $manage_value = isset($row['manage_stock']) ? (string) $row['manage_stock'] : '';
                $manage_stock = in_array($manage_value, array('yes', '1', 'parent'), true);
                $raw_quantity = isset($row['stock_quantity']) ? $row['stock_quantity'] : null;
                $quantity = $manage_stock && $raw_quantity !== '' && $raw_quantity !== null && is_numeric($raw_quantity)
                    ? (int) $raw_quantity
                    : null;
                $stock_status = isset($row['stock_status']) ? (string) $row['stock_status'] : '';
                $rows[] = array(
                    'id' => isset($row['id']) ? (int) $row['id'] : 0,
                    'name' => isset($row['name']) ? (string) $row['name'] : '',
                    'sku' => isset($row['sku']) ? (string) $row['sku'] : '',
                    'manage_stock' => $manage_stock,
                    'stock_quantity' => $quantity,
                    'stock_status' => $stock_status !== '' ? $stock_status : 'instock',
                    'price' => isset($row['price']) ? (string) $row['price'] : '',
                );
            }
        }

        usort($rows, function ($a, $b) {
            $qa = $a['stock_quantity'] === null ? PHP_INT_MAX : $a['stock_quantity'];
            $qb = $b['stock_quantity'] === null ? PHP_INT_MAX : $b['stock_quantity'];
            return $qa <=> $qb;
        });
        $low_count = self::low_stock_count();
        if (is_wp_error($low_count)) {
            return $low_count;
        }
        $response = array('items' => $rows, 'low_count' => $low_count);
        wp_cache_set($cache_key, $response, 'wsm', 120);
        set_transient($cache_key, $response, 120);
        return $response;
    }

}

