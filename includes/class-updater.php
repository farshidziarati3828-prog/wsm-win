<?php
/**
 * به‌روزرسانی افزونه: مهاجرت ترتیبی، بکاپ قبل از مهاجرت، بازگردانی در خطا،
 * اعلان مدیر، و بررسی نسخه از سرور به‌روزرسانی شخصی.
 *
 * هر مهاجرت یک تابع است که با نسخهٔ مقصد کلید خورده است. مهاجرت‌های بعد از نسخهٔ
 * نصب‌شده و تا نسخهٔ جاری (همین فایل) به ترتیب نسخه اجرا می‌شوند.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Updater {

    const VERSION_OPTION = 'wsm_version';
    const FAILED_OPTION  = 'wsm_upgrade_failed_to';
    const LAST_OPTION    = 'wsm_last_migration';
    const LOG_TABLE      = 'wsm_migration_log';
    const NOTICE_KEY     = 'wsm_upgrade_notice';
    const INFO_KEY       = 'wsm_update_info';
    const BACKUP_DIR     = 'wsm-backups';
    const BACKUP_KEEP    = 5;

    public static function init() {
        // اولویت ۱: مهاجرت باید قبل از دروازهٔ تأیید ۲مرحله‌ای (اولویت ۲) اجرا شود، وگرنه صفحات wsm مهاجرت را مسدود می‌کنند
        add_action('admin_init', array(__CLASS__, 'maybe_upgrade'), 1);
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'inject_update'));
        add_filter('auto_update_plugin', array(__CLASS__, 'allow_auto_update'), 10, 2);
    }

    /* ---------------- نسخهٔ نصب‌شده ---------------- */

    public static function stored_version() {
        $v = get_option(self::VERSION_OPTION, '');
        if (is_string($v) && $v !== '') {
            return $v;
        }
        // نصب‌هایی که قبل از این سیستم فعال شده‌اند (جدول‌ها هست، wsm_version نیست) نسخهٔ ۱٫۰٫۰ هستند.
        if (get_option('wsm_db_version') !== false) {
            return '1.0.0';
        }
        return WSM_VERSION;
    }

    /**
     * فهرست مهاجرت‌ها. افزونه‌های دیگر می‌توانند با فیلتر wsm_migrations مهاجرت اضافه کنند.
     */
    public static function migrations() {
        $list = array(
            '1.1.0' => array(__CLASS__, 'migrate_110'),
            '1.2.0' => array(__CLASS__, 'migrate_120'),
            '1.3.0' => array(__CLASS__, 'migrate_130'),
        );
        return apply_filters('wsm_migrations', $list);
    }

    /**
     * مهاجرت‌هایی که باید از $from تا $to اجرا شوند، به ترتیب نسخه.
     */
    public static function pending($from, $to) {
        $out = array();
        foreach (self::migrations() as $version => $callback) {
            if (version_compare($version, $from, '>') && version_compare($version, $to, '<=')) {
                $out[$version] = $callback;
            }
        }
        uksort($out, 'version_compare');
        return $out;
    }

    /* ---------------- اجرای به‌روزرسانی ---------------- */

    public static function maybe_upgrade() {
        $from = self::stored_version();
        $to   = WSM_VERSION;

        if ($from === $to) {
            delete_option(self::FAILED_OPTION);
            return;
        }

        if (version_compare($from, $to, '>')) {
            set_transient(self::NOTICE_KEY, array(
                'type'    => 'warning',
                'message' => 'نسخهٔ دیتابیس (' . $from . ') از نسخهٔ افزونه (' . $to . ') جدیدتر است. افزونه را به نسخهٔ جدیدتر به‌روز کنید.',
            ), 60);
            return;
        }

        // اگر مهاجرت برای همین نسخهٔ مقصد قبلاً شکست خورده، تا تغییر نسخهٔ افزونه دوباره تلاش نمی‌شود.
        if (get_option(self::FAILED_OPTION) === $to) {
            return;
        }

        self::run_migrations($from, $to);
    }

    public static function run_migrations($from, $to) {
        WSM_Core::create_tables(); // شامل جدول wsm_migration_log

        $pending = self::pending($from, $to);

        $backup = self::backup($from, $to);
        if (is_wp_error($backup)) {
            self::fail($from, $to, 0, 'بکاپ قبل از به‌روزرسانی ساخته نشد: ' . $backup->get_error_message(), false, '');
            return;
        }

        $log_id = self::log_start($from, $to);
        $error  = '';

        try {
            foreach ($pending as $version => $callback) {
                $result = call_user_func($callback, $from, $to);
                if (is_wp_error($result)) {
                    throw new Exception('مهاجرت ' . $version . ': ' . $result->get_error_message());
                }
                if ($result === false) {
                    throw new Exception('مهاجرت ' . $version . ' ناموفق برگشت.');
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error !== '') {
            $restored = self::restore($backup);
            self::fail($from, $to, $log_id, $error, $restored, $backup);
            return;
        }

        update_option(self::VERSION_OPTION, $to, false);
        delete_option(self::FAILED_OPTION);
        self::log_finish($log_id, 'success', '');
        update_option(self::LAST_OPTION, array(
            'status'       => 'success',
            'version_from' => $from,
            'version_to'   => $to,
            'backup'       => basename($backup),
            'created_at'   => current_time('mysql'),
        ), false);
        self::prune_backups();

        set_transient(self::NOTICE_KEY, array(
            'type'    => 'success',
            'message' => 'مدیر فروشگاه به نسخهٔ ' . $to . ' به‌روز شد. بکاپ: ' . basename($backup),
        ), 60);
    }

    private static function fail($from, $to, $log_id, $message, $restored, $backup_file) {
        update_option(self::VERSION_OPTION, $from, false);
        update_option(self::FAILED_OPTION, $to, false);
        if ($log_id) {
            self::log_finish($log_id, $restored ? 'rolled_back' : 'failed', $message);
        }
        update_option(self::LAST_OPTION, array(
            'status'       => $restored ? 'rolled_back' : 'failed',
            'version_from' => $from,
            'version_to'   => $to,
            'backup'       => is_string($backup_file) ? basename($backup_file) : '',
            'created_at'   => current_time('mysql'),
            'error'        => $message,
        ), false);

        set_transient(self::NOTICE_KEY, array(
            'type'    => 'error',
            'message' => 'به‌روزرسانی مدیر فروشگاه به ' . $to . ' ناموفق بود: ' . $message .
                ($restored ? ' داده‌ها به وضعیت قبل بازگردانده شد.' : ' بازگردانی خودکار ناموفق بود؛ بکاپ را بررسی کنید.'),
        ), 60);

        wp_mail(
            get_option('admin_email'),
            'مدیر فروشگاه: خطا در به‌روزرسانی به ' . $to,
            "به‌روزرسانی از نسخهٔ {$from} به {$to} ناموفق بود.\n\nخطا: {$message}\n\n" .
            ($restored ? 'داده‌ها به وضعیت قبل بازگردانده شد.' : 'بازگردانی خودکار انجام نشد؛ لطفاً بکاپ را بررسی کنید.')
        );
    }

    /* ---------------- مهاجرت‌ها ---------------- */

    /**
     * ۱٫۱٫۰: تنظیمات جدید (آدرس سرور به‌روزرسانی و به‌روزرسانی خودکار) به تنظیمات ذخیره‌شده اضافه می‌شود.
     * مقادیر کاربر دست‌نخورده می‌ماند.
     */
    public static function migrate_110() {
        $saved    = get_option('wsm_settings', array());
        $saved    = is_array($saved) ? $saved : array();
        $defaults = WSM_Util::default_settings();

        $merged = array_merge($defaults, $saved);
        update_option('wsm_settings', $merged, false);

        return true;
    }

    /**
     * ۱٫۲٫۰: جدول‌های امنیتی (محدودیت نرخ، IP مسدود، nonce، لاگ امنیتی) و ستون کلید امضای دستگاه.
     * دستگاه‌های قبلی کلید امضا ندارند؛ بعد از اولین درخواست باید دوباره متصل شوند (کد wsm_reconnect_required).
     */
    public static function migrate_120() {
        global $wpdb;
        WSM_Core::create_tables();

        foreach (array('wsm_auth_attempts', 'wsm_blocked_ips', 'wsm_used_nonces', 'wsm_security_log') as $name) {
            $full  = $wpdb->prefix . $name;
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $full));
            if ($found !== $full) {
                return new WP_Error('wsm_table_missing', 'جدول ' . $name . ' ساخته نشد.');
            }
        }

        $devices = $wpdb->prefix . 'wsm_devices';
        $column  = $wpdb->get_results("SHOW COLUMNS FROM {$devices} LIKE 'signing_key'");
        if (empty($column)) {
            return new WP_Error('wsm_column_missing', 'ستون signing_key در جدول دستگاه‌ها ساخته نشد.');
        }

        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$devices} WHERE (signing_key IS NULL OR signing_key = '') AND status != 'deleted'");
        WSM_Security::log('migration_120', 'info', 0, 'ارتقا به ۱٫۲٫۰؛ دستگاه‌های نیازمند اتصال مجدد: ' . $pending);

        return true;
    }

    /**
     * ۱٫۳٫۰: ستون مجوزهای مستقل دستگاه‌ها. داده‌های دستگاه‌های قدیمی NULL می‌مانند
     * تا دسترسی پیشین آن‌ها حفظ شود؛ اتصال‌های جدید صریحاً فقط‌خواندنی ساخته می‌شوند.
     */
    public static function migrate_130() {
        global $wpdb;
        WSM_Core::create_tables();
        $devices = $wpdb->prefix . 'wsm_devices';
        $column = $wpdb->get_results("SHOW COLUMNS FROM {$devices} LIKE 'permissions'");
        if (empty($column)) {
            return new WP_Error('wsm_permissions_column_missing', 'ستون permissions در جدول دستگاه‌ها ساخته نشد.');
        }
        WSM_Security::log('migration_130', 'info', 0, 'ارتقا به ۱٫۳٫۰؛ مجوز دستگاه‌های قدیمی حفظ و دستگاه‌های تازه فقط‌خواندنی می‌شوند.');
        return true;
    }

    /* ---------------- جدول لاگ ---------------- */

    private static function log_start($from, $to) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . self::LOG_TABLE,
            array(
                'version_from'  => $from,
                'version_to'    => $to,
                'status'        => 'running',
                'error_message' => '',
                'created_at'    => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        return (int) $wpdb->insert_id;
    }

    private static function log_finish($id, $status, $error) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::LOG_TABLE,
            array('status' => $status, 'error_message' => (string) $error),
            array('id' => (int) $id),
            array('%s', '%s'),
            array('%d')
        );
    }

    /* ---------------- بکاپ و بازگردانی ---------------- */

    /**
     * جداول افزونه و تنظیماتش را به یک فایل SQL می‌ریزد (یک دستور در هر خط).
     * @return string|WP_Error مسیر فایل یا خطا
     */
    public static function backup($from, $to) {
        global $wpdb;

        if (!WSM_Crypto::available()) {
            return new WP_Error('wsm_backup_crypto', 'برای ساخت بکاپ رمزنگاری‌شده، OpenSSL در PHP باید فعال باشد.');
        }
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return new WP_Error('wsm_backup_dir', $upload['error']);
        }

        $dir = trailingslashit($upload['basedir']) . self::BACKUP_DIR;
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('wsm_backup_dir', 'ساخت پوشهٔ بکاپ ممکن نشد.');
        }
        self::protect_dir($dir);

        $stamp = gmdate('Ymd-His');
        $suffix = wp_generate_password(8, false, false);
        $file  = $dir . '/backup-v' . $from . '-to-v' . $to . '-' . $stamp . '-' . $suffix . '.wsm';
        $fh    = @fopen($file, 'xb');
        if (!$fh) {
            return new WP_Error('wsm_backup_file', 'ساخت فایل بکاپ ممکن نشد.');
        }

        $record_count = 0;
        $write_record = function ($record) use ($fh, &$record_count) {
            $json = wp_json_encode($record, JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                return false;
            }
            $encrypted = WSM_Crypto::encrypt($json);
            if (!is_string($encrypted)) {
                return false;
            }
            $line = $encrypted . "\n";
            if (fwrite($fh, $line) !== strlen($line)) {
                return false;
            }
            $record_count++;
            return true;
        };

        $ok = $write_record(array(
            'type'      => 'header',
            'format'    => 2,
            'from'      => (string) $from,
            'to'        => (string) $to,
            'created_at'=> $stamp,
        ));

        foreach (self::data_tables() as $table) {
            if (!$ok) {
                break;
            }
            $rows = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);
            if ($rows === null) {
                $ok = false;
                break;
            }
            $ok = $write_record(array('type' => 'truncate', 'table' => $table));
            foreach ($rows as $row) {
                if (!$ok || !$write_record(array('type' => 'row', 'table' => $table, 'data' => $row))) {
                    $ok = false;
                    break;
                }
            }
        }

        $opts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('wsm_') . '%'
            ),
            ARRAY_A
        );
        if ($opts === null) {
            $ok = false;
        }
        if ($ok) {
            $ok = $write_record(array('type' => 'delete_options', 'prefix' => 'wsm_'));
        }
        foreach ((array) $opts as $row) {
            if (!$ok || !$write_record(array('type' => 'option', 'data' => $row))) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            // Footer makes an incomplete/truncated backup detectable before restore starts.
            $ok = $write_record(array('type' => 'complete', 'records' => $record_count));
        }

        $flushed = $ok && fflush($fh);
        fclose($fh);
        if (!$flushed) {
            @unlink($file);
            return new WP_Error('wsm_backup_write', 'نوشتن یا رمزنگاری کامل بکاپ انجام نشد.');
        }
        return $file;
    }

    /**
     * فایل رمزنگاری‌شدهٔ بکاپ را بررسی و اجرا می‌کند. بکاپ‌های قدیمی SQL نیز برای سازگاری پذیرفته می‌شوند.
     */
    public static function restore($file) {
        global $wpdb;

        if (!is_string($file) || !is_readable($file)) {
            return false;
        }
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return false;
        }
        $first = fgets($fh);
        if ($first === false) {
            fclose($fh);
            return false;
        }
        $first = trim($first);

        // سازگاری با بکاپ‌های SQL نسخه‌های پیشین.
        if (strpos($first, '-- WSM backup') === 0) {
            fclose($fh);
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                return false;
            }
            $wpdb->hide_errors();
            foreach ($lines as $line) {
                if (strpos($line, '--') === 0) {
                    continue;
                }
                if ($wpdb->query($line) === false) {
                    return false;
                }
            }
            return true;
        }

        if (!WSM_Crypto::available()) {
            fclose($fh);
            return false;
        }
        $header = self::decode_backup_line($first);
        if (!is_array($header) || ($header['type'] ?? '') !== 'header' || (int) ($header['format'] ?? 0) !== 2) {
            fclose($fh);
            return false;
        }

        // Pass 1 validates every authenticated record and the footer before any table is truncated.
        $expected_records = 1;
        $payload_records = 0;
        $footer_seen = false;
        while (($line = fgets($fh)) !== false) {
            $record = self::decode_backup_line(trim($line));
            if (!is_array($record) || $footer_seen) {
                fclose($fh);
                return false;
            }
            $type = isset($record['type']) ? $record['type'] : '';
            if ($type === 'complete') {
                $footer_seen = true;
                if ((int) ($record['records'] ?? -1) !== $expected_records) {
                    fclose($fh);
                    return false;
                }
                continue;
            }
            if (!self::valid_backup_record($record)) {
                fclose($fh);
                return false;
            }
            $expected_records++;
            $payload_records++;
        }
        if (!$footer_seen || $payload_records < 1) {
            fclose($fh);
            return false;
        }

        // Pass 2 applies only validated, whitelisted records.
        rewind($fh);
        fgets($fh); // header
        $wpdb->hide_errors();
        while (($line = fgets($fh)) !== false) {
            $record = self::decode_backup_line(trim($line));
            if (!is_array($record)) {
                fclose($fh);
                return false;
            }
            if (($record['type'] ?? '') === 'complete') {
                fclose($fh);
                return true;
            }
            $result = self::apply_backup_record($record);
            if ($result === false) {
                fclose($fh);
                return false;
            }
        }
        fclose($fh);
        return false;
    }

    private static function decode_backup_line($line) {
        $json = WSM_Crypto::decrypt((string) $line);
        if (!is_string($json)) {
            return false;
        }
        $record = json_decode($json, true);
        return is_array($record) ? $record : false;
    }

    private static function valid_backup_record($record) {
        $type = isset($record['type']) ? $record['type'] : '';
        if ($type === 'truncate') {
            return isset($record['table']) && in_array($record['table'], self::data_tables(), true);
        }
        if ($type === 'row') {
            return isset($record['table'], $record['data'])
                && in_array($record['table'], self::data_tables(), true)
                && is_array($record['data']);
        }
        if ($type === 'delete_options') {
            return isset($record['prefix']) && $record['prefix'] === 'wsm_';
        }
        if ($type === 'option') {
            return isset($record['data']) && is_array($record['data'])
                && !empty($record['data']['option_name'])
                && strpos((string) $record['data']['option_name'], 'wsm_') === 0
                && array_key_exists('option_value', $record['data'])
                && array_key_exists('autoload', $record['data']);
        }
        return false;
    }

    private static function apply_backup_record($record) {
        global $wpdb;
        $type = isset($record['type']) ? $record['type'] : '';
        if ($type === 'truncate') {
            $table = $record['table'];
            return $wpdb->query("TRUNCATE TABLE `{$table}`") !== false;
        }
        if ($type === 'row') {
            return $wpdb->insert($record['table'], $record['data']) !== false;
        }
        if ($type === 'delete_options') {
            $like = $wpdb->esc_like($record['prefix']) . '%';
            return $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like)) !== false;
        }
        if ($type === 'option') {
            return $wpdb->replace($wpdb->options, $record['data']) !== false;
        }
        return false;
    }

    private static function data_tables() {
        global $wpdb;
        return array(
            $wpdb->prefix . 'wsm_devices',
            $wpdb->prefix . 'wsm_activity_log',
            $wpdb->prefix . 'wsm_access_keys',
            $wpdb->prefix . 'wsm_auth_attempts',
            $wpdb->prefix . 'wsm_blocked_ips',
            $wpdb->prefix . 'wsm_used_nonces',
            $wpdb->prefix . 'wsm_security_log',
        );
    }

    private static function protect_dir($dir) {
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        $web_config = $dir . '/web.config';
        if (!file_exists($web_config)) {
            file_put_contents($web_config, '<configuration><system.webServer><security><authorization><deny users="*" /></authorization></security></system.webServer></configuration>');
        }
    }

    private static function prune_backups() {
        $upload = wp_upload_dir();
        $dir    = trailingslashit($upload['basedir']) . self::BACKUP_DIR;
        $files  = array_merge((array) glob($dir . '/backup-v*.wsm'), (array) glob($dir . '/backup-v*.sql'));
        if (!is_array($files) || count($files) <= self::BACKUP_KEEP) {
            return;
        }
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        foreach (array_slice($files, self::BACKUP_KEEP) as $old) {
            @unlink($old);
        }
    }

    /* ---------------- اعلان ---------------- */

    public static function admin_notice() {
        $notice = get_transient(self::NOTICE_KEY);
        if (!$notice || !is_array($notice) || !current_user_can('manage_options')) {
            return;
        }
        $class = 'notice-info';
        if ($notice['type'] === 'success') {
            $class = 'notice-success';
        } elseif ($notice['type'] === 'error') {
            $class = 'notice-error';
        } elseif ($notice['type'] === 'warning') {
            $class = 'notice-warning';
        }
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible" dir="rtl"><p>' .
            esc_html($notice['message']) . '</p></div>';
    }

    /* ---------------- بررسی نسخه از سرور شخصی ---------------- */

    /**
     * آخرین نتیجهٔ مهاجرت (بدون مسیر کامل فایل) به‌همراه وجود فایل بکاپ روی دیسک.
     */
    public static function last_migration() {
        $last = get_option(self::LAST_OPTION, null);
        if (!is_array($last)) {
            return null;
        }
        global $wpdb;
        $last['log_rows']      = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . self::LOG_TABLE);
        $last['backup_exists'] = false;
        if (!empty($last['backup'])) {
            $upload = wp_upload_dir();
            $path   = trailingslashit($upload['basedir']) . self::BACKUP_DIR . '/' . basename($last['backup']);
            $last['backup_exists'] = file_exists($path);
        }
        return $last;
    }

    /**
     * اطلاعات نسخهٔ جدید از آدرس تنظیم‌شده (JSON با version، package، changelog).
     * نتیجه ۱۲ ساعت کش می‌شود.
     */
    /**
     * آخرین نسخه را از سرویس HTTPS تنظیم‌شده می‌خواند. پاسخ سرور JSON شامل
     * version، package و اختیاری changelog، tested و requires_php است.
     */
    public static function remote_info($force = false) {
        $settings = WSM_Util::settings();
        $url      = trim((string) $settings['update_server_url']);
        $info     = array(
            'current'          => WSM_VERSION,
            'latest'           => null,
            'update_available' => false,
            'package'          => '',
            'changelog'        => '',
            'error'            => '',
        );

        if ($url === '') {
            return $info;
        }
        if (!$force) {
            $cached = get_transient(self::INFO_KEY);
            if (is_array($cached) && isset($cached['checked_at']) && (time() - (int) $cached['checked_at']) < 12 * HOUR_IN_SECONDS) {
                unset($cached['checked_at']);
                return array_merge($info, $cached);
            }
        }
        if (!self::is_https_url($url)) {
            $info['error'] = 'نشانی سرور به‌روزرسانی باید یک URL معتبر HTTPS باشد.';
            set_transient(self::INFO_KEY, array_merge($info, array('checked_at' => time())), 5 * MINUTE_IN_SECONDS);
            return $info;
        }

        $response = wp_safe_remote_get($url, array(
            'timeout'     => 10,
            'redirection' => 3,
            'headers'     => array('Accept' => 'application/json'),
            'user-agent'  => 'WP-Store-Manager/' . WSM_VERSION . '; ' . home_url('/'),
        ));
        if (is_wp_error($response)) {
            $info['error'] = 'اتصال به سرور نسخه‌ها ناموفق بود.';
            set_transient(self::INFO_KEY, array_merge($info, array('checked_at' => time())), 5 * MINUTE_IN_SECONDS);
            return $info;
        }
        $http = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($http !== 200 || !is_array($data)) {
            $info['error'] = 'پاسخ سرور نسخه‌ها معتبر نیست (HTTP ' . $http . ').';
            set_transient(self::INFO_KEY, array_merge($info, array('checked_at' => time())), 5 * MINUTE_IN_SECONDS);
            return $info;
        }

        $version = isset($data['version']) ? sanitize_text_field((string) $data['version']) : '';
        if ($version === '' && isset($data['latest'])) {
            $version = sanitize_text_field((string) $data['latest']);
        }
        if (!preg_match('/^[0-9]+(?:\.[0-9A-Za-z-]+)*$/', $version)) {
            $info['error'] = 'شمارهٔ نسخه در پاسخ سرور معتبر نیست.';
            set_transient(self::INFO_KEY, array_merge($info, array('checked_at' => time())), 5 * MINUTE_IN_SECONDS);
            return $info;
        }

        $package = isset($data['package']) ? esc_url_raw((string) $data['package']) : '';
        if ($package !== '' && !self::is_https_url($package)) {
            $package = '';
        }
        $info['latest']           = $version;
        $info['package']          = $package;
        $info['changelog']        = isset($data['changelog']) ? wp_kses_post((string) $data['changelog']) : '';
        $info['tested']           = isset($data['tested']) ? sanitize_text_field((string) $data['tested']) : '';
        $info['requires_php']     = isset($data['requires_php']) ? sanitize_text_field((string) $data['requires_php']) : '7.4';
        $info['update_available'] = version_compare($version, WSM_VERSION, '>') && $package !== '';
        if (version_compare($version, WSM_VERSION, '>') && $package === '') {
            $info['error'] = 'نسخهٔ جدید اعلام شده اما نشانی ZIP امن (HTTPS) موجود نیست.';
        }

        set_transient(self::INFO_KEY, array_merge($info, array('checked_at' => time())), 12 * HOUR_IN_SECONDS);
        return $info;
    }

    /** ثبت اطلاعات به‌روزرسانی در transient استاندارد وردپرس */
    public static function inject_update($transient) {
        if (!is_object($transient) || empty($transient->checked) || !is_array($transient->checked)) {
            return $transient;
        }
        $info = self::remote_info(false);
        if (empty($info['update_available']) || empty($info['package'])) {
            return $transient;
        }
        $plugin_file = plugin_basename(WSM_PLUGIN_FILE);
        if (!isset($transient->checked[$plugin_file])) {
            return $transient;
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }
        $transient->response[$plugin_file] = (object) array(
            'slug'        => 'wp-store-manager',
            'plugin'      => $plugin_file,
            'new_version' => $info['latest'],
            'url'         => plugin_dir_url(WSM_PLUGIN_FILE),
            'package'     => $info['package'],
            'tested'      => isset($info['tested']) ? $info['tested'] : '',
            'requires_php'=> isset($info['requires_php']) ? $info['requires_php'] : '7.4',
            'sections'    => array('changelog' => $info['changelog']),
        );
        return $transient;
    }

    /** فعال‌سازی نصب خودکار فقط برای همین افزونه و تنها با رضایت صریح مدیر */
    public static function allow_auto_update($update, $item) {
        $settings = WSM_Util::settings();
        if (empty($settings['auto_update']) || !is_object($item) || empty($item->plugin)) {
            return $update;
        }
        return $item->plugin === plugin_basename(WSM_PLUGIN_FILE) ? true : $update;
    }

    private static function is_https_url($url) {
        if (!is_string($url) || $url === '' || !function_exists('wp_http_validate_url') || !wp_http_validate_url($url)) {
            return false;
        }
        $parts = wp_parse_url($url);
        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            && strtolower($parts['scheme']) === 'https';
    }
}
