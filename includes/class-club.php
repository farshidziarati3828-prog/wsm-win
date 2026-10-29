<?php
/**
 * باشگاه: اعضا، طرح‌ها، عضویت‌ها، دریافت‌ها، تردد و حقوق مربیان.
 * داده‌ها در جداول اختصاصی افزونه نگهداری می‌شوند تا با HPOS و چرخهٔ سفارش‌های ووکامرس تداخل نداشته باشند.
 */
if (!defined('ABSPATH')) {
    exit;
}

class WSM_Club {

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'wsm_club_' . sanitize_key($name);
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . self::table('members') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            member_code varchar(40) NOT NULL DEFAULT '',
            card_number varchar(80) NOT NULL DEFAULT '',
            card_balance decimal(20,6) NOT NULL DEFAULT 0,
            first_name varchar(100) NOT NULL DEFAULT '',
            last_name varchar(100) NOT NULL DEFAULT '',
            phone varchar(40) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            device_user_id varchar(32) NOT NULL DEFAULT '',
            join_date date NULL DEFAULT NULL,
            birth_date date NULL DEFAULT NULL,
            gender varchar(20) NOT NULL DEFAULT '',
            marital_status varchar(20) NOT NULL DEFAULT '',
            insurance_number varchar(80) NOT NULL DEFAULT '',
            insurance_expiry date NULL DEFAULT NULL,
            address text NULL,
            collection_id bigint(20) unsigned NOT NULL DEFAULT 0,
            sport_id bigint(20) unsigned NOT NULL DEFAULT 0,
            coach_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            sms_consent tinyint(1) NOT NULL DEFAULT 0,
            notes text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY member_code (member_code),
            KEY phone (phone),
            KEY card_number (card_number),
            KEY last_name (last_name),
            KEY device_user_id (device_user_id),
            KEY join_date (join_date),
            KEY birth_date (birth_date),
            KEY insurance_expiry (insurance_expiry),
            KEY collection_id (collection_id),
            KEY sport_id (sport_id),
            KEY status (status),
            KEY updated_at (updated_at)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('plans') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL DEFAULT '',
            duration_days int(10) unsigned NOT NULL DEFAULT 30,
            price decimal(20,6) NOT NULL DEFAULT 0,
            max_visits int(10) unsigned NOT NULL DEFAULT 0,
            collection_id bigint(20) unsigned NOT NULL DEFAULT 0,
            sport_id bigint(20) unsigned NOT NULL DEFAULT 0,
            start_time time NOT NULL DEFAULT '00:00:00',
            end_time time NOT NULL DEFAULT '23:59:59',
            pool_access tinyint(1) NOT NULL DEFAULT 0,
            sessions_per_week int(10) unsigned NOT NULL DEFAULT 0,
            description text NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY active (active),
            KEY name (name),
            KEY collection_id (collection_id),
            KEY sport_id (sport_id)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('memberships') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_uuid varchar(36) NULL DEFAULT NULL,
            member_id bigint(20) unsigned NOT NULL DEFAULT 0,
            plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
            plan_name varchar(120) NOT NULL DEFAULT '',
            start_date date NOT NULL,
            end_date date NOT NULL,
            price decimal(20,6) NOT NULL DEFAULT 0,
            discount_amount decimal(20,6) NOT NULL DEFAULT 0,
            discount_note varchar(255) NOT NULL DEFAULT '',
            max_visits int(10) unsigned NOT NULL DEFAULT 0,
            sessions_used_before int(10) unsigned NOT NULL DEFAULT 0,
            freeze_days_total int(10) unsigned NOT NULL DEFAULT 0,
            frozen_at datetime NULL DEFAULT NULL,
            freeze_until date NULL DEFAULT NULL,
            resume_status varchar(20) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'active',
            expiry_reminder_sent tinyint(1) NOT NULL DEFAULT 0,
            debt_reminder_sent tinyint(1) NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY client_uuid (client_uuid),
            KEY member_id (member_id),
            KEY plan_id (plan_id),
            KEY period (start_date,end_date),
            KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('payments') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_uuid varchar(36) NULL DEFAULT NULL,
            member_id bigint(20) unsigned NOT NULL DEFAULT 0,
            membership_id bigint(20) unsigned NOT NULL DEFAULT 0,
            amount decimal(20,6) NOT NULL DEFAULT 0,
            method varchar(24) NOT NULL DEFAULT 'cash',
            reference varchar(120) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'paid',
            note varchar(255) NOT NULL DEFAULT '',
            paid_at datetime NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY client_uuid (client_uuid),
            KEY member_id (member_id),
            KEY membership_id (membership_id),
            KEY status (status),
            KEY paid_at (paid_at)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('attendance') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            member_id bigint(20) unsigned NOT NULL DEFAULT 0,
            membership_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_device_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_id varchar(64) NOT NULL DEFAULT '',
            member_code varchar(40) NOT NULL DEFAULT '',
            device_user_id varchar(32) NOT NULL DEFAULT '',
            scan_value varchar(80) NOT NULL DEFAULT '',
            direction varchar(8) NOT NULL DEFAULT 'in',
            event_type varchar(16) NOT NULL DEFAULT 'entry',
            source varchar(20) NOT NULL DEFAULT 'manual',
            decision varchar(12) NOT NULL DEFAULT 'denied',
            reason varchar(190) NOT NULL DEFAULT '',
            occurred_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY device_event (source_device_id,event_id),
            KEY member_id (member_id),
            KEY occurred_at (occurred_at),
            KEY decision (decision),
            KEY event_type (event_type)
        ) $charset;");
        $attendance_table = self::table('attendance');
        $wpdb->query("UPDATE $attendance_table SET event_type='unknown' WHERE member_id=0 AND event_type='entry'");
        $wpdb->query("UPDATE $attendance_table SET event_type='exit' WHERE member_id>0 AND direction='out' AND event_type='entry'");

        dbDelta("CREATE TABLE " . self::table('staff') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(160) NOT NULL DEFAULT '',
            phone varchar(40) NOT NULL DEFAULT '',
            role varchar(100) NOT NULL DEFAULT '',
            compensation_type varchar(20) NOT NULL DEFAULT 'monthly',
            base_amount decimal(20,6) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            notes text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY name (name)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('payroll') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
            period_start date NOT NULL,
            period_end date NOT NULL,
            gross decimal(20,6) NOT NULL DEFAULT 0,
            deductions decimal(20,6) NOT NULL DEFAULT 0,
            net decimal(20,6) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'draft',
            paid_at datetime NULL DEFAULT NULL,
            note varchar(255) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY staff_id (staff_id),
            KEY period (period_start,period_end),
            KEY status (status)
        ) $charset;");
    }

    private static function now() {
        return function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
    }

    private static function today() {
        return function_exists('current_time') ? current_time('Y-m-d') : gmdate('Y-m-d');
    }

    private static function text($value, $max = 190) {
        return WSM_Util::clean_text(is_scalar($value) ? $value : '', $max);
    }

    private static function phone($value) {
        $value = WSM_Persian_Normalizer::normalize(self::text($value, 40));
        return preg_replace('/[^0-9+()\- .]/u', '', $value);
    }

    private static function valid_date($value) {
        if (!is_scalar($value)) {
            return false;
        }
        $value = (string) $value;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function optional_date($value) {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        return self::valid_date($value) ? $value : false;
    }

    private static function amount($value, $label, $allow_zero = false) {
        if (!is_scalar($value)) {
            return new WP_Error('wsm_club_amount_invalid', $label . ' نامعتبر است.', array('status' => 400));
        }
        $input = trim((string) $value);
        // Validate the caller's value first: wc_format_decimal() can turn arbitrary text into zero.
        if ($input === '' || !is_numeric($input)) {
            return new WP_Error('wsm_club_amount_invalid', $label . ' باید عددی ' . ($allow_zero ? 'نامنفی' : 'مثبت') . ' باشد.', array('status' => 400));
        }
        $raw = wc_format_decimal($input, min(6, max(0, (int) wc_get_price_decimals())));
        if ($raw === '' || !is_numeric($raw) || !is_finite((float) $raw) || (float) $raw < 0 || (float) $raw > 1000000000000 || (!$allow_zero && (float) $raw <= 0)) {
            return new WP_Error('wsm_club_amount_invalid', $label . ' باید عددی ' . ($allow_zero ? 'نامنفی' : 'مثبت') . ' باشد.', array('status' => 400));
        }
        return $raw;
    }

    /** Strict non-negative integer parser for bounded numeric fields; unlike id(), never abs() a negative value. */
    private static function strict_non_negative_integer($value) {
        if (!is_scalar($value)) { return false; }
        $raw = trim((string) $value);
        if (!preg_match('/^\\d+$/', $raw)) { return false; }
        $normalized = ltrim($raw, '0');
        if ($normalized === '') { return 0; }
        $max = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($max) || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            return false;
        }
        return (int) $normalized;
    }

    private static function id($value) {
        return is_scalar($value) && filter_var((string) $value, FILTER_VALIDATE_INT) !== false ? absint($value) : 0;
    }

    /** Normalize a terminal user ID to ASCII decimal, keeping it within the ZKTeco-safe uint32 range. */
    private static function normalize_device_user_id($value) {
        if ($value === null) { return ''; }
        if (!is_scalar($value) || is_bool($value)) { return false; }
        $value = trim(strtr((string) $value, array(
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        )));
        if ($value === '') { return ''; }
        if (!preg_match('/^[0-9]{1,10}$/', $value)) { return false; }
        $value = ltrim($value, '0');
        if ($value === '') { return false; }
        if (strlen($value) > 10 || (strlen($value) === 10 && strcmp($value, '4294967295') > 0)) { return false; }
        return $value;
    }

    /** Application-level uniqueness check; returns WP_Error if the lookup itself fails. */
    private static function device_user_id_exists($device_user_id, $exclude_id = 0) {
        global $wpdb;
        if ((string) $device_user_id === '') { return false; }
        $normalized = self::normalize_device_user_id($device_user_id);
        if ($normalized !== false && $normalized !== '') {
            // Also catch legacy values such as "00042" that represent the same numeric device ID.
            $condition = '(device_user_id = %s OR (device_user_id REGEXP %s AND CAST(device_user_id AS UNSIGNED) = CAST(%s AS UNSIGNED)))';
            $params = array($normalized, '^[0-9]+$', $normalized);
        } else {
            $condition = 'device_user_id = %s';
            $params = array((string) $device_user_id);
        }
        $sql = 'SELECT id FROM ' . self::table('members') . ' WHERE ' . $condition . ((int) $exclude_id > 0 ? ' AND id <> %d' : '') . ' LIMIT 1';
        if ((int) $exclude_id > 0) { $params[] = (int) $exclude_id; }
        $wpdb->last_error = '';
        $existing_id = $wpdb->get_var($wpdb->prepare($sql, $params));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_club_device_uid_lookup_failed', 'بررسی یکتایی شناسهٔ دستگاه انجام نشد.', array('status' => 500));
        }
        return !empty($existing_id);
    }

    /** Remove the just-inserted placeholder row if its automatic device ID cannot be claimed. */
    private static function discard_pending_member($member_id) {
        global $wpdb;
        return $wpdb->delete(self::table('members'), array('id' => (int) $member_id), array('%d')) === 1;
    }

    private static function fetch_member($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('members') . ' WHERE id = %d', (int) $id));
    }

    private static function fetch_plan($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('plans') . ' WHERE id = %d', (int) $id));
    }

    private static function fetch_staff($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('staff') . ' WHERE id = %d', (int) $id));
    }

    public static function balance_due($member_id) {
        global $wpdb;
        $sql = 'SELECT COALESCE(SUM(GREATEST(0, m.price - COALESCE(p.paid,0))),0)
                FROM ' . self::table('memberships') . ' m
                LEFT JOIN (SELECT membership_id, SUM(amount) AS paid FROM ' . self::table('payments') . " WHERE status = 'paid' GROUP BY membership_id) p ON p.membership_id = m.id
                WHERE m.member_id = %d AND m.status <> 'cancelled'";
        $value = $wpdb->get_var($wpdb->prepare($sql, (int) $member_id));
        return max(0, (float) $value);
    }

    private static function membership_balance($membership_id) {
        global $wpdb;
        $sql = 'SELECT GREATEST(0, m.price - COALESCE((SELECT SUM(p.amount) FROM ' . self::table('payments') . " p WHERE p.membership_id = m.id AND p.status = 'paid'),0)) FROM " . self::table('memberships') . ' m WHERE m.id = %d';
        return max(0, (float) $wpdb->get_var($wpdb->prepare($sql, (int) $membership_id)));
    }

    private static function current_membership($member_id) {
        global $wpdb;
        $today = self::today();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table('memberships') . " WHERE member_id = %d AND status IN ('active','scheduled') AND start_date <= %s AND end_date >= %s ORDER BY end_date DESC, id DESC LIMIT 1",
            (int) $member_id, $today, $today
        ));
    }

    private static function eligibility($member, $pos_debt_due = 0.0) {
        global $wpdb;
        if (!$member) {
            return array('eligible' => false, 'reason' => 'پروندهٔ عضو فعال نیست.', 'membership' => null, 'balance_due' => 0.0, 'pos_debt_due' => 0.0, 'total_debt_due' => 0.0);
        }
        $wpdb->last_error = '';
        $membership_balance = self::balance_due((int) $member->id);
        $pos_debt_due = max(0.0, (float) $pos_debt_due);
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_club_membership_balance_lookup','محاسبهٔ بدهی عضویت انجام نشد؛ وضعیت دسترسی قابل تعیین نیست.',array('status'=>500));
        }
        $total_debt_due = $membership_balance + $pos_debt_due;
        $debt = array('balance_due' => $membership_balance, 'pos_debt_due' => $pos_debt_due, 'total_debt_due' => $total_debt_due);
        if ($member->status !== 'active') {
            return array_merge(array('eligible' => false, 'reason' => 'پروندهٔ عضو فعال نیست.', 'membership' => null), $debt);
        }
        $wpdb->last_error = '';
        $membership = self::current_membership((int) $member->id);
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_club_membership_lookup','خواندن عضویت معتبر ورزشکار انجام نشد؛ وضعیت دسترسی قابل تعیین نیست.',array('status'=>500));
        }
        if (!$membership) {
            $wpdb->last_error = '';
            $frozen = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('memberships') . " WHERE member_id=%d AND status='frozen' ORDER BY id DESC LIMIT 1", (int) $member->id));
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_club_membership_freeze_lookup','بررسی وضعیت Freeze ورزشکار انجام نشد؛ وضعیت دسترسی قابل تعیین نیست.',array('status'=>500));
            }
            if ($frozen) { return array_merge(array('eligible' => false, 'reason' => 'عضویت این ورزشکار موقتاً فریز شده است.', 'membership' => $frozen), $debt); }
            return array_merge(array('eligible' => false, 'reason' => 'عضویت معتبر امروز ندارد.', 'membership' => null), $debt);
        }
        if ((int) $membership->max_visits > 0) {
            $wpdb->last_error = '';
            $used = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::table('attendance') . " WHERE membership_id = %d AND direction = 'in' AND decision = 'allowed'",
                (int) $membership->id
            ));
            if (!empty($wpdb->last_error) || $used === null) {
                return new WP_Error('wsm_club_membership_sessions_lookup','شمارش سهمیهٔ جلسات انجام نشد؛ وضعیت دسترسی قابل تعیین نیست.',array('status'=>500));
            }
            $used=(int)$used;
            if ($used + (int) $membership->sessions_used_before >= (int) $membership->max_visits) {
                return array_merge(array('eligible' => false, 'reason' => 'سهمیهٔ جلسات این طرح تمام شده است.', 'membership' => $membership), $debt);
            }
        }
        $club_settings = class_exists('WSM_Club_Operations') ? WSM_Club_Operations::settings() : array('max_debt' => '0');
        $max_debt = isset($club_settings['max_debt']) ? max(0.0, (float) $club_settings['max_debt']) : 0.0;
        if ($total_debt_due > $max_debt + 0.000001) {
            return array_merge(array('eligible' => false, 'reason' => 'مجموع بدهی عضویت و خرید از سقف مجاز عبور کرده است؛ ورود مسدود شد.', 'membership' => $membership), $debt);
        }
        $reason = $total_debt_due > 0.000001
            ? 'عضویت معتبر است و مجموع بدهی در سقف مجاز قرار دارد.'
            : 'عضویت معتبر و تسویه‌شده است.';
        return array_merge(array('eligible' => true, 'reason' => $reason, 'membership' => $membership), $debt);
    }

    private static function format_member($row, $pos_debt_due = null) {
        global $wpdb;
        if ($pos_debt_due === null) {
            if (!class_exists('WSM_Club_Operations') || !method_exists('WSM_Club_Operations', 'pos_debt_by_member')) {
                return new WP_Error('wsm_club_pos_debt_unavailable','محاسبهٔ بدهی فروش حضوری برای پروندهٔ ورزشکار در دسترس نیست.',array('status'=>503));
            }
            $debt_map = WSM_Club_Operations::pos_debt_by_member(array((int) $row->id));
            if (is_wp_error($debt_map)) { return $debt_map; }
            $pos_debt_due = isset($debt_map[(int) $row->id]) ? (float) $debt_map[(int) $row->id] : 0.0;
        }
        $eligibility = self::eligibility($row, $pos_debt_due);
        if (is_wp_error($eligibility)) { return $eligibility; }
        $membership = $eligibility['membership'];
        $current = null;
        if ($membership) {
            $wpdb->last_error = '';
            $current_membership_balance = self::membership_balance((int) $membership->id);
            if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_membership_balance_lookup','محاسبهٔ ماندهٔ عضویت جاری انجام نشد.',array('status'=>500)); }
            $current = array(
                'id' => (int) $membership->id,
                'plan_name' => $membership->plan_name,
                'start_date' => $membership->start_date,
                'end_date' => $membership->end_date,
                'price' => (string) $membership->price,
                'balance_due' => $current_membership_balance,
                'max_visits' => (int) $membership->max_visits,
            );
        }
        $item = array(
            'id' => (int) $row->id,
            'member_code' => (string) $row->member_code,
            'first_name' => (string) $row->first_name,
            'last_name' => (string) $row->last_name,
            'name' => trim($row->first_name . ' ' . $row->last_name),
            'card_number' => isset($row->card_number) ? (string) $row->card_number : '',
            'card_balance' => isset($row->card_balance) ? (string) $row->card_balance : '0',
            'phone' => (string) $row->phone,
            'email' => (string) $row->email,
            'device_user_id' => (string) $row->device_user_id,
            'join_date' => isset($row->join_date) ? (string) $row->join_date : '',
            'birth_date' => isset($row->birth_date) ? (string) $row->birth_date : '',
            'gender' => isset($row->gender) ? (string) $row->gender : '',
            'marital_status' => isset($row->marital_status) ? (string) $row->marital_status : '',
            'insurance_number' => isset($row->insurance_number) ? (string) $row->insurance_number : '',
            'insurance_expiry' => isset($row->insurance_expiry) ? (string) $row->insurance_expiry : '',
            'address' => isset($row->address) ? (string) $row->address : '',
            'collection_id' => isset($row->collection_id) ? (int) $row->collection_id : 0,
            'sport_id' => isset($row->sport_id) ? (int) $row->sport_id : 0,
            'coach_id' => isset($row->coach_id) ? (int) $row->coach_id : 0,
            'status' => (string) $row->status,
            'sms_consent' => (bool) $row->sms_consent,
            'notes' => (string) $row->notes,
            'balance_due' => $eligibility['balance_due'],
            'eligible' => (bool) $eligibility['eligible'],
            'eligibility_reason' => $eligibility['reason'],
            'membership' => $current,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        );
        $can_view_debt = class_exists('WSM_Permissions') && (
            WSM_Permissions::has_current_permission('club.reports.view') ||
            WSM_Permissions::has_current_permission('club.finance.manage') ||
            WSM_Permissions::has_current_permission('club.billing.manage')
        );
        if ($can_view_debt) {
            $item['pos_debt_due'] = (float) $eligibility['pos_debt_due'];
            $item['total_debt_due'] = (float) $eligibility['total_debt_due'];
        }
        return $item;
    }

    private static function format_plan($row) {
        return array(
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'duration_days' => (int) $row->duration_days,
            'price' => (string) $row->price,
            'max_visits' => (int) $row->max_visits,
            'collection_id' => isset($row->collection_id) ? (int) $row->collection_id : 0,
            'sport_id' => isset($row->sport_id) ? (int) $row->sport_id : 0,
            'start_time' => isset($row->start_time) ? (string) $row->start_time : '00:00:00',
            'end_time' => isset($row->end_time) ? (string) $row->end_time : '23:59:59',
            'pool_access' => isset($row->pool_access) ? (bool) $row->pool_access : false,
            'sessions_per_week' => isset($row->sessions_per_week) ? (int) $row->sessions_per_week : 0,
            'description' => (string) $row->description,
            'active' => (bool) $row->active,
        );
    }

    public static function overview() {
        global $wpdb;
        $cache_key = 'wsm_club_overview_v3';
        $cached = wp_cache_get($cache_key, 'wsm');
        if (!is_array($cached)) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                wp_cache_set($cache_key, $cached, 'wsm', 900);
            }
        }
        if (is_array($cached) && isset($cached['counts'], $cached['month_revenue'], $cached['today'])) {
            $cached['counts']['portal_pending_reviews'] = class_exists('WSM_Club_Operations') && method_exists('WSM_Club_Operations', 'count_pending_portal_renewal_reviews')
                ? (int) WSM_Club_Operations::count_pending_portal_renewal_reviews()
                : 0;
            return $cached;
        }

        $today = self::today();
        $month_start = current_time('Y-m-01');
        $week_end = (new DateTimeImmutable($today, wp_timezone()))->modify('+7 days')->format('Y-m-d');
        $members = self::table('members');
        $memberships = self::table('memberships');
        $payments = self::table('payments');
        $attendance = self::table('attendance');
        $staff = self::table('staff');
        $active_members = (int) $wpdb->get_var("SELECT COUNT(*) FROM $members WHERE status = 'active'");
        $current_memberships = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT member_id) FROM $memberships WHERE status IN ('active','scheduled') AND start_date <= %s AND end_date >= %s", $today, $today));
        $today_entries = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attendance WHERE direction = 'in' AND occurred_at >= %s", $today . ' 00:00:00'));
        $due_soon = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $memberships WHERE status IN ('active','scheduled','frozen') AND end_date >= %s AND end_date <= %s", $today, $week_end));
        $month_revenue = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM $payments WHERE status = 'paid' AND paid_at >= %s", $month_start . ' 00:00:00'));
        $active_staff = (int) $wpdb->get_var("SELECT COUNT(*) FROM $staff WHERE status = 'active'");
        $debtors = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT m.member_id) FROM $memberships m
             LEFT JOIN (SELECT membership_id,SUM(amount) AS paid FROM $payments WHERE status='paid' GROUP BY membership_id) p ON p.membership_id=m.id
             JOIN $members u ON u.id=m.member_id
             WHERE u.status='active' AND m.price > COALESCE(p.paid,0)"
        );
        $portal_pending_reviews = class_exists('WSM_Club_Operations') && method_exists('WSM_Club_Operations', 'count_pending_portal_renewal_reviews')
            ? (int) WSM_Club_Operations::count_pending_portal_renewal_reviews()
            : 0;
        $response = array(
            'currency' => get_woocommerce_currency(),
            'currency_symbol' => wsm_currency_symbol(),
            'counts' => array(
                'members' => $active_members,
                'current_memberships' => $current_memberships,
                'debtors' => $debtors,
                'today_entries' => $today_entries,
                'expiring_7_days' => $due_soon,
                'active_staff' => $active_staff,
                'portal_pending_reviews' => $portal_pending_reviews,
            ),
            'month_revenue' => $month_revenue,
            'month_revenue_display' => wsm_fa_money($month_revenue),
            'today' => $today,
        );
        wp_cache_set($cache_key, $response, 'wsm', 900);
        set_transient($cache_key, $response, 900);
        return $response;
    }

    public static function invalidate_overview_cache() {
        foreach (array('wsm_club_overview_v3', 'wsm_club_overview_v2') as $cache_key) {
            wp_cache_delete($cache_key, 'wsm');
            delete_transient($cache_key);
        }
    }

    public static function list_members($args = array()) {
        global $wpdb;
        $page = max(1, (int) (isset($args['page']) ? $args['page'] : 1));
        $per_page = max(1, min(100, (int) (isset($args['per_page']) ? $args['per_page'] : 30)));
        $search = self::text(isset($args['search']) ? $args['search'] : '', 100);
        $search_normalized = class_exists('WSM_Persian_Normalizer') ? WSM_Persian_Normalizer::normalize($search) : $search;
        $status = isset($args['status']) && in_array($args['status'], array('active','paused','archived'), true) ? $args['status'] : '';
        $where = array('1=1');
        $params = array();
        if ($status !== '') {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        if ($search !== '') {
            $variants=array($search,$search_normalized);
            $variants[]=strtr($search_normalized,array('ی'=>'ي','ک'=>'ك'));
            $variants[]=strtr($search_normalized,array('0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'));
            $variants[]=strtr($search_normalized,array('0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩'));
            $variants=array_values(array_unique(array_filter($variants,'strlen')));
            $search_groups=array();
            foreach($variants as $variant){
                $like='%'.$wpdb->esc_like($variant).'%';
                $search_groups[]='(member_code LIKE %s OR card_number LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s OR device_user_id LIKE %s)';
                array_push($params,$like,$like,$like,$like,$like,$like);
            }
            $where[]='('.implode(' OR ',$search_groups).')';
        }
        $where_sql = implode(' AND ', $where);
        $count_sql = 'SELECT COUNT(*) FROM ' . self::table('members') . ' WHERE ' . $where_sql;
        $wpdb->last_error = '';
        $total_raw = $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);
        if (!empty($wpdb->last_error) || $total_raw === null) { return new WP_Error('wsm_club_member_search_failed','جست‌وجو و شمارش اعضای باشگاه انجام نشد.',array('status'=>500)); }
        $total = (int) $total_raw;
        $offset = ($page - 1) * $per_page;
        $query = 'SELECT * FROM ' . self::table('members') . ' WHERE ' . $where_sql . ' ORDER BY last_name ASC, first_name ASC, id DESC LIMIT %d OFFSET %d';
        $query_params = array_merge($params, array($per_page, $offset));
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare($query, $query_params));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_member_search_failed','خواندن نتایج اعضای باشگاه انجام نشد.',array('status'=>500)); }
        $rows = (array) $rows;
        $member_ids = array_map(function ($row) { return (int) $row->id; }, $rows);
        if (!class_exists('WSM_Club_Operations') || !method_exists('WSM_Club_Operations','pos_debt_by_member')) { return new WP_Error('wsm_club_pos_debt_unavailable','محاسبهٔ بدهی فروش حضوری برای فهرست اعضا در دسترس نیست.',array('status'=>503)); }
        $pos_debts = WSM_Club_Operations::pos_debt_by_member($member_ids);
        if (is_wp_error($pos_debts)) { return $pos_debts; }
        $items = array();
        foreach ($rows as $row) { $items[] = self::format_member($row, isset($pos_debts[(int) $row->id]) ? $pos_debts[(int) $row->id] : 0.0); }
        return array(
            'items' => $items,
            'total' => $total,
            'total_pages' => (int) ceil($total / $per_page),
            'page' => $page,
        );
    }

    public static function get_member($id) {
        $row = self::fetch_member($id);
        if (!$row) {
            return new WP_Error('wsm_club_member_missing', 'عضو باشگاه پیدا نشد.', array('status' => 404));
        }
        if (!class_exists('WSM_Club_Operations') || !method_exists('WSM_Club_Operations','pos_debt_by_member')) { return new WP_Error('wsm_club_pos_debt_unavailable','محاسبهٔ بدهی فروش حضوری برای پروندهٔ عضو در دسترس نیست.',array('status'=>503)); }
        $pos_debts = WSM_Club_Operations::pos_debt_by_member(array((int) $row->id));
        if (is_wp_error($pos_debts)) { return $pos_debts; }
        $item = self::format_member($row, isset($pos_debts[(int) $row->id]) ? $pos_debts[(int) $row->id] : 0.0);
        $memberships = self::list_memberships(array('member_id' => (int) $id, 'per_page' => 100));
        if (is_wp_error($memberships)) { return $memberships; }
        $payments = self::list_payments(array('member_id' => (int) $id, 'per_page' => 100));
        if (is_wp_error($payments)) { return $payments; }
        $item['memberships'] = $memberships['items'];
        $item['payments'] = $payments['items'];
        return $item;
    }

    public static function save_member($data, $id = 0) {
        global $wpdb;
        if (!is_array($data)) {
            return new WP_Error('wsm_club_member_invalid', 'اطلاعات عضو نامعتبر است.', array('status' => 400));
        }
        $first = array_key_exists('first_name', $data) ? self::text($data['first_name'], 100) : '';
        $last = array_key_exists('last_name', $data) ? self::text($data['last_name'], 100) : '';
        if ($id) {
            $before = self::fetch_member($id);
            if (!$before) {
                return new WP_Error('wsm_club_member_missing', 'عضو باشگاه پیدا نشد.', array('status' => 404));
            }
            $first = array_key_exists('first_name', $data) ? $first : $before->first_name;
            $last = array_key_exists('last_name', $data) ? $last : $before->last_name;
        }
        if (trim($first . ' ' . $last) === '') {
            return new WP_Error('wsm_club_name_required', 'نام یا نام خانوادگی عضو الزامی است.', array('status' => 400));
        }
        $status = isset($data['status']) && in_array($data['status'], array('active','paused','archived'), true) ? $data['status'] : ($id ? $before->status : 'active');
        $phone = array_key_exists('phone', $data) ? self::phone($data['phone']) : ($id ? $before->phone : '');
        if ($phone === '' && !$id) {
            return new WP_Error('wsm_club_phone_required', 'شمارهٔ تماس عضو را وارد کنید.', array('status' => 400));
        }
        $email_value = array_key_exists('email', $data) ? $data['email'] : ($id ? $before->email : '');
        if ($email_value !== null && !is_scalar($email_value)) {
            return new WP_Error('wsm_club_email_invalid', 'ایمیل عضو معتبر نیست.', array('status' => 400));
        }
        $email_input = is_scalar($email_value) ? trim((string) $email_value) : '';
        if ($email_input !== '' && !is_email($email_input)) {
            return new WP_Error('wsm_club_email_invalid', 'ایمیل عضو معتبر نیست.', array('status' => 400));
        }
        $email = $email_input === '' ? '' : sanitize_email($email_input);
        if ($email !== '' && !is_email($email)) {
            return new WP_Error('wsm_club_email_invalid', 'ایمیل عضو معتبر نیست.', array('status' => 400));
        }
        $device_user_id = '';
        if (array_key_exists('device_user_id', $data)) {
            $device_user_id = self::normalize_device_user_id($data['device_user_id']);
            if ($device_user_id === false) {
                return new WP_Error('wsm_club_device_uid_invalid', 'شناسهٔ دستگاه باید عددی بین ۱ تا ۴۲۹۴۹۶۷۲۹۵ باشد.', array('status' => 400));
            }
            // The form says empty means automatic. On edit, that automatic value is this member's ID.
            if ($device_user_id === '' && $id) {
                $device_user_id = self::normalize_device_user_id((string) $id);
                if ($device_user_id === false) {
                    return new WP_Error('wsm_club_device_uid_invalid', 'شناسهٔ خودکار عضو خارج از محدودهٔ قابل‌پشتیبانی دستگاه است.', array('status' => 400));
                }
            }
        } elseif ($id) {
            // Partial updates that omit this field must preserve the existing value.
            $device_user_id = (string) $before->device_user_id;
        }
        if ($device_user_id !== '') {
            $uid_exists = self::device_user_id_exists($device_user_id, $id);
            if (is_wp_error($uid_exists)) { return $uid_exists; }
            if ($uid_exists) {
                return new WP_Error('wsm_club_device_uid_exists', 'این شناسهٔ دستگاه برای عضو دیگری ثبت شده است.', array('status' => 409));
            }
        }
        $card = array_key_exists('card_number', $data) ? trim(self::text($data['card_number'], 80)) : ($id ? (string) $before->card_number : '');
        $card = WSM_Persian_Normalizer::normalize($card);
        if ($card !== '' && !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $card)) {
            return new WP_Error('wsm_club_card_invalid', 'شمارهٔ کارت باید شامل حروف لاتین، عدد، خط تیره یا زیرخط باشد.', array('status' => 400));
        }
        if ($card !== '') {
            $card_sql = 'SELECT id FROM ' . self::table('members') . ' WHERE card_number = %s' . ($id ? ' AND id <> %d' : '') . ' LIMIT 1';
            $card_args = $id ? array($card, (int) $id) : array($card);
            if ($wpdb->get_var($wpdb->prepare($card_sql, $card_args))) {
                return new WP_Error('wsm_club_card_exists', 'این شمارهٔ کارت برای عضو دیگری ثبت شده است.', array('status' => 409));
            }
        }
        $date_fields = array();
        foreach (array('join_date', 'birth_date', 'insurance_expiry') as $date_key) {
            if (array_key_exists($date_key, $data)) {
                $date_value = self::optional_date($data[$date_key]);
            } elseif ($id) {
                $date_value = isset($before->{$date_key}) && $before->{$date_key} !== '0000-00-00' ? $before->{$date_key} : null;
            } else {
                $date_value = $date_key === 'join_date' ? self::today() : null;
            }
            if ($date_value === false) {
                return new WP_Error('wsm_club_date_invalid', 'یکی از تاریخ‌های عضویت، تولد یا بیمه معتبر نیست.', array('status' => 400));
            }
            $date_fields[$date_key] = $date_value;
        }
        $gender_allowed = array('', 'female', 'male', 'other', 'unspecified');
        $marital_allowed = array('', 'single', 'married', 'divorced', 'widowed', 'unspecified');
        $gender = array_key_exists('gender', $data) ? sanitize_key(self::text($data['gender'], 20)) : ($id ? (string) $before->gender : '');
        $marital = array_key_exists('marital_status', $data) ? sanitize_key(self::text($data['marital_status'], 20)) : ($id ? (string) $before->marital_status : '');
        if (!in_array($gender, $gender_allowed, true) || !in_array($marital, $marital_allowed, true)) {
            return new WP_Error('wsm_club_profile_invalid', 'جنسیت یا وضعیت تأهل معتبر نیست.', array('status' => 400));
        }
        $insurance = array_key_exists('insurance_number', $data) ? self::text($data['insurance_number'], 80) : ($id ? (string) $before->insurance_number : '');
        $address = array_key_exists('address', $data) ? sanitize_textarea_field(is_scalar($data['address']) ? (string) $data['address'] : '') : ($id ? (string) $before->address : '');
        $relations = array();
        foreach (array('collection_id' => 'collections', 'sport_id' => 'sports') as $key => $table_name) {
            $value = array_key_exists($key, $data) ? self::id($data[$key]) : ($id ? (int) $before->{$key} : 0);
            if ($value && !WSM_Club_Operations::entity_exists($table_name, $value)) {
                return new WP_Error('wsm_club_reference_invalid', 'مجموعه یا رشتهٔ انتخاب‌شده پیدا نشد.', array('status' => 400));
            }
            $relations[$key] = $value;
        }
        $coach_id = array_key_exists('coach_id', $data) ? self::id($data['coach_id']) : ($id ? (int) $before->coach_id : 0);
        if ($coach_id && !self::fetch_staff($coach_id)) {
            return new WP_Error('wsm_club_coach_invalid', 'مربی انتخاب‌شده پیدا نشد.', array('status' => 400));
        }
        $consent = array_key_exists('sms_consent', $data) ? (rest_sanitize_boolean($data['sms_consent']) ? 1 : 0) : ($id ? (int) $before->sms_consent : 0);
        $notes = array_key_exists('notes', $data) ? sanitize_textarea_field(is_scalar($data['notes']) ? (string) $data['notes'] : '') : ($id ? $before->notes : '');
        $now = self::now();
        if ($id) {
            $old = array('name' => trim($before->first_name . ' ' . $before->last_name), 'status' => $before->status, 'sms_consent' => (int) $before->sms_consent);
            $updated = $wpdb->update(self::table('members'), array(
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone,
                'email' => $email,
                'device_user_id' => $device_user_id,
                'card_number' => $card,
                'join_date' => $date_fields['join_date'],
                'birth_date' => $date_fields['birth_date'],
                'gender' => $gender,
                'marital_status' => $marital,
                'insurance_number' => $insurance,
                'insurance_expiry' => $date_fields['insurance_expiry'],
                'address' => $address,
                'collection_id' => $relations['collection_id'],
                'sport_id' => $relations['sport_id'],
                'coach_id' => $coach_id,
                'status' => $status,
                'sms_consent' => $consent,
                'notes' => $notes,
                'updated_at' => $now,
            ), array('id' => (int) $id));
            if ($updated === false) {
                return new WP_Error('wsm_club_save_failed', 'ذخیرهٔ عضو انجام نشد.', array('status' => 500));
            }
            $member_id = (int) $id;
            WSM_Activity_Log::log('update', 'club_member', $member_id, $old['name'], $old, array('name' => trim($first . ' ' . $last), 'status' => $status, 'sms_consent' => $consent), 'success');
        } else {
            $pending_code = 'PENDING-' . str_replace('-', '', wp_generate_uuid4());
            $inserted = $wpdb->insert(self::table('members'), array(
                'member_code' => $pending_code,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone,
                'email' => $email,
                'device_user_id' => '',
                'card_number' => $card,
                'join_date' => $date_fields['join_date'],
                'birth_date' => $date_fields['birth_date'],
                'gender' => $gender,
                'marital_status' => $marital,
                'insurance_number' => $insurance,
                'insurance_expiry' => $date_fields['insurance_expiry'],
                'address' => $address,
                'collection_id' => $relations['collection_id'],
                'sport_id' => $relations['sport_id'],
                'coach_id' => $coach_id,
                'status' => $status,
                'sms_consent' => $consent,
                'notes' => $notes,
                'created_at' => $now,
                'updated_at' => $now,
            ));
            $member_id = (int) $wpdb->insert_id;
            if ($inserted === false || $member_id < 1) {
                return new WP_Error('wsm_club_save_failed', 'ثبت عضو انجام نشد.', array('status' => 500));
            }
            $code = 'GYM' . str_pad((string) $member_id, 7, '0', STR_PAD_LEFT);
            $uid = $device_user_id !== '' ? $device_user_id : self::normalize_device_user_id((string) $member_id);
            if ($uid === false || $uid === '') {
                $deleted = self::discard_pending_member($member_id);
                if (!$deleted) { return new WP_Error('wsm_club_save_failed', 'شناسهٔ خودکار خارج از محدوده است و پاک‌سازی پروندهٔ ناقص انجام نشد.', array('status' => 500)); }
                return new WP_Error('wsm_club_device_uid_invalid', 'شناسهٔ خودکار عضو خارج از محدودهٔ قابل‌پشتیبانی دستگاه است.', array('status' => 400));
            }
            // Re-check after INSERT: an explicit ID can collide with this newly allocated member ID.
            $uid_exists = self::device_user_id_exists($uid, $member_id);
            if (is_wp_error($uid_exists) || $uid_exists) {
                $deleted = self::discard_pending_member($member_id);
                if (!$deleted) { return new WP_Error('wsm_club_save_failed', 'شناسهٔ دستگاه تکراری/بررسی‌ناپذیر بود و پاک‌سازی پروندهٔ ناقص انجام نشد.', array('status' => 500)); }
                if (is_wp_error($uid_exists)) { return $uid_exists; }
                return new WP_Error('wsm_club_device_uid_exists', 'این شناسهٔ دستگاه برای عضو دیگری ثبت شده است.', array('status' => 409));
            }
            $assigned = $wpdb->update(self::table('members'), array('member_code' => $code, 'device_user_id' => $uid), array('id' => $member_id));
            if ($assigned !== 1) {
                $deleted = self::discard_pending_member($member_id);
                if (!$deleted) { return new WP_Error('wsm_club_save_failed', 'ذخیرهٔ شناسهٔ دستگاه و پاک‌سازی پروندهٔ ناقص انجام نشد.', array('status' => 500)); }
                return new WP_Error('wsm_club_save_failed', 'ذخیرهٔ عضو انجام نشد.', array('status' => 500));
            }
            WSM_Activity_Log::log('create', 'club_member', $member_id, trim($first . ' ' . $last), '', array('member_code' => $code, 'status' => $status, 'sms_consent' => $consent), 'success');
        }
        $member = self::fetch_member($member_id);
        if ($member && class_exists('WSM_Club_Operations')) {
            $phonebook_sync = WSM_Club_Operations::sync_member_to_phonebook($member);
            if (is_wp_error($phonebook_sync) && function_exists('error_log')) {
                error_log('WSM phonebook sync failed for club member #' . (int) $member_id . ': ' . $phonebook_sync->get_error_code());
            }
        }
        if (!$id && $member && !empty($member->sms_consent) && class_exists('WSM_Club_Operations')) {
            WSM_Club_Operations::trigger_sms_event('welcome', $member);
        }
        return self::format_member($member);
    }

    public static function archive_member($id) {
        global $wpdb;
        $row = self::fetch_member($id);
        if (!$row) {
            return new WP_Error('wsm_club_member_missing', 'عضو باشگاه پیدا نشد.', array('status' => 404));
        }
        $ok = $wpdb->update(self::table('members'), array('status' => 'archived', 'updated_at' => self::now()), array('id' => (int) $id));
        if ($ok === false) {
            return new WP_Error('wsm_club_save_failed', 'بایگانی عضو انجام نشد.', array('status' => 500));
        }
        WSM_Activity_Log::log('update', 'club_member', (int) $id, trim($row->first_name . ' ' . $row->last_name), array('status' => $row->status), array('status' => 'archived'), 'success');
        $archived_member = self::fetch_member($id);
        if ($archived_member && class_exists('WSM_Club_Operations')) {
            $phonebook_sync = WSM_Club_Operations::sync_member_to_phonebook($archived_member);
            if (is_wp_error($phonebook_sync) && function_exists('error_log')) {
                error_log('WSM phonebook archive sync failed for club member #' . (int) $id . ': ' . $phonebook_sync->get_error_code());
            }
        }
        return self::format_member($archived_member);
    }

    public static function list_plans($include_inactive = false) {
        global $wpdb;
        $where = $include_inactive ? '1=1' : 'active = 1';
        $wpdb->last_error = '';
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table('plans') . ' WHERE ' . $where . ' ORDER BY active DESC, name ASC, id DESC LIMIT 200');
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_plan_lookup_failed', 'خواندن تعرفه‌های باشگاه انجام نشد.', array('status' => 500)); }
        return array('items' => array_map(array(__CLASS__, 'format_plan'), (array) $rows));
    }

    public static function save_plan($data, $id = 0) {
        global $wpdb;
        if (!is_array($data)) {
            return new WP_Error('wsm_club_plan_invalid', 'اطلاعات طرح نامعتبر است.', array('status' => 400));
        }
        $before = $id ? self::fetch_plan($id) : null;
        if ($id && !$before) {
            return new WP_Error('wsm_club_plan_missing', 'طرح عضویت پیدا نشد.', array('status' => 404));
        }
        $name = array_key_exists('name', $data) ? self::text($data['name'], 120) : ($before ? $before->name : '');
        if ($name === '') {
            return new WP_Error('wsm_club_plan_name_required', 'نام طرح الزامی است.', array('status' => 400));
        }
        $duration = array_key_exists('duration_days', $data) ? self::strict_non_negative_integer($data['duration_days']) : ($before ? (int) $before->duration_days : 0);
        if ($duration === false || $duration < 1 || $duration > 3650) {
            return new WP_Error('wsm_club_plan_duration_invalid', 'مدت طرح باید بین ۱ تا ۳۶۵۰ روز باشد.', array('status' => 400));
        }
        $price = self::amount(array_key_exists('price', $data) ? $data['price'] : ($before ? $before->price : null), 'شهریهٔ طرح', true);
        if (is_wp_error($price)) { return $price; }
        $visits = array_key_exists('max_visits', $data) ? self::strict_non_negative_integer($data['max_visits']) : ($before ? (int) $before->max_visits : 0);
        if ($visits === false || $visits < 0 || $visits > 100000) {
            return new WP_Error('wsm_club_plan_visits_invalid', 'تعداد جلسات خارج از محدوده است.', array('status' => 400));
        }
        $description = array_key_exists('description', $data) ? sanitize_textarea_field(is_scalar($data['description']) ? (string) $data['description'] : '') : ($before ? $before->description : '');
        $active = array_key_exists('active', $data) ? (rest_sanitize_boolean($data['active']) ? 1 : 0) : ($before ? (int) $before->active : 1);
        $relations = array();
        foreach (array('collection_id' => 'collections', 'sport_id' => 'sports') as $key => $table_name) {
            $value = array_key_exists($key, $data) ? self::id($data[$key]) : ($before && isset($before->{$key}) ? (int) $before->{$key} : 0);
            if ($value && !WSM_Club_Operations::entity_exists($table_name, $value)) {
                return new WP_Error('wsm_club_reference_invalid', 'مجموعه یا رشتهٔ تعرفه پیدا نشد.', array('status' => 400));
            }
            $relations[$key] = $value;
        }
        $time_values = array();
        foreach (array('start_time' => '00:00', 'end_time' => '23:59') as $key => $fallback) {
            $value = array_key_exists($key, $data) ? self::text($data[$key], 8) : ($before && isset($before->{$key}) ? substr((string) $before->{$key}, 0, 5) : $fallback);
            if (!preg_match('/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
                return new WP_Error('wsm_club_plan_time_invalid', 'ساعت شروع/پایان تعرفه معتبر نیست.', array('status' => 400));
            }
            $time_values[$key] = strlen($value) === 4 ? '0' . $value . ':00' : $value . ':00';
        }
        $start_minutes = ((int) substr($time_values['start_time'], 0, 2) * 60) + (int) substr($time_values['start_time'], 3, 2);
        $end_minutes = ((int) substr($time_values['end_time'], 0, 2) * 60) + (int) substr($time_values['end_time'], 3, 2);
        if ($start_minutes >= $end_minutes) {
            return new WP_Error('wsm_club_plan_time_invalid', 'ساعت پایان تعرفه باید بعد از ساعت شروع باشد.', array('status' => 400));
        }
        $pool = array_key_exists('pool_access', $data) ? (rest_sanitize_boolean($data['pool_access']) ? 1 : 0) : ($before && isset($before->pool_access) ? (int) $before->pool_access : 0);
        $sessions_week = array_key_exists('sessions_per_week', $data) ? self::strict_non_negative_integer($data['sessions_per_week']) : ($before && isset($before->sessions_per_week) ? (int) $before->sessions_per_week : 0);
        if ($sessions_week === false || $sessions_week < 0 || $sessions_week > 21) { return new WP_Error('wsm_club_plan_sessions_invalid', 'تعداد جلسات هفتگی باید حداکثر ۲۱ باشد.', array('status' => 400)); }
        $values = array('name' => $name, 'duration_days' => $duration, 'price' => $price, 'max_visits' => $visits, 'collection_id' => $relations['collection_id'], 'sport_id' => $relations['sport_id'], 'start_time' => $time_values['start_time'], 'end_time' => $time_values['end_time'], 'pool_access' => $pool, 'sessions_per_week' => $sessions_week, 'description' => $description, 'active' => $active, 'updated_at' => self::now());
        if ($id) {
            $ok = $wpdb->update(self::table('plans'), $values, array('id' => (int) $id));
            if ($ok === false) { return new WP_Error('wsm_club_save_failed', 'ذخیرهٔ طرح انجام نشد.', array('status' => 500)); }
            $plan_id = (int) $id;
            WSM_Activity_Log::log('update', 'club_plan', $plan_id, $name, array('price' => $before->price, 'active' => (int) $before->active), array('price' => $price, 'active' => $active), 'success');
        } else {
            $values['created_at'] = self::now();
            if ($wpdb->insert(self::table('plans'), $values) === false) { return new WP_Error('wsm_club_save_failed', 'ثبت طرح انجام نشد.', array('status' => 500)); }
            $plan_id = (int) $wpdb->insert_id;
            WSM_Activity_Log::log('create', 'club_plan', $plan_id, $name, '', array('price' => $price, 'duration_days' => $duration), 'success');
        }
        return self::format_plan(self::fetch_plan($plan_id));
    }

    public static function archive_plan($id) {
        global $wpdb;
        $row = self::fetch_plan($id);
        if (!$row) {
            return new WP_Error('wsm_club_plan_missing', 'طرح عضویت پیدا نشد.', array('status' => 404));
        }
        if ($wpdb->update(self::table('plans'), array('active' => 0, 'updated_at' => self::now()), array('id' => (int) $id)) === false) {
            return new WP_Error('wsm_club_save_failed', 'بایگانی طرح انجام نشد.', array('status' => 500));
        }
        WSM_Activity_Log::log('update', 'club_plan', (int) $id, $row->name, array('active' => (int) $row->active), array('active' => 0), 'success');
        return self::format_plan(self::fetch_plan($id));
    }

    private static function format_membership($row) {
        $today = self::today();
        $status = (string) $row->status;
        if ($status === 'active' && $row->end_date < $today) { $status = 'expired'; }
        if ($status === 'scheduled' && $row->start_date <= $today && $row->end_date >= $today) { $status = 'active'; }
        return array(
            'id' => (int) $row->id,
            'membership_uuid' => isset($row->client_uuid) ? (string) $row->client_uuid : '',
            'member_id' => (int) $row->member_id,
            'member_code' => isset($row->member_code) ? (string) $row->member_code : '',
            'member_name' => isset($row->member_name) ? (string) $row->member_name : '',
            'plan_id' => (int) $row->plan_id,
            'plan_name' => (string) $row->plan_name,
            'start_date' => (string) $row->start_date,
            'end_date' => (string) $row->end_date,
            'price' => (string) $row->price,
            'discount_amount' => isset($row->discount_amount) ? (string) $row->discount_amount : '0',
            'discount_note' => isset($row->discount_note) ? (string) $row->discount_note : '',
            'paid' => isset($row->paid) ? (float) $row->paid : 0,
            'balance_due' => max(0, (float) $row->price - (isset($row->paid) ? (float) $row->paid : 0)),
            'max_visits' => (int) $row->max_visits,
            'sessions_used_before' => isset($row->sessions_used_before) ? (int) $row->sessions_used_before : 0,
            'sessions_used' => (isset($row->used_visits) ? (int) $row->used_visits : 0) + (isset($row->sessions_used_before) ? (int) $row->sessions_used_before : 0),
            'freeze_days_total' => isset($row->freeze_days_total) ? (int) $row->freeze_days_total : 0,
            'freeze_until' => isset($row->freeze_until) ? (string) $row->freeze_until : '',
            'status' => $status,
        );
    }

    public static function list_memberships($args = array()) {
        global $wpdb;
        $limit = max(1, min(100, (int) (isset($args['per_page']) ? $args['per_page'] : 50)));
        $member_id = isset($args['member_id']) ? self::id($args['member_id']) : 0;
        $status = isset($args['status']) && in_array($args['status'], array('active','scheduled','expired','frozen','cancelled'), true) ? $args['status'] : '';
        $where = array('1=1');
        $params = array();
        if ($member_id) { $where[] = 'm.member_id = %d'; $params[] = $member_id; }
        if ($status === 'active') {
            $today = self::today();
            $where[] = "((m.status='active' AND m.end_date >= %s) OR (m.status='scheduled' AND m.start_date <= %s AND m.end_date >= %s))";
            array_push($params, $today, $today, $today);
        } elseif ($status === 'expired') {
            $where[] = "(m.status='expired' OR (m.status='active' AND m.end_date < %s))";
            $params[] = self::today();
        } elseif ($status === 'scheduled') {
            $today = self::today();
            $where[] = "m.status='scheduled' AND (m.start_date > %s OR m.end_date < %s)";
            array_push($params, $today, $today);
        } elseif ($status !== '') {
            $where[] = 'm.status = %s';
            $params[] = $status;
        }
        $sql = "SELECT m.*, u.member_code, CONCAT(u.first_name,' ',u.last_name) AS member_name, COALESCE(p.paid,0) AS paid, COALESCE(a.used,0) AS used_visits
                FROM " . self::table('memberships') . " m
                LEFT JOIN " . self::table('members') . " u ON u.id=m.member_id
                LEFT JOIN (SELECT membership_id,SUM(amount) AS paid FROM " . self::table('payments') . " WHERE status='paid' GROUP BY membership_id) p ON p.membership_id=m.id
                LEFT JOIN (SELECT membership_id,COUNT(*) AS used FROM " . self::table('attendance') . " WHERE direction='in' AND decision='allowed' GROUP BY membership_id) a ON a.membership_id=m.id
                WHERE " . implode(' AND ', $where) . ' ORDER BY m.start_date DESC,m.id DESC LIMIT %d';
        $params[] = $limit;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_membership_lookup_failed', 'خواندن ثبت‌نام‌های باشگاه انجام نشد.', array('status' => 500)); }
        return array('items' => array_map(array(__CLASS__, 'format_membership'), (array) $rows));
    }

    private static function projected_membership_end($membership) {
        if (!$membership || empty($membership->end_date) || !self::valid_date($membership->end_date)) { return ''; }
        $end = new DateTimeImmutable((string) $membership->end_date, wp_timezone());
        if ((string) $membership->status !== 'frozen') { return $end->format('Y-m-d'); }
        $freeze_start = !empty($membership->frozen_at) ? substr((string) $membership->frozen_at, 0, 10) : '';
        $freeze_until = !empty($membership->freeze_until) ? (string) $membership->freeze_until : '';
        if (!self::valid_date($freeze_start) || !self::valid_date($freeze_until) || $freeze_until <= $freeze_start) {
            $days = 1;
        } else {
            $from = new DateTimeImmutable($freeze_start, wp_timezone());
            $until = new DateTimeImmutable($freeze_until, wp_timezone());
            $days = max(1, (int) $from->diff($until)->days);
        }
        return $end->modify('+' . $days . ' days')->format('Y-m-d');
    }

    private static function next_start_date($member_id) {
        global $wpdb;
        $today = self::today();
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status,start_date,end_date,frozen_at,freeze_until FROM " . self::table('memberships') . " WHERE member_id = %d AND status IN ('active','scheduled','frozen')",
            (int) $member_id
        ));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_membership_schedule_lookup','خواندن پایان عضویت‌های قبلی برای زمان‌بندی عضویت جدید انجام نشد.',array('status'=>500)); }
        $last_end = ''; 
        foreach ((array) $rows as $row) {
            $projected_end = self::projected_membership_end($row);
            if ($projected_end !== '' && $projected_end >= $today && ($last_end === '' || $projected_end > $last_end)) { $last_end = $projected_end; }
        }
        if ($last_end !== '') {
            return (new DateTimeImmutable($last_end, wp_timezone()))->modify('+1 day')->format('Y-m-d');
        }
        return $today;
    }

    private static function find_membership_overlap($member_id, $start, $end) {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,status,start_date,end_date,frozen_at,freeze_until FROM " . self::table('memberships') . " WHERE member_id=%d AND status IN ('active','scheduled','frozen') AND start_date<=%s AND (end_date>=%s OR status='frozen') ORDER BY start_date ASC,id ASC",
            (int) $member_id, (string) $end, (string) $start
        ));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_membership_overlap_lookup','بررسی هم‌پوشانی عضویت‌های قبلی انجام نشد.',array('status'=>500)); }
        foreach ((array) $rows as $row) {
            $existing_end = self::projected_membership_end($row);
            if ($existing_end !== '' && (string) $row->start_date <= (string) $end && $existing_end >= (string) $start) { return $row; }
        }
        return null;
    }

    private static function valid_client_uuid($value) {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /** Full row used to return an idempotent membership replay in the same shape as the list API. */
    private static function membership_row_by_client_uuid($client_uuid) {
        global $wpdb;
        $table = self::table('memberships');
        $member_table = self::table('members');
        $payment_table = self::table('payments');
        $attendance_table = self::table('attendance');
        $sql = "SELECT m.*,u.member_code,CONCAT(u.first_name,' ',u.last_name) AS member_name,
                    COALESCE(p.paid,0) AS paid,COALESCE(a.used,0) AS used_visits
                FROM $table m
                LEFT JOIN $member_table u ON u.id=m.member_id
                LEFT JOIN (SELECT membership_id,SUM(amount) AS paid FROM $payment_table WHERE status='paid' GROUP BY membership_id) p ON p.membership_id=m.id
                LEFT JOIN (SELECT membership_id,COUNT(*) AS used FROM $attendance_table WHERE direction='in' AND decision='allowed' GROUP BY membership_id) a ON a.membership_id=m.id
                WHERE m.client_uuid=%s LIMIT 1";
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare($sql, strtolower($client_uuid)));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_club_membership_lookup_failed', 'بررسی عضویت قبلی انجام نشد.', array('status' => 500));
        }
        return $row;
    }

    /** Return the original membership for an exact UUID replay, or null if it is new. */
    private static function membership_uuid_replay($membership_uuid, $member_id, $plan_id, $data) {
        if ($membership_uuid === '') { return null; }
        $existing = self::membership_row_by_client_uuid($membership_uuid);
        if (is_wp_error($existing)) { return $existing; }
        if (!$existing) { return null; }
        if ((int) $existing->member_id !== (int) $member_id || (int) $existing->plan_id !== (int) $plan_id) {
            return new WP_Error('wsm_club_membership_uuid_conflict', 'این شناسهٔ عضویت قبلاً برای عضو یا تعرفهٔ دیگری ثبت شده است.', array('status' => 409));
        }
        if (array_key_exists('start_date', $data) && $data['start_date'] !== null) {
            if (!is_scalar($data['start_date'])) {
                return new WP_Error('wsm_club_membership_uuid_conflict', 'تاریخ شروع با عضویت ثبت‌شده برای این شناسه یکسان نیست.', array('status' => 409));
            }
            $requested_start = trim((string) $data['start_date']);
            if ($requested_start !== '' && (string) $existing->start_date !== $requested_start) {
                return new WP_Error('wsm_club_membership_uuid_conflict', 'تاریخ شروع با عضویت ثبت‌شده برای این شناسه یکسان نیست.', array('status' => 409));
            }
        }
        if (array_key_exists('discount_amount', $data)) {
            $retry_discount = self::amount($data['discount_amount'], 'تخفیف عضویت', true);
            if (is_wp_error($retry_discount)) { return $retry_discount; }
            if (abs((float) $existing->discount_amount - (float) $retry_discount) > 0.000001) {
                return new WP_Error('wsm_club_membership_uuid_conflict', 'مبلغ تخفیف با عضویت ثبت‌شده برای این شناسه یکسان نیست.', array('status' => 409));
            }
        }
        if (array_key_exists('sessions_used_before', $data) && (int) $existing->sessions_used_before !== self::id($data['sessions_used_before'])) {
            return new WP_Error('wsm_club_membership_uuid_conflict', 'تعداد جلسات قبلی با عضویت ثبت‌شده برای این شناسه یکسان نیست.', array('status' => 409));
        }
        return self::format_membership($existing);
    }

    public static function create_membership($data) {
        global $wpdb;
        if (!is_array($data)) { return new WP_Error('wsm_club_membership_invalid', 'اطلاعات عضویت نامعتبر است.', array('status' => 400)); }
        $member_id = self::id(isset($data['member_id']) ? $data['member_id'] : 0);
        $plan_id = self::id(isset($data['plan_id']) ? $data['plan_id'] : 0);
        $membership_uuid = '';
        if (array_key_exists('membership_uuid', $data)) {
            if (!is_scalar($data['membership_uuid'])) {
                return new WP_Error('wsm_club_membership_uuid_invalid', 'شناسهٔ یکتای عضویت معتبر نیست.', array('status' => 400));
            }
            $membership_uuid = strtolower(trim((string) $data['membership_uuid']));
            if ($membership_uuid !== '' && !self::valid_client_uuid($membership_uuid)) {
                return new WP_Error('wsm_club_membership_uuid_invalid', 'شناسهٔ یکتای عضویت معتبر نیست.', array('status' => 400));
            }
        }
        $replayed_membership = self::membership_uuid_replay($membership_uuid, $member_id, $plan_id, $data);
        if (is_wp_error($replayed_membership)) { return $replayed_membership; }
        if ($replayed_membership !== null) { return $replayed_membership; }
        $member = self::fetch_member($member_id);
        $plan = self::fetch_plan($plan_id);
        if (!$member || $member->status !== 'active') { return new WP_Error('wsm_club_member_missing', 'عضو فعال پیدا نشد.', array('status' => 404)); }
        if (!$plan || !(int) $plan->active) { return new WP_Error('wsm_club_plan_missing', 'طرح فعال پیدا نشد.', array('status' => 404)); }
        $base_price = (float) $plan->price;
        $discount = self::amount(isset($data['discount_amount']) ? $data['discount_amount'] : 0, 'تخفیف عضویت', true);
        if (is_wp_error($discount)) { return $discount; }
        if ((float) $discount > $base_price) { return new WP_Error('wsm_club_discount_invalid', 'تخفیف نمی‌تواند از مبلغ تعرفه بیشتر باشد.', array('status' => 400)); }
        $final_price = wc_format_decimal($base_price - (float) $discount, min(6, max(0, (int) wc_get_price_decimals())));
        $sessions_before = self::id(isset($data['sessions_used_before']) ? $data['sessions_used_before'] : 0);
        if ($sessions_before > 100000 || ((int) $plan->max_visits > 0 && $sessions_before > (int) $plan->max_visits)) { return new WP_Error('wsm_club_sessions_invalid', 'تعداد جلسات استفاده‌شدهٔ قبلی از سهمیهٔ تعرفه بیشتر است.', array('status' => 400)); }
        $discount_note = self::text(isset($data['discount_note']) ? $data['discount_note'] : '', 255);
        $now = self::now();
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('wsm_club_membership_transaction', 'آغاز تراکنش ثبت عضویت انجام نشد.', array('status' => 500));
        }
        $wpdb->last_error = '';
        $locked_member = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('members') . ' WHERE id=%d FOR UPDATE', $member_id));
        if (!empty($wpdb->last_error)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_club_membership_member_lock', 'قفل‌کردن پروندهٔ ورزشکار برای ثبت عضویت انجام نشد.', array('status' => 500));
        }
        if (!$locked_member || $locked_member->status !== 'active') {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_club_member_missing', 'عضو فعال پیدا نشد.', array('status' => 404));
        }
        // Recheck after taking the member lock so two concurrent offline retries
        // with the same UUID return the single committed membership, not an overlap error.
        $replayed_membership = self::membership_uuid_replay($membership_uuid, $member_id, $plan_id, $data);
        if (is_wp_error($replayed_membership)) { $wpdb->query('ROLLBACK'); return $replayed_membership; }
        if ($replayed_membership !== null) { $wpdb->query('ROLLBACK'); return $replayed_membership; }
        $member = $locked_member;
        $requested_start = '';
        if (array_key_exists('start_date', $data) && is_scalar($data['start_date'])) { $requested_start = trim((string) $data['start_date']); }
        elseif (array_key_exists('start_date', $data) && $data['start_date'] !== null) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_club_date_invalid', 'تاریخ شروع عضویت نامعتبر است.', array('status' => 400));
        }
        $start = $requested_start !== '' ? $requested_start : self::next_start_date($member_id);
        if (is_wp_error($start)) { $wpdb->query('ROLLBACK'); return $start; }
        if (!self::valid_date($start)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_club_date_invalid', 'تاریخ شروع عضویت نامعتبر است.', array('status' => 400));
        }
        $start_dt = new DateTimeImmutable($start, wp_timezone());
        $end = $start_dt->modify('+' . max(1, (int) $plan->duration_days - 1) . ' days')->format('Y-m-d');
        $overlap = self::find_membership_overlap($member_id, $start, $end);
        if (is_wp_error($overlap)) { $wpdb->query('ROLLBACK'); return $overlap; }
        if ($overlap) {
            $overlap_end = self::projected_membership_end($overlap);
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_club_membership_overlap', 'بازهٔ این عضویت با عضویت فعال، زمان‌بندی‌شده یا فریز‌شدهٔ دیگری هم‌پوشانی دارد؛ تاریخ شروع را پس از پایان مؤثر آن انتخاب کنید.', array('status' => 409, 'membership_id' => (int) $overlap->id, 'overlap_end_date' => $overlap_end));
        }
        $previous_membership = self::current_membership($member_id);
        $portal_raffle_context_available = true;
        $wpdb->last_error = '';
        $prior_unexpired_membership = $wpdb->get_row($wpdb->prepare(
            "SELECT id, plan_name, start_date, end_date, status FROM " . self::table('memberships') . " WHERE member_id=%d AND status IN ('active','scheduled','frozen') AND end_date >= %s ORDER BY end_date DESC,id DESC LIMIT 1",
            (int)$member_id, self::today()
        ));
        if (!empty($wpdb->last_error)) { $portal_raffle_context_available = false; $prior_unexpired_membership = null; }
        $wpdb->last_error = '';
        $prior_any_membership_count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table('memberships') . " WHERE member_id=%d AND status <> 'cancelled'",
            (int)$member_id
        ));
        if (!empty($wpdb->last_error)) { $portal_raffle_context_available = false; $prior_any_membership_count = 0; }
        $prior_end_date = '';
        if ($portal_raffle_context_available) {
            if ($prior_unexpired_membership && !empty($prior_unexpired_membership->end_date)) { $prior_end_date = (string)$prior_unexpired_membership->end_date; }
            elseif ($prior_any_membership_count > 0) {
                $wpdb->last_error = '';
                $prior_end_date = (string)$wpdb->get_var($wpdb->prepare("SELECT end_date FROM " . self::table('memberships') . " WHERE member_id=%d AND status<>'cancelled' ORDER BY end_date DESC,id DESC LIMIT 1",(int)$member_id));
                if (!empty($wpdb->last_error)) { $portal_raffle_context_available = false; }
            }
        }
        $status = $start > self::today() ? 'scheduled' : 'active';
        $inserted = $wpdb->insert(self::table('memberships'), array(
            'client_uuid' => $membership_uuid !== '' ? $membership_uuid : null,
            'member_id' => $member_id,
            'plan_id' => $plan_id,
            'plan_name' => $plan->name,
            'start_date' => $start,
            'end_date' => $end,
            'price' => $final_price,
            'discount_amount' => $discount,
            'discount_note' => $discount_note,
            'max_visits' => (int) $plan->max_visits,
            'sessions_used_before' => $sessions_before,
            'status' => $status,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ));
        if ($inserted === false || (int) $wpdb->insert_id < 1) {
            $wpdb->query('ROLLBACK');
            $replayed_membership = self::membership_uuid_replay($membership_uuid, $member_id, $plan_id, $data);
            if (is_wp_error($replayed_membership)) { return $replayed_membership; }
            if ($replayed_membership !== null) { return $replayed_membership; }
            return new WP_Error('wsm_club_save_failed', 'ثبت عضویت انجام نشد.', array('status' => 500));
        }
        $id = (int) $wpdb->insert_id;
        if ((float) $discount > 0) {
            $discount_ledger=WSM_Club_Operations::add_finance('discount', $discount, 'تخفیف عضویت ' . $member->member_code, array('member_id' => $member_id, 'membership_id' => $id, 'description' => $discount_note));
            if (is_wp_error($discount_ledger)) { $wpdb->query('ROLLBACK'); return $discount_ledger; }
        }
        $wpdb->query('COMMIT');
        if ($portal_raffle_context_available && class_exists('WSM_Club_Operations') && method_exists('WSM_Club_Operations', 'evaluate_raffle_on_membership_created')) {
            WSM_Club_Operations::evaluate_raffle_on_membership_created(array(
                'member_id' => (int)$member_id,
                'membership_id' => (int)$id,
                'plan_id' => (int)$plan_id,
                'plan_name' => (string)$plan->name,
                'start_date' => (string)$start,
                'end_date' => (string)$end,
                'prior_unexpired_membership' => $prior_unexpired_membership,
                'prior_any_membership_count' => (int)$prior_any_membership_count,
                'had_unexpired_override' => isset($data['_had_unexpired_prior']) ? (bool)$data['_had_unexpired_prior'] : null,
                'prior_end_date_override' => isset($data['_prior_end_date']) ? (string)$data['_prior_end_date'] : $prior_end_date,
                'source' => !empty($data['_portal_renewal']) ? 'athlete_portal' : 'staff_panel',
            ));
        }
        if (class_exists('WSM_Club_Operations') && method_exists('WSM_Club_Operations', 'refresh_receivables_projection')) {
            $projection = WSM_Club_Operations::refresh_receivables_projection();
            if (is_wp_error($projection)) { error_log('[WSM Club] Membership was saved, but the receivables projection refresh was deferred.'); }
        }
        WSM_Activity_Log::log('create', 'club_membership', $id, $member->member_code . ' / ' . $plan->name, '', array('start_date' => $start, 'end_date' => $end, 'price' => $final_price, 'discount_amount' => $discount, 'sessions_used_before' => $sessions_before), 'success');
        if ($status === 'active' && class_exists('WSM_Club_Operations')) {
            $level_changed = $previous_membership && (string)$previous_membership->plan_name !== (string)$plan->name;
            $event_key = $level_changed ? 'membership_level_change' : 'membership_activation';
            WSM_Club_Operations::trigger_sms_event($event_key, $member, array(
                'plan_name' => (string)$plan->name,
                'old_plan_name' => $level_changed ? (string)$previous_membership->plan_name : '',
                'start_date' => $start,
                'end_date' => $end,
                'membership_id' => $id,
            ));
        }
        $membership_list = self::list_memberships(array('member_id' => $member_id, 'per_page' => 100));
        if (is_wp_error($membership_list)) {
            return new WP_Error('wsm_club_save_result_lookup_failed', 'عضویت ثبت شد اما خواندن نتیجه ناموفق بود؛ درخواست را با همان شناسهٔ عضویت دوباره همگام‌سازی کنید.', array('status' => 500, 'cause' => $membership_list->get_error_code()));
        }
        foreach ($membership_list['items'] as $item) { if ((int) $item['id'] === $id) { return $item; } }
        return new WP_Error('wsm_club_save_failed', 'عضویت ثبت شد اما خواندن نتیجه ناموفق بود.', array('status' => 500));
    }

    /** Create a membership and its optional receipt as one idempotent offline-sync operation. */
    public static function create_membership_from_sync($data) {
        global $wpdb;
        if (!is_array($data)) { return new WP_Error('wsm_sync_membership_invalid', 'اطلاعات عضویت آفلاین معتبر نیست.', array('status' => 400)); }
        $member_uuid = isset($data['member_uuid']) && is_scalar($data['member_uuid']) ? strtolower(trim((string) $data['member_uuid'])) : '';
        $membership_uuid = isset($data['membership_uuid']) && is_scalar($data['membership_uuid']) ? strtolower(trim((string) $data['membership_uuid'])) : '';
        if (!self::valid_client_uuid($member_uuid)) {
            return new WP_Error('wsm_sync_membership_member_uuid_invalid', 'شناسهٔ عضو آفلاین برای ثبت تعرفه معتبر نیست.', array('status' => 400));
        }
        if (!self::valid_client_uuid($membership_uuid)) {
            return new WP_Error('wsm_sync_membership_uuid_invalid', 'شناسهٔ یکتای عضویت آفلاین معتبر نیست.', array('status' => 400));
        }
        if (!class_exists('WSM_Club_Operations') || !method_exists('WSM_Club_Operations', 'find_sync_member_id')) {
            return new WP_Error('wsm_sync_membership_map_unavailable', 'پیوند عضو آفلاین برای ثبت عضویت در دسترس نیست.', array('status' => 500));
        }
        $mapped_member_id = WSM_Club_Operations::find_sync_member_id($member_uuid);
        if (is_wp_error($mapped_member_id)) { return $mapped_member_id; }
        $mapped_member_id = (int) $mapped_member_id;
        $payload_member_id = self::id(isset($data['member_id']) ? $data['member_id'] : 0);
        // Cached remote members have a local UUID too, but no offline-map alias.
        // Accept their positive server id only when no UUID mapping exists; a
        // mapped local-only UUID remains authoritative and conflicting ids fail closed.
        if ($mapped_member_id > 0 && $payload_member_id > 0 && $payload_member_id !== $mapped_member_id) {
            return new WP_Error('wsm_sync_membership_member_conflict', 'شناسهٔ سروری عضو با پیوند UUID آن یکسان نیست.', array('status' => 409));
        }
        $member_id = $mapped_member_id > 0 ? $mapped_member_id : $payload_member_id;
        if ($member_id < 1) {
            return new WP_Error('wsm_sync_membership_member_unlinked', 'عضو هنوز روی سرور پیوند نشده است؛ ابتدا همگام‌سازی ثبت ورزشکار باید موفق شود.', array('status' => 409));
        }
        $data['member_id'] = $member_id;
        $data['membership_uuid'] = $membership_uuid;

        $received_raw = isset($data['received_amount']) && is_scalar($data['received_amount']) ? trim((string) $data['received_amount']) : '0';
        if ($received_raw === '') { $received_raw = '0'; }
        $received = self::amount($received_raw, 'مبلغ دریافتی', true);
        if (is_wp_error($received)) { return $received; }
        $payment_method = isset($data['payment_method']) && is_scalar($data['payment_method']) ? sanitize_key((string) $data['payment_method']) : 'debt';
        $payment_uuid = isset($data['payment_uuid']) && is_scalar($data['payment_uuid']) ? strtolower(trim((string) $data['payment_uuid'])) : '';
        if ((float) $received > 0) {
            if (!in_array($payment_method, array('cash', 'card', 'bank_transfer', 'online', 'other'), true)) {
                return new WP_Error('wsm_sync_membership_payment_method_invalid', 'روش دریافت عضویت معتبر نیست.', array('status' => 400));
            }
            if (!self::valid_client_uuid($payment_uuid)) {
                return new WP_Error('wsm_sync_membership_payment_uuid_invalid', 'شناسهٔ یکتای دریافت آفلاین معتبر نیست.', array('status' => 400));
            }
        } elseif ($payment_method !== 'debt') {
            return new WP_Error('wsm_sync_membership_payment_required', 'برای روش نقدی/کارتی/انتقالی باید مبلغ مثبت ثبت شود یا روش نسیه انتخاب شود.', array('status' => 400));
        }
        if ((float)$received > 0) {
            $existing_membership = self::membership_row_by_client_uuid($membership_uuid);
            if (is_wp_error($existing_membership)) { return $existing_membership; }
            // A retry after membership creation must not depend on the plan still being active or unchanged.
            // create_membership() below validates the UUID against the original member, plan, dates and discount.
            if (!$existing_membership) {
                $sync_plan = self::fetch_plan(self::id(isset($data['plan_id']) ? $data['plan_id'] : 0));
                if (!$sync_plan || !(int) $sync_plan->active) { return new WP_Error('wsm_sync_membership_plan_invalid', 'تعرفهٔ فعال برای ثبت دریافت عضویت پیدا نشد.', array('status' => 404)); }
                $sync_discount = self::amount(isset($data['discount_amount']) ? $data['discount_amount'] : 0, 'تخفیف عضویت', true);
                if (is_wp_error($sync_discount)) { return $sync_discount; }
                $sync_net_price = max(0.0, (float) $sync_plan->price - (float) $sync_discount);
                if ((float) $received > $sync_net_price + 0.000001) { return new WP_Error('wsm_sync_membership_overpay', 'مبلغ دریافتی نمی‌تواند از مبلغ نهایی تعرفه بیشتر باشد.', array('status' => 409, 'maximum_receivable' => $sync_net_price)); }
            }
        }

        $membership = self::create_membership($data);
        if (is_wp_error($membership)) { return $membership; }
        $membership_id = isset($membership['id']) ? (int) $membership['id'] : 0;
        if ($membership_id < 1) {
            return new WP_Error('wsm_sync_membership_result_invalid', 'شناسهٔ عضویت ثبت‌شده معتبر نیست.', array('status' => 500));
        }
        $payment = null;
        if ((float) $received > 0) {
            $payment_note = isset($data['payment_note']) && is_scalar($data['payment_note']) ? trim((string) $data['payment_note']) : '';
            if ($payment_method === 'other' && strpos($payment_note, 'روش انتخابی: اعتباری') === false) {
                $payment_note = trim('روش انتخابی: اعتباری' . ($payment_note !== '' ? ' | ' . $payment_note : ''));
            }
            $payment = self::record_payment(array(
                'member_id' => $member_id,
                'membership_id' => $membership_id,
                'payment_uuid' => $payment_uuid,
                'amount' => $received,
                'method' => $payment_method,
                'gateway_id' => isset($data['gateway_id']) ? $data['gateway_id'] : '',
                'account_id' => isset($data['account_id']) ? $data['account_id'] : 0,
                'reference' => isset($data['payment_reference']) ? $data['payment_reference'] : '',
                'note' => $payment_note,
            ));
            if (is_wp_error($payment)) { return $payment; }
        }
        $result = array(
            'id' => $membership_id,
            'membership_id' => $membership_id,
            'membership_uuid' => $membership_uuid,
            'member_id' => $member_id,
            'member_uuid' => $member_uuid,
            'membership' => $membership,
            'payment_id' => is_array($payment) && isset($payment['id']) ? (int) $payment['id'] : 0,
            'payment' => $payment,
        );
        $wpdb->last_error = '';
        $result['member_balance_due'] = self::balance_due($member_id);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_sync_membership_balance_failed','عضویت/دریافت پردازش شد اما بازخوانی ماندهٔ بدهی انجام نشد؛ همگام‌سازی را دوباره تلاش کنید.',array('status'=>500)); }
        if (!class_exists('WSM_Club_Operations') || !method_exists('WSM_Club_Operations', 'pos_debt_by_member')) {
            return new WP_Error('wsm_sync_membership_pos_debt_unavailable','عضویت/دریافت پردازش شد اما محاسبهٔ بدهی خرید در دسترس نیست؛ همگام‌سازی را دوباره تلاش کنید.',array('status'=>500));
        }
        $pos_debts = WSM_Club_Operations::pos_debt_by_member(array($member_id));
        if (is_wp_error($pos_debts)) { return new WP_Error('wsm_sync_membership_pos_debt_failed','عضویت/دریافت پردازش شد اما بازخوانی بدهی خرید انجام نشد؛ همگام‌سازی را دوباره تلاش کنید.',array('status'=>500,'cause'=>$pos_debts->get_error_code())); }
        $result['member_pos_debt_due'] = isset($pos_debts[$member_id]) ? (float) $pos_debts[$member_id] : 0.0;
        $result['member_total_debt_due'] = (float) $result['member_balance_due'] + (float) $result['member_pos_debt_due'];
        return $result;
    }

    public static function record_payment($data) {
        global $wpdb;
        if (!is_array($data)) { return new WP_Error('wsm_club_payment_invalid', 'اطلاعات پرداخت نامعتبر است.', array('status' => 400)); }
        $member_id = self::id(isset($data['member_id']) ? $data['member_id'] : 0);
        $membership_id = self::id(isset($data['membership_id']) ? $data['membership_id'] : 0);
        $member = self::fetch_member($member_id);
        if (!$member || !$membership_id) { return new WP_Error('wsm_club_payment_reference_invalid', 'عضو و عضویت مربوط به پرداخت را انتخاب کنید.', array('status' => 400)); }
        $amount = self::amount(isset($data['amount']) ? $data['amount'] : null, 'مبلغ پرداخت', false);
        if (is_wp_error($amount)) { return $amount; }
        if (array_key_exists('method', $data)) {
            if (!is_string($data['method']) || !in_array($data['method'], array('cash','card','bank_transfer','online','other'), true)) {
                return new WP_Error('wsm_club_payment_method_invalid', 'روش دریافت شهریه معتبر نیست.', array('status' => 400));
            }
            $method = $data['method'];
        } else {
            $method = 'cash';
        }
        $gateway_id = isset($data['gateway_id']) && is_scalar($data['gateway_id']) ? sanitize_key((string)$data['gateway_id']) : '';
        $requested_account_id = isset($data['account_id']) ? self::id($data['account_id']) : 0;
        $reference = self::text(isset($data['reference']) ? $data['reference'] : '', 120);
        $note = self::text(isset($data['note']) ? $data['note'] : '', 255);
        $payment_uuid = '';
        if (array_key_exists('payment_uuid', $data)) {
            if (!is_scalar($data['payment_uuid'])) {
                return new WP_Error('wsm_club_payment_uuid_invalid', 'شناسهٔ یکتای دریافت معتبر نیست.', array('status' => 400));
            }
            $payment_uuid = strtolower(trim((string) $data['payment_uuid']));
            if ($payment_uuid !== '' && !self::valid_client_uuid($payment_uuid)) {
                return new WP_Error('wsm_club_payment_uuid_invalid', 'شناسهٔ یکتای دریافت معتبر نیست.', array('status' => 400));
            }
        }
        if ($payment_uuid !== '') {
            $wpdb->last_error = '';
            $existing_payment = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('payments') . ' WHERE client_uuid = %s LIMIT 1', $payment_uuid));
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_club_payment_lookup_failed', 'بررسی دریافت قبلی انجام نشد.', array('status' => 500));
            }
            if ($existing_payment) {
                if ((string) $existing_payment->status !== 'paid') {
                    return new WP_Error('wsm_club_payment_uuid_void', 'این شناسهٔ یکتا به دریافت باطل‌شده تعلق دارد؛ برای ثبت دریافت تازه شناسهٔ تازه بسازید.', array('status' => 409));
                }
                if ((int) $existing_payment->member_id !== $member_id || (int) $existing_payment->membership_id !== $membership_id ||
                    abs((float) $existing_payment->amount - (float) $amount) > 0.000001 || (string) $existing_payment->method !== (string) $method) {
                    return new WP_Error('wsm_club_payment_uuid_conflict', 'این شناسهٔ دریافت قبلاً برای تراکنش دیگری استفاده شده است.', array('status' => 409));
                }
                $wpdb->last_error = '';
                $existing_balance_due = self::membership_balance($membership_id);
                if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_payment_balance_lookup_failed','دریافت قبلی شناسایی شد اما ماندهٔ عضویت خوانده نشد؛ همگام‌سازی باید دوباره تلاش شود.',array('status'=>500)); }
                return array(
                    'id' => (int) $existing_payment->id,
                    'payment_uuid' => $payment_uuid,
                    'member_id' => $member_id,
                    'membership_id' => $membership_id,
                    'amount' => (string) $existing_payment->amount,
                    'method' => (string) $existing_payment->method,
                    'reference' => (string) $existing_payment->reference,
                    'paid_at' => (string) $existing_payment->paid_at,
                    'balance_due' => $existing_balance_due,
                    'idempotent_replay' => true,
                );
            }
        }
        if (!class_exists('WSM_Club_Operations') || !method_exists('WSM_Club_Operations', 'membership_payment_finance_context')) {
            return new WP_Error('wsm_club_payment_finance_unavailable', 'نگاشت حساب مالی برای دریافت شهریه در دسترس نیست.', array('status' => 503));
        }
        $finance_context = WSM_Club_Operations::membership_payment_finance_context($method, $gateway_id, $requested_account_id);
        if (is_wp_error($finance_context)) { return $finance_context; }
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('wsm_club_payment_transaction', 'آغاز تراکنش دریافت شهریه انجام نشد.', array('status' => 500));
        }
        $membership = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('memberships') . ' WHERE id = %d AND member_id = %d FOR UPDATE', $membership_id, $member_id));
        if (!$membership) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_payment_reference_invalid', 'عضویت مربوط به پرداخت پیدا نشد.', array('status' => 404)); }
        if ($membership->status==='cancelled') { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_payment_cancelled','برای عضویت لغوشده نمی‌توان دریافت تازه ثبت کرد.',array('status'=>409)); }
        if (in_array($method,array('cash','card'),true)) {
            $locked_session=WSM_Club_Operations::lock_open_cash_session((int)$finance_context['cash_session_id'],(int)$finance_context['cash_account_id'],(int)$finance_context['device_id']);
            if (is_wp_error($locked_session)) { $wpdb->query('ROLLBACK'); return $locked_session; }
        }
        $wpdb->last_error = '';
        $balance = self::membership_balance($membership_id);
        if (!empty($wpdb->last_error)) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_payment_balance_lookup_failed','محاسبهٔ ماندهٔ عضویت برای ثبت دریافت انجام نشد.',array('status'=>500)); }
        if ((float) $amount > $balance + 0.000001) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_club_payment_overpay', 'مبلغ از ماندهٔ این عضویت بیشتر است.', array('status' => 409, 'balance_due' => $balance));
        }
        $now = self::now();
        if ($wpdb->insert(self::table('payments'), array(
            'client_uuid' => $payment_uuid !== '' ? $payment_uuid : null,
            'member_id' => $member_id,
            'membership_id' => $membership_id,
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
            'status' => 'paid',
            'note' => $note,
            'paid_at' => $now,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
        )) === false) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_save_failed', 'ثبت دریافت انجام نشد.', array('status' => 500)); }
        $id = (int) $wpdb->insert_id;
        $ledger=WSM_Club_Operations::add_finance('member_payment', $amount, 'دریافت شهریه ' . $member->member_code, array('member_id' => $member_id, 'membership_id' => $membership_id, 'payment_id' => $id, 'account_id' => (int)$finance_context['account_id'], 'cash_session_id' => (int)$finance_context['cash_session_id'], 'method' => $method, 'reference' => $reference, 'occurred_at' => $now, 'description' => $note));
        if (is_wp_error($ledger)) { $wpdb->query('ROLLBACK'); return $ledger; }
        $wpdb->query('COMMIT');
        if (class_exists('WSM_Club_Operations') && method_exists('WSM_Club_Operations', 'refresh_receivables_projection')) {
            $projection = WSM_Club_Operations::refresh_receivables_projection();
            if (is_wp_error($projection)) { error_log('[WSM Club] Payment was saved, but the receivables projection refresh was deferred.'); }
        }
        WSM_Activity_Log::log('sale', 'club_payment', $id, $member->member_code, '', array('amount' => $amount, 'method' => $method), 'success');
        $wpdb->last_error = '';
        $balance_due = self::membership_balance($membership_id);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_payment_balance_lookup_failed','دریافت ثبت شد اما خواندن ماندهٔ پس از دریافت ناموفق بود؛ UUID پرداخت را برای تلاش تکراری نگه دارید.',array('status'=>500)); }
        if (class_exists('WSM_Club_Operations')) {
            WSM_Club_Operations::trigger_sms_event('payment_receipt', $member, array(
                'amount' => (string)$amount,
                'balance' => (string)$balance_due,
                'method' => (string)$method,
                'reference' => (string)$reference,
                'paid_at' => (string)$now,
                'plan_name' => (string)$membership->plan_name,
                'payment_id' => $id,
            ));
        }
        return array('id' => $id, 'payment_uuid' => $payment_uuid, 'member_id' => $member_id, 'membership_id' => $membership_id, 'amount' => (string) $amount, 'method' => $method, 'account_id' => (int)$finance_context['account_id'], 'gateway_id' => (string)$finance_context['gateway_id'], 'cash_session_id' => (int)$finance_context['cash_session_id'], 'reference' => $reference, 'paid_at' => $now, 'balance_due' => $balance_due);
    }

    public static function list_payments($args = array()) {
        global $wpdb;
        $limit = max(1, min(100, (int) (isset($args['per_page']) ? $args['per_page'] : 50)));
        $member_id = isset($args['member_id']) ? self::id($args['member_id']) : 0;
        $where = array('p.status = %s');
        $params = array('paid');
        if ($member_id) { $where[] = 'p.member_id = %d'; $params[] = $member_id; }
        $sql = 'SELECT p.*, u.member_code, CONCAT(u.first_name," ",u.last_name) AS member_name, m.plan_name
                FROM ' . self::table('payments') . ' p
                LEFT JOIN ' . self::table('members') . ' u ON u.id=p.member_id
                LEFT JOIN ' . self::table('memberships') . ' m ON m.id=p.membership_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY p.paid_at DESC,p.id DESC LIMIT %d';
        $params[] = $limit;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_payment_lookup_failed', 'خواندن دریافت‌های باشگاه انجام نشد.', array('status' => 500)); }
        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array('id' => (int) $row->id, 'member_id' => (int) $row->member_id, 'member_code' => (string) $row->member_code, 'member_name' => trim((string) $row->member_name), 'membership_id' => (int) $row->membership_id, 'plan_name' => (string) $row->plan_name, 'amount' => (string) $row->amount, 'method' => (string) $row->method, 'reference' => (string) $row->reference, 'note' => (string) $row->note, 'paid_at' => (string) $row->paid_at);
        }
        return array('items' => $items);
    }

    private static function to_site_datetime($value) {
        if (is_numeric($value)) {
            $stamp = (int) $value;
            if ($stamp < 1 || $stamp > time() + DAY_IN_SECONDS) { return false; }
            return wp_date('Y-m-d H:i:s', $stamp, wp_timezone());
        }
        if (!is_scalar($value) || trim((string) $value) === '') { return false; }
        try {
            $date = new DateTimeImmutable((string) $value, wp_timezone());
        } catch (Exception $e) {
            return false;
        }
        if ($date->getTimestamp() > time() + DAY_IN_SECONDS) { return false; }
        return $date->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
    }

    private static function attendance_event_type($member, $direction, $decision, $occurred_at) {
        if (!$member) { return 'unknown'; }
        if ($direction === 'out') { return 'exit'; }
        if ($direction !== 'in' || $decision !== 'allowed' || !class_exists('WSM_Club_Operations')) { return 'entry'; }
        $settings = WSM_Club_Operations::settings();
        $start = isset($settings['attendance_start_time']) ? trim((string) $settings['attendance_start_time']) : '';
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)) { return 'entry'; }
        try {
            $stamp = new DateTimeImmutable((string) $occurred_at, wp_timezone());
        } catch (Exception $e) {
            return 'entry';
        }
        $parts = array_map('intval', explode(':', $start));
        $grace = isset($settings['late_grace_minutes']) ? max(0, min(240, (int) $settings['late_grace_minutes'])) : 0;
        $threshold = $parts[0] * 3600 + $parts[1] * 60 + $grace * 60;
        $actual = ((int) $stamp->format('H')) * 3600 + ((int) $stamp->format('i')) * 60 + ((int) $stamp->format('s'));
        return $actual > $threshold ? 'late' : 'entry';
    }

    private static function create_attendance($member, $scan_value, $direction, $source, $source_device_id, $event_id, $occurred_at) {
        global $wpdb;
        if (!is_scalar($direction)) {
            return new WP_Error('wsm_club_direction_invalid', 'جهت تردد باید in یا out باشد.', array('status' => 400));
        }
        $direction = strtolower(trim((string) $direction));
        if (!in_array($direction, array('in', 'out'), true)) {
            return new WP_Error('wsm_club_direction_invalid', 'جهت تردد باید in یا out باشد.', array('status' => 400));
        }
        $source = in_array($source, array('manual','bridge'), true) ? $source : 'manual';
        $membership = null;
        $decision = 'denied';
        $reason = 'عضو شناسایی نشد.';
        if ($direction === 'out') {
            // Egress is never blocked, including when the scanned user is unknown.
            $decision = 'allowed';
            $reason = 'خروج همیشه مجاز است.';
            if ($member) {
                $membership = self::current_membership((int) $member->id);
            }
        } elseif ($member) {
            $pos_debts = class_exists('WSM_Club_Operations') && method_exists('WSM_Club_Operations','pos_debt_by_member')
                ? WSM_Club_Operations::pos_debt_by_member(array((int) $member->id))
                : new WP_Error('wsm_club_pos_debt_unavailable','محاسبهٔ بدهی فروش حضوری در دسترس نیست.');
            if (is_wp_error($pos_debts)) {
                $decision = 'denied';
                $reason = 'بررسی بدهی خرید انجام نشد؛ ورود برای حفظ کنترل مالی موقتاً مسدود شد.';
                $membership = self::current_membership((int) $member->id);
            } else {
                $pos_debt_due = isset($pos_debts[(int) $member->id]) ? (float) $pos_debts[(int) $member->id] : 0.0;
                $eligibility = self::eligibility($member, $pos_debt_due);
                if (is_wp_error($eligibility)) {
                    $decision = 'denied';
                    $reason = 'محاسبهٔ بدهی یا اعتبار عضویت انجام نشد؛ ورود برای حفظ کنترل مالی موقتاً مسدود شد.';
                    $membership = null;
                } else {
                    $decision = $eligibility['eligible'] ? 'allowed' : 'denied';
                    $reason = $eligibility['reason'];
                    $membership = $eligibility['membership'];
                }
            }
        }
        $code = $member ? (string) $member->member_code : '';
        $uid = $member ? (string) $member->device_user_id : ($source === 'bridge' ? (string) $scan_value : '');
        $event_type = self::attendance_event_type($member, $direction, $decision, $occurred_at);
        $event_id = preg_match('/^[a-f0-9]{64}$/', (string) $event_id) ? strtolower((string) $event_id) : hash('sha256', $source_device_id . '|' . $scan_value . '|' . $occurred_at . '|' . wp_generate_uuid4());
        $data = array(
            'member_id' => $member ? (int) $member->id : 0,
            'membership_id' => $membership ? (int) $membership->id : 0,
            'source_device_id' => (int) $source_device_id,
            'event_id' => $event_id,
            'member_code' => $code,
            'device_user_id' => $uid,
            'scan_value' => self::text($scan_value, 80),
            'direction' => $direction,
            'event_type' => $event_type,
            'source' => $source,
            'decision' => $decision,
            'reason' => self::text($reason, 190),
            'occurred_at' => $occurred_at,
            'created_at' => self::now(),
        );
        $existing = $wpdb->get_row($wpdb->prepare(
            'SELECT a.id,a.event_id,a.event_type,a.decision,a.reason,a.member_code,a.member_id,a.device_user_id,a.direction,a.source,a.occurred_at,u.first_name,u.last_name,u.card_number
             FROM ' . self::table('attendance') . ' a LEFT JOIN ' . self::table('members') . ' u ON u.id=a.member_id
             WHERE a.source_device_id = %d AND a.event_id = %s',
            (int) $source_device_id, $event_id
        ));
        if ($existing) {
            $existing_member = (int) $existing->member_id > 0;
            return array(
                'id' => (int) $existing->id,
                'event_id' => (string) $existing->event_id,
                'accepted' => true,
                'duplicate' => true,
                'member_id' => (int) $existing->member_id,
                'device_user_id' => (string) $existing->device_user_id,
                'direction' => (string) $existing->direction,
                'source' => (string) $existing->source,
                'decision' => (string) $existing->decision,
                'event_type' => (string) $existing->event_type,
                'reason' => (string) $existing->reason,
                'member_code' => (string) $existing->member_code,
                'member_name' => $existing_member ? trim((string) $existing->first_name . ' ' . (string) $existing->last_name) : 'ناشناس',
                'card_number' => $existing_member ? (string) $existing->card_number : '',
                'occurred_at' => (string) $existing->occurred_at,
            );
        }
        if ($wpdb->insert(self::table('attendance'), $data) === false) {
            return new WP_Error('wsm_club_attendance_save_failed', 'ثبت رویداد تردد انجام نشد.', array('status' => 500));
        }
        $id = (int) $wpdb->insert_id;
        if ($member) {
            WSM_Activity_Log::log('create', 'club_attendance', $id, $code, '', array('direction' => $direction, 'decision' => $decision, 'source' => $source), 'success');
            if (class_exists('WSM_Club_Operations')) {
                $attendance_label = $direction === 'out' ? 'خروج' : ($decision === 'allowed' ? 'حضور' : 'حضور ردشده');
                WSM_Club_Operations::trigger_sms_event('attendance_update', $member, array(
                    'attendance' => $attendance_label,
                    'direction' => $direction,
                    'decision' => $decision,
                    'event_type' => $event_type,
                    'reason' => $reason,
                    'occurred_at' => $occurred_at,
                    'class_name' => 'باشگاه',
                    'attendance_id' => $id,
                ));
                if ($direction === 'in' && $decision === 'allowed' && $membership && (int)$membership->max_visits > 0) {
                    $used = (int)$wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM ".self::table('attendance')." WHERE membership_id=%d AND direction='in' AND decision='allowed'",
                        (int)$membership->id
                    ));
                    $remaining = max(0, (int)$membership->max_visits - (int)$membership->sessions_used_before - $used);
                    if ($remaining === 1) {
                        WSM_Club_Operations::trigger_sms_event('last_session', $member, array(
                            'plan_name' => (string)$membership->plan_name,
                            'session_number' => (int)$membership->sessions_used_before + $used,
                            'total_sessions' => (int)$membership->max_visits,
                            'remaining_sessions' => $remaining,
                            'membership_id' => (int)$membership->id,
                        ));
                    }
                }
            }
        }
        return array(
            'id' => $id,
            'event_id' => $event_id,
            'accepted' => true,
            'duplicate' => false,
            'member_id' => $member ? (int) $member->id : 0,
            'device_user_id' => $uid,
            'direction' => $direction,
            'source' => $source,
            'decision' => $decision,
            'event_type' => $event_type,
            'reason' => $reason,
            'member_code' => $code,
            'member_name' => $member ? trim($member->first_name . ' ' . $member->last_name) : 'ناشناس',
            'card_number' => $member ? (string) $member->card_number : '',
            'occurred_at' => $occurred_at,
        );
    }

    private static function check_attendance_direction_permission($direction) {
        if (!class_exists('WSM_Permissions') || !class_exists('WSM_Devices')) { return true; }
        $device = WSM_Permissions::current_device();
        if (!$device) { return true; }
        $direction_permission = $direction === 'out' ? 'club.attendance.checkout' : 'club.attendance.checkin';
        if (WSM_Devices::device_can($device, 'club.attendance.manage') || WSM_Devices::device_can($device, $direction_permission)) {
            return true;
        }
        return new WP_Error('wsm_device_permission_denied', 'این دستگاه مجوز ثبت این جهت تردد را ندارد.', array(
            'status' => 403,
            'permission' => array('club.attendance.manage', $direction_permission),
        ));
    }

    public static function checkin($data) {
        if (!is_array($data)) { return new WP_Error('wsm_club_attendance_invalid', 'اطلاعات تردد نامعتبر است.', array('status' => 400)); }
        if (array_key_exists('direction', $data)) {
            if (!is_scalar($data['direction'])) { return new WP_Error('wsm_club_direction_invalid', 'جهت تردد باید in یا out باشد.', array('status' => 400)); }
            $direction = strtolower(trim((string) $data['direction']));
            if ($direction === '') { $direction = 'in'; }
        } else {
            $direction = 'in';
        }
        if (!in_array($direction, array('in', 'out'), true)) {
            return new WP_Error('wsm_club_direction_invalid', 'جهت تردد باید in یا out باشد.', array('status' => 400));
        }
        $permission = self::check_attendance_direction_permission($direction);
        if (is_wp_error($permission)) { return $permission; }
        $scan = self::text(isset($data['identifier']) ? $data['identifier'] : (isset($data['member_code']) ? $data['member_code'] : ''), 80);
        if ($scan === '') { return new WP_Error('wsm_club_scan_required', 'کد عضو، بارکد یا شمارهٔ تماس را اسکن/وارد کنید.', array('status' => 400)); }
        $member = self::find_member_by_identifier($scan);
        return self::create_attendance($member, $scan, $direction, 'manual', WSM_Permissions::current_device_id(), '', self::now());
    }

    private static function find_member_by_device_user_id($device_user_id) {
        global $wpdb;
        $device_user_id = self::text($device_user_id, 32);
        if ($device_user_id === '') { return null; }
        $normalized = self::normalize_device_user_id($device_user_id);
        if ($normalized !== false && $normalized !== '') {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . self::table('members') . ' WHERE (device_user_id = %s OR (device_user_id REGEXP %s AND CAST(device_user_id AS UNSIGNED) = CAST(%s AS UNSIGNED))) ORDER BY id ASC LIMIT 1',
                array($normalized, '^[0-9]+$', $normalized)
            ));
        }
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table('members') . ' WHERE device_user_id = %s ORDER BY id ASC LIMIT 1',
            $device_user_id
        ));
    }

    private static function find_member_by_identifier($identifier) {
        global $wpdb;
        $identifier = self::text($identifier, 80);
        if ($identifier === '') { return null; }
        $digits = preg_replace('/\\D/u', '', self::phone($identifier));
        $normalized_uid = self::normalize_device_user_id($identifier);
        if ($normalized_uid !== false && $normalized_uid !== '') {
            $uid_condition = '(device_user_id = %s OR (device_user_id REGEXP %s AND CAST(device_user_id AS UNSIGNED) = CAST(%s AS UNSIGNED)))';
            $uid_params = array($normalized_uid, '^[0-9]+$', $normalized_uid);
        } else {
            $uid_condition = 'device_user_id = %s';
            $uid_params = array($identifier);
        }
        $query = 'SELECT * FROM ' . self::table('members') . ' WHERE member_code = %s OR ' . $uid_condition . ' OR card_number = %s';
        $params = array_merge(array($identifier), $uid_params, array($identifier));
        if ($digits !== '') {
            $query .= " OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')',''),'.','') = %s";
            $params[] = $digits;
        }
        $query .= ' ORDER BY CASE WHEN member_code = %s THEN 0 WHEN ' . $uid_condition . ' THEN 1 WHEN card_number = %s THEN 2 ELSE 3 END ASC, id ASC LIMIT 1';
        $params = array_merge($params, array($identifier), $uid_params, array($identifier));
        return $wpdb->get_row($wpdb->prepare($query, $params));
    }

    public static function list_attendance($args = array()) {
        global $wpdb;
        $page = max(1, min(100000, (int) (isset($args['page']) ? $args['page'] : 1)));
        $limit = max(1, min(100, (int) (isset($args['per_page']) ? $args['per_page'] : 50)));
        $offset = ($page - 1) * $limit;
        $member_id = isset($args['member_id']) ? self::id($args['member_id']) : 0;
        $where = array('1=1');
        $params = array();
        if ($member_id) { $where[] = 'a.member_id = %d'; $params[] = $member_id; }
        $source_device_id = isset($args['source_device_id']) ? self::id($args['source_device_id']) : 0;
        if ($source_device_id) { $where[] = 'a.source_device_id = %d'; $params[] = $source_device_id; }
        if (isset($args['decision']) && in_array($args['decision'], array('allowed','denied'), true)) { $where[] = 'a.decision = %s'; $params[] = $args['decision']; }
        if (isset($args['event_type']) && in_array($args['event_type'], array('entry','exit','late','unknown'), true)) { $where[] = 'a.event_type = %s'; $params[] = $args['event_type']; }
        if (isset($args['direction']) && in_array($args['direction'], array('in','out'), true)) { $where[] = 'a.direction = %s'; $params[] = $args['direction']; }
        if (isset($args['source']) && in_array($args['source'], array('bridge','manual'), true)) { $where[] = 'a.source = %s'; $params[] = $args['source']; }
        if (isset($args['date_from']) && self::valid_date($args['date_from'])) { $where[] = 'a.occurred_at >= %s'; $params[] = $args['date_from'] . ' 00:00:00'; }
        if (isset($args['date_to']) && self::valid_date($args['date_to'])) { $where[] = 'a.occurred_at <= %s'; $params[] = $args['date_to'] . ' 23:59:59'; }
        $query = self::text(isset($args['q']) ? $args['q'] : '', 80);
        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $where[] = '(a.member_code LIKE %s OR a.device_user_id LIKE %s OR a.scan_value LIKE %s OR u.first_name LIKE %s OR u.last_name LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $where_sql = implode(' AND ', $where);
        $join = ' FROM ' . self::table('attendance') . ' a LEFT JOIN ' . self::table('members') . ' u ON u.id=a.member_id WHERE ' . $where_sql;
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*)' . $join, $params));
        $sql = 'SELECT a.*, u.first_name,u.last_name' . $join . ' ORDER BY a.occurred_at DESC,a.id DESC LIMIT %d OFFSET %d';
        $query_params = $params;
        $query_params[] = $limit;
        $query_params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $query_params));
        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array(
                'id' => (int) $row->id,
                'member_id' => (int) $row->member_id,
                'member_code' => (string) $row->member_code,
                'member_name' => trim((string) $row->first_name . ' ' . (string) $row->last_name) ?: 'ناشناس',
                'device_user_id' => (string) $row->device_user_id,
                'scan_value' => (string) $row->scan_value,
                'direction' => (string) $row->direction,
                'event_type' => (string) ($row->event_type ?: ($row->member_id ? ($row->direction === 'out' ? 'exit' : 'entry') : 'unknown')),
                'source' => (string) $row->source,
                'decision' => (string) $row->decision,
                'reason' => (string) $row->reason,
                'occurred_at' => (string) $row->occurred_at,
            );
        }
        return array('items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $limit,
                     'total_pages' => (int) ceil($total / $limit));
    }

    public static function access_list($args = array()) {
        global $wpdb;
        $page = max(1, (int) (isset($args['page']) ? $args['page'] : 1));
        $limit = max(1, min(200, (int) (isset($args['per_page']) ? $args['per_page'] : 100)));
        $offset = ($page - 1) * $limit;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('members') . " WHERE status <> 'archived' AND device_user_id <> '' ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_access_list_lookup','خواندن اعضای متصل به دستگاه انجام نشد.',array('status'=>500)); }
        $rows = (array) $rows;
        $member_ids = array_map(function ($row) { return (int) $row->id; }, $rows);
        if (!class_exists('WSM_Club_Operations') || !method_exists('WSM_Club_Operations','pos_debt_by_member')) { return new WP_Error('wsm_club_pos_debt_unavailable','محاسبهٔ بدهی فروش حضوری برای فهرست اعضا در دسترس نیست.',array('status'=>503)); }
        $pos_debts = WSM_Club_Operations::pos_debt_by_member($member_ids);
        if (is_wp_error($pos_debts)) { return $pos_debts; }
        $items = array();
        foreach ($rows as $row) {
            $eligibility = self::eligibility($row, isset($pos_debts[(int) $row->id]) ? $pos_debts[(int) $row->id] : 0.0);
            if (is_wp_error($eligibility)) { return $eligibility; }
            $items[] = array(
                'member_id' => (int) $row->id,
                'member_code' => (string) $row->member_code,
                'device_user_id' => (string) $row->device_user_id,
                'name' => trim((string) $row->first_name . ' ' . (string) $row->last_name),
                'eligible' => (bool) $eligibility['eligible'],
                'reason' => (string) $eligibility['reason'],
                'valid_until' => $eligibility['membership'] ? (string) $eligibility['membership']->end_date : '',
            );
        }
        return array('items' => $items, 'page' => $page, 'per_page' => $limit, 'has_more' => count($items) === $limit);
    }

    /** Attendance history scoped to the authenticated bridge device. */
    public static function bridge_attendance_list($args = array()) {
        $device_id = WSM_Permissions::current_device_id();
        if (!$device_id) { return new WP_Error('wsm_club_bridge_device_missing', 'شناسهٔ پل احراز نشده است.', array('status' => 403)); }
        $args['source'] = 'bridge';
        $args['source_device_id'] = $device_id;
        return self::list_attendance($args);
    }

    public static function ingest_events($data) {
        if (!is_array($data) || empty($data['events']) || !is_array($data['events'])) {
            return new WP_Error('wsm_club_bridge_events_invalid', 'فهرست رویدادهای دستگاه نامعتبر یا خالی است.', array('status' => 400));
        }
        if (count($data['events']) > 100) { return new WP_Error('wsm_club_bridge_limit', 'در هر درخواست حداکثر ۱۰۰ رویداد بفرستید.', array('status' => 400)); }
        $results = array();
        $received = 0;
        $rejected = 0;
        $reject = function ($index, $event_id, $code, $message) use (&$results, &$rejected) {
            $results[] = array(
                'index' => is_numeric($index) ? (int) $index : 0,
                'event_id' => (string) $event_id,
                'accepted' => false,
                'duplicate' => false,
                'error_code' => (string) $code,
                'message' => (string) $message,
            );
            $rejected++;
        };
        foreach ($data['events'] as $index => $event) {
            if (!is_array($event)) {
                $reject($index, '', 'event_invalid', 'ساختار رویداد تردد نامعتبر است.');
                continue;
            }
            $event_id = isset($event['event_id']) && is_scalar($event['event_id']) ? strtolower(trim((string) $event['event_id'])) : '';
            if (!preg_match('/^[a-f0-9]{64}$/', $event_id)) {
                $reject($index, $event_id, 'event_id_invalid', 'شناسهٔ پایدار رویداد باید SHA-256 با ۶۴ رقم hex باشد.');
                continue;
            }
            $uid = self::text(isset($event['device_user_id']) && is_scalar($event['device_user_id']) ? $event['device_user_id'] : '', 32);
            if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $uid)) {
                $reject($index, $event_id, 'device_user_id_invalid', 'شناسهٔ کاربر دستگاه خالی یا نامعتبر است.');
                continue;
            }
            $timestamp = self::to_site_datetime(isset($event['timestamp']) ? $event['timestamp'] : '');
            if (!$timestamp) {
                $reject($index, $event_id, 'timestamp_invalid', 'زمان رویداد نامعتبر یا بیش از حد در آینده است.');
                continue;
            }
            $direction = 'in';
            if (array_key_exists('direction', $event)) {
                if (!is_scalar($event['direction']) || !in_array(strtolower(trim((string) $event['direction'])), array('in', 'out'), true)) {
                    $reject($index, $event_id, 'direction_invalid', 'جهت تردد باید in یا out باشد.');
                    continue;
                }
                $direction = strtolower(trim((string) $event['direction']));
            }
            $member = self::find_member_by_device_user_id($uid);
            $scan = $member ? (string) $member->member_code : $uid;
            $result = self::create_attendance($member, $scan, $direction, 'bridge', WSM_Permissions::current_device_id(), $event_id, $timestamp);
            if (is_wp_error($result)) {
                $reject($index, $event_id, $result->get_error_code(), $result->get_error_message());
                continue;
            }
            $result['event_id'] = $event_id;
            $result['accepted'] = true;
            $result['index'] = is_numeric($index) ? (int) $index : 0;
            $results[] = $result;
            $received++;
        }
        return array('received' => $received, 'rejected' => $rejected, 'items' => $results);
    }

    public static function list_staff($args = array()) {
        global $wpdb;
        $limit = max(1, min(100, (int) (isset($args['per_page']) ? $args['per_page'] : 50)));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('staff') . ' ORDER BY status ASC,name ASC,id DESC LIMIT %d', $limit));
        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array('id' => (int) $row->id, 'name' => (string) $row->name, 'phone' => (string) $row->phone, 'role' => (string) $row->role, 'compensation_type' => (string) $row->compensation_type, 'base_amount' => (string) $row->base_amount, 'status' => (string) $row->status, 'notes' => (string) $row->notes);
        }
        return array('items' => $items);
    }

    public static function save_staff($data, $id = 0) {
        global $wpdb;
        if (!is_array($data)) { return new WP_Error('wsm_club_staff_invalid', 'اطلاعات مربی نامعتبر است.', array('status' => 400)); }
        $before = $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('staff') . ' WHERE id=%d', (int) $id)) : null;
        if ($id && !$before) { return new WP_Error('wsm_club_staff_missing', 'مربی/کارمند پیدا نشد.', array('status' => 404)); }
        $name = array_key_exists('name', $data) ? self::text($data['name'], 160) : ($before ? $before->name : '');
        if ($name === '') { return new WP_Error('wsm_club_staff_name_required', 'نام مربی/کارمند الزامی است.', array('status' => 400)); }
        $phone = array_key_exists('phone', $data) ? self::phone($data['phone']) : ($before ? $before->phone : '');
        $role = array_key_exists('role', $data) ? self::text($data['role'], 100) : ($before ? $before->role : 'مربی');
        $type = isset($data['compensation_type']) && in_array($data['compensation_type'], array('monthly','per_session','hourly'), true) ? $data['compensation_type'] : ($before ? $before->compensation_type : 'monthly');
        $amount = self::amount(array_key_exists('base_amount', $data) ? $data['base_amount'] : ($before ? $before->base_amount : '0'), 'مبلغ پایه', true);
        if (is_wp_error($amount)) { return $amount; }
        $status = isset($data['status']) && in_array($data['status'], array('active','inactive'), true) ? $data['status'] : ($before ? $before->status : 'active');
        $notes = array_key_exists('notes', $data) ? sanitize_textarea_field(is_scalar($data['notes']) ? (string) $data['notes'] : '') : ($before ? $before->notes : '');
        $values = array('name' => $name, 'phone' => $phone, 'role' => $role, 'compensation_type' => $type, 'base_amount' => $amount, 'status' => $status, 'notes' => $notes, 'updated_at' => self::now());
        if ($id) {
            $ok = $wpdb->update(self::table('staff'), $values, array('id' => (int) $id));
            if ($ok === false) { return new WP_Error('wsm_club_save_failed', 'ذخیرهٔ مربی انجام نشد.', array('status' => 500)); }
            $staff_id = (int) $id;
            WSM_Activity_Log::log('update', 'club_staff', $staff_id, $name, array('status' => $before->status), array('status' => $status, 'base_amount' => $amount), 'success');
        } else {
            $values['created_at'] = self::now();
            if ($wpdb->insert(self::table('staff'), $values) === false) { return new WP_Error('wsm_club_save_failed', 'ثبت مربی انجام نشد.', array('status' => 500)); }
            $staff_id = (int) $wpdb->insert_id;
            WSM_Activity_Log::log('create', 'club_staff', $staff_id, $name, '', array('role' => $role, 'base_amount' => $amount), 'success');
        }
        return self::list_staff(array('per_page' => 100))['items'];
    }

    public static function list_payroll($args = array()) {
        global $wpdb;
        $limit = max(1, min(100, (int) (isset($args['per_page']) ? $args['per_page'] : 50)));
        $sql = 'SELECT p.*,s.name AS staff_name,s.role AS staff_role FROM ' . self::table('payroll') . ' p LEFT JOIN ' . self::table('staff') . ' s ON s.id=p.staff_id ORDER BY p.period_start DESC,p.id DESC LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit));
        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array('id' => (int) $row->id, 'staff_id' => (int) $row->staff_id, 'staff_name' => (string) $row->staff_name, 'staff_role' => (string) $row->staff_role, 'period_start' => (string) $row->period_start, 'period_end' => (string) $row->period_end, 'gross' => (string) $row->gross, 'deductions' => (string) $row->deductions, 'net' => (string) $row->net, 'status' => (string) $row->status, 'paid_at' => (string) $row->paid_at, 'note' => (string) $row->note);
        }
        return array('items' => $items);
    }

    public static function create_payroll($data) {
        global $wpdb;
        if (!is_array($data)) { return new WP_Error('wsm_club_payroll_invalid', 'اطلاعات حقوق نامعتبر است.', array('status' => 400)); }
        $staff_id = self::id(isset($data['staff_id']) ? $data['staff_id'] : 0);
        $staff = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('staff') . " WHERE id=%d AND status='active'", $staff_id));
        if (!$staff) { return new WP_Error('wsm_club_staff_missing', 'مربی/کارمند فعال پیدا نشد.', array('status' => 404)); }
        $from = isset($data['period_start']) ? (string) $data['period_start'] : '';
        $to = isset($data['period_end']) ? (string) $data['period_end'] : '';
        if (!self::valid_date($from) || !self::valid_date($to) || $from > $to) { return new WP_Error('wsm_club_period_invalid', 'بازهٔ حقوق نامعتبر است.', array('status' => 400)); }
        $gross = self::amount(isset($data['gross']) ? $data['gross'] : $staff->base_amount, 'حقوق ناخالص', true);
        $deductions = self::amount(isset($data['deductions']) ? $data['deductions'] : '0', 'کسورات', true);
        if (is_wp_error($gross)) { return $gross; }
        if (is_wp_error($deductions)) { return $deductions; }
        if ((float) $deductions > (float) $gross) { return new WP_Error('wsm_club_payroll_invalid', 'کسورات نمی‌تواند از حقوق ناخالص بیشتر باشد.', array('status' => 400)); }
        $net = wc_format_decimal((float) $gross - (float) $deductions, min(6, max(0, (int) wc_get_price_decimals())));
        $now = self::now();
        if ($wpdb->insert(self::table('payroll'), array('staff_id' => $staff_id, 'period_start' => $from, 'period_end' => $to, 'gross' => $gross, 'deductions' => $deductions, 'net' => $net, 'status' => 'draft', 'paid_at' => null, 'note' => self::text(isset($data['note']) ? $data['note'] : '', 255), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now)) === false) {
            return new WP_Error('wsm_club_save_failed', 'ثبت فیش حقوق انجام نشد.', array('status' => 500));
        }
        $id = (int) $wpdb->insert_id;
        WSM_Activity_Log::log('create', 'club_payroll', $id, $staff->name, '', array('period_start' => $from, 'period_end' => $to, 'net' => $net), 'success');
        $items = self::list_payroll(array('per_page' => 100))['items'];
        foreach ($items as $item) { if ((int) $item['id'] === $id) { return $item; } }
        return new WP_Error('wsm_club_save_failed', 'فیش حقوق ثبت شد اما خواندن نتیجه ناموفق بود.', array('status' => 500));
    }

    public static function mark_payroll_paid($id, $data = array()) {
        global $wpdb;
        $data = is_array($data) ? $data : array();
        $approved = WSM_Club_Operations::verify_finance_manager($data, 'payroll_paid');
        if (is_wp_error($approved)) { return $approved; }
        if (!rest_sanitize_boolean($data['payment_confirmed'] ?? false)) { return new WP_Error('wsm_club_payroll_payment_unconfirmed', 'پیش از ثبت فیش به‌عنوان پرداخت‌شده، انتقال واقعی حقوق را تأیید کنید.', array('status' => 400)); }
        $account_id = absint(isset($data['account_id']) ? $data['account_id'] : 0);
        if (!$account_id) { return new WP_Error('wsm_club_payroll_bank_required', 'حساب بانکی شرکت برای پرداخت حقوق انتخاب شود.', array('status' => 400)); }
        $account_type = $wpdb->get_var($wpdb->prepare('SELECT account_type FROM ' . WSM_Club_Operations::table('financial_accounts') . ' WHERE id=%d AND is_active=1', $account_id));
        if ($account_type !== 'bank') { return new WP_Error('wsm_club_payroll_bank_required', 'پرداخت حقوق فقط از حساب بانکی ثبت می‌شود؛ صندوق POS مجاز نیست.', array('status' => 400)); }
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('wsm_club_payroll_transaction', 'آغاز ثبت پرداخت حقوق انجام نشد.', array('status' => 500)); }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('payroll') . ' WHERE id=%d FOR UPDATE', (int) $id));
        if (!$row) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_payroll_missing', 'فیش حقوق پیدا نشد.', array('status' => 404)); }
        if ($row->status === 'paid') { $wpdb->query('COMMIT'); return array('id' => (int) $row->id, 'status' => 'paid', 'paid_at' => $row->paid_at); }
        $staff_row = self::fetch_staff((int) $row->staff_id);
        $now = self::now();
        $net = (string) $row->net;
        $ledger = WSM_Club_Operations::add_finance('payroll', $net, 'پرداخت حقوق ' . ($staff_row ? $staff_row->name : 'کارمند'), array('account_id' => $account_id, 'category' => 'حقوق و دستمزد', 'occurred_at' => $now, 'description' => $row->note, 'method' => 'bank_transfer', 'source' => 'payroll', 'transaction_type' => 'expense', 'account_delta' => '-' . ltrim($net, '+'), 'reference_id' => 'payroll:' . (int) $id));
        if (is_wp_error($ledger)) { $wpdb->query('ROLLBACK'); return $ledger; }
        $updated = $wpdb->update(self::table('payroll'), array('status' => 'paid', 'paid_at' => $now, 'updated_at' => $now), array('id' => (int) $id, 'status' => 'draft'));
        if ($updated !== 1) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_save_failed', 'ثبت وضعیت پرداخت حقوق انجام نشد؛ تغییر مالی بازگردانده شد.', array('status' => 500)); }
        $wpdb->query('COMMIT');
        WSM_Activity_Log::log('update', 'club_payroll', (int) $id, 'فیش حقوق #' . (int) $id, array('status' => $row->status, 'net' => $net), array('status' => 'paid', 'paid_at' => $now, 'account_id' => $account_id, 'ledger_entry_id' => (int) $ledger, 'approved_by' => $approved['user_id']), 'success');
        $items = self::list_payroll(array('per_page' => 100))['items'];
        foreach ($items as $item) { if ((int) $item['id'] === (int) $id) { return $item; } }
        return array('id' => (int) $id, 'status' => 'paid', 'paid_at' => $now, 'ledger_entry_id' => (int) $ledger);
    }

    public static function daily_tasks() {
        global $wpdb;
        if (class_exists('WSM_Club_Operations')) { WSM_Club_Operations::daily_tasks(); }
        $club_settings = class_exists('WSM_Club_Operations') ? WSM_Club_Operations::settings() : array('sms_events'=>array(),'debt_reminder_after_days'=>0,'sms_expiry_days_before'=>3);
        $today = self::today();
        $now = self::now();
        $membership_table = self::table('memberships');

        // Process state transitions in bounded batches so each event is emitted exactly once.
        $expired_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,member_id,plan_name,start_date,end_date,status FROM $membership_table WHERE status IN ('active','scheduled') AND end_date < %s ORDER BY end_date ASC,id ASC LIMIT 250",
            $today
        ));
        foreach ((array)$expired_rows as $row) {
            $changed = $wpdb->update($membership_table, array('status'=>'expired','updated_at'=>$now), array('id'=>(int)$row->id,'status'=>(string)$row->status));
            if ($changed !== false && $changed > 0 && class_exists('WSM_Club_Operations')) {
                $member = self::fetch_member((int)$row->member_id);
                if ($member) { WSM_Club_Operations::trigger_sms_event('membership_expired',$member,array('plan_name'=>(string)$row->plan_name,'start_date'=>(string)$row->start_date,'end_date'=>(string)$row->end_date,'membership_id'=>(int)$row->id)); }
            }
        }

        $activating_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,member_id,plan_name,start_date,end_date FROM $membership_table WHERE status='scheduled' AND start_date <= %s AND end_date >= %s ORDER BY start_date ASC,id ASC LIMIT 250",
            $today, $today
        ));
        foreach ((array)$activating_rows as $row) {
            $previous = $wpdb->get_row($wpdb->prepare(
                "SELECT plan_name,end_date FROM $membership_table WHERE member_id=%d AND id<>%d AND start_date <= %s ORDER BY start_date DESC,id DESC LIMIT 1",
                (int)$row->member_id, (int)$row->id, (string)$row->start_date
            ));
            $changed = $wpdb->update($membership_table, array('status'=>'active','updated_at'=>$now), array('id'=>(int)$row->id,'status'=>'scheduled'));
            if ($changed !== false && $changed > 0 && class_exists('WSM_Club_Operations')) {
                $member = self::fetch_member((int)$row->member_id);
                if ($member) {
                    $previous_contiguous = $previous && (string)$previous->end_date >= (new DateTimeImmutable((string)$row->start_date, wp_timezone()))->modify('-1 day')->format('Y-m-d');
                    $level_changed = $previous_contiguous && (string)$previous->plan_name !== (string)$row->plan_name;
                    $event_key = $level_changed ? 'membership_level_change' : 'membership_activation';
                    WSM_Club_Operations::trigger_sms_event($event_key,$member,array(
                        'plan_name'=>(string)$row->plan_name,
                        'old_plan_name'=>$level_changed?(string)$previous->plan_name:'',
                        'start_date'=>(string)$row->start_date,
                        'end_date'=>(string)$row->end_date,
                        'membership_id'=>(int)$row->id,
                    ));
                }
            }
        }

        if (!class_exists('WSM_SMS') || !WSM_SMS::is_active()) { return; }
        $reminder_days = isset($club_settings['sms_expiry_days_before']) ? max(1,min(365,(int)$club_settings['sms_expiry_days_before'])) : 3;
        $until = (new DateTimeImmutable($today, wp_timezone()))->modify('+' . $reminder_days . ' days')->format('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*,u.first_name,u.last_name,u.card_number,u.phone,u.sms_consent,u.member_code FROM $membership_table m INNER JOIN " . self::table('members') . " u ON u.id=m.member_id WHERE m.status='active' AND u.status='active' AND u.sms_consent=1 AND (u.phone<>'' OR EXISTS (SELECT 1 FROM " . self::table('phonebook') . " pb WHERE pb.member_id=u.id AND pb.phone<>'' AND pb.sms_consent=1)) AND ((m.end_date >= %s AND m.end_date <= %s AND m.expiry_reminder_sent=0) OR (m.debt_reminder_sent=0 AND m.price > COALESCE((SELECT SUM(p.amount) FROM " . self::table('payments') . " p WHERE p.membership_id=m.id AND p.status='paid'),0))) ORDER BY m.id ASC LIMIT 200",
            $today, $until
        ));
        foreach ((array)$rows as $row) {
            $member = self::fetch_member((int)$row->member_id);
            if (!$member) { continue; }
            $balance = self::membership_balance((int)$row->id);
            if ($balance > 0.000001 && !(int)$row->debt_reminder_sent && !empty($club_settings['sms_events']['payment_reminder'])) {
                $age = isset($row->created_at) ? (int)floor((current_time('timestamp') - strtotime($row->created_at)) / DAY_IN_SECONDS) : 0;
                if ($age >= (int)$club_settings['debt_reminder_after_days']) {
                    $sent = WSM_Club_Operations::trigger_sms_event('payment_reminder',$member,array('balance'=>$balance,'plan_name'=>(string)$row->plan_name,'end_date'=>(string)$row->end_date,'membership_id'=>(int)$row->id));
                    if (!empty($sent['ok'])) { $wpdb->update($membership_table,array('debt_reminder_sent'=>1,'updated_at'=>$now),array('id'=>(int)$row->id)); }
                }
            }
            if (!empty($club_settings['sms_events']['membership_expiry_reminder']) && $row->end_date >= $today && $row->end_date <= $until && !(int)$row->expiry_reminder_sent) {
                $days_left = max(0,(int)floor((strtotime((string)$row->end_date)-strtotime($today))/DAY_IN_SECONDS));
                $sent = WSM_Club_Operations::trigger_sms_event('membership_expiry_reminder',$member,array('plan_name'=>(string)$row->plan_name,'end_date'=>(string)$row->end_date,'days'=>$days_left,'balance'=>$balance,'membership_id'=>(int)$row->id));
                if (!empty($sent['ok'])) { $wpdb->update($membership_table,array('expiry_reminder_sent'=>1,'updated_at'=>$now),array('id'=>(int)$row->id)); }
            }
        }
    }
}
