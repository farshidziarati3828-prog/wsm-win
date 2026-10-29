<?php
/**
 * امنیت لایهٔ درخواست:
 * - محدودیت نرخ: ۱۰ تلاش ناموفق در ۱۵ دقیقه ⇒ مسدود کردن IP برای ۵ دقیقه
 * - امضای HMAC-SHA256 برای هر درخواست Bearer (متد، مسیر، زمان، nonce، هش بدنه)
 * - پنجرهٔ زمانی ±۵ دقیقه و nonce یکتا با TTL ده دقیقه
 * - لاگ امنیتی و هشدار (ایمیل IP جدید، پیامک از طریق فیلتر wsm_send_sms)
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Security {

    /** نتیجهٔ اعتبارسنجی nonce در همین درخواست HTTP؛ callback وردپرس ممکن است چندبار اجرا شود. */
    private static $verified_memo = array();
    private static $verified_memo_request = "";

    const MAX_FAILURES     = 10;
    const FAILURE_WINDOW   = 900;   // ۱۵ دقیقه
    const BLOCK_SECONDS    = 300;   // ۵ دقیقه
    const CLOCK_SKEW       = 300;   // ۵ دقیقه
    const NONCE_TTL        = 600;   // ۱۰ دقیقه
    const NEW_IP_WINDOW    = 86400; // هشدار IP جدید برای هر دستگاه/IP حداکثر یک بار در روز

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'wsm_' . $name;
    }

    /* ---------------- لاگ امنیتی ---------------- */

    public static function log($event, $severity, $device_id, $details) {
        global $wpdb;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $wpdb->insert(self::table('security_log'), array(
            'event'      => substr((string) $event, 0, 40),
            'severity'   => substr((string) $severity, 0, 10),
            'device_id'  => (int) $device_id,
            'ip_address' => WSM_Util::client_ip(),
            'user_agent' => substr($ua, 0, 255),
            'details'    => substr((string) $details, 0, 1000),
            'created_at' => WSM_Util::now(),
        ));
    }

    /* ---------------- محدودیت نرخ ---------------- */

    /**
     * اگر IP مسدود باشد، زمان پایان مسدودی را برمی‌گرداند؛ وگرنه false.
     * @return string|false
     */
    public static function blocked_until($ip = null) {
        global $wpdb;
        $ip  = $ip === null ? WSM_Util::client_ip() : $ip;
        $row = $wpdb->get_var($wpdb->prepare(
            'SELECT blocked_until FROM ' . self::table('blocked_ips') . ' WHERE ip_address = %s AND blocked_until > %s ORDER BY id DESC LIMIT 1',
            $ip, WSM_Util::now()
        ));
        return $row ? $row : false;
    }

    /** تعداد IPهایی که در حال حاضر توسط محدودکنندهٔ نرخ مسدود هستند. */
    public static function blocked_ip_count() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(DISTINCT ip_address) FROM ' . self::table('blocked_ips') . ' WHERE blocked_until > %s',
            WSM_Util::now()
        ));
    }

    /** فهرست آخرین رکورد مسدودی هر IP؛ رکوردهای منقضی نیز تا پاک‌سازی روزانه قابل مشاهده‌اند. */
    public static function blocked_ip_records($args = array()) {
        global $wpdb;
        $table = self::table('blocked_ips');
        $where = array('b.id = (SELECT MAX(latest.id) FROM ' . $table . ' AS latest WHERE latest.ip_address = b.ip_address)');
        $params = array();
        $search = isset($args['search']) && is_scalar($args['search']) ? sanitize_text_field((string) $args['search']) : '';
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(b.ip_address LIKE %s OR b.reason LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        $limit = isset($args['limit']) ? max(1, min(200, (int) $args['limit'])) : 100;
        $params[] = $limit;
        $sql = 'SELECT b.ip_address,b.reason,b.blocked_until,b.created_at,' .
            '(SELECT COUNT(*) FROM ' . $table . ' AS history WHERE history.ip_address = b.ip_address) AS block_count ' .
            'FROM ' . $table . ' AS b WHERE ' . implode(' AND ', $where) .
            ' ORDER BY b.blocked_until DESC,b.id DESC LIMIT %d';
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /** رویدادهای اخیر مسدودسازی IP؛ پس از انقضا نیز برای بررسی مدیر تا ۹۰ روز در لاگ می‌مانند. */
    public static function ip_block_history($args = array()) {
        global $wpdb;
        $table = self::table('security_log');
        $where = array('event = %s');
        $params = array('ip_blocked');
        $search = isset($args['search']) && is_scalar($args['search']) ? sanitize_text_field((string) $args['search']) : '';
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(ip_address LIKE %s OR details LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        $limit = isset($args['limit']) ? max(1, min(200, (int) $args['limit'])) : 50;
        $params[] = $limit;
        $sql = 'SELECT ip_address,details,created_at FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d';
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /** رفع تمام مسدودی‌های افزونه و تلاش‌های ناموفق ذخیره‌شده برای یک IPv4/IPv6. */
    public static function unblock_ip($ip) {
        global $wpdb;
        $ip = trim((string) $ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return new WP_Error('wsm_ip_invalid', 'نشانی IPv4 یا IPv6 معتبر وارد کنید.', array('status' => 400));
        }
        $attempts = $wpdb->delete(self::table('auth_attempts'), array('ip_address' => $ip), array('%s'));
        if ($attempts === false) {
            return new WP_Error('wsm_ip_unblock_failed', 'پاک‌کردن تلاش‌های ناموفق این IP انجام نشد.', array('status' => 500));
        }
        $blocks = $wpdb->delete(self::table('blocked_ips'), array('ip_address' => $ip), array('%s'));
        if ($blocks === false) {
            return new WP_Error('wsm_ip_unblock_failed', 'حذف رکورد مسدودی این IP انجام نشد.', array('status' => 500));
        }
        self::log('ip_unblocked', 'warning', 0, 'رفع مسدودی افزونه برای IP ' . $ip . '؛ رکوردهای مسدودی و تلاش‌های ناموفق پاک شد.');
        return array('ip_address' => $ip, 'blocks_removed' => (int) $blocks, 'attempts_removed' => (int) $attempts);
    }

    /**
     * ثبت یک تلاش ناموفق. با رسیدن به حد مجاز، IP مسدود و هشدار پیامکی ثبت می‌شود.
     */
    public static function register_failure($reason) {
        global $wpdb;
        $ip  = WSM_Util::client_ip();
        $now = WSM_Util::now();

        $wpdb->insert(self::table('auth_attempts'), array(
            'ip_address' => $ip,
            'reason'     => substr((string) $reason, 0, 40),
            'created_at' => $now,
        ));

        $since = date('Y-m-d H:i:s', current_time('timestamp') - self::FAILURE_WINDOW);
        $count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table('auth_attempts') . ' WHERE ip_address = %s AND created_at > %s',
            $ip, $since
        ));

        if ($count < self::MAX_FAILURES) {
            self::log('auth_failed', 'warning', 0, $reason . ' — تلاش ' . $count . ' از ' . self::MAX_FAILURES);
            return false;
        }

        $until = date('Y-m-d H:i:s', current_time('timestamp') + self::BLOCK_SECONDS);
        $wpdb->insert(self::table('blocked_ips'), array(
            'ip_address'    => $ip,
            'reason'        => $count . ' تلاش ناموفق در ۱۵ دقیقه',
            'blocked_until' => $until,
            'created_at'    => $now,
        ));
        $wpdb->delete(self::table('auth_attempts'), array('ip_address' => $ip), array('%s'));

        self::log('ip_blocked', 'critical', 0, 'IP تا ' . $until . ' مسدود شد');
        self::notify_security_sms('مدیر فروشگاه: ' . $count . ' تلاش ناموفق اتصال از IP ' . $ip . ' — مسدود تا ' . $until, array('login_count'=>$count,'ip_address'=>$ip,'lock_until'=>$until));

        return $until;
    }

    /* ---------------- هشدار ---------------- */

    /**
     * پیامک: فقط اگر افزونهٔ دیگری فیلتر wsm_send_sms را پیاده کرده باشد ارسال می‌شود.
     * بدون سرویس‌دهندهٔ پیامک، فقط در لاگ ثبت می‌شود.
     */
    /** Security-event SMS uses its own configurable event toggle and admin template. */
    public static function notify_security_sms($message, $context = array()) {
        if (!class_exists('WSM_Club_Operations')) { return self::notify_sms($message); }
        $context = is_array($context) ? $context : array();
        $context['message'] = (string)$message;
        $result = WSM_Club_Operations::send_admin_sms_event('suspicious_login', $context);
        if (!empty($result['ok'])) {
            self::log('sms_sent', 'info', 0, substr((string)$message, 0, 200));
        } elseif (!empty($result['detail']) && strpos((string)$result['detail'], 'غیرفعال') === false) {
            self::log('sms_failed', 'warning', 0, (string)$result['detail']);
        }
        return !empty($result['ok']);
    }

    public static function notify_sms($message) {
        $sent = (bool) apply_filters('wsm_send_sms', false, $message);
        if ($sent) {
            self::log('sms_sent', 'info', 0, substr($message, 0, 200));
        } elseif (!class_exists('WSM_SMS') || !WSM_SMS::is_active()) {
            if (class_exists('WSM_SMS') && WSM_SMS::is_configured()) {
                self::log('sms_disabled', 'info', 0, 'اطلاعات ملی‌پیامک کامل است، اما هشدار پیامکی خاموش است');
            } else {
                self::log('sms_not_configured', 'info', 0, 'هیچ سرویس پیامکی متصل نیست');
            }
        }
        // اگر پیکربندی هست و ارسال شکست خورده، خود WSM_SMS::send علت را ثبت کرده است (sms_failed).
        return $sent;
    }

    /** خطای wp_mail را برای نمایش در تنظیمات ذخیره می‌کند (بدون محتوای ایمیل). */
    public static function record_mail_error($wp_error) {
        $msg = is_wp_error($wp_error) ? $wp_error->get_error_message() : 'خطای نامشخص';
        update_option('wsm_last_mail_error', substr((string) $msg, 0, 300), false);
        self::log('mail_failed', 'warning', 0, substr((string) $msg, 0, 200));
    }

    /** ایمیل آزمایشی به مدیر؛ نتیجهٔ پذیرش wp_mail و آخرین خطا را برمی‌گرداند. */
    public static function send_test_email() {
        $to = get_option('admin_email');
        delete_option('wsm_last_mail_error');
        $ok = wp_mail(
            $to,
            'ایمیل آزمایشی افزونهٔ فروشگاه',
            "این یک ایمیل آزمایشی از افزونهٔ مدیریت فروشگاه است.\nاگر این پیام را دیدید، مسیر ایمیل مدیر فعال است."
        );
        if ($ok) {
            self::log('mail_test_ok', 'info', 0, 'ایمیل آزمایشی توسط وردپرس پذیرفته شد');
            return array('ok' => true, 'detail' => 'وردپرس ارسال را پذیرفت. صندوق ورودی و پوشهٔ هرزنامه را بررسی کنید.');
        }
        $err = (string) get_option('wsm_last_mail_error', '');
        self::log('mail_test_failed', 'warning', 0, $err !== '' ? $err : 'wp_mail بازگشت false داد');
        return array('ok' => false, 'detail' => 'ارسال ناموفق: ' . ($err !== '' ? $err : 'wp_mail مقدار false برگرداند.'));
    }

    /**
     * هشدار IP جدید: اگر دستگاه از IP متفاوتی وصل شود، به مدیر ایمیل می‌شود (حداکثر یک بار در روز برای هر IP).
     */
    public static function check_new_ip($device, $ip) {
        global $wpdb;
        if (!$device || $ip === '' || (string) $device->ip_address === '' || (string) $device->ip_address === $ip) {
            return;
        }
        $since = date('Y-m-d H:i:s', current_time('timestamp') - self::NEW_IP_WINDOW);
        $seen  = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table('security_log') . ' WHERE event = %s AND device_id = %d AND details = %s AND created_at > %s',
            'new_ip', (int) $device->id, $ip, $since
        ));
        if ($seen > 0) {
            return;
        }
        self::log('new_ip', 'warning', (int) $device->id, $ip);
        $body = 'دستگاه «' . $device->device_name . '» از IP جدید ' . $ip . ' متصل شد. اگر شما نبودید، دستگاه را از صفحهٔ دستگاه‌ها قطع کنید.';
        wp_mail(get_option('admin_email'), 'مدیر فروشگاه: اتصال از IP جدید', $body);
    }

    /* ---------------- امضای درخواست ---------------- */

    /**
     * رشتهٔ امضا: METHOD \n ROUTE \n TIMESTAMP \n NONCE \n BODY_SHA256
     * ورودی آخر هش بدنه است (هِگزِ SHA-256)، نه خود بدنه.
     */
    public static function canonical_string($method, $route, $timestamp, $nonce, $body_sha256) {
        return implode("\n", array(
            strtoupper((string) $method),
            (string) $route,
            (string) $timestamp,
            (string) $nonce,
            strtolower((string) $body_sha256),
        ));
    }

    /**
     * هش بدنهٔ درخواست برای امضا.
     * JSON و سایر بدنه‌ها: SHA-256 بایت‌های خام.
     * multipart/form-data: PHP بدنهٔ خام را پیش از رسیدن به وردپرس مصرف می‌کند (php://input خالی است)،
     * بنابراین هش کانونیک از فیلدها و محتوای فایل‌ها ساخته می‌شود تا کلاینت هم همان را محاسبه کند.
     */
    public static function body_sha256($request) {
        $ctype = strtolower(trim((string) $request->get_header('content_type')));
        if (strpos($ctype, 'multipart/form-data') === 0) {
            return self::multipart_sha256($request->get_body_params(), $request->get_file_params());
        }
        return hash('sha256', (string) $request->get_body());
    }

    /**
     * هش کانونیک multipart: هر فیلد یک خط "f:name:sha256(value)"، هر فایل یک خط "u:name:filename:sha256(content)".
     * خط‌ها مرتب می‌شوند و با \n به هم می‌پیوندند؛ سپس SHA-256 گرفته می‌شود.
     */
    public static function multipart_sha256($fields, $files) {
        $lines = array();
        if (is_array($fields)) {
            foreach ($fields as $name => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $lines[] = 'f:' . wp_unslash((string) $name) . ':' . hash('sha256', wp_unslash((string) $value));
            }
        }
        if (is_array($files)) {
            foreach ($files as $name => $file) {
                $tmp   = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
                $sha   = ($tmp !== '' && is_readable($tmp)) ? hash_file('sha256', $tmp) : 'none';
                $fname = isset($file['name']) ? wp_unslash((string) $file['name']) : '';
                $lines[] = 'u:' . wp_unslash((string) $name) . ':' . $fname . ':' . $sha;
            }
        }
        sort($lines, SORT_STRING);
        return hash('sha256', implode("\n", $lines));
    }

    /**
     * بررسی امضای یک درخواست احراز‌شدهٔ Bearer
     * ترتیب: وجود هدرها ⇒ زمان ⇒ فرمت nonce ⇒ امضا ⇒ anti-replay فقط برای درخواست‌های تغییردهنده
     * @return true|WP_Error
     */
    public static function verify_signature($request, $device) {
        // WordPress may call permission_callback again while building the REST Allow header.
        // Memoize only within the same PHP request, keyed by stable signed headers rather than
        // request-object identity or mutable parsed query/body parameters.
        $marker = self::request_marker();
        if (self::$verified_memo_request !== $marker) {
            self::$verified_memo_request = $marker;
            self::$verified_memo = array();
        }
        $key = array(
            'device' => (int) $device->id,
            'method' => strtoupper((string) $request->get_method()),
            'route'  => (string) $request->get_route(),
            'ts'     => trim((string) $request->get_header('x_wsm_timestamp')),
            'nonce'  => trim((string) $request->get_header('x_wsm_nonce')),
            'sig'    => strtolower(trim((string) $request->get_header('x_wsm_signature'))),
        );
        $memo_key = hash('sha256', $marker . "\n" . serialize($key));
        if (array_key_exists($memo_key, self::$verified_memo)) {
            return self::$verified_memo[$memo_key];
        }
        $result = self::verify_signature_once($request, $device);
        self::$verified_memo[$memo_key] = $result;
        return $result;
    }

    private static function verify_signature_once($request, $device) {
        $secret = WSM_Devices::signing_key($device);
        if ($secret === false) {
            return new WP_Error('wsm_reconnect_required', 'این دستگاه باید دوباره متصل شود.', array('status' => 401));
        }

        $ts    = trim((string) $request->get_header('x_wsm_timestamp'));
        $nonce = trim((string) $request->get_header('x_wsm_nonce'));
        $sig   = strtolower(trim((string) $request->get_header('x_wsm_signature')));

        if ($ts === '' || $nonce === '' || $sig === '') {
            self::register_failure('signature_missing');
            return new WP_Error('wsm_signature_required', 'امضای درخواست ارسال نشده است؛ از صفحهٔ اتصال دوباره وارد شوید.', array('status' => 401));
        }

        if (!preg_match('/^\d{10}$/', $ts) || abs(time() - (int) $ts) > self::CLOCK_SKEW) {
            self::register_failure('timestamp_out_of_window');
            return new WP_Error('wsm_timestamp_invalid', 'زمان درخواست معتبر نیست. ساعت دستگاه را بررسی کنید.', array('status' => 401));
        }

        if (!preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
            self::register_failure('signature_malformed');
            return new WP_Error('wsm_signature_invalid', 'امضای درخواست نامعتبر است.', array('status' => 401));
        }

        $method = strtoupper((string) $request->get_method());
        $canonical = self::canonical_string($method, $request->get_route(), $ts, $nonce, self::body_sha256($request));
        $expected  = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, $sig)) {
            self::register_failure('signature_invalid');
            self::log('signature_invalid', 'warning', (int) $device->id, $request->get_route());
            return new WP_Error('wsm_signature_invalid', 'امضای درخواست نامعتبر است.', array('status' => 401));
        }

        // Signed reads remain authenticated and timestamp-checked, but do not enter the replay store.
        // Parallel GET/HEAD calls must never reject one another as wsm_nonce_reused.
        if (!in_array($method, array('POST', 'PUT', 'DELETE'), true)) {
            return true;
        }

        $nonce_saved = self::store_nonce($nonce, (int) $device->id);
        if (is_wp_error($nonce_saved)) {
            return $nonce_saved;
        }
        if ($nonce_saved === false) {
            self::register_failure('nonce_reused');
            self::log('replay_blocked', 'critical', (int) $device->id, $method . ' ' . $request->get_route());
            return new WP_Error('wsm_nonce_reused', 'این درخواست قبلاً ثبت شده است.', array('status' => 401));
        }

        return true;
    }

    /** نشانگر اجرای همین درخواست HTTP؛ در نبود زمان سرور، شناسهٔ تصادفی پایدار می‌سازیم. */
    private static function request_marker() {
        $request_time = '';
        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            $request_time = (string) $_SERVER['REQUEST_TIME_FLOAT'];
        } elseif (isset($_SERVER['REQUEST_TIME'])) {
            $request_time = (string) $_SERVER['REQUEST_TIME'];
        }
        if (!isset($_SERVER['WSM_REQUEST_MARKER']) || !isset($_SERVER['WSM_REQUEST_MARKER_TIME']) || (string) $_SERVER['WSM_REQUEST_MARKER_TIME'] !== $request_time) {
            try {
                $random = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $random = uniqid('wsm_', true);
            }
            $_SERVER['WSM_REQUEST_MARKER_TIME'] = $request_time;
            $_SERVER['WSM_REQUEST_MARKER'] = ($request_time !== '' ? $request_time . ':' : '') . $random;
        }
        return (string) $_SERVER['WSM_REQUEST_MARKER'];
    }

    /**
     * nonce فقط پس از تأیید امضا ذخیره می‌شود تا کسی با درخواست جعلی جدول را پر نکند.
     * @return true|false|WP_Error false فقط یعنی nonce فعال قبلاً ثبت شده است.
     */
    private static function store_nonce($nonce, $device_id) {
        global $wpdb;
        $table = self::table('used_nonces');
        $hash  = hash('sha256', $nonce);
        $now   = WSM_Util::now();
        $delete_sql = $wpdb->prepare("DELETE FROM $table WHERE expires_at < %s LIMIT 500", $now);
        $wpdb->query($delete_sql);
        // INSERT IGNORE prevents duplicate-key warnings. A genuine duplicate returns 0;
        // a database/schema failure returns false and must not be reported as nonce reuse.
        $insert_sql = $wpdb->prepare(
            "INSERT IGNORE INTO $table (nonce_hash, device_id, expires_at, created_at) VALUES (%s, %d, %s, %s)",
            $hash,
            (int) $device_id,
            date('Y-m-d H:i:s', current_time('timestamp') + self::NONCE_TTL),
            $now
        );
        $inserted = $wpdb->query($insert_sql);
        if ($inserted === 1) {
            return true;
        }

        if ($inserted === false) {
            // Recover the one common schema drift that makes every signed tab fail: missing nonce table.
            $table_like = $wpdb->esc_like($table);
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_like));
            if ($found !== $table && class_exists('WSM_Core')) {
                WSM_Core::create_tables();
                $wpdb->query($delete_sql);
                $inserted = $wpdb->query($insert_sql);
                if ($inserted === 1) {
                    return true;
                }
            }
        }

        if ($inserted === 0) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, expires_at FROM $table WHERE nonce_hash = %s LIMIT 1",
                $hash
            ));
            if ($existing && (string) $existing->expires_at > $now) {
                return false;
            }
            if ($existing) {
                $wpdb->delete($table, array('id' => (int) $existing->id), array('%d'));
                $inserted = $wpdb->query($insert_sql);
                if ($inserted === 1) {
                    return true;
                }
                if ($inserted === 0) {
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table WHERE nonce_hash = %s LIMIT 1",
                        $hash
                    ));
                    if ($existing) {
                        return false;
                    }
                }
            }
        }

        self::log('nonce_store_failed', 'critical', (int) $device_id, 'Database could not persist the request nonce.');
        return new WP_Error(
            'wsm_nonce_store_failed',
            'ذخیرهٔ امن درخواست در پایگاه‌داده انجام نشد؛ جدول امنیتی افزونه یا دسترسی پایگاه‌داده را بررسی کنید.',
            array('status' => 503)
        );
    }

    /* ---------------- پاکسازی روزانه ---------------- */

    public static function cleanup() {
        global $wpdb;
        $now = WSM_Util::now();
        $day = date('Y-m-d H:i:s', current_time('timestamp') - DAY_IN_SECONDS);
        $ninety = date('Y-m-d H:i:s', current_time('timestamp') - 90 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('blocked_ips') . ' WHERE blocked_until < %s', $now));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('auth_attempts') . ' WHERE created_at < %s', $day));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('used_nonces') . ' WHERE expires_at < %s', $now));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('security_log') . ' WHERE created_at < %s', $ninety));
    }
}

