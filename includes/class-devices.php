<?php
/**
 * مدیریت دستگاه‌های متصل: ثبت، توکن، وضعیت (فعال / قطع / مسدود / حذف)
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Devices {

    const STATUSES = array('pending', 'active', 'unlinked', 'blocked', 'deleted');

    /** @var bool */
    private static $schema_checked = false;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wsm_devices';
    }


    /** Add auth-session columns to the existing devices table only. */
    public static function ensure_schema() {
        global $wpdb;
        if (self::$schema_checked) { return true; }
        $table = self::table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) { return false; }
        $columns = (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        $needed = array(
            'device_fingerprint' => "ALTER TABLE {$table} ADD COLUMN device_fingerprint varchar(64) NOT NULL DEFAULT ''",
            'remember_me' => "ALTER TABLE {$table} ADD COLUMN remember_me tinyint(1) NOT NULL DEFAULT 1",
            'refresh_expires' => "ALTER TABLE {$table} ADD COLUMN refresh_expires datetime NULL DEFAULT NULL",
        );
        foreach ($needed as $column => $sql) {
            if (!in_array($column, $columns, true)) { $wpdb->query($sql); }
        }
        $columns = (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        foreach (array_keys($needed) as $column) { if (!in_array($column, $columns, true)) { return false; } }
        if (!self::ensure_multi_device_index($table)) { return false; }
        self::ensure_fingerprint_lookup_index($table);
        // A one-time, conservative repair for legacy rows with the same stable identity.
        // IP/UA alone is deliberately excluded: NAT and shared browser profiles can collide.
        self::cleanup_duplicate_devices_once($table);
        self::$schema_checked = true;
        return true;
    }

    /** Keep the newest row for exact, stable identity duplicates during the first post-update schema check. */
    private static function cleanup_duplicate_devices_once($table) {
        global $wpdb;
        $option = 'wsm_devices_dedupe_v1';
        if ((int) get_option($option, 0) >= 1) { return true; }

        $lock_name = 'wsm_device_cleanup_' . substr(hash('sha256', (string) $table), 0, 40);
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 2));
        if ((string) $locked !== '1') { return false; }

        try {
            if ((int) get_option($option, 0) >= 1) { return true; }
            foreach (array('device_fingerprint', 'token_hash', 'refresh_hash') as $column) {
                $newer_time = "GREATEST(COALESCE(newer.last_seen, '1000-01-01 00:00:00'), newer.updated_at, newer.created_at)";
                $older_time = "GREATEST(COALESCE(older.last_seen, '1000-01-01 00:00:00'), older.updated_at, older.created_at)";
                $sql = "DELETE older FROM {$table} AS older INNER JOIN {$table} AS newer
                    ON older.user_id = newer.user_id
                    AND older.{$column} <> ''
                    AND older.{$column} = newer.{$column}
                    AND ({$newer_time} > {$older_time} OR ({$newer_time} = {$older_time} AND newer.id > older.id))";
                if ($wpdb->query($sql) === false) { return false; }
            }
            update_option($option, 1, false);
            return true;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /** Remove a legacy single-user unique index so accounts can hold many device sessions. */
    private static function ensure_multi_device_index($table) {
        global $wpdb;
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}");
        if (!is_array($indexes)) { return false; }
        $keys = array();
        foreach ($indexes as $index) {
            $row = is_object($index) ? get_object_vars($index) : (array) $index;
            $name = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            $column = isset($row['Column_name']) ? (string) $row['Column_name'] : '';
            $sequence = isset($row['Seq_in_index']) ? (int) $row['Seq_in_index'] : 0;
            $non_unique = isset($row['Non_unique']) ? (int) $row['Non_unique'] : 1;
            if ($name === '' || $column === '' || $sequence < 1) { continue; }
            if (!isset($keys[$name])) { $keys[$name] = array('non_unique' => $non_unique, 'columns' => array()); }
            $keys[$name]['columns'][$sequence] = $column;
        }
        foreach ($keys as $name => $index) {
            ksort($index['columns']);
            if ((int) $index['non_unique'] !== 0 || array_values($index['columns']) !== array('user_id')) { continue; }
            if ($name === 'PRIMARY') { return false; }
            $quoted_name = chr(96) . str_replace(chr(96), chr(96) . chr(96), (string) $name) . chr(96);
            if ($wpdb->query("ALTER TABLE {$table} DROP INDEX {$quoted_name}") === false) { return false; }
        }
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}");
        if (!is_array($indexes)) { return false; }
        $keys = array();
        foreach ($indexes as $index) {
            $row = is_object($index) ? get_object_vars($index) : (array) $index;
            $name = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            $column = isset($row['Column_name']) ? (string) $row['Column_name'] : '';
            $sequence = isset($row['Seq_in_index']) ? (int) $row['Seq_in_index'] : 0;
            $non_unique = isset($row['Non_unique']) ? (int) $row['Non_unique'] : 1;
            if ($name === '' || $column === '' || $sequence < 1) { continue; }
            if (!isset($keys[$name])) { $keys[$name] = array('non_unique' => $non_unique, 'columns' => array()); }
            $keys[$name]['columns'][$sequence] = $column;
        }
        foreach ($keys as $index) {
            ksort($index['columns']);
            if ((int) $index['non_unique'] === 0 && array_values($index['columns']) === array('user_id')) { return false; }
        }
        return true;
    }

    /** Add a non-unique lookup index for per-user device fingerprints when absent. */
    private static function ensure_fingerprint_lookup_index($table) {
        global $wpdb;
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}");
        if (!is_array($indexes)) { return false; }
        $keys = array();
        foreach ($indexes as $index) {
            $row = is_object($index) ? get_object_vars($index) : (array) $index;
            $name = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            $column = isset($row['Column_name']) ? (string) $row['Column_name'] : '';
            $sequence = isset($row['Seq_in_index']) ? (int) $row['Seq_in_index'] : 0;
            $non_unique = isset($row['Non_unique']) ? (int) $row['Non_unique'] : 1;
            if ($name === '' || $column === '' || $sequence < 1) { continue; }
            if (!isset($keys[$name])) { $keys[$name] = array('non_unique' => $non_unique, 'columns' => array()); }
            $keys[$name]['columns'][$sequence] = $column;
        }
        foreach ($keys as $index) {
            ksort($index['columns']);
            if (array_values($index['columns']) === array('user_id', 'device_fingerprint')) { return true; }
        }
        if ($wpdb->query("ALTER TABLE {$table} ADD KEY wsm_user_device_fingerprint (user_id, device_fingerprint)") === false) { return false; }
        return true;
    }

    public static function find($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", (int) $id));
    }

    public static function find_by_token_hash($hash) {
        global $wpdb;
        if (!$hash) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE token_hash = %s", $hash));
    }

    public static function find_by_refresh_hash($hash) {
        global $wpdb;
        if (!$hash) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE refresh_hash = %s", $hash));
    }


    /** Find the latest non-deleted device for an account/browser fingerprint. */
    public static function find_by_fingerprint($user_id, $fingerprint) {
        global $wpdb;
        if ((int) $user_id < 1 || !is_string($fingerprint) || !preg_match('/^[a-f0-9]{64}$/', $fingerprint) || !self::ensure_schema()) { return null; }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d AND device_fingerprint = %s AND status <> 'deleted' ORDER BY id DESC LIMIT 1",
            (int) $user_id, $fingerprint
        ));
    }

    /**
     * Activate a previously pending or unlinked device after the employee proves
     * their account credentials. This method is intentionally not exposed by REST.
     * @return object|WP_Error
     */
    public static function activate_after_login($id) {
        global $wpdb;
        if (!self::ensure_schema()) { return new WP_Error('wsm_device_schema_unavailable', 'ساختار جدول دستگاه‌ها آماده نیست.', array('status' => 500)); }
        $id = (int) $id;
        $device = self::find($id);
        if (!$device) { return new WP_Error('wsm_device_missing', 'دستگاه پیدا نشد.', array('status' => 404)); }
        if ($device->status === 'blocked') { return new WP_Error('wsm_device_blocked', 'این دستگاه مسدود است؛ با مدیر تماس بگیرید.', array('status' => 403)); }
        if ($device->status === 'active') { return $device; }
        if (!in_array($device->status, array('pending', 'unlinked'), true)) {
            return new WP_Error('wsm_device_unavailable', 'این دستگاه قابل استفاده نیست.', array('status' => 403));
        }
        $updated = $wpdb->update(self::table(), array(
            'status' => 'active',
            'block_reason' => '',
            'token_hash' => '',
            'refresh_hash' => '',
            'token_expires' => null,
            'refresh_expires' => null,
            'remember_me' => 0,
            'signing_key' => '',
            'updated_at' => WSM_Util::now(),
        ), array('id' => $id));
        if ($updated === false) { return new WP_Error('wsm_device_update_failed', 'فعال‌سازی خودکار دستگاه انجام نشد.', array('status' => 500)); }
        WSM_Activity_Log::log('auth', 'device', $id, $device->device_name, $device->status, 'active', 'success', $id, (int) $device->user_id);
        $fresh = self::find($id);
        return $fresh ?: new WP_Error('wsm_device_missing', 'دستگاه پس از فعال‌سازی پیدا نشد.', array('status' => 404));
    }

    /**
     * Register a new device, or refresh the existing row when an authenticated stable fingerprint matches.
     * Labels and IP addresses are metadata, never identity or permission-inheritance keys.
     * @return object|WP_Error
     */
    public static function register($user_id, $info, $permissions = null, $status = 'active', $fingerprint = '') {
        global $wpdb;
        if (!self::ensure_schema()) { return new WP_Error('wsm_device_schema_unavailable', 'ساختار جدول دستگاه‌ها آماده نیست.', array('status' => 500)); }
        $status = in_array($status, array('active', 'pending'), true) ? $status : 'pending';
        $fingerprint = is_string($fingerprint) && preg_match('/^[a-f0-9]{64}$/', $fingerprint) ? $fingerprint : '';
        $name     = WSM_Util::clean_text(isset($info['device_name']) ? $info['device_name'] : 'دستگاه ناشناس', 100);
        $platform = WSM_Util::clean_text(isset($info['platform']) ? $info['platform'] : '', 40);
        $os       = WSM_Util::clean_text(isset($info['os_version']) ? $info['os_version'] : '', 40);
        $app_ver  = WSM_Util::clean_text(isset($info['app_version']) ? $info['app_version'] : '', 20);
        $now      = WSM_Util::now();
        $ip       = WSM_Util::client_ip();
        if ($permissions === null) {
            $permissions = self::default_permissions();
        } else {
            $catalog = self::permission_catalog();
            $permissions = array_values(array_unique(array_filter((array) $permissions, function ($permission) use ($catalog) {
                return is_string($permission) && isset($catalog[$permission]);
            })));
        }

        // Serialize registration for the same authenticated identity. This closes the
        // lookup-then-insert race where two simultaneous logins could create two rows.
        $lock_name = '';
        if ($fingerprint !== '') {
            $lock_name = 'wsm_device_' . substr(hash('sha256', $wpdb->prefix . ':' . (int) $user_id . ':' . $fingerprint), 0, 48);
            $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
            if ((string) $locked !== '1') {
                return new WP_Error('wsm_device_identity_busy', 'ثبت هم‌زمان دستگاه انجام نشد؛ چند ثانیه بعد دوباره تلاش کنید.', array('status' => 503));
            }
        }

        try {
            if ($fingerprint !== '') {
                $existing = self::find_by_fingerprint((int) $user_id, $fingerprint);
                if ($existing) {
                    $updated = $wpdb->update(self::table(), array(
                        'device_name' => $name,
                        'platform' => $platform,
                        'os_version' => $os,
                        'app_version' => $app_ver,
                        'ip_address' => $ip,
                        'last_seen' => $now,
                        'updated_at' => $now,
                    ), array('id' => (int) $existing->id));
                    if ($updated === false) {
                        return new WP_Error('wsm_device_update_failed', 'به‌روزرسانی آخرین اتصال دستگاه انجام نشد.', array('status' => 500));
                    }
                    $device = self::find((int) $existing->id);
                    return $device ?: new WP_Error('wsm_device_missing', 'دستگاه موجود پس از به‌روزرسانی پیدا نشد.', array('status' => 404));
                }
            }

            $inserted = $wpdb->insert(self::table(), array(
                'user_id'     => (int) $user_id,
                'device_fingerprint' => $fingerprint,
                'remember_me' => 0,
                'refresh_expires' => null,
                'device_name' => $name,
                'platform'    => $platform,
                'os_version'  => $os,
                'app_version' => $app_ver,
                'ip_address'  => $ip,
                'permissions' => wp_json_encode($permissions),
                'status'      => $status,
                'last_seen'   => $now,
                'created_at'  => $now,
                'updated_at'  => $now,
            ));
            if ($inserted === false || (int) $wpdb->insert_id < 1) {
                return new WP_Error('wsm_device_create_failed', 'ثبت دستگاه در پایگاه داده انجام نشد.', array('status' => 500));
            }
            $device = self::find((int) $wpdb->insert_id);
            return $device ?: new WP_Error('wsm_device_create_failed', 'دستگاه ثبت شد اما قابل بازیابی نیست.', array('status' => 500));
        } finally {
            if ($lock_name !== '') {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            }
        }
    }

    /** Add an administrator-created device record to the existing device table (without a login session). */
    public static function register_manual($user_id, $info, $ip_address, $permissions = null) {
        global $wpdb;
        if (!self::ensure_schema()) { return new WP_Error('wsm_device_schema_unavailable', 'ساختار دستگاه‌ها آماده نیست.', array('status' => 500)); }
        $user_id = (int) $user_id;
        if ($user_id < 1 || !get_userdata($user_id)) { return new WP_Error('wsm_device_user_invalid', 'کاربر دستگاه معتبر نیست.', array('status' => 400)); }
        $ip_address = trim((string) $ip_address);
        if ($ip_address !== '' && !filter_var($ip_address, FILTER_VALIDATE_IP)) { return new WP_Error('wsm_device_ip_invalid', 'نشانی IP معتبر نیست.', array('status' => 400)); }
        $name = WSM_Util::clean_text(isset($info['device_name']) ? $info['device_name'] : 'دستگاه دستی', 100);
        $platform = WSM_Util::clean_text(isset($info['platform']) ? $info['platform'] : 'Manual', 40);
        $os = WSM_Util::clean_text(isset($info['os_version']) ? $info['os_version'] : '', 40);
        $app_version = WSM_Util::clean_text(isset($info['app_version']) ? $info['app_version'] : 'manual', 20);
        if ($permissions === null) { $permissions = class_exists('WSM_Permissions') ? WSM_Permissions::user_permissions($user_id) : self::default_permissions(); }
        if ($permissions === null) { $permissions = self::default_permissions(); }
        $catalog = self::permission_catalog();
        $clean = array();
        foreach ((array) $permissions as $permission) { if (is_string($permission) && isset($catalog[$permission]) && !in_array($permission, $clean, true)) { $clean[] = $permission; } }
        $now = WSM_Util::now();
        $inserted = $wpdb->insert(self::table(), array(
            'user_id' => $user_id, 'device_fingerprint' => '', 'remember_me' => 0, 'refresh_expires' => null,
            'device_name' => $name, 'platform' => $platform, 'os_version' => $os, 'app_version' => $app_version,
            'ip_address' => $ip_address, 'permissions' => wp_json_encode($clean), 'status' => 'unlinked',
            'token_hash' => '', 'refresh_hash' => '', 'token_expires' => null, 'signing_key' => '', 'block_reason' => '',
            'last_seen' => null, 'created_at' => $now, 'updated_at' => $now,
        ));
        if ($inserted === false || (int) $wpdb->insert_id < 1) { return new WP_Error('wsm_device_create_failed', 'ثبت دستگاه در پایگاه داده انجام نشد.', array('status' => 500)); }
        $device = self::find((int) $wpdb->insert_id);
        if (!$device) { return new WP_Error('wsm_device_create_failed', 'دستگاه ثبت شد اما قابل بازیابی نیست.', array('status' => 500)); }
        WSM_Activity_Log::log('create', 'device', (int) $device->id, $name, '', 'ثبت دستی', 'success', (int) $device->id, $user_id);
        return $device;
    }

    /** Rename a device using its existing row and write an audit event. */
    public static function rename_device($id, $name) {
        global $wpdb;
        $device = self::find((int) $id);
        if (!$device || $device->status === 'deleted') { return new WP_Error('wsm_not_found', 'دستگاه پیدا نشد.', array('status' => 404)); }
        $name = WSM_Util::clean_text($name, 100);
        if ($name === '') { return new WP_Error('wsm_device_name_invalid', 'نام دستگاه الزامی است.', array('status' => 400)); }
        $updated = $wpdb->update(self::table(), array('device_name' => $name, 'updated_at' => WSM_Util::now()), array('id' => (int) $id));
        if ($updated === false) { return new WP_Error('wsm_device_update_failed', 'ویرایش نام دستگاه انجام نشد.', array('status' => 500)); }
        WSM_Activity_Log::log('update', 'device', (int) $id, $name, $device->device_name, $name, 'success', (int) $id, (int) $device->user_id);
        return self::find((int) $id);
    }

    public static function save_tokens($id, $token_hash, $refresh_hash, $expires, $remember = null, $refresh_expires = null) {
        global $wpdb;
        if (!self::ensure_schema()) { return false; }
        $data = array('token_hash'=>$token_hash,'refresh_hash'=>$refresh_hash,'token_expires'=>$expires,'updated_at'=>WSM_Util::now());
        if ($remember !== null) { $data['remember_me'] = $remember ? 1 : 0; }
        if (func_num_args() >= 6) { $data['refresh_expires'] = $refresh_expires; }
        return $wpdb->update(self::table(), $data, array('id' => (int) $id)) !== false;
    }

    /**
     * ذخیرهٔ کلید امضای دستگاه به‌صورت رمزنگاری‌شده
     * @return bool
     */
    public static function save_signing_key($id, $plain_key) {
        global $wpdb;
        $enc = WSM_Crypto::encrypt($plain_key);
        if ($enc === false) {
            return false;
        }
        $result = $wpdb->update(self::table(), array(
            'signing_key' => $enc,
            'updated_at'  => WSM_Util::now(),
        ), array('id' => (int) $id));
        return $result !== false;
    }

    /**
     * کلید امضای دستگاه (رمزگشایی‌شده) یا false اگر دستگاه کلید ندارد (مثلاً قبل از ارتقای ۱٫۲٫۰)
     * @return string|false
     */
    public static function signing_key($device) {
        if (!$device || empty($device->signing_key)) {
            return false;
        }
        return WSM_Crypto::decrypt($device->signing_key);
    }

    public static function touch($id, $ip) {
        global $wpdb;
        $now = WSM_Util::now();
        // هر ۶۰ ثانیه یک‌بار به‌روزرسانی می‌شود تا بار دیتابیس کم بماند
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET last_seen = %s, ip_address = %s WHERE id = %d AND (last_seen IS NULL OR last_seen < %s)",
            $now, $ip, (int) $id, date('Y-m-d H:i:s', current_time('timestamp') - 60)
        ));
    }

    /**
     * عملیات مدیریتی: block | unblock | unlink | delete
     */
    public static function set_status($id, $action, $reason = '') {
        global $wpdb;
        if (!self::ensure_schema()) { return new WP_Error('wsm_device_schema_unavailable', 'ساختار جدول دستگاه‌ها آماده نیست.', array('status' => 500)); }
        $device = self::find($id);
        if (!$device) {
            return new WP_Error('wsm_not_found', 'دستگاه پیدا نشد.', array('status' => 404));
        }
        $now  = WSM_Util::now();
        $data = array('updated_at' => $now);

        switch ($action) {
            case 'activate':
                if (!in_array($device->status, array('pending', 'unlinked'), true)) { return new WP_Error('wsm_device_not_activatable', 'فقط دستگاه در انتظار یا قطع‌شده را می‌توان فعال کرد.', array('status' => 409)); }
                $data += array('status' => 'active', 'block_reason' => '', 'token_hash' => '', 'refresh_hash' => '', 'token_expires' => null, 'refresh_expires' => null, 'remember_me' => 0, 'signing_key' => '');
                break;
            case 'request_approval':
                if ($device->status !== 'unlinked') { return new WP_Error('wsm_device_not_unlinked', 'این دستگاه برای درخواست اتصال مجدد آماده نیست.', array('status' => 409)); }
                $data += array('status' => 'pending', 'token_hash' => '', 'refresh_hash' => '', 'token_expires' => null, 'refresh_expires' => null, 'remember_me' => 0, 'signing_key' => '');
                break;
            case 'approve':
                if ($device->status !== 'pending') { return new WP_Error('wsm_device_not_pending', 'فقط دستگاه در انتظار را می‌توان تأیید کرد.', array('status' => 409)); }
                $data += array('status' => 'active', 'block_reason' => '', 'token_hash' => '', 'refresh_hash' => '', 'token_expires' => null, 'refresh_expires' => null, 'remember_me' => 0, 'signing_key' => '');
                break;
            case 'logout':
                if ($device->status !== 'active') { return new WP_Error('wsm_device_not_active', 'دستگاه فعال نیست.', array('status' => 409)); }
                $data += array('token_hash' => '', 'refresh_hash' => '', 'token_expires' => null, 'refresh_expires' => null, 'remember_me' => 0, 'signing_key' => '');
                break;
            case 'block':
                $data += array('status' => 'blocked', 'block_reason' => (string) $reason, 'token_hash' => '', 'refresh_hash' => '', 'token_expires' => null, 'refresh_expires' => null, 'remember_me' => 0, 'signing_key' => '');
                break;
            case 'unblock':
                $data += array('status' => 'unlinked', 'block_reason' => '', 'token_hash' => '', 'refresh_hash' => '', 'token_expires' => null, 'refresh_expires' => null, 'remember_me' => 0, 'signing_key' => '');
                break;
            case 'unlink':
                $data += array('status' => 'unlinked', 'token_hash' => '', 'refresh_hash' => '', 'token_expires' => null, 'refresh_expires' => null, 'remember_me' => 0, 'signing_key' => '');
                break;
            case 'delete':
                $data += array('status' => 'deleted', 'token_hash' => '', 'refresh_hash' => '', 'token_expires' => null, 'refresh_expires' => null, 'remember_me' => 0, 'signing_key' => '');
                break;
            default:
                return new WP_Error('wsm_bad_action', 'عملیات نامعتبر است.', array('status' => 400));
        }

        $updated = $wpdb->update(self::table(), $data, array('id' => (int) $id));
        if ($updated === false) {
            return new WP_Error('wsm_device_update_failed', 'به‌روزرسانی وضعیت دستگاه در پایگاه داده انجام نشد.', array('status' => 500));
        }
        $new_status = isset($data['status']) ? $data['status'] : $device->status;
        WSM_Activity_Log::log('device', 'device', (int) $id, $device->device_name, $device->status, $new_status, 'success', (int) $id);
        return self::find($id);
    }

    /** Apply an allow-listed admin bulk action while preserving per-device audit events. */
    public static function bulk_set_status($device_ids, $operation) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('wsm_bulk_forbidden', 'این عملیات فقط برای مدیرکل وردپرس مجاز است.', array('status' => 403));
        }
        $status_action = array('revoke' => 'unlink', 'delete' => 'delete');
        if (!is_string($operation) || !isset($status_action[$operation])) {
            return new WP_Error('wsm_bulk_bad_action', 'عملیات دسته‌جمعی نامعتبر است.', array('status' => 400));
        }
        if (!is_array($device_ids) || !$device_ids || count($device_ids) > 500) {
            return new WP_Error('wsm_bulk_bad_ids', 'فهرست دستگاه‌ها خالی یا بیش از حد مجاز است.', array('status' => 400));
        }

        $ids = array();
        foreach ($device_ids as $raw_id) {
            if (!is_scalar($raw_id) || !preg_match('/^[0-9]+$/D', (string) $raw_id)) {
                return new WP_Error('wsm_bulk_bad_ids', 'شناسهٔ یکی از دستگاه‌ها معتبر نیست.', array('status' => 400));
            }
            $id = absint($raw_id);
            if ($id < 1) {
                return new WP_Error('wsm_bulk_bad_ids', 'شناسهٔ یکی از دستگاه‌ها معتبر نیست.', array('status' => 400));
            }
            $ids[$id] = $id;
        }
        if (!$ids) {
            return new WP_Error('wsm_bulk_bad_ids', 'هیچ دستگاه معتبری انتخاب نشده است.', array('status' => 400));
        }

        $result = array('requested' => count($ids), 'changed' => 0, 'unchanged' => 0, 'failed' => 0, 'failed_ids' => array());
        foreach ($ids as $id) {
            $device = self::find($id);
            if (!$device) {
                $result['failed']++;
                $result['failed_ids'][] = $id;
                continue;
            }
            if ($device->status === 'deleted' || ($status_action[$operation] === 'unlink' && in_array($device->status, array('unlinked', 'blocked'), true))) {
                $result['unchanged']++;
                continue;
            }
            $updated = self::set_status($id, $status_action[$operation]);
            if (is_wp_error($updated)) {
                $result['failed']++;
                $result['failed_ids'][] = $id;
                continue;
            }
            $result['changed']++;
        }
        return $result;
    }

    public static function count_by_status() {
        global $wpdb;
        self::ensure_schema();
        $counts = array('all' => 0, 'pending' => 0, 'active' => 0, 'unlinked' => 0, 'blocked' => 0, 'deleted' => 0);
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS c FROM " . self::table() . " GROUP BY status");
        foreach ((array) $rows as $row) {
            if (isset($counts[$row->status])) {
                $counts[$row->status] = (int) $row->c;
            }
            if ($row->status !== 'deleted') {
                $counts['all'] += (int) $row->c;
            }
        }
        return $counts;
    }

    /** فهرست مجوزهای ریزدانه، با عنوان فارسی و گروه‌بندی برای پنل مدیریت. */
    public static function permission_groups() {
        return array(
            'داشبورد' => array(
                'dashboard.view' => 'مشاهدهٔ داشبورد',
                'dashboard.sales.today' => 'مشاهدهٔ فروش امروز',
                'dashboard.orders.today' => 'مشاهدهٔ سفارش‌های امروز',
                'dashboard.low_stock' => 'مشاهدهٔ هشدار کمبود موجودی',
                'dashboard.expiring' => 'مشاهدهٔ کالاهای نزدیک انقضا',
                'dashboard.club.summary' => 'مشاهدهٔ خلاصهٔ باشگاه',
            ),
            'محصولات و انبار' => array(
                'products.view' => 'مشاهدهٔ فهرست کالاها و دسته‌بندی‌ها',
                'products.create' => 'ایجاد کالا',
                'products.edit' => 'ویرایش کالا',
                'products.stock.edit' => 'ویرایش موجودی کالا',
                'products.price.edit' => 'ویرایش قیمت کالا',
                'products.image.manage' => 'افزودن و ویرایش تصویر کالا',
                'products.barcode' => 'ساخت و مدیریت بارکد',
                'products.import' => 'ورود گروهی کالاها',
                'products.export' => 'خروجی‌گرفتن از کالاها',
                'products.delete' => 'حذف کالا',
                'products.categories.manage' => 'مدیریت دسته‌بندی کالاها',
            ),
            'سفارش‌ها' => array(
                'orders.view' => 'مشاهدهٔ سفارش‌ها',
                'orders.create' => 'ایجاد فروش یا سفارش دستی',
                'orders.edit' => 'ویرایش اطلاعات سفارش',
                'orders.change_status' => 'تغییر وضعیت سفارش',
                'orders.refund' => 'ثبت بازپرداخت سفارش',
                'orders.delete' => 'حذف سفارش',
                'orders.history' => 'مشاهدهٔ تاریخچهٔ سفارش',
                'orders.customer.view' => 'مشاهدهٔ اطلاعات مشتری سفارش',
                'orders.payment.manage' => 'مدیریت پرداخت سفارش',
                'orders.note.manage' => 'ثبت یادداشت سفارش',
                'orders.export' => 'خروجی‌گرفتن از سفارش‌ها',
            ),
            'فاکتورها' => array(
                'invoices.view' => 'مشاهدهٔ فاکتورها',
                'invoices.create' => 'ایجاد فاکتور',
                'invoices.edit' => 'ویرایش فاکتور',
                'invoices.change_status' => 'تغییر وضعیت فاکتور',
                'invoices.delete' => 'حذف فاکتور',
                'invoices.print' => 'چاپ فاکتور و برچسب پستی',
                'invoices.export' => 'خروجی‌گرفتن از فاکتورها',
                'invoices.convert' => 'تبدیل سفارش به فاکتور',
            ),
            'مشتریان و حساب‌ها' => array(
                'customers.view' => 'مشاهدهٔ مشتریان و دفتر حساب',
                'customers.create' => 'ایجاد مشتری',
                'customers.edit' => 'ویرایش اطلاعات مشتری',
                'customers.delete' => 'حذف مشتری',
                'customers.account.manage' => 'مدیریت حساب و بدهی مشتری',
                'customers.credit.view' => 'مشاهدهٔ اعتبار و ماندهٔ حساب',
                'customers.credit.collect' => 'ثبت و تسویهٔ بدهی مشتری',
                'customers.export' => 'خروجی‌گرفتن از مشتریان',
            ),
            'گزارش‌ها و تحلیل' => array(
                'reports.view' => 'مشاهدهٔ گزارش‌ها',
                'reports.sales' => 'مشاهدهٔ گزارش فروش',
                'reports.stock' => 'مشاهدهٔ گزارش موجودی',
                'reports.customers' => 'مشاهدهٔ گزارش مشتریان',
                'reports.finance' => 'مشاهدهٔ گزارش مالی',
                'reports.export' => 'خروجی‌گرفتن از گزارش‌ها',
                'club.reports.view' => 'مشاهدهٔ گزارش‌های تفصیلی باشگاه',
            ),
            'باشگاه: اعضا' => array(
                'club.members.view' => 'مشاهدهٔ اعضا و پرونده‌ها',
                'club.members.manage' => 'ثبت و ویرایش اعضا',
                'club.members.archive' => 'بایگانی پروندهٔ عضو',
                'club.members.export' => 'خروجی‌گرفتن از فهرست اعضا',
                'club.members.contact.view' => 'مشاهدهٔ اطلاعات تماس اعضا',
                'club.members.private.view' => 'مشاهدهٔ اطلاعات حساس پروندهٔ اعضا',
            ),
            'طرح‌ها و ثبت‌نام' => array(
                'club.plans.view' => 'مشاهدهٔ طرح‌ها و تعرفه‌ها',
                'club.plans.manage' => 'مدیریت طرح‌ها و تعرفه‌ها',
                'club.memberships.view' => 'مشاهدهٔ عضویت‌ها و ثبت‌نام‌ها',
                'club.memberships.manage' => 'ثبت و ویرایش عضویت',
                'club.memberships.freeze' => 'تعلیق عضویت',
                'club.memberships.cancel' => 'لغو عضویت',
                'club.registration.manage' => 'مدیریت فرایند ثبت‌نام',
            ),
            'شهریه و پرداخت‌های باشگاه' => array(
                'club.billing.view' => 'مشاهدهٔ شهریه و دریافتی‌ها',
                'club.billing.manage' => 'ثبت و مدیریت دریافت شهریه',
                'club.payments.view' => 'مشاهدهٔ پرداخت‌های باشگاه',
                'club.payments.create' => 'ثبت پرداخت باشگاه',
                'club.card_balance.view' => 'مشاهدهٔ ماندهٔ کارت اعضا',
                'club.card_balance.recharge' => 'شارژ کارت اعضا',
                'club.receipts.print' => 'چاپ رسید پرداخت باشگاه',
            ),
            'حضور و غیاب' => array(
                'club.attendance.view' => 'مشاهدهٔ گزارش تردد',
                'club.attendance.manage' => 'ثبت ورود و خروج حضوری',
                'club.attendance.checkin' => 'ثبت ورود عضو',
                'club.attendance.checkout' => 'ثبت خروج عضو',
                'club.attendance.correct' => 'اصلاح رخداد تردد',
                'club.attendance.export' => 'خروجی‌گرفتن از ترددها',
            ),
            'کارکنان و حقوق' => array(
                'club.staff.view' => 'مشاهدهٔ کارکنان و مربیان',
                'club.staff.manage' => 'مدیریت کارکنان و مربیان',
                'club.staff.create' => 'ثبت نیروی جدید',
                'club.staff.edit' => 'ویرایش پروندهٔ نیرو',
                'club.staff.delete' => 'بایگانی پروندهٔ نیرو',
                'club.payroll.view' => 'مشاهدهٔ فیش و حقوق',
                'club.payroll.manage' => 'ثبت و مدیریت حقوق',
                'club.payroll.mark_paid' => 'ثبت پرداخت حقوق',
            ),
            'امور مالی و صندوق' => array(
                'club.finance.view' => 'مشاهدهٔ گردش مالی و صندوق',
                'club.finance.manage' => 'مدیریت گردش مالی و صندوق',
                'club.expenses.view' => 'مشاهدهٔ هزینه‌ها',
                'club.expenses.create' => 'ثبت هزینه',
                'club.cash_removal.create' => 'ثبت خروج وجه از صندوق',
                'club.finance.void' => 'ابطال سند مالی با ثبت دلیل',
                'club.finance.export' => 'خروجی‌گرفتن از گردش مالی',
                'club.finance.reconcile' => 'تطبیق و بستن صندوق',
            ),
            'دفتر مجموعه‌ها و راهنما' => array(
                'club.directory.view' => 'مشاهدهٔ دفتر مجموعه‌ها',
                'club.directory.manage' => 'مدیریت مجموعه‌ها، رشته‌ها و دفتر تلفن',
                'club.collections.manage' => 'مدیریت مجموعه‌ها',
                'club.sports.manage' => 'مدیریت رشته‌های ورزشی',
                'club.phonebook.view' => 'مشاهدهٔ دفتر تلفن',
                'club.phonebook.manage' => 'مدیریت دفتر تلفن',
            ),
            'پیامک و ارتباطات' => array(
                'club.sms.view' => 'مشاهدهٔ پیامک‌ها و گزارش ارسال',
                'club.sms.manage' => 'مدیریت پیامک‌های باشگاه',
                'club.sms.send' => 'ارسال پیامک',
                'club.sms.bulk_send' => 'ارسال گروهی پیامک',
                'club.sms.templates.manage' => 'مدیریت قالب پیامک',
                'club.sms.logs.view' => 'مشاهدهٔ گزارش ارسال پیامک',
                'club.sms.recipients.export' => 'خروجی‌گرفتن از مخاطبان پیامک',
            ),
            'فروش حضوری و POS' => array(
                'club.pos.view' => 'مشاهدهٔ صندوق فروش حضوری',
                'club.pos.create' => 'ثبت فروش حضوری',
                'club.pos.discount' => 'اعمال تخفیف حضوری',
                'club.pos.refund' => 'ثبت بازپرداخت حضوری',
                'club.pos.change_payment' => 'تغییر روش پرداخت حضوری',
                'club.pos.reprint' => 'چاپ مجدد رسید فروش',
                'club.pos.quick_access.manage' => 'مدیریت کادرهای دسترسی سریع POS',
                'club.pos.debt' => 'ثبت فروش نسیه',
            ),
            'کاربران و نقش‌ها' => array(
                'users.view' => 'مشاهدهٔ کاربران اپ',
                'users.manage' => 'مدیریت کاربران اپ',
                'users.create' => 'ایجاد حساب کاربری',
                'users.edit' => 'ویرایش حساب کاربری',
                'users.deactivate' => 'غیرفعال‌کردن حساب کاربری',
                'users.delete' => 'حذف نرم حساب کاربری',
                'users.permissions.manage' => 'مدیریت نقش و مجوزهای کاربران',
                'users.super_admin.assign' => 'واگذاری نقش مدیرکل',
                'club.users.manage' => 'مدیریت حساب‌های اپ باشگاه',
            ),
            'دستگاه‌ها' => array(
                'devices.view' => 'مشاهدهٔ دستگاه‌ها',
                'devices.manage' => 'مدیریت دستگاه‌های متصل',
                'devices.approve' => 'تأیید دستگاه تازه',
                'devices.block' => 'مسدودکردن دستگاه',
                'devices.unlink' => 'قطع دسترسی دستگاه',
                'devices.delete' => 'حذف دستگاه',
                'devices.permissions.manage' => 'مدیریت مجوزهای هر دستگاه',
            ),
            'لاگ فعالیت و ممیزی' => array(
                'activity.view' => 'مشاهدهٔ گزارش فعالیت',
                'activity.filter' => 'فیلترکردن گزارش فعالیت',
                'activity.export' => 'خروجی‌گرفتن از گزارش فعالیت',
                'activity.view.all' => 'مشاهدهٔ فعالیت همهٔ کارکنان',
                'activity.view.mine' => 'مشاهدهٔ فعالیت خود',
                'security.audit' => 'مشاهدهٔ رویدادهای ممیزی امنیتی',
            ),
            'تنظیمات و امنیت' => array(
                'settings.view' => 'مشاهدهٔ تنظیمات اپ',
                'settings.edit' => 'ویرایش تنظیمات اپ',
                'settings.general.edit' => 'ویرایش تنظیمات عمومی',
                'settings.notifications.edit' => 'ویرایش تنظیمات اعلان‌ها',
                'settings.security.view' => 'مشاهدهٔ تنظیمات امنیتی',
                'security.manage' => 'مدیریت امنیت و الزام TOTP پنل وردپرس',
                'security.password_policy' => 'مدیریت سیاست گذرواژه',
                'security.sessions.manage' => 'مدیریت نشست‌های ورود',
            ),
            'پل و سخت‌افزار' => array(
                'club.bridge' => 'همگام‌سازی پل سخت‌افزاری باشگاه',
                'bridge.pair' => 'جفت‌سازی پل سخت‌افزاری',
                'bridge.devices.manage' => 'مدیریت دستگاه‌های سخت‌افزاری',
                'bridge.attendance.sync' => 'همگام‌سازی رخدادهای تردد',
                'bridge.accesslist.sync' => 'همگام‌سازی فهرست مجاز دسترسی',
            ),
        );
    }

    public static function permission_catalog() {
        $permissions = array();
        foreach (self::permission_groups() as $group) {
            foreach ($group as $key => $label) {
                $permissions[$key] = $label;
            }
        }
        return $permissions;
    }

    /** حداقل دسترسی خواندن که برای دستگاه تازه صادر می‌شود. */
    public static function default_permissions() {
        return array(
            'dashboard.view',
            'products.view',
            'orders.view',
            'orders.history',
            'invoices.view',
            'invoices.print',
            'customers.view',
            'reports.view',
            'settings.view',
        );
    }

    /**
     * مجوزهای دستگاه. NULL فقط برای دستگاه‌های موجود پیش از نسخهٔ ۱٫۸.۰ است و
     * رفتار قبلی آن‌ها (دسترسی کامل اپ، به‌جز مدیریت TOTP) حفظ می‌شود.
     */
    public static function permissions_for($device) {
        $catalog = self::permission_catalog();
        if ($device && !empty($device->user_id)) {
            $user = get_userdata((int) $device->user_id);
            $record = class_exists('WSM_Permissions') ? WSM_Permissions::user_permissions_record((int) $device->user_id) : null;
            if (($user && user_can($user, 'manage_options')) || ($record !== null && $record['preset'] === 'super_admin') || get_user_meta((int) $device->user_id, 'wsm_app_role', true) === 'super_admin') {
                return array_keys($catalog);
            }
        }
        if (!$device || !property_exists($device, 'permissions') || $device->permissions === null || $device->permissions === '') {
            // New catalog entries must not silently expand old devices' grants.
            $legacy = array('dashboard.view','products.view','products.create','products.edit','products.delete',
                'orders.view','orders.create','orders.edit','orders.change_status','orders.refund','orders.delete','orders.history',
                'invoices.view','invoices.create','invoices.edit','invoices.change_status','invoices.delete','invoices.print',
                'customers.view','reports.view','devices.view','devices.manage','activity.view','settings.view','settings.edit');
            return array_values(array_intersect($legacy, array_keys($catalog)));
        }
        $raw = is_array($device->permissions) ? $device->permissions : json_decode((string) $device->permissions, true);
        if (!is_array($raw)) {
            return self::default_permissions();
        }
        $clean = array();
        foreach ($raw as $key) {
            if (is_string($key) && isset($catalog[$key]) && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }
        return $clean;
    }

    public static function device_can($device, $permission) {
        if (!isset(self::permission_catalog()[$permission])) { return false; }
        if ($permission === 'security.manage') {
            $user_id = $device ? (int) $device->user_id : 0;
            $user = $user_id ? get_userdata($user_id) : false;
            if (!$user || !user_can($user, 'manage_options')) { return false; }
        }
        if (class_exists('WSM_Permissions') && method_exists('WSM_Permissions', 'allows')) {
            return WSM_Permissions::allows($device, $permission);
        }
        return in_array($permission, self::permissions_for($device), true);
    }

    /** Keep each account's explicit grants aligned on its non-bridge devices. */
    public static function sync_user_permissions($user_id, $permissions) {
        global $wpdb;
        if ((int) $user_id < 1 || !self::ensure_schema()) { return false; }
        $catalog = self::permission_catalog();
        $clean = array();
        foreach ((array) $permissions as $permission) {
            if (is_string($permission) && isset($catalog[$permission]) && !in_array($permission, $clean, true)) { $clean[] = $permission; }
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, permissions FROM ' . self::table() . ' WHERE user_id = %d AND status <> %s', (int) $user_id, 'deleted'));
        foreach ((array) $rows as $row) {
            $current = json_decode((string) $row->permissions, true);
            if (is_array($current) && count($current) === 1 && $current[0] === 'club.bridge') { continue; }
            if ($wpdb->update(self::table(), array('permissions' => wp_json_encode($clean), 'updated_at' => WSM_Util::now()), array('id' => (int) $row->id)) === false) { return false; }
        }
        return true;
    }

    /** ذخیرهٔ مجوزها؛ مدیر فروشگاه نمی‌تواند مجوز مدیریت امنیت را تغییر دهد. */
    public static function save_permissions($id, $permissions) {
        global $wpdb;
        $device = self::find($id);
        if (!$device) {
            return new WP_Error('wsm_not_found', 'دستگاه پیدا نشد.', array('status' => 404));
        }
        $catalog = self::permission_catalog();
        $owner = get_userdata((int) $device->user_id);
        $owner_record = class_exists('WSM_Permissions') ? WSM_Permissions::user_permissions_record((int) $device->user_id) : null;
        $owner_super = ($owner && user_can($owner, 'manage_options')) || ($owner_record !== null && $owner_record['preset'] === 'super_admin') || get_user_meta((int) $device->user_id, 'wsm_app_role', true) === 'super_admin';
        $selected = array();
        foreach ((array) $permissions as $key) {
            // Capability IDs intentionally contain a dot; sanitize_key() would strip it.
            $key = is_scalar($key) ? (string) $key : '';
            if (isset($catalog[$key]) && !in_array($key, $selected, true)) {
                $selected[] = $key;
            }
        }
        if ($owner_super) {
            $selected = array_keys($catalog);
            if (!$owner || !user_can($owner, 'manage_options')) { $selected = array_values(array_diff($selected, array('security.manage'))); }
        } else {
            $selected = array_values(array_diff($selected, array('users.super_admin.assign')));
        }
        $is_security_admin = current_user_can('manage_options');
        if (!$is_security_admin) {
            $existing = self::permissions_for($device);
            $selected = array_values(array_diff($selected, array('security.manage')));
            if (in_array('security.manage', $existing, true)) {
                $selected[] = 'security.manage';
            }
        } elseif (in_array('security.manage', $selected, true)) {
            $owner = get_userdata((int) $device->user_id);
            if (!$owner || !user_can($owner, 'manage_options')) {
                return new WP_Error('wsm_security_owner_invalid', 'مدیریت امنیت فقط به دستگاه متصل به حساب مدیرکل وردپرس واگذار می‌شود.', array('status' => 403));
            }
        }
        $result = $wpdb->update(self::table(), array('permissions' => wp_json_encode($selected), 'updated_at' => WSM_Util::now()), array('id' => (int) $id));
        if ($result === false) {
            return new WP_Error('wsm_permission_save_failed', 'ذخیرهٔ مجوزهای دستگاه انجام نشد.', array('status' => 500));
        }
        WSM_Activity_Log::log('update', 'device', (int) $id, 'مجوزهای دستگاه', '', implode(',', $selected), 'success', (int) $id);
        return self::find($id);
    }

    /**
     * فهرست دستگاه‌ها. status='' یعنی همه به‌جز حذف‌شده‌ها
     */
    public static function query($args = array()) {
        global $wpdb;
        self::ensure_schema();
        $where = array('1=1');
        $params = array();

        if (!empty($args['status']) && in_array($args['status'], self::STATUSES, true)) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        } else {
            $where[] = "status != 'deleted'";
        }
        if (!empty($args['search'])) {
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[]  = '(device_name LIKE %s OR ip_address LIKE %s OR platform LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 100;
        $sql   = "SELECT * FROM " . self::table() . " WHERE " . implode(' AND ', $where) . " ORDER BY last_seen DESC, id DESC LIMIT %d";
        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function format($d) {
        $user = get_userdata((int) $d->user_id);
        return array(
            'id' => (int) $d->id,
            'user_id' => (int) $d->user_id,
            'user_login' => $user ? (string) $user->user_login : '',
            'display_name' => $user ? (string) $user->display_name : '',
            'device_name' => $d->device_name,
            'platform' => $d->platform,
            'os_version' => $d->os_version,
            'app_version' => $d->app_version,
            'ip_address' => $d->ip_address,
            'status' => $d->status,
            'remember_me' => isset($d->remember_me) ? (bool) $d->remember_me : false,
            'permissions' => self::permissions_for($d),
            'block_reason' => $d->block_reason,
            'last_seen' => wsm_fa_date($d->last_seen),
            'created_at' => wsm_fa_date($d->created_at),
        );
    }
}

