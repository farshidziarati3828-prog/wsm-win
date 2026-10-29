<?php
/**
 * لاگ فعالیت: هر عملیات با مقدار «قبل → بعد»، IP، دستگاه و نتیجه
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Activity_Log {

    const ACTIONS = array('create', 'update', 'delete', 'auth', 'device', 'refund', 'status', 'copy', 'sale', 'invoice');

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wsm_activity_log';
    }

    private static function encode($value) {
        if ($value === '' || $value === null) {
            return '';
        }
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '';
        }
        if (strlen($json) > 5000) {
            $json = substr($json, 0, 5000) . '…';
        }
        return $json;
    }

    /**
     * ثبت یک رکورد لاگ
     * @param int|null $device_id اگر null باشد، دستگاهِ درخواست جاری استفاده می‌شود
     */
    public static function log($action_type, $entity_type, $entity_id, $entity_name, $before, $after, $result = 'success', $device_id = null, $user_id_override = null) {
        global $wpdb;
        if ($device_id === null) {
            $device_id = WSM_Permissions::current_device_id();
        }
        $device = $device_id ? WSM_Devices::find((int) $device_id) : null;
        $actor_device = WSM_Permissions::current_device();
        $current_user_id = (int) get_current_user_id();
        $actor_user_id = $actor_device ? (int) $actor_device->user_id : ($current_user_id > 0 ? $current_user_id : ($device ? (int) $device->user_id : 0));
        if ($user_id_override !== null) { $actor_user_id = max(0, (int) $user_id_override); }

        $wpdb->insert(self::table(), array(
            'device_id'   => (int) $device_id,
            'user_id'     => $actor_user_id,
            'action_type' => substr((string) $action_type, 0, 20),
            'entity_type' => substr((string) $entity_type, 0, 30),
            'entity_id'   => (int) $entity_id,
            'entity_name' => WSM_Util::clean_text($entity_name, 255),
            'before_data' => self::encode($before),
            'after_data'  => self::encode($after),
            'result'      => $result === 'failed' ? 'failed' : 'success',
            'ip_address'  => WSM_Util::client_ip(),
            'created_at'  => WSM_Util::now(),
        ));
    }

    /**
     * فیلتر و فهرست لاگ (جدیدترین اول)
     * کلیدهای اختیاری: user_id, device_id, action, result, date_from, date_to, entity_type, entity_id, limit
     */
    public static function query($args = array()) {
        global $wpdb;
        $where  = array('1=1');
        $params = array();

        if (!empty($args['user_id'])) {
            $where[]  = 'l.user_id = %d';
            $params[] = (int) $args['user_id'];
        }
        if (!empty($args['device_id'])) {
            $where[]  = 'l.device_id = %d';
            $params[] = (int) $args['device_id'];
        }
        if (!empty($args['action'])) {
            $where[]  = 'l.action_type = %s';
            $params[] = (string) $args['action'];
        }
        if (!empty($args['result'])) {
            $where[]  = 'l.result = %s';
            $params[] = (string) $args['result'];
        }
        if (!empty($args['entity_type'])) {
            $where[]  = 'l.entity_type = %s';
            $params[] = (string) $args['entity_type'];
        }
        if (!empty($args['entity_id'])) {
            $where[]  = 'l.entity_id = %d';
            $params[] = (int) $args['entity_id'];
        }
        if (!empty($args['date_from']) && self::valid_date($args['date_from'])) {
            $where[]  = 'l.created_at >= %s';
            $params[] = $args['date_from'] . ' 00:00:00';
        }
        if (!empty($args['date_to']) && self::valid_date($args['date_to'])) {
            $where[]  = 'l.created_at <= %s';
            $params[] = $args['date_to'] . ' 23:59:59';
        }

        $limit    = isset($args['limit']) ? max(1, min(1000, (int) $args['limit'])) : 100;
        $params[] = $limit;

        $sql = "SELECT l.*, d.device_name, d.platform
                FROM " . self::table() . " l
                LEFT JOIN {$wpdb->prefix}wsm_devices d ON d.id = l.device_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY l.id DESC LIMIT %d";

        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        foreach ($rows as &$row) {
            $row['created_at_fa'] = wsm_fa_date($row['created_at']);
        }
        return $rows;
    }

    private static function valid_date($value) {
        if (!is_scalar($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            return false;
        }
        $parts = array_map('intval', explode('-', (string) $value));
        return count($parts) === 3 && checkdate($parts[1], $parts[2], $parts[0]);
    }

    public static function count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table());
    }

    /**
     * حذف رکوردهای قدیمی‌تر از مدت نگهداری
     */
    public static function cleanup() {
        global $wpdb;
        $settings = WSM_Util::settings();
        $days     = max(7, (int) $settings['log_retention_days']);
        $before   = date('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::table() . " WHERE created_at < %s", $before));
    }

    /**
     * خروجی CSV (با BOM برای اکسل فارسی)
     */
    /** Prevent spreadsheet formula execution when log content is opened from CSV. */
    public static function csv_safe_cell($value) {
        $value = (string) $value;
        if (preg_match('/^[\x00-\x20]*[=+\-@]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    public static function export_csv($args = array()) {
        $args['limit'] = 1000;
        $rows = self::query($args);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wsm-activity-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('زمان', 'دستگاه', 'پلتفرم', 'عملیات', 'نوع', 'شناسه', 'نام', 'قبل', 'بعد', 'نتیجه', 'IP'));
        foreach ($rows as $r) {
            $cells = array(
                $r['created_at'], $r['device_name'], $r['platform'], $r['action_type'], $r['entity_type'],
                $r['entity_id'], $r['entity_name'], $r['before_data'], $r['after_data'], $r['result'], $r['ip_address'],
            );
            fputcsv($out, array_map(array(__CLASS__, 'csv_safe_cell'), $cells));
        }
        fclose($out);
    }
}

