<?php
/**
 * مشتریان ووکامرس: فهرست، جزئیات، تاریخچهٔ سفارش‌ها و مجموع خرید
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Customers {

    public static function list($args = array()) {
        $page     = max(1, (int) (isset($args['page']) ? $args['page'] : 1));
        $per_page = max(1, min(100, (int) (isset($args['per_page']) ? $args['per_page'] : 20)));
        $search   = WSM_Util::clean_text(isset($args['search']) ? $args['search'] : '', 100);

        $query = array(
            'role__in' => array('customer', 'subscriber'),
            'number'   => $per_page,
            'paged'    => $page,
            'orderby'  => 'registered',
            'order'    => 'DESC',
            'count_total' => true,
        );
        if ($search !== '') {
            $query['search']         = '*' . $search . '*';
            $query['search_columns'] = array('user_login', 'user_email', 'display_name');
        }

        $users = new WP_User_Query($query);
        $items = array();
        foreach ((array) $users->get_results() as $u) {
            $items[] = self::brief($u);
        }
        $total = (int) $users->get_total();
        return array(
            'items'       => $items,
            'total'       => $total,
            'total_pages' => (int) ceil($total / $per_page),
            'page'        => $page,
        );
    }

    private static function brief(WP_User $u) {
        $customer = new WC_Customer($u->ID);
        return array(
            'id'           => (int) $u->ID,
            'name'         => trim($customer->get_first_name() . ' ' . $customer->get_last_name()) ?: $u->display_name,
            'email'        => $u->user_email,
            'phone'        => (string) $customer->get_billing_phone(),
            'orders_count' => (int) $customer->get_order_count(),
            'total_spent'  => (string) $customer->get_total_spent(),
            'registered'   => wsm_fa_date($u->user_registered),
        );
    }

    public static function get($id, $args = array()) {
        $u = get_userdata((int) $id);
        if (!$u) {
            return new WP_Error('wsm_not_found', 'مشتری پیدا نشد.', array('status' => 404));
        }
        $customer = new WC_Customer($u->ID);
        $page = max(1, (int) (isset($args['page']) ? $args['page'] : 1));
        $per_page = max(1, min(50, (int) (isset($args['per_page']) ? $args['per_page'] : 20)));
        $order_result = wc_get_orders(array(
            'customer_id' => $u->ID,
            'limit' => $per_page,
            'page' => $page,
            'paginate' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'type' => 'shop_order',
        ));
        if (is_wp_error($order_result)) {
            return $order_result;
        }
        $orders = is_object($order_result) && isset($order_result->orders)
            ? (array) $order_result->orders
            : (array) $order_result;
        $history = array();
        foreach ($orders as $o) {
            $history[] = array(
                'id'      => (int) $o->get_id(),
                'number'  => (string) $o->get_order_number(),
                'status'  => $o->get_status(),
                'date'    => wsm_fa_date($o->get_date_created() ? $o->get_date_created()->date('Y-m-d H:i:s') : ''),
                'total'   => (string) $o->get_total(),
            );
        }
        $total = is_object($order_result) && isset($order_result->total)
            ? (int) $order_result->total
            : count($orders);
        $total_pages = is_object($order_result) && isset($order_result->max_num_pages)
            ? max(1, (int) $order_result->max_num_pages)
            : 1;
        $base = self::brief($u);
        $base['address'] = array(
            'address_1' => $customer->get_billing_address_1(),
            'city'      => $customer->get_billing_city(),
            'postcode'  => $customer->get_billing_postcode(),
        );
        $base['orders'] = $history;
        $base['orders_total'] = $total;
        $base['orders_total_pages'] = $total_pages;
        $base['orders_page'] = $page;
        return $base;
    }
    /* ---------------- دفتر حساب فروشگاه ---------------- */

    private static function account_table() {
        global $wpdb;
        return $wpdb->prefix . 'wsm_store_customers';
    }

    private static function ledger_table() {
        global $wpdb;
        return $wpdb->prefix . 'wsm_customer_ledger';
    }

    /** شماره را برای جلوگیری از ساخت حساب تکراری بر اساس فاصله/خط‌تیره یکسان می‌کند. */
    private static function normalize_phone($value) {
        if (!is_scalar($value)) { return ''; }
        $digits = array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9');
        $phone = strtr(WSM_Util::clean_text($value, 40), $digits);
        return preg_replace('/[^0-9]+/', '', $phone);
    }

    private static function account_row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::account_table() . ' WHERE id = %d LIMIT 1',
            absint($id)
        ), ARRAY_A);
    }

    /** اطلاعات هویتی برای فروش دستی؛ وجود حساب را هم سمت سرور کنترل می‌کند. */
    public static function account_identity($id) {
        $row = self::account_row($id);
        if (!$row) {
            return new WP_Error('wsm_store_customer_missing', 'حساب مشتری پیدا نشد.', array('status' => 404));
        }
        return array(
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'phone' => (string) $row['phone'],
            'email' => (string) $row['email'],
        );
    }

    private static function balance_string($customer_id) {
        global $wpdb;
        $amount = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM ' . self::ledger_table() . ' WHERE customer_id = %d',
            absint($customer_id)
        ));
        return (string) ($amount === null ? '0' : $amount);
    }

    public static function order_balance($order_id) {
        global $wpdb;
        $amount = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM ' . self::ledger_table() . ' WHERE order_id = %d',
            absint($order_id)
        ));
        return (float) ($amount === null ? 0 : $amount);
    }

    private static function account_summary($row, $use_row_balance = false) {
        $balance = $use_row_balance && isset($row['balance'])
            ? (float) $row['balance']
            : (float) self::balance_string((int) $row['id']);
        return array(
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'phone' => (string) $row['phone'],
            'email' => (string) $row['email'],
            'balance' => (string) $balance,
            'balance_display' => wsm_fa_money($balance),
            'has_debt' => $balance > 0.000001,
            'created_at' => wsm_fa_date(isset($row['created_at']) ? $row['created_at'] : ''),
        );
    }

    public static function accounts_list($args = array()) {
        global $wpdb;
        $page = max(1, (int) (isset($args['page']) ? $args['page'] : 1));
        $per_page = max(1, min(100, (int) (isset($args['per_page']) ? $args['per_page'] : 20)));
        $search = WSM_Util::clean_text(isset($args['search']) ? $args['search'] : '', 100);
        $where = 'WHERE 1=1';
        $params = array();
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $phone_key = self::normalize_phone($search);
            $where .= ' AND (c.name LIKE %s OR c.phone LIKE %s OR c.email LIKE %s';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            if ($phone_key !== '') {
                $where .= ' OR c.phone_key LIKE %s';
                $params[] = '%' . $wpdb->esc_like($phone_key) . '%';
            }
            $where .= ')';
        }
        $table = self::account_table();
        $ledger = self::ledger_table();
        $count_sql = 'SELECT COUNT(*) FROM ' . $table . ' c ' . $where;
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);
        $sql = 'SELECT c.*, COALESCE((SELECT SUM(l.amount) FROM ' . $ledger . ' l WHERE l.customer_id = c.id), 0) AS balance FROM ' .
            $table . ' c ' . $where . ' ORDER BY c.updated_at DESC, c.id DESC LIMIT %d OFFSET %d';
        $query_params = $params;
        $query_params[] = $per_page;
        $query_params[] = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);
        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = self::account_summary($row, true);
        }
        $total_balance = $wpdb->get_var('SELECT COALESCE(SUM(amount), 0) FROM ' . $ledger);
        return array(
            'items' => $items,
            'total' => $total,
            'total_pages' => (int) ceil($total / $per_page),
            'page' => $page,
            'receivables' => (string) ($total_balance === null ? '0' : $total_balance),
            'receivables_display' => wsm_fa_money((float) $total_balance),
        );
    }

    public static function account_create($data) {
        global $wpdb;
        if (!is_array($data)) {
            return new WP_Error('wsm_store_customer_invalid', 'اطلاعات مشتری نامعتبر است.', array('status' => 400));
        }
        $name = WSM_Util::clean_text(isset($data['name']) ? $data['name'] : '', 191);
        $phone = WSM_Util::clean_text(isset($data['phone']) ? $data['phone'] : '', 40);
        $phone_key = self::normalize_phone($phone);
        if ($name === '' || strlen($phone_key) < 7 || strlen($phone_key) > 20) {
            return new WP_Error('wsm_store_customer_required', 'نام و شمارهٔ موبایل معتبر مشتری را وارد کنید.', array('status' => 400));
        }
        if (isset($data['email']) && !is_scalar($data['email'])) {
            return new WP_Error('wsm_store_customer_email', 'نشانی ایمیل معتبر نیست.', array('status' => 400));
        }
        $email_raw = isset($data['email']) ? trim((string) $data['email']) : '';
        if ($email_raw !== '' && !is_email($email_raw)) {
            return new WP_Error('wsm_store_customer_email', 'نشانی ایمیل معتبر نیست.', array('status' => 400));
        }
        $email = sanitize_email($email_raw);
        if ($email_raw !== '' && ($email === '' || !is_email($email))) {
            return new WP_Error('wsm_store_customer_email', 'نشانی ایمیل معتبر نیست.', array('status' => 400));
        }
        $table = self::account_table();
        $duplicate = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $table . ' WHERE phone_key = %s LIMIT 1', $phone_key));
        if ($duplicate) {
            return new WP_Error('wsm_store_customer_duplicate', 'برای این شماره قبلاً حساب مشتری ساخته شده است.', array('status' => 409, 'customer_id' => $duplicate));
        }
        $now = current_time('mysql');
        $ok = $wpdb->insert($table, array(
            'name' => $name,
            'phone' => $phone,
            'phone_key' => $phone_key,
            'email' => $email,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ), array('%s','%s','%s','%s','%d','%s','%s'));
        if (!$ok) {
            $duplicate = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $table . ' WHERE phone_key = %s LIMIT 1', $phone_key));
            if ($duplicate) {
                return new WP_Error('wsm_store_customer_duplicate', 'برای این شماره قبلاً حساب مشتری ساخته شده است.', array('status' => 409, 'customer_id' => $duplicate));
            }
            return new WP_Error('wsm_store_customer_save', 'ذخیرهٔ حساب مشتری انجام نشد.', array('status' => 500));
        }
        $id = (int) $wpdb->insert_id;
        WSM_Activity_Log::log('create', 'store_customer', $id, $name, '', array('phone' => $phone), 'success');
        return self::account_get($id);
    }

    public static function account_update($id, $data) {
        global $wpdb;
        if (!is_array($data)) {
            return new WP_Error('wsm_store_customer_invalid', 'اطلاعات مشتری نامعتبر است.', array('status' => 400));
        }
        $row = self::account_row($id);
        if (!$row) {
            return new WP_Error('wsm_store_customer_missing', 'حساب مشتری پیدا نشد.', array('status' => 404));
        }
        $update = array();
        $formats = array();
        if (array_key_exists('name', $data)) {
            $name = WSM_Util::clean_text($data['name'], 191);
            if ($name === '') { return new WP_Error('wsm_store_customer_required', 'نام مشتری نمی‌تواند خالی باشد.', array('status' => 400)); }
            $update['name'] = $name;
            $formats[] = '%s';
        }
        if (array_key_exists('phone', $data)) {
            $phone = WSM_Util::clean_text($data['phone'], 40);
            $phone_key = self::normalize_phone($phone);
            if (strlen($phone_key) < 7 || strlen($phone_key) > 20) { return new WP_Error('wsm_store_customer_required', 'شمارهٔ موبایل مشتری معتبر نیست.', array('status' => 400)); }
            $duplicate = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::account_table() . ' WHERE phone_key = %s AND id <> %d LIMIT 1', $phone_key, absint($id)));
            if ($duplicate) { return new WP_Error('wsm_store_customer_duplicate', 'این شماره به حساب دیگری تعلق دارد.', array('status' => 409, 'customer_id' => $duplicate)); }
            $update['phone'] = $phone;
            $update['phone_key'] = $phone_key;
            $formats[] = '%s';
            $formats[] = '%s';
        }
        if (array_key_exists('email', $data)) {
            if ($data['email'] !== null && !is_scalar($data['email'])) { return new WP_Error('wsm_store_customer_email', 'نشانی ایمیل معتبر نیست.', array('status' => 400)); }
            $email_raw = is_scalar($data['email']) ? trim((string) $data['email']) : '';
            if ($email_raw !== '' && !is_email($email_raw)) { return new WP_Error('wsm_store_customer_email', 'نشانی ایمیل معتبر نیست.', array('status' => 400)); }
            $email = sanitize_email($email_raw);
            if ($email_raw !== '' && ($email === '' || !is_email($email))) { return new WP_Error('wsm_store_customer_email', 'نشانی ایمیل معتبر نیست.', array('status' => 400)); }
            $update['email'] = $email;
            $formats[] = '%s';
        }
        if ($update) {
            $update['updated_at'] = current_time('mysql');
            $formats[] = '%s';
            $wpdb->update(self::account_table(), $update, array('id' => absint($id)), $formats, array('%d'));
            WSM_Activity_Log::log('update', 'store_customer', absint($id), $row['name'], $row, $update, 'success');
        }
        return self::account_get($id);
    }

    private static function insert_ledger_entry($customer_id, $type, $amount, $order_id = 0, $method = '', $note = '', $batch_id = '') {
        global $wpdb;
        $amount = wc_format_decimal((string) $amount, max(0, min(8, (int) wc_get_price_decimals())));
        $ok = $wpdb->insert(self::ledger_table(), array(
            'customer_id' => absint($customer_id),
            'order_id' => absint($order_id),
            'entry_type' => sanitize_key((string) $type),
            'amount' => (string) $amount,
            'payment_method' => sanitize_key((string) $method),
            'batch_id' => sanitize_text_field((string) $batch_id),
            'note' => sanitize_textarea_field((string) $note),
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ), array('%d','%d','%s','%s','%s','%s','%s','%d','%s'));
        return $ok ? (int) $wpdb->insert_id : new WP_Error('wsm_ledger_write_failed', 'ثبت گردش دفتر حساب انجام نشد.', array('status' => 500));
    }

    /** ثبت فروش اعتباری؛ قفل حساب از ثبت دوباره/هم‌زمانی همان سفارش جلوگیری می‌کند. */
    public static function record_credit_sale($customer_id, $order_id, $amount) {
        global $wpdb;
        $customer_id = absint($customer_id);
        $order_id = absint($order_id);
        $amount = round((float) wc_format_decimal((string) $amount), max(0, min(8, (int) wc_get_price_decimals())));
        if (!$customer_id || !$order_id || !is_finite($amount) || $amount <= 0) {
            return new WP_Error('wsm_credit_sale_invalid', 'فروش اعتباری یا مبلغ سفارش نامعتبر است.', array('status' => 400));
        }
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::account_table() . ' WHERE id = %d FOR UPDATE', $customer_id));
        if (!$locked) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_store_customer_missing', 'حساب مشتری پیدا نشد.', array('status' => 404));
        }
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::ledger_table() . ' WHERE customer_id = %d AND order_id = %d AND entry_type = %s LIMIT 1',
            $customer_id, $order_id, 'sale'
        ));
        if ($exists) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_credit_sale_duplicate', 'برای این سفارش قبلاً بدهی ثبت شده است.', array('status' => 409));
        }
        $entry = self::insert_ledger_entry($customer_id, 'sale', $amount, $order_id, 'credit', 'فروش حضوری به حساب مشتری');
        if (is_wp_error($entry)) {
            $wpdb->query('ROLLBACK');
            return $entry;
        }
        $wpdb->query('COMMIT');
        return true;
    }

    public static function account_get($id) {
        global $wpdb;
        $row = self::account_row($id);
        if (!$row) {
            return new WP_Error('wsm_store_customer_missing', 'حساب مشتری پیدا نشد.', array('status' => 404));
        }
        $out = self::account_summary($row);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::ledger_table() . ' WHERE customer_id = %d ORDER BY id DESC LIMIT 100',
            absint($id)
        ), ARRAY_A);
        $labels = array('sale' => 'فروش نسیه', 'payment' => 'دریافت وجه', 'void' => 'ابطال ماندهٔ فروش', 'reinstate' => 'بازگشت بدهی فروش');
        $ledger = array();
        foreach ((array) $rows as $entry) {
            $order_number = '';
            if (!empty($entry['order_id'])) {
                $order = wc_get_order((int) $entry['order_id']);
                if ($order) { $order_number = (string) $order->get_order_number(); }
            }
            $signed = (float) $entry['amount'];
            $ledger[] = array(
                'id' => (int) $entry['id'],
                'type' => (string) $entry['entry_type'],
                'type_label' => isset($labels[$entry['entry_type']]) ? $labels[$entry['entry_type']] : (string) $entry['entry_type'],
                'amount' => (string) $entry['amount'],
                'amount_display' => wsm_fa_money(abs($signed)),
                'direction' => $signed < 0 ? 'debit' : 'credit',
                'payment_method' => (string) $entry['payment_method'],
                'batch_id' => (string) $entry['batch_id'],
                'note' => (string) $entry['note'],
                'order_id' => (int) $entry['order_id'],
                'order_number' => $order_number,
                'date' => wsm_fa_date($entry['created_at']),
            );
        }
        $out['ledger'] = $ledger;
        return $out;
    }

    /** دریافت وجه را به قدیمی‌ترین فروش‌های باز مشتری تخصیص می‌دهد و رسید قابل ردیابی می‌سازد. */
    public static function collect_payment($customer_id, $data) {
        global $wpdb;
        $customer_id = absint($customer_id);
        if (!is_array($data) || !$customer_id || !isset($data['amount']) || !is_scalar($data['amount'])) {
            return new WP_Error('wsm_payment_invalid', 'مبلغ دریافتی نامعتبر است.', array('status' => 400));
        }
        $decimals = max(0, min(8, (int) wc_get_price_decimals()));
        $amount = round((float) wc_format_decimal((string) $data['amount']), $decimals);
        if (!is_finite($amount) || $amount <= 0 || $amount > 1000000000000) {
            return new WP_Error('wsm_payment_invalid', 'مبلغ دریافتی باید عددی و بزرگ‌تر از صفر باشد.', array('status' => 400));
        }
        $method = isset($data['method']) && is_scalar($data['method']) ? sanitize_key((string) $data['method']) : 'cash';
        $methods = array('cash', 'card', 'bank', 'other');
        if (!in_array($method, $methods, true)) {
            return new WP_Error('wsm_payment_method_invalid', 'روش دریافت نامعتبر است.', array('status' => 400));
        }
        $note = isset($data['note']) && is_scalar($data['note']) ? WSM_Util::clean_text($data['note'], 300) : '';
        $tolerance = pow(10, -$decimals) / 2;
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::account_table() . ' WHERE id = %d FOR UPDATE', $customer_id));
        if (!$locked) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_store_customer_missing', 'حساب مشتری پیدا نشد.', array('status' => 404));
        }
        $balance = (float) self::balance_string($customer_id);
        if ($balance <= $tolerance) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_customer_no_debt', 'این مشتری بدهی باز ندارد.', array('status' => 409));
        }
        if ($amount - $balance > $tolerance) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_payment_over_balance', 'مبلغ دریافتی از ماندهٔ بدهی بیشتر است.', array('status' => 409, 'balance' => (string) $balance));
        }
        $charges = $wpdb->get_results($wpdb->prepare(
            'SELECT order_id FROM ' . self::ledger_table() . ' WHERE customer_id = %d AND entry_type = %s AND order_id > 0 ORDER BY id ASC',
            $customer_id, 'sale'
        ), ARRAY_A);
        $batch = wp_generate_uuid4();
        $remaining = $amount;
        $settled_orders = array();
        $seen = array();
        foreach ((array) $charges as $charge) {
            $order_id = absint($charge['order_id']);
            if (!$order_id || isset($seen[$order_id])) { continue; }
            $seen[$order_id] = true;
            $open = self::order_balance($order_id);
            if ($open <= $tolerance) { continue; }
            $applied = min($remaining, $open);
            if ($applied <= $tolerance) { break; }
            $entry = self::insert_ledger_entry($customer_id, 'payment', -$applied, $order_id, $method, $note, $batch);
            if (is_wp_error($entry)) {
                $wpdb->query('ROLLBACK');
                return $entry;
            }
            $remaining -= $applied;
            if ($open - $applied <= $tolerance) { $settled_orders[] = $order_id; }
            if ($remaining <= $tolerance) { break; }
        }
        if ($remaining > $tolerance) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_payment_allocation_failed', 'تخصیص مبلغ به سفارش‌های باز انجام نشد؛ صفحه را تازه کنید.', array('status' => 409));
        }
        $wpdb->query('COMMIT');
        foreach (array_unique($settled_orders) as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_meta('_wsm_credit_sale') && $order->get_status() === 'on-hold') {
                $order->update_status('completed', 'ماندهٔ این سفارش از حساب مشتری به‌طور کامل تسویه شد.');
            }
        }
        $new_balance = (float) self::balance_string($customer_id);
        $account = self::account_row($customer_id);
        WSM_Activity_Log::log('payment', 'store_customer', $customer_id, $account ? $account['name'] : '', $balance, $new_balance, 'success');
        return self::account_get($customer_id);
    }

    /** تغییر وضعیت لغوشده را از مانده جدا می‌کند؛ بازگردانی سفارش نیز در دفتر ثبت می‌شود. */
    public static function sync_credit_order_status($order_id, $old_status, $new_status, $order = null) {
        $order = $order instanceof WC_Order ? $order : wc_get_order((int) $order_id);
        if (!$order || !$order->get_meta('_wsm_credit_sale')) { return; }
        $customer_id = absint($order->get_meta('_wsm_store_customer_id'));
        if (!$customer_id) { return; }
        $closed = in_array((string) $new_status, array('cancelled', 'failed', 'refunded'), true);
        $was_closed = in_array((string) $old_status, array('cancelled', 'failed', 'refunded'), true);
        $reversed = (bool) $order->get_meta('_wsm_credit_voided');
        if ($closed && !$reversed) {
            $open = max(0, self::order_balance($order->get_id()));
            if ($open > 0.000001) {
                $entry = self::insert_ledger_entry($customer_id, 'void', -$open, $order->get_id(), 'adjustment', 'ماندهٔ سفارش پس از لغو از بدهی مشتری خارج شد.');
                if (!is_wp_error($entry)) {
                    $order->update_meta_data('_wsm_credit_voided_amount', (string) $open);
                    $order->update_meta_data('_wsm_credit_voided', 1);
                    $order->save_meta_data();
                }
            } else {
                $order->update_meta_data('_wsm_credit_voided_amount', '0');
                $order->update_meta_data('_wsm_credit_voided', 1);
                $order->save_meta_data();
            }
        } elseif (!$closed && $was_closed && $reversed) {
            $reversed_amount = (float) $order->get_meta('_wsm_credit_voided_amount');
            if ($reversed_amount > 0) {
                $entry = self::insert_ledger_entry($customer_id, 'reinstate', $reversed_amount, $order->get_id(), 'adjustment', 'سفارش دوباره فعال شد و مانده به حساب برگشت.');
                if (is_wp_error($entry)) { return; }
            }
            $order->delete_meta_data('_wsm_credit_voided_amount');
            $order->delete_meta_data('_wsm_credit_voided');
            $order->save_meta_data();
        }
    }

}

