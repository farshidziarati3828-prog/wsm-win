<?php
/**
 * توابع کمکی فارسی: تاریخ شمسی، اعداد فارسی، پول
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wsm_to_persian_digits')) {
    function wsm_to_persian_digits($value) {
        return strtr((string) $value, array(
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ));
    }
}

if (!function_exists('wsm_fa_number')) {
    /**
     * عدد با جداکنندهٔ هزارگان فارسی (٬) و ارقام فارسی
     */
    function wsm_fa_number($number, $decimals = 0) {
        $formatted = number_format((float) $number, (int) $decimals, '٫', '٬');
        return wsm_to_persian_digits($formatted);
    }
}

if (!function_exists('wsm_currency_symbol')) {
    /** Return WooCommerce's currency symbol as plain UTF-8 text, never as an HTML entity/tag. */
    function wsm_currency_symbol() {
        $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '';
        $symbol = html_entity_decode((string) $symbol, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(wp_strip_all_tags($symbol));
    }
}

if (!function_exists('wsm_fa_money')) {
    function wsm_fa_money($amount) {
        return trim(wsm_fa_number($amount) . ' ' . wsm_currency_symbol());
    }
}

if (!function_exists('wsm_gregorian_to_jalali')) {
    /**
     * تبدیل میلادی به شمسی (الگوریتم استاندارد)
     * @return array [jy, jm, jd]
     */
    function wsm_gregorian_to_jalali($gy, $gm, $gd) {
        $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
        if ($gy > 1600) {
            $jy = 979;
            $gy -= 1600;
        } else {
            $jy = 0;
            $gy -= 621;
        }
        $gy2  = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
              + intdiv($gy2 + 399, 400) - 80 + $gd + $g_d_m[$gm - 1];
        $jy += 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return array($jy, $jm, $jd);
    }
}

if (!function_exists('wsm_fa_date')) {
    /**
     * تاریخ و ساعت شمسی: ۱۴۰۵/۰۷/۱۷ ۱۴:۳۰
     */
    function wsm_fa_date($datetime, $with_time = true) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime((string) $datetime);
        if (!$ts) {
            return '—';
        }
        // زمان‌های ذخیره‌شده به وقت محلی سایت هستند (current_time)، پس تاریخ را هم به وقت محلی می‌گیریم
        $local = date('Y-n-j H:i', $ts);
        list($gy, $gm, $gd) = array_map('intval', explode('-', substr($local, 0, strpos($local, ' '))));
        list($jy, $jm, $jd) = wsm_gregorian_to_jalali($gy, $gm, $gd);
        $date = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        $time = $with_time ? ' ' . substr($local, strpos($local, ' ') + 1) : '';
        return wsm_to_persian_digits($date . $time);
    }
}

if (!function_exists('wsm_fa_date_short')) {
    function wsm_fa_date_short($datetime) {
        return wsm_fa_date($datetime, false);
    }
}

