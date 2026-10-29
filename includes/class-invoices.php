<?php
/**
 * فاکتور فروش: هر فاکتور یک سفارش ووکامرس با متای _wsm_invoice=1 است.
 * اقلام با تخفیف درصدی/مبلغی، تخفیف کل فاکتور، مالیات درصدی و هزینهٔ ارسال.
 * خروجی HTML قابل چاپ/ذخیره به PDF از قالب‌های فاکتور و برچسب پستی.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Invoices {

    const STATUSES = array('draft', 'sent', 'paid', 'cancelled');

    private static function label($status) {
        $labels = array('draft' => 'پیش‌نویس', 'sent' => 'ارسال‌شده', 'paid' => 'پرداخت‌شده', 'cancelled' => 'لغوشده');
        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /** نگاشت وضعیت استاندارد ووکامرس به وضعیت قابل نمایش فاکتور. */
    public static function status_from_order($status) {
        $status = sanitize_key((string) $status);
        if (strpos($status, 'wc-') === 0) {
            $status = substr($status, 3);
        }
        if ($status === '') {
            return 'draft';
        }
        if (in_array($status, array('cancelled', 'failed', 'refunded'), true)) {
            return 'cancelled';
        }
        if (in_array($status, array('processing', 'completed'), true)) {
            return 'paid';
        }
        if ($status === 'on-hold') {
            return 'sent';
        }
        if (in_array($status, array('pending', 'draft', 'auto-draft'), true)) {
            return 'draft';
        }
        // وضعیت‌های سفارشیِ فروشگاه به‌صورت فاکتور ارسال‌شده نمایش داده می‌شوند.
        return 'sent';
    }

    /** اطلاعات گیرنده برای برچسب؛ نشانی ارسال بر صورتحساب اولویت دارد. */
    private static function postal_recipient(WC_Order $o) {
        $saved = $o->get_meta('_wsm_postal_recipient', true);
        $saved = is_array($saved) ? $saved : array();
        $shipping = $o->get_address('shipping');
        $billing  = $o->get_address('billing');
        $shipping = is_array($shipping) ? $shipping : array();
        $billing  = is_array($billing) ? $billing : array();

        $shipping_name = trim((isset($shipping['first_name']) ? $shipping['first_name'] : '') . ' ' . (isset($shipping['last_name']) ? $shipping['last_name'] : ''));
        $billing_name  = trim((isset($billing['first_name']) ? $billing['first_name'] : '') . ' ' . (isset($billing['last_name']) ? $billing['last_name'] : ''));
        $shipping_phone = method_exists($o, 'get_shipping_phone') ? (string) $o->get_shipping_phone() : (string) $o->get_meta('_shipping_phone');
        $fallback = array(
            'name'       => $shipping_name !== '' ? $shipping_name : $billing_name,
            'company'    => !empty($shipping['company']) ? $shipping['company'] : (isset($billing['company']) ? $billing['company'] : ''),
            'phone'      => $shipping_phone !== '' ? $shipping_phone : (string) $o->get_billing_phone(),
            'address_1'  => !empty($shipping['address_1']) ? $shipping['address_1'] : (isset($billing['address_1']) ? $billing['address_1'] : ''),
            'address_2'  => !empty($shipping['address_2']) ? $shipping['address_2'] : (isset($billing['address_2']) ? $billing['address_2'] : ''),
            'city'       => !empty($shipping['city']) ? $shipping['city'] : (isset($billing['city']) ? $billing['city'] : ''),
            'state'      => !empty($shipping['state']) ? $shipping['state'] : (isset($billing['state']) ? $billing['state'] : ''),
            'postcode'   => !empty($shipping['postcode']) ? $shipping['postcode'] : (isset($billing['postcode']) ? $billing['postcode'] : ''),
            'country'    => !empty($shipping['country']) ? $shipping['country'] : (isset($billing['country']) ? $billing['country'] : ''),
        );
        $recipient = array();
        foreach ($fallback as $key => $value) {
            $recipient[$key] = array_key_exists($key, $saved) ? (string) $saved[$key] : (string) $value;
        }
        return $recipient;
    }

    private static function discount_of($type, $value, $base) {
        $value = max(0, (float) $value);
        if ($type === 'percent') {
            $value = min(100, $value);
            return round($base * $value / 100);
        }
        if ($type === 'amount') {
            return min($value, $base);
        }
        return 0;
    }

    /** Validate a non-negative finite amount before it reaches WooCommerce. */
    private static function numeric_amount($value, $label) {
        if (!is_scalar($value)) {
            return new WP_Error('wsm_amount_invalid', $label . ' نامعتبر است.', array('status' => 400));
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }
        if (!is_numeric($raw) || !is_finite((float) $raw) || (float) $raw < 0 || (float) $raw > 1000000000000) {
            return new WP_Error('wsm_amount_invalid', $label . ' باید عددی و نامنفی باشد.', array('status' => 400));
        }
        return (float) $raw;
    }

    /**
     * اعتبارسنجی و محاسبهٔ ورودی فاکتور
     * @return array|WP_Error
     */
    private static function normalize($data) {
        if (!is_array($data)) {
            return new WP_Error('wsm_invoice_data_invalid', 'اطلاعات فاکتور نامعتبر است.', array('status' => 400));
        }
        $items_in = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
        if (!count($items_in)) {
            return new WP_Error('wsm_items_empty', 'حداقل یک قلم کالا لازم است.', array('status' => 400));
        }
        if (count($items_in) > 500) {
            return new WP_Error('wsm_items_limit', 'هر فاکتور حداکثر می‌تواند ۵۰۰ قلم داشته باشد.', array('status' => 400));
        }

        $items = array();
        foreach ($items_in as $it) {
            if (!is_array($it)) {
                return new WP_Error('wsm_items_invalid', 'یکی از اقلام فاکتور نامعتبر است.', array('status' => 400));
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
            if (isset($it['name']) && !is_scalar($it['name'])) {
                return new WP_Error('wsm_items_invalid', 'نام قلم کالا نامعتبر است.', array('status' => 400));
            }
            $name = WSM_Util::clean_text(isset($it['name']) ? $it['name'] : '', 255);
            $price = null;
            if (array_key_exists('price', $it) && $it['price'] !== '') {
                if (!is_scalar($it['price'])) {
                    return new WP_Error('wsm_price_invalid', 'قیمت قلم نامعتبر است.', array('status' => 400));
                }
                $price = wc_format_decimal((string) $it['price']);
                if (!is_numeric($price) || !is_finite((float) $price) || (float) $price < 0) {
                    return new WP_Error('wsm_price_invalid', 'قیمت قلم باید عددی و نامنفی باشد.', array('status' => 400));
                }
                $price = (float) $price;
            }
            $product = $pid ? wc_get_product($pid) : null;

            if ($pid && !$product) {
                return new WP_Error('wsm_product_missing', 'محصول #' . $pid . ' پیدا نشد.', array('status' => 400));
            }
            if ($product) {
                $name  = $name ?: $product->get_name();
                $price = $price === null ? (float) $product->get_price() : $price;
            }
            if ($name === '') {
                return new WP_Error('wsm_name_required', 'نام قلم کالا الزامی است.', array('status' => 400));
            }
            $price = max(0, (float) $price);
            $dtype_raw = isset($it['discount_type']) && is_scalar($it['discount_type']) ? (string) $it['discount_type'] : '';
            $dtype = in_array($dtype_raw, array('percent', 'amount'), true) ? $dtype_raw : 'none';
            $dval_raw = isset($it['discount_value']) ? $it['discount_value'] : 0;
            $dval = self::numeric_amount($dval_raw, 'تخفیف قلم');
            if (is_wp_error($dval)) {
                return $dval;
            }

            $gross = $price * $qty;
            $disc  = self::discount_of($dtype, $dval, $gross);
            $items[] = array(
                'product' => $product, 'product_id' => $pid, 'name' => $name, 'qty' => $qty,
                'unit' => $price, 'gross' => $gross, 'line_disc' => $disc, 'net' => $gross - $disc,
                'dtype' => $dtype, 'dval' => $dval,
            );
        }

        $base_net = 0.0;
        $line_disc_total = 0.0;
        $gross_total = 0.0;
        foreach ($items as $row) {
            $base_net        += $row['net'];
            $line_disc_total += $row['line_disc'];
            $gross_total     += $row['gross'];
        }

        $discount_input = isset($data['discount']) && is_array($data['discount']) ? $data['discount'] : array();
        if (isset($data['discount']) && !is_array($data['discount'])) {
            return new WP_Error('wsm_discount_invalid', 'تخفیف فاکتور نامعتبر است.', array('status' => 400));
        }
        $dtype_raw = isset($discount_input['type']) && is_scalar($discount_input['type']) ? (string) $discount_input['type'] : '';
        $dtype_all = in_array($dtype_raw, array('percent', 'amount'), true) ? $dtype_raw : 'none';
        $dval_all = self::numeric_amount(isset($discount_input['value']) ? $discount_input['value'] : 0, 'تخفیف فاکتور');
        if (is_wp_error($dval_all)) {
            return $dval_all;
        }
        $order_disc = self::discount_of($dtype_all, $dval_all, $base_net);
        $after      = $base_net - $order_disc;
        $tax_pct    = self::numeric_amount(isset($data['tax_percent']) ? $data['tax_percent'] : 0, 'درصد مالیات');
        if (is_wp_error($tax_pct)) {
            return $tax_pct;
        }
        $tax_pct = min(100, $tax_pct);
        $tax        = round($after * $tax_pct / 100);
        $shipping   = self::numeric_amount(isset($data['shipping_amount']) ? $data['shipping_amount'] : 0, 'هزینهٔ ارسال');
        if (is_wp_error($shipping)) {
            return $shipping;
        }
        $total      = $after + $tax + $shipping;

        return array(
            'items'          => $items,
            'gross_total'    => $gross_total,
            'line_disc'      => $line_disc_total,
            'order_disc'     => $order_disc,
            'dtype_all'      => $dtype_all,
            'dval_all'       => $dval_all,
            'tax_pct'        => $tax_pct,
            'tax'            => $tax,
            'shipping'       => $shipping,
            'total'          => $total,
        );
    }

    /**
     * ساخت اقلام و مجموع‌ها روی یک سفارش
     */
    private static function apply_to_order(WC_Order $o, $calc, $data) {
        foreach (array_keys($o->get_items(array('line_item', 'fee', 'shipping'))) as $item_id) {
            $o->remove_item($item_id);
        }

        foreach ($calc['items'] as $row) {
            $item = new WC_Order_Item_Product();
            if ($row['product']) {
                $item->set_product($row['product']);
            }
            $item->set_name($row['name']);
            $item->set_quantity($row['qty']);
            $item->set_subtotal($row['gross']);
            $item->set_total($row['net']);
            $item->add_meta_data('_wsm_unit_price', $row['unit'], true);
            $item->add_meta_data('_wsm_disc_type', $row['dtype'], true);
            $item->add_meta_data('_wsm_disc_value', $row['dval'], true);
            $o->add_item($item);
        }

        if ($calc['order_disc'] > 0) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name('تخفیف فاکتور');
            $fee->set_amount(-$calc['order_disc']);
            $fee->set_total(-$calc['order_disc']);
            $fee->add_meta_data('_wsm_fee_kind', 'discount', true);
            $o->add_item($fee);
        }
        if ($calc['tax'] > 0) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name('مالیات (' . $calc['tax_pct'] . '٪)');
            $fee->set_amount($calc['tax']);
            $fee->set_total($calc['tax']);
            $fee->add_meta_data('_wsm_fee_kind', 'tax', true);
            $o->add_item($fee);
        }
        if ($calc['shipping'] > 0) {
            $ship = new WC_Order_Item_Shipping();
            $ship->set_method_title('هزینهٔ ارسال');
            $ship->set_total($calc['shipping']);
            $o->add_item($ship);
        }

        $o->set_discount_total($calc['line_disc'] + $calc['order_disc']);
        $o->set_shipping_total($calc['shipping']);
        $o->set_total($calc['total']);

        $buyer = isset($data['buyer']) && is_array($data['buyer']) ? $data['buyer'] : array();
        $o->set_billing_first_name(WSM_Util::clean_text(isset($buyer['name']) ? $buyer['name'] : '', 100));
        $o->set_billing_last_name('');
        $o->set_billing_phone(sanitize_text_field(isset($buyer['mobile']) ? $buyer['mobile'] : ''));
        $o->set_billing_email(sanitize_email(isset($buyer['email']) ? $buyer['email'] : ''));
        $o->set_billing_address_1(WSM_Util::clean_text(isset($buyer['address']) ? $buyer['address'] : '', 255));
        $o->update_meta_data('_wsm_buyer_national_id', sanitize_text_field(isset($buyer['national_id']) ? $buyer['national_id'] : ''));

        $recipient_input = isset($data['recipient']) && is_array($data['recipient']) ? $data['recipient'] : array();
        $recipient_fallback = self::postal_recipient($o);
        $saved_recipient = $o->get_meta('_wsm_postal_recipient', true);
        $has_saved_recipient = is_array($saved_recipient);
        $recipient_sanitizers = array(
            'name'      => function ($value) { return WSM_Util::clean_text($value, 150); },
            'company'   => function ($value) { return WSM_Util::clean_text($value, 150); },
            'phone'     => function ($value) { return sanitize_text_field($value); },
            'address_1' => function ($value) { return sanitize_textarea_field($value); },
            'address_2' => function ($value) { return sanitize_textarea_field($value); },
            'city'      => function ($value) { return WSM_Util::clean_text($value, 100); },
            'state'     => function ($value) { return WSM_Util::clean_text($value, 100); },
            'postcode'  => function ($value) { return sanitize_text_field($value); },
            'country'   => function ($value) { return WSM_Util::clean_text($value, 100); },
        );
        $recipient = array();
        foreach ($recipient_sanitizers as $field => $sanitize_value) {
            $has_input = array_key_exists($field, $recipient_input) && is_scalar($recipient_input[$field]);
            $value = $has_input ? $sanitize_value((string) $recipient_input[$field]) : '';
            if ($has_input && ($value !== '' || $has_saved_recipient)) {
                $recipient[$field] = $value;
            } else {
                // برای فاکتور دستیِ تازه، نام/تلفن/نشانی خریدار به‌عنوان گیرندهٔ پیش‌فرض استفاده می‌شود.
                $recipient[$field] = isset($recipient_fallback[$field]) ? (string) $recipient_fallback[$field] : '';
            }
        }
        $o->update_meta_data('_wsm_postal_recipient', $recipient);
        $o->set_shipping_first_name($recipient['name']);
        $o->set_shipping_last_name('');
        $o->set_shipping_company($recipient['company']);
        $o->set_shipping_address_1($recipient['address_1']);
        $o->set_shipping_address_2($recipient['address_2']);
        $o->set_shipping_city($recipient['city']);
        $o->set_shipping_state($recipient['state']);
        $o->set_shipping_postcode($recipient['postcode']);
        $o->set_shipping_country($recipient['country']);
        if (method_exists($o, 'set_shipping_phone')) {
            $o->set_shipping_phone($recipient['phone']);
        } else {
            $o->update_meta_data('_shipping_phone', $recipient['phone']);
        }

        $o->update_meta_data('_wsm_discount_type', $calc['dtype_all']);
        $o->update_meta_data('_wsm_discount_value', $calc['dval_all']);
        $o->update_meta_data('_wsm_tax_percent', $calc['tax_pct']);
        $o->set_customer_note(sanitize_textarea_field(isset($data['note']) ? $data['note'] : ''));
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            WSM_Util::save_custom_field_values($o, 'invoices', $data['custom_fields']);
        }
    }

    public static function create($data) {
        $calc = self::normalize($data);
        if (is_wp_error($calc)) {
            return $calc;
        }
        if (isset($data['status']) && !in_array($data['status'], self::STATUSES, true)) {
            return new WP_Error('wsm_bad_status', 'وضعیت فاکتور نامعتبر است.', array('status' => 400));
        }
        $status = isset($data['status']) ? $data['status'] : 'draft';

        $o = wc_create_order(array('status' => 'pending'));
        if (is_wp_error($o)) {
            return $o;
        }
        $o->update_meta_data('_wsm_invoice', 1);
        $o->update_meta_data('_wsm_invoice_status', $status);
        $o->set_created_via('wp-store-manager');
        self::apply_to_order($o, $calc, $data);
        $o->save();

        WSM_Activity_Log::log('invoice', 'invoice', $o->get_id(), 'فاکتور INV-' . $o->get_id(), '', $calc['total'], 'success');
        return self::format(wc_get_order($o->get_id()));
    }

    /**
     * ساخت فاکتورِ مرتبط از روی سفارش موجود، بدون تغییر یا حذف سفارش اصلی.
     * اگر قبلاً فاکتور مرتبط ساخته شده باشد، همان را برمی‌گرداند.
     */
    public static function create_from_order($order_id) {
        $source = wc_get_order((int) $order_id);
        if (!$source || $source->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_order_not_found', 'سفارش برای صدور فاکتور پیدا نشد.', array('status' => 404));
        }

        $linked_id = absint($source->get_meta('_wsm_linked_invoice_id'));
        if ($linked_id) {
            $existing = wc_get_order($linked_id);
            if ($existing && $existing->get_meta('_wsm_invoice') && absint($existing->get_meta('_wsm_source_order_id')) === (int) $source->get_id()) {
                $mapped = self::status_from_order($source->get_status());
                if ((string) $existing->get_meta('_wsm_invoice_status') !== $mapped) {
                    $existing->update_meta_data('_wsm_invoice_status', $mapped);
                    $existing->save_meta_data();
                }
                return self::format($existing);
            }
            $source->delete_meta_data('_wsm_linked_invoice_id');
        }

        $source_items = $source->get_items('line_item');
        if (!$source_items) {
            return new WP_Error('wsm_order_items_empty', 'این سفارش قلم کالایی ندارد و فاکتور از آن ساخته نشد.', array('status' => 400));
        }

        $invoice = wc_create_order(array('status' => 'pending'));
        if (is_wp_error($invoice)) {
            return $invoice;
        }
        $invoice->set_created_via('wp-store-manager');
        $invoice->set_currency($source->get_currency());
        $invoice->set_address($source->get_address('billing'), 'billing');
        $invoice->set_address($source->get_address('shipping'), 'shipping');
        $invoice->update_meta_data('_wsm_postal_recipient', self::postal_recipient($source));
        foreach (array('_wsm_club_member_id','_wsm_club_member_code','_wsm_club_card_number') as $club_meta_key) {
            $club_meta_value = $source->get_meta($club_meta_key, true);
            if ($club_meta_value !== '' && $club_meta_value !== null) { $invoice->update_meta_data($club_meta_key, $club_meta_value); }
        }
        if (method_exists($invoice, 'set_shipping_phone')) {
            $shipping_phone = method_exists($source, 'get_shipping_phone') ? $source->get_shipping_phone() : $source->get_meta('_shipping_phone');
            $invoice->set_shipping_phone($shipping_phone ? $shipping_phone : $source->get_billing_phone());
        }
        $invoice->set_customer_note($source->get_customer_note());

        foreach ($source_items as $source_item) {
            $qty = (float) $source_item->get_quantity();
            $gross = (float) $source_item->get_subtotal();
            $net = (float) $source_item->get_total();
            $product = $source_item->get_product();
            $item = new WC_Order_Item_Product();
            if ($product) {
                $item->set_product($product);
            }
            $item->set_name($source_item->get_name());
            $item->set_quantity($qty);
            $item->set_subtotal($gross);
            $item->set_total($net);
            $item->set_taxes($source_item->get_taxes());
            $discount = max(0, $gross - $net);
            $item->add_meta_data('_wsm_unit_price', $qty > 0 ? $gross / $qty : 0, true);
            $item->add_meta_data('_wsm_disc_type', $discount > 0 ? 'amount' : 'none', true);
            $item->add_meta_data('_wsm_disc_value', $discount, true);
            $invoice->add_item($item);
        }

        foreach ($source->get_items('shipping') as $source_shipping) {
            $shipping = new WC_Order_Item_Shipping();
            $shipping->set_method_title($source_shipping->get_method_title());
            $shipping->set_method_id($source_shipping->get_method_id());
            $shipping->set_instance_id($source_shipping->get_instance_id());
            $shipping->set_total($source_shipping->get_total());
            $shipping->set_taxes($source_shipping->get_taxes());
            $invoice->add_item($shipping);
        }
        foreach ($source->get_items('fee') as $source_fee) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name($source_fee->get_name());
            $fee->set_amount($source_fee->get_amount());
            $fee->set_total($source_fee->get_total());
            $fee->set_tax_status($source_fee->get_tax_status());
            $fee->set_tax_class($source_fee->get_tax_class());
            $fee->set_taxes($source_fee->get_taxes());
            $invoice->add_item($fee);
        }

        $tax_total = (float) $source->get_total_tax();
        $tax_base = max(0, (float) $source->get_total() - (float) $source->get_shipping_total() - $tax_total);
        $tax_percent = $tax_base > 0 ? min(100, round($tax_total / $tax_base * 100, 2)) : 0;
        if ($tax_total > 0) {
            $tax_fee = new WC_Order_Item_Fee();
            $tax_fee->set_name('مالیات');
            $tax_fee->set_amount($tax_total);
            $tax_fee->set_total($tax_total);
            $tax_fee->add_meta_data('_wsm_fee_kind', 'tax', true);
            $invoice->add_item($tax_fee);
        }

        $invoice->set_discount_total((float) $source->get_discount_total());
        $invoice->set_shipping_total((float) $source->get_shipping_total());
        $invoice->set_cart_tax((float) $source->get_cart_tax());
        $invoice->set_shipping_tax((float) $source->get_shipping_tax());
        $invoice->set_total((float) $source->get_total());
        $invoice->update_meta_data('_wsm_invoice', 1);
        $invoice->update_meta_data('_wsm_invoice_status', self::status_from_order($source->get_status()));
        $invoice->update_meta_data('_wsm_source_order_id', (int) $source->get_id());
        $invoice->update_meta_data('_wsm_source_order_number', (string) $source->get_order_number());
        $invoice->update_meta_data('_wsm_discount_type', 'none');
        $invoice->update_meta_data('_wsm_discount_value', 0);
        $invoice->update_meta_data('_wsm_tax_percent', $tax_percent);
        $invoice->save();

        $source->update_meta_data('_wsm_linked_invoice_id', (int) $invoice->get_id());
        $source->save_meta_data();
        WSM_Activity_Log::log('invoice', 'invoice', $invoice->get_id(), 'فاکتور INV-' . $invoice->get_id(),
            'سفارش #' . $source->get_order_number(), self::status_from_order($source->get_status()), 'success');
        return self::format(wc_get_order($invoice->get_id()));
    }

    /** با تغییر وضعیت سفارش اصلی، وضعیت فاکتور مرتبط نیز همگام می‌شود. */
    public static function sync_source_order_status($order_id, $old_status, $new_status, $order = null) {
        $order = $order instanceof WC_Order ? $order : wc_get_order((int) $order_id);
        if (!$order) {
            return;
        }

        $new_invoice_status = self::status_from_order($new_status);
        if ($order->get_meta('_wsm_invoice')) {
            if ((string) $order->get_meta('_wsm_invoice_status') !== $new_invoice_status) {
                $order->update_meta_data('_wsm_invoice_status', $new_invoice_status);
                $order->save_meta_data();
            }
            return;
        }

        $invoice_id = absint($order->get_meta('_wsm_linked_invoice_id'));
        if (!$invoice_id) {
            return;
        }
        $invoice = wc_get_order($invoice_id);
        if (!$invoice || !$invoice->get_meta('_wsm_invoice') || absint($invoice->get_meta('_wsm_source_order_id')) !== (int) $order->get_id()) {
            $order->delete_meta_data('_wsm_linked_invoice_id');
            $order->save_meta_data();
            return;
        }
        if ((string) $invoice->get_meta('_wsm_invoice_status') !== $new_invoice_status) {
            $invoice->update_meta_data('_wsm_invoice_status', $new_invoice_status);
            $invoice->save_meta_data();
        }
    }

    public static function update($id, $data) {
        $o = wc_get_order((int) $id);
        if (!$o || !$o->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'فاکتور پیدا نشد.', array('status' => 404));
        }
        $calc = self::normalize($data);
        if (is_wp_error($calc)) {
            return $calc;
        }
        $before = $o->get_total();
        self::apply_to_order($o, $calc, $data);
        if (isset($data['status'])) {
            if (!in_array($data['status'], self::STATUSES, true)) {
                return new WP_Error('wsm_bad_status', 'وضعیت فاکتور نامعتبر است.', array('status' => 400));
            }
            $o->update_meta_data('_wsm_invoice_status', $data['status']);
        }
        $o->save();

        WSM_Activity_Log::log('update', 'invoice', $o->get_id(), 'فاکتور INV-' . $o->get_id(), $before, $calc['total'], 'success');
        return self::format(wc_get_order($o->get_id()));
    }

    public static function set_status($id, $status) {
        if (!is_scalar($status)) {
            return new WP_Error('wsm_bad_status', 'وضعیت فاکتور نامعتبر است.', array('status' => 400));
        }
        $o = wc_get_order((int) $id);
        if (!$o || !$o->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'فاکتور پیدا نشد.', array('status' => 404));
        }
        if (!in_array($status, self::STATUSES, true)) {
            return new WP_Error('wsm_bad_status', 'وضعیت فاکتور نامعتبر است.', array('status' => 400));
        }
        $before = $o->get_meta('_wsm_invoice_status');
        $o->update_meta_data('_wsm_invoice_status', $status);
        $o->save();
        WSM_Activity_Log::log('update', 'invoice', $o->get_id(), 'فاکتور INV-' . $o->get_id(), $before, $status, 'success');
        return self::format(wc_get_order($o->get_id()));
    }

    public static function delete($id) {
        $o = wc_get_order((int) $id);
        if (!$o || !$o->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'فاکتور پیدا نشد.', array('status' => 404));
        }
        $source_id = absint($o->get_meta('_wsm_source_order_id'));
        if ($source_id) {
            $source = wc_get_order($source_id);
            if ($source && absint($source->get_meta('_wsm_linked_invoice_id')) === (int) $o->get_id()) {
                $source->delete_meta_data('_wsm_linked_invoice_id');
                $source->save_meta_data();
            }
        }
        $o->delete(true);
        WSM_Activity_Log::log('delete', 'invoice', (int) $id, 'فاکتور INV-' . $id, 'exists', 'deleted', 'success');
        return array('deleted' => true, 'id' => (int) $id);
    }

    /**
     * کپی فاکتور قدیمی به‌صورت پیش‌نویس جدید
     */
    public static function copy($id) {
        $src = wc_get_order((int) $id);
        if (!$src || !$src->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'فاکتور پیدا نشد.', array('status' => 404));
        }
        $data = self::to_input($src);
        $data['status'] = 'draft';
        $new = self::create($data);
        if (!is_wp_error($new)) {
            WSM_Activity_Log::log('copy', 'invoice', $new['id'], 'فاکتور INV-' . $new['id'], 'INV-' . $id, 'draft', 'success');
        }
        return $new;
    }

    /**
     * تبدیل فاکتور ذخیره‌شده به ورودی فرم (برای کپی و ویرایش)
     */
    private static function to_input(WC_Order $o) {
        $items = array();
        foreach ($o->get_items('line_item') as $item) {
            $items[] = array(
                'product_id'     => (int) $item->get_product_id(),
                'name'           => $item->get_name(),
                'qty'            => (int) $item->get_quantity(),
                'price'          => (float) $item->get_meta('_wsm_unit_price', true),
                'discount_type'  => $item->get_meta('_wsm_disc_type', true) ?: 'none',
                'discount_value' => (float) $item->get_meta('_wsm_disc_value', true),
            );
        }
        return array(
            'recipient' => self::postal_recipient($o),
            'custom_fields' => WSM_Util::custom_field_values($o, 'invoices'),
            'buyer' => array(
                'name'        => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
                'mobile'      => $o->get_billing_phone(),
                'email'       => $o->get_billing_email(),
                'address'     => $o->get_billing_address_1(),
                'national_id' => $o->get_meta('_wsm_buyer_national_id'),
            ),
            'items'           => $items,
            'discount'        => array('type' => $o->get_meta('_wsm_discount_type') ?: 'none', 'value' => (float) $o->get_meta('_wsm_discount_value')),
            'tax_percent'     => (float) $o->get_meta('_wsm_tax_percent'),
            'shipping_amount' => (float) $o->get_shipping_total(),
            'note'            => $o->get_customer_note(),
        );
    }

    public static function format(WC_Order $o) {
        $items = array();
        $subtotal = 0.0;
        $line_disc = 0.0;
        $item_tax = 0.0;
        foreach ($o->get_items('line_item') as $item_id => $item) {
            $pid    = (int) $item->get_product_id();
            $img_id = $pid ? (int) get_post_thumbnail_id($pid) : 0;
            $gross  = (float) $item->get_subtotal();
            $net    = (float) $item->get_total();
            $subtotal += $gross;
            $line_disc += ($gross - $net);
            $item_taxes = $item->get_taxes();
            if (isset($item_taxes['total']) && is_array($item_taxes['total'])) { $item_tax += array_sum(array_map('floatval', $item_taxes['total'])); }
            $items[] = array(
                'id'             => (int) $item_id,
                'product_id'     => $pid,
                'name'           => $item->get_name(),
                'qty'            => (int) $item->get_quantity(),
                'unit_price'     => (float) $item->get_meta('_wsm_unit_price', true),
                'discount_type'  => $item->get_meta('_wsm_disc_type', true) ?: 'none',
                'discount_value' => (float) $item->get_meta('_wsm_disc_value', true),
                'gross'          => $gross,
                'total'          => $net,
                'image_url'      => $img_id ? (string) wp_get_attachment_image_url($img_id, 'thumbnail') : '',
            );
        }
        $order_disc = 0.0;
        $tax        = $item_tax;
        foreach ($o->get_fees() as $fee) {
            if ($fee->get_meta('_wsm_fee_kind') === 'discount') {
                $order_disc = abs((float) $fee->get_total());
            } elseif ($fee->get_meta('_wsm_fee_kind') === 'tax') {
                $tax += (float) $fee->get_total();
            }
        }
        $status_meta = (string) $o->get_meta('_wsm_invoice_status');
        $status      = $status_meta !== '' ? $status_meta : self::status_from_order($o->get_status());
        $created     = $o->get_date_created();

        return array(
            'id'                   => (int) $o->get_id(),
            'number'               => 'INV-' . $o->get_id(),
            'source_order_id'      => absint($o->get_meta('_wsm_source_order_id')),
            'source_order_number'  => (string) $o->get_meta('_wsm_source_order_number'),
            'club_member_id'       => absint($o->get_meta('_wsm_club_member_id')),
            'club_member_code'     => (string) $o->get_meta('_wsm_club_member_code'),
            'club_card_number'     => (string) $o->get_meta('_wsm_club_card_number'),
            'status'               => $status,
            'status_label'    => self::label($status),
            'date'            => wsm_fa_date($created ? $created->date('Y-m-d H:i:s') : ''),
            'date_iso'        => $created ? $created->date('Y-m-d') : '',
            'buyer'           => array(
                'name'        => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
                'mobile'      => $o->get_billing_phone(),
                'email'       => $o->get_billing_email(),
                'address'     => $o->get_billing_address_1(),
                'national_id' => (string) $o->get_meta('_wsm_buyer_national_id'),
            ),
            'recipient'       => self::postal_recipient($o),
            'custom_fields'   => WSM_Util::custom_field_values($o, 'invoices'),
            'items'           => $items,
            'discount'        => array('type' => $o->get_meta('_wsm_discount_type') ?: 'none', 'value' => (float) $o->get_meta('_wsm_discount_value')),
            'tax_percent'     => (float) $o->get_meta('_wsm_tax_percent'),
            'note'            => $o->get_customer_note(),
            'totals'          => array(
                'subtotal'       => $subtotal,
                'line_discount'  => $line_disc,
                'order_discount' => $order_disc,
                'tax'            => $tax,
                'shipping'       => (float) $o->get_shipping_total(),
                'total'          => (float) $o->get_total(),
            ),
            'total_display'   => wsm_fa_money($o->get_total()),
        );
    }

    /** تولید سند چاپ مستقل برای برچسب پستی ۱۰۰×۱۵۰ میلی‌متر. */
    public static function render_label($id) {
        $order = wc_get_order((int) $id);
        if (!$order || !$order->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'فاکتور پیدا نشد.', array('status' => 404));
        }
        $template = WSM_PATH . 'templates/postal-label.php';
        if (!is_readable($template)) {
            return new WP_Error('wsm_label_template_missing', 'قالب برچسب پستی پیدا نشد.', array('status' => 500));
        }
        $invoice = self::format($order);
        $settings = WSM_Util::settings();
        $sender = array(
            'name'       => (string) $settings['postal_sender_name'],
            'company'    => (string) $settings['postal_sender_company'],
            'phone'      => (string) $settings['postal_sender_phone'],
            'email'      => (string) $settings['postal_sender_email'],
            'address_1'  => (string) $settings['postal_sender_address_1'],
            'address_2'  => (string) $settings['postal_sender_address_2'],
            'city'       => (string) $settings['postal_sender_city'],
            'state'      => (string) $settings['postal_sender_state'],
            'postcode'   => (string) $settings['postal_sender_postcode'],
            'country'    => (string) $settings['postal_sender_country'],
        );
        if ($sender['name'] === '') {
            $sender['name'] = (string) $settings['store_name'];
        }
        if ($sender['company'] === '') {
            $sender['company'] = (string) $settings['store_name'];
        }
        ob_start();
        include $template;
        return ob_get_clean();
    }

    /**
     * فهرست فاکتورها با صفحه‌بندی، فیلتر وضعیت و جستجوی شماره/مشخصات خریدار.
     * فاکتورها سفارش ووکامرس هستند اما فقط از مسیر invoices مدیریت می‌شوند.
     */
    public static function list($args = array()) {
        $page     = max(1, (int) (isset($args['page']) ? $args['page'] : 1));
        $per_page = max(1, min(50, (int) (isset($args['per_page']) ? $args['per_page'] : 20)));
        $status   = isset($args['status']) ? sanitize_key((string) $args['status']) : '';
        $search   = WSM_Util::clean_text(isset($args['search']) ? $args['search'] : '', 100);
        // wc_get_orders meta_query is HPOS-only; resolve IDs to keep invoice paging correct on both datastores.
        $invoice_ids = WSM_Util::order_ids_with_meta('_wsm_invoice', '1');
        $query = array(
            'type' => 'shop_order',
            'limit' => $per_page,
            'page' => $page,
            'paginate' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
            // [0] is a deliberate no-match sentinel when no invoices exist.
            'post__in' => $invoice_ids ? $invoice_ids : array(0),
        );

        // The common unfiltered view uses native order pagination and never loads every invoice.
        if ($status === '' && $search === '') {
            $result = wc_get_orders($query);
            if (is_wp_error($result)) {
                return $result;
            }
            $orders = is_object($result) && isset($result->orders)
                ? (array) $result->orders
                : (array) $result;
            $items = array();
            foreach ($orders as $order) {
                if ($order instanceof WC_Order && $order->get_meta('_wsm_invoice')) {
                    $items[] = self::format($order);
                }
            }
            $total = is_object($result) && isset($result->total) ? (int) $result->total : count($items);
            $total_pages = is_object($result) && isset($result->max_num_pages)
                ? max(1, (int) $result->max_num_pages)
                : max(1, (int) ceil($total / $per_page));
            return array('items' => $items, 'total' => $total, 'total_pages' => $total_pages, 'page' => $page);
        }

        // Filtering retains the existing fuzzy/status semantics, but streams bounded batches.
        $scan_limit = 50;
        $query['limit'] = $scan_limit;
        $query['page'] = 1;
        $offset = ($page - 1) * $per_page;
        $items = array();
        $total = 0;
        $scan_page = 1;
        $max_scan_pages = 100;
        while (true) {
            if ($scan_page > $max_scan_pages) {
                return new WP_Error('wsm_invoice_filter_too_large', 'فیلتر فعلی بیش از ۵۰۰۰ فاکتور را بررسی می‌کند؛ لطفاً فیلتر دقیق‌تری انتخاب کنید.', array('status' => 400));
            }
            $query['page'] = $scan_page;
            $result = wc_get_orders($query);
            if (is_wp_error($result)) {
                return $result;
            }
            $has_pagination = is_object($result) && isset($result->orders);
            $orders = $has_pagination ? (array) $result->orders : (array) $result;
            $max_pages = $has_pagination && isset($result->max_num_pages)
                ? max(1, (int) $result->max_num_pages)
                : ($scan_page + (count($orders) >= $scan_limit ? 1 : 0));
            if ($max_pages > $max_scan_pages) {
                return new WP_Error('wsm_invoice_filter_too_large', 'فیلتر فعلی بیش از ۵۰۰۰ فاکتور را بررسی می‌کند؛ لطفاً فیلتر دقیق‌تری انتخاب کنید.', array('status' => 400));
            }

            foreach ($orders as $order) {
                if (!$order instanceof WC_Order || !$order->get_meta('_wsm_invoice')) {
                    continue;
                }
                $invoice_status = (string) $order->get_meta('_wsm_invoice_status');
                if ($invoice_status === '') {
                    $invoice_status = self::status_from_order($order->get_status());
                }
                if ($status !== '' && in_array($status, self::STATUSES, true) && $invoice_status !== $status) {
                    continue;
                }
                if ($search !== '') {
                    $number = 'INV-' . $order->get_id();
                    $source_number = (string) $order->get_meta('_wsm_source_order_number');
                    $name = $order->get_billing_first_name();
                    $phone = $order->get_billing_phone();
                    $email = $order->get_billing_email();
                    $score = max(
                        WSM_Persian_Normalizer::score($search, $number),
                        WSM_Persian_Normalizer::score($search, (string) $order->get_id()),
                        WSM_Persian_Normalizer::score($search, $source_number),
                        WSM_Persian_Normalizer::score($search, $name),
                        WSM_Persian_Normalizer::score($search, $phone),
                        WSM_Persian_Normalizer::score($search, $email)
                    );
                    if ($score < 0.7) {
                        continue;
                    }
                }
                if ($total >= $offset && count($items) < $per_page) {
                    $items[] = self::format($order);
                }
                $total++;
            }
            if ($scan_page >= $max_pages) {
                break;
            }
            $scan_page++;
        }

        return array(
            'items' => $items,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $per_page)),
            'page' => $page,
        );
    }

    public static function get($id) {
        $order = wc_get_order((int) $id);
        if (!$order || !$order->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'فاکتور پیدا نشد.', array('status' => 404));
        }
        return self::format($order);
    }

    /**
     * ساخت HTML امن و آمادهٔ چاپ/PDF از قالب داخلی.
     * @return string|WP_Error
     */
    public static function render_html($id) {
        $order = wc_get_order((int) $id);
        if (!$order || !$order->get_meta('_wsm_invoice')) {
            return new WP_Error('wsm_not_found', 'فاکتور پیدا نشد.', array('status' => 404));
        }
        $template = WSM_PATH . 'templates/invoice-print.php';
        if (!is_readable($template)) {
            return new WP_Error('wsm_template_missing', 'قالب چاپ فاکتور پیدا نشد.', array('status' => 500));
        }
        $invoice = self::format($order);
        $store   = WSM_Util::settings();
        if (empty($store['store_name'])) {
            $store['store_name'] = get_bloginfo('name');
        }
        ob_start();
        include $template;
        return (string) ob_get_clean();
    }
}
