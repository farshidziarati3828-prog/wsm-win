<?php
/**
 * پنل wp-admin — منوی واحد WSM برای داشبورد، دستگاه‌ها، کارمندان، گزارش فعالیت، نقش‌ها، کلید دسترسی، امنیت و تنظیمات.
 * هدف پنل فقط مدیریت است؛ کارهای روزانهٔ فروشگاه از اپ انجام می‌شود.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Admin_Menu {

    const SLUG = 'wsm';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'handle_actions'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_wsm_bulk_devices', array(__CLASS__, 'ajax_bulk_devices'));
        add_action('wp_ajax_wsm_bulk_employees', array(__CLASS__, 'ajax_bulk_employees'));
    }

    public static function menu() {
        $manager_cap = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
        add_menu_page('WSM', 'WSM', $manager_cap, self::SLUG, array(__CLASS__, 'page_dashboard'), 'dashicons-store', 56);
        add_submenu_page(self::SLUG, 'داشبورد', 'داشبورد', $manager_cap, self::SLUG, array(__CLASS__, 'page_dashboard'));
        add_submenu_page(self::SLUG, 'دستگاه‌ها', 'دستگاه‌ها', 'manage_options', self::SLUG . '-devices', array(__CLASS__, 'page_devices'));
        add_submenu_page(self::SLUG, 'کارمندان', 'کارمندان', 'manage_options', self::SLUG . '-employees', array(__CLASS__, 'page_employees'));
        add_submenu_page(self::SLUG, 'گزارش فعالیت', 'گزارش فعالیت', 'manage_options', self::SLUG . '-activity', array(__CLASS__, 'page_activity'));
        add_submenu_page(self::SLUG, 'نقش‌ها و مجوزها', 'نقش‌ها و مجوزها', 'manage_options', self::SLUG . '-roles', array(__CLASS__, 'page_roles'));
        add_submenu_page(self::SLUG, 'کلید دسترسی', 'کلید دسترسی', $manager_cap, self::SLUG . '-access-key', array(__CLASS__, 'page_access_key'));
        add_submenu_page(self::SLUG, 'امنیت', 'امنیت', 'manage_options', self::SLUG . '-security', array(__CLASS__, 'page_security'));
        add_submenu_page(self::SLUG, 'تنظیمات', 'تنظیمات', $manager_cap, self::SLUG . '-settings', array(__CLASS__, 'page_settings'));
    }

    public static function assets($hook) {
        if (strpos((string) $hook, self::SLUG) === false) {
            return;
        }
        wp_enqueue_style('wsm-admin', WSM_URL . 'assets/css/admin.css', array(), WSM_VERSION . '-wpadmin-layout-7');
        wp_enqueue_script('wsm-admin', WSM_URL . 'assets/js/admin.js', array(), WSM_VERSION . '-manual-backup-labels-1', true);
    }

    /* ---------------------------------------------------------------
     * اکشن‌ها (ساخت کلید، عملیات دستگاه، ذخیرهٔ تنظیمات، CSV)
     * ------------------------------------------------------------- */

    public static function handle_actions() {
        $raw_action = isset($_POST['wsm_action']) && is_scalar($_POST['wsm_action']) ? wp_unslash($_POST['wsm_action']) : (isset($_GET['wsm_action']) && is_scalar($_GET['wsm_action']) ? wp_unslash($_GET['wsm_action']) : '');
        if ($raw_action === '') {
            return;
        }
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die('دسترسی کافی نیست.');
        }

        $action = sanitize_key($raw_action);
        $admin_only_actions = array('save_device_permissions','device_block','device_unblock','device_unlink','device_delete','device_activate','device_approve','save_device_name','add_device_manual','save_device_policy','employee_save','employee_deactivate','role_save','role_delete','export_activity_excel','export_csv','save_security');
        if (in_array($action, $admin_only_actions, true) && !current_user_can('manage_options')) { wp_die('این بخش فقط برای مدیر کل وردپرس مجاز است.'); }
        check_admin_referer('wsm_' . $action);

        switch ($action) {

            case 'save_device_permissions':
                $device_id = isset($_POST['device_id']) && is_scalar($_POST['device_id']) ? (int) wp_unslash($_POST['device_id']) : 0;
                $posted = isset($_POST['wsm_permissions']) ? (array) wp_unslash($_POST['wsm_permissions']) : array();
                $saved = WSM_Devices::save_permissions($device_id, $posted);
                if (is_wp_error($saved)) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-devices&permission_error=1'));
                    exit;
                }
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-devices&permissions_saved=1'));
                exit;

            case 'save_device_policy':
                update_option('wsm_auto_approve_devices', self::checkbox_input($_POST, 'auto_approve_devices') ? 1 : 0, false);
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-devices&policy_saved=1'));
                exit;

            case 'add_device_manual':
                $posted_key = self::scalar_input($_POST, 'access_key');
                $manual_ip = trim(self::scalar_input($_POST, 'ip_address'));
                $manual_user = (int) self::scalar_input($_POST, 'user_id');
                if ($posted_key !== '') {
                    $key_user = WSM_Auth::access_key_user_id($posted_key);
                    if ($key_user < 1) { wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-devices&device_error=key')); exit; }
                    $manual_user = $key_user;
                }
                if ($manual_user < 1 || ($posted_key === '' && ($manual_ip === '' || !filter_var($manual_ip, FILTER_VALIDATE_IP)))) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-devices&device_error=manual'));
                    exit;
                }
                $manual_info = array('device_name' => self::scalar_input($_POST, 'device_name', 'دستگاه دستی'), 'platform' => self::scalar_input($_POST, 'platform', 'Manual'), 'app_version' => 'manual');
                $manual_device = WSM_Devices::register_manual($manual_user, $manual_info, $manual_ip, WSM_Permissions::user_permissions($manual_user));
                if (is_wp_error($manual_device)) { wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-devices&device_error=manual')); exit; }
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-devices&device_added=1'));
                exit;

            case 'save_device_name':
                $id = (int) self::scalar_input($_POST, 'device_id');
                $renamed = WSM_Devices::rename_device($id, self::scalar_input($_POST, 'device_name'));
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-devices&' . (is_wp_error($renamed) ? 'device_error=rename' : 'done=1')));
                exit;

            case 'employee_save':
                $employee_id = (int) self::scalar_input($_POST, 'user_id');
                $employee_data = array(
                    'user_login' => self::scalar_input($_POST, 'user_login'),
                    'login_alias' => self::scalar_input($_POST, 'login_alias'),
                    'display_name' => self::scalar_input($_POST, 'display_name'),
                    'user_email' => self::scalar_input($_POST, 'user_email'),
                    'phone' => self::scalar_input($_POST, 'phone'),
                    'password' => self::scalar_input($_POST, 'password'),
                    'preset' => self::scalar_input($_POST, 'preset', 'custom'),
                    'permissions' => isset($_POST['permissions']) && is_array($_POST['permissions']) ? (array) wp_unslash($_POST['permissions']) : array(),
                    'status' => self::scalar_input($_POST, 'status', 'active'),
                );
                $employee_result = $employee_id ? WSM_Auth::update_app_user($employee_id, $employee_data) : WSM_Auth::create_app_user($employee_data);
                if (is_wp_error($employee_result)) {
                    set_transient('wsm_employee_error_' . get_current_user_id(), array('code' => $employee_result->get_error_code(), 'message' => $employee_result->get_error_message()), 2 * MINUTE_IN_SECONDS);
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-employees&employee_error=' . rawurlencode($employee_result->get_error_code())));
                    exit;
                }
                if (!$employee_id && is_array($employee_result) && !empty($employee_result['user_login'])) {
                    set_transient('wsm_employee_created_login_' . get_current_user_id(), array(
                        'login' => (string) $employee_result['user_login'],
                        'alias' => isset($employee_result['user_login_alias']) ? (string) $employee_result['user_login_alias'] : '',
                    ), 2 * MINUTE_IN_SECONDS);
                }
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-employees&employee_saved=1'));
                exit;

            case 'employee_deactivate':
                $employee_id = (int) self::scalar_input($_GET, 'user_id');
                $employee_status = self::scalar_input($_GET, 'status', 'inactive') === 'active' ? 'active' : 'inactive';
                $employee_result = WSM_Auth::update_app_user($employee_id, array('status' => $employee_status));
                if (is_wp_error($employee_result)) {
                    set_transient('wsm_employee_error_' . get_current_user_id(), array('code' => $employee_result->get_error_code(), 'message' => $employee_result->get_error_message()), 2 * MINUTE_IN_SECONDS);
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-employees&employee_error=' . rawurlencode($employee_result->get_error_code())));
                } else {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-employees&employee_saved=1'));
                }
                exit;

            case 'role_save':
                $role_key = sanitize_key(self::scalar_input($_POST, 'role_key'));
                $role_label = sanitize_text_field(self::scalar_input($_POST, 'role_label'));
                $role_permissions = isset($_POST['permissions']) && is_array($_POST['permissions']) ? self::sanitize_permission_input((array) wp_unslash($_POST['permissions'])) : array();
                if ($role_label === '') { wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-roles&role_error=label')); exit; }
                $custom_roles = WSM_Permissions::custom_roles();
                if ($role_key === '') {
                    do { $role_key = 'role_custom_' . strtolower(wp_generate_password(8, false, false)); } while (isset($custom_roles[$role_key]));
                }
                if (!preg_match('/^role_[a-z0-9_-]{1,32}$/', $role_key)) { wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-roles&role_error=key')); exit; }
                $custom_roles[$role_key] = array('label' => $role_label, 'permissions' => $role_permissions);
                if (!WSM_Permissions::save_custom_roles($custom_roles)) { wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-roles&role_error=save')); exit; }
                foreach ((array) get_users(array('number' => 1000, 'fields' => 'ID')) as $role_user_id) {
                    if (get_user_meta((int) $role_user_id, 'wsm_app_role', true) === $role_key) { WSM_Permissions::save_user_permissions((int) $role_user_id, $role_permissions, $role_key, get_current_user_id()); }
                }
                WSM_Activity_Log::log('update', 'permission_role', 0, $role_label, '', array('role' => $role_key, 'permission_count' => count($role_permissions)), 'success', null, get_current_user_id());
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-roles&role_saved=1'));
                exit;

            case 'role_delete':
                $role_key = sanitize_key(self::scalar_input($_GET, 'role_key'));
                $custom_roles = WSM_Permissions::custom_roles();
                if (!isset($custom_roles[$role_key])) { wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-roles&role_error=missing')); exit; }
                unset($custom_roles[$role_key]);
                if (!WSM_Permissions::save_custom_roles($custom_roles)) { wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-roles&role_error=save')); exit; }
                foreach ((array) get_users(array('number' => 1000, 'fields' => 'ID')) as $role_user_id) {
                    if (get_user_meta((int) $role_user_id, 'wsm_app_role', true) === $role_key) {
                        $record = WSM_Permissions::user_permissions_record((int) $role_user_id);
                        $grants = $record ? $record['permissions'] : array();
                        WSM_Permissions::save_user_permissions((int) $role_user_id, $grants, 'custom', get_current_user_id());
                        update_user_meta((int) $role_user_id, 'wsm_app_role', 'custom');
                    }
                }
                WSM_Activity_Log::log('delete', 'permission_role', 0, $role_key, '', array(), 'success', null, get_current_user_id());
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-roles&role_saved=1'));
                exit;

            case 'export_activity_excel':
                $export_args = array(
                    'user_id' => (int) self::scalar_input($_GET, 'user_id'),
                    'device_id' => (int) self::scalar_input($_GET, 'device_id'),
                    'action' => sanitize_key(self::scalar_input($_GET, 'action_type')),
                    'result' => sanitize_key(self::scalar_input($_GET, 'result')),
                    'date_from' => self::scalar_input($_GET, 'date_from'),
                    'date_to' => self::scalar_input($_GET, 'date_to'),
                    'limit' => 1000,
                );
                $ip_filter = trim(self::scalar_input($_GET, 'ip_address'));
                $export_rows = WSM_Activity_Log::query($export_args);
                if ($ip_filter !== '') { $export_rows = array_values(array_filter($export_rows, function ($row) use ($ip_filter) { return isset($row['ip_address']) && stripos((string) $row['ip_address'], $ip_filter) !== false; })); }
                nocache_headers();
                header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
                header('Content-Disposition: attachment; filename="wsm-activity-' . gmdate('Ymd-His') . '.xls"');
                echo "\xEF\xBB\xBF";
                echo '<?xml version="1.0" encoding="UTF-8"?>';
                echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="WSM Activity"><Table>';
                $excel_cell = function ($value) { return '<Cell><Data ss:Type="String">' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</Data></Cell>'; };
                echo '<Row>' . $excel_cell('زمان') . $excel_cell('نام کاربری') . $excel_cell('نام کامل') . $excel_cell('دستگاه') . $excel_cell('عملیات') . $excel_cell('هدف') . $excel_cell('نتیجه') . $excel_cell('IP') . '</Row>';
                foreach ((array) $export_rows as $row) {
                    $activity_user = get_userdata(isset($row['user_id']) ? (int) $row['user_id'] : 0);
                    echo '<Row>' . $excel_cell(isset($row['created_at']) ? $row['created_at'] : '') . $excel_cell($activity_user ? $activity_user->user_login : '') . $excel_cell($activity_user ? $activity_user->display_name : '') . $excel_cell(isset($row['device_name']) ? $row['device_name'] : '') . $excel_cell(self::action_label(isset($row['action_type']) ? $row['action_type'] : '')) . $excel_cell(isset($row['entity_name']) && $row['entity_name'] !== '' ? $row['entity_name'] : (isset($row['entity_type']) ? $row['entity_type'] : '')) . $excel_cell(isset($row['result']) ? $row['result'] : '') . $excel_cell(isset($row['ip_address']) ? $row['ip_address'] : '') . '</Row>';
                }
                echo '</Table></Worksheet></Workbook>';
                exit;

            case 'save_security':
                if (!current_user_can('manage_options')) {
                    wp_die('تنها مدیرکل وردپرس می‌تواند الزام TOTP را تغییر دهد.');
                }
                $settings = WSM_Util::settings();
                $required = self::checkbox_input($_POST, 'wsm_totp_required');
                if ($required && !WSM_Crypto::available()) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-security&security_error=crypto'));
                    exit;
                }
                $settings['totp_required'] = $required ? 1 : 0;
                $settings['token_ttl_days'] = max(1, min(365, (int) self::scalar_input($_POST, 'token_ttl_days', 90)));
                $settings['key_ttl_days'] = max(1, min(365, (int) self::scalar_input($_POST, 'key_ttl_days', 90)));
                update_option('wsm_settings', $settings, false);
                $policy = array(
                    'max_attempts' => max(1, min(20, (int) self::scalar_input($_POST, 'max_attempts', 5))),
                    'window_minutes' => max(1, min(1440, (int) self::scalar_input($_POST, 'window_minutes', 15))),
                    'lock_minutes' => max(1, min(1440, (int) self::scalar_input($_POST, 'lock_minutes', 30))),
                );
                update_option('wsm_security_policy', $policy, false);
                if ((int) WSM_Util::settings()['totp_required'] !== (int) $settings['totp_required'] || (int) WSM_Util::settings()['token_ttl_days'] !== (int) $settings['token_ttl_days'] || (int) WSM_Util::settings()['key_ttl_days'] !== (int) $settings['key_ttl_days'] || get_option('wsm_security_policy') !== $policy) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-security&security_error=storage'));
                    exit;
                }
                WSM_Security::log('security_policy_changed', 'warning', 0, 'سیاست عمر نشست، کلید و تلاش‌های ورود از پیشخوان تغییر کرد.');
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-security&security_saved=1'));
                exit;

            case 'new_key':
                if (!WSM_Crypto::available()) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-access-key&key_error=crypto'));
                    exit;
                }
                $key = WSM_Auth::generate_access_key(get_current_user_id());
                WSM_Auth::stash_key(get_current_user_id(), $key['plain']);
                WSM_Security::log('key_created', 'info', 0, 'کلید جدید توسط مدیر ساخته شد');
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-access-key&created=1'));
                exit;

            case 'device_approve':
            case 'device_activate':
            case 'device_block':
            case 'device_unblock':
            case 'device_unlink':
            case 'device_delete':
                $id     = isset($_GET['device_id']) && is_scalar($_GET['device_id']) ? (int) $_GET['device_id'] : 0;
                $reason = isset($_GET['reason']) && is_scalar($_GET['reason']) ? sanitize_text_field(wp_unslash($_GET['reason'])) : '';
                $map    = array(
                    'device_approve' => 'approve',
                    'device_activate'=> 'activate',
                    'device_block'   => 'block',
                    'device_unblock' => 'unblock',
                    'device_unlink'  => 'unlink',
                    'device_delete'  => 'delete',
                );
                $status_result = WSM_Devices::set_status($id, $map[$action], $reason);
                if (is_wp_error($status_result)) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-devices&device_error=1'));
                    exit;
                }
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-devices&done=1'));
                exit;

            case 'ip_unblock':
                if (!current_user_can('manage_options')) {
                    wp_die('تنها مدیرکل وردپرس می‌تواند مسدودی IP را پاک کند.');
                }
                $ip = isset($_POST['ip_address']) && is_scalar($_POST['ip_address'])
                    ? sanitize_text_field(wp_unslash($_POST['ip_address']))
                    : '';
                $result = WSM_Security::unblock_ip($ip);
                $redirect = admin_url('admin.php?page=' . self::SLUG . '-devices&tab=blocked_ips');
                $redirect = add_query_arg(is_wp_error($result) ? 'ip_error' : 'ip_unblocked', '1', $redirect);
                wp_safe_redirect($redirect);
                exit;

            case 'test_sms':
                // پیام آزمایشی ملی‌پیامک؛ نتیجه با transient کوتاه‌مدت به صفحه برمی‌گردد (بدون رمز یا شماره در URL)
                $res = WSM_SMS::send_test();
                set_transient('wsm_test_result_' . get_current_user_id(), array('channel' => 'sms', 'ok' => (bool) $res['ok'], 'detail' => (string) $res['detail']), 300);
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-settings&tested=sms'));
                exit;

            case 'test_email':
                $res = WSM_Security::send_test_email();
                set_transient('wsm_test_result_' . get_current_user_id(), array('channel' => 'email', 'ok' => (bool) $res['ok'], 'detail' => (string) $res['detail']), 300);
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-settings&tested=email'));
                exit;

            case 'save_backup_settings':
                $in=isset($_POST['wsm_backup_settings'])&&is_array($_POST['wsm_backup_settings'])?(array)wp_unslash($_POST['wsm_backup_settings']):array();
                $backup_input=array(
                    'auto_enabled'=>self::checkbox_input($in,'auto_enabled'),
                    'schedule'=>sanitize_key(self::scalar_input($in,'schedule','daily')),
                    'storage_mode'=>sanitize_key(self::scalar_input($in,'storage_mode','default')),
                    'storage_path'=>self::scalar_input($in,'storage_path'),
                    'external_storage_path'=>self::scalar_input($in,'external_storage_path'),
                    'retention'=>self::scalar_input($in,'retention','5'),
                    'email_notifications'=>self::checkbox_input($in,'email_notifications'),
                    'email_recipient'=>sanitize_email(self::scalar_input($in,'email_recipient')),
                );
                $backup_saved=WSM_Club_Operations::save_backup_configuration($backup_input);
                $notice=is_wp_error($backup_saved)?array('ok'=>false,'message'=>$backup_saved->get_error_message()):array('ok'=>true,'message'=>'تنظیمات بکاپ ذخیره شد.');
                set_transient('wsm_backup_settings_result_'.get_current_user_id(),$notice,300);
                wp_safe_redirect(admin_url('admin.php?page='.self::SLUG.'-settings'));
                exit;

            case 'save_settings':
                $in       = isset($_POST['wsm']) ? (array) wp_unslash($_POST['wsm']) : array();
                $settings = WSM_Util::settings();
                if (current_user_can('manage_options')) {
                    $settings['token_ttl_days'] = max(1, min(365, (int) self::scalar_input($in, 'token_ttl_days', $settings['token_ttl_days'])));
                    $settings['key_ttl_days'] = max(1, min(365, (int) self::scalar_input($in, 'key_ttl_days', $settings['key_ttl_days'])));
                }
                $settings['log_retention_days'] = max(7, min(730, (int) self::scalar_input($in, 'log_retention_days', 90)));
                $settings['notify_new_order']   = self::checkbox_input($in, 'notify_new_order') ? 1 : 0;
                $settings['delete_data_on_uninstall'] = self::checkbox_input($in, 'delete_data_on_uninstall') ? 1 : 0;
                $settings['store_name']         = sanitize_text_field(self::scalar_input($in, 'store_name'));
                $settings['store_logo']         = esc_url_raw(self::scalar_input($in, 'store_logo'));
                $settings['update_server_url']  = esc_url_raw(self::scalar_input($in, 'update_server_url'));
                $settings['auto_update']        = self::checkbox_input($in, 'auto_update') ? 1 : 0;
                $settings['expiry_alert_days']   = max(1, min(365, (int) self::scalar_input($in, 'expiry_alert_days', 30)));
                $settings['expiry_alert_email']  = self::checkbox_input($in, 'expiry_alert_email') ? 1 : 0;
                $settings['expiry_alert_sms']    = self::checkbox_input($in, 'expiry_alert_sms') ? 1 : 0;
                $expiry_email = sanitize_email(self::scalar_input($in, 'expiry_alert_email_to', get_option('admin_email')));
                $settings['expiry_alert_email_to'] = is_email($expiry_email) ? $expiry_email : get_option('admin_email');
                $expiry_meta_key = trim(sanitize_text_field(self::scalar_input($in, 'expiry_meta_key')));
                $settings['expiry_meta_key'] = ($expiry_meta_key === '' || preg_match('/^[A-Za-z0-9_:\-.]{1,120}$/', $expiry_meta_key)) ? $expiry_meta_key : '';
                foreach (array('name', 'company', 'phone', 'email', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country') as $sender_field) {
                    $setting_key = 'postal_sender_' . $sender_field;
                    $sender_value = isset($in[$setting_key]) && is_scalar($in[$setting_key]) ? (string) $in[$setting_key] : '';
                    if ($sender_field === 'email') {
                        $sender_email = sanitize_email($sender_value);
                        $settings[$setting_key] = $sender_email === '' || is_email($sender_email) ? $sender_email : '';
                    } elseif (in_array($sender_field, array('address_1', 'address_2'), true)) {
                        $settings[$setting_key] = sanitize_textarea_field($sender_value);
                    } else {
                        $settings[$setting_key] = sanitize_text_field($sender_value);
                    }
                }
                $club_sms_input = array();
                foreach (array('sms_events','sms_templates') as $sms_settings_key) {
                    if (array_key_exists($sms_settings_key, $in)) { $club_sms_input[$sms_settings_key] = $in[$sms_settings_key]; }
                }
                if (array_key_exists('sms_expiry_days_before', $in)) { $club_sms_input['sms_expiry_days_before'] = self::scalar_input($in, 'sms_expiry_days_before', 3); }
                if (array_key_exists('debt_reminder_after_days', $in)) { $club_sms_input['debt_reminder_after_days'] = self::scalar_input($in, 'debt_reminder_after_days', 0); }
                if ($club_sms_input) {
                    $club_sms_saved = WSM_Club_Operations::update_settings($club_sms_input);
                    if (is_wp_error($club_sms_saved)) {
                        set_transient('wsm_sms_event_settings_result_' . get_current_user_id(), array('ok'=>false,'message'=>$club_sms_saved->get_error_message()), 300);
                        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-settings&sms_event_error=1'));
                        exit;
                    }
                }
                WSM_Expiry::invalidate_detection();
                $sms_mode = self::scalar_input($in, 'sms_mode', 'auto');
                $sms_input = array('mode' => $sms_mode, 'enabled' => self::checkbox_input($in, 'sms_enabled'),
                    'admin_mobile' => self::scalar_input($in, 'sms_admin_mobile'));
                if ($sms_mode === 'manual') {
                    $sms_input['username'] = self::scalar_input($in, 'sms_username');
                    $sms_input['from'] = self::scalar_input($in, 'sms_from');
                    $sms_password = self::scalar_input($in, 'sms_password');
                    if ($sms_password !== '') { $sms_input['password'] = $sms_password; }
                }
                $sms_saved = WSM_SMS::save_config($sms_input);
                if (is_wp_error($sms_saved)) {
                    set_transient('wsm_sms_config_error_' . get_current_user_id(), $sms_saved->get_error_message(), 300);
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-settings&sms_config_error=1'));
                    exit;
                }
                delete_transient(WSM_Updater::INFO_KEY);
                update_option('wsm_settings', $settings, false);
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-settings&saved=1'));
                exit;

            case 'export_csv':
                WSM_Activity_Log::export_csv(array(
                    'device_id' => isset($_GET['device_id']) && is_scalar($_GET['device_id']) ? (int) $_GET['device_id'] : 0,
                    'action'    => isset($_GET['action']) && is_scalar($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '',
                    'result'    => isset($_GET['result']) && is_scalar($_GET['result']) ? sanitize_key(wp_unslash($_GET['result'])) : '',
                    'date_from' => isset($_GET['date_from']) && is_scalar($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
                    'date_to'   => isset($_GET['date_to']) && is_scalar($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
                ));
                exit;

            case 'upload_app':
                // نصب بیلد اپ (index.html خروجی Vite) بدون نیاز به FTP/Node
                if (empty($_FILES['wsm_app_file']) || empty($_FILES['wsm_app_file']['tmp_name'])
                    || !isset($_FILES['wsm_app_file']['error']) || (int) $_FILES['wsm_app_file']['error'] !== UPLOAD_ERR_OK) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-settings&app_error=filerequired'));
                    exit;
                }
                $upload = $_FILES['wsm_app_file'];
                $ext    = strtolower(pathinfo(isset($upload['name']) ? $upload['name'] : '', PATHINFO_EXTENSION));
                if (!in_array($ext, array('html', 'htm'), true) || (int) $upload['size'] > 10 * 1024 * 1024) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-settings&app_error=invalid'));
                    exit;
                }
                $tmp     = $upload['tmp_name'];
                $content = file_get_contents($tmp); // phpcs:ignore
                if (!$content || strpos($content, '<div id="root"') === false) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-settings&app_error=invalid'));
                    exit;
                }
                // فقط سورس خام Vite (اسکریپت /src/main.tsx یا آدرس سرور توسعه) رد می‌شود.
                // بیلد واقعی ممکن است رشتهٔ localhost داشته باشد (مثلاً از React Router) و باید پذیرفته شود.
                if (strpos($content, '/src/main.tsx') !== false || strpos($content, 'localhost:5173') !== false || strpos($content, '127.0.0.1:5173') !== false) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-settings&app_error=source'));
                    exit;
                }
                if (!is_dir(WSM_PATH . 'wsm-app/')) {
                    wp_mkdir_p(WSM_PATH . 'wsm-app/');
                }
                $ok = file_put_contents(WSM_PATH . 'wsm-app/index.html', $content, LOCK_EX); // phpcs:ignore
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '-settings&' . ($ok ? 'app_installed=1' : 'app_error=write')));
                exit;
        }
    }

    public static function ajax_bulk_devices() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'این عملیات فقط برای مدیرکل وردپرس مجاز است.'), 403);
        }
        if (!check_ajax_referer('wsm_bulk_devices', 'nonce', false)) {
            wp_send_json_error(array('message' => 'اعتبار درخواست منقضی یا نامعتبر است؛ صفحه را تازه‌سازی کنید.'), 403);
        }
        $operation = isset($_POST['operation']) && is_scalar($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $device_ids = isset($_POST['device_ids']) ? wp_unslash($_POST['device_ids']) : array();
        $result = WSM_Devices::bulk_set_status($device_ids, $operation);
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $status = is_array($error_data) && isset($error_data['status']) ? (int) $error_data['status'] : 400;
            wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()), $status);
        }
        wp_send_json_success($result);
    }

    /** Process employee bulk actions with per-user permission checks and a partial-result report. */
    public static function ajax_bulk_employees() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'این عملیات فقط برای مدیرکل وردپرس مجاز است.'), 403);
        }
        if (!check_ajax_referer('wsm_bulk_employees', 'nonce', false)) {
            wp_send_json_error(array('message' => 'اعتبار درخواست منقضی یا نامعتبر است؛ صفحه را تازه‌سازی کنید.'), 403);
        }
        $operation = isset($_POST['operation']) && is_scalar($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $target_status = array('activate' => 'active', 'deactivate' => 'inactive');
        if ($operation !== 'delete' && !isset($target_status[$operation])) {
            wp_send_json_error(array('message' => 'عملیات دسته‌جمعی نامعتبر است.'), 400);
        }
        $raw_ids = isset($_POST['employee_ids']) ? wp_unslash($_POST['employee_ids']) : array();
        if (!is_array($raw_ids) || !$raw_ids || count($raw_ids) > 500) {
            wp_send_json_error(array('message' => 'فهرست کارمندان خالی یا بیش از حد مجاز است.'), 400);
        }
        $ids = array();
        foreach ($raw_ids as $raw_id) {
            if (!is_scalar($raw_id) || !preg_match('/^[0-9]+$/D', (string) $raw_id)) {
                wp_send_json_error(array('message' => 'شناسهٔ یکی از کارمندان معتبر نیست.'), 400);
            }
            $id = absint($raw_id);
            if ($id < 1) { wp_send_json_error(array('message' => 'شناسهٔ یکی از کارمندان معتبر نیست.'), 400); }
            $ids[$id] = $id;
        }
        if (!$ids) { wp_send_json_error(array('message' => 'هیچ کارمند معتبری انتخاب نشده است.'), 400); }

        $result = array('requested' => count($ids), 'changed' => 0, 'unchanged' => 0, 'failed' => 0, 'errors' => array());
        foreach ($ids as $id) {
            $employee = get_userdata($id);
            if (!$employee) {
                $action_result = new WP_Error('wsm_employee_missing', 'حساب کارمند پیدا نشد.');
            } elseif ($operation === 'delete') {
                $action_result = self::delete_employee_completely($id);
            } else {
                $employee_roles = is_array($employee->roles) ? $employee->roles : array();
                $is_app_employee = get_user_meta($id, 'wsm_app_user_status', true) !== ''
                    || get_user_meta($id, 'wsm_app_role', true) !== ''
                    || WSM_Permissions::user_permissions_record($id) !== null
                    || in_array('administrator', $employee_roles, true)
                    || in_array('shop_manager', $employee_roles, true)
                    || !empty($employee->allcaps['manage_woocommerce'])
                    || !empty($employee->allcaps['manage_options']);
                if (!$is_app_employee) {
                    $action_result = new WP_Error('wsm_employee_missing', 'این حساب در فهرست کاربران WSM وجود ندارد.');
                } else {
                    $current_status = get_user_meta($id, 'wsm_app_user_status', true) === 'inactive' ? 'inactive' : 'active';
                    if ($current_status === $target_status[$operation]) { $result['unchanged']++; continue; }
                    $action_result = WSM_Auth::update_app_user($id, array('status' => $target_status[$operation]));
                }
            }
            if (is_wp_error($action_result)) {
                $result['failed']++;
                if (count($result['errors']) < 12) {
                    $label = $employee ? (trim((string) $employee->display_name) !== '' ? $employee->display_name : $employee->user_login) : ('کاربر #' . $id);
                    $result['errors'][] = array('id' => $id, 'message' => $label . ': ' . $action_result->get_error_message());
                }
                continue;
            }
            $result['changed']++;
        }
        wp_send_json_success($result);
    }

    /** Permanently remove an employee WordPress account while revoking access and preserving audit history. */
    private static function delete_employee_completely($user_id) {
        $user_id = absint($user_id);
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user) { return new WP_Error('wsm_employee_missing', 'حساب کارمند پیدا نشد.'); }
        if ($user_id === (int) get_current_user_id()) { return new WP_Error('wsm_employee_self_delete', 'نمی‌توانید حساب خود را حذف کنید.'); }
        if (user_can($user, 'manage_options') || (function_exists('is_super_admin') && is_super_admin($user_id))) { return new WP_Error('wsm_employee_admin_locked', 'حساب مدیرکل یا مدیرکل شبکه قابل حذف نیست.'); }
        $user_file = ABSPATH . 'wp-admin/includes/user.php';
        if (!function_exists('wp_delete_user') && file_exists($user_file)) { require_once $user_file; }
        if (!function_exists('wp_delete_user')) { return new WP_Error('wsm_employee_delete_unavailable', 'امکان حذف کامل حساب در این نصب وردپرس در دسترس نیست.'); }
        if (!WSM_Devices::ensure_schema()) { return new WP_Error('wsm_employee_devices_schema', 'ساختار دستگاه‌ها آماده نیست؛ حذف کامل انجام نشد.'); }
        if (!WSM_Permissions::ensure_schema()) { return new WP_Error('wsm_employee_permissions_schema', 'ساختار مجوزهای کارمند آماده نیست؛ حذف کامل انجام نشد.'); }

        global $wpdb;
        $device_ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . WSM_Devices::table() . ' WHERE user_id = %d AND status <> %s', $user_id, 'deleted'));
        if ($wpdb->last_error) { return new WP_Error('wsm_employee_devices_read_failed', 'خواندن دستگاه‌های کارمند انجام نشد؛ حساب حذف نشد.'); }

        /* Reuse the existing guarded deletion flow: it blocks super-admin/self removal, disables login, and revokes sessions/keys. */
        $soft_result = WSM_Auth::delete_app_user($user_id);
        if (is_wp_error($soft_result)) { return $soft_result; }
        foreach (array_chunk(array_map('absint', (array) $device_ids), 500) as $device_chunk) {
            if (!$device_chunk) { continue; }
            $device_result = WSM_Devices::bulk_set_status($device_chunk, 'delete');
            if (is_wp_error($device_result)) { return new WP_Error('wsm_employee_devices_delete_failed', 'حساب غیرفعال و نشست‌ها باطل شدند، اما حذف کامل دستگاه‌ها انجام نشد: ' . $device_result->get_error_message()); }
        }
        $permission_result = $wpdb->delete(WSM_Permissions::user_table(), array('user_id' => $user_id), array('%d'));
        if ($permission_result === false) { return new WP_Error('wsm_employee_permissions_delete_failed', 'حساب غیرفعال شد، اما پاک‌سازی رکورد مجوز انجام نشد؛ حذف کامل متوقف شد.'); }

        $login = (string) $user->user_login;
        $deleted = wp_delete_user($user_id, (int) get_current_user_id());
        if (!$deleted) { return new WP_Error('wsm_employee_wp_delete_failed', 'حساب غیرفعال شد، اما حذف کامل حساب وردپرس ناموفق بود.'); }
        WSM_Activity_Log::log('delete', 'app_user', $user_id, $login, 'inactive', 'permanently_deleted', 'success', null, (int) get_current_user_id());
        return true;
    }

    /* ---------------------------------------------------------------
     * صفحهٔ داشبورد
     * ------------------------------------------------------------- */

    public static function page_dashboard() {
        $counts  = WSM_Devices::count_by_status();
        $logs    = current_user_can('manage_options') ? WSM_Activity_Log::query(array('limit' => 8)) : array();
        $prefix  = get_option('wsm_access_key_prefix', '');
        $expires = get_option('wsm_access_key_expires', '');

        echo '<div class="wrap wsm-admin-wrapper wsm-wrap" dir="rtl">';
        self::header('داشبورد مدیریت اپ');

        echo '<div class="wsm-cards">';
        self::card('دستگاه‌های فعال', (int) $counts['active'], '#0e9f6e');
        self::card('مسدودشده', (int) $counts['blocked'], '#e02424');
        self::card('قطع‌شده', (int) $counts['unlinked'], '#b7791f');
        if (current_user_can('manage_options')) { self::card('کل فعالیت‌ها', WSM_Activity_Log::count(), '#1e429f'); }
        echo '</div>';

        echo '<div class="wsm-row">';
        echo '<div class="wsm-panel">';
        echo '<h2>وضعیت اتصال</h2>';
        if ($prefix) {
            $expired = $expires && strtotime($expires) < time();
            echo '<p>کلید فعال: <code>' . esc_html($prefix) . '…</code> ';
            echo $expired
                ? '<span class="wsm-badge wsm-badge-red">منقضی</span>'
                : '<span class="wsm-badge wsm-badge-green">فعال</span>';
            echo ' — انقضا: ' . esc_html(wsm_fa_date_short($expires)) . '</p>';
            echo '<p>آدرس اتصال اپ: <a href="' . esc_url(home_url('/wsm-connect')) . '" target="_blank"><code>' . esc_html(home_url('/wsm-connect')) . '</code></a></p>';
        } else {
            echo '<p>هنوز کلیدی ساخته نشده است. <a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-access-key')) . '">ساخت کلید دسترسی</a></p>';
        }
        echo '<p><a class="button button-primary" href="' . esc_url(home_url('/wsm-connect')) . '" target="_blank">باز کردن صفحهٔ اتصال اپ</a></p>';
        echo '</div>';

        if (current_user_can('manage_options')) {
        echo '<div class="wsm-panel">';
        echo '<h2>آخرین فعالیت‌های اپ</h2>';
        echo '<table class="widefat striped"><thead><tr><th>زمان</th><th>دستگاه</th><th>عملیات</th><th>هدف</th><th>نتیجه</th></tr></thead><tbody>';
        foreach ((array) $logs as $l) {
            echo '<tr>';
            echo '<td>' . esc_html(wsm_fa_date($l['created_at'])) . '</td>';
            echo '<td>' . esc_html($l['device_name'] ?: '—') . '</td>';
            echo '<td>' . esc_html(self::action_label($l['action_type'])) . '</td>';
            echo '<td>' . esc_html($l['entity_name'] ?: $l['entity_type']) . '</td>';
            echo '<td>' . self::result_badge($l['result']) . '</td>';
            echo '</tr>';
        }
        if (!$logs) {
            echo '<tr><td colspan="5">هنوز فعالیتی ثبت نشده است.</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-activity')) . '">مشاهدهٔ همهٔ فعالیت‌ها ←</a></p>';
        echo '</div>';
        }
        echo '</div>';
        echo '</div></div>';
    }

    /* ---------------------------------------------------------------
     * صفحهٔ دستگاه‌ها — تب: همه / فعال / قطع‌شده / مسدود / حذف‌شده
     * ------------------------------------------------------------- */

    public static function page_devices() {
        if (!current_user_can('manage_options')) { wp_die('این بخش فقط برای مدیر کل وردپرس مجاز است.'); }
        $tab = isset($_GET['tab']) && is_scalar($_GET['tab']) ? sanitize_key($_GET['tab']) : 'all';
        $valid_tabs = array('all', 'pending', 'active', 'unlinked', 'blocked', 'deleted', 'blocked_ips');
        if (!in_array($tab, $valid_tabs, true)) {
            $tab = 'all';
        }
        if ($tab === 'blocked_ips' && !current_user_can('manage_options')) {
            wp_die('مشاهده و رفع مسدودی IP فقط برای مدیرکل وردپرس مجاز است.');
        }
        $search = isset($_GET['s']) && is_scalar($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $counts = WSM_Devices::count_by_status();
        $devices = $tab === 'blocked_ips' ? array() : WSM_Devices::query(array(
            'status' => $tab === 'all' ? '' : $tab,
            'search' => $search,
            'limit'  => 100,
        ));
        $ip_count = current_user_can('manage_options') ? WSM_Security::blocked_ip_count() : 0;
        $ip_blocks = $tab === 'blocked_ips' ? WSM_Security::blocked_ip_records(array('search' => $search, 'limit' => 100)) : array();
        $ip_history = $tab === 'blocked_ips' ? WSM_Security::ip_block_history(array('search' => $search, 'limit' => 50)) : array();

        $tabs = array(
            'all'      => 'همه (' . $counts['all'] . ')',
            'pending'  => 'در انتظار (' . $counts['pending'] . ')',
            'active'   => 'فعال (' . $counts['active'] . ')',
            'unlinked' => 'قطع‌شده (' . $counts['unlinked'] . ')',
            'blocked'  => 'دستگاه مسدود (' . $counts['blocked'] . ')',
            'deleted'  => 'حذف‌شده (' . $counts['deleted'] . ')',
        );
        if (current_user_can('manage_options')) {
            $tabs['blocked_ips'] = 'IPهای مسدود (' . $ip_count . ')';
        }

        echo '<div class="wrap wsm-admin-wrapper wsm-wrap wsm-devices-page" dir="rtl">';
        self::header('دستگاه‌های متصل');

        if (isset($_GET['done'])) {
            echo '<div class="notice notice-success is-dismissible"><p>عملیات با موفقیت انجام شد.</p></div>';
        }
        if (isset($_GET['device_error'])) {
            echo '<div class="notice notice-error"><p>عملیات دستگاه انجام نشد؛ وضعیت و پایگاه داده را بررسی کنید.</p></div>';
        }
        if (isset($_GET['ip_unblocked'])) {
            echo '<div class="notice notice-success is-dismissible"><p>رکوردهای مسدودی افزونه و تلاش‌های ناموفق برای این IP پاک شد. اگر اتصال همچنان برقرار نشد، وضعیت کلید دسترسی و مسیریابی شبکه را بررسی کنید.</p></div>';
        }
        if (isset($_GET['ip_error'])) {
            echo '<div class="notice notice-error"><p>رفع مسدودی انجام نشد؛ IPv4/IPv6 را بررسی کنید یا گزارش امنیت را ببینید.</p></div>';
        }

        echo '<ul class="subsubsub wsm-device-tabs">';
        $i = 0;
        foreach ($tabs as $key => $label) {
            $active = $tab === $key ? ' class="current"' : '';
            echo ($i++ > 0 ? ' | ' : '') . '<li><a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-devices&tab=' . $key)) . '"' . $active . '>' . esc_html($label) . '</a></li>';
        }
        echo '</ul>';

        echo '<form method="get" class="wsm-device-search">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG . '-devices') . '">';
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
        $search_placeholder = $tab === 'blocked_ips' ? 'جستجو در IP یا علت مسدودی…' : 'جستجوی نام دستگاه، IP یا پلتفرم…';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr($search_placeholder) . '"> ';
        echo '<button class="button">جستجو</button>';
        echo '</form>';

        if ($tab !== 'blocked_ips') {
            $auto_approve = get_option('wsm_auto_approve_devices', 1) ? 1 : 0;
            $employee_users = get_users(array('number' => 500, 'orderby' => 'display_name', 'order' => 'ASC'));
            echo '<div class="wsm-grid wsm-device-setup-grid">';
            echo '<div class="wsm-panel"><h2>سیاست تأیید دستگاه</h2><form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-devices')) . '">';
            wp_nonce_field('wsm_save_device_policy');
            echo '<input type="hidden" name="wsm_action" value="save_device_policy"><label><input type="checkbox" name="auto_approve_devices" value="1" ' . checked($auto_approve, 1, false) . '> تأیید خودکار دستگاه هنگام ورود معتبر</label><p><button class="button button-primary" type="submit">ذخیرهٔ سیاست</button></p></form></div>';
            echo '<div class="wsm-panel"><h2>افزودن دستی دستگاه</h2><p>با کلید دسترسی معتبر، مالک از روی کلید تعیین می‌شود؛ در غیر این صورت کاربر و IP را وارد کنید. دستگاه ثبت‌شده تا فعال‌سازی مدیر ورود ندارد.</p><form method="post" class="wsm-device-add-form" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-devices')) . '">';
            wp_nonce_field('wsm_add_device_manual');
            echo '<input type="hidden" name="wsm_action" value="add_device_manual"><p><label>کلید دسترسی (اختیاری)<br><input class="regular-text" type="password" name="access_key" autocomplete="off" dir="ltr"></label></p><p><label>کاربر (برای افزودن با IP)<br><select name="user_id"><option value="0">انتخاب کاربر</option>';
            foreach ($employee_users as $employee_user) { echo '<option value="' . (int) $employee_user->ID . '">' . esc_html($employee_user->display_name . ' — ' . $employee_user->user_login) . '</option>'; }
            echo '</select></label></p><p><label>نام دستگاه<br><input class="regular-text" name="device_name" value="دستگاه دستی"></label></p><p><label>سیستم‌عامل / پلتفرم<br><input class="regular-text" name="platform" value="Manual"></label></p><p><label>IP (برای ثبت دستی بدون کلید الزامی)<br><input class="regular-text" name="ip_address" dir="ltr" inputmode="text"></label></p><button class="button button-primary" type="submit">ثبت دستگاه</button></form></div></div>';
        }

        if ($tab === 'blocked_ips') {
            $current_ip = WSM_Util::client_ip();
            $unblock_url = admin_url('admin.php?page=' . self::SLUG . '-devices&tab=blocked_ips');
            echo '<div class="notice notice-info inline"><p>این فهرست مربوط به محدودکنندهٔ نرخ خود افزونه است: پس از ۱۰ تلاش ناموفق در ۱۵ دقیقه، IP برای ۵ دقیقه مسدود می‌شود. رکوردهای جاری و تاریخچهٔ رویدادهای مسدودی تا ۹۰ روز نمایش داده می‌شوند. مسدودی فایروال هاست/CDN از این صفحه قابل تغییر نیست.</p>';
            echo '<p>IP مبدأیی که این صفحه از سرور دریافت کرده است (REMOTE_ADDR): <code dir="ltr">' . esc_html($current_ip ?: 'نامشخص') . '</code>. پشت پراکسی/CDN ممکن است این IP با IP واقعی موبایل فرق داشته باشد.</p></div>';
            echo '<div class="wsm-panel"><h2>رفع مسدودی دستی IP</h2>';
            echo '<p>اگر IP در فهرست نیست، IPv4 یا IPv6 دستگاه را اینجا وارد کنید. این عملیات همهٔ رکوردهای مسدودی افزونه و تلاش‌های ناموفق همان IP را پاک می‌کند؛ محدودیت شبکه یا فایروال بیرونی را تغییر نمی‌دهد.</p>';
            echo '<form method="post" class="wsm-device-unblock-form" action="' . esc_url($unblock_url) . '">';
            wp_nonce_field('wsm_ip_unblock');
            echo '<input type="hidden" name="wsm_action" value="ip_unblock">';
            echo '<label for="wsm-unblock-ip">IP مورد نظر</label> <input class="wsm-device-ip-input" id="wsm-unblock-ip" name="ip_address" type="text" dir="ltr" inputmode="text" autocomplete="off" required placeholder="مثلاً 203.0.113.10"> ';
            echo '<button type="submit" class="button button-primary">پاک‌کردن مسدودی افزونه</button></form></div>';

            echo '<div class="wsm-panel wsm-device-table-panel"><h2>رکوردهای مسدودی IP</h2><div class="wsm-device-table-wrap wsm-device-table-ip"><table class="widefat striped"><thead><tr><th>IP</th><th>علت</th><th>آخرین زمان مسدودی</th><th>پایان/وضعیت</th><th>تعداد دفعات</th><th>عملیات</th></tr></thead><tbody>';
            foreach ((array) $ip_blocks as $block) {
                $active_block = strcmp((string) $block->blocked_until, WSM_Util::now()) > 0;
                echo '<tr><td><code dir="ltr">' . esc_html($block->ip_address) . '</code></td>';
                echo '<td>' . esc_html($block->reason) . '</td>';
                echo '<td>' . esc_html(wsm_fa_date($block->created_at)) . '</td>';
                echo '<td>' . ($active_block ? '<span class="wsm-badge wsm-badge-red">فعال تا ' . esc_html(wsm_fa_date($block->blocked_until)) . '</span>' : '<span class="wsm-badge wsm-badge-grey">منقضی‌شده</span>') . '</td>';
                echo '<td>' . (int) $block->block_count . '</td><td>';
                echo '<form method="post" class="wsm-device-row-form" action="' . esc_url($unblock_url) . '">';
                wp_nonce_field('wsm_ip_unblock');
                echo '<input type="hidden" name="wsm_action" value="ip_unblock"><input type="hidden" name="ip_address" value="' . esc_attr($block->ip_address) . '">';
                echo '<button type="submit" class="button button-small' . ($active_block ? ' button-primary' : '') . '">' . ($active_block ? 'رفع مسدودی' : 'حذف سابقه') . '</button></form></td></tr>';
            }
            if (!$ip_blocks) {
                echo '<tr><td colspan="6">رکورد جاری/باقی‌مانده‌ای در جدول مسدودی افزونه نیست؛ ممکن است بازهٔ ۳۰ دقیقه‌ای تمام و رکورد پاک شده باشد. تاریخچهٔ اخیر را پایین صفحه بررسی کنید.</td></tr>';
            }
            echo '</tbody></table></div></div>';

            echo '<div class="wsm-panel wsm-device-table-panel"><h2>تاریخچهٔ اخیر مسدودی‌ها</h2><div class="wsm-device-table-wrap wsm-device-table-history"><table class="widefat striped"><thead><tr><th>IP</th><th>رویداد</th><th>زمان</th><th>اقدام</th></tr></thead><tbody>';
            foreach ((array) $ip_history as $event) {
                echo '<tr><td><code dir="ltr">' . esc_html($event->ip_address) . '</code></td><td>' . esc_html($event->details) . '</td><td>' . esc_html(wsm_fa_date($event->created_at)) . '</td><td>';
                echo '<form method="post" class="wsm-device-row-form" action="' . esc_url($unblock_url) . '">';
                wp_nonce_field('wsm_ip_unblock');
                echo '<input type="hidden" name="wsm_action" value="ip_unblock"><input type="hidden" name="ip_address" value="' . esc_attr($event->ip_address) . '">';
                echo '<button type="submit" class="button button-small">پاک‌سازی IP</button></form></td></tr>';
            }
            if (!$ip_history) {
                echo '<tr><td colspan="4">رویداد مسدودی ثبت‌شده‌ای در تاریخچهٔ نگهداری‌شده پیدا نشد.</td></tr>';
            }
            echo '</tbody></table></div></div></div></div>';
            return;
        }

        if (isset($_GET['permissions_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>مجوزهای دستگاه ذخیره شد.</p></div>';
        }
        if (isset($_GET['permission_error'])) {
            echo '<div class="notice notice-error"><p>ذخیرهٔ مجوزهای دستگاه ناموفق بود. دستگاه و جدول دیتابیس را بررسی کنید.</p></div>';
        }
        echo '<div class="notice notice-info inline"><p>قطع دسترسی نشست را باطل می‌کند و به معنی مسدودکردن IP نیست. برای اتصال دوباره، صفحهٔ <a href="' . esc_url(home_url('/wsm-connect')) . '">اتصال اپ</a> را باز و Access Key فعال را دوباره وارد کنید؛ handshake تازه یک دستگاه read-only جدید می‌سازد و مجوزهای آن از دستگاه قبلی به ارث نمی‌رسد. اگر خطای مسدودی می‌بینید، تب «IPهای مسدود» را بررسی کنید. اگر Access Key افشا شده، آن را بچرخانید؛ فقط مدیرکل می‌تواند مجوز امنیت و TOTP را واگذار کند.</p></div>';

        if ($tab !== 'deleted') {
            echo '<div class="tablenav top wsm-device-bulk-toolbar" data-wsm-device-bulk data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('wsm_bulk_devices')) . '"><div class="alignleft actions bulkactions">';
            echo '<label class="screen-reader-text" for="wsm-device-bulk-action">عملیات دسته‌جمعی دستگاه‌ها</label><select id="wsm-device-bulk-action" name="bulk_action"><option value="">عملیات دسته‌جمعی</option><option value="revoke">قطع دسترسی</option><option value="delete">حذف کامل (انتقال به فهرست حذف‌شده‌ها؛ حفظ سوابق)</option></select> ';
            echo '<button type="button" class="button action wsm-device-bulk-apply" disabled>اجرا</button> <span class="description wsm-device-bulk-status" role="status" aria-live="polite"></span>';
            echo '</div><br class="clear"></div>';
        }
        $bulk_checkbox_disabled = $tab === 'deleted' ? ' disabled' : '';
        echo '<div class="wsm-panel wsm-device-table-panel"><div class="wsm-device-table-wrap wsm-device-table-wide"><table class="widefat striped">';
        echo '<thead><tr><th class="check-column"><input type="checkbox" class="wsm-device-select-all" aria-label="انتخاب همهٔ دستگاه‌های قابل‌اقدام"' . $bulk_checkbox_disabled . '></th><th>نام دستگاه</th><th>سیستم‌عامل</th><th>IP</th><th>کلید دسترسی</th><th>وضعیت</th><th>آخرین اتصال</th><th>کاربر</th><th>مجوزهای مؤثر</th><th>عملیات</th></tr></thead><tbody>';

        global $wpdb;
        foreach ((array) $devices as $device_row) {
            $d    = WSM_Devices::format($device_row);
            $user = $d['user_id'] ? get_userdata((int) $d['user_id']) : null;
            $key_row = $d['user_id'] ? $wpdb->get_row($wpdb->prepare("SELECT key_prefix, expires_at FROM {$wpdb->prefix}wsm_access_keys WHERE user_id = %d AND revoked_at IS NULL AND key_hash <> '' ORDER BY id DESC LIMIT 1", (int) $d['user_id'])) : null;
            $key_prefix = $key_row ? $key_row->key_prefix : ((int) get_option('wsm_access_key_user', 0) === (int) $d['user_id'] ? get_option('wsm_access_key_prefix', '') : '');
            $key_expiry = $key_row ? $key_row->expires_at : ((int) get_option('wsm_access_key_user', 0) === (int) $d['user_id'] ? get_option('wsm_access_key_expires', '') : '');
            $row_checkbox_disabled = $d['status'] === 'deleted' ? ' disabled' : '';
            echo '<tr><th scope="row" class="check-column"><input type="checkbox" class="wsm-device-row-select" value="' . (int) $d['id'] . '" aria-label="انتخاب دستگاه ' . esc_attr($d['device_name']) . '"' . $row_checkbox_disabled . '></th>';
            echo '<td><form method="post" class="wsm-device-name-form" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-devices')) . '"><input type="hidden" name="wsm_action" value="save_device_name"><input type="hidden" name="device_id" value="' . (int) $d['id'] . '">';
            wp_nonce_field('wsm_save_device_name');
            echo '<input class="regular-text" name="device_name" value="' . esc_attr($d['device_name']) . '" maxlength="100" aria-label="نام دستگاه"><button class="button button-small" type="submit">ذخیره</button></form><small>' . esc_html($d['app_version']) . '</small></td>';
            echo '<td>' . esc_html(trim($d['platform'] . ' ' . $d['os_version'])) . '</td>';
            echo '<td><code dir="ltr">' . esc_html($d['ip_address'] ?: '—') . '</code></td>';
            echo '<td><code dir="ltr">' . esc_html($key_prefix ? $key_prefix . '…' : '—') . '</code>' . ($key_expiry ? '<br><small>انقضا: ' . esc_html(wsm_fa_date($key_expiry)) . '</small>' : '') . '</td>';
            echo '<td>' . self::device_badge($d['status']) . '</td>';
            echo '<td>' . esc_html($d['last_seen'] ?: '—') . '</td>';
            echo '<td>' . esc_html($user ? $user->display_name . ' (' . $user->user_login . ')' : '—') . '</td>';
            echo '<td><details class="wsm-device-permission-details"><summary>' . count($d['permissions']) . ' مجوز فعال — ویرایش</summary>';
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-devices')) . '" class="wsm-device-permissions">';
            wp_nonce_field('wsm_save_device_permissions');
            echo '<input type="hidden" name="wsm_action" value="save_device_permissions">';
            echo '<input type="hidden" name="device_id" value="' . (int) $d['id'] . '">';
            echo '<div class="wsm-permission-bulk"><label><input type="checkbox" class="wsm-permission-select-all"> <span>انتخاب همهٔ دسترسی‌های قابل واگذاری</span></label><span class="wsm-permission-bulk-status" aria-live="polite"></span></div>';
            foreach (WSM_Devices::permission_groups() as $group_label => $group_permissions) {
                echo '<fieldset class="wsm-device-permission-group"><legend><strong>' . esc_html($group_label) . '</strong></legend><div class="wsm-device-permission-list">';
                foreach ($group_permissions as $permission_key => $permission_label) {
                    $checked = in_array($permission_key, $d['permissions'], true);
                    $owner = $permission_key === 'security.manage' ? get_userdata((int) $d['user_id']) : null;
                    $security_allowed = current_user_can('manage_options') && $owner && user_can($owner, 'manage_options');
                    $disabled = ($permission_key === 'security.manage' && !$security_allowed) ? ' disabled' : '';
                    echo '<label class="wsm-device-permission-item"><input type="checkbox" name="wsm_permissions[]" value="' . esc_attr($permission_key) . '" ' . checked($checked, true, false) . $disabled . '> <span>' . esc_html($permission_label) . '</span></label>';
                }
                echo '</div></fieldset>';
            }
            if (!current_user_can('manage_options')) {
                echo '<p class="description">گزینهٔ مدیریت امنیت فقط توسط مدیرکل تغییرپذیر است.</p>';
            } elseif (!user_can((int) $d['user_id'], 'manage_options')) {
                echo '<p class="description">مجوز مدیریت امنیت برای این حساب غیرفعال است؛ دستگاه باید به Access Key حساب مدیرکل متصل باشد.</p>';
            }
            echo '<button type="submit" class="button button-small button-primary">ذخیرهٔ مجوزها</button></form></details></td>';
            echo '<td class="wsm-actions">';

            $base = admin_url('admin.php?page=' . self::SLUG . '-devices&device_id=' . (int) $d['id']);
            if ($d['status'] === 'active') {
                echo '<a class="button button-small" href="' . esc_url(wp_nonce_url($base . '&wsm_action=device_unlink', 'wsm_device_unlink')) . '">قطع دسترسی</a> ';
                echo '<a class="button button-small" href="' . esc_url(wp_nonce_url($base . '&wsm_action=device_block', 'wsm_device_block')) . '">مسدود</a> ';
            } elseif ($d['status'] === 'blocked') {
                echo '<a class="button button-small" href="' . esc_url(wp_nonce_url($base . '&wsm_action=device_unblock', 'wsm_device_unblock')) . '">رفع مسدودی</a> ';
            } elseif (in_array($d['status'], array('pending', 'unlinked'), true)) {
                echo '<a class="button button-small button-primary" href="' . esc_url(wp_nonce_url($base . '&wsm_action=device_approve', 'wsm_device_approve')) . '">تأیید و فعال‌سازی</a> ';
                echo '<a class="button button-small" href="' . esc_url(wp_nonce_url($base . '&wsm_action=device_activate', 'wsm_device_activate')) . '">فعال‌سازی</a> ';
            }
            if ($d['status'] !== 'deleted') {
                echo '<a class="button button-small button-link-delete" href="' . esc_url(wp_nonce_url($base . '&wsm_action=device_delete', 'wsm_device_delete')) . '" onclick="return confirm(\'دستگاه حذف شود؟\')">حذف</a>';
            }
            $activity_url = add_query_arg(
                array('page' => self::SLUG . '-activity', 'device_id' => (int) $d['id']),
                admin_url('admin.php')
            );
            echo ' <a class="button button-small" href="' . esc_url($activity_url) . '">فعالیت‌ها</a>';
            echo '</td>';
            echo '</tr>';
        }
        if (!$devices) {
            echo '<tr><td colspan="10">دستگاهی با این فیلتر پیدا نشد.</td></tr>';
        }
        echo '</tbody></table></div></div>';
        echo '</div></div>';
    }

    /* ---------------------------------------------------------------
     * فعالیت‌ها و خروجی CSV
     * ------------------------------------------------------------- */
    public static function page_employees() {
        if (!current_user_can('manage_options')) { wp_die('این بخش فقط برای مدیر کل وردپرس مجاز است.'); }
        $list = WSM_Auth::list_app_users();
        $users = is_wp_error($list) ? array() : (array) $list['items'];
        $groups = WSM_Devices::permission_groups();
        $presets = WSM_Permissions::presets();
        $edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        $editing = false;
        foreach ($users as $candidate) { if ((int) $candidate['id'] === $edit_id) { $editing = $candidate; break; } }
        $selected_preset = $editing ? $editing['preset'] : 'custom';
        if (!isset($presets[$selected_preset])) { $selected_preset = 'custom'; }
        $selected_permissions = $editing ? (array) $editing['permissions'] : (array) $presets[$selected_preset]['permissions'];
        $locked = $editing && $selected_preset === 'super_admin';
        echo '<div class="wrap wsm-admin-wrapper wsm-wrap wsm-employees-page" dir="rtl">';
        self::header('مدیریت کارمندان');
        if (isset($_GET['employee_saved'])) {
            $created_login_data = get_transient('wsm_employee_created_login_' . get_current_user_id());
            delete_transient('wsm_employee_created_login_' . get_current_user_id());
            $created_login = is_array($created_login_data) ? (isset($created_login_data['login']) ? (string) $created_login_data['login'] : '') : (is_scalar($created_login_data) ? (string) $created_login_data : '');
            $created_alias = is_array($created_login_data) && isset($created_login_data['alias']) ? (string) $created_login_data['alias'] : '';
            $login_notice = $created_alias !== '' && $created_alias !== $created_login
                ? ' نام ورود: <code dir="ltr">' . esc_html($created_alias) . '</code> — شناسهٔ فنی وردپرس: <code dir="ltr">' . esc_html($created_login) . '</code>.'
                : ($created_login !== '' ? ' نام کاربری نهایی: <code dir="ltr">' . esc_html($created_login) . '</code>' : '');
            echo '<div class="notice notice-success is-dismissible"><p>اطلاعات کارمند ذخیره شد.' . $login_notice . '</p></div>';
        }
        if (isset($_GET['employee_error'])) {
            $employee_error = get_transient('wsm_employee_error_' . get_current_user_id());
            delete_transient('wsm_employee_error_' . get_current_user_id());
            $employee_error_message = is_array($employee_error) && !empty($employee_error['message']) ? (string) $employee_error['message'] : 'ذخیره انجام نشد. کد خطا: ' . (isset($_GET['employee_error']) ? sanitize_key(wp_unslash($_GET['employee_error'])) : 'unknown');
            echo '<div class="notice notice-error"><p>' . esc_html($employee_error_message) . '</p></div>';
        }
        echo '<div class="wsm-panel"><h2>' . ($editing ? 'ویرایش کارمند: ' . esc_html($editing['display_name']) : 'افزودن کارمند') . '</h2>';
        echo '<p class="description wsm-employee-help">نام کاربری فارسی نیز برای ورود پذیرفته می‌شود؛ وردپرس یک شناسهٔ فنی ASCII نگه می‌دارد و نام فارسی به‌عنوان نام ورود محفوظ می‌ماند. نام کاربری را می‌توانید خالی بگذارید تا به‌صورت یکتا ساخته شود؛ اگر نام انتخابی قبلاً استفاده شده باشد، سامانه پسوند یکتا اضافه می‌کند. رمز عبور هنگام افزودن اجباری و حداقل ۶ نویسه است؛ برای حفظ رمز در ویرایش، فیلد را خالی بگذارید.</p>';
        echo '<form method="post" class="form-wrap wsm-employee-form" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-employees')) . '">';
        wp_nonce_field('wsm_employee_save');
        echo '<input type="hidden" name="wsm_action" value="employee_save"><input type="hidden" name="user_id" value="' . (int) ($editing ? $editing['id'] : 0) . '">';
        echo '<table class="form-table wsm-employee-form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="wsm-employee-user-login">نام کاربری' . ($editing ? '' : ' (اختیاری)') . '</label></th><td><input id="wsm-employee-user-login" class="regular-text" type="text" name="user_login" value="' . esc_attr($editing ? $editing['user_login'] : '') . '" ' . ($editing ? 'readonly' : '') . ' autocomplete="username" dir="auto">' . (!$editing ? '<p class="description">نام فارسی هم پذیرفته می‌شود؛ همان نام را هنگام ورود وارد کنید. خالی بگذارید تا نام فنی یکتا ساخته شود.</p>' : '') . '</td></tr>';
        if ($editing) {
            echo '<tr><th scope="row"><label for="wsm-employee-login-alias">نام جایگزین برای ورود</label></th><td><input id="wsm-employee-login-alias" class="regular-text" type="text" name="login_alias" value="' . esc_attr(isset($editing['user_login_alias']) ? $editing['user_login_alias'] : '') . '" autocomplete="username" dir="auto"><p class="description">برای حساب‌های قدیمی، نام فارسی مثل «تست» را اینجا ثبت کنید تا کارمند با همان نام وارد شود؛ خالی‌کردن فیلد، نام جایگزین را حذف می‌کند.</p></td></tr>';
        }
        echo '<tr><th scope="row"><label for="wsm-employee-password">' . ($editing ? 'رمز عبور تازه (اختیاری)' : 'رمز عبور *') . '</label></th><td><input id="wsm-employee-password" class="regular-text" type="password" name="password" minlength="6" ' . (!$editing ? 'required' : '') . ' autocomplete="new-password" dir="ltr"></td></tr>';
        echo '<tr><th scope="row"><label for="wsm-employee-display-name">نام کامل</label></th><td><input id="wsm-employee-display-name" class="regular-text" type="text" name="display_name" value="' . esc_attr($editing ? $editing['display_name'] : '') . '"></td></tr>';
        echo '<tr><th scope="row"><label for="wsm-employee-phone">موبایل</label></th><td><input id="wsm-employee-phone" class="regular-text" type="tel" name="phone" value="' . esc_attr($editing ? $editing['phone'] : '') . '" dir="ltr"></td></tr>';
        echo '<tr><th scope="row"><label for="wsm-employee-email">ایمیل</label></th><td><input id="wsm-employee-email" class="regular-text" type="email" name="user_email" value="' . esc_attr($editing ? $editing['user_email'] : '') . '" dir="ltr"></td></tr>';
        echo '<tr><th scope="row"><label for="wsm-employee-preset">نقش</label></th><td><select class="regular-text" name="preset" id="wsm-employee-preset" ' . ($locked ? 'disabled' : '') . '>';
        foreach ($presets as $preset_key => $preset_data) {
            if ($preset_key === 'super_admin' && !$editing && !current_user_can('manage_options')) { continue; }
            $option_permissions = esc_attr(wp_json_encode(array_values($preset_data['permissions']), JSON_UNESCAPED_UNICODE));
            echo '<option value="' . esc_attr($preset_key) . '" data-permissions="' . $option_permissions . '" ' . selected($selected_preset, $preset_key, false) . '>' . esc_html($preset_data['label'] . ' — ' . count($preset_data['permissions']) . ' مجوز') . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="wsm-employee-status">وضعیت</label></th><td><select class="regular-text" name="status" id="wsm-employee-status" ' . ($locked ? 'disabled' : '') . '><option value="active" ' . selected($editing ? $editing['status'] : 'active', 'active', false) . '>فعال</option><option value="inactive" ' . selected($editing ? $editing['status'] : 'active', 'inactive', false) . '>غیرفعال</option></select></td></tr>';
        $permission_total = count(WSM_Devices::permission_catalog());
        $permission_tools_disabled = $locked ? ' disabled' : '';
        echo '</tbody></table>';
        echo '<div class="wsm-employee-permission-heading"><h3>مجوزهای کارمند <span class="wsm-permission-selected-count" data-wsm-permission-count data-total="' . (int) $permission_total . '">' . count($selected_permissions) . ' از ' . (int) $permission_total . '</span></h3><div class="wsm-employee-permission-global-actions"><button type="button" class="button button-small" data-wsm-permission-action="select-all"' . $permission_tools_disabled . '>انتخاب همه</button><button type="button" class="button button-small" data-wsm-permission-action="clear-all"' . $permission_tools_disabled . '>لغو انتخاب همه</button></div></div>';
        echo '<div class="wsm-employee-permission-toolbar"><label for="wsm-employee-permission-search">جستجوی مجوزها</label><input id="wsm-employee-permission-search" class="regular-text" type="search" autocomplete="off" placeholder="نام مجوز یا کلید؛ مثلاً orders"><span class="description">با نام فارسی یا کلید مجوز جستجو کنید؛ انتخاب همه فقط موارد نمایش‌داده‌شده را شامل می‌شود.</span></div>';
        echo '<p class="wsm-employee-permission-empty" data-wsm-permission-empty hidden>مجوزی با این عبارت پیدا نشد.</p>';
        echo '<div class="wsm-permission-tree wsm-employee-permissions">';
        foreach ($groups as $group_name => $permissions) {
            echo '<details class="wsm-panel wsm-employee-permission-group"><summary><strong>' . esc_html($group_name) . '</strong> <span class="description">' . count($permissions) . ' مجوز</span></summary><div class="wsm-employee-permission-group-actions"><button type="button" class="button button-small" data-wsm-permission-action="select-group"' . $permission_tools_disabled . '>انتخاب همه</button><button type="button" class="button button-small" data-wsm-permission-action="clear-group"' . $permission_tools_disabled . '>لغو انتخاب همه</button></div><div class="wsm-grid wsm-grid-2 wsm-employee-permission-grid">';
            foreach ($permissions as $permission => $label) {
                echo '<label class="wsm-employee-permission-item"><input class="wsm-permission-check" type="checkbox" name="permissions[]" value="' . esc_attr($permission) . '" ' . checked(in_array($permission, $selected_permissions, true), true, false) . ' ' . ($locked ? 'disabled' : '') . '><span>' . esc_html($label) . '<br><code dir="ltr">' . esc_html($permission) . '</code></span></label>';
            }
            echo '</div></details>';
        }
        echo '</div><p><button class="button button-primary" type="submit">ذخیرهٔ کارمند و مجوزها</button> ';
        if ($editing) { echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-employees')) . '">انصراف</a>'; }
        echo '</p></form></div>';
        echo '<div class="wsm-panel wsm-employee-table-panel" data-wsm-employee-bulk data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('wsm_bulk_employees')) . '"><h2>فهرست کارمندان</h2>';
        echo '<div class="wsm-employee-table-toolbar">';
        echo '<div class="wsm-employee-bulk-actions"><label class="screen-reader-text" for="wsm-employee-bulk-action">عملیات دسته‌جمعی کاربران</label><select id="wsm-employee-bulk-action" name="bulk_action"><option value="">عملیات دسته‌جمعی</option><option value="activate">تغییر وضعیت به فعال</option><option value="deactivate">تغییر وضعیت به غیرفعال</option><option value="delete">حذف کامل</option></select><button type="button" class="button button-primary wsm-employee-bulk-apply" disabled>اجرا</button></div>';
        echo '<div class="wsm-employee-table-search"><label class="screen-reader-text" for="wsm-employee-search">جستجوی کارمند بر اساس نام یا ایمیل</label><input id="wsm-employee-search" class="regular-text" type="search" autocomplete="off" placeholder="جستجو بر اساس نام یا ایمیل…"></div><span class="description wsm-employee-bulk-status" role="status" aria-live="polite"></span></div>';
        echo '<div class="wsm-employee-table-wrap"><table class="widefat striped"><thead><tr><th scope="col" class="check-column"><input type="checkbox" class="wsm-employee-select-all" aria-label="انتخاب همهٔ کارمندان قابل‌اقدام"></th><th>نام کاربری</th><th>نام کامل</th><th>نقش</th><th>موبایل</th><th>ایمیل</th><th>آخرین ورود</th><th>وضعیت</th><th>دستگاه‌ها</th><th>مجوزها</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($users as $employee) {
            $edit_url = add_query_arg(array('page' => self::SLUG . '-employees', 'edit' => (int) $employee['id']), admin_url('admin.php'));
            $activity_url = add_query_arg(array('page' => self::SLUG . '-activity', 'user_id' => (int) $employee['id']), admin_url('admin.php'));
            $next_status = $employee['status'] === 'active' ? 'inactive' : 'active';
            $status_url = wp_nonce_url(add_query_arg(array('page' => self::SLUG . '-employees', 'wsm_action' => 'employee_deactivate', 'user_id' => (int) $employee['id'], 'status' => $next_status), admin_url('admin.php')), 'wsm_employee_deactivate');
            $last_login = $employee['last_login'] !== '' ? wsm_fa_date($employee['last_login']) : '—';
            $bulk_protected = $employee['preset'] === 'super_admin' || (int) $employee['id'] === (int) get_current_user_id();
            $row_checkbox_disabled = $bulk_protected ? ' disabled' : '';
            $status_badge = !empty($employee['deleted_at']) ? '<span class="wsm-badge">حذف نرم‌شده</span>' : ($employee['status'] === 'active' ? '<span class="wsm-badge wsm-badge-success">فعال</span>' : '<span class="wsm-badge">غیرفعال</span>');
            echo '<tr data-wsm-employee-row data-employee-name="' . esc_attr($employee['display_name']) . '" data-employee-email="' . esc_attr($employee['user_email']) . '"><th scope="row" class="check-column"><input type="checkbox" class="wsm-employee-row-select" value="' . (int) $employee['id'] . '" aria-label="انتخاب کارمند ' . esc_attr($employee['display_name']) . '"' . $row_checkbox_disabled . '></th><td><code dir="ltr">' . esc_html($employee['user_login']) . '</code>' . (!empty($employee['user_login_alias']) ? '<br><span class="description">ورود: <code dir="auto">' . esc_html($employee['user_login_alias']) . '</code></span>' : '') . '</td><td>' . esc_html($employee['display_name']) . '</td><td>' . esc_html($employee['role_label']) . '</td><td>' . esc_html($employee['phone'] ?: '—') . '</td><td>' . esc_html($employee['user_email'] ?: '—') . '</td><td>' . esc_html($last_login) . '</td><td>' . $status_badge . '</td><td>' . (int) $employee['device_count'] . '</td><td><strong>' . (int) $employee['permission_count'] . '</strong> / ' . count(WSM_Devices::permission_catalog()) . '</td><td><a class="button button-small" href="' . esc_url($edit_url) . '">ویرایش / رمز</a> <a class="button button-small" href="' . esc_url($activity_url) . '">فعالیت</a> ';
            if ($employee['preset'] !== 'super_admin') {
                $status_confirm = $next_status === 'inactive' ? 'حساب کارمند غیرفعال شود؟' : (!empty($employee['deleted_at']) ? 'حساب حذف‌شده بازگردانی و دوباره فعال شود؟' : 'حساب کارمند دوباره فعال شود؟');
                $status_label = $next_status === 'inactive' ? 'غیرفعال‌سازی' : (!empty($employee['deleted_at']) ? 'بازگردانی' : 'فعال‌سازی');
                echo '<a class="button button-small" href="' . esc_url($status_url) . '" onclick="return confirm(' . esc_attr(wp_json_encode($status_confirm)) . ')">' . $status_label . '</a>';
            } else { echo '<span class="description">مدیر کل — حفاظت‌شده</span>'; }
            echo '</td></tr>';
        }
        echo '<tr class="wsm-employee-no-results" hidden><td colspan="11">کارمندی با این نام یا ایمیل پیدا نشد.</td></tr>';
        if (!$users) { echo '<tr class="wsm-employee-empty-row"><td colspan="11">کارمندی ثبت نشده است.</td></tr>'; }
        echo '</tbody></table></div></div>';
        echo '</div></div>';
    }

    public static function page_roles() {
        if (!current_user_can('manage_options')) { wp_die('این بخش فقط برای مدیر کل وردپرس مجاز است.'); }
        $presets = WSM_Permissions::presets();
        $custom = WSM_Permissions::custom_roles();
        $groups = WSM_Devices::permission_groups();
        $edit_key = isset($_GET['edit_role']) ? sanitize_key(wp_unslash($_GET['edit_role'])) : '';
        $editing = isset($custom[$edit_key]) ? $custom[$edit_key] : null;
        echo '<div class="wrap wsm-admin-wrapper wsm-wrap" dir="rtl">';
        self::header('نقش‌ها و مجوزها');
        if (isset($_GET['role_saved'])) { echo '<div class="notice notice-success is-dismissible"><p>نقش ذخیره شد و دسترسی کارمندانی که از آن استفاده می‌کنند همگام شد.</p></div>'; }
        if (isset($_GET['role_error'])) { echo '<div class="notice notice-error"><p>ذخیرهٔ نقش انجام نشد. عنوان نقش و فهرست مجوزها را بررسی کنید.</p></div>'; }
        echo '<div class="wsm-panel"><h2>نقش‌های پیش‌فرض</h2><p class="description">مدیر کل همهٔ مجوزها را دارد و تغییرپذیر نیست. نقش‌های پیش‌فرض را برای کارمندان انتخاب کنید یا یک نقش ترکیبی بسازید.</p><table class="widefat striped"><thead><tr><th>نقش</th><th>کلید</th><th>تعداد مجوز</th><th>نوع</th></tr></thead><tbody>';
        foreach ($presets as $role_key => $role) {
            $is_custom = isset($custom[$role_key]);
            echo '<tr><td>' . esc_html($role['label']) . '</td><td><code dir="ltr">' . esc_html($role_key) . '</code></td><td><strong>' . count($role['permissions']) . '</strong> / ' . count(WSM_Devices::permission_catalog()) . '</td><td>' . ($is_custom ? 'سفارشی' : ($role_key === 'super_admin' ? 'غیرقابل‌تغییر' : 'پیش‌فرض')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="wsm-panel"><h2>' . ($editing ? 'ویرایش نقش ترکیبی' : 'افزودن نقش ترکیبی') . '</h2><form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-roles')) . '">';
        wp_nonce_field('wsm_role_save');
        echo '<input type="hidden" name="wsm_action" value="role_save"><input type="hidden" name="role_key" value="' . esc_attr($edit_key) . '"><p><label><strong>نام نقش</strong><br><input type="text" class="regular-text" name="role_label" required maxlength="80" value="' . esc_attr($editing ? $editing['label'] : '') . '"></label></p>';
        echo '<h3>مجوزهای نقش ترکیبی (' . ($editing ? count($editing['permissions']) : 0) . ' از ' . count(WSM_Devices::permission_catalog()) . ')</h3><div class="wsm-permission-tree">';
        foreach ($groups as $group_name => $permissions) {
            echo '<details class="wsm-panel" style="margin:8px 0"><summary><strong>' . esc_html($group_name) . '</strong> <span class="description">' . count($permissions) . ' مجوز</span></summary><div class="wsm-grid wsm-grid-2" style="padding-top:12px">';
            foreach ($permissions as $permission => $label) {
                $checked = $editing && in_array($permission, $editing['permissions'], true);
                echo '<label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="permissions[]" value="' . esc_attr($permission) . '" ' . checked($checked, true, false) . '><span>' . esc_html($label) . '<br><code dir="ltr">' . esc_html($permission) . '</code></span></label>';
            }
            echo '</div></details>';
        }
        echo '</div><p><button class="button button-primary" type="submit">ذخیرهٔ نقش و مجوزها</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-roles')) . '">نقش تازه</a></p></form></div>';
        if ($custom) {
            echo '<div class="wsm-panel"><h2>نقش‌های ترکیبی ثبت‌شده</h2><table class="widefat striped"><thead><tr><th>نام</th><th>مجوزها</th><th>عملیات</th></tr></thead><tbody>';
            foreach ($custom as $role_key => $role) {
                $edit_url = add_query_arg(array('page' => self::SLUG . '-roles', 'edit_role' => $role_key), admin_url('admin.php'));
                $delete_url = wp_nonce_url(add_query_arg(array('page' => self::SLUG . '-roles', 'wsm_action' => 'role_delete', 'role_key' => $role_key), admin_url('admin.php')), 'wsm_role_delete');
                $role_delete_confirm = wp_json_encode('این نقش حذف می‌شود؛ دسترسی فعلی کاربران آن حفظ و به نقش سفارشی منتقل می‌شود. ادامه می‌دهید؟');
                echo '<tr><td>' . esc_html($role['label']) . '</td><td>' . count($role['permissions']) . ' / ' . count(WSM_Devices::permission_catalog()) . '</td><td><a class="button button-small" href="' . esc_url($edit_url) . '">ویرایش</a> <a class="button button-small" href="' . esc_url($delete_url) . '" onclick="return confirm(' . esc_attr($role_delete_confirm) . ')">حذف نقش</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
    }

    public static function page_activity() {
        if (!current_user_can('manage_options')) { wp_die('این بخش فقط برای مدیر کل وردپرس مجاز است.'); }
        $args = array(
            'user_id' => isset($_GET['user_id']) && is_scalar($_GET['user_id']) ? (int) $_GET['user_id'] : 0,
            'device_id' => isset($_GET['device_id']) && is_scalar($_GET['device_id']) ? (int) $_GET['device_id'] : 0,
            'action' => isset($_GET['action_type']) && is_scalar($_GET['action_type']) ? sanitize_key(wp_unslash($_GET['action_type'])) : '',
            'result' => isset($_GET['result']) && is_scalar($_GET['result']) ? sanitize_key(wp_unslash($_GET['result'])) : '',
            'date_from' => isset($_GET['date_from']) && is_scalar($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
            'date_to' => isset($_GET['date_to']) && is_scalar($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
            'limit' => 1000,
        );
        $ip_filter = isset($_GET['ip_address']) && is_scalar($_GET['ip_address']) ? trim(sanitize_text_field(wp_unslash($_GET['ip_address']))) : '';
        $logs = WSM_Activity_Log::query($args);
        if ($ip_filter !== '') { $logs = array_values(array_filter($logs, function ($row) use ($ip_filter) { return isset($row['ip_address']) && stripos((string) $row['ip_address'], $ip_filter) !== false; })); }
        $devices = WSM_Devices::query(array('limit' => 500));
        $users = get_users(array('number' => 500, 'orderby' => 'display_name', 'order' => 'ASC'));
        echo '<div class="wrap wsm-admin-wrapper wsm-wrap" dir="rtl">';
        self::header('گزارش فعالیت کاربران');
        echo '<div class="wsm-panel"><form method="get" class="wsm-filter">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG . '-activity') . '">';
        echo '<label>کاربر <select name="user_id"><option value="0">همهٔ کاربران</option>';
        foreach ($users as $activity_user) { echo '<option value="' . (int) $activity_user->ID . '" ' . selected($args['user_id'], (int) $activity_user->ID, false) . '>' . esc_html($activity_user->display_name . ' — ' . $activity_user->user_login) . '</option>'; }
        echo '</select></label> ';
        echo '<label>دستگاه <select name="device_id"><option value="0">همهٔ دستگاه‌ها</option>';
        foreach ((array) $devices as $device) { echo '<option value="' . (int) $device->id . '" ' . selected($args['device_id'], (int) $device->id, false) . '>' . esc_html($device->device_name . ' — ' . $device->platform) . '</option>'; }
        echo '</select></label> ';
        echo '<label>نوع عملیات <select name="action_type"><option value="">همهٔ عملیات</option>';
        foreach (WSM_Activity_Log::ACTIONS as $action) { echo '<option value="' . esc_attr($action) . '" ' . selected($args['action'], $action, false) . '>' . esc_html(self::action_label($action)) . '</option>'; }
        echo '</select></label> ';
        echo '<label>IP <input type="search" name="ip_address" value="' . esc_attr($ip_filter) . '" dir="ltr" placeholder="جستجوی IP"></label> ';
        echo '<label>از تاریخ <input type="date" name="date_from" value="' . esc_attr($args['date_from']) . '"></label> ';
        echo '<label>تا تاریخ <input type="date" name="date_to" value="' . esc_attr($args['date_to']) . '"></label> ';
        echo '<label>نتیجه <select name="result"><option value="">همه</option><option value="success" ' . selected($args['result'], 'success', false) . '>موفق</option><option value="failed" ' . selected($args['result'], 'failed', false) . '>ناموفق</option></select></label> ';
        echo '<button class="button button-primary">اعمال فیلتر</button></form></div>';
        $export_args = array('page' => self::SLUG . '-activity', 'wsm_action' => 'export_activity_excel', 'user_id' => $args['user_id'], 'device_id' => $args['device_id'], 'action_type' => $args['action'], 'result' => $args['result'], 'date_from' => $args['date_from'], 'date_to' => $args['date_to'], 'ip_address' => $ip_filter);
        $export_url = wp_nonce_url(add_query_arg($export_args, admin_url('admin.php')), 'wsm_export_activity_excel');
        echo '<p><a class="button" href="' . esc_url($export_url) . '">خروجی Excel (.xls)</a> <span class="description">تا ۱۰۰۰ رخداد با فیلترهای فعلی؛ خروجی SpreadsheetML سازگار با Excel.</span></p>';
        echo '<table class="widefat striped"><thead><tr><th>زمان</th><th>کاربر</th><th>دستگاه</th><th>نوع عملیات</th><th>هدف</th><th>نتیجه</th><th>IP</th></tr></thead><tbody>';
        foreach ((array) $logs as $row) {
            $activity_user = get_userdata(isset($row['user_id']) ? (int) $row['user_id'] : 0);
            echo '<tr><td>' . esc_html(wsm_fa_date($row['created_at'])) . '</td><td>' . esc_html($activity_user ? $activity_user->display_name . ' (' . $activity_user->user_login . ')' : '—') . '</td><td>' . esc_html(($row['device_name'] ?: '—') . (!empty($row['platform']) ? ' — ' . $row['platform'] : '')) . '</td><td>' . esc_html(self::action_label($row['action_type'])) . '</td><td>' . esc_html($row['entity_name'] ?: $row['entity_type']) . '</td><td>' . self::result_badge($row['result']) . '</td><td><code dir="ltr">' . esc_html($row['ip_address']) . '</code></td></tr>';
        }
        if (!$logs) { echo '<tr><td colspan="7">رخدادی با این فیلترها پیدا نشد.</td></tr>'; }
        echo '</tbody></table></div></div>';
    }

    public static function page_access_key() {
        $uid     = get_current_user_id();
        $plain   = WSM_Auth::reveal_key($uid);
        $prefix  = get_option('wsm_access_key_prefix', '');
        $expires = get_option('wsm_access_key_expires', '');
        $hash    = get_option('wsm_access_key_hash', '');

        echo '<div class="wrap wsm-admin-wrapper wsm-wrap" dir="rtl">';
        self::header('کلید دسترسی اپ');
        if (!empty($_GET['created'])) {
            echo '<div class="notice notice-success"><p>کلید جدید ساخته شد. کلید کامل فقط همین یک‌بار نمایش داده می‌شود.</p></div>';
        }
        if (isset($_GET['key_error']) && is_scalar($_GET['key_error']) && sanitize_key(wp_unslash($_GET['key_error'])) === 'crypto') {
            echo '<div class="notice notice-error"><p>برای ساخت و ذخیرهٔ امن کلید، افزونهٔ OpenSSL باید روی سرور فعال باشد.</p></div>';
        }
        if ($plain) {
            echo '<div class="notice notice-warning"><p>این کلید را امن نگه دارید؛ پس از ترک این صفحه دیگر قابل نمایش نیست.</p></div>';
            echo '<div class="wsm-keybox"><strong>کلید جدید:</strong><code id="wsm-key">' . esc_html($plain) . '</code>';
            echo '<button type="button" class="button" onclick="wsmCopyKey()">کپی کلید</button></div>';
        } elseif ($hash) {
            echo '<div class="notice notice-info"><p>کلید کامل دیگر قابل بازیابی نیست. برای اتصال دستگاه تازه، کلید را بچرخانید و کلید جدید را ذخیره کنید.</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p>هنوز کلیدی وجود ندارد. یک کلید تازه بسازید تا اپ بتواند به فروشگاه وصل شود.</p></div>';
        }
        if ($prefix) {
            echo '<div class="wsm-panel"><p>پیشوند کلید: <code dir="ltr">' . esc_html($prefix) . '…</code></p>';
            echo '<p>انقضا: ' . esc_html($expires ? wsm_fa_date($expires) : '—') . '</p></div>';
        }
        $new_key_url = wp_nonce_url(
            admin_url('admin.php?page=' . self::SLUG . '-access-key&wsm_action=new_key'),
            'wsm_new_key'
        );
        echo '<p><a class="button button-primary" href="' . esc_url($new_key_url) . '" onclick="return confirm(\'ساخت کلید جدید، کلید قبلی را باطل می‌کند. ادامه می‌دهید؟\')">ساخت / چرخش کلید</a></p>';
        echo '<div class="wsm-panel"><h2>اتصال دستگاه</h2><p>این نشانی را در مرورگر موبایل باز کنید:</p><p><a href="' . esc_url(home_url('/wsm-connect')) . '" target="_blank" rel="noopener"><code>' . esc_html(home_url('/wsm-connect')) . '</code></a></p><p>کلید کامل فقط یک‌بار نشان داده می‌شود. اگر گم شد، کلید جدید بسازید؛ دستگاه‌های قبلی با توکن خود تا پایان نشست معتبر می‌مانند.</p></div>';
        echo '</div></div>';
    }

    /* ---------------------------------------------------------------
     * رخدادهای امنیتی
     * ------------------------------------------------------------- */
    public static function page_security() {
        if (!current_user_can('manage_options')) { wp_die('این بخش فقط برای مدیر کل وردپرس مجاز است.'); }
        global $wpdb;
        $table = WSM_Security::table('security_log');
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A);
        $settings = WSM_Util::settings();
        $policy = get_option('wsm_security_policy', array());
        if (!is_array($policy)) { $policy = array(); }
        $policy = array_merge(array('max_attempts' => 5, 'window_minutes' => 15, 'lock_minutes' => 30), $policy);
        echo '<div class="wrap wsm-admin-wrapper wsm-wrap" dir="rtl">';
        self::header('امنیت و ورود');
        if (isset($_GET['security_saved'])) { echo '<div class="notice notice-success is-dismissible"><p>سیاست‌های امنیتی ذخیره شد.</p></div>'; }
        if (isset($_GET['security_error']) && is_scalar($_GET['security_error'])) {
            $security_error = sanitize_key(wp_unslash($_GET['security_error']));
            if ($security_error === 'crypto') { echo '<div class="notice notice-error"><p>برای فعال‌کردن TOTP، افزونهٔ OpenSSL در PHP باید در دسترس باشد.</p></div>'; }
            elseif ($security_error === 'storage') { echo '<div class="notice notice-error"><p>ذخیرهٔ سیاست امنیتی در پایگاه داده انجام نشد.</p></div>'; }
        }
        echo '<div class="wsm-panel"><h2>تنظیمات امنیت</h2><p class="description">سیاست ورود کارکنان و عمر اعتبار نشست‌ها در همین افزونه اعمال می‌شود. تغییرات محدودکننده برای تلاش‌های بعدی ورود استفاده می‌شود.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-security')) . '">';
        wp_nonce_field('wsm_save_security');
        echo '<input type="hidden" name="wsm_action" value="save_security"><div class="wsm-grid wsm-grid-3">';
        echo '<p><label><strong>عمر Access Token (روز)</strong><br><input type="number" min="1" max="365" name="token_ttl_days" value="' . (int) $settings['token_ttl_days'] . '"></label></p>';
        echo '<p><label><strong>عمر Access Key (روز)</strong><br><input type="number" min="1" max="365" name="key_ttl_days" value="' . (int) $settings['key_ttl_days'] . '"></label></p>';
        echo '<p><label><strong>حداکثر تلاش ناموفق</strong><br><input type="number" min="1" max="20" name="max_attempts" value="' . (int) $policy['max_attempts'] . '"></label></p>';
        echo '<p><label><strong>پنجرهٔ شمارش (دقیقه)</strong><br><input type="number" min="1" max="1440" name="window_minutes" value="' . (int) $policy['window_minutes'] . '"></label></p>';
        echo '<p><label><strong>مدت قفل پس از تلاش‌ها (دقیقه)</strong><br><input type="number" min="1" max="1440" name="lock_minutes" value="' . (int) $policy['lock_minutes'] . '"></label></p>';
        echo '</div><p><label><input type="checkbox" name="wsm_totp_required" value="1" ' . checked(!empty($settings['totp_required']), true, false) . '> برای مدیران، ورود به پنل افزونه با Microsoft/Google Authenticator (TOTP) الزامی باشد</label></p>';
        echo '<p><button class="button button-primary" type="submit">ذخیرهٔ سیاست امنیت</button> <span class="description">حدود مجاز: عمر ۱ تا ۳۶۵ روز، حداکثر ۱ تا ۲۰ تلاش و پنجره/قفل ۱ تا ۱۴۴۰ دقیقه.</span></p></form></div>';
        echo '<div class="wsm-panel"><h2>ورودهای مشکوک و ناموفق</h2><p class="description">۱۰۰ رخداد اخیر امنیتی، از جمله تلاش ناموفق، قفل IP، تغییر IP و هشدارهای دستگاه.</p>';
        echo '<table class="widefat striped"><thead><tr><th>زمان</th><th>رخداد</th><th>شدت</th><th>کاربر / دستگاه</th><th>IP</th><th>عامل کاربر</th><th>جزئیات</th></tr></thead><tbody>';
        foreach ((array) $rows as $row) {
            $security_device = !empty($row['device_id']) ? WSM_Devices::find((int) $row['device_id']) : false;
            $security_user = $security_device ? get_userdata((int) $security_device->user_id) : false;
            $security_label = $security_user ? $security_user->display_name . ' — ' . $security_device->device_name : (!empty($row['device_id']) ? 'دستگاه #' . (int) $row['device_id'] : '—');
            echo '<tr><td>' . esc_html(wsm_fa_date($row['created_at'])) . '</td><td><code>' . esc_html($row['event']) . '</code></td><td>' . esc_html($row['severity']) . '</td><td>' . esc_html($security_label) . '</td><td><code dir="ltr">' . esc_html($row['ip_address']) . '</code></td><td>' . esc_html(isset($row['user_agent']) ? $row['user_agent'] : '—') . '</td><td>' . esc_html($row['details']) . '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="7">رخداد امنیتی ثبت نشده است.</td></tr>'; }
        echo '</tbody></table></div></div></div>';
    }

    /* ---------------------------------------------------------------
     * تنظیمات افزونه، PWA و سرویس‌های اعلان
     * ------------------------------------------------------------- */
    /** Persian display labels for manual backup UI only; technical identifiers remain unchanged. */
    private static function manual_backup_table_label($name) {
        static $labels = array(
            'wsm_access_keys' => 'کلیدهای دسترسی (API Keys)',
            'wsm_activity_log' => 'گزارش فعالیت‌های سیستم',
            'wsm_devices' => 'دستگاه‌های متصل',
            'wsm_auth_attempts' => 'تلاش‌های ورود و احراز هویت',
            'wsm_blocked_ips' => 'نشانی‌های اینترنتی مسدودشده',
            'wsm_used_nonces' => 'شناسه‌های یک‌بارمصرف امنیتی',
            'wsm_security_log' => 'گزارش رویدادهای امنیتی',
            'wsm_migration_log' => 'گزارش تغییرات ساختار پایگاه داده',
            'wsm_sync_logs' => 'لاگ‌های همگام‌سازی (سینک)',
            'wsm_sync_member_map' => 'پیوند شناسه‌های اعضا برای همگام‌سازی',
            'wsm_store_customers' => 'اطلاعات مشتریان فروشگاه',
            'wsm_customer_ledger' => 'حساب و گردش مالی مشتریان',
            'wsm_user_permissions' => 'دسترسی‌های کاربران',
            'wsm_users_permissions' => 'دسترسی‌های کاربران',
            'wsm_club_members' => 'اطلاعات اعضای باشگاه',
            'wsm_club_memberships' => 'اشتراک‌ها و عضویت‌های باشگاه',
            'wsm_club_plans' => 'تعرفه‌ها و طرح‌های باشگاه',
            'wsm_club_payments' => 'پرداختی‌های باشگاه',
            'wsm_club_attendance' => 'سوابق ورود و خروج (تردد) اعضا',
            'wsm_club_staff' => 'اطلاعات کارکنان باشگاه',
            'wsm_club_payroll' => 'حقوق و دستمزد کارکنان باشگاه',
            'wsm_club_finance' => 'سوابق مالی باشگاه (جدول قدیمی)',
            'wsm_club_financial_ledger' => 'دفتر کل و گردش مالی باشگاه',
            'wsm_club_financial_accounts' => 'حساب‌های مالی باشگاه',
            'wsm_club_receivables' => 'مطالبات باشگاه',
            'wsm_club_payables' => 'بدهی‌ها و پرداختنی‌های باشگاه',
            'wsm_club_cash_sessions' => 'نشست‌ها و عملیات صندوق باشگاه',
            'wsm_club_collections' => 'مجموعه‌ها و گروه‌های باشگاه',
            'wsm_club_sports' => 'رشته‌های ورزشی باشگاه',
            'wsm_club_phonebook' => 'دفتر مخاطبان باشگاه',
            'wsm_club_sms_log' => 'گزارش پیامک‌های باشگاه',
            'wsm_messages' => 'پیام‌های افزونه',
            'wsm_message_audit' => 'گزارش رویدادهای پیام‌ها',
            'wsm_backup_logs' => 'تاریخچهٔ عملیات بکاپ',
            'wsm_products' => 'اطلاعات محصولات',
            'wsm_orders' => 'اطلاعات سفارش‌ها',
            'wsm_customers' => 'اطلاعات مشتریان',
        );
        $key = strtolower((string) $name);
        if (isset($labels[$key])) { return $labels[$key]; }
        if (strpos($key, 'wsm_') !== 0) { return 'جدول داده‌های افزونه'; }
        $words = array(
            'access' => 'دسترسی', 'key' => 'کلید', 'keys' => 'کلیدها', 'activity' => 'فعالیت',
            'log' => 'گزارش', 'logs' => 'گزارش‌ها', 'device' => 'دستگاه', 'devices' => 'دستگاه‌ها',
            'auth' => 'احراز هویت', 'attempts' => 'تلاش‌ها', 'blocked' => 'مسدودشده', 'ips' => 'نشانی‌های اینترنتی',
            'ip' => 'نشانی اینترنتی', 'used' => 'استفاده‌شده', 'nonces' => 'شناسه‌های یک‌بارمصرف',
            'migration' => 'تغییر ساختار', 'store' => 'فروشگاه', 'customer' => 'مشتری', 'customers' => 'مشتریان',
            'ledger' => 'دفتر کل و گردش مالی', 'sync' => 'همگام‌سازی', 'member' => 'عضو', 'members' => 'اعضا',
            'map' => 'پیوند', 'permission' => 'دسترسی', 'permissions' => 'دسترسی‌ها', 'product' => 'محصول',
            'products' => 'محصولات', 'order' => 'سفارش', 'orders' => 'سفارش‌ها', 'backup' => 'بکاپ',
            'settings' => 'تنظیمات', 'club' => 'باشگاه', 'finance' => 'امور مالی', 'financial' => 'مالی',
            'account' => 'حساب', 'accounts' => 'حساب‌ها', 'payment' => 'پرداختی', 'payments' => 'پرداختی‌ها',
            'attendance' => 'تردد و ورود و خروج', 'membership' => 'عضویت', 'memberships' => 'اشتراک‌ها و عضویت‌ها',
            'plan' => 'طرح و تعرفه', 'plans' => 'طرح‌ها و تعرفه‌ها', 'staff' => 'کارکنان',
            'payroll' => 'حقوق و دستمزد', 'receivables' => 'مطالبات', 'payables' => 'بدهی‌ها',
            'cash' => 'صندوق', 'sessions' => 'نشست‌ها', 'collections' => 'مجموعه‌ها', 'sports' => 'رشته‌های ورزشی',
            'phonebook' => 'دفتر مخاطبان', 'sms' => 'پیامک', 'message' => 'پیام', 'messages' => 'پیام‌ها',
            'audit' => 'رویدادها', 'security' => 'امنیت', 'user' => 'کاربر', 'users' => 'کاربران',
            'token' => 'شناسهٔ امنیتی', 'tokens' => 'شناسه‌های امنیتی', 'queue' => 'صف', 'event' => 'رویداد',
            'events' => 'رویدادها', 'file' => 'فایل', 'files' => 'فایل‌ها', 'notification' => 'اعلان',
            'notifications' => 'اعلان‌ها', 'profile' => 'نمایه', 'media' => 'رسانه',
        );
        $translated = array();
        foreach (explode('_', substr($key, 4)) as $part) {
            if (isset($words[$part])) { $translated[] = $words[$part]; }
        }
        return $translated ? 'داده‌های افزونهٔ ' . implode(' ', $translated) : 'سایر داده‌های افزونه';
    }

    /** Persian explanations for selectable plugin folder groups; the group ID is not modified. */
    private static function manual_backup_folder_label($id) {
        static $labels = array(
            '__root__' => 'فایل‌های اصلی افزونه',
            'includes' => 'فایل‌های هسته و منطق افزونه',
            'assets' => 'فایل‌های ظاهری و کدهای رابط کاربری',
            'templates' => 'الگوهای نمایش و قالب‌ها',
            'wsm-app' => 'برنامهٔ وب و رابط کاربری',
            'bridge' => 'لایهٔ ارتباطی و اتصال برنامه',
            'src-tauri' => 'کد و پیکربندی برنامهٔ دسکتاپ',
            'languages' => 'فایل‌های زبان و ترجمه',
            'android-twa' => 'برنامه و پیکربندی نسخهٔ اندروید',
            '.github' => 'پیکربندی گیت‌هاب و روندهای ساخت',
        );
        $key = strtolower((string) $id);
        return isset($labels[$key]) ? $labels[$key] : ($key === '__root__' ? 'فایل‌های اصلی افزونه' : 'فایل‌های سایر پوشه‌های افزونه');
    }

    /** Backup storage, schedule, manual snapshot, history, and restore tooling; kept inside Settings only. */
    private static function backup_settings_panel() {
        $config=WSM_Club_Operations::get_backup_configuration();
        $backup_inventory=WSM_Club_Operations::get_backup_inventory();
        $backup_zip_available=class_exists('ZipArchive');
        $default_path=WSM_Club_Operations::default_backup_directory_path();
        $default_path_value=is_wp_error($default_path)?'':(string)$default_path;
        $custom_path=isset($config['storage_mode'])&&$config['storage_mode']==='custom';
        $display_path=$custom_path&&isset($config['storage_path'])&&is_scalar($config['storage_path'])?(string)$config['storage_path']:$default_path_value;
        $external_path=isset($config['external_storage_path'])&&is_scalar($config['external_storage_path'])?(string)$config['external_storage_path']:'';
        $notice_key='wsm_backup_settings_result_'.get_current_user_id();$notice=get_transient($notice_key);
        if($notice){delete_transient($notice_key);}
        $history_filter_type=isset($_GET['wsm_backup_type'])&&is_scalar($_GET['wsm_backup_type'])?sanitize_key(wp_unslash((string)$_GET['wsm_backup_type'])):'';
        $history_filter_status=isset($_GET['wsm_backup_status'])&&is_scalar($_GET['wsm_backup_status'])?sanitize_key(wp_unslash((string)$_GET['wsm_backup_status'])):'';
        if(!in_array($history_filter_type,array('manual','automatic','pre_restore'),true)){$history_filter_type='';}
        if(!in_array($history_filter_status,array('success','failed','deleted','missing'),true)){$history_filter_status='';}
        $backup_history_notice=isset($_GET['wsm_backup_notice'])&&is_scalar($_GET['wsm_backup_notice'])?sanitize_key(wp_unslash((string)$_GET['wsm_backup_notice'])):'';
        $history=WSM_Club_Operations::get_recent_backup_logs(20,array('backup_type'=>$history_filter_type,'status'=>$history_filter_status));
        $next_backup_run=wp_next_scheduled('wsm_scheduled_backup');
        $backup_recurrence_map=array('daily'=>'wsm_backup_daily','weekly'=>'wsm_backup_weekly','monthly'=>'wsm_backup_monthly');
        $config_schedule=isset($config['schedule'])?(string)$config['schedule']:'';
        $expected_backup_recurrence=isset($backup_recurrence_map[$config_schedule])?$backup_recurrence_map[$config_schedule]:'';
        $current_backup_recurrence=function_exists('wp_get_schedule')?wp_get_schedule('wsm_scheduled_backup'):false;
        echo '<div class="wsm-panel wsm-backup-panel" id="wsm-backup-control" data-endpoint="'.esc_url(admin_url('admin-post.php')).'" data-create-nonce="'.esc_attr(wp_create_nonce('wsm_create_backup')).'" data-test-path-nonce="'.esc_attr(wp_create_nonce('wsm_test_backup_path')).'" data-describe-nonce="'.esc_attr(wp_create_nonce('wsm_describe_backup')).'" data-preview-nonce="'.esc_attr(wp_create_nonce('wsm_preview_backup')).'" data-apply-nonce="'.esc_attr(wp_create_nonce('wsm_apply_backup')).'">';
        echo '<h2>پشتیبان‌گیری و بازیابی</h2><p class="description">فایل بکاپ رمزگذاری نمی‌شود. فیلدهای رمز، کلید و توکن احراز هویت دستگاه و کلیدهای دسترسی ذخیره نمی‌شوند؛ پس از بازیابی ممکن است دستگاه‌ها به اتصال دوباره نیاز داشته باشند. بازیابی ابتدا فقط‌خواندنی پیش‌نمایش می‌شود و پیش از هر نوشتن، نسخهٔ پشتیبان جداگانه می‌سازد.</p>';
        echo '<p class="description">کاربران، نقش‌ها، گذرواژه‌ها و فراداده‌های حساب کاربری وردپرس در بکاپ نیستند. شناسه‌های کاربر در داده‌های افزونه با همان مقدار عددی می‌مانند؛ اگر فایل را روی سایت دیگری بازیابی می‌کنید، ارجاع‌ها را دستی تطبیق دهید.</p>';
        if(!$backup_zip_available){echo '<div class="notice notice-error inline"><p>قابلیت پشتیبانی از بسته‌های فشردهٔ ZIP در PHP سرور فعال نیست؛ ساخت و بازیابی بسته‌های جدید تا فعال‌سازی کتابخانهٔ ZipArchive ممکن نیست. فایل‌های JSON قدیمی فقط برای بازیابی داده‌ها قابل بررسی‌اند.</p></div>';}
        if(is_wp_error($default_path)){
            echo '<div class="notice notice-error inline"><p><strong>مسیر پیش‌فرض بکاپ در دسترس نیست.</strong> '.esc_html($default_path->get_error_message()).' برای ادامه مسیر سفارشی وارد کنید.</p></div>';
        }elseif($custom_path){
            echo '<div class="notice notice-warning inline wsm-backup-location-warning"><p>مسیر اصلی بکاپ روی مسیر سفارشی <code dir="ltr">'.esc_html($display_path).'</code> است. اگر این مسیر زیر پوشهٔ بارگذاری‌های وردپرس (<code dir="ltr">uploads</code>) باشد، بازگردانی کامل فایل‌های سایت یا حذف و جایگزینی این پوشه می‌تواند فایل بکاپ را حذف کند. برای نگهداری نسخهٔ جداگانه بیرون از ریشهٔ وب، مسیر خارجی را تنظیم کنید.</p></div>';
        }else{
            echo '<div class="notice notice-warning inline wsm-backup-location-warning"><p>هشدار: بکاپ اصلی در پوشهٔ بارگذاری‌های وردپرس (<code dir="ltr">uploads</code>) ذخیره می‌شود. بازگردانی کامل فایل‌های سایت، حذف یا جایگزینی این پوشه می‌تواند بکاپ را هم حذف کند. برای نگهداری نسخهٔ جداگانه بیرون از ریشهٔ وب، مسیر خارجی را تنظیم کنید.</p></div>';
        }
        if(is_array($notice)&&isset($notice['message'])){echo '<div class="notice '.(!empty($notice['ok'])?'notice-success':'notice-error').' inline"><p>'.esc_html($notice['message']).'</p></div>';}
        echo '<div class="wsm-backup-subpanel"><h3>زمان‌بندی و محل ذخیره‌سازی</h3>';
        echo '<form method="post" action="'.esc_url(admin_url('admin.php?page='.self::SLUG.'-settings&wsm_action=save_backup_settings')).'">';
        wp_nonce_field('wsm_save_backup_settings');
        echo '<div class="wsm-backup-grid">';
        echo '<label class="wsm-backup-field wsm-backup-check"><input type="checkbox" name="wsm_backup_settings[auto_enabled]" value="1" '.checked(!empty($config['auto_enabled']),true,false).'> بکاپ خودکار فعال باشد</label>';
        echo '<label class="wsm-backup-field"><span>بازهٔ بکاپ</span><select name="wsm_backup_settings[schedule]">';
        foreach(array('daily'=>'روزانه','weekly'=>'هفتگی','monthly'=>'هر ۳۰ روز') as $key=>$label){echo '<option value="'.esc_attr($key).'" '.selected(isset($config['schedule'])?$config['schedule']:'daily',$key,false).'>'.esc_html($label).'</option>';}
        echo '</select></label>';
        $default_mode_label=is_wp_error($default_path)?'مسیر پیش‌فرض بارگذاری‌های وردپرس در دسترس نیست':'پیش‌فرض: '.$default_path_value;
        echo '<label class="wsm-backup-field"><span>محل ذخیره‌سازی</span><select id="wsm-backup-storage-mode" name="wsm_backup_settings[storage_mode]">';
        foreach(array('default'=>$default_mode_label,'custom'=>'مسیر سفارشی') as $key=>$label){echo '<option value="'.esc_attr($key).'" '.selected(isset($config['storage_mode'])?$config['storage_mode']:'default',$key,false).'>'.esc_html($label).'</option>';}
        echo '</select></label>';
        echo '<label class="wsm-backup-field"><span>مسیر ذخیره‌سازی (پیش‌فرض یا مسیر سفارشی)</span><input type="text" dir="ltr" class="regular-text" id="wsm-backup-storage-path" name="wsm_backup_settings[storage_path]" value="'.esc_attr($display_path).'" placeholder="'.esc_attr($default_path_value!==''?$default_path_value:'wp-content/uploads/wsm-backups/').'"><span class="description">برای تغییر مسیر، «مسیر سفارشی» را انتخاب کنید.</span></label>';
        echo '<label class="wsm-backup-field"><span>مسیر خارجی (اختیاری)</span><input type="text" dir="ltr" class="regular-text" id="wsm-backup-external-path" name="wsm_backup_settings[external_storage_path]" value="'.esc_attr($external_path).'" placeholder="/home/{username}/wsm-backups/"><span class="description">اگر پر شود، بکاپ‌ها علاوه بر مسیر پیش‌فرض، در این مسیر نیز ذخیره می‌شوند.</span></label>';
        echo '<label class="wsm-backup-field"><span>تعداد بکاپ‌های خودکار برای نگهداری</span><input type="number" min="1" max="100" name="wsm_backup_settings[retention]" value="'.(int)(isset($config['retention'])?$config['retention']:5).'"><span class="description">تغییر این عدد فایل‌ها را فوراً حذف نمی‌کند؛ حد نگهداری پس از بکاپ خودکار موفق بعدی اعمال می‌شود.</span></label>';
        echo '<label class="wsm-backup-field wsm-backup-check"><input type="checkbox" name="wsm_backup_settings[email_notifications]" value="1" '.checked(!empty($config['email_notifications']),true,false).'> اعلان ایمیلی نتیجهٔ بکاپ خودکار</label>';
        echo '<label class="wsm-backup-field"><span>ایمیل اعلان</span><input type="email" dir="ltr" class="regular-text" name="wsm_backup_settings[email_recipient]" value="'.esc_attr(isset($config['email_recipient'])&&is_scalar($config['email_recipient'])?$config['email_recipient']:'').'" placeholder="'.esc_attr(get_option('admin_email','')).'"><span class="description">نتیجهٔ بکاپ خودکار ارسال می‌شود؛ در موفقیت نام فایل و چکیدهٔ رمزنگاری‌شدهٔ SHA-256 درج می‌شود، اما خود فایل بکاپ پیوست ایمیل نمی‌شود.</span></label>';
        echo '</div>';
        if(!empty($config['auto_enabled'])){
            $schedule_matches=!function_exists('wp_get_schedule')||$current_backup_recurrence===$expected_backup_recurrence;
            if($next_backup_run&&$expected_backup_recurrence!==''&&$schedule_matches){
                $next_local=date_i18n('Y-m-d H:i:s',(int)$next_backup_run);
                $next_display=function_exists('wsm_fa_date')?wsm_fa_date($next_local):$next_local;
                echo '<p class="description">بکاپ خودکار فعال است؛ زمان تقریبی اجرای بعدی: <strong>'.esc_html($next_display).'</strong>.</p>';
            }else{
                echo '<div class="notice notice-warning inline"><p>بکاپ خودکار فعال است، اما رویداد مورد انتظار در زمان‌بند داخلی وردپرس (WP-Cron) ثبت یا تأیید نشده است؛ تنظیمات را ذخیره کنید و پیکربندی زمان‌بند را بررسی کنید.</p></div>';
            }
        }elseif($next_backup_run){
            echo '<div class="notice notice-warning inline"><p>بکاپ خودکار خاموش است، اما یک رویداد زمان‌بند داخلی وردپرس (WP-Cron) باقی مانده است؛ وضعیت زمان‌بندی را بررسی کنید.</p></div>';
        }else{
            echo '<p class="description">بکاپ خودکار خاموش است.</p>';
        }
        if(defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON){
            echo '<div class="notice notice-warning inline"><p>زمان‌بند داخلی وردپرس (WP-Cron) غیرفعال است؛ برای اجرای خودکار، زمان‌بند واقعی سرور باید <code>wp-cron.php</code> را فراخوانی کند.</p></div>';
        }else{
            echo '<p class="description">زمان‌بند داخلی وردپرس (WP-Cron) با بازدیدهای سایت اجرا می‌شود و زمان دقیق تضمین‌شده نیست. بازه‌ها فاصلهٔ تقریبی ۲۴ ساعت، ۷ روز یا ۳۰ روز هستند؛ برای سایت کم‌ترافیک از زمان‌بند واقعی سرور استفاده کنید.</p>';
        }
        echo '<p class="description">پوشهٔ بکاپ در صورت نیاز هنگام ذخیرهٔ تنظیمات یا ساخت بکاپ خودکار ساخته می‌شود. بکاپ‌های دستی و نسخهٔ پشتیبان پیش از بازیابی از پاک‌سازی خودکار مستثنا هستند.</p>';
        echo '<p><button type="button" class="button" id="wsm-test-backup-path">تست مسیر</button> <span id="wsm-test-backup-path-status" class="wsm-backup-status" role="status" aria-live="polite"></span><br><span class="description">در صورت نبود پوشه، برای آزمون ساخته و محافظت می‌شود؛ فایل آزمایشی پس از نوشتن و خواندن حذف می‌شود و تنظیمات ذخیره نمی‌شوند. مسیر اصلی و مسیر خارجیِ واردشده بررسی می‌شوند.</span></p>';
        echo '<p><button type="submit" class="button button-primary">ذخیرهٔ تنظیمات بکاپ</button></p></form></div>';

        echo '<div class="wsm-backup-subpanel"><h3>بکاپ دستی</h3><p>بکاپ با قالب داده‌محور ZIP و پسوند <code>.wsmbackup</code> ساخته می‌شود و فقط جدول‌های عملیاتی باشگاه، پیوند شناسهٔ اعضا، پیام‌ها و شش تنظیم مجاز را در بر می‌گیرد. بسته شامل <code>database.json</code> و <code>manifest.json</code> با SHA-256 است؛ تعداد فایل‌های کد همیشه صفر است و کد افزونه نه ذخیره و نه بازیابی می‌شود. پیام‌ها و گزارش رویدادهای آن فقط روی همان نصب وردپرس قابل بازیابی‌اند. فایل بکاپ رمزگذاری نمی‌شود.</p>';
        if(is_wp_error($backup_inventory)){
            echo '<div class="notice notice-error inline"><p>فهرست جدول‌ها و فایل‌های بکاپ آماده نشد: '.esc_html($backup_inventory->get_error_message()).' تنظیمات دست‌نخورده ماندند.</p></div>';
        }else{
            echo '<div class="wsm-backup-create-selection" id="wsm-create-backup-sections">';
            echo '<div class="wsm-backup-selection-actions"><button type="button" class="button button-small" data-wsm-backup-select-all>انتخاب همه</button> <button type="button" class="button button-small" data-wsm-backup-clear-all>پاک‌کردن انتخاب‌ها</button></div>';
            echo '<div class="wsm-backup-selection-column"><h4>جدول‌های پایگاه داده</h4>';
            if(!empty($backup_inventory['database_tables'])){
                foreach($backup_inventory['database_tables'] as $table){
                    $name=isset($table['name'])?(string)$table['name']:'';if($name===''){continue;}
                    $label=self::manual_backup_table_label($name);
                    $required_message_table=in_array($name,array('wsm_messages','wsm_message_audit'),true);
                    $required_attr=$required_message_table?' data-wsm-backup-required="1" disabled':'';
                    $required_note=$required_message_table?' — همیشه در بکاپ دستی درج می‌شود':'';
                    echo '<label class="wsm-backup-create-choice"><input type="checkbox" class="wsm-backup-create-table" value="'.esc_attr($name).'" checked'.$required_attr.'><span><strong>'.esc_html($label).'</strong> <small><code dir="ltr">'.esc_html($name).'</code></small> — '.number_format_i18n((int)$table['record_count']).' رکورد'.esc_html($required_note).'</span></label>';
                }
            }else{echo '<p class="description">جدولی با پیشوند افزونه پیدا نشد.</p>';}
            echo '</div><div class="wsm-backup-selection-column"><h4>محتوای فایل</h4><p class="description">فقط داده‌های باشگاه و پیام‌های موجود در جدول‌های مجاز انتخاب می‌شوند. فایل‌های افزونه، مسیرهای کد و پوشه‌های برنامه در بکاپ داده قرار نمی‌گیرند.</p></div></div>';
        }
        echo '<p><button type="button" class="button button-primary" id="wsm-create-backup" '.((is_wp_error($backup_inventory)||!$backup_zip_available)?'disabled':'').'>ساخت بکاپ اکنون</button> <span id="wsm-create-backup-progress" class="wsm-backup-progress" role="progressbar" aria-label="ساخت و بررسی بکاپ" aria-valuetext="در حال انجام" hidden><span class="wsm-backup-progress-bar"></span></span> <span id="wsm-create-backup-status" class="wsm-backup-status" role="status" aria-live="polite"></span></p></div>';

        echo '<div class="wsm-backup-subpanel wsm-restore-tool"><h3>پیش‌نمایش و بازیابی از فایل</h3><p>بستهٔ <code>.wsmbackup</code> و فایل دادهٔ JSON قدیمی با ساختار و جمع کنترلی معتبر بررسی می‌شوند؛ بکاپ قدیمی پیش از بازیابی هشدار قرمز دارد. بسته‌های قدیمیِ دارای فایل کد، در صورت پشتیبانی، فقط برای خواندن و پیش‌نمایش داده بررسی می‌شوند و فایل کد از آن‌ها قابل انتخاب یا بازیابی نیست. مسیر، زمان‌بندی و اعلان بکاپ از فایل اعمال نمی‌شود و تنظیمات مقصد حفظ می‌شود. بازیابی جدول‌های انتخاب‌شده جایگزینی کامل همان جدول‌هاست؛ رکوردهای فعلی که در بکاپ نیستند حذف می‌شوند. پیام‌ها و ممیزی آن‌ها فقط با نشانهٔ نصب یکسان قابل انتخاب‌اند؛ برای نصب دیگر یا بکاپ قدیمی بدون نشانه، این گزینه‌ها غیرفعال‌اند. شمار حذف‌ها در پیش‌نمایش می‌آید و برای آن تأیید جداگانه لازم است. اگر جدول‌ها به هم ارجاع دارند، همهٔ جدول‌های مرتبط را با هم انتخاب کنید.</p>';
        echo '<label class="wsm-backup-field"><span>فایل بکاپ</span><input id="wsm-restore-file" type="file" accept=".wsmbackup,.json,application/json,application/octet-stream"></label> <button type="button" class="button" id="wsm-describe-backup">بررسی فایل و انتخاب بخش‌ها</button>';
        echo '<div id="wsm-restore-status" class="wsm-backup-status" role="status" aria-live="polite"></div>';
        echo '<div id="wsm-restore-description" class="wsm-restore-description" hidden><div id="wsm-restore-compatibility-warning" class="notice notice-error inline wsm-backup-old-warning" role="alert" hidden></div><label id="wsm-restore-version-confirm-wrap" class="wsm-backup-confirm wsm-backup-version-confirm" hidden><input id="wsm-restore-version-confirm" type="checkbox"> هشدار قرمز اختلاف نسخه یا قالب قدیمی را خواندم و با علم به احتمال ناسازگاری ادامه می‌دهم.</label><p id="wsm-restore-metadata" class="description"></p><div id="wsm-restore-choices"></div><p><button type="button" class="button" id="wsm-preview-backup" disabled>پیش‌نمایش انتخاب‌شده</button></p></div>';
        echo '<div id="wsm-restore-preview" class="wsm-restore-preview" hidden><h4>نتیجهٔ پیش‌نمایش (بدون تغییر داده)</h4><div id="wsm-restore-preview-summary"></div>';
        echo '<label class="wsm-backup-confirm"><input id="wsm-restore-confirm" type="checkbox"> پیش‌نمایش را بررسی کردم و اجرای تغییرات انتخاب‌شده را تأیید می‌کنم.</label>';
        echo '<div id="wsm-restore-delete-confirm-wrap" class="notice notice-error inline wsm-backup-delete-warning" role="alert" hidden><p id="wsm-restore-delete-warning-text"></p><label class="wsm-backup-confirm"><input id="wsm-restore-delete-confirm" type="checkbox"> جداگانه تأیید می‌کنم جدول‌های انتخاب‌شده دقیقاً با بکاپ جایگزین شوند و رکوردهای اضافه حذف شوند.</label></div>';
        echo '<p><button type="button" class="button button-primary" id="wsm-apply-backup" disabled>اجرای بازیابی</button> <span id="wsm-apply-status" class="wsm-backup-status" role="status" aria-live="polite"></span></p><div id="wsm-apply-result"></div></div></div>';

        echo '<div class="wsm-backup-subpanel"><h3>تاریخچهٔ بکاپ و نسخه‌های پشتیبان</h3>';
        if($backup_history_notice==='deleted'){echo '<div class="notice notice-success inline"><p>نسخه‌های ثبت‌شده حذف شدند؛ گزارش در تاریخچه باقی مانده است.</p></div>';}
        echo '<form method="get" class="wsm-backup-history-filters" action="'.esc_url(admin_url('admin.php')).'">';
        echo '<input type="hidden" name="page" value="'.esc_attr(self::SLUG.'-settings').'">';
        echo '<label>نوع بکاپ <select name="wsm_backup_type"><option value="">همه</option>';
        foreach(array('manual'=>'دستی','automatic'=>'خودکار','pre_restore'=>'پیش از بازیابی') as $key=>$label){echo '<option value="'.esc_attr($key).'" '.selected($history_filter_type,$key,false).'>'.esc_html($label).'</option>';}
        echo '</select></label><label>وضعیت گزارش <select name="wsm_backup_status"><option value="">همه</option>';
        foreach(array('success'=>'موفق','failed'=>'ناموفق','deleted'=>'پاک‌شده','missing'=>'فایل مفقود') as $key=>$label){echo '<option value="'.esc_attr($key).'" '.selected($history_filter_status,$key,false).'>'.esc_html($label).'</option>';}
        echo '</select></label><button type="submit" class="button">اعمال فیلتر</button>';
        if($history_filter_type!==''||$history_filter_status!==''){echo '<a class="button" href="'.esc_url(add_query_arg('page',self::SLUG.'-settings',admin_url('admin.php'))).'">پاک‌کردن فیلتر</a>';}
        echo '</form>';
        if(is_wp_error($history)){echo '<p class="notice notice-warning inline">'.esc_html($history->get_error_message()).'</p>';}
        elseif(!$history){echo '<p>'.($history_filter_type!==''||$history_filter_status!==''?'با این فیلتر گزارشی پیدا نشد.':'هنوز گزارشی برای بکاپ ثبت نشده است.').'</p>';}
        else{
            $type_labels=array('manual'=>'دستی','automatic'=>'خودکار','pre_restore'=>'پیش از بازیابی');
            $status_labels=array('success'=>'موفق','failed'=>'ناموفق','deleted'=>'پاک‌شده','missing'=>'فایل مفقود');
            echo '<div class="wsm-backup-table-wrap"><table class="widefat striped"><thead><tr><th>زمان</th><th>نوع</th><th>وضعیت</th><th>حجم</th><th>جدول / رکورد</th><th>چکیدهٔ رمزنگاری‌شده (SHA-256)</th><th>فایل / عملیات</th></tr></thead><tbody>';
            foreach($history as $entry){
                $type=isset($entry['backup_type'])?(string)$entry['backup_type']:'';$status=isset($entry['status'])?(string)$entry['status']:'';
                $created=isset($entry['created_at'])?(string)$entry['created_at']:'';$display_date=function_exists('wsm_fa_date')?wsm_fa_date($created):$created;
                $file_checksum=isset($entry['file_checksum'])?(string)$entry['file_checksum']:'';$has_file_checksum=(bool)preg_match('/^[a-f0-9]{64}$/i',$file_checksum);
                $checksum=$has_file_checksum?$file_checksum:(isset($entry['checksum'])?(string)$entry['checksum']:'');
                $checksum_title=$has_file_checksum?'چکیدهٔ SHA-256 فایل':'چکیدهٔ SHA-256 محتوای بکاپ (رکورد قدیمی)';
                echo '<tr><td>'.esc_html($display_date).'</td><td>'.esc_html(isset($type_labels[$type])?$type_labels[$type]:$type).'</td><td>'.esc_html(isset($status_labels[$status])?$status_labels[$status]:$status).'</td>';
                echo '<td>'.esc_html(size_format((int)(isset($entry['file_size'])?$entry['file_size']:0))).'</td><td>'.(int)(isset($entry['table_count'])?$entry['table_count']:0).' / '.(int)(isset($entry['record_count'])?$entry['record_count']:0).'</td>';
                echo '<td><code dir="ltr" title="'.esc_attr($checksum_title).'">'.esc_html($checksum).'</code></td><td>';
                if(!empty($entry['download_url'])){echo '<a class="button button-small" href="'.esc_url($entry['download_url']).'">دانلود</a>';}
                elseif($status==='success'&&isset($entry['download_issue'])&&$entry['download_issue']!==''){echo '<span class="description">'.esc_html($entry['download_issue']).'</span>';}
                elseif(in_array($status,array('failed','missing'),true)&&isset($entry['error_message'])&&$entry['error_message']!==''){echo '<span class="description">'.esc_html($entry['error_message']).'</span>';}
                else{echo '—';}
                $entry_id=isset($entry['backup_id'])&&is_scalar($entry['backup_id'])?(string)$entry['backup_id']:'';
                if(in_array($status,array('success','missing'),true)&&preg_match('/^[a-f0-9]{32}$/',$entry_id)){
                    $delete_aria='حذف بکاپ '.(isset($type_labels[$type])?$type_labels[$type]:$type).' '.$display_date;
                    echo '<form method="post" class="wsm-backup-delete-form" action="'.esc_url(admin_url('admin-post.php')).'" data-confirm="'.esc_attr('همهٔ نسخه‌های ثبت‌شده از مسیر اصلی و خارجی برای همیشه حذف می‌شوند و قابل‌بازیابی نیستند؛ گزارش تاریخچه باقی می‌ماند. ادامه می‌دهید؟').'">';
                    echo '<input type="hidden" name="action" value="wsm_delete_backup"><input type="hidden" name="backup_id" value="'.esc_attr($entry_id).'">';
                    if($history_filter_type!==''){echo '<input type="hidden" name="wsm_backup_type" value="'.esc_attr($history_filter_type).'">';}
                    if($history_filter_status!==''){echo '<input type="hidden" name="wsm_backup_status" value="'.esc_attr($history_filter_status).'">';}
                    wp_nonce_field('wsm_delete_backup_'.$entry_id);
                    echo '<button type="submit" class="button button-small button-link-delete" aria-label="'.esc_attr($delete_aria).'">حذف</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
    }

    private static function sms_events_settings_panel($club_settings) {
        if (!class_exists('WSM_Club_Operations')) { return; }
        $catalog = WSM_Club_Operations::sms_event_catalog();
        $events = isset($club_settings['sms_events']) && is_array($club_settings['sms_events']) ? $club_settings['sms_events'] : array();
        $templates = isset($club_settings['sms_templates']) && is_array($club_settings['sms_templates']) ? $club_settings['sms_templates'] : array();
        $groups = array();
        foreach ($catalog as $key => $event) {
            $category = isset($event['category']) ? (string)$event['category'] : 'سایر';
            if (!isset($groups[$category])) { $groups[$category] = array(); }
            $groups[$category][$key] = $event;
        }
        echo '<div class="wsm-panel"><h2>مدیریت رویدادهای پیامکی</h2><p class="description">هر رویداد مستقل است؛ برای ارسال، هم تیک رویداد و هم سرویس فعال ملی‌پیامک لازم است. پیام اعضا فقط با رضایت پیامکی ذخیره‌شده ارسال می‌شود. الگوها با آداپتر ملی‌پیامک فرستاده می‌شوند و عبارت لغو11 طبق رفتار فعلی افزوده می‌شود.</p>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="wsm-sms-expiry-days-before">چند روز مانده به انقضای اشتراک</label></th><td><input id="wsm-sms-expiry-days-before" type="number" min="1" max="365" name="wsm[sms_expiry_days_before]" value="' . (int)(isset($club_settings['sms_expiry_days_before']) ? $club_settings['sms_expiry_days_before'] : 3) . '"></td></tr>';
        echo '<tr><th><label for="wsm-sms-debt-reminder-after-days">تأخیر یادآوری ماندهٔ پرداخت (روز)</label></th><td><input id="wsm-sms-debt-reminder-after-days" type="number" min="0" max="365" name="wsm[debt_reminder_after_days]" value="' . (int)(isset($club_settings['debt_reminder_after_days']) ? $club_settings['debt_reminder_after_days'] : 0) . '"><p class="description">در مدل فعلی، این مقدار برای ماندهٔ شهریه از تاریخ ثبت عضویت استفاده می‌شود؛ اتصال قسط/تمدید خودکار از هوک رویداد پیامکی انجام می‌شود.</p></td></tr>';
        echo '</tbody></table>';
        foreach ($groups as $category => $category_events) {
            echo '<h3>' . esc_html($category) . '</h3><table class="form-table"><tbody>';
            foreach ($category_events as $key => $event) {
                $label = isset($event['label']) ? (string)$event['label'] : $key;
                $description = isset($event['description']) ? (string)$event['description'] : '';
                $template = isset($templates[$key]) && is_scalar($templates[$key]) ? (string)$templates[$key] : '';
                echo '<tr><th scope="row"><label for="wsm-sms-event-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
                echo '<input type="hidden" name="wsm[sms_events][' . esc_attr($key) . ']" value="0">';
                echo '<label><input id="wsm-sms-event-' . esc_attr($key) . '" type="checkbox" name="wsm[sms_events][' . esc_attr($key) . ']" value="1" ' . checked(!empty($events[$key]), true, false) . '> فعال باشد</label>';
                if ($description !== '') { echo '<p class="description">' . esc_html($description) . '</p>'; }
                echo '<label for="wsm-sms-template-' . esc_attr($key) . '">قالب پیامک</label><br>';
                echo '<textarea id="wsm-sms-template-' . esc_attr($key) . '" class="large-text" rows="2" maxlength="500" name="wsm[sms_templates][' . esc_attr($key) . ']">' . esc_textarea($template) . '</textarea>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p class="description">متغیرهای قابل‌استفاده بر اساس رویداد: {name}، {first_name}، {last_name}، {member_code}، {card_number}، {plan_name}، {old_plan_name}، {start_date}، {end_date}، {days}، {balance}، {amount}، {method}، {reference}، {class_name}، {class_date}، {class_time}، {remaining_sessions}، {attendance}، {referral_reward}، {event_name}، {survey_link}، {announcement}، {otp}، {login_count}، {ip_address}، {lock_until}.</p>';
        echo '<p class="description">برای رویدادهای بدون مدل بومی (کلاس/معرفی/نظرسنجی/پرداخت ناموفق/OTP) ماژول مربوط می‌تواند از <code>do_action(&quot;wsm_sms_event_trigger&quot;, $event_key, $member, $context)</code> استفاده کند.</p>';
        echo '</div>';
    }

    public static function page_settings() {
        $settings = WSM_Util::settings();
        $sms      = WSM_SMS::config();
        $club_sms_settings = WSM_Club_Operations::settings();
        $built    = WSM_Util::app_built();
        $result_key = 'wsm_test_result_' . get_current_user_id();
        $test_result = get_transient($result_key);
        if ($test_result) {
            delete_transient($result_key);
        }
        $event_result_key = 'wsm_sms_event_settings_result_' . get_current_user_id();
        $event_settings_result = get_transient($event_result_key);
        if ($event_settings_result) { delete_transient($event_result_key); }
        $app_errors = array(
            'filerequired' => 'فایل HTML را انتخاب کنید.',
            'invalid'      => 'فایل معتبر نیست یا حجم آن بیشتر از ۱۰ مگابایت است.',
            'source'       => 'فایل سورس Vite پذیرفته نمی‌شود؛ خروجی build شده را بارگذاری کنید.',
            'write'        => 'نوشتن فایل انجام نشد؛ دسترسی نوشتن پوشهٔ افزونه را بررسی کنید.',
        );

        echo '<div class="wrap wsm-admin-wrapper wsm-wrap" dir="rtl">';
        self::header('تنظیمات');
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
        }
        if (isset($_GET['sms_config_error'])) {
            $sms_error_key = 'wsm_sms_config_error_' . get_current_user_id();
            $sms_error = get_transient($sms_error_key);
            delete_transient($sms_error_key);
            echo '<div class="notice notice-error"><p>' . esc_html(is_string($sms_error) && $sms_error !== '' ? $sms_error : 'ذخیرهٔ تنظیمات پیامک ناموفق بود.') . '</p></div>';
        }
        if (isset($_GET['sms_event_error']) && is_array($event_settings_result)) {
            echo '<div class="notice notice-error"><p>' . esc_html(isset($event_settings_result['message']) ? $event_settings_result['message'] : 'ذخیرهٔ تنظیمات رویدادهای پیامکی ناموفق بود.') . '</p></div>';
        }
        if (isset($_GET['app_installed'])) {
            echo '<div class="notice notice-success"><p>فایل اپ نصب شد. صفحهٔ اتصال را دوباره بارگذاری کنید.</p></div>';
        }
        if (isset($_GET['app_error'])) {
            $code = isset($_GET['app_error']) && is_scalar($_GET['app_error']) ? sanitize_key(wp_unslash($_GET['app_error'])) : '';
            echo '<div class="notice notice-error"><p>' . esc_html(isset($app_errors[$code]) ? $app_errors[$code] : 'بارگذاری اپ ناموفق بود.') . '</p></div>';
        }
        if (is_array($test_result)) {
            $class = !empty($test_result['ok']) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($test_result['detail']) . '</p></div>';
        }
        $mail_error = get_option('wsm_last_mail_error', '');
        if ($mail_error !== '') {
            echo '<div class="notice notice-warning"><p>آخرین خطای ایمیل: ' . esc_html($mail_error) . '</p></div>';
        }

        echo '<div class="wsm-panel"><h2>اپ تحت وب (PWA)</h2>';
        if ($built) {
            $index_path = WSM_Util::app_dir() . 'index.html';
            echo '<p><span class="wsm-badge wsm-badge-green">آماده</span> فایل اپ در پوشهٔ <code>wsm-app</code> موجود است (' . esc_html(size_format((int) filesize($index_path))) . ').</p>';
            echo '<p><a class="button" href="' . esc_url(home_url('/wsm-connect')) . '" target="_blank" rel="noopener">باز کردن صفحهٔ اتصال</a></p>';
        } else {
            echo '<p><span class="wsm-badge wsm-badge-red">نصب نشده</span> فایل خروجی build اپ در دسترس نیست.</p>';
        }
        echo '<p>برای جایگزینی نسخهٔ اپ، فایل خروجی HTML مستقل (حداکثر ۱۰ مگابایت) را انتخاب کنید. فایل‌های manifest، Service Worker و آیکون‌ها باید کنار index.html در پوشهٔ wsm-app باشند.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-settings&wsm_action=upload_app')) . '">';
        wp_nonce_field('wsm_upload_app');
        echo '<input type="file" name="wsm_app_file" accept=".html,.htm,text/html" required> ';
        echo '<button type="submit" class="button">بارگذاری index.html</button></form>';
        echo '</div>';

        self::backup_settings_panel();
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '-settings&wsm_action=save_settings')) . '">';
        wp_nonce_field('wsm_save_settings');
        echo '<div class="wsm-panel"><h2>عمومی و نشست‌ها</h2><table class="form-table"><tbody>';
        echo '<tr><th><label for="wsm-store-name">نام فروشگاه</label></th><td><input id="wsm-store-name" class="regular-text" name="wsm[store_name]" value="' . esc_attr($settings['store_name']) . '"></td></tr>';
        echo '<tr><th><label for="wsm-store-logo">نشانی لوگو</label></th><td><input id="wsm-store-logo" class="regular-text" type="url" name="wsm[store_logo]" value="' . esc_attr($settings['store_logo']) . '"></td></tr>';
        if (current_user_can('manage_options')) {
            echo '<tr><th><label for="wsm-token-ttl">عمر توکن (روز)</label></th><td><input id="wsm-token-ttl" type="number" min="1" max="365" name="wsm[token_ttl_days]" value="' . (int) $settings['token_ttl_days'] . '"></td></tr>';
            echo '<tr><th><label for="wsm-key-ttl">عمر کلید دسترسی (روز)</label></th><td><input id="wsm-key-ttl" type="number" min="1" max="365" name="wsm[key_ttl_days]" value="' . (int) $settings['key_ttl_days'] . '"></td></tr>';
        } else {
            echo '<tr><th>عمر توکن و Access Key</th><td>' . (int) $settings['token_ttl_days'] . ' / ' . (int) $settings['key_ttl_days'] . ' روز — تغییر فقط برای مدیر کل، در صفحهٔ امنیت مجاز است.</td></tr>';
        }
        echo '<tr><th><label for="wsm-log-retention">نگهداری لاگ (روز)</label></th><td><input id="wsm-log-retention" type="number" min="7" max="730" name="wsm[log_retention_days]" value="' . (int) $settings['log_retention_days'] . '"></td></tr>';
        echo '<tr><th>اعلان سفارش جدید</th><td><label><input type="checkbox" name="wsm[notify_new_order]" value="1" ' . checked(!empty($settings['notify_new_order']), true, false) . '> ارسال هشدار پیامکی برای مدیر</label></td></tr>';
        echo '<tr><th>حذف داده‌ها هنگام حذف افزونه</th><td><label><input type="checkbox" name="wsm[delete_data_on_uninstall]" value="1" ' . checked(!empty($settings['delete_data_on_uninstall']), true, false) . '> جداول و تنظیمات افزونه پاک شوند</label><p class="description">سفارش‌ها و فاکتورهای ووکامرس عمداً حذف نمی‌شوند.</p></td></tr>';
        echo '</tbody></table></div>';

        $sender_labels = array(
            'name'       => 'نام فرستنده',
            'company'    => 'فروشگاه / شرکت',
            'phone'      => 'تلفن',
            'email'      => 'ایمیل',
            'address_1'  => 'نشانی',
            'address_2'  => 'ادامهٔ نشانی',
            'city'       => 'شهر',
            'state'      => 'استان',
            'postcode'   => 'کدپستی',
            'country'    => 'کشور',
        );
        echo '<div class="wsm-panel"><h2>مشخصات فرستندهٔ برچسب پستی</h2><p class="description">این اطلاعات در بخش فرستندهٔ همهٔ برچسب‌ها درج می‌شود. نام و فروشگاه در شروع از نام فروشگاه وردپرس پر می‌شوند.</p><table class="form-table"><tbody>';
        foreach ($sender_labels as $sender_field => $sender_label) {
            $setting_key = 'postal_sender_' . $sender_field;
            $type = $sender_field === 'email' ? 'email' : ($sender_field === 'phone' ? 'tel' : 'text');
            $ltr = in_array($sender_field, array('phone', 'email', 'postcode'), true) ? ' dir="ltr"' : '';
            echo '<tr><th><label for="wsm-' . esc_attr($setting_key) . '">' . esc_html($sender_label) . '</label></th><td><input id="wsm-' . esc_attr($setting_key) . '" class="regular-text" type="' . esc_attr($type) . '"' . $ltr . ' name="wsm[' . esc_attr($setting_key) . ']" value="' . esc_attr(isset($settings[$setting_key]) ? $settings[$setting_key] : '') . '"></td></tr>';
        }
        echo '</tbody></table></div>';

        $expiry_key_in_use = WSM_Expiry::meta_key_in_use();
        echo '<div class="wsm-panel"><h2>هشدار تاریخ انقضا</h2><p class="description">گزارش روزانهٔ کالاهایی که تا آستانهٔ انتخابی منقضی می‌شوند؛ زمان‌بندی ارسال از WP-Cron وردپرس استفاده می‌کند.</p><table class="form-table"><tbody>';
        echo '<tr><th><label for="wsm-expiry-days">آستانهٔ هشدار (روز مانده)</label></th><td><input id="wsm-expiry-days" type="number" min="1" max="365" name="wsm[expiry_alert_days]" value="' . (int) $settings['expiry_alert_days'] . '"></td></tr>';
        echo '<tr><th><label for="wsm-expiry-email-to">ایمیل گیرنده</label></th><td><input id="wsm-expiry-email-to" class="regular-text" type="email" dir="ltr" name="wsm[expiry_alert_email_to]" value="' . esc_attr(!empty($settings['expiry_alert_email_to']) ? $settings['expiry_alert_email_to'] : get_option('admin_email')) . '"></td></tr>';
        echo '<tr><th>روش‌های اعلان</th><td><label><input type="checkbox" name="wsm[expiry_alert_email]" value="1" ' . checked(!empty($settings['expiry_alert_email']), true, false) . '> ایمیل روزانه</label><br><label><input type="checkbox" name="wsm[expiry_alert_sms]" value="1" ' . checked(!empty($settings['expiry_alert_sms']), true, false) . '> پیامک روزانه (نیازمند پیکربندی و فعال‌سازی ملی‌پیامک)</label></td></tr>';
        echo '<tr><th><label for="wsm-expiry-meta-key">کلید متای افزونهٔ انقضا</label></th><td><input id="wsm-expiry-meta-key" class="regular-text" dir="ltr" name="wsm[expiry_meta_key]" value="' . esc_attr(isset($settings['expiry_meta_key']) ? $settings['expiry_meta_key'] : '') . '" placeholder="تشخیص خودکار"><p class="description">خالی بگذارید تا کلیدهای متداولِ حاوی expiry/expiration خودکار بررسی شوند. کلید در حال استفاده: <code>' . esc_html($expiry_key_in_use !== '' ? $expiry_key_in_use : 'یافت نشد؛ تاریخ‌های این اپ استفاده می‌شوند') . '</code></p></td></tr>';
        echo '</tbody></table></div>';

        $sms_status = WSM_SMS::app_config_get(null);
        $sms_source = isset($sms_status['effective_source']) ? $sms_status['effective_source'] : 'none';
        $sms_source_labels = array(
            'plugin' => 'تنظیمات ملی‌پیامک در افزونهٔ وردپرس',
            'theme' => 'تنظیمات ملی‌پیامک در قالب',
            'manual' => 'تنظیمات دستی همین افزونه',
            'manual_fallback' => 'تنظیمات دستی جایگزین',
            'none' => 'پیکربندی کامل پیدا نشد',
        );
        $sms_discovery = $sms_source === 'plugin' ? (isset($sms_status['plugin']) ? $sms_status['plugin'] : array()) : ($sms_source === 'theme' ? (isset($sms_status['theme']) ? $sms_status['theme'] : array()) : array());
        echo '<div class="wsm-panel"><h2>پیامک ملی‌پیامک</h2><p class="description">حالت خودکار فقط تنظیمات دارای انتخاب صریح درگاه ملی‌پیامک را می‌خواند؛ اعتبارنامهٔ سرویس‌های دیگر ارسال نمی‌شود. اگر منبع معتبر پیدا نشد، حالت دستی را انتخاب کنید. رمز/کلید API دستی فقط به‌صورت رمزنگاری‌شده ذخیره می‌شود. آماده‌بودن پیکربندی به معنی تحویل پیامک نیست؛ برای بررسی اتصال، پیامک آزمایشی بفرستید.</p><table class="form-table"><tbody>';
        echo '<tr><th><label for="wsm-sms-mode">منبع تنظیمات</label></th><td><select id="wsm-sms-mode" name="wsm[sms_mode]"><option value="auto" ' . selected(isset($sms_status['mode']) ? $sms_status['mode'] : 'auto', 'auto', false) . '>خودکار از ملی‌پیامکِ وردپرس</option><option value="manual" ' . selected(isset($sms_status['mode']) ? $sms_status['mode'] : 'auto', 'manual', false) . '>دستی / نام کاربری و رمز</option></select></td></tr>';
        echo '<tr><th>وضعیت فعلی</th><td>' . esc_html(isset($sms_source_labels[$sms_source]) ? $sms_source_labels[$sms_source] : 'نامشخص') . ' — ' . (!empty($sms_status['verified']) ? 'آزمون توسط سرویس پذیرفته شد (تحویل به گوشی تأیید نشده)' : (!empty($sms_status['active']) ? 'آمادهٔ آزمون ارسال' : (!empty($sms_status['configured']) ? 'پیکربندی کامل ولی غیرفعال' : 'اطلاعات ناقص یا مبهم'))) . '</td></tr>';
        if (!empty($sms_discovery['option_name'])) { echo '<tr><th>محل تنظیمات شناسایی‌شده</th><td><code>' . esc_html($sms_discovery['option_name']) . '</code></td></tr>'; }
        echo '<tr><th><label for="wsm-sms-username">نام کاربری پنل</label></th><td><input id="wsm-sms-username" class="regular-text" name="wsm[sms_username]" value="' . esc_attr(isset($sms['username']) ? $sms['username'] : '') . '"></td></tr>';
        echo '<tr><th><label for="wsm-sms-password">رمز وب‌سرویس / کلید API پنل</label></th><td><input id="wsm-sms-password" class="regular-text" type="password" autocomplete="new-password" name="wsm[sms_password]" value="" placeholder="' . (!empty($sms['password_enc']) ? 'ذخیره شده؛ برای تغییر وارد کنید' : 'وارد کنید') . '"></td></tr>';
        echo '<tr><th><label for="wsm-sms-from">شمارهٔ ارسال‌کننده</label></th><td><input id="wsm-sms-from" class="regular-text" dir="ltr" name="wsm[sms_from]" value="' . esc_attr(isset($sms['from']) ? $sms['from'] : '') . '"><p class="description">اگر در حالت دستی خالی باشد، فقط در صورت شناسایی دقیقاً یک خط از پنل استفاده می‌شود؛ برای چند خط، شمارهٔ دلخواه را وارد کنید.</p></td></tr>';
        echo '<tr><th><label for="wsm-sms-mobile">شمارهٔ مدیر برای آزمون</label></th><td><input id="wsm-sms-mobile" class="regular-text" dir="ltr" name="wsm[sms_admin_mobile]" value="' . esc_attr(isset($sms['admin_mobile']) ? $sms['admin_mobile'] : '') . '"><p class="description">شمارهٔ مدیرِ گیرنده از خط ارسال‌کننده جداست.</p></td></tr>';
        echo '<tr><th>هشدار پیامکی</th><td><label><input type="checkbox" name="wsm[sms_enabled]" value="1" ' . checked(!empty($sms_status['enabled']), true, false) . '> فعال باشد</label></td></tr>';
        echo '</tbody></table></div>';
        self::sms_events_settings_panel($club_sms_settings);

        echo '<div class="wsm-panel"><h2>به‌روزرسانی افزونه</h2><table class="form-table"><tbody>';
        echo '<tr><th><label for="wsm-update-url">نشانی JSON نسخه‌ها (HTTPS)</label></th><td><input id="wsm-update-url" class="regular-text" type="url" dir="ltr" name="wsm[update_server_url]" value="' . esc_attr($settings['update_server_url']) . '"><p class="description">پاسخ سرور باید JSON شامل version و package (نشانی ZIP با HTTPS) باشد.</p></td></tr>';
        echo '<tr><th>به‌روزرسانی خودکار</th><td><label><input type="checkbox" name="wsm[auto_update]" value="1" ' . checked(!empty($settings['auto_update']), true, false) . '> پس از شناسایی نسخهٔ جدید، اجازهٔ نصب خودکار داده شود</label></td></tr>';
        echo '</tbody></table>';
        $update = WSM_Updater::remote_info(false);
        echo '<p>نسخهٔ نصب‌شده: <code>' . esc_html(WSM_VERSION) . '</code>';
        if (!empty($update['latest'])) {
            echo ' — آخرین نسخهٔ سرور: <code>' . esc_html($update['latest']) . '</code>';
            echo !empty($update['update_available']) ? ' <span class="wsm-badge wsm-badge-amber">نسخهٔ جدید موجود است</span>' : ' <span class="wsm-badge wsm-badge-green">به‌روز</span>';
        } elseif (!empty($update['error'])) {
            echo ' — <span class="wsm-badge wsm-badge-red">' . esc_html($update['error']) . '</span>';
        } else {
            echo ' — آدرس سرور به‌روزرسانی تنظیم نشده است.';
        }
        echo '</p></div>';

        $migration = WSM_Updater::last_migration();
        if ($migration) {
            echo '<div class="wsm-panel"><h2>آخرین مهاجرت دیتابیس</h2><p>وضعیت: <strong>' . esc_html($migration['status']) . '</strong> — ' . esc_html($migration['version_from']) . ' ← ' . esc_html($migration['version_to']) . '</p>';
            if (!empty($migration['backup'])) {
                echo '<p>بکاپ: <code>' . esc_html($migration['backup']) . '</code> ' . (!empty($migration['backup_exists']) ? 'موجود' : 'یافت نشد') . '</p>';
            }
            if (!empty($migration['error'])) {
                echo '<p class="notice notice-error inline">' . esc_html($migration['error']) . '</p>';
            }
            echo '</div>';
        }

        echo '<p><button type="submit" class="button button-primary">ذخیرهٔ تنظیمات</button></p></form>';
        echo '<div class="wsm-panel"><h2>آزمایش اعلان‌ها</h2><p>پیامک آزمایشی حتی اگر هشدار پیامکی خاموش باشد ارسال می‌شود. ایمیل به نشانی مدیر وردپرس فرستاده می‌شود.</p>';
        $sms_test = wp_nonce_url(admin_url('admin.php?page=' . self::SLUG . '-settings&wsm_action=test_sms'), 'wsm_test_sms');
        $mail_test = wp_nonce_url(admin_url('admin.php?page=' . self::SLUG . '-settings&wsm_action=test_email'), 'wsm_test_email');
        echo '<a class="button" href="' . esc_url($sms_test) . '">آزمایش پیامک</a> ';
        echo '<a class="button" href="' . esc_url($mail_test) . '">آزمایش ایمیل</a></div>';
        echo '</div></div>';
    }

    /** Return only scalar form/query values, avoiding array-to-string warnings and PHP 8 type errors. */
    private static function sanitize_permission_input($values) {
        $catalog = WSM_Devices::permission_catalog();
        $clean = array();
        foreach ((array) $values as $permission) {
            if (is_string($permission) && isset($catalog[$permission]) && !in_array($permission, $clean, true)) { $clean[] = $permission; }
        }
        return $clean;
    }

    private static function scalar_input($source, $key, $default = '') {
        if (!is_array($source) || !array_key_exists($key, $source) || !is_scalar($source[$key])) {
            return (string) $default;
        }
        return (string) $source[$key];
    }

    private static function checkbox_input($source, $key) {
        return in_array(strtolower(trim(self::scalar_input($source, $key))), array('1', 'true', 'yes', 'on'), true);
    }

    /* ---------------------------------------------------------------
     * قطعات مشترک رابط مدیریت
     * ------------------------------------------------------------- */
    private static function header($title) {
        $tabs = array('wsm' => 'داشبورد');
        if (current_user_can('manage_options')) {
            $tabs['wsm-devices'] = 'دستگاه‌ها';
            $tabs['wsm-employees'] = 'کارمندان';
            $tabs['wsm-activity'] = 'گزارش فعالیت';
            $tabs['wsm-roles'] = 'نقش‌ها و مجوزها';
            $tabs['wsm-security'] = 'امنیت';
        }
        $tabs['wsm-access-key'] = 'کلید دسترسی';
        $tabs['wsm-settings'] = 'تنظیمات';
        $current = isset($_GET['page']) && is_scalar($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : self::SLUG;
        echo '<h1 class="wsm-title">' . esc_html($title) . '</h1><div class="wsm-admin-content"><nav class="wsm-tabs">';
        foreach ($tabs as $slug => $label) {
            $class = $slug === $current ? ' class="current"' : '';
            echo '<a' . $class . ' href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function card($label, $value, $color) {
        echo '<div class="wsm-card" style="border-top-color:' . esc_attr($color) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong></div>';
    }

    private static function action_label($action) {
        $labels = array(
            'create' => 'ایجاد', 'update' => 'ویرایش', 'delete' => 'حذف', 'auth' => 'احراز هویت',
            'device' => 'دستگاه', 'refund' => 'بازپرداخت', 'status' => 'تغییر وضعیت', 'copy' => 'کپی',
            'sale' => 'فروش دستی', 'invoice' => 'فاکتور',
        );
        return isset($labels[$action]) ? $labels[$action] : (string) $action;
    }

    private static function result_badge($result) {
        if ($result === 'success') {
            return '<span class="wsm-badge wsm-badge-green">موفق</span>';
        }
        return '<span class="wsm-badge wsm-badge-red">ناموفق</span>';
    }

    private static function device_badge($status) {
        $map = array(
            'active'   => array('فعال', 'green'),
            'blocked'  => array('مسدود', 'red'),
            'unlinked' => array('قطع‌شده', 'amber'),
            'deleted'  => array('حذف‌شده', 'grey'),
        );
        if (!isset($map[$status])) {
            return '<span class="wsm-badge wsm-badge-grey">' . esc_html((string) $status) . '</span>';
        }
        return '<span class="wsm-badge wsm-badge-' . esc_attr($map[$status][1]) . '">' . esc_html($map[$status][0]) . '</span>';
    }
}
