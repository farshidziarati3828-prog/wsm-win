<?php
/**
 * سفارش‌ها (HPOS-safe): فهرست، جزئیات، ویرایش، تغییر وضعیت، بازپرداخت، فروش دستی، تاریخچه
 * فاکتورها (با متای _wsm_invoice) پس از واکشی صفحه از نتایج نمایشی حذف می‌شوند.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Orders {

    const BILLING_FIELDS = array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone');

    public static function statuses() {
        $out = array();
        foreach (wc_get_order_statuses() as $key => $label) {
            $out[] = array('key' => substr($key, 3), 'label' => $label);
        }
        return $out;
    }

    public static function format(WC_Order $o) {
        $items = array();
        foreach ($o->get_items() as $item_id => $item) {
            $pid     = (int) $item->get_product_id();
            $img_id  = $pid ? (int) get_post_thumbnail_id($pid) : 0;
            $items[] = array(
                'id'         => (int) $item_id,
                'product_id' => $pid,
                'name'       => $item->get_name(),
                'qty'        => (int) $item->get_quantity(),
                'subtotal'   => (string) $item->get_subtotal(),
                'total'      => (string) $item->get_total(),
                'image_url'  => $img_id ? (string) wp_get_attachment_image_url($img_id, 'thumbnail') : '',
            );
        }
        $created = $o->get_date_created();
        $status  = $o->get_status();

        return array(
            'id'                    => (int) $o->get_id(),
            'number'                => (string) $o->get_order_number(),
            'status'                => $status,
            'status_label'          => wc_get_order_status_name($status),
            'date'                  => wsm_fa_date($created ? $created->date('Y-m-d H:i:s') : ''),
            'date_iso'              => $created ? $created->date('Y-m-d') : '',
            'customer_name'         => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
            'billing'               => self::billing_array($o),
            'shipping'              => array(
                'first_name' => $o->get_shipping_first_name(),
                'last_name'  => $o->get_shipping_last_name(),
                'address_1'  => $o->get_shipping_address_1(),
                'address_2'  => $o->get_shipping_address_2(),
                'city'       => $o->get_shipping_city(),
                'postcode'   => $o->get_shipping_postcode(),
            ),
            'customer_note'         => $o->get_customer_note(),
            'payment_method_title'  => $o->get_payment_method_title(),
            'currency'              => $o->get_currency(),
            'subtotal'              => (string) $o->get_subtotal(),
            'discount_total'        => (string) $o->get_discount_total(),
            'shipping_total'        => (string) $o->get_shipping_total(),
            'total'                 => (string) $o->get_total(),
            'total_refunded'        => (string) $o->get_total_refunded(),
            'total_display'         => wsm_fa_money($o->get_total()),
            'items'                 => $items,
            'is_invoice'            => (bool) $o->get_meta('_wsm_invoice'),
            'invoice_id'            => absint($o->get_meta('_wsm_linked_invoice_id')),
            'is_manual_sale'        => (bool) $o->get_meta('_wsm_manual_sale'),
            'is_credit_sale'        => (bool) $o->get_meta('_wsm_credit_sale'),
            'is_pos_sale'            => (bool) $o->get_meta('_wsm_club_pos') || (bool) $o->get_meta('_wsm_club_pos_debt'),
            'is_paid'                => (method_exists($o, 'is_paid') && $o->is_paid()) || in_array($o->get_status(), array('processing', 'completed', 'refunded', 'partially-refunded'), true),
            'store_customer_id'     => absint($o->get_meta('_wsm_store_customer_id')),
            'account_balance_due'   => $o->get_meta('_wsm_credit_sale') && class_exists('WSM_Customers') ? (string) WSM_Customers::order_balance($o->get_id()) : '0',
            'club_member_id'        => absint($o->get_meta('_wsm_club_member_id')),
            'club_member_code'      => (string) $o->get_meta('_wsm_club_member_code'),
            'club_card_number'      => (string) $o->get_meta('_wsm_club_card_number'),
            'custom_fields'         => WSM_Util::custom_field_values($o, 'orders'),
        );
    }

    private static function billing_array(WC_Order $o) {
        return array(
            'first_name' => $o->get_billing_first_name(),
            'last_name'  => $o->get_billing_last_name(),
            'company'    => $o->get_billing_company(),
            'address_1'  => $o->get_billing_address_1(),
            'address_2'  => $o->get_billing_address_2(),
            'city'       => $o->get_billing_city(),
            'state'      => $o->get_billing_state(),
            'postcode'   => $o->get_billing_postcode(),
            'country'    => $o->get_billing_country(),
            'email'      => $o->get_billing_email(),
            'phone'      => $o->get_billing_phone(),
        );
    }

    /**
     * تبدیل تاریخ فیلتر به میلادی: ورودی شمسی اپ یا میلادی API.
     * @return string|WP_Error
     */
    private static function normalize_filter_date($value) {
        if (!is_scalar($value)) {
            return new WP_Error('wsm_bad_order_date', 'تاریخ سفارش نامعتبر است؛ تاریخ را به‌شکل شمسی مانند ۱۴۰۵/۰۷/۱۷ وارد کنید.', array('status' => 400));
        }
        $value = trim(sanitize_text_field((string) $value));
        if ($value === '') {
            return '';
        }
        $digits = strtr($value, array(
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ));
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $digits, $compact)) {
            $digits = $compact[1] . '/' . $compact[2] . '/' . $compact[3];
        }
        if (preg_match('/^(\d{4})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{1,2})$/', $digits, $parts)) {
            $year = (int) $parts[1];
            $month = (int) $parts[2];
            $day = (int) $parts[3];
            if ($year > 1600) {
                if (checkdate($month, $day, $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            } else {
                $jalali = WSM_Expiry::normalize_input($digits);
                if ($jalali !== false && $jalali !== '') {
                    return $jalali;
                }
            }
        }
        return new WP_Error('wsm_bad_order_date', 'تاریخ سفارش نامعتبر است؛ تاریخ را به‌شکل شمسی مانند ۱۴۰۵/۰۷/۱۷ وارد کنید.', array('status' => 400));
    }

    /**
     * فهرست سفارش‌ها (با تاریخ شمسی در ورودی رابط؛ خروجی تاریخ نیز شمسی است)
     */
    public static function list($args = array()) {
        $page     = max(1, (int) (isset($args['page']) ? $args['page'] : 1));
        $per_page = max(1, min(50, (int) (isset($args['per_page']) ? $args['per_page'] : 20)));
        $query = array(
            'type'       => 'shop_order',
            'limit'      => $per_page,
            'page'       => $page,
            'paginate'   => true,
            'orderby'    => 'date',
            'order'      => 'DESC',
        );
        $statuses = array_column(self::statuses(), 'key');
        if (!empty($args['status']) && in_array($args['status'], $statuses, true)) {
            $query['status'] = $args['status'];
        }
        // Use WooCommerce's supported order-query filters (HPOS-compatible).
        if (isset($args['billing_phone']) && is_scalar($args['billing_phone'])) {
            $phone = WSM_Util::clean_text((string) $args['billing_phone'], 40);
            if ($phone !== '') { $query['billing_phone'] = $phone; }
        }
        if (isset($args['billing_email']) && is_scalar($args['billing_email'])) {
            $email = sanitize_email((string) $args['billing_email']);
            if ($email !== '') { $query['billing_email'] = $email; }
        }
        if (isset($args['customer_id']) && is_scalar($args['customer_id'])) {
            $customer_id = absint($args['customer_id']);
            if ($customer_id > 0) { $query['customer_id'] = $customer_id; }
        }
        if (isset($args['search']) && is_scalar($args['search'])) {
            $search = WSM_Util::clean_text((string) $args['search'], 100);
            if ($search !== '') { $query['search'] = $search; }
        }
        $date_from = self::normalize_filter_date(isset($args['date_from']) ? $args['date_from'] : '');
        if (is_wp_error($date_from)) {
            return $date_from;
        }
        $date_to = self::normalize_filter_date(isset($args['date_to']) ? $args['date_to'] : '');
        if (is_wp_error($date_to)) {
            return $date_to;
        }
        if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
            return new WP_Error('wsm_bad_order_date_range', 'تاریخ شروع نباید بعد از تاریخ پایان باشد.', array('status' => 400));
        }
        if ($date_from !== '' && $date_to !== '') {
            $query['date_created'] = $date_from . '...' . $date_to;
        } elseif ($date_from !== '') {
            $query['date_created'] = '>=' . $date_from;
        } elseif ($date_to !== '') {
            $query['date_created'] = '<=' . $date_to;
        }
        // Cache read-only order pages briefly across requests; Woo write hooks rotate the epoch.
        $cache_epoch = get_transient('wsm_orders_list_epoch_v1');
        if (!is_string($cache_epoch) || $cache_epoch === '') {
            $cache_epoch = wp_generate_uuid4();
            set_transient('wsm_orders_list_epoch_v1', $cache_epoch, 900);
        }
        $cache_key = 'wsm_orders_list_v2_' . md5($cache_epoch . '|' . wp_json_encode($query));
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
        $result = wc_get_orders($query);
        if (is_wp_error($result)) {
            return $result;
        }
        $order_items = is_object($result) && isset($result->orders) ? (array) $result->orders : (array) $result;
        $items = array();
        foreach ($order_items as $o) {
            if ($o instanceof WC_Order && !$o->get_meta('_wsm_invoice')) {
                $items[] = self::format($o);
            }
        }
        $total = is_object($result) && isset($result->total) ? (int) $result->total : count($items);
        $total_pages = is_object($result) && isset($result->max_num_pages) ? (int) $result->max_num_pages : max(1, (int) ceil($total / $per_page));
        $response = array(
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $total_pages,
            'page'        => $page,
        );
        wp_cache_set($cache_key, $response, 'wsm', 900);
        set_transient($cache_key, $response, 900);
        return $response;
    }

    public static function get($id) {
        $o = wc_get_order((int) $id);
        if (!$o || $o->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'سفارش پیدا نشد.', array('status' => 404));
        }
        return self::format($o);
    }

    private static function apply_billing(WC_Order $o, $data) {
        if (isset($data['billing']) && is_array($data['billing'])) {
            foreach (self::BILLING_FIELDS as $f) {
                if (array_key_exists($f, $data['billing']) && is_scalar($data['billing'][$f])) {
                    $method = 'set_billing_' . $f;
                    $o->$method(sanitize_text_field((string) $data['billing'][$f]));
                }
            }
        }
        if (isset($data['shipping']) && is_array($data['shipping'])) {
            foreach (array('first_name', 'last_name', 'address_1', 'address_2', 'city', 'postcode') as $f) {
                if (array_key_exists($f, $data['shipping']) && is_scalar($data['shipping'][$f])) {
                    $method = 'set_shipping_' . $f;
                    $o->$method(sanitize_text_field((string) $data['shipping'][$f]));
                }
            }
        }
        if (isset($data['customer_note']) && is_scalar($data['customer_note'])) {
            $o->set_customer_note(sanitize_textarea_field((string) $data['customer_note']));
        }
    }

    /**
     * جایگزینی اقلام سفارش با لیست جدید: [{product_id, qty, price?}]
     * @return true|WP_Error
     */
    public static function set_items(WC_Order $o, $items, $pos_sale = false) {
        if (!is_array($items)) {
            return new WP_Error('wsm_items_invalid', 'فهرست اقلام نامعتبر است.', array('status' => 400));
        }
        if (count($items) > 500) {
            return new WP_Error('wsm_items_limit', 'هر سفارش حداکثر می‌تواند ۵۰۰ قلم داشته باشد.', array('status' => 400));
        }
        $prepared = array();
        $pos_stock_requested = array();
        foreach ($items as $it) {
            if (!is_array($it)) {
                return new WP_Error('wsm_items_invalid', 'یکی از اقلام سفارش نامعتبر است.', array('status' => 400));
            }
            $pid_raw = isset($it['product_id']) ? $it['product_id'] : 0;
            if (!is_scalar($pid_raw)) {
                return new WP_Error('wsm_items_invalid', 'شناسهٔ کالا نامعتبر است.', array('status' => 400));
            }
            $pid = (int) $pid_raw;
            $qty_raw = isset($it['qty']) ? $it['qty'] : 1;
            $qty = is_scalar($qty_raw) && filter_var((string) $qty_raw, FILTER_VALIDATE_INT) !== false ? (int) $qty_raw : 0;
            if ($qty < 1 || $qty > 100000) {
                return new WP_Error('wsm_items_invalid', 'تعداد هر قلم باید بین ۱ تا ۱۰۰٬۰۰۰ باشد.', array('status' => 400));
            }
            $p = wc_get_product($pid);
            if (!$p) {
                return new WP_Error('wsm_product_missing', 'محصول #' . $pid . ' پیدا نشد.', array('status' => 400));
            }
            if ($pos_sale) {
                $channel = WSM_Products::sales_channel($p);
                if ($channel === 'online') {
                    return new WP_Error('wsm_pos_online_only', 'این کالا فقط برای فروش آنلاین فعال شده و در فروش حضوری قابل ثبت نیست.', array('status' => 409));
                }
                if ($p->get_status() !== 'publish') {
                    return new WP_Error('wsm_pos_product_unpublished', 'فقط کالاهای منتشرشده در فروش حضوری قابل ثبت هستند.', array('status' => 409));
                }
                if ($p->get_stock_status() === 'outofstock' && (!method_exists($p, 'backorders_allowed') || !$p->backorders_allowed())) {
                    return new WP_Error('wsm_pos_out_of_stock', 'کالای ناموجود در فروش حضوری قابل ثبت نیست.', array('status' => 409));
                }
                $stock_id = method_exists($p, 'get_stock_managed_by_id') ? (int) $p->get_stock_managed_by_id() : (int) $p->get_id();
                $stock_product = wc_get_product($stock_id);
                if ($stock_product && $stock_product->get_manage_stock() && !$stock_product->backorders_allowed()) {
                    $available = $stock_product->get_stock_quantity();
                    $pos_stock_requested[$stock_id] = (isset($pos_stock_requested[$stock_id]) ? $pos_stock_requested[$stock_id] : 0) + $qty;
                    if ($available !== null && (float) $available < (float) $pos_stock_requested[$stock_id]) {
                        return new WP_Error('wsm_pos_stock_shortage', 'موجودی برای تعداد درخواستی کافی نیست؛ موجودی فعلی: ' . max(0, (int) $available), array('status' => 409));
                    }
                }
            }
            $price = null;
            if (array_key_exists('price', $it) && $it['price'] !== '') {
                if (!is_scalar($it['price'])) {
                    return new WP_Error('wsm_price_invalid', 'قیمت قلم نامعتبر است.', array('status' => 400));
                }
                $raw_price = trim((string) $it['price']);
                $raw_price = str_replace(array('٫', '٬'), array('.', ''), strtr($raw_price, array(
                    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                    '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                    '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                )));
                $price = wc_format_decimal($raw_price);
                if (!is_numeric($price) || !is_finite((float) $price) || (float) $price < 0 || (float) $price > 1000000000000) {
                    return new WP_Error('wsm_price_invalid', 'قیمت قلم باید عددی و نامنفی باشد.', array('status' => 400));
                }
                $price = (float) $price;
            }
            $prepared[] = array($p, $qty, $price);
        }
        foreach (array_keys($o->get_items()) as $item_id) {
            $o->remove_item($item_id);
        }
        foreach ($prepared as $row) {
            list($p, $qty, $price) = $row;
            if ($price !== null) {
                $o->add_product($p, $qty, array('subtotal' => $price * $qty, 'total' => $price * $qty));
            } else {
                $o->add_product($p, $qty);
            }
        }
        return true;
    }

    public static function update($id, $data) {
        if (!is_array($data)) {
            return new WP_Error('wsm_order_data_invalid', 'اطلاعات سفارش نامعتبر است.', array('status' => 400));
        }
        if (array_key_exists('status', $data) && !is_scalar($data['status'])) {
            return new WP_Error('wsm_bad_status', 'وضعیت سفارش نامعتبر است.', array('status' => 400));
        }
        $o = wc_get_order((int) $id);
        if (!$o || $o->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'سفارش پیدا نشد.', array('status' => 404));
        }
        if ($o->get_meta('_wsm_credit_sale') && isset($data['items'])) {
            return new WP_Error('wsm_credit_order_locked', 'اقلام فروش اعتباری را پس از ثبت نمی‌توان از ویرایش سفارش تغییر داد؛ برای برگشت کالا از مسیر لغو/بازپرداخت استفاده کنید.', array('status' => 409));
        }
        if ($o->get_meta('_wsm_credit_sale') && isset($data['status'])
            && self::clean_status($data['status']) !== $o->get_status()
            && !WSM_Devices::device_can(WSM_Permissions::current_device(), 'customers.account.manage')) {
            return new WP_Error('wsm_device_permission_denied', 'تغییر وضعیت فروش اعتباری به مجوز customers.account.manage نیاز دارد.', array('status' => 403));
        }
        $before = array('status' => $o->get_status(), 'total' => $o->get_total(), 'items' => count($o->get_items()));

        self::apply_billing($o, $data);
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            WSM_Util::save_custom_field_values($o, 'orders', $data['custom_fields']);
        }
        if (isset($data['items'])) {
            $ok = self::set_items($o, $data['items']);
            if (is_wp_error($ok)) {
                return $ok;
            }
        }
        if (!empty($data['status'])) {
            $next_status = self::clean_status($data['status']);
            if (!in_array($next_status, array_column(self::statuses(), 'key'), true)) {
                return new WP_Error('wsm_bad_status', 'وضعیت سفارش نامعتبر است.', array('status' => 400));
            }
            $o->set_status($next_status);
        }
        $o->calculate_totals();
        $o->save();

        WSM_Activity_Log::log('update', 'order', $o->get_id(), '#' . $o->get_order_number(), $before,
            array('status' => $o->get_status(), 'total' => $o->get_total(), 'items' => count($o->get_items())), 'success');
        $invoice_error = '';
        $after_update_order = wc_get_order($o->get_id());
        if (!empty($data['status']) && $after_update_order && $after_update_order->get_status() !== $before['status'] && !absint($after_update_order->get_meta('_wsm_linked_invoice_id'))) {
            $invoice = WSM_Invoices::create_from_order($o->get_id());
            if (is_wp_error($invoice)) {
                $invoice_error = $invoice->get_error_message();
            }
        }
        $response = self::format(wc_get_order($o->get_id()));
        if ($invoice_error !== '') {
            $response['invoice_error'] = $invoice_error;
        }
        return $response;
    }

    private static function clean_status($status) {
        if (!is_scalar($status)) {
            return '';
        }
        $status = sanitize_key((string) $status);
        return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
    }

    public static function set_status($id, $status, $note = '') {
        $o = wc_get_order((int) $id);
        if (!$o || $o->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'سفارش پیدا نشد.', array('status' => 404));
        }
        $status = self::clean_status($status);
        if (!in_array($status, array_column(self::statuses(), 'key'), true)) {
            return new WP_Error('wsm_bad_status', 'وضعیت نامعتبر است.', array('status' => 400));
        }
        $before = $o->get_status();
        if ($o->get_meta('_wsm_credit_sale') && $before !== $status
            && !WSM_Devices::device_can(WSM_Permissions::current_device(), 'customers.account.manage')) {
            return new WP_Error('wsm_device_permission_denied', 'تغییر وضعیت فروش اعتباری به مجوز customers.account.manage نیاز دارد.', array('status' => 403));
        }
        $o->update_status($status, is_scalar($note) ? (sanitize_text_field((string) $note) ?: '') : '');
        WSM_Activity_Log::log('update', 'order', $o->get_id(), '#' . $o->get_order_number(), $before, $status, 'success');

        $invoice_error = '';
        $after_status_order = wc_get_order($o->get_id());
        if ($before !== $status && $after_status_order && !absint($after_status_order->get_meta('_wsm_linked_invoice_id'))) {
            $invoice = WSM_Invoices::create_from_order($o->get_id());
            if (is_wp_error($invoice)) {
                $invoice_error = $invoice->get_error_message();
            }
        }
        $updated = wc_get_order($o->get_id());
        $response = self::format($updated);
        if ($invoice_error !== '') {
            $response['invoice_error'] = $invoice_error;
        }
        return $response;
    }

    public static function delete($id) {
        $o = wc_get_order((int) $id);
        if (!$o || $o->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'سفارش پیدا نشد.', array('status' => 404));
        }
        if ($o->get_meta('_wsm_credit_sale')) {
            return new WP_Error('wsm_credit_order_delete_blocked', 'سفارش اعتباری حذف دائمی نمی‌شود تا سابقهٔ بدهی/وصول حفظ شود؛ وضعیت آن را لغو کنید.', array('status' => 409));
        }
        $paid_status = in_array($o->get_status(), array('processing', 'completed', 'refunded', 'partially-refunded'), true);
        if ($o->get_meta('_wsm_club_pos') || $o->get_meta('_wsm_club_pos_debt') || $paid_status || (method_exists($o, 'is_paid') && $o->is_paid())) {
            return new WP_Error('wsm_financial_order_delete_blocked', 'حذف فیزیکی فروش ثبت‌شده ممنوع است؛ از ابطال/اصلاح یا بازپرداخت ثبت‌شده استفاده کنید.', array('status' => 409));
        }
        $name = '#' . $o->get_order_number();
        $o->delete(true);
        WSM_Activity_Log::log('delete', 'order', (int) $id, $name, 'exists', 'deleted', 'success');
        return array('deleted' => true, 'id' => (int) $id);
    }

    public static function refund($id, $amount, $reason = '') {
        if (!is_scalar($amount) || !is_numeric((string) $amount) || !is_finite((float) $amount) || (float) $amount <= 0) {
            return new WP_Error('wsm_refund_amount', 'مبلغ بازپرداخت نامعتبر است.', array('status' => 400));
        }
        $o = wc_get_order((int) $id);
        if (!$o || $o->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'سفارش پیدا نشد.', array('status' => 404));
        }
        if ($o->get_meta('_wsm_credit_sale')) {
            return new WP_Error('wsm_credit_refund_blocked', 'بازپرداخت فروش اعتباری باید با عملیات حساب مشتری هماهنگ شود؛ ابتدا گردش حساب را بررسی کنید.', array('status' => 409));
        }
        $amount = (float) wc_format_decimal($amount);
        if ($amount <= 0 || $amount > (float) $o->get_remaining_refund_amount()) {
            return new WP_Error('wsm_refund_amount', 'مبلغ بازپرداخت نامعتبر است.', array('status' => 400));
        }
        $refund = wc_create_refund(array(
            'amount'         => $amount,
            'reason'         => WSM_Util::clean_text($reason, 200),
            'order_id'       => $o->get_id(),
            'refund_payment' => false,
        ));
        if (is_wp_error($refund) || !$refund) {
            return new WP_Error('wsm_refund_failed', 'بازپرداخت انجام نشد.', array('status' => 500));
        }
        WSM_Activity_Log::log('refund', 'order', $o->get_id(), '#' . $o->get_order_number(), '', $amount, 'success');
        return self::format(wc_get_order($o->get_id()));
    }

    /**
     * Canonicalize associative payload keys while preserving list order for stable idempotency hashes.
     */
    private static function normalize_manual_sale_payload($value) {
        if (!is_array($value)) { return $value; }
        $keys = array_keys($value);
        if ($keys === array_keys($keys)) {
            $normalized = array();
            foreach ($value as $item) { $normalized[] = self::normalize_manual_sale_payload($item); }
            return $normalized;
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) { $value[$key] = self::normalize_manual_sale_payload($item); }
        return $value;
    }

    private static function manual_sale_request_hash($data) {
        $payload = $data;
        unset($payload['sale_uuid']);
        $canonical = array(
            'user_id' => (int) get_current_user_id(),
            'device_id' => class_exists('WSM_Permissions') ? (int) WSM_Permissions::current_device_id() : 0,
            'payload' => self::normalize_manual_sale_payload($payload),
        );
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return new WP_Error('wsm_sale_payload_invalid', 'ساخت اثرانگشت امن درخواست فروش ممکن نشد.', array('status' => 400));
        }
        return hash('sha256', $json);
    }

    private static function find_manual_sale_by_uuid($sale_uuid) {
        $orders = wc_get_orders(array(
            'limit' => 2,
            'return' => 'objects',
            'meta_query' => array(array(
                'key' => '_wsm_manual_sale_uuid',
                'value' => (string) $sale_uuid,
                'compare' => '=',
            )),
        ));
        if (is_wp_error($orders)) {
            return new WP_Error('wsm_sale_idempotency_lookup_failed', 'بررسی تکراری‌بودن فروش انجام نشد.', array('status' => 500));
        }
        $matches = array();
        foreach ((array) $orders as $order) {
            if ($order instanceof WC_Order
                && (string) $order->get_meta('_wsm_manual_sale_uuid') === (string) $sale_uuid
                && $order->get_meta('_wsm_manual_sale')) {
                $matches[] = $order;
            }
        }
        if (count($matches) > 1) {
            return new WP_Error('wsm_sale_idempotency_ambiguous', 'برای این شناسهٔ فروش بیش از یک سفارش پیدا شد؛ ثبت جدید متوقف شد.', array('status' => 500));
        }
        return isset($matches[0]) ? $matches[0] : null;
    }

    private static function replay_manual_sale(WC_Order $order, $request_hash) {
        if ((string) $order->get_meta('_wsm_manual_sale_state') !== 'complete') {
            return new WP_Error('wsm_sale_in_progress', 'این فروش قبلاً آغاز شده اما نتیجهٔ نهایی آن هنوز تأیید نشده است؛ از ثبت دوباره خودداری شد.', array('status' => 409));
        }
        $saved_hash = (string) $order->get_meta('_wsm_manual_sale_request_hash');
        if ($saved_hash === '' || !hash_equals($saved_hash, (string) $request_hash)) {
            return new WP_Error('wsm_sale_uuid_conflict', 'این شناسه قبلاً برای درخواست فروش دیگری استفاده شده است؛ برای فروش تازه شناسهٔ جدید بسازید.', array('status' => 409));
        }
        return self::format($order);
    }

    private static function acquire_manual_sale_claim($claim_key, $token) {
        $lease = array('token' => (string) $token, 'expires' => time() + 1800);
        if (add_option($claim_key, $lease, '', 'no')) { return true; }
        $current = get_option($claim_key, false);
        if (is_array($current) && isset($current['expires']) && (int) $current['expires'] > time()) { return false; }
        if (!delete_option($claim_key)) { return false; }
        return add_option($claim_key, $lease, '', 'no');
    }

    private static function release_manual_sale_claim($claim_key, $token) {
        $current = get_option($claim_key, false);
        if (is_array($current) && isset($current['token']) && is_string($current['token'])
            && hash_equals($current['token'], (string) $token)) {
            delete_option($claim_key);
        }
    }

    /**
     * Idempotent wrapper for store POS retries. The order meta is the durable record;
     * the option claim serializes concurrent requests without a schema migration.
     */
    public static function create_manual($data) {
        if (!is_array($data)) {
            return new WP_Error('wsm_order_data_invalid', 'اطلاعات فروش دستی نامعتبر است.', array('status' => 400));
        }
        if (!array_key_exists('sale_uuid', $data)) {
            return self::create_manual_order($data);
        }
        if (!is_scalar($data['sale_uuid'])) {
            return new WP_Error('wsm_sale_uuid_invalid', 'شناسهٔ تکرارناپذیر فروش معتبر نیست.', array('status' => 400));
        }
        $sale_uuid = strtolower(trim((string) $data['sale_uuid']));
        if ($sale_uuid === '') { return self::create_manual_order($data); }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $sale_uuid)) {
            return new WP_Error('wsm_sale_uuid_invalid', 'شناسهٔ تکرارناپذیر فروش معتبر نیست.', array('status' => 400));
        }
        $request_hash = self::manual_sale_request_hash($data);
        if (is_wp_error($request_hash)) { return $request_hash; }

        $existing = self::find_manual_sale_by_uuid($sale_uuid);
        if (is_wp_error($existing)) { return $existing; }
        if ($existing) { return self::replay_manual_sale($existing, $request_hash); }

        try {
            $token = bin2hex(random_bytes(16));
        } catch (Exception $exception) {
            return new WP_Error('wsm_sale_lock_random_failed', 'ساخت قفل امن ثبت فروش انجام نشد.', array('status' => 500));
        }
        $claim_key = 'wsm_manual_sale_claim_' . md5($sale_uuid);
        if (!self::acquire_manual_sale_claim($claim_key, $token)) {
            $existing = self::find_manual_sale_by_uuid($sale_uuid);
            if (is_wp_error($existing)) { return $existing; }
            if ($existing) { return self::replay_manual_sale($existing, $request_hash); }
            return new WP_Error('wsm_sale_in_progress', 'این فروش در حال ثبت است؛ چند لحظه بعد دوباره تلاش کنید.', array('status' => 409));
        }

        try {
            $existing = self::find_manual_sale_by_uuid($sale_uuid);
            if (is_wp_error($existing)) { return $existing; }
            if ($existing) { return self::replay_manual_sale($existing, $request_hash); }
            return self::create_manual_order($data, $sale_uuid, $request_hash);
        } finally {
            self::release_manual_sale_claim($claim_key, $token);
        }
    }

    /**
     * ثبت فروش دستی برای مشتری حضوری
     */
    private static function create_manual_order($data, $sale_uuid = '', $request_hash = '') {
        global $wpdb;
        if (!is_array($data)) {
            return new WP_Error('wsm_order_data_invalid', 'اطلاعات فروش دستی نامعتبر است.', array('status' => 400));
        }
        $payment_type = isset($data['payment_type']) && is_scalar($data['payment_type']) ? sanitize_key((string) $data['payment_type']) : 'cash';
        $payment_types = array(
            'cash' => array('wsm_pos_cash', 'نقدی'),
            'card' => array('wsm_pos_card', 'کارتخوان'),
            'transfer' => array('wsm_pos_transfer', 'انتقال بانکی'),
            'credit' => array('wsm_store_account', 'حساب مشتری (نسیه)'),
        );
        if (!isset($payment_types[$payment_type])) {
            return new WP_Error('wsm_payment_method_invalid', 'روش پرداخت فروش حضوری نامعتبر است.', array('status' => 400));
        }

        $account = null;
        $account_id = 0;
        if ($payment_type === 'credit') {
            $account_id = isset($data['account_id']) && is_scalar($data['account_id']) ? absint($data['account_id']) : 0;
            if (!WSM_Devices::device_can(WSM_Permissions::current_device(), 'customers.account.manage')) {
                return new WP_Error('wsm_device_permission_denied', 'برای ثبت فروش نسیه، مجوز customers.account.manage لازم است.', array('status' => 403));
            }
            if (!$account_id) {
                return new WP_Error('wsm_credit_customer_required', 'برای فروش نسیه ابتدا حساب مشتری را انتخاب کنید.', array('status' => 400));
            }
            $account = WSM_Customers::account_identity($account_id);
            if (is_wp_error($account)) { return $account; }
        }

        $o = wc_create_order(array('status' => $payment_type === 'credit' ? 'on-hold' : 'completed'));
        if (is_wp_error($o)) {
            return $o;
        }
        if ($sale_uuid !== '') {
            $o->update_meta_data('_wsm_manual_sale', 1);
            $o->update_meta_data('_wsm_manual_sale_uuid', (string) $sale_uuid);
            $o->update_meta_data('_wsm_manual_sale_request_hash', (string) $request_hash);
            $o->update_meta_data('_wsm_manual_sale_state', 'processing');
            $o->save_meta_data();
            $claimed_order = wc_get_order($o->get_id());
            if (!$claimed_order || (string) $claimed_order->get_meta('_wsm_manual_sale_uuid') !== (string) $sale_uuid
                || (string) $claimed_order->get_meta('_wsm_manual_sale_request_hash') !== (string) $request_hash) {
                $o->delete(true);
                return new WP_Error('wsm_manual_sale_claim_persist_failed', 'ذخیرهٔ شناسهٔ تکرارناپذیر فروش انجام نشد؛ سفارش ثبت نشد.', array('status' => 500));
            }
            $o = $claimed_order;
        }
        self::apply_billing($o, $data);
        if ($account) {
            if (!$o->get_billing_first_name()) { $o->set_billing_first_name($account['name']); }
            if (!$o->get_billing_phone()) { $o->set_billing_phone($account['phone']); }
            if (!$o->get_billing_email() && $account['email']) { $o->set_billing_email($account['email']); }
            $o->update_meta_data('_wsm_store_customer_id', $account_id);
            $o->update_meta_data('_wsm_credit_sale', 1);
        }
        $o->set_payment_method($payment_types[$payment_type][0]);
        $o->set_payment_method_title($payment_types[$payment_type][1]);

        $ok = self::set_items($o, isset($data['items']) ? $data['items'] : array(), true);
        if (is_wp_error($ok)) {
            $o->delete(true);
            return $ok;
        }
        if (!count($o->get_items())) {
            $o->delete(true);
            return new WP_Error('wsm_items_empty', 'حداقل یک قلم کالا لازم است.', array('status' => 400));
        }
        $o->update_meta_data('_wsm_manual_sale', 1);
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            WSM_Util::save_custom_field_values($o, 'orders', $data['custom_fields']);
        }
        $o->set_created_via('wp-store-manager');
        $o->calculate_totals();
        if ((float) $o->get_total() <= 0) {
            $o->delete(true);
            if ($payment_type === 'credit') {
                return new WP_Error('wsm_credit_amount_invalid', 'فروش نسیه باید مبلغی بزرگ‌تر از صفر داشته باشد.', array('status' => 400));
            }
            return new WP_Error('wsm_sale_amount_invalid', 'مبلغ فروش باید بزرگ‌تر از صفر باشد؛ فروش با مبلغ صفر ثبت نمی‌شود.', array('status' => 400));
        }
        $o->save();

        $discard_created_order = static function ($error) use ($o) {
            if (function_exists('wc_increase_stock_levels')) {
                wc_increase_stock_levels((int) $o->get_id());
            }
            $o->delete(true);
            return $error;
        };

        if ($payment_type === 'credit') {
            $ledger = WSM_Customers::record_credit_sale($account_id, $o->get_id(), $o->get_total());
            if (is_wp_error($ledger)) {
                return $discard_created_order($ledger);
            }
        } else {
            $manual_total = wc_format_decimal((string) $o->get_total());
            if (!is_numeric($manual_total) || !is_finite((float) $manual_total) || (float) $manual_total < 0) {
                return $discard_created_order(new WP_Error('wsm_manual_sale_amount_invalid', 'مبلغ فروش دستی نامعتبر است؛ سفارش ثبت نشد.', array('status' => 400)));
            }
            if ((float) $manual_total > 0) {
                if (!class_exists('WSM_Club_Operations') || !is_callable(array('WSM_Club_Operations', 'ensure_financial_core')) || !is_callable(array('WSM_Club_Operations', 'add_finance'))) {
                    return $discard_created_order(new WP_Error('wsm_finance_service_unavailable', 'سرویس دفتر مالی در دسترس نیست؛ فروش ثبت نشد.', array('status' => 503)));
                }
                $finance_core = WSM_Club_Operations::ensure_financial_core();
                if (is_wp_error($finance_core) || !$finance_core) {
                    $finance_error = is_wp_error($finance_core) ? $finance_core : new WP_Error('wsm_finance_core_unavailable', 'راه‌اندازی دفتر مالی کامل نشد؛ فروش ثبت نشد.', array('status' => 503));
                    return $discard_created_order($finance_error);
                }
                if ($wpdb->query('START TRANSACTION') === false) {
                    return $discard_created_order(new WP_Error('wsm_manual_sale_finance_transaction', 'آغاز تراکنش ثبت فروش در دفتر مالی انجام نشد؛ فروش ثبت نشد.', array('status' => 500)));
                }
                $finance_methods = array('cash' => 'cash', 'card' => 'card', 'transfer' => 'bank_transfer');
                $created_at = $o->get_date_created();
                $finance_id = WSM_Club_Operations::add_finance(
                    'pos_sale',
                    $manual_total,
                    'فروش دستی فروشگاه #' . $o->get_order_number(),
                    array(
                        'order_id' => (int) $o->get_id(),
                        'method' => $finance_methods[$payment_type],
                        'source' => 'pos_sale',
                        'reference' => (string) $o->get_order_number(),
                        'reference_id' => 'store-manual-sale:' . (int) $o->get_id(),
                        'transaction_type' => 'income',
                        'occurred_at' => $created_at ? $created_at->date('Y-m-d H:i:s') : current_time('mysql'),
                        'description' => 'ثبت فروش دستی فروشگاه؛ روش پرداخت: ' . $payment_types[$payment_type][1],
                    )
                );
                if (is_wp_error($finance_id)) {
                    $wpdb->query('ROLLBACK');
                    return $discard_created_order($finance_id);
                }
                if (absint($finance_id) < 1) {
                    $wpdb->query('ROLLBACK');
                    return $discard_created_order(new WP_Error('wsm_manual_sale_finance_save', 'ثبت گردش مالی فروش دستی انجام نشد؛ فروش ثبت نشد.', array('status' => 500)));
                }
                if ($wpdb->query('COMMIT') === false) {
                    $wpdb->query('ROLLBACK');
                    return $discard_created_order(new WP_Error('wsm_manual_sale_finance_commit', 'ثبت نهایی گردش مالی فروش دستی انجام نشد؛ فروش ثبت نشد.', array('status' => 500)));
                }
            }
        }
        // The manual sale is delivered immediately; explicitly reduce stock even for on-hold credit orders.
        if (function_exists('wc_reduce_stock_levels')) {
            wc_reduce_stock_levels($o->get_id());
        }
        if (class_exists('WSM_Reports') && is_callable(array('WSM_Reports', 'flush_sale_caches'))) {
            WSM_Reports::flush_sale_caches();
        }
        if (!empty($data['note']) && is_scalar($data['note'])) {
            $o->add_order_note(sanitize_text_field((string) $data['note']));
        }
        WSM_Activity_Log::log('sale', 'order', $o->get_id(), '#' . $o->get_order_number(), '', $o->get_total(), 'success');
        if ($sale_uuid !== '') {
            $o->update_meta_data('_wsm_manual_sale_state', 'complete');
            $o->save_meta_data();
            $confirmed = wc_get_order($o->get_id());
            if (!$confirmed || (string) $confirmed->get_meta('_wsm_manual_sale_state') !== 'complete') {
                return new WP_Error('wsm_manual_sale_unconfirmed', 'فروش ثبت شد اما تأیید شناسهٔ درخواست ممکن نشد؛ پیش از تلاش دوباره سفارش را بررسی کنید.', array('status' => 500));
            }
            $o = $confirmed;
        }
        return self::format(wc_get_order($o->get_id()));
    }

    /**
     * یادداشت‌های سفارش + لاگ اپ برای این سفارش
     */
    public static function history($id) {
        $order = wc_get_order((int) $id);
        if (!$order || $order->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'سفارش پیدا نشد.', array('status' => 404));
        }
        $notes = array();
        foreach ((array) wc_get_order_notes(array('order_id' => (int) $id, 'limit' => 100)) as $n) {
            $notes[] = array(
                'content' => wp_strip_all_tags($n->content),
                'date'    => wsm_fa_date($n->date_created->date('Y-m-d H:i:s')),
                'author'  => $n->added_by,
                'system'  => $n->added_by === 'system',
            );
        }
        $logs = WSM_Activity_Log::query(array('entity_type' => 'order', 'entity_id' => (int) $id, 'limit' => 100));
        return array('notes' => $notes, 'activity' => $logs);
    }
}

