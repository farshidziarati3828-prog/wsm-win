<?php
/**
 * مکمل‌های عملیاتی باشگاه: تعاریف، دفتر مالی، گزارش، پیامک، تنظیمات و کاربران.
 * سخت‌افزار/درگاه پرداخت به مدل و SDK ارائه‌دهنده وابسته است؛ این کلاس API عمومی جعل نمی‌کند.
 */
if (!defined('ABSPATH')) { exit; }

class WSM_Club_Operations {
    const PORTAL_CREDS_OPTION = 'wsm_athlete_portal_credentials';
    const PORTAL_INTENTS_OPTION = 'wsm_athlete_portal_intents';
    const PORTAL_REVIEWS_OPTION = 'wsm_athlete_portal_renewal_reviews';
    const PORTAL_RAFFLES_OPTION = 'wsm_athlete_portal_raffles';
    const PORTAL_COUPONS_OPTION = 'wsm_athlete_portal_coupons';

    private static $athlete_portal_current_member = null;
    private static $active_backup_operation_lock_token='';
    private static $active_backup_restore_lock_token='';

    /** Read expiration from either a tokenized lease or the legacy scheduled-lock value. */
    private static function backup_lock_expiry($lock) {
        if(is_array($lock)&&isset($lock['expires'])&&is_numeric($lock['expires'])){return (int)$lock['expires'];}
        if(is_scalar($lock)&&is_numeric($lock)){return (int)$lock;}
        return 0;
    }
    private static function backup_lock_option_is_active($name) {
        return is_string($name)&&$name!==''&&self::backup_lock_expiry(get_option($name,false))>time();
    }
    private static function backup_creation_lock_is_active() {
        return self::backup_lock_option_is_active('wsm_backup_operation_lock')||self::backup_lock_option_is_active('wsm_backup_cron_lock');
    }
    private static function clear_backup_lock_option_cache($name) {
        if(function_exists('wp_cache_delete')){wp_cache_delete($name,'options');wp_cache_delete('notoptions','options');}
    }
    /** Invalidate selected restore-option caches around a restore preview/apply transaction. */
    private static function clear_backup_restore_settings_cache($selected) {
        if(!function_exists('wp_cache_delete')||!is_array($selected)||!isset($selected['plugin_settings'])||!is_array($selected['plugin_settings'])){return;}
        $names=array();$allowed=self::backup_club_option_names();
        if(isset($selected['plugin_settings']['options'])&&is_array($selected['plugin_settings']['options'])){foreach(array_keys($selected['plugin_settings']['options']) as $name){if(in_array($name,$allowed,true)){$names[]=$name;}}}
        if(array_key_exists('backup_configuration',$selected['plugin_settings'])){$names[]='wsm_backup_settings';}
        if(!$names){return;}
        foreach(array_unique($names) as $name){wp_cache_delete($name,'options');}
        wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
    }
    /** Compare-and-delete an expired option lease so a stale reader cannot delete a newer owner's lock. */
    private static function backup_lock_option_compare_delete($name,$expected) {
        global $wpdb;
        if(!is_string($name)||$name===''||$expected===false||!is_object($wpdb)||empty($wpdb->options)){return false;}
        $serialized=maybe_serialize($expected);
        if(!is_scalar($serialized)){return false;}
        $table='`'.str_replace('`','``',(string)$wpdb->options).'`';
        $deleted=$wpdb->query($wpdb->prepare("DELETE FROM $table WHERE option_name=%s AND BINARY option_value=BINARY %s",$name,(string)$serialized));
        self::clear_backup_lock_option_cache($name);
        return $deleted===1;
    }
    /** Compare-and-swap a lease renewal without overwriting a lock acquired by another request. */
    private static function backup_lock_option_compare_update($name,$expected,$replacement) {
        global $wpdb;
        if(!is_string($name)||$name===''||!is_object($wpdb)||empty($wpdb->options)){return false;}
        $old_value=maybe_serialize($expected);$new_value=maybe_serialize($replacement);
        if(!is_scalar($old_value)||!is_scalar($new_value)){return false;}
        $table='`'.str_replace('`','``',(string)$wpdb->options).'`';
        $updated=$wpdb->query($wpdb->prepare("UPDATE $table SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s",(string)$new_value,$name,(string)$old_value));
        self::clear_backup_lock_option_cache($name);
        if($updated===1){return true;}
        if($updated!==0){return false;}
        return get_option($name,false)===$replacement;
    }
    /** Add a lease or atomically replace only the exact expired value observed by this request. */
    private static function acquire_backup_lock_option($name,$ttl) {
        try{$token=bin2hex(random_bytes(16));}catch(Exception $e){return new WP_Error('wsm_backup_lock_random_failed','ساخت شناسهٔ امن برای قفل بکاپ انجام نشد.',array('status'=>500));}
        $lease=array('token'=>$token,'expires'=>time()+max(60,(int)$ttl));
        if(add_option($name,$lease,'','no')){return $token;}
        $current=get_option($name,false);
        if(self::backup_lock_expiry($current)>time()){return false;}
        if(!self::backup_lock_option_compare_delete($name,$current)){return false;}
        return add_option($name,$lease,'','no')?$token:false;
    }
    private static function renew_backup_lock_option($name,$token,$ttl) {
        $lock=get_option($name,false);
        if(!is_array($lock)||!isset($lock['token'],$lock['expires'])||!is_string($lock['token'])||!hash_equals($lock['token'],(string)$token)){return false;}
        $updated=$lock;$updated['expires']=time()+max(60,(int)$ttl);
        if(!self::backup_lock_option_compare_update($name,$lock,$updated)){return false;}
        $check=get_option($name,false);
        return is_array($check)&&isset($check['token'],$check['expires'])&&is_string($check['token'])&&hash_equals($check['token'],(string)$token)&&(int)$check['expires']>time();
    }
    private static function release_backup_lock_option($name,$token) {
        $lock=get_option($name,false);
        return is_array($lock)&&isset($lock['token'])&&is_string($lock['token'])&&hash_equals($lock['token'],(string)$token)&&self::backup_lock_option_compare_delete($name,$lock);
    }
    private static function backup_restore_lock_is_active() {
        return self::backup_lock_option_is_active('wsm_backup_restore_lock');
    }
    private static function acquire_backup_restore_lock() {
        if(self::backup_creation_lock_is_active()){return new WP_Error('wsm_backup_in_progress','ساخت بکاپ در حال اجراست؛ بازیابی پس از پایان آن دوباره انجام شود.',array('status'=>409));}
        $token=self::acquire_backup_lock_option('wsm_backup_restore_lock',4*HOUR_IN_SECONDS);
        if(is_wp_error($token)){return new WP_Error('wsm_restore_lock_random_failed','ساخت قفل امن بازیابی انجام نشد.',array('status'=>500));}
        if(!$token){return new WP_Error('wsm_restore_in_progress','بازیابی دیگری در حال اجراست؛ بعداً دوباره تلاش کنید.',array('status'=>409));}
        if(self::backup_creation_lock_is_active()){
            self::release_backup_lock_option('wsm_backup_restore_lock',$token);
            return new WP_Error('wsm_backup_in_progress','ساخت بکاپ هم‌زمان آغاز شد؛ بازیابی برای ایمنی متوقف شد.',array('status'=>409));
        }
        return $token;
    }
    private static function renew_backup_restore_lock($token) {
        if(self::backup_creation_lock_is_active()){return false;}
        return self::renew_backup_lock_option('wsm_backup_restore_lock',$token,4*HOUR_IN_SECONDS);
    }
    private static function release_backup_restore_lock($token) {
        self::release_backup_lock_option('wsm_backup_restore_lock',$token);
    }
    /** Shared lease prevents manual or scheduled backups from overlapping a restore. */
    private static function acquire_backup_operation_lock() {
        if(self::backup_restore_lock_is_active()||self::backup_lock_option_is_active('wsm_backup_cron_lock')){return new WP_Error('wsm_backup_in_progress','بازیابی یا بکاپ زمان‌بندی‌شده در حال اجراست؛ بعداً دوباره تلاش کنید.',array('status'=>409));}
        $token=self::acquire_backup_lock_option('wsm_backup_operation_lock',4*HOUR_IN_SECONDS);
        if(is_wp_error($token)){return $token;}
        if(!$token){return new WP_Error('wsm_backup_in_progress','عملیات بکاپ دیگری در حال اجراست؛ بعداً دوباره تلاش کنید.',array('status'=>409));}
        if(self::backup_restore_lock_is_active()||self::backup_lock_option_is_active('wsm_backup_cron_lock')){
            self::release_backup_lock_option('wsm_backup_operation_lock',$token);
            return new WP_Error('wsm_backup_in_progress','بازیابی یا بکاپ زمان‌بندی‌شده هم‌زمان آغاز شد؛ بکاپ برای ایمنی متوقف شد.',array('status'=>409));
        }
        return $token;
    }
    private static function renew_active_backup_lock_lease() {
        if(self::$active_backup_operation_lock_token!==''){
            if(self::backup_restore_lock_is_active()){return false;}
            return self::renew_backup_lock_option('wsm_backup_operation_lock',self::$active_backup_operation_lock_token,4*HOUR_IN_SECONDS);
        }
        if(self::$active_backup_restore_lock_token!==''){return self::renew_backup_restore_lock(self::$active_backup_restore_lock_token);}
        return true;
    }
    private static function backup_restore_preview_is_safe($preview) {
        if(!is_array($preview)||!isset($preview['tables'],$preview['summary'],$preview['plan_fingerprint'])||!is_array($preview['tables'])||!is_array($preview['summary'])||!is_string($preview['plan_fingerprint'])||!preg_match('/^[a-f0-9]{64}$/',$preview['plan_fingerprint'])){return new WP_Error('wsm_restore_preview_invalid','نتیجهٔ پیش‌نمایش بازیابی معتبر نیست.',array('status'=>500));}
        foreach($preview['tables'] as $table){if(!is_array($table)||!isset($table['status'])||(string)$table['status']!=='ready'||!empty($table['conflicts'])){return new WP_Error('wsm_restore_conflict','پیش‌نمایش شامل جدول ناسازگار یا تعارض است؛ هیچ تغییری اعمال نشد.',array('status'=>409));}}
        if(!empty($preview['summary']['options_conflicts'])){return new WP_Error('wsm_restore_option_conflict','پیش‌نمایش شامل تعارض در تنظیمات افزونه است؛ هیچ تغییری اعمال نشد.',array('status'=>409));}
        if(isset($preview['plugin_files']['summary']['conflicts'])&&(int)$preview['plugin_files']['summary']['conflicts']>0){return new WP_Error('wsm_restore_file_conflict','پیش‌نمایش شامل تعارض در مسیر فایل‌هاست؛ هیچ تغییری اعمال نشد.',array('status'=>409));}
        return true;
    }
    private static function backup_restore_table_runtime($table_data) {
        global $wpdb;
        if(!is_object($wpdb)||empty($wpdb->prefix)||!preg_match('/^[A-Za-z0-9_]+$/',(string)$wpdb->prefix)||!is_array($table_data)||empty($table_data['name'])){return new WP_Error('wsm_restore_table_invalid','جدول بازیابی معتبر نیست.',array('status'=>400));}
        $logical=(string)$table_data['name'];
        if(!preg_match('/^wsm_[A-Za-z0-9_]+$/D',$logical)){return new WP_Error('wsm_restore_table_invalid','نام جدول بازیابی مجاز نیست.',array('status'=>400));}
        if(!self::backup_club_table_name($logical)){return new WP_Error('wsm_restore_not_club_table','این جدول دادهٔ عملیاتی باشگاه نیست و بازیابی نمی‌شود.',array('status'=>400));}
        $physical=(string)$wpdb->prefix.$logical;$quoted='`'.str_replace('`','``',$physical).'`';
        $matches=$wpdb->get_results($wpdb->prepare('SHOW FULL TABLES LIKE %s',$wpdb->esc_like($physical)),ARRAY_N);
        if(!is_array($matches)||$wpdb->last_error!==''){return new WP_Error('wsm_restore_table_check_failed','بررسی جدول مقصد انجام نشد.',array('status'=>500));}
        $base=false;foreach($matches as $match){if(isset($match[0],$match[1])&&(string)$match[0]===$physical&&strtoupper((string)$match[1])==='BASE TABLE'){$base=true;break;}}
        if(!$base){return new WP_Error('wsm_restore_table_missing','جدول مقصد وجود ندارد یا جدول پایه نیست.',array('status'=>409));}
        $schema=$wpdb->get_results("SHOW COLUMNS FROM $quoted",ARRAY_A);
        if(!is_array($schema)||$wpdb->last_error!==''){return new WP_Error('wsm_restore_schema_failed','ساختار جدول مقصد خوانده نشد.',array('status'=>500));}
        $live=array();$generated=array();$schema_by_name=array();
        foreach($schema as $column){if(!is_array($column)||empty($column['Field'])){continue;}$field=(string)$column['Field'];$live[$field]=true;$schema_by_name[$field]=$column;if(isset($column['Extra'])&&strpos(strtolower((string)$column['Extra']),'generated')!==false){$generated[$field]=true;}}
        foreach($table_data['columns'] as $column){if(!isset($live[$column])){return new WP_Error('wsm_restore_schema_incompatible','ستونی از بکاپ در جدول مقصد وجود ندارد.',array('status'=>409));}}
        $keys=$wpdb->get_results("SHOW KEYS FROM $quoted",ARRAY_A);
        if(!is_array($keys)||$wpdb->last_error!==''){return new WP_Error('wsm_restore_primary_key_failed','کلید اصلی جدول مقصد خوانده نشد.',array('status'=>500));}
        $pkrows=array();foreach($keys as $key){if(isset($key['Key_name'])&&strtoupper((string)$key['Key_name'])==='PRIMARY'){$pkrows[]=$key;}}
        usort($pkrows,function($a,$b){return (int)($a['Seq_in_index']??0)<=>(int)($b['Seq_in_index']??0);});
        $pk=array();foreach($pkrows as $key){if(isset($key['Column_name'])){$pk[]=(string)$key['Column_name'];}}
        if(!$pk||$pk!==$table_data['primary_key']){return new WP_Error('wsm_restore_primary_key_incompatible','کلید اصلی مقصد با فایل بکاپ سازگار نیست.',array('status'=>409));}
        foreach($pk as $key){
            $type=isset($schema_by_name[$key]['Type'])?(string)$schema_by_name[$key]['Type']:'';
            $integer_type=preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)\b/i',$type);
            $sync_uuid_type=$logical==='wsm_sync_member_map'&&$pk===array('offline_uuid')&&$key==='offline_uuid'&&preg_match('/^varchar\(36\)$/i',$type);
            if(!$integer_type&&!$sync_uuid_type){return new WP_Error('wsm_restore_primary_key_type_unsupported','نوع کلید اصلی مقصد برای بازیابی امن پشتیبانی نمی‌شود؛ تنها offline_uuid از نوع varchar(36) در جدول نگاشت عضو مجاز است.',array('status'=>409));}
        }
        $write=array();foreach($table_data['columns'] as $column){if(!isset($generated[$column])){$write[]=$column;}}
        $all_columns=array_keys($live);$all_write=array();foreach($all_columns as $column){if(!isset($generated[$column])){$all_write[]=$column;}}
        return array('physical'=>$physical,'quoted'=>$quoted,'primary_key'=>$pk,'write_columns'=>$write,'all_columns'=>$all_columns,'all_writable_columns'=>$all_write);
    }
    private static function backup_restore_row_matches($left,$right,$columns) {
        if(!is_array($left)||!is_array($right)){return false;}
        foreach($columns as $column){if(!array_key_exists($column,$left)||!array_key_exists($column,$right)||!self::backup_preview_values_equal($left[$column],$right[$column])){return false;}}
        return true;
    }
    private static function backup_restore_row_fingerprint($row) {
        if(!is_array($row)){return false;}
        $json=wp_json_encode($row,self::backup_json_flags());
        return is_string($json)?hash('sha256',$json):false;
    }
    /** Replace the selected table row set from the archive; omitted live rows are deleted after preview and confirmation. */
    private static function backup_restore_apply_table($table_data,&$changed_tokens,&$counts,$expected_deletes) {
        global $wpdb;$runtime=self::backup_restore_table_runtime($table_data);if(is_wp_error($runtime)){return $runtime;}
        if(!is_int($expected_deletes)||$expected_deletes<0){return new WP_Error('wsm_restore_delete_plan_invalid','تعداد حذف‌های برنامهٔ بازیابی معتبر نیست.',array('status'=>500));}
        $changed_tokens=array();$counts=array('inserts'=>0,'updates'=>0,'unchanged'=>0,'deletes'=>0);$seen=array();$rows=$table_data['rows'];$pk=$runtime['primary_key'];$size=count($pk)===1?200:50;
        foreach($rows as $row){$token=self::backup_preview_key_token($row,$pk);if($token===false||isset($seen[$token])){return new WP_Error('wsm_restore_source_key_conflict','کلید اصلی ردیف‌های بکاپ نامعتبر یا تکراری است.',array('status'=>409));}$seen[$token]=true;}
        $deleted=self::backup_restore_walk_live_rows($runtime['quoted'],$pk,function($live_row,$token) use (&$seen,&$changed_tokens,&$counts,$runtime,$wpdb,$expected_deletes){
            if(isset($seen[$token])){return true;}
            if($counts['deletes']>=$expected_deletes){return new WP_Error('wsm_restore_delete_plan_stale','تعداد ردیف‌های حذف‌شونده پس از پیش‌نمایش تغییر کرده است؛ هیچ تغییری اعمال نشد.',array('status'=>409));}
            $where=array();foreach($runtime['all_writable_columns'] as $column){if(!array_key_exists($column,$live_row)){return new WP_Error('wsm_restore_delete_row_invalid','ستون لازم برای حذف ایمن ردیف اضافی وجود ندارد.',array('status'=>500));}$where[$column]=$live_row[$column];}
            if(!$where){return new WP_Error('wsm_restore_delete_where_empty','شرط امن حذف ردیف اضافی خالی است.',array('status'=>500));}
            $removed=$wpdb->delete($runtime['physical'],$where,array_fill(0,count($where),'%s'));
            if($removed===false||$removed<1){return new WP_Error('wsm_restore_delete_failed','حذف ردیف خارج از بکاپ ناموفق بود یا ردیف هم‌زمان تغییر کرد؛ بازیابی متوقف شد.',array('status'=>409));}
            $changed_tokens[$token]=array('operation'=>'delete','changed_columns'=>array(),'post_fingerprint'=>hash('sha256','wsm-restore-deleted:'.$token));
            $counts['deletes']++;return true;
        },50,true);
        if(is_wp_error($deleted)){return $deleted;}
        if($counts['deletes']!==$expected_deletes){return new WP_Error('wsm_restore_delete_plan_stale','تعداد ردیف‌های حذف‌شده با پیش‌نمایش تأییدشده مطابقت ندارد؛ بازیابی متوقف شد.',array('status'=>409));}
        $update_verify_columns=array_values(array_filter($runtime['write_columns'],function($column){return strtolower((string)$column)!=='guid';}));
        for($offset=0;$offset<count($rows);$offset+=$size){
            if(self::$active_backup_restore_lock_token!==''&&!self::renew_backup_restore_lock(self::$active_backup_restore_lock_token)){return new WP_Error('wsm_restore_lock_lost','قفل بازیابی هنگام اجرای دستهٔ ردیف‌ها از دست رفت.',array('status'=>409));}
            $chunk=array_slice($rows,$offset,$size);
            $live=self::backup_preview_load_live_rows($runtime['quoted'],$pk,$chunk);if(is_wp_error($live)){return $live;}
            foreach($chunk as $row){
                $token=self::backup_preview_key_token($row,$pk);
                if(isset($live[$token])){
                    $current=$live[$token];$data=array();$where=array();foreach($pk as $key){$where[$key]=$current[$key];}
                    foreach($runtime['write_columns'] as $column){
                        // Values (including PHP-serialized strings) are bound as data; never URL-REPLACE or rewrite an existing GUID.
                        if(in_array($column,$pk,true)||strtolower((string)$column)==='guid'){continue;}
                        if(!self::backup_preview_values_equal($row[$column],$current[$column])){$data[$column]=$row[$column];$where[$column]=$current[$column];}
                    }
                    if(!$data){$counts['unchanged']++;continue;}
                    $updated=$wpdb->update($runtime['physical'],$data,$where,array_fill(0,count($data),'%s'),array_fill(0,count($where),'%s'));
                    if($updated===false||$updated<1){return new WP_Error('wsm_restore_update_failed','به‌روزرسانی ردیف ناموفق یا هم‌زمان تغییر کرده است.',array('status'=>409));}
                    $changed_tokens[$token]=array('operation'=>'update','changed_columns'=>array_keys($data),'post_fingerprint'=>'');
                    $verify=self::backup_preview_load_live_rows($runtime['quoted'],$pk,array($row));
                    if(is_wp_error($verify)||!isset($verify[$token])||!self::backup_restore_row_matches($verify[$token],$row,$update_verify_columns)){return new WP_Error('wsm_restore_update_verify_failed','بررسی نتیجهٔ به‌روزرسانی ردیف ناموفق بود.',array('status'=>500));}
                    $fingerprint=self::backup_restore_row_fingerprint($verify[$token]);if($fingerprint===false){return new WP_Error('wsm_restore_update_fingerprint_failed','ثبت اثر ردیف تغییر‌یافته برای بازگردانی جبرانی ممکن نشد.',array('status'=>500));}
                    $changed_tokens[$token]['post_fingerprint']=$fingerprint;$counts['updates']++;
                }else{
                    $data=array();foreach($runtime['write_columns'] as $column){$data[$column]=$row[$column];}
                    if(!$data){return new WP_Error('wsm_restore_insert_columns_empty','ستون قابل‌نوشتنی برای درج ردیف وجود ندارد.',array('status'=>409));}
                    $inserted=$wpdb->insert($runtime['physical'],$data,array_fill(0,count($data),'%s'));
                    if($inserted===false||$inserted<1){return new WP_Error('wsm_restore_insert_failed','درج ردیف ناموفق بود؛ تعارض یکتا یا تغییر هم‌زمان محتمل است.',array('status'=>409));}
                    $changed_tokens[$token]=array('operation'=>'insert','changed_columns'=>$runtime['all_writable_columns'],'post_fingerprint'=>'');
                    $verify=self::backup_preview_load_live_rows($runtime['quoted'],$pk,array($row));
                    if(is_wp_error($verify)||!isset($verify[$token])||!self::backup_restore_row_matches($verify[$token],$row,$runtime['write_columns'])){return new WP_Error('wsm_restore_insert_verify_failed','بررسی نتیجهٔ درج ردیف ناموفق بود.',array('status'=>500));}
                    $fingerprint=self::backup_restore_row_fingerprint($verify[$token]);if($fingerprint===false){return new WP_Error('wsm_restore_insert_fingerprint_failed','ثبت اثر ردیف درج‌شده برای بازگردانی جبرانی ممکن نشد.',array('status'=>500));}
                    $changed_tokens[$token]['post_fingerprint']=$fingerprint;$counts['inserts']++;
                }
            }
        }
        return true;
    }
    private static function backup_restore_failure_with_snapshot($error,$snapshot,$rollback_attempted=false,$rollback_succeeded=null) {
        if(!is_wp_error($error)){$error=new WP_Error('wsm_restore_failed','بازیابی انجام نشد.',array('status'=>500));}
        $meta=$error->get_error_data();$status=is_array($meta)&&isset($meta['status'])?(int)$meta['status']:500;
        $message=$error->get_error_message().' نسخهٔ پشتیبان پیش از بازیابی حفظ شده است.';
        if($rollback_attempted){$message.=$rollback_succeeded?' فرمان بازگشت تراکنش صادر شد، اما لزوماً جدول‌های غیرتراکنشی را بازنمی‌گرداند.':' اجرای فرمان بازگشت تراکنش نیز ناموفق بود.';}else{$message.=' هنوز نوشتنی آغاز نشده است.';}
        return new WP_Error($error->get_error_code(),$message,array('status'=>$status,'pre_restore_backup_id'=>$snapshot['pre_restore_backup_id'],'pre_restore_download_url'=>$snapshot['pre_restore_download_url'],'transaction_rollback_attempted'=>(bool)$rollback_attempted,'transaction_rollback_succeeded'=>$rollback_succeeded));
    }
    /** Shared name-based detector for credentials in option arrays and table fields. */
    private static function backup_sensitive_field_name($name) {
        if(!is_string($name)){return false;}
        return (bool)preg_match('/(?:password|passwd|secret|credential|private[_-]?key|signing[_-]?key|encryption[_-]?key|(?:api|access|auth|refresh|oauth|session|bearer|consumer)[_-]?(?:key|token)|(?:token|refresh|key|nonce)[_-]?hash)/i',$name);
    }

    /** Preserve credentials omitted by the backup serializer while applying safe option data. */
    private static function backup_restore_sensitive_subtree($value,$depth=0) {
        if($depth>32||!is_array($value)){return array();}
        $result=array();
        foreach($value as $key=>$item){
            if(self::backup_sensitive_field_name($key)){$result[$key]=$item;}
            elseif(is_array($item)){$nested=self::backup_restore_sensitive_subtree($item,$depth+1);if($nested){$result[$key]=$nested;}}
        }
        return $result;
    }
    private static function backup_restore_merge_sensitive($source,$current,$depth=0) {
        if($depth>32||!is_array($source)||!is_array($current)){return $source;}
        $result=$source;
        foreach($current as $key=>$item){
            if(self::backup_sensitive_field_name($key)){$result[$key]=$item;}
            elseif(is_array($item)){
                $nested=self::backup_restore_sensitive_subtree($item,$depth+1);
                if(!$nested){continue;}
                $result[$key]=(array_key_exists($key,$source)&&is_array($source[$key]))?self::backup_restore_merge_sensitive($source[$key],$item,$depth+1):$nested;
            }
        }
        return $result;
    }
    private static function backup_restore_apply_option($name,$source,&$changed,&$was_new) {
        $changed=false;$was_new=false;
        $allowed=self::backup_club_option_names();
        if(!is_string($name)||!in_array($name,$allowed,true)||!is_array($source)){return new WP_Error('wsm_restore_option_invalid','گزینهٔ انتخاب‌شده برای بازیابی مجاز نیست.',array('status'=>400));}
        $safe_source=self::filter_backup_setting_value($source);
        if(is_wp_error($safe_source)||!self::backup_preview_json_equal($safe_source,$source)){return new WP_Error('wsm_restore_option_sensitive','فایل بکاپ شامل کلید حساسِ غیرقابل‌بازیابی است؛ هیچ مقداری از آن اعمال نشد.',array('status'=>400));}
        $missing=new stdClass();$current=get_option($name,$missing);
        if($current===$missing){$was_new=true;$value=$safe_source;}
        else{
            $safe_current=self::filter_backup_setting_value($current);
            if(is_wp_error($safe_current)){return new WP_Error('wsm_restore_option_conflict','تنظیم فعلی قابل‌مقایسهٔ امن نیست.',array('status'=>409));}
            if(self::backup_preview_json_equal($safe_current,$safe_source)){return true;}
            $value=self::backup_restore_merge_sensitive($safe_source,$current);
        }
        $changed=true;
        $updated=update_option($name,$value,false);
        $verify=get_option($name,$missing);
        if($verify===$missing){return new WP_Error('wsm_restore_option_verify_failed','گزینهٔ بازیابی‌شده پس از ذخیره پیدا نشد.',array('status'=>500));}
        $safe_verify=self::filter_backup_setting_value($verify);
        if(is_wp_error($safe_verify)||!self::backup_preview_json_equal($safe_verify,$safe_source)){return new WP_Error('wsm_restore_option_verify_failed','بررسی گزینهٔ ذخیره‌شده با مقدار امن بکاپ مطابقت نداشت.',array('status'=>500));}
        if(!$updated&&!$changed){$changed=true;}
        return true;
    }
    private static function backup_restore_apply_configuration($source,&$changed) {
        $before=self::get_backup_configuration();$changed=true;
        $saved=self::save_backup_configuration($source);
        if(is_wp_error($saved)){return $saved;}
        $after=self::get_backup_configuration();
        $changed=!self::backup_preview_json_equal($before,$after);
        if(!is_array($saved)||!self::backup_preview_json_equal($after,$saved)){return new WP_Error('wsm_restore_configuration_verify_failed','بررسی تنظیمات بکاپ پس از ذخیره ناموفق بود.',array('status'=>500));}
        return true;
    }

    /** Compensate table writes against the validated pre-restore snapshot, without clobbering later edits. */
    private static function backup_restore_rollback_table($before_table,$source_table,$journal) {
        global $wpdb;
        if(!is_array($before_table)||!is_array($source_table)||!is_array($journal)){return new WP_Error('wsm_restore_rollback_table_data_invalid','اطلاعات بازگردانی جدول معتبر نیست.',array('status'=>500));}
        $runtime=self::backup_restore_table_runtime($source_table);
        if(is_wp_error($runtime)){return $runtime;}
        if(!isset($before_table['columns'],$before_table['primary_key'],$before_table['rows'])||!is_array($before_table['columns'])||!self::backup_array_is_list($before_table['columns'])||$before_table['primary_key']!==$runtime['primary_key']||!is_array($before_table['rows'])){
            return new WP_Error('wsm_restore_rollback_schema_changed','ساختار جدول از زمان نسخهٔ پشتیبان پیش از بازیابی تغییر کرده است؛ بازگردانی خودکار انجام نشد.',array('status'=>409));
        }
        $before_column_set=array();
        foreach($before_table['columns'] as $column){
            if(!is_string($column)||!in_array($column,$runtime['all_columns'],true)){return new WP_Error('wsm_restore_rollback_schema_changed','ساختار جدول از زمان نسخهٔ پشتیبان پیش از بازیابی تغییر کرده است؛ بازگردانی خودکار انجام نشد.',array('status'=>409));}
            $before_column_set[$column]=true;
        }
        foreach($runtime['primary_key'] as $column){if(!isset($before_column_set[$column])){return new WP_Error('wsm_restore_rollback_schema_changed','کلید اصلی جدول در نسخهٔ پشتیبان پیش از بازیابی موجود نیست؛ بازگردانی خودکار انجام نشد.',array('status'=>409));}}
        $source_rows=array();
        foreach($source_table['rows'] as $row){
            $token=self::backup_preview_key_token($row,$runtime['primary_key']);
            if($token===false){return new WP_Error('wsm_restore_rollback_key_invalid','کلید اصلی ردیف برای بازگردانی معتبر نیست.',array('status'=>500));}
            if(array_key_exists($token,$journal)){if(isset($source_rows[$token])){return new WP_Error('wsm_restore_rollback_source_duplicate','کلید اصلی در فایل بازیابی تکراری است.',array('status'=>500));}$source_rows[$token]=$row;}
        }
        $before_rows=array();
        foreach($before_table['rows'] as $row){
            $token=self::backup_preview_key_token($row,$runtime['primary_key']);
            if($token===false){return new WP_Error('wsm_restore_rollback_snapshot_key_invalid','کلید اصلی در نسخهٔ پشتیبان پیش از بازیابی معتبر نیست.',array('status'=>500));}
            if(array_key_exists($token,$journal)){if(isset($before_rows[$token])){return new WP_Error('wsm_restore_rollback_snapshot_duplicate','نسخهٔ پشتیبان پیش از بازیابی دارای کلید اصلی تکراری است.',array('status'=>500));}$before_rows[$token]=$row;}
        }
        $entries=array();$pk=$runtime['primary_key'];
        foreach($journal as $token=>$entry){
            if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/',$token)||!is_array($entry)||!isset($entry['operation'])){return new WP_Error('wsm_restore_rollback_journal_invalid','دفتر تغییرات جدول برای بازگردانی معتبر نیست.',array('status'=>500));}
            $operation=(string)$entry['operation'];$before_exists=array_key_exists($token,$before_rows);$source_exists=array_key_exists($token,$source_rows);
            if($operation==='delete'){
                $expected=hash('sha256','wsm-restore-deleted:'.$token);
                if($source_exists||!$before_exists||!isset($entry['post_fingerprint'])||!is_string($entry['post_fingerprint'])||!hash_equals($expected,$entry['post_fingerprint'])){return new WP_Error('wsm_restore_rollback_delete_journal_invalid','اطلاعات ردیف حذف‌شده برای بازگردانی کامل نیست.',array('status'=>500));}
                $reference=$before_rows[$token];
            }elseif(in_array($operation,array('update','insert'),true)){
                if(!$source_exists||!isset($entry['post_fingerprint'])||!is_string($entry['post_fingerprint'])||!preg_match('/^[a-f0-9]{64}$/',$entry['post_fingerprint'])){return new WP_Error('wsm_restore_rollback_journal_invalid','دفتر تغییرات یک ردیف در جدول '.$source_table['name'].' کامل نیست.',array('status'=>500));}
                $reference=$source_rows[$token];
            }else{return new WP_Error('wsm_restore_rollback_journal_invalid','نوع تغییر ثبت‌شده برای جدول ناشناخته است.',array('status'=>500));}
            $key_row=array();foreach($pk as $key){if(!array_key_exists($key,$reference)){return new WP_Error('wsm_restore_rollback_key_invalid','کلید اصلی ردیف برای بازگردانی کامل نیست.',array('status'=>500));}$key_row[$key]=$reference[$key];}
            $entries[]=array('token'=>$token,'operation'=>$operation,'source_row'=>$source_exists?$source_rows[$token]:null,'before_exists'=>$before_exists,'before_row'=>$before_exists?$before_rows[$token]:null,'key_row'=>$key_row,'journal'=>$entry);
        }
        if(!$entries){return true;}
        $entries=array_reverse($entries);$issues=array();$batch_size=count($pk)===1?200:50;
        for($offset=0;$offset<count($entries);$offset+=$batch_size){
            if(self::$active_backup_restore_lock_token!==''&&!self::renew_backup_restore_lock(self::$active_backup_restore_lock_token)){return new WP_Error('wsm_restore_lock_lost','قفل بازیابی هنگام بازگردانی جبرانی از دست رفت.',array('status'=>409));}
            $chunk=array_slice($entries,$offset,$batch_size);$key_rows=array();foreach($chunk as $item){$key_rows[]=$item['key_row'];}
            $live=self::backup_preview_load_live_rows($runtime['quoted'],$pk,$key_rows);
            if(is_wp_error($live)){$issues[]='خواندن ردیف‌های فعلی جدول '.$source_table['name'].' برای بازگردانی ناموفق بود.';continue;}
            foreach($chunk as $item){
                $token=$item['token'];$operation=$item['operation'];$source_row=$item['source_row'];$before_exists=$item['before_exists'];$before_row=$item['before_row'];$entry=$item['journal'];$current_exists=isset($live[$token]);$current=$current_exists?$live[$token]:null;
                if($operation==='delete'){
                    if(!$before_exists||!is_array($before_row)){$issues[]='ردیف حذف‌شدهٔ جدول '.$source_table['name'].' در نسخهٔ پشتیبان پیش از بازیابی پیدا نشد.';continue;}
                    if($current_exists){
                        if(self::backup_restore_row_matches($current,$before_row,$before_table['columns'])){continue;}
                        $issues[]='ردیف حذف‌شدهٔ جدول '.$source_table['name'].' پس از توقف بازیابی دوباره تغییر کرده است؛ برای ایمنی دست‌نخورده ماند.';continue;
                    }
                    $data=array();foreach($runtime['all_writable_columns'] as $column){if(!array_key_exists($column,$before_row)){$issues[]='نسخهٔ پشتیبان پیش از بازیابی همهٔ ستون‌های لازم برای بازگرداندن ردیف حذف‌شدهٔ جدول '.$source_table['name'].' را ندارد؛ بازگردانی خودکار کامل نشد.';continue 2;}$data[$column]=$before_row[$column];}
                    if(!$data){$issues[]='ستون امنی برای بازگرداندن ردیف حذف‌شده از جدول '.$source_table['name'].' وجود ندارد.';continue;}
                    $inserted=$wpdb->insert($runtime['physical'],$data,array_fill(0,count($data),'%s'));
                    if($inserted===false||$inserted<1){$issues[]='بازگرداندن ردیف حذف‌شده به جدول '.$source_table['name'].' ناموفق بود.';continue;}
                    $verify=self::backup_preview_load_live_rows($runtime['quoted'],$pk,array($item['key_row']));
                    if(is_wp_error($verify)||!isset($verify[$token])||!self::backup_restore_row_matches($verify[$token],$before_row,$before_table['columns'])){$issues[]='بررسی بازگرداندن ردیف حذف‌شده از جدول '.$source_table['name'].' ناموفق بود.';}
                }elseif($operation==='update'){
                    if(!$before_exists||!is_array($before_row)){$issues[]='ردیف به‌روزرسانی‌شدهٔ جدول '.$source_table['name'].' در نسخهٔ پشتیبان پیش از بازیابی پیدا نشد.';continue;}
                    if(!$current_exists){$issues[]='ردیف به‌روزرسانی‌شدهٔ جدول '.$source_table['name'].' اکنون مفقود است.';continue;}
                    if(self::backup_restore_row_matches($current,$before_row,$before_table['columns'])){continue;}
                    $fingerprint=self::backup_restore_row_fingerprint($current);
                    if(!is_string($fingerprint)||!hash_equals((string)$entry['post_fingerprint'],$fingerprint)){$issues[]='ردیف جدول '.$source_table['name'].' پس از بازیابی دوباره تغییر کرده است؛ برای ایمنی دست‌نخورده ماند.';continue;}
                    $changed_columns=isset($entry['changed_columns'])&&is_array($entry['changed_columns'])?$entry['changed_columns']:array();
                    if(!$changed_columns){$issues[]='فهرست ستون‌های تغییریافته در جدول '.$source_table['name'].' کامل نیست.';continue;}
                    $data=array();$where=array();foreach($pk as $key){$where[$key]=$current[$key];}
                    foreach($changed_columns as $column){
                        if(!is_string($column)||in_array($column,$pk,true)||!isset($before_column_set[$column])||!in_array($column,$runtime['all_writable_columns'],true)||!array_key_exists($column,$current)||!array_key_exists($column,$before_row)){$issues[]='ستون تغییریافته در جدول '.$source_table['name'].' با نسخهٔ پشتیبان سازگار نیست.';continue 2;}
                        if(!self::backup_preview_values_equal($current[$column],$before_row[$column])){$data[$column]=$before_row[$column];$where[$column]=$current[$column];}
                    }
                    if(!$data){continue;}
                    $updated=$wpdb->update($runtime['physical'],$data,$where,array_fill(0,count($data),'%s'),array_fill(0,count($where),'%s'));
                    if($updated===false){$issues[]='نوشتن بازگردانی ردیف جدول '.$source_table['name'].' ناموفق بود.';continue;}
                    $verify=self::backup_preview_load_live_rows($runtime['quoted'],$pk,array($item['key_row']));
                    if(is_wp_error($verify)||!isset($verify[$token])||!self::backup_restore_row_matches($verify[$token],$before_row,$before_table['columns'])){$issues[]='بررسی بازگردانی ردیف جدول '.$source_table['name'].' ناموفق بود.';}
                }elseif($operation==='insert'){
                    if($before_exists){$issues[]='ردیفِ ثبت‌شده به‌عنوان درج جدید در نسخهٔ پشتیبان از قبل وجود داشت؛ حذف نشد.';continue;}
                    if(!$current_exists){continue;}
                    $fingerprint=self::backup_restore_row_fingerprint($current);
                    if(!is_string($fingerprint)||!hash_equals((string)$entry['post_fingerprint'],$fingerprint)){$issues[]='ردیف درج‌شده در جدول '.$source_table['name'].' پس از بازیابی تغییر کرده است؛ برای ایمنی حذف نشد.';continue;}
                    $where=array();foreach($runtime['all_writable_columns'] as $column){if(!array_key_exists($column,$current)){$issues[]='ستون ردیف درج‌شده با جدول فعلی سازگار نیست؛ حذف نشد.';continue 2;}$where[$column]=$current[$column];}
                    if(!$where){$issues[]='شرط امن حذف ردیف درج‌شده در جدول '.$source_table['name'].' خالی بود.';continue;}
                    $deleted=$wpdb->delete($runtime['physical'],$where,array_fill(0,count($where),'%s'));
                    if($deleted===false){$issues[]='حذف امن ردیف درج‌شده از جدول '.$source_table['name'].' ناموفق بود.';continue;}
                    $verify=self::backup_preview_load_live_rows($runtime['quoted'],$pk,array($item['key_row']));
                    if(is_wp_error($verify)||isset($verify[$token])){$issues[]='بررسی حذف ردیف درج‌شده از جدول '.$source_table['name'].' ناموفق بود.';}
                }
            }
        }
        if($issues){return new WP_Error('wsm_restore_table_rollback_failed',substr(implode(' ',$issues),0,1200),array('status'=>500));}
        return true;
    }

    /** Restore a selected plugin option from the pre-restore snapshot without discarding live credentials. */
    private static function backup_restore_rollback_option($name,$source_value,$before_options,$was_changed) {
        if(empty($was_changed)){return true;}
        $allowed=self::backup_club_option_names();
        if(!is_string($name)||!in_array($name,$allowed,true)||!is_array($before_options)){return new WP_Error('wsm_restore_option_rollback_invalid','اطلاعات بازگردانی تنظیمات افزونه معتبر نیست.',array('status'=>500));}
        $safe_source=self::filter_backup_setting_value($source_value);
        if(is_wp_error($safe_source)||!self::backup_preview_json_equal($safe_source,$source_value)){return new WP_Error('wsm_restore_option_rollback_source_invalid','گزینهٔ ورودی برای بازگردانی امن نیست.',array('status'=>400));}
        $before_exists=array_key_exists($name,$before_options);$safe_before=$before_exists?$before_options[$name]:null;
        if($before_exists&&(!is_array($safe_before)||is_wp_error(self::filter_backup_setting_value($safe_before)))){return new WP_Error('wsm_restore_option_rollback_snapshot_invalid','مقدار گزینه در نسخهٔ پشتیبان پیش از بازیابی معتبر نیست.',array('status'=>500));}
        $missing=new stdClass();$current=get_option($name,$missing);
        if($current===$missing){return $before_exists?new WP_Error('wsm_restore_option_rollback_missing','گزینه‌ای که پیش از بازیابی وجود داشت اکنون مفقود است.',array('status'=>409)):true;}
        $safe_current=self::filter_backup_setting_value($current);
        if(is_wp_error($safe_current)){return new WP_Error('wsm_restore_option_rollback_conflict','گزینهٔ فعلی قابل‌مقایسهٔ امن نیست؛ دست‌نخورده ماند.',array('status'=>409));}
        if($before_exists){
            if(self::backup_preview_json_equal($safe_current,$safe_before)){return true;}
            if(!self::backup_preview_json_equal($safe_current,$safe_source)){return new WP_Error('wsm_restore_option_rollback_conflict','گزینه پس از بازیابی دوباره تغییر کرده است؛ برای ایمنی دست‌نخورده ماند.',array('status'=>409));}
            $restore_value=self::backup_restore_merge_sensitive($safe_before,$current);
            update_option($name,$restore_value,false);
            $verify=get_option($name,$missing);
            if($verify===$missing){return new WP_Error('wsm_restore_option_rollback_failed','ذخیرهٔ مقدار قبلی گزینه انجام نشد.',array('status'=>500));}
            $safe_verify=self::filter_backup_setting_value($verify);
            if(is_wp_error($safe_verify)||!self::backup_preview_json_equal($safe_verify,$safe_before)){return new WP_Error('wsm_restore_option_rollback_verify_failed','بررسی بازگردانی گزینه ناموفق بود.',array('status'=>500));}
            return true;
        }
        if(!self::backup_preview_json_equal($safe_current,$safe_source)){return new WP_Error('wsm_restore_option_rollback_conflict','گزینهٔ تازه‌ساخته‌شده پس از بازیابی تغییر کرده است؛ حذف نشد.',array('status'=>409));}
        if(self::backup_restore_sensitive_subtree($current)){return new WP_Error('wsm_restore_option_rollback_secret_conflict','گزینهٔ تازه‌ساخته‌شده دارای کلید حساس است؛ برای ایمنی حذف نشد.',array('status'=>409));}
        delete_option($name);
        return get_option($name,$missing)===$missing?true:new WP_Error('wsm_restore_option_rollback_delete_failed','حذف گزینهٔ تازه‌ساخته‌شده انجام نشد.',array('status'=>500));
    }

    /** Restore backup scheduling/storage settings only when the live value still matches the restore's post-state. */
    private static function backup_restore_rollback_configuration($before_configuration,$journal) {
        if(!is_array($journal)||empty($journal['changed'])){return true;}
        if(!is_array($before_configuration)||!isset($journal['target'])||!is_array($journal['target'])){return new WP_Error('wsm_restore_configuration_rollback_invalid','اطلاعات بازگردانی تنظیمات بکاپ ناقص است.',array('status'=>500));}
        $current=self::get_backup_configuration();
        if(self::backup_preview_json_equal($current,$before_configuration)){return true;}
        if(!self::backup_preview_json_equal($current,$journal['target'])){return new WP_Error('wsm_restore_configuration_rollback_conflict','تنظیمات بکاپ پس از بازیابی دوباره تغییر کرده‌اند؛ برای ایمنی دست‌نخورده ماندند.',array('status'=>409));}
        $saved=self::save_backup_configuration($before_configuration);
        if(is_wp_error($saved)){return new WP_Error('wsm_restore_configuration_rollback_failed','بازگردانی تنظیمات قبلی بکاپ ناموفق بود: '.$saved->get_error_message(),array('status'=>500));}
        $after=self::get_backup_configuration();
        if(!self::backup_preview_json_equal($after,$saved)){return new WP_Error('wsm_restore_configuration_rollback_verify_failed','بررسی بازگردانی تنظیمات قبلی بکاپ ناموفق بود.',array('status'=>500));}
        return true;
    }

    /** Run compensating rollback for every section changed by the failed restore. */
    private static function backup_restore_rollback_from_snapshot($before_selected,$source_selected,$ledger) {
        if(self::$active_backup_restore_lock_token!==''&&!self::renew_backup_restore_lock(self::$active_backup_restore_lock_token)){return new WP_Error('wsm_restore_lock_lost','قفل بازیابی از دست رفت؛ بازگردانی جبرانی برای جلوگیری از تداخل متوقف شد.',array('status'=>409));}
        $issues=array();
        $before_settings=isset($before_selected['plugin_settings'])&&is_array($before_selected['plugin_settings'])?$before_selected['plugin_settings']:array();
        $source_settings=isset($source_selected['plugin_settings'])&&is_array($source_selected['plugin_settings'])?$source_selected['plugin_settings']:array();
        if(isset($ledger['backup_configuration'])&&is_array($ledger['backup_configuration'])&&!empty($ledger['backup_configuration']['changed'])){
            if(self::$active_backup_restore_lock_token!==''&&!self::renew_backup_restore_lock(self::$active_backup_restore_lock_token)){return new WP_Error('wsm_restore_lock_lost','قفل بازیابی پیش از بازگردانی تنظیمات از دست رفت.',array('status'=>409));}
            $before_configuration=isset($before_settings['backup_configuration'])?$before_settings['backup_configuration']:null;
            $rolled=self::backup_restore_rollback_configuration($before_configuration,$ledger['backup_configuration']);
            if(is_wp_error($rolled)){$issues[]=$rolled->get_error_message();}
        }
        $before_options=isset($before_settings['options'])&&is_array($before_settings['options'])?$before_settings['options']:array();
        $source_options=isset($source_settings['options'])&&is_array($source_settings['options'])?$source_settings['options']:array();
        foreach(array_reverse($source_options,true) as $name=>$source_value){
            if(empty($ledger['options'][$name])){continue;}
            if(self::$active_backup_restore_lock_token!==''&&!self::renew_backup_restore_lock(self::$active_backup_restore_lock_token)){return new WP_Error('wsm_restore_lock_lost','قفل بازیابی پیش از بازگردانی تنظیمات افزونه از دست رفت.',array('status'=>409));}
            $rolled=self::backup_restore_rollback_option($name,$source_value,$before_options,true);
            if(is_wp_error($rolled)){$issues[]=$rolled->get_error_message();}
        }
        $before_tables=array();foreach(isset($before_selected['database_tables'])&&is_array($before_selected['database_tables'])?$before_selected['database_tables']:array() as $table){if(isset($table['name'])){$before_tables[$table['name']]=$table;}}
        $source_tables=isset($source_selected['database_tables'])&&is_array($source_selected['database_tables'])?array_reverse($source_selected['database_tables']):array();
        foreach($source_tables as $table){
            if(self::$active_backup_restore_lock_token!==''&&!self::renew_backup_restore_lock(self::$active_backup_restore_lock_token)){return new WP_Error('wsm_restore_lock_lost','قفل بازیابی پیش از بازگردانی جبرانی جدول از دست رفت.',array('status'=>409));}
            $name=isset($table['name'])?(string)$table['name']:'';$journal=isset($ledger['tables'][$name])&&is_array($ledger['tables'][$name])?$ledger['tables'][$name]:array();
            if(!$journal){continue;}
            if(!isset($before_tables[$name])){$issues[]='جدول '.$name.' در نسخهٔ پشتیبان پیش از بازیابی موجود نیست.';continue;}
            $rolled=self::backup_restore_rollback_table($before_tables[$name],$table,$journal);
            if(is_wp_error($rolled)){$issues[]=$rolled->get_error_message();}
        }
        if($issues){return new WP_Error('wsm_restore_compensation_failed',substr(implode(' ',$issues),0,1800),array('status'=>500));}
        return true;
    }

    private static function backup_restore_failure_after_compensation($error,$snapshot,$transaction_rollback_succeeded,$compensation) {
        if(!is_wp_error($error)){$error=new WP_Error('wsm_restore_failed','بازیابی انجام نشد.',array('status'=>500));}
        $error_data=$error->get_error_data();$status=is_array($error_data)&&isset($error_data['status'])?(int)$error_data['status']:500;
        $compensation_succeeded=!is_wp_error($compensation);
        $message=$error->get_error_message().' نسخهٔ پشتیبان پیش از بازیابی حفظ شده است.';
        $message.=$transaction_rollback_succeeded?' فرمان بازگشت تراکنش پایگاه‌داده اجرا شد.':' فرمان بازگشت تراکنش پایگاه‌داده ناموفق بود.';
        if($compensation_succeeded){$message.=' بازگردانی جبرانی بخش‌های تغییریافته با موفقیت انجام شد.';}
        else{$message.=' بازگردانی جبرانی کامل نشد؛ فایل نسخهٔ پشتیبان برای بازیابی دستی حفظ شده است.';}
        $id=isset($snapshot['pre_restore_backup_id'])?(string)$snapshot['pre_restore_backup_id']:'';
        $url=isset($snapshot['pre_restore_download_url'])?(string)$snapshot['pre_restore_download_url']:'';
        $audit='بازیابی ناموفق بود؛ بازگشت تراکنش پایگاه‌داده: '.($transaction_rollback_succeeded?'موفق':'ناموفق').'؛ بازگردانی جبرانی: '.($compensation_succeeded?'موفق':'ناقص').'.';
        if(!$compensation_succeeded){$audit.=' جزئیات: '.$compensation->get_error_message();}
        if($id!==''){self::append_backup_log_message($id,$audit);}
        return new WP_Error($error->get_error_code(),$message,array('status'=>$status,'pre_restore_backup_id'=>$id,'pre_restore_download_url'=>$url,'transaction_rollback_succeeded'=>(bool)$transaction_rollback_succeeded,'compensating_rollback_succeeded'=>$compensation_succeeded,'compensating_rollback_message'=>$compensation_succeeded?'':$compensation->get_error_message()));
    }

    /** Load and checksum-validate the pre-restore ZIP package before any restore writes begin. */
    private static function backup_restore_load_pre_restore_package($snapshot) {
        if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_restore_lock_lost','قفل بازیابی پیش از خواندن نسخهٔ پشتیبان از دست رفت.',array('status'=>409));}
        if(!is_array($snapshot)||empty($snapshot['pre_restore_backup_id'])){return new WP_Error('wsm_restore_snapshot_id_missing','شناسهٔ نسخهٔ پشتیبان پیش از بازیابی موجود نیست.',array('status'=>500));}
        $file=self::resolve_backup_file((string)$snapshot['pre_restore_backup_id']);
        if(is_wp_error($file)){return $file;}
        $package=self::read_backup_package($file['path'],(int)$file['size_bytes']);
        if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_restore_lock_lost','قفل بازیابی هنگام اعتبارسنجی نسخهٔ پشتیبان از دست رفت.',array('status'=>409));}
        if(is_wp_error($package)){return new WP_Error('wsm_restore_snapshot_invalid','اعتبارسنجی بستهٔ ZIP، فهرست داخلی یا جمع کنترلی نسخهٔ پشتیبان پیش از بازیابی ناموفق بود.',array('status'=>500));}
        $document=$package['document'];
        if(!isset($document['backup_metadata']['backup_type'])||$document['backup_metadata']['backup_type']!=='pre_restore'){return new WP_Error('wsm_restore_snapshot_type_invalid','نوع فایل بازیابی‌شده نسخهٔ پشتیبان پیش از بازیابی نیست.',array('status'=>400));}
        return $package;
    }

    private static function apply_backup_restore_document($document,$selection,$confirmed=false,$confirm_backup_configuration=false,$confirm_table_replacement=false,$expected_plan_fingerprint='',$archive=null) {
        if(!self::backup_valid_boolean($confirmed)||!rest_sanitize_boolean($confirmed)){return new WP_Error('wsm_restore_confirmation_required','برای بازیابی، تأیید صریح لازم است.',array('status'=>400));}
        if(!is_string($expected_plan_fingerprint)||!preg_match('/^[a-f0-9]{64}$/',$expected_plan_fingerprint)){return new WP_Error('wsm_restore_preview_stale','پیش‌نمایش تأییدشده معتبر نیست؛ دوباره پیش‌نمایش بگیرید.',array('status'=>409));}
        $selected=self::select_backup_document_sections($document,$selection);if(is_wp_error($selected)){return $selected;}
        if(!empty($selected['selection']['plugin_paths'])){return new WP_Error('wsm_restore_plugin_files_disabled','بازیابی فایل‌های کد افزونه از بکاپ داده پشتیبانی نمی‌شود.',array('status'=>400));}
        if(array_key_exists('backup_configuration',$selected['plugin_settings'])){return new WP_Error('wsm_restore_backup_configuration_disabled','تنظیمات مسیر، زمان‌بندی و اعلان بکاپ از فایل بازیابی نمی‌شوند.',array('status'=>400));}
        if(!is_array($archive)){return new WP_Error('wsm_restore_archive_missing','بستهٔ بکاپ برای بازیابی در دسترس نیست.',array('status'=>400));}
        $archive_selection=self::validate_backup_archive_selection($archive,$selected['selection']);if(is_wp_error($archive_selection)){return $archive_selection;}
        $has_configuration=false;
        foreach($selected['plugin_settings']['options'] as $name=>$value){$clean=self::filter_backup_setting_value($value);if(is_wp_error($clean)||!self::backup_preview_json_equal($clean,$value)){return new WP_Error('wsm_restore_option_sensitive','فایل بکاپ شامل کلید حساسِ غیرقابل‌بازیابی است.',array('status'=>400));}}
        $preview=self::preview_backup_restore_package($document,$selected['selection'],$archive);if(is_wp_error($preview)){return $preview;}
        if(isset($preview['summary']['deletes'])&&(int)$preview['summary']['deletes']>0&&(!self::backup_valid_boolean($confirm_table_replacement)||!rest_sanitize_boolean($confirm_table_replacement))){return new WP_Error('wsm_restore_table_replacement_confirmation_required','پیش‌نمایش نشان می‌دهد ردیف‌های مقصد که در بکاپ نیستند حذف می‌شوند؛ برای جایگزینی کامل جدول، تأیید جداگانه لازم است.',array('status'=>400));}
        $safe=self::backup_restore_preview_is_safe($preview);if(is_wp_error($safe)){return $safe;}
        if(!isset($preview['plan_fingerprint'])||!is_string($preview['plan_fingerprint'])||!hash_equals($expected_plan_fingerprint,$preview['plan_fingerprint'])){return new WP_Error('wsm_restore_preview_stale','داده‌های فعلی یا ساختار جدول پس از پیش‌نمایش تغییر کرده است؛ هیچ تغییری اعمال نشد. پیش‌نمایش را دوباره اجرا کنید.',array('status'=>409));}
        $lock=self::acquire_backup_restore_lock();if(is_wp_error($lock)){return $lock;}
        self::$active_backup_restore_lock_token=$lock;
        try{
            $snapshot=self::create_pre_restore_snapshot();if(is_wp_error($snapshot)){return $snapshot;}
            $pre_package=self::backup_restore_load_pre_restore_package($snapshot);
            if(is_wp_error($pre_package)){return self::backup_restore_failure_with_snapshot($pre_package,$snapshot);}
            $pre_document=$pre_package['document'];$pre_archive=$pre_package['archive'];
            $pre_selection=$selected['selection'];
            $pre_option_names=isset($pre_document['plugin_settings']['options'])&&is_array($pre_document['plugin_settings']['options'])?array_keys($pre_document['plugin_settings']['options']):array();
            $pre_selection['plugin_options']=array_values(array_intersect($pre_selection['plugin_options'],$pre_option_names));
            $pre_selected=self::select_backup_document_sections($pre_document,$pre_selection);
            if(is_wp_error($pre_selected)){return self::backup_restore_failure_with_snapshot($pre_selected,$snapshot);}
            global $wpdb;if(!is_object($wpdb)){return self::backup_restore_failure_with_snapshot(new WP_Error('wsm_restore_db_unavailable','پایگاه داده در دسترس نیست.',array('status'=>500)),$snapshot);}
            if(!self::renew_backup_restore_lock($lock)){return self::backup_restore_failure_with_snapshot(new WP_Error('wsm_restore_lock_lost','قفل بازیابی پیش از آغاز تراکنش از دست رفت.',array('status'=>409)),$snapshot);}
            if($wpdb->query('START TRANSACTION')===false){return self::backup_restore_failure_with_snapshot(new WP_Error('wsm_restore_transaction_failed','آغاز تراکنش بازیابی ناموفق بود.',array('status'=>500)),$snapshot);}
            self::clear_backup_restore_settings_cache($selected);
            $preview=self::preview_backup_restore_package($document,$selected['selection'],$archive);
            $preflight_error=is_wp_error($preview)?$preview:null;
            if(!$preflight_error){
                $safe=self::backup_restore_preview_is_safe($preview);
                if(is_wp_error($safe)){$preflight_error=$safe;}
                elseif(!isset($preview['plan_fingerprint'])||!is_string($preview['plan_fingerprint'])||!hash_equals($expected_plan_fingerprint,$preview['plan_fingerprint'])){$preflight_error=new WP_Error('wsm_restore_preview_stale','داده‌های فعلی یا ساختار جدول پس از پیش‌نمایش تغییر کرده است؛ هیچ تغییری اعمال نشد. پیش‌نمایش را دوباره اجرا کنید.',array('status'=>409));}
            }
            if($preflight_error){$rolled=$wpdb->query('ROLLBACK');self::clear_backup_lock_option_cache('wsm_backup_restore_lock');self::clear_backup_restore_settings_cache($selected);return self::backup_restore_failure_with_snapshot($preflight_error,$snapshot,true,$rolled!==false);}
            $expected_table_deletes=array();foreach($preview['tables'] as $table_preview){if(is_array($table_preview)&&isset($table_preview['name'],$table_preview['deletes'])){$expected_table_deletes[(string)$table_preview['name']]=(int)$table_preview['deletes'];}}
            $ledger=array('tables'=>array(),'options'=>array(),'backup_configuration'=>false);$table_results=array();$totals=array('inserts'=>0,'updates'=>0,'unchanged'=>0,'deletes'=>0);$option_results=array();$option_totals=array('new'=>0,'changed'=>0,'unchanged'=>0);$failure=null;
            foreach($selected['database_tables'] as $table){
                if(!self::renew_backup_restore_lock($lock)){$failure=new WP_Error('wsm_restore_lock_lost','قفل بازیابی از دست رفت.',array('status'=>409));break;}
                $changed=array();$counts=array();$expected_deletes=array_key_exists($table['name'],$expected_table_deletes)?$expected_table_deletes[$table['name']]:-1;$applied=self::backup_restore_apply_table($table,$changed,$counts,$expected_deletes);$ledger['tables'][$table['name']]=$changed;
                if(is_wp_error($applied)){$failure=$applied;break;}
                $table_results[]=array('name'=>$table['name'],'inserts'=>$counts['inserts'],'updates'=>$counts['updates'],'unchanged'=>$counts['unchanged'],'deletes'=>$counts['deletes']);
                foreach($totals as $key=>$value){$totals[$key]+=(int)$counts[$key];}
            }
            if(!$failure){foreach($selected['plugin_settings']['options'] as $name=>$value){
                if(!self::renew_backup_restore_lock($lock)){$failure=new WP_Error('wsm_restore_lock_lost','قفل بازیابی از دست رفت.',array('status'=>409));break;}
                $changed=false;$was_new=false;$applied=self::backup_restore_apply_option($name,$value,$changed,$was_new);$ledger['options'][$name]=$changed;
                if(is_wp_error($applied)){$failure=$applied;break;}
                $status=$was_new?'new':($changed?'changed':'unchanged');$option_totals[$status]++;$option_results[]=array('name'=>$name,'status'=>$status);
            }}
            $configuration_changed=false;
            if(!$failure&&$has_configuration){
                if(!self::renew_backup_restore_lock($lock)){$failure=new WP_Error('wsm_restore_lock_lost','قفل بازیابی پیش از جایگزینی تنظیمات بکاپ از دست رفت.',array('status'=>409));}
                else{$applied=self::backup_restore_apply_configuration($selected['plugin_settings']['backup_configuration'],$configuration_changed);$ledger['backup_configuration']=array('changed'=>$configuration_changed,'target'=>self::get_backup_configuration());if(is_wp_error($applied)){$failure=$applied;}}
            }
            $file_changes=array();$file_results=array('file_count'=>0,'new'=>0,'overwrite'=>0,'unchanged'=>0,'total_bytes'=>0);
            if(!$failure&&$selected['selection']['plugin_paths']){
                if(!self::renew_backup_restore_lock($lock)){$failure=new WP_Error('wsm_restore_lock_lost','قفل بازیابی پیش از نوشتن فایل‌های افزونه از دست رفت.',array('status'=>409));}
                else{$file_results=self::backup_apply_plugin_files($archive,$selected['selection']['plugin_paths'],$file_changes);if(is_wp_error($file_results)){$failure=$file_results;}}
            }
            if(!$failure&&!self::renew_backup_restore_lock($lock)){$failure=new WP_Error('wsm_restore_lock_lost','قفل بازیابی پیش از ثبت نهایی از دست رفت.',array('status'=>409));}
            if($failure){
                $rolled=$wpdb->query('ROLLBACK');self::clear_backup_lock_option_cache('wsm_backup_restore_lock');self::clear_backup_restore_settings_cache($selected);
                $compensation=self::backup_restore_rollback_from_snapshot($pre_selected,$selected,$ledger);
                if($file_changes){$file_compensation=self::backup_rollback_plugin_files($pre_archive,$file_changes);if(is_wp_error($file_compensation)){$detail=is_wp_error($compensation)?$compensation->get_error_message().' ':'';$compensation=new WP_Error('wsm_restore_compensation_failed',$detail.'بازگردانی فایل‌های افزونه نیز کامل نشد: '.$file_compensation->get_error_message(),array('status'=>500));}}
                return self::backup_restore_failure_after_compensation($failure,$snapshot,$rolled!==false,$compensation);
            }
            if($wpdb->query('COMMIT')===false){
                $rolled=$wpdb->query('ROLLBACK');self::clear_backup_lock_option_cache('wsm_backup_restore_lock');self::clear_backup_restore_settings_cache($selected);
                $commit_error=new WP_Error('wsm_restore_commit_failed','ثبت نهایی تراکنش بازیابی ناموفق بود.',array('status'=>500));
                $compensation=self::backup_restore_rollback_from_snapshot($pre_selected,$selected,$ledger);
                if($file_changes){$file_compensation=self::backup_rollback_plugin_files($pre_archive,$file_changes);if(is_wp_error($file_compensation)){$detail=is_wp_error($compensation)?$compensation->get_error_message().' ':'';$compensation=new WP_Error('wsm_restore_compensation_failed',$detail.'بازگردانی فایل‌های افزونه نیز کامل نشد: '.$file_compensation->get_error_message(),array('status'=>500));}}
                return self::backup_restore_failure_after_compensation($commit_error,$snapshot,$rolled!==false,$compensation);
            }
            $audit=self::append_backup_log_message($snapshot['pre_restore_backup_id'],'بازیابی بخش‌های انتخاب‌شدهٔ پایگاه داده، تنظیمات و فایل‌ها پایان یافت.');
            return array('success'=>true,'pre_restore_backup_id'=>$snapshot['pre_restore_backup_id'],'pre_restore_download_url'=>$snapshot['pre_restore_download_url'],'preview_summary'=>$preview['summary'],'table_results'=>$table_results,'option_results'=>$option_results,'file_results'=>$file_results,'applied_counts'=>array('tables'=>$totals,'options'=>$option_totals,'files'=>$file_results,'backup_configuration_changed'=>$configuration_changed),'audit_warning'=>$audit?null:'ثبت نتیجهٔ بازیابی در گزارش نسخهٔ پشتیبان انجام نشد.');
        }finally{self::$active_backup_restore_lock_token='';self::clear_backup_lock_option_cache('wsm_backup_restore_lock');self::release_backup_restore_lock($lock);self::clear_backup_restore_settings_cache($selected);}
    }    const SETTINGS = 'wsm_club_settings';
    const BACKUP_LOG_SCHEMA_VERSION = 3;
    const BACKUP_CRON_HOOK = 'wsm_scheduled_backup';

    public static function table($name) {
        global $wpdb;
        $allowed = array('collections','sports','phonebook','finance','financial_ledger','financial_accounts','receivables','payables','cash_sessions','sms_log');
        if (!in_array($name, $allowed, true)) { return ''; }
        if (in_array($name, array('finance','financial_ledger'), true)) { $name = 'financial_ledger'; }
        return $wpdb->prefix . 'wsm_club_' . $name;
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table('collections') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL DEFAULT '',
            phone varchar(40) NOT NULL DEFAULT '',
            address text NULL,
            notes text NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY active (active),
            KEY name (name)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('sports') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL DEFAULT '',
            description text NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY active (active),
            KEY name (name)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('phonebook') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(160) NOT NULL DEFAULT '',
            phone varchar(40) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            group_name varchar(100) NOT NULL DEFAULT '',
            phone_key varchar(40) NOT NULL DEFAULT '',
            member_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source varchar(20) NOT NULL DEFAULT 'manual',
            member_status varchar(20) NOT NULL DEFAULT '',
            sms_consent tinyint(1) NOT NULL DEFAULT 0,
            notes text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY phone (phone),
            KEY phone_key (phone_key),
            KEY member_id (member_id),
            KEY member_status (member_status),
            KEY group_name (group_name)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('finance') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(32) NOT NULL DEFAULT '',
            member_id bigint(20) unsigned NOT NULL DEFAULT 0,
            membership_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            title varchar(190) NOT NULL DEFAULT '',
            category varchar(100) NOT NULL DEFAULT '',
            amount decimal(20,6) NOT NULL DEFAULT 0,
            transaction_date datetime NOT NULL,
            transaction_type varchar(20) NOT NULL DEFAULT 'income',
            source varchar(40) NOT NULL DEFAULT '',
            reference_id varchar(120) NOT NULL DEFAULT '',
            payment_method varchar(24) NOT NULL DEFAULT 'cash',
            account_id bigint(20) unsigned NOT NULL DEFAULT 0,
            account_delta decimal(20,6) NOT NULL DEFAULT 0,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payable_id bigint(20) unsigned NOT NULL DEFAULT 0,
            cash_session_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ledger_status varchar(16) NOT NULL DEFAULT 'confirmed',
            ip_address varchar(45) NOT NULL DEFAULT '',
            method varchar(24) NOT NULL DEFAULT 'cash',
            reference varchar(120) NOT NULL DEFAULT '',
            description text NULL,
            occurred_at datetime NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'posted',
            void_reason varchar(255) NOT NULL DEFAULT '',
            voided_by bigint(20) unsigned NOT NULL DEFAULT 0,
            voided_at datetime NULL DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY kind_date (kind,occurred_at),
            KEY member_id (member_id),
            KEY membership_id (membership_id),
            KEY payment_id (payment_id),
            KEY status (status),
            KEY account_id (account_id),
            KEY transaction_date (transaction_date),
            KEY transaction_type (transaction_type),
            KEY source (source),
            KEY reference_id (reference_id),
            KEY ledger_status (ledger_status),
            KEY parent_id (parent_id),
            KEY payable_id (payable_id),
            KEY cash_session_id (cash_session_id)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('financial_accounts') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            account_key varchar(64) NOT NULL DEFAULT '',
            account_name varchar(160) NOT NULL DEFAULT '',
            account_type varchar(16) NOT NULL DEFAULT 'virtual',
            current_balance decimal(20,6) NOT NULL DEFAULT 0,
            opening_balance decimal(20,6) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY account_key (account_key),
            KEY account_type (account_type),
            KEY is_active (is_active)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('receivables') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            member_id bigint(20) unsigned NOT NULL DEFAULT 0,
            total_debt decimal(20,6) NOT NULL DEFAULT 0,
            paid_amount decimal(20,6) NOT NULL DEFAULT 0,
            remaining decimal(20,6) NOT NULL DEFAULT 0,
            last_payment_date datetime NULL DEFAULT NULL,
            status varchar(16) NOT NULL DEFAULT 'open',
            reference_id varchar(120) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY member_id (member_id),
            KEY status (status),
            KEY reference_id (reference_id),
            KEY updated_at (updated_at)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('payables') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            supplier_name varchar(190) NOT NULL DEFAULT '',
            total_debt decimal(20,6) NOT NULL DEFAULT 0,
            paid_amount decimal(20,6) NOT NULL DEFAULT 0,
            remaining decimal(20,6) NOT NULL DEFAULT 0,
            due_date date NULL,
            status varchar(16) NOT NULL DEFAULT 'open',
            reference varchar(120) NOT NULL DEFAULT '',
            ledger_entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
            description text NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY due_date (due_date),
            KEY supplier_name (supplier_name)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('cash_sessions') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            opened_at datetime NOT NULL,
            opening_amount decimal(20,6) NOT NULL DEFAULT 0,
            opening_book_balance decimal(20,6) NOT NULL DEFAULT 0,
            expected_amount decimal(20,6) NOT NULL DEFAULT 0,
            counted_amount decimal(20,6) NULL DEFAULT NULL,
            discrepancy decimal(20,6) NOT NULL DEFAULT 0,
            difference_reason varchar(255) NOT NULL DEFAULT '',
            status varchar(16) NOT NULL DEFAULT 'open',
            opened_by bigint(20) unsigned NOT NULL DEFAULT 0,
            closed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            closed_at datetime NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY opened_at (opened_at),
            KEY closed_at (closed_at)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('sms_log') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            member_id bigint(20) unsigned NOT NULL DEFAULT 0,
            phone varchar(40) NOT NULL DEFAULT '',
            message_type varchar(32) NOT NULL DEFAULT '',
            message text NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'failed',
            detail varchar(255) NOT NULL DEFAULT '',
            provider_ref varchar(100) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY member_id (member_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;");
        self::backfill_phonebook_phone_keys();
    }

    public static function ensure_financial_core($run_pos_dedupe = true) {
        try {
            if (get_option('wsm_club_financial_core_version') === '5') {
                // The one-time merge takes a database lock; keep it off the general init path.
                return $run_pos_dedupe ? self::deduplicate_generated_pos_accounts_once() : true;
            }
            self::create_tables();
            $tables=self::financial_tables_ready();
            if (is_wp_error($tables)) { return $tables; }
            $seeded=self::seed_financial_accounts();
            if (is_wp_error($seeded) || !$seeded) { return $seeded; }
            $migrated=self::migrate_legacy_finance_table();
            if (is_wp_error($migrated) || !$migrated) { return $migrated; }
            $backfilled=self::backfill_financial_ledger();
            if (is_wp_error($backfilled) || !$backfilled) { return $backfilled; }
            if ($run_pos_dedupe) {
                $deduplicated=self::deduplicate_generated_pos_accounts_once();
                if (is_wp_error($deduplicated) || !$deduplicated) { return $deduplicated; }
            }
            update_option('wsm_club_financial_core_version', '5', false);
            return true;
        } catch (Throwable $e) {
            error_log('[WSM Club] WSM_Club_Operations::ensure_financial_core failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new WP_Error('wsm_finance_core_exception', 'راه‌اندازی دفتر مالی کامل نشد؛ عملیات مالی تا رفع خطا در دسترس نیست.', array('status' => 503));
        }
    }

    /** Upgrade finance tables on init; run the optional POS merge only in WP-Cron, not wp-admin page loads. */
    public static function ensure_financial_core_for_init() {
        $run_pos_dedupe = defined('DOING_CRON') && DOING_CRON;
        return self::ensure_financial_core($run_pos_dedupe);
    }

    /** Shared lock prevents a POS-account creator racing the one-time account merge. */
    private static function financial_pos_account_lock_name() {
        global $wpdb;
        return 'wsm_fin_pos_' . substr(md5((string) $wpdb->prefix), 0, 32);
    }

    /** Normalize generated account labels without changing their stored display text. */
    private static function financial_account_name_identity($name) {
        $name = trim((string) $name);
        $collapsed = preg_replace('/\s+/u', ' ', $name);
        if ($collapsed !== null) { $name = $collapsed; }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (preg_match('/linux\s+armv8l/i', $lower) === 1) { return 'linux armv8l'; }
        $stripped = preg_replace('/^(?:صندوق|کارتخوان|حساب(?:\s+بانکی)?)\s*(?:[·\-:|]\s*)?/u', '', $lower);
        if ($stripped !== null && trim($stripped) !== '') { $lower = trim($stripped); }
        return $lower;
    }

    /** Acquire the POS-account advisory lock on MySQL, with a test-friendly fallback for other database drivers. */
    private static function acquire_financial_pos_account_lock($timeout = 0) {
        global $wpdb;
        $lock_name = self::financial_pos_account_lock_name();
        if (defined('WSM_TEST_SKIP_GET_LOCK') && WSM_TEST_SKIP_GET_LOCK) { return '1'; }
        $wpdb->last_error = '';
        $lock_value = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, (int) $timeout));
        if ((string) $lock_value === '1') { return '1'; }
        if ((string) $lock_value === '0') { return '0'; }
        if ($wpdb->last_error !== '' || $lock_value === null) {
            $wpdb->last_error = '';
            return '1';
        }
        return '0';
    }

    /**
     * Merge same-name generated POS accounts plus legacy Linux armv8l browser accounts.
     * Ledger rows, legacy finance rows, mappings, balances, and open-session ownership are reconciled first.
     */
    private static function deduplicate_generated_pos_accounts_once() {
        global $wpdb;
        $marker = 'wsm_finance_pos_account_dedupe_v3';
        $deferred_key = 'wsm_finance_pos_account_dedupe_v3_deferred';
        if (get_option($marker) === '1' || get_transient($deferred_key)) { return true; }
        $tables_ready = self::financial_tables_ready();
        if (is_wp_error($tables_ready)) { return $tables_ready; }

        $lock_name = self::financial_pos_account_lock_name();
        $lock_value = self::acquire_financial_pos_account_lock(0);
        if ((string) $lock_value !== '1') {
            return new WP_Error('wsm_finance_pos_dedupe_busy', 'پاک‌سازی هم‌زمان حساب‌های POS انجام می‌شود؛ عملیات مالی را دوباره بارگیری کنید.', array('status' => 503));
        }

        $transaction_open = false;
        $rollback = function () use (&$transaction_open) {
            global $wpdb;
            if ($transaction_open) { $wpdb->query('ROLLBACK'); $transaction_open = false; }
            if (function_exists('wp_cache_delete')) { wp_cache_delete('wsm_finance_control_config', 'options'); }
        };

        try {
            if (get_option($marker) === '1' || get_transient($deferred_key)) { return true; }
            $wpdb->last_error = '';
            if ($wpdb->query('START TRANSACTION') === false || $wpdb->last_error !== '') {
                return new WP_Error('wsm_finance_pos_dedupe_transaction', 'آغاز تراکنش امن برای ادغام حساب‌های POS انجام نشد.', array('status' => 503));
            }
            $transaction_open = true;

            $accounts = self::table('financial_accounts');
            $ledger = self::table('financial_ledger');
            $sessions = self::table('cash_sessions');
            $legacy_finance = self::table('finance');

            // The production cash-open path locks the account row; hold the same rows to prevent a new session racing the merge.
            if (!(defined('WSM_TEST_SKIP_GET_LOCK') && WSM_TEST_SKIP_GET_LOCK)) {
                $wpdb->last_error = '';
                $locked_accounts = $wpdb->get_results("SELECT id FROM $accounts WHERE is_active=1 AND account_type IN ('cash','pos') FOR UPDATE", ARRAY_A);
                if ($wpdb->last_error !== '') {
                    $rollback();
                    return new WP_Error('wsm_finance_pos_dedupe_lock_accounts', 'قفل‌کردن حساب‌های POS پیش از ادغام امن انجام نشد؛ هیچ حسابی حذف نشد.', array('status' => 503));
                }
            }

            $wpdb->last_error = '';
            $open_session_ids = $wpdb->get_col("SELECT id FROM $sessions WHERE status='open'");
            if ($wpdb->last_error !== '') {
                $rollback();
                return new WP_Error('wsm_finance_pos_dedupe_sessions', 'بررسی نشست‌های باز صندوق انجام نشد؛ هیچ حسابی حذف نشد.', array('status' => 503));
            }
            $open_cash_account_ids = array();
            foreach ((array) $open_session_ids as $sid) {
                $wpdb->last_error = '';
                $smarker = self::finance_session_marker((int) $sid);
                if ($wpdb->last_error !== '') {
                    $rollback();
                    return new WP_Error('wsm_finance_pos_dedupe_sessions', 'خواندن حساب مرتبط با نشست باز انجام نشد؛ هیچ حسابی حذف نشد.', array('status' => 503));
                }
                $cid = absint($smarker['account_id'] ?? 0);
                if ($cid) { $open_cash_account_ids[$cid] = true; }
            }

            $wpdb->last_error = '';
            $legacy_found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($legacy_finance)));
            if ($wpdb->last_error !== '') {
                $rollback();
                return new WP_Error('wsm_finance_pos_dedupe_legacy_check', 'بررسی ارجاع‌های دفتر مالی قدیمی انجام نشد؛ هیچ حسابی حذف نشد.', array('status' => 503));
            }
            $has_legacy_finance = ($legacy_found === $legacy_finance && $legacy_finance !== $ledger);

            $wpdb->last_error = '';
            $rows = $wpdb->get_results("SELECT id,account_key,account_name,account_type FROM $accounts WHERE is_active=1 AND account_type IN ('cash','pos') ORDER BY id ASC", ARRAY_A);
            if ($wpdb->last_error !== '') {
                $rollback();
                return new WP_Error('wsm_finance_pos_dedupe_read', 'خواندن حساب‌های POS برای پاک‌سازی انجام نشد؛ هیچ حسابی حذف نشد.', array('status' => 503));
            }

            $groups = array();
            foreach ((array) $rows as $row) {
                $key_match = array();
                $account_key = (string) ($row['account_key'] ?? '');
                $is_generated_key = preg_match('/^wsm_pos_([0-9]+)_(cash|terminal)$/', $account_key, $key_match) === 1;
                $type = (string) ($row['account_type'] ?? '');
                $name = (string) ($row['account_name'] ?? '');
                $is_legacy_linux_device = in_array($type, array('cash', 'pos'), true) && preg_match('/linux\s+armv8l/i', $name) === 1;
                if (!$is_generated_key && !$is_legacy_linux_device) { continue; }
                if ($is_generated_key) {
                    $expected_type = $key_match[2] === 'cash' ? 'cash' : 'pos';
                    if ($type !== $expected_type) { continue; }
                }
                $identity = self::financial_account_name_identity($name);
                if ($identity === '') { continue; }
                $group_key = $type . "\0" . $identity;
                if (!isset($groups[$group_key])) { $groups[$group_key] = array(); }
                $groups[$group_key][] = $row;
            }

            $id_map = array();
            $deferred_any_group = false;
            foreach ($groups as $group) {
                $ids = array_values(array_unique(array_filter(array_map(function ($row) { return absint($row['id'] ?? 0); }, $group))));
                if (count($ids) < 2) { continue; }
                $open_in_group = array_values(array_filter($ids, function ($id) use ($open_cash_account_ids) { return isset($open_cash_account_ids[$id]); }));
                if (count($open_in_group) > 1) {
                    $deferred_any_group = true;
                    continue;
                }
                $canonical_id = count($open_in_group) === 1 ? (int) $open_in_group[0] : min($ids);
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));

                $wpdb->last_error = '';
                $sums = $wpdb->get_row($wpdb->prepare(
                    "SELECT CAST(COALESCE(SUM(current_balance),0) AS CHAR) AS current_balance,CAST(COALESCE(SUM(opening_balance),0) AS CHAR) AS opening_balance FROM $accounts WHERE id IN ($placeholders)",
                    $ids
                ), ARRAY_A);
                if ($wpdb->last_error !== '' || !is_array($sums)) {
                    $rollback();
                    return new WP_Error('wsm_finance_pos_dedupe_balances', 'جمع‌کردن مانده‌های POS انجام نشد؛ هیچ حسابی حذف نشد.', array('status' => 503));
                }
                $wpdb->last_error = '';
                $balance_update = $wpdb->update($accounts, array(
                    'current_balance' => (string) $sums['current_balance'],
                    'opening_balance' => (string) $sums['opening_balance'],
                    'updated_at' => current_time('mysql'),
                ), array('id' => $canonical_id), array('%s', '%s', '%s'), array('%d'));
                if ($balance_update === false || $wpdb->last_error !== '') {
                    $rollback();
                    return new WP_Error('wsm_finance_pos_dedupe_balance_write', 'حفظ ماندهٔ تجمیعی POS انجام نشد؛ هیچ حسابی حذف نشد.', array('status' => 503));
                }

                $duplicate_ids = array_values(array_filter($ids, function ($id) use ($canonical_id) { return (int) $id !== (int) $canonical_id; }));
                foreach ($duplicate_ids as $duplicate_id) {
                    $wpdb->last_error = '';
                    $ledger_update = $wpdb->query($wpdb->prepare("UPDATE $ledger SET account_id=%d WHERE account_id=%d", $canonical_id, $duplicate_id));
                    if ($ledger_update === false || $wpdb->last_error !== '') {
                        $rollback();
                        return new WP_Error('wsm_finance_pos_dedupe_ledger', 'جابجایی ردیف‌های دفتر کل انجام نشد؛ هیچ حسابی حذف نشد.', array('status' => 503));
                    }
                    if ($has_legacy_finance) {
                        $wpdb->last_error = '';
                        $legacy_update = $wpdb->query($wpdb->prepare("UPDATE $legacy_finance SET account_id=%d WHERE account_id=%d", $canonical_id, $duplicate_id));
                        if ($legacy_update === false || $wpdb->last_error !== '') {
                            $rollback();
                            return new WP_Error('wsm_finance_pos_dedupe_legacy', 'جابجایی ردیف‌های دفتر مالی قدیمی انجام نشد؛ هیچ حسابی حذف نشد.', array('status' => 503));
                        }
                    }
                    $id_map[(int) $duplicate_id] = (int) $canonical_id;
                }

                $wpdb->last_error = '';
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM $accounts WHERE id IN (" . implode(',', array_fill(0, count($duplicate_ids), '%d')) . ")",
                    $duplicate_ids
                ));
                if ($deleted === false || $wpdb->last_error !== '') {
                    $rollback();
                    return new WP_Error('wsm_finance_pos_dedupe_delete', 'حذف ردیف تکراری حساب POS انجام نشد؛ تراکنش بازگردانده شد.', array('status' => 503));
                }
            }

            if ($id_map) {
                $raw_config = get_option('wsm_finance_control_config', array());
                $config = is_array($raw_config) ? $raw_config : array();
                $config_changed = false;
                if (isset($config['device_map']) && is_array($config['device_map'])) {
                    foreach ($config['device_map'] as $device_id => $map) {
                        if (!is_array($map)) { continue; }
                        foreach (array('cash_account_id', 'pos_account_id') as $field) {
                            $old_id = absint($map[$field] ?? 0);
                            if ($old_id && isset($id_map[$old_id])) {
                                $config['device_map'][$device_id][$field] = $id_map[$old_id];
                                $config_changed = true;
                            }
                        }
                    }
                }
                if (isset($config['gateway_map']) && is_array($config['gateway_map'])) {
                    foreach ($config['gateway_map'] as $gateway_id => $account_id) {
                        $old_id = absint($account_id);
                        if ($old_id && isset($id_map[$old_id])) {
                            $config['gateway_map'][$gateway_id] = $id_map[$old_id];
                            $config_changed = true;
                        }
                    }
                }
                $default_bank_id = absint($config['default_bank_account_id'] ?? 0);
                if ($default_bank_id && isset($id_map[$default_bank_id])) {
                    $config['default_bank_account_id'] = $id_map[$default_bank_id];
                    $config_changed = true;
                }
                if ($config_changed) {
                    $saved = update_option('wsm_finance_control_config', $config, false);
                    if (!$saved && get_option('wsm_finance_control_config', array()) !== $config) {
                        $rollback();
                        return new WP_Error('wsm_finance_pos_dedupe_config', 'به‌روزرسانی نگاشت حساب‌های POS انجام نشد؛ هیچ حسابی حذف نشد.', array('status' => 503));
                    }
                }
            }

            $wpdb->last_error = '';
            if ($wpdb->query('COMMIT') === false || $wpdb->last_error !== '') {
                $rollback();
                return new WP_Error('wsm_finance_pos_dedupe_commit', 'ثبت نهایی ادغام حساب‌های POS انجام نشد؛ تراکنش بازگردانده شد.', array('status' => 503));
            }
            $transaction_open = false;

            if ($deferred_any_group) {
                set_transient($deferred_key, '1', 30);
                return true;
            }
            delete_transient($deferred_key);
            update_option('wsm_finance_pos_account_dedupe_v2', '1', false);
            $saved_marker = update_option($marker, '1', false);
            if (!$saved_marker && get_option($marker) !== '1') {
                return new WP_Error('wsm_finance_pos_dedupe_marker', 'ثبت نشانگر پاک‌سازی حساب‌های POS انجام نشد؛ عملیات در درخواست بعدی دوباره بررسی می‌شود.', array('status' => 503));
            }
            return true;
        } catch (Throwable $e) {
            $rollback();
            throw $e;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            $wpdb->last_error = '';
        }
    }

    private static function financial_tables_ready() {
        global $wpdb;
        foreach(array('financial_ledger','financial_accounts','receivables','payables','cash_sessions') as $name){
            $table=self::table($name);
            $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
            if($wpdb->last_error!==''){return new WP_Error('wsm_finance_schema_check','بررسی جدول‌های مالی انجام نشد.',array('status'=>503));}
            if($found!==$table){return new WP_Error('wsm_finance_schema_missing','یک یا چند جدول اصلی مالی ساخته نشدند؛ دادهٔ مالی مسدود شد.',array('status'=>503));}
        }
        return true;
    }

    private static function financial_migration_query($sql) {
        global $wpdb;
        $result=$wpdb->query($sql);
        if($result===false||$wpdb->last_error!==''){return new WP_Error('wsm_finance_migration_failed','انتقال یا بازسازی داده‌های دفتر مالی انجام نشد.',array('status'=>503));}
        return true;
    }

    private static function migrate_legacy_finance_table() {
        global $wpdb;
        $legacy=$wpdb->prefix.'wsm_club_finance';
        $target=self::table('financial_ledger');
        if ($legacy === $target) { return true; }
        $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($legacy)));
        if ($found !== $legacy) { return true; }
        $legacy_columns=(array)$wpdb->get_col("SHOW COLUMNS FROM `".$legacy."`",0);
        $target_columns=(array)$wpdb->get_col("SHOW COLUMNS FROM `".$target."`",0);
        $columns=array_values(array_intersect($target_columns,$legacy_columns));
        $columns=array_values(array_filter($columns,function($column){return preg_match('/^[A-Za-z0-9_]+$/',(string)$column);}));
        if (!$columns || !in_array('id',$columns,true)) { return new WP_Error('wsm_finance_migration_columns','ستون‌های دفتر مالی قدیمی برای انتقال کامل شناسایی نشدند.'); }
        $select_columns=$columns;
        if (!in_array('transaction_date',$columns,true) && in_array('occurred_at',$legacy_columns,true) && in_array('transaction_date',$target_columns,true)) { $columns[]='transaction_date';$select_columns[]='occurred_at'; }
        $target_list=implode(',',array_map(function($column){return '`'.$column.'`';},$columns));
        $select_list=implode(',',array_map(function($column){return '`'.$column.'`';},$select_columns));
        $copied=$wpdb->query("INSERT IGNORE INTO `".$target."` ($target_list) SELECT $select_list FROM `".$legacy."`");
        return $copied !== false;
    }

    private static function financial_core_ready() {
        $ready=self::ensure_financial_core();
        if (is_wp_error($ready)) { return $ready; }
        if (!$ready) { return new WP_Error('wsm_finance_core_unavailable','راه‌اندازی دفتر مالی کامل نشد؛ هیچ دادهٔ مالی نمایش یا ثبت نشد.',array('status'=>503)); }
        return true;
    }

    private static function seed_financial_accounts() {
        global $wpdb;
        $table=self::table('financial_accounts'); $now=current_time('mysql');
        $accounts=array(
            array('cash_box','صندوق نقدی','cash'),
            array('bank_account_1','حساب بانکی ۱','bank'),
            array('bank_account_2','حساب بانکی ۲','bank'),
            array('pos_terminal_1','کارتخوان ۱','pos'),
            array('club_credit_liability','اعتبار کارت باشگاه','virtual'),
            array('virtual_unassigned','حساب مجازی/نامشخص','virtual')
        );
        foreach($accounts as $account){
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (account_key,account_name,account_type,current_balance,opening_balance,is_active,created_at,updated_at) VALUES (%s,%s,%s,0,0,1,%s,%s)",$account[0],$account[1],$account[2],$now,$now));
            if($wpdb->last_error!==''){return new WP_Error('wsm_finance_accounts_seed_failed','ساخت حساب‌های پایهٔ مالی انجام نشد.',array('status'=>503));}
        }
        return true;
    }

    private static function backfill_financial_ledger() {
        global $wpdb;
        $table=self::table('financial_ledger');
        $ok=self::financial_migration_query("UPDATE $table SET transaction_date=occurred_at WHERE transaction_date IS NULL OR transaction_date='0000-00-00 00:00:00'");
        if(is_wp_error($ok)){return $ok;}
        $ok=self::financial_migration_query("UPDATE $table SET transaction_type=CASE WHEN kind='cash_adjustment' AND account_delta>0 THEN 'income' WHEN kind='cash_adjustment' AND account_delta<0 THEN 'expense' WHEN kind IN ('member_payment','card_recharge','pos_sale','misc_income') THEN 'income' WHEN kind IN ('expense','cash_removal','payroll','pos_refund','supplier_payment') THEN 'expense' WHEN kind IN ('receivable','pos_receivable') THEN 'receivable' WHEN kind='supplier_payable' THEN 'payable' WHEN kind='discount' THEN 'discount' WHEN kind='card_spend' THEN 'liability' WHEN kind LIKE 'transfer_%' OR kind='balance_adjustment' THEN 'transfer' WHEN transaction_type IN ('income','expense','transfer','receivable','payable','discount','liability') THEN transaction_type ELSE 'expense' END");
        if(is_wp_error($ok)){return $ok;}
        $ok=self::financial_migration_query("UPDATE $table SET source=CASE WHEN source<>'' AND source<>kind THEN source WHEN kind='member_payment' THEN 'membership_payment' WHEN kind='card_recharge' THEN 'card_charge' WHEN kind='pos_sale' THEN 'pos_sale' WHEN kind='expense' THEN 'manual_expense' WHEN kind='cash_removal' THEN 'cash_removal' WHEN kind='supplier_payment' THEN 'supplier_payment' WHEN kind='supplier_payable' THEN 'supplier_commitment' WHEN kind='pos_refund' THEN 'pos_refund' WHEN kind='payroll' THEN 'payroll' WHEN kind='discount' THEN 'membership_discount' ELSE kind END");
        if(is_wp_error($ok)){return $ok;}
        $ok=self::financial_migration_query("UPDATE $table SET reference_id=CASE WHEN reference_id<>'' THEN reference_id WHEN reference<>'' THEN reference WHEN order_id>0 THEN CAST(order_id AS CHAR) WHEN payment_id>0 THEN CAST(payment_id AS CHAR) ELSE '' END");
        if(is_wp_error($ok)){return $ok;}
        $ok=self::financial_migration_query("UPDATE $table SET payment_method=CASE WHEN method IN ('card','manual_card','card_pos') THEN 'card_pos' WHEN method IN ('bank_transfer','online','check') THEN 'bank_transfer' WHEN method IN ('club_credit','credit_nasiye') THEN 'credit_nasiye' WHEN method='transfer' THEN 'transfer' WHEN method='other' THEN 'other' ELSE 'cash' END");
        if(is_wp_error($ok)){return $ok;}
        $ok=self::financial_migration_query("UPDATE $table SET ledger_status=CASE WHEN status='void' THEN 'cancelled' WHEN ledger_status IN ('cancelled','partial','settled') THEN ledger_status WHEN kind IN ('receivable','pos_receivable','supplier_payable') THEN 'pending' WHEN ledger_status IN ('confirmed','pending') THEN ledger_status WHEN status='posted' THEN 'confirmed' ELSE 'pending' END");
        if(is_wp_error($ok)){return $ok;}
        $accounts=self::table('financial_accounts');
        $ok=self::financial_migration_query("UPDATE $table f LEFT JOIN $accounts a ON a.account_key=CASE WHEN f.kind='card_spend' THEN 'club_credit_liability' WHEN f.method IN ('card','manual_card','card_pos') THEN 'pos_terminal_1' WHEN f.method IN ('bank_transfer','online','check') THEN 'bank_account_1' WHEN f.method='other' THEN 'virtual_unassigned' ELSE 'cash_box' END SET f.account_id=CASE WHEN f.kind IN ('discount','supplier_payable','receivable','pos_receivable') THEN 0 WHEN f.account_id>0 AND EXISTS (SELECT 1 FROM $accounts ax WHERE ax.id=f.account_id AND ax.is_active=1) THEN f.account_id ELSE COALESCE(a.id,0) END,f.account_delta=CASE WHEN f.kind IN ('discount','supplier_payable','receivable','pos_receivable') THEN 0 WHEN f.account_delta<>0 THEN f.account_delta WHEN f.kind IN ('member_payment','card_recharge','pos_sale','misc_income') THEN f.amount WHEN f.kind IN ('expense','cash_removal','payroll','pos_refund','supplier_payment') THEN -f.amount WHEN f.kind='transfer_in' THEN f.amount WHEN f.kind='transfer_out' THEN -f.amount ELSE 0 END");
        if(is_wp_error($ok)){return $ok;}
        $ok=self::financial_migration_query("UPDATE $accounts a SET current_balance=opening_balance+COALESCE((SELECT SUM(f.account_delta) FROM $table f WHERE f.account_id=a.id AND f.status='posted'),0)");
        if(is_wp_error($ok)){return $ok;}
        return true;
    }
    /** Definitions shared by the WordPress and app SMS settings screens. */
    public static function sms_event_catalog() {
        return array(
            'welcome' => array('key'=>'welcome','category'=>'عضویت','label'=>'خوش‌آمدگویی ثبت‌نام اولیه','description'=>'پس از ثبت عضو جدید؛ فقط با رضایت پیامکی ذخیره‌شده.','default_enabled'=>0,'native'=>true),
            'membership_activation' => array('key'=>'membership_activation','category'=>'عضویت','label'=>'فعال‌شدن اشتراک و نام سطح','description'=>'پس از فعال‌شدن عضویت؛ اگر تاریخ شروع آینده باشد، هنگام فعال‌شدن در کار روزانه ارسال می‌شود.','default_enabled'=>0,'native'=>true),
            'membership_level_change' => array('key'=>'membership_level_change','category'=>'عضویت','label'=>'تغییر سطح عضویت','description'=>'هنگام ایجاد عضویت فعال با سطح متفاوت نسبت به عضویت فعال قبلی؛ اتصال‌های بیرونی نیز می‌توانند از هوک رویداد پیامک استفاده کنند.','default_enabled'=>0,'native'=>true),
            'membership_expiry_reminder' => array('key'=>'membership_expiry_reminder','category'=>'عضویت','label'=>'یادآوری پیش از انقضا','description'=>'چند روز پیش از پایان اعتبار عضویت فعال؛ با یادآوری ماندهٔ پرداخت مستقل است و تعداد روز در همین تنظیمات قابل تغییر است.','default_enabled'=>1,'native'=>true),
            'membership_expired' => array('key'=>'membership_expired','category'=>'عضویت','label'=>'اخطار پس از انقضا','description'=>'هنگام تغییر وضعیت عضویت فعال/زمان‌بندی‌شده به منقضی.','default_enabled'=>0,'native'=>true),
            'membership_cancelled' => array('key'=>'membership_cancelled','category'=>'عضویت','label'=>'لغو عضویت','description'=>'پس از لغو موفق عضویت.','default_enabled'=>0,'native'=>true),
            'payment_reminder' => array('key'=>'payment_reminder','category'=>'مالی','label'=>'یادآوری سررسید قسط/تمدید','description'=>'برای ماندهٔ بدهی عضویت در کار روزانه؛ آستانهٔ روز تأخیر قابل تنظیم است. مدل اقساط/تمدید خودکار جداگانه در داده‌های فعلی وجود ندارد و از هوک قابل اتصال است.','default_enabled'=>1,'native'=>true),
            'payment_failed' => array('key'=>'payment_failed','category'=>'مالی','label'=>'پرداخت ناموفق','description'=>'اتصال مستقیم به درگاه در هستهٔ فعلی وجود ندارد؛ ماژول پرداخت می‌تواند رویداد ناموفق را از هوک مستند فراخوانی کند.','default_enabled'=>0,'native'=>false),
            'payment_receipt' => array('key'=>'payment_receipt','category'=>'مالی','label'=>'رسید پس از پرداخت موفق نقدی یا آنلاین','description'=>'پس از ثبت دریافت موفق شهریه در مدل باشگاه؛ پرداخت نقدی، کارت، انتقال یا آنلاین.','default_enabled'=>0,'native'=>true),
            'class_registration' => array('key'=>'class_registration','category'=>'کلاس و حضور','label'=>'تأیید ثبت‌نام کلاس/رویداد','description'=>'مدل مستقل کلاس/رویداد در هستهٔ فعلی وجود ندارد؛ سامانهٔ کلاس می‌تواند از هوک مستند استفاده کند.','default_enabled'=>0,'native'=>false),
            'class_reminder' => array('key'=>'class_reminder','category'=>'کلاس و حضور','label'=>'یادآوری یک‌روزمانده','description'=>'سامانهٔ زمان‌بندی کلاس می‌تواند یک روز پیش از جلسه از هوک مستند رویداد پیامک استفاده کند.','default_enabled'=>0,'native'=>false),
            'last_session' => array('key'=>'last_session','category'=>'کلاس و حضور','label'=>'هشدار آخرین جلسه','description'=>'در اشتراک‌های دارای سهمیهٔ جلسه، با باقی‌ماندن یک جلسه ارسال می‌شود؛ سامانهٔ کلاس نیز می‌تواند همین رویداد را فراخوانی کند.','default_enabled'=>0,'native'=>true),
            'attendance_update' => array('key'=>'attendance_update','category'=>'کلاس و حضور','label'=>'پیام پس از ثبت حضور/غیبت','description'=>'برای ورود/خروج ثبت‌شده ارسال می‌شود؛ ثبت غیبت که در هسته مدل مستقلی ندارد از هوک رویداد پیامک قابل اتصال است.','default_enabled'=>0,'native'=>true),
            'birthday' => array('key'=>'birthday','category'=>'بازاریابی','label'=>'تبریک تولد با تقویم شمسی','description'=>'تاریخ تولد ذخیره‌شدهٔ میلادی با سالگرد شمسی امروز مقایسه می‌شود.','default_enabled'=>0,'native'=>true),
            'referral' => array('key'=>'referral','category'=>'بازاریابی','label'=>'تشکر معرفی دوست/پاداش','description'=>'مدل معرفی/پاداش در هستهٔ فعلی وجود ندارد؛ ماژول معرفی از هوک مستند استفاده کند.','default_enabled'=>0,'native'=>false),
            'survey' => array('key'=>'survey','category'=>'بازاریابی','label'=>'نظرسنجی پس از دوره یا رویداد','description'=>'پس از پایان دوره/رویداد، سامانهٔ مربوط می‌تواند پیوند نظرسنجی را از هوک مستند ارسال کند.','default_enabled'=>0,'native'=>false),
            'announcement' => array('key'=>'announcement','category'=>'بازاریابی','label'=>'اطلاعیهٔ گروهی','description'=>'در بخش «پیامک باشگاه» هنگام انتخاب گزینهٔ اطلاعیهٔ گروهی؛ به اعضای دارای رضایت ارسال می‌شود.','default_enabled'=>0,'native'=>true),
            'two_factor_otp' => array('key'=>'two_factor_otp','category'=>'امنیت','label'=>'OTP ورود دومرحله‌ای','description'=>'قالب و ارسال از هوک مستند قابل استفاده است؛ TOTP فعلی افزونه عمداً به پیامک تبدیل/تضعیف نمی‌شود.','default_enabled'=>0,'native'=>false),
            'suspicious_login' => array('key'=>'suspicious_login','category'=>'امنیت','label'=>'هشدار مدیر برای تلاش‌های ورود ناموفق مکرر','description'=>'پس از رسیدن تلاش‌های ناموفق احراز هویت یا TOTP به آستانه، به شمارهٔ مدیر ملی‌پیامک ارسال می‌شود.','default_enabled'=>1,'native'=>true),
        );
    }

    private static function sms_event_defaults() {
        $defaults = array();
        foreach (self::sms_event_catalog() as $key => $event) { $defaults[$key] = (int) !empty($event['default_enabled']); }
        return $defaults;
    }

    private static function sms_template_defaults() {
        $templates = array(
            'welcome' => '{name} عزیز، به باشگاه خوش آمدید. کد عضویت شما {member_code} است.',
            'membership_activation' => '{name} عزیز، اشتراک {plan_name} شما از {start_date} فعال شد و تا {end_date} اعتبار دارد.',
            'membership_level_change' => '{name} عزیز، سطح اشتراک شما از {old_plan_name} به {plan_name} تغییر کرد. پایان اعتبار: {end_date}.',
            'membership_expiry_reminder' => '{name} عزیز، {days} روز تا پایان اشتراک {plan_name} شما باقی مانده است ({end_date}). برای تمدید اقدام کنید.',
            'membership_expired' => '{name} عزیز، اشتراک {plan_name} شما در {end_date} به پایان رسید. برای تمدید با پذیرش تماس بگیرید.',
            'membership_cancelled' => '{name} عزیز، اشتراک {plan_name} شما لغو شد.',
            'payment_reminder' => '{name} عزیز، ماندهٔ شهریهٔ {member_code} برابر {balance} است. برای تسویه اقدام کنید.',
            'payment_failed' => '{name} عزیز، پرداخت {amount} برای اشتراک {plan_name} ناموفق بود. لطفاً روش پرداخت را بررسی کنید.',
            'payment_receipt' => '{name} عزیز، مبلغ {amount} بابت اشتراک {plan_name} با روش {method} دریافت شد. مانده: {balance}.',
            'class_registration' => '{name} عزیز، ثبت‌نام شما در کلاس {class_name} انجام شد. زمان: {class_date} {class_time}.',
            'class_reminder' => '{name} عزیز، یادآوری کلاس {class_name}: {class_date} ساعت {class_time}.',
            'last_session' => '{name} عزیز، از اشتراک {plan_name} شما فقط {remaining_sessions} جلسه باقی مانده است.',
            'attendance_update' => '{name} عزیز، رویداد حضور/غیبت شما در {class_name} ثبت شد: {attendance} در {occurred_at}.',
            'birthday' => '{name} عزیز، تولدتان مبارک! باشگاه برای شما روزهای خوش و تندرستی آرزو می‌کند.',
            'referral' => '{name} عزیز، از معرفی دوست شما سپاسگزاریم. پاداش شما: {referral_reward}.',
            'survey' => '{name} عزیز، از حضور در {event_name} سپاسگزاریم. لطفاً دیدگاه خود را ثبت کنید: {survey_link}',
            'announcement' => '{name} عزیز، اطلاعیهٔ باشگاه: {announcement}',
            'two_factor_otp' => 'کد ورود دومرحله‌ای شما: {otp} . این کد را در اختیار دیگران قرار ندهید.',
            'suspicious_login' => 'هشدار امنیتی: {login_count} تلاش ناموفق از {ip_address} ثبت شد؛ IP تا {lock_until} مسدود شد.',
            'manual' => 'پیام باشگاه برای {name}: ',
        );
        // Keep the previous template keys available to older app builds and integrations.
        $templates['registration'] = $templates['membership_activation'];
        $templates['expiry'] = $templates['membership_expiry_reminder'];
        $templates['debt'] = $templates['payment_reminder'];
        return $templates;
    }

    public static function defaults() {
        return array(
            'sound_on_entry' => 1,
            'capture_card_on_entry' => 1,
            'card_display_ms' => 2000,
            'attendance_start_time' => '',
            'late_grace_minutes' => 0,
            'sms_new_registration' => 0,
            'sms_birthday' => 0,
            'sms_expiry' => 1,
            'sms_debt' => 1,
            'sms_events' => self::sms_event_defaults(),
            'sms_expiry_days_before' => 3,
            'max_debt' => '0',
            'pos_quick_access_limit' => 6,
            'registration_grace_days' => 0,
            'debt_reminder_after_days' => 0,
            'sms_templates' => self::sms_template_defaults(),
            'portal_enabled' => 0,
            'portal_gateway_provider' => 'auto',
            'portal_merchant_id' => '',
            'portal_sandbox' => 0,
            'portal_training_url' => '',
            'portal_support_roles' => array('administrator'),
            'portal_terms_text' => '۱. همراه داشتن کارت عضویت و لباس ورزشی مناسب در باشگاه الزامی است.' . "\n" . '۲. شهریهٔ پرداخت‌شده پس از شروع دوره قابل استرداد نیست.' . "\n" . '۳. رعایت شئونات اخلاقی و مقررات ایمنی باشگاه الزامی است.',
            'portal_otp_template' => 'کد تأیید پورتال ورزشکار {club_name}: {otp} (این کد را در اختیار دیگران قرار ندهید)',
            'portal_login_otp_template' => 'کد ورود یک‌بارمصرف Athlete Hub باشگاه {club_name}: {otp} (معتبر تا ۵ دقیقه؛ این کد را در اختیار دیگران نگذارید.)',
        );
    }

    public static function settings() {
        $saved = get_option(self::SETTINGS, array());
        $saved = is_array($saved) ? $saved : array();
        $settings = wp_parse_args($saved, self::defaults());
        $training_url = isset($settings['portal_training_url']) && is_scalar($settings['portal_training_url']) ? trim((string)$settings['portal_training_url']) : '';
        $training_parts = $training_url !== '' && function_exists('wp_parse_url') ? wp_parse_url($training_url) : array();
        $settings['portal_training_url'] = strlen($training_url) <= 500 && filter_var($training_url, FILTER_VALIDATE_URL) && is_array($training_parts) && strtolower((string)($training_parts['scheme'] ?? '')) === 'https' && !empty($training_parts['host']) ? esc_url_raw($training_url, array('https')) : '';
        $support_roles = array('administrator');
        $support_role_allowlist = array('shop_manager','store_manager','registration','finance','buffet_seller','coach','sales','custom');
        foreach ((array)($settings['portal_support_roles'] ?? array()) as $support_role) {
            if (!is_scalar($support_role)) { continue; }
            $support_role = sanitize_key((string)$support_role);
            if (in_array($support_role, $support_role_allowlist, true)) { $support_roles[] = $support_role; }
        }
        $settings['portal_support_roles'] = array_values(array_unique($support_roles));

        $legacy_event_map = array(
            'membership_activation' => 'sms_new_registration',
            'membership_expiry_reminder' => 'sms_expiry',
            'payment_reminder' => 'sms_debt',
            'birthday' => 'sms_birthday',
        );
        $saved_events = isset($saved['sms_events']) && is_array($saved['sms_events']) ? $saved['sms_events'] : array();
        $events = self::sms_event_defaults();
        foreach (self::sms_event_catalog() as $key => $event) {
            if (array_key_exists($key, $saved_events)) {
                $events[$key] = !empty($saved_events[$key]) ? 1 : 0;
            } elseif (isset($legacy_event_map[$key]) && array_key_exists($legacy_event_map[$key], $saved)) {
                $events[$key] = !empty($saved[$legacy_event_map[$key]]) ? 1 : 0;
            }
        }
        $settings['sms_events'] = $events;

        $saved_templates = isset($saved['sms_templates']) && is_array($saved['sms_templates']) ? $saved['sms_templates'] : array();
        $template_aliases = array('registration'=>'membership_activation','expiry'=>'membership_expiry_reminder','debt'=>'payment_reminder');
        foreach ($template_aliases as $legacy => $event_key) {
            if (!array_key_exists($event_key, $saved_templates) && array_key_exists($legacy, $saved_templates)) {
                $saved_templates[$event_key] = $saved_templates[$legacy];
            }
        }
        $settings['sms_templates'] = wp_parse_args($saved_templates, self::sms_template_defaults());
        foreach ($template_aliases as $legacy => $event_key) { $settings['sms_templates'][$legacy] = $settings['sms_templates'][$event_key]; }
        $settings['sms_new_registration'] = $events['membership_activation'];
        $settings['sms_birthday'] = $events['birthday'];
        $settings['sms_expiry'] = $events['membership_expiry_reminder'];
        $settings['sms_debt'] = $events['payment_reminder'];
        return $settings;
    }

    public static function update_settings($input) {
        if (!is_array($input)) { return new WP_Error('wsm_club_settings_invalid', 'تنظیمات باشگاه نامعتبر است.', array('status' => 400)); }
        $portal_setting_keys = array('portal_enabled','portal_sandbox','portal_gateway_provider','portal_merchant_id','portal_training_url','portal_support_roles','portal_terms_text','portal_otp_template','portal_login_otp_template');
        if (array_intersect($portal_setting_keys, array_keys($input)) && (!class_exists('WSM_Permissions') || !WSM_Permissions::has_current_permission('club.settings.manage'))) {
            return new WP_Error('wsm_portal_settings_permission_denied', 'تنظیمات پورتال فقط با مجوز club.settings.manage قابل تغییر است.', array('status' => 403));
        }
        $device = class_exists('WSM_Permissions') ? WSM_Permissions::current_device() : null;
        $user = $device ? get_userdata((int) $device->user_id) : false;
        $is_wp_admin = $user && class_exists('WSM_Permissions') && WSM_Permissions::is_wordpress_admin_user($user);
        $can_full_club_settings = !class_exists('WSM_Permissions') || !$device || $is_wp_admin ||
            WSM_Permissions::has_current_permission('club.settings.manage');
        if (!$can_full_club_settings) {
            $allowed_keys = array();
            if (WSM_Permissions::has_current_permission('club.sms.templates.manage') || WSM_Permissions::has_current_permission('club.sms.manage')) {
                $allowed_keys = array_merge($allowed_keys, array(
                    'sms_events' => true,
                    'sms_templates' => true,
                    'sms_expiry_days_before' => true,
                    'debt_reminder_after_days' => true,
                    'sms_new_registration' => true,
                    'sms_birthday' => true,
                    'sms_expiry' => true,
                    'sms_debt' => true,
                ));
            }
            if (WSM_Permissions::has_current_permission('club.pos.quick_access.manage')) {
                $allowed_keys = array_merge($allowed_keys, array('pos_quick_access_limit' => true, 'wsm_pos_quick_access' => true));
            }
            $input = array_intersect_key($input, $allowed_keys);
            if (!$input) {
                return new WP_Error('wsm_device_permission_denied', 'این دستگاه فقط مجوز مدیریت قالب پیامک یا دسترسی سریع POS را دارد.', array('status' => 403));
            }
        }
        $s = self::settings();
        foreach (array('sound_on_entry','capture_card_on_entry','sms_new_registration','sms_birthday','sms_expiry','sms_debt') as $key) {
            if (array_key_exists($key, $input)) { $s[$key] = rest_sanitize_boolean($input[$key]) ? 1 : 0; }
        }
        $legacy_event_map = array('sms_new_registration'=>'membership_activation','sms_birthday'=>'birthday','sms_expiry'=>'membership_expiry_reminder','sms_debt'=>'payment_reminder');
        foreach ($legacy_event_map as $legacy_key => $event_key) {
            if (array_key_exists($legacy_key, $input)) { $s['sms_events'][$event_key] = $s[$legacy_key]; }
        }
        if (array_key_exists('sms_events', $input)) {
            if (!is_array($input['sms_events'])) { return new WP_Error('wsm_club_sms_events_invalid', 'تنظیم رویدادهای پیامکی نامعتبر است.', array('status' => 400)); }
            foreach (self::sms_event_catalog() as $event_key => $event) {
                if (array_key_exists($event_key, $input['sms_events'])) {
                    if (!is_scalar($input['sms_events'][$event_key])) { return new WP_Error('wsm_club_sms_event_invalid', 'وضعیت یکی از رویدادهای پیامکی نامعتبر است.', array('status' => 400)); }
                    $s['sms_events'][$event_key] = rest_sanitize_boolean($input['sms_events'][$event_key]) ? 1 : 0;
                }
            }
        }
        foreach ($legacy_event_map as $legacy_key => $event_key) { $s[$legacy_key] = $s['sms_events'][$event_key]; }
        if (array_key_exists('sms_expiry_days_before', $input)) {
            if (!is_numeric($input['sms_expiry_days_before'])) { return new WP_Error('wsm_club_setting_invalid', 'تعداد روز مانده تا یادآوری انقضا باید عددی باشد.', array('status' => 400)); }
            $s['sms_expiry_days_before'] = max(1, min(365, (int) $input['sms_expiry_days_before']));
        }
        if (array_key_exists('card_display_ms', $input)) {
            if (!is_numeric($input['card_display_ms'])) { return new WP_Error('wsm_club_setting_invalid', 'مدت نمایش کارت باید عددی باشد.', array('status' => 400)); }
            $s['card_display_ms'] = max(250, min(30000, (int) $input['card_display_ms']));
        }
        if (array_key_exists('attendance_start_time', $input)) {
            if (!is_scalar($input['attendance_start_time'])) { return new WP_Error('wsm_club_late_time_invalid', 'ساعت شروع باید خالی یا به‌صورت HH:MM معتبر باشد.', array('status' => 400)); }
            $start_time = trim((string) $input['attendance_start_time']);
            if ($start_time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start_time)) {
                return new WP_Error('wsm_club_late_time_invalid', 'ساعت شروع باید خالی یا به‌صورت HH:MM معتبر باشد.', array('status' => 400));
            }
            $s['attendance_start_time'] = $start_time;
        }
        if (array_key_exists('late_grace_minutes', $input)) {
            if (!is_numeric($input['late_grace_minutes'])) { return new WP_Error('wsm_club_late_grace_invalid', 'مهلت دیرکرد باید عددی باشد.', array('status' => 400)); }
            $s['late_grace_minutes'] = max(0, min(240, (int) $input['late_grace_minutes']));
        }
        foreach (array('registration_grace_days','debt_reminder_after_days') as $key) {
            if (array_key_exists($key, $input)) {
                if (!is_numeric($input[$key])) { return new WP_Error('wsm_club_setting_invalid', 'تعداد روز نامعتبر است.', array('status' => 400)); }
                $s[$key] = max(0, min(365, (int) $input[$key]));
            }
        }
        if (array_key_exists('max_debt', $input)) {
            $amount = self::amount($input['max_debt'], 'حد مجاز بدهی', true);
            if (is_wp_error($amount)) { return $amount; }
            $s['max_debt'] = (string) $amount;
        }
        if (array_key_exists('pos_quick_access_limit', $input)) {
            if (!is_scalar($input['pos_quick_access_limit']) || !preg_match('/^\d+$/', (string) $input['pos_quick_access_limit']) || (int) $input['pos_quick_access_limit'] < 4 || (int) $input['pos_quick_access_limit'] > 8) { return new WP_Error('wsm_pos_quick_access_limit_invalid', 'تعداد کالاهای دسترسی سریع باید بین ۴ تا ۸ باشد.', array('status' => 400)); }
            $s['pos_quick_access_limit'] = (int) $input['pos_quick_access_limit'];
        }
        $quick_access_ids = null;
        if (array_key_exists('wsm_pos_quick_access', $input)) {
            $quick_access_ids = self::normalize_pos_quick_access_ids($input['wsm_pos_quick_access'], (int) $s['pos_quick_access_limit']);
            if (is_wp_error($quick_access_ids)) { return $quick_access_ids; }
        } elseif (array_key_exists('pos_quick_access_limit', $input)) {
            $current_quick_access = self::pos_quick_access_state();
            $quick_access_ids = array_slice($current_quick_access['product_ids'], 0, (int) $s['pos_quick_access_limit']);
        }
        if (isset($input['sms_templates'])) {
            if (!is_array($input['sms_templates'])) { return new WP_Error('wsm_club_sms_templates_invalid', 'قالب پیامک نامعتبر است.', array('status' => 400)); }
            $template_input = $input['sms_templates'];
            $template_aliases = array('registration'=>'membership_activation','expiry'=>'membership_expiry_reminder','debt'=>'payment_reminder');
            foreach ($template_aliases as $legacy => $event_key) {
                if (array_key_exists($legacy, $template_input) && !array_key_exists($event_key, $template_input)) { $template_input[$event_key] = $template_input[$legacy]; }
            }
            $template_keys = array_merge(array_keys(self::sms_event_catalog()), array('registration','expiry','debt','manual'));
            foreach ($template_keys as $key) {
                if (array_key_exists($key, $template_input) && is_scalar($template_input[$key])) {
                    $s['sms_templates'][$key] = self::text($template_input[$key], 500);
                }
            }
            foreach ($template_aliases as $legacy => $event_key) { $s['sms_templates'][$legacy] = $s['sms_templates'][$event_key]; }
        }
        if (array_key_exists('portal_enabled', $input)) { $s['portal_enabled'] = rest_sanitize_boolean($input['portal_enabled']) ? 1 : 0; }
        if (array_key_exists('portal_sandbox', $input)) { $s['portal_sandbox'] = rest_sanitize_boolean($input['portal_sandbox']) ? 1 : 0; }
        if (array_key_exists('portal_gateway_provider', $input)) {
            if (!is_scalar($input['portal_gateway_provider'])) { return new WP_Error('wsm_portal_provider_invalid', 'درگاه پورتال نامعتبر است.', array('status'=>400)); }
            $provider = sanitize_key((string)$input['portal_gateway_provider']);
            if (!in_array($provider, array('auto', 'zarinpal'), true)) { return new WP_Error('wsm_portal_provider_invalid', 'حالت خودکار درگاه پرداخت معتبر نیست.', array('status'=>400)); }
            // WooCommerce online gateways take priority; Zarinpal is the compatibility fallback.
            $s['portal_gateway_provider'] = 'auto';
        }
        if (array_key_exists('portal_merchant_id', $input)) {
            if (!is_scalar($input['portal_merchant_id'])) { return new WP_Error('wsm_portal_merchant_invalid', 'مرچنت‌کد درگاه پرداخت نامعتبر است.', array('status'=>400)); }
            $merchant = trim(sanitize_text_field((string)$input['portal_merchant_id']));
            if ($merchant !== '' && !preg_match('/^[A-Za-z0-9-]{36}$/', $merchant)) { return new WP_Error('wsm_portal_merchant_invalid', 'مرچنت‌کد زرین‌پال باید یک شناسهٔ ۳۶ نویسه‌ای معتبر باشد.', array('status'=>400)); }
            $s['portal_merchant_id'] = $merchant;
        }
        if (array_key_exists('portal_training_url', $input)) {
            if (!is_scalar($input['portal_training_url'])) { return new WP_Error('wsm_training_url_invalid', 'لینک برنامهٔ تمرینی معتبر نیست.', array('status'=>400)); }
            $training_url = trim((string)$input['portal_training_url']);
            $training_parts = $training_url !== '' && function_exists('wp_parse_url') ? wp_parse_url($training_url) : array();
            if ($training_url !== '' && (strlen($training_url) > 500 || !filter_var($training_url, FILTER_VALIDATE_URL) || !is_array($training_parts) || strtolower((string)($training_parts['scheme'] ?? '')) !== 'https' || empty($training_parts['host']))) {
                return new WP_Error('wsm_training_url_invalid', 'لینک برنامهٔ تمرینی باید نشانی HTTPS معتبر باشد.', array('status'=>400));
            }
            $safe_training_url = $training_url === '' ? '' : esc_url_raw($training_url, array('https'));
            if ($training_url !== '' && $safe_training_url === '') { return new WP_Error('wsm_training_url_invalid', 'لینک برنامهٔ تمرینی معتبر نیست.', array('status'=>400)); }
            $s['portal_training_url'] = $safe_training_url;
        }
        if (array_key_exists('portal_support_roles', $input)) {
            if (!self::portal_support_can_manage()) { return new WP_Error('wsm_support_roles_forbidden', 'فقط مدیر کل مجاز به تنظیم نقش‌های پشتیبانی است.', array('status'=>403)); }
            if (!is_array($input['portal_support_roles'])) { return new WP_Error('wsm_support_roles_invalid', 'فهرست نقش‌های پشتیبانی معتبر نیست.', array('status'=>400)); }
            $roles = array('administrator');
            $allowed_roles = array('shop_manager','store_manager','registration','finance','buffet_seller','coach','sales','custom');
            foreach ($input['portal_support_roles'] as $role) {
                if (!is_scalar($role)) { return new WP_Error('wsm_support_roles_invalid', 'نام نقش پشتیبانی معتبر نیست.', array('status'=>400)); }
                $role = sanitize_key((string)$role);
                if (in_array($role, $allowed_roles, true)) { $roles[] = $role; }
            }
            $s['portal_support_roles'] = array_values(array_unique($roles));
        }
        if (array_key_exists('portal_terms_text', $input)) {
            if (!is_scalar($input['portal_terms_text'])) { return new WP_Error('wsm_portal_terms_invalid', 'متن قوانین و شرایط نامعتبر است.', array('status'=>400)); }
            $terms = sanitize_textarea_field((string)$input['portal_terms_text']);
            $s['portal_terms_text'] = function_exists('mb_substr') ? mb_substr($terms, 0, 5000, 'UTF-8') : substr($terms, 0, 5000);
        }
        if (array_key_exists('portal_otp_template', $input)) {
            if (!is_scalar($input['portal_otp_template'])) { return new WP_Error('wsm_portal_otp_template_invalid', 'قالب پیامک کد بازیابی نامعتبر است.', array('status'=>400)); }
            $tpl = trim(sanitize_textarea_field((string)$input['portal_otp_template']));
            $tpl = function_exists('mb_substr') ? mb_substr($tpl, 0, 500, 'UTF-8') : substr($tpl, 0, 500);
            if ($tpl === '') { $tpl = self::defaults()['portal_otp_template']; }
            if (strpos($tpl, '{otp}') === false) { return new WP_Error('wsm_portal_otp_template_missing_code', 'قالب پیامک باید متغیر {otp} را داشته باشد.', array('status'=>400)); }
            $s['portal_otp_template'] = $tpl;
        }
        if (array_key_exists('portal_login_otp_template', $input)) {
            if (!is_scalar($input['portal_login_otp_template'])) { return new WP_Error('wsm_portal_login_otp_template_invalid', 'قالب پیامک ورود یک‌بارمصرف معتبر نیست.', array('status'=>400)); }
            $tpl = trim(sanitize_textarea_field((string)$input['portal_login_otp_template']));
            $tpl = function_exists('mb_substr') ? mb_substr($tpl, 0, 500, 'UTF-8') : substr($tpl, 0, 500);
            if ($tpl === '') { $tpl = self::defaults()['portal_login_otp_template']; }
            if (strpos($tpl, '{otp}') === false) { return new WP_Error('wsm_portal_login_otp_template_missing_code', 'قالب پیامک ورود باید متغیر {otp} را داشته باشد.', array('status'=>400)); }
            $s['portal_login_otp_template'] = $tpl;
        }
        update_option(self::SETTINGS, $s, false);
        if ($quick_access_ids !== null) { update_option('wsm_pos_quick_access', $quick_access_ids, false); }
        WSM_Activity_Log::log('update', 'club_settings', 0, 'تنظیمات باشگاه', '', 'ذخیره شد', 'success');
        return self::public_settings();
    }

    public static function public_settings() {
        $s = self::settings();
        unset($s['max_debt']);
        $s['max_debt'] = (string) self::settings()['max_debt'];
        $device = class_exists('WSM_Permissions') ? WSM_Permissions::current_device() : null;
        $user = $device ? get_userdata((int) $device->user_id) : false;
        $is_wp_admin = $user && class_exists('WSM_Permissions') && WSM_Permissions::is_wordpress_admin_user($user);
        $can_full_club_settings = !class_exists('WSM_Permissions') || !$device || $is_wp_admin ||
            WSM_Permissions::has_current_permission('club.settings.manage');
        $can_manage_quick_access = $can_full_club_settings || WSM_Permissions::has_current_permission('club.pos.quick_access.manage');
        if ($can_manage_quick_access) {
            $s['pos_quick_access_limit'] = self::pos_quick_access_limit();
            $quick_access = self::pos_quick_access_state();
            $s['wsm_pos_quick_access'] = $quick_access['product_ids'];
            $s['pos_quick_access_products'] = $quick_access['items'];
        }
        $s['sms_service_active'] = class_exists('WSM_SMS') && WSM_SMS::is_active();
        $s['sms_event_catalog'] = array_values(self::sms_event_catalog());
        $s['portal_url'] = home_url('/athlete-portal');
        $s['portal_available_gateways'] = self::athlete_portal_public_gateways();
        $s['portal_gateway_ready'] = self::athlete_portal_gateway_ready();
        $s['portal_gateway_mode'] = self::athlete_portal_gateway_mode();

        if (!$can_full_club_settings) {
            $allowed_keys = array();
            $can_view_sms_settings = false;
            foreach (array('club.sms.view','club.sms.manage','club.sms.send','club.sms.bulk_send','club.sms.templates.manage','club.sms.logs.view') as $permission) {
                if (WSM_Permissions::has_current_permission($permission)) { $can_view_sms_settings = true; break; }
            }
            if ($can_view_sms_settings) {
                $allowed_keys = array_merge($allowed_keys, array(
                    'sms_events' => true,
                    'sms_templates' => true,
                    'sms_expiry_days_before' => true,
                    'debt_reminder_after_days' => true,
                    'sms_new_registration' => true,
                    'sms_birthday' => true,
                    'sms_expiry' => true,
                    'sms_debt' => true,
                    'sms_service_active' => true,
                    'sms_event_catalog' => true,
                ));
            }
            if ($can_manage_quick_access) {
                $allowed_keys = array_merge($allowed_keys, array(
                    'pos_quick_access_limit' => true,
                    'wsm_pos_quick_access' => true,
                    'pos_quick_access_products' => true,
                ));
            }
            $s = array_intersect_key($s, $allowed_keys);
        }
        $can_manage_portal_settings = class_exists('WSM_Permissions') && WSM_Permissions::has_current_permission('club.settings.manage');
        $s['portal_support_can_manage'] = self::portal_support_can_manage();
        if (!$can_manage_portal_settings) {
            foreach (array('portal_enabled','portal_gateway_provider','portal_merchant_id','portal_sandbox','portal_training_url','portal_support_roles','portal_terms_text','portal_otp_template','portal_login_otp_template') as $portal_key) { unset($s[$portal_key]); }
        } elseif (empty($s['portal_support_can_manage'])) {
            unset($s['portal_support_roles']);
        }
        return $s;
    }

    private static function pos_quick_access_limit() {
        $settings = self::settings();
        $limit = isset($settings['pos_quick_access_limit']) ? (int) $settings['pos_quick_access_limit'] : 6;
        return max(4, min(8, $limit));
    }

    private static function normalize_pos_quick_access_ids($values, $limit = null) {
        if (!is_array($values)) { return new WP_Error('wsm_pos_quick_access_invalid', 'فهرست کالاهای دسترسی سریع نامعتبر است.', array('status' => 400)); }
        $limit = $limit === null ? self::pos_quick_access_limit() : (int) $limit;
        if ($limit < 4 || $limit > 8) { return new WP_Error('wsm_pos_quick_access_limit_invalid', 'تعداد کالاهای دسترسی سریع باید بین ۴ تا ۸ باشد.', array('status' => 400)); }
        $ids = array();
        foreach ($values as $value) {
            if (!is_scalar($value) || !preg_match('/^\d+$/', (string) $value) || (int) $value < 1) { return new WP_Error('wsm_pos_quick_access_product_invalid', 'شناسهٔ یکی از کالاهای دسترسی سریع نامعتبر است.', array('status' => 400)); }
            $id = (int) $value;
            if (in_array($id, $ids, true)) { continue; }
            $product = function_exists('wc_get_product') ? wc_get_product($id) : false;
            if (!$product || $product->get_status() !== 'publish' || $product->get_type() === 'variable' || (class_exists('WSM_Products') && WSM_Products::sales_channel($product) === 'online')) { return new WP_Error('wsm_pos_quick_access_product_unavailable', 'فقط کالای سادهٔ منتشرشده و قابل فروش حضوری را می‌توان به دسترسی سریع افزود.', array('status' => 400)); }
            $ids[] = $id;
            if (count($ids) > $limit) { return new WP_Error('wsm_pos_quick_access_limit_reached', 'تعداد کالاهای دسترسی سریع از حد تنظیم‌شده بیشتر است.', array('status' => 400)); }
        }
        return $ids;
    }

    private static function pos_quick_access_state() {
        $stored = get_option('wsm_pos_quick_access', array());
        $stored = is_array($stored) ? $stored : array();
        $limit = self::pos_quick_access_limit();
        $ids = array(); $items = array();
        foreach ($stored as $value) {
            $id = is_scalar($value) ? absint($value) : 0;
            if (!$id || in_array($id, $ids, true)) { continue; }
            $product = function_exists('wc_get_product') ? wc_get_product($id) : false;
            if (!$product || $product->get_status() !== 'publish' || $product->get_type() === 'variable' || (class_exists('WSM_Products') && WSM_Products::sales_channel($product) === 'online')) { continue; }
            $ids[] = $id;
            $items[] = class_exists('WSM_Products') ? WSM_Products::format($product) : array('id' => $id, 'name' => $product->get_name(), 'price' => (string) $product->get_price());
            if (count($ids) >= $limit) { break; }
        }
        if ($ids !== array_values($stored)) { update_option('wsm_pos_quick_access', $ids, false); }
        return array('product_ids' => $ids, 'limit' => $limit, 'items' => $items);
    }

    private static function save_pos_quick_access($values, $limit = null) {
        if ($limit !== null) {
            if (!is_scalar($limit) || !preg_match('/^\d+$/', (string) $limit) || (int) $limit < 4 || (int) $limit > 8) { return new WP_Error('wsm_pos_quick_access_limit_invalid', 'تعداد کالاهای دسترسی سریع باید بین ۴ تا ۸ باشد.', array('status' => 400)); }
            $limit = (int) $limit;
        } else { $limit = self::pos_quick_access_limit(); }
        $ids = self::normalize_pos_quick_access_ids($values, $limit);
        if (is_wp_error($ids)) { return $ids; }
        update_option('wsm_pos_quick_access', $ids, false);
        if (func_num_args() > 1 && func_get_arg(1) !== null) { $settings = self::settings(); $settings['pos_quick_access_limit'] = $limit; update_option(self::SETTINGS, $settings, false); }
        return self::pos_quick_access_state();
    }

    private static function normalize_pos_discount_items($rows) {
        if (!is_array($rows) || !$rows || count($rows) > 100) { return new WP_Error('wsm_pos_discount_items_invalid', 'فهرست کالاهای تأیید تخفیف معتبر نیست.', array('status' => 400)); }
        $items = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { return new WP_Error('wsm_pos_discount_items_invalid', 'ردیف کالای تأیید تخفیف معتبر نیست.', array('status' => 400)); }
            $product_id = isset($row['product_id']) && is_scalar($row['product_id']) ? absint($row['product_id']) : 0;
            $sku = isset($row['sku']) && is_scalar($row['sku']) ? trim((string) $row['sku']) : '';
            if (!$product_id && $sku !== '' && function_exists('wc_get_product_id_by_sku')) { $product_id = (int) wc_get_product_id_by_sku($sku); }
            $qty_raw = isset($row['qty']) && is_scalar($row['qty']) ? trim(WSM_Persian_Normalizer::normalize((string) $row['qty'])) : '1';
            if (!$product_id || !function_exists('wc_get_product') || !wc_get_product($product_id) || !preg_match('/^\d+$/D', $qty_raw) || (int) $qty_raw < 1 || (int) $qty_raw > 10000) {
                return new WP_Error('wsm_pos_discount_items_invalid', 'شناسه، تعداد یا کالای تأیید تخفیف معتبر نیست.', array('status' => 400));
            }
            $items[] = array('product_id' => $product_id, 'qty' => (int) $qty_raw);
        }
        usort($items, function($a, $b) { return ((int) $a['product_id'] <=> (int) $b['product_id']) ?: ((int) $a['qty'] <=> (int) $b['qty']); });
        return $items;
    }

    private static function pos_discount_claim_hash($items, $sale_uuid, $discount_amount, $method) {
        $normalized = array();
        foreach ((array) $items as $row) { $normalized[] = array('product_id' => absint($row['product_id'] ?? 0), 'qty' => absint($row['qty'] ?? 0)); }
        usort($normalized, function($a, $b) { return ((int) $a['product_id'] <=> (int) $b['product_id']) ?: ((int) $a['qty'] <=> (int) $b['qty']); });
        $claim = array('device_id' => class_exists('WSM_Permissions') ? WSM_Permissions::current_device_id() : 0, 'user_id' => (int) get_current_user_id(), 'sale_uuid' => strtolower((string) $sale_uuid), 'discount_amount' => (string) $discount_amount, 'method' => sanitize_key((string) $method), 'items' => $normalized);
        return hash('sha256', (string) wp_json_encode($claim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function authorize_pos_discount($data) {
        $items = self::normalize_pos_discount_items($data['items'] ?? array());
        if (is_wp_error($items)) { return $items; }
        $sale_uuid = isset($data['sale_uuid']) && is_scalar($data['sale_uuid']) ? strtolower(trim((string) $data['sale_uuid'])) : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $sale_uuid)) { return new WP_Error('wsm_pos_discount_sale_invalid', 'شناسهٔ تکرارناپذیر فروش برای تأیید تخفیف معتبر نیست.', array('status' => 400)); }
        $method = isset($data['method']) && is_scalar($data['method']) ? sanitize_key((string) $data['method']) : 'cash';
        if (!in_array($method, array('cash', 'manual_card', 'bank_transfer', 'club_credit', 'member_debt'), true)) { return new WP_Error('wsm_pos_discount_method_invalid', 'روش پرداخت برای تأیید تخفیف معتبر نیست.', array('status' => 400)); }
        $discount = self::amount($data['discount_amount'] ?? null, 'مبلغ تخفیف', false);
        if (is_wp_error($discount)) { return $discount; }
        $approved = self::verify_finance_manager($data, 'pos_discount');
        if (is_wp_error($approved)) { return $approved; }
        if (class_exists('WSM_Activity_Log')) { WSM_Activity_Log::log('authorize', 'pos_discount', 0, 'تأیید تخفیف POS · ' . $sale_uuid, '', array('discount_amount' => $discount, 'method' => $method, 'device_id' => class_exists('WSM_Permissions') ? WSM_Permissions::current_device_id() : 0, 'sale_uuid' => $sale_uuid, 'items_hash' => self::pos_discount_claim_hash($items, $sale_uuid, $discount, $method), 'approved_by_user_id' => (int) $approved['user_id'], 'approved_at' => (string) $approved['approved_at']), 'success', null, (int) $approved['user_id']); }
        $payload = array('claim' => self::pos_discount_claim_hash($items, $sale_uuid, $discount, $method), 'device_id' => class_exists('WSM_Permissions') ? WSM_Permissions::current_device_id() : 0, 'user_id' => (int) get_current_user_id(), 'sale_uuid' => $sale_uuid, 'approved_by_user_id' => (int) $approved['user_id'], 'approved_at' => (string) $approved['approved_at'], 'expires_at' => time() + 7 * DAY_IN_SECONDS);
        $encoded = rtrim(strtr(base64_encode((string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, wp_salt('auth'));
        return array('approval_token' => $encoded . '.' . $signature, 'expires_at' => $payload['expires_at'], 'sale_uuid' => $sale_uuid);
    }

    private static function verify_pos_discount_token($token, $items, $sale_uuid, $discount_amount, $method) {
        if (!is_string($token) || strpos($token, '.') === false) { return new WP_Error('wsm_pos_discount_approval_required', 'برای تخفیف، تأیید مدیر WordPress لازم است و بدون اتصال آنلاین قابل ثبت نیست.', array('status' => 403)); }
        list($encoded, $signature) = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $encoded, wp_salt('auth'));
        if ($encoded === '' || !hash_equals($expected, (string) $signature)) { return new WP_Error('wsm_pos_discount_approval_invalid', 'مجوز تخفیف معتبر نیست.', array('status' => 403)); }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload) || empty($payload['expires_at']) || (int) $payload['expires_at'] < time()) { return new WP_Error('wsm_pos_discount_approval_expired', 'مجوز تخفیف منقضی شده است؛ فروش را با اتصال اینترنت دوباره تأیید کنید.', array('status' => 403)); }
        $device_id = class_exists('WSM_Permissions') ? WSM_Permissions::current_device_id() : 0;
        if ((int) ($payload['device_id'] ?? -1) !== (int) $device_id || (int) ($payload['user_id'] ?? -1) !== (int) get_current_user_id() || !hash_equals((string) ($payload['sale_uuid'] ?? ''), strtolower((string) $sale_uuid))) {
            return new WP_Error('wsm_pos_discount_approval_scope', 'مجوز تخفیف به دستگاه، کاربر یا فروش دیگری تعلق دارد.', array('status' => 403));
        }
        $claim = self::pos_discount_claim_hash($items, $sale_uuid, $discount_amount, $method);
        if (!hash_equals((string) ($payload['claim'] ?? ''), $claim)) { return new WP_Error('wsm_pos_discount_approval_scope', 'اقلام، روش پرداخت یا مبلغ فروش پس از تأیید تغییر کرده است.', array('status' => 403)); }
        return $payload;
    }

    private static function handle_pos_quick_access_request($data) {
        $action = isset($data['quick_access_action']) && is_scalar($data['quick_access_action']) ? sanitize_key((string) $data['quick_access_action']) : '';
        if ($action === 'get') { return self::pos_quick_access_state(); }
        if ($action === 'authorize_discount') { return self::authorize_pos_discount($data); }
        if ($action === 'save') {
            if (!array_key_exists('product_ids', $data)) { return new WP_Error('wsm_pos_quick_access_invalid', 'فهرست کالاهای دسترسی سریع ارسال نشده است.', array('status' => 400)); }
            return self::save_pos_quick_access($data['product_ids'], array_key_exists('limit', $data) ? $data['limit'] : null);
        }
        return new WP_Error('wsm_pos_quick_access_action_invalid', 'درخواست دسترسی سریع نامعتبر است.', array('status' => 400));
    }

    public static function entity_exists($kind, $id) {
        global $wpdb;
        if (!in_array($kind, array('collections','sports'), true) || (int) $id < 1) { return false; }
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table($kind) . ' WHERE id=%d AND active=1', (int) $id));
    }

    private static function text($v, $max = 190) {
        $v = is_scalar($v) ? sanitize_text_field((string) $v) : '';
        return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    }

    /** Keep the display value readable while normalizing Persian/Arabic numerals. */
    private static function normalize_phone_display($value) {
        $value = WSM_Persian_Normalizer::normalize(self::text($value, 40));
        return preg_replace('/[^0-9+()\- .]/u', '', $value);
    }

    /** Normalize Iranian local and international spellings to one lookup key. */
    private static function phonebook_phone_key($value) {
        $value = WSM_Persian_Normalizer::normalize(self::text($value, 40));
        $digits = preg_replace('/\D+/u', '', $value);
        if (preg_match('/^0098([0-9]{10})$/', $digits, $match) || preg_match('/^98([0-9]{10})$/', $digits, $match)) {
            $digits = '0' . $match[1];
        }
        return (string) $digits;
    }

    /** Backfill a bounded legacy batch; remaining blank keys are matched lazily without data loss. */
    private static function backfill_phonebook_phone_keys() {
        global $wpdb;
        $table = self::table('phonebook');
        $rows = $wpdb->get_results("SELECT id, phone FROM $table WHERE phone_key='' ORDER BY id ASC LIMIT 500");
        foreach ((array) $rows as $row) {
            $key = self::phonebook_phone_key(isset($row->phone) ? $row->phone : '');
            if ($key === '') { continue; }
            $wpdb->update($table, array('phone_key' => $key), array('id' => (int) $row->id, 'phone_key' => ''));
        }
    }

    /** Find an existing contact by normalized phone; lazily repairs any legacy blank key. */
    private static function find_phonebook_contact_by_phone_key($phone_key, $exclude_id = 0) {
        global $wpdb;
        $phone_key = (string) $phone_key;
        if ($phone_key === '') { return null; }
        $table = self::table('phonebook');
        $wpdb->last_error = '';
        $sql = "SELECT * FROM $table WHERE phone_key=%s";
        $args = array($phone_key);
        if ((int) $exclude_id > 0) { $sql .= ' AND id<>%d'; $args[] = (int) $exclude_id; }
        $sql .= ' ORDER BY id ASC LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, $args));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_phonebook_lookup_failed', 'جست‌وجوی شماره در دفتر تلفن انجام نشد.', array('status' => 500));
        }
        if ($row) { return $row; }

        $wpdb->last_error = '';
        $legacy_rows = $wpdb->get_results("SELECT * FROM $table WHERE phone_key='' ORDER BY id ASC");
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_phonebook_lookup_failed', 'بررسی شماره‌های قدیمی دفتر تلفن انجام نشد.', array('status' => 500));
        }
        foreach ((array) $legacy_rows as $legacy) {
            if ((int) $legacy->id === (int) $exclude_id || self::phonebook_phone_key(isset($legacy->phone) ? $legacy->phone : '') !== $phone_key) { continue; }
            $updated = $wpdb->update($table, array('phone_key' => $phone_key), array('id' => (int) $legacy->id, 'phone_key' => ''));
            if ($updated === false) {
                return new WP_Error('wsm_phonebook_lookup_failed', 'به‌روزرسانی نمایهٔ شمارهٔ قدیمی انجام نشد.', array('status' => 500));
            }
            $legacy->phone_key = $phone_key;
            return $legacy;
        }
        return null;
    }

    /** Upsert a club member into the existing phonebook table; never removes archived contacts. */
    public static function sync_member_to_phonebook($member) {
        global $wpdb;
        if (is_array($member)) { $member = (object) $member; }
        if (!is_object($member) || empty($member->id)) { return false; }
        $member_id = absint($member->id);
        $name = self::text(trim((string) (isset($member->first_name) ? $member->first_name : '') . ' ' . (string) (isset($member->last_name) ? $member->last_name : '')), 160);
        if ($member_id < 1 || $name === '') { return false; }

        $table = self::table('phonebook');
        $wpdb->last_error = '';
        $by_member = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE member_id=%d ORDER BY id ASC LIMIT 1", $member_id));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_phonebook_lookup_failed', 'پیداکردن پیوند ورزشکار در دفتر تلفن انجام نشد.', array('status' => 500));
        }
        $phone = self::normalize_phone_display(isset($member->phone) ? $member->phone : '');
        $phone_key = self::phonebook_phone_key($phone);
        $by_phone = $phone_key !== '' ? self::find_phonebook_contact_by_phone_key($phone_key) : null;
        if (is_wp_error($by_phone)) { return $by_phone; }

        $existing = $by_member ?: null;
        if ($by_phone && (!$existing || (int) $by_phone->id !== (int) $existing->id)) {
            if ($existing) {
                // Keep the old number as a manual contact rather than deleting it on a phone change.
                $detached = $wpdb->update($table, array('member_id' => 0, 'source' => 'manual', 'member_status' => ''), array('id' => (int) $existing->id));
                if ($detached === false) {
                    return new WP_Error('wsm_phonebook_save_failed', 'حفظ شمارهٔ قبلی در دفتر تلفن انجام نشد.', array('status' => 500));
                }
            }
            $existing = $by_phone;
        }
        if (!$existing && $phone_key === '') { return true; }

        $member_status = isset($member->status) && in_array((string) $member->status, array('active', 'paused', 'archived'), true) ? (string) $member->status : 'active';
        $group_name = $existing && isset($existing->group_name) ? (string) $existing->group_name : '';
        if ($group_name === '' || in_array($group_name, array('ورزشکاران', 'ورزشکاران بایگانی‌شده'), true)) {
            $group_name = $member_status === 'archived' ? 'ورزشکاران بایگانی‌شده' : 'ورزشکاران';
        }
        $saved_phone = $phone !== '' ? $phone : ($existing && isset($existing->phone) ? (string) $existing->phone : '');
        $saved_phone_key = $saved_phone !== '' ? self::phonebook_phone_key($saved_phone) : ($existing && isset($existing->phone_key) ? (string) $existing->phone_key : '');
        $email = isset($member->email) && is_scalar($member->email) ? sanitize_email((string) $member->email) : '';
        if ($email === '' && $existing && isset($existing->email)) { $email = (string) $existing->email; }
        $values = array(
            'name' => $name,
            'phone' => $saved_phone,
            'phone_key' => $saved_phone_key,
            'email' => $email,
            'group_name' => self::text($group_name, 100),
            'member_id' => $member_id,
            'source' => 'athlete',
            'member_status' => $member_status,
            'sms_consent' => !empty($member->sms_consent) ? 1 : 0,
            'updated_at' => current_time('mysql'),
        );
        if ($existing) {
            $ok = $wpdb->update($table, $values, array('id' => (int) $existing->id));
        } else {
            $values['created_at'] = current_time('mysql');
            $ok = $wpdb->insert($table, $values);
        }
        if ($ok === false) {
            return new WP_Error('wsm_phonebook_save_failed', 'همگام‌سازی ورزشکار با دفتر تلفن انجام نشد.', array('status' => 500));
        }
        return true;
    }

    private static function decimal_units($value) {
        if (!is_scalar($value)) { return null; }
        $raw=trim((string)$value);
        $raw=strtr($raw,array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.','٬'=>''));
        $raw=str_replace(array(','," ","\xc2\xa0"),'',$raw);
        if (!preg_match('/^-?\d+(?:\.\d{1,6})?$/D',$raw)) { return null; }
        $negative=isset($raw[0])&&$raw[0]==='-'; if($negative){$raw=substr($raw,1);}
        $parts=explode('.',$raw,2); $whole=ltrim($parts[0],'0'); if($whole===''){$whole='0';}
        if(strlen($whole)>13||(strlen($whole)===13&&strcmp($whole,'1000000000000')>0)){return null;}
        $fraction=isset($parts[1])?str_pad($parts[1],6,'0'):'000000';
        $units=((int)$whole*1000000)+(int)$fraction;
        return $negative?-$units:$units;
    }

    private static function decimal_string($units) {
        $units=(int)$units; $negative=$units<0; $absolute=abs($units);
        $whole=intdiv($absolute,1000000); $fraction=str_pad((string)($absolute%1000000),6,'0',STR_PAD_LEFT);
        $fraction=rtrim($fraction,'0');
        return ($negative?'-':'').(string)$whole.($fraction!==''?'.'.$fraction:'');
    }

    private static function amount($value, $label = 'مبلغ', $allow_zero = false) {
        $units=self::decimal_units($value);
        if ($units===null||$units<0||$units>1000000000000000000||(!$allow_zero&&$units===0)) {
            return new WP_Error('wsm_club_amount_invalid', $label . ' باید مبلغی ' . ($allow_zero ? 'نامنفی و حداکثر با ۶ رقم اعشار' : 'مثبت و حداکثر با ۶ رقم اعشار') . ' باشد.', array('status' => 400));
        }
        return self::decimal_string($units);
    }

    private static function valid_range($from, $to) {
        foreach (array($from,$to) as $value) {
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { return false; }
            $p = explode('-', $value);
            if (!checkdate((int) $p[1], (int) $p[2], (int) $p[0])) { return false; }
        }
        return $from <= $to;
    }

    public static function save_entity($kind, $data, $id = 0) {
        global $wpdb;
        if (!is_array($data)) { return new WP_Error('wsm_club_entity_invalid', 'اطلاعات ثبت معتبر نیست.', array('status' => 400)); }
        $allowed = array('collections','sports','phonebook');
        if (!in_array($kind, $allowed, true)) { return new WP_Error('wsm_club_entity_invalid', 'نوع فهرست معتبر نیست.', array('status' => 400)); }
        $table = self::table($kind);
        $before = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", (int) $id)) : null;
        if ($id && !$before) { return new WP_Error('wsm_club_entity_missing', 'رکورد پیدا نشد.', array('status' => 404)); }
        $name = array_key_exists('name',$data) ? self::text($data['name'],160) : ($before ? $before->name : '');
        if ($name === '') { return new WP_Error('wsm_club_entity_name', 'نام الزامی است.', array('status' => 400)); }
        if ($kind === 'sports') {
            $values = array('name'=>$name,'description'=>array_key_exists('description',$data)?sanitize_textarea_field((string)$data['description']):($before?$before->description:''),'active'=>array_key_exists('active',$data)?(rest_sanitize_boolean($data['active'])?1:0):($before?(int)$before->active:1),'updated_at'=>current_time('mysql'));
        } elseif ($kind === 'collections') {
            $phone = array_key_exists('phone',$data) ? preg_replace('/[^0-9+()\-. ]/u','',self::text($data['phone'],40)) : ($before?$before->phone:'');
            $values = array('name'=>$name,'phone'=>$phone,'address'=>array_key_exists('address',$data)?sanitize_textarea_field((string)$data['address']):($before?$before->address:''),'notes'=>array_key_exists('notes',$data)?sanitize_textarea_field((string)$data['notes']):($before?$before->notes:''),'active'=>array_key_exists('active',$data)?(rest_sanitize_boolean($data['active'])?1:0):($before?(int)$before->active:1),'updated_at'=>current_time('mysql'));
        } else {
            $phone_value = array_key_exists('phone', $data) ? $data['phone'] : ($before ? $before->phone : '');
            $phone = self::normalize_phone_display($phone_value);
            $phone_key = self::phonebook_phone_key($phone);
            if ($phone === '' || $phone_key === '') { return new WP_Error('wsm_club_phone_required','شماره تلفن الزامی است.',array('status'=>400)); }

            $matched = null;
            if (!$id) {
                $matched = self::find_phonebook_contact_by_phone_key($phone_key);
                if (is_wp_error($matched)) { return $matched; }
                if ($matched) { $before = $matched; }
            } else {
                $duplicate = self::find_phonebook_contact_by_phone_key($phone_key, (int) $id);
                if (is_wp_error($duplicate)) { return $duplicate; }
                if ($duplicate) { return new WP_Error('wsm_club_phonebook_duplicate_phone','این شماره قبلاً در دفتر تلفن ثبت شده است؛ مخاطب موجود را ویرایش کنید.',array('status'=>409)); }
            }

            $email_value = array_key_exists('email', $data) ? $data['email'] : ($before ? $before->email : '');
            $email = sanitize_email(is_scalar($email_value) ? (string) $email_value : '');
            if ($email !== '' && !is_email($email)) { return new WP_Error('wsm_club_email_invalid','ایمیل معتبر نیست.',array('status'=>400)); }
            $group_value = array_key_exists('group_name', $data) ? $data['group_name'] : ($before ? $before->group_name : '');
            $notes_value = array_key_exists('notes', $data) ? $data['notes'] : ($before ? $before->notes : '');
            $notes_text = is_scalar($notes_value) ? (string) $notes_value : '';
            if (array_key_exists('sms_consent', $data) && !is_scalar($data['sms_consent'])) {
                return new WP_Error('wsm_phonebook_sms_consent_invalid', 'وضعیت رضایت پیامکی معتبر نیست.', array('status' => 400));
            }
            $sms_consent = array_key_exists('sms_consent', $data)
                ? (rest_sanitize_boolean($data['sms_consent']) ? 1 : 0)
                : ($before ? (int) $before->sms_consent : 0);
            if ($matched) {
                // Adding a number that already exists updates that contact without erasing its optional data.
                if ($email === '' && isset($before->email)) { $email = (string) $before->email; }
                if (self::text($group_value, 100) === '' && isset($before->group_name)) { $group_value = $before->group_name; }
                if (trim($notes_text) === '' && isset($before->notes)) { $notes_text = (string) $before->notes; }
                if (!array_key_exists('sms_consent', $data) || !rest_sanitize_boolean($data['sms_consent'])) { $sms_consent = (int) $before->sms_consent; }
            }
            $values = array(
                'name' => $name,
                'phone' => $phone,
                'phone_key' => $phone_key,
                'email' => $email,
                'group_name' => self::text($group_value, 100),
                'sms_consent' => $sms_consent,
                'notes' => sanitize_textarea_field($notes_text),
                'updated_at' => current_time('mysql'),
            );
        }
        if ($kind === 'phonebook' && $id && $before && !empty($before->member_id) && class_exists('WSM_Club')) {
            $linked_member_id = absint($before->member_id);
            $wpdb->last_error = '';
            $linked_member = $wpdb->get_row($wpdb->prepare('SELECT id,phone,email,sms_consent FROM ' . WSM_Club::table('members') . ' WHERE id=%d LIMIT 1', $linked_member_id));
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_phonebook_member_lookup_failed', 'بررسی ورزشکار پیوندخورده در دفتر تلفن انجام نشد.', array('status' => 500));
            }
            if ($linked_member) {
                $member_changes = array();
                if ((string) $linked_member->phone !== (string) $phone) { $member_changes['phone'] = $phone; }
                if ((string) $linked_member->email !== (string) $email) { $member_changes['email'] = $email; }
                if ((int) $linked_member->sms_consent !== (int) $sms_consent) { $member_changes['sms_consent'] = (int) $sms_consent; }
                if ($member_changes) {
                    $member_saved = WSM_Club::save_member($member_changes, $linked_member_id);
                    if (is_wp_error($member_saved)) { return $member_saved; }
                }
            }
        }
        $record_id = (int) $id;
        if ($id) {
            $ok = $wpdb->update($table, $values, array('id' => (int) $id));
        } elseif (isset($matched) && $matched) {
            $record_id = (int) $matched->id;
            $ok = $wpdb->update($table, $values, array('id' => $record_id));
        } else {
            $values['created_at'] = current_time('mysql');
            $ok = $wpdb->insert($table, $values);
            $record_id = (int) $wpdb->insert_id;
        }
        if ($ok === false) { return new WP_Error('wsm_club_save_failed','ذخیرهٔ رکورد انجام نشد.',array('status'=>500)); }
        WSM_Activity_Log::log(($id || (isset($matched) && $matched))?'update':'create','club_'.$kind,$record_id,$name,'','ذخیره شد','success');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$record_id), ARRAY_A);
    }

    public static function list_entities($kind, $args = array()) {
        global $wpdb;
        if (!in_array($kind,array('collections','sports','phonebook'),true)) { return array('items'=>array()); }
        $table=self::table($kind); $search=self::text(isset($args['search'])?$args['search']:'',100); $where='1=1'; $params=array();
        if ($kind !== 'phonebook' && empty($args['all'])) { $where.=' AND active=1'; }
        if ($search!=='') {
            $like='%'.$wpdb->esc_like($search).'%';
            if ($kind==='sports') { $where.=' AND (name LIKE %s OR description LIKE %s)'; $params=array($like,$like); }
            elseif ($kind==='collections') { $where.=' AND (name LIKE %s OR phone LIKE %s OR notes LIKE %s)'; $params=array($like,$like,$like); }
            elseif ($kind==='phonebook') {
                $phone_like='%'.$wpdb->esc_like(self::normalize_phone_display($search)).'%';
                $key_like='%'.$wpdb->esc_like(self::phonebook_phone_key($search)).'%';
                $where.=' AND (name LIKE %s OR phone LIKE %s OR phone_key LIKE %s OR email LIKE %s OR group_name LIKE %s)';
                $params=array($like,$phone_like,$key_like,$like,$like);
            } else { $where.=' AND (name LIKE %s OR phone LIKE %s OR email LIKE %s OR group_name LIKE %s)'; $params=array($like,$like,$like,$like); }
        }
        $limit=max(1,min(300,(int)(isset($args['per_page'])?$args['per_page']:200)));
        $sql="SELECT * FROM $table WHERE $where ORDER BY name ASC,id DESC LIMIT %d"; $params[]=$limit;
        $rows=$wpdb->get_results($wpdb->prepare($sql,$params),ARRAY_A);
        return array('items'=>(array)$rows);
    }

    /** Validate a WordPress administrator's password for one sensitive financial action. */
    public static function verify_finance_manager($data, $action = 'finance_operation') {
        $data = is_array($data) ? $data : array();
        $username = isset($data['manager_username']) && is_scalar($data['manager_username']) ? sanitize_user(wp_unslash((string) $data['manager_username']), true) : '';
        $password = isset($data['manager_password']) && is_scalar($data['manager_password']) ? (string) wp_unslash($data['manager_password']) : '';
        if (!function_exists('is_ssl') || !is_ssl()) {
            return new WP_Error('wsm_finance_https_required', 'تأیید مدیر و ارسال گذرواژه فقط از اتصال HTTPS مجاز است.', array('status' => 400));
        }
        if ($username === '' || $password === '' || strlen($password) > 4096) {
            return new WP_Error('wsm_finance_manager_required', 'نام کاربری و گذرواژهٔ مدیر WordPress لازم است.', array('status' => 400));
        }
        $ip = class_exists('WSM_Util') && is_callable(array('WSM_Util', 'client_ip')) ? (string) WSM_Util::client_ip() : '';
        $rate_key = 'wsm_fin_appr_' . substr(hash('sha256', strtolower($username) . '|' . $ip), 0, 32);
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 6) {
            return new WP_Error('wsm_finance_manager_rate_limited', 'تعداد تلاش‌های تأیید مدیر زیاد است؛ ۱۵ دقیقه بعد دوباره تلاش کنید.', array('status' => 429));
        }
        $user = get_user_by('login', $username);
        if (!$user || !user_can($user, 'manage_options') || !wp_check_password($password, $user->user_pass, (int) $user->ID)) {
            set_transient($rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
            if (class_exists('WSM_Activity_Log')) {
                WSM_Activity_Log::log('auth', 'finance_approval', 0, 'تأیید مدیر مالی', '', array('action' => sanitize_key((string) $action), 'username' => $username, 'approved' => false), 'failed');
            }
            return new WP_Error('wsm_finance_manager_invalid', 'اعتبار مدیر WordPress معتبر نیست.', array('status' => 403));
        }
        delete_transient($rate_key);
        $result = array('user_id' => (int) $user->ID, 'username' => (string) $user->user_login, 'action' => sanitize_key((string) $action), 'approved_at' => current_time('mysql'));
        if (class_exists('WSM_Activity_Log')) {
            WSM_Activity_Log::log('auth', 'finance_approval', 0, 'تأیید مدیر مالی', '', array('action' => $result['action'], 'manager_user_id' => $result['user_id'], 'manager_login' => $result['username'], 'approved_at' => $result['approved_at']), 'success', null, $result['user_id']);
        }
        return $result;
    }

    private static function finance_control_config() {
        $raw = get_option('wsm_finance_control_config', array());
        $raw = is_array($raw) ? $raw : array();
        $device_map = array();
        foreach ((array) (isset($raw['device_map']) ? $raw['device_map'] : array()) as $device_id => $map) {
            $device_id = absint($device_id);
            if (!$device_id || !is_array($map)) { continue; }
            $cash_id = absint(isset($map['cash_account_id']) ? $map['cash_account_id'] : 0);
            $pos_id = absint(isset($map['pos_account_id']) ? $map['pos_account_id'] : 0);
            if ($cash_id && $pos_id) { $device_map[$device_id] = array('cash_account_id' => $cash_id, 'pos_account_id' => $pos_id); }
        }
        $gateway_map = array();
        foreach ((array) (isset($raw['gateway_map']) ? $raw['gateway_map'] : array()) as $gateway_id => $account_id) {
            $gateway_id = sanitize_key((string) $gateway_id);
            $account_id = absint($account_id);
            if ($gateway_id !== '' && $account_id) { $gateway_map[$gateway_id] = $account_id; }
        }
        return array('version' => 1, 'device_map' => $device_map, 'gateway_map' => $gateway_map, 'default_bank_account_id' => absint(isset($raw['default_bank_account_id']) ? $raw['default_bank_account_id'] : 0));
    }

    private static function finance_account_id_by_key($key) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('financial_accounts') . ' WHERE account_key=%s AND is_active=1 LIMIT 1', sanitize_key((string) $key)));
    }

    private static function create_financial_account_row($name, $type, $key = '', $opening_balance = '0') {
        global $wpdb;
        $name = self::text($name, 160);
        if ($name === '' || !in_array($type, array('cash', 'bank', 'pos', 'virtual'), true)) { return new WP_Error('wsm_finance_account_invalid', 'نام یا نوع حساب مالی معتبر نیست.', array('status' => 400)); }
        $opening_balance = self::amount($opening_balance, 'ماندهٔ افتتاحیهٔ حساب', true);
        if (is_wp_error($opening_balance)) { return $opening_balance; }
        $opening_balance = wc_format_decimal($opening_balance, 6);
        $key = sanitize_key((string) $key);
        if ($key === '') {
            $slug = sanitize_title($name);
            $key = 'wsm_' . $type . '_' . substr($slug !== '' ? $slug : 'account', 0, 20) . '_' . strtolower(wp_generate_password(8, false, false));
        }
        $key = substr($key, 0, 64);
        $key_match = array();
        $is_device_account = preg_match('/^wsm_pos_([0-9]+)_(cash|terminal)$/', $key, $key_match) === 1;
        if ($is_device_account) {
            $expected_type = $key_match[2] === 'cash' ? 'cash' : 'pos';
            if ($type !== $expected_type) { return new WP_Error('wsm_finance_device_account_type', 'نوع حساب POS با کلید دستگاه هم‌خوانی ندارد.', array('status' => 400)); }
        }

        $table = self::table('financial_accounts');
        $now = current_time('mysql');
        $lock_name = '';
        $is_linux_device_label = in_array($type, array('cash', 'pos'), true) && preg_match('/linux\s+armv8l/i', $name) === 1;
        if ($is_device_account || $is_linux_device_label) {
            $lock_name = self::financial_pos_account_lock_name();
            $locked = self::acquire_financial_pos_account_lock(10);
            if ((string) $locked !== '1') { return new WP_Error('wsm_finance_device_account_busy', 'ساخت حساب POS هم‌زمان است؛ عملیات دوباره تلاش شود.', array('status' => 503)); }
        }

        try {
            $wpdb->last_error = '';
            $existing_row = $wpdb->get_row($wpdb->prepare("SELECT id,account_name,account_type FROM $table WHERE account_key=%s LIMIT 1", $key), ARRAY_A);
            if ($wpdb->last_error !== '') { return new WP_Error('wsm_finance_account_lookup_failed', 'بررسی حساب مالی موجود انجام نشد.', array('status' => 500)); }
            if (is_array($existing_row) && !empty($existing_row['id'])) {
                if ($is_device_account && (string) $existing_row['account_type'] !== $type) { return new WP_Error('wsm_finance_device_account_type', 'نوع حساب موجود با کلید دستگاه هم‌خوانی ندارد.', array('status' => 409)); }
                if ($is_device_account && (string) $existing_row['account_name'] !== $name) {
                    $renamed = $wpdb->update($table, array('account_name' => $name, 'updated_at' => $now), array('id' => (int) $existing_row['id']), array('%s', '%s'), array('%d'));
                    if ($renamed === false) { return new WP_Error('wsm_finance_account_update_failed', 'به‌روزرسانی حساب POS موجود انجام نشد.', array('status' => 500)); }
                } elseif ($is_device_account) {
                    $touched = $wpdb->update($table, array('updated_at' => $now), array('id' => (int) $existing_row['id']), array('%s'), array('%d'));
                    if ($touched === false) { return new WP_Error('wsm_finance_account_update_failed', 'به‌روزرسانی زمان آخر حساب POS موجود انجام نشد.', array('status' => 500)); }
                }
                return (int) $existing_row['id'];
            }

            if ($is_device_account || $is_linux_device_label) {
                $target_identity = self::financial_account_name_identity($name);
                $wpdb->last_error = '';
                $candidate_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id,account_key,account_name FROM $table WHERE account_type=%s AND is_active=1 ORDER BY id ASC",
                    $type
                ), ARRAY_A);
                if ($wpdb->last_error !== '') { return new WP_Error('wsm_finance_account_name_lookup_failed', 'بررسی حساب POS هم‌نام انجام نشد.', array('status' => 500)); }
                $same_name = 0;
                foreach ((array) $candidate_rows as $candidate) {
                    $candidate_key = (string) ($candidate['account_key'] ?? '');
                    $candidate_name = (string) ($candidate['account_name'] ?? '');
                    $candidate_generated = preg_match('/^wsm_pos_[0-9]+_(cash|terminal)$/', $candidate_key) === 1;
                    $candidate_legacy_linux = preg_match('/linux\s+armv8l/i', $candidate_name) === 1;
                    if (!$candidate_generated && !$candidate_legacy_linux) { continue; }
                    if ($target_identity !== '' && self::financial_account_name_identity($candidate_name) === $target_identity) {
                        $same_name = (int) $candidate['id'];
                        break;
                    }
                }
                if ($same_name) {
                    $touched = $wpdb->update($table, array('updated_at' => $now), array('id' => $same_name), array('%s'), array('%d'));
                    if ($touched === false || $wpdb->last_error !== '') { return new WP_Error('wsm_finance_account_update_failed', 'به‌روزرسانی زمان آخر حساب POS هم‌نام انجام نشد.', array('status' => 500)); }
                    return $same_name;
                }
            }

            $wpdb->last_error = '';
            $upsert_sql = $wpdb->prepare(
                "INSERT INTO $table (account_key,account_name,account_type,current_balance,opening_balance,is_active,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,1,%s,%s) ON DUPLICATE KEY UPDATE account_name=VALUES(account_name),is_active=1,updated_at=VALUES(updated_at)",
                $key, $name, $type, $opening_balance, $opening_balance, $now, $now
            );
            $inserted = $wpdb->query($upsert_sql);
            if ($inserted === false || $wpdb->last_error !== '') {
                $wpdb->last_error = '';
                $inserted = $wpdb->insert($table, array('account_key' => $key, 'account_name' => $name, 'account_type' => $type, 'current_balance' => $opening_balance, 'opening_balance' => $opening_balance, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now));
                if ($inserted !== 1) { return new WP_Error('wsm_finance_account_create_failed', 'ساخت حساب مالی انجام نشد.', array('status' => 500)); }
            }
            $id = (int) $wpdb->insert_id;
            if (!$id) {
                $wpdb->last_error = '';
                $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE account_key=%s LIMIT 1", $key));
            }
            if (!$id) { return new WP_Error('wsm_finance_account_create_failed', 'ساخت حساب مالی انجام نشد.', array('status' => 500)); }
            if (class_exists('WSM_Activity_Log')) { WSM_Activity_Log::log('create', 'financial_account', $id, $name, '', array('account_key' => $key, 'account_type' => $type, 'opening_balance' => $opening_balance, 'current_balance' => $opening_balance), 'success'); }
            return $id;
        } finally {
            if ($lock_name !== '') { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name)); $wpdb->last_error = ''; }
        }
    }

    /** Idempotently allocate cash/POS mappings; repeated names reuse their mapped pair. */
    private static function ensure_device_finance_mapping($device_id) {
        $device_id = absint($device_id);
        $config = self::finance_control_config();
        if (!class_exists('WSM_Devices')) { return $config; }
        if ($device_id && isset($config['device_map'][$device_id])) { return $config; }

        $devices = WSM_Devices::query(array('status' => 'active', 'limit' => 500));
        $candidates = array();
        foreach ((array) $devices as $device) {
            if (WSM_Devices::device_can($device, 'club.pos.create')) { $candidates[(int) $device->id] = $device; }
        }
        if ($device_id && !isset($candidates[$device_id])) {
            $current = WSM_Devices::find($device_id);
            if ($current && $current->status === 'active' && WSM_Devices::device_can($current, 'club.pos.create')) { $candidates[$device_id] = $current; }
        }
        ksort($candidates, SORT_NUMERIC);

        $used_cash = array();
        $used_pos = array();
        $name_pair_map = array();
        foreach ((array) $config['device_map'] as $mapped_device_id => $mapped) {
            if (!is_array($mapped)) { continue; }
            $cash_id = absint($mapped['cash_account_id'] ?? 0);
            $pos_id = absint($mapped['pos_account_id'] ?? 0);
            if (!$cash_id || !$pos_id) { continue; }
            $used_cash[$cash_id] = true;
            $used_pos[$pos_id] = true;
            $mapped_device = $candidates[(int) $mapped_device_id] ?? null;
            if (!$mapped_device && class_exists('WSM_Devices')) {
                $lookup = WSM_Devices::find((int) $mapped_device_id);
                if ($lookup && $lookup->status === 'active' && WSM_Devices::device_can($lookup, 'club.pos.create')) { $mapped_device = $lookup; }
            }
            if ($mapped_device) {
                $identity = self::financial_account_name_identity((string) ($mapped_device->device_name ?? ''));
                if ($identity !== '' && !isset($name_pair_map[$identity])) {
                    $name_pair_map[$identity] = array('cash_account_id' => $cash_id, 'pos_account_id' => $pos_id);
                }
            }
        }

        $default_cash = self::finance_account_id_by_key('cash_box');
        $default_pos = self::finance_account_id_by_key('pos_terminal_1');
        $changed = false;
        foreach ($candidates as $id => $device) {
            if (isset($config['device_map'][$id])) { continue; }
            $device_name = self::text(isset($device->device_name) ? $device->device_name : ('POS ' . $id), 100);
            $name_identity = self::financial_account_name_identity($device_name);
            if ($name_identity !== '' && isset($name_pair_map[$name_identity])) {
                $cash_id = (int) $name_pair_map[$name_identity]['cash_account_id'];
                $pos_id = (int) $name_pair_map[$name_identity]['pos_account_id'];
            } elseif ($default_cash && $default_pos && !isset($used_cash[$default_cash]) && !isset($used_pos[$default_pos])) {
                // Preserve the existing behavior: only the first unmapped POS receives the shared seed pair.
                $cash_id = (int) $default_cash;
                $pos_id = (int) $default_pos;
            } else {
                $cash_id = self::create_financial_account_row('صندوق · ' . $device_name, 'cash', 'wsm_pos_' . $id . '_cash');
                if (is_wp_error($cash_id) || !$cash_id) { continue; }
                $pos_id = self::create_financial_account_row('کارتخوان · ' . $device_name, 'pos', 'wsm_pos_' . $id . '_terminal');
                if (is_wp_error($pos_id) || !$pos_id) { continue; }
            }
            $config['device_map'][$id] = array('cash_account_id' => (int) $cash_id, 'pos_account_id' => (int) $pos_id);
            $used_cash[(int) $cash_id] = true;
            $used_pos[(int) $pos_id] = true;
            $changed = true;
            if ($name_identity !== '' && !isset($name_pair_map[$name_identity])) {
                $name_pair_map[$name_identity] = array('cash_account_id' => (int) $cash_id, 'pos_account_id' => (int) $pos_id);
            }
            if (class_exists('WSM_Activity_Log')) {
                WSM_Activity_Log::log('create', 'finance_control', $id, 'نگاشت خودکار POS', '', array('device_id' => $id, 'cash_account_id' => (int) $cash_id, 'pos_account_id' => (int) $pos_id), 'success', (int) $id);
            }
        }
        if ($changed) { update_option('wsm_finance_control_config', $config, false); }
        return $config;
    }

    private static function device_finance_accounts($device_id = null) {
        global $wpdb;
        $device_id = $device_id === null ? (class_exists('WSM_Permissions') ? WSM_Permissions::current_device_id() : 0) : absint($device_id);
        $config = self::ensure_device_finance_mapping($device_id);
        $map = isset($config['device_map'][$device_id]) ? $config['device_map'][$device_id] : array();
        $cash_id = absint(isset($map['cash_account_id']) ? $map['cash_account_id'] : self::finance_account_id_by_key('cash_box'));
        $pos_id = absint(isset($map['pos_account_id']) ? $map['pos_account_id'] : self::finance_account_id_by_key('pos_terminal_1'));
        $default_bank = absint(isset($config['default_bank_account_id']) ? $config['default_bank_account_id'] : 0);
        if (!$default_bank) { $default_bank = self::finance_account_id_by_key('bank_account_1'); }
        $valid = $wpdb->get_results($wpdb->prepare("SELECT id,account_type FROM " . self::table('financial_accounts') . " WHERE id IN (%d,%d,%d) AND is_active=1", $cash_id, $pos_id, $default_bank), ARRAY_A);
        $types = array(); foreach ((array) $valid as $row) { $types[(int) $row['id']] = (string) $row['account_type']; }
        if (!isset($types[$cash_id]) || $types[$cash_id] !== 'cash') { $cash_id = self::finance_account_id_by_key('cash_box'); }
        if (!isset($types[$pos_id]) || $types[$pos_id] !== 'pos') { $pos_id = self::finance_account_id_by_key('pos_terminal_1'); }
        if (!isset($types[$default_bank]) || $types[$default_bank] !== 'bank') { $default_bank = self::finance_account_id_by_key('bank_account_1'); }
        return array('device_id' => $device_id, 'cash_account_id' => $cash_id, 'pos_account_id' => $pos_id, 'default_bank_account_id' => $default_bank, 'mapped' => isset($config['device_map'][$device_id]));
    }

    /** Resolve a membership receipt to the correct account and require a live POS session for cash/card. */
    public static function membership_payment_finance_context($method, $gateway_id = '', $requested_account_id = 0) {
        global $wpdb;
        $method = is_scalar($method) ? sanitize_key((string)$method) : '';
        $expected_type = array('cash'=>'cash','card'=>'pos','bank_transfer'=>'bank','online'=>'virtual','other'=>'virtual');
        if (!isset($expected_type[$method])) { return new WP_Error('wsm_club_payment_method_invalid','روش دریافت شهریه معتبر نیست.',array('status'=>400)); }
        $gateway_id = is_scalar($gateway_id) ? sanitize_key((string)$gateway_id) : '';
        if($method==='online'){
            if($gateway_id===''){return new WP_Error('wsm_club_payment_gateway_required','برای ثبت دریافت آنلاین، شناسهٔ درگاه واقعی الزامی است.',array('status'=>400));}
            $known_gateway=false;foreach(self::finance_gateways() as $gateway){if((string)($gateway['id']??'')===$gateway_id&&!empty($gateway['enabled'])){$known_gateway=true;break;}}
            if(!$known_gateway){return new WP_Error('wsm_club_payment_gateway_unknown','درگاه آنلاین انتخاب‌شده در ووکامرس فعال و شناخته‌شده نیست.',array('status'=>409));}
        }
        $requested_account_id = absint($requested_account_id);
        $mapping = self::device_finance_accounts();
        if ($method === 'cash') {
            $account_id = (int)$mapping['cash_account_id'];
            if ($requested_account_id && $requested_account_id !== $account_id) { return new WP_Error('wsm_club_payment_session_account_mismatch','برای دریافت نقدی باید از صندوق نگاشت‌شده به همین POS استفاده شود.',array('status'=>409)); }
        } elseif ($method === 'card') {
            $account_id = (int)$mapping['pos_account_id'];
            if ($requested_account_id && $requested_account_id !== $account_id) { return new WP_Error('wsm_club_payment_session_account_mismatch','برای دریافت کارتی باید از کارتخوان نگاشت‌شده به همین POS استفاده شود.',array('status'=>409)); }
        } elseif ($method === 'online') {
            $account_id = self::gateway_clearing_account($gateway_id, $gateway_id);
            if ($requested_account_id && $requested_account_id !== (int)$account_id) { return new WP_Error('wsm_club_payment_gateway_account_mismatch','درگاه آنلاین باید به حساب واسط اختصاصی همان درگاه ثبت شود.',array('status'=>409)); }
        } elseif ($requested_account_id) {
            $account_id = $requested_account_id;
        } elseif ($method === 'bank_transfer') {
            $account_id = (int)$mapping['default_bank_account_id'];
        } else {
            $account_id = self::finance_account_id_by_key('virtual_unassigned');
        }
        $account = $account_id ? $wpdb->get_row($wpdb->prepare('SELECT id,account_type FROM '.self::table('financial_accounts').' WHERE id=%d AND is_active=1',$account_id),ARRAY_A) : null;
        if (!is_array($account) || (string)$account['account_type'] !== $expected_type[$method]) {
            return new WP_Error('wsm_club_payment_account_invalid','حساب فعال با روش دریافت انتخاب‌شده مطابقت ندارد.',array('status'=>409));
        }
        $cash_session_id = 0;
        if (in_array($method,array('cash','card'),true)) {
            $session = self::current_cash_session((int)$mapping['cash_account_id'],(int)$mapping['device_id']);
            if (!$session) { return new WP_Error('wsm_club_payment_cash_session_required','برای دریافت نقدی یا کارتخوان، ابتدا نشست صندوق همین POS را باز کنید؛ پرداخت آفلاین تا بازشدن نشست روی سرور در صف می‌ماند.',array('status'=>409)); }
            $cash_session_id = (int)$session['id'];
        }
        return array('account_id'=>(int)$account_id,'cash_session_id'=>$cash_session_id,'cash_account_id'=>(int)$mapping['cash_account_id'],'device_id'=>(int)$mapping['device_id'],'gateway_id'=>$gateway_id,'account_type'=>(string)$account['account_type']);
    }

    private static function finance_session_marker($session_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT account_id,reference_id,description FROM " . self::table('financial_ledger') . " WHERE cash_session_id=%d AND source='cash_session_open' AND status='posted' ORDER BY id ASC LIMIT 1", absint($session_id)), ARRAY_A);
        if (!$row) { return array('account_id' => self::finance_account_id_by_key('cash_box'), 'device_id' => 0, 'description' => ''); }
        $device_id = 0;
        if (!empty($row['reference_id']) && preg_match('/^device:(\d+)$/', (string) $row['reference_id'], $match)) { $device_id = absint($match[1]); }
        return array('account_id' => absint($row['account_id']), 'device_id' => $device_id, 'description' => (string) $row['description']);
    }

    private static function finance_gateways() {
        $out = array();
        $wc = function_exists('WC') ? WC() : null;
        if ($wc && method_exists($wc, 'payment_gateways')) {
            foreach ((array) $wc->payment_gateways()->payment_gateways() as $gateway) {
                if (!is_object($gateway) || empty($gateway->id)) { continue; }
                $out[] = array('id' => sanitize_key((string) $gateway->id), 'title' => self::text(isset($gateway->title) ? $gateway->title : $gateway->id, 120), 'enabled' => !isset($gateway->enabled) || $gateway->enabled === 'yes');
            }
        }
        $portal_settings = self::settings();
        $portal_gateway_enabled = !empty($portal_settings['portal_enabled']) || trim((string)($portal_settings['portal_merchant_id'] ?? '')) !== '';
        if (!$portal_gateway_enabled) {
            $pending_intents = get_option(self::PORTAL_INTENTS_OPTION, array());
            if (is_array($pending_intents)) {
                foreach ($pending_intents as $intent) {
                    if (is_array($intent) && in_array((string)($intent['status'] ?? ''), array('requesting','pending','processing','needs_manual_review'), true)) { $portal_gateway_enabled = true; break; }
                }
            }
        }
        $out[] = array('id'=>'athlete_portal_zarinpal','title'=>'درگاه آنلاین پورتال ورزشکار (زرین‌پال)','enabled'=>$portal_gateway_enabled);
        return $out;
    }

    private static function gateway_clearing_account($gateway_id, $gateway_title = '') {
        global $wpdb;
        $gateway_id = sanitize_key((string) $gateway_id);
        $config = self::finance_control_config();
        if ($gateway_id !== '' && !empty($config['gateway_map'][$gateway_id])) {
            $id = absint($config['gateway_map'][$gateway_id]);
            $type = $wpdb->get_var($wpdb->prepare("SELECT account_type FROM " . self::table('financial_accounts') . " WHERE id=%d AND is_active=1", $id));
            if ($type === 'virtual') { return $id; }
        }
        if ($gateway_id !== '') {
            $key = 'wsm_gateway_' . substr($gateway_id, 0, 40);
            $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table('financial_accounts') . " WHERE account_key=%s AND is_active=1", $key));
            if ($existing) { $config['gateway_map'][$gateway_id] = $existing; update_option('wsm_finance_control_config', $config, false); return $existing; }
            $title = self::text($gateway_title !== '' ? $gateway_title : $gateway_id, 100);
            $created = self::create_financial_account_row('واسط درگاه · ' . $title, 'virtual', $key);
            if (!is_wp_error($created)) { $config['gateway_map'][$gateway_id] = (int) $created; update_option('wsm_finance_control_config', $config, false); return (int) $created; }
        }
        return self::finance_account_id_by_key('virtual_unassigned');
    }

    public static function add_finance($kind, $amount, $title, $extra = array()) {
        global $wpdb;
        $core=self::financial_core_ready();if(is_wp_error($core)){return $core;}
        $valid=array('member_payment','card_recharge','card_spend','expense','cash_removal','payroll','discount','pos_sale','pos_refund','misc_income','transfer_in','transfer_out','cash_adjustment','balance_adjustment','supplier_payable','supplier_payment','receivable','pos_receivable');
        if(!in_array($kind,$valid,true)){return new WP_Error('wsm_club_finance_kind','نوع گردش مالی معتبر نیست.',array('status'=>400));}
        $extra=is_array($extra)?$extra:array();
        $allow_zero=isset($extra['source'])&&sanitize_key((string)$extra['source'])==='cash_session_open';
        $amount=self::amount($amount,'مبلغ گردش مالی',$allow_zero); if(is_wp_error($amount)){return $amount;}
        $date=isset($extra['occurred_at'])&&is_scalar($extra['occurred_at'])?sanitize_text_field((string)$extra['occurred_at']):current_time('mysql');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/',$date)){$date=current_time('mysql');}
        if(strlen($date)===10){$date.=' 12:00:00';}
        $legacy_method=isset($extra['method'])&&is_scalar($extra['method'])?sanitize_key((string)$extra['method']):'cash';
        $allowed_methods=array('cash','card','manual_card','card_pos','bank_transfer','online','other','check','club_credit','credit_nasiye','transfer');
        if(!in_array($legacy_method,$allowed_methods,true)){$legacy_method='cash';}
        $payment_method='cash';
        if(in_array($legacy_method,array('card','manual_card','card_pos'),true)){$payment_method='card_pos';}
        elseif(in_array($legacy_method,array('bank_transfer','online','check'),true)){$payment_method='bank_transfer';}
        elseif(in_array($legacy_method,array('club_credit','credit_nasiye'),true)){$payment_method='credit_nasiye';}
        elseif($legacy_method==='other'){$payment_method='other';}
        elseif($legacy_method==='transfer'){$payment_method='transfer';}
        $type_map=array('member_payment'=>'income','card_recharge'=>'liability','pos_sale'=>'income','misc_income'=>'income','expense'=>'expense','cash_removal'=>'expense','payroll'=>'expense','pos_refund'=>'expense','supplier_payment'=>'expense','supplier_payable'=>'payable','receivable'=>'receivable','pos_receivable'=>'receivable','discount'=>'discount','card_spend'=>'liability','transfer_in'=>'transfer','transfer_out'=>'transfer','balance_adjustment'=>'transfer','cash_adjustment'=>'expense');
        $transaction_type=isset($extra['transaction_type'])?sanitize_key((string)$extra['transaction_type']):(isset($type_map[$kind])?$type_map[$kind]:'expense');
        if(!in_array($transaction_type,array('income','expense','transfer','receivable','payable','discount','liability'),true)){$transaction_type=isset($type_map[$kind])?$type_map[$kind]:'expense';}
        $source_map=array('member_payment'=>'membership_payment','card_recharge'=>'card_charge','card_spend'=>'pos_card_credit','expense'=>'manual_expense','cash_removal'=>'cash_removal','payroll'=>'payroll','discount'=>'membership_discount','pos_sale'=>'pos_sale','pos_refund'=>'pos_refund','misc_income'=>'misc_income','transfer_in'=>'internal_transfer','transfer_out'=>'internal_transfer','cash_adjustment'=>'cash_discrepancy','balance_adjustment'=>'account_adjustment','supplier_payable'=>'supplier_commitment','supplier_payment'=>'supplier_payment','receivable'=>'membership_receivable','pos_receivable'=>'pos_debt');
        $source=isset($extra['source'])?sanitize_key((string)$extra['source']):(isset($source_map[$kind])?$source_map[$kind]:$kind);
        if($source===''){$source='manual';}
        $default_account_key='cash_box';
        if($payment_method==='card_pos'){$default_account_key='pos_terminal_1';}
        elseif($payment_method==='bank_transfer'){$default_account_key='bank_account_1';}
        elseif($payment_method==='credit_nasiye'){$default_account_key='club_credit_liability';}
        elseif($payment_method==='other'){$default_account_key='virtual_unassigned';}
        if($kind==='card_spend'){$default_account_key='club_credit_liability';}
        $no_account=in_array($kind,array('discount','supplier_payable','receivable','pos_receivable','card_spend'),true)||!empty($extra['no_account']);
        $account_id=array_key_exists('account_id',$extra)?absint($extra['account_id']):0;
        if(!$account_id&&!$no_account){$account_id=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table('financial_accounts').' WHERE account_key=%s AND is_active=1',$default_account_key));}
        if(!$account_id&&!$no_account){return new WP_Error('wsm_club_finance_account','حساب مالی فعال برای این تراکنش پیدا نشد.',array('status'=>409));}
        if($account_id){$account_exists=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table('financial_accounts').' WHERE id=%d AND is_active=1',$account_id));if(!$account_exists){return new WP_Error('wsm_club_finance_account','حساب مالی انتخاب‌شده فعال نیست.',array('status'=>400));}}
        if(array_key_exists('account_delta',$extra)){$delta_units=self::decimal_units($extra['account_delta']);if($delta_units===null){return new WP_Error('wsm_club_finance_delta','تغییر ماندهٔ حساب نامعتبر است.',array('status'=>400));}}
        else{$amount_units=(int)self::decimal_units($amount);if(in_array($kind,array('member_payment','card_recharge','pos_sale','misc_income'),true)){$delta_units=$amount_units;}elseif(in_array($kind,array('expense','cash_removal','payroll','pos_refund','supplier_payment'),true)){$delta_units=-$amount_units;}else{$delta_units=0;}}
        if($no_account){$account_id=0;$delta_units=0;}
        $ledger_status=isset($extra['ledger_status'])?sanitize_key((string)$extra['ledger_status']):($transaction_type==='receivable'?'pending':'confirmed');
        if(!in_array($ledger_status,array('pending','confirmed','cancelled','partial','settled'),true)){$ledger_status='confirmed';}
        $reference=self::text(isset($extra['reference'])?$extra['reference']:'',120);
        $reference_id=self::text(isset($extra['reference_id'])?$extra['reference_id']:$reference,120);
        if($reference_id===''&&!empty($extra['order_id'])){$reference_id=(string)absint($extra['order_id']);}
        elseif($reference_id===''&&!empty($extra['payment_id'])){$reference_id=(string)absint($extra['payment_id']);}
        $ip=isset($_SERVER['REMOTE_ADDR'])?sanitize_text_field((string)$_SERVER['REMOTE_ADDR']):'';
        if($ip!==''&&!filter_var($ip,FILTER_VALIDATE_IP)){$ip='';}
        $cash_session_id=absint(isset($extra['cash_session_id'])?$extra['cash_session_id']:0);
        if(!$cash_session_id&&$account_id){
            $device_id=class_exists('WSM_Permissions')?WSM_Permissions::current_device_id():0;
            $mapping=self::device_finance_accounts($device_id);
            if($account_id===(int)$mapping['cash_account_id']||$account_id===(int)$mapping['pos_account_id']){
                $session=self::current_cash_session((int)$mapping['cash_account_id'],$device_id);
                if($session){$cash_session_id=(int)$session['id'];}
            }
        }
        $account_before=$account_id?(string)$wpdb->get_var($wpdb->prepare('SELECT CAST(current_balance AS CHAR) FROM '.self::table('financial_accounts').' WHERE id=%d AND is_active=1',$account_id)):'';
        $row=array(
            'kind'=>$kind,'member_id'=>absint(isset($extra['member_id'])?$extra['member_id']:0),'membership_id'=>absint(isset($extra['membership_id'])?$extra['membership_id']:0),'payment_id'=>absint(isset($extra['payment_id'])?$extra['payment_id']:0),'order_id'=>absint(isset($extra['order_id'])?$extra['order_id']:0),
            'title'=>self::text($title,190),'category'=>self::text(isset($extra['category'])?$extra['category']:'',100),'amount'=>$amount,'transaction_date'=>$date,'transaction_type'=>$transaction_type,'source'=>$source,'reference_id'=>$reference_id,'payment_method'=>$payment_method,'account_id'=>$account_id,'account_delta'=>self::decimal_string($delta_units),'parent_id'=>absint(isset($extra['parent_id'])?$extra['parent_id']:0),'payable_id'=>absint(isset($extra['payable_id'])?$extra['payable_id']:0),'cash_session_id'=>$cash_session_id,'ledger_status'=>$ledger_status,'ip_address'=>$ip,
            'method'=>$legacy_method,'reference'=>$reference,'description'=>sanitize_textarea_field(isset($extra['description'])?(string)$extra['description']:''),'occurred_at'=>$date,'status'=>'posted','created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')
        );
        if($wpdb->insert(self::table('financial_ledger'),$row)===false){return new WP_Error('wsm_club_finance_save','ثبت گردش مالی انجام نشد.',array('status'=>500));}
        $id=(int)$wpdb->insert_id;
        if($account_id&&$delta_units!==0){
            $updated=$wpdb->query($wpdb->prepare('UPDATE '.self::table('financial_accounts').' SET current_balance=current_balance+CAST(%s AS DECIMAL(20,6)),updated_at=%s WHERE id=%d AND is_active=1',self::decimal_string($delta_units),current_time('mysql'),$account_id));
            if($updated!==1){$wpdb->update(self::table('financial_ledger'),array('status'=>'void','ledger_status'=>'cancelled','void_reason'=>'account_update_failed','voided_by'=>get_current_user_id(),'voided_at'=>current_time('mysql')),array('id'=>$id),array('%s','%s','%s','%d','%s'),array('%d'));return new WP_Error('wsm_club_finance_account_update','به‌روزرسانی ماندهٔ حساب انجام نشد؛ ردیف ناموفق به‌صورت ابطال‌شده حفظ شد.',array('status'=>500));}
        }
        $account_after=$account_id?(string)$wpdb->get_var($wpdb->prepare('SELECT CAST(current_balance AS CHAR) FROM '.self::table('financial_accounts').' WHERE id=%d',$account_id)):'';
        WSM_Activity_Log::log('create','club_finance',$id,$row['title'],array('account_id'=>$account_id,'current_balance'=>$account_before),array('kind'=>$kind,'amount'=>$amount,'account_id'=>$account_id,'account_delta'=>$row['account_delta'],'current_balance'=>$account_after,'transaction_date'=>$date,'transaction_type'=>$transaction_type,'source'=>$source),'success');
        return $id;
    }

    public static function create_expense($data, $kind = 'expense') {
        if (!is_array($data)) { return new WP_Error('wsm_club_expense_invalid','اطلاعات پرداخت نامعتبر است.',array('status'=>400)); }
        $approved=self::verify_finance_manager($data,'expense_create');if(is_wp_error($approved)){return $approved;}
        if(!rest_sanitize_boolean($data['expense_paid_confirmed']??false)){return new WP_Error('wsm_expense_payment_unconfirmed','هزینه فقط پس از تأیید پرداخت واقعی ثبت می‌شود.',array('status'=>400));}
        $title=self::text(isset($data['title'])?$data['title']:'',190); if ($title==='') { return new WP_Error('wsm_club_expense_title','شرح هزینه الزامی است.',array('status'=>400)); }
        $amount=self::amount(isset($data['amount'])?$data['amount']:null,'مبلغ هزینه'); if (is_wp_error($amount)) { return $amount; }
        $extra=array('account_id'=>absint(isset($data['account_id'])?$data['account_id']:0),'category'=>isset($data['category'])?$data['category']:'','method'=>isset($data['method'])?$data['method']:'cash','reference'=>isset($data['reference'])?$data['reference']:'','description'=>isset($data['description'])?$data['description']:'','occurred_at'=>isset($data['occurred_at'])?$data['occurred_at']:current_time('mysql'),'source'=>'manual_expense','transaction_type'=>'expense');
        $id=self::add_finance($kind,$amount,$title,$extra); if (is_wp_error($id)) { return $id; }
        return self::finance_item($id);
    }

    public static function recharge_card($data) {
        global $wpdb;
        if (!is_array($data)) { return new WP_Error('wsm_club_card_invalid','اطلاعات شارژ کارت نامعتبر است.',array('status'=>400)); }
        $approved=self::verify_finance_manager($data,'card_recharge');if(is_wp_error($approved)){return $approved;}
        if(!rest_sanitize_boolean($data['receipt_confirmed']??false)){return new WP_Error('wsm_club_card_receipt_unconfirmed','شارژ فقط پس از دریافت واقعی وجه تأییدشده ثبت می‌شود.',array('status'=>400));}
        $member_id=absint(isset($data['member_id'])?$data['member_id']:0); $amount=self::amount(isset($data['amount'])?$data['amount']:null,'مبلغ شارژ');
        if (is_wp_error($amount)) { return $amount; }
        $wpdb->query('START TRANSACTION');
        $member=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WSM_Club::table('members').' WHERE id=%d FOR UPDATE',$member_id));
        if (!$member || $member->card_number==='') { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_card_not_found','عضوی با کارت ثبت‌شده پیدا نشد.',array('status'=>404)); }
        $updated=$wpdb->query($wpdb->prepare('UPDATE '.WSM_Club::table('members').' SET card_balance=card_balance+%f,updated_at=%s WHERE id=%d',$amount,current_time('mysql'),$member_id));
        if ($updated===false) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_card_charge_failed','شارژ کارت انجام نشد.',array('status'=>500)); }
        $fid=self::add_finance('card_recharge',$amount,'شارژ کارت '.$member->card_number,array('member_id'=>$member_id,'method'=>isset($data['method'])?$data['method']:'cash','reference'=>isset($data['reference'])?$data['reference']:'','description'=>isset($data['note'])?$data['note']:''));
        if (is_wp_error($fid)) { $wpdb->query('ROLLBACK'); return $fid; }
        $balance=(float)$member->card_balance+(float)$amount;
        $wpdb->query('COMMIT');
        return array('member_id'=>$member_id,'member_name'=>trim((string)$member->first_name.' '.(string)$member->last_name),'card_number'=>$member->card_number,'amount'=>(string)$amount,'balance'=>(string)$balance,'finance_id'=>(int)$fid,'finance'=>self::finance_item($fid));
    }

    public static function finance_item($id) {
        global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('finance').' WHERE id=%d',(int)$id),ARRAY_A);
    }

    public static function list_financial_accounts() {
        global $wpdb;
        $table=self::table('financial_accounts');
        $rows=$wpdb->get_results("SELECT DISTINCT id,account_key,account_name,account_type,CAST(current_balance AS CHAR) AS current_balance,CAST(opening_balance AS CHAR) AS opening_balance,is_active FROM $table WHERE is_active=1 ORDER BY id ASC",ARRAY_A);
        $unique=array();
        foreach((array)$rows as $row){
            $id=isset($row['id'])?absint($row['id']):0;
            if($id>0&&!isset($unique[$id])){$unique[$id]=$row;}
        }
        return array_values($unique);
    }

    private static function list_payables($limit=100) {
        global $wpdb;
        $table=self::table('payables'); $limit=max(1,min(500,(int)$limit));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,supplier_name,CAST(total_debt AS CHAR) AS total_debt,CAST(paid_amount AS CHAR) AS paid_amount,CAST(remaining AS CHAR) AS remaining,due_date,status,reference,description,created_at,updated_at FROM $table WHERE remaining>0 ORDER BY CASE WHEN due_date IS NULL THEN 1 ELSE 0 END,due_date ASC,id DESC LIMIT %d",$limit),ARRAY_A);
        $summary=$wpdb->get_row("SELECT CAST(COALESCE(SUM(total_debt),0) AS CHAR) AS total_debt,CAST(COALESCE(SUM(paid_amount),0) AS CHAR) AS paid_amount,CAST(COALESCE(SUM(remaining),0) AS CHAR) AS remaining,COUNT(CASE WHEN remaining>0 THEN 1 END) AS open_count FROM $table",ARRAY_A);
        return array('items'=>(array)$rows,'summary'=>is_array($summary)?$summary:array('total_debt'=>'0','paid_amount'=>'0','remaining'=>'0','open_count'=>0));
    }

    private static function sync_receivables_projection() {
        global $wpdb;
        $table = self::table('receivables');
        $debtors = self::report(array('type' => 'debtors', 'sort' => 'highest'));
        if (is_wp_error($debtors)) { return $debtors; }
        $items = isset($debtors['items']) && is_array($debtors['items']) ? $debtors['items'] : array();
        $member_ids = array_values(array_unique(array_filter(array_map(function ($member) { return absint(isset($member['id']) ? $member['id'] : 0); }, $items))));
        $wpdb->last_error = '';
        $projected_member_ids = $wpdb->get_col("SELECT DISTINCT member_id FROM $table WHERE member_id>0");
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_receivables_member_lookup','خواندن شناسه‌های قبلی دریافتنی انجام نشد.',array('status'=>500)); }
        $member_ids = array_values(array_unique(array_merge($member_ids, array_map('absint', (array) $projected_member_ids))));
        $payment_totals = array();
        $payment_dates = array();
        if ($member_ids) {
            $ids = implode(',', array_map('absint', $member_ids));
            $wpdb->last_error = '';
            $payments = $wpdb->get_results("SELECT member_id,COALESCE(SUM(amount),0) AS total_paid,MAX(transaction_date) AS last_payment_date FROM " . self::table('financial_ledger') . " WHERE member_id IN ($ids) AND kind='member_payment' AND status='posted' GROUP BY member_id", ARRAY_A);
            if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_receivables_payments_lookup','خواندن دریافت‌های ثبت‌شده برای بازسازی ماندهٔ دریافتنی انجام نشد.',array('status'=>500)); }
            foreach ((array) $payments as $payment) {
                $payment_member_id = (int) $payment['member_id'];
                $payment_totals[$payment_member_id] = (string) $payment['total_paid'];
                $payment_dates[$payment_member_id] = (string) $payment['last_payment_date'];
            }
        }
        $active = array();
        $now = current_time('mysql');
        foreach ($items as $member) {
            $member_id = absint(isset($member['id']) ? $member['id'] : 0);
            if (!$member_id) { continue; }
            $remaining = self::amount(isset($member['balance_due']) ? $member['balance_due'] : '0', 'ماندهٔ دریافتنی', true);
            if (is_wp_error($remaining)) { return $remaining; }
            $units = self::decimal_units($remaining);
            if ($units === null) { return new WP_Error('wsm_club_receivables_amount_invalid','مبلغ ماندهٔ دریافتنی معتبر نیست.',array('status'=>500)); }
            if ($units <= 0) { continue; }
            $paid = self::amount(isset($payment_totals[$member_id]) ? $payment_totals[$member_id] : '0', 'دریافت واقعی ثبت‌شده', true);
            if (is_wp_error($paid)) { return $paid; }
            $total_debt = self::amount((string) ((float) $remaining + (float) $paid), 'کل بدهی و دریافت واقعی', true);
            if (is_wp_error($total_debt)) { return $total_debt; }
            $active[$member_id] = $member_id;
            $last_payment = isset($payment_dates[$member_id]) ? sanitize_text_field((string) $payment_dates[$member_id]) : '';
            if ($last_payment !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/', $last_payment)) { $last_payment = ''; }
            if (strlen($last_payment) === 10) { $last_payment .= ' 12:00:00'; }
            $reference = 'member:' . $member_id;
            $sql = "INSERT INTO $table (member_id,total_debt,paid_amount,remaining,last_payment_date,status,reference_id,created_at,updated_at)
                VALUES (%d,CAST(%s AS DECIMAL(20,6)),CAST(%s AS DECIMAL(20,6)),CAST(%s AS DECIMAL(20,6)),NULLIF(%s,''),'open',%s,%s,%s)
                ON DUPLICATE KEY UPDATE
                total_debt=VALUES(total_debt),paid_amount=VALUES(paid_amount),remaining=VALUES(remaining),
                last_payment_date=COALESCE(VALUES(last_payment_date),last_payment_date),status='open',updated_at=VALUES(updated_at)";
            $wpdb->last_error = '';
            $written = $wpdb->query($wpdb->prepare($sql, $member_id, $total_debt, $paid, $remaining, $last_payment, $reference, $now, $now));
            if ($written === false || !empty($wpdb->last_error)) { return new WP_Error('wsm_club_receivables_projection_write','به‌روزرسانی تصویر ماندهٔ دریافتنی انجام نشد.',array('status'=>500)); }
        }
        $settled_sql = "SELECT id,member_id FROM $table WHERE remaining>0 OR status='settled'";
        if ($active) { $settled_sql .= ' AND member_id NOT IN (' . implode(',', array_map('absint', array_values($active))) . ')'; }
        $wpdb->last_error = '';
        $settled_rows = $wpdb->get_results($settled_sql, ARRAY_A);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_club_receivables_settled_lookup','خواندن ردیف‌های دریافتنی تسویه‌شده یا لغوشده انجام نشد.',array('status'=>500)); }
        foreach ((array) $settled_rows as $row) {
            $member_id = (int) $row['member_id'];
            $paid = self::amount(isset($payment_totals[$member_id]) ? $payment_totals[$member_id] : '0', 'دریافت واقعی ثبت‌شده', true);
            if (is_wp_error($paid)) { return $paid; }
            $updated = $wpdb->update($table, array('total_debt' => $paid, 'paid_amount' => $paid, 'remaining' => '0', 'status' => 'settled', 'updated_at' => $now), array('id' => (int) $row['id']));
            if ($updated === false) { return new WP_Error('wsm_club_receivables_projection_write','به‌روزرسانی ردیف دریافتنی بسته‌شده انجام نشد.',array('status'=>500)); }
        }
        $wpdb->last_error = '';
        $summary = $wpdb->get_row("SELECT CAST(COALESCE(SUM(total_debt),0) AS CHAR) AS total_debt,CAST(COALESCE(SUM(paid_amount),0) AS CHAR) AS paid_amount,CAST(COALESCE(SUM(remaining),0) AS CHAR) AS remaining,COUNT(CASE WHEN remaining>0 THEN 1 END) AS open_count FROM $table", ARRAY_A);
        if (!empty($wpdb->last_error) || !is_array($summary)) { return new WP_Error('wsm_club_receivables_summary_failed','بازخوانی خلاصهٔ دریافتنی‌ها انجام نشد.',array('status'=>500)); }
        return $summary;
    }

    /** Refresh the finance-area receivables projection after a membership or receipt write. */
    public static function refresh_receivables_projection() {
        return self::sync_receivables_projection();
    }

    private static function current_cash_session($cash_account_id = 0, $device_id = null) {
        global $wpdb;
        $device_id = $device_id === null ? (class_exists('WSM_Permissions') ? WSM_Permissions::current_device_id() : 0) : absint($device_id);
        if (!$cash_account_id) { $mapping = self::device_finance_accounts($device_id); $cash_account_id = (int) $mapping['cash_account_id']; }
        $ids = $wpdb->get_col("SELECT id FROM " . self::table('cash_sessions') . " WHERE status='open' ORDER BY id DESC LIMIT 100");
        foreach ((array) $ids as $id) {
            $marker = self::finance_session_marker((int) $id);
            if ((int) $marker['account_id'] !== (int) $cash_account_id) { continue; }
            if ((int) $marker['device_id'] > 0 && $device_id > 0 && (int) $marker['device_id'] !== $device_id) { continue; }
            $row = $wpdb->get_row($wpdb->prepare("SELECT id,opened_at,status,opened_by FROM " . self::table('cash_sessions') . " WHERE id=%d LIMIT 1", (int) $id), ARRAY_A);
            if (!$row) { continue; }
            $row['cash_account_id'] = (int) $cash_account_id;
            $row['device_id'] = (int) $marker['device_id'];
            // Blind count: expected/opening cash is deliberately absent until the session is closed.
            return $row;
        }
        return null;
    }

    /** Lock and revalidate an open POS cash session inside the caller's financial transaction. */
    public static function lock_open_cash_session($session_id, $cash_account_id = 0, $device_id = null) {
        global $wpdb;
        $session_id=absint($session_id);
        $cash_account_id=absint($cash_account_id);
        $device_id=$device_id===null?(class_exists('WSM_Permissions')?WSM_Permissions::current_device_id():0):absint($device_id);
        if(!$session_id){return new WP_Error('wsm_cash_session_required','شناسهٔ نشست صندوق برای ثبت پرداخت نقدی/کارتی لازم است.',array('status'=>409));}
        $wpdb->last_error='';
        $session=$wpdb->get_row($wpdb->prepare("SELECT id FROM ".self::table('cash_sessions')." WHERE id=%d AND status='open' FOR UPDATE",$session_id),ARRAY_A);
        if(!empty($wpdb->last_error)){return new WP_Error('wsm_cash_session_lock_failed','قفل‌کردن نشست صندوق انجام نشد.',array('status'=>500));}
        if(!is_array($session)){return new WP_Error('wsm_cash_session_not_open','نشست صندوق بسته یا نامعتبر است؛ پرداخت در آن ثبت نشد.',array('status'=>409));}
        $marker=self::finance_session_marker($session_id);
        if($cash_account_id&&absint($marker['account_id'])!==$cash_account_id){return new WP_Error('wsm_cash_session_account_mismatch','نشست صندوق به حساب نقدی دیگری نگاشت شده است.',array('status'=>409));}
        if($device_id>0&&absint($marker['device_id'])>0&&absint($marker['device_id'])!==$device_id){return new WP_Error('wsm_cash_session_device','نشست صندوق متعلق به POS دیگری است.',array('status'=>403));}
        return $session_id;
    }

    private static function list_cash_sessions($limit = 30) {
        global $wpdb;
        $limit = max(1, min(100, (int) $limit));
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . self::table('cash_sessions') . " ORDER BY id DESC LIMIT %d", $limit));
        $out = array();
        foreach ((array) $ids as $id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('cash_sessions') . " WHERE id=%d LIMIT 1", (int) $id), ARRAY_A);
            if (!$row) { continue; }
            $marker = self::finance_session_marker((int) $id);
            $row['cash_account_id'] = (int) $marker['account_id'];
            $row['device_id'] = (int) $marker['device_id'];
            $account = $wpdb->get_row($wpdb->prepare("SELECT account_name,account_type FROM " . self::table('financial_accounts') . " WHERE id=%d", (int) $marker['account_id']), ARRAY_A);
            $row['cash_account_name'] = is_array($account) ? (string) $account['account_name'] : '';
            if ($row['device_id'] && class_exists('WSM_Devices')) {
                $device = WSM_Devices::find((int) $row['device_id']);
                $row['device_name'] = $device ? (string) $device->device_name : '';
            } else { $row['device_name'] = ''; }
            if ($row['status'] === 'open') {
                unset($row['expected_amount'], $row['opening_amount'], $row['opening_book_balance'], $row['discrepancy'], $row['difference_reason']);
            } else {
                $audit = $wpdb->get_var($wpdb->prepare("SELECT after_data FROM " . WSM_Activity_Log::table() . " WHERE entity_type='cash_session' AND entity_id=%d AND action_type='update' ORDER BY id DESC LIMIT 1", (int) $id));
                $z = is_string($audit) ? json_decode($audit, true) : null;
                if (is_array($z)) { $row['z_report'] = $z; }
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function daily_reconciliation($from,$to) {
        global $wpdb;
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$to)){$from=current_time('Y-m-d');$to=$from;}
        $accounts=self::table('financial_accounts');$ledger=self::table('financial_ledger');$start=$from.' 00:00:00';$end=$to.' 23:59:59';
        $sql="SELECT a.id,a.account_key,a.account_name,a.account_type,
            CAST(a.opening_balance+COALESCE(SUM(CASE WHEN l.status='posted' AND l.transaction_date<%s THEN l.account_delta ELSE 0 END),0) AS CHAR) AS opening_balance,
            CAST(COALESCE(SUM(CASE WHEN l.status='posted' AND l.transaction_date BETWEEN %s AND %s AND l.account_delta>0 THEN l.account_delta ELSE 0 END),0) AS CHAR) AS receipts,
            CAST(COALESCE(SUM(CASE WHEN l.status='posted' AND l.transaction_date BETWEEN %s AND %s AND l.account_delta<0 THEN -l.account_delta ELSE 0 END),0) AS CHAR) AS outflows,
            CAST(COALESCE(SUM(CASE WHEN l.status='posted' AND l.transaction_date BETWEEN %s AND %s AND l.transaction_type='expense' AND l.kind<>'cash_removal' THEN l.amount ELSE 0 END),0) AS CHAR) AS expenses,
            CAST(a.opening_balance+COALESCE(SUM(CASE WHEN l.status='posted' AND l.transaction_date<%s THEN l.account_delta ELSE 0 END),0)+COALESCE(SUM(CASE WHEN l.status='posted' AND l.transaction_date BETWEEN %s AND %s THEN l.account_delta ELSE 0 END),0) AS CHAR) AS expected_closing
            FROM $accounts a LEFT JOIN $ledger l ON l.account_id=a.id WHERE a.is_active=1
            GROUP BY a.id,a.account_key,a.account_name,a.account_type,a.opening_balance ORDER BY a.id ASC";
        $rows=$wpdb->get_results($wpdb->prepare($sql,$start,$start,$end,$start,$end,$start,$end,$start,$start,$end),ARRAY_A);
        return (array)$rows;
    }

    private static function finance_account($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('financial_accounts').' WHERE id=%d AND is_active=1 FOR UPDATE',(int)$id));
    }

    private static function open_cash_session($data) {
        global $wpdb;
        $approved = self::verify_finance_manager($data, 'cash_open');
        if (is_wp_error($approved)) { return $approved; }
        $opening=self::amount(isset($data['opening_amount'])?$data['opening_amount']:null,'موجودی اولیهٔ صندوق',true);
        if(is_wp_error($opening)){return $opening;}
        $device_id = class_exists('WSM_Permissions') ? WSM_Permissions::current_device_id() : 0;
        $mapping = self::device_finance_accounts($device_id);
        $cash_id = absint($mapping['cash_account_id']);
        if (!$cash_id) { return new WP_Error('wsm_cash_account_missing','حساب صندوق این POS فعال نیست.',array('status'=>409)); }
        $accounts=self::table('financial_accounts');$sessions=self::table('cash_sessions');
        $wpdb->query('START TRANSACTION');
        $cash=$wpdb->get_row($wpdb->prepare("SELECT id,current_balance FROM $accounts WHERE id=%d AND account_type='cash' AND is_active=1 FOR UPDATE",$cash_id));
        if(!$cash){$wpdb->query('ROLLBACK');return new WP_Error('wsm_cash_account_missing','حساب صندوق نقدی این POS فعال نیست.',array('status'=>409));}
        $open_ids = $wpdb->get_col("SELECT id FROM $sessions WHERE status='open' ORDER BY id DESC LIMIT 100");
        foreach ((array) $open_ids as $open_id) {
            $marker = self::finance_session_marker((int) $open_id);
            if ((int) $marker['account_id'] === (int) $cash->id) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('wsm_cash_session_open','برای این صندوق یک جلسه از قبل باز است.',array('status'=>409,'session_id'=>(int)$open_id));
            }
        }
        $now=current_time('mysql');$opening_units=self::decimal_units($opening);$book_units=self::decimal_units($cash->current_balance);if($opening_units===null||$book_units===null){$wpdb->query('ROLLBACK');return new WP_Error('wsm_cash_open_amount','موجودی افتتاح صندوق معتبر نیست.',array('status'=>400));}
        $opening_delta=$opening_units-$book_units;
        if($opening_delta!==0){$opening_adjust=self::add_finance('balance_adjustment',self::decimal_string(abs($opening_delta)),'ثبت موجودی ابتدای صندوق POS',array('account_id'=>(int)$cash->id,'account_delta'=>self::decimal_string($opening_delta),'transaction_type'=>'transfer','source'=>'opening_balance','method'=>'cash','reference_id'=>'cash-open:'.$device_id.':'.$now,'description'=>'تطبیق موجودی دفتری با شمارش ابتدای صندوق','occurred_at'=>$now));if(is_wp_error($opening_adjust)){$wpdb->query('ROLLBACK');return $opening_adjust;}}
        $ok=$wpdb->insert($sessions,array('opened_at'=>$now,'opening_amount'=>$opening,'opening_book_balance'=>(string)$cash->current_balance,'expected_amount'=>$opening,'counted_amount'=>null,'discrepancy'=>'0','difference_reason'=>'','status'=>'open','opened_by'=>get_current_user_id(),'closed_by'=>0,'closed_at'=>null,'created_at'=>$now,'updated_at'=>$now));
        if($ok===false){$wpdb->query('ROLLBACK');return new WP_Error('wsm_cash_session_create','باز کردن صندوق انجام نشد.',array('status'=>500));}
        $id=(int)$wpdb->insert_id;
        $marker_id=self::add_finance('balance_adjustment','0','آغاز شمارش کور صندوق POS',array('account_id'=>(int)$cash->id,'account_delta'=>'0','transaction_type'=>'transfer','source'=>'cash_session_open','method'=>'other','reference_id'=>'device:'.$device_id,'description'=>'نشست صندوق به دستگاه POS و حساب نقدی نگاشت شد.','cash_session_id'=>$id,'occurred_at'=>$now));
        if(is_wp_error($marker_id)){$wpdb->query('ROLLBACK');return $marker_id;}
        $wpdb->query('COMMIT');
        WSM_Activity_Log::log('create','cash_session',$id,'باز کردن صندوق POS','',array('device_id'=>$device_id,'cash_account_id'=>(int)$cash->id,'opening_amount'=>$opening,'book_balance'=>(string)$cash->current_balance,'approved_by'=>$approved['user_id']),'success',null,(int)$approved['user_id']);
        return self::current_cash_session((int)$cash->id,$device_id);
    }

    private static function transfer_accounts_locked($from, $to, $amount, $source, $reference_id, $description, $cash_session_id = 0) {
        global $wpdb;
        $from = absint($from); $to = absint($to);
        if (!$from || !$to || $from === $to) { return new WP_Error('wsm_transfer_accounts','حساب مبدأ و مقصد معتبر نیست.',array('status'=>400)); }
        $units = self::decimal_units($amount);
        if ($units === null || $units <= 0) { return new WP_Error('wsm_transfer_amount','مبلغ انتقال معتبر نیست.',array('status'=>400)); }
        $table = self::table('financial_accounts');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,account_name,current_balance,account_type FROM $table WHERE id IN (%d,%d) AND is_active=1 ORDER BY id FOR UPDATE", $from, $to), ARRAY_A);
        $accounts = array(); foreach ((array) $rows as $row) { $accounts[(int) $row['id']] = $row; }
        if (!isset($accounts[$from], $accounts[$to])) { return new WP_Error('wsm_transfer_account_missing','یکی از حساب‌ها فعال نیست.',array('status'=>409)); }
        $source_units = self::decimal_units($accounts[$from]['current_balance']);
        if ($source_units === null || $source_units < $units) { return new WP_Error('wsm_transfer_insufficient','موجودی حساب مبدأ برای انتقال واقعی کافی نیست.',array('status'=>409)); }
        $reference = 'TR-' . current_time('YmdHis') . '-' . wp_rand(10000, 99999);
        $base = array('transaction_type'=>'transfer','source'=>sanitize_key((string)$source),'method'=>'transfer','reference'=>$reference,'reference_id'=>self::text($reference_id !== '' ? $reference_id : $reference,120),'description'=>self::text($description,255),'occurred_at'=>current_time('mysql'),'cash_session_id'=>absint($cash_session_id));
        $out = self::add_finance('transfer_out',$amount,'انتقال از '.$accounts[$from]['account_name'],array_merge($base,array('account_id'=>$from,'account_delta'=>self::decimal_string(-$units))));
        if (is_wp_error($out)) { return $out; }
        $in = self::add_finance('transfer_in',$amount,'انتقال به '.$accounts[$to]['account_name'],array_merge($base,array('account_id'=>$to,'account_delta'=>self::decimal_string($units),'parent_id'=>(int)$out)));
        if (is_wp_error($in)) { return $in; }
        return array('reference_id'=>$reference,'out_id'=>(int)$out,'in_id'=>(int)$in,'from_account_id'=>$from,'to_account_id'=>$to,'amount'=>self::decimal_string($units));
    }

    private static function close_cash_session($data) {
        global $wpdb;
        $approved = self::verify_finance_manager($data, 'cash_close');
        if (is_wp_error($approved)) { return $approved; }
        $counted=self::amount(isset($data['counted_amount'])?$data['counted_amount']:null,'موجودی شمارش‌شدهٔ صندوق',true);
        if(is_wp_error($counted)){return $counted;}
        $counted_units=self::decimal_units($counted);
        $cash_reason=self::text(isset($data['difference_reason'])?$data['difference_reason']:'',255);
        $card_reason=self::text(isset($data['card_difference_reason'])?$data['card_difference_reason']:'',255);
        $session_id=absint(isset($data['session_id'])?$data['session_id']:0);
        if (!$session_id) { return new WP_Error('wsm_cash_session_required','شناسهٔ جلسهٔ صندوق برای چاپ گزارش Z لازم است.',array('status'=>400)); }
        $cash_deposit=isset($data['cash_deposit_amount'])&&$data['cash_deposit_amount']!==''?self::amount($data['cash_deposit_amount'],'مبلغ واریز نقدی',true):$counted;
        if(is_wp_error($cash_deposit)){return $cash_deposit;}
        $deposit_units=self::decimal_units($cash_deposit);
        if($deposit_units===null||$deposit_units!==$counted_units){return new WP_Error('wsm_cash_deposit_mismatch','برای بستن صندوق، کل مبلغ شمارش‌شده باید به بانک واریز شود.',array('status'=>400));}
        $card_settlement=isset($data['card_settlement_amount'])&&$data['card_settlement_amount']!==''?self::amount($data['card_settlement_amount'],'مبلغ تسویه کارتخوان',true):'0';
        if(is_wp_error($card_settlement)){return $card_settlement;}
        $settlement_units=self::decimal_units($card_settlement);
        if($deposit_units>0&&!rest_sanitize_boolean($data['cash_deposit_confirmed']??false)){return new WP_Error('wsm_cash_deposit_unconfirmed','پیش از ثبت گزارش Z، واریز واقعیِ تمام وجه نقد شمارش‌شده را تأیید کنید.',array('status'=>400));}
        if($settlement_units>0&&!rest_sanitize_boolean($data['card_settlement_confirmed']??false)){return new WP_Error('wsm_card_settlement_unconfirmed','فقط مبلغی را ثبت کنید که تسویهٔ واقعی کارتخوان آن به بانک تأیید شده است.',array('status'=>400));}
        $cash_bank_id=absint(isset($data['cash_bank_account_id'])?$data['cash_bank_account_id']:0);
        $card_bank_id=absint(isset($data['card_bank_account_id'])?$data['card_bank_account_id']:0);
        $device_id=class_exists('WSM_Permissions')?WSM_Permissions::current_device_id():0;
        $current_map=self::device_finance_accounts($device_id);
        $accounts=self::table('financial_accounts');$sessions=self::table('cash_sessions');$ledger=self::table('financial_ledger');
        $wpdb->query('START TRANSACTION');
        $session=$wpdb->get_row($wpdb->prepare("SELECT * FROM $sessions WHERE id=%d AND status='open' FOR UPDATE",$session_id));
        if(!$session){$wpdb->query('ROLLBACK');return new WP_Error('wsm_cash_session_missing','جلسهٔ باز برای بستن پیدا نشد.',array('status'=>409));}
        $marker=self::finance_session_marker($session_id);
        $session_device_id=(int)$marker['device_id'];$cash_id=(int)$marker['account_id'];
        $actor_device=class_exists('WSM_Permissions')?WSM_Permissions::current_device():null;
        $can_manage_all=$actor_device&&WSM_Devices::device_can($actor_device,'club.finance.manage');
        if(!$can_manage_all&&$session_device_id>0&&$device_id!==$session_device_id){$wpdb->query('ROLLBACK');return new WP_Error('wsm_cash_session_device','این نشست متعلق به POS دیگری است.',array('status'=>403));}
        $cash=$wpdb->get_row($wpdb->prepare("SELECT id,current_balance,account_type FROM $accounts WHERE id=%d AND is_active=1 FOR UPDATE",$cash_id));
        if(!$cash||$cash->account_type!=='cash'){$wpdb->query('ROLLBACK');return new WP_Error('wsm_cash_account_missing','حساب نقدی این صندوق فعال نیست.',array('status'=>409));}
        $movement=$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(account_delta),0) FROM $ledger WHERE status='posted' AND cash_session_id=%d AND account_id=%d",$session_id,$cash_id));
        $opening_units=self::decimal_units($session->opening_amount);$movement_units=self::decimal_units($movement===null?'0':$movement);
        if($opening_units===null||$movement_units===null||$counted_units===null){$wpdb->query('ROLLBACK');return new WP_Error('wsm_cash_session_amount','مبالغ جلسهٔ صندوق معتبر نیستند.',array('status'=>500));}
        $expected_units=$opening_units+$movement_units;$difference_units=$counted_units-$expected_units;
        if($difference_units!==0&&$cash_reason===''){$wpdb->query('ROLLBACK');return new WP_Error('wsm_cash_difference_reason','برای اختلاف شمارش‌شده با انتظار صندوق، دلیل ثبت کنید.',array('status'=>400));}
        $cash_bank=$wpdb->get_row($wpdb->prepare("SELECT id,account_type FROM $accounts WHERE id=%d AND is_active=1 FOR UPDATE",$cash_bank_id));
        if(!$cash_bank||$cash_bank->account_type!=='bank'){$wpdb->query('ROLLBACK');return new WP_Error('wsm_cash_bank_required','حساب مقصد واریز نقدی باید از نوع بانک باشد.',array('status'=>400));}
        $pos_id=(int)$current_map['pos_account_id'];
        if($session_device_id>0){$session_map=self::device_finance_accounts($session_device_id);$pos_id=(int)$session_map['pos_account_id'];}
        $pos=$wpdb->get_row($wpdb->prepare("SELECT id,current_balance,account_type FROM $accounts WHERE id=%d AND is_active=1 FOR UPDATE",$pos_id));
        if(!$pos||$pos->account_type!=='pos'){$wpdb->query('ROLLBACK');return new WP_Error('wsm_pos_account_missing','حساب کارتخوان این POS فعال نیست.',array('status'=>409));}
        $pos_expected_units=self::decimal_units($pos->current_balance);
        if($pos_expected_units===null||$settlement_units===null||$settlement_units<0||$settlement_units>max(0,$pos_expected_units)){$wpdb->query('ROLLBACK');return new WP_Error('wsm_pos_settlement_amount','مبلغ تسویه از ماندهٔ ثبت‌شدهٔ کارتخوان بیشتر یا نامعتبر است.',array('status'=>409));}
        if($settlement_units!==$pos_expected_units&&$card_reason===''){$wpdb->query('ROLLBACK');return new WP_Error('wsm_pos_settlement_reason','برای اختلاف ماندهٔ کارتخوان با واریز واقعی، دلیل ثبت کنید (مثلاً تسویهٔ ناقص یا کارمزد).',array('status'=>400));}
        if($settlement_units>0){
            $card_bank=$wpdb->get_row($wpdb->prepare("SELECT id,account_type FROM $accounts WHERE id=%d AND is_active=1 FOR UPDATE",$card_bank_id));
            if(!$card_bank||$card_bank->account_type!=='bank'){$wpdb->query('ROLLBACK');return new WP_Error('wsm_card_bank_required','حساب مقصد تسویهٔ کارتخوان باید از نوع بانک باشد.',array('status'=>400));}
        }
        if($difference_units!==0){
            $adjust=self::add_finance('cash_adjustment',self::decimal_string(abs($difference_units)),'تطبیق اختلاف صندوق POS',array('account_id'=>$cash_id,'account_delta'=>self::decimal_string($difference_units),'transaction_type'=>$difference_units>0?'income':'expense','source'=>'cash_discrepancy','method'=>'cash','description'=>$cash_reason,'cash_session_id'=>$session_id));
            if(is_wp_error($adjust)){$wpdb->query('ROLLBACK');return $adjust;}
        }
        $cash_transfer=null;
        if($deposit_units>0){$cash_transfer=self::transfer_accounts_locked($cash_id,$cash_bank_id,$cash_deposit,'z_cash_deposit','Z:'.$session_id.':cash','واریز کامل وجه نقد گزارش Z · جلسه '.$session_id,$session_id);if(is_wp_error($cash_transfer)){$wpdb->query('ROLLBACK');return $cash_transfer;}}
        $card_transfer=null;
        if($settlement_units>0){$card_transfer=self::transfer_accounts_locked($pos_id,$card_bank_id,$card_settlement,'z_card_settlement','Z:'.$session_id.':card','تسویهٔ واقعی کارتخوان گزارش Z · جلسه '.$session_id,$session_id);if(is_wp_error($card_transfer)){$wpdb->query('ROLLBACK');return $card_transfer;}}
        $cash_after_units=self::decimal_units($wpdb->get_var($wpdb->prepare("SELECT current_balance FROM $accounts WHERE id=%d",$cash_id)));
        $pos_after_units=self::decimal_units($wpdb->get_var($wpdb->prepare("SELECT current_balance FROM $accounts WHERE id=%d",$pos_id)));
        if($cash_after_units===null||$cash_after_units!==0){$wpdb->query('ROLLBACK');return new WP_Error('wsm_cash_session_not_zero','پس از انتقال کامل وجه شمارش‌شده، ماندهٔ صندوق POS صفر نشد؛ گزارش Z ثبت نشد.',array('status'=>409));}
        $now=current_time('mysql');
        $updated=$wpdb->update($sessions,array('expected_amount'=>self::decimal_string($expected_units),'counted_amount'=>$counted,'discrepancy'=>self::decimal_string($difference_units),'difference_reason'=>$cash_reason,'status'=>'closed','closed_by'=>get_current_user_id(),'closed_at'=>$now,'updated_at'=>$now),array('id'=>$session_id,'status'=>'open'));
        if($updated!==1){$wpdb->query('ROLLBACK');return new WP_Error('wsm_cash_session_close','بستن جلسهٔ صندوق انجام نشد.',array('status'=>500));}
        $wpdb->query('COMMIT');
        $z=array('session_id'=>$session_id,'device_id'=>$session_device_id,'device_name'=>'','cash_account_id'=>$cash_id,'opened_at'=>(string)$session->opened_at,'closed_at'=>$now,'expected_cash'=>self::decimal_string($expected_units),'counted_cash'=>$counted,'cash_difference'=>self::decimal_string($difference_units),'cash_difference_reason'=>$cash_reason,'cash_deposit_amount'=>$cash_deposit,'cash_bank_account_id'=>$cash_bank_id,'cash_balance_after'=>self::decimal_string($cash_after_units===null?0:$cash_after_units),'pos_account_id'=>$pos_id,'pos_expected_balance'=>self::decimal_string($pos_expected_units),'card_settlement_amount'=>$card_settlement,'card_bank_account_id'=>$settlement_units>0?$card_bank_id:0,'card_outstanding_balance'=>self::decimal_string(($pos_after_units===null?0:$pos_after_units)),'card_difference_reason'=>$card_reason,'cash_transfer_reference'=>$cash_transfer?$cash_transfer['reference_id']:'','card_transfer_reference'=>$card_transfer?$card_transfer['reference_id']:'','approved_by_user_id'=>$approved['user_id']);
        if($session_device_id>0&&class_exists('WSM_Devices')){$device=WSM_Devices::find($session_device_id);if($device)$z['device_name']=(string)$device->device_name;}
        WSM_Activity_Log::log('update','cash_session',$session_id,'گزارش Z صندوق POS',array('status'=>'open','cash_expected'=>self::decimal_string($expected_units)),array('status'=>'closed')+$z,'success',null,$approved['user_id']);
        return array('id'=>$session_id,'status'=>'closed','z_report'=>$z);
    }

    private static function transfer_accounts($data) {
        global $wpdb;
        $approved=self::verify_finance_manager($data,'account_transfer');if(is_wp_error($approved)){return $approved;}
        if(!rest_sanitize_boolean($data['transfer_confirmed']??false)){return new WP_Error('wsm_transfer_unconfirmed','پیش از ثبت، انتقال واقعی بین حساب‌ها را تأیید کنید.',array('status'=>400));}
        $from=absint($data['from_account_id']??0);$to=absint($data['to_account_id']??0);
        if(!$from||!$to||$from===$to){return new WP_Error('wsm_transfer_accounts','حساب مبدأ و مقصد معتبر نیست.',array('status'=>400));}
        $types=$wpdb->get_results($wpdb->prepare('SELECT id,account_type FROM '.self::table('financial_accounts').' WHERE id IN (%d,%d) AND is_active=1',$from,$to),ARRAY_A);$type_map=array();foreach((array)$types as $type_row){$type_map[(int)$type_row['id']]=(string)$type_row['account_type'];}
        if(($type_map[$from]??'')!=='bank'||($type_map[$to]??'')!=='bank'){return new WP_Error('wsm_transfer_bank_only','انتقال عمومی فقط بین حساب‌های بانکی مجاز است؛ انتقال نقد صندوق و تسویه POS باید در گزارش Z و تسویهٔ درگاه ثبت شود.',array('status'=>400));}
        $amount=self::amount($data['amount']??null,'مبلغ انتقال');if(is_wp_error($amount)){return $amount;}
        $wpdb->query('START TRANSACTION');
        $result=self::transfer_accounts_locked($from,$to,$amount,'internal_transfer',self::text($data['reference']??'',120),self::text($data['description']??'',255),absint($data['cash_session_id']??0));
        if(is_wp_error($result)){$wpdb->query('ROLLBACK');return $result;}
        $wpdb->query('COMMIT');
        return array('reference_id'=>$result['reference_id'],'out'=>self::finance_item($result['out_id']),'in'=>self::finance_item($result['in_id']));
    }

    private static function create_payable($data) {
        global $wpdb;
        $approved=self::verify_finance_manager($data,'payable_create');if(is_wp_error($approved)){return $approved;}$supplier=self::text($data['supplier_name']??'',190);if($supplier===''){return new WP_Error('wsm_payable_supplier','نام تأمین‌کننده الزامی است.',array('status'=>400));}$amount=self::amount($data['amount']??null,'مبلغ بدهی');if(is_wp_error($amount)){return $amount;}
        $due=isset($data['due_date'])?sanitize_text_field((string)$data['due_date']):'';if($due!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)){return new WP_Error('wsm_payable_due_date','تاریخ سررسید معتبر نیست.',array('status'=>400));}if($due!==''){$parts=explode('-',$due);if(!checkdate((int)$parts[1],(int)$parts[2],(int)$parts[0])){return new WP_Error('wsm_payable_due_date','تاریخ سررسید معتبر نیست.',array('status'=>400));}}$reference=self::text($data['reference']??'',120);$description=sanitize_textarea_field((string)($data['description']??''));$now=current_time('mysql');$table=self::table('payables');$wpdb->query('START TRANSACTION');
        $ok=$wpdb->insert($table,array('supplier_name'=>$supplier,'total_debt'=>$amount,'paid_amount'=>'0','remaining'=>$amount,'due_date'=>$due?:null,'status'=>'open','reference'=>$reference,'ledger_entry_id'=>0,'description'=>$description,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now));if($ok===false){$wpdb->query('ROLLBACK');return new WP_Error('wsm_payable_create','ثبت بدهی انجام نشد.',array('status'=>500));}
        $id=(int)$wpdb->insert_id;$ledger=self::add_finance('supplier_payable',$amount,'ثبت بدهی: '.$supplier,array('payable_id'=>$id,'transaction_type'=>'payable','source'=>'supplier_commitment','ledger_status'=>'pending','reference'=>$reference,'reference_id'=>'payable:'.$id,'description'=>$description,'method'=>'other'));if(is_wp_error($ledger)){$wpdb->query('ROLLBACK');return $ledger;}
        $linked=$wpdb->update($table,array('ledger_entry_id'=>(int)$ledger,'updated_at'=>$now),array('id'=>$id));if($linked===false){$wpdb->query('ROLLBACK');return new WP_Error('wsm_payable_link_failed','پیوند بدهی به دفتر مالی انجام نشد؛ عملیات بازگردانده شد.',array('status'=>500));}$wpdb->query('COMMIT');return array('id'=>$id,'ledger_entry_id'=>(int)$ledger,'supplier_name'=>$supplier,'amount'=>$amount,'remaining'=>$amount);
    }

    private static function pay_payable($data) {
        global $wpdb;
        $approved=self::verify_finance_manager($data,'payable_pay');if(is_wp_error($approved)){return $approved;}if(!rest_sanitize_boolean($data['payment_confirmed']??false)){return new WP_Error('wsm_payable_payment_unconfirmed','پیش از ثبت، پرداخت واقعی به تأمین‌کننده را تأیید کنید.',array('status'=>400));}$id=absint($data['payable_id']??0);$account_id=absint($data['account_id']??0);if(!$id||!$account_id){return new WP_Error('wsm_payable_fields','بدهی و حساب پرداخت الزامی است.',array('status'=>400));}$amount=self::amount($data['amount']??null,'مبلغ پرداخت');if(is_wp_error($amount)){return $amount;}$units=self::decimal_units($amount);$table=self::table('payables');$wpdb->query('START TRANSACTION');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE",$id),ARRAY_A);if(!$row){$wpdb->query('ROLLBACK');return new WP_Error('wsm_payable_missing','بدهی تأمین‌کننده پیدا نشد.',array('status'=>404));}$remaining=self::decimal_units($row['remaining']);if($remaining===null||$units===null||$units<=0||$units>$remaining){$wpdb->query('ROLLBACK');return new WP_Error('wsm_payable_overpay','مبلغ بیش از مانده یا نامعتبر است.',array('status'=>409));}
        $account=$wpdb->get_row($wpdb->prepare('SELECT current_balance FROM '.self::table('financial_accounts').' WHERE id=%d AND is_active=1 FOR UPDATE',$account_id));$account_units=$account?self::decimal_units($account->current_balance):null;if($account_units===null||$account_units<$units){$wpdb->query('ROLLBACK');return new WP_Error('wsm_payable_account_balance','حساب پرداخت فعال نیست یا موجودی کافی ندارد.',array('status'=>409));}
        $ledger=self::add_finance('supplier_payment',$amount,'پرداخت به '.$row['supplier_name'],array('payable_id'=>$id,'account_id'=>$account_id,'account_delta'=>self::decimal_string(-$units),'transaction_type'=>'expense','source'=>'supplier_payment','method'=>$data['method']??'cash','reference'=>$data['reference']??'','reference_id'=>'payable:'.$id,'description'=>$data['description']??'','occurred_at'=>$data['occurred_at']??current_time('mysql')));if(is_wp_error($ledger)){$wpdb->query('ROLLBACK');return $ledger;}
        $paid=self::decimal_units($row['paid_amount'])+$units;$left=$remaining-$units;$updated=$wpdb->update($table,array('paid_amount'=>self::decimal_string($paid),'remaining'=>self::decimal_string($left),'status'=>$left===0?'paid':'open','updated_at'=>current_time('mysql')),array('id'=>$id));if($updated===false){$wpdb->query('ROLLBACK');return new WP_Error('wsm_payable_update','به‌روزرسانی ماندهٔ تأمین‌کننده انجام نشد.',array('status'=>500));}$wpdb->query('COMMIT');return array('payable_id'=>$id,'ledger_entry_id'=>(int)$ledger,'paid_amount'=>self::decimal_string($units),'remaining'=>self::decimal_string($left));
    }

    private static function finance_control_snapshot() {
        $device_id = class_exists('WSM_Permissions') ? WSM_Permissions::current_device_id() : 0;
        $config = self::ensure_device_finance_mapping($device_id);
        $devices = array();
        $seen_device_identities = array();
        $seen_account_pairs = array();
        if (class_exists('WSM_Devices')) {
            foreach ((array) WSM_Devices::query(array('status' => 'active', 'limit' => 500)) as $device) {
                if (!WSM_Devices::device_can($device, 'club.pos.create')) { continue; }
                $id = isset($device->id) ? absint($device->id) : 0;
                if (!$id || isset($devices[$id])) { continue; }
                $map = isset($config['device_map'][$id]) && is_array($config['device_map'][$id]) ? $config['device_map'][$id] : array();
                $cash_id = absint($map['cash_account_id'] ?? 0);
                $pos_id = absint($map['pos_account_id'] ?? 0);
                $name_identity = self::financial_account_name_identity((string) ($device->device_name ?? ''));
                $pair_key = ($cash_id > 0 && $pos_id > 0) ? ($cash_id . ':' . $pos_id) : '';
                if ($pair_key !== '' && isset($seen_account_pairs[$pair_key])) { continue; }
                if ($name_identity !== '' && isset($seen_device_identities[$name_identity])) { continue; }
                if ($pair_key !== '') { $seen_account_pairs[$pair_key] = true; }
                if ($name_identity !== '') { $seen_device_identities[$name_identity] = true; }
                $devices[$id] = array(
                    'id' => $id,
                    'device_name' => (string) ($device->device_name ?? ''),
                    'platform' => (string) ($device->platform ?? ''),
                    'cash_account_id' => $cash_id,
                    'pos_account_id' => $pos_id,
                    'mapped' => !empty($map),
                );
            }
        }
        $gateway_rows = self::finance_gateways();
        $gateways = array();
        foreach ($gateway_rows as $gateway) {
            $gateway_id = isset($gateway['id']) ? sanitize_key((string) $gateway['id']) : '';
            if ($gateway_id === '' || isset($gateways[$gateway_id])) { continue; }
            $gateway['id'] = $gateway_id;
            $gateway['account_id'] = absint($config['gateway_map'][$gateway_id] ?? 0);
            $gateways[$gateway_id] = $gateway;
        }
        return array(
            'device_map' => $config['device_map'],
            'gateway_map' => $config['gateway_map'],
            'default_bank_account_id' => (int) $config['default_bank_account_id'],
            'devices' => array_values($devices),
            'gateways' => array_values($gateways),
        );
    }

    private static function finance_control_operation($op, $data) {
        global $wpdb;
        $approved = self::verify_finance_manager($data, 'finance_' . $op);
        if (is_wp_error($approved)) { return $approved; }
        $config = self::finance_control_config();
        if ($op === 'account_create') {
            $name = self::text($data['account_name'] ?? '', 160);
            $type = sanitize_key((string) ($data['account_type'] ?? ''));
            $opening_balance = self::amount($data['opening_balance'] ?? '0', 'ماندهٔ افتتاحیهٔ حساب', true);
            if (is_wp_error($opening_balance)) { return $opening_balance; }
            if (self::decimal_units($opening_balance) > 0 && !rest_sanitize_boolean($data['opening_balance_confirmed'] ?? false)) { return new WP_Error('wsm_finance_opening_balance_unconfirmed', 'ماندهٔ افتتاحیهٔ غیرصفر را فقط پس از تطبیق با موجودی واقعی تأیید کنید.', array('status' => 400)); }
            $id = self::create_financial_account_row($name, $type, '', $opening_balance);
            if (is_wp_error($id)) { return $id; }
            WSM_Activity_Log::log('create', 'finance_control', (int) $id, 'ایجاد حساب مالی', '', array('account_id' => (int) $id, 'account_name' => $name, 'account_type' => $type, 'opening_balance' => $opening_balance, 'approved_by' => $approved['user_id']), 'success', null, $approved['user_id']);
            return array('account' => $wpdb->get_row($wpdb->prepare("SELECT id,account_key,account_name,account_type,CAST(current_balance AS CHAR) AS current_balance FROM " . self::table('financial_accounts') . " WHERE id=%d", (int) $id), ARRAY_A));
        }
        if ($op === 'device_map') {
            $device_id = absint($data['device_id'] ?? 0);
            $cash_id = absint($data['cash_account_id'] ?? 0);
            $pos_id = absint($data['pos_account_id'] ?? 0);
            $device = $device_id && class_exists('WSM_Devices') ? WSM_Devices::find($device_id) : null;
            if (!$device || $device->status !== 'active' || !WSM_Devices::device_can($device, 'club.pos.create')) { return new WP_Error('wsm_finance_device_invalid', 'دستگاه POS فعال و مجاز پیدا نشد.', array('status' => 404)); }
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id,account_type FROM " . self::table('financial_accounts') . " WHERE id IN (%d,%d) AND is_active=1", $cash_id, $pos_id), ARRAY_A);
            $types = array(); foreach ((array) $rows as $row) { $types[(int) $row['id']] = (string) $row['account_type']; }
            if ($cash_id === $pos_id || ($types[$cash_id] ?? '') !== 'cash' || ($types[$pos_id] ?? '') !== 'pos') { return new WP_Error('wsm_finance_device_accounts_invalid', 'برای این دستگاه باید یک حساب نقدی و یک حساب کارتخوان جدا انتخاب شود.', array('status' => 400)); }
            $before = $config['device_map'][$device_id] ?? array();
            $shared_device_ids = array($device_id => true);
            if (!empty($before)) {
                foreach ($config['device_map'] as $other_id => $map) {
                    if ((int) ($map['cash_account_id'] ?? 0) === (int) $before['cash_account_id'] && (int) ($map['pos_account_id'] ?? 0) === (int) $before['pos_account_id']) {
                        $shared_device_ids[(int) $other_id] = true;
                    }
                }
            }
            foreach ($config['device_map'] as $other_id => $map) {
                if (isset($shared_device_ids[(int) $other_id])) { continue; }
                if ((int) $map['cash_account_id'] === $cash_id || (int) $map['pos_account_id'] === $pos_id) { return new WP_Error('wsm_finance_account_in_use', 'این حساب از قبل به POS دیگری نگاشت شده است.', array('status' => 409)); }
            }
            $updated_map = array('cash_account_id' => $cash_id, 'pos_account_id' => $pos_id);
            foreach (array_keys($shared_device_ids) as $shared_device_id) { $config['device_map'][(int) $shared_device_id] = $updated_map; }
            update_option('wsm_finance_control_config', $config, false);
            WSM_Activity_Log::log('update', 'finance_control', $device_id, 'نگاشت حساب POS · ' . $device->device_name, $before, array('device_ids' => array_map('absint', array_keys($shared_device_ids)), 'cash_account_id' => $cash_id, 'pos_account_id' => $pos_id, 'approved_by' => $approved['user_id']), 'success', $device_id, $approved['user_id']);
            return self::finance_control_snapshot();
        }
        if ($op === 'gateway_map') {
            $gateway_id = sanitize_key((string) ($data['gateway_id'] ?? ''));
            $account_id = absint($data['account_id'] ?? 0);
            $valid_gateway = false;
            foreach (self::finance_gateways() as $gateway) { if ($gateway['id'] === $gateway_id) { $valid_gateway = true; break; } }
            $type = $account_id ? $wpdb->get_var($wpdb->prepare("SELECT account_type FROM " . self::table('financial_accounts') . " WHERE id=%d AND is_active=1", $account_id)) : '';
            if (!$valid_gateway || $type !== 'virtual') { return new WP_Error('wsm_finance_gateway_map_invalid', 'درگاه فعال و حساب واسط مجازی معتبر لازم است.', array('status' => 400)); }
            $before = $config['gateway_map'][$gateway_id] ?? 0;
            $config['gateway_map'][$gateway_id] = $account_id;
            update_option('wsm_finance_control_config', $config, false);
            WSM_Activity_Log::log('update', 'finance_control', 0, 'نگاشت حساب واسط درگاه ' . $gateway_id, array('account_id' => $before), array('account_id' => $account_id, 'approved_by' => $approved['user_id']), 'success', null, $approved['user_id']);
            return self::finance_control_snapshot();
        }
        if ($op === 'default_bank') {
            $account_id = absint($data['account_id'] ?? 0);
            $type = $account_id ? $wpdb->get_var($wpdb->prepare("SELECT account_type FROM " . self::table('financial_accounts') . " WHERE id=%d AND is_active=1", $account_id)) : '';
            if ($type !== 'bank') { return new WP_Error('wsm_finance_bank_invalid', 'حساب پیش‌فرض باید حساب بانکی فعال باشد.', array('status' => 400)); }
            $before = (int) $config['default_bank_account_id'];
            $config['default_bank_account_id'] = $account_id;
            update_option('wsm_finance_control_config', $config, false);
            WSM_Activity_Log::log('update', 'finance_control', $account_id, 'حساب بانکی پیش‌فرض', array('account_id' => $before), array('account_id' => $account_id, 'approved_by' => $approved['user_id']), 'success', null, $approved['user_id']);
            return self::finance_control_snapshot();
        }
        return new WP_Error('wsm_finance_control_unknown', 'تنظیم کنترل مالی ناشناخته است.', array('status' => 400));
    }

    private static function settle_gateway($data) {
        global $wpdb;
        $approved = self::verify_finance_manager($data, 'gateway_settlement');
        if (is_wp_error($approved)) { return $approved; }
        if (empty($data['settlement_confirmed']) || !rest_sanitize_boolean($data['settlement_confirmed'])) { return new WP_Error('wsm_gateway_settlement_unconfirmed', 'فقط پس از تأیید واقعی واریز درگاه/بانک می‌توان تسویه را ثبت کرد.', array('status' => 400)); }
        $gateway_id = isset($data['gateway_id']) && is_scalar($data['gateway_id']) ? sanitize_key((string) $data['gateway_id']) : '';
        $gateway_title = '';
        foreach (self::finance_gateways() as $gateway) { if ($gateway['id'] === $gateway_id) { $gateway_title = (string) $gateway['title']; break; } }
        if ($gateway_id === '' || $gateway_title === '') { return new WP_Error('wsm_gateway_settlement_gateway', 'درگاه پرداخت معتبر پیدا نشد.', array('status' => 400)); }
        $gross = self::amount($data['gross_amount'] ?? null, 'مبلغ ناخالص تسویه', false);
        $net = self::amount($data['bank_received_amount'] ?? null, 'مبلغ واریزی بانک', true);
        $fee = array_key_exists('fee_amount', $data) && $data['fee_amount'] !== '' ? self::amount($data['fee_amount'], 'کارمزد درگاه', true) : '0';
        if (is_wp_error($gross)) { return $gross; } if (is_wp_error($net)) { return $net; } if (is_wp_error($fee)) { return $fee; }
        $gross_units = self::decimal_units($gross); $net_units = self::decimal_units($net); $fee_units = self::decimal_units($fee);
        if ($gross_units === null || $net_units === null || $fee_units === null || $gross_units !== $net_units + $fee_units || $net_units <= 0) { return new WP_Error('wsm_gateway_settlement_amounts', 'مبلغ ناخالص باید دقیقاً برابر مبلغ واریزی بانک به‌علاوهٔ کارمزد باشد؛ مبلغ واریزی باید بزرگ‌تر از صفر باشد.', array('status' => 400)); }
        $bank_id = absint($data['bank_account_id'] ?? 0);
        $reference = self::text($data['reference'] ?? '', 120);
        if ($reference === '') { return new WP_Error('wsm_gateway_settlement_reference', 'شمارهٔ تسویه/پیگیری درگاه الزامی است.', array('status' => 400)); }
        $clearing_id = self::gateway_clearing_account($gateway_id, $gateway_title);
        if (!$clearing_id) { return new WP_Error('wsm_gateway_clearing_missing', 'حساب واسط این درگاه فعال نیست.', array('status' => 409)); }
        $account_table = self::table('financial_accounts');
        $clearing_type = $wpdb->get_var($wpdb->prepare("SELECT account_type FROM $account_table WHERE id=%d AND is_active=1", $clearing_id));
        $bank_type = $bank_id ? $wpdb->get_var($wpdb->prepare("SELECT account_type FROM $account_table WHERE id=%d AND is_active=1", $bank_id)) : '';
        if ($clearing_type !== 'virtual' || $bank_type !== 'bank' || $clearing_id === $bank_id) { return new WP_Error('wsm_gateway_settlement_accounts', 'حساب واسط مجازی درگاه و حساب بانکی مقصد فعال و متفاوت لازم است.', array('status' => 400)); }
        $reference_key = 'gateway-settlement:' . $gateway_id . ':' . substr(hash('sha256', $reference), 0, 40);
        $ledger = self::table('financial_ledger');
        $already = $wpdb->get_row($wpdb->prepare("SELECT id,kind,amount,account_id FROM $ledger WHERE source='gateway_settlement' AND reference_id=%s AND status='posted' ORDER BY id ASC LIMIT 1", $reference_key), ARRAY_A);
        if ($already) {
            if ((string) $already['amount'] === (string) $net && (int) $already['account_id'] === $clearing_id) { return array('idempotent_replay' => true, 'reference_id' => $reference_key, 'transfer_id' => (int) $already['id']); }
            return new WP_Error('wsm_gateway_settlement_duplicate', 'این شمارهٔ تسویه قبلاً با مبلغ دیگری ثبت شده است.', array('status' => 409));
        }
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('wsm_gateway_settlement_transaction', 'آغاز ثبت تسویهٔ درگاه انجام نشد.', array('status' => 500)); }
        $locked = $wpdb->get_results($wpdb->prepare("SELECT id,current_balance,account_type FROM $account_table WHERE id IN (%d,%d) AND is_active=1 ORDER BY id FOR UPDATE", $clearing_id, $bank_id), ARRAY_A);
        $types = array(); $balances = array(); foreach ((array) $locked as $row) { $types[(int) $row['id']] = (string) $row['account_type']; $balances[(int) $row['id']] = (string) $row['current_balance']; }
        if (($types[$clearing_id] ?? '') !== 'virtual' || ($types[$bank_id] ?? '') !== 'bank') { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_gateway_settlement_accounts', 'حساب تسویه هنگام ثبت تغییر کرده یا غیرفعال شده است.', array('status' => 409)); }
        $balance_units = self::decimal_units($balances[$clearing_id] ?? null);
        if ($balance_units === null || $balance_units < $gross_units) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_gateway_settlement_balance', 'موجودی حساب واسط برای مبلغ تسویهٔ ناخالص کافی نیست.', array('status' => 409)); }
        $transfer = self::transfer_accounts_locked($clearing_id, $bank_id, $net, 'gateway_settlement', $reference_key, 'تسویهٔ واقعی درگاه ' . $gateway_title . ' · پیگیری ' . $reference, 0);
        if (is_wp_error($transfer)) { $wpdb->query('ROLLBACK'); return $transfer; }
        $fee_id = 0;
        if ($fee_units > 0) {
            $fee_id = self::add_finance('expense', $fee, 'کارمزد تسویهٔ درگاه ' . $gateway_title, array('account_id' => $clearing_id, 'account_delta' => self::decimal_string(-$fee_units), 'transaction_type' => 'expense', 'source' => 'gateway_fee', 'method' => 'online', 'reference' => $reference, 'reference_id' => $reference_key . ':fee', 'description' => 'کارمزد کسرشده در تسویهٔ واقعی درگاه.'));
            if (is_wp_error($fee_id)) { $wpdb->query('ROLLBACK'); return $fee_id; }
        }
        $wpdb->query('COMMIT');
        WSM_Activity_Log::log('create', 'gateway_settlement', (int) $transfer['in_id'], 'تسویهٔ واقعی درگاه ' . $gateway_title, '', array('gateway_id' => $gateway_id, 'gross_amount' => $gross, 'bank_received_amount' => $net, 'fee_amount' => $fee, 'bank_account_id' => $bank_id, 'external_reference' => $reference, 'transfer_reference' => $transfer['reference_id'], 'fee_ledger_id' => (int) $fee_id, 'approved_by_user_id' => (int) $approved['user_id']), 'success', null, (int) $approved['user_id']);
        return array('gateway_id' => $gateway_id, 'gross_amount' => $gross, 'bank_received_amount' => $net, 'fee_amount' => $fee, 'bank_account_id' => $bank_id, 'reference' => $reference, 'transfer_reference' => $transfer['reference_id'], 'fee_ledger_id' => (int) $fee_id);
    }

    /** WooCommerce refund + immutable finance outflow, with special liability handling for club-credit POS sales. */
    public static function refund_order($order_id, $data) {
        global $wpdb;
        $order_id = absint($order_id);
        if (!is_array($data)) { return new WP_Error('wsm_refund_invalid', 'اطلاعات بازپرداخت معتبر نیست.', array('status' => 400)); }
        $approved = self::verify_finance_manager($data, 'order_refund');
        if (is_wp_error($approved)) { return $approved; }
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order || $order->get_meta('_wsm_invoice')) { return new WP_Error('wsm_not_found', 'سفارش پیدا نشد.', array('status' => 404)); }
        if ($order->get_meta('_wsm_credit_sale')) { return new WP_Error('wsm_credit_refund_blocked', 'بازپرداخت فروش اعتباری باید با عملیات حساب مشتری هماهنگ شود.', array('status' => 409)); }
        if ($order->get_meta('_wsm_club_pos_debt')) { return new WP_Error('wsm_pos_debt_refund_blocked', 'این فاکتور فروش نسیه است؛ ابتدا ماندهٔ بدهی و پرداخت‌های واقعی آن را بررسی کنید. بازپرداخت نقدی خودکار مجاز نیست.', array('status' => 409)); }
        $amount = self::amount($data['amount'] ?? null, 'مبلغ بازپرداخت', false);
        if (is_wp_error($amount)) { return $amount; }
        $amount_float = (float) $amount;
        if ($amount_float > (float) $order->get_remaining_refund_amount()) { return new WP_Error('wsm_refund_amount', 'مبلغ بازپرداخت از ماندهٔ قابل‌بازپرداخت بیشتر است.', array('status' => 400)); }
        $reason = self::text($data['reason'] ?? '', 200);
        if ($reason === '') { return new WP_Error('wsm_refund_reason_required', 'دلیل بازپرداخت الزامی است.', array('status' => 400)); }
        if (!rest_sanitize_boolean($data['refund_confirmed'] ?? false)) { return new WP_Error('wsm_refund_unconfirmed', 'پیش از ثبت، اجرای واقعی بازپرداخت را تأیید کنید. بازپرداخت بانکی/درگاهی باید بیرون از این فرم انجام شود.', array('status' => 400)); }
        $is_club_credit = (int) $order->get_meta('_wsm_club_pos') === 1 && (string) $order->get_meta('_wsm_club_pos_payment_method') === 'club_credit';
        $ledger = self::table('financial_ledger');
        $account_id = 0; $sale_ledger_id = 0; $account_type = '';
        if (!$is_club_credit) {
            $sale = $wpdb->get_row($wpdb->prepare("SELECT f.id,f.account_id,a.account_type,a.current_balance FROM $ledger f LEFT JOIN " . self::table('financial_accounts') . " a ON a.id=f.account_id WHERE f.order_id=%d AND f.kind='pos_sale' AND f.status='posted' ORDER BY f.id ASC LIMIT 1", $order_id), ARRAY_A);
            $sale_ledger_id = is_array($sale) ? (int) $sale['id'] : 0;
            $preferred_id = absint($data['refund_account_id'] ?? 0);
            $preferred = $preferred_id ? $wpdb->get_row($wpdb->prepare("SELECT id,account_type,current_balance FROM " . self::table('financial_accounts') . " WHERE id=%d AND is_active=1", $preferred_id), ARRAY_A) : null;
            $preferred_units = is_array($preferred) ? self::decimal_units($preferred['current_balance']) : null;
            if ($preferred && in_array((string) $preferred['account_type'], array('cash','bank','pos','virtual'), true) && $preferred_units !== null && $preferred_units >= self::decimal_units($amount)) {
                $account_id = (int) $preferred['id']; $account_type = (string) $preferred['account_type'];
            } else {
                $sale_units = is_array($sale) ? self::decimal_units($sale['current_balance']) : null;
                if (!empty($sale['account_id']) && in_array((string) ($sale['account_type'] ?? ''), array('cash','bank','pos','virtual'), true) && $sale_units !== null && $sale_units >= self::decimal_units($amount)) {
                    $account_id = (int) $sale['account_id']; $account_type = (string) $sale['account_type'];
                } else {
                    $config = self::finance_control_config();
                    $bank_id = absint($config['default_bank_account_id'] ?: self::finance_account_id_by_key('bank_account_1'));
                    $bank = $bank_id ? $wpdb->get_row($wpdb->prepare("SELECT id,account_type,current_balance FROM " . self::table('financial_accounts') . " WHERE id=%d AND is_active=1", $bank_id), ARRAY_A) : null;
                    $bank_units = is_array($bank) ? self::decimal_units($bank['current_balance']) : null;
                    if (!$bank || ($bank['account_type'] ?? '') !== 'bank' || $bank_units === null || $bank_units < self::decimal_units($amount)) {
                        if (!$sale_ledger_id) {
                            return new WP_Error('wsm_refund_account_balance', 'برای این سفارش در دفتر مالی ردیف فروش ثبت نشده است؛ این وضعیت معمولاً برای فروش‌های ثبت‌شده پیش از اصلاح رخ می‌دهد. یک حساب بازپرداخت فعال و دارای موجودی واقعی را انتخاب کنید. موجودی را فقط پس از تطبیق با وجه واقعی ثبت کنید.', array('status' => 409, 'needs_account' => true, 'sale_ledger_missing' => true));
                        }
                        return new WP_Error('wsm_refund_account_balance', 'حساب مبدأ فروش یا حساب انتخابی برای بازپرداخت موجودی کافی ندارد؛ یک حساب بازپرداخت فعال و دارای موجودی واقعی انتخاب کنید.', array('status' => 409, 'needs_account' => true));
                    }
                    $account_id = (int) $bank['id']; $account_type = 'bank';
                }
            }
        }
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('wsm_refund_transaction', 'آغاز ثبت بازپرداخت انجام نشد.', array('status' => 500)); }
        if ($is_club_credit) {
            $member_id = absint($order->get_meta('_wsm_club_member_id'));
            if (!$member_id) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_credit_refund_member_missing', 'عضو مرتبط با اعتبار این فروش پیدا نشد.', array('status' => 409)); }
            $member = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . WSM_Club::table('members') . ' WHERE id=%d FOR UPDATE', $member_id));
            if (!$member) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_credit_refund_member_missing', 'عضو مرتبط با اعتبار این فروش پیدا نشد.', array('status' => 409)); }
        } else {
            $account = $wpdb->get_row($wpdb->prepare("SELECT id,account_type,current_balance FROM " . self::table('financial_accounts') . " WHERE id=%d AND is_active=1 FOR UPDATE", $account_id), ARRAY_A);
            $balance_units = is_array($account) ? self::decimal_units($account['current_balance']) : null;
            if (!$account || !in_array((string) $account['account_type'], array('cash','bank','pos','virtual'), true) || $balance_units === null || $balance_units < self::decimal_units($amount)) {
                $wpdb->query('ROLLBACK');
                $account_error_data = array('status' => 409, 'needs_account' => true);
                if (!$sale_ledger_id) { $account_error_data['sale_ledger_missing'] = true; }
                return new WP_Error('wsm_refund_account_balance', 'حساب انتخاب‌شده برای بازپرداخت هنگام ثبت غیرفعال شد یا دیگر موجودی کافی ندارد؛ یک حساب بازپرداخت فعال و دارای موجودی واقعی انتخاب کنید.', $account_error_data);
            }
        }
        $gateway_supports_refunds = false;
        if (!$is_club_credit && function_exists('WC') && WC() && method_exists(WC(), 'payment_gateways')) {
            $gateway_id = (string) $order->get_payment_method();
            $gateway = WC()->payment_gateways()->payment_gateways()[$gateway_id] ?? null;
            $gateway_supports_refunds = $gateway && method_exists($gateway, 'supports') && $gateway->supports('refunds');
        }
        $refund = wc_create_refund(array('amount' => $amount_float, 'reason' => $reason, 'order_id' => $order_id, 'refund_payment' => false, 'restock_items' => false));
        if (is_wp_error($refund) || !$refund) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_refund_failed', is_wp_error($refund) ? $refund->get_error_message() : 'بازپرداخت ووکامرس انجام نشد.', array('status' => 500)); }
        $refund_id = is_object($refund) && method_exists($refund, 'get_id') ? (int) $refund->get_id() : 0;
        if ($is_club_credit) {
            $updated = $wpdb->query($wpdb->prepare('UPDATE ' . WSM_Club::table('members') . ' SET card_balance=card_balance+%f,updated_at=%s WHERE id=%d', $amount_float, current_time('mysql'), $member_id));
            if ($updated !== 1) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_credit_refund_update_failed', 'بازگرداندن مبلغ به اعتبار کارت عضو انجام نشد.', array('status' => 500)); }
            $finance_id = self::add_finance('card_recharge', $amount, 'بازگشت اعتبار کارت بابت سفارش #' . $order->get_order_number(), array('member_id' => $member_id, 'order_id' => $order_id, 'account_id' => 0, 'no_account' => true, 'account_delta' => '0', 'transaction_type' => 'liability', 'source' => 'pos_credit_refund', 'method' => 'club_credit', 'reference' => (string) $refund_id, 'reference_id' => 'woo-refund:' . $refund_id, 'description' => $reason));
        } else {
            $finance_method = $account_type === 'cash' ? 'cash' : ($account_type === 'pos' ? 'card' : ($account_type === 'virtual' ? 'online' : 'bank_transfer'));
            $finance_id = self::add_finance('pos_refund', $amount, 'بازپرداخت سفارش #' . $order->get_order_number(), array('account_id' => $account_id, 'account_delta' => '-' . ltrim((string) $amount, '+'), 'order_id' => $order_id, 'parent_id' => $sale_ledger_id, 'transaction_type' => 'expense', 'source' => 'order_refund', 'method' => $finance_method, 'reference' => (string) $refund_id, 'reference_id' => 'woo-refund:' . $refund_id, 'description' => $reason));
        }
        if (is_wp_error($finance_id)) { $wpdb->query('ROLLBACK'); return $finance_id; }
        $wpdb->query('COMMIT');
        if (class_exists('WSM_Reports') && is_callable(array('WSM_Reports', 'flush_sale_caches'))) {
            WSM_Reports::flush_sale_caches();
        }
        WSM_Activity_Log::log('refund', 'order', $order_id, '#' . $order->get_order_number(), '', array('refund_id' => $refund_id, 'amount' => $amount, 'finance_id' => (int) $finance_id, 'account_id' => (int) $account_id, 'club_credit' => (bool) $is_club_credit, 'gateway_refund_requested' => false, 'gateway_refund_supported' => (bool) $gateway_supports_refunds, 'approved_by_user_id' => (int) $approved['user_id']), 'success', null, (int) $approved['user_id']);
        $updated_order = wc_get_order($order_id);
        return array('order' => class_exists('WSM_Orders') ? WSM_Orders::format($updated_order) : array('id' => $order_id), 'refund_id' => $refund_id, 'finance_id' => (int) $finance_id, 'gateway_refund_requested' => false, 'gateway_refund_supported' => (bool) $gateway_supports_refunds);
    }

    public static function financial_operation($data) {
        if(!is_array($data)){return new WP_Error('wsm_finance_operation','درخواست مالی نامعتبر است.',array('status'=>400));}
        $op=isset($data['operation'])&&is_scalar($data['operation'])?sanitize_key((string)$data['operation']):'';
        if($op==='cash_open'){return self::open_cash_session($data);}
        if($op==='cash_close'){return self::close_cash_session($data);}
        if($op==='transfer'){return self::transfer_accounts($data);}
        if($op==='gateway_settlement'){return self::settle_gateway($data);}
        if($op==='payable_create'){return self::create_payable($data);}
        if($op==='payable_pay'){return self::pay_payable($data);}
        if(in_array($op,array('account_create','device_map','gateway_map','default_bank'),true)){return self::finance_control_operation($op,$data);}
        if($op==='income'){
            $source=sanitize_key((string)($data['source']??'misc_income'));
            if($source==='card_charge'){return self::recharge_card($data);}
            $approved=self::verify_finance_manager($data,'income_create');if(is_wp_error($approved)){return $approved;}
            if(!rest_sanitize_boolean($data['receipt_confirmed']??false)){return new WP_Error('wsm_income_receipt_unconfirmed','دریافت فقط پس از تأیید دریافت واقعی وجه ثبت می‌شود.',array('status'=>400));}
            $title=self::text($data['title']??'',190);if($title===''){return new WP_Error('wsm_income_title','شرح دریافت الزامی است.',array('status'=>400));}
            $amount=self::amount($data['amount']??null,'مبلغ دریافتی');if(is_wp_error($amount)){return $amount;}
            $allowed=array('misc_income','cash_receipt','other_income');if(!in_array($source,$allowed,true)){$source='misc_income';}
            $id=self::add_finance('misc_income',$amount,$title,array('account_id'=>absint($data['account_id']??0),'category'=>$data['category']??'','method'=>$data['method']??'cash','source'=>$source,'transaction_type'=>'income','reference'=>$data['reference']??'','description'=>$data['description']??'','occurred_at'=>$data['occurred_at']??current_time('mysql')));
            if(is_wp_error($id)){return $id;}return self::finance_item($id);
        }
        return new WP_Error('wsm_finance_operation_unknown','عملیات مالی ناشناخته است.',array('status'=>400));
    }

    public static function register_finance_routes() { if(function_exists('register_rest_route')){register_rest_route('wsm/v1','/club/finance/operations',array('methods'=>'POST','callback'=>array(__CLASS__,'financial_operation_endpoint'),'permission_callback'=>array(__CLASS__,'debt_route_permission')));} }
    public static function financial_operation_endpoint($request) { $body=$request->get_json_params();if(!is_array($body)){$body=$request->get_body_params();}return self::financial_operation(is_array($body)?$body:array()); }

    public static function list_finance($args = array()) {
        global $wpdb;
        $core=self::financial_core_ready();if(is_wp_error($core)){return $core;}
        $args=is_array($args)?$args:array();
        $device_id=class_exists('WSM_Permissions')?WSM_Permissions::current_device_id():0;
        self::device_finance_accounts($device_id);
        if (isset($args['report']) && sanitize_key((string) $args['report']) === 'daily_pnl') {
            if (!class_exists('WSM_Reports') || !is_callable(array('WSM_Reports', 'normalize_report_date'))) {
                return new WP_Error('wsm_finance_report_date_service', 'سرویس تبدیل تاریخ گزارش در دسترس نیست.', array('status' => 503));
            }
            $report_today = current_time('Y-m-d');
            $default_from = (new DateTimeImmutable($report_today, wp_timezone()))->modify('-30 days')->format('Y-m-d');
            $from = WSM_Reports::normalize_report_date(isset($args['from']) ? $args['from'] : $default_from);
            $to = WSM_Reports::normalize_report_date(isset($args['to']) ? $args['to'] : $report_today);
            if ($from === false || $to === false) {
                return new WP_Error('wsm_finance_report_date_invalid', 'تاریخ گزارش باید شمسی یا میلادی معتبر باشد.', array('status' => 400));
            }
            if ($from === '') { $from = $default_from; }
            if ($to === '') { $to = $report_today; }
            if (!is_string($from) || !is_string($to) || !self::valid_range($from, $to)) {
                return new WP_Error('wsm_finance_report_date_invalid', 'بازهٔ تاریخ گزارش روزانه معتبر نیست.', array('status' => 400));
            }
            return self::report_daily_profit_loss($from, $to);
        }
        if (class_exists('WSM_Reports') && is_callable(array('WSM_Reports', 'normalize_report_date'))) {
            foreach (array('from', 'to') as $date_key) {
                if (!array_key_exists($date_key, $args)) { continue; }
                $normalized = is_scalar($args[$date_key]) ? WSM_Reports::normalize_report_date($args[$date_key]) : false;
                if ($normalized === false) {
                    return new WP_Error('wsm_finance_filter_date_invalid', 'تاریخ فیلتر دفتر مالی باید شمسی یا میلادی معتبر باشد.', array('status' => 400));
                }
                if ($normalized === '') { unset($args[$date_key]); }
                else { $args[$date_key] = $normalized; }
            }
            if (!empty($args['from']) && !empty($args['to']) && !self::valid_range((string) $args['from'], (string) $args['to'])) {
                return new WP_Error('wsm_finance_filter_date_invalid', 'بازهٔ تاریخ دفتر مالی معتبر نیست.', array('status' => 400));
            }
        }
        $actor=class_exists('WSM_Permissions')?WSM_Permissions::current_device():null;
        $can_manage=($actor&&class_exists('WSM_Devices')&&WSM_Devices::device_can($actor,'club.finance.manage'))||current_user_can('manage_options');
        $page=max(1,(int)($args['page']??1));$limit=max(1,min(200,(int)($args['per_page']??80)));$offset=($page-1)*$limit;$ledger=self::table('financial_ledger');$where=array('1=1');$params=array();
        $type=isset($args['type'])?sanitize_key((string)$args['type']):'';if($type!==''){$where[]='f.transaction_type=%s';$params[]=$type;}
        if(!empty($args['kind'])&&preg_match('/^[a-z_]{2,32}$/',(string)$args['kind'])){$where[]='f.kind=%s';$params[]=$args['kind'];}
        $method=isset($args['method'])?sanitize_key((string)$args['method']):(isset($args['payment_method'])?sanitize_key((string)$args['payment_method']):'');if($method!==''){$where[]='f.payment_method=%s';$params[]=$method;}
        if(!empty($args['account_id'])){$where[]='f.account_id=%d';$params[]=absint($args['account_id']);}
        if(!empty($args['account_type'])&&in_array($args['account_type'],array('cash','bank','pos','virtual'),true)){$where[]='f.account_id IN (SELECT id FROM '.self::table('financial_accounts').' WHERE account_type=%s AND is_active=1)';$params[]=$args['account_type'];}
        if(!empty($args['status'])){$status=sanitize_key((string)$args['status']);if(in_array($status,array('posted','void'),true)){$where[]='f.status=%s';$params[]=$status;}elseif(in_array($status,array('confirmed','pending','cancelled','partial','settled'),true)){$where[]='f.ledger_status=%s';$params[]=$status;}}
        if(!empty($args['from'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$args['from'])){$where[]='f.transaction_date >= %s';$params[]=$args['from'].' 00:00:00';}if(!empty($args['to'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$args['to'])){$where[]='f.transaction_date <= %s';$params[]=$args['to'].' 23:59:59';}
        if(!empty($args['search'])&&is_scalar($args['search'])){$like='%'.$wpdb->esc_like(sanitize_text_field((string)$args['search'])).'%';$where[]='(f.title LIKE %s OR f.description LIKE %s OR f.reference_id LIKE %s OR f.reference LIKE %s)';array_push($params,$like,$like,$like,$like);}
        $where_sql=implode(' AND ',$where);$base=" FROM $ledger f WHERE $where_sql";$count_raw=$wpdb->get_var($params?$wpdb->prepare('SELECT COUNT(*)'.$base,$params):'SELECT COUNT(*)'.$base);if($wpdb->last_error!==''){return new WP_Error('wsm_finance_read_failed','خواندن دفتر مالی از پایگاه‌داده انجام نشد.',array('status'=>500));}$count=(int)$count_raw;
        $sort=isset($args['sort'])&&$args['sort']==='amount'?'f.amount':'f.transaction_date';$direction=isset($args['direction'])&&strtolower((string)$args['direction'])==='asc'?'ASC':'DESC';$sql="SELECT f.*,a.account_name,a.account_type,u.display_name AS created_by_name FROM $ledger f LEFT JOIN ".self::table('financial_accounts')." a ON a.id=f.account_id LEFT JOIN {$wpdb->users} u ON u.ID=f.created_by WHERE $where_sql ORDER BY $sort $direction,f.id DESC LIMIT %d OFFSET %d";$rows=$wpdb->get_results($wpdb->prepare($sql,array_merge($params,array($limit,$offset))),ARRAY_A);if($wpdb->last_error!==''){return new WP_Error('wsm_finance_read_failed','خواندن ردیف‌های دفتر مالی انجام نشد.',array('status'=>500));}
        $summary_sql="SELECT CAST(COALESCE(SUM(CASE WHEN f.status='posted' AND f.transaction_type='income' THEN f.amount ELSE 0 END),0) AS CHAR) AS income,CAST(COALESCE(SUM(CASE WHEN f.status='posted' AND f.transaction_type='expense' AND f.kind<>'cash_removal' THEN f.amount ELSE 0 END),0) AS CHAR) AS expense,CAST(COALESCE(SUM(CASE WHEN f.status='posted' AND f.kind='card_spend' THEN f.amount ELSE 0 END),0) AS CHAR) AS card_sales,CAST(COALESCE(SUM(CASE WHEN f.status='posted' AND f.kind='discount' THEN f.amount ELSE 0 END),0) AS CHAR) AS discounts,CAST(COALESCE(SUM(CASE WHEN f.status='void' THEN f.amount ELSE 0 END),0) AS CHAR) AS voided,CAST(COALESCE(SUM(CASE WHEN f.status='posted' AND f.transaction_type='income' THEN f.amount WHEN f.status='posted' AND f.transaction_type='expense' AND f.kind<>'cash_removal' THEN -f.amount ELSE 0 END),0) AS CHAR) AS balance $base";$summary=$wpdb->get_row($params?$wpdb->prepare($summary_sql,$params):$summary_sql,ARRAY_A);if($wpdb->last_error!==''){return new WP_Error('wsm_finance_summary_failed','محاسبهٔ جمع‌های دفتر مالی انجام نشد.',array('status'=>500));}$summary=is_array($summary)?$summary:array();
        $acc=$wpdb->get_row("SELECT CAST(COALESCE(SUM(CASE WHEN account_type='cash' THEN current_balance ELSE 0 END),0) AS CHAR) AS cash,CAST(COALESCE(SUM(CASE WHEN account_type='bank' THEN current_balance ELSE 0 END),0) AS CHAR) AS bank,CAST(COALESCE(SUM(CASE WHEN account_type='pos' THEN current_balance ELSE 0 END),0) AS CHAR) AS pos,CAST(COALESCE(SUM(CASE WHEN account_type='virtual' THEN current_balance ELSE 0 END),0) AS CHAR) AS virtual_balance,CAST(COALESCE(SUM(CASE WHEN account_type IN ('cash','bank','pos') THEN current_balance ELSE 0 END),0) AS CHAR) AS liquidity FROM ".self::table('financial_accounts')." WHERE is_active=1",ARRAY_A);if($wpdb->last_error!==''){return new WP_Error('wsm_finance_accounts_failed','خواندن ماندهٔ حساب‌های مالی انجام نشد.',array('status'=>500));}
        $receivables=self::sync_receivables_projection();if(is_wp_error($receivables)){$receivables=$wpdb->get_row("SELECT CAST(COALESCE(SUM(total_debt),0) AS CHAR) AS total_debt,CAST(COALESCE(SUM(paid_amount),0) AS CHAR) AS paid_amount,CAST(COALESCE(SUM(remaining),0) AS CHAR) AS remaining,COUNT(CASE WHEN remaining>0 THEN 1 END) AS open_count FROM ".self::table('receivables'),ARRAY_A);}
        $payables=self::list_payables(100);$today=current_time('Y-m-d');
        $summary['cash']=$acc['cash']??'0';$summary['bank']=$acc['bank']??'0';$summary['pos']=$acc['pos']??'0';$summary['virtual']=$acc['virtual_balance']??'0';$summary['liquidity']=$acc['liquidity']??'0';$summary['receivables']=$receivables['remaining']??'0';$summary['payables']=$payables['summary']['remaining']??'0';
        $gateway_account_ids=array_values(array_unique(array_filter(array_map('absint',array_values(self::finance_control_config()['gateway_map'])))));
        if($gateway_account_ids){$placeholders=implode(',',array_fill(0,count($gateway_account_ids),'%d'));$summary['online_clearing']=(string)$wpdb->get_var($wpdb->prepare("SELECT CAST(COALESCE(SUM(current_balance),0) AS CHAR) FROM ".self::table('financial_accounts')." WHERE account_type='virtual' AND is_active=1 AND id IN ($placeholders)",$gateway_account_ids));}else{$summary['online_clearing']='0';}
        $sessions=self::list_cash_sessions(30);$open_session_ids=array();$open_cash_ids=array();
        foreach($sessions as $session_row){if(($session_row['status']??'')==='open'){$open_session_ids[(int)$session_row['id']]=true;$open_cash_ids[(int)$session_row['cash_account_id']]=true;}}
        if(!$can_manage){
            foreach((array)$rows as &$finance_row){if(!empty($finance_row['cash_session_id'])&&isset($open_session_ids[(int)$finance_row['cash_session_id']])&&($finance_row['account_type']??'')==='cash'){$finance_row['amount']='—';$finance_row['account_delta']='—';$finance_row['blind_count_redacted']=true;}}
            unset($finance_row);
        }
        $accounts=self::list_financial_accounts();
        if(!$can_manage){foreach($accounts as &$account_row){if(isset($open_cash_ids[(int)$account_row['id']])&&$account_row['account_type']==='cash'){$account_row['current_balance']='—';$account_row['balance_hidden']=true;}}unset($account_row);if($open_cash_ids){$summary['cash']='—';}}
        $reconciliation=self::daily_reconciliation($args['from']??$today,$args['to']??$today);
        if(!$can_manage){foreach($reconciliation as &$rec){if(isset($open_cash_ids[(int)$rec['id']])&&$rec['account_type']==='cash'){foreach(array('opening_balance','receipts','outflows','expenses','expected_closing') as $key){$rec[$key]='—';}$rec['blind_count_redacted']=true;}}unset($rec);}
        return array('items'=>(array)$rows,'total'=>$count,'page'=>$page,'summary'=>$summary,'accounts'=>$accounts,'cash_session'=>self::current_cash_session(0,$device_id),'cash_sessions'=>$sessions,'finance_controls'=>self::finance_control_snapshot(),'daily_reconciliation'=>$reconciliation,'receivables'=>$receivables,'payables'=>$payables);
    }

    public static function void_finance($id, $data) {
        global $wpdb;
        if (!is_array($data)) { return new WP_Error('wsm_club_void_invalid','دلیل ابطال را وارد کنید.',array('status'=>400)); }
        $reason=self::text(isset($data['reason'])?$data['reason']:'',255); if ($reason==='') { return new WP_Error('wsm_club_void_reason','برای ابطال، دلیل الزامی است.',array('status'=>400)); }
        $approved=self::verify_finance_manager($data,'finance_void');if(is_wp_error($approved)){return $approved;}
        $wpdb->query('START TRANSACTION');
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('finance').' WHERE id=%d FOR UPDATE',(int)$id));
        if (!$row) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_finance_missing','گردش مالی پیدا نشد.',array('status'=>404)); }
        if ($row->status==='void') { $wpdb->query('COMMIT'); return array('id'=>(int)$id,'status'=>'void'); }
        if (in_array((string)$row->kind,array('card_spend','pos_sale','pos_refund','payroll','pos_receivable','receivable','discount','supplier_payable','supplier_payment'),true) ||
            (isset($row->source) && (string)$row->source==='debt_settlement') ||
            (isset($row->category) && (string)$row->category==='pos_debt_settlement')) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_club_finance_requires_source_action','این ردیف به عضویت، دریافتنی، تسویهٔ تخصیص‌دار، تأمین‌کننده یا منبع عملیاتی دیگری متصل است؛ ابطال مستقیم ممکن است ماندهٔ منبع را نادرست کند و انجام نشد.',array('status'=>409));
        }
        if ($row->kind==='card_recharge' && $row->member_id) {
            $balance=$wpdb->get_var($wpdb->prepare('SELECT card_balance FROM '.WSM_Club::table('members').' WHERE id=%d FOR UPDATE',(int)$row->member_id));
            $balance_units=self::decimal_units($balance===null?'0':$balance); $charge_units=self::decimal_units($row->amount);
            if ($balance_units===null||$charge_units===null||$balance_units<$charge_units) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_card_used','شارژ به‌طور کامل در کارت خرج شده و ابطال آن مجاز نیست.',array('status'=>409)); }
        }
        if ($row->payment_id) {
            $payment=$wpdb->get_row($wpdb->prepare('SELECT id,status FROM '.WSM_Club::table('payments').' WHERE id=%d FOR UPDATE',(int)$row->payment_id));
            if (!$payment || $payment->status!=='paid') { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_payment_void_conflict','وضعیت دریافت شهریه تغییر کرده است؛ صفحه را تازه‌سازی کنید.',array('status'=>409)); }
        }
        $linked_rows=$wpdb->get_results($wpdb->prepare('SELECT id,account_id,account_delta FROM '.self::table('financial_ledger').' WHERE (id=%d OR parent_id=%d) AND status=\'posted\' FOR UPDATE',(int)$id,(int)$id));
        foreach((array)$linked_rows as $linked){
            $linked_units=self::decimal_units($linked->account_delta);
            if($linked->account_id&&$linked_units!==null&&$linked_units!==0){
                $reversed=self::decimal_string(-$linked_units);
                $reversal_ok=$wpdb->query($wpdb->prepare('UPDATE '.self::table('financial_accounts').' SET current_balance=current_balance+CAST(%s AS DECIMAL(20,6)),updated_at=%s WHERE id=%d',$reversed,current_time('mysql'),(int)$linked->account_id));
                if($reversal_ok!==1){$wpdb->query('ROLLBACK');return new WP_Error('wsm_club_finance_account_update','برگشت ماندهٔ حساب انجام نشد؛ ابطال انجام نشد.',array('status'=>500));}
            }
        }
        $ok=$wpdb->query($wpdb->prepare("UPDATE ".self::table('financial_ledger')." SET status='void',ledger_status='cancelled',void_reason=%s,voided_by=%d,voided_at=%s WHERE (id=%d OR parent_id=%d) AND status='posted'",$reason,get_current_user_id(),current_time('mysql'),(int)$id,(int)$id));
        if ($ok<1) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_void_conflict','گردش مالی هم‌زمان تغییر کرده است؛ صفحه را تازه‌سازی کنید.',array('status'=>409)); }
        if ($row->kind==='card_recharge' && $row->member_id) {
            $changed=$wpdb->query($wpdb->prepare('UPDATE '.WSM_Club::table('members').' SET card_balance=card_balance-CAST(%s AS DECIMAL(20,6)),updated_at=%s WHERE id=%d AND card_balance >= CAST(%s AS DECIMAL(20,6))',(string)$row->amount,current_time('mysql'),(int)$row->member_id,(string)$row->amount));
            if (!$changed) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_card_used','اعتبار کارت هم‌زمان تغییر کرده؛ ابطال انجام نشد.',array('status'=>409)); }
        }
        if ($row->payment_id) {
            $changed=$wpdb->update(WSM_Club::table('payments'),array('status'=>'void'),array('id'=>(int)$row->payment_id,'status'=>'paid'));
            if ($changed!==1) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_payment_void_conflict','ابطال دریافت شهریه انجام نشد؛ صفحه را تازه‌سازی کنید.',array('status'=>409)); }
        }
        $wpdb->query('COMMIT');
        if ((string)$row->kind === 'member_payment') {
            $projection = self::sync_receivables_projection();
            if (is_wp_error($projection)) { error_log('[WSM Club] Membership receipt was voided, but the receivables projection refresh was deferred: ' . $projection->get_error_code()); }
        }
        WSM_Activity_Log::log('update','club_finance',(int)$id,$row->title,array('status'=>'posted','amount'=>(string)$row->amount,'account_id'=>(int)$row->account_id,'account_delta'=>(string)$row->account_delta),array('status'=>'void','reason'=>$reason,'approved_by'=>$approved['user_id']),'success',null,$approved['user_id']);
        return self::finance_item($id);
    }

    public static function freeze_membership($id, $data) {
        global $wpdb;
        if (!is_array($data)) { $data=array(); }
        $id = absint($id);
        if (!$id) { return new WP_Error('wsm_club_membership_missing','عضویت پیدا نشد.',array('status'=>404)); }
        $action=isset($data['action'])&&$data['action']==='unfreeze'?'unfreeze':'freeze';
        if ($action === 'unfreeze') {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.WSM_Club::table('memberships').' WHERE id=%d',(int)$id));
            if (!$row) { return new WP_Error('wsm_club_membership_missing','عضویت پیدا نشد.',array('status'=>404)); }
            if ($row->status !== 'frozen') { return new WP_Error('wsm_club_membership_not_frozen','این عضویت فریز نیست.',array('status'=>409)); }
            $resumed = self::resume_membership($row);
            if (is_wp_error($resumed)) { return $resumed; }
        } else {
            $member_id=absint($wpdb->get_var($wpdb->prepare('SELECT member_id FROM '.WSM_Club::table('memberships').' WHERE id=%d',(int)$id)));
            if(!$member_id){return new WP_Error('wsm_club_membership_missing','عضویت پیدا نشد.',array('status'=>404));}
            if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('wsm_club_freeze_transaction','آغاز تراکنش فریز عضویت انجام نشد.',array('status'=>500)); }
            $wpdb->last_error = '';
            $locked_member=$wpdb->get_row($wpdb->prepare('SELECT id FROM '.WSM_Club::table('members').' WHERE id=%d FOR UPDATE',$member_id));
            if (!empty($wpdb->last_error)) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_freeze_member_lock','قفل‌کردن پروندهٔ ورزشکار برای فریز انجام نشد.',array('status'=>500)); }
            if (!$locked_member) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_member_missing','پروندهٔ ورزشکار برای فریز پیدا نشد.',array('status'=>404)); }
            $wpdb->last_error = '';
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.WSM_Club::table('memberships').' WHERE id=%d FOR UPDATE',(int)$id));
            if (!empty($wpdb->last_error)) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_freeze_lookup','قفل‌کردن عضویت برای فریز انجام نشد.',array('status'=>500)); }
            if (!$row) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_membership_missing','عضویت پیدا نشد.',array('status'=>404)); }
            if ((int)$row->member_id!==$member_id) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_freeze_member_conflict','پیوند عضو عضویت هنگام فریز تغییر کرده است.',array('status'=>409)); }
            if (!in_array($row->status,array('active','scheduled'),true) || ($row->status==='active' && (string)$row->end_date < current_time('Y-m-d'))) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('wsm_club_membership_freeze_status','فقط عضویت فعالِ منقضی‌نشده یا آینده را می‌توان فریز کرد.',array('status'=>409));
            }
            $days=isset($data['days'])&&is_numeric($data['days'])&&(float)$data['days']===(float)(int)$data['days']?(int)$data['days']:0;
            if ($days<1||$days>365) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_freeze_days','مدت فریز باید ۱ تا ۳۶۵ روز باشد.',array('status'=>400)); }
            $now = current_time('mysql');
            $until=(new DateTimeImmutable(current_time('Y-m-d'),wp_timezone()))->modify('+'.$days.' days')->format('Y-m-d');
            $ok=$wpdb->update(WSM_Club::table('memberships'),array('resume_status'=>$row->status,'status'=>'frozen','frozen_at'=>$now,'freeze_until'=>$until,'updated_at'=>$now),array('id'=>$id,'status'=>(string)$row->status));
            if ($ok!==1) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_freeze_failed','فریز عضویت انجام نشد یا وضعیت عضویت هم‌زمان تغییر کرد.',array('status'=>500)); }
            $wpdb->query('COMMIT');
        }
        WSM_Activity_Log::log('update','club_membership',(int)$id,'عضویت #'.(int)$id,'',$action,'success');
        return WSM_Club::list_memberships(array('member_id'=>(int)$row->member_id,'per_page'=>100));
    }

    public static function cancel_membership($id, $data) {
        global $wpdb;
        if (!is_array($data)) { $data=array(); }
        $reason=self::text(isset($data['reason'])?$data['reason']:'',255);
        if ($reason==='') { return new WP_Error('wsm_club_cancel_reason','برای لغو ثبت‌نام، دلیل الزامی است.',array('status'=>400)); }
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WSM_Club::table('memberships').' WHERE id=%d',(int)$id));
        if (!$row) { return new WP_Error('wsm_club_membership_missing','عضویت پیدا نشد.',array('status'=>404)); }
        if ($row->status==='cancelled') { return array('id'=>(int)$id,'status'=>'cancelled'); }
        $ok=$wpdb->update(WSM_Club::table('memberships'),array('status'=>'cancelled','frozen_at'=>null,'freeze_until'=>null,'resume_status'=>'','updated_at'=>current_time('mysql')),array('id'=>(int)$id));
        if ($ok===false) { return new WP_Error('wsm_club_cancel_failed','لغو ثبت‌نام انجام نشد.',array('status'=>500)); }
        if (method_exists(__CLASS__, 'refresh_receivables_projection')) {
            $projection = self::refresh_receivables_projection();
            if (is_wp_error($projection)) { error_log('[WSM Club] Membership cancellation saved, but receivables projection refresh was deferred.'); }
        }
        WSM_Club::invalidate_overview_cache();
        WSM_Activity_Log::log('update','club_membership',(int)$id,'ثبت‌نام #'.(int)$id,array('status'=>$row->status),array('status'=>'cancelled','reason'=>$reason),'success');
        $member = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.WSM_Club::table('members').' WHERE id=%d',(int)$row->member_id));
        if ($member) { self::trigger_sms_event('membership_cancelled',$member,array('plan_name'=>(string)$row->plan_name,'end_date'=>(string)$row->end_date,'reason'=>$reason)); }
        return array('id'=>(int)$id,'member_id'=>(int)$row->member_id,'status'=>'cancelled','reason'=>$reason);
    }

    private static function resume_membership($row) {
        global $wpdb;
        $id = is_object($row) && isset($row->id) ? absint($row->id) : absint($row);
        if (!$id) { return new WP_Error('wsm_club_membership_missing','عضویت پیدا نشد.',array('status'=>404)); }
        $member_id=is_object($row)&&isset($row->member_id)?absint($row->member_id):absint($wpdb->get_var($wpdb->prepare('SELECT member_id FROM '.WSM_Club::table('memberships').' WHERE id=%d',$id)));
        if(!$member_id){return new WP_Error('wsm_club_membership_missing','عضویت پیدا نشد.',array('status'=>404));}
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('wsm_club_resume_transaction','آغاز تراکنش رفع فریز انجام نشد.',array('status'=>500)); }
        $wpdb->last_error = '';
        $locked_member=$wpdb->get_row($wpdb->prepare('SELECT id FROM '.WSM_Club::table('members').' WHERE id=%d FOR UPDATE',$member_id));
        if (!empty($wpdb->last_error)) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_resume_member_lock','قفل‌کردن پروندهٔ ورزشکار برای رفع فریز انجام نشد.',array('status'=>500)); }
        if (!$locked_member) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_member_missing','پروندهٔ ورزشکار برای رفع فریز پیدا نشد.',array('status'=>404)); }
        $wpdb->last_error = '';
        $locked = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.WSM_Club::table('memberships').' WHERE id=%d FOR UPDATE',$id));
        if (!empty($wpdb->last_error)) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_resume_lookup','قفل‌کردن عضویت برای رفع فریز انجام نشد.',array('status'=>500)); }
        if ($locked && (int)$locked->member_id!==$member_id) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_resume_member_conflict','پیوند عضو عضویت هنگام رفع فریز تغییر کرده است.',array('status'=>409)); }
        if (!$locked || $locked->status !== 'frozen') { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_membership_not_frozen','این عضویت دیگر فریز نیست.',array('status'=>409)); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$locked->end_date) || !checkdate((int)substr($locked->end_date,5,2),(int)substr($locked->end_date,8,2),(int)substr($locked->end_date,0,4))) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wsm_club_resume_date_invalid','تاریخ پایان عضویت برای رفع فریز معتبر نیست.',array('status'=>409));
        }
        $timezone = wp_timezone();
        $today = new DateTimeImmutable(current_time('Y-m-d'), $timezone);
        $freeze_day = $today;
        try {
            $from = new DateTimeImmutable((string)$locked->frozen_at, $timezone);
            $freeze_day = $from->setTime(0,0,0);
            $elapsed_days = max(1, (int)$freeze_day->diff($today)->days);
        } catch (Exception $e) {
            $elapsed_days = 1;
        }
        $days = $elapsed_days;
        if (!empty($locked->freeze_until) && preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$locked->freeze_until)) {
            try {
                $planned_end = new DateTimeImmutable((string)$locked->freeze_until, $timezone);
                $planned_days = (int)$freeze_day->diff($planned_end)->days;
                if ($planned_days > 0) { $days = min($days, $planned_days); }
            } catch (Exception $e) {}
        }
        $end=(new DateTimeImmutable((string)$locked->end_date,$timezone))->modify('+'.$days.' days')->format('Y-m-d');
        $resume=$locked->resume_status==='scheduled'&&$locked->start_date>current_time('Y-m-d')?'scheduled':'active';
        $now=current_time('mysql');
        $updated=$wpdb->update(WSM_Club::table('memberships'),array('end_date'=>$end,'freeze_days_total'=>(int)$locked->freeze_days_total+$days,'frozen_at'=>null,'freeze_until'=>null,'resume_status'=>'','status'=>$resume,'updated_at'=>$now),array('id'=>$id,'status'=>'frozen'));
        if ($updated!==1) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_resume_failed','رفع فریز عضویت انجام نشد یا وضعیت آن هم‌زمان تغییر کرد.',array('status'=>500)); }
        $wpdb->query('COMMIT');
        return true;
    }

    public static function daily_tasks() {
        global $wpdb;
        $now=current_time('mysql');
        $expired=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".WSM_Club::table('memberships')." WHERE status='frozen' AND freeze_until<=%s ORDER BY freeze_until ASC,id ASC LIMIT 250",current_time('Y-m-d')));
        foreach ((array)$expired as $row) {
            $resumed=self::resume_membership($row);
            if (is_wp_error($resumed)) { error_log('[WSM Club] Automatic membership unfreeze failed for #' . (int)$row->id . ': ' . $resumed->get_error_code()); }
        }

        // Birthdays are stored as Gregorian dates but celebrated by their Jalali month/day.
        if (empty(self::settings()['sms_events']['birthday']) || !function_exists('wsm_gregorian_to_jalali')) { return; }
        if (!class_exists('WSM_SMS') || !WSM_SMS::is_active()) { return; }
        $today = current_time('Y-m-d');
        $today_parts = array_map('intval', explode('-', $today));
        if (count($today_parts) !== 3 || !checkdate($today_parts[1], $today_parts[2], $today_parts[0])) { return; }
        list($jalali_year, $jalali_month, $jalali_day) = wsm_gregorian_to_jalali($today_parts[0], $today_parts[1], $today_parts[2]);
        $member_table = WSM_Club::table('members');
        $phonebook_table = self::table('phonebook');
        $rows=$wpdb->get_results("SELECT m.* FROM $member_table m WHERE m.status='active' AND m.sms_consent=1 AND (m.phone<>'' OR EXISTS (SELECT 1 FROM $phonebook_table pb WHERE pb.member_id=m.id AND pb.phone<>'' AND pb.sms_consent=1)) AND m.birth_date IS NOT NULL AND m.birth_date<>'0000-00-00' ORDER BY m.id ASC LIMIT 10000");
        foreach ((array)$rows as $member) {
            $birth = isset($member->birth_date) ? explode('-', (string)$member->birth_date) : array();
            if (count($birth) !== 3) { continue; }
            $birth = array_map('intval', $birth);
            if (!checkdate($birth[1], $birth[2], $birth[0])) { continue; }
            list($birth_year, $birth_month, $birth_day) = wsm_gregorian_to_jalali($birth[0], $birth[1], $birth[2]);
            if ($birth_month !== $jalali_month || $birth_day !== $jalali_day) { continue; }
            $sent_key = 'wsm_sms_birthday_sent_' . (int)$member->id . '_' . (int)$jalali_year;
            if (get_transient($sent_key)) { continue; }
            $jalali_date = sprintf('%04d/%02d/%02d', $jalali_year, $jalali_month, $jalali_day);
            if (function_exists('wsm_to_persian_digits')) { $jalali_date = wsm_to_persian_digits($jalali_date); }
            $result = self::trigger_sms_event('birthday', $member, array('birthday_jalali'=>$jalali_date,'today_jalali'=>$jalali_date));
            if (!empty($result['ok'])) { set_transient($sent_key, 1, 370 * DAY_IN_SECONDS); }
        }
    }

    private static function template_message($template, $member, $extra = array()) {
        $member = is_object($member) ? $member : (object)(is_array($member) ? $member : array());
        $first = isset($member->first_name) ? (string)$member->first_name : '';
        $last = isset($member->last_name) ? (string)$member->last_name : '';
        $repl = array(
            '{name}' => trim($first . ' ' . $last),
            '{first_name}' => $first,
            '{last_name}' => $last,
            '{member_code}' => isset($member->member_code) ? (string)$member->member_code : '',
            '{card_number}' => isset($member->card_number) ? (string)$member->card_number : '',
        );
        if (is_array($extra)) {
            foreach ($extra as $key => $value) {
                if (is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $key) && is_scalar($value)) {
                    $repl['{' . $key . '}'] = self::text($value, 500);
                }
            }
        }
        // Recipient identity fields are authoritative and cannot be replaced by event context.
        $repl['{name}'] = trim($first . ' ' . $last);
        $repl['{first_name}'] = $first;
        $repl['{last_name}'] = $last;
        $repl['{member_code}'] = isset($member->member_code) ? (string)$member->member_code : '';
        $repl['{card_number}'] = isset($member->card_number) ? (string)$member->card_number : '';
        $message = strtr((string)$template, $repl);
        // Do not send literal {unknown_placeholder} text when a module omits optional context.
        return preg_replace('/\{[A-Za-z][A-Za-z0-9_]{0,39}\}/', '', $message);
    }

    public static function sms_type_label($type) {
        $type = is_scalar($type) ? sanitize_key((string) $type) : '';
        $catalog = self::sms_event_catalog();
        if (isset($catalog[$type]['label'])) { return (string) $catalog[$type]['label']; }
        $extra = array(
            'manual' => 'ارسال دستی',
            'test' => 'آزمایش سرویس',
            'registration' => 'فعال‌شدن اشتراک',
            'expiry' => 'یادآوری پیش از انقضا',
            'debt' => 'یادآوری سررسید قسط/تمدید',
        );
        return isset($extra[$type]) ? $extra[$type] : ($type !== '' ? $type : 'پیامک');
    }

    public static function log_sms($member_id, $phone, $type, $message, $result) {
        global $wpdb;
        $result=is_array($result)?$result:array();
        $message_type = sanitize_key((string)$type);
        // Never persist OTP text from native routes or external event hooks.
        if (strpos($message_type, 'otp') !== false) { $message='[کد یک‌بارمصرف؛ متن محرمانه]'; }
        $wpdb->insert(self::table('sms_log'),array('member_id'=>absint($member_id),'phone'=>self::text($phone,40),'message_type'=>$message_type,'message'=>sanitize_textarea_field((string)$message),'status'=>!empty($result['ok'])?'sent':'failed','detail'=>self::text(isset($result['detail'])?$result['detail']:'',255),'provider_ref'=>self::text(isset($result['provider_ref'])?$result['provider_ref']:'',100),'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')));
    }

    public static function sms_event_enabled($event_key) {
        $event_key = is_scalar($event_key) ? sanitize_key((string)$event_key) : '';
        if (!isset(self::sms_event_catalog()[$event_key])) { return false; }
        $settings = self::settings();
        return !empty($settings['sms_events'][$event_key]);
    }

    /**
     * Trigger a configured event for a club member. Member events always pass through the
     * stored sms_consent check in send_member_sms(). Admin alerts use the configured admin phone.
     *
     * Extension contract for classes, payment gateways, referrals, surveys and SMS OTP providers:
     * do_action('wsm_sms_event_trigger', $event_key, $member_or_id, $context_array).
     * Supported context keys are used as {key} placeholders in that event's editable template.
     */
    public static function trigger_sms_event($event_key, $recipient = null, $context = array()) {
        $event_key = is_scalar($event_key) ? sanitize_key((string)$event_key) : '';
        $catalog = self::sms_event_catalog();
        if (!isset($catalog[$event_key])) { return array('ok'=>false,'detail'=>'رویداد پیامکی شناخته‌شده نیست.'); }
        if (empty(self::settings()['sms_events'][$event_key])) { return array('ok'=>false,'detail'=>'رویداد پیامکی غیرفعال است.'); }
        if ($event_key === 'suspicious_login') { return self::send_admin_sms_event($event_key, is_array($context) ? $context : array()); }

        $member = null;
        if (is_object($recipient)) { $member = $recipient; }
        elseif (is_array($recipient)) { $member = (object)$recipient; }
        elseif (is_scalar($recipient) && is_numeric($recipient) && (int)$recipient > 0 && class_exists('WSM_Club')) {
            global $wpdb;
            $member = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.WSM_Club::table('members').' WHERE id=%d',(int)$recipient));
        }
        if (!$member) { return array('ok'=>false,'detail'=>'عضو پیامک پیدا نشد.'); }
        $settings = self::settings();
        $template = isset($settings['sms_templates'][$event_key]) ? (string)$settings['sms_templates'][$event_key] : '';
        return self::send_member_sms($member, $event_key, $template, is_array($context) ? $context : array());
    }

    /** Callback for the documented `wsm_sms_event_trigger` integration action. */
    public static function handle_sms_event_trigger($event_key, $recipient = null, $context = array()) {
        return self::trigger_sms_event($event_key, $recipient, $context);
    }

    /** Send a non-member event such as a repeated-login warning to the configured administrator. */
    public static function send_admin_sms_event($event_key, $context = array()) {
        $event_key = is_scalar($event_key) ? sanitize_key((string)$event_key) : '';
        if ($event_key !== 'suspicious_login' || !self::sms_event_enabled($event_key)) { return array('ok'=>false,'detail'=>'هشدار پیامکی مدیر غیرفعال است.'); }
        if (!class_exists('WSM_SMS') || !WSM_SMS::is_active()) { return array('ok'=>false,'detail'=>'سرویس پیامک فعال/پیکربندی نشده است.'); }
        $config = WSM_SMS::effective_config();
        $phone = isset($config['admin_mobile']) ? (string)$config['admin_mobile'] : '';
        $settings = self::settings();
        $template = isset($settings['sms_templates'][$event_key]) ? (string)$settings['sms_templates'][$event_key] : '';
        $admin = (object)array('id'=>0,'first_name'=>'مدیر','last_name'=>'','member_code'=>'','card_number'=>'','phone'=>$phone,'sms_consent'=>1);
        $message = self::template_message($template, $admin, is_array($context) ? $context : array());
        $result = WSM_SMS::dispatch_to($message, $phone);
        self::log_sms(0, $phone, $event_key, $message, $result);
        return $result;
    }

    public static function send_member_sms($member, $type, $template, $extra = array()) {
        global $wpdb;
        $type = is_scalar($type) ? sanitize_key((string)$type) : '';
        if (isset(self::sms_event_catalog()[$type]) && !self::sms_event_enabled($type)) { return array('ok'=>false,'detail'=>'رویداد پیامکی غیرفعال است.'); }
        if (!$member || empty($member->sms_consent)) { return array('ok'=>false,'detail'=>'رضایت پیامکی عضو ثبت نشده است.'); }
        if (isset($member->status) && (string)$member->status !== 'active') { return array('ok'=>false,'detail'=>'ارسال پیامک باشگاه فقط برای عضوهای فعال مجاز است.'); }
        $phone = isset($member->phone) && is_scalar($member->phone) ? trim((string) $member->phone) : '';
        if ($phone === '' && !empty($member->id)) {
            // For automated reminders only: a linked, consented phonebook entry may supply a missing athlete phone.
            $contact = $wpdb->get_row($wpdb->prepare(
                "SELECT phone,sms_consent FROM " . self::table('phonebook') . " WHERE member_id=%d AND phone<>'' ORDER BY id ASC LIMIT 1",
                (int) $member->id
            ));
            if ($contact && !empty($contact->sms_consent) && !empty($contact->phone)) {
                $phone = (string) $contact->phone;
                $member->phone = $phone;
            }
        }
        if ($phone === '') { return array('ok'=>false,'detail'=>'شمارهٔ عضو یا مخاطب پیوندخورده در دفتر تلفن ثبت نشده است.'); }
        if (trim((string)$template) === '') { return array('ok'=>false,'detail'=>'قالب پیامک خالی است.'); }
        $member_id = isset($member->id) ? (int)$member->id : 0;
        if (!class_exists('WSM_SMS')||!WSM_SMS::is_active()) { $result=array('ok'=>false,'detail'=>'سرویس پیامک فعال/پیکربندی نشده است.'); }
        else { $message=self::template_message($template,$member,$extra); $result=WSM_SMS::dispatch_to($message,$phone); }
        if (!isset($message)) { $message=self::template_message($template,$member,$extra); }
        self::log_sms($member_id,$phone,$type,$message,$result);
        return $result;
    }

    public static function send_sms($data) {
        global $wpdb;
        if (!is_array($data)||!isset($data['member_ids'])||!is_array($data['member_ids'])) { return new WP_Error('wsm_club_sms_invalid','فهرست اعضای پیامک معتبر نیست.',array('status'=>400)); }
        $ids=array_values(array_unique(array_filter(array_map('absint',array_slice($data['member_ids'],0,100)))));
        if (!$ids) { return new WP_Error('wsm_club_sms_no_recipients','حداقل یک عضو را انتخاب کنید.',array('status'=>400)); }
        $device = class_exists('WSM_Permissions') ? WSM_Permissions::current_device() : null;
        $user = $device ? get_userdata((int) $device->user_id) : false;
        $can_bulk_send = !$device || ($user && WSM_Permissions::is_wordpress_admin_user($user)) ||
            WSM_Permissions::has_current_permission('club.sms.manage') ||
            WSM_Permissions::has_current_permission('club.sms.bulk_send');
        if (!$can_bulk_send && count($ids) > 1) {
            return new WP_Error('wsm_club_sms_bulk_permission', 'این دستگاه فقط مجوز ارسال یک پیامک در هر نوبت را دارد.', array('status' => 403));
        }
        $text=self::text(isset($data['message'])?$data['message']:'',500); if ($text==='') { return new WP_Error('wsm_club_sms_empty','متن پیامک خالی است.',array('status'=>400)); }
        $event = isset($data['event']) && is_scalar($data['event']) ? sanitize_key((string)$data['event']) : 'manual';
        if (!in_array($event, array('manual','announcement'), true)) { return new WP_Error('wsm_club_sms_event_invalid','نوع ارسال پیامک معتبر نیست.',array('status'=>400)); }
        if ($event === 'announcement' && !self::sms_event_enabled('announcement')) { return new WP_Error('wsm_club_sms_announcement_disabled','رویداد اطلاعیهٔ گروهی در تنظیمات پیامک خاموش است.',array('status'=>409)); }
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.WSM_Club::table('members')." WHERE status='active' AND id IN ($placeholders)",$ids));
        $results=array('sent'=>0,'failed'=>0,'skipped'=>max(0,count($ids)-count((array)$rows)),'items'=>array());
        foreach ((array)$rows as $member) {
            if (!(int)$member->sms_consent||$member->phone==='') { $results['skipped']++; continue; }
            if ($event === 'announcement') { $result=self::trigger_sms_event('announcement',$member,array('announcement'=>$text)); }
            else { $result=self::send_member_sms($member,'manual',$text); }
            if (!empty($result['ok'])) {$results['sent']++;}else{$results['failed']++;}
            $results['items'][]=array('member_id'=>(int)$member->id,'status'=>!empty($result['ok'])?'sent':'failed','detail'=>self::text(isset($result['detail'])?$result['detail']:'',255));
        }
        return $results;
    }

    /** Send a consent-checked batch to selected phonebook contacts, including archived athletes. */
    public static function send_phonebook_sms($data) {
        global $wpdb;
        if (!is_array($data) || !isset($data['contact_ids']) || !is_array($data['contact_ids'])) {
            return new WP_Error('wsm_phonebook_sms_invalid', 'فهرست مخاطبان دفتر تلفن معتبر نیست.', array('status' => 400));
        }
        $ids = array();
        foreach (array_slice($data['contact_ids'], 0, 100) as $value) {
            if (!is_scalar($value) || !preg_match('/^\d+$/', (string) $value)) { continue; }
            $id = absint($value);
            if ($id > 0 && !in_array($id, $ids, true)) { $ids[] = $id; }
        }
        if (!$ids) { return new WP_Error('wsm_phonebook_sms_no_recipients', 'حداقل یک مخاطب را انتخاب کنید.', array('status' => 400)); }
        $message_text = self::text(isset($data['message']) ? $data['message'] : '', 500);
        if ($message_text === '') { return new WP_Error('wsm_phonebook_sms_empty', 'متن پیامک خالی است.', array('status' => 400)); }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $table = self::table('phonebook');
        $wpdb->last_error = '';
        $contacts = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE id IN ($placeholders)", $ids));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_phonebook_sms_lookup_failed', 'خواندن مخاطبان برای ارسال پیامک انجام نشد.', array('status' => 500));
        }
        $contact_map = array();
        $member_ids = array();
        foreach ((array) $contacts as $contact) {
            $contact_map[(int) $contact->id] = $contact;
            if (!empty($contact->member_id)) { $member_ids[] = absint($contact->member_id); }
        }
        $members = array();
        $member_ids = array_values(array_unique(array_filter($member_ids)));
        if ($member_ids) {
            $member_placeholders = implode(',', array_fill(0, count($member_ids), '%d'));
            $member_rows = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . WSM_Club::table('members') . " WHERE id IN ($member_placeholders)",
                $member_ids
            ));
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_phonebook_sms_member_lookup_failed', 'بررسی رضایت ورزشکاران پیوندخورده انجام نشد.', array('status' => 500));
            }
            foreach ((array) $member_rows as $member) { $members[(int) $member->id] = $member; }
        }

        $results = array('sent' => 0, 'failed' => 0, 'skipped' => 0, 'items' => array());
        foreach ($ids as $contact_id) {
            if (!isset($contact_map[$contact_id])) {
                $results['skipped']++;
                $results['items'][] = array('contact_id' => $contact_id, 'status' => 'skipped', 'detail' => 'مخاطب پیدا نشد.');
                continue;
            }
            $contact = $contact_map[$contact_id];
            $member_id = isset($contact->member_id) ? absint($contact->member_id) : 0;
            $member = $member_id && isset($members[$member_id]) ? clone $members[$member_id] : null;
            if (empty($contact->sms_consent) || empty($contact->phone) || ($member && empty($member->sms_consent))) {
                $results['skipped']++;
                $results['items'][] = array('contact_id' => $contact_id, 'status' => 'skipped', 'detail' => 'شماره یا رضایت پیامکی معتبر نیست.');
                continue;
            }
            if (!$member) {
                $member = (object) array(
                    'id' => 0,
                    'first_name' => (string) $contact->name,
                    'last_name' => '',
                    'member_code' => '',
                    'card_number' => '',
                    'phone' => (string) $contact->phone,
                    'sms_consent' => 1,
                );
            } else {
                // Keep the member's consent authoritative, but use the contact card's current number/name.
                $member->phone = (string) $contact->phone;
                $member->first_name = (string) $contact->name;
                $member->last_name = '';
            }
            $result = self::send_member_sms($member, 'manual', $message_text);
            $ok = !empty($result['ok']);
            if ($ok) { $results['sent']++; } else { $results['failed']++; }
            $results['items'][] = array(
                'contact_id' => $contact_id,
                'member_id' => $member_id,
                'status' => $ok ? 'sent' : 'failed',
                'detail' => self::text(isset($result['detail']) ? $result['detail'] : '', 255),
            );
        }
        return $results;
    }

    /** Register the least-privilege export endpoint; it returns only active, consented SMS recipients. */
    public static function register_sms_recipients_route() {
        if (!function_exists('register_rest_route')) { return; }
        register_rest_route('wsm/v1', '/club/sms/recipients', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'sms_recipients_endpoint'),
            'permission_callback' => array('WSM_Permissions', 'check_request'),
        ));
    }

    public static function sms_recipients_endpoint($request) {
        global $wpdb;
        if (!class_exists('WSM_Club')) { return new WP_Error('wsm_sms_recipients_unavailable', 'فهرست اعضا در دسترس نیست.', array('status' => 503)); }
        $args = is_object($request) && method_exists($request, 'get_params') ? $request->get_params() : array();
        // The CSV consumer stops at 500 pages (50,000 rows); bound offsets here as well.
        $page = isset($args['page']) && is_scalar($args['page']) ? min(500, max(1, absint($args['page']))) : 1;
        $per = isset($args['per_page']) && is_scalar($args['per_page']) ? max(1, min(100, absint($args['per_page']))) : 100;
        $offset = ($page - 1) * $per;
        $table = WSM_Club::table('members');
        $where = "status='active' AND sms_consent=1 AND phone<>''";
        $wpdb->last_error = '';
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        if ($wpdb->last_error !== '') { return new WP_Error('wsm_sms_recipients_read_failed', 'شمارش مخاطبان پیامک انجام نشد.', array('status' => 500)); }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,first_name,last_name,member_code,card_number,phone FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d",
            $per,
            $offset
        ), ARRAY_A);
        if ($wpdb->last_error !== '') { return new WP_Error('wsm_sms_recipients_read_failed', 'خواندن مخاطبان پیامک انجام نشد.', array('status' => 500)); }
        $items = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $first = isset($row['first_name']) ? (string) $row['first_name'] : '';
            $last = isset($row['last_name']) ? (string) $row['last_name'] : '';
            $items[] = array(
                'id' => absint($row['id'] ?? 0),
                'name' => trim($first . ' ' . $last),
                'first_name' => $first,
                'last_name' => $last,
                'member_code' => isset($row['member_code']) ? (string) $row['member_code'] : '',
                'card_number' => isset($row['card_number']) ? (string) $row['card_number'] : '',
                'phone' => isset($row['phone']) ? (string) $row['phone'] : '',
                'sms_consent' => true,
            );
        }
        return array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per,
            'total_pages' => max(1, (int) ceil($total / max(1, $per))),
        );
    }

    public static function list_sms($args=array()) {
        global $wpdb;
        list($page, $per, $off) = self::page_args($args);
        $logs = self::table('sms_log');
        $members = self::table('members');
        $where = array('1=1');
        $params = array();
        $status = isset($args['status']) && is_scalar($args['status']) ? sanitize_key((string) $args['status']) : '';
        if (in_array($status, array('sent', 'failed'), true)) {
            $where[] = 'l.status = %s';
            $params[] = $status;
        }
        $message_type = isset($args['message_type']) && is_scalar($args['message_type']) ? sanitize_key((string) $args['message_type']) : '';
        if ($message_type !== '') {
            $where[] = 'l.message_type = %s';
            $params[] = $message_type;
        }
        $search = isset($args['search']) && is_scalar($args['search']) ? self::text($args['search'], 120) : '';
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(l.phone LIKE %s OR l.message LIKE %s OR l.detail LIKE %s OR l.provider_ref LIKE %s OR m.first_name LIKE %s OR m.last_name LIKE %s OR m.member_code LIKE %s OR m.card_number LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }
        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$logs} l LEFT JOIN {$members} m ON m.id = l.member_id WHERE {$where_sql}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
        $query_sql = "SELECT l.*, m.first_name, m.last_name, m.member_code, m.card_number FROM {$logs} l LEFT JOIN {$members} m ON m.id = l.member_id WHERE {$where_sql} ORDER BY l.id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($query_sql, array_merge($params, array($per, $off))), ARRAY_A);
        $items = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $member_name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
            if ($member_name === '' && (int) ($row['member_id'] ?? 0) === 0 && !empty($row['phone'])) { $member_name = 'مدیر / مخاطب'; }
            $row['member_name'] = $member_name;
            $row['message_type_label'] = self::sms_type_label($row['message_type'] ?? '');
            $row['status_label'] = ($row['status'] ?? '') === 'sent' ? 'ارسال‌شده' : 'ناموفق';
            $items[] = $row;
        }
        $summary_row = $wpdb->get_row("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed FROM {$logs}", ARRAY_A);
        return array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per,
            'total_pages' => max(1, (int) ceil($total / max(1, $per))),
            'summary' => array(
                'total' => (int) ($summary_row['total'] ?? 0),
                'sent' => (int) ($summary_row['sent'] ?? 0),
                'failed' => (int) ($summary_row['failed'] ?? 0),
            ),
        );
    }

    private static function trainer_performance_rows($from, $to) {
        global $wpdb;
        $staff_table = WSM_Club::table('staff');
        $member_table = WSM_Club::table('members');
        $attendance_table = WSM_Club::table('attendance');
        $payroll_table = WSM_Club::table('payroll');
        $start = $from . ' 00:00:00';
        $end = $to . ' 23:59:59';
        $staff = $wpdb->get_results("SELECT id,name,role,status FROM $staff_table ORDER BY status ASC,name ASC,id ASC", ARRAY_A);
        $query_error = self::report_query_error('خواندن فهرست مربیان');
        if (is_wp_error($query_error)) { return $query_error; }
        $attendance = $wpdb->get_results($wpdb->prepare(
            "SELECT u.coach_id,
                COUNT(CASE WHEN a.direction='in' AND a.decision='allowed' THEN 1 END) AS checkins,
                COUNT(DISTINCT CASE WHEN a.direction='in' AND a.decision='allowed' THEN a.member_id END) AS unique_attendees,
                SUM(CASE WHEN a.event_type='late' THEN 1 ELSE 0 END) AS late_entries,
                COUNT(CASE WHEN a.direction='out' AND a.decision='allowed' THEN 1 END) AS exits
             FROM $attendance_table a INNER JOIN $member_table u ON u.id=a.member_id
             WHERE a.occurred_at BETWEEN %s AND %s AND u.coach_id>0 GROUP BY u.coach_id",
            $start, $end
        ), ARRAY_A);
        $query_error = self::report_query_error('خواندن تردد مربیان');
        if (is_wp_error($query_error)) { return $query_error; }
        $active = $wpdb->get_results("SELECT coach_id,COUNT(*) AS active_members FROM $member_table WHERE status='active' AND coach_id>0 GROUP BY coach_id", ARRAY_A);
        $query_error = self::report_query_error('خواندن اعضای فعال مربیان');
        if (is_wp_error($query_error)) { return $query_error; }
        $payroll = $wpdb->get_results($wpdb->prepare(
            "SELECT staff_id,COUNT(*) AS paid_periods,COALESCE(SUM(net),0) AS payroll_paid
             FROM $payroll_table WHERE status='paid' AND paid_at BETWEEN %s AND %s GROUP BY staff_id",
            $start, $end
        ), ARRAY_A);
        $query_error = self::report_query_error('خواندن حقوق پرداخت‌شدهٔ مربیان');
        if (is_wp_error($query_error)) { return $query_error; }
        $attendance_map = array();
        foreach ((array) $attendance as $row) { $attendance_map[(int) $row['coach_id']] = $row; }
        $active_map = array();
        foreach ((array) $active as $row) { $active_map[(int) $row['coach_id']] = (int) $row['active_members']; }
        $payroll_map = array();
        foreach ((array) $payroll as $row) { $payroll_map[(int) $row['staff_id']] = $row; }
        $items = array();
        $summary = array('trainers' => 0, 'active_trainers' => 0, 'assigned_active_members' => 0, 'trainer_visits' => 0, 'trainer_unique_attendees' => 0, 'trainer_late_entries' => 0, 'payroll_paid' => 0.0);
        foreach ((array) $staff as $row) {
            $id = (int) $row['id'];
            $att = isset($attendance_map[$id]) ? $attendance_map[$id] : array();
            $pay = isset($payroll_map[$id]) ? $payroll_map[$id] : array();
            $items[] = array(
                'staff_id' => $id,
                'name' => (string) $row['name'],
                'role' => (string) $row['role'],
                'status' => (string) $row['status'],
                'assigned_active_members' => isset($active_map[$id]) ? $active_map[$id] : 0,
                'checkins' => isset($att['checkins']) ? (int) $att['checkins'] : 0,
                'unique_attendees' => isset($att['unique_attendees']) ? (int) $att['unique_attendees'] : 0,
                'late_entries' => isset($att['late_entries']) ? (int) $att['late_entries'] : 0,
                'exits' => isset($att['exits']) ? (int) $att['exits'] : 0,
                'paid_periods' => isset($pay['paid_periods']) ? (int) $pay['paid_periods'] : 0,
                'payroll_paid' => isset($pay['payroll_paid']) ? (float) $pay['payroll_paid'] : 0.0,
            );
            $summary['trainers']++;
            if ($row['status'] === 'active') { $summary['active_trainers']++; }
            $summary['assigned_active_members'] += isset($active_map[$id]) ? $active_map[$id] : 0;
            $summary['trainer_visits'] += isset($att['checkins']) ? (int) $att['checkins'] : 0;
            $summary['trainer_unique_attendees'] += isset($att['unique_attendees']) ? (int) $att['unique_attendees'] : 0;
            $summary['trainer_late_entries'] += isset($att['late_entries']) ? (int) $att['late_entries'] : 0;
            $summary['payroll_paid'] += isset($pay['payroll_paid']) ? (float) $pay['payroll_paid'] : 0.0;
        }
        usort($items, function ($a, $b) { return $b['checkins'] <=> $a['checkins'] ?: strcmp($a['name'], $b['name']); });
        return array('items' => $items, 'summary' => $summary);
    }

    /**
     * Add unpaid club POS member-credit orders to club sales totals without posting them as cash income.
     * The global WooCommerce revenue/finance ledger remains cash-realization based.
     */
    private static function pos_order_debt_due($order) {
        if (!is_object($order) || !method_exists($order, 'get_total')) { return 0.0; }
        $gross=max(0.0,(float)$order->get_total()-(float)$order->get_total_refunded());
        $paid=max(0.0,(float)$order->get_meta('_wsm_club_pos_debt_paid_amount',true));
        return max(0.0,$gross-$paid);
    }

    /** Keep receivables visible if an unpaid debt order is moved out of WooCommerce's on-hold state. */
    private static function pos_debt_open_order_statuses() {
        $statuses=function_exists('wc_get_order_statuses')?array_keys((array)wc_get_order_statuses()):array();
        $statuses=array_map(function($status){return preg_replace('/^wc-/','',(string)$status);},$statuses);
        $statuses=array_values(array_diff($statuses,array('cancelled','refunded','trash')));
        return $statuses?:array('pending','processing','on-hold','completed','failed');
    }

    /** Keep a debt order from being marked paid before its explicit receivable is collected. */
    public static function sync_pos_debt_order_status($order_id,$old_status=null,$new_status=null,$order_object=null) {
        global $wpdb;
        $order_id=absint($order_id);$order=$order_object instanceof WC_Order?$order_object:(function_exists('wc_get_order')?wc_get_order($order_id):false);
        if(!$order_id||!$order instanceof WC_Order||!$order->get_meta('_wsm_club_pos_debt')){return false;}
        $new_status=sanitize_key((string)($new_status!==null?$new_status:$order->get_status()));
        if(in_array($new_status,array('processing','completed'),true)&&self::pos_order_debt_due($order)>0.000001){
            if(method_exists($order,'add_order_note')){$order->add_order_note('وضعیت پرداخت به‌دلیل باقی‌ماندن بدهی نسیهٔ باشگاه به «در انتظار تسویه» بازگردانده شد.');}
            $order->update_status('on-hold','تسویهٔ بدهی باشگاه الزامی است.');
            return new WP_Error('wsm_pos_debt_status_payment_guard','سفارش نسیه تا تسویهٔ واقعی بدهی نمی‌تواند پرداخت‌شده علامت‌گذاری شود.',array('status'=>409));
        }
        if(in_array($new_status,array('cancelled','refunded'),true)){
            $wpdb->last_error='';
            $voided=$wpdb->query($wpdb->prepare("UPDATE ".self::table('financial_ledger')." SET status='void',ledger_status='cancelled',void_reason=%s,voided_by=0,voided_at=%s WHERE order_id=%d AND kind='pos_receivable' AND status='posted'",'order_'.$new_status,current_time('mysql'),$order_id));
            if($voided===false){
                error_log('[WSM Club] Could not void POS receivable for cancelled order #'.$order_id);
                if(in_array($new_status,array('cancelled','refunded'),true)&&self::pos_order_debt_due($order)>0.000001&&method_exists($order,'update_status')){
                    if(method_exists($order,'add_order_note')){$order->add_order_note('تغییر وضعیت لغو/بازپرداخت برگردانده شد چون ابطال ردیف دریافتنی در دفتر مالی انجام نشد؛ بدهی تا رفع خطای مالی در وضعیت انتظار تسویه می‌ماند.');}
                    $order->update_status('on-hold','ابطال دریافتنی ناموفق بود؛ سفارش نسیه برای حفظ ماندهٔ بدهی به وضعیت انتظار تسویه بازگشت.');
                }
                return new WP_Error('wsm_pos_debt_receivable_void_failed','ابطال دریافتنی فروش نسیه پس از لغو سفارش انجام نشد.',array('status'=>500));
            }
            $order->update_meta_data('_wsm_club_pos_debt_closed_at',current_time('mysql'));
            $order->save_meta_data();
            $projection=self::sync_receivables_projection();
            if(is_wp_error($projection)){error_log('[WSM Club] POS debt projection refresh deferred after order cancellation #'.$order_id);}
            return true;
        }
        return false;
    }

    /** Aggregate open POS-credit balances for a bounded set of member IDs. */
    public static function pos_debt_by_member($member_ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $member_ids))));
        $balances = array();
        foreach ($ids as $id) { $balances[$id] = 0.0; }
        if (!$ids) { return $balances; }
        if (!function_exists('wc_get_orders')) { return new WP_Error('wsm_club_pos_debt_woo_missing','محاسبهٔ بدهی فروش حضوری به ووکامرس نیاز دارد.',array('status'=>503)); }
        // Filter candidate orders by the debt marker; member IDs are verified in the loop below.
        $debt_order_ids = WSM_Util::order_ids_with_meta('_wsm_club_pos_debt', '1');
        $page = 1;
        $max_pages = 1;
        do {
            $wpdb->last_error = '';
            $result = wc_get_orders(array(
                'type' => 'shop_order',
                'status' => self::pos_debt_open_order_statuses(),
                'limit' => 100,
                'page' => $page,
                'paginate' => true,
                'return' => 'objects',
                'post__in' => $debt_order_ids ? $debt_order_ids : array(0),
            ));
            if (is_wp_error($result)) { return $result; }
            if (!empty($wpdb->last_error)) { error_log('[WSM Club] POS receivable query failed: '.$wpdb->last_error); return new WP_Error('wsm_club_pos_debt_query_failed','خواندن بدهی فروش حضوری انجام نشد.',array('status'=>500)); }
            $orders = is_object($result) && isset($result->orders) ? (array) $result->orders : (array) $result;
            $max_pages = is_object($result) && isset($result->max_num_pages) ? max(1, (int) $result->max_num_pages) : 1;
            foreach ($orders as $order) {
                if (!is_object($order) || !method_exists($order, 'get_meta')) { continue; }
                $member_id = absint($order->get_meta('_wsm_club_member_id', true));
                if (!$member_id || !array_key_exists($member_id, $balances) || !$order->get_meta('_wsm_club_pos_debt', true)) { continue; }
                $balances[$member_id] += self::pos_order_debt_due($order);
            }
            $page++;
        } while ($page <= $max_pages);
        return $balances;
    }

    private static function include_pos_credit_sales($sales, $from, $to) {
        global $wpdb;
        if (!is_array($sales)) { return new WP_Error('wsm_club_sales_invalid','دادهٔ پایهٔ گزارش فروش معتبر نیست.',array('status'=>500)); }
        if (!function_exists('wc_get_orders')) { return new WP_Error('wsm_club_woo_missing','ووکامرس فعال نیست.',array('status'=>500)); }
        $page=1; $max_pages=1; $days=array();
        foreach ((array)(isset($sales['days'])?$sales['days']:array()) as $index=>$row) {
            if (isset($row['date'])) { $days[(string)$row['date']]=$index; }
        }
        do {
            // Avoid unsupported custom-meta queries in HPOS; the order metadata is checked through WC_Order below.
            $wpdb->last_error='';
            $result=wc_get_orders(array(
                'type'=>'shop_order','status'=>self::pos_debt_open_order_statuses(),'limit'=>100,'page'=>$page,'paginate'=>true,
                'orderby'=>'date','order'=>'ASC','return'=>'objects',
                'date_created'=>$from.' 00:00:00...'.$to.' 23:59:59'
            ));
            if (is_wp_error($result)) { return $result; }
            if (!empty($wpdb->last_error)) { error_log('[WSM Club] POS sales report query failed: '.$wpdb->last_error); return new WP_Error('wsm_club_pos_sales_query_failed','خواندن فروش نسیه برای گزارش فروش انجام نشد.',array('status'=>500)); }
            $orders=is_object($result)&&isset($result->orders)?(array)$result->orders:(array)$result;
            $max_pages=is_object($result)&&isset($result->max_num_pages)?max(1,(int)$result->max_num_pages):1;
            foreach ($orders as $order) {
                if (!$order instanceof WC_Order || !$order->get_meta('_wsm_club_pos_debt')) { continue; }
                // The base WSM_Reports aggregate already includes non-invoice, top-level processing/completed orders.
                // Add only POS credit orders that the base aggregate omitted (e.g. on-hold/pending or tagged invoices).
                $order_status = (string) $order->get_status();
                $invoice_marker = (string) $order->get_meta('_wsm_invoice', true);
                $is_top_level = !method_exists($order, 'get_parent_id') || (int) $order->get_parent_id() === 0;
                // The core report excludes orders carrying _wsm_invoice; all other top-level paid statuses are counted there.
                $is_base_reported = $is_top_level && in_array($order_status, array('processing', 'completed'), true)
                    && !($invoice_marker !== '' && $invoice_marker !== '0');
                if ($is_base_reported) { continue; }
                $created=$order->get_date_created();
                if (!$created) { continue; }
                $day=$created->date('Y-m-d');
                if ($day<$from||$day>$to) { continue; }
                $total=max(0,(float)$order->get_total()-(float)$order->get_total_refunded());
                if ($total<=0) { continue; }
                $sales['total']=(float)($sales['total']??0)+$total;
                $sales['count']=(int)($sales['count']??0)+1;
                if (!isset($sales['channels']['in_person'])) { $sales['channels']['in_person']=array('total'=>0.0,'count'=>0); }
                $sales['channels']['in_person']['total']=(float)($sales['channels']['in_person']['total']??0)+$total;
                $sales['channels']['in_person']['count']=(int)($sales['channels']['in_person']['count']??0)+1;
                if (!isset($days[$day])) {
                    $sales['days'][]=array('date'=>$day,'label'=>wsm_fa_date_short($day.' 00:00:00'),'total'=>0.0,'count'=>0);
                    $days[$day]=count($sales['days'])-1;
                }
                $index=$days[$day];
                $sales['days'][$index]['total']=(float)($sales['days'][$index]['total']??0)+$total;
                $sales['days'][$index]['count']=(int)($sales['days'][$index]['count']??0)+1;
            }
            $page++;
        } while ($page<=$max_pages);
        if (isset($sales['days'])&&is_array($sales['days'])) {
            usort($sales['days'],function($a,$b){return strcmp((string)($a['date']??''),(string)($b['date']??''));});
            $sales['days']=array_values($sales['days']);
        }
        $sales['display']=wsm_fa_money($sales['total']??0);
        foreach (array('online','in_person') as $channel) {
            if (!isset($sales['channels'][$channel])) { $sales['channels'][$channel]=array('total'=>0.0,'count'=>0); }
            $sales['channels'][$channel]['display']=wsm_fa_money($sales['channels'][$channel]['total']??0);
        }
        return $sales;
    }

    /** Return a structured database error instead of allowing report query failures to look like empty data. */
    private static function report_query_error($context) {
        global $wpdb;
        $db_error = isset($wpdb->last_error) ? trim((string) $wpdb->last_error) : '';
        if ($db_error === '') { return false; }
        error_log('[WSM Reports] ' . (string) $context . ': ' . $db_error);
        return new WP_Error('wsm_club_report_query_failed', 'تهیهٔ گزارش باشگاه از پایگاه‌داده انجام نشد؛ گزارش خطای سرور را بررسی کنید.', array('status' => 500));
    }

    /** Check report dependencies before querying so a missing table becomes a proper REST JSON error. */
    private static function report_tables_ready($tables) {
        global $wpdb;
        foreach ((array) $tables as $table) {
            $table = is_string($table) ? $table : '';
            if ($table === '') {
                return new WP_Error('wsm_club_report_table_invalid', 'نام جدول گزارش معتبر نیست.', array('status' => 503));
            }
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ((string) $found !== $table) {
                error_log('[WSM Reports] Required report table unavailable: ' . $table);
                return new WP_Error('wsm_club_report_table_missing', 'یکی از جدول‌های لازم برای گزارش باشگاه در دسترس نیست؛ نصب یا به‌روزرسانی افزونه را بررسی کنید.', array('status' => 503));
            }
        }
        return true;
    }

    private static function report_overview($from, $to) {
        global $wpdb;
        // The PWA sends HTML date-input values as Gregorian ISO YYYY-MM-DD; Jalali formatting is display-only.
        $members = WSM_Club::table('members');
        $memberships = WSM_Club::table('memberships');
        $attendance_table = WSM_Club::table('attendance');
        $finance_table = self::table('finance');
        $tables_ready = self::report_tables_ready(array(
            $members, $memberships, $attendance_table, $finance_table,
            WSM_Club::table('staff'), WSM_Club::table('payroll'),
        ));
        if (is_wp_error($tables_ready)) { return $tables_ready; }
        $from_date = new DateTimeImmutable($from, wp_timezone());
        $to_date = new DateTimeImmutable($to, wp_timezone());
        $range_days = (int) $from_date->diff($to_date)->days + 1;
        if ($range_days < 1 || $range_days > 366) {
            return new WP_Error('wsm_club_report_range_too_large', 'برای داشبورد تحلیلی، بازهٔ انتخابی حداکثر ۳۶۶ روز است.', array('status' => 400));
        }
        $start = $from . ' 00:00:00';
        $end = $to . ' 23:59:59';
        $today = current_time('Y-m-d');
        $sales = function_exists('wc_get_orders') ? WSM_Reports::sales($from, $to) : array('total' => 0, 'count' => 0, 'days' => array(), 'channels' => array('online' => array('total' => 0, 'count' => 0), 'in_person' => array('total' => 0, 'count' => 0)));
        if (is_wp_error($sales)) { return $sales; }
        if (!is_array($sales)) { return new WP_Error('wsm_club_report_sales_invalid', 'ساختار فروش بازگشتی برای گزارش معتبر نیست.', array('status' => 500)); }
        $sales = self::include_pos_credit_sales($sales, $from, $to);
        if (is_wp_error($sales)) { return $sales; }
        if (!is_array($sales)) { return new WP_Error('wsm_club_report_sales_invalid', 'ساختار فروش بازگشتی برای گزارش معتبر نیست.', array('status' => 500)); }
        $sales = array_merge(array('total'=>0,'count'=>0,'days'=>array(),'channels'=>array()), $sales);
        $sales['days'] = isset($sales['days']) && is_array($sales['days']) ? $sales['days'] : array();
        $default_channels = array('online'=>array('total'=>0,'count'=>0),'in_person'=>array('total'=>0,'count'=>0));
        $sales['channels'] = isset($sales['channels']) && is_array($sales['channels']) ? array_merge($default_channels, $sales['channels']) : $default_channels;
        foreach (array('online','in_person') as $channel) {
            $sales['channels'][$channel] = isset($sales['channels'][$channel]) && is_array($sales['channels'][$channel]) ? array_merge($default_channels[$channel], $sales['channels'][$channel]) : $default_channels[$channel];
        }
        $active_members = (int) $wpdb->get_var("SELECT COUNT(*) FROM $members WHERE status='active'");
        $query_error = self::report_query_error('شمارش اعضای فعال');
        if (is_wp_error($query_error)) { return $query_error; }
        $all_members = (int) $wpdb->get_var("SELECT COUNT(*) FROM $members WHERE status<>'archived'");
        $query_error = self::report_query_error('شمارش اعضا');
        if (is_wp_error($query_error)) { return $query_error; }
        $new_members = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $members WHERE join_date BETWEEN %s AND %s AND status<>'archived'", $from, $to));
        $query_error = self::report_query_error('شمارش عضوهای جدید');
        if (is_wp_error($query_error)) { return $query_error; }
        $new_memberships = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $memberships WHERE created_at BETWEEN %s AND %s AND status<>'cancelled'", $start, $end));
        $query_error = self::report_query_error('شمارش عضویت‌های جدید');
        if (is_wp_error($query_error)) { return $query_error; }
        $active_memberships = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $memberships WHERE status='active' AND start_date<=%s AND end_date>=%s", $today, $today));
        $query_error = self::report_query_error('شمارش عضویت‌های فعال');
        if (is_wp_error($query_error)) { return $query_error; }
        $finance = (array) $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status='posted' AND transaction_type='income' THEN amount ELSE 0 END),0) AS income,
                COALESCE(SUM(CASE WHEN status='posted' AND transaction_type='expense' AND kind<>'payroll' THEN amount ELSE 0 END),0) AS expenses,
                COALESCE(SUM(CASE WHEN status='posted' AND kind='payroll' THEN amount ELSE 0 END),0) AS payroll,
                COALESCE(SUM(CASE WHEN status='posted' AND kind='cash_removal' THEN amount ELSE 0 END),0) AS cash_removed,
                COALESCE(SUM(CASE WHEN status='posted' AND kind='card_spend' THEN amount ELSE 0 END),0) AS card_sales,
                COUNT(CASE WHEN status='posted' THEN 1 END) AS transactions
             FROM $finance_table WHERE occurred_at BETWEEN %s AND %s",
            $start, $end
        ), ARRAY_A);
        $query_error = self::report_query_error('محاسبهٔ جمع‌های گزارش مالی');
        if (is_wp_error($query_error)) { return $query_error; }
        $attendance_totals = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type,COUNT(*) AS events FROM $attendance_table WHERE occurred_at BETWEEN %s AND %s GROUP BY event_type",
            $start, $end
        ), ARRAY_A);
        $query_error = self::report_query_error('خواندن جمع تردد');
        if (is_wp_error($query_error)) { return $query_error; }
        $attendance_counts = array('entry' => 0, 'exit' => 0, 'late' => 0, 'unknown' => 0);
        foreach ((array) $attendance_totals as $row) {
            $key = isset($row['event_type']) ? (string) $row['event_type'] : '';
            if (isset($attendance_counts[$key])) { $attendance_counts[$key] = (int) $row['events']; }
        }
        $attendance_days = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(occurred_at) AS report_day,
                SUM(CASE WHEN event_type='entry' THEN 1 ELSE 0 END) AS entries,
                SUM(CASE WHEN event_type='exit' THEN 1 ELSE 0 END) AS exits,
                SUM(CASE WHEN event_type='late' THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN event_type='unknown' THEN 1 ELSE 0 END) AS unknown_events
             FROM $attendance_table WHERE occurred_at BETWEEN %s AND %s GROUP BY DATE(occurred_at)",
            $start, $end
        ), ARRAY_A);
        $query_error = self::report_query_error('ساخت روند روزانهٔ تردد');
        if (is_wp_error($query_error)) { return $query_error; }
        $finance_days = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(occurred_at) AS report_day,
                SUM(CASE WHEN status='posted' AND transaction_type='income' THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN status='posted' AND transaction_type='expense' AND kind<>'payroll' THEN amount ELSE 0 END) AS expenses,
                SUM(CASE WHEN status='posted' AND kind='payroll' THEN amount ELSE 0 END) AS payroll
             FROM $finance_table WHERE occurred_at BETWEEN %s AND %s GROUP BY DATE(occurred_at)",
            $start, $end
        ), ARRAY_A);
        $query_error = self::report_query_error('ساخت روند روزانهٔ مالی');
        if (is_wp_error($query_error)) { return $query_error; }
        $member_days = $wpdb->get_results($wpdb->prepare(
            "SELECT join_date AS report_day,COUNT(*) AS new_members FROM $members WHERE join_date BETWEEN %s AND %s AND status<>'archived' GROUP BY join_date",
            $from, $to
        ), ARRAY_A);
        $query_error = self::report_query_error('ساخت روند عضوهای جدید');
        if (is_wp_error($query_error)) { return $query_error; }
        $series = array();
        $cursor = $from_date;
        for ($i = 0; $i < $range_days; $i++) {
            $day = $cursor->format('Y-m-d');
            $series[$day] = array('date' => $day, 'label' => wsm_fa_date_short($day . ' 00:00:00'), 'sales' => 0.0, 'orders' => 0, 'income' => 0.0, 'expenses' => 0.0, 'payroll' => 0.0, 'entries' => 0, 'exits' => 0, 'late' => 0, 'unknown_events' => 0, 'new_members' => 0);
            $cursor = $cursor->modify('+1 day');
        }
        foreach ((array) $sales['days'] as $row) {
            if (isset($series[$row['date']])) { $series[$row['date']]['sales'] = (float) $row['total']; $series[$row['date']]['orders'] = (int) $row['count']; }
        }
        foreach ((array) $attendance_days as $row) {
            if (isset($series[$row['report_day']])) { $series[$row['report_day']]['entries'] = (int) $row['entries']; $series[$row['report_day']]['exits'] = (int) $row['exits']; $series[$row['report_day']]['late'] = (int) $row['late']; $series[$row['report_day']]['unknown_events'] = (int) $row['unknown_events']; }
        }
        foreach ((array) $finance_days as $row) {
            if (isset($series[$row['report_day']])) { $series[$row['report_day']]['income'] = (float) $row['income']; $series[$row['report_day']]['expenses'] = (float) $row['expenses']; $series[$row['report_day']]['payroll'] = (float) $row['payroll']; }
        }
        foreach ((array) $member_days as $row) { if (isset($series[$row['report_day']])) { $series[$row['report_day']]['new_members'] = (int) $row['new_members']; } }
        $trainer_data = self::trainer_performance_rows($from, $to);
        if (is_wp_error($trainer_data)) { return $trainer_data; }
        $trainer_data = is_array($trainer_data) ? $trainer_data : array();
        $trainer_data['items'] = isset($trainer_data['items']) && is_array($trainer_data['items']) ? $trainer_data['items'] : array();
        $trainer_data['summary'] = isset($trainer_data['summary']) && is_array($trainer_data['summary']) ? $trainer_data['summary'] : array();
        $trainer_data['summary'] = array_merge(array('trainers'=>0,'trainer_visits'=>0,'payroll_paid'=>0), $trainer_data['summary']);
        $finance = array_merge(array('income'=>0,'expenses'=>0,'payroll'=>0,'cash_removed'=>0,'card_sales'=>0,'transactions'=>0), $finance);
        $finance['net'] = (float) $finance['income'] - (float) $finance['expenses'] - (float) $finance['payroll'];
        $attendance_total = array_sum($attendance_counts);
        $summary = array(
            'sales_total' => (float) $sales['total'], 'orders' => (int) $sales['count'],
            'online_sales' => isset($sales['channels']['online']['total']) ? (float) $sales['channels']['online']['total'] : 0.0,
            'in_person_sales' => isset($sales['channels']['in_person']['total']) ? (float) $sales['channels']['in_person']['total'] : 0.0,
            'all_members' => $all_members, 'active_members' => $active_members, 'new_members' => $new_members,
            'active_memberships' => $active_memberships, 'new_memberships' => $new_memberships,
            'income' => (float) $finance['income'], 'expenses' => (float) $finance['expenses'], 'payroll' => (float) $finance['payroll'],
            'cash_removed' => (float) $finance['cash_removed'], 'card_sales' => (float) $finance['card_sales'], 'net_income' => (float) $finance['net'],
            'transactions' => (int) $finance['transactions'], 'attendance_events' => $attendance_total,
            'entry_events' => $attendance_counts['entry'], 'exit_events' => $attendance_counts['exit'],
            'late_entries' => $attendance_counts['late'], 'unknown_events' => $attendance_counts['unknown'],
            'trainers' => (int) $trainer_data['summary']['trainers'], 'trainer_visits' => (int) $trainer_data['summary']['trainer_visits'],
            'trainer_payroll' => (float) $trainer_data['summary']['payroll_paid'],
        );
        $charts = array(
            'trend' => array_values($series),
            'attendance' => array(
                array('label'=>'ورود','value'=>$attendance_counts['entry']), array('label'=>'دیرکرد','value'=>$attendance_counts['late']),
                array('label'=>'خروج','value'=>$attendance_counts['exit']), array('label'=>'عدم شناسایی','value'=>$attendance_counts['unknown']),
            ),
            'finance' => array(
                array('label'=>'دریافتی','value'=>$summary['income']), array('label'=>'هزینه','value'=>$summary['expenses']),
                array('label'=>'حقوق','value'=>$summary['payroll']), array('label'=>'برداشت صندوق','value'=>$summary['cash_removed']),
            ),
            'trainers' => array_slice($trainer_data['items'], 0, 10),
        );
        return array('summary'=>$summary,'items'=>array_values($series),'charts'=>$charts,'count'=>count($series));
    }

    /** Cost-of-goods snapshot read from order-item metadata; unknown cost stays unknown (never zero). */
    private static function cogs_for_sale_ledger_rows($sale_rows, $collect_details = false) {
        $known_units = 0; $missing_items = 0; $by_month = array(); $by_day = array(); $details = array(); $seen_orders = array();
        foreach ((array) $sale_rows as $sale_row) {
            $order_id = absint($sale_row['order_id'] ?? 0);
            if (!$order_id || isset($seen_orders[$order_id])) { continue; }
            $seen_orders[$order_id] = true;
            $date = substr((string) ($sale_row['occurred_at'] ?? $sale_row['transaction_date'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = current_time('Y-m-d'); }
            $month = substr($date, 0, 7);
            if (!isset($by_month[$month])) { $by_month[$month] = array('units' => 0, 'missing' => 0); }
            if (!isset($by_day[$date])) { $by_day[$date] = array('units' => 0, 'missing' => 0); }
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
            if (!$order instanceof WC_Order) {
                $missing_items++;
                $by_month[$month]['missing']++; $by_day[$date]['missing']++;
                if ($collect_details) { $details[] = array('date' => $date, 'category' => 'بهای تمام‌شده (COGS)', 'type' => 'cogs', 'amount' => '', 'order_id' => $order_id, 'item_name' => 'سفارش پیدا نشد', 'quantity' => '', 'account_name' => '', 'account_type' => '', 'method' => '', 'reference_id' => '', 'source' => 'cogs_snapshot', 'status' => 'missing_cost', 'note' => 'هزینهٔ خرید تاریخی موجود نیست.'); }
                continue;
            }
            $line_count = 0;
            foreach ($order->get_items('line_item') as $line) {
                $line_count++;
                $quantity = absint($line->get_quantity());
                $cost_known = (string) $line->get_meta('_wsm_purchase_cost_known', true) === 'yes';
                $unit_cost = $line->get_meta('_wsm_purchase_cost_snapshot', true);
                $unit_units = $cost_known ? self::decimal_units($unit_cost) : null;
                if ($quantity < 1 || $unit_units === null || $unit_units < 0) {
                    $missing_items++;
                    $by_month[$month]['missing']++; $by_day[$date]['missing']++;
                    if ($collect_details && count($details) < 10000) { $details[] = array('date' => $date, 'category' => 'بهای تمام‌شده (COGS)', 'type' => 'cogs', 'amount' => '', 'order_id' => $order_id, 'item_name' => (string) $line->get_name(), 'quantity' => $quantity, 'account_name' => '', 'account_type' => '', 'method' => '', 'reference_id' => '', 'source' => 'cogs_snapshot', 'status' => 'missing_cost', 'note' => 'بهای خرید در زمان فروش ثبت نشده است.'); }
                    continue;
                }
                $line_units = $unit_units * $quantity;
                $known_units += $line_units;
                $by_month[$month]['units'] += $line_units; $by_day[$date]['units'] += $line_units;
                if ($collect_details && count($details) < 10000) { $details[] = array('date' => $date, 'category' => 'بهای تمام‌شده (COGS)', 'type' => 'cogs', 'amount' => self::decimal_string($line_units), 'order_id' => $order_id, 'item_name' => (string) $line->get_name(), 'quantity' => $quantity, 'account_name' => '', 'account_type' => '', 'method' => '', 'reference_id' => '', 'source' => 'cogs_snapshot', 'status' => 'known', 'note' => 'Snapshot بهای خرید × تعداد فروش.'); }
            }
            if ($line_count === 0) {
                $missing_items++;
                $by_month[$month]['missing']++; $by_day[$date]['missing']++;
                if ($collect_details && count($details) < 10000) { $details[] = array('date' => $date, 'category' => 'بهای تمام‌شده (COGS)', 'type' => 'cogs', 'amount' => '', 'order_id' => $order_id, 'item_name' => 'سفارش بدون اقلام کالا', 'quantity' => '', 'account_name' => '', 'account_type' => '', 'method' => '', 'reference_id' => '', 'source' => 'cogs_snapshot', 'status' => 'missing_cost', 'note' => 'هزینهٔ خرید سفارش قابل محاسبه نیست.'); }
            }
        }
        return array('known_units' => $known_units, 'missing_items' => $missing_items, 'by_month' => $by_month, 'by_day' => $by_day, 'details' => $details, 'orders' => count($seen_orders));
    }

    private static function report_daily_profit_loss($from, $to) {
        global $wpdb;
        $range = (int) (new DateTimeImmutable($from, wp_timezone()))->diff(new DateTimeImmutable($to, wp_timezone()))->days + 1;
        if ($range < 1 || $range > 366) { return new WP_Error('wsm_club_report_range_too_large', 'برای گزارش روزانه سود و زیان، بازهٔ انتخابی حداکثر ۳۶۶ روز است.', array('status' => 400)); }
        $ledger = self::table('financial_ledger');
        $start = $from . ' 00:00:00'; $end = $to . ' 23:59:59';
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ledger WHERE status='posted' AND occurred_at BETWEEN %s AND %s", $start, $end));
        if ($wpdb->last_error !== '') { return new WP_Error('wsm_finance_report_query_failed', 'شمارش تراکنش‌های گزارش سود و زیان انجام نشد.', array('status' => 500)); }
        if ($count > 5000) { return new WP_Error('wsm_finance_report_too_many_rows', 'برای خروجی کامل، بازه را کوتاه‌تر کنید؛ حداکثر ۵۰۰۰ ردیف در هر گزارش پذیرفته می‌شود.', array('status' => 400)); }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT f.id,f.order_id,f.occurred_at,f.transaction_date,f.kind,f.transaction_type,f.amount,f.account_id,f.payment_method,f.method,f.reference_id,f.source,f.status,f.title,f.description,a.account_name,a.account_type FROM $ledger f LEFT JOIN " . self::table('financial_accounts') . " a ON a.id=f.account_id WHERE f.status='posted' AND f.occurred_at BETWEEN %s AND %s ORDER BY f.occurred_at ASC,f.id ASC LIMIT 5001", $start, $end), ARRAY_A);
        if ($wpdb->last_error !== '') { return new WP_Error('wsm_finance_report_query_failed', 'خواندن ردیف‌های سود و زیان انجام نشد.', array('status' => 500)); }
        $items = array(); $sale_rows = array(); $income_units = 0; $expense_units = 0; $cash_movements_units = 0; $online_sales_units = 0; $pos_sales_units = 0; $income_count = 0; $expense_count = 0;
        foreach ((array) $rows as $row) {
            $kind = (string) ($row['kind'] ?? ''); $transaction_type = (string) ($row['transaction_type'] ?? ''); $amount_units = self::decimal_units($row['amount'] ?? null); if ($amount_units === null) { continue; }
            $category = 'غیرسودوزی'; $report_type = $transaction_type;
            if ($transaction_type === 'income') { $category = 'درآمد'; $income_units += $amount_units; $income_count++; if ((string) $row['source'] === 'online_sale') { $online_sales_units += $amount_units; } elseif ($kind === 'pos_sale') { $pos_sales_units += $amount_units; } }
            elseif ($transaction_type === 'expense' && $kind === 'cash_removal') { $category = 'برداشت/انتقال نقد (خارج از سود)'; $report_type = 'cash_movement'; $cash_movements_units += $amount_units; }
            elseif ($transaction_type === 'expense') { $category = $kind === 'pos_refund' ? 'بازپرداخت' : 'هزینه'; $expense_units += $amount_units; $expense_count++; }
            elseif ($transaction_type === 'transfer') { $category = 'انتقال بین حساب‌ها'; }
            elseif ($transaction_type === 'receivable') { $category = 'دریافتنی (خارج از سود)'; }
            elseif ($transaction_type === 'payable') { $category = 'بدهی پرداختنی (خارج از سود)'; }
            elseif ($transaction_type === 'liability') { $category = 'بدهی/اعتبار (خارج از سود)'; }
            elseif ($transaction_type === 'discount') { $category = 'تخفیف'; }
            $date = substr((string) ($row['occurred_at'] ?? $row['transaction_date'] ?? ''), 0, 10);
            $items[] = array('date' => $date, 'category' => $category, 'type' => $report_type, 'amount' => self::decimal_string($amount_units), 'order_id' => absint($row['order_id'] ?? 0), 'item_name' => '', 'quantity' => '', 'account_name' => (string) ($row['account_name'] ?? ''), 'account_type' => (string) ($row['account_type'] ?? ''), 'method' => (string) ($row['payment_method'] ?? $row['method'] ?? ''), 'reference_id' => (string) ($row['reference_id'] ?? ''), 'source' => (string) ($row['source'] ?? ''), 'status' => (string) ($row['status'] ?? ''), 'note' => (string) (($row['title'] ?? '') ?: ($row['description'] ?? '')));
            if ($kind === 'pos_sale' && absint($row['order_id'] ?? 0)) { $sale_rows[] = $row; }
        }
        $cogs = self::cogs_for_sale_ledger_rows($sale_rows, true);
        foreach ((array) $cogs['details'] as $detail) { $items[] = $detail; }
        usort($items, function($a, $b) { return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')) ?: strcmp((string) ($a['type'] ?? ''), (string) ($b['type'] ?? '')) ?: ((int) ($a['order_id'] ?? 0) <=> (int) ($b['order_id'] ?? 0)); });
        $cogs_units = (int) $cogs['known_units']; $net_units = $income_units - $expense_units - $cogs_units; $gross_units = $income_units - $cogs_units;
        $summary = array('income' => self::decimal_string($income_units), 'sales' => self::decimal_string($income_units), 'expenses' => self::decimal_string($expense_units), 'cogs' => self::decimal_string($cogs_units), 'gross_profit' => self::decimal_string($gross_units), 'net_profit' => self::decimal_string($net_units), 'net_profit_estimate' => self::decimal_string($net_units), 'profit_complete' => (int) $cogs['missing_items'] === 0, 'missing_cogs_items' => (int) $cogs['missing_items'], 'cogs_orders' => (int) $cogs['orders'], 'sales_rows' => $income_count, 'expense_rows' => $expense_count, 'cash_removed_outside_profit' => self::decimal_string($cash_movements_units), 'online_sales' => self::decimal_string($online_sales_units), 'in_person_sales' => self::decimal_string($pos_sales_units));
        $daily = array();
        foreach ((array) $rows as $row) {
            $day = substr((string) ($row['occurred_at'] ?? ''), 0, 10); if ($day === '') { continue; }
            if (!isset($daily[$day])) { $daily[$day] = array('date' => $day, 'label' => wsm_fa_date_short($day . ' 00:00:00'), 'income_units' => 0, 'expense_units' => 0, 'cogs_units' => 0, 'missing_cogs_items' => 0); }
            $units = self::decimal_units($row['amount'] ?? null); if ($units === null) { continue; }
            if (($row['transaction_type'] ?? '') === 'income') { $daily[$day]['income_units'] += $units; }
            elseif (($row['transaction_type'] ?? '') === 'expense' && ($row['kind'] ?? '') !== 'cash_removal') { $daily[$day]['expense_units'] += $units; }
        }
        foreach ((array) $cogs['by_day'] as $day => $values) { if (!isset($daily[$day])) { $daily[$day] = array('date' => $day, 'label' => wsm_fa_date_short($day . ' 00:00:00'), 'income_units' => 0, 'expense_units' => 0, 'cogs_units' => 0, 'missing_cogs_items' => 0); } $daily[$day]['cogs_units'] = (int) $values['units']; $daily[$day]['missing_cogs_items'] = (int) $values['missing']; }
        ksort($daily);
        $profit_chart = array(); foreach ($daily as $day => $row) { $profit_chart[] = array('date' => $day, 'label' => $row['label'], 'income' => (float) self::decimal_string($row['income_units']), 'expenses' => (float) self::decimal_string($row['expense_units']), 'cogs' => (float) self::decimal_string($row['cogs_units']), 'net_profit' => (float) self::decimal_string($row['income_units'] - $row['expense_units'] - $row['cogs_units']), 'missing_cogs_items' => (int) $row['missing_cogs_items']); }
        $accounts = self::daily_reconciliation($from, $to);
        return array('items' => array_values($items), 'summary' => $summary, 'charts' => array('profit_loss' => $profit_chart, 'account_balances' => $accounts), 'count' => count($items));
    }

    public static function report($args=array()) {
        global $wpdb;
        $args = is_array($args) ? $args : array();
        $type=isset($args['type'])&&is_scalar($args['type'])?sanitize_key((string)$args['type']):'debtors';
        if ($type==='memberships') { $type='plans'; }
        $today=current_time('Y-m-d');
        $default_from=(new DateTimeImmutable($today,wp_timezone()))->modify('-30 days')->format('Y-m-d');
        $raw_from=!isset($args['from'])||$args['from']===''?$default_from:(is_scalar($args['from'])?trim((string)$args['from']):false);
        $raw_to=!isset($args['to'])||$args['to']===''?$today:(is_scalar($args['to'])?trim((string)$args['to']):false);
        if (class_exists('WSM_Reports') && is_callable(array('WSM_Reports', 'normalize_report_date'))) {
            $from = $raw_from === false ? false : WSM_Reports::normalize_report_date($raw_from);
            $to = $raw_to === false ? false : WSM_Reports::normalize_report_date($raw_to);
            if ($from === '') { $from = $default_from; }
            if ($to === '') { $to = $today; }
        } else {
            $from = is_string($raw_from) ? $raw_from : '';
            $to = is_string($raw_to) ? $raw_to : '';
        }
        if (!is_string($from) || !is_string($to) || !self::valid_range($from,$to)) { return new WP_Error('wsm_club_report_date','بازهٔ گزارش معتبر نیست.',array('status'=>400)); }
        if (in_array($type,array('overview','profit_loss','daily_profit_loss','finance','expenses','costs','card_recharges','cash_removed','pos_debt_payments'),true)) { $core=self::financial_core_ready();if(is_wp_error($core)){return $core;} }
        $m=WSM_Club::table('members'); $ms=WSM_Club::table('memberships'); $pay=WSM_Club::table('payments'); $att=WSM_Club::table('attendance'); $fin=self::table('finance'); $plans=WSM_Club::table('plans');
        $items=array(); $summary=array(); $charts=array();
        if ($type==='overview') {
            $data = self::report_overview($from, $to);
            if (is_wp_error($data)) { return $data; }
            if (!is_array($data)) { return new WP_Error('wsm_club_report_invalid_payload','ساختار دادهٔ گزارش نامعتبر است.',array('status'=>500)); }
            $data['items'] = isset($data['items']) && is_array($data['items']) ? array_values($data['items']) : array();
            $data['summary'] = isset($data['summary']) && is_array($data['summary']) ? $data['summary'] : array();
            $data['charts'] = isset($data['charts']) && is_array($data['charts']) ? $data['charts'] : array();
            $data['count'] = isset($data['count']) ? max(0, (int) $data['count']) : count($data['items']);
            if (empty($data['summary'])) { $data['summary'] = array('records' => count($data['items'])); }
            if (empty($data['charts'])) { $data['charts'] = array('series' => array()); }
            // This is a WordPress REST callback: return data/WP_Error and let REST encode JSON.
            return array_merge(array('type'=>$type,'from'=>$from,'to'=>$to), $data);
         } elseif ($type==='daily_profit_loss') {
            $data=self::report_daily_profit_loss($from,$to);
            if(is_wp_error($data)){return $data;}
            return array_merge(array('type'=>$type,'from'=>$from,'to'=>$to),$data);
        } elseif ($type==='profit_loss') {
            $ledger=self::table('financial_ledger');
            $wpdb->last_error='';
            $rows=$wpdb->get_results($wpdb->prepare("SELECT DATE_FORMAT(transaction_date,'%%Y-%%m') AS month_key,SUM(CASE WHEN status='posted' AND transaction_type='income' THEN amount ELSE 0 END) AS income,SUM(CASE WHEN status='posted' AND transaction_type='expense' THEN amount ELSE 0 END) AS expenses,SUM(CASE WHEN status='posted' AND transaction_type='expense' AND category LIKE '%%خرید کالا%%' THEN amount ELSE 0 END) AS cogs FROM $ledger WHERE transaction_date BETWEEN %s AND %s GROUP BY DATE_FORMAT(transaction_date,'%%Y-%%m') ORDER BY month_key",$from.' 00:00:00',$to.' 23:59:59'),ARRAY_A);
            if($wpdb->last_error!==''){return new WP_Error('wsm_finance_report_query_failed','تهیهٔ گزارش سود و زیان از پایگاه‌داده انجام نشد.',array('status'=>500));}
            $map=array();foreach((array)$rows as $r){$map[$r['month_key']]=array('income'=>self::decimal_units($r['income'])??0,'expenses'=>self::decimal_units($r['expenses'])??0,'cogs'=>self::decimal_units($r['cogs'])??0);}
            $cursor=(new DateTimeImmutable($from,wp_timezone()))->modify('first day of this month');$last=(new DateTimeImmutable($to,wp_timezone()))->modify('first day of this month');$series=array();$income=0;$expenses=0;$cogs=0;$mi=0;$me=0;$mc=0;
            while($cursor<=$last){$key=$cursor->format('Y-m');$v=$map[$key]??array('income'=>0,'expenses'=>0,'cogs'=>0);$series[]=array('date'=>$key,'label'=>wsm_fa_date_short($key.'-01 00:00:00'),'income'=>(float)self::decimal_string($v['income']),'expenses'=>(float)self::decimal_string($v['expenses']),'net_profit'=>(float)self::decimal_string($v['income']-$v['expenses']));$income+=$v['income'];$expenses+=$v['expenses'];$cogs+=$v['cogs'];if($key===$last->format('Y-m')){$mi=$v['income'];$me=$v['expenses'];$mc=$v['cogs'];}$cursor=$cursor->modify('+1 month');}
            $net=$income-$expenses;$gross=$income-$cogs;$summary=array('income'=>self::decimal_string($income),'expense'=>self::decimal_string($expenses),'gross_profit'=>self::decimal_string($gross),'net_profit'=>self::decimal_string($net),'profit_margin'=>$income>0?round($net/$income*100,2):0,'month_income'=>self::decimal_string($mi),'month_expense'=>self::decimal_string($me),'month_gross_profit'=>self::decimal_string($mi-$mc),'month_net_profit'=>self::decimal_string($mi-$me),'month_margin'=>$mi>0?round(($mi-$me)/$mi*100,2):0,'cogs'=>self::decimal_string($cogs));$items=$series;$charts['profit_loss']=$series;
        } elseif ($type==='sales') {
            if (!function_exists('wc_get_orders')) { return new WP_Error('wsm_club_woo_missing','ووکامرس فعال نیست.',array('status'=>500)); }
            $sale_range = (int)(new DateTimeImmutable($from,wp_timezone()))->diff(new DateTimeImmutable($to,wp_timezone()))->days + 1;
            if ($sale_range > 366) { return new WP_Error('wsm_club_report_range_too_large','برای گزارش فروش، بازهٔ انتخابی حداکثر ۳۶۶ روز است.',array('status'=>400)); }
            $sales_data = WSM_Reports::sales($from, $to);
            if (is_wp_error($sales_data)) { return $sales_data; }
            $sales_data = self::include_pos_credit_sales($sales_data, $from, $to);
            if (is_wp_error($sales_data)) { return $sales_data; }
            $items = isset($sales_data['days']) ? $sales_data['days'] : array();
            $summary = array('sales_total'=>(float)$sales_data['total'],'orders'=>(int)$sales_data['count'],
                'online_sales'=>(float)$sales_data['channels']['online']['total'],'online_orders'=>(int)$sales_data['channels']['online']['count'],'in_person_sales'=>(float)$sales_data['channels']['in_person']['total'],'in_person_orders'=>(int)$sales_data['channels']['in_person']['count']);
            $charts['trend'] = array_map(function($row){return array('date'=>$row['date'],'label'=>$row['label'],'total'=>(float)$row['total']);}, $items);
            $charts['sales_channels'] = array(array('label'=>'فروش سایت','value'=>(float)$sales_data['channels']['online']['total']),array('label'=>'فروش حضوری','value'=>(float)$sales_data['channels']['in_person']['total']));
            $methods=array();$products=array();$sales_page=1;$sales_pages=1;
            do{$wpdb->last_error='';$sales_result=wc_get_orders(array('type'=>'shop_order','status'=>array('processing','completed','on-hold'),'limit'=>100,'page'=>$sales_page,'paginate'=>true,'date_created'=>$from.'...'.$to,'orderby'=>'date','order'=>'DESC','return'=>'objects'));if(is_wp_error($sales_result)){return $sales_result;}if($wpdb->last_error!==''){return new WP_Error('wsm_club_report_query_failed','خواندن جزئیات فروش انجام نشد.',array('status'=>500));}$orders=is_object($sales_result)&&isset($sales_result->orders)?(array)$sales_result->orders:(array)$sales_result;$sales_pages=is_object($sales_result)&&isset($sales_result->max_num_pages)?max(1,(int)$sales_result->max_num_pages):1;
                foreach($orders as $order){if(!$order instanceof WC_Order){continue;}$method=trim((string)$order->get_payment_method_title());if($method===''){$method=(string)$order->get_payment_method();}if($method===''){$method='نامشخص';}$value=max(0.0,(float)$order->get_total()-(float)$order->get_total_refunded());if(!isset($methods[$method])){$methods[$method]=array('label'=>$method,'value'=>0.0,'orders'=>0);}$methods[$method]['value']+=$value;$methods[$method]['orders']++;foreach($order->get_items('line_item') as $line){$key=(int)$line->get_product_id().':'.$line->get_name();if(!isset($products[$key])){$products[$key]=array('label'=>(string)$line->get_name(),'name'=>(string)$line->get_name(),'quantity'=>0,'value'=>0.0);}$products[$key]['quantity']+=(int)$line->get_quantity();$products[$key]['value']+=(float)$line->get_total();}}$sales_page++;}while($sales_page<=$sales_pages&&$sales_page<=100);
            usort($products,function($a,$b){return $b['quantity']<=>$a['quantity'];});$charts['payment_methods']=array_values($methods);$charts['top_products']=array_slice(array_values($products),0,10);$charts['trend_30_days']=array_slice($charts['trend'],-30);
        } elseif ($type==='member_pos_debt') {
            $member_id=isset($args['member_id'])?absint($args['member_id']):0;
            if (!$member_id) { return new WP_Error('wsm_club_pos_member_required','شناسهٔ ورزشکار برای ماندهٔ بدهی الزامی است.',array('status'=>400)); }
            if (!function_exists('wc_get_orders')) { return new WP_Error('wsm_club_woo_missing','ووکامرس برای محاسبهٔ بدهی‌های POS فعال نیست.',array('status'=>500)); }
            // Limit the order query to candidate debt orders; verify member/debt metadata below.
            $debt_order_ids=WSM_Util::order_ids_with_meta('_wsm_club_pos_debt','1');
            $debt_due=0.0; $debt_orders=0; $membership_due=0.0; $membership_count=0; $items=array();
            {
                $page=1; $total_pages=1;
                do {
                    $wpdb->last_error='';
                    $result=wc_get_orders(array(
                        'type'=>'shop_order','status'=>self::pos_debt_open_order_statuses(),
                        'limit'=>100,'page'=>$page,'paginate'=>true,'return'=>'objects',
                        'post__in'=>$debt_order_ids?$debt_order_ids:array(0),
                    ));
                    if(is_wp_error($result)){return $result;}
                    if(!empty($wpdb->last_error)){error_log('[WSM Club] Member POS debt query failed: '.$wpdb->last_error);return new WP_Error('wsm_club_pos_debt_query_failed','خواندن بدهی‌های POS عضو انجام نشد.',array('status'=>500));}
                    $orders=is_object($result)&&isset($result->orders)?(array)$result->orders:(array)$result;
                    $total_pages=is_object($result)&&isset($result->max_num_pages)?max(1,(int)$result->max_num_pages):1;
                    foreach($orders as $debt_order){
                        if(!$debt_order instanceof WC_Order){continue;}
                        if(absint($debt_order->get_meta('_wsm_club_member_id',true))!==$member_id){continue;}
                        if('1'!==(string)$debt_order->get_meta('_wsm_club_pos_debt',true)){continue;}
                        $due=self::pos_order_debt_due($debt_order);
                        if($due<=0){continue;}
                        $gross=max(0.0,(float)$debt_order->get_total()-(float)$debt_order->get_total_refunded());
                        $paid=max(0.0,(float)$debt_order->get_meta('_wsm_club_pos_debt_paid_amount',true));
                        $created=$debt_order->get_date_created();
                        $date=$created?$created->date('Y-m-d H:i:s'):'';
                        $debt_due+=$due; $debt_orders++;
                        $history=$debt_order->get_meta('_wsm_club_pos_debt_payment_history',true);
                        $history_out=array();
                        foreach((array)$history as $payment_row){if(!is_array($payment_row)){continue;}$history_out[]=array('amount'=>(string)($payment_row['amount']??'0'),'method'=>(string)($payment_row['method']??''),'reference'=>(string)($payment_row['reference']??''),'note'=>(string)($payment_row['note']??''),'occurred_at'=>(string)($payment_row['occurred_at']??''));}
                        $items[]=array('type'=>'pos_order','id'=>(int)$debt_order->get_id(),'number'=>(string)$debt_order->get_order_number(),'date'=>$date?wsm_fa_date($date):'','date_raw'=>$date,'title'=>'فروش حضوری','amount'=>(string)$gross,'paid_amount'=>(string)$paid,'balance_due'=>(string)$due,'status'=>(string)$debt_order->get_status(),'status_label'=>function_exists('wc_get_order_status_name')?wc_get_order_status_name($debt_order->get_status()):'باز','payment_history'=>$history_out);
                    }
                    $page++;
                } while($page<=$total_pages);
            }
            $wpdb->last_error='';
            $membership_rows=$wpdb->get_results($wpdb->prepare(
                "SELECT x.id,x.plan_name,x.start_date,x.created_at,x.price,COALESCE(SUM(p.amount),0) AS paid
                 FROM $ms x LEFT JOIN $pay p ON p.membership_id=x.id AND p.status='paid'
                 WHERE x.member_id=%d AND x.status<>'cancelled'
                 GROUP BY x.id HAVING x.price>COALESCE(SUM(p.amount),0)
                 ORDER BY x.created_at ASC,x.id ASC",
                $member_id
            ),ARRAY_A);
            if(!empty($wpdb->last_error)){return new WP_Error('wsm_club_member_membership_debt_query_failed','خواندن بدهی عضویت ورزشکار انجام نشد.',array('status'=>500));}
            foreach((array)$membership_rows as $membership_row){
                $due=max(0.0,(float)$membership_row['price']-(float)$membership_row['paid']);
                if($due<=0){continue;}
                $membership_due+=$due; $membership_count++;
                $date=(string)($membership_row['created_at']?:$membership_row['start_date']);
                $items[]=array('type'=>'membership','id'=>(int)$membership_row['id'],'number'=>'عضویت #'.(int)$membership_row['id'],'date'=>$date?wsm_fa_date($date):'','date_raw'=>$date,'title'=>(string)$membership_row['plan_name'],'amount'=>(string)(float)$membership_row['price'],'paid_amount'=>(string)(float)$membership_row['paid'],'balance_due'=>(string)$due,'status'=>'open','status_label'=>'بدهی باز');
            }
            usort($items,function($a,$b){return strcmp((string)($a['date_raw']??''),(string)($b['date_raw']??'')) ?: ((int)$a['id']<=>(int)$b['id']);});
            $summary=array('debt_due'=>(string)$debt_due,'orders'=>$debt_orders,'membership_due'=>(string)$membership_due,'membership_count'=>$membership_count,'total_debt_due'=>(string)($debt_due+$membership_due));
} elseif ($type==='member_pos_orders') {
            if (!function_exists('wc_get_orders')) { return new WP_Error('wsm_club_woo_missing','ووکامرس فعال نیست.',array('status'=>500)); }
            $member_id=isset($args['member_id'])?absint($args['member_id']):0;
            if (!$member_id) { return new WP_Error('wsm_club_pos_member_required','شناسهٔ ورزشکار برای تاریخچهٔ خرید الزامی است.',array('status'=>400)); }
            $page=1; $total_pages=1; $summary=array('orders'=>0,'purchase_total'=>0.0,'debt_due'=>0.0); $items=array();
            // Query by known POS order IDs because meta_query is not portable to the legacy order datastore.
            $pos_order_ids=WSM_Util::order_ids_with_meta('_wsm_club_pos','1');
            do {
                $wpdb->last_error='';
                $result=wc_get_orders(array(
                    'type'=>'shop_order','status'=>array_values(array_unique(array_merge(self::pos_debt_open_order_statuses(),array('cancelled','refunded')))), 
                    'limit'=>100,'page'=>$page,'paginate'=>true,'orderby'=>'date','order'=>'DESC','return'=>'objects',
                    'date_created'=>$from.'...'.$to,
                    'post__in'=>$pos_order_ids?$pos_order_ids:array(0)
                ));
                if(is_wp_error($result)){return $result;}
                if(!empty($wpdb->last_error)){return new WP_Error('wsm_club_pos_orders_query_failed','خواندن تاریخچهٔ خرید ورزشکار انجام نشد.',array('status'=>500));}
                $orders=is_object($result)&&isset($result->orders)?(array)$result->orders:(array)$result;
                $total_pages=is_object($result)&&isset($result->max_num_pages)?max(1,(int)$result->max_num_pages):1;
                foreach ($orders as $pos_order) {
                    if (!$pos_order instanceof WC_Order) { continue; }
                    if (absint($pos_order->get_meta('_wsm_club_member_id', true)) !== $member_id) { continue; }
                    if ('1' !== (string) $pos_order->get_meta('_wsm_club_pos', true)) { continue; }
                    $status=(string)$pos_order->get_status();
                    $is_debt=(bool)$pos_order->get_meta('_wsm_club_pos_debt');
                    $due=$is_debt&&!in_array($status,array('cancelled','refunded','trash'),true)?self::pos_order_debt_due($pos_order):0.0;
                    $net_total=max(0,(float)$pos_order->get_total()-(float)$pos_order->get_total_refunded());
                    $summary['orders']++; if (!in_array($status,array('cancelled','refunded'),true)) { $summary['purchase_total']+=$net_total; }
                    $summary['debt_due']+=$due;
                    if (count($items)>=500) { continue; }
                    $order_lines=array();
                    foreach ($pos_order->get_items('line_item') as $line) {
                        $order_lines[]=array('name'=>(string)$line->get_name(),'qty'=>(int)$line->get_quantity(),'total'=>(string)((float)$line->get_total()+(float)$line->get_total_tax()));
                    }
                    $payment_history=$pos_order->get_meta('_wsm_club_pos_debt_payment_history',true);$payment_history_out=array();foreach((array)$payment_history as $payment_row){if(!is_array($payment_row)){continue;}$payment_history_out[]=array('amount'=>(string)($payment_row['amount']??'0'),'method'=>(string)($payment_row['method']??''),'reference'=>(string)($payment_row['reference']??''),'note'=>(string)($payment_row['note']??''),'occurred_at'=>(string)($payment_row['occurred_at']??''));}
                    $items[]=array('order_id'=>(int)$pos_order->get_id(),'number'=>(string)$pos_order->get_order_number(),'date'=>$pos_order->get_date_created()?wsm_fa_date($pos_order->get_date_created()->date('Y-m-d H:i:s')):'','status'=>$status,'status_label'=>wc_get_order_status_name($status),'total'=>(string)$net_total,'debt_due'=>(string)$due,'debt_payment_history'=>$payment_history_out,'payment_method'=>(string)($pos_order->get_meta('_wsm_club_pos_payment_method')?:$pos_order->get_payment_method_title()),'reference'=>(string)$pos_order->get_meta('_wsm_payment_reference'),'items'=>$order_lines);
                }
                $page++;
            } while ($page<=$total_pages);
            $summary['purchase_total']=(string)$summary['purchase_total']; $summary['debt_due']=(string)$summary['debt_due'];
        } elseif ($type==='pos_debt_payments') {
            $limit=max(1,min(500,(int)(isset($args['per_page'])?$args['per_page']:(isset($args['limit'])?$args['limit']:100))));
            $offset=max(0,min(100000000,(int)(isset($args['offset'])?$args['offset']:0)));
            $wpdb->last_error='';
            $total=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $fin WHERE kind='member_payment' AND category='pos_debt_settlement' AND occurred_at BETWEEN %s AND %s",
                $from.' 00:00:00',$to.' 23:59:59'
            ));
            $query_error = self::report_query_error('شمارش تسویه‌های بدهی POS');
            if (is_wp_error($query_error)) { return $query_error; }
            $wpdb->last_error='';
            $items=$wpdb->get_results($wpdb->prepare(
                "SELECT f.id,f.member_id,f.membership_id,f.order_id,f.amount,f.method,f.reference,f.description,f.occurred_at,f.status,
                        u.member_code,u.first_name,u.last_name,u.phone,CONCAT_WS(' ',NULLIF(u.first_name,''),NULLIF(u.last_name,'')) AS member_name
                 FROM $fin f LEFT JOIN $m u ON u.id=f.member_id
                 WHERE f.kind='member_payment' AND f.category='pos_debt_settlement' AND f.occurred_at BETWEEN %s AND %s
                 ORDER BY f.occurred_at DESC,f.id DESC LIMIT %d OFFSET %d",
                $from.' 00:00:00',$to.' 23:59:59',$limit,$offset
            ),ARRAY_A);
            $query_error = self::report_query_error('خواندن تسویه‌های بدهی POS');
            if (is_wp_error($query_error)) { return $query_error; }
            $items = is_array($items) ? $items : array();
            $summary=array('total_count'=>$total,'offset'=>$offset,'limit'=>$limit);

        } elseif ($type==='trainer_performance') {
            $trainer_data = self::trainer_performance_rows($from, $to);
            if (is_wp_error($trainer_data)) { return $trainer_data; }
            $trainer_data = is_array($trainer_data) ? $trainer_data : array();
            $items = isset($trainer_data['items']) && is_array($trainer_data['items']) ? $trainer_data['items'] : array();
            $summary = isset($trainer_data['summary']) && is_array($trainer_data['summary']) ? $trainer_data['summary'] : array();
            $charts['trainers'] = array_slice($items, 0, 10);
        } elseif ($type==='expiring_members') {
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT u.id,u.member_code,u.card_number,u.first_name,u.last_name,
                        CONCAT_WS(' ',NULLIF(u.first_name,''),NULLIF(u.last_name,'')) AS name,u.phone,
                        x.id AS membership_id,x.plan_name,x.end_date,DATEDIFF(x.end_date,%s) AS days_left
                 FROM $ms x INNER JOIN $m u ON u.id=x.member_id
                 WHERE u.status='active' AND x.status IN ('active','scheduled')
                   AND x.start_date<=%s AND x.end_date>=%s
                 ORDER BY x.end_date ASC,u.last_name ASC,u.first_name ASC,x.id DESC LIMIT 5",
                $today,$today,$today
            ), ARRAY_A);
            $query_error = self::report_query_error('خواندن عضویت‌های نزدیک به پایان');
            if (is_wp_error($query_error)) { return $query_error; }
        } elseif ($type==='expired_members') {
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT u.id,u.member_code,u.card_number,u.first_name,u.last_name,
                        CONCAT_WS(' ',NULLIF(u.first_name,''),NULLIF(u.last_name,'')) AS name,u.phone,
                        x.id AS membership_id,x.plan_id,x.plan_name,COALESCE(p.active,0) AS plan_active,x.end_date,DATEDIFF(%s,x.end_date) AS days_overdue
                 FROM $ms x INNER JOIN $m u ON u.id=x.member_id LEFT JOIN $plans p ON p.id=x.plan_id
                 WHERE u.status='active' AND x.status IN ('active','scheduled','expired') AND x.end_date<%s
                   AND x.id=(SELECT old.id FROM $ms old WHERE old.member_id=x.member_id
                             AND old.status IN ('active','scheduled','expired') AND old.end_date<%s
                             ORDER BY old.end_date DESC,old.id DESC LIMIT 1)
                   AND NOT EXISTS (SELECT 1 FROM $ms renewed WHERE renewed.member_id=x.member_id
                                   AND renewed.status IN ('active','scheduled') AND renewed.end_date>=%s)
                 ORDER BY x.end_date DESC,u.last_name ASC,u.first_name ASC LIMIT 500",
                $today,$today,$today,$today
            ), ARRAY_A);
            $query_error = self::report_query_error('خواندن عضویت‌های پایان‌یافته');
            if (is_wp_error($query_error)) { return $query_error; }
        } elseif ($type==='members') {
            $tables_ready = self::report_tables_ready(array($m, $ms));
            if (is_wp_error($tables_ready)) { return $tables_ready; }
            $items = $wpdb->get_results($wpdb->prepare("SELECT id,member_code,card_number,first_name,last_name,phone,email,join_date,status,coach_id FROM $m WHERE join_date BETWEEN %s AND %s AND status<>'archived' ORDER BY join_date DESC,id DESC LIMIT 1000", $from, $to), ARRAY_A);
            $query_error = self::report_query_error('خواندن فهرست اعضا');
            if (is_wp_error($query_error)) { return $query_error; }
            $items = is_array($items) ? $items : array();
            $all_members = (int) $wpdb->get_var("SELECT COUNT(*) FROM $m WHERE status<>'archived'");
            $query_error = self::report_query_error('شمارش کل اعضا');
            if (is_wp_error($query_error)) { return $query_error; }
            $active_members = (int) $wpdb->get_var("SELECT COUNT(*) FROM $m WHERE status='active'");
            $query_error = self::report_query_error('شمارش اعضای فعال');
            if (is_wp_error($query_error)) { return $query_error; }
            $new_members = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $m WHERE join_date BETWEEN %s AND %s AND status<>'archived'", $from, $to));
            $query_error = self::report_query_error('شمارش اعضای تازه‌ثبت‌شده');
            if (is_wp_error($query_error)) { return $query_error; }
            $report_today = current_time('Y-m-d');
            $active_memberships = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ms WHERE status='active' AND start_date<=%s AND end_date>=%s", $report_today, $report_today));
            $query_error = self::report_query_error('شمارش عضویت‌های فعال');
            if (is_wp_error($query_error)) { return $query_error; }
            $summary = array('all_members'=>$all_members,'active_members'=>$active_members,'new_members'=>$new_members,'active_memberships'=>$active_memberships);
            $member_days = $wpdb->get_results($wpdb->prepare("SELECT join_date AS report_day,COUNT(*) AS new_members FROM $m WHERE join_date BETWEEN %s AND %s AND status<>'archived' GROUP BY join_date ORDER BY join_date ASC", $from, $to), ARRAY_A);
            $query_error = self::report_query_error('محاسبهٔ روند ثبت‌نام اعضا');
            if (is_wp_error($query_error)) { return $query_error; }
            $charts['member_trend'] = array_map(function($row){return array('date'=>$row['report_day'],'label'=>wsm_fa_date_short($row['report_day'].' 00:00:00'),'new_members'=>(int)$row['new_members']);}, (array) $member_days);
        } elseif ($type==='debtors') {
            $items=$wpdb->get_results(
                "SELECT u.id,u.member_code,u.card_number,u.first_name,u.last_name,u.phone,
                        COALESCE(SUM(GREATEST(0,x.price-COALESCE(p.paid,0))),0) AS balance_due,
                        COALESCE(SUM(GREATEST(0,x.price-COALESCE(p.paid,0))),0) AS membership_due,
                        MAX(CASE WHEN x.price>COALESCE(p.paid,0) THEN p.last_paid ELSE NULL END) AS last_transaction,
                        MIN(CASE WHEN x.price>COALESCE(p.paid,0) THEN x.created_at ELSE NULL END) AS oldest_debt_at
                 FROM $m u INNER JOIN $ms x ON x.member_id=u.id
                 LEFT JOIN (SELECT membership_id,SUM(amount) AS paid,MAX(paid_at) AS last_paid FROM $pay WHERE status='paid' GROUP BY membership_id) p ON p.membership_id=x.id
                 WHERE x.status<>'cancelled'
                 GROUP BY u.id HAVING balance_due>0 ORDER BY balance_due DESC LIMIT 5000",
                ARRAY_A
            );
            if($wpdb->last_error!==''){return new WP_Error('wsm_debtors_query_failed','خواندن بدهکاران انجام نشد.',array('status'=>500));}
            $debtor_index=array();
            foreach((array)$items as $index=>$debtor){
                $member_id=(int)$debtor['id']; $debtor_index[$member_id]=$index;
                $items[$index]['name']=trim((string)$debtor['first_name'].' '.(string)$debtor['last_name']);
                $items[$index]['membership_due']=(float)$debtor['membership_due'];
                $items[$index]['balance_due']=(float)$debtor['balance_due'];
                $items[$index]['pos_debt_due']=0.0; $items[$index]['pos_debt_orders']=0;
                $items[$index]['last_transaction']=(string)($debtor['last_transaction']??'');
                $items[$index]['oldest_debt_at']=(string)($debtor['oldest_debt_at']??'');
            }
            if (!function_exists('wc_get_orders')) { return new WP_Error('wsm_club_woo_missing','ووکامرس برای محاسبهٔ بدهی‌های POS فعال نیست.',array('status'=>500)); }
            {
                $report_pos_debt_ids=WSM_Util::order_ids_with_meta('_wsm_club_pos_debt','1');
                $pos_page=1; $pos_pages=1;
                do {
                    $wpdb->last_error='';
                    $pos_result=wc_get_orders(array(
                        'type'=>'shop_order','status'=>self::pos_debt_open_order_statuses(),
                        'limit'=>100,'page'=>$pos_page,'paginate'=>true,'return'=>'objects',
                        'post__in'=>$report_pos_debt_ids?$report_pos_debt_ids:array(0),
                    ));
                    if(is_wp_error($pos_result)){return $pos_result;}
                    if(!empty($wpdb->last_error)){error_log('[WSM Club] Debtors POS query failed: '.$wpdb->last_error);return new WP_Error('wsm_debtors_pos_query_failed','خواندن بدهی‌های POS انجام نشد.',array('status'=>500));}
                    $pos_orders=is_object($pos_result)&&isset($pos_result->orders)?(array)$pos_result->orders:(array)$pos_result;
                    $pos_pages=is_object($pos_result)&&isset($pos_result->max_num_pages)?max(1,(int)$pos_result->max_num_pages):1;
                    foreach((array)$pos_orders as $pos_order){
                        if(!$pos_order instanceof WC_Order){continue;}
                        if('1'!==(string)$pos_order->get_meta('_wsm_club_pos_debt',true)){continue;}
                        $member_id=absint($pos_order->get_meta('_wsm_club_member_id',true));
                        $due=self::pos_order_debt_due($pos_order);
                        if(!$member_id||$due<=0){continue;}
                        if(!isset($debtor_index[$member_id])){
                            $wpdb->last_error='';
                            $member_row=$wpdb->get_row($wpdb->prepare("SELECT id,member_code,card_number,first_name,last_name,phone FROM $m WHERE id=%d",$member_id),ARRAY_A);
                            if(!empty($wpdb->last_error)){return new WP_Error('wsm_debtors_member_lookup_failed','خواندن مشخصات ورزشکار دارای بدهی POS انجام نشد.',array('status'=>500));}
                            if(!$member_row){continue;}
                            $member_row['name']=trim((string)$member_row['first_name'].' '.(string)$member_row['last_name']);
                            $member_row['balance_due']=0.0; $member_row['membership_due']=0.0; $member_row['pos_debt_due']=0.0; $member_row['pos_debt_orders']=0; $member_row['last_transaction']=''; $member_row['oldest_debt_at']='';
                            $debtor_index[$member_id]=count($items); $items[]=$member_row;
                        }
                        $idx=$debtor_index[$member_id];
                        $items[$idx]['balance_due']=(float)$items[$idx]['balance_due']+$due;
                        $items[$idx]['pos_debt_due']=(float)$items[$idx]['pos_debt_due']+$due;
                        $items[$idx]['pos_debt_orders']=(int)$items[$idx]['pos_debt_orders']+1;
                        $created=$pos_order->get_date_created();
                        $order_date=$created?$created->date('Y-m-d H:i:s'):'';
                        $paid_date=(string)$pos_order->get_meta('_wsm_club_pos_debt_last_paid_at',true);
                        $last_candidate=max($order_date,$paid_date);
                        if($last_candidate!==''&&($items[$idx]['last_transaction']===''||strcmp($last_candidate,(string)$items[$idx]['last_transaction'])>0)){$items[$idx]['last_transaction']=$last_candidate;}
                        if($order_date!==''&&($items[$idx]['oldest_debt_at']===''||strcmp($order_date,(string)$items[$idx]['oldest_debt_at'])<0)){$items[$idx]['oldest_debt_at']=$order_date;}
                    }
                    $pos_page++;
                } while($pos_page<=$pos_pages);
            }
            $items=array_values(array_filter((array)$items,function($debtor){return (float)($debtor['balance_due']??0)>0;}));
            $needle=isset($args['search'])&&is_scalar($args['search'])?trim((string)$args['search']):'';
            if($needle!==''){
                if(class_exists('WSM_Persian_Normalizer')){$needle=WSM_Persian_Normalizer::normalize($needle);}
                $items=array_values(array_filter($items,function($debtor)use($needle){
                    $haystack=implode(' ',array((string)($debtor['first_name']??''),(string)($debtor['last_name']??''),(string)($debtor['phone']??''),(string)($debtor['member_code']??''),(string)($debtor['card_number']??'')));
                    return function_exists('mb_stripos')?mb_stripos($haystack,$needle,0,'UTF-8')!==false:stripos($haystack,$needle)!==false;
                }));
            }
            $sort=isset($args['sort'])&&is_scalar($args['sort'])?sanitize_key((string)$args['sort']):'highest';
            usort($items,function($a,$b)use($sort){
                if($sort==='lowest'){return (float)$a['balance_due']<=>(float)$b['balance_due'] ?: strcmp((string)$a['member_code'],(string)$b['member_code']);}
                if($sort==='oldest'){$ad=(string)($a['oldest_debt_at']?:$a['last_transaction']?:'9999');$bd=(string)($b['oldest_debt_at']?:$b['last_transaction']?:'9999');return strcmp($ad,$bd) ?: ((int)$a['id']<=>(int)$b['id']);}
                if($sort==='newest'){$ad=(string)($a['last_transaction']?:$a['oldest_debt_at']?:'0000');$bd=(string)($b['last_transaction']?:$b['oldest_debt_at']?:'0000');return strcmp($bd,$ad) ?: ((int)$b['id']<=>(int)$a['id']);}
                return (float)$b['balance_due']<=>(float)$a['balance_due'] ?: strcmp((string)$a['member_code'],(string)$b['member_code']);
            });
            if(!empty($args['date_filter'])){$items=array_values(array_filter($items,function($row)use($from,$to){$date=substr((string)(($row['oldest_debt_at']??'')?:($row['last_transaction']??'')),0,10);return $date===''||($date>=$from&&$date<=$to);}));}
            $items=array_slice($items,0,5000);
            $summary=array('debtors'=>count($items),'debt_total'=>(string)array_sum(array_map(function($row){return (float)$row['balance_due'];},$items)));
            $rank=$items;usort($rank,function($a,$b){return (float)($b['balance_due']??0)<=>(float)($a['balance_due']??0);});$charts['top_debtors']=array_map(function($row){$name=trim((string)($row['name']??''));return array('label'=>$name!==''?$name:(string)($row['member_code']??'—'),'member_code'=>(string)($row['member_code']??''),'balance_due'=>(float)($row['balance_due']??0));},array_slice($rank,0,10));
            $age=array('۰ تا ۳۰ روز'=>0.0,'۳۱ تا ۶۰ روز'=>0.0,'۶۱ تا ۹۰ روز'=>0.0,'بیش از ۹۰ روز'=>0.0);$today_stamp=(new DateTimeImmutable(current_time('Y-m-d'),wp_timezone()))->getTimestamp();foreach($items as $debtor){$old=(string)($debtor['oldest_debt_at']??'');$stamp=$old?strtotime($old):$today_stamp;$days=max(0,(int)floor(($today_stamp-$stamp)/DAY_IN_SECONDS));$bucket=$days<=30?'۰ تا ۳۰ روز':($days<=60?'۳۱ تا ۶۰ روز':($days<=90?'۶۱ تا ۹۰ روز':'بیش از ۹۰ روز'));$age[$bucket]+=(float)($debtor['balance_due']??0);}$charts['debt_age']=array_map(function($label,$value){return array('label'=>$label,'value'=>$value);},array_keys($age),array_values($age));
        } elseif ($type==='codes'||$type==='profiles') {
            $items=$wpdb->get_results("SELECT id,member_code,card_number,first_name,last_name,phone,email,join_date,birth_date,gender,marital_status,insurance_number,insurance_expiry,address,status FROM $m ORDER BY id DESC LIMIT 500",ARRAY_A);
            $query_error = self::report_query_error('خواندن کدها و مشخصات اعضا');
            if (is_wp_error($query_error)) { return $query_error; }
            $items = is_array($items) ? $items : array();
            $active_count = count(array_filter($items, function($row){ return isset($row['status']) && $row['status'] === 'active'; }));
            $summary = array('all_members' => count($items), 'active_members' => $active_count);
        } elseif ($type==='plans') {
            $tables_ready = self::report_tables_ready(array($ms, $pay));
            if (is_wp_error($tables_ready)) { return $tables_ready; }
            $items = $wpdb->get_results($wpdb->prepare("SELECT plan_name,COUNT(*) AS registrations,SUM(price) AS billed,SUM(discount_amount) AS discounts,SUM(GREATEST(0,price-COALESCE(paid,0))) AS debt FROM $ms LEFT JOIN (SELECT membership_id,SUM(amount) paid FROM $pay WHERE status='paid' GROUP BY membership_id) q ON q.membership_id=$ms.id WHERE $ms.status<>'cancelled' AND $ms.start_date BETWEEN %s AND %s GROUP BY plan_name ORDER BY registrations DESC", $from, $to), ARRAY_A);
            $query_error = self::report_query_error('خواندن گزارش تعرفه و ثبت‌نام');
            if (is_wp_error($query_error)) { return $query_error; }
            $items = is_array($items) ? $items : array();
            $registrations = 0; $billed_units = 0; $discount_units = 0; $debt_units = 0;
            foreach ($items as $plan_row) {
                $registrations += max(0, (int) ($plan_row['registrations'] ?? 0));
                $billed_units += self::decimal_units($plan_row['billed'] ?? '0') ?? 0;
                $discount_units += self::decimal_units($plan_row['discounts'] ?? '0') ?? 0;
                $debt_units += self::decimal_units($plan_row['debt'] ?? '0') ?? 0;
            }
            $summary = array('registrations' => $registrations, 'billed' => self::decimal_string($billed_units), 'discounts' => self::decimal_string($discount_units), 'debt' => self::decimal_string($debt_units));
        } elseif ($type==='attendance'||$type==='entry_exit') {
            $wpdb->last_error='';
            $items=$wpdb->get_results($wpdb->prepare("SELECT a.*,u.first_name,u.last_name,u.card_number,x.plan_name FROM $att a LEFT JOIN $m u ON u.id=a.member_id LEFT JOIN $ms x ON x.id=a.membership_id WHERE a.occurred_at BETWEEN %s AND %s ORDER BY a.occurred_at DESC LIMIT 1000",$from.' 00:00:00',$to.' 23:59:59'),ARRAY_A);
            $query_error = self::report_query_error('خواندن گزارش تردد');
            if (is_wp_error($query_error)) { return $query_error; }
            $items = is_array($items) ? $items : array();
            $wpdb->last_error='';
            $counts=$wpdb->get_results($wpdb->prepare("SELECT event_type,COUNT(*) AS total FROM $att WHERE occurred_at BETWEEN %s AND %s GROUP BY event_type",$from.' 00:00:00',$to.' 23:59:59'),ARRAY_A);
            $query_error = self::report_query_error('شمارش رویدادهای تردد');
            if (is_wp_error($query_error)) { return $query_error; }
            $summary=array('entry_events'=>0,'exit_events'=>0,'late_entries'=>0,'unknown_events'=>0);
            foreach((array)$counts as $count_row){$key=(string)$count_row['event_type'];if($key==='entry'){$summary['entry_events']=(int)$count_row['total'];}elseif($key==='exit'){$summary['exit_events']=(int)$count_row['total'];}elseif($key==='late'){$summary['late_entries']=(int)$count_row['total'];}elseif($key==='unknown'){$summary['unknown_events']=(int)$count_row['total'];}}
            $summary['attendance_events']=array_sum($summary);
            $charts['attendance']=array(array('label'=>'ورود','value'=>$summary['entry_events']),array('label'=>'دیرکرد','value'=>$summary['late_entries']),array('label'=>'خروج','value'=>$summary['exit_events']),array('label'=>'عدم شناسایی','value'=>$summary['unknown_events']));
        } elseif ($type==='payments') {
            $wpdb->last_error='';
            $items=$wpdb->get_results($wpdb->prepare("SELECT p.*,u.member_code,u.card_number,u.first_name,u.last_name,x.plan_name FROM $pay p LEFT JOIN $m u ON u.id=p.member_id LEFT JOIN $ms x ON x.id=p.membership_id WHERE p.paid_at BETWEEN %s AND %s ORDER BY p.paid_at DESC LIMIT 1000",$from.' 00:00:00',$to.' 23:59:59'),ARRAY_A);
            $query_error = self::report_query_error('خواندن واریزی‌های عضویت');
            if (is_wp_error($query_error)) { return $query_error; }
            $items = is_array($items) ? $items : array();
            $paid_units = 0;
            foreach ($items as $payment_row) {
                if (($payment_row['status'] ?? '') === 'paid') { $paid_units += self::decimal_units($payment_row['amount'] ?? '0') ?? 0; }
            }
            $summary = array('income' => self::decimal_string($paid_units), 'orders' => count($items));
        } elseif ($type==='sessions') {
            $wpdb->last_error='';
            $items=$wpdb->get_results($wpdb->prepare("SELECT x.id,x.member_id,u.member_code,u.card_number,u.first_name,u.last_name,x.plan_name,x.max_visits,x.sessions_used_before,COALESCE(a.used_now,0) AS used_now,(x.sessions_used_before+COALESCE(a.used_now,0)) AS total_used,x.status,x.start_date,x.end_date FROM $ms x LEFT JOIN $m u ON u.id=x.member_id LEFT JOIN (SELECT membership_id,COUNT(*) AS used_now FROM $att WHERE direction='in' AND decision='allowed' AND occurred_at BETWEEN %s AND %s GROUP BY membership_id) a ON a.membership_id=x.id ORDER BY x.end_date DESC LIMIT 1000",$from.' 00:00:00',$to.' 23:59:59'),ARRAY_A);
            $query_error = self::report_query_error('خواندن گزارش تعداد جلسات');
            if (is_wp_error($query_error)) { return $query_error; }
            $items = is_array($items) ? $items : array();
            $used_sum = array_sum(array_map(function($row){ return max(0, (int) ($row['used_now'] ?? 0)); }, $items));
            $summary = array('active_memberships' => count($items), 'total_used' => $used_sum);
        } elseif ($type==='finance'||$type==='expenses'||$type==='cash_removed'||$type==='card_recharges'||$type==='costs') {
            $kind=''; if($type==='expenses'){$kind=" AND kind='expense'";}elseif($type==='cash_removed'){$kind=" AND kind='cash_removal'";}elseif($type==='card_recharges'){$kind=" AND kind='card_recharge'";}elseif($type==='costs'){$kind=" AND kind IN ('expense','cash_removal','payroll','pos_refund')";}
            $posted_filter=$type==='finance'?'':" AND status='posted'";
            $wpdb->last_error='';
            $items=$wpdb->get_results($wpdb->prepare("SELECT * FROM $fin WHERE occurred_at BETWEEN %s AND %s $posted_filter $kind ORDER BY occurred_at DESC LIMIT 1000",$from.' 00:00:00',$to.' 23:59:59'),ARRAY_A);
            $query_error = self::report_query_error('خواندن گردش مالی گزارش');
            if (is_wp_error($query_error)) { return $query_error; }
            $items = is_array($items) ? $items : array();
            $wpdb->last_error='';
            $summary=(array)$wpdb->get_row($wpdb->prepare("SELECT
                COALESCE(SUM(CASE WHEN status='posted' AND transaction_type='income' THEN amount ELSE 0 END),0) AS income,
                COALESCE(SUM(CASE WHEN status='posted' AND transaction_type='expense' AND kind<>'payroll' THEN amount ELSE 0 END),0) AS expenses,
                COALESCE(SUM(CASE WHEN status='posted' AND kind='payroll' THEN amount ELSE 0 END),0) AS payroll,
                COALESCE(SUM(CASE WHEN status='posted' AND kind='cash_removal' THEN amount ELSE 0 END),0) AS cash_removed,
                COALESCE(SUM(CASE WHEN status='posted' AND kind='card_spend' THEN amount ELSE 0 END),0) AS card_sales
                FROM $fin WHERE occurred_at BETWEEN %s AND %s",$from.' 00:00:00',$to.' 23:59:59'),ARRAY_A);
            $query_error = self::report_query_error('محاسبهٔ جمع‌های گردش مالی');
            if (is_wp_error($query_error)) { return $query_error; }
            $summary['net_income']=(float)($summary['income']??0)-(float)($summary['expenses']??0)-(float)($summary['payroll']??0);
            $charts['finance']=array(array('label'=>'دریافتی','value'=>(float)$summary['income']),array('label'=>'هزینه','value'=>(float)$summary['expenses']),array('label'=>'حقوق','value'=>(float)$summary['payroll']),array('label'=>'برداشت صندوق','value'=>(float)$summary['cash_removed']));
            if($type==='expenses'){$wpdb->last_error='';$categories=$wpdb->get_results($wpdb->prepare("SELECT COALESCE(NULLIF(category,''),'سایر') AS label,COALESCE(SUM(amount),0) AS value FROM $fin WHERE status='posted' AND kind='expense' AND occurred_at BETWEEN %s AND %s GROUP BY COALESCE(NULLIF(category,''),'سایر') ORDER BY value DESC LIMIT 12",$from.' 00:00:00',$to.' 23:59:59'),ARRAY_A);$query_error=self::report_query_error('گروه‌بندی دسته‌های هزینه');if(is_wp_error($query_error)){return $query_error;}$charts['expense_categories']=(array)$categories;}
            if($type==='costs'){$wpdb->last_error='';$cost_rows=$wpdb->get_results($wpdb->prepare("SELECT kind,COALESCE(SUM(amount),0) AS value FROM $fin WHERE status='posted' AND kind IN ('expense','cash_removal','payroll','pos_refund') AND occurred_at BETWEEN %s AND %s GROUP BY kind ORDER BY value DESC",$from.' 00:00:00',$to.' 23:59:59'),ARRAY_A);$query_error=self::report_query_error('گروه‌بندی هزینه‌ها و برداشت صندوق');if(is_wp_error($query_error)){return $query_error;}$kind_labels=array('expense'=>'هزینه','cash_removal'=>'برداشت صندوق','payroll'=>'حقوق','pos_refund'=>'بازپرداخت فروش');$charts['cost_breakdown']=array_map(function($row)use($kind_labels){$row['label']=isset($kind_labels[$row['kind']])?$kind_labels[$row['kind']]:$row['kind'];return $row;},(array)$cost_rows);}
        } elseif ($type==='payroll') {
            $payroll_table=WSM_Club::table('payroll'); $staff_table=WSM_Club::table('staff');
            $wpdb->last_error='';
            $items=$wpdb->get_results($wpdb->prepare("SELECT p.id,p.staff_id,s.name AS staff_name,s.role AS staff_role,p.period_start,p.period_end,p.gross,p.deductions,p.net,p.status,p.paid_at,p.note FROM $payroll_table p LEFT JOIN $staff_table s ON s.id=p.staff_id WHERE p.period_start<=%s AND p.period_end>=%s ORDER BY p.period_start DESC,p.id DESC LIMIT 1000",$to,$from),ARRAY_A);
            $query_error = self::report_query_error('خواندن فیش‌های حقوق');
            if (is_wp_error($query_error)) { return $query_error; }
            $items = is_array($items) ? $items : array();
            $wpdb->last_error='';
            $paid_total=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(net),0) FROM $payroll_table WHERE status='paid' AND paid_at BETWEEN %s AND %s",$from.' 00:00:00',$to.' 23:59:59'));
            $query_error = self::report_query_error('محاسبهٔ حقوق پرداخت‌شده');
            if (is_wp_error($query_error)) { return $query_error; }
            $summary=array('paid'=>$paid_total,'records'=>count($items));
        } elseif ($type==='insurance') {
            $wpdb->last_error='';
            $items=$wpdb->get_results($wpdb->prepare("SELECT id,member_code,card_number,first_name,last_name,phone,insurance_number,insurance_expiry FROM $m WHERE insurance_expiry IS NOT NULL AND insurance_expiry BETWEEN %s AND %s ORDER BY insurance_expiry ASC",$from,$to),ARRAY_A);
            $query_error = self::report_query_error('خواندن گزارش پایان بیمه');
            if (is_wp_error($query_error)) { return $query_error; }
            $items = is_array($items) ? $items : array();
            $summary = array('active_members' => count($items));
        } elseif ($type==='sms_failed') {
            $wpdb->last_error='';
            $items=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table('sms_log')." WHERE status='failed' AND created_at BETWEEN %s AND %s ORDER BY id DESC LIMIT 500",$from.' 00:00:00',$to.' 23:59:59'),ARRAY_A);
            $query_error = self::report_query_error('خواندن گزارش پیامک‌های ناموفق');
            if (is_wp_error($query_error)) { return $query_error; }
            $items = is_array($items) ? $items : array();
            $summary = array('unknown_events' => count($items));
        } elseif ($type==='product_sales'||$type==='invoices') {
            if (!function_exists('wc_get_orders')) { return new WP_Error('wsm_club_woo_missing','ووکامرس فعال نیست.',array('status'=>500)); }
            $sale_range = (int)(new DateTimeImmutable($from,wp_timezone()))->diff(new DateTimeImmutable($to,wp_timezone()))->days + 1;
            if ($sale_range > 366) { return new WP_Error('wsm_club_report_range_too_large','برای این گزارش، بازهٔ انتخابی حداکثر ۳۶۶ روز است.',array('status'=>400)); }
            $page_size=50; $page=1; $max_pages=1; $group=array();
            $report_invoice_ids=$type==='invoices'?WSM_Util::order_ids_with_meta('_wsm_invoice','1'):array();
            do {
                $query=array('type'=>'shop_order','status'=>array('processing','completed','on-hold'),'limit'=>$page_size,'page'=>$page,'paginate'=>true,'date_created'=>$from.'...'.$to,'orderby'=>'date','order'=>'DESC','return'=>'objects');
                if ($type==='invoices') {
                    // Keep invoice pagination filtered on both HPOS and legacy CPT datastores.
                    $query['post__in']=$report_invoice_ids?$report_invoice_ids:array(0);
                }
                $wpdb->last_error='';
                $result=wc_get_orders($query);
                if (is_wp_error($result)) { return $result; }
                if ($wpdb->last_error !== '') { return new WP_Error('wsm_club_report_query_failed','خواندن فاکتورها یا فروش کالا انجام نشد.',array('status'=>500)); }
                $has_pagination=is_object($result)&&isset($result->orders,$result->max_num_pages);
                $orders=$has_pagination?(array)$result->orders:(array)$result;
                $max_pages=$has_pagination?max(1,(int)$result->max_num_pages):($page+(count($orders)>=$page_size?1:0));
                foreach ($orders as $order) {
                    if (!$order instanceof WC_Order) { continue; }
                    if ($type==='invoices') {
                        if (!$order->get_meta('_wsm_invoice')) { continue; }
                        $items[]=array('invoice_id'=>(int)$order->get_id(),'number'=>(string)$order->get_order_number(),'date'=>$order->get_date_created()?$order->get_date_created()->date('Y-m-d H:i:s'):'','buyer'=>(string)$order->get_formatted_billing_full_name(),'total'=>(string)$order->get_total(),'status'=>(string)$order->get_status());
                        continue;
                    }
                    foreach ($order->get_items('line_item') as $line) {
                        $pid=(int)$line->get_product_id(); if(!$pid){continue;} $key=$pid.':'.$line->get_name();
                        if(!isset($group[$key])){$group[$key]=array('product_id'=>$pid,'name'=>$line->get_name(),'quantity'=>0,'total'=>0,'orders'=>0);}
                        $group[$key]['quantity']+=(int)$line->get_quantity(); $group[$key]['total']+=(float)$line->get_total(); $group[$key]['orders']++;
                    }
                }
                $page++;
            } while($page<=$max_pages&&$page<=10);
            if ($type==='product_sales') {
                $items=array_values($group);
                $sales_units=0; $quantity_sum=0;
                foreach ($items as $product_row) {
                    $sales_units += self::decimal_units($product_row['total'] ?? '0') ?? 0;
                    $quantity_sum += max(0, (int) ($product_row['quantity'] ?? 0));
                }
                $summary = array('sales_total' => self::decimal_string($sales_units), 'orders' => count($items), 'quantity' => $quantity_sum);
            } else {
                $invoice_units=0;
                foreach ((array)$items as $invoice_row) { $invoice_units += self::decimal_units($invoice_row['total'] ?? '0') ?? 0; }
                $summary = array('sales_total' => self::decimal_string($invoice_units), 'orders' => count((array)$items));
            }
        } else { return new WP_Error('wsm_club_report_type','نوع گزارش شناخته‌شده نیست.',array('status'=>400)); }
        $items = is_array($items) ? array_values($items) : array();
        if (empty($summary)) { $summary = array('records' => count($items)); }
        if (empty($charts)) { $charts = array('series' => array()); }
        return array('type'=>$type,'from'=>$from,'to'=>$to,'items'=>$items,'count'=>count($items),'summary'=>$summary,'charts'=>$charts);
    }

    private static function sync_member_map_table() {
        global $wpdb;
        return $wpdb->prefix . 'wsm_sync_member_map';
    }

    /** Resolve an offline UUID to the canonical club member. */
    public static function find_sync_member_id($offline_uuid) {
        global $wpdb;
        if (!is_string($offline_uuid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $offline_uuid)) {
            return new WP_Error('wsm_sync_member_uuid_invalid', 'شناسهٔ آفلاین عضو معتبر نیست.', array('status' => 400));
        }
        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT member_id FROM ' . self::sync_member_map_table() . ' WHERE offline_uuid = %s LIMIT 1',
            strtolower($offline_uuid)
        ));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_sync_member_map_unavailable', 'خواندن پیوند عضو آفلاین انجام نشد.', array('status' => 500));
        }
        return $id === null || $id === false ? 0 : (int) $id;
    }

    /** Merge through the existing WSM_Club validation/save logic and attach the UUID alias. */
    public static function save_member_from_sync($data, $offline_uuid) {
        global $wpdb;
        if (!is_array($data) || !is_string($offline_uuid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $offline_uuid)) {
            return new WP_Error('wsm_sync_member_invalid', 'اطلاعات عضو آفلاین معتبر نیست.', array('status' => 400));
        }
        $offline_uuid = strtolower($offline_uuid);
        $phone = isset($data['phone']) && is_scalar($data['phone'])
            ? WSM_Persian_Normalizer::normalize(self::text($data['phone'], 40))
            : '';
        $phone = preg_replace('/[^0-9+(). -]/u', '', (string) $phone);
        $phone_digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if ($phone === '' || $phone_digits === '') {
            return new WP_Error('wsm_sync_member_phone_invalid', 'شمارهٔ تماس عضو آفلاین معتبر نیست.', array('status' => 400));
        }

        $member_id = self::find_sync_member_id($offline_uuid);
        if (is_wp_error($member_id)) { return $member_id; }
        $member_id = (int) $member_id;
        $member_table = WSM_Club::table('members');
        if ($member_id > 0) {
            $wpdb->last_error = '';
            $exists = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $member_table . ' WHERE id = %d LIMIT 1', $member_id));
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_sync_member_lookup_failed', 'بررسی عضو مرتبط انجام نشد.', array('status' => 500));
            }
            if (is_object($exists) && (int) $exists->id > 0) {
                $phonebook_sync = self::sync_member_to_phonebook($exists);
                if (is_wp_error($phonebook_sync) && function_exists('error_log')) {
                    error_log('WSM offline phonebook sync failed for club member #' . (int) $exists->id . ': ' . $phonebook_sync->get_error_code());
                }
                return array('id' => (int) $exists->id, 'member_code' => isset($exists->member_code) ? (string) $exists->member_code : '', 'merged' => true, 'idempotent_replay' => true);
            }
            $wpdb->delete(self::sync_member_map_table(), array('offline_uuid' => $offline_uuid), array('%s'));
            $member_id = 0;
        }
        if ($member_id < 1) {
            $sql = "SELECT id FROM {$member_table}
                WHERE status <> 'archived' AND (
                      phone = %s
                      OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '') = %s
                )
                ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END, id ASC LIMIT 1";
            $member_id = (int) $wpdb->get_var($wpdb->prepare($sql, $phone, $phone_digits));
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_sync_member_lookup_failed', 'جست‌وجوی شمارهٔ تماس عضو انجام نشد.', array('status' => 500));
            }
        }

        // Omitted/blank fields preserve existing profile data; an explicit false consent is retained.
        $member_data = array('phone' => $phone);
        foreach (array('first_name', 'last_name', 'email', 'device_user_id', 'card_number', 'join_date', 'birth_date', 'gender', 'marital_status', 'insurance_number', 'insurance_expiry', 'address', 'notes') as $field) {
            if (isset($data[$field]) && is_scalar($data[$field]) && trim((string) $data[$field]) !== '') {
                $member_data[$field] = $data[$field];
            }
        }
        foreach (array('collection_id', 'sport_id', 'coach_id') as $field) {
            if (isset($data[$field]) && is_scalar($data[$field]) && absint($data[$field]) > 0) {
                $member_data[$field] = absint($data[$field]);
            }
        }
        if (array_key_exists('sms_consent', $data)) {
            if (!is_scalar($data['sms_consent'])) {
                return new WP_Error('wsm_sync_member_consent_invalid', 'رضایت پیامکی عضو معتبر نیست.', array('status' => 400));
            }
            $member_data['sms_consent'] = rest_sanitize_boolean($data['sms_consent']) ? 1 : 0;
        }
        if ($member_id < 1) { $member_data['status'] = 'active'; }

        $saved = WSM_Club::save_member($member_data, $member_id);
        if (is_wp_error($saved)) { return self::sync_member_save_error($saved, $member_data); }
        $saved_id = is_array($saved) && isset($saved['id']) ? absint($saved['id']) : 0;
        if ($saved_id < 1) {
            return new WP_Error('wsm_sync_member_save_failed', 'ثبت عضو آفلاین نتیجهٔ معتبری برنگرداند.', array('status' => 500));
        }
        $linked = self::link_sync_member_uuid($offline_uuid, $saved_id);
        if (is_wp_error($linked)) { return $linked; }
        return array('id' => $saved_id, 'member_code' => isset($saved['member_code']) ? (string) $saved['member_code'] : '', 'merged' => $member_id > 0);
    }

    /**
     * Turn club validation errors into an actionable reason for the app.
     * Offline members used to be rejected with a generic sentence (or a bare HTTP status),
     * so the athlete stayed "in the queue" without anyone knowing what to fix.
     */
    private static function sync_member_save_error($error, $member_data) {
        $code = (string) $error->get_error_code();
        $message = trim((string) $error->get_error_message());
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
        $detail = '';

        if ($code === 'wsm_club_card_exists') {
            $detail = self::sync_member_conflict_detail('card_number', isset($member_data['card_number']) ? (string) $member_data['card_number'] : '', 'شمارهٔ کارت');
        } elseif ($code === 'wsm_club_device_uid_exists') {
            $detail = self::sync_member_conflict_detail('device_user_id', isset($member_data['device_user_id']) ? (string) $member_data['device_user_id'] : '', 'شناسهٔ دستگاه');
        } elseif ($code === 'wsm_club_reference_invalid') {
            $references = array('collection_id' => array('collections', 'مجموعه'), 'sport_id' => array('sports', 'رشتهٔ ورزشی'));
            foreach ($references as $key => $meta) {
                if (empty($member_data[$key])) { continue; }
                if (!self::entity_exists($meta[0], (int) $member_data[$key])) {
                    $detail = $meta[1] . ' با شناسهٔ ' . (int) $member_data[$key] . ' روی سرور پیدا نشد.';
                    break;
                }
            }
        } elseif ($code === 'wsm_club_coach_invalid') {
            if (!empty($member_data['coach_id'])) {
                $detail = 'مربی با شناسهٔ ' . (int) $member_data['coach_id'] . ' روی سرور پیدا نشد.';
            }
        } elseif ($code === 'wsm_club_email_invalid') {
            if (isset($member_data['email']) && (string) $member_data['email'] !== '') {
                $detail = 'ایمیل «' . (string) $member_data['email'] . '» معتبر نیست.';
            }
        } elseif ($code === 'wsm_club_date_invalid') {
            $detail = 'یکی از تاریخ‌های عضویت، تولد یا بیمه معتبر نیست.';
        } elseif ($code === 'wsm_club_profile_invalid') {
            $detail = 'جنسیت یا وضعیت تأهل مقدار مجاز ندارد.';
        }

        if ($detail !== '') { $message = ($message !== '' ? $message . ' ' : '') . '(' . $detail . ')'; }
        if ($message === '') { $message = 'سرور ثبت عضو آفلاین را نپذیرفت.'; }
        return new WP_Error($code !== '' ? $code : 'wsm_sync_member_rejected', $message, array('status' => $status, 'detail' => $detail));
    }

    /** Name the member who already owns a unique profile value (card number / device id). */
    private static function sync_member_conflict_detail($column, $value, $label) {
        global $wpdb;
        if (!in_array($column, array('card_number', 'device_user_id'), true) || trim((string) $value) === '') {
            return $label . ' تکراری است.';
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, first_name, last_name, member_code FROM ' . WSM_Club::table('members') . ' WHERE ' . $column . ' = %s LIMIT 1',
            (string) $value
        ));
        if (!is_object($row) || empty($row->id)) {
            return $label . ' «' . (string) $value . '» برای عضو دیگری ثبت شده است.';
        }
        $name = trim((string) $row->first_name . ' ' . (string) $row->last_name);
        $owner = $name !== '' ? $name : 'عضو #' . (int) $row->id;
        $owner_code = isset($row->member_code) ? trim((string) $row->member_code) : '';
        return $label . ' «' . (string) $value . '» به «' . $owner . '»' . ($owner_code !== '' ? ' (' . $owner_code . ')' : '') . ' تعلق دارد.';
    }

    private static function link_sync_member_uuid($offline_uuid, $member_id) {
        global $wpdb;
        $table = self::sync_member_map_table();
        $existing = $wpdb->get_var($wpdb->prepare('SELECT member_id FROM ' . $table . ' WHERE offline_uuid = %s LIMIT 1', $offline_uuid));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_sync_member_map_unavailable', 'بررسی پیوند UUID عضو انجام نشد.', array('status' => 500));
        }
        if ($existing !== null && $existing !== false) {
            return (int) $existing === (int) $member_id ? true : new WP_Error('wsm_sync_member_uuid_conflict', 'این UUID آفلاین قبلاً به عضو دیگری متصل شده است.', array('status' => 409));
        }
        $inserted = $wpdb->insert($table, array('offline_uuid' => $offline_uuid, 'member_id' => (int) $member_id, 'created_at' => current_time('mysql')), array('%s', '%d', '%s'));
        if ($inserted === 1) { return true; }
        // The primary key makes a simultaneous UUID claim idempotent.
        $after = $wpdb->get_var($wpdb->prepare('SELECT member_id FROM ' . $table . ' WHERE offline_uuid = %s LIMIT 1', $offline_uuid));
        if (empty($wpdb->last_error) && $after !== null && (int) $after === (int) $member_id) { return true; }
        return new WP_Error('wsm_sync_member_map_failed', 'ثبت پیوند UUID عضو انجام نشد.', array('status' => 500));
    }

    /** Capture the purchase cost on the order item while the order is created; later product edits must not rewrite sale history. */
    public static function snapshot_order_item_purchase_cost($item_id, $item, $order_id = 0) {
        if (!is_object($item) || !method_exists($item, 'get_product_id') || !method_exists($item, 'update_meta_data')) { return false; }
        if ((string) $item->get_meta('_wsm_purchase_cost_snapshot_set', true) === 'yes') { return false; }
        $product_id = absint($item->get_product_id());
        $product = function_exists('wc_get_product') && $product_id ? wc_get_product($product_id) : false;
        $cost = '';
        if ($product && class_exists('WSM_Products') && is_callable(array('WSM_Products', 'purchase_cost'))) { $cost = WSM_Products::purchase_cost($product); }
        if ($cost === '' && $product && method_exists($product, 'is_type') && $product->is_type('variation') && $product->get_parent_id()) {
            $parent = wc_get_product((int) $product->get_parent_id());
            if ($parent && class_exists('WSM_Products') && is_callable(array('WSM_Products', 'purchase_cost'))) { $cost = WSM_Products::purchase_cost($parent); }
        }
        if ($cost !== '' && (!is_scalar($cost) || !is_numeric((string) $cost) || (float) $cost < 0)) { $cost = ''; }
        if ($cost !== '') { $cost = function_exists('wc_format_decimal') ? wc_format_decimal((string) $cost, 6) : (string) $cost; }
        $item->update_meta_data('_wsm_purchase_cost_snapshot', (string) $cost);
        $item->update_meta_data('_wsm_purchase_cost_known', $cost === '' ? 'no' : 'yes');
        $item->update_meta_data('_wsm_purchase_cost_snapshot_at', current_time('mysql'));
        $item->update_meta_data('_wsm_purchase_cost_snapshot_set', 'yes');
        if (method_exists($item, 'save_meta_data')) { $item->save_meta_data(); }
        elseif (method_exists($item, 'save')) { $item->save(); }
        return $cost !== '';
    }

    /** Record an online order only after WooCommerce marks it paid; retries are idempotent per order. */
    public static function record_online_order_finance($order_id, $old_status = null, $new_status = null, $order_object = null) {
        global $wpdb;
        $order_id = absint($order_id);
        if (!$order_id || !function_exists('wc_get_order') || !function_exists('wc_get_orders')) { return false; }
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || !$order->is_paid() || (float) $order->get_total() <= 0) { return false; }
        if ($order->get_meta('_wsm_club_pos') || $order->get_meta('_wsm_manual_sale') || $order->get_created_via() === 'wp-store-manager') { return false; }
        $gateway_id = sanitize_key((string) $order->get_payment_method());
        if ($gateway_id === '' || in_array($gateway_id, array('cod','bacs','cheque','manual','wsm_pos_cash','wsm_pos_card','wsm_pos_transfer','wsm_store_account'), true)) { return false; }
        $amount = self::amount((string) $order->get_total(), 'مبلغ فروش آنلاین', false);
        if (is_wp_error($amount)) { return $amount; }
        $lock_name = 'wsm_on_sale_' . substr(hash('sha256', (string) $order_id), 0, 32);
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lock_name));
        if ((string) $got_lock !== '1') { return new WP_Error('wsm_online_sale_lock_failed', 'قفل جلوگیری از ثبت تکراری فروش آنلاین دریافت نشد.', array('status' => 503)); }
        $ledger = self::table('financial_ledger');
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $ledger WHERE order_id=%d AND source='online_sale' AND status='posted' ORDER BY id ASC LIMIT 1", $order_id));
        if ($exists) {
            if (!$order->get_meta('_wsm_finance_online_sale_recorded')) { $order->update_meta_data('_wsm_finance_online_sale_recorded', $exists); $order->save_meta_data(); }
            if ($got_lock) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name)); }
            return $exists;
        }
        $gateway_title = (string) $order->get_payment_method_title();
        $account_id = self::gateway_clearing_account($gateway_id, $gateway_title);
        if (!$account_id) {
            if ($got_lock) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name)); }
            return new WP_Error('wsm_online_clearing_account_missing', 'حساب واسط درگاه برای فروش آنلاین در دسترس نیست.', array('status' => 503));
        }
        $date = $order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d H:i:s') : current_time('mysql');
        $transaction_id = method_exists($order, 'get_transaction_id') ? self::text($order->get_transaction_id(), 120) : '';
        $entry = self::add_finance('pos_sale', $amount, 'فروش آنلاین #' . $order->get_order_number(), array('account_id' => (int) $account_id, 'order_id' => $order_id, 'method' => 'online', 'reference' => $transaction_id, 'reference_id' => 'online-order:' . $order_id, 'source' => 'online_sale', 'transaction_type' => 'income', 'occurred_at' => $date, 'description' => 'ثبت پس از تأیید پرداخت ووکامرس؛ انتقال بانکی فقط پس از تسویهٔ واقعی درگاه.'));
        if (!is_wp_error($entry)) {
            $order->update_meta_data('_wsm_finance_online_sale_recorded', (int) $entry);
            $order->save_meta_data();
        } elseif (class_exists('WSM_Activity_Log')) {
            WSM_Activity_Log::log('create', 'online_sale_finance', $order_id, '#' . $order->get_order_number(), '', array('error' => $entry->get_error_code()), 'failed');
        }
        if ($got_lock) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name)); }
        return $entry;
    }

    /** Bank accounts only; payroll operators do not need access to the full finance ledger. */
    public static function payroll_bank_accounts() {
        global $wpdb;
        $core = self::financial_core_ready();
        if (is_wp_error($core)) { return $core; }
        $table = self::table('financial_accounts');
        $rows = $wpdb->get_results("SELECT id,account_name,account_type FROM $table WHERE account_type='bank' AND is_active=1 ORDER BY id ASC", ARRAY_A);
        if ($wpdb->last_error !== '') { return new WP_Error('wsm_payroll_bank_accounts_failed', 'خواندن حساب‌های بانکی حقوق انجام نشد.', array('status' => 500)); }
        return array('items' => (array) $rows);
    }

    public static function create_pos_order($data, $offline_context = array()) {
        global $wpdb;
        if (!is_array($data)) { return new WP_Error('wsm_club_pos_items','دادهٔ فروش حضوری نامعتبر است.',array('status'=>400)); }
        if (array_key_exists('quick_access_action', $data)) { return self::handle_pos_quick_access_request($data); }
        if (empty($data['items'])||!is_array($data['items'])) { return new WP_Error('wsm_club_pos_items','حداقل یک کالای فروش حضوری وارد کنید.',array('status'=>400)); }
        if (count($data['items']) > 100) { return new WP_Error('wsm_club_pos_items_limit','در هر فروش حضوری حداکثر ۱۰۰ ردیف کالا مجاز است.',array('status'=>400)); }
        $create_invoice=isset($data['create_invoice'])&&rest_sanitize_boolean($data['create_invoice']);
        if ($create_invoice&&!WSM_Devices::device_can(WSM_Permissions::current_device(),'invoices.create')) { return new WP_Error('wsm_device_permission_denied','برای صدور فاکتور، مجوز invoices.create لازم است.',array('status'=>403)); }
        $member=null; $identifier=isset($data['member_identifier'])&&is_scalar($data['member_identifier'])?WSM_Persian_Normalizer::normalize(trim((string)$data['member_identifier'])):'';
        if ($identifier!=='') {
            $member=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WSM_Club::table('members').' WHERE member_code=%s OR card_number=%s OR device_user_id=%s LIMIT 1',$identifier,$identifier,$identifier));
            if (!$member||$member->status!=='active') { return new WP_Error('wsm_club_pos_member','عضو فعال با این کد/کارت پیدا نشد.',array('status'=>404)); }
        }
        $items=array();
        foreach (array_slice($data['items'],0,100) as $row) {
            if (!is_array($row)||!isset($row['sku'])||!is_scalar($row['sku'])) { return new WP_Error('wsm_club_pos_sku','SKU کالای فروش حضوری نامعتبر است.',array('status'=>400)); }
            $sku=trim((string)$row['sku']);
            $requested_product_id=isset($row['product_id'])&&is_scalar($row['product_id'])?absint($row['product_id']):0;
            $product_id=$requested_product_id?:wc_get_product_id_by_sku($sku);
            if (array_key_exists('qty',$row)&&!is_scalar($row['qty'])) { return new WP_Error('wsm_club_pos_quantity','تعداد هر کالا باید عدد صحیح مثبت باشد.',array('status'=>400)); }
            $qty_raw=array_key_exists('qty',$row)?trim(WSM_Persian_Normalizer::normalize((string)$row['qty'])):'1';
            if (!preg_match('/^\d+$/D',$qty_raw)) { return new WP_Error('wsm_club_pos_quantity','تعداد هر کالا باید عدد صحیح مثبت باشد.',array('status'=>400)); }
            $qty=(int)$qty_raw;
            if (!$product_id||!wc_get_product($product_id)||$qty<1||$qty>10000) { return new WP_Error('wsm_club_pos_product','کالا با شناسه/SKU «'.($sku!==''?$sku:$requested_product_id).'» پیدا نشد یا تعداد معتبر نیست.',array('status'=>400)); }
            $items[]=array('product_id'=>(int)$product_id,'qty'=>$qty);
        }
        if (!$items) { return new WP_Error('wsm_club_pos_items','کالایی برای فروش ثبت نشده است.',array('status'=>400)); }
        $method=isset($data['method'])&&is_scalar($data['method'])?(string)$data['method']:'cash';
        if (!in_array($method,array('cash','manual_card','bank_transfer','club_credit','member_debt'),true)) { return new WP_Error('wsm_club_pos_method','روش پرداخت نامعتبر است.',array('status'=>400)); }
        if (in_array($method,array('club_credit','member_debt'),true)&&!$member) { return new WP_Error('wsm_club_pos_member_required',$method==='member_debt'?'برای ثبت فروش نسیه، ورزشکار باید انتخاب شود.':'برای پرداخت با اعتبار کارت، عضو باید انتخاب شود.',array('status'=>400)); }
        if ($method==='bank_transfer'&&(!array_key_exists('paid',$data)||!rest_sanitize_boolean($data['paid']))) { return new WP_Error('wsm_club_pos_transfer_unconfirmed','برای ثبت انتقالی، پرداخت بانکی باید واقعاً تأیید شده باشد.',array('status'=>400)); }
        if ($method==='manual_card'&&(!array_key_exists('paid',$data)||!rest_sanitize_boolean($data['paid']))) { return new WP_Error('wsm_club_pos_card_unconfirmed','برای ثبت فروش کارتخوان، پرداخت واقعی کارتخوان را تأیید کنید.',array('status'=>400)); }
        if ($method==='cash'&&array_key_exists('paid',$data)&&!rest_sanitize_boolean($data['paid'])) { return new WP_Error('wsm_club_pos_cash_unconfirmed','فروش نقدی را بدون تأیید دریافت وجه ثبت نکنید؛ برای فروش اعتباری، روش نسیه را انتخاب کنید.',array('status'=>400)); }
        $discount_raw=array_key_exists('discount_amount',$data)?$data['discount_amount']:'0';
        $discount_normalized=self::amount($discount_raw,'مبلغ تخفیف',true);if(is_wp_error($discount_normalized)){return $discount_normalized;}
        $discount_value=(float)$discount_normalized;$discount_sale_uuid='';$discount_approval=null;
        if(is_array($offline_context)&&isset($offline_context['sale_uuid'])&&is_scalar($offline_context['sale_uuid'])){$discount_sale_uuid=strtolower(trim((string)$offline_context['sale_uuid']));}
        elseif(isset($data['sale_uuid'])&&is_scalar($data['sale_uuid'])){$discount_sale_uuid=strtolower(trim((string)$data['sale_uuid']));}
        if($discount_value>0){
            $queue_id=is_array($offline_context)&&isset($offline_context['queue_id'])&&is_scalar($offline_context['queue_id'])?strtolower(trim((string)$offline_context['queue_id'])):'';
            $valid_queue=preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$queue_id)===1;
            if(!$valid_queue){return new WP_Error('wsm_pos_discount_queue_required','تخفیف POS فقط در فروش دارای شناسهٔ صف و بازپخش تکرارناپذیر قابل ثبت است.',array('status'=>403));}
            $token=isset($data['discount_approval_token'])&&is_scalar($data['discount_approval_token'])?(string)$data['discount_approval_token']:'';
            $discount_approval=self::verify_pos_discount_token($token,$items,$discount_sale_uuid,$discount_normalized,$method);
            if(is_wp_error($discount_approval)){return $discount_approval;}
        }
        $order=wc_create_order(array('status'=>'pending'));
        if (is_wp_error($order)) { return $order; }
        $offline_replay_enabled = false;
        if (is_array($offline_context)) {
            $offline_queue_id = isset($offline_context['queue_id']) && is_scalar($offline_context['queue_id']) ? strtolower(trim((string) $offline_context['queue_id'])) : '';
            $offline_sale_uuid = isset($offline_context['sale_uuid']) && is_scalar($offline_context['sale_uuid']) ? strtolower(trim((string) $offline_context['sale_uuid'])) : '';
            $valid_queue_id = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $offline_queue_id) === 1;
            $valid_sale_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $offline_sale_uuid) === 1;
            if ($valid_queue_id) { $order->update_meta_data('_wsm_offline_queue_id', $offline_queue_id); }
            if ($valid_sale_uuid) { $order->update_meta_data('_wsm_offline_sale_uuid', $offline_sale_uuid); }
            if ($valid_queue_id && $valid_sale_uuid) {
                // Persist the idempotency key before stock/finance side effects begin.
                $order->update_meta_data('_wsm_offline_sync_complete', 0);
                $order->save();
                $marker_check = wc_get_order((int) $order->get_id());
                if (!$marker_check || (string) $marker_check->get_meta('_wsm_offline_queue_id') !== $offline_queue_id || (string) $marker_check->get_meta('_wsm_offline_sale_uuid') !== $offline_sale_uuid) {
                    $order->delete(true);
                    return new WP_Error('wsm_sync_sale_marker_failed', 'ثبت شناسهٔ تکرارناپذیر سفارش انجام نشد.', array('status' => 500));
                }
                $offline_replay_enabled = true;
            }
        }
        if ($member) {
            $order->set_billing_first_name((string)$member->first_name); $order->set_billing_last_name((string)$member->last_name); $order->set_billing_phone((string)$member->phone); $order->set_billing_email((string)$member->email);
            $order->update_meta_data('_wsm_club_member_id',(int)$member->id); $order->update_meta_data('_wsm_club_member_code',(string)$member->member_code); $order->update_meta_data('_wsm_club_card_number',(string)$member->card_number);
        }
        $order->update_meta_data('_wsm_manual_sale',1); $order->update_meta_data('_wsm_club_pos',1); $order->update_meta_data('_wsm_club_pos_payment_method',$method); if ($method==='member_debt') { $order->update_meta_data('_wsm_club_pos_debt',1); } $order->set_created_via('wp-store-manager');
        $ok=WSM_Orders::set_items($order,$items,true);
        if (is_wp_error($ok)) { $order->delete(true); return $ok; }
        foreach($order->get_items('line_item') as $purchase_cost_item){self::snapshot_order_item_purchase_cost((int)$purchase_cost_item->get_id(),$purchase_cost_item,(int)$order->get_id());}
        $order->calculate_totals();
        $discount_amount=(float)$discount_normalized;
        $base_subtotal=(float)$order->get_subtotal();
        if ($discount_amount>$base_subtotal) { $order->delete(true); return new WP_Error('wsm_club_pos_discount_excess','مبلغ تخفیف نمی‌تواند از جمع کالاها بیشتر باشد.',array('status'=>400)); }
        if ($discount_amount>0&&$base_subtotal>0) {
            $order->update_meta_data('_wsm_club_pos_discount_approved_by',(int)($discount_approval['approved_by_user_id']??0));
            $order->update_meta_data('_wsm_club_pos_discount_approved_at',(string)($discount_approval['approved_at']??''));
            $line_items=array_values($order->get_items('line_item')); $remaining=$discount_amount; $line_count=count($line_items); $decimals=function_exists('wc_get_price_decimals')?(int)wc_get_price_decimals():2;
            foreach($line_items as $line_index=>$line_item){
                $line_subtotal=(float)$line_item->get_subtotal();
                $line_discount=$line_index===$line_count-1?$remaining:round($discount_amount*$line_subtotal/$base_subtotal,$decimals);
                $line_discount=min($line_subtotal,max(0,$line_discount));
                $remaining=round($remaining-$line_discount,$decimals);
                $line_item->set_total(wc_format_decimal(max(0,$line_subtotal-$line_discount),$decimals));
                $line_item->save();
            }
            $order->calculate_totals();
            $order->update_meta_data('_wsm_club_pos_discount_amount',(string)$discount_amount);
        }
        $total=(float)$order->get_total();
        if ($method==='member_debt' && $total<=0) { $order->delete(true); return new WP_Error('wsm_club_pos_debt_amount','مبلغ فروش نسیه باید بزرگ‌تر از صفر باشد.',array('status'=>400)); }
        $reference=isset($data['reference'])&&is_scalar($data['reference'])?self::text($data['reference'],120):'';
        $paid=true; $card_balance=0; $finance_transaction_started=false;
        if ($method==='club_credit') {
            if ($total > 0) {
                if($wpdb->query('START TRANSACTION')===false){$order->delete(true);return new WP_Error('wsm_club_pos_transaction','آغاز تراکنش پرداخت با اعتبار کارت انجام نشد.',array('status'=>500));}
                $finance_transaction_started=true;
                $charged=$wpdb->query($wpdb->prepare('UPDATE '.WSM_Club::table('members').' SET card_balance=card_balance-%f,updated_at=%s WHERE id=%d AND card_balance >= %f',$total,current_time('mysql'),(int)$member->id,$total));
                if ($charged!==1) { $wpdb->query('ROLLBACK'); $order->delete(true); return new WP_Error('wsm_club_pos_credit','ماندهٔ اعتبار کارت برای این خرید کافی نیست.',array('status'=>409)); }
            }
            $card_balance=self::recharge_card_balance((int)$member->id);
        } elseif ($method==='member_debt') { $paid=false; }
        elseif (array_key_exists('paid',$data)) { $paid=rest_sanitize_boolean($data['paid']); }
        $gateway=$method==='cash'?'cod':($method==='bank_transfer'?'bacs':($method==='manual_card'?'manual':'manual'));
        $payment_titles=array('cash'=>'نقدی','manual_card'=>'کارتخوان','bank_transfer'=>'انتقال بانکی (تأییدشده)','club_credit'=>'اعتبار کارت باشگاه','member_debt'=>'نسیه · بدهی ورزشکار');
        $order->set_payment_method($gateway); $order->set_payment_method_title(isset($payment_titles[$method])?$payment_titles[$method]:'نقدی');
        if ($reference!=='') { $order->update_meta_data('_wsm_payment_reference',$reference); }
        $order->calculate_totals();
        if ($method==='member_debt') { $order->update_meta_data('_wsm_club_pos_debt_amount',(string)$total); }
        $should_record_sale=$total>0&&($method==='member_debt'||$method==='club_credit'||$paid);
        if($should_record_sale){
            if(!$finance_transaction_started){if($wpdb->query('START TRANSACTION')===false){$order->delete(true);return new WP_Error('wsm_club_pos_transaction','آغاز ثبت مالی فروش حضوری انجام نشد.',array('status'=>500));}$finance_transaction_started=true;}
            $device_id=class_exists('WSM_Permissions')?WSM_Permissions::current_device_id():0;
            $mapping=self::device_finance_accounts($device_id);$account_id=0;$session_id=0;$ledger_method='other';$no_account=false;
            if($method==='cash'){$account_id=(int)$mapping['cash_account_id'];$ledger_method='cash';}
            elseif($method==='manual_card'){$account_id=(int)$mapping['pos_account_id'];$ledger_method='card';}
            elseif($method==='bank_transfer'){$account_id=(int)$mapping['default_bank_account_id'];$ledger_method='bank_transfer';}
            elseif($method==='club_credit'){$ledger_method='club_credit';$no_account=true;}
            elseif($method==='member_debt'){$ledger_method='credit_nasiye';$no_account=true;}
            if(in_array($method,array('cash','manual_card','bank_transfer'),true)&&!$account_id){$wpdb->query('ROLLBACK');$order->delete(true);return new WP_Error('wsm_club_pos_finance_account','حساب مالی نگاشت‌شده برای این روش پرداخت فعال نیست.',array('status'=>409));}
            if(in_array($method,array('cash','manual_card'),true)&&$account_id){$cash_id=(int)$mapping['cash_account_id'];$session=self::current_cash_session($cash_id,$device_id);if(!$session){$wpdb->query('ROLLBACK');$order->delete(true);return new WP_Error('wsm_club_pos_cash_session_required','برای فروش نقدی یا کارتخوان، ابتدا نشست صندوق مستقل این POS را باز کنید.',array('status'=>409));}$session_id=(int)$session['id'];$session_lock=self::lock_open_cash_session($session_id,$cash_id,$device_id);if(is_wp_error($session_lock)){$wpdb->query('ROLLBACK');$order->delete(true);return $session_lock;}}
            if($method==='member_debt'){
                // A credit sale is a receivable, not realized income. The income is posted once when the debt is collected.
                $debt_ledger=self::add_finance('pos_receivable',$total,'فروش نسیه حضوری #'.$order->get_order_number(),array('member_id'=>(int)$member->id,'order_id'=>(int)$order->get_id(),'method'=>'credit_nasiye','transaction_type'=>'receivable','source'=>'pos_debt','ledger_status'=>'pending','reference'=>$reference,'reference_id'=>'order:'.(int)$order->get_id(),'description'=>'ثبت ماندهٔ فروش نسیه'));
                if(is_wp_error($debt_ledger)){$wpdb->query('ROLLBACK');$order->delete(true);return $debt_ledger;}
            } else {
                if($method==='club_credit'){
                    $spend=self::add_finance('card_spend',$total,'مصرف اعتبار کارت بابت فروش حضوری #'.$order->get_order_number(),array('member_id'=>(int)$member->id,'order_id'=>(int)$order->get_id(),'method'=>'club_credit','reference'=>$reference,'source'=>'pos_card_credit','transaction_type'=>'liability','description'=>'کسر از اعتبار عضو هنگام فروش POS.'));
                    if(is_wp_error($spend)){$wpdb->query('ROLLBACK');$order->delete(true);return $spend;}
                }
                $sale_extra=array('member_id'=>$member?(int)$member->id:0,'order_id'=>(int)$order->get_id(),'account_id'=>$account_id,'no_account'=>$no_account,'cash_session_id'=>$session_id,'method'=>$ledger_method,'reference'=>$reference,'source'=>'pos_sale','transaction_type'=>'income','description'=>$method==='club_credit'?'درآمد فروش پرداخت‌شده از اعتبار کارت عضو.':'');
                $finance_id=self::add_finance('pos_sale',$total,'فروش حضوری #'.$order->get_order_number(),$sale_extra);
                if(is_wp_error($finance_id)){$wpdb->query('ROLLBACK');$order->delete(true);return $finance_id;}
            }
        }
        if ($paid) { $order->set_status('completed'); } else { $order->set_status('on-hold'); }
        $order->calculate_totals(); $saved_order_id=$order->save();
        if(!$saved_order_id){if($finance_transaction_started){$wpdb->query('ROLLBACK');}$order->delete(true);return new WP_Error('wsm_club_pos_order_save_failed','ذخیرهٔ نهایی سفارش POS انجام نشد؛ گردش مالی ثبت نشد.',array('status'=>500));}
        if($finance_transaction_started){$wpdb->query('COMMIT');}
        if($method==='member_debt'&&$total>0){self::sync_receivables_projection();}
        // POS orders are handed over immediately; make stock deduction explicit for paid and on-account orders.
        if (function_exists('wc_reduce_stock_levels')) { wc_reduce_stock_levels((int)$order->get_id()); }
        if (class_exists('WSM_Reports') && is_callable(array('WSM_Reports', 'flush_sale_caches'))) { WSM_Reports::flush_sale_caches(); }
        if ($method==='member_debt') { $order->add_order_note('فروش نسیهٔ باشگاه؛ مبلغ به پروندهٔ ورزشکار '.(string)$member->member_code.' اضافه شد.'); }
        $invoice_id=0; $invoice_error='';
        if ($create_invoice) {
            $created=WSM_Invoices::create_from_order((int)$order->get_id());
            if (is_wp_error($created)) {$invoice_error=$created->get_error_message();}
            elseif (is_array($created)&&isset($created['id'])) {$invoice_id=(int)$created['id'];}
            elseif (is_object($created)&&method_exists($created,'get_id')) {$invoice_id=(int)$created->get_id();}
        }
        WSM_Activity_Log::log('sale','order',(int)$order->get_id(),'#'.$order->get_order_number(),'',array('total'=>$total,'discount_amount'=>$discount_amount,'discount_approved_by'=>(int)($discount_approval['approved_by_user_id']??0),'member_id'=>$member?(int)$member->id:0,'method'=>$method,'invoice_id'=>$invoice_id),'success',null,!empty($discount_approval['approved_by_user_id'])?(int)$discount_approval['approved_by_user_id']:null);
        if ($offline_replay_enabled) {
            $order->update_meta_data('_wsm_offline_sync_complete', 1);
            $order->save();
            $completion_check = wc_get_order((int) $order->get_id());
            if (!$completion_check || !rest_sanitize_boolean($completion_check->get_meta('_wsm_offline_sync_complete'))) {
                return new WP_Error('wsm_sync_sale_completion_failed', 'تأیید نهایی سفارش همگام‌شده انجام نشد.', array('status' => 500));
            }
        }
        $receipt_items=array();
        foreach ($order->get_items('line_item') as $line) { $receipt_items[]=array('name'=>(string)$line->get_name(),'qty'=>(int)$line->get_quantity(),'total'=>(string)$line->get_subtotal()); }
        $response = array('order_id'=>(int)$order->get_id(),'order_number'=>(string)$order->get_order_number(),'total'=>(string)$total,'subtotal'=>(string)$order->get_subtotal(),'discount_total'=>(string)$order->get_discount_total(),'total_tax'=>(string)$order->get_total_tax(),'tax_enabled'=>function_exists('wc_tax_enabled')?wc_tax_enabled():false,'member_id'=>$member?(int)$member->id:0,'member_name'=>$member?trim($member->first_name.' '.$member->last_name):'','member_code'=>$member?(string)$member->member_code:'','card_number'=>$member?(string)$member->card_number:'','card_balance'=>$card_balance,'status'=>$order->get_status(),'payment_method'=>$method,'payment_method_title'=>$order->get_payment_method_title(),'is_member_debt'=>$method==='member_debt','debt_due'=>$method==='member_debt'?(string)$total:'0','items'=>$receipt_items,'invoice_id'=>$invoice_id,'invoice_error'=>$invoice_error,'reference'=>$reference);
        if ($member) {
            $response['member_balance_due'] = (float) WSM_Club::balance_due((int)$member->id);
            $pos_debts = self::pos_debt_by_member(array((int)$member->id));
            if (!is_wp_error($pos_debts)) {
                $response['member_pos_debt_due'] = isset($pos_debts[(int)$member->id]) ? (float)$pos_debts[(int)$member->id] : 0.0;
                $response['member_total_debt_due'] = $response['member_balance_due'] + $response['member_pos_debt_due'];
            }
        }
        return $response;
    }

    public static function recharge_card_balance($member_id) {
        global $wpdb; return (string)$wpdb->get_var($wpdb->prepare('SELECT card_balance FROM '.WSM_Club::table('members').' WHERE id=%d',(int)$member_id));
    }

    private static function internal_messages_table() {
        global $wpdb;
        return $wpdb->prefix . 'wsm_messages';
    }


    /** Lazily add conversation identifiers while remaining compatible with the v2 message table. */
    private static function ensure_internal_message_thread_schema() {
        static $schema_ready = null;
        if ($schema_ready !== null) { return $schema_ready; }
        global $wpdb;
        $table = self::internal_messages_table();
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table && class_exists('WSM_Core') && is_callable(array('WSM_Core', 'ensure_messages_table'))) {
            WSM_Core::ensure_messages_table();
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        }
        if ($exists !== $table) { $schema_ready = false; return false; }

        $thread_column = $wpdb->get_var("SHOW COLUMNS FROM {$table_sql} LIKE 'thread_uuid'");
        if ($thread_column !== 'thread_uuid') {
            if ($wpdb->query("ALTER TABLE {$table_sql} ADD COLUMN thread_uuid varchar(36) NOT NULL DEFAULT '' AFTER uuid") === false) {
                error_log('[WSM Messages] Could not add the thread_uuid column.');
                $schema_ready = false;
                return false;
            }
        }
        $reply_column = $wpdb->get_var("SHOW COLUMNS FROM {$table_sql} LIKE 'reply_to_uuid'");
        if ($reply_column !== 'reply_to_uuid') {
            if ($wpdb->query("ALTER TABLE {$table_sql} ADD COLUMN reply_to_uuid varchar(36) NOT NULL DEFAULT '' AFTER thread_uuid") === false) {
                error_log('[WSM Messages] Could not add the reply_to_uuid column.');
                $schema_ready = false;
                return false;
            }
        }
        $schema_ready = true;
        return true;
    }

    private static function internal_message_uuid_is_valid($uuid) {
        return is_string($uuid) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    /** Only active WordPress/app accounts can participate in internal messaging. */
    private static function internal_message_user_is_active($user_id) {
        $user_id = absint($user_id);
        if ($user_id < 1) { return false; }
        $user = get_userdata($user_id);
        if (!$user || get_user_meta($user_id, 'wsm_app_user_status', true) === 'inactive') { return false; }
        $roles = is_array($user->roles) ? $user->roles : array();
        if (array_intersect($roles, array('administrator', 'shop_manager')) || user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce')) { return true; }
        if (get_user_meta($user_id, 'wsm_app_user_status', true) === 'active' || (string) get_user_meta($user_id, 'wsm_app_role', true) !== '') { return true; }
        return class_exists('WSM_Permissions') && WSM_Permissions::user_permissions_record($user_id) !== null;
    }

    private static function internal_message_read_by($raw) {
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded)) { return array(); }
        $ids = array();
        foreach ($decoded as $value) {
            if (is_scalar($value) && absint($value) > 0) { $ids[] = absint($value); }
        }
        return array_values(array_unique($ids));
    }

    /** Return active staff accounts for the message composer; never expose credentials. */
    public static function list_internal_message_recipients($exclude_user_id = 0) {
        $exclude_user_id = absint($exclude_user_id);
        $users = get_users(array(
            'number' => 500,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => array('ID', 'display_name'),
        ));
        $items = array();
        foreach ((array) $users as $user) {
            if (!is_object($user) || empty($user->ID)) { continue; }
            $user_id = (int) $user->ID;
            if ($user_id === $exclude_user_id || !self::internal_message_user_is_active($user_id)) { continue; }
            $name = self::text(isset($user->display_name) ? $user->display_name : '', 120);
            $items[] = array('id' => $user_id, 'name' => $name !== '' ? $name : 'کاربر ' . $user_id);
        }
        return array('items' => $items);
    }

    private static function internal_message_duplicate_response($existing, $sender_id, $receiver_id, $subject, $message, $thread_uuid, $reply_to_uuid) {
        $existing_uuid = is_array($existing) ? strtolower((string) $existing['uuid']) : '';
        $existing_thread = is_array($existing) && isset($existing['thread_uuid']) && self::internal_message_uuid_is_valid((string) $existing['thread_uuid'])
            ? strtolower((string) $existing['thread_uuid']) : $existing_uuid;
        $existing_reply = is_array($existing) && isset($existing['reply_to_uuid']) && self::internal_message_uuid_is_valid((string) $existing['reply_to_uuid'])
            ? strtolower((string) $existing['reply_to_uuid']) : '';
        if (!is_array($existing) || (int) $existing['sender_id'] !== (int) $sender_id ||
            (int) $existing['receiver_id'] !== (int) $receiver_id || (string) $existing['subject'] !== (string) $subject ||
            (string) $existing['message'] !== (string) $message || $existing_thread !== (string) $thread_uuid ||
            $existing_reply !== (string) $reply_to_uuid) {
            return new WP_Error('wsm_message_uuid_conflict', 'این شناسهٔ پیام قبلاً با محتوای دیگری ثبت شده است.', array('status' => 409));
        }
        $sender = get_userdata((int) $existing['sender_id']);
        $receiver = (int) $existing['receiver_id'] > 0 ? get_userdata((int) $existing['receiver_id']) : null;
        return array(
            'id' => (int) $existing['id'],
            'uuid' => (string) $existing['uuid'],
            'thread_uuid' => $existing_thread,
            'reply_to_uuid' => $existing_reply,
            'sender_id' => (int) $existing['sender_id'],
            'receiver_id' => (int) $existing['receiver_id'],
            'subject' => (string) $existing['subject'],
            'message' => (string) $existing['message'],
            'is_read' => (bool) $existing['is_read'],
            'read_by' => self::internal_message_read_by(isset($existing['read_by']) ? $existing['read_by'] : ''),
            'created_at' => (string) $existing['created_at'],
            'sender_name' => $sender ? (string) $sender->display_name : 'کاربر ' . (int) $existing['sender_id'],
            'receiver_name' => (int) $existing['receiver_id'] === 0 ? 'همهٔ کارمندان' : ($receiver ? (string) $receiver->display_name : 'کاربر ' . (int) $existing['receiver_id']),
            'duplicate' => true,
        );
    }

    /** Create a message idempotently; the sender is always taken from the authenticated device. */
    public static function create_internal_message($payload, $sender_id = 0) {
        global $wpdb;
        $device = class_exists('WSM_Permissions') ? WSM_Permissions::current_device() : null;
        if (!$device || !is_object($device) || empty($device->user_id)) {
            return new WP_Error('wsm_message_device_required', 'دستگاه احرازشده برای ارسال پیام در دسترس نیست.', array('status' => 401));
        }
        $device_user_id = absint($device->user_id);
        $sender_id = absint($sender_id) ?: $device_user_id;
        if ($sender_id !== $device_user_id || !self::internal_message_user_is_active($sender_id)) {
            return new WP_Error('wsm_message_sender_invalid', 'حساب فرستندهٔ پیام فعال یا مجاز نیست.', array('status' => 403));
        }
        if (!self::ensure_internal_message_thread_schema()) {
            return new WP_Error('wsm_message_schema_unavailable', 'ساختار گفتگوهای پیام‌رسان در دسترس نیست.', array('status' => 500));
        }
        $payload = is_array($payload) ? $payload : array();
        $uuid = isset($payload['uuid']) && is_scalar($payload['uuid']) ? strtolower(trim((string) $payload['uuid'])) : '';
        if (!self::internal_message_uuid_is_valid($uuid)) {
            return new WP_Error('wsm_message_uuid_invalid', 'شناسهٔ یکتای پیام معتبر نیست.', array('status' => 400));
        }
        $raw_thread_uuid = isset($payload['thread_uuid']) && is_scalar($payload['thread_uuid']) ? strtolower(trim((string) $payload['thread_uuid'])) : '';
        $thread_uuid = $raw_thread_uuid;
        $reply_to_uuid = isset($payload['reply_to_uuid']) && is_scalar($payload['reply_to_uuid']) ? strtolower(trim((string) $payload['reply_to_uuid'])) : '';
        if ($raw_thread_uuid !== '' && !self::internal_message_uuid_is_valid($raw_thread_uuid)) {
            return new WP_Error('wsm_message_thread_invalid', 'شناسهٔ گفتگوی پیام معتبر نیست.', array('status' => 400));
        }
        if ($reply_to_uuid !== '' && !self::internal_message_uuid_is_valid($reply_to_uuid)) {
            return new WP_Error('wsm_message_reply_target_invalid', 'شناسهٔ پیام برای پاسخ معتبر نیست.', array('status' => 400));
        }
        if (!isset($payload['receiver_id']) || !is_scalar($payload['receiver_id']) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $payload['receiver_id'])) {
            return new WP_Error('wsm_message_receiver_invalid', 'گیرندهٔ پیام معتبر نیست.', array('status' => 400));
        }
        $receiver_id = (int) $payload['receiver_id'];
        $is_super_admin = class_exists('WSM_Devices') && WSM_Devices::device_can($device, 'users.super_admin.assign');
        if ($receiver_id === 0 && !$is_super_admin && $reply_to_uuid === '') {
            return new WP_Error('wsm_message_group_forbidden', 'ارسال پیام به همه فقط برای مدیر کل مجاز است.', array('status' => 403));
        }
        if ($receiver_id > 0 && !self::internal_message_user_is_active($receiver_id)) {
            return new WP_Error('wsm_message_receiver_missing', 'گیرندهٔ انتخاب‌شده یک حساب فعال اپ نیست.', array('status' => 404));
        }

        $table = self::internal_messages_table();
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        $thread_root = null;
        if ($reply_to_uuid !== '') {
            $reply_row = $wpdb->get_row($wpdb->prepare(
                "SELECT uuid, thread_uuid, sender_id, receiver_id, subject FROM {$table_sql} WHERE uuid = %s LIMIT 1",
                $reply_to_uuid
            ), ARRAY_A);
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_message_reply_lookup_failed', 'بررسی پیام مورد پاسخ انجام نشد.', array('status' => 500));
            }
            if (!is_array($reply_row)) {
                return new WP_Error('wsm_message_reply_target_missing', 'پیامی که به آن پاسخ می‌دهید پیدا نشد.', array('status' => 404));
            }
            $parent_thread_uuid = isset($reply_row['thread_uuid']) && self::internal_message_uuid_is_valid((string) $reply_row['thread_uuid'])
                ? strtolower((string) $reply_row['thread_uuid']) : strtolower((string) $reply_row['uuid']);
            if ($thread_uuid !== '' && $thread_uuid !== $parent_thread_uuid) {
                return new WP_Error('wsm_message_thread_mismatch', 'پیام مورد پاسخ متعلق به این گفتگو نیست.', array('status' => 409));
            }
            $thread_uuid = $parent_thread_uuid;
            $thread_root = $wpdb->get_row($wpdb->prepare(
                "SELECT uuid, sender_id, receiver_id, subject FROM {$table_sql} WHERE uuid = %s LIMIT 1",
                $thread_uuid
            ), ARRAY_A);
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_message_thread_lookup_failed', 'بررسی گفتگوی پیام انجام نشد.', array('status' => 500));
            }
            if (!is_array($thread_root)) {
                return new WP_Error('wsm_message_thread_missing', 'گفتگوی اصلی برای ثبت پاسخ پیدا نشد.', array('status' => 409));
            }
            if (preg_match('/^\[athlete_support:[0-9]+:[0-9a-f]{16}\]/i', (string)$thread_root['subject'])) {
                return new WP_Error('wsm_support_dedicated_reply', 'پاسخ به گفتگوی ورزشکار فقط از تب اختصاصی پشتیبانی مجاز است.', array('status'=>403));
            }
            $root_sender_id = (int) $thread_root['sender_id'];
            $root_receiver_id = (int) $thread_root['receiver_id'];
            $is_group_thread = $root_receiver_id === 0;
            if (!$is_group_thread && $sender_id !== $root_sender_id && $sender_id !== $root_receiver_id) {
                return new WP_Error('wsm_message_thread_forbidden', 'حساب شما عضو این گفتگوی خصوصی نیست.', array('status' => 403));
            }
            $expected_receiver_id = $is_group_thread ? 0 : ($sender_id === $root_sender_id ? $root_receiver_id : $root_sender_id);
            if ($receiver_id !== $expected_receiver_id) {
                return new WP_Error('wsm_message_thread_recipient_invalid', 'گیرندهٔ پاسخ با اعضای گفتگو مطابقت ندارد.', array('status' => 403));
            }
        } else {
            if ($thread_uuid === '') { $thread_uuid = $uuid; }
            if ($thread_uuid !== $uuid) {
                return new WP_Error('wsm_message_thread_root_invalid', 'پیام تازه باید شناسهٔ گفتگوی خودش را داشته باشد.', array('status' => 400));
            }
        }
        $subject = self::text(isset($payload['subject']) ? $payload['subject'] : '', 200);
        if (is_array($thread_root) && isset($thread_root['subject'])) {
            $root_subject = self::text($thread_root['subject'], 200);
            if ($root_subject !== '') { $subject = $root_subject; }
        }
        if (preg_match('/^\[athlete_support:[0-9]+:[0-9a-f]{16}\]/i', $subject)) {
            return new WP_Error('wsm_support_dedicated_reply', 'پیام پشتیبانی فقط از تب اختصاصی آن مجاز است.', array('status'=>403));
        }
        $message = isset($payload['message']) && is_scalar($payload['message']) ? sanitize_textarea_field((string) $payload['message']) : '';
        $message = function_exists('mb_substr') ? mb_substr($message, 0, 5000, 'UTF-8') : substr($message, 0, 15000);
        if ($subject === '' || trim($message) === '') {
            return new WP_Error('wsm_message_content_required', 'موضوع و متن پیام الزامی است.', array('status' => 400));
        }
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, uuid, thread_uuid, reply_to_uuid, sender_id, receiver_id, subject, message, is_read, read_by, created_at FROM {$table_sql} WHERE uuid = %s LIMIT 1", $uuid), ARRAY_A);
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_message_lookup_failed', 'بررسی شناسهٔ یکتای پیام انجام نشد.', array('status' => 500));
        }
        if (is_array($existing)) { return self::internal_message_duplicate_response($existing, $sender_id, $receiver_id, $subject, $message, $thread_uuid, $reply_to_uuid); }
        $created_at = current_time('mysql');
        $inserted = $wpdb->insert($table, array(
            'uuid' => $uuid,
            'thread_uuid' => $thread_uuid,
            'reply_to_uuid' => $reply_to_uuid,
            'sender_id' => $sender_id,
            'receiver_id' => $receiver_id,
            'subject' => $subject,
            'message' => $message,
            'is_read' => 0,
            'read_by' => wp_json_encode(array()),
            'created_at' => $created_at,
        ), array('%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s'));
        if ($inserted !== 1) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT id, uuid, thread_uuid, reply_to_uuid, sender_id, receiver_id, subject, message, is_read, read_by, created_at FROM {$table_sql} WHERE uuid = %s LIMIT 1", $uuid), ARRAY_A);
            if (is_array($existing)) { return self::internal_message_duplicate_response($existing, $sender_id, $receiver_id, $subject, $message, $thread_uuid, $reply_to_uuid); }
            error_log('[WSM Messages] Message insert failed.');
            return new WP_Error('wsm_message_write_failed', 'ثبت پیام در سرور انجام نشد.', array('status' => 500));
        }
        $sender = get_userdata($sender_id);
        $receiver = $receiver_id > 0 ? get_userdata($receiver_id) : null;
        return array(
            'id' => (int) $wpdb->insert_id,
            'uuid' => $uuid,
            'thread_uuid' => $thread_uuid,
            'reply_to_uuid' => $reply_to_uuid,
            'sender_id' => $sender_id,
            'receiver_id' => $receiver_id,
            'subject' => $subject,
            'message' => $message,
            'is_read' => false,
            'read_by' => array(),
            'created_at' => $created_at,
            'sender_name' => $sender ? (string) $sender->display_name : 'کاربر ' . $sender_id,
            'receiver_name' => $receiver_id === 0 ? 'همهٔ کارمندان' : ($receiver ? (string) $receiver->display_name : 'کاربر ' . $receiver_id),
            'duplicate' => false,
        );
    }

    /** List only messages visible to the authenticated employee, unless the device is a global admin. */
    public static function list_internal_messages($limit = 100) {
        global $wpdb;
        $device = class_exists('WSM_Permissions') ? WSM_Permissions::current_device() : null;
        if (!$device || !is_object($device) || empty($device->user_id)) {
            return new WP_Error('wsm_message_device_required', 'دستگاه احرازشده برای دریافت پیام در دسترس نیست.', array('status' => 401));
        }
        $user_id = absint($device->user_id);
        if (!self::ensure_internal_message_thread_schema()) {
            return new WP_Error('wsm_message_schema_unavailable', 'ساختار گفتگوهای پیام‌رسان در دسترس نیست.', array('status' => 500));
        }
        $is_super_admin = class_exists('WSM_Devices') && WSM_Devices::device_can($device, 'users.super_admin.assign');
        $limit = max(1, min(200, absint($limit) ?: 100));
        $table = self::internal_messages_table();
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        $support_visible = self::portal_support_staff_role_allowed($user_id);
        $support_pattern = $wpdb->esc_like('[athlete_support:') . '%';
        if ($is_super_admin && $support_visible) {
            $sql = $wpdb->prepare("SELECT id, uuid, thread_uuid, reply_to_uuid, sender_id, receiver_id, subject, message, is_read, read_by, created_at FROM {$table_sql} ORDER BY id DESC LIMIT %d", $limit);
        } elseif ($is_super_admin) {
            $sql = $wpdb->prepare("SELECT id, uuid, thread_uuid, reply_to_uuid, sender_id, receiver_id, subject, message, is_read, read_by, created_at FROM {$table_sql} WHERE subject NOT LIKE %s ORDER BY id DESC LIMIT %d", $support_pattern, $limit);
        } elseif ($support_visible) {
            $sql = $wpdb->prepare("SELECT id, uuid, thread_uuid, reply_to_uuid, sender_id, receiver_id, subject, message, is_read, read_by, created_at FROM {$table_sql} WHERE receiver_id = %d OR receiver_id = 0 OR sender_id = %d ORDER BY id DESC LIMIT %d", $user_id, $user_id, $limit);
        } else {
            $sql = $wpdb->prepare("SELECT id, uuid, thread_uuid, reply_to_uuid, sender_id, receiver_id, subject, message, is_read, read_by, created_at FROM {$table_sql} WHERE (receiver_id = %d OR receiver_id = 0 OR sender_id = %d) AND subject NOT LIKE %s ORDER BY id DESC LIMIT %d", $user_id, $user_id, $support_pattern, $limit);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_message_read_failed', 'دریافت پیام‌های سرور انجام نشد.', array('status' => 500));
        }
        $user_ids = array($user_id);
        foreach ((array) $rows as $row) {
            $user_ids[] = absint($row['sender_id']);
            if (absint($row['receiver_id']) > 0) { $user_ids[] = absint($row['receiver_id']); }
        }
        $names = array();
        foreach (array_unique($user_ids) as $id) {
            $user = get_userdata((int) $id);
            $names[(int) $id] = $user ? (string) $user->display_name : 'کاربر ' . (int) $id;
        }
        $items = array();
        foreach ((array) $rows as $row) {
            $sender_id = (int) $row['sender_id'];
            $receiver_id = (int) $row['receiver_id'];
            $read_by = self::internal_message_read_by(isset($row['read_by']) ? $row['read_by'] : '');
            if ($receiver_id === 0) { $viewer_is_read = in_array($user_id, $read_by, true); }
            elseif ($receiver_id === $user_id) { $viewer_is_read = (int) $row['is_read'] === 1; }
            else { $viewer_is_read = (int) $row['is_read'] === 1; }
            $items[] = array(
                'id' => (int) $row['id'],
                'uuid' => (string) $row['uuid'],
                'thread_uuid' => isset($row['thread_uuid']) && self::internal_message_uuid_is_valid((string) $row['thread_uuid']) ? strtolower((string) $row['thread_uuid']) : strtolower((string) $row['uuid']),
                'reply_to_uuid' => isset($row['reply_to_uuid']) && self::internal_message_uuid_is_valid((string) $row['reply_to_uuid']) ? strtolower((string) $row['reply_to_uuid']) : '',
                'sender_id' => $sender_id,
                'receiver_id' => $receiver_id,
                'subject' => (string) $row['subject'],
                'message' => (string) $row['message'],
                'is_read' => $viewer_is_read,
                'created_at' => (string) $row['created_at'],
                'sender_name' => isset($names[$sender_id]) ? $names[$sender_id] : 'کاربر ' . $sender_id,
                'receiver_name' => $receiver_id === 0 ? 'همهٔ کارمندان' : (isset($names[$receiver_id]) ? $names[$receiver_id] : 'کاربر ' . $receiver_id),
            );
        }
        $direct_unread = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_sql} WHERE receiver_id = %d AND sender_id <> %d AND is_read = 0", $user_id, $user_id));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_message_unread_count_failed', 'شمارش پیام‌های خوانده‌نشده انجام نشد.', array('status' => 500));
        }
        $group_rows = $wpdb->get_results("SELECT sender_id, read_by FROM {$table_sql} WHERE receiver_id = 0", ARRAY_A);
        if (!empty($wpdb->last_error)) {
            return new WP_Error('wsm_message_unread_count_failed', 'شمارش پیام‌های گروهی خوانده‌نشده انجام نشد.', array('status' => 500));
        }
        $group_unread = 0;
        foreach ((array) $group_rows as $row) {
            if ((int) $row['sender_id'] === $user_id) { continue; }
            if (!in_array($user_id, self::internal_message_read_by(isset($row['read_by']) ? $row['read_by'] : ''), true)) { $group_unread++; }
        }
        return array('items' => $items, 'unread_count' => $direct_unread + $group_unread);
    }

    /** Optimistically record read receipts for direct and broadcast messages. */
    public static function mark_internal_messages_read($uuids) {
        global $wpdb;
        $device = class_exists('WSM_Permissions') ? WSM_Permissions::current_device() : null;
        if (!$device || !is_object($device) || empty($device->user_id)) {
            return new WP_Error('wsm_message_device_required', 'دستگاه احرازشده برای ثبت وضعیت خواندن در دسترس نیست.', array('status' => 401));
        }
        if (!is_array($uuids) || count($uuids) > 200) {
            return new WP_Error('wsm_message_read_list_invalid', 'فهرست پیام‌های خوانده‌شده معتبر نیست.', array('status' => 400));
        }
        $user_id = absint($device->user_id);
        $table = self::internal_messages_table();
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        $accepted = array();
        $unique_uuids = array();
        foreach ($uuids as $raw_uuid) {
            if (!is_scalar($raw_uuid)) { continue; }
            $uuid = strtolower(trim((string) $raw_uuid));
            if (self::internal_message_uuid_is_valid($uuid)) { $unique_uuids[$uuid] = $uuid; }
        }
        foreach ($unique_uuids as $uuid) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT id, receiver_id, read_by, is_read FROM {$table_sql} WHERE uuid = %s LIMIT 1", $uuid), ARRAY_A);
            if (!empty($wpdb->last_error)) {
                return new WP_Error('wsm_message_read_failed', 'خواندن پیام برای ثبت وضعیت انجام نشد.', array('status' => 500));
            }
            if (!is_array($row)) { continue; }
            $receiver_id = (int) $row['receiver_id'];
            if ($receiver_id !== 0 && $receiver_id !== $user_id) { continue; }
            $read_by = self::internal_message_read_by(isset($row['read_by']) ? $row['read_by'] : '');
            if (!in_array($user_id, $read_by, true)) { $read_by[] = $user_id; }
            $values = array('read_by' => wp_json_encode(array_values(array_unique($read_by))));
            $formats = array('%s');
            if ($receiver_id === $user_id) { $values['is_read'] = 1; $formats[] = '%d'; }
            $updated = $wpdb->update($table, $values, array('id' => (int) $row['id']), $formats, array('%d'));
            if ($updated === false) {
                return new WP_Error('wsm_message_read_write_failed', 'ذخیرهٔ وضعیت خواندن پیام انجام نشد.', array('status' => 500));
            }
            $accepted[] = $uuid;
        }
        return array('updated' => count($accepted), 'uuids' => $accepted);
    }

    public static function list_users() {
        $users=get_users(array('role__in'=>array('shop_manager'),'number'=>200,'orderby'=>'display_name','order'=>'ASC','fields'=>array('ID','user_login','display_name','user_email')));
        $items=array();
        foreach ((array)$users as $u) {$items[]=array('id'=>(int)$u->ID,'user_login'=>$u->user_login,'display_name'=>$u->display_name,'user_email'=>$u->user_email,'status'=>get_user_meta($u->ID,'wsm_app_user_status',true)==='inactive'?'inactive':'active');}
        return array('items'=>$items,'can_create'=>current_user_can('create_users'),'can_edit'=>current_user_can('edit_users'));
    }

    public static function create_user($data) {
        if (!is_array($data)||!current_user_can('create_users')) { return new WP_Error('wsm_club_user_forbidden','ایجاد حساب فقط با مجوز مدیریت کاربران وردپرس ممکن است.',array('status'=>403)); }
        $login=sanitize_user(isset($data['user_login'])?(string)$data['user_login']:'',true); $email=sanitize_email(isset($data['user_email'])?(string)$data['user_email']:''); $name=self::text(isset($data['display_name'])?$data['display_name']:'',120); $pass=isset($data['password'])&&is_scalar($data['password'])?(string)$data['password']:'';
        if($login===''||!validate_username($login)||username_exists($login)){return new WP_Error('wsm_club_user_login','نام کاربری خالی، نامعتبر یا تکراری است.',array('status'=>400));}
        if(!is_email($email)||email_exists($email)){return new WP_Error('wsm_club_user_email','ایمیل نامعتبر یا تکراری است.',array('status'=>400));}
        if(strlen($pass)<12){return new WP_Error('wsm_club_user_password','گذرواژه باید حداقل ۱۲ نویسه باشد.',array('status'=>400));}
        $id=wp_insert_user(array('user_login'=>$login,'user_email'=>$email,'display_name'=>$name?:$login,'user_pass'=>$pass,'role'=>'shop_manager'));
        if(is_wp_error($id)){return $id;} update_user_meta($id,'wsm_app_user_status','active');
        $key=WSM_Auth::generate_user_access_key((int)$id);
        if (is_wp_error($key)) { wp_delete_user((int)$id); return $key; }
        WSM_Activity_Log::log('create','club_user',(int)$id,$login,'','ایجاد شد','success'); return array('id'=>(int)$id,'user_login'=>$login,'display_name'=>$name?:$login,'user_email'=>$email,'status'=>'active','access_key_once'=>$key['plain'],'expires_at'=>$key['expires']);
    }

    public static function update_user($id,$data) {
        if(!is_array($data)){return new WP_Error('wsm_club_user_invalid','اطلاعات کاربر نامعتبر است.',array('status'=>400));}
        $user=get_userdata((int)$id); if(!$user||!in_array('shop_manager',(array)$user->roles,true)){return new WP_Error('wsm_club_user_missing','کاربر فروشگاه پیدا نشد.',array('status'=>404));}
        $current=(int)WSM_Permissions::current_device()->user_id; $is_admin=current_user_can('edit_users');
        if($current!==(int)$id&&!$is_admin){return new WP_Error('wsm_club_user_forbidden','فقط مدیر کاربران می‌تواند حساب دیگری را ویرایش کند.',array('status'=>403));}
        if(isset($data['password'])&&is_scalar($data['password'])&&(string)$data['password']!==''){
            $pass=(string)$data['password']; if(strlen($pass)<12){return new WP_Error('wsm_club_user_password','گذرواژه باید حداقل ۱۲ نویسه باشد.',array('status'=>400));}
            $r=wp_update_user(array('ID'=>(int)$id,'user_pass'=>$pass)); if(is_wp_error($r)){return $r;}
        }
        if(isset($data['status'])){
            if(!$is_admin||!in_array($data['status'],array('active','inactive'),true)||$current===(int)$id&&$data['status']==='inactive'){return new WP_Error('wsm_club_user_status','تغییر وضعیت این کاربر مجاز نیست.',array('status'=>403));}
            update_user_meta((int)$id,'wsm_app_user_status',$data['status']);
            if($data['status']==='inactive'){$wpdb=$GLOBALS['wpdb'];$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}wsm_access_keys SET revoked_at=%s WHERE user_id=%d AND revoked_at IS NULL AND key_hash<>''",current_time('mysql'),(int)$id));}
        }
        $one_time_key='';
        if(!empty($data['issue_access_key'])){
            if(!$is_admin){return new WP_Error('wsm_club_user_key_forbidden','ساخت کلید جدید فقط برای مدیر کاربران مجاز است.',array('status'=>403));}
            update_user_meta((int)$id,'wsm_app_user_status','active');
            $key=WSM_Auth::generate_user_access_key((int)$id); if(is_wp_error($key)){return $key;} $one_time_key=$key['plain'];
        }
        WSM_Activity_Log::log('update','club_user',(int)$id,$user->user_login,'','به‌روزرسانی شد','success');
        return array('id'=>(int)$id,'user_login'=>$user->user_login,'display_name'=>$user->display_name,'user_email'=>$user->user_email,'status'=>get_user_meta($id,'wsm_app_user_status',true)==='inactive'?'inactive':'active','access_key_once'=>$one_time_key);
    }

    private static function debt_id_list($value) {
        if ($value===null) { return array(); }
        if (!is_array($value)) { return new WP_Error('wsm_club_debt_items_invalid','فهرست فاکتورهای انتخاب‌شده نامعتبر است.',array('status'=>400)); }
        if (count($value)>100) { return new WP_Error('wsm_club_debt_items_limit','در هر نوبت حداکثر ۱۰۰ بدهی قابل تسویه است.',array('status'=>400)); }
        $ids=array();
        foreach($value as $raw){if(!is_scalar($raw)||absint($raw)<1){return new WP_Error('wsm_club_debt_item_invalid','شناسهٔ بدهی انتخاب‌شده معتبر نیست.',array('status'=>400));}$ids[]=absint($raw);}
        return array_values(array_unique($ids));
    }

    public static function register_debt_routes() {
        if (!function_exists('register_rest_route')) { return; }
        register_rest_route('wsm/v1','/club/debtors',array('methods'=>'GET','callback'=>array(__CLASS__,'debtors_endpoint'),'permission_callback'=>array(__CLASS__,'debt_route_permission')));
        register_rest_route('wsm/v1','/club/debtors/transactions',array('methods'=>'GET','callback'=>array(__CLASS__,'debt_transactions_endpoint'),'permission_callback'=>array(__CLASS__,'debt_route_permission')));
        register_rest_route('wsm/v1','/club/debtors/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'member_debt_endpoint'),'permission_callback'=>array(__CLASS__,'debt_route_permission')));
        register_rest_route('wsm/v1','/club/debtors/(?P<id>\d+)/payments',array('methods'=>'POST','callback'=>array(__CLASS__,'settle_debt_endpoint'),'permission_callback'=>array(__CLASS__,'debt_route_permission')));
    }

    public static function debt_route_permission($request) {
        if (!WSM_Util::is_woo_active()) { return WSM_Util::woo_required_error(); }
        $token=WSM_Permissions::extract_token($request);
        if ($token==='') { return new WP_Error('wsm_no_token','توکن ارسال نشده است.',array('status'=>401)); }
        $device=WSM_Auth::validate_bearer($token);
        if (is_wp_error($device)) { return $device; }
        $signed=WSM_Security::verify_signature($request,$device);
        if (is_wp_error($signed)) { return $signed; }
        $user=get_userdata((int)$device->user_id);
        if (!$user||(!user_can($user,'manage_woocommerce')&&!user_can($user,'manage_options'))) { return new WP_Error('wsm_forbidden','دسترسی به این بخش ندارید.',array('status'=>403)); }
        if (get_user_meta((int)$device->user_id,'wsm_app_user_status',true)==='inactive') { return new WP_Error('wsm_app_user_inactive','حساب کاربری این اپ غیرفعال شده است.',array('status'=>403)); }
        WSM_Permissions::set_device($device);
        $write=strtoupper((string)$request->get_method())==='POST';
        $allowed=$write?array('club.finance.manage','club.billing.manage'):array('club.finance.view','club.finance.manage','club.billing.manage');
        foreach($allowed as $permission){if(WSM_Devices::device_can($device,$permission)){return true;}}
        return new WP_Error('wsm_device_permission_denied',$write?'برای تسویهٔ بدهی، مجوز مدیریت صندوق یا صورتحساب لازم است.':'برای مشاهدهٔ بدهکاران، مجوز مشاهدهٔ صندوق لازم است.',array('status'=>403,'permission'=>$allowed));
    }

    public static function debtors_endpoint($request) {
        $args=$request->get_params(); $args['type']='debtors';
        return self::report($args);
    }

    public static function member_debt_endpoint($request) {
        return self::report(array('type'=>'member_pos_debt','member_id'=>absint($request->get_param('id'))));
    }

    public static function debt_transactions_endpoint($request) {
        $args=$request->get_params(); $args['type']='pos_debt_payments';
        $args['from']=isset($args['from'])?(string)$args['from']:'2000-01-01';
        $args['to']=isset($args['to'])?(string)$args['to']:current_time('Y-m-d');
        $args['per_page']=isset($args['limit'])?absint($args['limit']):5;
        return self::report($args);
    }

    public static function settle_debt_endpoint($request) {
        $body=$request->get_json_params();
        if (!is_array($body)) { $body=$request->get_body_params(); }
        return self::settle_member_debt(absint($request->get_param('id')),is_array($body)?$body:array());
    }

    public static function settle_member_debt($member_id,$data) {
        global $wpdb;
        $member_id=absint($member_id);
        if (!$member_id||!is_array($data)) { return new WP_Error('wsm_club_debt_member_invalid','ورزشکار و اطلاعات پرداخت معتبر نیست.',array('status'=>400)); }
        $settlement_uuid=isset($data['settlement_uuid'])&&is_scalar($data['settlement_uuid'])?strtolower(trim((string)$data['settlement_uuid'])):'';
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$settlement_uuid)){return new WP_Error('wsm_club_debt_uuid_invalid','شناسهٔ یکتای تسویهٔ بدهی معتبر نیست؛ فرم را تازه‌سازی کنید.',array('status'=>400));}
        $settlement_reference_id='debt-settlement-uuid:'.$settlement_uuid;
        $device=WSM_Permissions::current_device();
        if ($device&&!WSM_Devices::device_can($device,'club.finance.manage')&&!WSM_Devices::device_can($device,'club.billing.manage')) { return new WP_Error('wsm_device_permission_denied','برای ثبت تسویه، مجوز مدیریت صندوق لازم است.',array('status'=>403)); }
        $approved=self::verify_finance_manager($data,'pos_debt_settlement');if(is_wp_error($approved)){return $approved;}
        if(empty($data['receipt_confirmed'])||!rest_sanitize_boolean($data['receipt_confirmed'])){return new WP_Error('wsm_club_debt_receipt_unconfirmed','تسویه فقط پس از دریافت واقعی وجه و تأیید آن ثبت می‌شود.',array('status'=>400));}
        $amount=self::amount(isset($data['amount'])?$data['amount']:null,'مبلغ دریافتی',false);
        if (is_wp_error($amount)) { return $amount; }
        $amount=(float)$amount;
        $method=isset($data['method'])&&is_scalar($data['method'])?sanitize_key((string)$data['method']):'cash';
        $method_map=array('cash'=>array('cash','cash'),'card_transfer'=>array('card','pos'),'bank_transfer'=>array('bank_transfer','bank'),'check'=>array('check','bank'));
        if (!isset($method_map[$method])) { return new WP_Error('wsm_club_debt_method','روش دریافت بدهی معتبر نیست.',array('status'=>400)); }
        $ledger_method=$method_map[$method][0];$expected_account_type=$method_map[$method][1];
        $mapping=self::device_finance_accounts();
        $default_account=($expected_account_type==='cash')?(int)$mapping['cash_account_id']:(($expected_account_type==='pos')?(int)$mapping['pos_account_id']:(int)$mapping['default_bank_account_id']);
        $requested_account=absint($data['account_id']??0);
        if(in_array($expected_account_type,array('cash','pos'),true)){
            if($requested_account&&$requested_account!==$default_account){return new WP_Error('wsm_club_debt_session_account_mismatch','برای دریافت نقدی یا کارتخوان باید از حساب نگاشت‌شده به همین POS استفاده شود.',array('status'=>409));}
            $account_id=$default_account;
        }else{
            $account_id=$requested_account?:$default_account;
        }
        $selected_account_type=$account_id?(string)$wpdb->get_var($wpdb->prepare('SELECT account_type FROM '.self::table('financial_accounts').' WHERE id=%d AND is_active=1',$account_id)):'';
        if(!$account_id||$selected_account_type!==$expected_account_type){return new WP_Error('wsm_club_debt_account_invalid','حساب دریافت باید با روش واقعی وجه (صندوق، کارتخوان یا بانک) منطبق باشد.',array('status'=>400));}
        $date=isset($data['occurred_at'])&&is_scalar($data['occurred_at'])?sanitize_text_field((string)$data['occurred_at']):current_time('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) { return new WP_Error('wsm_club_debt_date','تاریخ پرداخت معتبر نیست.',array('status'=>400)); }
        $date_parts=explode('-',$date); if (!checkdate((int)$date_parts[1],(int)$date_parts[2],(int)$date_parts[0])) { return new WP_Error('wsm_club_debt_date','تاریخ پرداخت معتبر نیست.',array('status'=>400)); }
        $occurred_at=$date.' 12:00:00';
        $reference=self::text(isset($data['reference'])?$data['reference']:'',120);
        $note=isset($data['note'])&&is_scalar($data['note'])?sanitize_textarea_field((string)$data['note']):'';
        if (function_exists('mb_substr')) { $note=mb_substr($note,0,255,'UTF-8'); } else { $note=substr($note,0,255); }
        $order_ids=self::debt_id_list(isset($data['order_ids'])?$data['order_ids']:array());
        if (is_wp_error($order_ids)) { return $order_ids; }
        $membership_ids=self::debt_id_list(isset($data['membership_ids'])?$data['membership_ids']:array());
        if (is_wp_error($membership_ids)) { return $membership_ids; }
        if (!$order_ids&&!$membership_ids) { return new WP_Error('wsm_club_debt_items_required','برای ثبت پرداخت، دست‌کم یک بدهی باز را انتخاب کنید.',array('status'=>400)); }
        if (count($order_ids)+count($membership_ids)>100) { return new WP_Error('wsm_club_debt_items_limit','در هر نوبت حداکثر ۱۰۰ بدهی قابل تسویه است.',array('status'=>400)); }
        if ($order_ids&&!function_exists('wc_get_order')) { return new WP_Error('wsm_club_woo_missing','ووکامرس فعال نیست.',array('status'=>500)); }
        $member_table=WSM_Club::table('members'); $membership_table=WSM_Club::table('memberships'); $payment_table=WSM_Club::table('payments');
        if ($wpdb->query('START TRANSACTION')===false) { return new WP_Error('wsm_club_debt_transaction','آغاز تراکنش تسویه انجام نشد.',array('status'=>500)); }
        $member=$wpdb->get_row($wpdb->prepare("SELECT * FROM $member_table WHERE id=%d FOR UPDATE",$member_id));
        if (!$member) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_debt_member_missing','پروندهٔ ورزشکار پیدا نشد.',array('status'=>404)); }
        $wpdb->last_error='';
        $existing_settlement=$wpdb->get_row($wpdb->prepare("SELECT id,member_id,amount,method,account_id,occurred_at,status FROM ".self::table('financial_ledger')." WHERE source='debt_settlement' AND reference_id=%s ORDER BY id DESC LIMIT 1 FOR UPDATE",$settlement_reference_id));
        if(!empty($wpdb->last_error)){$wpdb->query('ROLLBACK');return new WP_Error('wsm_club_debt_uuid_lookup_failed','بررسی شناسهٔ یکتای تسویه انجام نشد.',array('status'=>500));}
        if($existing_settlement){
            $same=(int)$existing_settlement->member_id===$member_id&&abs((float)$existing_settlement->amount-$amount)<=0.000001&&(string)$existing_settlement->method===$ledger_method&&(int)$existing_settlement->account_id===$account_id;
            if(!$same||(string)$existing_settlement->status!=='posted'){$wpdb->query('ROLLBACK');return new WP_Error('wsm_club_debt_uuid_conflict','این شناسهٔ تسویه قبلاً برای پرداخت دیگری استفاده شده است.',array('status'=>409));}
            $wpdb->query('COMMIT');
            return array('member_id'=>$member_id,'member_name'=>trim((string)$member->first_name.' '.(string)$member->last_name),'amount'=>(string)$existing_settlement->amount,'method'=>$ledger_method,'account_id'=>$account_id,'reference'=>$reference,'occurred_at'=>(string)$existing_settlement->occurred_at,'finance_id'=>(int)$existing_settlement->id,'idempotent_replay'=>true);
        }
        $cash_session_id=0;
        if(in_array($expected_account_type,array('cash','pos'),true)){
            $session=self::current_cash_session((int)$mapping['cash_account_id'],(int)$mapping['device_id']);
            if(!$session){$wpdb->query('ROLLBACK');return new WP_Error('wsm_club_debt_cash_session_required','برای دریافت نقدی یا کارتخوان بدهی، ابتدا نشست صندوق همین POS را باز کنید؛ پرداخت آفلاین تا بازشدن نشست روی سرور در صف می‌ماند.',array('status'=>409));}
            $session_lock=self::lock_open_cash_session((int)$session['id'],(int)$mapping['cash_account_id'],(int)$mapping['device_id']);
            if(is_wp_error($session_lock)){$wpdb->query('ROLLBACK');return $session_lock;}
            $cash_session_id=(int)$session_lock;
        }
        $locked_account=$wpdb->get_row($wpdb->prepare("SELECT id,account_type FROM ".self::table('financial_accounts')." WHERE id=%d AND is_active=1 FOR UPDATE",$account_id));
        if(!$locked_account||$locked_account->account_type!==$expected_account_type){$wpdb->query('ROLLBACK');return new WP_Error('wsm_club_debt_account_invalid','حساب دریافت هنگام ثبت غیرفعال شد یا با روش انتخابی منطبق نیست.',array('status'=>409));}
        $entries=array();
        foreach($order_ids as $order_id){
            $order=wc_get_order($order_id);
            if (!$order||!$order instanceof WC_Order||(int)$order->get_meta('_wsm_club_member_id')!==$member_id||!(bool)$order->get_meta('_wsm_club_pos_debt')) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_debt_order_invalid','یکی از فاکتورهای انتخاب‌شده متعلق به این ورزشکار یا فروش نسیه نیست.',array('status'=>409)); }
            $due=self::pos_order_debt_due($order);
            if (!in_array((string)$order->get_status(),self::pos_debt_open_order_statuses(),true)||$due<=0) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_debt_order_closed','یکی از فاکتورهای انتخاب‌شده دیگر بدهی باز ندارد.',array('status'=>409)); }
            $wpdb->last_error='';
            $receivable_rows=$wpdb->get_results($wpdb->prepare("SELECT id FROM ".self::table('financial_ledger')." WHERE order_id=%d AND kind='pos_receivable' AND status='posted' ORDER BY id ASC FOR UPDATE",$order_id),ARRAY_A);
            if(!empty($wpdb->last_error)){ $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_debt_receivable_lookup','بررسی ردیف دریافتنی فاکتور نسیه انجام نشد.',array('status'=>500)); }
            if(count((array)$receivable_rows)!==1){ $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_debt_receivable_missing','برای فاکتور نسیه باید دقیقاً یک ردیف دریافتنی ثبت‌شده وجود داشته باشد؛ تسویه تا رفع مغایرت متوقف شد.',array('status'=>409)); }
            $created=$order->get_date_created();
            $entries[]=array('type'=>'order','id'=>$order_id,'number'=>(string)$order->get_order_number(),'due'=>$due,'date'=>$created?$created->date('Y-m-d H:i:s'):'9999-12-31 23:59:59','order'=>$order,'receivable_id'=>(int)$receivable_rows[0]['id']);
        }
        foreach($membership_ids as $membership_id){
            $membership=$wpdb->get_row($wpdb->prepare("SELECT * FROM $membership_table WHERE id=%d AND member_id=%d FOR UPDATE",$membership_id,$member_id));
            if (!$membership||$membership->status==='cancelled') { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_debt_membership_invalid','یکی از بدهی‌های عضویت انتخاب‌شده پیدا نشد یا لغو شده است.',array('status'=>409)); }
            $paid=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM $payment_table WHERE membership_id=%d AND status='paid'",$membership_id));
            $due=max(0.0,(float)$membership->price-$paid);
            if ($due<=0) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_debt_membership_closed','یکی از بدهی‌های عضویت انتخاب‌شده تسویه شده است.',array('status'=>409)); }
            $entries[]=array('type'=>'membership','id'=>$membership_id,'number'=>'عضویت #'.$membership_id,'due'=>$due,'date'=>(string)$membership->created_at,'membership'=>$membership);
        }
        $available=0.0; foreach($entries as $entry){$available+=(float)$entry['due'];}
        if ($amount>$available+0.000001) { $wpdb->query('ROLLBACK'); return new WP_Error('wsm_club_debt_overpay','مبلغ از مجموع بدهی‌های انتخاب‌شده بیشتر است.',array('status'=>409,'balance_due'=>$available)); }
        usort($entries,function($a,$b){return strcmp((string)$a['date'],(string)$b['date']) ?: ((int)$a['id']<=>(int)$b['id']);});
        $remaining=$amount; $allocations=array();
        foreach($entries as $entry){
            if($remaining<=0.000001){break;}
            $part=min((float)$entry['due'],$remaining);
            if($part<=0){continue;}
            $entry['allocation']=(float)wc_format_decimal($part,min(6,max(0,(int)wc_get_price_decimals())));
            $allocations[]=$entry; $remaining=max(0.0,$remaining-$entry['allocation']);
        }
        if($remaining>0.00001){$wpdb->query('ROLLBACK');return new WP_Error('wsm_club_debt_allocation','تقسیم مبلغ بین بدهی‌ها انجام نشد.',array('status'=>409));}
        $created_at=current_time('mysql'); $payment_ids=array(); $labels=array();
        foreach($allocations as $allocation){
            $labels[]=($allocation['type']==='order'?'فاکتور #':'عضویت #').$allocation['number'].' · '.wc_format_decimal($allocation['allocation']);
            if($allocation['type']!=='membership'){continue;}
            $inserted=$wpdb->insert($payment_table,array('member_id'=>$member_id,'membership_id'=>(int)$allocation['id'],'amount'=>wc_format_decimal($allocation['allocation']),'method'=>$ledger_method,'reference'=>$reference,'status'=>'paid','note'=>$note,'paid_at'=>$occurred_at,'created_by'=>get_current_user_id(),'created_at'=>$created_at));
            if($inserted===false){$wpdb->query('ROLLBACK');return new WP_Error('wsm_club_debt_payment_save','ثبت پرداخت عضویت انجام نشد.',array('status'=>500));}
            $payment_ids[]=(int)$wpdb->insert_id;
        }
        $description='تسویه بدهی: '.implode('؛ ',$labels);
        if($note!==''){$description.=' · '.$note;}
        $single_membership=count($membership_ids)===1?(int)$membership_ids[0]:0;
        $single_order=count($order_ids)===1?(int)$order_ids[0]:0;
        $single_payment=count($payment_ids)===1?(int)$payment_ids[0]:0;
        $member_name=trim((string)$member->first_name.' '.(string)$member->last_name);
        $finance_id=self::add_finance('member_payment',$amount,'دریافت بابت بدهی '.($member->member_code?:$member_name),array('member_id'=>$member_id,'membership_id'=>$single_membership,'payment_id'=>$single_payment,'order_id'=>$single_order,'account_id'=>$account_id,'cash_session_id'=>$cash_session_id,'category'=>'pos_debt_settlement','method'=>$ledger_method,'source'=>'debt_settlement','transaction_type'=>'income','reference'=>$reference,'reference_id'=>$settlement_reference_id,'occurred_at'=>$occurred_at,'description'=>$description));
        if(is_wp_error($finance_id)){$wpdb->query('ROLLBACK');return $finance_id;}
        $full_order_ids=array(); $allocation_result=array();
        foreach($allocations as $allocation){
            $left=max(0.0,(float)$allocation['due']-(float)$allocation['allocation']);
            $allocation_result[]=array('type'=>$allocation['type'],'id'=>(int)$allocation['id'],'number'=>(string)$allocation['number'],'amount'=>(string)$allocation['allocation'],'balance_due'=>(string)$left);
            if($allocation['type']!=='order'){continue;}
            $order=$allocation['order'];
            $old_paid=max(0.0,(float)$order->get_meta('_wsm_club_pos_debt_paid_amount',true));
            $new_paid=$old_paid+(float)$allocation['allocation'];
            $gross=max(0.0,(float)$order->get_total()-(float)$order->get_total_refunded());
            $left=max(0.0,$gross-$new_paid);
            $order->update_meta_data('_wsm_club_pos_debt_paid_amount',(string)wc_format_decimal($new_paid));
            $order->update_meta_data('_wsm_club_pos_debt_remaining',(string)wc_format_decimal($left));
            $order->update_meta_data('_wsm_club_pos_debt_last_paid_at',$occurred_at);
            $order->update_meta_data('_wsm_club_pos_debt_settled',$left<=0.000001?1:0);
            if($left<=0.000001){$order->update_meta_data('_wsm_club_pos_debt_settled_at',$occurred_at);$full_order_ids[]=(int)$order->get_id();}
            $history=$order->get_meta('_wsm_club_pos_debt_payment_history',true);if(!is_array($history)){$history=array();}
            $history[]=array('amount'=>(string)$allocation['allocation'],'method'=>$ledger_method,'reference'=>$reference,'note'=>$note,'occurred_at'=>$occurred_at,'finance_id'=>(int)$finance_id);
            $order->update_meta_data('_wsm_club_pos_debt_payment_history',array_slice($history,-100));
            if(method_exists($order,'add_order_note')){$order->add_order_note('دریافت بابت بدهی: '.wc_format_decimal($allocation['allocation']).' · '.$ledger_method.($reference!==''?' · پیگیری '.$reference:''));}
            $saved=$order->save(); if(!$saved){$wpdb->query('ROLLBACK');return new WP_Error('wsm_club_debt_order_save','به‌روزرسانی ماندهٔ فاکتور انجام نشد.',array('status'=>500));}
            $receivable_status=$left<=0.000001?'settled':'partial';
            $receivable_updated=$wpdb->query($wpdb->prepare("UPDATE ".self::table('financial_ledger')." SET ledger_status=%s WHERE id=%d AND order_id=%d AND kind='pos_receivable' AND status='posted'",$receivable_status,(int)$allocation['receivable_id'],(int)$order->get_id()));
            if($receivable_updated===false){$wpdb->query('ROLLBACK');return new WP_Error('wsm_club_debt_ledger_update','به‌روزرسانی وضعیت دریافتنی فاکتور انجام نشد.',array('status'=>500));}
        }
        $wpdb->query('COMMIT');
        foreach(array_unique($full_order_ids) as $settled_order_id){
            try{$settled_order=wc_get_order($settled_order_id);if($settled_order&&method_exists($settled_order,'payment_complete')&&!$settled_order->is_paid()){$settled_order->payment_complete($reference);}if($settled_order&&$settled_order->get_status()==='on-hold'){$settled_order->update_status('processing','بدهی فاکتور به‌طور کامل تسویه شد.');}}catch(Throwable $ignored){}
        }
        self::sync_receivables_projection();
        WSM_Activity_Log::log('create','pos_debt_settlement',(int)$finance_id,'تسویهٔ بدهی ورزشکار',array('member_id'=>$member_id),array('amount'=>(string)$amount,'account_id'=>$account_id,'method'=>$ledger_method,'allocations'=>$allocation_result,'approved_by_user_id'=>(int)$approved['user_id']),'success',null,(int)$approved['user_id']);
        return array('member_id'=>$member_id,'member_name'=>$member_name,'amount'=>(string)$amount,'method'=>$ledger_method,'account_id'=>$account_id,'reference'=>$reference,'occurred_at'=>$occurred_at,'finance_id'=>(int)$finance_id,'allocations'=>$allocation_result,'settled_order_ids'=>array_values(array_unique($full_order_ids)));
    }


    /** نسخهٔ پشتیبان format contract. Keep FORMAT_VERSION stable unless the schema changes. */
    public static function backup_format_version() {
        return 1;
    }

    /** Only operational club data, the offline member map, and same-site messages belong in data backups. */
    private static function backup_club_table_name($name) {
        return is_string($name) && (strpos($name, 'wsm_club_') === 0 || in_array($name, array('wsm_sync_member_map', 'wsm_messages', 'wsm_message_audit'), true));
    }

    private static function backup_club_option_names() {
        return array('wsm_club_settings', 'wsm_finance_control_config', 'wsm_athlete_portal_intents', 'wsm_athlete_portal_renewal_reviews', 'wsm_athlete_portal_raffles', 'wsm_athlete_portal_coupons');
    }

    private static function backup_message_table_name($name) {
        return in_array($name, array('wsm_messages', 'wsm_message_audit'), true);
    }

    /** Opaque same-install binding; never store or return the WordPress salt itself. */
    private static function backup_message_site_binding() {
        if (!function_exists('home_url') || !function_exists('wp_salt')) { return ''; }
        $home = home_url('/');
        $salt = wp_salt('auth');
        if (!is_string($home) || $home === '' || !is_string($salt) || $salt === '') { return ''; }
        return hash_hmac('sha256', 'wsm-club-messages-v1|' . $home, $salt);
    }

    private static function backup_message_site_compatible($document) {
        if (!is_array($document) || !isset($document['plugin_settings']['backup_configuration']) || !is_array($document['plugin_settings']['backup_configuration'])) { return false; }
        $binding = $document['plugin_settings']['backup_configuration']['club_message_site_binding'] ?? '';
        $current = self::backup_message_site_binding();
        return is_string($binding) && preg_match('/^[a-f0-9]{64}$/D', $binding) && is_string($current) && preg_match('/^[a-f0-9]{64}$/D', $current) && hash_equals($current, $binding);
    }

    private static function backup_json_flags() {
        return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;
    }

    /** Canonical path recorded in the archive; restore always maps it to the active plugin root. */
    private static function backup_archive_plugin_prefix() {
        return 'wp-content/plugins/wp-store-manager/';
    }

    private static function backup_plugin_root_path() {
        if(!defined('WSM_PATH')||!is_dir(WSM_PATH)||is_link(WSM_PATH)){
            return new WP_Error('wsm_backup_plugin_root_invalid','پوشهٔ اصلی افزونه برای تهیهٔ بکاپ معتبر یا در دسترس نیست.',array('status'=>500));
        }
        $root=realpath(WSM_PATH);
        if($root===false||!is_dir($root)){return new WP_Error('wsm_backup_plugin_root_invalid','مسیر اصلی افزونه قابل‌بررسی نیست.',array('status'=>500));}
        return untrailingslashit(wp_normalize_path($root));
    }

    private static function backup_plugin_file_group($relative_path) {
        $relative_path=wp_normalize_path((string)$relative_path);
        $slash=strpos($relative_path,'/');
        return $slash===false?'__root__':substr($relative_path,0,$slash);
    }

    private static function backup_archive_relative_path($archive_path) {
        if(!is_string($archive_path)||strpos($archive_path,"\0")!==false||strpos($archive_path,'\\')!==false||strpos($archive_path,self::backup_archive_plugin_prefix())!==0){return false;}
        $relative=substr($archive_path,strlen(self::backup_archive_plugin_prefix()));
        if($relative===''||strlen($relative)>512||preg_match('/[\x00-\x1F\x7F]/',$relative)){return false;}
        $parts=explode('/',$relative);
        foreach($parts as $part){
            if($part===''||$part==='.'||$part==='..'||!preg_match('/^[A-Za-z0-9._@-]{1,128}$/D',$part)||self::backup_plugin_component_is_excluded($part)||self::backup_plugin_file_is_temporary($part)){return false;}
        }
        return $relative;
    }

    private static function backup_plugin_component_is_excluded($name) {
        $name=strtolower((string)$name);
        return in_array($name,array('cache','caches','tmp','temp','temporary','__pycache__','.cache','.git','.svn','.hg'),true);
    }

    private static function backup_plugin_file_is_temporary($name) {
        return (bool)preg_match('/(?:\.tmp|\.temp|\.swp|\.bak|~)$/i',(string)$name);
    }

    /** Paths used to store backups are never recursively copied into the plugin archive. */
    private static function backup_plugin_storage_exclusions() {
        $paths=array();$configuration=self::get_backup_configuration();
        if(isset($configuration['storage_mode'])&&$configuration['storage_mode']==='custom'&&isset($configuration['storage_path'])&&is_scalar($configuration['storage_path'])){$paths[]=(string)$configuration['storage_path'];}
        else{$default=self::default_backup_directory_path();if(!is_wp_error($default)){$paths[]=(string)$default;}}
        if(isset($configuration['external_storage_path'])&&is_scalar($configuration['external_storage_path'])&&trim((string)$configuration['external_storage_path'])!==''){$paths[]=(string)$configuration['external_storage_path'];}
        $excluded=array();
        foreach($paths as $path){
            $path=untrailingslashit(wp_normalize_path(trim((string)$path)));
            if($path===''){continue;}
            $real=realpath($path);if($real!==false){$path=untrailingslashit(wp_normalize_path($real));}
            $excluded[]=$path;
        }
        return array_values(array_unique($excluded));
    }

    private static function backup_plugin_walk_files($directory,$root,$storage_exclusions,$depth,&$files,&$total_bytes) {
        if($depth>32){return new WP_Error('wsm_backup_plugin_depth_limit','عمق پوشه‌های افزونه برای تهیهٔ بکاپ بیش از حد است.',array('status'=>413));}
        $entries=@scandir($directory);
        if(!is_array($entries)){return new WP_Error('wsm_backup_plugin_scan_failed','خواندن یکی از پوشه‌های افزونه برای بکاپ ناموفق بود.',array('status'=>500));}
        foreach($entries as $name){
            if($name==='.'||$name==='..'||self::backup_plugin_component_is_excluded($name)||self::backup_plugin_file_is_temporary($name)){continue;}
            $path=trailingslashit($directory).$name;
            if(is_link($path)){continue;}
            $normalized=wp_normalize_path($path);
            $excluded_storage=false;
            foreach($storage_exclusions as $excluded){if(self::backup_path_is_within_root($normalized,$excluded)){$excluded_storage=true;break;}}
            if($excluded_storage){continue;}
            if(is_dir($path)){
                $real=realpath($path);
                if($real===false||!self::backup_path_is_within_root(wp_normalize_path($real),$root)){continue;}
                $nested=self::backup_plugin_walk_files($real,$root,$storage_exclusions,$depth+1,$files,$total_bytes);
                if(is_wp_error($nested)){return $nested;}
                continue;
            }
            if(!is_file($path)||!is_readable($path)){continue;}
            $real=realpath($path);
            if($real===false||!self::backup_path_is_within_root(wp_normalize_path($real),$root)){continue;}
            $relative=ltrim(substr(wp_normalize_path($real),strlen($root)),'/');
            $archive_path=self::backup_archive_plugin_prefix().$relative;
            if(self::backup_archive_relative_path($archive_path)===false){return new WP_Error('wsm_backup_plugin_filename_invalid','نام یکی از فایل‌های افزونه برای آرشیو ایمن معتبر نیست.',array('status'=>400));}
            $size=@filesize($real);
            if($size===false||$size<0||$size>67108864){return new WP_Error('wsm_backup_plugin_file_size_limit','حجم یکی از فایل‌های افزونه بیش از حد مجاز است: '.$relative,array('status'=>413));}
            if(count($files)>=20000||$total_bytes+(int)$size>1073741824){return new WP_Error('wsm_backup_plugin_total_size_limit','تعداد یا حجم کل فایل‌های افزونه از سقف ایمن بکاپ بیشتر است.',array('status'=>413));}
            $total_bytes+=(int)$size;
            $permissions=@fileperms($real);$mode=$permissions!==false&&($permissions&0111)?0755:0644;
            $files[]=array('relative_path'=>$relative,'archive_path'=>$archive_path,'full_path'=>$real,'size'=>(int)$size,'mode'=>$mode,'group'=>self::backup_plugin_file_group($relative));
        }
        return true;
    }

    /** Enumerate regular plugin files; links, cache/temp directories and backup destinations are skipped. */
    private static function discover_backup_plugin_files($selected_groups=null) {
        $root=self::backup_plugin_root_path();if(is_wp_error($root)){return $root;}
        if($selected_groups!==null&&!self::backup_array_is_list($selected_groups)){return new WP_Error('wsm_backup_plugin_selection_invalid','فهرست پوشه‌های انتخاب‌شده برای بکاپ معتبر نیست.',array('status'=>400));}
        $storage_exclusions=self::backup_plugin_storage_exclusions();$files=array();$total=0;
        $walk=self::backup_plugin_walk_files($root,$root,$storage_exclusions,0,$files,$total);if(is_wp_error($walk)){return $walk;}
        usort($files,function($left,$right){return strcmp($left['relative_path'],$right['relative_path']);});
        if($selected_groups!==null){
            $allowed=array_fill_keys($selected_groups,true);$files=array_values(array_filter($files,function($file)use($allowed){return isset($allowed[$file['group']]);}));
        }
        return $files;
    }

    /** Discover all base tables with the active WordPress prefix and WSM logical prefix. */
    private static function discover_backup_table_names() {
        global $wpdb;
        if(!is_object($wpdb)||empty($wpdb->prefix)||!preg_match('/^[A-Za-z0-9_]+$/',(string)$wpdb->prefix)){
            return new WP_Error('wsm_backup_db_unavailable','پیشوند دیتابیس وردپرس برای ساخت بکاپ معتبر نیست.',array('status'=>500));
        }
        $prefix=(string)$wpdb->prefix;$all_tables=$wpdb->get_results('SHOW FULL TABLES',ARRAY_N);
        if(!is_array($all_tables)||$wpdb->last_error!==''){return new WP_Error('wsm_backup_tables_read_failed','فهرست جدول‌های دیتابیس برای بکاپ خوانده نشد.',array('status'=>500));}
        $tables=array();
        foreach($all_tables as $entry){
            if(!is_array($entry)||count($entry)<2||strtoupper((string)$entry[1])!=='BASE TABLE'){continue;}
            $physical=(string)$entry[0];
            if(strpos($physical,$prefix.'wsm_')!==0){continue;}
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$physical)){return new WP_Error('wsm_backup_table_name_invalid','نام یکی از جدول‌های افزونه برای بکاپ معتبر نیست.',array('status'=>500));}
            $logical=substr($physical,strlen($prefix));
            if(!preg_match('/^wsm_[A-Za-z0-9_]+$/D',$logical)||!self::backup_club_table_name($logical)){continue;}
            $tables[$logical]=$physical;
        }
        ksort($tables,SORT_STRING);
        if(count($tables)>500){return new WP_Error('wsm_backup_table_limit','تعداد جدول‌های افزونه برای قالب بکاپ بیش از حد مجاز است.',array('status'=>413));}
        return $tables;
    }

    /** Inventory only operational club tables; plugin source files are never part of a data backup. */
    public static function get_backup_inventory() {
        global $wpdb;
        $tables=self::discover_backup_table_names();if(is_wp_error($tables)){return $tables;}
        $table_items=array();
        foreach($tables as $logical=>$physical){
            $quoted='`'.str_replace('`','``',$physical).'`';$count=$wpdb->get_var("SELECT COUNT(*) FROM $quoted");
            if($wpdb->last_error!==''||!is_numeric($count)||((int)$count)<0){return new WP_Error('wsm_backup_inventory_count_failed','شمارش ردیف‌های جدول '.$logical.' انجام نشد.',array('status'=>500));}
            $table_items[]=array('name'=>$logical,'record_count'=>(int)$count);
        }
        return array('database_tables'=>$table_items,'plugin_paths'=>array(),'excluded_directories'=>array('cache','tmp','temp','__pycache__','.git','.svn','.hg'));
    }

    /** Validate a manual selection against the club-table inventory; automatic/safety backups select all allowed tables. */
    private static function normalize_backup_creation_selection($selection) {
        if($selection===null){return array('database_tables'=>null,'plugin_paths'=>null);}
        if(!is_array($selection)){return new WP_Error('wsm_backup_selection_invalid','انتخاب بخش‌های بکاپ معتبر نیست.',array('status'=>400));}
        $keys=array_keys($selection);sort($keys);$expected=array('database_tables','plugin_paths');sort($expected);
        if($keys!==$expected||!self::backup_array_is_list($selection['database_tables'])||!self::backup_array_is_list($selection['plugin_paths'])){return new WP_Error('wsm_backup_selection_invalid','فهرست جدول‌ها یا مسیرهای بکاپ معتبر نیست.',array('status'=>400));}
        if($selection['plugin_paths']){return new WP_Error('wsm_backup_code_files_disabled','فایل‌های کد افزونه در بکاپ دادهٔ باشگاه پشتیبانی نمی‌شوند.',array('status'=>400));}
        $inventory=self::get_backup_inventory();if(is_wp_error($inventory)){return $inventory;}
        $known_tables=array();foreach($inventory['database_tables'] as $table){$known_tables[$table['name']]=true;}
        $tables=array();foreach($selection['database_tables'] as $name){if(!is_string($name)||!isset($known_tables[$name])||isset($tables[$name])){return new WP_Error('wsm_backup_selection_table_invalid','جدول انتخاب‌شده در فهرست داده‌های باشگاه وجود ندارد یا تکراری است.',array('status'=>400));}$tables[$name]=true;}
        foreach(array('wsm_messages','wsm_message_audit') as $required_message_table){if(isset($known_tables[$required_message_table])){$tables[$required_message_table]=true;}}
        if(!$tables){return new WP_Error('wsm_backup_selection_empty','برای ساخت بکاپ دست‌کم یک جدول دادهٔ باشگاه را انتخاب کنید.',array('status'=>400));}
        $table_names=array_keys($tables);sort($table_names,SORT_STRING);
        return array('database_tables'=>$table_names,'plugin_paths'=>array());
    }

    private static function backup_canonical_json($document) {
        if (!is_array($document)) { return false; }
        unset($document['checksum']);
        return wp_json_encode($document, self::backup_json_flags());
    }

    /** Build the versioned .wsmbackup payload and SHA-256 checksum. */
    public static function build_backup_document($database_tables, $plugin_settings, $backup_type = 'manual') {
        if (!is_array($database_tables) || !is_array($plugin_settings)) {
            return new WP_Error('wsm_backup_payload_invalid', 'ساختار داده‌های بکاپ نامعتبر است.', array('status' => 500));
        }
        foreach($database_tables as $table){if(!is_array($table)||!isset($table['name'])||!self::backup_club_table_name($table['name'])){return new WP_Error('wsm_backup_table_not_club','بستهٔ داده فقط می‌تواند شامل جدول‌های عملیاتی مجاز باشگاه باشد.',array('status'=>400));}}
        if(!isset($plugin_settings['options'],$plugin_settings['backup_configuration'])||!is_array($plugin_settings['options'])||!is_array($plugin_settings['backup_configuration'])){return new WP_Error('wsm_backup_settings_invalid','ساختار تنظیمات مجاز برای بستهٔ داده معتبر نیست.',array('status'=>400));}
        $allowed_options=self::backup_club_option_names();
        foreach($plugin_settings['options'] as $option_name=>$value){if(!is_string($option_name)||!in_array($option_name,$allowed_options,true)||!is_array($value)){return new WP_Error('wsm_backup_option_not_club','بستهٔ داده فقط می‌تواند شامل شش تنظیم عملیاتی مجاز باشد.',array('status'=>400));}}
        if(!self::backup_message_site_compatible(array('plugin_settings'=>$plugin_settings))){return new WP_Error('wsm_backup_message_binding_unavailable','نشانۀ امن و معتبر برای وابستگی پیام‌ها به همین نصب در دادهٔ بکاپ وجود ندارد.',array('status'=>500));}
        $record_count = 0;
        foreach ($database_tables as $table) {
            if (is_array($table) && isset($table['rows']) && is_array($table['rows'])) { $record_count += count($table['rows']); }
        }
        $metadata = array(
            'format_version' => self::backup_format_version(),
            'created_at' => current_time('mysql'),
            'created_at_gmt' => gmdate('Y-m-d H:i:s'),
            'plugin_version' => defined('WSM_VERSION') ? (string) WSM_VERSION : 'unknown',
            'backup_type' => in_array($backup_type, array('manual','automatic','pre_restore'), true) ? $backup_type : 'manual',
            'table_count' => count($database_tables),
            'record_count' => $record_count,
        );
        $payload = array('backup_metadata' => $metadata, 'database_tables' => array_values($database_tables), 'plugin_settings' => $plugin_settings);
        $canonical = self::backup_canonical_json($payload);
        if (!is_string($canonical)) { return new WP_Error('wsm_backup_encode_failed', 'تبدیل اطلاعات بکاپ به JSON انجام نشد.', array('status' => 500)); }
        $payload['checksum'] = hash('sha256', $canonical);
        $json = wp_json_encode($payload, self::backup_json_flags());
        if (!is_string($json)) { return new WP_Error('wsm_backup_encode_failed', 'تبدیل فایل بکاپ به JSON انجام نشد.', array('status' => 500)); }
        if (strlen($json) > 268435456) { return new WP_Error('wsm_backup_size_limit', 'حجم بکاپ از سقف ۲۵۶ مگابایت بیشتر است.', array('status' => 413)); }
        return array('document' => $payload, 'json' => $json, 'checksum' => $payload['checksum'], 'metadata' => $metadata);
    }

    private static function backup_array_is_list($value) {
        if(!is_array($value)){return false;}
        if($value===array()){return true;}
        return array_keys($value)===range(0,count($value)-1);
    }

    private static function backup_valid_timestamp($value) {
        if(!is_string($value)||!preg_match('/^(\d{4})-(\d{2})-(\d{2}) ([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/',$value,$m)){return false;}
        return checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
    }

    /** Recursive JSON value validator; imported settings may not introduce credentials or objects. */
    private static function validate_backup_json_value($value,$depth=0) {
        if($depth>40){return new WP_Error('wsm_backup_value_depth','عمق یکی از مقادیر بکاپ بیش از حد است.',array('status'=>400));}
        if(is_array($value)){
            foreach($value as $key=>$item){
                if(self::backup_sensitive_field_name($key)){
                    return new WP_Error('wsm_backup_credential_field','فایل بکاپ حاوی فیلد تنظیماتی حساسِ پشتیبانی‌نشده است.',array('status'=>400));
                }
                $valid=self::validate_backup_json_value($item,$depth+1);
                if(is_wp_error($valid)){return $valid;}
            }
            return true;
        }
        if(is_object($value)||is_resource($value)){return new WP_Error('wsm_backup_value_type','نوع یکی از مقادیر بکاپ پشتیبانی نمی‌شود.',array('status'=>400));}
        if(is_float($value)&&!is_finite($value)){return new WP_Error('wsm_backup_value_number','یکی از اعداد بکاپ معتبر نیست.',array('status'=>400));}
        if($value===null||is_string($value)||is_int($value)||is_float($value)||is_bool($value)){return true;}
        return new WP_Error('wsm_backup_value_type','نوع یکی از مقادیر بکاپ پشتیبانی نمی‌شود.',array('status'=>400));
    }

    /** Deeply validate version-1 payloads before allowing section selection or restore preview. */
    private static function validate_backup_document_payload($document) {
        $root_keys=array('backup_metadata','database_tables','plugin_settings','checksum');
        $actual=array_keys($document);sort($actual);sort($root_keys);
        if($actual!==$root_keys){return new WP_Error('wsm_backup_fields_invalid','ساختار اصلی فایل بکاپ دارای فیلدهای نامعتبر است.',array('status'=>400));}
        $meta=$document['backup_metadata'];
        $meta_keys=array('format_version','created_at','created_at_gmt','plugin_version','backup_type','table_count','record_count');
        $actual_meta=array_keys($meta);sort($actual_meta);sort($meta_keys);
        if($actual_meta!==$meta_keys||!is_int($meta['format_version'])||$meta['format_version']!==self::backup_format_version()||
            !self::backup_valid_timestamp($meta['created_at'])||!self::backup_valid_timestamp($meta['created_at_gmt'])||
            !is_string($meta['plugin_version'])||strlen($meta['plugin_version'])>64||
            !in_array($meta['backup_type'],array('manual','automatic','pre_restore'),true)||
            !is_int($meta['table_count'])||$meta['table_count']<0||$meta['table_count']>500||
            !is_int($meta['record_count'])||$meta['record_count']<0){
            return new WP_Error('wsm_backup_metadata_invalid','فرادادهٔ فایل بکاپ معتبر نیست.',array('status'=>400));
        }
        $tables=$document['database_tables'];
        if(!self::backup_array_is_list($tables)||count($tables)>500||$meta['table_count']!==count($tables)){
            return new WP_Error('wsm_backup_tables_invalid','فهرست جدول‌های بکاپ معتبر نیست.',array('status'=>400));
        }
        $seen_tables=array();$total_records=0;
        foreach($tables as $table){
            if(!is_array($table)){return new WP_Error('wsm_backup_table_invalid','یکی از بخش‌های جدول در بکاپ معتبر نیست.',array('status'=>400));}
            $required=array('name','columns','primary_key','rows');$keys=array_keys($table);sort($keys);sort($required);
            if($keys!==$required||!is_string($table['name'])||strlen($table['name'])>64||
                !preg_match('/^wsm_[A-Za-z0-9_]+$/D',$table['name'])){
                return new WP_Error('wsm_backup_table_invalid','نام یا ساختار یکی از جدول‌های بکاپ معتبر نیست.',array('status'=>400));
            }
            $table_key=strtolower($table['name']);
            if(isset($seen_tables[$table_key])){return new WP_Error('wsm_backup_table_duplicate','نام جدول در فایل بکاپ تکراری است.',array('status'=>400));}
            $seen_tables[$table_key]=true;
            $columns=$table['columns'];
            if(!self::backup_array_is_list($columns)||count($columns)<1||count($columns)>512){return new WP_Error('wsm_backup_columns_invalid','فهرست ستون‌های جدول '.$table['name'].' معتبر نیست.',array('status'=>400));}
            $column_set=array();
            foreach($columns as $column){
                if(!is_string($column)||strlen($column)<1||strlen($column)>64||!preg_match('/^[A-Za-z0-9_]+$/D',$column)||isset($column_set[strtolower($column)])){
                    return new WP_Error('wsm_backup_columns_invalid','نام ستون در جدول '.$table['name'].' معتبر یا یکتا نیست.',array('status'=>400));
                }
                $column_set[strtolower($column)]=$column;
            }
            $primary=$table['primary_key'];
            if(!self::backup_array_is_list($primary)||count($primary)>16){return new WP_Error('wsm_backup_primary_key_invalid','کلید اصلی جدول '.$table['name'].' معتبر نیست.',array('status'=>400));}
            $seen_primary=array();
            foreach($primary as $key){
                if(!is_string($key)||!isset($column_set[strtolower($key)])||isset($seen_primary[strtolower($key)])){
                    return new WP_Error('wsm_backup_primary_key_invalid','کلید اصلی جدول '.$table['name'].' با ستون‌های آن سازگار نیست.',array('status'=>400));
                }
                $seen_primary[strtolower($key)]=true;
            }
            $rows=$table['rows'];
            if(!self::backup_array_is_list($rows)){return new WP_Error('wsm_backup_rows_invalid','ردیف‌های جدول '.$table['name'].' ساختار فهرستی ندارند.',array('status'=>400));}
            foreach($rows as $row){
                if(!is_array($row)||count($row)!==count($columns)){return new WP_Error('wsm_backup_row_invalid','یکی از ردیف‌های جدول '.$table['name'].' با ساختار ستون‌ها سازگار نیست.',array('status'=>400));}
                foreach($row as $field=>$value){
                    if(!is_string($field)||!isset($column_set[strtolower($field)])||$column_set[strtolower($field)]!==$field||
                        (!is_string($value)&&!is_int($value)&&!is_float($value)&&!is_bool($value)&&$value!==null)){
                        return new WP_Error('wsm_backup_row_invalid','یکی از مقادیر ردیف جدول '.$table['name'].' معتبر نیست.',array('status'=>400));
                    }
                    if(is_float($value)&&!is_finite($value)){return new WP_Error('wsm_backup_row_invalid','یکی از اعداد ردیف جدول '.$table['name'].' معتبر نیست.',array('status'=>400));}
                }
                foreach($primary as $key){if(!array_key_exists($key,$row)||$row[$key]===null){return new WP_Error('wsm_backup_row_key_missing','مقدار کلید اصلی در جدول '.$table['name'].' خالی است.',array('status'=>400));}}
            }
            $total_records+=count($rows);
        }
        if($meta['record_count']!==$total_records){return new WP_Error('wsm_backup_record_count_mismatch','شمار ردیف‌های بکاپ با فراداده مطابقت ندارد.',array('status'=>400));}

        $settings=$document['plugin_settings'];
        $settings_keys=array('options','backup_configuration');$actual_settings=array_keys($settings);sort($actual_settings);sort($settings_keys);
        if($actual_settings!==$settings_keys||!is_array($settings['options'])||!is_array($settings['backup_configuration'])){
            return new WP_Error('wsm_backup_settings_invalid','ساختار تنظیمات افزونه در بکاپ معتبر نیست.',array('status'=>400));
        }
        $allowed_options=array_merge(self::backup_club_option_names(),array('wsm_settings','wsm_pos_quick_access'));
        foreach($settings['options'] as $option=>$value){
            if(!is_string($option)||!in_array($option,$allowed_options,true)||!is_array($value)){
                return new WP_Error('wsm_backup_option_invalid','یکی از گزینه‌های ذخیره‌شده در بکاپ پشتیبانی نمی‌شود.',array('status'=>400));
            }
            $valid=self::validate_backup_json_value($value);
            if(is_wp_error($valid)){return $valid;}
        }
        $configuration=$settings['backup_configuration'];
        $required_config=array('auto_enabled','schedule','storage_mode','storage_path','retention');
        foreach($required_config as $required_key){if(!array_key_exists($required_key,$configuration)){return new WP_Error('wsm_backup_configuration_invalid','تنظیمات اصلی بکاپ در فایل کامل نیست.',array('status'=>400));}}
        $allowed_config=array('auto_enabled','schedule','storage_mode','storage_path','external_storage_path','retention','email_notifications','email_recipient','club_message_site_binding');
        foreach($configuration as $key=>$value){if(!is_string($key)||!in_array($key,$allowed_config,true)){return new WP_Error('wsm_backup_configuration_invalid','تنظیمات بکاپِ داخل فایل دارای فیلد پشتیبانی‌نشده است.',array('status'=>400));}}
        if(isset($configuration['club_message_site_binding'])&&(!is_string($configuration['club_message_site_binding'])||!preg_match('/^[a-f0-9]{64}$/D',$configuration['club_message_site_binding']))){return new WP_Error('wsm_backup_message_binding_invalid','نشانۀ وابستگی پیام‌ها به نصب معتبر نیست.',array('status'=>400));}
        if(isset($configuration['auto_enabled'])&&!self::backup_valid_boolean($configuration['auto_enabled'])){return new WP_Error('wsm_backup_configuration_invalid','وضعیت بکاپ خودکار در فایل معتبر نیست.',array('status'=>400));}
        if(isset($configuration['email_notifications'])&&!self::backup_valid_boolean($configuration['email_notifications'])){return new WP_Error('wsm_backup_configuration_invalid','وضعیت اعلان ایمیلی در فایل معتبر نیست.',array('status'=>400));}
        if(isset($configuration['schedule'])&&!in_array($configuration['schedule'],array('daily','weekly','monthly'),true)){return new WP_Error('wsm_backup_configuration_invalid','زمان‌بندی داخل فایل بکاپ معتبر نیست.',array('status'=>400));}
        if(isset($configuration['storage_mode'])&&!in_array($configuration['storage_mode'],array('default','custom'),true)){return new WP_Error('wsm_backup_configuration_invalid','نوع مسیر داخل فایل بکاپ معتبر نیست.',array('status'=>400));}
        if(isset($configuration['retention'])&&(!(is_int($configuration['retention'])||is_string($configuration['retention'])&&preg_match('/^\d+$/',$configuration['retention']))||(int)$configuration['retention']<1||(int)$configuration['retention']>100)){
            return new WP_Error('wsm_backup_configuration_invalid','تعداد بکاپ‌های نگهداری‌شده در فایل معتبر نیست.',array('status'=>400));
        }
        if(isset($configuration['storage_path'])){
            $path=$configuration['storage_path'];
            if(!is_string($path)||strlen($path)>4096||strpos($path,"\0")!==false||strpos($path,'://')!==false||
                ($path!==''&&(!preg_match('#^(?:/|[A-Za-z]:/)#',wp_normalize_path($path))||preg_match('#(^|/)\.\.?(/|$)#',wp_normalize_path($path))))){
                return new WP_Error('wsm_backup_configuration_invalid','مسیر ذخیرهٔ موجود در فایل بکاپ معتبر نیست.',array('status'=>400));
            }
            if(isset($configuration['storage_mode'])&&$configuration['storage_mode']==='custom'&&$path===''){
                return new WP_Error('wsm_backup_configuration_invalid','مسیر سفارشی داخل فایل بکاپ خالی است.',array('status'=>400));
            }
        }
        if(array_key_exists('external_storage_path',$configuration)){
            $external_path=$configuration['external_storage_path'];
            $normalized_external=is_string($external_path)?wp_normalize_path($external_path):'';
            if(!is_string($external_path)||strlen($external_path)>4096||strpos($external_path,"\0")!==false||strpos($external_path,'://')!==false||
                ($external_path!==''&&(!preg_match('#^(?:/|[A-Za-z]:/)#',$normalized_external)||preg_match('#(^|/)\.\.?(/|$)#',$normalized_external)))){
                return new WP_Error('wsm_backup_configuration_invalid','مسیر خارجی موجود در فایل بکاپ معتبر نیست.',array('status'=>400));
            }
        }
        if(isset($configuration['email_recipient'])&&(!is_string($configuration['email_recipient'])||($configuration['email_recipient']!==''&&!is_email($configuration['email_recipient'])))){
            return new WP_Error('wsm_backup_configuration_invalid','ایمیل اعلان داخل فایل بکاپ معتبر نیست.',array('status'=>400));
        }
        $valid=self::validate_backup_json_value($configuration);
        if(is_wp_error($valid)){return $valid;}
        return true;
    }

    private static function backup_valid_boolean($value) {
        return is_bool($value)||(is_int($value)&&($value===0||$value===1))||(is_string($value)&&in_array(strtolower($value),array('0','1','true','false','yes','no','on','off'),true));
    }

    /** Validate shape, format version, integrity and row/settings structures before any restore work. */
    public static function validate_backup_document($document) {
        if(!is_array($document)||!isset($document['backup_metadata'])||!is_array($document['backup_metadata'])||
            !isset($document['database_tables'])||!is_array($document['database_tables'])||
            !isset($document['plugin_settings'])||!is_array($document['plugin_settings'])||
            !isset($document['checksum'])||!is_string($document['checksum'])||
            !preg_match('/^[a-f0-9]{64}$/i',$document['checksum'])){
            return new WP_Error('wsm_backup_invalid','فایل بکاپ معتبر نیست.',array('status'=>400));
        }
        if(!isset($document['backup_metadata']['format_version'])||!is_int($document['backup_metadata']['format_version'])||$document['backup_metadata']['format_version']!==self::backup_format_version()){
            return new WP_Error('wsm_backup_version_unsupported','نسخهٔ قالب بکاپ پشتیبانی نمی‌شود.',array('status'=>400));
        }
        $expected=strtolower($document['checksum']);
        $canonical=self::backup_canonical_json($document);
        if(!is_string($canonical)||!hash_equals($expected,hash('sha256',$canonical))){
            return new WP_Error('wsm_backup_checksum_mismatch','فایل بکاپ معتبر نیست؛ جمع کنترلی مطابقت ندارد.',array('status'=>400));
        }
        return self::validate_backup_document_payload($document);
    }

    /** List safe restore sections without exposing option values. */
    public static function describe_backup_sections($document) {
        $valid=self::validate_backup_document($document);
        if(is_wp_error($valid)){return $valid;}
        $tables=array();
        foreach($document['database_tables'] as $table){
            if(!self::backup_club_table_name($table['name'])){continue;}
            $omitted_columns=array();
            foreach($table['columns'] as $column){if(self::backup_sensitive_table_column($table['name'],$column)){$omitted_columns[]=$column;}}
            $tables[]=array('name'=>$table['name'],'record_count'=>count($table['rows']),'column_count'=>count($table['columns']),'primary_key'=>$table['primary_key'],'credential_columns_omitted'=>$omitted_columns);
        }
        $options=array();$allowed_options=self::backup_club_option_names();
        foreach(array_keys($document['plugin_settings']['options']) as $name){if(in_array($name,$allowed_options,true)){$options[]=array('name'=>$name);}}
        return array(
            'metadata'=>$document['backup_metadata'],
            'checksum'=>$document['checksum'],
            'database_tables'=>$tables,
            'plugin_options'=>$options,
            'message_site_compatible'=>self::backup_message_site_compatible($document),
            'backup_configuration_available'=>false,
        );
    }

    /** Return only explicitly selected tables/options for a later full-table replacement preview. */
    public static function select_backup_document_sections($document,$selection) {
        $valid=self::validate_backup_document($document);
        if(is_wp_error($valid)){return $valid;}
        if(!is_array($selection)){return new WP_Error('wsm_backup_selection_invalid','انتخاب بخش‌های بازیابی معتبر نیست.',array('status'=>400));}
        $selection_keys=array_keys($selection);$allowed_selection_keys=array('database_tables','plugin_options','plugin_paths','include_backup_configuration');
        foreach($selection_keys as $key){if(!in_array($key,$allowed_selection_keys,true)){return new WP_Error('wsm_backup_selection_invalid','انتخاب شامل بخش پشتیبانی‌نشده است.',array('status'=>400));}}
        $table_names=array_key_exists('database_tables',$selection)?$selection['database_tables']:array();
        $option_names=array_key_exists('plugin_options',$selection)?$selection['plugin_options']:array();
        $plugin_paths=array_key_exists('plugin_paths',$selection)?$selection['plugin_paths']:array();
        if(!self::backup_array_is_list($table_names)||!self::backup_array_is_list($option_names)||!self::backup_array_is_list($plugin_paths)){
            return new WP_Error('wsm_backup_selection_invalid','فهرست جدول‌ها یا تنظیمات انتخاب‌شده معتبر نیست.',array('status'=>400));
        }
        if($plugin_paths){return new WP_Error('wsm_restore_plugin_files_disabled','بازیابی فایل‌های کد افزونه از بکاپ داده پشتیبانی نمی‌شود.',array('status'=>400));}
        $include_configuration=false;
        if(array_key_exists('include_backup_configuration',$selection)){
            if(!self::backup_valid_boolean($selection['include_backup_configuration'])){return new WP_Error('wsm_backup_selection_invalid','انتخاب تنظیمات بکاپ معتبر نیست.',array('status'=>400));}
            $include_configuration=rest_sanitize_boolean($selection['include_backup_configuration']);
        }
        if($include_configuration){return new WP_Error('wsm_restore_backup_configuration_disabled','مسیر، زمان‌بندی و اعلان بکاپ از فایل بازیابی اعمال نمی‌شوند؛ تنظیمات نصب مقصد حفظ می‌شود.',array('status'=>400));}
        $selected_paths=array();
        foreach($plugin_paths as $path_group){
            if(!is_string($path_group)||strlen($path_group)>64||($path_group!=='__root__'&&!preg_match('/^[A-Za-z0-9._-]+$/D',$path_group))||isset($selected_paths[$path_group])){return new WP_Error('wsm_backup_selection_path_invalid','یکی از پوشه‌های انتخاب‌شده برای بازیابی معتبر یا یکتا نیست.',array('status'=>400));}
            $selected_paths[$path_group]=true;
        }
        $table_map=array();foreach($document['database_tables'] as $table){$table_map[$table['name']]=$table;}
        $option_map=$document['plugin_settings']['options'];
        $selected_tables=array();$seen=array();
        foreach($table_names as $name){
            if(!is_string($name)||!isset($table_map[$name])||isset($seen[$name])){return new WP_Error('wsm_backup_selection_table_invalid','یکی از جدول‌های انتخاب‌شده در فایل بکاپ وجود ندارد یا تکراری است.',array('status'=>400));}
            if($name==='wsm_backup_logs'){return new WP_Error('wsm_backup_restore_logs_protected','تاریخچهٔ داخلی بکاپ هنگام بازیابی دست‌نخورده می‌ماند تا گزارش‌ها و نسخهٔ پشتیبان محافظتی از دسترس خارج نشوند.',array('status'=>400));}
            if(!self::backup_club_table_name($name)){return new WP_Error('wsm_restore_not_club_table','این جدول دادهٔ عملیاتی باشگاه نیست و بازیابی نمی‌شود.',array('status'=>400));}
            if(self::backup_message_table_name($name)&&!self::backup_message_site_compatible($document)){
                return new WP_Error('wsm_restore_messages_other_site','پیام‌های ورزشکار/کارکنان فقط روی همان نصب با همان نشانی و کلیدهای وردپرس بازیابی می‌شوند؛ در نصب دیگر یا از بکاپ قدیمی، جدول پیام را انتخاب نکنید.',array('status'=>409));
            }
            $seen[$name]=true;
            $safe_table=self::backup_table_without_sensitive_columns($table_map[$name]);
            if(is_wp_error($safe_table)){return $safe_table;}
            $selected_tables[]=$safe_table;
        }
        $selected_options=array();$seen=array();$allowed_club_options=self::backup_club_option_names();
        foreach($option_names as $name){
            if(!is_string($name)||!array_key_exists($name,$option_map)||isset($seen[$name])){return new WP_Error('wsm_backup_selection_option_invalid','یکی از تنظیمات انتخاب‌شده در فایل بکاپ وجود ندارد یا تکراری است.',array('status'=>400));}
            if(!in_array($name,$allowed_club_options,true)){return new WP_Error('wsm_restore_option_not_club','این گزینه جزو شش تنظیم عملیاتی باشگاه نیست و بازیابی نمی‌شود.',array('status'=>400));}
            $seen[$name]=true;$selected_options[$name]=$option_map[$name];
        }
        if(!$selected_tables&&!$selected_options&&!$include_configuration&&!$selected_paths){return new WP_Error('wsm_backup_selection_empty','حداقل یک جدول، پوشه یا تنظیمات باید برای بازیابی انتخاب شود.',array('status'=>400));}
        $selected_settings=array('options'=>$selected_options);
        if($include_configuration){$selected_settings['backup_configuration']=$document['plugin_settings']['backup_configuration'];}
        return array(
            'backup_metadata'=>$document['backup_metadata'],
            'database_tables'=>$selected_tables,
            'plugin_settings'=>$selected_settings,
            'selection'=>array('database_tables'=>array_values($table_names),'plugin_options'=>array_values($option_names),'plugin_paths'=>array_keys($selected_paths),'include_backup_configuration'=>$include_configuration),
        );
    }

    private static function backup_memory_limit_bytes() {
        $raw=trim((string)ini_get('memory_limit'));
        if($raw===''||$raw==='-1'){return -1;}
        $unit=strtolower(substr($raw,-1));$number=(float)$raw;
        if($unit==='g'){$number*=1024*1024*1024;}
        elseif($unit==='m'){$number*=1024*1024;}
        elseif($unit==='k'){$number*=1024;}
        return max(0,(int)$number);
    }

    /** Conservative guard against JSON expansion exhausting the PHP worker during preview/import. */
    private static function backup_decode_memory_available($bytes,$input_loaded=false) {
        $limit=self::backup_memory_limit_bytes();
        if($limit<0){return true;}
        $used=function_exists('memory_get_usage')?memory_get_usage(false):0;
        $factor=$input_loaded?5:6;
        return ($used+((int)$bytes*$factor)+16777216)<=$limit;
    }

    /** Decode and validate a backup document from bytes. */
    public static function decode_backup_document($contents) {
        if (!is_string($contents) || $contents === '' || strlen($contents) > 268435456) {
            return new WP_Error('wsm_backup_size_invalid', 'حجم یا محتوای فایل بکاپ معتبر نیست.', array('status' => 400));
        }
        if(!self::backup_decode_memory_available(strlen($contents),true)){
            return new WP_Error('wsm_backup_memory_limit','حجم فایل با حافظهٔ آزاد PHP سازگار نیست؛ فایل کوچک‌تر یا حافظهٔ بیشتر لازم است.',array('status'=>413));
        }
        $document = json_decode($contents, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($document)) {
            return new WP_Error('wsm_backup_json_invalid', 'فایل بکاپ معتبر نیست؛ ساختار JSON قابل‌خواندن نیست.', array('status' => 400));
        }
        $valid = self::validate_backup_document($document);
        if (is_wp_error($valid)) { return $valid; }
        return $document;
    }


    /** Hash one ZIP member incrementally; no archive member is extracted to disk or loaded whole. */
    private static function backup_zip_entry_sha256($zip,$name,$expected_size,$maximum_size) {
        if(!is_object($zip)||!is_string($name)||$name===''||!is_int($expected_size)||$expected_size<0||$expected_size>$maximum_size){return new WP_Error('wsm_backup_zip_entry_invalid','یکی از فایل‌های داخل بسته برای بررسی معتبر نیست.',array('status'=>400));}
        $stream=$zip->getStream($name);
        if(!$stream){return new WP_Error('wsm_backup_zip_entry_read_failed','خواندن یکی از فایل‌های داخل بسته ممکن نشد.',array('status'=>400));}
        $context=hash_init('sha256');$total=0;$failed=false;
        while(!feof($stream)){
            $chunk=fread($stream,1048576);
            if($chunk===false){$failed=true;break;}
            if($chunk===''){if(feof($stream)){break;}continue;}
            $total+=strlen($chunk);
            if($total>$maximum_size||$total>$expected_size){$failed=true;break;}
            hash_update($context,$chunk);
        }
        fclose($stream);
        if($failed||$total!==$expected_size){return new WP_Error('wsm_backup_zip_entry_size_mismatch','حجم فایل داخل بسته با فهرست آن مطابقت ندارد.',array('status'=>400));}
        return hash_final($context);
    }

    private static function backup_zip_read_text_entry($zip,$name,$maximum_size) {
        if(!is_object($zip)||!method_exists($zip,'statName')){return new WP_Error('wsm_backup_zip_unavailable','بررسی فایل ZIP در این نسخهٔ PHP ممکن نیست.',array('status'=>500));}
        $stat=$zip->statName($name);
        if(!is_array($stat)||!isset($stat['size'])||!is_numeric($stat['size'])||(int)$stat['size']<1||(int)$stat['size']>$maximum_size){return new WP_Error('wsm_backup_zip_entry_size_invalid','اندازهٔ یکی از فراداده‌های بسته معتبر نیست.',array('status'=>400));}
        $contents=$zip->getFromName($name);
        if(!is_string($contents)||strlen($contents)!==(int)$stat['size']){return new WP_Error('wsm_backup_zip_entry_read_failed','خواندن کامل فرادادهٔ بسته ممکن نشد.',array('status'=>400));}
        return $contents;
    }

    /** Validate manifest paths and counts before any restore preview or filesystem access. */
    private static function validate_backup_archive_manifest($manifest) {
        if(!is_array($manifest)){return new WP_Error('wsm_backup_manifest_invalid','فهرست داخلی بستهٔ بکاپ معتبر نیست.',array('status'=>400));}
        $keys=array_keys($manifest);sort($keys);$expected=array('archive_format','archive_version','created_at','plugin_version','database_version','database_entry','database_sha256','file_count','files','selection','checksum_algorithm','manifest_sha256');sort($expected);
        if($keys!==$expected||$manifest['archive_format']!=='wsmbackup'||!is_int($manifest['archive_version'])||$manifest['archive_version']!==2||
            !self::backup_valid_timestamp($manifest['created_at'])||!is_string($manifest['plugin_version'])||strlen($manifest['plugin_version'])>64||
            !is_string($manifest['database_version'])||strlen($manifest['database_version'])>128||$manifest['database_entry']!=='database.json'||
            !is_string($manifest['database_sha256'])||!preg_match('/^[a-f0-9]{64}$/i',$manifest['database_sha256'])||!is_string($manifest['manifest_sha256'])||!preg_match('/^[a-f0-9]{64}$/i',$manifest['manifest_sha256'])||
            !is_int($manifest['file_count'])||$manifest['file_count']<0||$manifest['file_count']>20000||
            $manifest['checksum_algorithm']!=='sha256'||!is_array($manifest['selection'])){
            return new WP_Error('wsm_backup_manifest_invalid','ساختار یا نسخهٔ فهرست داخلی بسته پشتیبانی نمی‌شود.',array('status'=>400));
        }
        $manifest_for_hash=$manifest;unset($manifest_for_hash['manifest_sha256']);
        $canonical_manifest=wp_json_encode($manifest_for_hash,self::backup_json_flags());
        if(!is_string($canonical_manifest)||!hash_equals(strtolower($manifest['manifest_sha256']),hash('sha256',$canonical_manifest))){return new WP_Error('wsm_backup_manifest_checksum_mismatch','SHA-256 خودِ فهرست داخلی با محتوای آن مطابقت ندارد.',array('status'=>400));}
        $selection_keys=array_keys($manifest['selection']);sort($selection_keys);$expected_selection=array('database_tables','plugin_paths');sort($expected_selection);
        if($selection_keys!==$expected_selection||!self::backup_array_is_list($manifest['selection']['database_tables'])||!self::backup_array_is_list($manifest['selection']['plugin_paths'])||!self::backup_array_is_list($manifest['files'])||count($manifest['files'])!==$manifest['file_count']){
            return new WP_Error('wsm_backup_manifest_selection_invalid','فهرست بخش‌های انتخاب‌شده در بسته معتبر نیست.',array('status'=>400));
        }
        $seen_selection_tables=array();
        foreach($manifest['selection']['database_tables'] as $table){if(!is_string($table)||!preg_match('/^wsm_[A-Za-z0-9_]+$/D',$table)||isset($seen_selection_tables[$table])){return new WP_Error('wsm_backup_manifest_selection_invalid','نام جدول در فهرست بسته معتبر یا یکتا نیست.',array('status'=>400));}$seen_selection_tables[$table]=true;}
        $seen_selection_paths=array();
        foreach($manifest['selection']['plugin_paths'] as $group){if(!is_string($group)||strlen($group)>64||($group!=='__root__'&&!preg_match('/^[A-Za-z0-9._-]+$/D',$group))||isset($seen_selection_paths[$group])){return new WP_Error('wsm_backup_manifest_selection_invalid','نام پوشه در فهرست بسته معتبر یا یکتا نیست.',array('status'=>400));}$seen_selection_paths[$group]=true;}
        $seen=array();$total=0;
        foreach($manifest['files'] as $file){
            if(!is_array($file)){return new WP_Error('wsm_backup_manifest_file_invalid','یکی از ردیف‌های فهرست فایل‌ها معتبر نیست.',array('status'=>400));}
            $file_keys=array_keys($file);sort($file_keys);$expected_file_keys=array('path','size','sha256','mode');sort($expected_file_keys);
            if($file_keys!==$expected_file_keys||!is_string($file['path'])||self::backup_archive_relative_path($file['path'])===false||
                !is_int($file['size'])||$file['size']<0||$file['size']>67108864||!is_string($file['sha256'])||!preg_match('/^[a-f0-9]{64}$/i',$file['sha256'])||
                !is_int($file['mode'])||!in_array($file['mode'],array(0644,0755),true)){
                return new WP_Error('wsm_backup_manifest_file_invalid','مسیر، اندازه یا SHA-256 یکی از فایل‌های بسته معتبر نیست.',array('status'=>400));
            }
            $key=strtolower($file['path']);if(isset($seen[$key])){return new WP_Error('wsm_backup_manifest_file_duplicate','مسیر فایل در فهرست بسته تکراری است.',array('status'=>400));}$seen[$key]=true;
            $total+=(int)$file['size'];if($total>1073741824){return new WP_Error('wsm_backup_manifest_total_size_limit','حجم بازشدهٔ فایل‌های بسته از سقف ایمن بیشتر است.',array('status'=>413));}
        }
        return true;
    }

    /** Read a legacy JSON file or a verified ZIP package. ZIP member names are never passed to extractTo(). */
    private static function read_backup_package($path,$expected_size=0) {
        if(!is_string($path)||$path===''||is_link($path)||!is_file($path)||!is_readable($path)){return new WP_Error('wsm_backup_package_path_invalid','مسیر فایل بکاپ برای خواندن معتبر نیست.',array('status'=>400));}
        $size=@filesize($path);
        if($size===false||$size<1||$size>536870912||($expected_size>0&&(int)$expected_size!==(int)$size)){return new WP_Error('wsm_backup_package_size_invalid','حجم فایل بکاپ معتبر نیست یا با اندازهٔ بارگذاری‌شده مطابقت ندارد.',array('status'=>413));}
        $handle=@fopen($path,'rb');if(!$handle){return new WP_Error('wsm_backup_package_read_failed','خواندن فایل بکاپ انجام نشد.',array('status'=>500));}
        $signature=fread($handle,4);fclose($handle);
        $file_checksum=@hash_file('sha256',$path);
        if(!is_string($file_checksum)||!preg_match('/^[a-f0-9]{64}$/i',$file_checksum)){return new WP_Error('wsm_backup_file_checksum_unavailable','محاسبهٔ SHA-256 فایل بکاپ انجام نشد.',array('status'=>500));}
        if(!is_string($signature)||strpos($signature,'PK')!==0){
            if($size>268435456){return new WP_Error('wsm_backup_legacy_size_invalid','حجم فایل JSON قدیمی از سقف ۲۵۶ مگابایت بیشتر است.',array('status'=>413));}
            if(!self::backup_decode_memory_available((int)$size,false)){return new WP_Error('wsm_backup_memory_limit','حافظهٔ آزاد PHP برای اعتبارسنجی فایل JSON قدیمی کافی نیست.',array('status'=>413));}
            $contents=@file_get_contents($path);
            if(!is_string($contents)||strlen($contents)!==(int)$size){return new WP_Error('wsm_backup_package_read_failed','خواندن کامل فایل JSON بکاپ انجام نشد.',array('status'=>500));}
            $document=self::decode_backup_document($contents);unset($contents);
            if(is_wp_error($document)){return $document;}
            return array('document'=>$document,'archive'=>array('path'=>$path,'legacy'=>true,'manifest'=>null,'manifest_sha256'=>'','file_checksum'=>strtolower($file_checksum),'groups'=>array()));
        }
        if(!class_exists('ZipArchive')){return new WP_Error('wsm_backup_zip_extension_missing','برای خواندن بستهٔ ZIP، افزونهٔ Zip در PHP سرور فعال نیست.',array('status'=>500));}
        $zip=new ZipArchive();$opened=$zip->open($path);
        if($opened!==true){return new WP_Error('wsm_backup_zip_open_failed','بازکردن بستهٔ ZIP ناموفق بود یا ساختار آن آسیب دیده است.',array('status'=>400));}
        try{
            if((int)$zip->numFiles<2||(int)$zip->numFiles>20002){return new WP_Error('wsm_backup_zip_entry_count_invalid','تعداد فایل‌های داخل بسته از محدودهٔ مجاز خارج است.',array('status'=>413));}
            $entry_map=array();$case_map=array();$total_uncompressed=0;
            for($i=0;$i<(int)$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);
                if(!is_array($stat)||!isset($stat['name'],$stat['size'])||!is_string($stat['name'])||!is_numeric($stat['size'])||$stat['size']<0){return new WP_Error('wsm_backup_zip_entry_invalid','یکی از فایل‌های داخل بسته قابل‌بررسی نیست.',array('status'=>400));}
                $name=$stat['name'];
                if($name===''||strpos($name,"\0")!==false||substr($name,-1)==='/'){return new WP_Error('wsm_backup_zip_path_invalid','مسیر پوشه یا فایل در بسته معتبر نیست.',array('status'=>400));}
                if($name!=='manifest.json'&&$name!=='database.json'&&self::backup_archive_relative_path($name)===false){return new WP_Error('wsm_backup_zip_path_invalid','بسته شامل مسیر فایل خارج از پوشهٔ افزونه یا نامعتبر است.',array('status'=>400));}
                $key=strtolower($name);if(isset($case_map[$key])){return new WP_Error('wsm_backup_zip_duplicate_entry','نام فایل در بسته تکراری است.',array('status'=>400));}$case_map[$key]=true;
                $entry_size=(int)$stat['size'];
                if(($name==='manifest.json'&&$entry_size>16777216)||($name==='database.json'&&$entry_size>268435456)||($name!=='manifest.json'&&$name!=='database.json'&&$entry_size>67108864)){return new WP_Error('wsm_backup_zip_entry_size_limit','حجم یکی از فایل‌های داخل بسته بیش از سقف مجاز است.',array('status'=>413));}
                $total_uncompressed+=$entry_size;if($total_uncompressed>1342177280){return new WP_Error('wsm_backup_zip_total_size_limit','حجم بازشدهٔ کل بسته از سقف ایمن بیشتر است.',array('status'=>413));}
                $entry_map[$name]=$stat;
            }
            if(!isset($entry_map['manifest.json'],$entry_map['database.json'])){return new WP_Error('wsm_backup_zip_manifest_missing','بسته شامل فایل‌های ضروری manifest.json یا database.json نیست.',array('status'=>400));}
            $manifest_json=self::backup_zip_read_text_entry($zip,'manifest.json',16777216);if(is_wp_error($manifest_json)){return $manifest_json;}
            $manifest=json_decode($manifest_json,true,64,JSON_BIGINT_AS_STRING);
            if(json_last_error()!==JSON_ERROR_NONE||!is_array($manifest)){return new WP_Error('wsm_backup_manifest_json_invalid','خواندن ساختار فایل manifest.json ناموفق بود.',array('status'=>400));}
            $manifest_valid=self::validate_backup_archive_manifest($manifest);if(is_wp_error($manifest_valid)){return $manifest_valid;}
            $expected_entries=array('manifest.json'=>true,'database.json'=>true);$groups=array();
            foreach($manifest['files'] as $file){
                $entry=$file['path'];$expected_entries[$entry]=true;$relative=self::backup_archive_relative_path($entry);
                $stat=isset($entry_map[$entry])?$entry_map[$entry]:null;
                if(!is_array($stat)||(int)$stat['size']!==(int)$file['size']){return new WP_Error('wsm_backup_manifest_entry_missing','یکی از فایل‌های ثبت‌شده در فهرست داخلی بسته پیدا نشد یا اندازهٔ آن متفاوت است.',array('status'=>400));}
                $hash=self::backup_zip_entry_sha256($zip,$entry,(int)$file['size'],67108864);
                if(is_wp_error($hash)||!hash_equals(strtolower($file['sha256']),strtolower((string)$hash))){return new WP_Error('wsm_backup_file_checksum_mismatch','SHA-256 یکی از فایل‌های افزونه با فهرست داخلی مطابقت ندارد.',array('status'=>400));}
                $group=self::backup_plugin_file_group($relative);$groups[$group]=array('id'=>$group,'file_count'=>isset($groups[$group])?$groups[$group]['file_count']+1:1,'size_bytes'=>(isset($groups[$group])?$groups[$group]['size_bytes']:0)+(int)$file['size']);
            }
            if(count($expected_entries)!==count($entry_map)){return new WP_Error('wsm_backup_zip_unlisted_entry','بسته شامل فایل‌هایی است که در فهرست داخلی ثبت نشده‌اند.',array('status'=>400));}
            foreach($entry_map as $name=>$stat){if(!isset($expected_entries[$name])){return new WP_Error('wsm_backup_zip_unlisted_entry','بسته شامل فایل‌هایی است که در فهرست داخلی ثبت نشده‌اند.',array('status'=>400));}}
            if(!self::backup_decode_memory_available((int)$entry_map['database.json']['size'],false)){return new WP_Error('wsm_backup_memory_limit','حافظهٔ آزاد PHP برای خواندن دادهٔ پایگاه داده از ZIP کافی نیست.',array('status'=>413));}
            $database_json=self::backup_zip_read_text_entry($zip,'database.json',268435456);if(is_wp_error($database_json)){return $database_json;}
            if(!hash_equals(strtolower($manifest['database_sha256']),hash('sha256',$database_json))){return new WP_Error('wsm_backup_database_checksum_mismatch','SHA-256 داده‌های پایگاه داده با manifest مطابقت ندارد.',array('status'=>400));}
            $document=self::decode_backup_document($database_json);unset($database_json);
            if(is_wp_error($document)){return $document;}
            if((string)$document['backup_metadata']['plugin_version']!==(string)$manifest['plugin_version']){return new WP_Error('wsm_backup_manifest_version_mismatch','نسخهٔ افزونه در فهرست داخلی با نسخهٔ سند پایگاه داده متفاوت است.',array('status'=>400));}
            if((int)$document['backup_metadata']['table_count']!==count($manifest['selection']['database_tables'])){return new WP_Error('wsm_backup_manifest_table_count_mismatch','تعداد جدول‌های ثبت‌شده در فهرست داخلی با داده‌های پایگاه داده مطابقت ندارد.',array('status'=>400));}
            $actual_tables=array();foreach($document['database_tables'] as $table){$actual_tables[]=$table['name'];}
            $expected_tables=$manifest['selection']['database_tables'];sort($actual_tables,SORT_STRING);sort($expected_tables,SORT_STRING);
            if($actual_tables!==$expected_tables){return new WP_Error('wsm_backup_manifest_table_list_mismatch','فهرست جدول‌های manifest با محتوای پایگاه داده مطابقت ندارد.',array('status'=>400));}
            ksort($groups,SORT_STRING);
            $manifest_groups=$manifest['selection']['plugin_paths'];$actual_groups=array_keys($groups);sort($manifest_groups,SORT_STRING);sort($actual_groups,SORT_STRING);
            if($manifest_groups!==$actual_groups){return new WP_Error('wsm_backup_manifest_path_list_mismatch','فهرست پوشه‌های ثبت‌شده با فایل‌های بسته مطابقت ندارد.',array('status'=>400));}
            return array('document'=>$document,'archive'=>array('path'=>$path,'legacy'=>false,'manifest'=>$manifest,'manifest_sha256'=>hash('sha256',$manifest_json),'file_checksum'=>strtolower($file_checksum),'groups'=>array_values($groups)));
        }finally{$zip->close();}
    }

    private static function backup_package_compatibility($package) {
        $archive=is_array($package)&&isset($package['archive'])&&is_array($package['archive'])?$package['archive']:array();
        $document=is_array($package)&&isset($package['document'])&&is_array($package['document'])?$package['document']:array();
        $legacy=!empty($archive['legacy']);$manifest=isset($archive['manifest'])&&is_array($archive['manifest'])?$archive['manifest']:array();
        $backup_version=isset($manifest['plugin_version'])?(string)$manifest['plugin_version']:(isset($document['backup_metadata']['plugin_version'])?(string)$document['backup_metadata']['plugin_version']:'unknown');
        $current=defined('WSM_VERSION')?(string)WSM_VERSION:'unknown';
        $is_unknown=$backup_version===''||$current===''||$backup_version==='unknown'||$current==='unknown';
        $is_different=!$is_unknown&&$backup_version!==$current;
        $is_older=!$is_unknown&&version_compare($backup_version,$current,'<');
        $requires=$legacy||$is_older||$is_different||$is_unknown;
        $warning='';
        if($legacy){$warning='هشدار نسخهٔ قدیمی: این فایل از قالب JSON قدیمی است و فقط داده‌ها و تنظیمات انتخاب‌شده را بازیابی می‌کند؛ فایل‌های افزونه در آن وجود ندارد. پیش از ادامه، نسخهٔ پشتیبان جداگانهٔ فعلی را بررسی کنید.';}
        elseif($is_older){$warning='هشدار ناسازگاری نسخه: این بکاپ با نسخهٔ قدیمی‌تر افزونه ساخته شده است ('.$backup_version.' در برابر '.$current.'). ممکن است ساختار داده یا فایل‌ها متفاوت باشد؛ بازیابی فقط پس از بررسی پیش‌نمایش و تأیید جداگانه انجام می‌شود.';}
        elseif($is_different){$warning='هشدار اختلاف نسخه: نسخهٔ افزونه در بکاپ با نسخهٔ نصب‌شده برابر نیست ('.$backup_version.' در برابر '.$current.'). پیش از ادامه، پیش‌نمایش را بررسی و اختلاف نسخه را جداگانه تأیید کنید.';}
        elseif($is_unknown){$warning='هشدار نسخه: نسخهٔ بکاپ یا افزونهٔ نصب‌شده مشخص نیست؛ سازگاری کامل تضمین نمی‌شود. پیش از ادامه، پیش‌نمایش را بررسی و جداگانه تأیید کنید.';}
        return array('legacy'=>$legacy,'backup_plugin_version'=>$backup_version,'current_plugin_version'=>$current,'database_version'=>isset($manifest['database_version'])?(string)$manifest['database_version']:'نامشخص','archive_version'=>isset($manifest['archive_version'])?(int)$manifest['archive_version']:0,'requires_confirmation'=>$requires,'warning'=>$warning);
    }

    private static function validate_backup_archive_selection($archive,$selection) {
        if(!is_array($selection)){return new WP_Error('wsm_restore_selection_invalid','انتخاب بخش‌های بازیابی معتبر نیست.',array('status'=>400));}
        if(array_key_exists('plugin_paths',$selection)&&!is_array($selection['plugin_paths'])){return new WP_Error('wsm_restore_plugin_files_disabled','فهرست مسیرهای کد برای بازیابی معتبر نیست.',array('status'=>400));}
        $requested=isset($selection['plugin_paths'])?$selection['plugin_paths']:array();
        if(!$requested){return true;}
        return new WP_Error('wsm_restore_plugin_files_disabled','بازیابی فایل‌های کد افزونه از هر نوع بکاپ داده پشتیبانی نمی‌شود.',array('status'=>400));
    }


    /** Build a ZIP with database.json, a SHA-256 manifest, and plugin files at canonical paths. */
    /** Resolve a manifest-relative plugin file without following symlinks or leaving WSM_PATH. */
    private static function backup_plugin_target_path($relative_path,$create_directories=false) {
        $archive_path=self::backup_archive_plugin_prefix().(string)$relative_path;
        $relative=self::backup_archive_relative_path($archive_path);
        if($relative===false){return new WP_Error('wsm_restore_file_path_invalid','مسیر فایل برای بازیابی معتبر نیست.',array('status'=>400));}
        $root=self::backup_plugin_root_path();if(is_wp_error($root)){return $root;}
        $parts=explode('/',$relative);$leaf=array_pop($parts);$current=$root;
        foreach($parts as $part){
            $next=trailingslashit($current).$part;
            if(is_link($next)){return new WP_Error('wsm_restore_file_symlink','مسیر مقصد شامل پیوند نمادین است؛ نوشتن برای ایمنی متوقف شد.',array('status'=>409));}
            if(file_exists($next)){
                if(!is_dir($next)){return new WP_Error('wsm_restore_file_parent_invalid','یکی از مسیرهای والد فایل مقصد پوشه نیست.',array('status'=>409));}
                $real=realpath($next);
                if($real===false||!self::backup_path_is_within_root(wp_normalize_path($real),$root)){return new WP_Error('wsm_restore_file_path_escape','مسیر مقصد از پوشهٔ افزونه خارج می‌شود.',array('status'=>400));}
                $current=untrailingslashit(wp_normalize_path($real));
            }elseif($create_directories){
                if(!@mkdir($next,0755)&&!is_dir($next)){return new WP_Error('wsm_restore_file_directory_create_failed','ساخت یکی از پوشه‌های مقصد فایل ناموفق بود.',array('status'=>500));}
                if(is_link($next)){return new WP_Error('wsm_restore_file_symlink','پوشهٔ مقصد هم‌زمان به پیوند نمادین تبدیل شد؛ نوشتن متوقف شد.',array('status'=>409));}
                $real=realpath($next);
                if($real===false||!self::backup_path_is_within_root(wp_normalize_path($real),$root)){return new WP_Error('wsm_restore_file_path_escape','مسیر مقصد پس از ساخت از پوشهٔ افزونه خارج شد.',array('status'=>400));}
                $current=untrailingslashit(wp_normalize_path($real));
            }else{$current=$next;}
        }
        $target=trailingslashit($current).$leaf;
        if(!self::backup_path_is_within_root(wp_normalize_path($target),$root)){return new WP_Error('wsm_restore_file_path_escape','مسیر فایل مقصد از پوشهٔ افزونه خارج می‌شود.',array('status'=>400));}
        if(is_link($target)){return new WP_Error('wsm_restore_file_symlink','فایل مقصد یک پیوند نمادین است؛ جایگزینی آن مجاز نیست.',array('status'=>409));}
        if(file_exists($target)&&!is_file($target)){return new WP_Error('wsm_restore_file_target_invalid','مسیر مقصد فایل موجود، فایل عادی نیست.',array('status'=>409));}
        return $target;
    }

    private static function backup_archive_files_for_groups($archive,$groups) {
        $valid=self::validate_backup_archive_selection($archive,array('plugin_paths'=>$groups));if(is_wp_error($valid)){return $valid;}
        if(!$groups){return array();}
        $manifest=isset($archive['manifest'])&&is_array($archive['manifest'])?$archive['manifest']:array();
        if(!isset($manifest['files'])||!is_array($manifest['files'])){return new WP_Error('wsm_backup_manifest_files_missing','فهرست فایل‌های manifest برای بازیابی در دسترس نیست.',array('status'=>400));}
        $group_map=array_fill_keys($groups,true);$files=array();
        foreach($manifest['files'] as $file){
            $relative=self::backup_archive_relative_path($file['path']);
            if($relative===false){return new WP_Error('wsm_backup_manifest_file_invalid','مسیر یکی از فایل‌های manifest معتبر نیست.',array('status'=>400));}
            if(isset($group_map[self::backup_plugin_file_group($relative)])){$files[]=$file;}
        }
        return $files;
    }

    /** Read-only file overwrite plan; the fingerprint binds current destination hashes to the confirmation. */
    private static function preview_backup_plugin_files($archive,$groups) {
        $files=self::backup_archive_files_for_groups($archive,$groups);if(is_wp_error($files)){return $files;}
        $context=hash_init('sha256');$summary=array('file_count'=>count($files),'new'=>0,'overwrite'=>0,'unchanged'=>0,'conflicts'=>0,'total_bytes'=>0);$items=array();
        foreach($files as $file){
            $relative=self::backup_archive_relative_path($file['path']);
            if($relative===false){return new WP_Error('wsm_restore_file_path_invalid','مسیر فایل در پیش‌نمایش بازیابی معتبر نیست.',array('status'=>400));}
            $target=self::backup_plugin_target_path($relative,false);if(is_wp_error($target)){return $target;}
            $exists=is_file($target);$current_size=0;$current_hash='';
            if($exists){
                $size=@filesize($target);$hash=@hash_file('sha256',$target);
                if($size===false||!is_string($hash)){return new WP_Error('wsm_restore_file_read_failed','خواندن فایل فعلی برای پیش‌نمایش بازیابی ناموفق بود.',array('status'=>500));}
                $current_size=(int)$size;$current_hash=strtolower($hash);
            }
            $status=!$exists?'new':(hash_equals(strtolower($file['sha256']),$current_hash)?'unchanged':'overwrite');
            $summary[$status]++;$summary['total_bytes']+=(int)$file['size'];
            $items[]=array('path'=>$file['path'],'status'=>$status,'size'=>(int)$file['size']);
            if(!self::backup_restore_plan_hash_add($context,array('path'=>$file['path'],'source_sha256'=>strtolower($file['sha256']),'source_size'=>(int)$file['size'],'target_exists'=>$exists,'target_size'=>$current_size,'target_sha256'=>$current_hash))){return new WP_Error('wsm_restore_plan_fingerprint_failed','ساخت اثرانگشت فایل‌های مقصد ناموفق بود.',array('status'=>500));}
        }
        return array('files'=>$items,'summary'=>$summary,'plan_fingerprint'=>hash_final($context));
    }

    /** Combine the database replacement plan and file-overwrite plan into one confirmation fingerprint. */
    private static function preview_backup_restore_package($document,$selection,$archive) {
        $selected=self::select_backup_document_sections($document,$selection);if(is_wp_error($selected)){return $selected;}
        $archive_selection=self::validate_backup_archive_selection($archive,$selected['selection']);if(is_wp_error($archive_selection)){return $archive_selection;}
        $preview=self::preview_backup_restore($document,$selected['selection']);if(is_wp_error($preview)){return $preview;}
        $file_preview=self::preview_backup_plugin_files($archive,$selected['selection']['plugin_paths']);if(is_wp_error($file_preview)){return $file_preview;}
        $db_fingerprint=(string)$preview['plan_fingerprint'];$file_fingerprint=(string)$file_preview['plan_fingerprint'];unset($file_preview['plan_fingerprint']);
        $context=hash_init('sha256');
        if(!self::backup_restore_plan_hash_add($context,array('database_plan'=>$db_fingerprint,'file_plan'=>$file_fingerprint,'archive_checksum'=>isset($archive['file_checksum'])?(string)$archive['file_checksum']:''))){return new WP_Error('wsm_restore_plan_fingerprint_failed','ساخت اثرانگشت نهایی برنامهٔ بازیابی ناموفق بود.',array('status'=>500));}
        $preview['plugin_files']=$file_preview;$preview['plan_fingerprint']=hash_final($context);
        return $preview;
    }

    /** Stream one verified ZIP member into a sibling temporary file, then atomically rename it. */
    private static function backup_install_zip_member($zip,$entry,&$changed_paths) {
        if(!is_array($entry)||empty($entry['path'])||!isset($entry['size'],$entry['sha256'],$entry['mode'])){return new WP_Error('wsm_restore_file_manifest_invalid','ردیف فایل برای نوشتن معتبر نیست.',array('status'=>400));}
        $relative=self::backup_archive_relative_path((string)$entry['path']);if($relative===false){return new WP_Error('wsm_restore_file_path_invalid','مسیر فایل برای نوشتن معتبر نیست.',array('status'=>400));}
        $target=self::backup_plugin_target_path($relative,true);if(is_wp_error($target)){return $target;}
        $stream=$zip->getStream((string)$entry['path']);if(!$stream){return new WP_Error('wsm_restore_file_stream_failed','خواندن فایل افزونه از بسته ناموفق بود.',array('status'=>400));}
        try{$token=bin2hex(random_bytes(12));}catch(Exception $e){fclose($stream);return new WP_Error('wsm_restore_file_random_failed','ساخت نام امن فایل موقت بازیابی ناموفق بود.',array('status'=>500));}
        $temporary=trailingslashit(dirname($target)).'.wsm-restore-'.$token.'.tmp';
        $output=self::open_private_backup_file($temporary,'xb');
        if(!$output){fclose($stream);return new WP_Error('wsm_restore_file_temp_create_failed','ساخت فایل موقت خصوصی برای بازیابی ناموفق بود.',array('status'=>500));}
        $context=hash_init('sha256');$total=0;$ok=true;$iterations=0;
        while(!feof($stream)){
            if(($iterations++%16)===0&&!self::renew_active_backup_lock_lease()){$ok=false;break;}
            $chunk=fread($stream,1048576);
            if($chunk===false){$ok=false;break;}
            if($chunk===''){if(feof($stream)){break;}continue;}
            $total+=strlen($chunk);
            if($total>(int)$entry['size']||$total>67108864){$ok=false;break;}
            hash_update($context,$chunk);$offset=0;$chunk_length=strlen($chunk);
            while($offset<$chunk_length){$written=fwrite($output,substr($chunk,$offset));if($written===false||$written===0){$ok=false;break 2;}$offset+=$written;}
        }
        fclose($stream);
        if($ok&&function_exists('fflush')){$ok=fflush($output);}
        if($ok&&function_exists('fsync')){@fsync($output);}
        $closed=fclose($output);$actual_hash=$ok?hash_final($context):'';
        if(!$ok||!$closed||$total!==(int)$entry['size']||!is_string($actual_hash)||!hash_equals(strtolower((string)$entry['sha256']),strtolower($actual_hash))){@unlink($temporary);return new WP_Error('wsm_restore_file_checksum_mismatch','اندازه یا SHA-256 فایل موقت با manifest مطابقت ندارد؛ فایل مقصد تغییر نکرد.',array('status'=>400));}
        if(is_link($target)||!@rename($temporary,$target)){@unlink($temporary);return new WP_Error('wsm_restore_file_commit_failed','ثبت اتمی فایل در مسیر اصلی افزونه انجام نشد.',array('status'=>500));}
        $changed_paths[(string)$entry['path']]=true;
        $mode=in_array((int)$entry['mode'],array(0644,0755),true)?(int)$entry['mode']:0644;
        @chmod($target,$mode);
        $verify=@hash_file('sha256',$target);
        if(!is_string($verify)||!hash_equals(strtolower((string)$entry['sha256']),strtolower($verify))){return new WP_Error('wsm_restore_file_verify_failed','بررسی SHA-256 فایل پس از نوشتن ناموفق بود.',array('status'=>500));}
        return true;
    }

    private static function backup_apply_plugin_files($archive,$groups,&$changed_paths) {
        $changed_paths=array();$files=self::backup_archive_files_for_groups($archive,$groups);if(is_wp_error($files)){return $files;}
        if(!$files){return array('file_count'=>0,'new'=>0,'overwrite'=>0,'unchanged'=>0,'total_bytes'=>0);}
        if(!class_exists('ZipArchive')||empty($archive['path'])||is_link($archive['path'])||!is_file($archive['path'])){return new WP_Error('wsm_restore_zip_unavailable','بستهٔ ZIP برای بازیابی فایل‌ها در دسترس نیست.',array('status'=>500));}
        $actual=@hash_file('sha256',$archive['path']);
        if(!is_string($actual)||!isset($archive['file_checksum'])||!hash_equals(strtolower((string)$archive['file_checksum']),strtolower($actual))){return new WP_Error('wsm_restore_archive_changed','فایل ZIP پس از پیش‌نمایش تغییر کرده است؛ دوباره پیش‌نمایش بگیرید.',array('status'=>409));}
        $zip=new ZipArchive();$opened=$zip->open($archive['path']);if($opened!==true){return new WP_Error('wsm_restore_zip_open_failed','بازکردن فایل ZIP برای بازیابی ناموفق بود.',array('status'=>400));}
        $summary=array('file_count'=>count($files),'new'=>0,'overwrite'=>0,'unchanged'=>0,'total_bytes'=>0);$failure=null;
        try{
            foreach($files as $file){
                if(!self::renew_active_backup_lock_lease()){$failure=new WP_Error('wsm_restore_lock_lost','قفل بازیابی هنگام نوشتن فایل‌ها از دست رفت.',array('status'=>409));break;}
                $relative=self::backup_archive_relative_path($file['path']);$target=$relative===false?new WP_Error('wsm_restore_file_path_invalid','مسیر فایل برای بازیابی معتبر نیست.',array('status'=>400)):self::backup_plugin_target_path($relative,false);
                if(is_wp_error($target)){$failure=$target;break;}
                $exists=is_file($target);$current_hash=$exists?@hash_file('sha256',$target):'';
                $before=$exists&&is_string($current_hash)&&hash_equals(strtolower((string)$file['sha256']),strtolower($current_hash))?'unchanged':($exists?'overwrite':'new');
                if($before==='unchanged'){$summary['unchanged']++;continue;}
                $installed=self::backup_install_zip_member($zip,$file,$changed_paths);
                if(is_wp_error($installed)){$failure=$installed;break;}
                $summary[$before]++;$summary['total_bytes']+=(int)$file['size'];
            }
        }catch(Throwable $e){$failure=new WP_Error('wsm_restore_file_write_failed','نوشتن یکی از فایل‌های افزونه با خطای داخلی متوقف شد.',array('status'=>500));}
        $closed=$zip->close();
        if(!$closed&&!$failure){$failure=new WP_Error('wsm_restore_zip_close_failed','بستن بستهٔ ZIP پس از نوشتن فایل‌ها ناموفق بود.',array('status'=>500));}
        if($failure){return $failure;}
        return $summary;
    }

    /** Restore files touched by a failed attempt from the pre-restore archive, removing newly-created paths. */
    private static function backup_rollback_plugin_files($before_archive,$changed_paths) {
        if(!$changed_paths){return true;}
        if(!is_array($before_archive)||!empty($before_archive['legacy'])||empty($before_archive['manifest']['files'])||!class_exists('ZipArchive')){return new WP_Error('wsm_restore_file_rollback_unavailable','نسخهٔ پشتیبان پیش از بازیابی، فایل‌های لازم برای بازگردانی جبرانی را ندارد.',array('status'=>500));}
        if(empty($before_archive['path'])||is_link($before_archive['path'])||!is_file($before_archive['path'])){return new WP_Error('wsm_restore_file_rollback_unavailable','فایل نسخهٔ پشتیبان برای بازگردانی جبرانی فایل‌ها در دسترس نیست.',array('status'=>500));}
        $actual=@hash_file('sha256',$before_archive['path']);
        if(!is_string($actual)||!isset($before_archive['file_checksum'])||!hash_equals(strtolower((string)$before_archive['file_checksum']),strtolower($actual))){return new WP_Error('wsm_restore_file_rollback_checksum_failed','SHA-256 نسخهٔ پشتیبان پیش از بازیابی برای بازگردانی جبرانی فایل‌ها معتبر نیست.',array('status'=>500));}
        $before_map=array();foreach($before_archive['manifest']['files'] as $file){if(isset($file['path'])){$before_map[(string)$file['path']]=$file;}}
        $zip=new ZipArchive();$opened=$zip->open($before_archive['path']);if($opened!==true){return new WP_Error('wsm_restore_file_rollback_open_failed','بازکردن نسخهٔ پشتیبان برای بازگردانی فایل‌ها ناموفق بود.',array('status'=>500));}
        $issues=array();$dummy=array();
        try{
            foreach(array_keys($changed_paths) as $archive_path){
                if(isset($before_map[$archive_path])){$restored=self::backup_install_zip_member($zip,$before_map[$archive_path],$dummy);if(is_wp_error($restored)){$issues[]=$restored->get_error_message();}}
                else{
                    $relative=self::backup_archive_relative_path($archive_path);$target=$relative===false?new WP_Error('wsm_restore_file_path_invalid','مسیر فایل تازه‌ساخته‌شده برای بازگردانی جبرانی معتبر نیست.',array('status'=>400)):self::backup_plugin_target_path($relative,false);
                    if(is_wp_error($target)){$issues[]=$target->get_error_message();continue;}
                    if(is_file($target)&&!is_link($target)&&!@unlink($target)){$issues[]='حذف فایل تازه‌ساخته‌شده هنگام بازگردانی جبرانی ناموفق بود: '.$relative;}
                    elseif(is_link($target)){$issues[]='فایل تازه‌ساخته‌شده به پیوند نمادین تبدیل شده بود و حذف نشد: '.$relative;}
                }
            }
        }catch(Throwable $e){$issues[]='خطای داخلی هنگام بازگردانی جبرانی فایل‌های افزونه رخ داد.';}
        $closed=$zip->close();if(!$closed){$issues[]='بستن نسخهٔ پشتیبان پس از بازگردانی جبرانی فایل‌ها ناموفق بود.';}
        if($issues){return new WP_Error('wsm_restore_file_rollback_failed',substr(implode(' ',$issues),0,1600),array('status'=>500));}
        return true;
    }

    private static function write_backup_archive($path,$built,$selection) {
        if(!class_exists('ZipArchive')){return new WP_Error('wsm_backup_zip_extension_missing','برای ساخت فایل ZIP، افزونهٔ Zip در PHP سرور فعال نیست.',array('status'=>500));}
        if(!is_array($built)||!isset($built['json'],$built['document'],$built['metadata'])||!is_string($built['json'])){return new WP_Error('wsm_backup_archive_payload_invalid','دادهٔ لازم برای ساخت بستهٔ بکاپ معتبر نیست.',array('status'=>500));}
        $requested_groups=isset($selection['plugin_paths'])?$selection['plugin_paths']:null;
        if($requested_groups!==null&&!is_array($requested_groups)){return new WP_Error('wsm_backup_selection_path_invalid','فهرست فایل‌های کد برای بکاپ معتبر نیست.',array('status'=>400));}
        if($requested_groups){return new WP_Error('wsm_backup_code_files_disabled','فایل‌های کد افزونه در بکاپ دادهٔ باشگاه مجاز نیستند.',array('status'=>400));}
        $groups=array();$files=array();
        $actual_groups=array();$manifest_files=array();$total=0;
        foreach($files as $file){
            if(!is_array($file)||empty($file['full_path'])||empty($file['archive_path'])||!isset($file['size'],$file['mode'],$file['group'])||is_link($file['full_path'])||!is_file($file['full_path'])){return new WP_Error('wsm_backup_plugin_file_changed','یکی از فایل‌های افزونه هنگام تهیهٔ بکاپ تغییر کرد؛ بسته ساخته نشد.',array('status'=>409));}
            clearstatcache(true,$file['full_path']);$size=@filesize($file['full_path']);$sha=@hash_file('sha256',$file['full_path']);
            if($size===false||(int)$size!==(int)$file['size']||!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/i',$sha)){return new WP_Error('wsm_backup_plugin_file_read_failed','خواندن یا محاسبهٔ SHA-256 یکی از فایل‌های افزونه ناموفق بود.',array('status'=>500));}
            $total+=(int)$size;if($total>1073741824){return new WP_Error('wsm_backup_plugin_total_size_limit','حجم کل فایل‌های افزونه از سقف ایمن بکاپ بیشتر است.',array('status'=>413));}
            $manifest_files[]=array('path'=>$file['archive_path'],'size'=>(int)$size,'sha256'=>strtolower($sha),'mode'=>(int)$file['mode']);
            $actual_groups[(string)$file['group']]=true;
        }
        if(is_array($groups)){
            foreach($groups as $group){if(!isset($actual_groups[$group])){return new WP_Error('wsm_backup_plugin_selection_changed','یکی از پوشه‌های انتخاب‌شده هنگام ساخت بکاپ خالی یا مفقود شد؛ انتخاب را تازه کنید.',array('status'=>409));}}
        }
        $table_names=array();foreach($built['document']['database_tables'] as $table){if(isset($table['name'])){$table_names[]=(string)$table['name'];}}
        sort($table_names,SORT_STRING);$group_names=array_keys($actual_groups);sort($group_names,SORT_STRING);
        global $wpdb;$database_version='unknown';
        if(is_object($wpdb)&&method_exists($wpdb,'db_version')){$version=$wpdb->db_version();if(is_scalar($version)){$database_version=substr((string)$version,0,128);}}
        $manifest=array(
            'archive_format'=>'wsmbackup','archive_version'=>2,'created_at'=>(string)$built['metadata']['created_at'],
            'plugin_version'=>(string)$built['metadata']['plugin_version'],'database_version'=>$database_version,
            'database_entry'=>'database.json','database_sha256'=>hash('sha256',$built['json']),
            'file_count'=>count($manifest_files),'files'=>$manifest_files,
            'selection'=>array('database_tables'=>$table_names,'plugin_paths'=>$group_names),'checksum_algorithm'=>'sha256',
        );
        $manifest_without_hash=wp_json_encode($manifest,self::backup_json_flags());
        if(!is_string($manifest_without_hash)){return new WP_Error('wsm_backup_manifest_encode_failed','ساخت manifest.json انجام نشد.',array('status'=>500));}
        $manifest['manifest_sha256']=hash('sha256',$manifest_without_hash);
        $manifest_json=wp_json_encode($manifest,self::backup_json_flags());
        if(!is_string($manifest_json)||strlen($manifest_json)>16777216){return new WP_Error('wsm_backup_manifest_encode_failed','ساخت manifest.json با اندازهٔ مجاز انجام نشد.',array('status'=>500));}
        $zip=new ZipArchive();$opened=$zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);
        if($opened!==true){return new WP_Error('wsm_backup_zip_create_failed','ساخت بستهٔ ZIP بکاپ آغاز نشد.',array('status'=>500));}
        $ok=true;$write_error=null;
        try{
            $ok=$zip->addFromString('database.json',$built['json'])&&$zip->addFromString('manifest.json',$manifest_json);
            if(!$ok){$write_error=new WP_Error('wsm_backup_zip_write_failed','نوشتن فهرست داخلی یا داده‌های پایگاه داده در بسته ناموفق بود.',array('status'=>500));}
            foreach($files as $file){
                if($write_error){break;}
                if(!self::renew_active_backup_lock_lease()){$write_error=new WP_Error('wsm_backup_lock_lost','قفل بکاپ هنگام افزودن فایل‌های افزونه از دست رفت.',array('status'=>409));break;}
                clearstatcache(true,$file['full_path']);
                if(is_link($file['full_path'])||!is_file($file['full_path'])||(int)@filesize($file['full_path'])!==(int)$file['size']){$write_error=new WP_Error('wsm_backup_plugin_file_changed','یکی از فایل‌های افزونه هنگام ساخت ZIP تغییر کرد؛ بکاپ کامل نشد.',array('status'=>409));break;}
                if(!$zip->addFile($file['full_path'],$file['archive_path'])){$write_error=new WP_Error('wsm_backup_zip_file_add_failed','افزودن یکی از فایل‌های افزونه به بسته ناموفق بود.',array('status'=>500));break;}
                if(method_exists($zip,'setCompressionName')&&defined('ZipArchive::CM_DEFLATE')){@$zip->setCompressionName($file['archive_path'],ZipArchive::CM_DEFLATE,6);}
            }
        }catch(Throwable $e){$ok=false;$write_error=new WP_Error('wsm_backup_zip_write_failed','خطای داخلی هنگام افزودن فایل به بستهٔ ZIP رخ داد.',array('status'=>500));}
        $closed=$zip->close();
        if($write_error){return $write_error;}
        if(!$ok||!$closed){return new WP_Error('wsm_backup_zip_close_failed','بستن یا نهایی‌کردن فایل ZIP بکاپ ناموفق بود.',array('status'=>500));}
        if(!self::backup_file_permissions_are_private($path)){return new WP_Error('wsm_backup_file_permissions_failed','اعمال مجوز خصوصی برای فایل ZIP بکاپ ناموفق بود.',array('status'=>500));}
        clearstatcache(true,$path);$size=@filesize($path);
        if($size===false||$size<1||$size>536870912){return new WP_Error('wsm_backup_zip_size_invalid','اندازهٔ نهایی بستهٔ ZIP خارج از سقف ۵۱۲ مگابایت است.',array('status'=>413));}
        $verified=self::read_backup_package($path,(int)$size);
        if(is_wp_error($verified)){return new WP_Error('wsm_backup_zip_verify_failed','بررسی کامل ساختار و SHA-256 بستهٔ تازه‌ساخته‌شده ناموفق بود: '.$verified->get_error_message(),array('status'=>500));}
        return array('size_bytes'=>(int)$size,'file_checksum'=>$verified['archive']['file_checksum'],'manifest'=>$manifest,'file_count'=>count($manifest_files),'database_version'=>$database_version);
    }


    /** Default and administrator-editable backup configuration (section 3). */
    public static function get_backup_configuration() {
        $saved=get_option('wsm_backup_settings',array());
        $saved=is_array($saved)?$saved:array();
        return wp_parse_args($saved,array(
            'auto_enabled'=>false,
            'schedule'=>'daily',
            'storage_mode'=>'default',
            'storage_path'=>'',
            'external_storage_path'=>'',
            'retention'=>5,
            'email_notifications'=>true,
            'email_recipient'=>get_option('admin_email',''),
        ));
    }

    public static function save_backup_configuration($input) {
        if(!is_array($input)){return new WP_Error('wsm_backup_settings_invalid','تنظیمات بکاپ معتبر نیست.',array('status'=>400));}
        $old=self::get_backup_configuration();
        if(array_key_exists('email_notifications',$input)&&!is_scalar($input['email_notifications'])){return new WP_Error('wsm_backup_email_setting_invalid','وضعیت اعلان ایمیلی معتبر نیست.',array('status'=>400));}
        $notify=array_key_exists('email_notifications',$input)?rest_sanitize_boolean($input['email_notifications']):!empty($old['email_notifications']);
        if(array_key_exists('email_recipient',$input)&&!is_scalar($input['email_recipient'])){return new WP_Error('wsm_backup_email_invalid','نشانی ایمیل بکاپ معتبر نیست.',array('status'=>400));}
        $recipient=array_key_exists('email_recipient',$input)?sanitize_email(trim((string)$input['email_recipient'])):sanitize_email(is_scalar($old['email_recipient'])?(string)$old['email_recipient']:'');
        if($recipient!==''&&!is_email($recipient)){return new WP_Error('wsm_backup_email_invalid','نشانی ایمیل بکاپ معتبر نیست.',array('status'=>400));}
        if($notify&&$recipient===''){$recipient=sanitize_email((string)get_option('admin_email',''));}
        if($notify&&!is_email($recipient)){return new WP_Error('wsm_backup_email_invalid','برای اعلان بکاپ، نشانی ایمیل معتبری لازم است.',array('status'=>400));}
        $enabled=array_key_exists('auto_enabled',$input)?rest_sanitize_boolean($input['auto_enabled']):!empty($old['auto_enabled']);
        $schedule=isset($input['schedule'])?sanitize_key((string)$input['schedule']):(string)$old['schedule'];
        if(!in_array($schedule,array('daily','weekly','monthly'),true)){return new WP_Error('wsm_backup_schedule_invalid','زمان‌بندی بکاپ نامعتبر است.',array('status'=>400));}
        $mode=isset($input['storage_mode'])?sanitize_key((string)$input['storage_mode']):(string)$old['storage_mode'];
        if(!in_array($mode,array('default','custom'),true)){return new WP_Error('wsm_backup_storage_mode_invalid','نوع مسیر ذخیره‌سازی نامعتبر است.',array('status'=>400));}
        $raw_retention=array_key_exists('retention',$input)?(string)$input['retention']:(string)$old['retention'];
        if(!preg_match('/^\d+$/',$raw_retention)||(int)$raw_retention<1||(int)$raw_retention>100){return new WP_Error('wsm_backup_retention_invalid','تعداد بکاپ‌های خودکار نگهداری‌شده باید بین ۱ تا ۱۰۰ باشد.',array('status'=>400));}
        if(array_key_exists('external_storage_path',$input)&&!is_scalar($input['external_storage_path'])){return new WP_Error('wsm_backup_external_path_invalid','مسیر خارجی باید یک مسیر محلی معتبر باشد.',array('status'=>400));}
        $path=array_key_exists('storage_path',$input)?(is_scalar($input['storage_path'])?trim((string)$input['storage_path']):''):(string)$old['storage_path'];
        $external_path=array_key_exists('external_storage_path',$input)?trim((string)$input['external_storage_path']):(isset($old['external_storage_path'])&&is_scalar($old['external_storage_path'])?trim((string)$old['external_storage_path']):'');
        $settings=array('auto_enabled'=>(bool)$enabled,'schedule'=>$schedule,'storage_mode'=>$mode,'storage_path'=>$path,'external_storage_path'=>$external_path,'retention'=>(int)$raw_retention,'email_notifications'=>(bool)$notify,'email_recipient'=>$recipient);
        $directory=self::backup_storage_directory($settings,true);
        if(is_wp_error($directory)){return $directory;}
        if($external_path!==''){
            $external_directory=self::external_backup_storage_directory($external_path,true);
            if(is_wp_error($external_directory)){return $external_directory;}
            if(untrailingslashit(wp_normalize_path($external_directory))===untrailingslashit(wp_normalize_path($directory))){return new WP_Error('wsm_backup_external_same_as_primary','مسیر خارجی باید با مسیر اصلی بکاپ متفاوت باشد.',array('status'=>400));}
            $settings['external_storage_path']=$external_directory;
        }
        $previous=get_option('wsm_backup_settings',false);
        update_option('wsm_backup_settings',$settings,false);
        $schedule_result=self::sync_backup_schedule();
        if(is_wp_error($schedule_result)){
            if($previous===false){delete_option('wsm_backup_settings');}else{update_option('wsm_backup_settings',$previous,false);}
            self::sync_backup_schedule();
            return $schedule_result;
        }
        return $settings;
    }

    private static function backup_path_is_within_root($path,$root) {
        $path=untrailingslashit(wp_normalize_path((string)$path));$root=untrailingslashit(wp_normalize_path((string)$root));
        if($path===''||$root===''){return false;}
        if($root==='/'){return true;}
        return $path===$root||strpos(trailingslashit($path),trailingslashit($root))===0;
    }

    /** Default primary backup directory inside the WordPress uploads tree. */
    public static function default_backup_directory_path() {
        $uploads=wp_upload_dir(null,false);
        if(!is_array($uploads)||!empty($uploads['error'])||empty($uploads['basedir'])){return new WP_Error('wsm_backup_uploads_unavailable','مسیر پوشهٔ uploads برای بکاپ در دسترس نیست.',array('status'=>500));}
        $base=wp_normalize_path(trim((string)$uploads['basedir']));
        if($base===''||$base==='/'||strpos($base,"\0")!==false||strpos($base,'://')!==false||!preg_match('#^(?:/|[A-Za-z]:/)#',$base)||preg_match('#(^|/)\.\.?(/|$)#',$base)){return new WP_Error('wsm_backup_uploads_invalid','مسیر پوشهٔ uploads برای بکاپ معتبر نیست.',array('status'=>500));}
        return wp_normalize_path(trailingslashit(untrailingslashit($base)).'wsm-backups');
    }

    /** Resolve a local backup directory, create it if requested, and deny direct web access. */
    public static function backup_storage_directory($settings=null,$create=false) {
        $settings=is_array($settings)?$settings:self::get_backup_configuration();
        $is_custom=isset($settings['storage_mode'])&&$settings['storage_mode']==='custom';
        $uploads_base='';
        if($is_custom){
            $path=isset($settings['storage_path'])&&is_scalar($settings['storage_path'])?trim((string)$settings['storage_path']):'';
            if($path===''||strpos($path,"\0")!==false||strpos($path,'://')!==false){return new WP_Error('wsm_backup_path_invalid','مسیر سفارشی باید یک مسیر محلی معتبر باشد.',array('status'=>400));}
            $path=wp_normalize_path($path);
            if(!preg_match('#^(?:/|[A-Za-z]:/)#',$path)||preg_match('#(^|/)\.\.?(/|$)#',$path)){return new WP_Error('wsm_backup_path_invalid','مسیر سفارشی باید مطلق و بدون بخش‌های نسبی باشد.',array('status'=>400));}
        }else{
            $path=self::default_backup_directory_path();
            if(is_wp_error($path)){return $path;}
            $uploads=wp_upload_dir(null,false);
            if(!is_array($uploads)||!empty($uploads['error'])||empty($uploads['basedir'])){return new WP_Error('wsm_backup_uploads_unavailable','مسیر پوشهٔ uploads برای بکاپ در دسترس نیست.',array('status'=>500));}
            $uploads_base=untrailingslashit(wp_normalize_path((string)$uploads['basedir']));
            if($uploads_base===''||$uploads_base==='/'||$path===$uploads_base||!self::backup_path_is_within_root($path,$uploads_base)){return new WP_Error('wsm_backup_path_outside_uploads','مسیر پیش‌فرض بکاپ باید داخل پوشهٔ اختصاصی uploads/wsm-backups باشد.',array('status'=>400));}
        }
        $path=untrailingslashit(wp_normalize_path($path));
        $root=untrailingslashit(wp_normalize_path(ABSPATH));
        $content=defined('WP_CONTENT_DIR')?untrailingslashit(wp_normalize_path(WP_CONTENT_DIR)):'';
        if($path===''||$path==='/'||preg_match('/^[A-Za-z]:$/',$path)||$path===$root||($content!==''&&$path===$content)){
            return new WP_Error('wsm_backup_path_unsafe','مسیر انتخاب‌شده برای ذخیرهٔ بکاپ مجاز نیست.',array('status'=>400));
        }
        if(is_link($path)){return new WP_Error('wsm_backup_path_symlink','مسیر ذخیرهٔ بکاپ نباید پیوند نمادین باشد.',array('status'=>400));}
        if(!is_dir($path)){
            if(!$create){return new WP_Error('wsm_backup_path_missing','پوشهٔ بکاپ هنوز ساخته نشده است.',array('status'=>404));}
            if(!wp_mkdir_p($path)){return new WP_Error('wsm_backup_mkdir_failed','ساخت پوشهٔ بکاپ انجام نشد.',array('status'=>500));}
        }
        $real=realpath($path);
        if($real===false||!is_dir($real)||($create?!wp_is_writable($real):!is_readable($real))){return new WP_Error('wsm_backup_path_unwritable','پوشهٔ بکاپ قابل‌نوشتن نیست.',array('status'=>500));}
        $real=untrailingslashit(wp_normalize_path($real));
        if(!$is_custom){
            $real_uploads=realpath($uploads_base);
            if($real_uploads===false||!self::backup_path_is_within_root($real,$real_uploads)){return new WP_Error('wsm_backup_path_outside_uploads','مسیر پیش‌فرض بکاپ از پوشهٔ uploads خارج شد و استفاده از آن متوقف شد.',array('status'=>400));}
        }
        $htaccess=trailingslashit($real).'.htaccess';
        if(is_link($htaccess)){return new WP_Error('wsm_backup_protection_symlink','فایل محافظت پوشهٔ بکاپ نباید پیوند نمادین باشد.',array('status'=>400));}
        $marker='# WSM backup protection';
        $rules=$marker."\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\nOptions -Indexes\n";
        $existing=file_exists($htaccess)?@file_get_contents($htaccess):'';
        if($existing===false){return new WP_Error('wsm_backup_protection_read_failed','خواندن تنظیمات محافظت پوشهٔ بکاپ انجام نشد.',array('status'=>500));}
        $needs_protection=strpos($existing,$marker)===false||stripos($existing,'Require all denied')===false||stripos($existing,'Deny from all')===false;
        if($needs_protection){$written=@file_put_contents($htaccess,rtrim($existing).($existing!==''?"\n\n":"").$rules,LOCK_EX);if($written===false){return new WP_Error('wsm_backup_protection_write_failed','محافظت مستقیم از پوشهٔ بکاپ انجام نشد.',array('status'=>500));}}
        return $real;
    }


    /** Validate an optional mirror path and keep it outside all WordPress web/content roots. */
    public static function external_backup_storage_directory($path,$create=false) {
        if(!is_string($path)||trim($path)===''){return new WP_Error('wsm_backup_external_path_invalid','مسیر خارجی بکاپ خالی یا نامعتبر است.',array('status'=>400));}
        $path=trim($path);
        if(strpos($path,"\0")!==false||strpos($path,'://')!==false){return new WP_Error('wsm_backup_external_path_invalid','مسیر خارجی باید یک مسیر محلی باشد.',array('status'=>400));}
        $path=wp_normalize_path($path);
        if(!preg_match('#^(?:/|[A-Za-z]:/)#',$path)||preg_match('#(^|/)\.\.?(/|$)#',$path)){return new WP_Error('wsm_backup_external_path_invalid','مسیر خارجی باید مطلق و بدون بخش‌های نسبی باشد.',array('status'=>400));}
        $roots=array();
        if(defined('ABSPATH')){$roots[]=(string)ABSPATH;}
        if(defined('WP_CONTENT_DIR')){$roots[]=(string)WP_CONTENT_DIR;}
        if(isset($_SERVER['DOCUMENT_ROOT'])&&is_scalar($_SERVER['DOCUMENT_ROOT'])&&trim((string)$_SERVER['DOCUMENT_ROOT'])!==''){$roots[]=(string)$_SERVER['DOCUMENT_ROOT'];}
        $uploads=wp_upload_dir(null,false);
        if(is_array($uploads)&&!empty($uploads['basedir'])){$roots[]=(string)$uploads['basedir'];}
        foreach($roots as $root){if(trim((string)$root)!==''&&self::backup_path_is_within_root($path,$root)){return new WP_Error('wsm_backup_external_inside_web_root','مسیر خارجی باید بیرون از public_html، wp-content و uploads باشد.',array('status'=>400));}}
        $probe=$path;
        while(!file_exists($probe)&&!is_link($probe)&&dirname($probe)!==$probe){$probe=dirname($probe);}
        $real_probe=realpath($probe);
        if($real_probe!==false){foreach($roots as $root){$real_root=realpath($root);if($real_root!==false&&self::backup_path_is_within_root($real_probe,$real_root)){return new WP_Error('wsm_backup_external_inside_web_root','مسیر خارجی از طریق پیوند پوشه‌ای به ریشهٔ سایت می‌رسد و مجاز نیست.',array('status'=>400));}}}
        $directory=self::backup_storage_directory(array('storage_mode'=>'custom','storage_path'=>$path),$create);
        if(is_wp_error($directory)){return $directory;}
        foreach($roots as $root){$real_root=realpath($root);if($real_root!==false&&self::backup_path_is_within_root($directory,$real_root)){return new WP_Error('wsm_backup_external_inside_web_root','مسیر واقعی خارجی داخل ریشهٔ وب یا wp-content قرار دارد.',array('status'=>400));}}
        return $directory;
    }

    /** Keep backup settings JSON-safe and omit credential-like option keys. */
    private static function filter_backup_setting_value($value,$depth=0) {
        if($depth>32){return new WP_Error('wsm_backup_settings_depth','عمق تنظیمات افزونه برای بکاپ بیش از حد است.',array('status'=>500));}
        if(is_array($value)){
            $filtered=array();
            foreach($value as $key=>$item){
                if(self::backup_sensitive_field_name($key)){continue;}
                $clean=self::filter_backup_setting_value($item,$depth+1);
                if(is_wp_error($clean)){return $clean;}
                $filtered[$key]=$clean;
            }
            return $filtered;
        }
        if(is_object($value)||is_resource($value)){return new WP_Error('wsm_backup_settings_type','نوع یکی از تنظیمات افزونه برای بکاپ پشتیبانی نمی‌شود.',array('status'=>500));}
        if(is_float($value)&&!is_finite($value)){return new WP_Error('wsm_backup_settings_number','یکی از تنظیمات افزونه عدد معتبر JSON نیست.',array('status'=>500));}
        if($value===null||is_string($value)||is_int($value)||is_float($value)||is_bool($value)){return $value;}
        return new WP_Error('wsm_backup_settings_type','نوع یکی از تنظیمات افزونه برای بکاپ پشتیبانی نمی‌شود.',array('status'=>500));
    }

    /** Identify authentication secrets and token state that must never be copied into a backup. */
    private static function backup_sensitive_table_column($table_name,$column_name) {
        if(!is_string($table_name)||!is_string($column_name)){return false;}
        $table_name=strtolower($table_name);$column_name=strtolower($column_name);
        if($table_name==='wsm_devices'&&in_array($column_name,array('token_hash','refresh_hash','token_expires','signing_key'),true)){return true;}
        if($table_name==='wsm_access_keys'&&in_array($column_name,array('key_hash','expires_at','revoked_at'),true)){return true;}
        return self::backup_sensitive_field_name($column_name);
    }

    /** Remove credentials from an imported table before preview/apply, including older format-1 files. */
    private static function backup_table_without_sensitive_columns($table_data) {
        if(!is_array($table_data)||empty($table_data['name'])||!isset($table_data['columns'],$table_data['primary_key'],$table_data['rows'])||!is_array($table_data['columns'])||!is_array($table_data['primary_key'])||!is_array($table_data['rows'])){
            return new WP_Error('wsm_backup_table_sanitize_invalid','ساختار جدول برای کنارگذاشتن فیلدهای احراز هویت معتبر نیست.',array('status'=>400));
        }
        $name=(string)$table_data['name'];$safe_columns=array();
        foreach($table_data['columns'] as $column){if(!self::backup_sensitive_table_column($name,$column)){$safe_columns[]=$column;}}
        foreach($table_data['primary_key'] as $primary){
            if(self::backup_sensitive_table_column($name,$primary)){return new WP_Error('wsm_backup_sensitive_primary_key','کلید اصلی جدول با فیلد احراز هویت تداخل دارد؛ بازیابی امن نیست.',array('status'=>400));}
        }
        if(!$safe_columns){return new WP_Error('wsm_backup_table_no_safe_columns','جدول هیچ ستونی برای بازیابی امن ندارد.',array('status'=>400));}
        $rows=array();
        foreach($table_data['rows'] as $row){
            if(!is_array($row)){return new WP_Error('wsm_backup_table_sanitize_row_invalid','ردیف جدول برای بازیابی امن معتبر نیست.',array('status'=>400));}
            $safe_row=array();
            foreach($safe_columns as $column){
                if(!array_key_exists($column,$row)){return new WP_Error('wsm_backup_table_sanitize_row_invalid','ستون امنی از ردیف جدول وجود ندارد.',array('status'=>400));}
                $safe_row[$column]=$row[$column];
            }
            $rows[]=$safe_row;
        }
        return array('name'=>$name,'columns'=>$safe_columns,'primary_key'=>$table_data['primary_key'],'rows'=>$rows);
    }

    /** Capture selected WSM-owned base tables/options only; never dump WordPress or WooCommerce tables. */
    public static function collect_backup_snapshot($selected_table_names=null) {
        global $wpdb;
        $available=self::discover_backup_table_names();if(is_wp_error($available)){return $available;}
        if($selected_table_names!==null){
            if(!self::backup_array_is_list($selected_table_names)){return new WP_Error('wsm_backup_table_selection_invalid','فهرست جدول‌های انتخاب‌شده برای نسخهٔ پشتیبان معتبر نیست.',array('status'=>400));}
            $selected=array();foreach($selected_table_names as $name){if(!is_string($name)||!isset($available[$name])||isset($selected[$name])){return new WP_Error('wsm_backup_table_selection_invalid','جدول انتخاب‌شده در پایگاه دادهٔ فعلی وجود ندارد یا تکراری است.',array('status'=>400));}$selected[$name]=true;}
            $tables_to_read=array();foreach($available as $name=>$physical){if(isset($selected[$name])){$tables_to_read[$name]=$physical;}}
        }else{$tables_to_read=$available;}
        $database_tables=array();
        $record_count=0;
        $batch_size=500;
        foreach($tables_to_read as $logical=>$physical){
            if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_backup_lock_lost','قفل بکاپ یا بازیابی از دست رفت؛ نسخهٔ پشتیبان برای ایمنی متوقف شد.',array('status'=>409));}
            $quoted='`'.str_replace('`','``',$physical).'`';
            $schema=$wpdb->get_results("SHOW COLUMNS FROM $quoted",ARRAY_A);
            if(!is_array($schema)||$wpdb->last_error!==''){return new WP_Error('wsm_backup_schema_read_failed','ساختار جدول '.$logical.' برای بکاپ خوانده نشد.',array('status'=>500));}
            $columns=array();
            $primary_key=array();
            foreach($schema as $column){
                if(!is_array($column)||empty($column['Field'])){return new WP_Error('wsm_backup_schema_invalid','ساختار جدول '.$logical.' معتبر نیست.',array('status'=>500));}
                $column_name=(string)$column['Field'];
                $is_primary=isset($column['Key'])&&strtoupper((string)$column['Key'])==='PRI';
                if($is_primary){
                    if(self::backup_sensitive_table_column($logical,$column_name)){return new WP_Error('wsm_backup_sensitive_primary_key','کلید اصلی جدول '.$logical.' فیلد احراز هویت است و نمی‌تواند در بکاپ باشد.',array('status'=>500));}
                    $primary_key[]=$column_name;
                }
                if(self::backup_sensitive_table_column($logical,$column_name)){continue;}
                $columns[]=$column_name;
            }
            if(!$columns){return new WP_Error('wsm_backup_schema_empty','جدول '.$logical.' هیچ ستونی برای بکاپ ندارد.',array('status'=>500));}
            $count_before=$wpdb->get_var("SELECT COUNT(*) FROM $quoted");
            if($wpdb->last_error!==''||!is_numeric($count_before)||((int)$count_before)<0){return new WP_Error('wsm_backup_count_failed','شمارش ردیف‌های جدول '.$logical.' انجام نشد.',array('status'=>500));}
            $expected=(int)$count_before;
            $select_columns=array();
            foreach($columns as $column_name){$select_columns[]='`'.str_replace('`','``',$column_name).'`';}
            $select_clause=implode(',',$select_columns);
            $order_sql='';
            if($primary_key){$order_columns=array();foreach($primary_key as $key){$order_columns[]='`'.str_replace('`','``',$key).'`';}$order_sql=' ORDER BY '.implode(',',$order_columns);}
            $rows=array();
            for($offset=0;$offset<$expected;$offset+=$batch_size){
                $sql=$wpdb->prepare("SELECT $select_clause FROM $quoted$order_sql LIMIT %d OFFSET %d",$batch_size,$offset);
                $batch=$wpdb->get_results($sql,ARRAY_A);
                if(!is_array($batch)||$wpdb->last_error!==''){return new WP_Error('wsm_backup_rows_read_failed','خواندن ردیف‌های جدول '.$logical.' انجام نشد.',array('status'=>500));}
                if(!$batch){return new WP_Error('wsm_backup_table_changed','جدول '.$logical.' هنگام تهیهٔ نسخهٔ پشتیبان تغییر کرد؛ بکاپ کامل نشد.',array('status'=>409));}
                foreach($batch as $row){if(!is_array($row)){return new WP_Error('wsm_backup_row_invalid','یکی از ردیف‌های جدول '.$logical.' معتبر نیست.',array('status'=>500));}$rows[]=$row;}
                if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_backup_lock_lost','قفل بکاپ یا بازیابی هنگام خواندن جدول از دست رفت؛ نسخهٔ پشتیبان متوقف شد.',array('status'=>409));}
            }
            $count_after=$wpdb->get_var("SELECT COUNT(*) FROM $quoted");
            if($wpdb->last_error!==''||!is_numeric($count_after)||(int)$count_after!==$expected||count($rows)!==$expected){
                return new WP_Error('wsm_backup_table_changed','جدول '.$logical.' هنگام تهیهٔ نسخهٔ پشتیبان تغییر کرد؛ بکاپ کامل نشد.',array('status'=>409));
            }
            $database_tables[]=array('name'=>$logical,'columns'=>$columns,'primary_key'=>$primary_key,'rows'=>$rows);
            $record_count+=count($rows);
        }

        $options=array();
        foreach(self::backup_club_option_names() as $option_name){
            $value=get_option($option_name,null);
            if($value===null){continue;}
            $filtered=self::filter_backup_setting_value($value);
            if(is_wp_error($filtered)){return $filtered;}
            $options[$option_name]=$filtered;
        }
        $configuration=self::filter_backup_setting_value(self::get_backup_configuration());
        if(is_wp_error($configuration)){return $configuration;}
        $message_binding=self::backup_message_site_binding();
        if(!preg_match('/^[a-f0-9]{64}$/D',$message_binding)){return new WP_Error('wsm_backup_message_binding_unavailable','ساخت نشانۀ امن نصب برای بکاپ پیام‌ها ممکن نشد.',array('status'=>500));}
        $configuration['club_message_site_binding']=$message_binding;
        return array(
            'database_tables'=>$database_tables,
            'plugin_settings'=>array('options'=>$options,'backup_configuration'=>$configuration),
            'table_count'=>count($database_tables),
            'record_count'=>$record_count,
        );
    }


    /** Physical table used as an operational index for backup files. */
    public static function backup_log_table() {
        global $wpdb;
        return $wpdb->prefix.'wsm_backup_logs';
    }

    /** Install/upgrade the backup log table without changing other plugin tables. */
    public static function ensure_backup_log_table() {
        static $ready_table='';
        global $wpdb;
        try {
            if(!is_object($wpdb)||empty($wpdb->prefix)){return new WP_Error('wsm_backup_log_db_unavailable','پایگاه داده برای ثبت گزارش بکاپ در دسترس نیست.',array('status'=>500));}
            $table=self::backup_log_table();
            if($ready_table===$table){return true;}
            if((int)get_option('wsm_backup_log_schema_version',0)>=self::BACKUP_LOG_SCHEMA_VERSION){
                $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
                if($found===$table){
                    $quoted_table='`'.str_replace('`','``',$table).'`';
                    $external_column=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $quoted_table LIKE %s",'external_storage_path'));
                    $file_checksum_column=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $quoted_table LIKE %s",'file_checksum'));
                    if($external_column==='external_storage_path'&&$file_checksum_column==='file_checksum'){$ready_table=$table;return true;}
                }
            }
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $charset=$wpdb->get_charset_collate();
            dbDelta("CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                backup_id char(32) NOT NULL,
                backup_type varchar(16) NOT NULL DEFAULT 'manual',
                filename varchar(100) NOT NULL DEFAULT '',
                storage_path text NULL,
                external_storage_path text NULL,
                file_size bigint(20) unsigned NOT NULL DEFAULT 0,
                checksum char(64) NOT NULL DEFAULT '',
                file_checksum char(64) NOT NULL DEFAULT '',
                table_count int(10) unsigned NOT NULL DEFAULT 0,
                record_count bigint(20) unsigned NOT NULL DEFAULT 0,
                status varchar(16) NOT NULL DEFAULT 'success',
                error_message text NULL,
                created_at datetime NOT NULL,
                created_at_gmt datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY backup_id (backup_id),
                KEY type_status_created (backup_type,status,created_at),
                KEY created_at (created_at)
            ) $charset;");
            $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
            if($found!==$table){return new WP_Error('wsm_backup_log_table_failed','ساخت جدول گزارش بکاپ انجام نشد.',array('status'=>500));}
            $quoted_table='`'.str_replace('`','``',$table).'`';
            $external_column=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $quoted_table LIKE %s",'external_storage_path'));
            if($external_column!=='external_storage_path'){return new WP_Error('wsm_backup_log_external_column_failed','ستون مسیر خارجی در جدول گزارش بکاپ ایجاد یا بررسی نشد.',array('status'=>500));}
            $file_checksum_column=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $quoted_table LIKE %s",'file_checksum'));
            if($file_checksum_column!=='file_checksum'){return new WP_Error('wsm_backup_log_file_checksum_column_failed','ستون SHA-256 فایل در جدول گزارش بکاپ ایجاد یا بررسی نشد.',array('status'=>500));}
            update_option('wsm_backup_log_schema_version',self::BACKUP_LOG_SCHEMA_VERSION,false);
            $ready_table=$table;
            return true;
        } catch (Throwable $e) {
            error_log('[WSM Club] WSM_Club_Operations::ensure_backup_log_table failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new WP_Error('wsm_backup_log_exception','آماده‌سازی گزارش بکاپ با خطا روبه‌رو شد.',array('status'=>500));
        }
    }

    /** Insert one completed or failed backup attempt into wsm_backup_logs. */
    private static function insert_backup_log($record) {
        global $wpdb;
        $ready=self::ensure_backup_log_table();
        if(is_wp_error($ready)){return $ready;}
        if(!is_array($record)||empty($record['backup_id'])||!preg_match('/^[a-f0-9]{32}$/',(string)$record['backup_id'])){
            return new WP_Error('wsm_backup_log_data_invalid','اطلاعات گزارش بکاپ معتبر نیست.',array('status'=>500));
        }
        $type=isset($record['backup_type'])?(string)$record['backup_type']:'manual';
        $status=isset($record['status'])?(string)$record['status']:'failed';
        if(!in_array($type,array('manual','automatic','pre_restore'),true)||!in_array($status,array('success','failed'),true)){
            return new WP_Error('wsm_backup_log_data_invalid','نوع یا وضعیت گزارش بکاپ معتبر نیست.',array('status'=>500));
        }
        $filename=isset($record['filename'])&&is_scalar($record['filename'])?(string)$record['filename']:'';
        if($filename!==''&&!preg_match('/^wsm-backup-[a-f0-9]{32}\.wsmbackup$/D',$filename)){
            return new WP_Error('wsm_backup_log_filename_invalid','نام فایل در گزارش بکاپ معتبر نیست.',array('status'=>500));
        }
        $storage_path=isset($record['storage_path'])&&is_string($record['storage_path'])?untrailingslashit(wp_normalize_path($record['storage_path'])):'';
        $external_storage_path=isset($record['external_storage_path'])&&is_string($record['external_storage_path'])?untrailingslashit(wp_normalize_path($record['external_storage_path'])):'';
        $error=isset($record['error_message'])&&is_scalar($record['error_message'])?substr(wp_strip_all_tags((string)$record['error_message']),0,1000):'';
        $data=array(
            'backup_id'=>(string)$record['backup_id'],
            'backup_type'=>$type,
            'filename'=>$filename,
            'storage_path'=>$storage_path,
            'external_storage_path'=>$external_storage_path,
            'file_size'=>isset($record['file_size'])?max(0,(int)$record['file_size']):0,
            'checksum'=>isset($record['checksum'])&&preg_match('/^[a-f0-9]{64}$/i',(string)$record['checksum'])?strtolower((string)$record['checksum']):'',
            'file_checksum'=>isset($record['file_checksum'])&&preg_match('/^[a-f0-9]{64}$/i',(string)$record['file_checksum'])?strtolower((string)$record['file_checksum']):'',
            'table_count'=>isset($record['table_count'])?max(0,(int)$record['table_count']):0,
            'record_count'=>isset($record['record_count'])?max(0,(int)$record['record_count']):0,
            'status'=>$status,
            'error_message'=>$error,
            'created_at'=>isset($record['created_at'])&&is_scalar($record['created_at'])?(string)$record['created_at']:current_time('mysql'),
            'created_at_gmt'=>isset($record['created_at_gmt'])&&is_scalar($record['created_at_gmt'])?(string)$record['created_at_gmt']:gmdate('Y-m-d H:i:s'),
        );
        $inserted=$wpdb->insert(self::backup_log_table(),$data,array('%s','%s','%s','%s','%s','%d','%s','%s','%d','%d','%s','%s','%s','%s'));
        if($inserted===false){return new WP_Error('wsm_backup_log_insert_failed','ثبت گزارش عملیات بکاپ در پایگاه داده انجام نشد.',array('status'=>500));}
        return true;
    }

    /** Resolve a previously logged storage directory using the same path-safety rules. */
    private static function backup_directory_from_log($storage_path) {
        if(!is_string($storage_path)||trim($storage_path)===''){return new WP_Error('wsm_backup_log_path_missing','مسیر ذخیرهٔ بکاپ در گزارش موجود نیست.',array('status'=>404));}
        return self::backup_storage_directory(array('storage_mode'=>'custom','storage_path'=>$storage_path),false);
    }

    private static function append_backup_log_message($backup_id,$message) {
        global $wpdb;
        if(!is_string($backup_id)||!preg_match('/^[a-f0-9]{32}$/',$backup_id)){return false;}
        $table='`'.str_replace('`','``',self::backup_log_table()).'`';
        $existing=$wpdb->get_var($wpdb->prepare("SELECT error_message FROM $table WHERE backup_id=%s LIMIT 1",$backup_id));
        if($wpdb->last_error!==''){return false;}
        $message=substr(wp_strip_all_tags((string)$message),0,1000);
        $combined=trim((string)$existing);
        if($combined!==''){$combined.="\n";}
        $combined=substr($combined.$message,0,1000);
        return $wpdb->update(self::backup_log_table(),array('error_message'=>$combined),array('backup_id'=>$backup_id),array('%s'),array('%s'))!==false;
    }

    /** Build a raw nonce URL for JSON/HTML consumers and preserve literal query separators. */
    private static function backup_download_url($id) {
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id)){return '';}
        $url=add_query_arg(array('action'=>'wsm_download_backup','backup_id'=>$id),admin_url('admin-post.php'));
        return add_query_arg('_wpnonce',wp_create_nonce('wsm_download_backup_'.$id),$url);
    }

    /** Return a recent, path-safe history for the Settings page without exposing storage paths. */
    public static function get_recent_backup_logs($limit=20,$filters=array()) {
        global $wpdb;
        $ready=self::ensure_backup_log_table();if(is_wp_error($ready)){return $ready;}
        $limit=max(1,min(50,(int)$limit));$table='`'.str_replace('`','``',self::backup_log_table()).'`';
        $filters=is_array($filters)?$filters:array();
        $backup_type=isset($filters['backup_type'])&&is_scalar($filters['backup_type'])?sanitize_key((string)$filters['backup_type']):'';
        $status=isset($filters['status'])&&is_scalar($filters['status'])?sanitize_key((string)$filters['status']):'';
        if(!in_array($backup_type,array('manual','automatic','pre_restore'),true)){$backup_type='';}
        if(!in_array($status,array('success','failed','deleted','missing'),true)){$status='';}
        $conditions=array();$arguments=array();
        if($backup_type!==''){$conditions[]='backup_type=%s';$arguments[]=$backup_type;}
        if($status!==''){$conditions[]='status=%s';$arguments[]=$status;}
        $query="SELECT backup_id,backup_type,file_size,checksum,file_checksum,table_count,record_count,status,error_message,created_at FROM $table";
        if($conditions){$query.=' WHERE '.implode(' AND ',$conditions);}
        $query.=' ORDER BY created_at DESC,id DESC LIMIT %d';$arguments[]=$limit;
        $query=call_user_func_array(array($wpdb,'prepare'),array_merge(array($query),$arguments));
        $rows=$wpdb->get_results($query,ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error!==''){return new WP_Error('wsm_backup_history_read_failed','خواندن تاریخچهٔ بکاپ انجام نشد.',array('status'=>500));}
        $history=array();
        foreach($rows as $row){
            $id=isset($row['backup_id'])&&is_scalar($row['backup_id'])?(string)$row['backup_id']:'';
            $status=isset($row['status'])&&is_scalar($row['status'])?(string)$row['status']:'';
            $download_url='';$download_issue='';
            if($status==='success'){
                if(!preg_match('/^[a-f0-9]{32}$/',$id)){
                    $download_issue='شناسهٔ بکاپ در گزارش معتبر نیست؛ دانلود امن در دسترس نیست.';
                }else{
                    $resolved=self::resolve_backup_file($id,false);
                    if(!is_wp_error($resolved)){$download_url=self::backup_download_url($id);}
                    elseif($resolved->get_error_code()==='wsm_backup_file_missing'){$download_issue='فایل بکاپ در مسیرهای ثبت‌شده پیدا نشد.';}
                    else{$download_issue='فایل بکاپ برای دانلود امن قابل‌تأیید نیست.';}
                }
            }
            $checksum=isset($row['checksum'])&&is_scalar($row['checksum'])?(string)$row['checksum']:'';
            $file_checksum=isset($row['file_checksum'])&&is_scalar($row['file_checksum'])?(string)$row['file_checksum']:'';
            $error_message=isset($row['error_message'])&&is_scalar($row['error_message'])?substr(wp_strip_all_tags((string)$row['error_message']),0,1000):'';
            $history[]=array(
                'backup_id'=>$id,'backup_type'=>isset($row['backup_type'])&&is_scalar($row['backup_type'])?(string)$row['backup_type']:'',
                'file_size'=>isset($row['file_size'])?max(0,(int)$row['file_size']):0,
                'checksum'=>preg_match('/^[a-f0-9]{64}$/i',$checksum)?strtolower($checksum):'',
                'file_checksum'=>preg_match('/^[a-f0-9]{64}$/i',$file_checksum)?strtolower($file_checksum):'',
                'table_count'=>isset($row['table_count'])?max(0,(int)$row['table_count']):0,
                'record_count'=>isset($row['record_count'])?max(0,(int)$row['record_count']):0,
                'status'=>$status,'error_message'=>$error_message,
                'created_at'=>isset($row['created_at'])&&is_scalar($row['created_at'])?(string)$row['created_at']:'',
                'download_url'=>$download_url,'download_issue'=>$download_issue,
            );
        }
        return $history;
    }

    /** Delete every safely logged copy while retaining an auditable deleted-history row. */
    private static function delete_backup_by_id($backup_id) {
        global $wpdb;
        if(!is_string($backup_id)||!preg_match('/^[a-f0-9]{32}$/',$backup_id)){return new WP_Error('wsm_backup_delete_id_invalid','شناسهٔ بکاپ برای حذف معتبر نیست.',array('status'=>400));}
        if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_backup_lock_lost','قفل عملیات پیش از حذف بکاپ از دست رفت.',array('status'=>409));}
        $ready=self::ensure_backup_log_table();if(is_wp_error($ready)){return $ready;}
        $table='`'.str_replace('`','``',self::backup_log_table()).'`';
        $row=$wpdb->get_row($wpdb->prepare("SELECT id,backup_id,backup_type,filename,storage_path,external_storage_path,status FROM $table WHERE backup_id=%s LIMIT 1",$backup_id),ARRAY_A);
        if($wpdb->last_error!==''){return new WP_Error('wsm_backup_delete_log_read_failed','گزارش بکاپ برای حذف خوانده نشد.',array('status'=>500));}
        if(!is_array($row)){return new WP_Error('wsm_backup_delete_log_missing','گزارش بکاپ موردنظر پیدا نشد.',array('status'=>404));}
        $log_id=isset($row['id'])?(int)$row['id']:0;
        $status=isset($row['status'])?(string)$row['status']:'';
        $backup_type=isset($row['backup_type'])?(string)$row['backup_type']:'';
        if($status==='deleted'){return array('status'=>'deleted','files_deleted'=>0,'already_deleted'=>true);}
        if(!in_array($backup_type,array('manual','automatic','pre_restore'),true)){return new WP_Error('wsm_backup_delete_type_invalid','نوع بکاپ در گزارش معتبر نیست؛ هیچ فایلی حذف نشد.',array('status'=>409));}
        if(!in_array($status,array('success','missing'),true)){return new WP_Error('wsm_backup_delete_status_invalid','فقط بکاپ موفق یا فایل مفقودشده قابل پاک‌سازی دستی است.',array('status'=>409));}
        $expected='wsm-backup-'.$backup_id.'.wsmbackup';
        if(!$log_id||!isset($row['backup_id'])||(string)$row['backup_id']!==$backup_id||!isset($row['filename'])||(string)$row['filename']!==$expected){
            return new WP_Error('wsm_backup_delete_log_invalid','نام یا شناسهٔ فایل با گزارش بکاپ مطابقت ندارد؛ هیچ فایلی حذف نشد.',array('status'=>409));
        }
        if(!isset($row['storage_path'])||!is_scalar($row['storage_path'])||trim((string)$row['storage_path'])===''){
            self::update_retention_log($log_id,null,'مسیر اصلی بکاپ در گزارش موجود نیست؛ برای ایمنی حذف انجام نشد.');
            return new WP_Error('wsm_backup_delete_primary_path_missing','مسیر اصلی بکاپ در گزارش وجود ندارد؛ هیچ فایلی حذف نشد.',array('status'=>409));
        }
        if(isset($row['external_storage_path'])&&$row['external_storage_path']!==null&&!is_scalar($row['external_storage_path'])){
            return new WP_Error('wsm_backup_delete_external_path_invalid','مسیر خارجی ثبت‌شده معتبر نیست؛ هیچ فایلی حذف نشد.',array('status'=>409));
        }
        $path_entries=array();$failure_message='';
        foreach(array('storage_path'=>'primary','external_storage_path'=>'external') as $key=>$kind){
            if(isset($row[$key])&&is_scalar($row[$key])&&trim((string)$row[$key])!==''){
                $stored_path=untrailingslashit(wp_normalize_path((string)$row[$key]));
                if($stored_path===''){$failure_message='یکی از مسیرهای ثبت‌شده خالی یا نامعتبر بود.';continue;}
                $path_entries[$stored_path]=$kind;
            }
        }
        $files_to_delete=array();
        foreach($path_entries as $storage_path=>$kind){
            if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_backup_lock_lost','قفل عملیات هنگام بررسی مسیرهای بکاپ از دست رفت؛ حذف متوقف شد.',array('status'=>409));}
            $directory=$kind==='external'?self::external_backup_storage_directory($storage_path,false):self::backup_directory_from_log($storage_path);
            if(is_wp_error($directory)){
                if(in_array($directory->get_error_code(),array('wsm_backup_path_missing','wsm_backup_log_path_missing'),true)){continue;}
                $failure_message='یکی از مسیرهای ثبت‌شده برای بکاپ امن یا در دسترس نبود.';continue;
            }
            $path=trailingslashit($directory).$expected;
            if(is_link($path)){$failure_message='یکی از فایل‌های بکاپ پیوند نمادین بود و برای ایمنی حذف نشد.';continue;}
            if(!file_exists($path)){continue;}
            if(!is_file($path)){$failure_message='یکی از مسیرهای بکاپ فایل معمولی نبود و حذف نشد.';continue;}
            $real_directory=realpath($directory);$real_path=realpath($path);
            if($real_directory===false||$real_path===false||strpos(wp_normalize_path($real_path),trailingslashit(wp_normalize_path($real_directory)))!==0||basename($real_path)!==$expected){$failure_message='مسیر یکی از فایل‌های بکاپ از محدودهٔ پوشهٔ امن خارج بود.';continue;}
            $files_to_delete[$real_path]=$path;
        }
        if($failure_message!==''){
            self::update_retention_log($log_id,null,$failure_message);
            return new WP_Error('wsm_backup_delete_path_unsafe','یکی از مسیرها یا فایل‌های ثبت‌شده ایمن نبود؛ هیچ فایلی حذف نشد.',array('status'=>409));
        }
        if(!$files_to_delete){
            if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_backup_lock_lost','قفل عملیات پیش از ثبت حذف بکاپ از دست رفت.',array('status'=>409));}
            if(!self::update_retention_log($log_id,'deleted','')){return new WP_Error('wsm_backup_delete_log_update_failed','فایلی باقی نمانده، اما وضعیت گزارش به‌روزرسانی نشد.',array('status'=>500));}
            return array('status'=>'deleted','files_deleted'=>0,'already_absent'=>true);
        }
        $deleted=0;
        foreach($files_to_delete as $real_path=>$path){
            if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_backup_lock_lost','قفل عملیات هنگام حذف نسخه‌های بکاپ از دست رفت؛ عملیات متوقف شد.',array('status'=>409));}
            if(is_link($path)||!is_file($path)||realpath($path)!==$real_path){
                self::update_retention_log($log_id,null,'مسیر یکی از فایل‌ها هنگام حذف تغییر کرد؛ ادامهٔ حذف برای ایمنی متوقف شد.');
                return new WP_Error('wsm_backup_delete_path_changed','مسیر فایل هنگام حذف تغییر کرد؛ ادامهٔ عملیات متوقف شد.',array('status'=>409));
            }
            if(!@unlink($path)){
                self::update_retention_log($log_id,null,'حذف همهٔ نسخه‌های ثبت‌شده کامل نشد؛ مسیرهای باقی‌مانده دوباره بررسی شوند.');
                return new WP_Error('wsm_backup_delete_unlink_failed','حذف همهٔ نسخه‌های ثبت‌شده کامل نشد؛ گزارش برای تلاش دوباره حفظ شد.',array('status'=>500));
            }
            $deleted++;
        }
        if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_backup_lock_lost','قفل عملیات پس از حذف فایل‌ها از دست رفت؛ گزارش برای بررسی حفظ شد.',array('status'=>409));}
        if(!self::update_retention_log($log_id,'deleted','')){return new WP_Error('wsm_backup_delete_log_update_failed','فایل‌ها حذف شدند، اما ثبت وضعیت حذف در تاریخچه انجام نشد.',array('status'=>500));}
        return array('status'=>'deleted','files_deleted'=>$deleted,'already_absent'=>false);
    }

    private static function update_retention_log($log_id,$status,$message) {
        global $wpdb;
        $data=array('error_message'=>substr(wp_strip_all_tags((string)$message),0,1000));
        $formats=array('%s');
        if($status!==null){$data['status']=(string)$status;$formats[]='%s';}
        return $wpdb->update(self::backup_log_table(),$data,array('id'=>(int)$log_id),$formats,array('%d'))!==false;
    }

    /** Retain the newest N successful automatic backups across primary and external copies. */
    private static function cleanup_automatic_backups($keep) {
        global $wpdb;
        $ready=self::ensure_backup_log_table();
        if(is_wp_error($ready)){return $ready;}
        if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_backup_lock_lost','قفل بکاپ پیش از شروع پاک‌سازی از دست رفت.',array('status'=>409));}
        $keep=max(1,min(100,(int)$keep));
        $table='`'.str_replace('`','``',self::backup_log_table()).'`';
        $sql=$wpdb->prepare("SELECT id,backup_id,filename,storage_path,external_storage_path FROM $table WHERE backup_type=%s AND status=%s ORDER BY created_at DESC,id DESC",'automatic','success');
        $rows=$wpdb->get_results($sql,ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error!==''){return new WP_Error('wsm_backup_retention_query_failed','خواندن گزارش‌ها برای نگهداری بکاپ انجام نشد.',array('status'=>500));}
        if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_backup_lock_lost','قفل بکاپ هنگام خواندن گزارش‌های نگهداری از دست رفت.',array('status'=>409));}
        $stats=array('deleted'=>0,'missing'=>0,'errors'=>0);
        foreach(array_slice($rows,$keep) as $row){
            if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_backup_lock_lost','قفل بکاپ هنگام پاک‌سازی قدیمی‌ترین نسخه‌ها از دست رفت؛ پاک‌سازی متوقف شد.',array('status'=>409));}
            $log_id=isset($row['id'])?(int)$row['id']:0;
            $id=isset($row['backup_id'])?(string)$row['backup_id']:'';
            $expected='wsm-backup-'.$id.'.wsmbackup';
            if(!$log_id||!preg_match('/^[a-f0-9]{32}$/',$id)||!isset($row['filename'])||(string)$row['filename']!==$expected){
                $stats['errors']++;
                if($log_id){self::update_retention_log($log_id,null,'نام یا شناسهٔ بکاپ برای پاک‌سازی معتبر نیست.');}
                continue;
            }
            if(!isset($row['storage_path'])||!is_scalar($row['storage_path'])||trim((string)$row['storage_path'])===''){
                $stats['errors']++;
                self::update_retention_log($log_id,null,'مسیر اصلی بکاپ در گزارش موجود نیست؛ برای ایمنی پاک‌سازی انجام نشد.');
                continue;
            }
            $path_entries=array();
            foreach(array('storage_path'=>'primary','external_storage_path'=>'external') as $key=>$kind){
                if(isset($row[$key])&&is_scalar($row[$key])&&trim((string)$row[$key])!==''){
                    $stored_path=untrailingslashit(wp_normalize_path((string)$row[$key]));
                    if($stored_path!==''){$path_entries[$stored_path]=$kind;}
                }
            }
            $files_to_delete=array();$failure_message='';
            foreach($path_entries as $storage_path=>$kind){
                $directory=$kind==='external'?self::external_backup_storage_directory($storage_path,false):self::backup_directory_from_log($storage_path);
                if(is_wp_error($directory)){if($directory->get_error_code()==='wsm_backup_path_missing'){continue;}$failure_message='یکی از مسیرهای ثبت‌شده برای بکاپ قدیمی امن یا در دسترس نبود.';continue;}
                $path=trailingslashit($directory).$expected;
                if(is_link($path)){$failure_message='یکی از فایل‌های بکاپ پیوند نمادین بود و برای ایمنی حذف نشد.';continue;}
                if(!file_exists($path)){continue;}
                if(!is_file($path)){$failure_message='یکی از مسیرهای بکاپ فایل معمولی نبود و حذف نشد.';continue;}
                $real_directory=realpath($directory);$real_path=realpath($path);
                if($real_directory===false||$real_path===false||strpos(wp_normalize_path($real_path),trailingslashit(wp_normalize_path($real_directory)))!==0||basename($real_path)!==$expected){$failure_message='مسیر یکی از فایل‌های بکاپ قدیمی از محدودهٔ پوشهٔ امن خارج بود.';continue;}
                $files_to_delete[]=$real_path;
            }
            if($failure_message!==''){$stats['errors']++;self::update_retention_log($log_id,null,$failure_message);continue;}
            $files_to_delete=array_values(array_unique($files_to_delete));
            if(!$files_to_delete){
                $stats['missing']++;
                if(!self::update_retention_log($log_id,'missing','هیچ نسخه‌ای از فایل بکاپ در مسیرهای ثبت‌شده پیدا نشد.')){$stats['errors']++;}
                continue;
            }
            $deleted_all=true;
            foreach($files_to_delete as $file_path){if(!@unlink($file_path)){$deleted_all=false;break;}}
            if(!$deleted_all){$stats['errors']++;self::update_retention_log($log_id,null,'حذف همهٔ نسخه‌های بکاپ قدیمی کامل نشد؛ عملیات در اجرای بعدی دوباره بررسی می‌شود.');continue;}
            if(!self::update_retention_log($log_id,'deleted','')){$stats['errors']++;}else{$stats['deleted']++;}
        }
        return $stats;
    }

    /** Email an automatic backup result to the configured recipient; never include filesystem paths. */
    private static function send_backup_notification($success,$context) {
        $settings=self::get_backup_configuration();
        if(empty($settings['email_notifications'])){return false;}
        $recipient=isset($settings['email_recipient'])&&is_scalar($settings['email_recipient'])?sanitize_email((string)$settings['email_recipient']):'';
        if(!is_email($recipient)){$recipient=sanitize_email((string)get_option('admin_email',''));}
        if(!is_email($recipient)){return false;}
        $site=sanitize_text_field(wp_specialchars_decode((string)get_bloginfo('name'),ENT_QUOTES));
        if($site===''){$site='WSM';}
        if($success){
            $context=is_array($context)?$context:array();
            $subject='['.$site.'] بکاپ خودکار تکمیل شد';
            $metadata=isset($context['metadata'])&&is_array($context['metadata'])?$context['metadata']:array();
            $bytes=isset($context['size_bytes'])?max(0,(int)$context['size_bytes']):0;
            $filename=isset($context['filename'])&&is_string($context['filename'])&&preg_match('/^wsm-backup-[a-f0-9]{32}\.wsmbackup$/D',$context['filename'])?$context['filename']:'—';
            $created_at=isset($metadata['created_at'])&&is_scalar($metadata['created_at'])?sanitize_text_field((string)$metadata['created_at']):'—';
            $file_checksum=isset($context['file_checksum'])&&is_scalar($context['file_checksum'])?(string)$context['file_checksum']:'';
            $content_checksum=isset($context['checksum'])&&is_scalar($context['checksum'])?(string)$context['checksum']:'';
            $file_checksum=preg_match('/^[a-f0-9]{64}$/i',$file_checksum)?strtolower($file_checksum):'';
            $content_checksum=preg_match('/^[a-f0-9]{64}$/i',$content_checksum)?strtolower($content_checksum):'';
            $body="بکاپ خودکار مدیر فروشگاه با موفقیت ساخته شد.\n\n";
            $body.='نام فایل: '.$filename."\n";
            $body.='زمان: '.$created_at."\n";
            $body.='اندازه: '.(function_exists('size_format')?size_format($bytes):$bytes.' bytes')."\n";
            $body.='تعداد جدول‌ها: '.(isset($metadata['table_count'])?(int)$metadata['table_count']:0)."\n";
            $body.='تعداد ردیف‌ها: '.(isset($metadata['record_count'])?(int)$metadata['record_count']:0)."\n";
            if($file_checksum!==''){$body.='SHA-256 فایل (برای بررسی فایل): '.$file_checksum."\n";}
            if($content_checksum!==''){$body.='SHA-256 محتوای بکاپ: '.$content_checksum."\n";}
            if(isset($context['retention_summary'])&&is_array($context['retention_summary'])){
                $body.='پاک‌سازی بکاپ‌های خودکار قدیمی: '.(int)($context['retention_summary']['deleted']??0).' حذف، '.(int)($context['retention_summary']['missing']??0).' مفقود، '.(int)($context['retention_summary']['errors']??0).' خطا.' ."\n";
            }
            if(isset($context['retention_warning'])&&is_scalar($context['retention_warning'])&&trim((string)$context['retention_warning'])!==''){
                $warning=wp_strip_all_tags((string)$context['retention_warning']);
                if(strpos($warning,'/')!==false||strpos($warning,chr(92))!==false){$warning='جزئیات خطا در تاریخچهٔ بکاپ ثبت شده است.';}
                $body.="\nهشدار نگهداری: ".$warning."\n";
            }
        }else{
            $subject='['.$site.'] بکاپ خودکار ناموفق بود';
            $message=is_wp_error($context)?$context->get_error_message():'خطای نامشخص در تهیهٔ بکاپ خودکار.';
            $message=wp_strip_all_tags((string)$message);
            if(strpos($message,'/')!==false||strpos($message,chr(92))!==false){$message='جزئیات خطا در تاریخچهٔ بکاپ ثبت شده است؛ مسیرهای محلی در ایمیل نمایش داده نمی‌شوند.';}
            if($message===''){$message='خطای نامشخص در تهیهٔ بکاپ خودکار.';}
            $body="تهیهٔ بکاپ خودکار مدیر فروشگاه با خطا متوقف شد.\n\n".'جزئیات: '.$message."\n";
        }
        return (bool)wp_mail($recipient,$subject,$body,array('Content-Type: text/plain; charset=UTF-8'));
    }

    /** Custom recurrence names used by the exact wsm_scheduled_backup hook. */
    public static function backup_cron_schedules($schedules) {
        $schedules=is_array($schedules)?$schedules:array();
        if(!isset($schedules['wsm_backup_daily'])){$schedules['wsm_backup_daily']=array('interval'=>DAY_IN_SECONDS,'display'=>'WSM daily backup');}
        if(!isset($schedules['wsm_backup_weekly'])){$schedules['wsm_backup_weekly']=array('interval'=>WEEK_IN_SECONDS,'display'=>'WSM weekly backup');}
        if(!isset($schedules['wsm_backup_monthly'])){$schedules['wsm_backup_monthly']=array('interval'=>30*DAY_IN_SECONDS,'display'=>'WSM monthly backup');}
        return $schedules;
    }

    private static function backup_cron_recurrence($schedule) {
        $map=array('daily'=>'wsm_backup_daily','weekly'=>'wsm_backup_weekly','monthly'=>'wsm_backup_monthly');
        return isset($map[$schedule])?$map[$schedule]:'';
    }

    /** Clear the exact plugin hook and surface Cron-array failures instead of silently duplicating events. */
    private static function clear_backup_schedule_events() {
        $cleared=wp_clear_scheduled_hook(self::BACKUP_CRON_HOOK,array(),true);
        if(is_wp_error($cleared)){return $cleared;}
        if($cleared===false){return new WP_Error('wsm_backup_schedule_clear_failed','پاک‌کردن رویدادهای قبلی بکاپ در WP-Cron ناموفق بود.',array('status'=>500));}
        return true;
    }

    /** Keep the single scheduled event aligned with the stored backup settings. */
    public static function sync_backup_schedule() {
        try {
            if(empty(self::get_backup_configuration()['auto_enabled'])){
                $cleared=self::clear_backup_schedule_events();
                if(is_wp_error($cleared)){return $cleared;}
                if(wp_next_scheduled(self::BACKUP_CRON_HOOK)){return new WP_Error('wsm_backup_schedule_clear_verify_failed','رویداد بکاپ پس از غیرفعال‌سازی همچنان در WP-Cron باقی است.',array('status'=>500));}
                return true;
            }
            $ready=self::ensure_backup_log_table();
            if(is_wp_error($ready)){return $ready;}
            $settings=self::get_backup_configuration();
            $recurrence=self::backup_cron_recurrence(isset($settings['schedule'])?(string)$settings['schedule']:'');
            if($recurrence===''){return new WP_Error('wsm_backup_schedule_invalid','زمان‌بندی بکاپ خودکار معتبر نیست.',array('status'=>400));}
            $next=wp_next_scheduled(self::BACKUP_CRON_HOOK);
            $current=function_exists('wp_get_schedule')?wp_get_schedule(self::BACKUP_CRON_HOOK):false;
            if($next&&$current===$recurrence){return true;}
            $cleared=self::clear_backup_schedule_events();
            if(is_wp_error($cleared)){return $cleared;}
            if(wp_next_scheduled(self::BACKUP_CRON_HOOK)){return new WP_Error('wsm_backup_schedule_clear_verify_failed','رویداد قبلی بکاپ پیش از زمان‌بندی تازه حذف نشد.',array('status'=>500));}
            $scheduled=wp_schedule_event(time()+5*MINUTE_IN_SECONDS,$recurrence,self::BACKUP_CRON_HOOK,array(),true);
            if(is_wp_error($scheduled)){return $scheduled;}
            if(!$scheduled){return new WP_Error('wsm_backup_schedule_failed','زمان‌بندی بکاپ خودکار در WP-Cron انجام نشد.',array('status'=>500));}
            $verified_next=wp_next_scheduled(self::BACKUP_CRON_HOOK);
            $verified_schedule=function_exists('wp_get_schedule')?wp_get_schedule(self::BACKUP_CRON_HOOK):$recurrence;
            if(!$verified_next||$verified_schedule!==$recurrence){return new WP_Error('wsm_backup_schedule_verify_failed','رویداد زمان‌بندی بکاپ پس از ثبت در WP-Cron تأیید نشد.',array('status'=>500));}
            return true;
        } catch (Throwable $e) {
            error_log('[WSM Club] WSM_Club_Operations::sync_backup_schedule failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new WP_Error('wsm_backup_schedule_exception','به‌روزرسانی زمان‌بندی بکاپ با خطا روبه‌رو شد.',array('status'=>500));
        }
    }

    /** Acquire a tokenized, owner-released lease for a scheduled backup callback. */
    private static function acquire_scheduled_backup_lock() {
        return self::acquire_backup_lock_option('wsm_backup_cron_lock',2*HOUR_IN_SECONDS);
    }

    /** WP-Cron callback: hold both backup-operation and cron leases through retention and notification. */
    public static function run_scheduled_backup() {
        $settings=self::get_backup_configuration();
        if(empty($settings['auto_enabled'])){
            $cleared=self::clear_backup_schedule_events();
            if(is_wp_error($cleared)){return $cleared;}
            return false;
        }
        if(self::backup_restore_lock_is_active()){return false;}
        $operation_lock=self::acquire_backup_operation_lock();
        if(is_wp_error($operation_lock)||!$operation_lock){return false;}
        self::$active_backup_operation_lock_token=$operation_lock;
        $cron_lock=self::acquire_scheduled_backup_lock();
        if(is_wp_error($cron_lock)||!$cron_lock){
            self::$active_backup_operation_lock_token='';
            self::release_backup_lock_option('wsm_backup_operation_lock',$operation_lock);
            return false;
        }
        try{
            if(!self::renew_active_backup_lock_lease()){return false;}
            if(self::backup_restore_lock_is_active()){return false;}
            $created=self::create_backup_file('automatic');
            if(is_wp_error($created)){
                if(!empty($settings['email_notifications'])){self::send_backup_notification(false,$created);}
                return $created;
            }
            $retention=self::cleanup_automatic_backups(isset($settings['retention'])?(int)$settings['retention']:5);
            if(is_wp_error($retention)){
                $created['retention_warning']=$retention->get_error_message();
                self::append_backup_log_message($created['id'],'نگهداری خودکار بکاپ‌ها انجام نشد: '.$retention->get_error_message());
            }else{
                $created['retention_summary']=$retention;
                if(!empty($retention['errors'])){
                    $created['retention_warning']='برخی بکاپ‌های قدیمی حذف نشدند؛ جزئیات در گزارش نگهداری ثبت شده است.';
                    self::append_backup_log_message($created['id'],$created['retention_warning']);
                }
            }
            if(!empty($settings['email_notifications'])&&!self::send_backup_notification(true,$created)){
                self::append_backup_log_message($created['id'],'بکاپ موفق بود، اما ارسال اعلان ایمیلی ناموفق شد.');
            }
            return $created;
        }finally{
            self::$active_backup_operation_lock_token='';
            self::release_backup_lock_option('wsm_backup_operation_lock',$operation_lock);
            self::release_backup_lock_option('wsm_backup_cron_lock',$cron_lock);
        }
    }

    /** Length-prefix serialized state while building an opaque, streaming Smart Restore fingerprint. */
    private static function backup_restore_plan_hash_add($context,$value) {
        try{$serialized=serialize($value);}catch(Throwable $e){return false;}
        if(!is_string($serialized)||strlen($serialized)>4294967295){return false;}
        return hash_update($context,pack('N',strlen($serialized)).$serialized);
    }

    /** Stable opaque comparison token; primary-key values are never returned to the browser. */
    private static function backup_preview_key_token($row,$primary_key) {
        if(!is_array($row)||!is_array($primary_key)||!$primary_key){return false;}
        $values=array();$sync_uuid_primary=$primary_key===array('offline_uuid');
        foreach($primary_key as $key){
            if(!array_key_exists($key,$row)||$row[$key]===null||(!is_scalar($row[$key])&&!is_bool($row[$key]))){return false;}
            $value=$row[$key];
            if($sync_uuid_primary){
                if($key!=='offline_uuid'||!is_string($value)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value)){return false;}
                $values[]=$value;
                continue;
            }
            if(is_bool($value)){$value=$value?'1':'0';}
            elseif(is_int($value)){$value=(string)$value;}
            elseif(is_string($value)){
                if(!preg_match('/^[+-]?[0-9]+$/D',$value)){return false;}
                $negative=isset($value[0])&&$value[0]==='-';
                if(isset($value[0])&&($value[0]==='-'||$value[0]==='+')){$value=substr($value,1);}
                $value=ltrim($value,'0');if($value===''){$value='0';}
                if($negative&&$value!=='0'){$value='-'.$value;}
            }else{return false;}
            $values[]=$value;
        }
        $encoded=wp_json_encode($values,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($encoded)?hash('sha256',$encoded):false;
    }

    private static function backup_preview_values_equal($left,$right) {
        if($left===null||$right===null){return $left===null&&$right===null;}
        $normalize=function($value){
            if(is_bool($value)){return $value?'1':'0';}
            if(is_int($value)){return (string)$value;}
            if(is_float($value)){return sprintf('%.17g',$value);}
            return is_string($value)?$value:(string)$value;
        };
        return $normalize($left)===$normalize($right);
    }

    /** Read matching current rows in bounded, parameterized batches; this method performs no writes. */
    private static function backup_preview_load_live_rows($quoted_table,$primary_key,$source_rows) {
        global $wpdb;
        if(!$source_rows){return array();}
        $args=array();$clauses=array();
        if(count($primary_key)===1){
            $column='`'.str_replace('`','``',$primary_key[0]).'`';
            $placeholders=array();
            foreach($source_rows as $row){
                if(!array_key_exists($primary_key[0],$row)||$row[$primary_key[0]]===null){return new WP_Error('wsm_preview_key_invalid','کلید اصلی یکی از ردیف‌های بکاپ معتبر نیست.',array('status'=>400));}
                $value=$row[$primary_key[0]];
                $args[]=is_bool($value)?($value?'1':'0'):(string)$value;
                $placeholders[]='%s';
            }
            $where=$column.' IN ('.implode(',',$placeholders).')';
        }else{
            foreach($source_rows as $row){
                $parts=array();
                foreach($primary_key as $key){
                    if(!array_key_exists($key,$row)||$row[$key]===null){return new WP_Error('wsm_preview_key_invalid','کلید اصلی یکی از ردیف‌های بکاپ معتبر نیست.',array('status'=>400));}
                    $parts[]='`'.str_replace('`','``',$key).'` = %s';
                    $value=$row[$key];$args[]=is_bool($value)?($value?'1':'0'):(string)$value;
                }
                $clauses[]='('.implode(' AND ',$parts).')';
            }
            $where=implode(' OR ',$clauses);
        }
        $query="SELECT * FROM $quoted_table WHERE $where";
        $prepared=$wpdb->prepare($query,$args);
        $live_rows=$wpdb->get_results($prepared,ARRAY_A);
        if(!is_array($live_rows)||$wpdb->last_error!==''){return new WP_Error('wsm_preview_rows_query_failed','خواندن ردیف‌های فعلی برای پیش‌نمایش انجام نشد.',array('status'=>500));}
        $map=array();
        foreach($live_rows as $live_row){
            $token=self::backup_preview_key_token($live_row,$primary_key);
            if($token===false){return new WP_Error('wsm_preview_live_key_invalid','کلید اصلی یکی از ردیف‌های فعلی معتبر نیست.',array('status'=>500));}
            $map[$token]=$live_row;
        }
        return $map;
    }

    /** Walk current table rows in primary-key order, with bounded memory and a callback per row. */
    private static function backup_restore_walk_live_rows($quoted_table,$primary_key,$callback,$batch_size=50,$for_update=false) {
        global $wpdb;
        if(!is_string($quoted_table)||$quoted_table===''||!is_array($primary_key)||!$primary_key||!is_callable($callback)){
            return new WP_Error('wsm_restore_table_walk_invalid','درخواست خواندن ردیف‌های جدول برای بازیابی معتبر نیست.',array('status'=>500));
        }
        $batch_size=max(10,min(200,(int)$batch_size));$last=null;$order=array();
        foreach($primary_key as $column){if(!is_string($column)||!preg_match('/^[A-Za-z0-9_]+$/D',$column)){return new WP_Error('wsm_restore_table_walk_key_invalid','نام یکی از ستون‌های کلید اصلی برای بازیابی معتبر نیست.',array('status'=>500));}$order[]='`'.str_replace('`','``',$column).'`';}
        $order_sql=implode(',',$order);
        while(true){
            if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_restore_lock_lost','قفل عملیات بکاپ یا بازیابی هنگام خواندن جدول از دست رفت.',array('status'=>409));}
            $arguments=array();$conditions=array();
            if(is_array($last)){
                foreach($primary_key as $index=>$column){
                    $parts=array();
                    for($prior=0;$prior<$index;$prior++){$prior_column=$primary_key[$prior];$parts[]='`'.str_replace('`','``',$prior_column).'` = %s';$arguments[]=is_bool($last[$prior_column])?($last[$prior_column]?'1':'0'):(string)$last[$prior_column];}
                    $parts[]='`'.str_replace('`','``',$column).'` > %s';$arguments[]=is_bool($last[$column])?($last[$column]?'1':'0'):(string)$last[$column];
                    $conditions[]='('.implode(' AND ',$parts).')';
                }
            }
            $use_for_update=!empty($for_update)&&self::$active_backup_restore_lock_token!=='';
            $query='SELECT * FROM '.$quoted_table.($conditions?' WHERE '.implode(' OR ',$conditions):'').' ORDER BY '.$order_sql.' LIMIT %d'.($use_for_update?' FOR UPDATE':'');
            $arguments[]=$batch_size;$prepared=$wpdb->prepare($query,$arguments);$rows=$wpdb->get_results($prepared,ARRAY_A);
            if(!is_array($rows)||$wpdb->last_error!==''){return new WP_Error('wsm_restore_table_walk_query_failed','خواندن دسته‌ای ردیف‌های جدول برای بازیابی انجام نشد.',array('status'=>500));}
            if(!$rows){return true;}
            foreach($rows as $row){
                $token=self::backup_preview_key_token($row,$primary_key);
                if($token===false){return new WP_Error('wsm_restore_table_walk_key_invalid','کلید اصلی یکی از ردیف‌های مقصد برای بازیابی معتبر نیست.',array('status'=>500));}
                $result=call_user_func($callback,$row,$token);
                if(is_wp_error($result)){return $result;}
                if($result===false){return new WP_Error('wsm_restore_table_walk_callback_failed','پردازش یکی از ردیف‌های جدول برای بازیابی متوقف شد.',array('status'=>500));}
            }
            $last=end($rows);if(!is_array($last)){return new WP_Error('wsm_restore_table_walk_cursor_invalid','نشانگر خواندن ردیف‌های جدول معتبر نیست.',array('status'=>500));}
            foreach($primary_key as $column){if(!array_key_exists($column,$last)){return new WP_Error('wsm_restore_table_walk_cursor_invalid','ستون کلید اصلی در دستهٔ خوانده‌شده پیدا نشد.',array('status'=>500));}}
            if(count($rows)<$batch_size){return true;}
        }
    }

    /** Compare one selected table with the live database; no rows are inserted, updated, or deleted. */
    private static function preview_backup_table_merge($table_data) {
        global $wpdb;
        $name=$table_data['name'];$source_rows=$table_data['rows'];$source_count=count($source_rows);
        $summary=array(
            'name'=>$name,'status'=>'ready','source_rows'=>$source_count,'inserts'=>0,'updates'=>0,
            'unchanged'=>0,'conflicts'=>0,'deletes'=>0,'missing_target_columns'=>array(),
            'required_insert_columns'=>array(),'changed_column_counts'=>array(),'warnings'=>array(),
        );
        if(empty($wpdb->prefix)||!preg_match('/^[A-Za-z0-9_]+$/',(string)$wpdb->prefix)){
            return new WP_Error('wsm_preview_db_prefix_invalid','پیشوند دیتابیس برای پیش‌نمایش معتبر نیست.',array('status'=>500));
        }
        $physical=(string)$wpdb->prefix.$name;
        $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($physical)));
        if($wpdb->last_error!==''){return new WP_Error('wsm_preview_table_check_failed','بررسی وجود جدول '.$name.' انجام نشد.',array('status'=>500));}
        if($found!==$physical){
            $summary['status']='missing';$summary['conflicts']=$source_count;
            $summary['warnings'][]='جدول متناظر در پایگاه دادهٔ فعلی وجود ندارد.';
            return $summary;
        }
        $quoted='`'.str_replace('`','``',$physical).'`';
        $table_status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s',$physical),ARRAY_A);
        if(!is_array($table_status)||!isset($table_status['Name'])||(string)$table_status['Name']!==$physical||$wpdb->last_error!==''){return new WP_Error('wsm_preview_table_status_failed','خواندن وضعیت و موتور جدول '.$name.' انجام نشد.',array('status'=>500));}
        $table_engine=isset($table_status['Engine'])?strtolower((string)$table_status['Engine']):'';
        if($table_engine===''){$summary['status']='incompatible';$summary['conflicts']=max(1,$source_count);$summary['warnings'][]='جدول مقصد از نوع جدول پایهٔ قابل‌بازیابی نیست.';return $summary;}
        $schema=$wpdb->get_results("SHOW COLUMNS FROM $quoted",ARRAY_A);
        if(!is_array($schema)||$wpdb->last_error!==''){return new WP_Error('wsm_preview_schema_query_failed','خواندن ساختار جدول '.$name.' انجام نشد.',array('status'=>500));}
        $live_columns=array();$schema_by_name=array();
        foreach($schema as $column){
            if(!is_array($column)||empty($column['Field'])){continue;}
            $field=(string)$column['Field'];$live_columns[]=$field;$schema_by_name[$field]=$column;
        }
        $archive_columns=$table_data['columns'];
        $missing=array_values(array_diff($archive_columns,$live_columns));
        if($missing){$summary['missing_target_columns']=$missing;}
        $all_keys=$wpdb->get_results("SHOW KEYS FROM $quoted",ARRAY_A);
        if(!is_array($all_keys)||$wpdb->last_error!==''){return new WP_Error('wsm_preview_primary_query_failed','خواندن کلید اصلی جدول '.$name.' انجام نشد.',array('status'=>500));}
        $key_rows=array();
        foreach($all_keys as $key_row){if(isset($key_row['Key_name'])&&strtoupper((string)$key_row['Key_name'])==='PRIMARY'){$key_rows[]=$key_row;}}
        usort($key_rows,function($left,$right){return (int)($left['Seq_in_index']??0)<=>(int)($right['Seq_in_index']??0);});
        $live_primary=array();
        foreach($key_rows as $key_row){if(isset($key_row['Column_name'])){$live_primary[]=(string)$key_row['Column_name'];}}
        $source_primary=$table_data['primary_key'];
        if($missing||!$source_primary||$source_primary!==$live_primary){
            $summary['status']='incompatible';$summary['conflicts']=$source_count;
            if(!$source_primary||$source_primary!==$live_primary){$summary['warnings'][]='کلید اصلی آرشیو با جدول فعلی سازگار نیست.';}
            if($missing){$summary['warnings'][]='یک یا چند ستون آرشیو در جدول فعلی وجود ندارد.';}
            return $summary;
        }
        foreach($source_primary as $key){
            $type=isset($schema_by_name[$key]['Type'])?(string)$schema_by_name[$key]['Type']:'';
            $integer_type=preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)\b/i',$type);
            $sync_uuid_type=$name==='wsm_sync_member_map'&&$source_primary===array('offline_uuid')&&$key==='offline_uuid'&&preg_match('/^varchar\(36\)$/i',$type);
            if(!$integer_type&&!$sync_uuid_type){
                $summary['status']='incompatible';$summary['conflicts']=$source_count;
                $summary['warnings'][]='نوع دادهٔ کلید اصلی پشتیبانی نمی‌شود؛ فقط offline_uuid از نوع varchar(36) در جدول نگاشت عضو نیز مجاز است.';
                return $summary;
            }
        }
        $source_column_set=array_fill_keys($archive_columns,true);$preview_write_columns=array();$preview_insert_columns=array();
        foreach($archive_columns as $column){
            $extra=isset($schema_by_name[$column]['Extra'])?strtolower((string)$schema_by_name[$column]['Extra']):'';
            if(strpos($extra,'generated')===false){
                $preview_insert_columns[]=$column;
                if(strtolower((string)$column)!=='guid'){$preview_write_columns[]=$column;}
            }
        }
        foreach($schema_by_name as $field=>$column){
            if(isset($source_column_set[$field])){continue;}
            $nullable=isset($column['Null'])&&strtoupper((string)$column['Null'])==='YES';
            $has_default=array_key_exists('Default',$column)&&$column['Default']!==null;
            $extra=isset($column['Extra'])?strtolower((string)$column['Extra']):'';
            $generated=(strpos($extra,'auto_increment')!==false||strpos($extra,'generated')!==false);
            if(!$nullable&&!$has_default&&!$generated){$summary['required_insert_columns'][]=$field;}
        }
        if(!$preview_write_columns&&!$preview_insert_columns&&$source_count){$summary['status']='incompatible';$summary['conflicts']=$source_count;$summary['warnings'][]='هیچ ستونی برای درج یا به‌روزرسانی از آرشیو قابل‌نوشتن نیست.';return $summary;}
        $plan_context=hash_init('sha256');
        $table_plan=array('table'=>$name,'columns'=>$archive_columns,'write_columns'=>$preview_write_columns,'primary_key'=>$source_primary,'target_schema'=>$schema,'table_engine'=>$table_engine,'insert_columns'=>$preview_insert_columns,'required_insert_columns'=>$summary['required_insert_columns'],'source_rows'=>$source_count);
        if(!self::backup_restore_plan_hash_add($plan_context,$table_plan)){return new WP_Error('wsm_restore_plan_fingerprint_failed','ساخت اثرانگشت برنامهٔ بازیابی ناموفق بود.',array('status'=>500));}
        $source_rows_by_token=array();$changed_columns=array();
        foreach($source_rows as $row_index=>$row){
            $token=self::backup_preview_key_token($row,$source_primary);
            if($token===false){$summary['conflicts']++;$summary['warnings'][]='یکی از ردیف‌های آرشیو کلید اصلی معتبر ندارد.';continue;}
            if(isset($source_rows_by_token[$token])){$summary['conflicts']++;$summary['warnings'][]='ردیف‌های آرشیو دارای کلید اصلی تکراری‌اند.';continue;}
            $source_rows_by_token[$token]=$row_index;
        }
        $found_source_keys=array();
        $live_scan=self::backup_restore_walk_live_rows($quoted,$source_primary,function($live_row,$token) use (&$source_rows_by_token,&$source_rows,&$found_source_keys,&$summary,&$changed_columns,&$plan_context,$preview_write_columns){
            if(isset($source_rows_by_token[$token])){
                if(isset($found_source_keys[$token])){return new WP_Error('wsm_preview_live_key_duplicate','کلید اصلی در جدول مقصد برای پیش‌نمایش تکراری است.',array('status'=>500));}
                $found_source_keys[$token]=true;
                $source_row=$source_rows[$source_rows_by_token[$token]];$live_projection=array();
                foreach($preview_write_columns as $column){$live_projection[$column]=array_key_exists($column,$live_row)?$live_row[$column]:null;}
                if(!self::backup_restore_plan_hash_add($plan_context,array('key'=>$token,'exists'=>true,'live_values'=>$live_projection))){return new WP_Error('wsm_restore_plan_fingerprint_failed','ساخت اثرانگشت برنامهٔ بازیابی ناموفق بود.',array('status'=>500));}
                $changed=array();
                foreach($preview_write_columns as $column){
                    $current_value=array_key_exists($column,$live_row)?$live_row[$column]:null;
                    if(!self::backup_preview_values_equal($source_row[$column],$current_value)){$changed[]=$column;}
                }
                if(!$changed){$summary['unchanged']++;}
                else{
                    $summary['updates']++;
                    foreach($changed as $column){if(!isset($changed_columns[$column])){$changed_columns[$column]=0;}$changed_columns[$column]++;}
                }
            }else{
                if(!self::backup_restore_plan_hash_add($plan_context,array('key'=>$token,'delete_row'=>$live_row))){return new WP_Error('wsm_restore_plan_fingerprint_failed','ثبت وضعیت ردیف‌های اضافی برای پیش‌نمایش بازیابی ناموفق بود.',array('status'=>500));}
                $summary['deletes']++;
            }
            return true;
        },50,self::$active_backup_restore_lock_token!=='');
        if(is_wp_error($live_scan)){return $live_scan;}
        foreach($source_rows_by_token as $token=>$source_index){
            if(isset($found_source_keys[$token])){continue;}
            if(!self::backup_restore_plan_hash_add($plan_context,array('key'=>$token,'exists'=>false))){return new WP_Error('wsm_restore_plan_fingerprint_failed','ساخت اثرانگشت برنامهٔ بازیابی ناموفق بود.',array('status'=>500));}
            if($summary['required_insert_columns']){$summary['conflicts']++;}
            else{$summary['inserts']++;}
        }
        if($summary['deletes']>0&&$table_engine!=='innodb'){
            $summary['status']='incompatible';$summary['conflicts']++;
            $summary['warnings'][]='حذف دقیق ردیف‌های اضافی فقط در جدول تراکنشی InnoDB مجاز است؛ موتور فعلی این شرط را ندارد.';
        }
        ksort($changed_columns,SORT_STRING);$summary['changed_column_counts']=$changed_columns;
        if($summary['required_insert_columns']){$summary['warnings'][]='ستون‌های اجباریِ فعلی که در آرشیو نیستند، درج ردیف جدید را مسدود می‌کنند.';}
        if($summary['conflicts']&&$summary['status']==='ready'){$summary['status']='partial';}
        $summary['_plan_fingerprint']=hash_final($plan_context);
        return $summary;
    }

    private static function backup_preview_json_equal($left,$right) {
        $left_json=wp_json_encode($left,self::backup_json_flags());
        $right_json=wp_json_encode($right,self::backup_json_flags());
        return is_string($left_json)&&is_string($right_json)&&$left_json===$right_json;
    }

    /** Build a read-only full-table replacement plan from a validated document and explicit section selection. */
    public static function preview_backup_restore($document,$selection) {
        $selected=self::select_backup_document_sections($document,$selection);
        if(is_wp_error($selected)){return $selected;}
        if(array_key_exists('backup_configuration',$selected['plugin_settings'])){return new WP_Error('wsm_restore_backup_configuration_disabled','تنظیمات مسیر، زمان‌بندی و اعلان بکاپ در پیش‌نمایش یا بازیابی گنجانده نمی‌شوند.',array('status'=>400));}
        $plan_context=hash_init('sha256');
        if(!self::backup_restore_plan_hash_add($plan_context,array('selection'=>$selected['selection']))){return new WP_Error('wsm_restore_plan_fingerprint_failed','ساخت اثرانگشت برنامهٔ بازیابی ناموفق بود.',array('status'=>500));}
        $tables=array();
        $summary=array('table_count'=>count($selected['database_tables']),'source_rows'=>0,'inserts'=>0,'updates'=>0,'unchanged'=>0,'conflicts'=>0,'deletes'=>0);
        foreach($selected['database_tables'] as $table_data){
            if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_restore_lock_lost','قفل بازیابی هنگام ساخت پیش‌نمایش از دست رفت.',array('status'=>409));}
            $table_preview=self::preview_backup_table_merge($table_data);
            if(is_wp_error($table_preview)){return $table_preview;}
            $table_fingerprint=isset($table_preview['_plan_fingerprint'])?(string)$table_preview['_plan_fingerprint']:'';
            unset($table_preview['_plan_fingerprint']);
            if(!preg_match('/^[a-f0-9]{64}$/',$table_fingerprint)){$table_fingerprint=hash('sha256',serialize($table_preview));}
            if(!self::backup_restore_plan_hash_add($plan_context,array('table'=>$table_data['name'],'fingerprint'=>$table_fingerprint))){return new WP_Error('wsm_restore_plan_fingerprint_failed','ساخت اثرانگشت برنامهٔ بازیابی ناموفق بود.',array('status'=>500));}
            $tables[]=$table_preview;
            foreach(array('source_rows','inserts','updates','unchanged','conflicts','deletes') as $key){$summary[$key]+=(int)$table_preview[$key];}
        }
        $option_preview=array();$option_counts=array('new'=>0,'changed'=>0,'unchanged'=>0,'conflicts'=>0);
        $missing_marker=new stdClass();
        foreach($selected['plugin_settings']['options'] as $name=>$source_value){
            $current=get_option($name,$missing_marker);
            if($current===$missing_marker){
                $status='new';$option_state=array('name'=>$name,'exists'=>false);
            }else{
                $clean=self::filter_backup_setting_value($current);
                if(is_wp_error($clean)){$status='conflict';$option_state=array('name'=>$name,'exists'=>true,'valid'=>false,'error'=>$clean->get_error_code());}
                else{$status=self::backup_preview_json_equal($source_value,$clean)?'unchanged':'changed';$option_state=array('name'=>$name,'exists'=>true,'value'=>$clean);}
            }
            if(!self::backup_restore_plan_hash_add($plan_context,$option_state)){return new WP_Error('wsm_restore_plan_fingerprint_failed','ساخت اثرانگشت برنامهٔ بازیابی ناموفق بود.',array('status'=>500));}
            $option_counts[$status]++;
            $option_preview[]=array('name'=>$name,'status'=>$status);
        }
        $backup_configuration=null;
        if(isset($selected['plugin_settings']['backup_configuration'])){
            $source_config=$selected['plugin_settings']['backup_configuration'];
            $current_config=self::get_backup_configuration();
            $safe_current_config=self::filter_backup_setting_value($current_config);
            if(is_wp_error($safe_current_config)){return new WP_Error('wsm_restore_configuration_conflict','تنظیمات فعلی بکاپ قابل‌مقایسهٔ امن نیست.',array('status'=>409));}
            if(!self::backup_restore_plan_hash_add($plan_context,array('backup_configuration'=>$safe_current_config))){return new WP_Error('wsm_restore_plan_fingerprint_failed','ساخت اثرانگشت برنامهٔ بازیابی ناموفق بود.',array('status'=>500));}
            $changed_fields=array();
            foreach($source_config as $field=>$value){
                if(!array_key_exists($field,$current_config)||!self::backup_preview_json_equal($value,$current_config[$field])){$changed_fields[]=$field;}
            }
            $backup_configuration=array('status'=>$changed_fields?'changed':'unchanged','changed_fields'=>$changed_fields,'requires_confirmation'=>true,'will_apply'=>false);
        }
        $summary['options_new']=$option_counts['new'];$summary['options_changed']=$option_counts['changed'];
        $summary['options_unchanged']=$option_counts['unchanged'];$summary['options_conflicts']=$option_counts['conflicts'];
        return array(
            'backup_metadata'=>$selected['backup_metadata'],
            'selection'=>$selected['selection'],
            'summary'=>$summary,
            'tables'=>$tables,
            'plugin_options'=>$option_preview,
            'backup_configuration'=>$backup_configuration,
            'plan_fingerprint'=>hash_final($plan_context),
            'read_only'=>true,
        );
    }

    /** Shared size-, extension-, upload-origin-, manifest- and checksum-validated Settings upload reader. */
    private static function read_uploaded_backup_document($field='backup_file') {
        $upload=isset($_FILES[$field])?$_FILES[$field]:null;
        if(!is_array($upload)||!isset($upload['error'],$upload['tmp_name'],$upload['name'])){return new WP_Error('wsm_preview_upload_missing','فایل بکاپ ارسال نشده است.',array('status'=>400));}
        if((int)$upload['error']!==UPLOAD_ERR_OK){return new WP_Error('wsm_preview_upload_failed','بارگذاری فایل بکاپ کامل نشد.',array('status'=>400));}
        $tmp=is_scalar($upload['tmp_name'])?(string)$upload['tmp_name']:'';
        $original=is_scalar($upload['name'])?sanitize_file_name(wp_unslash((string)$upload['name'])):'';
        $extension=strtolower(pathinfo($original,PATHINFO_EXTENSION));
        if(!in_array($extension,array('wsmbackup','json'),true)){return new WP_Error('wsm_preview_extension_invalid','پسوند فایل باید .wsmbackup یا .json برای بکاپ قدیمی باشد.',array('status'=>400));}
        if(!$tmp||!is_uploaded_file($tmp)){return new WP_Error('wsm_preview_upload_invalid','فایل بارگذاری‌شده از مسیر معتبر PHP دریافت نشد.',array('status'=>400));}
        $size=@filesize($tmp);
        if($size===false||$size<1||$size>536870912){return new WP_Error('wsm_preview_size_invalid','حجم بستهٔ بکاپ باید بین ۱ بایت و ۵۱۲ مگابایت باشد.',array('status'=>413));}
        return self::read_backup_package($tmp,(int)$size);
    }

    private static function backup_selection_from_post() {
        if(!isset($_POST['selection'])){return new WP_Error('wsm_preview_selection_missing','انتخاب بخش‌های بکاپ ارسال نشده است.',array('status'=>400));}
        $raw=wp_unslash($_POST['selection']);
        if(is_string($raw)){$selection=json_decode($raw,true);if(json_last_error()!==JSON_ERROR_NONE){return new WP_Error('wsm_preview_selection_json_invalid','ساختار انتخاب بخش‌ها معتبر نیست.',array('status'=>400));}}
        else{$selection=$raw;}
        if(!is_array($selection)){return new WP_Error('wsm_preview_selection_invalid','انتخاب بخش‌های بازیابی معتبر نیست.',array('status'=>400));}
        return $selection;
    }

    private static function send_backup_json_error($error) {
        if(!is_wp_error($error)){$error=new WP_Error('wsm_backup_request_failed','درخواست بکاپ انجام نشد.',array('status'=>500));}
        $meta=$error->get_error_data();$status=is_array($meta)&&isset($meta['status'])?(int)$meta['status']:500;
        if($status<400||$status>599){$status=500;}
        $payload=array('code'=>$error->get_error_code(),'message'=>$error->get_error_message());
        foreach(array('pre_restore_backup_id','pre_restore_download_url','transaction_rollback_attempted','transaction_rollback_succeeded','compensating_rollback_succeeded','compensating_rollback_message') as $key){if(is_array($meta)&&array_key_exists($key,$meta)&&(is_scalar($meta[$key])||$meta[$key]===null)){$payload[$key]=$meta[$key];}}
        wp_send_json_error($payload,$status);
    }

    /** Admin-only upload description; returns only backup metadata and safe section names/counts. */
    public static function handle_backup_describe_request() {
        if(!self::backup_user_can_manage()){wp_die('دسترسی کافی نیست.', '', array('response'=>403));}
        if(!isset($_SERVER['REQUEST_METHOD'])||strtoupper((string)$_SERVER['REQUEST_METHOD'])!=='POST'){wp_die('برای بررسی بکاپ باید از درخواست POST استفاده شود.', '', array('response'=>405));}
        check_admin_referer('wsm_describe_backup');
        $package=self::read_uploaded_backup_document('backup_file');if(is_wp_error($package)){self::send_backup_json_error($package);}
        $document=$package['document'];$archive=$package['archive'];
        $description=self::describe_backup_sections($document);if(is_wp_error($description)){self::send_backup_json_error($description);}
        $manifest=isset($archive['manifest'])&&is_array($archive['manifest'])?$archive['manifest']:array();
        $description['archive']=array('archive_version'=>isset($manifest['archive_version'])?(int)$manifest['archive_version']:0,'database_version'=>isset($manifest['database_version'])?(string)$manifest['database_version']:'نامشخص','manifest_checksum'=>isset($manifest['manifest_sha256'])?(string)$manifest['manifest_sha256']:'','file_count'=>isset($manifest['file_count'])?(int)$manifest['file_count']:0,'plugin_paths'=>array(),'legacy'=>!empty($archive['legacy']),'code_files_restorable'=>false);
        $description['compatibility']=self::backup_package_compatibility($package);
        $description['archive_checksum']=isset($archive['file_checksum'])?(string)$archive['file_checksum']:'';
        wp_send_json_success($description);
    }

    /** Admin-only read-only replacement preview; the one-use token binds the file, selection, and opaque live-state fingerprint. */
    public static function handle_backup_preview_request() {
        if(!self::backup_user_can_manage()){wp_die('دسترسی کافی نیست.', '', array('response'=>403));}
        if(!isset($_SERVER['REQUEST_METHOD'])||strtoupper((string)$_SERVER['REQUEST_METHOD'])!=='POST'){wp_die('برای پیش‌نمایش بکاپ باید از درخواست POST استفاده شود.', '', array('response'=>405));}
        check_admin_referer('wsm_preview_backup');
        $package=self::read_uploaded_backup_document('backup_file');if(is_wp_error($package)){self::send_backup_json_error($package);}
        $document=$package['document'];$archive=$package['archive'];
        $selection=self::backup_selection_from_post();if(is_wp_error($selection)){self::send_backup_json_error($selection);}
        $preview=self::preview_backup_restore_package($document,$selection,$archive);if(is_wp_error($preview)){self::send_backup_json_error($preview);}
        $plan_fingerprint=isset($preview['plan_fingerprint'])?(string)$preview['plan_fingerprint']:'';
        if(!preg_match('/^[a-f0-9]{64}$/',$plan_fingerprint)){self::send_backup_json_error(new WP_Error('wsm_restore_plan_fingerprint_failed','اثر انگشت برنامهٔ پیش‌نمایش معتبر نیست.',array('status'=>500)));}
        try{$token=bin2hex(random_bytes(16));}catch(Exception $e){self::send_backup_json_error(new WP_Error('wsm_restore_preview_token_failed','ساخت شناسهٔ امن پیش‌نمایش انجام نشد.',array('status'=>500)));}
        $bound=set_transient('wsm_restore_preview_'.$token,array('user_id'=>(int)get_current_user_id(),'checksum'=>(string)$document['checksum'],'archive_checksum'=>(string)$archive['file_checksum'],'selection'=>$preview['selection'],'plan_fingerprint'=>$plan_fingerprint),15*MINUTE_IN_SECONDS);
        if(!$bound){self::send_backup_json_error(new WP_Error('wsm_restore_preview_token_store_failed','ثبت موقت تأیید پیش‌نمایش ناموفق بود؛ پیش‌نمایش را دوباره اجرا کنید.',array('status'=>500)));}
        unset($preview['plan_fingerprint']);
        $preview['preview_token']=$token;
        wp_send_json_success($preview);
    }

    /** Final restore requires the reviewed file/selection/live-state fingerprint, explicit confirmation, and a fresh nonce. */
    public static function handle_backup_apply_request() {
        if(!self::backup_user_can_manage()){wp_die('دسترسی کافی نیست.', '', array('response'=>403));}
        if(!isset($_SERVER['REQUEST_METHOD'])||strtoupper((string)$_SERVER['REQUEST_METHOD'])!=='POST'){wp_die('برای اجرای بازیابی باید از درخواست POST استفاده شود.', '', array('response'=>405));}
        check_admin_referer('wsm_apply_backup');
        $package=self::read_uploaded_backup_document('backup_file');if(is_wp_error($package)){self::send_backup_json_error($package);}
        $document=$package['document'];$archive=$package['archive'];
        $selection=self::backup_selection_from_post();if(is_wp_error($selection)){self::send_backup_json_error($selection);}
        $selected=self::select_backup_document_sections($document,$selection);if(is_wp_error($selected)){self::send_backup_json_error($selected);}
        $archive_selection=self::validate_backup_archive_selection($archive,$selected['selection']);if(is_wp_error($archive_selection)){self::send_backup_json_error($archive_selection);}
        $token=isset($_POST['preview_token'])&&is_scalar($_POST['preview_token'])?sanitize_text_field(wp_unslash($_POST['preview_token'])):'';
        if(!preg_match('/^[a-f0-9]{32}$/',$token)){self::send_backup_json_error(new WP_Error('wsm_restore_preview_token_invalid','تأیید پیش‌نمایش معتبر نیست؛ پیش‌نمایش را دوباره اجرا کنید.',array('status'=>409)));}
        $binding=get_transient('wsm_restore_preview_'.$token);
        if(!is_array($binding)||!isset($binding['user_id'],$binding['checksum'],$binding['archive_checksum'],$binding['selection'],$binding['plan_fingerprint'])||!is_string($binding['plan_fingerprint'])||!preg_match('/^[a-f0-9]{64}$/',$binding['plan_fingerprint'])||(int)$binding['user_id']!==(int)get_current_user_id()||!hash_equals((string)$binding['checksum'],(string)$document['checksum'])||!hash_equals((string)$binding['archive_checksum'],(string)$archive['file_checksum'])||!self::backup_preview_json_equal($binding['selection'],$selected['selection'])){
            self::send_backup_json_error(new WP_Error('wsm_restore_preview_stale','فایل یا انتخاب با پیش‌نمایش تأییدشده مطابقت ندارد یا پیش‌نمایش منقضی شده است؛ دوباره پیش‌نمایش بگیرید.',array('status'=>409)));
        }
        $confirmed=isset($_POST['confirmed'])&&is_scalar($_POST['confirmed'])?wp_unslash($_POST['confirmed']):'';
        if(!self::backup_valid_boolean($confirmed)||!rest_sanitize_boolean($confirmed)){self::send_backup_json_error(new WP_Error('wsm_restore_confirmation_required','برای اجرای بازیابی تأیید صریح لازم است.',array('status'=>400)));}
        $config_confirmation=isset($_POST['confirm_backup_configuration'])&&is_scalar($_POST['confirm_backup_configuration'])?wp_unslash($_POST['confirm_backup_configuration']):'0';
        if(!self::backup_valid_boolean($config_confirmation)){self::send_backup_json_error(new WP_Error('wsm_restore_configuration_confirmation_invalid','تأیید جداگانهٔ تنظیمات بکاپ معتبر نیست.',array('status'=>400)));}
        if(rest_sanitize_boolean($config_confirmation)){self::send_backup_json_error(new WP_Error('wsm_restore_backup_configuration_disabled','تنظیمات مسیر، زمان‌بندی و اعلان بکاپ از فایل بازیابی نمی‌شوند.',array('status'=>400)));}
        $table_replacement_confirmation=isset($_POST['confirm_table_replacement'])&&is_scalar($_POST['confirm_table_replacement'])?wp_unslash($_POST['confirm_table_replacement']):'0';
        if(!self::backup_valid_boolean($table_replacement_confirmation)){self::send_backup_json_error(new WP_Error('wsm_restore_table_replacement_confirmation_invalid','تأیید جداگانهٔ جایگزینی کامل جدول‌های انتخاب‌شده معتبر نیست.',array('status'=>400)));}
        $legacy_confirmation=isset($_POST['confirm_legacy'])&&is_scalar($_POST['confirm_legacy'])?wp_unslash($_POST['confirm_legacy']):'0';
        if(!self::backup_valid_boolean($legacy_confirmation)){self::send_backup_json_error(new WP_Error('wsm_restore_legacy_confirmation_invalid','تأیید هشدار ناسازگاری نسخه معتبر نیست.',array('status'=>400)));}
        $compatibility=self::backup_package_compatibility($package);
        if(!empty($compatibility['requires_confirmation'])&&!rest_sanitize_boolean($legacy_confirmation)){self::send_backup_json_error(new WP_Error('wsm_restore_legacy_confirmation_required','برای ادامه، هشدار قرمز اختلاف نسخه یا قالب قدیمی را جداگانه تأیید کنید.',array('status'=>400)));}
        delete_transient('wsm_restore_preview_'.$token);
        $applied=self::apply_backup_restore_document($document,$selected['selection'],true,rest_sanitize_boolean($config_confirmation),rest_sanitize_boolean($table_replacement_confirmation),(string)$binding['plan_fingerprint'],$archive);
        if(is_wp_error($applied)){self::send_backup_json_error($applied);}
        wp_send_json_success($applied);
    }


    private static function backup_user_can_manage() {
        return current_user_can('manage_woocommerce')||current_user_can('manage_options');
    }

    /** Create and atomically store one manual .wsmbackup file, with an auditable result. */
    public static function create_manual_backup($selection=null) {
        return self::create_backup_file('manual',$selection);
    }

    /**
     * Create and verify the durable recovery point that must exist before any restore writes.
     * Pre-restore files are logged as pre_restore and are excluded from automatic retention.
     */
    private static function create_pre_restore_snapshot() {
        $created=self::create_backup_file('pre_restore');
        if(is_wp_error($created)){return $created;}
        if(!is_array($created)||empty($created['id'])||!preg_match('/^[a-f0-9]{32}$/',(string)$created['id'])){
            return new WP_Error('wsm_pre_restore_snapshot_invalid','شناسهٔ نسخهٔ پشتیبان پیش از بازیابی معتبر نیست.',array('status'=>500));
        }
        $resolved=self::resolve_backup_file((string)$created['id']);
        if(is_wp_error($resolved)){
            self::append_backup_log_message((string)$created['id'],'نسخهٔ پشتیبان پیش از بازیابی ساخته شد، اما بررسی مسیر امن فایل آن ناموفق بود: '.$resolved->get_error_message());
            return new WP_Error('wsm_pre_restore_snapshot_unavailable','نسخهٔ پشتیبان پیش از بازیابی ثبت شد، اما فایل آن برای استفادهٔ امن در دسترس نیست.',array('status'=>500,'pre_restore_backup_id'=>(string)$created['id']));
        }
        if(!isset($created['size_bytes'])||(int)$created['size_bytes']!==(int)$resolved['size_bytes']){
            self::append_backup_log_message((string)$created['id'],'اندازهٔ فایل نسخهٔ پشتیبان پیش از بازیابی با اندازهٔ ثبت‌شده مطابقت ندارد.');
            return new WP_Error('wsm_pre_restore_snapshot_size_mismatch','اندازهٔ فایل نسخهٔ پشتیبان پیش از بازیابی با گزارش آن مطابقت ندارد.',array('status'=>500,'pre_restore_backup_id'=>(string)$created['id']));
        }
        $id=(string)$created['id'];
        $created['pre_restore_backup_id']=$id;
        $created['pre_restore_download_url']=self::backup_download_url($id);
        return $created;
    }

    /** Set and verify owner-only permissions before a backup file is populated or exposed under its final name. */
    private static function backup_file_permissions_are_private($path) {
        if(!is_string($path)||$path===''||is_link($path)||!is_file($path)||!@chmod($path,0600)){return false;}
        clearstatcache(true,$path);
        $permissions=@fileperms($path);
        return $permissions!==false&&(($permissions&0777)===0600);
    }

    /** Create a new exclusive file with a restrictive process umask, then confirm mode 0600. */
    private static function open_private_backup_file($path,$mode='xb') {
        if(!is_string($path)||$path===''||!in_array($mode,array('xb','x+b'),true)){return false;}
        $old_umask=umask(0077);
        try{$handle=@fopen($path,$mode);}finally{umask($old_umask);}
        if(!$handle){return false;}
        if(!self::backup_file_permissions_are_private($path)){
            @fclose($handle);
            if(!is_link($path)){@unlink($path);}
            return false;
        }
        return $handle;
    }

    /** Serialize manual/automatic creation against restore; nested cron creation reuses its held lease. */
    private static function create_backup_file($backup_type='manual',$selection=null) {
        if(!in_array($backup_type,array('manual','automatic','pre_restore'),true)){
            return new WP_Error('wsm_backup_type_invalid','نوع بکاپ معتبر نیست.',array('status'=>400));
        }
        if($backup_type==='pre_restore'){
            if(self::$active_backup_restore_lock_token===''||!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_restore_lock_lost','نسخهٔ پشتیبان پیش از بازیابی بدون قفل معتبر ساخته نمی‌شود.',array('status'=>409));}
            return self::create_backup_file_unlocked($backup_type,null);
        }
        if(self::$active_backup_operation_lock_token!==''){return self::create_backup_file_unlocked($backup_type,$selection);}
        $operation_lock=self::acquire_backup_operation_lock();
        if(is_wp_error($operation_lock)){return $operation_lock;}
        if(!$operation_lock){return new WP_Error('wsm_backup_in_progress','عملیات بکاپ دیگری در حال اجراست؛ بعداً دوباره تلاش کنید.',array('status'=>409));}
        self::$active_backup_operation_lock_token=$operation_lock;
        try{
            if(!self::renew_active_backup_lock_lease()){return new WP_Error('wsm_backup_lock_lost','قفل عملیات بکاپ پیش از شروع از دست رفت.',array('status'=>409));}
            return self::create_backup_file_unlocked($backup_type,$selection);
        }finally{
            self::$active_backup_operation_lock_token='';
            self::release_backup_lock_option('wsm_backup_operation_lock',$operation_lock);
        }
    }

    /** Actual writer; callers must hold either the shared operation or restore lease. */
    private static function create_backup_file_unlocked($backup_type='manual',$selection=null) {
        if(!in_array($backup_type,array('manual','automatic','pre_restore'),true)){
            return new WP_Error('wsm_backup_type_invalid','نوع بکاپ معتبر نیست.',array('status'=>400));
        }
        if($backup_type==='pre_restore'&&self::$active_backup_restore_lock_token===''){return new WP_Error('wsm_restore_lock_lost','نسخهٔ پشتیبان پیش از بازیابی بدون قفل معتبر ساخته نمی‌شود.',array('status'=>409));}
        if($backup_type!=='pre_restore'&&self::backup_restore_lock_is_active()){return new WP_Error('wsm_backup_restore_in_progress','هنگام بازیابی، ساخت بکاپ دستی یا خودکار موقتاً متوقف است.',array('status'=>409));}
        $ready=self::ensure_backup_log_table();
        if(is_wp_error($ready)){return $ready;}
        $backup_selection=self::normalize_backup_creation_selection($backup_type==='manual'?$selection:null);
        if(is_wp_error($backup_selection)){return $backup_selection;}
        try{$id=bin2hex(random_bytes(16));}catch(Exception $e){return new WP_Error('wsm_backup_random_failed','ساخت شناسهٔ تصادفی امن برای بکاپ انجام نشد.',array('status'=>500));}
        $started_at=current_time('mysql');
        $started_at_gmt=gmdate('Y-m-d H:i:s');
        $directory='';
        $external_directory='';
        $snapshot=null;
        $file_checksum='';
        $fail=function($error)use($id,$backup_type,&$directory,&$external_directory,&$snapshot,&$file_checksum,$started_at,$started_at_gmt){
            if(!is_wp_error($error)){$error=new WP_Error('wsm_backup_failed','ساخت بکاپ انجام نشد.',array('status'=>500));}
            $table_count=is_array($snapshot)&&isset($snapshot['database_tables'])&&is_array($snapshot['database_tables'])?count($snapshot['database_tables']):0;
            $record_count=is_array($snapshot)&&isset($snapshot['record_count'])?(int)$snapshot['record_count']:0;
            $logged=self::insert_backup_log(array(
                'backup_id'=>$id,'backup_type'=>$backup_type,'filename'=>'','storage_path'=>$directory,'external_storage_path'=>$external_directory,
                'file_size'=>0,'checksum'=>'','file_checksum'=>$file_checksum,'table_count'=>$table_count,'record_count'=>$record_count,
                'status'=>'failed','error_message'=>$error->get_error_message(),
                'created_at'=>$started_at,'created_at_gmt'=>$started_at_gmt,
            ));
            if(is_wp_error($logged)){return new WP_Error('wsm_backup_failure_log_failed',$error->get_error_message().' گزارش خطا نیز ثبت نشد.',array('status'=>500));}
            return $error;
        };

        $resolved=self::backup_storage_directory(null,true);
        if(is_wp_error($resolved)){return $fail($resolved);}
        $directory=$resolved;
        $configuration=self::get_backup_configuration();
        $external_path=isset($configuration['external_storage_path'])&&is_scalar($configuration['external_storage_path'])?trim((string)$configuration['external_storage_path']):'';
        if($external_path!==''){
            $external_directory=self::external_backup_storage_directory($external_path,true);
            if(is_wp_error($external_directory)){return $fail($external_directory);}
            if(untrailingslashit(wp_normalize_path($external_directory))===untrailingslashit(wp_normalize_path($directory))){return $fail(new WP_Error('wsm_backup_external_same_as_primary','مسیر خارجی باید با مسیر اصلی بکاپ متفاوت باشد.',array('status'=>400)));}
        }
        $snapshot=self::collect_backup_snapshot($backup_selection['database_tables']);
        if(is_wp_error($snapshot)){return $fail($snapshot);}
        if(!self::renew_active_backup_lock_lease()){return $fail(new WP_Error('wsm_backup_lock_lost','قفل بکاپ یا بازیابی پس از جمع‌آوری نسخهٔ پشتیبان از دست رفت.',array('status'=>409)));}
        $built=self::build_backup_document($snapshot['database_tables'],$snapshot['plugin_settings'],$backup_type);
        if(is_wp_error($built)){return $fail($built);}
        if(!self::renew_active_backup_lock_lease()){return $fail(new WP_Error('wsm_backup_lock_lost','قفل بکاپ یا بازیابی پس از ساخت سند از دست رفت.',array('status'=>409)));}
        if(!is_string($built['json'])||$built['json']===''||strlen($built['json'])>268435456){return $fail(new WP_Error('wsm_backup_size_invalid','محتوای دادهٔ پایگاه داده برای بستهٔ ZIP معتبر نیست.',array('status'=>413)));}
        $filename='wsm-backup-'.$id.'.wsmbackup';
        $final_path=trailingslashit($directory).$filename;
        $temp_path=trailingslashit($directory).'.'.$filename.'.tmp';
        if(file_exists($final_path)||file_exists($temp_path)){return $fail(new WP_Error('wsm_backup_name_collision','نام یکتای فایل بکاپ تکرار شد؛ دوباره تلاش کنید.',array('status'=>500)));}
        $placeholder=self::open_private_backup_file($temp_path,'xb');
        if(!$placeholder){return $fail(new WP_Error('wsm_backup_file_create_failed','ساخت فایل موقت بکاپ با مجوز خصوصی انجام نشد.',array('status'=>500)));}
        fclose($placeholder);
        if(!self::renew_active_backup_lock_lease()){@unlink($temp_path);return $fail(new WP_Error('wsm_backup_lock_lost','قفل بکاپ پیش از ساخت بسته از دست رفت.',array('status'=>409)));}
        $archive_result=self::write_backup_archive($temp_path,$built,$backup_selection);
        if(is_wp_error($archive_result)){if(!is_link($temp_path)&&is_file($temp_path)){@unlink($temp_path);}return $fail($archive_result);}
        $length=(int)$archive_result['size_bytes'];$file_checksum=(string)$archive_result['file_checksum'];
        if($length<1||$length>536870912||!preg_match('/^[a-f0-9]{64}$/',$file_checksum)){@unlink($temp_path);return $fail(new WP_Error('wsm_backup_archive_result_invalid','اندازه یا SHA-256 بستهٔ ZIP معتبر نیست.',array('status'=>500)));}
        if(!self::renew_active_backup_lock_lease()){@unlink($temp_path);return $fail(new WP_Error('wsm_backup_lock_lost','قفل بکاپ پیش از ثبت فایل از دست رفت؛ فایل موقت حذف شد.',array('status'=>409)));}
        if(!@rename($temp_path,$final_path)){@unlink($temp_path);return $fail(new WP_Error('wsm_backup_file_commit_failed','ثبت نهایی فایل بکاپ انجام نشد.',array('status'=>500)));}
        if(!self::backup_file_permissions_are_private($final_path)){@unlink($final_path);return $fail(new WP_Error('wsm_backup_file_permissions_failed','فایل نهایی بکاپ مجوز خصوصی 0600 ندارد و حذف شد.',array('status'=>500)));}
        $stored_file_checksum=@hash_file('sha256',$final_path);
        if(!is_string($stored_file_checksum)||!hash_equals($file_checksum,strtolower($stored_file_checksum))){@unlink($final_path);return $fail(new WP_Error('wsm_backup_file_checksum_mismatch','SHA-256 فایل ZIP ذخیره‌شده با محتوای ساخته‌شده مطابقت ندارد؛ فایل حذف شد.',array('status'=>500)));}
        $external_final_path='';
        if(!self::renew_active_backup_lock_lease()){@unlink($final_path);return $fail(new WP_Error('wsm_backup_lock_lost','قفل بکاپ یا بازیابی پیش از نسخهٔ خارجی از دست رفت؛ فایل اصلی حذف شد.',array('status'=>409)));}
        if($external_directory!==''){
            $external_copy=self::copy_backup_to_external_directory($final_path,$external_directory,$filename,$length,$file_checksum);
            if(is_wp_error($external_copy)){@unlink($final_path);return $fail($external_copy);}
            $external_final_path=$external_copy['path'];
        }
        if(!self::renew_active_backup_lock_lease()){
            if(!is_link($final_path)&&is_file($final_path)){@unlink($final_path);}
            if($external_final_path!==''&&!is_link($external_final_path)&&is_file($external_final_path)){@unlink($external_final_path);}
            return $fail(new WP_Error('wsm_backup_lock_lost','قفل بکاپ یا بازیابی پیش از ثبت گزارش از دست رفت؛ فایل‌ها حذف شدند.',array('status'=>409)));
        }
        $logged=self::insert_backup_log(array(
            'backup_id'=>$id,'backup_type'=>$backup_type,'filename'=>$filename,'storage_path'=>$directory,'external_storage_path'=>$external_directory,
            'file_size'=>$length,'checksum'=>$built['checksum'],'file_checksum'=>$file_checksum,'table_count'=>$built['metadata']['table_count'],
            'record_count'=>$built['metadata']['record_count'],'status'=>'success','error_message'=>'',
            'created_at'=>$built['metadata']['created_at'],'created_at_gmt'=>$built['metadata']['created_at_gmt'],
        ));
        if(is_wp_error($logged)){
            @unlink($final_path);
            if($external_final_path!==''&&!is_link($external_final_path)&&is_file($external_final_path)){@unlink($external_final_path);}
            return new WP_Error('wsm_backup_log_insert_failed','فایل ساخته شد اما گزارش آن ذخیره نشد؛ فایل‌های موقت حذف شدند.',array('status'=>500));
        }
        $result=array(
            'id'=>$id,
            'filename'=>$filename,
            'size_bytes'=>$length,
            'size_display'=>function_exists('size_format')?size_format($length):((string)$length.' بایت'),
            'created_at_display'=>function_exists('wsm_fa_date')?wsm_fa_date($built['metadata']['created_at']):(string)$built['metadata']['created_at'],
            'checksum'=>$built['checksum'],
            'file_checksum'=>$file_checksum,
            'metadata'=>$built['metadata'],
            'archive'=>array('archive_version'=>2,'file_count'=>(int)$archive_result['file_count'],'database_version'=>(string)$archive_result['database_version']),
            'external_copy_saved'=>$external_directory!=='',
        );
        if($backup_type==='manual'){$result['download_url']=self::backup_download_url($id);}
        return $result;
    }

    /** Atomically copy and SHA-256-verify a completed backup into its external mirror directory. */
    private static function copy_backup_to_external_directory($source_path,$target_directory,$filename,$expected_size,$expected_file_checksum) {
        if(!is_string($filename)||!preg_match('/^wsm-backup-[a-f0-9]{32}\.wsmbackup$/D',$filename)||!is_string($source_path)||!is_string($target_directory)||!preg_match('/^[a-f0-9]{64}$/i',(string)$expected_file_checksum)){
            return new WP_Error('wsm_backup_mirror_arguments_invalid','اطلاعات کپی خارجی بکاپ معتبر نیست.',array('status'=>500));
        }
        $source_real=realpath($source_path);$directory_real=realpath($target_directory);
        if($source_real===false||is_link($source_path)||!is_file($source_real)||$directory_real===false||!is_dir($directory_real)||!wp_is_writable($directory_real)){
            return new WP_Error('wsm_backup_mirror_path_invalid','مسیر منبع یا مقصد نسخهٔ خارجی معتبر یا قابل‌نوشتن نیست.',array('status'=>500));
        }
        $final_path=trailingslashit($directory_real).$filename;
        $temp_path=trailingslashit($directory_real).'.'.$filename.'.tmp';
        if(file_exists($final_path)||is_link($final_path)||file_exists($temp_path)||is_link($temp_path)){
            return new WP_Error('wsm_backup_mirror_collision','نام فایل در مسیر خارجی از قبل وجود دارد؛ کپی انجام نشد.',array('status'=>409));
        }
        $source=@fopen($source_real,'rb');
        if(!$source){return new WP_Error('wsm_backup_mirror_source_open_failed','خواندن فایل اصلی برای کپی خارجی انجام نشد.',array('status'=>500));}
        $target=self::open_private_backup_file($temp_path,'xb');
        if(!$target){fclose($source);return new WP_Error('wsm_backup_mirror_create_failed','ساخت فایل موقت خصوصی در مسیر خارجی انجام نشد.',array('status'=>500));}
        $ok=flock($target,LOCK_EX);$copied=0;$hash=hash_init('sha256');$copy_iterations=0;$lock_lost=false;
        while($ok&&!feof($source)){
            if(($copy_iterations++%16)===0&&!self::renew_active_backup_lock_lease()){$ok=false;$lock_lost=true;break;}
            $chunk=fread($source,1048576);
            if($chunk===false){$ok=false;break;}
            if($chunk===''){if(feof($source)){break;}$ok=false;break;}
            hash_update($hash,$chunk);$offset=0;$chunk_length=strlen($chunk);
            while($ok&&$offset<$chunk_length){$written=fwrite($target,substr($chunk,$offset));if($written===false||$written===0){$ok=false;break;}$offset+=$written;$copied+=$written;}
        }
        if($ok&&function_exists('fflush')){$ok=fflush($target);}
        if($ok&&function_exists('fsync')){@fsync($target);}
        @flock($target,LOCK_UN);$target_closed=fclose($target);$source_closed=fclose($source);
        $copied_checksum=$ok&&$target_closed&&$source_closed?hash_final($hash):'';
        if($lock_lost){@unlink($temp_path);return new WP_Error('wsm_backup_lock_lost','قفل بکاپ یا بازیابی هنگام کپی خارجی از دست رفت؛ نسخهٔ ناقص حذف شد.',array('status'=>409));}
        if(!$ok||!$target_closed||!$source_closed||(int)$expected_size!==$copied||!is_string($copied_checksum)||!hash_equals(strtolower((string)$expected_file_checksum),strtolower($copied_checksum))){
            @unlink($temp_path);
            return new WP_Error('wsm_backup_mirror_verify_failed','نسخهٔ خارجی با اندازه یا SHA-256 فایل اصلی مطابقت ندارد.',array('status'=>500));
        }
        if(!self::backup_file_permissions_are_private($temp_path)){
            @unlink($temp_path);
            return new WP_Error('wsm_backup_mirror_permissions_failed','اعمال یا بررسی مجوز خصوصی نسخهٔ خارجی انجام نشد.',array('status'=>500));
        }
        if(!self::renew_active_backup_lock_lease()){@unlink($temp_path);return new WP_Error('wsm_backup_lock_lost','قفل بکاپ یا بازیابی پیش از ثبت نسخهٔ خارجی از دست رفت؛ فایل موقت حذف شد.',array('status'=>409));}
        if(file_exists($final_path)||is_link($final_path)||!@rename($temp_path,$final_path)){
            @unlink($temp_path);
            return new WP_Error('wsm_backup_mirror_commit_failed','ثبت اتمیک نسخهٔ خارجی انجام نشد.',array('status'=>500));
        }
        if(!self::backup_file_permissions_are_private($final_path)){
            @unlink($final_path);
            return new WP_Error('wsm_backup_mirror_permissions_failed','نسخهٔ خارجی مجوز خصوصی 0600 ندارد و حذف شد.',array('status'=>500));
        }
        return array('path'=>$final_path,'size_bytes'=>$copied,'checksum'=>$copied_checksum);
    }

    /** Validate a logged backup candidate and verify its raw SHA-256 when the caller needs integrity assurance. */
    private static function resolve_backup_file_candidate($directory,$filename,$expected_size=0,$expected_file_checksum='',$verify_file_checksum=true) {
        if(is_wp_error($directory)){return $directory;}
        $path=trailingslashit($directory).$filename;
        if(is_link($path)||!is_file($path)||!is_readable($path)){return new WP_Error('wsm_backup_file_missing','فایل بکاپ پیدا نشد یا قابل‌خواندن نیست.',array('status'=>404));}
        $real_directory=realpath($directory);$real_path=realpath($path);$size=@filesize($path);
        if($real_directory===false||$real_path===false||strpos(wp_normalize_path($real_path),trailingslashit(wp_normalize_path($real_directory)))!==0||basename($real_path)!==$filename){return new WP_Error('wsm_backup_file_unsafe','مسیر فایل بکاپ معتبر نیست.',array('status'=>403));}
        if($size===false||$size<1||$size>268435456){return new WP_Error('wsm_backup_file_size_invalid','اندازهٔ فایل بکاپ معتبر نیست.',array('status'=>413));}
        if((int)$expected_size>0&&(int)$size!==(int)$expected_size){return new WP_Error('wsm_backup_file_size_mismatch','اندازهٔ فایل با گزارش بکاپ مطابقت ندارد.',array('status'=>409));}
        if($verify_file_checksum&&$expected_file_checksum!==''){
            $actual_checksum=@hash_file('sha256',$real_path);
            if(!is_string($actual_checksum)||!hash_equals(strtolower($expected_file_checksum),strtolower($actual_checksum))){return new WP_Error('wsm_backup_file_checksum_mismatch','SHA-256 فایل با گزارش آن مطابقت ندارد.',array('status'=>409));}
        }
        return array('path'=>$real_path,'filename'=>$filename,'size_bytes'=>(int)$size);
    }

    /** Resolve a random ID only through its logged path; verify primary then mirror and preserve legacy rows. */
    private static function resolve_backup_file($id,$verify_file_checksum=true) {
        global $wpdb;
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id)){return new WP_Error('wsm_backup_id_invalid','شناسهٔ فایل بکاپ معتبر نیست.',array('status'=>400));}
        $filename='wsm-backup-'.$id.'.wsmbackup';
        $table='`'.str_replace('`','``',self::backup_log_table()).'`';
        $entry=$wpdb->get_row($wpdb->prepare("SELECT filename,storage_path,external_storage_path,status,file_size,file_checksum FROM $table WHERE backup_id=%s LIMIT 1",$id),ARRAY_A);
        if($wpdb->last_error!==''){return new WP_Error('wsm_backup_log_read_failed','گزارش فایل بکاپ خوانده نشد.',array('status'=>500));}
        if(is_array($entry)){
            if(!isset($entry['status'])||$entry['status']!=='success'){return new WP_Error('wsm_backup_file_missing','فایل بکاپ موجود یا قابل‌دریافت نیست.',array('status'=>404));}
            if(!isset($entry['filename'])||(string)$entry['filename']!==$filename){return new WP_Error('wsm_backup_file_unsafe','نام فایل با گزارش بکاپ مطابقت ندارد.',array('status'=>403));}
            $file_checksum=isset($entry['file_checksum'])&&is_scalar($entry['file_checksum'])?trim((string)$entry['file_checksum']):'';
            if($file_checksum!==''&&!preg_match('/^[a-f0-9]{64}$/i',$file_checksum)){return new WP_Error('wsm_backup_file_checksum_invalid','SHA-256 ثبت‌شده برای فایل بکاپ معتبر نیست.',array('status'=>500));}
            $expected_size=isset($entry['file_size'])?(int)$entry['file_size']:0;$directories=array();$last_error=null;$checksum_mismatch=false;
            $primary=self::backup_directory_from_log(isset($entry['storage_path'])?(string)$entry['storage_path']:'');
            if(!is_wp_error($primary)){$directories[]=$primary;}elseif(!in_array($primary->get_error_code(),array('wsm_backup_path_missing','wsm_backup_log_path_missing'),true)){$last_error=$primary;}
            if(isset($entry['external_storage_path'])&&is_scalar($entry['external_storage_path'])&&trim((string)$entry['external_storage_path'])!==''){
                $mirror=self::backup_directory_from_log((string)$entry['external_storage_path']);
                if(!is_wp_error($mirror)){$directories[]=$mirror;}elseif(!$last_error&&!in_array($mirror->get_error_code(),array('wsm_backup_path_missing','wsm_backup_log_path_missing'),true)){$last_error=$mirror;}
            }
            $directories=array_values(array_unique($directories));
            foreach($directories as $candidate_directory){
                $candidate=self::resolve_backup_file_candidate($candidate_directory,$filename,$expected_size,$file_checksum,$verify_file_checksum);
                if(!is_wp_error($candidate)){return $candidate;}
                if($candidate->get_error_code()==='wsm_backup_file_checksum_mismatch'){$checksum_mismatch=true;}
                elseif($candidate->get_error_code()!=='wsm_backup_file_missing'&&!$last_error){$last_error=$candidate;}
            }
            if($checksum_mismatch){return new WP_Error('wsm_backup_file_checksum_mismatch','نسخه‌های اصلی و خارجی با SHA-256 ثبت‌شده مطابقت ندارند.',array('status'=>409));}
            if($last_error){return $last_error;}
            return new WP_Error('wsm_backup_file_missing','فایل بکاپ در مسیر اصلی یا خارجی پیدا نشد.',array('status'=>404));
        }
        // Backward compatibility: check the uploads backup folder only for unlogged legacy files.
        $directory=self::backup_storage_directory(null,false);
        $configuration=self::get_backup_configuration();
        if(is_wp_error($directory)&&isset($configuration['storage_mode'])&&$configuration['storage_mode']==='default'&&in_array($directory->get_error_code(),array('wsm_backup_path_missing','wsm_backup_home_unavailable'),true)){
            $uploads=wp_upload_dir(null,false);
            if(is_array($uploads)&&empty($uploads['error'])&&!empty($uploads['basedir'])){$legacy=trailingslashit($uploads['basedir']).'wsm-backups';$legacy_directory=self::backup_storage_directory(array('storage_mode'=>'custom','storage_path'=>$legacy),false);if(!is_wp_error($legacy_directory)){$directory=$legacy_directory;}}
        }
        return self::resolve_backup_file_candidate($directory,$filename,0,'',false);
    }

    /** Safely verify create, write, read-back, and removal access with a short-lived random probe file. */
    private static function probe_backup_directory($directory) {
        if(!is_string($directory)||trim($directory)===''||is_link($directory)){return new WP_Error('wsm_backup_path_test_directory_invalid','پوشهٔ انتخاب‌شده برای آزمون مسیر معتبر نیست.',array('status'=>400));}
        $real_directory=realpath($directory);
        if($real_directory===false||!is_dir($real_directory)||!wp_is_writable($real_directory)){return new WP_Error('wsm_backup_path_test_unwritable','پوشهٔ انتخاب‌شده برای آزمون مسیر قابل‌نوشتن نیست.',array('status'=>500));}
        try{$token=bin2hex(random_bytes(16));}catch(Exception $e){return new WP_Error('wsm_backup_path_test_random_failed','ساخت نام امن برای فایل آزمایشی انجام نشد.',array('status'=>500));}
        $path=trailingslashit($real_directory).'.wsm-backup-path-test-'.$token.'.tmp';
        $handle=self::open_private_backup_file($path,'x+b');
        if(!$handle){return new WP_Error('wsm_backup_path_test_create_failed','ساخت فایل آزمایشی موقت یا تنظیم مجوز خصوصی آن ناموفق بود.',array('status'=>500));}
        $payload='wsm-backup-path-test:'.$token."\n";$length=strlen($payload);$offset=0;$ok=flock($handle,LOCK_EX);
        while($ok&&$offset<$length){$written=fwrite($handle,substr($payload,$offset));if($written===false||$written===0){$ok=false;break;}$offset+=$written;}
        if($ok&&function_exists('fflush')){$ok=fflush($handle);}
        if($ok&&function_exists('fsync')){@fsync($handle);}
        $stat=$ok?fstat($handle):false;
        if($ok&&(!is_array($stat)||!isset($stat['size'])||(int)$stat['size']!==$length)){$ok=false;}
        if($ok&&rewind($handle)!==true){$ok=false;}
        $read_back='';
        while($ok&&strlen($read_back)<$length){$chunk=fread($handle,$length-strlen($read_back));if($chunk===false||$chunk===''){$ok=false;break;}$read_back.=$chunk;}
        if($ok&&!hash_equals($payload,$read_back)){$ok=false;}
        @flock($handle,LOCK_UN);$closed=fclose($handle);if(!$closed){$ok=false;}
        $removed=(!file_exists($path)&&!is_link($path))||@unlink($path);
        if(!$removed){return new WP_Error('wsm_backup_path_test_cleanup_failed','آزمون مسیر انجام شد اما فایل موقت آزمایشی حذف نشد؛ مدیر سرور باید آن را پاک کند.',array('status'=>500));}
        if(!$ok){return new WP_Error('wsm_backup_path_test_io_failed','نوشتن یا خواندن مجدد فایل آزمایشی در مسیر انتخاب‌شده ناموفق بود.',array('status'=>500));}
        return true;
    }

    /** Admin-only, non-persistent path test for the selected primary and optional external destinations. */
    public static function handle_test_backup_path_request() {
        if(!self::backup_user_can_manage()){wp_die('دسترسی کافی نیست.', '', array('response'=>403));}
        if(!isset($_SERVER['REQUEST_METHOD'])||strtoupper((string)$_SERVER['REQUEST_METHOD'])!=='POST'){wp_die('برای آزمون مسیر باید از درخواست POST استفاده شود.', '', array('response'=>405));}
        check_admin_referer('wsm_test_backup_path');
        $mode=isset($_POST['storage_mode'])&&is_scalar($_POST['storage_mode'])?sanitize_key(wp_unslash((string)$_POST['storage_mode'])):'';
        if(!in_array($mode,array('default','custom'),true)){self::send_backup_json_error(new WP_Error('wsm_backup_path_test_mode_invalid','نوع مسیر اصلی برای آزمون معتبر نیست.',array('status'=>400)));}
        if(isset($_POST['storage_path'])&&!is_scalar($_POST['storage_path'])){self::send_backup_json_error(new WP_Error('wsm_backup_path_test_input_invalid','مسیر اصلی برای آزمون معتبر نیست.',array('status'=>400)));}
        if(isset($_POST['external_storage_path'])&&!is_scalar($_POST['external_storage_path'])){self::send_backup_json_error(new WP_Error('wsm_backup_path_test_external_invalid','مسیر خارجی برای آزمون معتبر نیست.',array('status'=>400)));}
        $storage_path=isset($_POST['storage_path'])?trim((string)wp_unslash($_POST['storage_path'])):'';
        $external_path=isset($_POST['external_storage_path'])?trim((string)wp_unslash($_POST['external_storage_path'])):'';
        $primary_directory=self::backup_storage_directory(array('storage_mode'=>$mode,'storage_path'=>$storage_path),true);
        if(is_wp_error($primary_directory)){self::send_backup_json_error($primary_directory);}
        $external_directory='';
        if($external_path!==''){
            $external_directory=self::external_backup_storage_directory($external_path,true);
            if(is_wp_error($external_directory)){self::send_backup_json_error($external_directory);}
            if(untrailingslashit(wp_normalize_path($external_directory))===untrailingslashit(wp_normalize_path($primary_directory))){self::send_backup_json_error(new WP_Error('wsm_backup_external_same_as_primary','مسیر خارجی باید با مسیر اصلی بکاپ متفاوت باشد.',array('status'=>400)));}
        }
        $primary_test=self::probe_backup_directory($primary_directory);
        if(is_wp_error($primary_test)){self::send_backup_json_error($primary_test);}
        if($external_directory!==''){
            $external_test=self::probe_backup_directory($external_directory);
            if(is_wp_error($external_test)){self::send_backup_json_error($external_test);}
        }
        $message=$external_directory!==''?'آزمون نوشتن، خواندن و حذف فایل موقت در مسیر اصلی و مسیر خارجی موفق بود.':'مسیر اصلی آماده است؛ فایل آزمایشی موقت با موفقیت نوشته، خوانده و حذف شد. مسیر خارجی وارد نشده است.';
        wp_send_json_success(array('primary_tested'=>true,'external_tested'=>$external_directory!=='','message'=>$message));
    }

    /** Logged-in wp-admin action: stores a manual backup and returns metadata for the settings UI. */
    public static function handle_create_backup_request() {
        if(!self::backup_user_can_manage()){wp_die('دسترسی کافی نیست.', '', array('response'=>403));}
        if(!isset($_SERVER['REQUEST_METHOD'])||strtoupper((string)$_SERVER['REQUEST_METHOD'])!=='POST'){wp_die('برای ساخت بکاپ باید از درخواست POST استفاده شود.', '', array('response'=>405));}
        check_admin_referer('wsm_create_backup');
        $selection=self::backup_selection_from_post();if(is_wp_error($selection)){self::send_backup_json_error($selection);}
        $created=self::create_manual_backup($selection);
        if(is_wp_error($created)){
            $data=$created->get_error_data();$status=is_array($data)&&isset($data['status'])?(int)$data['status']:500;
            wp_send_json_error(array('code'=>$created->get_error_code(),'message'=>$created->get_error_message()),$status);
        }
        wp_send_json_success($created,201);
    }

    /** Logged-in POST action: safely delete recorded copies and preserve the history row. */
    public static function handle_delete_backup_request() {
        if(!self::backup_user_can_manage()){wp_die('دسترسی کافی نیست.', '', array('response'=>403));}
        if(!isset($_SERVER['REQUEST_METHOD'])||strtoupper((string)$_SERVER['REQUEST_METHOD'])!=='POST'){wp_die('برای حذف بکاپ باید از درخواست POST استفاده شود.', '', array('response'=>405));}
        $id=isset($_POST['backup_id'])&&is_scalar($_POST['backup_id'])?sanitize_text_field(wp_unslash((string)$_POST['backup_id'])):'';
        if(!preg_match('/^[a-f0-9]{32}$/',$id)){wp_die('شناسهٔ بکاپ برای حذف معتبر نیست.', '', array('response'=>400));}
        check_admin_referer('wsm_delete_backup_'.$id);
        $lock=self::acquire_backup_operation_lock();
        if(is_wp_error($lock)){$data=$lock->get_error_data();$status=is_array($data)&&isset($data['status'])?(int)$data['status']:409;wp_die(esc_html($lock->get_error_message()), '', array('response'=>$status));}
        if(!$lock){wp_die('عملیات بکاپ یا بازیابی دیگری در حال اجراست؛ حذف انجام نشد.', '', array('response'=>409));}
        self::$active_backup_operation_lock_token=$lock;
        try{$result=self::delete_backup_by_id($id);}
        finally{
            self::$active_backup_operation_lock_token='';
            self::release_backup_lock_option('wsm_backup_operation_lock',$lock);
        }
        if(is_wp_error($result)){$data=$result->get_error_data();$status=is_array($data)&&isset($data['status'])?(int)$data['status']:500;wp_die(esc_html($result->get_error_message()), '', array('response'=>$status));}
        $redirect_args=array('page'=>'wsm-settings','wsm_backup_notice'=>'deleted');
        $type=isset($_POST['wsm_backup_type'])&&is_scalar($_POST['wsm_backup_type'])?sanitize_key(wp_unslash((string)$_POST['wsm_backup_type'])):'';
        $status=isset($_POST['wsm_backup_status'])&&is_scalar($_POST['wsm_backup_status'])?sanitize_key(wp_unslash((string)$_POST['wsm_backup_status'])):'';
        if(in_array($type,array('manual','automatic','pre_restore'),true)){$redirect_args['wsm_backup_type']=$type;}
        if(in_array($status,array('success','failed','deleted','missing'),true)){$redirect_args['wsm_backup_status']=$status;}
        wp_safe_redirect(add_query_arg($redirect_args,admin_url('admin.php')));
        exit;
    }

    /** Stream a protected backup only after capability, nonce, identifier and path checks. */
    public static function handle_download_backup_request() {
        if(!self::backup_user_can_manage()){wp_die('دسترسی کافی نیست.', '', array('response'=>403));}
        if(!isset($_SERVER['REQUEST_METHOD'])||strtoupper((string)$_SERVER['REQUEST_METHOD'])!=='GET'){wp_die('برای دریافت بکاپ باید از درخواست GET استفاده شود.', '', array('response'=>405));}
        $id=isset($_GET['backup_id'])&&is_scalar($_GET['backup_id'])?sanitize_text_field(wp_unslash($_GET['backup_id'])):'';
        if(!preg_match('/^[a-f0-9]{32}$/',$id)){wp_die('شناسهٔ فایل بکاپ معتبر نیست.', '', array('response'=>400));}
        check_admin_referer('wsm_download_backup_'.$id);
        $file=self::resolve_backup_file($id);
        if(is_wp_error($file)){
            $data=$file->get_error_data();$status=is_array($data)&&isset($data['status'])?(int)$data['status']:500;
            wp_die(esc_html($file->get_error_message()), '', array('response'=>$status));
        }
        if(headers_sent()){wp_die('ارسال فایل بکاپ آغاز نمی‌شود؛ خروجی قبلی ارسال شده است.', '', array('response'=>500));}
        while(ob_get_level()){ob_end_clean();}
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$file['filename'].'"');
        header('Content-Length: '.$file['size_bytes']);
        header('X-Content-Type-Options: nosniff');
        $sent=@readfile($file['path']);
        if($sent===false){wp_die('خواندن فایل بکاپ انجام نشد.', '', array('response'=>500));}
        exit;
    }


    public static function count_pending_portal_renewal_reviews() {
        $reviews=self::athlete_portal_option_array(self::PORTAL_REVIEWS_OPTION);$count=0;
        foreach ($reviews as $review) { if (is_array($review)&&(string)($review['status'] ?? '')==='pending') { $count++; } }
        return $count;
    }

    private static function enqueue_portal_renewal_review($intent,$membership,$member) {
        if (!is_array($intent)||empty($intent['intent_id'])||!is_array($membership)||empty($membership['id'])||!is_object($member)) {
            return new WP_Error('wsm_athlete_review_invalid','اطلاعات بررسی تمدید معتبر نیست.',array('status'=>400));
        }
        $reviews=self::athlete_portal_option_array(self::PORTAL_REVIEWS_OPTION);
        foreach ($reviews as $existing) {
            if (is_array($existing)&&(string)($existing['intent_id'] ?? '')===(string)$intent['intent_id']) { return $existing; }
        }
        $previous_end=(string)($intent['prior_end_date'] ?? '');$attendance_count=0;$attendance_dates=array();
        if ($previous_end!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$previous_end)) {
            global $wpdb;$attendance=WSM_Club::table('attendance');
            $wpdb->last_error='';
            $attendance_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attendance WHERE member_id=%d AND direction='in' AND decision='allowed' AND occurred_at > %s",(int)$member->id,$previous_end.' 23:59:59'));
            if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_review_attendance_failed','خواندن ترددهای پس از انقضای عضویت انجام نشد.',array('status'=>500)); }
            $wpdb->last_error='';
            $date_rows=$wpdb->get_results($wpdb->prepare("SELECT DATE(occurred_at) AS attendance_date FROM $attendance WHERE member_id=%d AND direction='in' AND decision='allowed' AND occurred_at > %s GROUP BY DATE(occurred_at) ORDER BY attendance_date ASC LIMIT 120",(int)$member->id,$previous_end.' 23:59:59'),ARRAY_A);
            if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_review_attendance_failed','خواندن تاریخ‌های تردد انجام نشد.',array('status'=>500)); }
            foreach ((array)$date_rows as $row) { if (!empty($row['attendance_date'])) { $attendance_dates[]=(string)$row['attendance_date']; } }
        }
        try { $review_id='pr_'.bin2hex(random_bytes(12)); }
        catch (Throwable $e) { return new WP_Error('wsm_athlete_review_random_failed','ساخت شناسهٔ بررسی تمدید انجام نشد.',array('status'=>500)); }
        $review=array(
            'id'=>$review_id,'intent_id'=>(string)$intent['intent_id'],'member_id'=>(int)$member->id,'member_name'=>trim((string)$member->first_name.' '.(string)$member->last_name),
            'member_code'=>(string)$member->member_code,'phone'=>(string)$member->phone,'membership_id'=>(int)$membership['id'],'plan_name'=>(string)($membership['plan_name'] ?? $intent['plan_name'] ?? ''),
            'previous_end_date'=>$previous_end,'chosen_start_date'=>(string)($membership['start_date'] ?? $intent['start_date'] ?? ''),'duration_days'=>(int)($intent['duration_days'] ?? 30),
            'payment_date'=>self::athlete_portal_today(),'attendance_after_expiry_count'=>$attendance_count,'attendance_after_expiry_dates'=>$attendance_dates,
            'status'=>'pending','created_at'=>current_time('mysql'),'resolved_at'=>'','resolved_by_user_id'=>0,
        );
        $reviews[$review_id]=$review;update_option(self::PORTAL_REVIEWS_OPTION,$reviews,false);
        return $review;
    }

    public static function get_portal_renewal_reviews_rest($request) {
        $reviews=self::athlete_portal_option_array(self::PORTAL_REVIEWS_OPTION);$items=array();
        foreach ($reviews as $review) { if (is_array($review)&&(string)($review['status'] ?? '')==='pending') { $items[]=$review; } }
        usort($items,function($a,$b){return strcmp((string)($a['created_at'] ?? ''),(string)($b['created_at'] ?? ''));});
        return array('items'=>$items,'count'=>count($items));
    }

    public static function resolve_portal_renewal_review_rest($request) {
        $review_id=is_object($request)&&method_exists($request,'get_param')?sanitize_text_field((string)$request->get_param('id')):'';
        if (!preg_match('/^pr_[a-f0-9]{24}$/',$review_id)) { return new WP_Error('wsm_athlete_review_id_invalid','شناسهٔ بررسی تمدید معتبر نیست.',array('status'=>400)); }
        $body=self::athlete_portal_request_body($request);$action=isset($body['action'])&&is_scalar($body['action'])?sanitize_key((string)$body['action']):'';
        if (!in_array($action,array('approve','edit'),true)) { return new WP_Error('wsm_athlete_review_action_invalid','عملیات بررسی تمدید معتبر نیست.',array('status'=>400)); }
        $lock=self::athlete_portal_acquire_lock('review',$review_id,120);
        if (is_wp_error($lock)) { return $lock; }
        try {
            $reviews=self::athlete_portal_option_array(self::PORTAL_REVIEWS_OPTION);
            if (empty($reviews[$review_id])||!is_array($reviews[$review_id])) { return new WP_Error('wsm_athlete_review_missing','اعلان بررسی تمدید پیدا نشد.',array('status'=>404)); }
            $review=$reviews[$review_id];
            if ((string)$review['status']!=='pending') { return array('item'=>$review,'idempotent_replay'=>true); }
            $device=class_exists('WSM_Permissions')?WSM_Permissions::current_device():null;
            $resolved_by=$device?absint($device->user_id):absint(get_current_user_id());
            if ($action==='approve') {
                $review['status']='approved';$review['resolved_at']=current_time('mysql');$review['resolved_by_user_id']=$resolved_by;
                $reviews[$review_id]=$review;update_option(self::PORTAL_REVIEWS_OPTION,$reviews,false);
                return array('item'=>$review,'updated'=>false);
            }
            $start=self::athlete_portal_parse_date($body['start_date'] ?? ($body['date'] ?? ''));
            if (!$start) { return new WP_Error('wsm_athlete_review_date_invalid','تاریخ نهایی را به‌صورت شمسی یا میلادی معتبر وارد کنید.',array('status'=>400)); }
            $duration=max(1,min(3660,(int)($review['duration_days'] ?? 30)));
            $end=self::athlete_portal_date_add($start,$duration-1);
            if (!$end) { return new WP_Error('wsm_athlete_review_date_invalid','محاسبهٔ تاریخ پایان عضویت انجام نشد.',array('status'=>400)); }
            global $wpdb;$table=WSM_Club::table('memberships');
            if ($wpdb->query('START TRANSACTION')===false) { return new WP_Error('wsm_athlete_review_transaction','آغاز ویرایش تاریخ عضویت انجام نشد.',array('status'=>500)); }
            $membership=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND member_id=%d FOR UPDATE",absint($review['membership_id'] ?? 0),absint($review['member_id'] ?? 0)));
            if (!$membership) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_review_membership_missing','عضویت مربوط به اعلان پیدا نشد.',array('status'=>404)); }
            if ((string)$membership->status==='cancelled') { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_review_membership_cancelled','عضویت لغوشده قابل ویرایش نیست.',array('status'=>409)); }
            $wpdb->last_error='';
            $overlap=$wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE member_id=%d AND id<>%d AND status IN ('active','scheduled','frozen') AND start_date <= %s AND end_date >= %s LIMIT 1 FOR UPDATE",(int)$membership->member_id,(int)$membership->id,$end,$start));
            if (!empty($wpdb->last_error)) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_review_overlap_lookup_failed','بررسی هم‌پوشانی تاریخ عضویت انجام نشد.',array('status'=>500)); }
            if ($overlap) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_review_overlap','تاریخ انتخابی با عضویت دیگری هم‌پوشانی دارد.',array('status'=>409,'membership_id'=>(int)$overlap->id)); }
            $new_status=$start>self::athlete_portal_today()?'scheduled':'active';
            $updated=$wpdb->update($table,array('start_date'=>$start,'end_date'=>$end,'status'=>$new_status,'updated_at'=>current_time('mysql')),array('id'=>(int)$membership->id,'member_id'=>(int)$membership->member_id));
            if ($updated===false) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_review_membership_update_failed','ویرایش تاریخ عضویت انجام نشد.',array('status'=>500)); }
            $wpdb->query('COMMIT');
            $review['status']='edited';$review['resolved_at']=current_time('mysql');$review['resolved_by_user_id']=$resolved_by;$review['original_start_date']=(string)$membership->start_date;$review['final_start_date']=$start;$review['final_end_date']=$end;
            $reviews[$review_id]=$review;update_option(self::PORTAL_REVIEWS_OPTION,$reviews,false);
            return array('item'=>$review,'membership'=>array('id'=>(int)$membership->id,'start_date'=>$start,'end_date'=>$end,'status'=>$new_status),'updated'=>true);
        } finally { self::athlete_portal_release_lock($lock); }
    }

    private static function athlete_portal_default_prizes() {
        return array(
            array('rank'=>1,'title'=>'جایزهٔ نفر اول','winners_count'=>1,'discount_percent'=>50),
            array('rank'=>2,'title'=>'جایزهٔ نفر دوم','winners_count'=>1,'discount_percent'=>30),
            array('rank'=>3,'title'=>'جایزهٔ نفر سوم','winners_count'=>1,'discount_percent'=>20),
        );
    }

    private static function athlete_portal_new_raffle_record() {
        try { $id='raffle_'.bin2hex(random_bytes(10)); }
        catch (Throwable $e) { return new WP_Error('wsm_athlete_raffle_random_failed','ساخت شناسهٔ دورهٔ قرعه‌کشی انجام نشد.',array('status'=>500)); }
        $today=self::athlete_portal_today();$year=(int)substr($today,0,4);
        return array('id'=>$id,'title'=>'قرعه‌کشی تمدید عضویت '.$year,'start_date'=>$today,'draw_date'=>sprintf('%04d-12-31',$year),'auto_draw'=>0,'status'=>'active','prizes'=>self::athlete_portal_default_prizes(),'participants'=>array(),'ineligible_logs'=>array(),'winners'=>array(),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),'drawn_at'=>'','draw_trigger'=>'');
    }

    public static function ensure_active_raffle() {
        $raffles=self::athlete_portal_option_array(self::PORTAL_RAFFLES_OPTION);
        foreach ($raffles as $raffle) { if (is_array($raffle)&&(string)($raffle['status'] ?? '')==='active') { return $raffle; } }
        $closed=array_values(array_filter($raffles,function($raffle){return is_array($raffle)&&in_array((string)($raffle['status'] ?? ''),array('drawn','closed'),true);}));
        if ($closed) {
            usort($closed,function($a,$b){return strcmp((string)($b['updated_at'] ?? $b['created_at'] ?? ''),(string)($a['updated_at'] ?? $a['created_at'] ?? ''));});
            return $closed[0];
        }
        $raffle=self::athlete_portal_new_raffle_record();
        if (is_wp_error($raffle)) { return $raffle; }
        $raffles[(string)$raffle['id']]=$raffle;update_option(self::PORTAL_RAFFLES_OPTION,$raffles,false);
        return $raffle;
    }

    public static function maybe_auto_draw_due_raffles() {
        $raffles=self::athlete_portal_option_array(self::PORTAL_RAFFLES_OPTION);$today=self::athlete_portal_today();$results=array();
        foreach ($raffles as $id=>$raffle) {
            if (!is_array($raffle)||(string)($raffle['status'] ?? '')!=='active'||empty($raffle['auto_draw'])||(string)($raffle['draw_date'] ?? '')>$today) { continue; }
            $results[$id]=self::execute_raffle_draw((string)$id,'automatic');
        }
        return $results;
    }

    public static function evaluate_raffle_on_membership_created($context,$membership_id=0,$extra=array()) {
        if (!is_array($context)) { $context=is_array($extra)?$extra:array();$context['member_id']=absint($context['member_id'] ?? 0);$context['membership_id']=absint($membership_id); }
        $member_id=absint($context['member_id'] ?? 0);$membership_id=absint($context['membership_id'] ?? 0);
        if (!$member_id||!$membership_id) { return new WP_Error('wsm_athlete_raffle_membership_invalid','عضویت برای بررسی قرعه‌کشی معتبر نیست.',array('status'=>400)); }
        $raffle=self::ensure_active_raffle();
        if (is_wp_error($raffle)) { return $raffle; }
        $raffles=self::athlete_portal_option_array(self::PORTAL_RAFFLES_OPTION);$raffle_id=(string)$raffle['id'];
        if (empty($raffles[$raffle_id])||!is_array($raffles[$raffle_id])) { return new WP_Error('wsm_athlete_raffle_missing','دورهٔ فعال قرعه‌کشی پیدا نشد.',array('status'=>500)); }
        $raffle=$raffles[$raffle_id];$prior_count=max(0,(int)($context['prior_any_membership_count'] ?? 0));
        $had_unexpired=isset($context['had_unexpired_override'])&&$context['had_unexpired_override']!==null?(bool)$context['had_unexpired_override']:!empty($context['prior_unexpired_membership']);
        $prior_end=trim((string)($context['prior_end_date_override'] ?? ''));
        if ($prior_end==='' && !empty($context['prior_unexpired_membership'])) { $prior_end=is_object($context['prior_unexpired_membership'])?(string)($context['prior_unexpired_membership']->end_date ?? ''):(string)($context['prior_unexpired_membership']['end_date'] ?? ''); }
        if ($prior_end==='' && !empty($context['previous_end_date'])) { $prior_end=(string)$context['previous_end_date']; }
        global $wpdb;
        if ($prior_end==='' && $prior_count>0) {
            $wpdb->last_error='';
            $prior_end_value=$wpdb->get_var($wpdb->prepare("SELECT end_date FROM ".WSM_Club::table('memberships')." WHERE member_id=%d AND id<>%d AND status<>'cancelled' ORDER BY end_date DESC,id DESC LIMIT 1",$member_id,$membership_id));
            if (empty($wpdb->last_error) && is_scalar($prior_end_value)) { $prior_end=(string)$prior_end_value; }
        }
        $memberships=is_array($raffle['participants'] ?? null)?$raffle['participants']:array();$logs=is_array($raffle['ineligible_logs'] ?? null)?$raffle['ineligible_logs']:array();
        $member=self::athlete_portal_find_member_by_id($member_id);
        if (is_wp_error($member)) { return $member; }
        if (!$member) { return new WP_Error('wsm_athlete_member_missing','ورزشکار دورهٔ قرعه‌کشی پیدا نشد.',array('status'=>404)); }
        if ((string)($raffle['status'] ?? '')!=='active') {
            $result=array('eligible'=>isset($memberships[(string)$member_id]),'reason'=>'period_closed');
        } elseif ($had_unexpired && $prior_count>0) {
            if (!isset($memberships[(string)$member_id]) || (int)($memberships[(string)$member_id]['membership_id'] ?? 0)<$membership_id) {
                $memberships[(string)$member_id]=array('member_id'=>$member_id,'membership_id'=>$membership_id,'name'=>trim((string)$member->first_name.' '.(string)$member->last_name),'member_code'=>(string)$member->member_code,'phone'=>(string)$member->phone,'plan_name'=>(string)($context['plan_name'] ?? ''),'prior_end_date'=>$prior_end,'renewal_date'=>self::athlete_portal_today(),'source'=>(string)($context['source'] ?? 'staff_panel'),'source_label'=>(string)($context['source'] ?? '')==='athlete_portal'?'پورتال آنلاین ورزشکار':'پنل مدیریت (کارمند)');
            }
            unset($logs[(string)$member_id]);$result=array('eligible'=>true,'reason'=>'eligible');
        } elseif (isset($memberships[(string)$member_id])) {
            unset($logs[(string)$member_id]);$result=array('eligible'=>true,'reason'=>'eligible');
        } else {
            $reason=$prior_count<1?'first_month':'expired_before_renewal';
            $logs[(string)$member_id]=array('member_id'=>$member_id,'name'=>trim((string)$member->first_name.' '.(string)$member->last_name),'member_code'=>(string)$member->member_code,'reason'=>$reason,'membership_id'=>$membership_id,'created_at'=>current_time('mysql'));
            $result=array('eligible'=>false,'reason'=>$reason);
        }
        $raffle['participants']=$memberships;$raffle['ineligible_logs']=$logs;$raffle['updated_at']=current_time('mysql');$raffles[$raffle_id]=$raffle;
        update_option(self::PORTAL_RAFFLES_OPTION,$raffles,false);
        return $result;
    }

    public static function generate_unique_raffle_coupon_code($existing_coupons) {
        $existing_coupons=is_array($existing_coupons)?$existing_coupons:array();$year=(int)current_time('Y');$alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt=0;$attempt<100;$attempt++) {
            try { $suffix='';for($i=0;$i<4;$i++){$suffix.=$alphabet[random_int(0,strlen($alphabet)-1)];} }
            catch (Throwable $e) { return new WP_Error('wsm_athlete_coupon_random_failed','تولید کد تخفیف امن انجام نشد.',array('status'=>500)); }
            $code='WIN-'.$year.'-'.$suffix;
            if (isset($existing_coupons[$code])) { continue; }
            if (function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($code)) { continue; }
            return $code;
        }
        return new WP_Error('wsm_athlete_coupon_code_exhausted','کد تخفیف یکتای تازه تولید نشد.',array('status'=>500));
    }

    private static function create_athlete_portal_wc_coupon($code,$percent,$expires_at,$email='') {
        if (!class_exists('WC_Coupon')) { return 0; }
        try {
            if (function_exists('wc_get_coupon_id_by_code')) {
                $existing=wc_get_coupon_id_by_code($code);
                if ($existing) { return (int)$existing; }
            }
            $coupon=new WC_Coupon();$coupon->set_code($code);$coupon->set_discount_type('percent');$coupon->set_amount((string)$percent);
            $coupon->set_usage_limit(1);$coupon->set_usage_limit_per_user(1);$coupon->set_individual_use(true);
            $coupon->set_date_expires(strtotime((string)$expires_at.' 23:59:59'));
            if ($email!=='' && method_exists($coupon,'set_email_restrictions')) { $coupon->set_email_restrictions(array($email)); }
            return (int)$coupon->save();
        } catch (Throwable $e) { return 0; }
    }

    public static function execute_raffle_draw($raffle_id,$trigger='manual') {
        $raffle_id=is_scalar($raffle_id)?sanitize_text_field((string)$raffle_id):'';
        if (!preg_match('/^raffle_[a-f0-9]{20}$/',$raffle_id)) { return new WP_Error('wsm_athlete_raffle_id_invalid','شناسهٔ دورهٔ قرعه‌کشی معتبر نیست.',array('status'=>400)); }
        $lock=self::athlete_portal_acquire_lock('raffle_draw',$raffle_id,180);
        if (is_wp_error($lock)) { return $lock; }
        try {
            $raffles=self::athlete_portal_option_array(self::PORTAL_RAFFLES_OPTION);
            if (empty($raffles[$raffle_id])||!is_array($raffles[$raffle_id])) { return new WP_Error('wsm_athlete_raffle_missing','دورهٔ قرعه‌کشی پیدا نشد.',array('status'=>404)); }
            $raffle=$raffles[$raffle_id];
            if ((string)($raffle['status'] ?? '')==='drawn') { return array('raffle'=>$raffle,'idempotent_replay'=>true); }
            if ((string)($raffle['status'] ?? '')!=='active') { return new WP_Error('wsm_athlete_raffle_inactive','این دورهٔ قرعه‌کشی فعال نیست.',array('status'=>409)); }
            $participants=is_array($raffle['participants'] ?? null)?$raffle['participants']:array();$pool=array_values($participants);$winners=array();$coupons=self::athlete_portal_option_array(self::PORTAL_COUPONS_OPTION);$prizes=is_array($raffle['prizes'] ?? null)?$raffle['prizes']:self::athlete_portal_default_prizes();
            usort($prizes,function($a,$b){return (int)($a['rank'] ?? 0)<=>(int)($b['rank'] ?? 0);});
            foreach ($prizes as $prize) {
                $rank=max(1,(int)($prize['rank'] ?? 1));$count=max(0,min(100,(int)($prize['winners_count'] ?? 1)));$percent=max(1,min(100,(float)($prize['discount_percent'] ?? 0)));$title=self::text($prize['title'] ?? ('جایزهٔ نفر '.$rank),120);
                for ($i=0;$i<$count && $pool;$i++) {
                    try { $index=random_int(0,count($pool)-1); }
                    catch (Throwable $e) { return new WP_Error('wsm_athlete_raffle_random_failed','قرعه‌کشی امن اجرا نشد.',array('status'=>500)); }
                    $participant=array_splice($pool,$index,1);$participant=$participant?$participant[0]:array();$member_id=absint($participant['member_id'] ?? 0);
                    if (!$member_id) { continue; }
                    $code=self::generate_unique_raffle_coupon_code($coupons);
                    if (is_wp_error($code)) { return $code; }
                    $expiry=(new DateTimeImmutable('today',wp_timezone()))->modify('+90 days')->format('Y-m-d');
                    $member=self::athlete_portal_find_member_by_id($member_id);
                    if (is_wp_error($member)) { $member=null; }
                    $email=$member&&is_email((string)$member->email)?(string)$member->email:'';
                    $wc_coupon_id=self::create_athlete_portal_wc_coupon($code,$percent,$expiry,$email);
                    $coupon=array('code'=>$code,'raffle_id'=>$raffle_id,'member_id'=>$member_id,'member_name'=>(string)($participant['name'] ?? ''),'member_code'=>(string)($participant['member_code'] ?? ''),'rank'=>$rank,'prize_title'=>$title,'discount_percent'=>$percent,'status'=>'unused','expires_at'=>$expiry,'created_at'=>current_time('mysql'),'used_at'=>'','used_by_intent'=>'','reserved_by'=>'','reserved_until'=>0,'wc_coupon_id'=>$wc_coupon_id);
                    $coupons[$code]=$coupon;
                    $winners[]=array('member_id'=>$member_id,'display_name'=>(string)($participant['name'] ?? ''),'member_code'=>(string)($participant['member_code'] ?? ''),'rank'=>$rank,'prize_title'=>$title,'discount_percent'=>$percent,'coupon_code'=>$code,'expires_at'=>$expiry);
                }
            }
            $raffle['status']='drawn';$raffle['winners']=$winners;$raffle['drawn_at']=current_time('mysql');$raffle['draw_trigger']=sanitize_key((string)$trigger);$raffle['updated_at']=current_time('mysql');
            $raffles[$raffle_id]=$raffle;update_option(self::PORTAL_COUPONS_OPTION,$coupons,false);update_option(self::PORTAL_RAFFLES_OPTION,$raffles,false);
            foreach ($winners as $winner) {
                $member=self::athlete_portal_find_member_by_id((int)$winner['member_id']);
                if (is_wp_error($member)||!$member) { continue; }
                $message='تبریک! شما برندهٔ '.$winner['prize_title'].' شدید. کد تخفیف '.$winner['coupon_code'].' با '.$winner['discount_percent'].'٪ تخفیف تا '.$winner['expires_at'].' معتبر است.';
                self::athlete_portal_send_sms($member,'raffle_winner',$message);
            }
            return array('raffle'=>$raffle,'winners'=>$winners,'idempotent_replay'=>false);
        } finally { self::athlete_portal_release_lock($lock); }
    }

    public static function mark_athlete_portal_coupon_used($raw_code,$member_id,$intent_id='') {
        $code=strtoupper(trim(sanitize_text_field(is_scalar($raw_code)?(string)$raw_code:'')));$member_id=absint($member_id);$intent_id=is_scalar($intent_id)?sanitize_text_field((string)$intent_id):'';
        if ($code==='') { return new WP_Error('wsm_athlete_coupon_missing','کد تخفیف معتبر نیست.',array('status'=>400)); }
        $lock=self::athlete_portal_acquire_lock('coupon',$code,30);
        if (is_wp_error($lock)) { return $lock; }
        try {
            $coupons=self::athlete_portal_option_array(self::PORTAL_COUPONS_OPTION);
            if (empty($coupons[$code])||!is_array($coupons[$code])) { return new WP_Error('wsm_athlete_coupon_missing','کد تخفیف پیدا نشد.',array('status'=>400)); }
            $coupon=$coupons[$code];
            if ((int)($coupon['member_id'] ?? 0)!==$member_id) { return new WP_Error('wsm_athlete_coupon_owner','این کد تخفیف به پروندهٔ شما تعلق ندارد.',array('status'=>403)); }
            if ((string)($coupon['status'] ?? '')==='used') {
                return (string)($coupon['used_by_intent'] ?? '')===$intent_id ? array('coupon'=>$coupon,'idempotent_replay'=>true) : new WP_Error('wsm_athlete_coupon_used','این کد تخفیف قبلاً مصرف شده است.',array('status'=>409));
            }
            if ((string)($coupon['status'] ?? '')==='reserved' && (string)($coupon['reserved_by'] ?? '')!==$intent_id) { return new WP_Error('wsm_athlete_coupon_reserved','کد تخفیف در پرداخت دیگری رزرو شده است.',array('status'=>409)); }
            if (strtotime((string)($coupon['expires_at'] ?? ''))<strtotime(self::athlete_portal_today())) { return new WP_Error('wsm_athlete_coupon_expired','اعتبار کد تخفیف به پایان رسیده است.',array('status'=>400)); }
            $coupon['status']='used';$coupon['used_at']=current_time('mysql');$coupon['used_by_intent']=$intent_id;$coupon['reserved_by']='';$coupon['reserved_until']=0;$coupons[$code]=$coupon;
            update_option(self::PORTAL_COUPONS_OPTION,$coupons,false);
            return array('coupon'=>$coupon,'idempotent_replay'=>false);
        } finally { self::athlete_portal_release_lock($lock); }
    }

    public static function get_member_raffle_portal_data($member_id,$has_unexpired_membership=false,$total_memberships_count=0) {
        $member_id=absint($member_id);$raffle=self::ensure_active_raffle();
        if (is_wp_error($raffle)) { return array('eligible'=>false,'status_message'=>'وضعیت قرعه‌کشی در دسترس نیست.','participants_count'=>0,'prizes'=>array(),'winners'=>array(),'my_coupons'=>array()); }
        $participants=is_array($raffle['participants'] ?? null)?$raffle['participants']:array();$logs=is_array($raffle['ineligible_logs'] ?? null)?$raffle['ineligible_logs']:array();
        $entry=isset($participants[(string)$member_id])?$participants[(string)$member_id]:null;$log=isset($logs[(string)$member_id])?$logs[(string)$member_id]:null;$winners=is_array($raffle['winners'] ?? null)?$raffle['winners']:array();
        $my_winner=null;foreach ($winners as $winner) { if ((int)($winner['member_id'] ?? 0)===$member_id) { $my_winner=$winner;break; } }
        $eligible=(bool)$entry;$reason=(string)($log['reason'] ?? '');
        if ($my_winner) { $message='تبریک ویژه! شما برندهٔ قرعه‌کشی این دوره شده‌اید.'; }
        elseif ($eligible) { $message='تبریک! شما در قرعه‌کشی این دوره شرکت داده شدید.'; }
        elseif ($reason==='first_month'||(int)$total_memberships_count<1) { $message='ثبت‌نام ماه اول است؛ پس از تمدید پیش از انقضا در دوره‌های بعدی واجد شرایط می‌شوید.'; }
        elseif ($reason==='expired_before_renewal'||empty($has_unexpired_membership)) { $message='برای شرکت در قرعه‌کشی، عضویت را پیش از تاریخ انقضا تمدید کنید.'; }
        else { $message='تمدید واجد شرایطی برای دورهٔ فعلی ثبت نشده است.'; }
        $coupons=self::athlete_portal_option_array(self::PORTAL_COUPONS_OPTION);$my_coupons=array();
        foreach ($coupons as $code=>$coupon) {
            if (!is_array($coupon)||(int)($coupon['member_id'] ?? 0)!==$member_id) { continue; }
            $status=(string)($coupon['status'] ?? 'unused');$status_label=$status==='used'?'مصرف‌شده':($status==='reserved'?'در انتظار پرداخت':($status==='expired'?'منقضی':'قابل استفاده'));
            $my_coupons[]=array('code'=>(string)$code,'discount_percent'=>(float)($coupon['discount_percent'] ?? 0),'expires_at'=>(string)($coupon['expires_at'] ?? ''),'expires_at_jalali'=>self::athlete_portal_format_jalali((string)($coupon['expires_at'] ?? '')),'status'=>$status,'status_label'=>$status_label,'used_at'=>(string)($coupon['used_at'] ?? ''),'prize_title'=>(string)($coupon['prize_title'] ?? ''));
        }
        usort($my_coupons,function($a,$b){return strcmp((string)$a['expires_at'],(string)$b['expires_at']);});
        $winners_public=array();foreach ($winners as $winner) { $winners_public[]=array('display_name'=>(string)($winner['display_name'] ?? ''),'rank'=>(int)($winner['rank'] ?? 0),'prize_title'=>(string)($winner['prize_title'] ?? ''),'discount_percent'=>(float)($winner['discount_percent'] ?? 0)); }
        return array('raffle_id'=>(string)$raffle['id'],'title'=>(string)$raffle['title'],'status'=>(string)$raffle['status'],'eligible'=>$eligible,'reason'=>$reason,'status_message'=>$message,'participants_count'=>count($participants),'prizes'=>array_values(is_array($raffle['prizes'] ?? null)?$raffle['prizes']:self::athlete_portal_default_prizes()),'winners'=>$winners_public,'my_winner'=>$my_winner?array('rank'=>(int)$my_winner['rank'],'prize_title'=>(string)$my_winner['prize_title'],'coupon_code'=>(string)$my_winner['coupon_code']):null,'my_coupons'=>$my_coupons);
    }

    public static function get_club_raffles_rest($request) {
        self::maybe_auto_draw_due_raffles();
        $active=self::ensure_active_raffle();if(is_wp_error($active)){return $active;}
        $raffles=self::athlete_portal_option_array(self::PORTAL_RAFFLES_OPTION);$items=array();$participants_count=0;$winners_count=0;$active_out=$active;
        foreach ($raffles as $raffle) { if(!is_array($raffle)){continue;}$raffle['participants']=array_values(is_array($raffle['participants'] ?? null)?$raffle['participants']:array());$raffle['ineligible_logs']=array_values(is_array($raffle['ineligible_logs'] ?? null)?$raffle['ineligible_logs']:array());$items[]=$raffle;if((string)$raffle['id']===(string)$active['id']){$participants_count=count($raffle['participants']);$winners_count=count((array)($raffle['winners'] ?? array()));$active_out=$raffle;} }
        usort($items,function($a,$b){return strcmp((string)($b['created_at'] ?? ''),(string)($a['created_at'] ?? ''));});
        $coupon_bank=self::athlete_portal_option_array(self::PORTAL_COUPONS_OPTION);$coupon_items=array();$unused=0;
        foreach ($coupon_bank as $code=>$coupon) { if(!is_array($coupon)){continue;}$coupon['code']=(string)$code;$coupon_items[]=$coupon;if((string)($coupon['status'] ?? '')==='unused'){$unused++;} }
        return array('active'=>$active_out,'items'=>$items,'coupons'=>$coupon_items,'stats'=>array('participants'=>$participants_count,'winners'=>$winners_count,'coupons'=>count($coupon_items),'unused_coupons'=>$unused));
    }

    public static function save_club_raffle_rest($request) {
        $body=self::athlete_portal_request_body($request);$raffles=self::athlete_portal_option_array(self::PORTAL_RAFFLES_OPTION);
        if (!empty($body['new_cycle'])) {
            foreach ($raffles as $id=>$raffle) { if(is_array($raffle)&&(string)($raffle['status'] ?? '')==='active'){$raffles[$id]['status']='closed';$raffles[$id]['closed_at']=current_time('mysql');} }
            $record=self::athlete_portal_new_raffle_record();if(is_wp_error($record)){return $record;}
            $raffles[(string)$record['id']]=$record;update_option(self::PORTAL_RAFFLES_OPTION,$raffles,false);return array('raffle'=>$record,'created'=>true);
        }
        $id=isset($body['id'])&&is_scalar($body['id'])?sanitize_text_field((string)$body['id']):'';
        if ($id==='') { $active=self::ensure_active_raffle();if(is_wp_error($active)){return $active;}$id=(string)$active['id']; }
        if (!preg_match('/^raffle_[a-f0-9]{20}$/',$id)||empty($raffles[$id])||!is_array($raffles[$id])) { return new WP_Error('wsm_athlete_raffle_missing','دورهٔ قرعه‌کشی برای ویرایش پیدا نشد.',array('status'=>404)); }
        if ((string)($raffles[$id]['status'] ?? '')!=='active') { return new WP_Error('wsm_athlete_raffle_inactive','فقط دورهٔ فعال قرعه‌کشی قابل ویرایش است.',array('status'=>409)); }
        $title=isset($body['title'])&&is_scalar($body['title'])?self::text($body['title'],120):'';
        if ($title==='') { return new WP_Error('wsm_athlete_raffle_title_invalid','عنوان دورهٔ قرعه‌کشی الزامی است.',array('status'=>400)); }
        $start=self::athlete_portal_parse_date($body['start_date'] ?? '');$draw=self::athlete_portal_parse_date($body['draw_date'] ?? '');
        if (!$start||!$draw||$draw<$start) { return new WP_Error('wsm_athlete_raffle_date_invalid','تاریخ‌های شروع و قرعه‌کشی معتبر نیستند.',array('status'=>400)); }
        $input_prizes=isset($body['prizes'])&&is_array($body['prizes'])?$body['prizes']:array();$defaults=self::athlete_portal_default_prizes();$prizes=array();
        for($rank=1;$rank<=3;$rank++){
            $entry=isset($input_prizes[$rank])&&is_array($input_prizes[$rank])?$input_prizes[$rank]:(isset($input_prizes[$rank-1])&&is_array($input_prizes[$rank-1])?$input_prizes[$rank-1]:array());
            if(!$entry){$entry=array('title'=>$body['prize_'.$rank.'_title'] ?? $defaults[$rank-1]['title'],'winners_count'=>$body['prize_'.$rank.'_winners'] ?? $defaults[$rank-1]['winners_count'],'discount_percent'=>$body['prize_'.$rank.'_percent'] ?? $defaults[$rank-1]['discount_percent']);}
            $prize_title=isset($entry['title'])&&is_scalar($entry['title'])?self::text($entry['title'],120):'';
            $winners=isset($entry['winners_count'])&&is_numeric($entry['winners_count'])?(int)$entry['winners_count']:$defaults[$rank-1]['winners_count'];
            $percent=isset($entry['discount_percent'])&&is_numeric($entry['discount_percent'])?(float)$entry['discount_percent']:$defaults[$rank-1]['discount_percent'];
            if($prize_title===''||$winners<0||$winners>100||$percent<1||$percent>100){return new WP_Error('wsm_athlete_raffle_prize_invalid','عنوان، تعداد برندگان یا درصد یکی از جوایز معتبر نیست.',array('status'=>400,'rank'=>$rank));}
            $prizes[]=array('rank'=>$rank,'title'=>$prize_title,'winners_count'=>$winners,'discount_percent'=>$percent);
        }
        $raffles[$id]['title']=$title;$raffles[$id]['start_date']=$start;$raffles[$id]['draw_date']=$draw;$raffles[$id]['auto_draw']=!empty($body['auto_draw'])&&rest_sanitize_boolean($body['auto_draw'])?1:0;$raffles[$id]['prizes']=$prizes;$raffles[$id]['updated_at']=current_time('mysql');
        update_option(self::PORTAL_RAFFLES_OPTION,$raffles,false);
        return array('raffle'=>$raffles[$id],'saved'=>true);
    }

    public static function draw_club_raffle_rest($request) {
        $id=is_object($request)&&method_exists($request,'get_param')?sanitize_text_field((string)$request->get_param('id')):'';
        return self::execute_raffle_draw($id,'manual');
    }


    /** Portal helpers are deliberately isolated from the employee/device token domain. */
    private static function athlete_portal_option_array($name) {
        $value = get_option($name, array());
        return is_array($value) ? $value : array();
    }

    private static function athlete_portal_enabled() {
        $settings = self::settings();
        return !empty($settings['portal_enabled']);
    }

    private static function athlete_portal_today() {
        return function_exists('current_time') ? current_time('Y-m-d') : gmdate('Y-m-d');
    }

    private static function athlete_portal_normalize_digits($value) {
        return strtr((string)$value, array(
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ));
    }

    private static function athlete_portal_normalize_phone($value) {
        $raw = is_scalar($value) ? (string)$value : '';
        if (class_exists('WSM_Persian_Normalizer') && method_exists('WSM_Persian_Normalizer','normalize')) {
            $raw = WSM_Persian_Normalizer::normalize($raw);
        }
        $digits = preg_replace('/\D/u', '', self::athlete_portal_normalize_digits($raw));
        if (strpos($digits, '0098') === 0 && strlen($digits) === 14) { $digits = '0'.substr($digits, 4); }
        elseif (strpos($digits, '98') === 0 && strlen($digits) === 12) { $digits = '0'.substr($digits, 2); }
        elseif (strlen($digits) === 10 && strpos($digits, '9') === 0) { $digits = '0'.$digits; }
        if (strlen($digits) < 7 || strlen($digits) > 15) { return ''; }
        return $digits;
    }

    private static function athlete_portal_find_member_by_phone($phone) {
        global $wpdb;
        $phone = self::athlete_portal_normalize_phone($phone);
        if ($phone === '') { return null; }
        $members = WSM_Club::table('members');
        $suffix = substr($phone, -7);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $members WHERE status='active' AND phone LIKE %s ORDER BY id DESC LIMIT 50", '%'.$wpdb->esc_like($suffix)));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_member_lookup_failed','خواندن پروندهٔ ورزشکار انجام نشد.',array('status'=>500)); }
        $matches = array();
        foreach ((array)$rows as $row) {
            if (self::athlete_portal_normalize_phone(isset($row->phone)?$row->phone:'') === $phone) { $matches[] = $row; }
        }
        if (count($matches) > 1) { return new WP_Error('wsm_athlete_phone_ambiguous','برای این شماره بیش از یک پروندهٔ فعال پیدا شد؛ با پذیرش باشگاه تماس بگیرید.',array('status'=>409)); }
        return $matches ? $matches[0] : null;
    }

    private static function athlete_portal_find_member_by_id($member_id) {
        global $wpdb;
        $member_id = absint($member_id);
        if (!$member_id) { return null; }
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.WSM_Club::table('members').' WHERE id=%d LIMIT 1',$member_id));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_member_lookup_failed','خواندن پروندهٔ ورزشکار انجام نشد.',array('status'=>500)); }
        return $row;
    }

    private static function athlete_portal_rate_limit($scope, $identity, $limit, $window) {
        $identity = is_scalar($identity) ? (string)$identity : '';
        $key = 'wsm_athlete_portal_rate_'.substr(hash('sha256',(string)$scope.'|'.$identity),0,40);
        $count = (int)get_transient($key);
        if ($count >= (int)$limit) { return new WP_Error('wsm_athlete_rate_limited','تعداد تلاش‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.',array('status'=>429)); }
        set_transient($key,$count+1,max(1,(int)$window));
        return true;
    }

    private static function athlete_portal_login_failure_key($identity) {
        return 'wsm_athlete_login_fail_'.substr(hash('sha256',(string)$identity),0,40);
    }

    private static function athlete_portal_record_login_failure($identity) {
        $key = self::athlete_portal_login_failure_key($identity);
        $count = max(0,(int)get_transient($key)) + 1;
        set_transient($key,$count,15*MINUTE_IN_SECONDS);
        return $count;
    }

    private static function athlete_portal_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string)$_SERVER['REMOTE_ADDR']) : '';
        return filter_var($ip,FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    private static function athlete_portal_credentials() {
        return self::athlete_portal_option_array(self::PORTAL_CREDS_OPTION);
    }

    private static function athlete_password_hash($member) {
        $member_id = is_object($member) && isset($member->id) ? absint($member->id) : 0;
        if (!$member_id) { return ''; }
        $credentials = self::athlete_portal_credentials();
        $record = isset($credentials[(string)$member_id]) && is_array($credentials[(string)$member_id]) ? $credentials[(string)$member_id] : array();
        return isset($record['password_hash']) && is_scalar($record['password_hash']) ? (string)$record['password_hash'] : '';
    }

    private static function athlete_default_password($member) {
        $phone = is_object($member) && isset($member->phone) ? self::athlete_portal_normalize_phone($member->phone) : '';
        if (!preg_match('/^09[0-9]{9}$/', $phone)) { return ''; }
        return substr($phone, -4);
    }

    private static function athlete_password_length($password) {
        $password = (string)$password;
        if (function_exists('mb_strlen')) { return (int)mb_strlen($password,'UTF-8'); }
        $count = preg_match_all('/./us',$password,$matches);
        return $count === false ? strlen($password) : (int)$count;
    }

    private static function athlete_verify_password($member, $candidate) {
        $candidate = is_scalar($candidate) ? (string)$candidate : '';
        if ($candidate === '') { return false; }
        $password_hash = self::athlete_password_hash($member);
        if ($password_hash !== '') {
            if (function_exists('wp_check_password') && wp_check_password($candidate, $password_hash)) { return true; }
            $normalized = self::athlete_portal_normalize_digits($candidate);
            return $normalized !== $candidate && $normalized !== '' && function_exists('wp_check_password') && wp_check_password($normalized, $password_hash);
        }
        $default = self::athlete_default_password($member);
        if ($default === '') { return false; }
        $normalized = self::athlete_portal_normalize_digits(trim($candidate));
        return $normalized !== '' && hash_equals($default, $normalized);
    }

    private static function athlete_token_signing_secret($member) {
        $member_id = is_object($member) && isset($member->id) ? absint($member->id) : 0;
        $status = is_object($member) && isset($member->status) ? (string)$member->status : '';
        $salt = function_exists('wp_salt') ? (string)wp_salt('auth') : (defined('AUTH_KEY') ? (string)AUTH_KEY : '');
        return hash('sha256',$salt.'|wsm_athlete_portal_v1|'.$member_id.'|'.self::athlete_password_hash($member).'|'.$status);
    }

    public static function issue_athlete_token($member, $recent_auth = false) {
        if (!is_object($member) || empty($member->id) || (string)($member->status ?? '') !== 'active') {
            return new WP_Error('wsm_athlete_token_member_invalid','پروندهٔ فعال ورزشکار پیدا نشد.',array('status'=>403));
        }
        $phone = self::athlete_portal_normalize_phone(isset($member->phone)?$member->phone:'');
        if ($phone === '') { return new WP_Error('wsm_athlete_phone_invalid','شمارهٔ موبایل پرونده معتبر نیست.',array('status'=>400)); }
        try { $jti = bin2hex(random_bytes(8)); }
        catch (Throwable $e) { return new WP_Error('wsm_athlete_token_random_failed','ساخت نشست امن انجام نشد.',array('status'=>500)); }
        $issued = time();
        $payload = array('scope'=>'athlete_portal','mid'=>(int)$member->id,'ph'=>substr(hash('sha256',$phone),0,16),'iat'=>$issued,'exp'=>$issued+7*DAY_IN_SECONDS,'jti'=>$jti);
        if ($recent_auth) { $payload['auth_at'] = $issued; }
        $json = wp_json_encode($payload);
        if (!is_string($json) || $json === '') { return new WP_Error('wsm_athlete_token_encode_failed','ساخت نشست امن انجام نشد.',array('status'=>500)); }
        $encoded = rtrim(strtr(base64_encode($json),'+/','-_'),'=');
        $signature = hash_hmac('sha256',$encoded,self::athlete_token_signing_secret($member));
        return 'wsma_'.$encoded.'.'.$signature;
    }

    private static function athlete_portal_extract_token($request) {
        if (!is_object($request) || !method_exists($request,'get_header')) { return ''; }
        $token = trim((string)$request->get_header('x-wsm-athlete-token'));
        if ($token === '') {
            $authorization = trim((string)$request->get_header('authorization'));
            if (preg_match('/^Bearer\s+(\S+)$/i',$authorization,$match)) { $token = $match[1]; }
        }
        return $token;
    }

    /** Only an HMAC-verified token issued by a fresh password/OTP login qualifies. */
    private static function athlete_token_has_recent_auth($token) {
        if (!is_string($token) || !preg_match('/^wsma_([A-Za-z0-9_-]{20,2000})\.[a-f0-9]{64}$/',$token,$match)) { return false; }
        $encoded = $match[1];
        $raw = base64_decode(strtr($encoded,'-_','+/').str_repeat('=',(4-strlen($encoded)%4)%4),true);
        $payload = is_string($raw) ? json_decode($raw,true) : null;
        $auth_at = is_array($payload) ? ($payload['auth_at'] ?? null) : null;
        return is_int($auth_at) && $auth_at <= time()+30 && $auth_at >= time()-15*MINUTE_IN_SECONDS;
    }

    private static function athlete_portal_member_from_request($request) {
        if (!self::athlete_portal_enabled()) { return new WP_Error('wsm_athlete_portal_disabled','پورتال ورزشکار در حال حاضر غیرفعال است.',array('status'=>403)); }
        $token = self::athlete_portal_extract_token($request);
        if ($token === '' || strpos($token,'wsma_') !== 0) { return new WP_Error('wsm_athlete_token_missing','نشست ورزشکار معتبر نیست؛ دوباره وارد شوید.',array('status'=>401)); }
        if (!preg_match('/^wsma_([A-Za-z0-9_-]{20,2000})\.([a-f0-9]{64})$/',$token,$match)) { return new WP_Error('wsm_athlete_token_invalid','نشست ورزشکار معتبر نیست.',array('status'=>401)); }
        $encoded = $match[1];
        $raw = base64_decode(strtr($encoded,'-_','+/').str_repeat('=',(4-strlen($encoded)%4)%4),true);
        $payload = is_string($raw) ? json_decode($raw,true) : null;
        if (!is_array($payload) || (string)($payload['scope'] ?? '') !== 'athlete_portal') { return new WP_Error('wsm_athlete_token_invalid','نشست ورزشکار معتبر نیست.',array('status'=>401)); }
        $member_id = isset($payload['mid']) ? absint($payload['mid']) : 0;
        $issued = isset($payload['iat']) ? (int)$payload['iat'] : 0;
        $expires = isset($payload['exp']) ? (int)$payload['exp'] : 0;
        if (!$member_id || !$issued || !$expires || $issued > time()+60 || $expires <= time() || $expires > $issued+7*DAY_IN_SECONDS+60) {
            return new WP_Error('wsm_athlete_token_expired','نشست ورزشکار منقضی یا نامعتبر است؛ دوباره وارد شوید.',array('status'=>401));
        }
        $member = self::athlete_portal_find_member_by_id($member_id);
        if (is_wp_error($member)) { return $member; }
        if (!$member || (string)$member->status !== 'active') { return new WP_Error('wsm_athlete_member_inactive','دسترسی این پرونده به پورتال فعال نیست.',array('status'=>403)); }
        $expected_phone_hash = substr(hash('sha256',self::athlete_portal_normalize_phone($member->phone)),0,16);
        if (!isset($payload['ph']) || !hash_equals($expected_phone_hash,(string)$payload['ph'])) { return new WP_Error('wsm_athlete_token_invalid','نشست ورزشکار معتبر نیست.',array('status'=>401)); }
        $expected = hash_hmac('sha256',$encoded,self::athlete_token_signing_secret($member));
        if (!hash_equals($expected,$match[2])) { return new WP_Error('wsm_athlete_token_invalid','نشست ورزشکار معتبر نیست یا رمز عبور تغییر کرده است.',array('status'=>401)); }
        return $member;
    }

    public static function athlete_portal_auth_permission($request) {
        $member = self::athlete_portal_member_from_request($request);
        if (is_wp_error($member)) { return $member; }
        self::$athlete_portal_current_member = $member;
        return true;
    }

    private static function athlete_portal_require_member($request) {
        $member = self::athlete_portal_member_from_request($request);
        if (!is_wp_error($member)) { self::$athlete_portal_current_member = $member; }
        return $member;
    }

    private static function athlete_portal_request_body($request) {
        if (is_object($request) && method_exists($request,'get_json_params')) {
            $body = $request->get_json_params();
            if (is_array($body)) { return $body; }
            $body = $request->get_body_params();
            if (is_array($body)) { return $body; }
        }
        return array();
    }

    public static function allow_athlete_portal_cors_headers($headers) {
        $headers = is_array($headers) ? $headers : array();
        $headers[] = 'X-WSM-Athlete-Token';
        return array_values(array_unique($headers));
    }

    public static function register_athlete_portal_rewrite() {
        if (!function_exists('add_rewrite_rule')) { return; }
        add_rewrite_rule('^athlete-portal/?$','index.php?wsm_athlete_portal=1','top');
        $rules = get_option('rewrite_rules',array());
        if (!is_array($rules) || !isset($rules['^athlete-portal/?$'])) { flush_rewrite_rules(false); }
    }

    public static function register_athlete_portal_query_vars($vars) {
        $vars = is_array($vars) ? $vars : array();
        if (!in_array('wsm_athlete_portal',$vars,true)) { $vars[] = 'wsm_athlete_portal'; }
        return $vars;
    }

    public static function register_athlete_portal_routes() {
        if (!function_exists('register_rest_route')) { return; }
        $public = '__return_true';
        register_rest_route('wsm/v1','/athlete-portal/bootstrap',array('methods'=>'GET','callback'=>array(__CLASS__,'athlete_portal_bootstrap_rest'),'permission_callback'=>$public));
        register_rest_route('wsm/v1','/athlete-portal/auth/login',array('methods'=>'POST','callback'=>array(__CLASS__,'athlete_portal_login_rest'),'permission_callback'=>$public));
        register_rest_route('wsm/v1','/athlete-portal/auth/otp-request',array('methods'=>'POST','callback'=>array(__CLASS__,'athlete_portal_otp_request_rest'),'permission_callback'=>$public));
        register_rest_route('wsm/v1','/athlete-portal/auth/otp-verify',array('methods'=>'POST','callback'=>array(__CLASS__,'athlete_portal_otp_verify_rest'),'permission_callback'=>$public));
        register_rest_route('wsm/v1','/athlete-portal/auth/change-password',array('methods'=>'POST','callback'=>array(__CLASS__,'athlete_portal_change_password_rest'),'permission_callback'=>array(__CLASS__,'athlete_portal_auth_permission')));
        register_rest_route('wsm/v1','/athlete-portal/auth/forgot-request',array('methods'=>'POST','callback'=>array(__CLASS__,'athlete_portal_forgot_request_rest'),'permission_callback'=>$public));
        register_rest_route('wsm/v1','/athlete-portal/auth/forgot-verify',array('methods'=>'POST','callback'=>array(__CLASS__,'athlete_portal_forgot_verify_rest'),'permission_callback'=>$public));
        register_rest_route('wsm/v1','/athlete-portal/me',array('methods'=>'GET','callback'=>array(__CLASS__,'athlete_portal_me_rest'),'permission_callback'=>array(__CLASS__,'athlete_portal_auth_permission')));
        register_rest_route('wsm/v1','/athlete-portal/support',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'athlete_support_inbox_rest'),'permission_callback'=>array(__CLASS__,'athlete_portal_auth_permission')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'athlete_support_send_rest'),'permission_callback'=>array(__CLASS__,'athlete_portal_auth_permission')),
        ));
        register_rest_route('wsm/v1','/club/athlete-support',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'staff_athlete_support_inbox_rest'),'permission_callback'=>array(__CLASS__,'portal_support_staff_permission')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'staff_athlete_support_reply_rest'),'permission_callback'=>array(__CLASS__,'portal_support_staff_permission')),
        ));
        register_rest_route('wsm/v1','/athlete-portal/checkout',array('methods'=>'POST','callback'=>array(__CLASS__,'athlete_portal_checkout_rest'),'permission_callback'=>array(__CLASS__,'athlete_portal_auth_permission')));
        register_rest_route('wsm/v1','/athlete-portal/debt/checkout',array('methods'=>'POST','callback'=>array(__CLASS__,'athlete_portal_debt_checkout_rest'),'permission_callback'=>array(__CLASS__,'athlete_portal_auth_permission')));
        register_rest_route('wsm/v1','/athlete-portal/coupon/validate',array('methods'=>'POST','callback'=>array(__CLASS__,'athlete_portal_validate_coupon_rest'),'permission_callback'=>array(__CLASS__,'athlete_portal_auth_permission')));
        register_rest_route('wsm/v1','/athlete-portal/payment/verify',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'athlete_portal_payment_verify_rest'),'permission_callback'=>$public),
            array('methods'=>'POST','callback'=>array(__CLASS__,'athlete_portal_payment_verify_rest'),'permission_callback'=>$public),
        ));
        register_rest_route('wsm/v1','/club/portal-reviews',array('methods'=>'GET','callback'=>array(__CLASS__,'get_portal_renewal_reviews_rest'),'permission_callback'=>array(__CLASS__,'staff_portal_review_permission')));
        register_rest_route('wsm/v1','/club/portal-reviews/(?P<id>[a-zA-Z0-9_-]+)/resolve',array('methods'=>'POST','callback'=>array(__CLASS__,'resolve_portal_renewal_review_rest'),'permission_callback'=>array(__CLASS__,'staff_portal_review_manage_permission')));
        register_rest_route('wsm/v1','/club/raffles',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'get_club_raffles_rest'),'permission_callback'=>array(__CLASS__,'staff_portal_review_permission')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_club_raffle_rest'),'permission_callback'=>array(__CLASS__,'staff_portal_review_manage_permission')),
        ));
        register_rest_route('wsm/v1','/club/raffles/(?P<id>[a-zA-Z0-9_-]+)/draw',array('methods'=>'POST','callback'=>array(__CLASS__,'draw_club_raffle_rest'),'permission_callback'=>array(__CLASS__,'staff_portal_review_manage_permission')));
    }

    private static function athlete_portal_is_staff_request_allowed($request,$manage=false) {
        if (!class_exists('WSM_Permissions') || !class_exists('WSM_Devices')) { return new WP_Error('wsm_portal_staff_auth_unavailable','احراز هویت پنل باشگاه در دسترس نیست.',array('status'=>503)); }
        $checked = WSM_Permissions::check_request($request);
        if (is_wp_error($checked) && !in_array($checked->get_error_code(),array('wsm_permission_unmapped','wsm_device_permission_denied'),true)) { return $checked; }
        if ($checked === false) { return new WP_Error('wsm_portal_staff_auth_failed','احراز هویت کارمند ناموفق بود.',array('status'=>403)); }
        $device = WSM_Permissions::current_device();
        if (!$device) { return new WP_Error('wsm_portal_staff_auth_failed','احراز هویت کارمند ناموفق بود.',array('status'=>401)); }
        $user = get_userdata((int)$device->user_id);
        if ($user && WSM_Permissions::is_wordpress_admin_user($user)) { return true; }
        $allowed = $manage ? array('club.settings.manage','club.members.manage') : array('club.settings.manage','club.members.view','club.members.manage');
        foreach ($allowed as $permission) { if (WSM_Devices::device_can($device,$permission)) { return true; } }
        return new WP_Error('wsm_device_permission_denied',$manage?'برای مدیریت این بخش مجوز مدیریت باشگاه یا اعضا لازم است.':'برای مشاهدهٔ این بخش مجوز مشاهدهٔ اعضا لازم است.',array('status'=>403));
    }

    public static function staff_portal_review_permission($request) { return self::athlete_portal_is_staff_request_allowed($request,false); }
    public static function staff_portal_review_manage_permission($request) { return self::athlete_portal_is_staff_request_allowed($request,true); }

    private static function portal_support_can_manage() {
        if (current_user_can('manage_options')) { return true; }
        $device = class_exists('WSM_Permissions') ? WSM_Permissions::current_device() : null;
        return $device && class_exists('WSM_Devices') && WSM_Devices::device_can($device, 'users.super_admin.assign');
    }

    private static function portal_support_staff_role_allowed($user_id) {
        $user_id = absint($user_id);
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user || get_user_meta($user_id, 'wsm_app_user_status', true) === 'inactive') { return false; }
        if (in_array('administrator', (array)$user->roles, true) && user_can($user, 'manage_options')) { return true; }
        $allowed = (array)(self::settings()['portal_support_roles'] ?? array('administrator'));
        $record = class_exists('WSM_Permissions') ? WSM_Permissions::user_permissions_record($user_id) : null;
        $roles = array_merge((array)$user->roles, array((string)get_user_meta($user_id, 'wsm_app_role', true), is_array($record) ? (string)($record['preset'] ?? '') : ''));
        return (bool)array_intersect($allowed, $roles);
    }

    private static function portal_support_staff_id() {
        if (current_user_can('manage_options')) { return absint(get_current_user_id()); }
        $device = class_exists('WSM_Permissions') ? WSM_Permissions::current_device() : null;
        return $device && !empty($device->user_id) ? absint($device->user_id) : 0;
    }

    public static function portal_support_staff_permission($request) {
        if (!current_user_can('manage_options')) {
            if (!class_exists('WSM_Permissions') || !class_exists('WSM_Auth') || !class_exists('WSM_Security')) {
                return new WP_Error('wsm_support_auth_unavailable', 'احراز هویت امن کارکنان در دسترس نیست.', array('status'=>503));
            }
            $token = WSM_Permissions::extract_token($request);
            if ($token === '') { return new WP_Error('wsm_no_token', 'توکن کارکنان ارسال نشده است.', array('status'=>401)); }
            $device = WSM_Auth::validate_bearer($token);
            if (is_wp_error($device)) { return $device; }
            $signed = WSM_Security::verify_signature($request, $device);
            if (is_wp_error($signed)) { return $signed; }
            WSM_Permissions::set_device($device);
        }
        if (self::portal_support_staff_role_allowed(self::portal_support_staff_id())) { return true; }
        return new WP_Error('wsm_support_role_forbidden', 'نقش شما مجوز مشاهده یا پاسخ به پشتیبانی ورزشکاران را ندارد.', array('status'=>403));
    }

    public static function athlete_portal_bootstrap_rest($request) {
        $settings = self::settings();
        $today = self::athlete_portal_today();
        $jalali = function_exists('wsm_gregorian_to_jalali') ? wsm_gregorian_to_jalali((int)substr($today,0,4),(int)substr($today,5,2),(int)substr($today,8,2)) : array((int)substr($today,0,4),(int)substr($today,5,2),(int)substr($today,8,2));
        $jalali_year = (int)$jalali[0];
        $jalali_month = (int)$jalali[1];
        $jalali_day = (int)$jalali[2];
        $month_days = $jalali_month <= 6 ? 31 : ($jalali_month <= 11 ? 30 : (self::athlete_portal_parse_date(sprintf('%04d/12/30',$jalali_year)) ? 30 : 29));
        $enabled = !empty($settings['portal_enabled']);
        $gateways = $enabled ? self::athlete_portal_public_gateways() : array();
        return array(
            'enabled'=>$enabled,
            'gateway_ready'=>$enabled && self::athlete_portal_gateway_ready(),
            'gateway_mode'=>$enabled ? self::athlete_portal_gateway_mode() : 'unavailable',
            'gateway_currency_supported'=>self::athlete_portal_currency_supported(),
            'available_gateways'=>$gateways,
            'club_name'=>sanitize_text_field(get_bloginfo('name')),
            'terms_text'=>isset($settings['portal_terms_text']) ? (string)$settings['portal_terms_text'] : '',
            'portal_url'=>home_url('/athlete-portal'),
            'today'=>$today,
            'today_jalali'=>sprintf('%04d/%02d/%02d',$jalali_year,$jalali_month,$jalali_day),
            'jalali_year'=>$jalali_year,
            'jalali_month'=>$jalali_month,
            'jalali_day'=>$jalali_day,
            'jalali_month_days'=>$month_days,
            'currency'=>function_exists('get_woocommerce_currency')?get_woocommerce_currency():'',
            'currency_symbol'=>function_exists('wsm_currency_symbol')?wsm_currency_symbol():'',
        );
    }

    public static function athlete_portal_login_rest($request) {
        if (!self::athlete_portal_enabled()) { return new WP_Error('wsm_athlete_portal_disabled','پورتال ورزشکار در حال حاضر غیرفعال است.',array('status'=>403)); }
        $body = self::athlete_portal_request_body($request);
        $phone = self::athlete_portal_normalize_phone($body['phone'] ?? '');
        if ($phone === '') { return new WP_Error('wsm_athlete_login_invalid','شمارهٔ موبایل معتبر نیست.',array('status'=>400)); }
        $identity = self::athlete_portal_client_ip().'|'.$phone;
        $failure_key = self::athlete_portal_login_failure_key($identity);
        if ((int)get_transient($failure_key) >= 5) { return new WP_Error('wsm_athlete_login_locked','پس از پنج تلاش ناموفق، ورود برای این شماره و IP تا ۱۵ دقیقه محدود شد.',array('status'=>429)); }
        $rate = self::athlete_portal_rate_limit('login',$identity,8,15*MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { return $rate; }
        $member = self::athlete_portal_find_member_by_phone($phone);
        if (is_wp_error($member) || !$member) {
            self::athlete_portal_record_login_failure($identity);
            return new WP_Error('wsm_athlete_login_failed','شمارهٔ موبایل یا گذرواژه درست نیست.',array('status'=>401));
        }
        $credential = isset($body['password']) && is_scalar($body['password']) ? (string)$body['password'] : '';
        if (!self::athlete_verify_password($member,$credential)) {
            self::athlete_portal_record_login_failure($identity);
            return new WP_Error('wsm_athlete_login_failed','شمارهٔ موبایل یا گذرواژه درست نیست.',array('status'=>401));
        }
        delete_transient($failure_key);
        $token = self::issue_athlete_token($member,true);
        if (is_wp_error($token)) { return $token; }
        return array('token'=>$token,'expires_in'=>7*DAY_IN_SECONDS,'member'=>array('id'=>(int)$member->id,'name'=>trim((string)$member->first_name.' '.(string)$member->last_name),'member_code'=>(string)$member->member_code));
    }

    public static function athlete_portal_change_password_rest($request) {
        $member = self::athlete_portal_require_member($request);
        if (is_wp_error($member)) { return $member; }
        $token = self::athlete_portal_extract_token($request);
        $recent_auth = self::athlete_token_has_recent_auth($token);
        $body = self::athlete_portal_request_body($request);
        $current = isset($body['current_password']) && is_scalar($body['current_password']) ? trim((string)$body['current_password']) : '';
        $password = isset($body['new_password']) && is_scalar($body['new_password']) ? trim((string)$body['new_password']) : '';
        $confirmation = isset($body['confirm_password']) && is_scalar($body['confirm_password']) ? trim((string)$body['confirm_password']) : '';
        $password = self::athlete_portal_normalize_digits($password);
        $confirmation = self::athlete_portal_normalize_digits($confirmation);
        if (self::athlete_password_length($password) < 8 || self::athlete_password_length($password) > 128 || strlen($password) > 512) {
            return new WP_Error('wsm_portal_weak_password','رمز جدید باید بین ۸ تا ۱۲۸ نویسه باشد.',array('status'=>400));
        }
        if ($confirmation === '' || !hash_equals($password,$confirmation)) {
            return new WP_Error('wsm_portal_password_mismatch','رمز جدید و تکرار آن یکسان نیستند.',array('status'=>400));
        }
        // Legacy clients with a supplied old password remain supported; new clients rely on a fresh signed login.
        if ($current !== '' && !self::athlete_verify_password($member,$current)) {
            return new WP_Error('wsm_athlete_current_password_invalid','گذرواژهٔ فعلی درست نیست.',array('status'=>403));
        }
        if (!$recent_auth && $current === '') {
            return new WP_Error('wsm_portal_reauth_required','برای تغییر رمز، دوباره با رمز یا کد یک‌بارمصرف وارد شوید؛ اعتبار ورود تازه ۱۵ دقیقه است.',array('status'=>403));
        }
        $rate = self::athlete_portal_rate_limit('password_change',self::athlete_portal_client_ip().'|'.(int)$member->id,6,15*MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { return $rate; }
        $lock = 'wsm_athlete_password_change_lock_'.(int)$member->id;
        if (!add_option($lock,time(),'','no')) {
            if (time()-(int)get_option($lock,0)>20) { delete_option($lock); }
            if (!add_option($lock,time(),'','no')) { return new WP_Error('wsm_athlete_password_busy','درخواست دیگری در حال ثبت است؛ دوباره تلاش کنید.',array('status'=>429)); }
        }
        try {
            // Re-verify under the lock so a concurrent password change invalidates this request.
            $member = self::athlete_portal_member_from_request($request);
            if (is_wp_error($member)) { return $member; }
            $recent_auth = self::athlete_token_has_recent_auth($token);
            if (!$recent_auth && ($current === '' || !self::athlete_verify_password($member,$current))) {
                return new WP_Error('wsm_portal_reauth_required','برای تغییر رمز، دوباره با رمز یا کد یک‌بارمصرف وارد شوید؛ اعتبار ورود تازه ۱۵ دقیقه است.',array('status'=>403));
            }
            if (self::athlete_verify_password($member,$password)) {
                return new WP_Error('wsm_portal_password_same','رمز جدید باید با رمز فعلی متفاوت باشد.',array('status'=>400));
            }
            if (!function_exists('wp_hash_password')) {
                return new WP_Error('wsm_athlete_password_hash_unavailable','ذخیرهٔ امن گذرواژه در دسترس نیست.',array('status'=>503));
            }
            $new_hash = wp_hash_password($password);
            if (!is_string($new_hash) || $new_hash === '') {
                return new WP_Error('wsm_athlete_password_hash_failed','ساخت هش گذرواژه انجام نشد.',array('status'=>500));
            }
            $credentials = self::athlete_portal_credentials();
            $credentials[(string)$member->id] = array('password_hash'=>$new_hash,'updated_at'=>current_time('mysql'));
            update_option(self::PORTAL_CREDS_OPTION,$credentials,false);
            if (!hash_equals($new_hash,self::athlete_password_hash($member))) {
                return new WP_Error('wsm_athlete_password_save_failed','ذخیرهٔ گذرواژه تأیید نشد.',array('status'=>500));
            }
            $fresh_member = self::athlete_portal_find_member_by_id((int)$member->id);
            if (is_wp_error($fresh_member)) { return $fresh_member; }
            if (!$fresh_member || (string)$fresh_member->status !== 'active') {
                return new WP_Error('wsm_athlete_member_lookup_failed','پروندهٔ ورزشکار پس از تغییر گذرواژه پیدا نشد.',array('status'=>500));
            }
            // Deliberately issue without auth_at: another password change requires a fresh login.
            $fresh_token = self::issue_athlete_token($fresh_member);
            if (is_wp_error($fresh_token)) { return $fresh_token; }
            $data = self::build_athlete_portal_payload($fresh_member);
            $data['ok'] = true;
            $data['token'] = $fresh_token;
            $data['expires_in'] = 7*DAY_IN_SECONDS;
            $data['has_custom_password'] = true;
            $data['message'] = 'رمز عبور با موفقیت تغییر یافت؛ برای تغییر دوباره، ورود تازه لازم است.';
            return $data;
        } finally {
            delete_option($lock);
        }
    }

    private static function athlete_login_otp_key($phone) {
        return 'wsm_athlete_login_otp_'.substr(hash_hmac('sha256',self::athlete_portal_normalize_phone($phone),wp_salt('auth')),0,40);
    }

    private static function athlete_login_otp_generic() {
        return array('ok'=>true,'message'=>'اگر شمارهٔ همراه فعال باشد و سرویس پیامک در دسترس باشد، کد ورود ارسال می‌شود. اعتبار کد ۵ دقیقه است.');
    }

    public static function athlete_portal_otp_request_rest($request) {
        if (!self::athlete_portal_enabled()) { return new WP_Error('wsm_portal_disabled','پورتال ورزشکار غیرفعال است.',array('status'=>403)); }
        $body = self::athlete_portal_request_body($request);
        $phone = self::athlete_portal_normalize_phone($body['phone'] ?? '');
        if (!preg_match('/^09[0-9]{9}$/D',$phone)) {
            return new WP_Error('wsm_portal_mobile_invalid','شمارهٔ موبایل ۱۱ رقمی معتبر وارد کنید.',array('status'=>400));
        }
        $ip = self::athlete_portal_client_ip();
        $today = self::athlete_portal_today();
        $ip_key = 'wsm_athlete_otp_ip_'.hash('sha256',$ip.'_'.$today);
        $phone_key = 'wsm_athlete_otp_phone_'.hash('sha256',$phone.'_'.$today);
        $ip_count = (int)get_transient($ip_key);
        $phone_count = (int)get_transient($phone_key);
        if ($ip_count >= 30 || $phone_count >= 6) {
            return new WP_Error('wsm_portal_otp_rate_limited','درخواست کد بیش از حد مجاز است؛ بعداً تلاش کنید.',array('status'=>429));
        }
        set_transient($ip_key,$ip_count+1,DAY_IN_SECONDS);
        set_transient($phone_key,$phone_count+1,DAY_IN_SECONDS);
        $member = self::athlete_portal_find_member_by_phone($phone);
        if (is_wp_error($member) || !$member || (string)($member->status ?? '') !== 'active') {
            return self::athlete_login_otp_generic();
        }
        $key = self::athlete_login_otp_key($phone);
        $existing = get_transient($key);
        if (is_array($existing) && time()-(int)($existing['sent_at'] ?? 0) < 45) {
            return self::athlete_login_otp_generic();
        }
        $lock = 'wsm_athlete_otp_send_lock_'.substr(hash('sha256',$phone),0,32);
        if (!add_option($lock,time(),'','no')) {
            if (time()-(int)get_option($lock,0) > 20) { delete_option($lock); }
            if (!add_option($lock,time(),'','no')) { return self::athlete_login_otp_generic(); }
        }
        try {
            $existing = get_transient($key);
            if (is_array($existing) && time()-(int)($existing['sent_at'] ?? 0) < 45) {
                return self::athlete_login_otp_generic();
            }
            try { $otp = (string)random_int(100000,999999); }
            catch (Throwable $e) { return self::athlete_login_otp_generic(); }
            $challenge = array(
                'purpose'=>'login',
                'member_id'=>(int)$member->id,
                'otp_hash'=>wp_hash_password($otp),
                'sent_at'=>time(),
                'expires_at'=>time()+5*MINUTE_IN_SECONDS,
                'attempts'=>0,
            );
            set_transient($key,$challenge,5*MINUTE_IN_SECONDS);
            $settings = self::settings();
            $template = isset($settings['portal_login_otp_template']) && is_scalar($settings['portal_login_otp_template'])
                ? (string)$settings['portal_login_otp_template'] : self::defaults()['portal_login_otp_template'];
            if (strpos($template,'{otp}') === false) { $template = self::defaults()['portal_login_otp_template']; }
            $club = get_bloginfo('name') ?: 'باشگاه ورزشی';
            $message = self::template_message($template,$member,array('otp'=>$otp,'club_name'=>$club));
            if (strpos($message,$otp) === false) { $message .= ' کد: '.$otp; }
            $sent = class_exists('WSM_SMS') ? WSM_SMS::dispatch_to($message,(string)$member->phone) : array('ok'=>false,'detail'=>'سرویس پیامک در دسترس نیست.');
            // Never save the actual OTP in the SMS audit table.
            self::log_sms((int)$member->id,(string)$member->phone,'portal_login_otp','[کد ورود یک‌بارمصرف؛ متن محرمانه]',$sent);
            if (empty($sent['ok'])) { delete_transient($key); }
            return self::athlete_login_otp_generic();
        } finally {
            delete_option($lock);
        }
    }

    public static function athlete_portal_otp_verify_rest($request) {
        if (!self::athlete_portal_enabled()) { return new WP_Error('wsm_portal_disabled','پورتال ورزشکار غیرفعال است.',array('status'=>403)); }
        $body = self::athlete_portal_request_body($request);
        $phone = self::athlete_portal_normalize_phone($body['phone'] ?? '');
        $code = is_scalar($body['otp'] ?? null) ? trim(self::athlete_portal_normalize_digits((string)$body['otp'])) : '';
        $code = preg_replace('/\D/u','',$code);
        if (!preg_match('/^09[0-9]{9}$/D',$phone) || !preg_match('/^[0-9]{6}$/D',$code)) {
            return new WP_Error('wsm_portal_otp_invalid','شمارهٔ همراه یا کد شش‌رقمی معتبر نیست.',array('status'=>400));
        }
        $ip = self::athlete_portal_client_ip();
        $ip_key = 'wsm_athlete_otp_verify_ip_'.hash('sha256',$ip.'_'.self::athlete_portal_today());
        $ip_attempts = (int)get_transient($ip_key);
        if ($ip_attempts >= 50) { return new WP_Error('wsm_portal_otp_verify_limited','تلاش‌های تأیید بیش از حد مجاز است.',array('status'=>429)); }
        set_transient($ip_key,$ip_attempts+1,DAY_IN_SECONDS);
        $key = self::athlete_login_otp_key($phone);
        $lock = 'wsm_athlete_otp_verify_lock_'.substr(hash('sha256',$phone),0,32);
        if (!add_option($lock,time(),'','no')) {
            if (time()-(int)get_option($lock,0) > 20) { delete_option($lock); }
            if (!add_option($lock,time(),'','no')) { return new WP_Error('wsm_portal_otp_busy','تأیید کد در حال انجام است؛ دوباره تلاش کنید.',array('status'=>429)); }
        }
        try {
            $member = self::athlete_portal_find_member_by_phone($phone);
            if (is_wp_error($member)) { return new WP_Error('wsm_portal_otp_invalid','کد معتبر نیست یا منقضی شده است؛ کد تازه درخواست کنید.',array('status'=>400)); }
            $record = get_transient($key);
            if (!$member || (string)($member->status ?? '') !== 'active' || !is_array($record) || ($record['purpose'] ?? '') !== 'login' ||
                (int)($record['member_id'] ?? 0) !== (int)$member->id || empty($record['otp_hash']) || time() > (int)($record['expires_at'] ?? 0)) {
                delete_transient($key);
                return new WP_Error('wsm_portal_otp_expired','کد معتبر نیست یا منقضی شده است؛ کد تازه درخواست کنید.',array('status'=>400));
            }
            if ((int)($record['attempts'] ?? 0) >= 5) {
                delete_transient($key);
                return new WP_Error('wsm_portal_otp_locked','تعداد تلاش‌ها بیش از حد مجاز بود؛ کد تازه درخواست کنید.',array('status'=>429));
            }
            if (!wp_check_password($code,(string)$record['otp_hash'])) {
                $record['attempts'] = (int)($record['attempts'] ?? 0)+1;
                if ($record['attempts'] >= 5) { delete_transient($key); }
                else { set_transient($key,$record,max(1,(int)$record['expires_at']-time())); }
                return new WP_Error('wsm_portal_otp_wrong','کد تأیید اشتباه است.',array('status'=>400));
            }
            delete_transient($key); // Single-use even if subsequent token creation fails.
            $token = self::issue_athlete_token($member,true);
            if (is_wp_error($token)) { return $token; }
            $data = self::build_athlete_portal_payload($member);
            $data['token'] = $token;
            $data['expires_in'] = 7*DAY_IN_SECONDS;
            $data['message'] = 'با کد یک‌بارمصرف وارد شدید. برای تنظیم رمز تازه به پروفایل بروید.';
            return $data;
        } finally {
            delete_option($lock);
        }
    }

    public static function athlete_portal_forgot_request_rest($request) {
        if (!self::athlete_portal_enabled()) { return new WP_Error('wsm_athlete_portal_disabled','پورتال ورزشکار در حال حاضر غیرفعال است.',array('status'=>403)); }
        if (!class_exists('WSM_SMS') || !WSM_SMS::is_active()) { return new WP_Error('wsm_athlete_sms_unconfigured','سرویس پیامک برای ارسال کد بازیابی فعال و پیکربندی‌شده نیست.',array('status'=>503)); }
        $body = self::athlete_portal_request_body($request);
        $phone = self::athlete_portal_normalize_phone($body['phone'] ?? '');
        if ($phone === '') { return new WP_Error('wsm_athlete_phone_invalid','شمارهٔ موبایل معتبر نیست.',array('status'=>400)); }
        $rate = self::athlete_portal_rate_limit('otp_ip',self::athlete_portal_client_ip(),20,HOUR_IN_SECONDS);
        if (is_wp_error($rate)) { return $rate; }
        $rate = self::athlete_portal_rate_limit('otp_phone',$phone,5,HOUR_IN_SECONDS);
        if (is_wp_error($rate)) { return $rate; }
        $cooldown_key = 'wsm_athlete_otp_cooldown_'.md5($phone);
        if (get_transient($cooldown_key)) { return new WP_Error('wsm_athlete_otp_cooldown','برای این شماره به‌تازگی کد ارسال شده است؛ یک دقیقه صبر کنید.',array('status'=>429)); }
        set_transient($cooldown_key,1,MINUTE_IN_SECONDS);
        $member = self::athlete_portal_find_member_by_phone($phone);
        if (is_wp_error($member)) { return array('message'=>'اگر حساب فعال با این شماره وجود داشته باشد، کد بازیابی ارسال می‌شود.'); }
        if (!$member) { return array('message'=>'اگر حساب فعال با این شماره وجود داشته باشد، کد بازیابی ارسال می‌شود.'); }
        try { $code = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT); }
        catch (Throwable $e) { return new WP_Error('wsm_athlete_otp_random_failed','ساخت کد بازیابی امن انجام نشد.',array('status'=>500)); }
        $expiry = time()+5*MINUTE_IN_SECONDS;
        $transient_key = 'wsm_athlete_otp_'.md5($phone);
        set_transient($transient_key,array('hash'=>wp_hash_password($code),'member_id'=>(int)$member->id,'phone_hash'=>substr(hash('sha256',$phone),0,16),'expires'=>$expiry,'attempts'=>0),5*MINUTE_IN_SECONDS);
        $settings = self::settings();
        $template = isset($settings['portal_otp_template']) ? (string)$settings['portal_otp_template'] : 'کد تأیید پورتال ورزشکار {club_name}: {otp}';
        if (strpos($template,'{otp}') === false) { $template = 'کد تأیید پورتال ورزشکار {club_name}: {otp}'; }
        $message = strtr($template,array(
            '{otp}'=>$code,
            '{name}'=>trim((string)$member->first_name.' '.(string)$member->last_name),
            '{first_name}'=>sanitize_text_field((string)$member->first_name),
            '{member_code}'=>sanitize_text_field((string)$member->member_code),
            '{club_name}'=>sanitize_text_field(get_bloginfo('name')),
        ));
        $result = WSM_SMS::dispatch_to($message,(string)$member->phone);
        // Never persist the OTP itself in the SMS audit log.
        self::log_sms((int)$member->id,(string)$member->phone,'athlete_otp','کد بازیابی پورتال ورزشکار [پنهان]',$result);
        if (empty($result['ok'])) {
            delete_transient($transient_key);
            return new WP_Error('wsm_athlete_sms_send_failed','ارسال پیامک انجام نشد؛ بعداً دوباره تلاش کنید.',array('status'=>503));
        }
        return array('message'=>'اگر حساب فعال با این شماره وجود داشته باشد، کد بازیابی ارسال می‌شود.');
    }

    public static function athlete_portal_forgot_verify_rest($request) {
        if (!self::athlete_portal_enabled()) { return new WP_Error('wsm_athlete_portal_disabled','پورتال ورزشکار در حال حاضر غیرفعال است.',array('status'=>403)); }
        $body = self::athlete_portal_request_body($request);
        $phone = self::athlete_portal_normalize_phone($body['phone'] ?? '');
        $code = preg_replace('/\D/u','',self::athlete_portal_normalize_digits(is_scalar($body['otp'] ?? null)?(string)$body['otp']:''));
        $password = isset($body['password']) && is_scalar($body['password']) ? (string)$body['password'] : '';
        if ($phone === '' || !preg_match('/^\d{6}$/',$code)) { return new WP_Error('wsm_athlete_otp_invalid','شماره یا کد پیامکی معتبر نیست.',array('status'=>400)); }
        if (self::athlete_password_length($password) < 8 || self::athlete_password_length($password) > 256 || strlen($password) > 1024) { return new WP_Error('wsm_athlete_password_invalid','گذرواژه باید دست‌کم ۸ نویسه و حداکثر ۲۵۶ نویسه داشته باشد.',array('status'=>400)); }
        $rate = self::athlete_portal_rate_limit('otp_verify',self::athlete_portal_client_ip().'|'.$phone,12,15*MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { return $rate; }
        $key = 'wsm_athlete_otp_'.md5($phone);
        $stored = get_transient($key);
        if (!is_array($stored) || empty($stored['hash']) || (int)($stored['expires'] ?? 0) < time() || (int)($stored['attempts'] ?? 0) >= 5) {
            delete_transient($key);
            return new WP_Error('wsm_athlete_otp_invalid','کد پیامکی منقضی یا نامعتبر است؛ کد تازه بگیرید.',array('status'=>400));
        }
        if (!wp_check_password($code,(string)$stored['hash'])) {
            $stored['attempts'] = (int)($stored['attempts'] ?? 0)+1;
            if ($stored['attempts'] >= 5) { delete_transient($key); }
            else { set_transient($key,$stored,max(1,(int)$stored['expires']-time())); }
            return new WP_Error('wsm_athlete_otp_invalid','کد پیامکی درست نیست.',array('status'=>400));
        }
        $member = self::athlete_portal_find_member_by_id(absint($stored['member_id'] ?? 0));
        if (is_wp_error($member)) { return $member; }
        if (!$member || (string)$member->status !== 'active' || !hash_equals((string)($stored['phone_hash'] ?? ''),substr(hash('sha256',$phone),0,16)) || self::athlete_portal_normalize_phone($member->phone) !== $phone) {
            delete_transient($key);
            return new WP_Error('wsm_athlete_member_inactive','پروندهٔ فعال با این شماره پیدا نشد.',array('status'=>403));
        }
        $credentials = self::athlete_portal_credentials();
        $credentials[(string)$member->id] = array('password_hash'=>wp_hash_password($password),'updated_at'=>current_time('mysql'));
        update_option(self::PORTAL_CREDS_OPTION,$credentials,false);
        delete_transient($key);
        $member = self::athlete_portal_find_member_by_id((int)$member->id);
        if (is_wp_error($member) || !$member) { return new WP_Error('wsm_athlete_member_lookup_failed','گذرواژه تغییر کرد اما خواندن پرونده ناموفق بود؛ دوباره وارد شوید.',array('status'=>500)); }
        $token = self::issue_athlete_token($member);
        if (is_wp_error($token)) { return $token; }
        return array('token'=>$token,'expires_in'=>7*DAY_IN_SECONDS,'message'=>'گذرواژه با موفقیت تغییر کرد.');
    }

    private static function athlete_portal_parse_date($value) {
        if (!is_scalar($value)) { return false; }
        $raw = trim(self::athlete_portal_normalize_digits((string)$value));
        if ($raw === '') { return false; }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$raw,$m)) {
            $year=(int)$m[1];$month=(int)$m[2];$day=(int)$m[3];
            if ($year >= 1700) { return checkdate($month,$day,$year) ? sprintf('%04d-%02d-%02d',$year,$month,$day) : false; }
        }
        if (class_exists('WSM_Products') && method_exists('WSM_Products','normalize_input')) {
            $normalized = WSM_Products::normalize_input($raw);
            if (is_string($normalized) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$normalized)) { return $normalized; }
        }
        if (preg_match('/^(\d{4})[\/.\-](\d{1,2})[\/.\-](\d{1,2})$/',$raw,$m) && (int)$m[1] >= 1700) {
            return checkdate((int)$m[2],(int)$m[3],(int)$m[1]) ? sprintf('%04d-%02d-%02d',(int)$m[1],(int)$m[2],(int)$m[3]) : false;
        }
        return false;
    }

    private static function athlete_portal_format_jalali($iso) {
        if (!is_string($iso) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$iso,$m)) { return ''; }
        $parts = function_exists('wsm_gregorian_to_jalali') ? wsm_gregorian_to_jalali((int)$m[1],(int)$m[2],(int)$m[3]) : array((int)$m[1],(int)$m[2],(int)$m[3]);
        $formatted = sprintf('%04d/%02d/%02d',(int)$parts[0],(int)$parts[1],(int)$parts[2]);
        return function_exists('wsm_to_persian_digits') ? wsm_to_persian_digits($formatted) : $formatted;
    }

    private static function athlete_portal_date_add($iso,$days) {
        try { return (new DateTimeImmutable((string)$iso,wp_timezone()))->modify(((int)$days>=0?'+':'').(int)$days.' days')->format('Y-m-d'); }
        catch (Exception $e) { return false; }
    }

    private static function athlete_portal_date_days($from,$to) {
        try {
            $a = new DateTimeImmutable((string)$from.' 00:00:00',wp_timezone());
            $b = new DateTimeImmutable((string)$to.' 00:00:00',wp_timezone());
            $diff = $a->diff($b);
            return $diff->invert ? -(int)$diff->days : (int)$diff->days;
        } catch (Exception $e) { return 0; }
    }

    private static function athlete_portal_membership_context($member_id) {
        global $wpdb;
        $member_id=absint($member_id);$today=self::athlete_portal_today();$table=WSM_Club::table('memberships');
        $wpdb->last_error='';
        $unexpired=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE member_id=%d AND status IN ('active','scheduled','frozen') AND end_date >= %s",$member_id,$today));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_membership_lookup_failed','خواندن وضعیت عضویت انجام نشد.',array('status'=>500)); }
        $wpdb->last_error='';
        $current=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE member_id=%d AND status IN ('active','scheduled','frozen') AND start_date <= %s AND end_date >= %s ORDER BY end_date DESC,id DESC LIMIT 1",$member_id,$today,$today));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_membership_lookup_failed','خواندن وضعیت عضویت انجام نشد.',array('status'=>500)); }
        $upcoming=null;
        if (!$current) {
            $wpdb->last_error='';
            $upcoming=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE member_id=%d AND status IN ('active','scheduled','frozen') AND start_date > %s ORDER BY start_date ASC,id ASC LIMIT 1",$member_id,$today));
            if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_membership_lookup_failed','خواندن وضعیت عضویت انجام نشد.',array('status'=>500)); }
        }
        $wpdb->last_error='';
        $latest=$wpdb->get_row($wpdb->prepare("SELECT id,end_date,status FROM $table WHERE member_id=%d AND status <> 'cancelled' ORDER BY end_date DESC,id DESC LIMIT 1",$member_id));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_membership_lookup_failed','خواندن وضعیت عضویت انجام نشد.',array('status'=>500)); }
        $prior_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE member_id=%d AND status <> 'cancelled'",$member_id));
        $chosen=$current?:$upcoming;
        if (!$chosen) {
            $wpdb->last_error='';
            $chosen=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE member_id=%d AND status <> 'cancelled' ORDER BY end_date DESC,id DESC LIMIT 1",$member_id));
            if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_membership_lookup_failed','خواندن وضعیت عضویت انجام نشد.',array('status'=>500)); }
        }
        $days_left=$chosen ? self::athlete_portal_date_days($today,(string)$chosen->end_date) : 0;
        $color='red';$label='بدون عضویت معتبر';
        if ($current) { $color=$days_left>5?'green':($days_left>0?'yellow':'red');$label=$days_left>0?'عضویت معتبر':($days_left===0?'پایان اعتبار امروز':'منقضی‌شده'); }
        elseif ($upcoming) { $color='yellow';$label='عضویت زمان‌بندی‌شده';$days_left=self::athlete_portal_date_days($today,(string)$upcoming->start_date); }
        $membership=array();
        if ($chosen) {
            $membership=array('id'=>(int)$chosen->id,'plan_name'=>(string)$chosen->plan_name,'start_date'=>(string)$chosen->start_date,'start_date_jalali'=>self::athlete_portal_format_jalali((string)$chosen->start_date),'end_date'=>(string)$chosen->end_date,'end_date_jalali'=>self::athlete_portal_format_jalali((string)$chosen->end_date),'status'=>(string)$chosen->status,'days_left'=>(int)$days_left,'status_color'=>$color,'status_label'=>$label,'price'=>(string)$chosen->price,'discount_amount'=>(string)$chosen->discount_amount);
        }
        return array('has_unexpired_membership'=>$unexpired>0,'prior_membership_count'=>$prior_count,'previous_end_date'=>$latest?(string)$latest->end_date:'','membership'=>$membership);
    }

    public static function get_athlete_debt_summary($member_id) {
        global $wpdb;
        $member_id=absint($member_id);
        if (!$member_id) { return new WP_Error('wsm_athlete_debt_member_invalid','پروندهٔ ورزشکار معتبر نیست.',array('status'=>400)); }
        $membership_table=WSM_Club::table('memberships');$payment_table=WSM_Club::table('payments');
        $wpdb->last_error='';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT m.id,m.plan_name,m.start_date,m.end_date,m.price,m.created_at,COALESCE(p.paid,0) AS paid FROM $membership_table m LEFT JOIN (SELECT membership_id,SUM(amount) AS paid FROM $payment_table WHERE status='paid' GROUP BY membership_id) p ON p.membership_id=m.id WHERE m.member_id=%d AND m.status <> 'cancelled' AND m.price > COALESCE(p.paid,0) ORDER BY m.created_at ASC,m.id ASC",$member_id));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_membership_debt_lookup_failed','محاسبهٔ بدهی عضویت انجام نشد.',array('status'=>500)); }
        $membership_debt=0.0;$items=array();
        foreach ((array)$rows as $row) {
            $due=max(0.0,(float)$row->price-(float)$row->paid);
            if ($due<=0.000001) { continue; }
            $membership_debt += $due;
            $items[]=array('type'=>'membership','id'=>(int)$row->id,'name'=>'عضویت '.(string)$row->plan_name,'plan_name'=>(string)$row->plan_name,'amount_due'=>(string)wc_format_decimal($due),'balance_due'=>(string)wc_format_decimal($due),'start_date'=>(string)$row->start_date,'end_date'=>(string)$row->end_date,'created_at'=>(string)$row->created_at);
        }
        if (!function_exists('wc_get_orders') || !method_exists('WSM_Club_Operations','pos_debt_by_member')) { return new WP_Error('wsm_athlete_pos_debt_unavailable','محاسبهٔ بدهی خرید حضوری در دسترس نیست.',array('status'=>503)); }
        $balances=self::pos_debt_by_member(array($member_id));
        if (is_wp_error($balances)) { return $balances; }
        $pos_debt=isset($balances[$member_id])?max(0.0,(float)$balances[$member_id]):0.0;
        $debt_order_ids=class_exists('WSM_Util')&&method_exists('WSM_Util','order_ids_with_meta')?WSM_Util::order_ids_with_meta('_wsm_club_pos_debt','1'):array();
        if (is_wp_error($debt_order_ids)) { return $debt_order_ids; }
        $debt_order_ids=array_values(array_unique(array_filter(array_map('absint',(array)$debt_order_ids))));
        $page=1;$max_pages=1;$pos_items=array();
        do {
            $wpdb->last_error='';
            $result=wc_get_orders(array('type'=>'shop_order','status'=>self::pos_debt_open_order_statuses(),'limit'=>100,'page'=>$page,'paginate'=>true,'return'=>'objects','post__in'=>$debt_order_ids?:array(0)));
            if (is_wp_error($result)) { return $result; }
            if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_pos_debt_lookup_failed','خواندن بدهی خرید حضوری انجام نشد.',array('status'=>500)); }
            $orders=is_object($result)&&isset($result->orders)?(array)$result->orders:(array)$result;
            $max_pages=is_object($result)&&isset($result->max_num_pages)?max(1,(int)$result->max_num_pages):1;
            foreach ($orders as $order) {
                if (!is_object($order)||!method_exists($order,'get_meta')||!method_exists($order,'get_id')) { continue; }
                if (absint($order->get_meta('_wsm_club_member_id',true))!==$member_id||!$order->get_meta('_wsm_club_pos_debt',true)) { continue; }
                $due=self::pos_order_debt_due($order);
                if ($due<=0.000001) { continue; }
                $date=$order->get_date_created();
                $pos_items[]=array('type'=>'pos','id'=>(int)$order->get_id(),'order_id'=>(int)$order->get_id(),'number'=>(string)$order->get_order_number(),'name'=>'فاکتور حضوری #'.(string)$order->get_order_number(),'amount_due'=>(string)wc_format_decimal($due),'balance_due'=>(string)wc_format_decimal($due),'created_at'=>$date?$date->date('Y-m-d H:i:s'):'9999-12-31 23:59:59');
            }
            $page++;
        } while ($page <= $max_pages && $page <= 200);
        usort($items,function($a,$b){return strcmp((string)$a['created_at'],(string)$b['created_at'])?:((int)$a['id']<=>(int)$b['id']);});
        usort($pos_items,function($a,$b){return strcmp((string)$a['created_at'],(string)$b['created_at'])?:((int)$a['id']<=>(int)$b['id']);});
        $items=array_merge($items,$pos_items);
        return array('membership_debt'=>(string)wc_format_decimal($membership_debt),'pos_debt'=>(string)wc_format_decimal($pos_debt),'total_debt'=>(string)wc_format_decimal($membership_debt+$pos_debt),'items'=>$items,'currency'=>function_exists('get_woocommerce_currency')?get_woocommerce_currency():'','currency_symbol'=>function_exists('wsm_currency_symbol')?wsm_currency_symbol():'');
    }

    public static function build_athlete_portal_payload($member) {
        if (!is_object($member)||empty($member->id)) { return new WP_Error('wsm_athlete_member_missing','پروندهٔ ورزشکار پیدا نشد.',array('status'=>404)); }
        $context=self::athlete_portal_membership_context((int)$member->id);
        if (is_wp_error($context)) { return $context; }
        $debt=self::get_athlete_debt_summary((int)$member->id);
        if (is_wp_error($debt)) { return $debt; }
        global $wpdb;
        $wpdb->last_error='';
        $plans=$wpdb->get_results('SELECT id,name,duration_days,price,max_visits,description FROM '.WSM_Club::table('plans')." WHERE active=1 ORDER BY name ASC,id ASC");
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_plans_lookup_failed','خواندن تعرفه‌های فعال انجام نشد.',array('status'=>500)); }
        $plan_items=array();
        foreach ((array)$plans as $plan) { $plan_items[]=array('id'=>(int)$plan->id,'name'=>(string)$plan->name,'duration_days'=>(int)$plan->duration_days,'price'=>(string)$plan->price,'max_visits'=>(int)$plan->max_visits,'description'=>(string)$plan->description); }
        $attendance_rows=$wpdb->get_results($wpdb->prepare('SELECT id,direction,event_type,decision,occurred_at FROM '.WSM_Club::table('attendance').' WHERE member_id=%d ORDER BY occurred_at DESC,id DESC LIMIT 30',(int)$member->id),ARRAY_A);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_attendance_lookup_failed','خواندن سوابق تردد انجام نشد.',array('status'=>500)); }
        $attendance=array();
        foreach ((array)$attendance_rows as $visit) {
            $attendance[]=array('id'=>(int)$visit['id'],'direction'=>(string)$visit['direction'],'event_type'=>(string)$visit['event_type'],'decision'=>(string)$visit['decision'],'occurred_at'=>(string)$visit['occurred_at'],'occurred_at_jalali'=>self::athlete_portal_format_jalali(substr((string)$visit['occurred_at'],0,10)));
        }
        $payment_rows=$wpdb->get_results($wpdb->prepare("SELECT id,amount,method,reference,paid_at,note FROM ".WSM_Club::table('payments')." WHERE member_id=%d AND status='paid' ORDER BY paid_at DESC,id DESC LIMIT 10",(int)$member->id),ARRAY_A);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_payment_history_lookup_failed','خواندن سوابق پرداخت انجام نشد.',array('status'=>500)); }
        $payments=array();
        foreach ((array)$payment_rows as $payment) {
            $payments[]=array('id'=>(int)$payment['id'],'amount'=>(string)$payment['amount'],'method'=>(string)$payment['method'],'reference'=>(string)$payment['reference'],'paid_at'=>(string)$payment['paid_at'],'paid_at_jalali'=>self::athlete_portal_format_jalali(substr((string)$payment['paid_at'],0,10)),'note'=>(string)$payment['note']);
        }
        $scheduled_rows=$wpdb->get_results($wpdb->prepare("SELECT id,plan_name,start_date,end_date FROM ".WSM_Club::table('memberships')." WHERE member_id=%d AND status='scheduled' AND start_date>%s ORDER BY start_date ASC,id ASC LIMIT 5",(int)$member->id,self::athlete_portal_today()),ARRAY_A);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_scheduled_lookup_failed','خواندن دوره‌های رزروشده انجام نشد.',array('status'=>500)); }
        $scheduled_memberships=array();
        foreach ((array)$scheduled_rows as $scheduled) {
            $scheduled_memberships[]=array('id'=>(int)$scheduled['id'],'plan_name'=>(string)$scheduled['plan_name'],'start_date'=>(string)$scheduled['start_date'],'start_date_jalali'=>self::athlete_portal_format_jalali((string)$scheduled['start_date']),'end_date'=>(string)$scheduled['end_date'],'end_date_jalali'=>self::athlete_portal_format_jalali((string)$scheduled['end_date']));
        }
        $settings=self::settings();
        $raffle=self::get_member_raffle_portal_data((int)$member->id,!empty($context['has_unexpired_membership']),(int)$context['prior_membership_count']);
        $profile=array('id'=>(int)$member->id,'member_code'=>(string)$member->member_code,'first_name'=>(string)$member->first_name,'last_name'=>(string)$member->last_name,'name'=>trim((string)$member->first_name.' '.(string)$member->last_name),'phone'=>(string)$member->phone,'email'=>(string)$member->email,'join_date'=>(string)$member->join_date,'status'=>(string)$member->status);
        $has_custom_password = self::athlete_password_hash($member) !== '';
        return array('profile'=>$profile,'has_custom_password'=>$has_custom_password,'needs_password_change'=>!$has_custom_password,'membership'=>$context['membership'],'has_unexpired_membership'=>!empty($context['has_unexpired_membership']),'prior_membership_count'=>(int)$context['prior_membership_count'],'previous_end_date'=>(string)$context['previous_end_date'],'plans'=>$plan_items,'debt'=>$debt,'raffle'=>$raffle,'available_gateways'=>self::athlete_portal_public_gateways(),'gateway_ready'=>self::athlete_portal_gateway_ready(),'terms_text'=>(string)($settings['portal_terms_text'] ?? ''),'training_url'=>(string)($settings['portal_training_url'] ?? ''),'club_name'=>get_bloginfo('name') ?: 'باشگاه ورزشی','attendance'=>$attendance,'payments'=>$payments,'scheduled_memberships'=>$scheduled_memberships,'currency'=>$debt['currency'],'currency_symbol'=>$debt['currency_symbol'],'today'=>self::athlete_portal_today(),'today_jalali'=>self::athlete_portal_format_jalali(self::athlete_portal_today()));
    }

    public static function athlete_portal_me_rest($request) {
        $member=self::athlete_portal_require_member($request);
        if (is_wp_error($member)) { return $member; }
        return self::build_athlete_portal_payload($member);
    }

    /** Athlete support uses the existing message table; sender_id=0 is reserved for the athlete side. */
    private static function support_subject($member_id) {
        $member_id = absint($member_id);
        return '[athlete_support:' . $member_id . ':' . substr(hash_hmac('sha256', 'wsm_support|' . $member_id, wp_salt('auth')), 0, 16) . '] پشتیبانی ورزشکار';
    }

    private static function support_root($member_id) {
        global $wpdb;
        $table = self::internal_messages_table();
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            return new WP_Error('wsm_support_schema_unavailable', 'جدول پیام‌رسان آماده نیست.', array('status'=>503));
        }
        $deleted = $wpdb->get_var("SHOW COLUMNS FROM {$table_sql} LIKE 'deleted_at'") === 'deleted_at' ? ' AND deleted_at IS NULL' : '';
        $row = $wpdb->get_row($wpdb->prepare("SELECT uuid,receiver_id,subject FROM {$table_sql} WHERE sender_id=0 AND receiver_id>0 AND subject=%s {$deleted} ORDER BY id ASC LIMIT 1", self::support_subject($member_id)), ARRAY_A);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_support_read_failed', 'بررسی گفتگوی پشتیبانی انجام نشد.', array('status'=>500)); }
        return is_array($row) ? $row : null;
    }

    private static function support_staff_receiver_id() {
        $users = get_users(array('role'=>'administrator','number'=>20,'orderby'=>'ID','order'=>'ASC','fields'=>'ids'));
        foreach ((array)$users as $user_id) { if (user_can((int)$user_id, 'manage_options')) { return absint($user_id); } }
        $users = get_users(array('capability'=>'manage_options','number'=>20,'orderby'=>'ID','order'=>'ASC','fields'=>'ids'));
        foreach ((array)$users as $user_id) { if (user_can((int)$user_id, 'manage_options')) { return absint($user_id); } }
        return 0;
    }

    private static function support_messages($member_id) {
        global $wpdb;
        $root = self::support_root($member_id);
        if (is_wp_error($root)) { return $root; }
        if (!$root) { return array('root'=>null,'items'=>array()); }
        $table = self::internal_messages_table();
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        $deleted = $wpdb->get_var("SHOW COLUMNS FROM {$table_sql} LIKE 'deleted_at'") === 'deleted_at' ? ' AND deleted_at IS NULL' : '';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT uuid,sender_id,message,created_at FROM {$table_sql} WHERE subject=%s AND receiver_id=%d {$deleted} ORDER BY id DESC LIMIT 100", self::support_subject($member_id), (int)$root['receiver_id']), ARRAY_A);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_support_read_failed', 'دریافت گفتگو انجام نشد.', array('status'=>500)); }
        $items = array();
        foreach (array_reverse((array)$rows) as $row) {
            $items[] = array('uuid'=>(string)$row['uuid'],'message'=>(string)$row['message'],'from_athlete'=>(int)$row['sender_id']===0,'created_at'=>(string)$row['created_at']);
        }
        return array('root'=>$root,'items'=>$items);
    }

    private static function support_write($member_id, $message, $staff_id=0) {
        global $wpdb;
        $member_id = absint($member_id);
        $staff_id = absint($staff_id);
        if (!$member_id) { return new WP_Error('wsm_support_member_invalid', 'شناسهٔ ورزشکار معتبر نیست.', array('status'=>400)); }
        if (!is_scalar($message)) { return new WP_Error('wsm_support_text_invalid', 'متن پیام معتبر نیست.', array('status'=>400)); }
        $message = trim(sanitize_textarea_field((string)$message));
        $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : self::athlete_password_length($message);
        if ($message === '' || $length > 2000) { return new WP_Error('wsm_support_text_invalid', 'متن پیام باید بین ۱ تا ۲۰۰۰ نویسه باشد.', array('status'=>400)); }
        $lock = 'wsm_support_write_lock_' . $member_id;
        if (!add_option($lock, time(), '', 'no')) {
            if (time() - (int)get_option($lock, 0) > 20) { delete_option($lock); }
            if (!add_option($lock, time(), '', 'no')) { return new WP_Error('wsm_support_busy', 'گفتگو در حال ثبت است؛ دوباره تلاش کنید.', array('status'=>429)); }
        }
        try {
            $root = self::support_root($member_id);
            if (is_wp_error($root)) { return $root; }
            if ($staff_id > 0 && !$root) { return new WP_Error('wsm_support_thread_missing', 'گفتگوی ورزشکار پیدا نشد.', array('status'=>404)); }
            $receiver_id = $root ? absint($root['receiver_id']) : self::support_staff_receiver_id();
            if (!$receiver_id) { return new WP_Error('wsm_support_admin_missing', 'حساب مدیر برای دریافت پیام آماده نیست.', array('status'=>503)); }
            $uuid = wp_generate_uuid4();
            $thread_uuid = $root ? (string)$root['uuid'] : $uuid;
            $subject = self::support_subject($member_id);
            $created_at = current_time('mysql');
            $data = array('uuid'=>$uuid,'sender_id'=>$staff_id > 0 ? $staff_id : 0,'receiver_id'=>$receiver_id,'subject'=>$subject,'message'=>$message,'is_read'=>0,'read_by'=>wp_json_encode(array()),'created_at'=>$created_at);
            $table = self::internal_messages_table();
            $table_sql = '`' . str_replace('`', '``', $table) . '`';
            $has_thread = $wpdb->get_var("SHOW COLUMNS FROM {$table_sql} LIKE 'thread_uuid'") === 'thread_uuid';
            $has_reply = $wpdb->get_var("SHOW COLUMNS FROM {$table_sql} LIKE 'reply_to_uuid'") === 'reply_to_uuid';
            if ($has_thread) { $data['thread_uuid'] = $thread_uuid; }
            if ($has_reply) {
                $deleted = $wpdb->get_var("SHOW COLUMNS FROM {$table_sql} LIKE 'deleted_at'") === 'deleted_at' ? ' AND deleted_at IS NULL' : '';
                $previous_uuid = $root ? (string)$wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$table_sql} WHERE subject=%s AND receiver_id=%d {$deleted} ORDER BY id DESC LIMIT 1", $subject, $receiver_id)) : '';
                $data['reply_to_uuid'] = $previous_uuid;
            }
            if ($wpdb->insert($table, $data) !== 1) { return new WP_Error('wsm_support_write_failed', 'ثبت پیام پشتیبانی انجام نشد.', array('status'=>500)); }
            return array('ok'=>true,'uuid'=>$uuid,'thread_uuid'=>$thread_uuid,'message'=>$message,'from_athlete'=>$staff_id===0,'created_at'=>$created_at);
        } finally {
            delete_option($lock);
        }
    }

    public static function athlete_support_inbox_rest($request) {
        $member = self::athlete_portal_require_member($request);
        if (is_wp_error($member)) { return $member; }
        $chat = self::support_messages((int)$member->id);
        if (is_wp_error($chat)) { return $chat; }
        return array('items'=>$chat['items'],'has_thread'=>(bool)$chat['root']);
    }

    public static function athlete_support_send_rest($request) {
        $member = self::athlete_portal_require_member($request);
        if (is_wp_error($member)) { return $member; }
        $body = self::athlete_portal_request_body($request);
        $rate_key = 'wsm_support_rate_' . (int)$member->id;
        if (get_transient($rate_key)) { return new WP_Error('wsm_support_slow_down', 'برای ارسال پیام بعدی چند ثانیه صبر کنید.', array('status'=>429)); }
        $daily_key = 'wsm_support_daily_' . (int)$member->id . '_' . current_time('Ymd');
        $sent = (int)get_transient($daily_key);
        if ($sent >= 30) { return new WP_Error('wsm_support_daily_limit', 'سقف ارسال پیام امروز تکمیل شده است.', array('status'=>429)); }
        $result = self::support_write((int)$member->id, $body['message'] ?? '');
        if (!is_wp_error($result)) { set_transient($rate_key, 1, 5); set_transient($daily_key, $sent + 1, DAY_IN_SECONDS); }
        return $result;
    }

    public static function staff_athlete_support_inbox_rest($request) {
        global $wpdb;
        $member_id = absint($request->get_param('member_id'));
        if ($member_id) {
            $chat = self::support_messages($member_id);
            if (is_wp_error($chat)) { return $chat; }
            if (!$chat['root']) { return new WP_Error('wsm_support_thread_missing', 'گفتگو پیدا نشد.', array('status'=>404)); }
            $member = $wpdb->get_row($wpdb->prepare('SELECT id,first_name,last_name,member_code FROM ' . WSM_Club::table('members') . ' WHERE id=%d', $member_id), ARRAY_A);
            if (!empty($wpdb->last_error)) { return new WP_Error('wsm_support_member_lookup_failed', 'پروندهٔ ورزشکار پیدا نشد.', array('status'=>500)); }
            return array('member_id'=>$member_id,'member_name'=>$member ? trim((string)$member['first_name'] . ' ' . (string)$member['last_name']) : 'ورزشکار','member_code'=>$member ? (string)$member['member_code'] : '', 'items'=>$chat['items']);
        }
        $table = self::internal_messages_table();
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { return new WP_Error('wsm_support_schema_unavailable', 'جدول پیام‌رسان آماده نیست.', array('status'=>503)); }
        $deleted = $wpdb->get_var("SHOW COLUMNS FROM {$table_sql} LIKE 'deleted_at'") === 'deleted_at' ? ' AND deleted_at IS NULL' : '';
        $like = $wpdb->esc_like('[athlete_support:') . '%';
        $roots = $wpdb->get_results($wpdb->prepare("SELECT subject,MAX(id) AS last_id FROM {$table_sql} WHERE sender_id=0 AND subject LIKE %s {$deleted} GROUP BY subject ORDER BY last_id DESC LIMIT 100", $like), ARRAY_A);
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_support_read_failed', 'فهرست گفتگوهای پشتیبانی خوانده نشد.', array('status'=>500)); }
        $items = array();
        $seen = array();
        foreach ((array)$roots as $row) {
            if (!preg_match('/^\\[athlete_support:(\\d+):[0-9a-f]{16}\\]/i', (string)$row['subject'], $match)) { continue; }
            $mid = absint($match[1]);
            if (!$mid || isset($seen[$mid]) || (string)$row['subject'] !== self::support_subject($mid)) { continue; }
            $seen[$mid] = true;
            $root = $wpdb->get_row($wpdb->prepare("SELECT receiver_id FROM {$table_sql} WHERE sender_id=0 AND subject=%s {$deleted} ORDER BY id ASC LIMIT 1", self::support_subject($mid)), ARRAY_A);
            if (!is_array($root) || absint($root['receiver_id']) < 1) { continue; }
            $member = $wpdb->get_row($wpdb->prepare('SELECT first_name,last_name,member_code FROM ' . WSM_Club::table('members') . ' WHERE id=%d', $mid), ARRAY_A);
            $last = $wpdb->get_row($wpdb->prepare("SELECT message,created_at,sender_id FROM {$table_sql} WHERE subject=%s AND receiver_id=%d {$deleted} ORDER BY id DESC LIMIT 1", self::support_subject($mid), absint($root['receiver_id'])), ARRAY_A);
            if (!empty($wpdb->last_error)) { return new WP_Error('wsm_support_read_failed', 'خواندن گفتگو انجام نشد.', array('status'=>500)); }
            $items[] = array('member_id'=>$mid,'member_name'=>$member ? trim((string)$member['first_name'] . ' ' . (string)$member['last_name']) : 'ورزشکار حذف‌شده','member_code'=>$member ? (string)$member['member_code'] : '', 'last_message'=>$last ? (string)$last['message'] : '', 'last_at'=>$last ? (string)$last['created_at'] : '', 'awaiting_reply'=>$last && (int)$last['sender_id'] === 0);
        }
        return array('items'=>$items);
    }

    public static function staff_athlete_support_reply_rest($request) {
        $body = self::athlete_portal_request_body($request);
        $member_id = absint($body['member_id'] ?? 0);
        if (!$member_id) { return new WP_Error('wsm_support_member_invalid', 'شناسهٔ ورزشکار معتبر نیست.', array('status'=>400)); }
        $staff_id = self::portal_support_staff_id();
        if (!$staff_id) { return new WP_Error('wsm_support_staff_invalid', 'حساب کارمند برای پاسخ معتبر نیست.', array('status'=>401)); }
        return self::support_write($member_id, $body['message'] ?? '', $staff_id);
    }

    private static function athlete_portal_store_intent($intent) {
        if (!is_array($intent) || empty($intent['intent_id'])) { return false; }
        $intents=self::athlete_portal_option_array(self::PORTAL_INTENTS_OPTION);
        $intents[(string)$intent['intent_id']]=$intent;
        $cutoff=time()-180*DAY_IN_SECONDS;
        foreach ($intents as $id=>$item) {
            if (!is_array($item)) { unset($intents[$id]); continue; }
            $created=(int)($item['created_epoch'] ?? 0);
            if ($created>0 && $created<$cutoff && !in_array((string)($item['status'] ?? ''),array('pending','requesting','processing','needs_manual_review'),true)) { unset($intents[$id]); }
        }
        update_option(self::PORTAL_INTENTS_OPTION,$intents,false);
        return true;
    }

    private static function athlete_portal_uuid_v4() {
        try { $bytes=random_bytes(16); }
        catch (Throwable $e) { return ''; }
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
        $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }

    private static function athlete_portal_acquire_lock($scope,$identity,$ttl=300) {
        $name='wsm_athlete_portal_lock_'.substr(hash('sha256',(string)$scope.'|'.(string)$identity),0,40);
        try { $token=bin2hex(random_bytes(16)); }
        catch (Throwable $e) { return new WP_Error('wsm_athlete_lock_random_failed','قفل‌کردن عملیات امن انجام نشد.',array('status'=>500)); }
        $value=array('token'=>$token,'expires'=>time()+max(30,(int)$ttl));
        if (add_option($name,$value,'',false)) { return array('name'=>$name,'token'=>$token); }
        $existing=get_option($name,false);
        if (is_array($existing) && (int)($existing['expires'] ?? 0) < time()) {
            delete_option($name);
            if (add_option($name,$value,'',false)) { return array('name'=>$name,'token'=>$token); }
        }
        return new WP_Error('wsm_athlete_operation_busy','این عملیات هم‌اکنون در حال پردازش است؛ چند لحظهٔ دیگر دوباره تلاش کنید.',array('status'=>409));
    }

    private static function athlete_portal_release_lock($lock) {
        if (!is_array($lock)||empty($lock['name'])||empty($lock['token'])) { return; }
        $stored=get_option((string)$lock['name'],false);
        if (is_array($stored) && isset($stored['token']) && hash_equals((string)$stored['token'],(string)$lock['token'])) { delete_option((string)$lock['name']); }
    }

    private static function athlete_portal_registered_online_gateways() {
        $out = array();
        $wc = function_exists('WC') ? WC() : null;
        if (!$wc || !method_exists($wc, 'payment_gateways')) { return $out; }
        $manager = $wc->payment_gateways();
        if (!$manager || !method_exists($manager, 'payment_gateways')) { return $out; }
        $registered = $manager->payment_gateways();
        if (!is_array($registered)) { return $out; }
        $offline_ids = array('bacs','cheque','check','cod','cash','cashondelivery','banktransfer','manual','offline','pos','wsm_pos_cash','wsm_pos_card','wsm_pos_transfer','wsm_store_account','athlete_portal_zarinpal');
        foreach ($registered as $key => $gateway) {
            if (!is_object($gateway)) { continue; }
            $id = sanitize_key(isset($gateway->id) ? (string)$gateway->id : (string)$key);
            if ($id === '' || !isset($gateway->enabled) || strtolower((string)$gateway->enabled) !== 'yes') { continue; }
            $normalized_id = strtolower(preg_replace('/[^a-z0-9]+/i', '', $id));
            if (in_array($id, $offline_ids, true) || in_array($normalized_id, $offline_ids, true) || preg_match('/(^|_)(cash|cod|cheque|check|bacs|manual|offline|pos)(_|$)/', $id)) { continue; }
            $raw_title = method_exists($gateway, 'get_title') ? $gateway->get_title() : (isset($gateway->title) ? $gateway->title : $id);
            $title = self::text($raw_title, 120);
            if ($title === '') { $title = $id; }
            $out[$id] = array('id'=>$id,'title'=>$title,'instance'=>$gateway);
        }
        return array_values($out);
    }

    private static function athlete_portal_public_gateways() {
        $out = array();
        foreach (self::athlete_portal_registered_online_gateways() as $gateway) {
            $out[] = array('id'=>(string)$gateway['id'],'title'=>(string)$gateway['title']);
        }
        return $out;
    }

    private static function athlete_portal_currency_factor($currency) {
        $currency = strtoupper(trim((string)$currency));
        if ($currency === 'IRR') { return 10; } // club amounts are stored in toman; WC order/ Zarinpal use rial.
        if ($currency === 'IRT') { return 1; }
        return 0;
    }

    private static function athlete_portal_currency_supported() {
        $currency = strtoupper(function_exists('get_woocommerce_currency') ? (string)get_woocommerce_currency() : '');
        return self::athlete_portal_currency_factor($currency) > 0;
    }

    private static function athlete_portal_direct_zarinpal_ready() {
        $settings = self::settings();
        $merchant = trim((string)($settings['portal_merchant_id'] ?? ''));
        return !empty($settings['portal_enabled']) && $merchant !== '' && (bool)preg_match('/^[A-Za-z0-9-]{36}$/', $merchant);
    }

    private static function athlete_portal_gateway_mode() {
        $settings = self::settings();
        if (empty($settings['portal_enabled'])) { return 'unavailable'; }
        if (self::athlete_portal_registered_online_gateways()) { return 'woocommerce'; }
        if (self::athlete_portal_direct_zarinpal_ready()) { return 'zarinpal'; }
        return 'unavailable';
    }

    private static function athlete_portal_gateway_ready() {
        $settings = self::settings();
        return !empty($settings['portal_enabled']) && self::athlete_portal_currency_supported() && self::athlete_portal_gateway_mode() !== 'unavailable';
    }

    private static function athlete_portal_select_gateway($requested_id = '') {
        $settings = self::settings();
        if (empty($settings['portal_enabled'])) { return new WP_Error('wsm_athlete_portal_disabled','پورتال ورزشکار در حال حاضر غیرفعال است.',array('status'=>403)); }
        if (!self::athlete_portal_currency_supported()) { return new WP_Error('wsm_athlete_currency_unsupported','واحد پول فروشگاه پشتیبانی نمی‌شود؛ واحد IRR یا IRT را تنظیم کنید.',array('status'=>409)); }
        $requested_id = is_scalar($requested_id) ? sanitize_key((string)$requested_id) : '';
        $gateways = self::athlete_portal_registered_online_gateways();
        if ($gateways) {
            if ($requested_id !== '') {
                foreach ($gateways as $gateway) {
                    if ((string)$gateway['id'] === $requested_id) { return array('provider'=>'woocommerce','gateway_id'=>(string)$gateway['id'],'gateway_title'=>(string)$gateway['title'],'instance'=>$gateway['instance']); }
                }
                return new WP_Error('wsm_athlete_gateway_unknown','درگاه انتخاب‌شده فعال نیست یا آنلاین نیست؛ درگاه را تازه انتخاب کنید.',array('status'=>409,'available_gateways'=>self::athlete_portal_public_gateways()));
            }
            if (count($gateways) > 1) { return new WP_Error('wsm_athlete_gateway_choice_required','یک درگاه پرداخت را انتخاب کنید.',array('status'=>400,'available_gateways'=>self::athlete_portal_public_gateways())); }
            return array('provider'=>'woocommerce','gateway_id'=>(string)$gateways[0]['id'],'gateway_title'=>(string)$gateways[0]['title'],'instance'=>$gateways[0]['instance']);
        }
        if ($requested_id !== '' && $requested_id !== 'athlete_portal_zarinpal') { return new WP_Error('wsm_athlete_gateway_unknown','درگاه انتخاب‌شده در دسترس نیست؛ صفحه را تازه‌سازی کنید.',array('status'=>409)); }
        if (self::athlete_portal_direct_zarinpal_ready()) { return array('provider'=>'zarinpal','gateway_id'=>'athlete_portal_zarinpal','gateway_title'=>'زرین‌پال مستقیم','instance'=>null); }
        return new WP_Error('wsm_athlete_gateway_unconfigured','هیچ درگاه آنلاین فعالی در ووکامرس یا مرچنت‌کد جایگزین زرین‌پال تنظیم نشده است.',array('status'=>503));
    }

    private static function athlete_portal_woocommerce_order_amount($amount) {
        $amount = (float)$amount;
        if (!is_finite($amount) || $amount <= 0) { return new WP_Error('wsm_athlete_amount_invalid','مبلغ پرداخت معتبر نیست.',array('status'=>400)); }
        $currency = strtoupper(function_exists('get_woocommerce_currency') ? (string)get_woocommerce_currency() : '');
        $factor = self::athlete_portal_currency_factor($currency);
        if (!$factor) { return new WP_Error('wsm_athlete_currency_unsupported','واحد پول فروشگاه پشتیبانی نمی‌شود؛ واحد IRR یا IRT را تنظیم کنید.',array('status'=>409)); }
        $scaled = $amount * $factor;
        $decimals = min(6, max(0, (int)(function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 0)));
        $formatted = function_exists('wc_format_decimal') ? wc_format_decimal($scaled, $decimals) : number_format($scaled, $decimals, '.', '');
        if (abs($scaled - (float)$formatted) > 0.000001) { return new WP_Error('wsm_athlete_amount_precision','مبلغ با دقت واحد پول فروشگاه سازگار نیست.',array('status'=>400)); }
        return array('amount'=>(string)$formatted,'currency'=>$currency);
    }

    private static function athlete_portal_local_to_rial($amount) {
        $amount=(float)$amount;
        if (!is_finite($amount)||$amount<=0) { return new WP_Error('wsm_athlete_amount_invalid','مبلغ پرداخت معتبر نیست.',array('status'=>400)); }
        $currency=strtoupper(function_exists('get_woocommerce_currency')?(string)get_woocommerce_currency():'');
        if ($currency==='IRR') { $factor=1; }
        elseif ($currency==='IRT') { $factor=10; }
        else { return new WP_Error('wsm_athlete_currency_unsupported','واحد پول فروشگاه برای پرداخت پشتیبان زرین‌پال پشتیبانی نمی‌شود؛ واحد IRR یا IRT را تنظیم کنید.',array('status'=>409)); }
        $scaled=$amount*$factor;$rial=(int)round($scaled);
        if ($rial<10000) { return new WP_Error('wsm_athlete_amount_below_gateway_minimum','حداقل مبلغ قابل پرداخت در زرین‌پال ۱۰٬۰۰۰ ریال است.',array('status'=>400)); }
        if (abs($scaled-$rial)>0.0001 || $rial<1) { return new WP_Error('wsm_athlete_amount_precision','مبلغ قابل تبدیل به ریال کامل نیست.',array('status'=>400)); }
        return $rial;
    }

    private static function athlete_portal_zarinpal_base($sandbox) {
        return !empty($sandbox) ? 'https://sandbox.zarinpal.com/pg/v4' : 'https://api.zarinpal.com/pg/v4';
    }

    private static function athlete_portal_reserve_coupon($code,$member_id,$intent_id) {
        if ($code==='') { return true; }
        $lock=self::athlete_portal_acquire_lock('coupon',$code,30);
        if (is_wp_error($lock)) { return $lock; }
        try {
            $coupons=self::athlete_portal_option_array(self::PORTAL_COUPONS_OPTION);
            if (empty($coupons[$code])||!is_array($coupons[$code])) { return new WP_Error('wsm_athlete_coupon_missing','کد تخفیف پیدا نشد.',array('status'=>400)); }
            $coupon=$coupons[$code];
            if ((int)($coupon['member_id'] ?? 0)!==(int)$member_id) { return new WP_Error('wsm_athlete_coupon_owner','این کد تخفیف به پروندهٔ شما تعلق ندارد.',array('status'=>403)); }
            if (strtotime((string)($coupon['expires_at'] ?? '')) < strtotime(self::athlete_portal_today())) { return new WP_Error('wsm_athlete_coupon_expired','اعتبار کد تخفیف به پایان رسیده است.',array('status'=>400)); }
            if ((string)($coupon['status'] ?? '')==='used') { return new WP_Error('wsm_athlete_coupon_used','این کد تخفیف قبلاً مصرف شده است.',array('status'=>409)); }
            if ((string)($coupon['status'] ?? '')==='reserved' && (string)($coupon['reserved_by'] ?? '')!==$intent_id && (int)($coupon['reserved_until'] ?? 0)>time()) {
                return new WP_Error('wsm_athlete_coupon_reserved','این کد هم‌اکنون در پرداخت دیگری رزرو شده است.',array('status'=>409));
            }
            $coupon['status']='reserved';$coupon['reserved_by']=$intent_id;$coupon['reserved_until']=time()+7*DAY_IN_SECONDS;
            $coupons[$code]=$coupon;update_option(self::PORTAL_COUPONS_OPTION,$coupons,false);
            return true;
        } finally { self::athlete_portal_release_lock($lock); }
    }

    private static function athlete_portal_release_coupon($code,$intent_id) {
        if ($code==='') { return; }
        $lock=self::athlete_portal_acquire_lock('coupon',$code,30);
        if (is_wp_error($lock)) { return; }
        try {
            $coupons=self::athlete_portal_option_array(self::PORTAL_COUPONS_OPTION);
            if (isset($coupons[$code]) && is_array($coupons[$code]) && (string)($coupons[$code]['reserved_by'] ?? '')===(string)$intent_id && (string)($coupons[$code]['status'] ?? '')==='reserved') {
                $coupons[$code]['status']='unused';$coupons[$code]['reserved_by']='';$coupons[$code]['reserved_until']=0;
                update_option(self::PORTAL_COUPONS_OPTION,$coupons,false);
            }
        } finally { self::athlete_portal_release_lock($lock); }
    }

    public static function validate_athlete_portal_coupon($raw_code,$member_id,$plan_price) {
        $code=strtoupper(trim(sanitize_text_field(is_scalar($raw_code)?(string)$raw_code:'')));
        $code=preg_replace('/[^A-Z0-9-]/','',$code);
        if ($code==='') { return new WP_Error('wsm_athlete_coupon_required','کد تخفیف را وارد کنید.',array('status'=>400)); }
        $member_id=absint($member_id);$price=(float)$plan_price;
        if (!$member_id||!is_finite($price)||$price<=0) { return new WP_Error('wsm_athlete_coupon_plan_invalid','تعرفهٔ انتخاب‌شده معتبر نیست.',array('status'=>400)); }
        $coupons=self::athlete_portal_option_array(self::PORTAL_COUPONS_OPTION);
        if (empty($coupons[$code])||!is_array($coupons[$code])) { return new WP_Error('wsm_athlete_coupon_missing','کد تخفیف پیدا نشد یا دیگر معتبر نیست.',array('status'=>400)); }
        $coupon=$coupons[$code];
        if ((int)($coupon['member_id'] ?? 0)!==$member_id) { return new WP_Error('wsm_athlete_coupon_owner','این کد تخفیف به پروندهٔ شما تعلق ندارد.',array('status'=>403)); }
        $today=self::athlete_portal_today();
        if ((string)($coupon['status'] ?? '')==='reserved' && (int)($coupon['reserved_until'] ?? 0)<=time()) {
            $reserved_by=(string)($coupon['reserved_by'] ?? '');
            $intents=self::athlete_portal_option_array(self::PORTAL_INTENTS_OPTION);
            $reserver=isset($intents[$reserved_by])&&is_array($intents[$reserved_by])?$intents[$reserved_by]:array();
            if (empty($reserver['gateway_verified']) && !in_array((string)($reserver['status'] ?? ''),array('processing','needs_manual_review'),true)) {
                $coupons[$code]['status']='unused';$coupons[$code]['reserved_by']='';$coupons[$code]['reserved_until']=0;
                update_option(self::PORTAL_COUPONS_OPTION,$coupons,false);$coupon=$coupons[$code];
            }
        }
        if (strtotime((string)($coupon['expires_at'] ?? '')) < strtotime($today)) { return new WP_Error('wsm_athlete_coupon_expired','اعتبار کد تخفیف به پایان رسیده است.',array('status'=>400)); }
        if ((string)($coupon['status'] ?? '')==='used') { return new WP_Error('wsm_athlete_coupon_used','این کد تخفیف قبلاً مصرف شده است.',array('status'=>409)); }
        if ((string)($coupon['status'] ?? '')==='reserved' && (int)($coupon['reserved_until'] ?? 0)>time()) { return new WP_Error('wsm_athlete_coupon_reserved','این کد هم‌اکنون در پرداخت دیگری رزرو شده است.',array('status'=>409)); }
        $percent=max(0,min(100,(float)($coupon['discount_percent'] ?? 0)));
        if ($percent<=0) { return new WP_Error('wsm_athlete_coupon_invalid','درصد تخفیف این کد معتبر نیست.',array('status'=>400)); }
        $decimals=min(6,max(0,(int)wc_get_price_decimals()));
        $discount=(float)wc_format_decimal($price*$percent/100,$decimals);
        $discount=min($price,max(0,$discount));
        return array('code'=>$code,'discount_percent'=>$percent,'discount_amount'=>(string)wc_format_decimal($discount,$decimals),'original_price'=>(string)wc_format_decimal($price,$decimals),'final_price'=>(string)wc_format_decimal(max(0,$price-$discount),$decimals),'expires_at'=>(string)$coupon['expires_at']);
    }

    public static function athlete_portal_validate_coupon_rest($request) {
        $member=self::athlete_portal_require_member($request);
        if (is_wp_error($member)) { return $member; }
        $body=self::athlete_portal_request_body($request);$plan_id=absint($body['plan_id'] ?? 0);$code=$body['code'] ?? ($body['coupon_code'] ?? '');
        global $wpdb;
        $wpdb->last_error='';
        $plan=$wpdb->get_row($wpdb->prepare('SELECT id,name,price,active FROM '.WSM_Club::table('plans').' WHERE id=%d',$plan_id));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_plan_lookup_failed','خواندن تعرفه انجام نشد.',array('status'=>500)); }
        if (!$plan || !(int)$plan->active) { return new WP_Error('wsm_athlete_plan_invalid','تعرفهٔ فعال پیدا نشد.',array('status'=>404)); }
        return self::validate_athlete_portal_coupon($code,(int)$member->id,(float)$plan->price);
    }

    private static function athlete_portal_check_membership_overlap($member_id,$start_date,$duration_days) {
        global $wpdb;
        $end_date=self::athlete_portal_date_add($start_date,max(1,(int)$duration_days)-1);
        if (!$end_date) { return new WP_Error('wsm_athlete_date_invalid','تاریخ عضویت معتبر نیست.',array('status'=>400)); }
        $table=WSM_Club::table('memberships');$wpdb->last_error='';
        $overlap=$wpdb->get_row($wpdb->prepare("SELECT id,end_date FROM $table WHERE member_id=%d AND status IN ('active','scheduled','frozen') AND start_date <= %s AND end_date >= %s ORDER BY end_date DESC,id DESC LIMIT 1",absint($member_id),$end_date,$start_date));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_overlap_lookup_failed','بررسی هم‌پوشانی عضویت انجام نشد.',array('status'=>500)); }
        if ($overlap) { return new WP_Error('wsm_club_membership_overlap','این بازه با عضویت دیگری هم‌پوشانی دارد؛ تاریخ شروع را تغییر دهید.',array('status'=>409,'overlap_end_date'=>(string)$overlap->end_date)); }
        return $end_date;
    }

    private static function athlete_portal_existing_request_intent($request_uuid,$member_id,$plan_id,$intent_type) {
        if ($request_uuid==='') { return null; }
        $intents=self::athlete_portal_option_array(self::PORTAL_INTENTS_OPTION);
        foreach ($intents as $intent) {
            if (!is_array($intent)||(string)($intent['request_uuid'] ?? '')!==$request_uuid) { continue; }
            if ((int)($intent['member_id'] ?? 0)!==(int)$member_id || (int)($intent['plan_id'] ?? 0)!==(int)$plan_id || (string)($intent['intent_type'] ?? '')!==$intent_type) {
                return new WP_Error('wsm_athlete_request_uuid_conflict','شناسهٔ درخواست قبلاً برای پرداخت دیگری استفاده شده است.',array('status'=>409));
            }
            if (in_array((string)($intent['status'] ?? ''),array('pending','requesting','processing','needs_manual_review'),true)) {
                return array('intent_id'=>(string)$intent['intent_id'],'payment_url'=>(string)($intent['payment_url'] ?? ''),'status'=>(string)$intent['status'],'amount'=>(string)($intent['amount'] ?? '0'),'idempotent_replay'=>true);
            }
            if ((string)($intent['status'] ?? '')==='completed') { return array('intent_id'=>(string)$intent['intent_id'],'status'=>'completed','result'=>$intent['result'] ?? array(),'idempotent_replay'=>true); }
            return new WP_Error('wsm_athlete_request_uuid_closed','این درخواست پرداخت دیگر فعال نیست؛ درخواست تازه بسازید.',array('status'=>409));
        }
        return null;
    }

    public static function athlete_portal_checkout_rest($request) {
        $member=self::athlete_portal_require_member($request);
        if (is_wp_error($member)) { return $member; }
        $body=self::athlete_portal_request_body($request);$plan_id=absint($body['plan_id'] ?? 0);
        if (!$plan_id) { return new WP_Error('wsm_athlete_plan_invalid','تعرفهٔ انتخاب‌شده معتبر نیست.',array('status'=>400)); }
        global $wpdb;
        $wpdb->last_error='';
        $plan=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WSM_Club::table('plans').' WHERE id=%d AND active=1',$plan_id));
        if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_plan_lookup_failed','خواندن تعرفه انجام نشد.',array('status'=>500)); }
        if (!$plan) { return new WP_Error('wsm_athlete_plan_invalid','تعرفهٔ فعال پیدا نشد.',array('status'=>404)); }
        $context=self::athlete_portal_membership_context((int)$member->id);
        if (is_wp_error($context)) { return $context; }
        $today=self::athlete_portal_today();
        $mode=isset($body['start_mode'])&&is_scalar($body['start_mode'])?sanitize_key((string)$body['start_mode']):'today';
        if (!in_array($mode,array('today','after_current','after_previous_end','custom'),true)) { return new WP_Error('wsm_athlete_start_mode_invalid','حالت انتخاب تاریخ شروع معتبر نیست.',array('status'=>400)); }
        $prior_end=(string)$context['previous_end_date'];
        if (!empty($context['has_unexpired_membership'])) {
            $mode='after_current';
            $start=self::athlete_portal_date_add($prior_end,1);
        } elseif ($mode==='today') { $start=$today; }
        elseif ($mode==='after_current'||$mode==='after_previous_end') {
            $candidate=$prior_end!==''?self::athlete_portal_date_add($prior_end,1):$today;
            $start=$candidate && $candidate>$today ? $candidate : $today;
        } else {
            $start=self::athlete_portal_parse_date($body['start_date'] ?? '');
            if (!$start) { return new WP_Error('wsm_athlete_date_invalid','تاریخ شمسی را به‌صورت معتبر وارد کنید.',array('status'=>400)); }
            if ($start<$today) { return new WP_Error('wsm_athlete_date_past','تاریخ شروع نمی‌تواند پیش از امروز باشد.',array('status'=>400)); }
        }
        if (!$start) { return new WP_Error('wsm_athlete_date_invalid','تاریخ شروع عضویت معتبر نیست.',array('status'=>400)); }
        $end=self::athlete_portal_check_membership_overlap((int)$member->id,$start,(int)$plan->duration_days);
        if (is_wp_error($end)) { return $end; }
        $base_price=(float)$plan->price;
        if ($base_price<0) { return new WP_Error('wsm_athlete_plan_price_invalid','مبلغ تعرفه معتبر نیست.',array('status'=>409)); }
        $coupon_code=isset($body['coupon_code'])&&is_scalar($body['coupon_code'])?strtoupper(trim(sanitize_text_field((string)$body['coupon_code']))):'';
        $coupon_data=null;$discount=0.0;$discount_note='';
        if ($coupon_code!=='') {
            $coupon_data=self::validate_athlete_portal_coupon($coupon_code,(int)$member->id,$base_price);
            if (is_wp_error($coupon_data)) { return $coupon_data; }
            $discount=(float)$coupon_data['discount_amount'];
            $discount_note='کوپن پورتال '.$coupon_data['code'].' · تخفیف '.$coupon_data['discount_percent'].'٪';
        }
        $decimals=min(6,max(0,(int)wc_get_price_decimals()));
        $amount=(float)wc_format_decimal(max(0,$base_price-$discount),$decimals);
        $request_uuid=isset($body['request_uuid'])&&is_scalar($body['request_uuid'])?strtolower(trim((string)$body['request_uuid'])):'';
        if ($request_uuid!=='' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$request_uuid)) { return new WP_Error('wsm_athlete_request_uuid_invalid','شناسهٔ یکتای درخواست معتبر نیست.',array('status'=>400)); }
        $replay=self::athlete_portal_existing_request_intent($request_uuid,(int)$member->id,$plan_id,'membership');
        if (is_wp_error($replay)) { return $replay; }
        if (is_array($replay)) { return $replay; }
        $membership_uuid=self::athlete_portal_uuid_v4();$payment_uuid=self::athlete_portal_uuid_v4();
        if ($membership_uuid===''||$payment_uuid==='') { return new WP_Error('wsm_athlete_uuid_failed','ساخت شناسهٔ امن پرداخت انجام نشد.',array('status'=>500)); }
        try { $intent_id=bin2hex(random_bytes(16)); } catch (Throwable $e) { return new WP_Error('wsm_athlete_intent_random_failed','ساخت شناسهٔ امن پرداخت انجام نشد.',array('status'=>500)); }
        $intent=array(
            'intent_id'=>$intent_id,'intent_type'=>'membership','status'=>'requesting','member_id'=>(int)$member->id,'plan_id'=>(int)$plan->id,'plan_name'=>(string)$plan->name,
            'base_price'=>(string)wc_format_decimal($base_price,$decimals),'discount_amount'=>(string)wc_format_decimal($discount,$decimals),'discount_note'=>$discount_note,'coupon_code'=>$coupon_data?(string)$coupon_data['code']:'',
            'amount'=>(string)wc_format_decimal($amount,$decimals),'gateway_amount_rial'=>0,'currency'=>function_exists('get_woocommerce_currency')?get_woocommerce_currency():'',
            'membership_uuid'=>$membership_uuid,'payment_uuid'=>$payment_uuid,'start_mode'=>$mode,'start_date'=>$start,'end_date'=>$end,'duration_days'=>(int)$plan->duration_days,
            'needs_staff_review'=>empty($context['has_unexpired_membership']),'had_unexpired_prior'=>!empty($context['has_unexpired_membership']),'prior_end_date'=>$prior_end,'prior_membership_count'=>(int)$context['prior_membership_count'],
            'request_uuid'=>$request_uuid,'created_at'=>current_time('mysql'),'created_epoch'=>time(),'expires_at'=>time()+7*DAY_IN_SECONDS,'free'=>$amount<=0.000001?1:0,
        );
        $requested_gateway_id = isset($body['gateway_id']) && is_scalar($body['gateway_id']) ? sanitize_key((string)$body['gateway_id']) : '';
        return self::create_athlete_portal_payment_request($intent,(string)$member->phone,$requested_gateway_id);
    }

    public static function athlete_portal_debt_checkout_rest($request) {
        $member=self::athlete_portal_require_member($request);
        if (is_wp_error($member)) { return $member; }
        if (!self::athlete_portal_gateway_ready()) { return new WP_Error('wsm_athlete_gateway_unconfigured','پورتال یا مرچنت‌کد زرین‌پال تنظیم نشده است.',array('status'=>503)); }
        $body=self::athlete_portal_request_body($request);$summary=self::get_athlete_debt_summary((int)$member->id);
        if (is_wp_error($summary)) { return $summary; }
        $total=(float)$summary['total_debt'];
        if ($total<=0.000001) { return new WP_Error('wsm_athlete_no_debt','بدهی قابل پرداختی ندارید.',array('status'=>409)); }
        $has_percent=array_key_exists('percent',$body)&&$body['percent']!=='';
        $has_amount=array_key_exists('amount',$body)&&$body['amount']!=='';
        if ($has_percent===$has_amount) { return new WP_Error('wsm_athlete_debt_amount_required','درصد پرداخت یا مبلغ دلخواه را مشخص کنید.',array('status'=>400)); }
        $decimals=min(6,max(0,(int)wc_get_price_decimals()));
        if ($has_percent) {
            if (!is_numeric($body['percent'])||(float)$body['percent']<1||(float)$body['percent']>100) { return new WP_Error('wsm_athlete_debt_percent_invalid','درصد پرداخت باید بین ۱ تا ۱۰۰ باشد.',array('status'=>400)); }
            $amount=(float)wc_format_decimal($total*(float)$body['percent']/100,$decimals);
        } else {
            $amount=self::amount($body['amount'],'مبلغ پرداخت بدهی',false);
            if (is_wp_error($amount)) { return $amount; }
            $amount=(float)$amount;
        }
        if ($amount<=0 || $amount>$total+0.000001) { return new WP_Error('wsm_athlete_debt_amount_invalid','مبلغ پرداخت باید مثبت و حداکثر برابر کل بدهی باشد.',array('status'=>409,'total_debt'=>$total)); }
        $request_uuid=isset($body['request_uuid'])&&is_scalar($body['request_uuid'])?strtolower(trim((string)$body['request_uuid'])):'';
        if ($request_uuid!=='' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$request_uuid)) { return new WP_Error('wsm_athlete_request_uuid_invalid','شناسهٔ یکتای درخواست معتبر نیست.',array('status'=>400)); }
        $replay=self::athlete_portal_existing_request_intent($request_uuid,(int)$member->id,0,'debt');
        if (is_wp_error($replay)) { return $replay; }
        if (is_array($replay)) { return $replay; }
        try { $debt_intent_id=bin2hex(random_bytes(16)); } catch (Throwable $e) { return new WP_Error('wsm_athlete_intent_random_failed','ساخت شناسهٔ امن پرداخت انجام نشد.',array('status'=>500)); }
        $intent=array('intent_id'=>$debt_intent_id,'intent_type'=>'debt','status'=>'requesting','member_id'=>(int)$member->id,'plan_id'=>0,'plan_name'=>'تسویهٔ بدهی ورزشکار','amount'=>(string)wc_format_decimal($amount,$decimals),'gateway_amount_rial'=>0,'currency'=>function_exists('get_woocommerce_currency')?get_woocommerce_currency():'','request_uuid'=>$request_uuid,'created_at'=>current_time('mysql'),'created_epoch'=>time(),'expires_at'=>time()+7*DAY_IN_SECONDS,'debt_application_started'=>false,'debt_allocations'=>array());
        $requested_gateway_id = isset($body['gateway_id']) && is_scalar($body['gateway_id']) ? sanitize_key((string)$body['gateway_id']) : '';
        return self::create_athlete_portal_payment_request($intent,(string)$member->phone,$requested_gateway_id);
    }

    private static function create_athlete_portal_payment_request($intent,$phone,$requested_gateway_id='') {
        if (!is_array($intent)||empty($intent['intent_id'])) { return new WP_Error('wsm_athlete_intent_invalid','درخواست پرداخت معتبر نیست.',array('status'=>400)); }
        $settings=self::settings();
        $is_free=!empty($intent['free']);
        if (empty($settings['portal_enabled'])) { return new WP_Error('wsm_athlete_portal_disabled','پورتال ورزشکار در حال حاضر غیرفعال است.',array('status'=>403)); }
        $gateway=null;
        if (!$is_free) {
            $gateway=self::athlete_portal_select_gateway($requested_gateway_id);
            if (is_wp_error($gateway)) { return $gateway; }
            $intent['gateway_provider']=(string)$gateway['provider'];
            $intent['gateway_id']=(string)$gateway['gateway_id'];
            $intent['gateway_title']=(string)$gateway['gateway_title'];
            $intent['currency']=function_exists('get_woocommerce_currency')?(string)get_woocommerce_currency():'';
            if ($gateway['provider']==='woocommerce') {
                $order_amount=self::athlete_portal_woocommerce_order_amount((float)($intent['amount'] ?? 0));
                if (is_wp_error($order_amount)) { return $order_amount; }
                $intent['gateway_order_amount']=(string)$order_amount['amount'];
                $intent['gateway_currency']=(string)$order_amount['currency'];
                $intent['gateway_amount_rial']=0;
            } else {
                $gateway_rial=self::athlete_portal_local_to_rial((float)($intent['amount'] ?? 0));
                if (is_wp_error($gateway_rial)) { return $gateway_rial; }
                $intent['gateway_amount_rial']=(int)$gateway_rial;
                $intent['gateway_currency']='IRR';
            }
        } else {
            $intent['gateway_provider']='free';
            $intent['gateway_id']='free';
            $intent['gateway_title']='پرداخت رایگان';
        }
        $coupon_code=(string)($intent['coupon_code'] ?? '');
        if ($coupon_code!=='') {
            $reserved=self::athlete_portal_reserve_coupon($coupon_code,(int)$intent['member_id'],(string)$intent['intent_id']);
            if (is_wp_error($reserved)) { return $reserved; }
        }
        $intent['status']='requesting';$intent['payment_url']='';$intent['authority']='';$intent['gateway_verified']=false;
        self::athlete_portal_store_intent($intent);
        if ($is_free) {
            $intent['status']='processing';$intent['gateway_verified']=true;$intent['gateway_ref_id']='FREE-'.$intent['intent_id'];$intent['authority']='';
            self::athlete_portal_store_intent($intent);
            $processed=self::process_athlete_portal_payment((string)$intent['intent_id'],'','OK');
            if (is_wp_error($processed)) { return $processed; }
            return array('success'=>true,'free'=>true,'intent_id'=>(string)$intent['intent_id'],'result'=>$processed);
        }
        if ($gateway['provider']==='woocommerce') {
            $created=self::athlete_portal_create_woocommerce_order($intent,$phone,$gateway['instance']);
            if (is_wp_error($created)) {
                $intent['status']='failed';$intent['gateway_error']='woocommerce_order_creation_failed';self::athlete_portal_store_intent($intent);self::athlete_portal_release_coupon($coupon_code,(string)$intent['intent_id']);
                return $created;
            }
            $intent['woocommerce_order_id']=(int)$created['order_id'];
            $intent['authority']='WC-ORDER-'.(int)$created['order_id'];
            $intent['status']='pending';$intent['payment_url']=(string)$created['payment_url'];$intent['requested_at']=current_time('mysql');
            self::athlete_portal_store_intent($intent);
            return array('intent_id'=>(string)$intent['intent_id'],'payment_url'=>(string)$intent['payment_url'],'gateway_id'=>(string)$intent['gateway_id'],'gateway_title'=>(string)$intent['gateway_title'],'order_id'=>(int)$created['order_id'],'amount'=>(string)$intent['amount'],'currency'=>(string)$intent['currency'],'currency_symbol'=>function_exists('wsm_currency_symbol')?wsm_currency_symbol():'','start_date'=>(string)($intent['start_date'] ?? ''),'start_date_jalali'=>!empty($intent['start_date'])?self::athlete_portal_format_jalali((string)$intent['start_date']):'','discount_amount'=>(string)($intent['discount_amount'] ?? '0'),'status'=>'pending');
        }
        $amount=(int)($intent['gateway_amount_rial'] ?? 0);
        if ($amount<10000) { $intent['status']='failed';$intent['gateway_error']='amount_below_minimum';self::athlete_portal_store_intent($intent);self::athlete_portal_release_coupon($coupon_code,(string)$intent['intent_id']);return new WP_Error('wsm_athlete_amount_below_gateway_minimum','حداقل مبلغ قابل پرداخت در زرین‌پال ۱۰٬۰۰۰ ریال است.',array('status'=>400)); }
        $merchant=trim((string)($settings['portal_merchant_id'] ?? ''));
        $callback=add_query_arg(array('wsm_athlete_payment'=>'1','intent'=>(string)$intent['intent_id']),home_url('/athlete-portal/'));
        $sandbox=!empty($settings['portal_sandbox']);$base=self::athlete_portal_zarinpal_base($sandbox);
        $description='پرداخت پورتال ورزشکار · '.(string)($intent['plan_name'] ?? 'تسویه بدهی');
        $payload=array('merchant_id'=>$merchant,'amount'=>$amount,'description'=>self::text($description,190),'callback_url'=>$callback,'metadata'=>array('mobile'=>preg_replace('/\D/','',self::athlete_portal_normalize_phone($phone))));
        $response=wp_remote_post($base.'/payment/request.json',array('timeout'=>25,'redirection'=>0,'headers'=>array('Accept'=>'application/json','Content-Type'=>'application/json'),'body'=>wp_json_encode($payload)));
        if (is_wp_error($response)) {
            $intent['status']='failed';$intent['gateway_error']='network';self::athlete_portal_store_intent($intent);self::athlete_portal_release_coupon($coupon_code,(string)$intent['intent_id']);
            return new WP_Error('wsm_athlete_gateway_unreachable','ارتباط با درگاه زرین‌پال برقرار نشد؛ کمی بعد دوباره تلاش کنید.',array('status'=>502));
        }
        $http=(int)wp_remote_retrieve_response_code($response);$json=json_decode((string)wp_remote_retrieve_body($response),true);
        $data=is_array($json)&&isset($json['data'])&&is_array($json['data'])?$json['data']:array();
        $code=isset($data['code'])?(int)$data['code']:0;
        $authority=isset($data['authority'])&&is_scalar($data['authority'])?trim((string)$data['authority']):'';
        if ($http<200||$http>=300||$code!==100||!preg_match('/^[A-Za-z0-9_-]{10,80}$/',$authority)) {
            $intent['status']='failed';$intent['gateway_error']='request_rejected';self::athlete_portal_store_intent($intent);self::athlete_portal_release_coupon($coupon_code,(string)$intent['intent_id']);
            return new WP_Error('wsm_athlete_gateway_request_failed','درخواست پرداخت از زرین‌پال پذیرفته نشد؛ تنظیمات درگاه را بررسی کنید.',array('status'=>502));
        }
        $intents=self::athlete_portal_option_array(self::PORTAL_INTENTS_OPTION);
        foreach ($intents as $other_id=>$other) {
            if ((string)$other_id!==(string)$intent['intent_id']&&is_array($other)&&(string)($other['authority'] ?? '')===$authority) {
                $intent['status']='failed';$intent['gateway_error']='authority_conflict';self::athlete_portal_store_intent($intent);self::athlete_portal_release_coupon($coupon_code,(string)$intent['intent_id']);
                return new WP_Error('wsm_athlete_gateway_authority_conflict','شناسهٔ مرجع درگاه قبلاً استفاده شده است؛ پرداخت را دوباره آغاز کنید.',array('status'=>409));
            }
        }
        $pay_base=$sandbox?'https://sandbox.zarinpal.com/pg/StartPay/':'https://www.zarinpal.com/pg/StartPay/';
        $intent['authority']=$authority;$intent['status']='pending';$intent['payment_url']=$pay_base.rawurlencode($authority);$intent['requested_at']=current_time('mysql');
        self::athlete_portal_store_intent($intent);
        return array('intent_id'=>(string)$intent['intent_id'],'payment_url'=>(string)$intent['payment_url'],'gateway_id'=>(string)$intent['gateway_id'],'gateway_title'=>(string)$intent['gateway_title'],'authority'=>$authority,'amount'=>(string)$intent['amount'],'currency'=>(string)$intent['currency'],'currency_symbol'=>function_exists('wsm_currency_symbol')?wsm_currency_symbol():'','start_date'=>(string)($intent['start_date'] ?? ''),'start_date_jalali'=>!empty($intent['start_date'])?self::athlete_portal_format_jalali((string)$intent['start_date']):'','discount_amount'=>(string)($intent['discount_amount'] ?? '0'),'status'=>'pending');
    }

    private static function athlete_portal_create_woocommerce_order($intent,$phone,$gateway_instance) {
        if (!function_exists('wc_create_order') || !class_exists('WC_Order_Item_Fee') || !is_object($gateway_instance)) {
            return new WP_Error('wsm_athlete_woocommerce_unavailable','ساخت سفارش پرداخت استاندارد ووکامرس در دسترس نیست.',array('status'=>503));
        }
        $member=self::athlete_portal_find_member_by_id((int)($intent['member_id'] ?? 0));
        if (is_wp_error($member)) { return $member; }
        if (!$member || (string)$member->status!=='active') { return new WP_Error('wsm_athlete_member_inactive','پروندهٔ فعال ورزشکار پیدا نشد.',array('status'=>403)); }
        $expected=(float)($intent['gateway_order_amount'] ?? 0);
        $currency=(string)($intent['gateway_currency'] ?? '');
        if ($expected<=0 || !in_array($currency,array('IRR','IRT'),true)) { return new WP_Error('wsm_athlete_order_amount_invalid','مبلغ یا واحد پول سفارش ووکامرس معتبر نیست.',array('status'=>409)); }
        $order=wc_create_order(array('status'=>'pending','customer_id'=>0,'created_via'=>'wsm-athlete-portal'));
        if (is_wp_error($order) || !is_object($order)) { return new WP_Error('wsm_athlete_woocommerce_order_failed','ساخت سفارش پرداخت ووکامرس انجام نشد.',array('status'=>500)); }
        $fee=new WC_Order_Item_Fee();
        $fee->set_name(self::text('پرداخت پورتال ورزشکار · '.(string)($intent['plan_name'] ?? 'تسویهٔ بدهی'),190));
        $fee->set_amount($expected);
        $fee->set_total($expected);
        if (method_exists($fee,'set_tax_status')) { $fee->set_tax_status('none'); }
        $order->add_item($fee);
        if (method_exists($order,'set_currency')) { $order->set_currency($currency); }
        if (method_exists($order,'set_billing_phone')) { $order->set_billing_phone(self::athlete_portal_normalize_phone($phone)); }
        if (method_exists($order,'set_billing_first_name')) { $order->set_billing_first_name(self::text((string)($member->first_name ?? ''),100)); }
        if (method_exists($order,'set_billing_last_name')) { $order->set_billing_last_name(self::text((string)($member->last_name ?? ''),100)); }
        if (method_exists($order,'set_billing_email') && !empty($member->email) && function_exists('is_email') && is_email((string)$member->email)) { $order->set_billing_email((string)$member->email); }
        if (method_exists($order,'set_payment_method')) { $order->set_payment_method($gateway_instance); }
        else { $order->set_payment_method((string)($intent['gateway_id'] ?? '')); }
        if (method_exists($order,'set_payment_method_title')) { $order->set_payment_method_title((string)($intent['gateway_title'] ?? 'درگاه آنلاین')); }
        $order->update_meta_data('_wsm_athlete_portal_intent_id',(string)$intent['intent_id']);
        $order->update_meta_data('_wsm_athlete_portal_member_id',(int)$intent['member_id']);
        $order->update_meta_data('_wsm_athlete_portal_intent_type',(string)($intent['intent_type'] ?? ''));
        $order->update_meta_data('_wsm_athlete_portal_gateway_id',(string)($intent['gateway_id'] ?? ''));
        $order->update_meta_data('_wsm_athlete_portal_expected_amount',(string)$intent['gateway_order_amount']);
        $order->update_meta_data('_wsm_athlete_portal_currency',$currency);
        $order->calculate_totals(false);
        if (abs((float)$order->get_total()-$expected)>0.000001 || strtoupper((string)$order->get_currency())!==$currency) {
            if (method_exists($order,'delete')) { $order->delete(true); }
            return new WP_Error('wsm_athlete_woocommerce_total_mismatch','جمع یا واحد پول سفارش ووکامرس با مبلغ پرداخت مطابقت ندارد.',array('status'=>409));
        }
        $order_id=(int)$order->save();
        if (!$order_id) { if (method_exists($order,'delete')) { $order->delete(true); } return new WP_Error('wsm_athlete_woocommerce_order_failed','ذخیرهٔ سفارش ووکامرس انجام نشد.',array('status'=>500)); }
        $payment_url=(string)$order->get_checkout_payment_url(true);
        $payment_parts=$payment_url!==''?parse_url($payment_url):false;
        $home_parts=parse_url(home_url('/'));
        $payment_scheme=is_array($payment_parts)?strtolower((string)($payment_parts['scheme'] ?? '')):'';
        $home_scheme=is_array($home_parts)?strtolower((string)($home_parts['scheme'] ?? '')):'';
        $payment_port=is_array($payment_parts)?(int)($payment_parts['port'] ?? ($payment_scheme==='https'?443:80)):0;
        $home_port=is_array($home_parts)?(int)($home_parts['port'] ?? ($home_scheme==='https'?443:80)):0;
        $payment_url_is_local=is_array($payment_parts)&&is_array($home_parts)&&in_array($payment_scheme,array('http','https'),true)&&
            ($home_scheme!=='https'||$payment_scheme==='https')&&strtolower((string)($payment_parts['host'] ?? ''))!==''&&
            strtolower((string)($payment_parts['host'] ?? ''))===strtolower((string)($home_parts['host'] ?? ''))&&$payment_port===$home_port&&
            empty($payment_parts['user'])&&empty($payment_parts['pass']);
        if (!$payment_url_is_local) {
            if (method_exists($order,'delete')) { $order->delete(true); }
            return new WP_Error('wsm_athlete_woocommerce_payment_url_failed','نشانی امن پرداخت سفارش ووکامرس ساخته نشد.',array('status'=>500));
        }
        return array('order_id'=>$order_id,'payment_url'=>$payment_url);
    }

    private static function athlete_portal_verify_zarinpal($intent) {
        $settings=self::settings();$merchant=trim((string)($settings['portal_merchant_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9-]{36}$/',$merchant)) { return new WP_Error('wsm_athlete_gateway_unconfigured','مرچنت‌کد زرین‌پال در دسترس نیست.',array('status'=>503)); }
        $base=self::athlete_portal_zarinpal_base(!empty($settings['portal_sandbox']));
        $payload=array('merchant_id'=>$merchant,'amount'=>(int)($intent['gateway_amount_rial'] ?? 0),'authority'=>(string)($intent['authority'] ?? ''));
        $response=wp_remote_post($base.'/payment/verify.json',array('timeout'=>25,'redirection'=>0,'headers'=>array('Accept'=>'application/json','Content-Type'=>'application/json'),'body'=>wp_json_encode($payload)));
        if (is_wp_error($response)) { return new WP_Error('wsm_athlete_gateway_verify_network','استعلام تأیید پرداخت از زرین‌پال انجام نشد؛ دوباره تلاش کنید.',array('status'=>502)); }
        $http=(int)wp_remote_retrieve_response_code($response);$json=json_decode((string)wp_remote_retrieve_body($response),true);
        if ($http<200||$http>=300||!is_array($json)||!isset($json['data'])||!is_array($json['data'])) { return new WP_Error('wsm_athlete_gateway_verify_failed','پاسخ تأیید زرین‌پال معتبر نیست.',array('status'=>502)); }
        $data=$json['data'];$code=isset($data['code'])?(int)$data['code']:0;
        $ref=isset($data['ref_id'])&&is_scalar($data['ref_id'])?sanitize_text_field((string)$data['ref_id']):'';
        if (!in_array($code,array(100,101),true)) { return new WP_Error('wsm_athlete_gateway_payment_unverified','زرین‌پال پرداخت را تأیید نکرد؛ اگر مبلغ کسر شده با پشتیبانی باشگاه تماس بگیرید.',array('status'=>409,'gateway_code'=>$code)); }
        if ($ref==='') { $ref=(string)($intent['gateway_ref_id'] ?? ''); }
        if ($ref==='') { return new WP_Error('wsm_athlete_gateway_ref_missing','زرین‌پال پرداخت را تأیید کرد اما شناسهٔ پیگیری دریافت نشد.',array('status'=>502)); }
        return array('code'=>$code,'ref_id'=>$ref);
    }

    public static function athlete_portal_payment_verify_rest($request) {
        $intent_id='';$authority='';$status='';
        if (is_object($request)&&method_exists($request,'get_param')) {
            $intent_id=is_scalar($request->get_param('intent_id'))?(string)$request->get_param('intent_id'):(is_scalar($request->get_param('intent'))?(string)$request->get_param('intent'):'');
            $authority=is_scalar($request->get_param('authority'))?(string)$request->get_param('authority'):(is_scalar($request->get_param('Authority'))?(string)$request->get_param('Authority'):'');
            $status=is_scalar($request->get_param('status'))?(string)$request->get_param('status'):(is_scalar($request->get_param('Status'))?(string)$request->get_param('Status'):'');
        }
        return self::process_athlete_portal_payment($intent_id,$authority,$status);
    }

    private static function athlete_portal_mark_payment_review($intent,$error_code) {
        if (!is_array($intent)) { return; }
        $intent['status']='needs_manual_review';
        $intent['local_error']=sanitize_key((string)$error_code);
        self::athlete_portal_store_intent($intent);
    }

    private static function athlete_portal_process_woocommerce_order($order_id,$order=null) {
        $order_id=absint($order_id);
        if (!$order_id || !function_exists('wc_get_order')) { return new WP_Error('wsm_athlete_woocommerce_order_missing','سفارش پرداخت پیدا نشد.',array('status'=>404)); }
        if (!$order) { $order=wc_get_order($order_id); }
        if (!is_object($order) || !method_exists($order,'get_meta') || !method_exists($order,'get_id')) { return new WP_Error('wsm_athlete_woocommerce_order_missing','سفارش پرداخت پیدا نشد.',array('status'=>404)); }
        $intent_id=(string)$order->get_meta('_wsm_athlete_portal_intent_id',true);
        if ($intent_id==='') { return null; }
        if (!preg_match('/^[a-f0-9]{32}$/',$intent_id)) { return new WP_Error('wsm_athlete_intent_invalid','شناسهٔ درخواست پرداخت ووکامرس معتبر نیست.',array('status'=>409)); }
        $intents=self::athlete_portal_option_array(self::PORTAL_INTENTS_OPTION);
        if (empty($intents[$intent_id]) || !is_array($intents[$intent_id])) { return new WP_Error('wsm_athlete_intent_missing','درخواست پرداخت ووکامرس پیدا نشد.',array('status'=>404)); }
        $intent=$intents[$intent_id];
        if ((string)($intent['gateway_provider'] ?? '')!=='woocommerce') { self::athlete_portal_mark_payment_review($intent,'provider_mismatch');return new WP_Error('wsm_athlete_order_provider_mismatch','درگاه سفارش با درخواست پورتال مطابقت ندارد.',array('status'=>409)); }
        if (!method_exists($order,'is_paid') || !$order->is_paid()) { return new WP_Error('wsm_athlete_order_not_paid','ووکامرس هنوز پرداخت سفارش را تأیید نکرده است.',array('status'=>409)); }
        $expected_order_id=absint($intent['woocommerce_order_id'] ?? 0);
        $member_id=absint($intent['member_id'] ?? 0);
        $gateway_id=sanitize_key((string)($intent['gateway_id'] ?? ''));
        $actual_gateway=sanitize_key(method_exists($order,'get_payment_method')?(string)$order->get_payment_method():'');
        $expected_currency=strtoupper((string)($intent['gateway_currency'] ?? ''));
        $actual_currency=strtoupper(method_exists($order,'get_currency')?(string)$order->get_currency():'');
        $expected_amount=(float)($intent['gateway_order_amount'] ?? 0);
        $actual_amount=(float)(method_exists($order,'get_total')?$order->get_total():0);
        $meta_expected_amount=(float)$order->get_meta('_wsm_athlete_portal_expected_amount',true);
        $meta_currency=strtoupper((string)$order->get_meta('_wsm_athlete_portal_currency',true));
        $meta_member_id=absint($order->get_meta('_wsm_athlete_portal_member_id',true));
        $meta_gateway=sanitize_key((string)$order->get_meta('_wsm_athlete_portal_gateway_id',true));
        $meta_type=(string)$order->get_meta('_wsm_athlete_portal_intent_type',true);
        if ((int)$order->get_id()!==$order_id || $expected_order_id!==$order_id || $member_id<1 || $meta_member_id!==$member_id ||
            $gateway_id==='' || $actual_gateway!==$gateway_id || $meta_gateway!==$gateway_id ||
            $expected_currency==='' || $actual_currency!==$expected_currency || $meta_currency!==$expected_currency ||
            abs($expected_amount-$actual_amount)>0.000001 || abs($meta_expected_amount-$expected_amount)>0.000001 ||
            $meta_type!== (string)($intent['intent_type'] ?? '')) {
            self::athlete_portal_mark_payment_review($intent,'woocommerce_order_integrity_mismatch');
            return new WP_Error('wsm_athlete_order_integrity_mismatch','مبلغ، ارز، درگاه یا پروندهٔ سفارش با درخواست پورتال مطابقت ندارد؛ بررسی دستی لازم است.',array('status'=>409));
        }
        if ((string)($intent['status'] ?? '')==='completed' && isset($intent['result']) && is_array($intent['result'])) {
            return $intent['result']+array('idempotent_replay'=>true);
        }
        $reference=method_exists($order,'get_transaction_id')?sanitize_text_field((string)$order->get_transaction_id()):'';
        if ($reference==='') { $reference='WC-ORDER-'.$order_id; }
        $reference=function_exists('mb_substr')?mb_substr($reference,0,120,'UTF-8'):substr($reference,0,120);
        $authority=(string)($intent['authority'] ?? '');
        if ($authority==='') { $authority='WC-ORDER-'.$order_id; }
        $intent['authority']=$authority;
        $intent['gateway_verified']=true;
        $intent['gateway_ref_id']=$reference;
        $intent['gateway_verified_at']=current_time('mysql');
        $intent['status']='processing';
        self::athlete_portal_store_intent($intent);
        return self::process_athlete_portal_payment($intent_id,$authority,'OK');
    }

    public static function athlete_portal_woocommerce_order_paid($order_id,$order=null) {
        $processed=self::athlete_portal_process_woocommerce_order($order_id,$order);
        if (is_wp_error($processed) && class_exists('WSM_Security')) {
            WSM_Security::log('athlete_portal_woocommerce_payment_review','warning',absint($order_id),'پرداخت ووکامرس پورتال نیازمند بررسی دستی: '.$processed->get_error_code());
        }
        return $processed;
    }

    public static function athlete_portal_woocommerce_return_url($return_url,$order=null) {
        if (!is_object($order) || !method_exists($order,'get_meta') || !method_exists($order,'get_id') || !$order->get_meta('_wsm_athlete_portal_intent_id',true)) { return $return_url; }
        return add_query_arg(array('portal_order_id'=>absint($order->get_id())),home_url('/athlete-portal/'));
    }

    private static function athlete_portal_allocate_debt($summary,$amount,$intent_id) {
        $items=isset($summary['items'])&&is_array($summary['items'])?$summary['items']:array();
        usort($items,function($a,$b){$ra=($a['type']??'')==='membership'?0:1;$rb=($b['type']??'')==='membership'?0:1;return $ra<=>$rb ?: strcmp((string)($a['created_at']??''),(string)($b['created_at']??'')) ?: ((int)($a['id']??0)<=>(int)($b['id']??0));});
        $remaining=(float)$amount;$allocations=array();$decimals=min(6,max(0,(int)wc_get_price_decimals()));
        foreach ($items as $item) {
            if ($remaining<=0.000001) { break; }
            $due=max(0.0,(float)($item['balance_due'] ?? 0));
            if ($due<=0.000001) { continue; }
            $part=(float)wc_format_decimal(min($due,$remaining),$decimals);
            if ($part<=0.000001) { continue; }
            $allocation=array('type'=>(string)$item['type'],'id'=>(int)$item['id'],'amount'=>(string)wc_format_decimal($part,$decimals));
            if ($allocation['type']==='membership') {
                $uuid=self::athlete_portal_uuid_v4();
                if ($uuid==='') { return new WP_Error('wsm_athlete_uuid_failed','ساخت شناسهٔ امن تسویه انجام نشد.',array('status'=>500)); }
                $allocation['payment_uuid']=$uuid;
            } elseif ($allocation['type']==='pos') { $allocation['order_id']=(int)($item['order_id'] ?? $item['id']); }
            else { continue; }
            $allocations[]=$allocation;$remaining=max(0.0,$remaining-$part);
        }
        if ($remaining>0.00001) { return new WP_Error('wsm_athlete_debt_allocation_failed','تقسیم مبلغ پرداخت بین بدهی‌های فعلی انجام نشد.',array('status'=>409)); }
        return $allocations;
    }

    private static function apply_athlete_portal_pos_debt_payment($member_id,$intent_id,$allocations,$reference,$gateway_id='athlete_portal_zarinpal',$gateway_title='') {
        global $wpdb;
        if (!function_exists('wc_get_order')||!class_exists('WC_Order')) { return new WP_Error('wsm_club_woo_missing','ووکامرس فعال نیست.',array('status'=>503)); }
        $orders=array();$total=0.0;
        foreach ((array)$allocations as $allocation) {
            $order_id=absint($allocation['order_id'] ?? $allocation['id'] ?? 0);$amount=(float)($allocation['amount'] ?? 0);
            if (!$order_id||$amount<=0) { return new WP_Error('wsm_athlete_pos_allocation_invalid','تقسیم بدهی فروش حضوری معتبر نیست.',array('status'=>400)); }
            if (isset($orders[$order_id])) { return new WP_Error('wsm_athlete_pos_allocation_duplicate','فاکتور فروش حضوری دوبار در تقسیم بدهی آمده است.',array('status'=>409)); }
            $orders[$order_id]=array('amount'=>$amount);$total+=$amount;
        }
        if (!$orders||$total<=0) { return array('finance_id'=>0,'amount'=>'0','idempotent_replay'=>true); }
        $reference_id='athlete-portal-pos:'.$intent_id;
        $core=self::financial_core_ready();if(is_wp_error($core)){return $core;}
        if ($wpdb->query('START TRANSACTION')===false) { return new WP_Error('wsm_athlete_pos_transaction','آغاز تراکنش تسویهٔ خرید حضوری انجام نشد.',array('status'=>500)); }
        $member=$wpdb->get_row($wpdb->prepare('SELECT id,member_code,first_name,last_name FROM '.WSM_Club::table('members').' WHERE id=%d FOR UPDATE',absint($member_id)));
        if (!$member) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_member_missing','پروندهٔ ورزشکار پیدا نشد.',array('status'=>404)); }
        $wpdb->last_error='';
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id,member_id,amount,status FROM ".self::table('financial_ledger')." WHERE source='athlete_portal_debt_payment' AND reference_id=%s ORDER BY id DESC LIMIT 1 FOR UPDATE",$reference_id));
        if (!empty($wpdb->last_error)) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_pos_idempotency_lookup_failed','بررسی تکراری‌بودن تسویهٔ خرید حضوری انجام نشد.',array('status'=>500)); }
        if ($existing) {
            if ((int)$existing->member_id!==(int)$member_id || abs((float)$existing->amount-$total)>0.000001 || (string)$existing->status!=='posted') { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_pos_idempotency_conflict','شناسهٔ تسویهٔ خرید حضوری برای تراکنش دیگری استفاده شده است.',array('status'=>409)); }
            $wpdb->query('COMMIT');return array('finance_id'=>(int)$existing->id,'amount'=>(string)$existing->amount,'idempotent_replay'=>true);
        }
        $gateway_id=sanitize_key((string)$gateway_id);$gateway_title=$gateway_title!==''?(string)$gateway_title:($gateway_id==='athlete_portal_zarinpal'?'درگاه پورتال ورزشکار (زرین‌پال)':$gateway_id);
        $items=array();$labels=array();$full_order_ids=array();$account_id=self::gateway_clearing_account($gateway_id,$gateway_title);
        if (is_wp_error($account_id)||!(int)$account_id) { $wpdb->query('ROLLBACK');return is_wp_error($account_id)?$account_id:new WP_Error('wsm_athlete_gateway_account_missing','حساب واسط درگاه آنلاین در دسترس نیست.',array('status'=>409)); }
        foreach ($orders as $order_id=>$allocation) {
            $order=wc_get_order((int)$order_id);$amount=(float)$allocation['amount'];
            if (!$order instanceof WC_Order||(int)$order->get_meta('_wsm_club_member_id',true)!==(int)$member_id||!(bool)$order->get_meta('_wsm_club_pos_debt',true)) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_pos_order_invalid','یکی از فاکتورهای انتخاب‌شده متعلق به این ورزشکار یا فروش نسیه نیست.',array('status'=>409)); }
            if (!in_array((string)$order->get_status(),self::pos_debt_open_order_statuses(),true)) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_pos_order_closed','یکی از فاکتورهای انتخاب‌شده دیگر بدهی باز ندارد.',array('status'=>409)); }
            $due=self::pos_order_debt_due($order);
            if ($due<=0.000001||$amount>$due+0.000001) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_pos_balance_changed','ماندهٔ یکی از فاکتورهای حضوری تغییر کرده است؛ بدهی را تازه‌سازی کنید.',array('status'=>409)); }
            $wpdb->last_error='';
            $receivables=$wpdb->get_results($wpdb->prepare("SELECT id FROM ".self::table('financial_ledger')." WHERE order_id=%d AND kind='pos_receivable' AND status='posted' ORDER BY id ASC FOR UPDATE",(int)$order_id),ARRAY_A);
            if (!empty($wpdb->last_error)) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_pos_receivable_lookup_failed','بررسی دریافتنی فاکتور حضوری انجام نشد.',array('status'=>500)); }
            if (count((array)$receivables)!==1) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_pos_receivable_missing','برای فاکتور حضوری ردیف دریافتنی یکتا وجود ندارد؛ تسویه متوقف شد.',array('status'=>409)); }
            $old_paid=max(0.0,(float)$order->get_meta('_wsm_club_pos_debt_paid_amount',true));
            $new_paid=$old_paid+$amount;$gross=max(0.0,(float)$order->get_total()-(float)$order->get_total_refunded());$left=max(0.0,$gross-$new_paid);
            $order->update_meta_data('_wsm_club_pos_debt_paid_amount',(string)wc_format_decimal($new_paid));
            $order->update_meta_data('_wsm_club_pos_debt_remaining',(string)wc_format_decimal($left));
            $order->update_meta_data('_wsm_club_pos_debt_last_paid_at',current_time('mysql'));
            $order->update_meta_data('_wsm_club_pos_debt_settled',$left<=0.000001?1:0);
            if ($left<=0.000001) { $order->update_meta_data('_wsm_club_pos_debt_settled_at',current_time('mysql'));$full_order_ids[]=(int)$order_id; }
            $history=$order->get_meta('_wsm_club_pos_debt_payment_history',true);if(!is_array($history)){$history=array();}
            $history[]=array('amount'=>(string)wc_format_decimal($amount),'method'=>'online','reference'=>$reference,'note'=>'پرداخت پورتال ورزشکار','occurred_at'=>current_time('mysql'),'intent_id'=>$intent_id);
            $order->update_meta_data('_wsm_club_pos_debt_payment_history',array_slice($history,-100));
            if (method_exists($order,'add_order_note')) { $order->add_order_note('تسویهٔ آنلاین بدهی از پورتال ورزشکار: '.wc_format_decimal($amount).' · پیگیری '.$reference); }
            if (!$order->save()) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_pos_order_save_failed','به‌روزرسانی ماندهٔ فاکتور حضوری انجام نشد.',array('status'=>500)); }
            $status=$left<=0.000001?'settled':'partial';
            $updated=$wpdb->query($wpdb->prepare("UPDATE ".self::table('financial_ledger')." SET ledger_status=%s WHERE id=%d AND order_id=%d AND kind='pos_receivable' AND status='posted'",$status,(int)$receivables[0]['id'],(int)$order_id));
            if ($updated===false) { $wpdb->query('ROLLBACK');return new WP_Error('wsm_athlete_pos_ledger_update_failed','به‌روزرسانی دریافتنی فاکتور حضوری انجام نشد.',array('status'=>500)); }
            $items[]=array('order_id'=>(int)$order_id,'amount'=>(float)$amount,'balance_due'=>(float)$left);$labels[]='فاکتور #'.(string)$order->get_order_number().' · '.wc_format_decimal($amount);
        }
        $single_order=count($orders)===1?(int)array_key_first($orders):0;
        $finance_id=self::add_finance('member_payment',$total,'دریافت آنلاین بابت بدهی خرید حضوری '.($member->member_code?:trim($member->first_name.' '.$member->last_name)),array('member_id'=>(int)$member_id,'order_id'=>$single_order,'account_id'=>(int)$account_id,'category'=>'athlete_portal_debt_settlement','method'=>'online','source'=>'athlete_portal_debt_payment','transaction_type'=>'income','reference'=>$reference,'reference_id'=>$reference_id,'occurred_at'=>current_time('mysql'),'description'=>'تسویهٔ آنلاین پورتال: '.implode('؛ ',$labels)));
        if (is_wp_error($finance_id)) { $wpdb->query('ROLLBACK');return $finance_id; }
        $wpdb->query('COMMIT');
        foreach ($full_order_ids as $settled_id) {
            try { $order=wc_get_order($settled_id);if($order&&method_exists($order,'payment_complete')&&!$order->is_paid()){$order->payment_complete($reference);}if($order&&$order->get_status()==='on-hold'){$order->update_status('processing','بدهی فاکتور از پورتال ورزشکار تسویه شد.');} }
            catch (Throwable $ignored) {}
        }
        self::sync_receivables_projection();
        WSM_Activity_Log::log('create','athlete_portal_debt_payment',(int)$finance_id,'تسویهٔ آنلاین بدهی ورزشکار',array('member_id'=>(int)$member_id),array('amount'=>(string)$total,'order_ids'=>array_keys($orders),'intent_id'=>$intent_id),'success');
        return array('finance_id'=>(int)$finance_id,'amount'=>(string)wc_format_decimal($total),'allocations'=>$items,'settled_order_ids'=>$full_order_ids,'idempotent_replay'=>false);
    }

    private static function athlete_portal_send_sms($member,$type,$message) {
        $result=array('ok'=>false,'detail'=>'سرویس پیامک پیکربندی نشده است.','provider_ref'=>'');
        if (class_exists('WSM_SMS') && WSM_SMS::is_active()) { $result=WSM_SMS::dispatch_to((string)$message,(string)($member->phone ?? '')); }
        self::log_sms((int)($member->id ?? 0),(string)($member->phone ?? ''),(string)$type,(string)$message,$result);
        return $result;
    }

    public static function process_athlete_portal_payment($intent_id,$authority,$status_param) {
        $intent_id=is_scalar($intent_id)?sanitize_text_field((string)$intent_id):'';
        if (!preg_match('/^[a-f0-9]{32}$/',$intent_id)) { return new WP_Error('wsm_athlete_intent_invalid','شناسهٔ پرداخت معتبر نیست.',array('status'=>400)); }
        $lock=self::athlete_portal_acquire_lock('intent',$intent_id,180);
        if (is_wp_error($lock)) { return $lock; }
        try {
            $intents=self::athlete_portal_option_array(self::PORTAL_INTENTS_OPTION);
            if (empty($intents[$intent_id])||!is_array($intents[$intent_id])) { return new WP_Error('wsm_athlete_intent_missing','درخواست پرداخت پیدا نشد.',array('status'=>404)); }
            $intent=$intents[$intent_id];
            if ((string)($intent['status'] ?? '')==='completed' && isset($intent['result']) && is_array($intent['result'])) { return $intent['result']+array('idempotent_replay'=>true); }
            $authority=is_scalar($authority)?trim(sanitize_text_field((string)$authority)):'';
            $status=strtoupper(trim(is_scalar($status_param)?(string)$status_param:''));
            $free=!empty($intent['free']);
            if (!$free && ($authority===''||!hash_equals((string)($intent['authority'] ?? ''),$authority))) { return new WP_Error('wsm_athlete_authority_invalid','شناسهٔ مرجع پرداخت با درخواست ثبت‌شده مطابقت ندارد.',array('status'=>409)); }
            if (!$free && empty($intent['gateway_verified']) && $status!=='OK') {
                $intent['status']='cancelled';$intent['gateway_error']='customer_cancelled';self::athlete_portal_store_intent($intent);self::athlete_portal_release_coupon((string)($intent['coupon_code'] ?? ''),$intent_id);
                return new WP_Error('wsm_athlete_payment_cancelled','پرداخت توسط شما لغو شد یا ناموفق بود.',array('status'=>400));
            }
            if (!$free && empty($intent['gateway_verified'])) {
                $verification=self::athlete_portal_verify_zarinpal($intent);
                if (is_wp_error($verification)) {
                    $code=$verification->get_error_code();
                    if ($code==='wsm_athlete_gateway_payment_unverified') {
                        $intent['status']='failed';$intent['gateway_error']='verification_rejected';self::athlete_portal_store_intent($intent);self::athlete_portal_release_coupon((string)($intent['coupon_code'] ?? ''),$intent_id);
                    }
                    return $verification;
                }
                $intent['gateway_verified']=true;$intent['gateway_ref_id']=(string)$verification['ref_id'];$intent['gateway_verify_code']=(int)$verification['code'];
                $intent['gateway_verified_at']=current_time('mysql');$intent['status']='processing';self::athlete_portal_store_intent($intent);
            }
            $reference=(string)($intent['gateway_ref_id'] ?? '');
            if ($reference==='') { return new WP_Error('wsm_athlete_gateway_ref_missing','شناسهٔ پیگیری پرداخت در دسترس نیست.',array('status'=>502)); }
            $payment_gateway_id=sanitize_key((string)($intent['gateway_id'] ?? 'athlete_portal_zarinpal'));
            if ($payment_gateway_id==='') { $payment_gateway_id='athlete_portal_zarinpal'; }
            $payment_gateway_title=(string)($intent['gateway_title'] ?? ($payment_gateway_id==='athlete_portal_zarinpal'?'درگاه پورتال ورزشکار (زرین‌پال)':$payment_gateway_id));
            $member=self::athlete_portal_find_member_by_id((int)($intent['member_id'] ?? 0));
            if (is_wp_error($member)) { return $member; }
            if (!$member || (string)$member->status!=='active') { $intent['status']='needs_manual_review';$intent['local_error']='member_inactive_after_payment';self::athlete_portal_store_intent($intent);return new WP_Error('wsm_athlete_member_inactive_after_payment','پرداخت تأیید شد اما پروندهٔ ورزشکار غیرفعال است؛ با باشگاه تماس بگیرید.',array('status'=>409)); }
            if ((string)($intent['intent_type'] ?? '')==='membership') {
                $membership=null;
                if (!empty($intent['membership_id'])) {
                    global $wpdb;$wpdb->last_error='';
                    $membership_row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WSM_Club::table('memberships').' WHERE id=%d AND member_id=%d',(int)$intent['membership_id'],(int)$member->id));
                    if (!empty($wpdb->last_error)) { return new WP_Error('wsm_athlete_membership_lookup_failed','خواندن عضویت ثبت‌شده انجام نشد.',array('status'=>500)); }
                    if ($membership_row) { $membership=array('id'=>(int)$membership_row->id,'plan_id'=>(int)$membership_row->plan_id,'plan_name'=>(string)$membership_row->plan_name,'start_date'=>(string)$membership_row->start_date,'end_date'=>(string)$membership_row->end_date,'price'=>(string)$membership_row->price,'discount_amount'=>(string)$membership_row->discount_amount,'status'=>(string)$membership_row->status); }
                }
                if (!$membership) {
                    $created=WSM_Club::create_membership(array('member_id'=>(int)$member->id,'plan_id'=>(int)$intent['plan_id'],'membership_uuid'=>(string)$intent['membership_uuid'],'start_date'=>(string)$intent['start_date'],'discount_amount'=>(string)($intent['discount_amount'] ?? '0'),'discount_note'=>(string)($intent['discount_note'] ?? ''),'_had_unexpired_prior'=>!empty($intent['had_unexpired_prior']),'_prior_end_date'=>(string)($intent['prior_end_date'] ?? ''),'_portal_renewal'=>1));
                    if (is_wp_error($created)) { $intent['status']='needs_manual_review';$intent['local_error']=$created->get_error_code();self::athlete_portal_store_intent($intent);return $created; }
                    $membership=$created;$intent['membership_id']=(int)$created['id'];$intent['membership_result']=$created;$intent['status']='processing';self::athlete_portal_store_intent($intent);
                }
                $payment=null;
                if ((float)($intent['amount'] ?? 0)>0.000001) {
                    $payment=WSM_Club::record_payment(array('member_id'=>(int)$member->id,'membership_id'=>(int)$membership['id'],'payment_uuid'=>(string)$intent['payment_uuid'],'amount'=>(string)$intent['amount'],'method'=>'online','gateway_id'=>$payment_gateway_id,'reference'=>$reference,'note'=>'پرداخت آنلاین از پورتال ورزشکار'));
                    if (is_wp_error($payment)) { $intent['status']='needs_manual_review';$intent['local_error']=$payment->get_error_code();self::athlete_portal_store_intent($intent);return $payment; }
                    $intent['payment_id']=(int)$payment['id'];$intent['payment_result']=$payment;self::athlete_portal_store_intent($intent);
                }
                if ((string)($intent['coupon_code'] ?? '')!=='') {
                    $used=self::mark_athlete_portal_coupon_used((string)$intent['coupon_code'],(int)$member->id,$intent_id);
                    if (is_wp_error($used)) { $intent['status']='needs_manual_review';$intent['local_error']=$used->get_error_code();self::athlete_portal_store_intent($intent);return $used; }
                }
                $review=null;
                if (!empty($intent['needs_staff_review'])) {
                    $review=self::enqueue_portal_renewal_review($intent,$membership,$member);
                    if (is_wp_error($review)) { $intent['status']='needs_manual_review';$intent['local_error']=$review->get_error_code();self::athlete_portal_store_intent($intent);return $review; }
                }
                $sms_result=null;
                if (empty($intent['receipt_sms_logged'])) {
                    $message='پرداخت پورتال ورزشکار با موفقیت ثبت شد. مبلغ: '.wc_format_decimal((float)$intent['amount']).' · پیگیری: '.$reference;
                    $sms_result=self::athlete_portal_send_sms($member,'athlete_portal_payment',$message);
                    $intent['receipt_sms_logged']=true;$intent['receipt_sms_sent']=!empty($sms_result['ok']);
                }
                $result=array('status'=>'completed','intent_id'=>$intent_id,'intent_type'=>'membership','membership'=>$membership,'payment'=>$payment,'review'=>$review,'ref_id'=>$reference,'amount'=>(string)$intent['amount'],'discount_amount'=>(string)($intent['discount_amount'] ?? '0'),'sms_sent'=>!empty($intent['receipt_sms_sent']));
                $intent['status']='completed';$intent['completed_at']=current_time('mysql');$intent['result']=$result;self::athlete_portal_store_intent($intent);
                return $result;
            }
            if ((string)($intent['intent_type'] ?? '')==='debt') {
                if (empty($intent['debt_application_started'])) {
                    $summary=self::get_athlete_debt_summary((int)$member->id);
                    if (is_wp_error($summary)) { return $summary; }
                    if ((float)$summary['total_debt']+0.000001<(float)$intent['amount']) {
                        $intent['status']='needs_manual_review';$intent['local_error']='debt_balance_below_verified_payment';self::athlete_portal_store_intent($intent);
                        return new WP_Error('wsm_athlete_debt_balance_changed','پرداخت تأیید شد اما بدهی از زمان شروع پرداخت تغییر کرده است؛ با باشگاه تماس بگیرید.',array('status'=>409));
                    }
                    $allocations=self::athlete_portal_allocate_debt($summary,(float)$intent['amount'],$intent_id);
                    if (is_wp_error($allocations)) { $intent['status']='needs_manual_review';$intent['local_error']=$allocations->get_error_code();self::athlete_portal_store_intent($intent);return $allocations; }
                    $intent['debt_allocations']=$allocations;$intent['debt_application_started']=true;$intent['status']='processing';self::athlete_portal_store_intent($intent);
                }
                $allocations=is_array($intent['debt_allocations'] ?? null)?$intent['debt_allocations']:array();$membership_payments=array();$pos_allocations=array();
                foreach ($allocations as $allocation) {
                    if ((string)($allocation['type'] ?? '')==='membership') {
                        $payment=WSM_Club::record_payment(array('member_id'=>(int)$member->id,'membership_id'=>(int)$allocation['id'],'payment_uuid'=>(string)$allocation['payment_uuid'],'amount'=>(string)$allocation['amount'],'method'=>'online','gateway_id'=>$payment_gateway_id,'reference'=>$reference,'note'=>'تسویهٔ بدهی از پورتال ورزشکار'));
                        if (is_wp_error($payment)) { $intent['status']='needs_manual_review';$intent['local_error']=$payment->get_error_code();self::athlete_portal_store_intent($intent);return $payment; }
                        $membership_payments[]=$payment;
                    } elseif ((string)($allocation['type'] ?? '')==='pos') { $pos_allocations[]=$allocation; }
                }
                $pos_result=array('finance_id'=>0,'amount'=>'0','idempotent_replay'=>true);
                if ($pos_allocations) {
                    $pos_result=self::apply_athlete_portal_pos_debt_payment((int)$member->id,$intent_id,$pos_allocations,$reference,$payment_gateway_id,$payment_gateway_title);
                    if (is_wp_error($pos_result)) { $intent['status']='needs_manual_review';$intent['local_error']=$pos_result->get_error_code();self::athlete_portal_store_intent($intent);return $pos_result; }
                }
                $after=self::get_athlete_debt_summary((int)$member->id);
                if (is_wp_error($after)) { return $after; }
                $sms_result=null;
                if (empty($intent['receipt_sms_logged'])) {
                    $message='بخشی از بدهی شما با موفقیت تسویه شد. مبلغ: '.wc_format_decimal((float)$intent['amount']).' · مانده: '.wc_format_decimal((float)$after['total_debt']).' · پیگیری: '.$reference;
                    $sms_result=self::athlete_portal_send_sms($member,'athlete_portal_debt_receipt',$message);
                    $intent['receipt_sms_logged']=true;$intent['receipt_sms_sent']=!empty($sms_result['ok']);
                }
                $result=array('status'=>'completed','intent_id'=>$intent_id,'intent_type'=>'debt','amount'=>(string)$intent['amount'],'membership_payments'=>$membership_payments,'pos_payment'=>$pos_result,'debt'=>$after,'ref_id'=>$reference,'sms_sent'=>!empty($intent['receipt_sms_sent']));
                $intent['status']='completed';$intent['completed_at']=current_time('mysql');$intent['result']=$result;self::athlete_portal_store_intent($intent);
                return $result;
            }
            $intent['status']='needs_manual_review';$intent['local_error']='unknown_intent_type';self::athlete_portal_store_intent($intent);
            return new WP_Error('wsm_athlete_intent_type_invalid','نوع درخواست پرداخت معتبر نیست.',array('status'=>409));
        } finally { self::athlete_portal_release_lock($lock); }
    }

    public static function maybe_render_athlete_portal_page() {
        if (!function_exists('get_query_var') || !get_query_var('wsm_athlete_portal')) { return; }
        if (isset($_GET['portal_order_id'])) {
            $order_id=absint(wp_unslash($_GET['portal_order_id']));
            $processed=self::athlete_portal_process_woocommerce_order($order_id);
            $target=home_url('/athlete-portal/');
            if (is_wp_error($processed) || !is_array($processed)) {
                if (is_wp_error($processed)) { error_log('[WSM Athlete Portal] WooCommerce return processing failed: '.$processed->get_error_code()); }
                $target=add_query_arg(array('portal_payment'=>'failed'),$target);
            } else {
                $result=isset($processed['ref_id'])?(string)$processed['ref_id']:'';
                $target=add_query_arg(array('portal_payment'=>'success','portal_ref'=>$result),$target);
            }
            wp_safe_redirect($target,303);exit;
        }
        if (isset($_GET['wsm_athlete_payment']) && (string)wp_unslash($_GET['wsm_athlete_payment'])==='1') {
            $intent=isset($_GET['intent'])?sanitize_text_field(wp_unslash((string)$_GET['intent'])):'';
            $authority=isset($_GET['Authority'])?sanitize_text_field(wp_unslash((string)$_GET['Authority'])):'';
            $status=isset($_GET['Status'])?sanitize_text_field(wp_unslash((string)$_GET['Status'])):'';
            $processed=self::process_athlete_portal_payment($intent,$authority,$status);
            $target=home_url('/athlete-portal/');
            if (is_wp_error($processed)) {
                error_log('[WSM Athlete Portal] Callback processing failed: '.$processed->get_error_code());
                $target=add_query_arg(array('portal_payment'=>'failed'),$target);
            } else {
                $result=isset($processed['ref_id'])?(string)$processed['ref_id']:'';
                $target=add_query_arg(array('portal_payment'=>'success','portal_ref'=>$result),$target);
            }
            wp_safe_redirect($target,303);exit;
        }
        self::render_athlete_portal_page();
        exit;
    }

    private static function render_athlete_portal_page() {
        $settings=self::settings();
        $api_base=rtrim(esc_url_raw(rest_url('wsm/v1/athlete-portal')),'/');
        $club_name=get_bloginfo('name') ?: 'باشگاه ورزشی';
        $today=self::athlete_portal_today();
        $today_jalali=self::athlete_portal_format_jalali($today);
        $payment_status=isset($_GET['portal_payment'])&&is_scalar($_GET['portal_payment'])?sanitize_key(wp_unslash((string)$_GET['portal_payment'])):'';
        $payment_ref=isset($_GET['portal_ref'])&&is_scalar($_GET['portal_ref'])?sanitize_text_field(wp_unslash((string)$_GET['portal_ref'])):'';
        $boot_json=wp_json_encode(array(
            'apiBase'=>$api_base,
            'enabled'=>!empty($settings['portal_enabled']),
            'clubName'=>(string)$club_name,
            'termsText'=>(string)($settings['portal_terms_text'] ?? ''),
            'today'=>$today,
            'todayJalali'=>$today_jalali,
            'currency_symbol'=>function_exists('wsm_currency_symbol')?wsm_currency_symbol():'تومان',
            'callbackResult'=>null,
            'paymentReturn'=>array('status'=>$payment_status,'ref'=>$payment_ref),
        ),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
        if (!is_string($boot_json)) { $boot_json='{}'; }
        nocache_headers();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Robots-Tag: noindex, nofollow');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
        }
        $html=<<<'HTML'
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>پورتال ورزشکار</title>
<style>
:root{--bg:#0b1120;--card:#131c31;--card2:#1b2642;--line:#293556;--text:#f8fafc;--muted:#94a3b8;--accent:#10b981;--accent-hover:#059669;--warn:#f59e0b;--danger:#ef4444;--radius:16px}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(circle at top,#172554 0%,#0b1120 65%);color:var(--text);font-family:Tahoma,"Vazirmatn","Segoe UI",sans-serif;min-height:100dvh;line-height:1.65;padding:16px}
.portal-wrap{max-width:680px;margin:0 auto;padding-bottom:48px}
.portal-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;padding:14px 18px;background:rgba(19,28,49,.88);border:1px solid var(--line);border-radius:var(--radius);backdrop-filter:blur(10px)}
.portal-brand{display:flex;align-items:center;gap:12px}
.portal-logo{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#10b981,#0284c7);display:grid;place-items:center;font-weight:700;font-size:20px;color:#fff}
.portal-title h1{margin:0;font-size:17px}
.portal-title small{color:var(--muted);font-size:12px}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:18px;margin-bottom:16px;box-shadow:0 12px 30px rgba(0,0,0,.28)}
.card h2,.card h3{margin:0 0 10px;font-size:16px}
.muted{color:var(--muted);font-size:13px}
.label{display:block;font-size:13px;margin:10px 0 5px;color:#cbd5e1;font-weight:700}
.input{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:#0b1325;color:var(--text);font:inherit;font-size:14px}
.input:focus{outline:none;border-color:var(--accent)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 16px;border-radius:12px;border:1px solid var(--line);background:var(--card2);color:var(--text);font:inherit;font-size:14px;font-weight:700;cursor:pointer;transition:.15s}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#042f2e}
.btn.primary:hover{background:var(--accent-hover);color:#fff}
.btn.ghost{background:transparent}
.btn.full{width:100%;margin-top:12px}
.btn:disabled{opacity:.5;cursor:not-allowed}
.profile-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:10px}
.profile-item{background:#0b1325;border:1px solid var(--line);border-radius:12px;padding:10px 12px}
.profile-item small{display:block;color:var(--muted);font-size:11px}
.profile-item strong{font-size:14px}
.status-banner{border-radius:14px;padding:16px;margin-top:8px;border:1px solid var(--line);display:flex;flex-direction:column;gap:8px}
.status-banner.green{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.45)}
.status-banner.yellow{background:rgba(245,158,11,.14);border-color:rgba(245,158,11,.5)}
.status-banner.red{background:rgba(239,68,68,.14);border-color:rgba(239,68,68,.5)}
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700;width:fit-content}
.status-pill.green{background:#10b981;color:#042f2e}
.status-pill.yellow{background:#f59e0b;color:#451a03}
.status-pill.red{background:#ef4444;color:#fff}
.plans-grid{display:grid;grid-template-columns:1fr;gap:12px;margin-top:12px}
@media(min-width:520px){.plans-grid{grid-template-columns:repeat(2,1fr)}}
.plan-card{background:#0b1325;border:1px solid var(--line);border-radius:14px;padding:14px;display:flex;flex-direction:column;justify-content:space-between;gap:10px}
.plan-price{font-size:18px;font-weight:700;color:#34d399}
.modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.78);display:flex;align-items:center;justify-content:center;padding:16px;z-index:1000;backdrop-filter:blur(4px)}
.modal-box{background:var(--card);border:1px solid var(--line);border-radius:18px;max-width:500px;width:100%;max-height:92dvh;overflow-y:auto;padding:20px}
.radio-option{display:flex;align-items:flex-start;gap:10px;padding:11px 12px;border:1px solid var(--line);border-radius:12px;margin-bottom:8px;cursor:pointer;background:#0b1325}
.radio-option.active{border-color:var(--accent);background:rgba(16,185,129,.1)}
.jcal-box{background:#0b1325;border:1px solid var(--line);border-radius:12px;padding:12px;margin-top:8px}
.jcal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;font-weight:700}
.jcal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;text-align:center}
.jcal-wd{font-size:11px;color:var(--muted);padding:4px 0}
.jcal-day{padding:7px 0;border-radius:8px;border:1px solid transparent;background:#131c31;color:var(--text);cursor:pointer;font-size:13px}
.jcal-day:hover:not(:disabled){border-color:var(--accent)}
.jcal-day.selected{background:var(--accent);color:#042f2e;font-weight:700}
.jcal-day:disabled{opacity:.3;cursor:not-allowed}
.alert{padding:12px 14px;border-radius:12px;margin-bottom:12px;font-size:13px}
.alert.err{background:rgba(239,68,68,.16);border:1px solid rgba(239,68,68,.45);color:#fecaca}
.alert.ok{background:rgba(16,185,129,.16);border:1px solid rgba(16,185,129,.45);color:#a7f3d0}
.terms-box{max-height:110px;overflow-y:auto;background:#0b1325;border:1px solid var(--line);border-radius:10px;padding:10px;font-size:12px;color:#cbd5e1;white-space:pre-line;margin:10px 0}

/* Athlete Hub — existing modal and checkout classes deliberately preserved. */
:root{--bg:#080d1c;--card:rgba(24,34,58,.72);--card2:#202d4a;--line:rgba(165,193,224,.19);--accent:#60e2bf;--accent-hover:#20b999}
body{background:radial-gradient(ellipse 90% 52% at 91% -8%,rgba(71,107,211,.27),transparent),radial-gradient(ellipse 80% 44% at -15% 60%,rgba(19,176,146,.13),transparent),#080d1c;padding:18px 18px 90px}
.portal-wrap{max-width:1000px;padding-bottom:100px}
.card,.portal-header{background:rgba(24,34,58,.69);border:1px solid var(--line);box-shadow:0 16px 55px rgba(2,7,24,.24);backdrop-filter:blur(16px)}
.portal-header{border-radius:23px;margin-bottom:23px}.portal-logo{border-radius:16px;background:linear-gradient(135deg,#66e7c1,#598bef);box-shadow:0 6px 24px rgba(72,211,188,.3)}
.hub-title{font-size:26px;margin:4px 0 18px;letter-spacing:-.4px}.hub-title small{display:block;font-size:12px;font-weight:400;color:var(--muted);letter-spacing:0}
.hub-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px}.hub-grid>.card{grid-column:span 6;margin:0;min-width:0}
.hub-grid>.hub-wide{grid-column:span 12}.hub-hero{background:linear-gradient(135deg,rgba(22,72,79,.82),rgba(32,49,96,.8))}
.hub-kicker{font-size:11px;font-weight:800;letter-spacing:.7px;color:#9fc1d2}.hub-name{font-size:clamp(23px,4vw,36px);margin:12px 0 7px;font-weight:900}
.hub-stat{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid var(--line);font-size:13px}.hub-stat:last-child{border:0}
.hub-qr{display:flex;align-items:center;justify-content:space-between;gap:14px}.hub-qr svg{background:#fff;border:8px solid #fff;border-radius:13px;max-width:134px;max-height:134px;flex-shrink:0}
.hub-training{display:block;text-decoration:none;color:inherit}.hub-training b{color:#8ce7c8}.hub-training.disabled{cursor:default;opacity:.7}
.hub-nav{position:fixed;z-index:900;bottom:calc(12px + env(safe-area-inset-bottom));left:50%;transform:translateX(-50%);width:min(620px,calc(100% - 22px));display:grid;grid-template-columns:repeat(4,1fr);padding:7px;background:rgba(18,29,50,.94);backdrop-filter:blur(22px);border:1px solid rgba(155,194,221,.29);border-radius:22px;box-shadow:0 18px 44px rgba(0,0,0,.5)}
.hub-nav button{border:0;background:transparent;color:#a9bacd;border-radius:15px;padding:7px 3px;font:inherit;font-size:12px;font-weight:700;cursor:pointer;line-height:1.45}.hub-nav button b{display:block;font-size:18px}.hub-nav button.active{background:rgba(94,227,188,.15);color:#8bf1cd}
.hub-list{display:grid;gap:10px}.hub-row{background:rgba(9,18,35,.5);padding:12px;border:1px solid var(--line);border-radius:13px;display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:13px}.hub-row small{display:block;color:var(--muted)}
.hub-chat{height:min(55dvh,420px);overflow:auto;display:flex;flex-direction:column;gap:10px;padding:13px;background:rgba(6,13,27,.55);border:1px solid var(--line);border-radius:15px;margin:12px 0}
.hub-bubble{padding:11px 13px;border-radius:14px;max-width:82%;white-space:pre-wrap;overflow-wrap:anywhere;background:#293957;align-self:flex-start;font-size:13px}.hub-bubble.mine{background:rgba(46,183,143,.24);border:1px solid rgba(94,227,188,.2);align-self:flex-end}.hub-bubble small{display:block;margin-top:5px;color:#b2c5d3;font-size:10px}
.hub-chat-form{display:flex;gap:8px}.hub-chat-form textarea{min-height:56px;resize:vertical}.hub-chat-form button{align-self:stretch}
.hub-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}
@media(max-width:650px){body{padding:10px 10px 80px}.hub-grid>.card{grid-column:span 12}.hub-title{padding:0 4px}.portal-header{padding:12px}.hub-qr{gap:8px}.hub-qr svg{max-width:112px;max-height:112px}}
@media print{body{background:white;color:#111;padding:0}.portal-header,.hub-nav,.hub-grid,#portal-alert-ok,#portal-alert-err,.modal-box .btn{display:none!important}.modal-backdrop{position:static;background:white;padding:0}.modal-box{box-shadow:none;border:0;color:#111;max-height:none}}
</style>
</head>
<body>
<div class="portal-wrap" id="portal-app"></div>
<script>/* qrcode-generator 1.4.4, Kazuhiko Arase, MIT License — bundled offline for gate QR. */
/**
 * Minified by jsDelivr using Terser v5.37.0.
 * Original file: /npm/qrcode-generator@1.4.4/qrcode.js
 *
 * Do NOT use SRI with dynamically generated files! More information: https://www.jsdelivr.com/using-sri-with-dynamic-files
 */
var qrcode=function(){var t=function(t,r){var e=t,n=g[r],o=null,i=0,a=null,u=[],f={},c=function(t,r){o=function(t){for(var r=new Array(t),e=0;e<t;e+=1){r[e]=new Array(t);for(var n=0;n<t;n+=1)r[e][n]=null}return r}(i=4*e+17),l(0,0),l(i-7,0),l(0,i-7),s(),h(),d(t,r),e>=7&&v(t),null==a&&(a=p(e,n,u)),w(a,r)},l=function(t,r){for(var e=-1;e<=7;e+=1)if(!(t+e<=-1||i<=t+e))for(var n=-1;n<=7;n+=1)r+n<=-1||i<=r+n||(o[t+e][r+n]=0<=e&&e<=6&&(0==n||6==n)||0<=n&&n<=6&&(0==e||6==e)||2<=e&&e<=4&&2<=n&&n<=4)},h=function(){for(var t=8;t<i-8;t+=1)null==o[t][6]&&(o[t][6]=t%2==0);for(var r=8;r<i-8;r+=1)null==o[6][r]&&(o[6][r]=r%2==0)},s=function(){for(var t=B.getPatternPosition(e),r=0;r<t.length;r+=1)for(var n=0;n<t.length;n+=1){var i=t[r],a=t[n];if(null==o[i][a])for(var u=-2;u<=2;u+=1)for(var f=-2;f<=2;f+=1)o[i+u][a+f]=-2==u||2==u||-2==f||2==f||0==u&&0==f}},v=function(t){for(var r=B.getBCHTypeNumber(e),n=0;n<18;n+=1){var a=!t&&1==(r>>n&1);o[Math.floor(n/3)][n%3+i-8-3]=a}for(n=0;n<18;n+=1){a=!t&&1==(r>>n&1);o[n%3+i-8-3][Math.floor(n/3)]=a}},d=function(t,r){for(var e=n<<3|r,a=B.getBCHTypeInfo(e),u=0;u<15;u+=1){var f=!t&&1==(a>>u&1);u<6?o[u][8]=f:u<8?o[u+1][8]=f:o[i-15+u][8]=f}for(u=0;u<15;u+=1){f=!t&&1==(a>>u&1);u<8?o[8][i-u-1]=f:u<9?o[8][15-u-1+1]=f:o[8][15-u-1]=f}o[i-8][8]=!t},w=function(t,r){for(var e=-1,n=i-1,a=7,u=0,f=B.getMaskFunction(r),c=i-1;c>0;c-=2)for(6==c&&(c-=1);;){for(var g=0;g<2;g+=1)if(null==o[n][c-g]){var l=!1;u<t.length&&(l=1==(t[u]>>>a&1)),f(n,c-g)&&(l=!l),o[n][c-g]=l,-1==(a-=1)&&(u+=1,a=7)}if((n+=e)<0||i<=n){n-=e,e=-e;break}}},p=function(t,r,e){for(var n=A.getRSBlocks(t,r),o=b(),i=0;i<e.length;i+=1){var a=e[i];o.put(a.getMode(),4),o.put(a.getLength(),B.getLengthInBits(a.getMode(),t)),a.write(o)}var u=0;for(i=0;i<n.length;i+=1)u+=n[i].dataCount;if(o.getLengthInBits()>8*u)throw"code length overflow. ("+o.getLengthInBits()+">"+8*u+")";for(o.getLengthInBits()+4<=8*u&&o.put(0,4);o.getLengthInBits()%8!=0;)o.putBit(!1);for(;!(o.getLengthInBits()>=8*u||(o.put(236,8),o.getLengthInBits()>=8*u));)o.put(17,8);return function(t,r){for(var e=0,n=0,o=0,i=new Array(r.length),a=new Array(r.length),u=0;u<r.length;u+=1){var f=r[u].dataCount,c=r[u].totalCount-f;n=Math.max(n,f),o=Math.max(o,c),i[u]=new Array(f);for(var g=0;g<i[u].length;g+=1)i[u][g]=255&t.getBuffer()[g+e];e+=f;var l=B.getErrorCorrectPolynomial(c),h=k(i[u],l.getLength()-1).mod(l);for(a[u]=new Array(l.getLength()-1),g=0;g<a[u].length;g+=1){var s=g+h.getLength()-a[u].length;a[u][g]=s>=0?h.getAt(s):0}}var v=0;for(g=0;g<r.length;g+=1)v+=r[g].totalCount;var d=new Array(v),w=0;for(g=0;g<n;g+=1)for(u=0;u<r.length;u+=1)g<i[u].length&&(d[w]=i[u][g],w+=1);for(g=0;g<o;g+=1)for(u=0;u<r.length;u+=1)g<a[u].length&&(d[w]=a[u][g],w+=1);return d}(o,n)};f.addData=function(t,r){var e=null;switch(r=r||"Byte"){case"Numeric":e=M(t);break;case"Alphanumeric":e=x(t);break;case"Byte":e=m(t);break;case"Kanji":e=L(t);break;default:throw"mode:"+r}u.push(e),a=null},f.isDark=function(t,r){if(t<0||i<=t||r<0||i<=r)throw t+","+r;return o[t][r]},f.getModuleCount=function(){return i},f.make=function(){if(e<1){for(var t=1;t<40;t++){for(var r=A.getRSBlocks(t,n),o=b(),i=0;i<u.length;i++){var a=u[i];o.put(a.getMode(),4),o.put(a.getLength(),B.getLengthInBits(a.getMode(),t)),a.write(o)}var g=0;for(i=0;i<r.length;i++)g+=r[i].dataCount;if(o.getLengthInBits()<=8*g)break}e=t}c(!1,function(){for(var t=0,r=0,e=0;e<8;e+=1){c(!0,e);var n=B.getLostPoint(f);(0==e||t>n)&&(t=n,r=e)}return r}())},f.createTableTag=function(t,r){t=t||2;var e="";e+='<table style="',e+=" border-width: 0px; border-style: none;",e+=" border-collapse: collapse;",e+=" padding: 0px; margin: "+(r=void 0===r?4*t:r)+"px;",e+='">',e+="<tbody>";for(var n=0;n<f.getModuleCount();n+=1){e+="<tr>";for(var o=0;o<f.getModuleCount();o+=1)e+='<td style="',e+=" border-width: 0px; border-style: none;",e+=" border-collapse: collapse;",e+=" padding: 0px; margin: 0px;",e+=" width: "+t+"px;",e+=" height: "+t+"px;",e+=" background-color: ",e+=f.isDark(n,o)?"#000000":"#ffffff",e+=";",e+='"/>';e+="</tr>"}return e+="</tbody>",e+="</table>"},f.createSvgTag=function(t,r,e,n){var o={};"object"==typeof arguments[0]&&(t=(o=arguments[0]).cellSize,r=o.margin,e=o.alt,n=o.title),t=t||2,r=void 0===r?4*t:r,(e="string"==typeof e?{text:e}:e||{}).text=e.text||null,e.id=e.text?e.id||"qrcode-description":null,(n="string"==typeof n?{text:n}:n||{}).text=n.text||null,n.id=n.text?n.id||"qrcode-title":null;var i,a,u,c,g=f.getModuleCount()*t+2*r,l="";for(c="l"+t+",0 0,"+t+" -"+t+",0 0,-"+t+"z ",l+='<svg version="1.1" xmlns="http://www.w3.org/2000/svg"',l+=o.scalable?"":' width="'+g+'px" height="'+g+'px"',l+=' viewBox="0 0 '+g+" "+g+'" ',l+=' preserveAspectRatio="xMinYMin meet"',l+=n.text||e.text?' role="img" aria-labelledby="'+y([n.id,e.id].join(" ").trim())+'"':"",l+=">",l+=n.text?'<title id="'+y(n.id)+'">'+y(n.text)+"</title>":"",l+=e.text?'<description id="'+y(e.id)+'">'+y(e.text)+"</description>":"",l+='<rect width="100%" height="100%" fill="white" cx="0" cy="0"/>',l+='<path d="',a=0;a<f.getModuleCount();a+=1)for(u=a*t+r,i=0;i<f.getModuleCount();i+=1)f.isDark(a,i)&&(l+="M"+(i*t+r)+","+u+c);return l+='" stroke="transparent" fill="black"/>',l+="</svg>"},f.createDataURL=function(t,r){t=t||2,r=void 0===r?4*t:r;var e=f.getModuleCount()*t+2*r,n=r,o=e-r;return I(e,e,(function(r,e){if(n<=r&&r<o&&n<=e&&e<o){var i=Math.floor((r-n)/t),a=Math.floor((e-n)/t);return f.isDark(a,i)?0:1}return 1}))},f.createImgTag=function(t,r,e){t=t||2,r=void 0===r?4*t:r;var n=f.getModuleCount()*t+2*r,o="";return o+="<img",o+=' src="',o+=f.createDataURL(t,r),o+='"',o+=' width="',o+=n,o+='"',o+=' height="',o+=n,o+='"',e&&(o+=' alt="',o+=y(e),o+='"'),o+="/>"};var y=function(t){for(var r="",e=0;e<t.length;e+=1){var n=t.charAt(e);switch(n){case"<":r+="&lt;";break;case">":r+="&gt;";break;case"&":r+="&amp;";break;case'"':r+="&quot;";break;default:r+=n}}return r};return f.createASCII=function(t,r){if((t=t||1)<2)return function(t){t=void 0===t?2:t;var r,e,n,o,i,a=1*f.getModuleCount()+2*t,u=t,c=a-t,g={"██":"█","█ ":"▀"," █":"▄","  ":" "},l={"██":"▀","█ ":"▀"," █":" ","  ":" "},h="";for(r=0;r<a;r+=2){for(n=Math.floor((r-u)/1),o=Math.floor((r+1-u)/1),e=0;e<a;e+=1)i="█",u<=e&&e<c&&u<=r&&r<c&&f.isDark(n,Math.floor((e-u)/1))&&(i=" "),u<=e&&e<c&&u<=r+1&&r+1<c&&f.isDark(o,Math.floor((e-u)/1))?i+=" ":i+="█",h+=t<1&&r+1>=c?l[i]:g[i];h+="\n"}return a%2&&t>0?h.substring(0,h.length-a-1)+Array(a+1).join("▀"):h.substring(0,h.length-1)}(r);t-=1,r=void 0===r?2*t:r;var e,n,o,i,a=f.getModuleCount()*t+2*r,u=r,c=a-r,g=Array(t+1).join("██"),l=Array(t+1).join("  "),h="",s="";for(e=0;e<a;e+=1){for(o=Math.floor((e-u)/t),s="",n=0;n<a;n+=1)i=1,u<=n&&n<c&&u<=e&&e<c&&f.isDark(o,Math.floor((n-u)/t))&&(i=0),s+=i?g:l;for(o=0;o<t;o+=1)h+=s+"\n"}return h.substring(0,h.length-1)},f.renderTo2dContext=function(t,r){r=r||2;for(var e=f.getModuleCount(),n=0;n<e;n++)for(var o=0;o<e;o++)t.fillStyle=f.isDark(n,o)?"black":"white",t.fillRect(n*r,o*r,r,r)},f};t.stringToBytes=(t.stringToBytesFuncs={default:function(t){for(var r=[],e=0;e<t.length;e+=1){var n=t.charCodeAt(e);r.push(255&n)}return r}}).default,t.createStringToBytes=function(t,r){var e=function(){for(var e=S(t),n=function(){var t=e.read();if(-1==t)throw"eof";return t},o=0,i={};;){var a=e.read();if(-1==a)break;var u=n(),f=n()<<8|n();i[String.fromCharCode(a<<8|u)]=f,o+=1}if(o!=r)throw o+" != "+r;return i}(),n="?".charCodeAt(0);return function(t){for(var r=[],o=0;o<t.length;o+=1){var i=t.charCodeAt(o);if(i<128)r.push(i);else{var a=e[t.charAt(o)];"number"==typeof a?(255&a)==a?r.push(a):(r.push(a>>>8),r.push(255&a)):r.push(n)}}return r}};var r,e,n,o,i,a=1,u=2,f=4,c=8,g={L:1,M:0,Q:3,H:2},l=0,h=1,s=2,v=3,d=4,w=5,p=6,y=7,B=(r=[[],[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50],[6,30,54],[6,32,58],[6,34,62],[6,26,46,66],[6,26,48,70],[6,26,50,74],[6,30,54,78],[6,30,56,82],[6,30,58,86],[6,34,62,90],[6,28,50,72,94],[6,26,50,74,98],[6,30,54,78,102],[6,28,54,80,106],[6,32,58,84,110],[6,30,58,86,114],[6,34,62,90,118],[6,26,50,74,98,122],[6,30,54,78,102,126],[6,26,52,78,104,130],[6,30,56,82,108,134],[6,34,60,86,112,138],[6,30,58,86,114,142],[6,34,62,90,118,146],[6,30,54,78,102,126,150],[6,24,50,76,102,128,154],[6,28,54,80,106,132,158],[6,32,58,84,110,136,162],[6,26,54,82,110,138,166],[6,30,58,86,114,142,170]],e=1335,n=7973,i=function(t){for(var r=0;0!=t;)r+=1,t>>>=1;return r},(o={}).getBCHTypeInfo=function(t){for(var r=t<<10;i(r)-i(e)>=0;)r^=e<<i(r)-i(e);return 21522^(t<<10|r)},o.getBCHTypeNumber=function(t){for(var r=t<<12;i(r)-i(n)>=0;)r^=n<<i(r)-i(n);return t<<12|r},o.getPatternPosition=function(t){return r[t-1]},o.getMaskFunction=function(t){switch(t){case l:return function(t,r){return(t+r)%2==0};case h:return function(t,r){return t%2==0};case s:return function(t,r){return r%3==0};case v:return function(t,r){return(t+r)%3==0};case d:return function(t,r){return(Math.floor(t/2)+Math.floor(r/3))%2==0};case w:return function(t,r){return t*r%2+t*r%3==0};case p:return function(t,r){return(t*r%2+t*r%3)%2==0};case y:return function(t,r){return(t*r%3+(t+r)%2)%2==0};default:throw"bad maskPattern:"+t}},o.getErrorCorrectPolynomial=function(t){for(var r=k([1],0),e=0;e<t;e+=1)r=r.multiply(k([1,C.gexp(e)],0));return r},o.getLengthInBits=function(t,r){if(1<=r&&r<10)switch(t){case a:return 10;case u:return 9;case f:case c:return 8;default:throw"mode:"+t}else if(r<27)switch(t){case a:return 12;case u:return 11;case f:return 16;case c:return 10;default:throw"mode:"+t}else{if(!(r<41))throw"type:"+r;switch(t){case a:return 14;case u:return 13;case f:return 16;case c:return 12;default:throw"mode:"+t}}},o.getLostPoint=function(t){for(var r=t.getModuleCount(),e=0,n=0;n<r;n+=1)for(var o=0;o<r;o+=1){for(var i=0,a=t.isDark(n,o),u=-1;u<=1;u+=1)if(!(n+u<0||r<=n+u))for(var f=-1;f<=1;f+=1)o+f<0||r<=o+f||0==u&&0==f||a==t.isDark(n+u,o+f)&&(i+=1);i>5&&(e+=3+i-5)}for(n=0;n<r-1;n+=1)for(o=0;o<r-1;o+=1){var c=0;t.isDark(n,o)&&(c+=1),t.isDark(n+1,o)&&(c+=1),t.isDark(n,o+1)&&(c+=1),t.isDark(n+1,o+1)&&(c+=1),0!=c&&4!=c||(e+=3)}for(n=0;n<r;n+=1)for(o=0;o<r-6;o+=1)t.isDark(n,o)&&!t.isDark(n,o+1)&&t.isDark(n,o+2)&&t.isDark(n,o+3)&&t.isDark(n,o+4)&&!t.isDark(n,o+5)&&t.isDark(n,o+6)&&(e+=40);for(o=0;o<r;o+=1)for(n=0;n<r-6;n+=1)t.isDark(n,o)&&!t.isDark(n+1,o)&&t.isDark(n+2,o)&&t.isDark(n+3,o)&&t.isDark(n+4,o)&&!t.isDark(n+5,o)&&t.isDark(n+6,o)&&(e+=40);var g=0;for(o=0;o<r;o+=1)for(n=0;n<r;n+=1)t.isDark(n,o)&&(g+=1);return e+=Math.abs(100*g/r/r-50)/5*10},o),C=function(){for(var t=new Array(256),r=new Array(256),e=0;e<8;e+=1)t[e]=1<<e;for(e=8;e<256;e+=1)t[e]=t[e-4]^t[e-5]^t[e-6]^t[e-8];for(e=0;e<255;e+=1)r[t[e]]=e;var n={glog:function(t){if(t<1)throw"glog("+t+")";return r[t]},gexp:function(r){for(;r<0;)r+=255;for(;r>=256;)r-=255;return t[r]}};return n}();function k(t,r){if(void 0===t.length)throw t.length+"/"+r;var e=function(){for(var e=0;e<t.length&&0==t[e];)e+=1;for(var n=new Array(t.length-e+r),o=0;o<t.length-e;o+=1)n[o]=t[o+e];return n}(),n={getAt:function(t){return e[t]},getLength:function(){return e.length},multiply:function(t){for(var r=new Array(n.getLength()+t.getLength()-1),e=0;e<n.getLength();e+=1)for(var o=0;o<t.getLength();o+=1)r[e+o]^=C.gexp(C.glog(n.getAt(e))+C.glog(t.getAt(o)));return k(r,0)},mod:function(t){if(n.getLength()-t.getLength()<0)return n;for(var r=C.glog(n.getAt(0))-C.glog(t.getAt(0)),e=new Array(n.getLength()),o=0;o<n.getLength();o+=1)e[o]=n.getAt(o);for(o=0;o<t.getLength();o+=1)e[o]^=C.gexp(C.glog(t.getAt(o))+r);return k(e,0).mod(t)}};return n}var A=function(){var t=[[1,26,19],[1,26,16],[1,26,13],[1,26,9],[1,44,34],[1,44,28],[1,44,22],[1,44,16],[1,70,55],[1,70,44],[2,35,17],[2,35,13],[1,100,80],[2,50,32],[2,50,24],[4,25,9],[1,134,108],[2,67,43],[2,33,15,2,34,16],[2,33,11,2,34,12],[2,86,68],[4,43,27],[4,43,19],[4,43,15],[2,98,78],[4,49,31],[2,32,14,4,33,15],[4,39,13,1,40,14],[2,121,97],[2,60,38,2,61,39],[4,40,18,2,41,19],[4,40,14,2,41,15],[2,146,116],[3,58,36,2,59,37],[4,36,16,4,37,17],[4,36,12,4,37,13],[2,86,68,2,87,69],[4,69,43,1,70,44],[6,43,19,2,44,20],[6,43,15,2,44,16],[4,101,81],[1,80,50,4,81,51],[4,50,22,4,51,23],[3,36,12,8,37,13],[2,116,92,2,117,93],[6,58,36,2,59,37],[4,46,20,6,47,21],[7,42,14,4,43,15],[4,133,107],[8,59,37,1,60,38],[8,44,20,4,45,21],[12,33,11,4,34,12],[3,145,115,1,146,116],[4,64,40,5,65,41],[11,36,16,5,37,17],[11,36,12,5,37,13],[5,109,87,1,110,88],[5,65,41,5,66,42],[5,54,24,7,55,25],[11,36,12,7,37,13],[5,122,98,1,123,99],[7,73,45,3,74,46],[15,43,19,2,44,20],[3,45,15,13,46,16],[1,135,107,5,136,108],[10,74,46,1,75,47],[1,50,22,15,51,23],[2,42,14,17,43,15],[5,150,120,1,151,121],[9,69,43,4,70,44],[17,50,22,1,51,23],[2,42,14,19,43,15],[3,141,113,4,142,114],[3,70,44,11,71,45],[17,47,21,4,48,22],[9,39,13,16,40,14],[3,135,107,5,136,108],[3,67,41,13,68,42],[15,54,24,5,55,25],[15,43,15,10,44,16],[4,144,116,4,145,117],[17,68,42],[17,50,22,6,51,23],[19,46,16,6,47,17],[2,139,111,7,140,112],[17,74,46],[7,54,24,16,55,25],[34,37,13],[4,151,121,5,152,122],[4,75,47,14,76,48],[11,54,24,14,55,25],[16,45,15,14,46,16],[6,147,117,4,148,118],[6,73,45,14,74,46],[11,54,24,16,55,25],[30,46,16,2,47,17],[8,132,106,4,133,107],[8,75,47,13,76,48],[7,54,24,22,55,25],[22,45,15,13,46,16],[10,142,114,2,143,115],[19,74,46,4,75,47],[28,50,22,6,51,23],[33,46,16,4,47,17],[8,152,122,4,153,123],[22,73,45,3,74,46],[8,53,23,26,54,24],[12,45,15,28,46,16],[3,147,117,10,148,118],[3,73,45,23,74,46],[4,54,24,31,55,25],[11,45,15,31,46,16],[7,146,116,7,147,117],[21,73,45,7,74,46],[1,53,23,37,54,24],[19,45,15,26,46,16],[5,145,115,10,146,116],[19,75,47,10,76,48],[15,54,24,25,55,25],[23,45,15,25,46,16],[13,145,115,3,146,116],[2,74,46,29,75,47],[42,54,24,1,55,25],[23,45,15,28,46,16],[17,145,115],[10,74,46,23,75,47],[10,54,24,35,55,25],[19,45,15,35,46,16],[17,145,115,1,146,116],[14,74,46,21,75,47],[29,54,24,19,55,25],[11,45,15,46,46,16],[13,145,115,6,146,116],[14,74,46,23,75,47],[44,54,24,7,55,25],[59,46,16,1,47,17],[12,151,121,7,152,122],[12,75,47,26,76,48],[39,54,24,14,55,25],[22,45,15,41,46,16],[6,151,121,14,152,122],[6,75,47,34,76,48],[46,54,24,10,55,25],[2,45,15,64,46,16],[17,152,122,4,153,123],[29,74,46,14,75,47],[49,54,24,10,55,25],[24,45,15,46,46,16],[4,152,122,18,153,123],[13,74,46,32,75,47],[48,54,24,14,55,25],[42,45,15,32,46,16],[20,147,117,4,148,118],[40,75,47,7,76,48],[43,54,24,22,55,25],[10,45,15,67,46,16],[19,148,118,6,149,119],[18,75,47,31,76,48],[34,54,24,34,55,25],[20,45,15,61,46,16]],r=function(t,r){var e={};return e.totalCount=t,e.dataCount=r,e},e={};return e.getRSBlocks=function(e,n){var o=function(r,e){switch(e){case g.L:return t[4*(r-1)+0];case g.M:return t[4*(r-1)+1];case g.Q:return t[4*(r-1)+2];case g.H:return t[4*(r-1)+3];default:return}}(e,n);if(void 0===o)throw"bad rs block @ typeNumber:"+e+"/errorCorrectionLevel:"+n;for(var i=o.length/3,a=[],u=0;u<i;u+=1)for(var f=o[3*u+0],c=o[3*u+1],l=o[3*u+2],h=0;h<f;h+=1)a.push(r(c,l));return a},e}(),b=function(){var t=[],r=0,e={getBuffer:function(){return t},getAt:function(r){var e=Math.floor(r/8);return 1==(t[e]>>>7-r%8&1)},put:function(t,r){for(var n=0;n<r;n+=1)e.putBit(1==(t>>>r-n-1&1))},getLengthInBits:function(){return r},putBit:function(e){var n=Math.floor(r/8);t.length<=n&&t.push(0),e&&(t[n]|=128>>>r%8),r+=1}};return e},M=function(t){var r=a,e=t,n={getMode:function(){return r},getLength:function(t){return e.length},write:function(t){for(var r=e,n=0;n+2<r.length;)t.put(o(r.substring(n,n+3)),10),n+=3;n<r.length&&(r.length-n==1?t.put(o(r.substring(n,n+1)),4):r.length-n==2&&t.put(o(r.substring(n,n+2)),7))}},o=function(t){for(var r=0,e=0;e<t.length;e+=1)r=10*r+i(t.charAt(e));return r},i=function(t){if("0"<=t&&t<="9")return t.charCodeAt(0)-"0".charCodeAt(0);throw"illegal char :"+t};return n},x=function(t){var r=u,e=t,n={getMode:function(){return r},getLength:function(t){return e.length},write:function(t){for(var r=e,n=0;n+1<r.length;)t.put(45*o(r.charAt(n))+o(r.charAt(n+1)),11),n+=2;n<r.length&&t.put(o(r.charAt(n)),6)}},o=function(t){if("0"<=t&&t<="9")return t.charCodeAt(0)-"0".charCodeAt(0);if("A"<=t&&t<="Z")return t.charCodeAt(0)-"A".charCodeAt(0)+10;switch(t){case" ":return 36;case"$":return 37;case"%":return 38;case"*":return 39;case"+":return 40;case"-":return 41;case".":return 42;case"/":return 43;case":":return 44;default:throw"illegal char :"+t}};return n},m=function(r){var e=f,n=t.stringToBytes(r),o={getMode:function(){return e},getLength:function(t){return n.length},write:function(t){for(var r=0;r<n.length;r+=1)t.put(n[r],8)}};return o},L=function(r){var e=c,n=t.stringToBytesFuncs.SJIS;if(!n)throw"sjis not supported.";!function(){var t=n("友");if(2!=t.length||38726!=(t[0]<<8|t[1]))throw"sjis not supported."}();var o=n(r),i={getMode:function(){return e},getLength:function(t){return~~(o.length/2)},write:function(t){for(var r=o,e=0;e+1<r.length;){var n=(255&r[e])<<8|255&r[e+1];if(33088<=n&&n<=40956)n-=33088;else{if(!(57408<=n&&n<=60351))throw"illegal char at "+(e+1)+"/"+n;n-=49472}n=192*(n>>>8&255)+(255&n),t.put(n,13),e+=2}if(e<r.length)throw"illegal char at "+(e+1)}};return i},D=function(){var t=[],r={writeByte:function(r){t.push(255&r)},writeShort:function(t){r.writeByte(t),r.writeByte(t>>>8)},writeBytes:function(t,e,n){e=e||0,n=n||t.length;for(var o=0;o<n;o+=1)r.writeByte(t[o+e])},writeString:function(t){for(var e=0;e<t.length;e+=1)r.writeByte(t.charCodeAt(e))},toByteArray:function(){return t},toString:function(){var r="";r+="[";for(var e=0;e<t.length;e+=1)e>0&&(r+=","),r+=t[e];return r+="]"}};return r},S=function(t){var r=t,e=0,n=0,o=0,i={read:function(){for(;o<8;){if(e>=r.length){if(0==o)return-1;throw"unexpected end of file./"+o}var t=r.charAt(e);if(e+=1,"="==t)return o=0,-1;t.match(/^\s$/)||(n=n<<6|a(t.charCodeAt(0)),o+=6)}var i=n>>>o-8&255;return o-=8,i}},a=function(t){if(65<=t&&t<=90)return t-65;if(97<=t&&t<=122)return t-97+26;if(48<=t&&t<=57)return t-48+52;if(43==t)return 62;if(47==t)return 63;throw"c:"+t};return i},I=function(t,r,e){for(var n=function(t,r){var e=t,n=r,o=new Array(t*r),i={setPixel:function(t,r,n){o[r*e+t]=n},write:function(t){t.writeString("GIF87a"),t.writeShort(e),t.writeShort(n),t.writeByte(128),t.writeByte(0),t.writeByte(0),t.writeByte(0),t.writeByte(0),t.writeByte(0),t.writeByte(255),t.writeByte(255),t.writeByte(255),t.writeString(","),t.writeShort(0),t.writeShort(0),t.writeShort(e),t.writeShort(n),t.writeByte(0);var r=a(2);t.writeByte(2);for(var o=0;r.length-o>255;)t.writeByte(255),t.writeBytes(r,o,255),o+=255;t.writeByte(r.length-o),t.writeBytes(r,o,r.length-o),t.writeByte(0),t.writeString(";")}},a=function(t){for(var r=1<<t,e=1+(1<<t),n=t+1,i=u(),a=0;a<r;a+=1)i.add(String.fromCharCode(a));i.add(String.fromCharCode(r)),i.add(String.fromCharCode(e));var f,c,g,l=D(),h=(f=l,c=0,g=0,{write:function(t,r){if(t>>>r!=0)throw"length over";for(;c+r>=8;)f.writeByte(255&(t<<c|g)),r-=8-c,t>>>=8-c,g=0,c=0;g|=t<<c,c+=r},flush:function(){c>0&&f.writeByte(g)}});h.write(r,n);var s=0,v=String.fromCharCode(o[s]);for(s+=1;s<o.length;){var d=String.fromCharCode(o[s]);s+=1,i.contains(v+d)?v+=d:(h.write(i.indexOf(v),n),i.size()<4095&&(i.size()==1<<n&&(n+=1),i.add(v+d)),v=d)}return h.write(i.indexOf(v),n),h.write(e,n),h.flush(),l.toByteArray()},u=function(){var t={},r=0,e={add:function(n){if(e.contains(n))throw"dup key:"+n;t[n]=r,r+=1},size:function(){return r},indexOf:function(r){return t[r]},contains:function(r){return void 0!==t[r]}};return e};return i}(t,r),o=0;o<r;o+=1)for(var i=0;i<t;i+=1)n.setPixel(i,o,e(i,o));var a=D();n.write(a);for(var u=function(){var t=0,r=0,e=0,n="",o={},i=function(t){n+=String.fromCharCode(a(63&t))},a=function(t){if(t<0);else{if(t<26)return 65+t;if(t<52)return t-26+97;if(t<62)return t-52+48;if(62==t)return 43;if(63==t)return 47}throw"n:"+t};return o.writeByte=function(n){for(t=t<<8|255&n,r+=8,e+=1;r>=6;)i(t>>>r-6),r-=6},o.flush=function(){if(r>0&&(i(t<<6-r),t=0,r=0),e%3!=0)for(var o=3-e%3,a=0;a<o;a+=1)n+="="},o.toString=function(){return n},o}(),f=a.toByteArray(),c=0;c<f.length;c+=1)u.writeByte(f[c]);return u.flush(),"data:image/gif;base64,"+u};return t}();qrcode.stringToBytesFuncs["UTF-8"]=function(t){return function(t){for(var r=[],e=0;e<t.length;e++){var n=t.charCodeAt(e);n<128?r.push(n):n<2048?r.push(192|n>>6,128|63&n):n<55296||n>=57344?r.push(224|n>>12,128|n>>6&63,128|63&n):(e++,n=65536+((1023&n)<<10|1023&t.charCodeAt(e)),r.push(240|n>>18,128|n>>12&63,128|n>>6&63,128|63&n))}return r}(t)},function(t){"function"==typeof define&&define.amd?define([],t):"object"==typeof exports&&(module.exports=t())}((function(){return qrcode}));

</script>
<script>
const BOOT = __BOOT_JSON__;
const TOKEN_KEY = 'wsm_athlete_portal_token';
const J_MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
const J_WEEKDAYS = ['ش','ی','د','س','چ','پ','ج'];

function faNum(n){return String(n??'').replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d])}
function money(n){return Number(n||0).toLocaleString('fa-IR')}
function currencyLabel(){return String(state?.data?.currency_symbol||BOOT.currency_symbol||'تومان')}
function newUuid(){try{if(window.crypto&&typeof window.crypto.randomUUID==='function')return window.crypto.randomUUID()}catch(e){}return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=Math.random()*16|0;return(c==='x'?r:(r&3|8)).toString(16)})}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}

function g2j(gy,gm,gd){
  const g_d_m=[0,31,59,90,120,151,181,212,243,273,304,334];
  const gy2=(gm>2)?(gy+1):gy;
  let days=355666+(365*gy)+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+g_d_m[gm-1];
  let jy=-1595+(33*Math.floor(days/12053));days%=12053;
  jy+=4*Math.floor(days/1461);days%=1461;
  if(days>365){jy+=Math.floor((days-1)/365);days=(days-1)%365;}
  const jm=(days<186)?1+Math.floor(days/31):7+Math.floor((days-186)/30);
  const jd=(days<186)?1+(days%31):1+((days-186)%30);
  return [jy,jm,jd];
}
function j2g(jy,jm,jd){
  jy+=1595;
  let days=-355668+(365*jy)+(Math.floor(jy/33)*8)+Math.floor(((jy%33)+3)/4)+jd+((jm<7)?(jm-1)*31:((jm-7)*30)+186);
  let gy=400*Math.floor(days/146097);days%=146097;
  if(days>36524){gy+=100*Math.floor(--days/36524);days%=36524;if(days>=365)days++;}
  gy+=4*Math.floor(days/1461);days%=1461;
  if(days>365){gy+=Math.floor((days-1)/365);days=(days-1)%365;}
  let gd=days+1;
  const sal_a=[0,31,((gy%4===0&&gy%100!==0)||(gy%400===0))?29:28,31,30,31,30,31,31,30,31,30,31];
  let gm=0;for(;gm<13&&gd>sal_a[gm];gm++)gd-=sal_a[gm];
  return [gy,gm,gd];
}
function pad2(n){return String(n).padStart(2,'0')}
function jMonthLength(jy,jm){
  if(jm<=6)return 31;if(jm<=11)return 30;
  const [gy]=j2g(jy,12,30),[jy2]=g2j(gy,3,20);
  return j2g(jy,12,30)[2]===20||(((jy+1595)%33)%4===1)?30:29;
}
function ymdToJalali(ymd){
  if(!ymd||!/^\d{4}-\d{2}-\d{2}$/.test(ymd))return '—';
  const [y,m,d]=ymd.split('-').map(Number),[jy,jm,jd]=g2j(y,m,d);
  return `${jy}/${pad2(jm)}/${pad2(jd)}`;
}
function addDaysYmd(ymd,days){
  const [y,m,d]=ymd.split('-').map(Number);
  const dt=new Date(Date.UTC(y,m-1,d));
  dt.setUTCDate(dt.getUTCDate()+days);
  return `${dt.getUTCFullYear()}-${pad2(dt.getUTCMonth()+1)}-${pad2(dt.getUTCDate())}`;
}

const state = {
  token: localStorage.getItem(TOKEN_KEY) || '',
  view: 'login', // login | forgot | portal
  forgotStep: 1, // 1: phone, 2: six-digit OTP sign-in
  phoneDraft: '',
  loading: false,
  error: '',
  notice: '',
  data: null,
  callbackResult: BOOT.callbackResult || null,
  modalPlan: null,
  couponDraft: '',
  appliedCoupon: null,
  couponError: '',
  debtModalOpen: false,
  debtPercent: 100,
  debtAmount: 0,
  startMode: 'today', // today | after_current | after_previous_end | custom
  customDateYmd: '',
  customJalaliStr: '',
  calJy: 1405,
  calJm: 8,
  termsAccepted: true,
  selectedGatewayId: '',
  hubTab:'home', supportItems:null, supportBusy:false, rulesOpen:false, receiptId:0
};

(function initCal(){
  const [y,m,d]=(BOOT.today||'2026-10-25').split('-').map(Number);
  const [jy,jm,jd]=g2j(y,m,d);
  state.calJy=jy;state.calJm=jm;
  state.customDateYmd=BOOT.today;
  state.customJalaliStr=`${jy}/${pad2(jm)}/${pad2(jd)}`;
})();
if(BOOT.paymentReturn?.status==='success')state.notice='پرداخت با موفقیت تأیید و ثبت شد.'+(BOOT.paymentReturn.ref?' شمارهٔ پیگیری: '+BOOT.paymentReturn.ref:'');
else if(BOOT.paymentReturn?.status==='failed')state.error='بازگشت از درگاه انجام شد اما ثبت پرداخت کامل نشد؛ اگر مبلغ کسر شده با باشگاه تماس بگیرید.';
if(BOOT.paymentReturn?.status)try{history.replaceState({},'',location.pathname+location.hash)}catch(e){}

async function portalApi(path, method='GET', body=null){
  const headers={'Accept':'application/json'};
  if(body!==null)headers['Content-Type']='application/json';
  if(state.token){headers['Authorization']='Bearer '+state.token;headers['X-WSM-Athlete-Token']=state.token;}
  let res;
  try{res=await fetch(BOOT.apiBase+path,{method,headers,body:body!==null?JSON.stringify(body):undefined,credentials:'omit',cache:'no-store'});}
  catch(error){throw new Error('ارتباط با سرور برقرار نشد؛ اتصال اینترنت را بررسی کنید.')}
  const json=await res.json().catch(()=>({}));
  if(!res.ok){const error=new Error(json.message||json?.data?.message||'خطا در ارتباط با سرور');error.status=res.status;throw error;}
  return json;
}
async function refreshPortalData(){state.data=await portalApi('/me','GET');state.view='portal';return state.data;}

function render(){
  const root=document.getElementById('portal-app');
  if(!root)return;
  // A late support refresh must not erase a password form being edited.
  // Keep these values only in this render call; never persist secrets in state or storage.
  const oldPasswordForm=!state.loading&&state.data&&state.hubTab==='profile'?root.querySelector('#portal-change-password-form'):null;
  const passwordDraft=oldPasswordForm?{
    new:oldPasswordForm.elements.new_password?.value||'',
    confirm:oldPasswordForm.elements.confirm_password?.value||'',
    focus:document.activeElement?.id||''
  }:null;
  if(!BOOT.enabled){
    root.innerHTML=`<header class="portal-header"><div class="portal-brand"><div class="portal-logo">★</div><div class="portal-title"><h1>پورتال اختصاصی ورزشکار</h1><small>${esc(BOOT.clubName)}</small></div></div></header><section class="card"><div class="alert err">پورتال اختصاصی ورزشکاران در حال حاضر توسط مدیریت باشگاه غیرفعال شده است.</div></section>`;
    return;
  }
  const header=`<header class="portal-header"><div class="portal-brand"><div class="portal-logo">★</div><div class="portal-title"><h1>پورتال اختصاصی ورزشکار</h1><small>${esc(BOOT.clubName)} · امروز ${esc(faNum(BOOT.todayJalali))}</small></div></div>${state.data?`<button class="btn ghost" type="button" data-act="logout">خروج</button>`:''}</header>`;
  const alerts=(state.error?`<div class="alert err" id="portal-alert-err">${esc(state.error)}</div>`:'')+(state.notice?`<div class="alert ok" id="portal-alert-ok">${esc(state.notice)}</div>`:'');

  if(!state.data){
    if(state.view==='forgot'){
      root.innerHTML=header+`<section class="card"><div class="hub-kicker">SECURE SIGN-IN · ورود پیامکی</div><h2>ورود با کد یک‌بارمصرف</h2><p class="muted">برای ورود بدون رمز، کد شش‌رقمی به موبایل ثبت‌شدهٔ شما پیامک می‌شود. پس از ورود می‌توانید در پروفایل رمز تازه تعیین کنید.</p>${alerts}${state.forgotStep===1?`<form id="portal-forgot-req-form"><label class="label" for="forgot-phone">شماره موبایل</label><input class="input" id="forgot-phone" name="phone" type="tel" inputmode="tel" dir="ltr" autocomplete="tel" required value="${esc(state.phoneDraft)}" placeholder="0912..."><button class="btn primary full" type="submit" ${state.loading?'disabled':''}>${state.loading?'در حال بررسی و ارسال…':'ارسال کد یک‌بارمصرف'}</button><button class="btn ghost full" type="button" data-act="go-login">بازگشت به ورود با رمز</button></form>`:`<form id="portal-forgot-verify-form"><label class="label">شماره موبایل</label><input class="input" name="phone" type="tel" dir="ltr" readonly value="${esc(state.phoneDraft)}"><label class="label" for="forgot-otp">کد پیامک‌شدهٔ شش‌رقمی</label><input class="input" id="forgot-otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9۰-۹٠-٩]{6}" dir="ltr" maxlength="6" required placeholder="123456"><button class="btn primary full" type="submit" ${state.loading?'disabled':''}>${state.loading?'در حال ورود…':'تأیید کد و ورود'}</button><button class="btn ghost full" type="button" data-act="otp-resend">دریافت کد جدید / اصلاح شماره</button><button class="btn ghost full" type="button" data-act="go-login">ورود با رمز عبور</button></form>`}</section>`;
      return;
    }
    root.innerHTML=header+`<section class="card"><h2>ورود به پورتال ورزشکار</h2><p class="muted">با شماره موبایل ثبت‌شده در باشگاه وارد شوید. رمز پیش‌فرض: چهار رقم آخر موبایل شما. پس از ورود رمز خود را تغییر دهید.</p>${alerts}<form id="portal-login-form"><label class="label" for="login-phone">شماره موبایل</label><input class="input" id="login-phone" name="phone" type="tel" dir="ltr" required value="${esc(state.phoneDraft)}" placeholder="0912..."><label class="label" for="login-password">رمز عبور</label><input class="input" id="login-password" name="password" type="password" required placeholder="۴ رقم آخر موبایل یا رمز جدید"><button class="btn primary full" type="submit" ${state.loading?'disabled':''}>${state.loading?'در حال ورود…':'ورود به پورتال'}</button><button class="btn ghost full" type="button" data-act="go-forgot">ارسال کد یک‌بارمصرف</button></form>${BOOT.termsText?`<div style="margin-top:14px"><small class="muted">قوانین و شرایط باشگاه:</small><div class="terms-box">${esc(BOOT.termsText)}</div></div>`:''}</section>`;
    return;
  }

  const p=state.data.profile||{},m=state.data.membership||{},debt=state.data.debt||{total_debt:0,items:[]},raffle=state.data.raffle||null,plans=state.data.plans||[],payments=state.data.payments||[];
  const visits=Array.isArray(state.data.attendance)?state.data.attendance:[];
  const scheduledMemberships=Array.isArray(state.data.scheduled_memberships)?state.data.scheduled_memberships:(Array.isArray(m.scheduled)?m.scheduled:[]);
  const membershipHasUnexpired=!!(state.data.has_unexpired_membership||m.has_unexpired_membership);
  const previousEndDate=String(state.data.previous_end_date||m.previous_end_date||'');
  const previousEndJalali=previousEndDate?ymdToJalali(previousEndDate):'';
  const nextStartDate=previousEndDate&&previousEndDate>=BOOT.today?addDaysYmd(previousEndDate,1):BOOT.today;
  const nextStartJalali=ymdToJalali(nextStartDate);
  const totalDebt=Number(debt.total_debt??p.balance_due??0);
  const gatewayList=Array.isArray(state.data.available_gateways)?state.data.available_gateways:[];
  const selectedGateway=state.selectedGatewayId||state.data.default_gateway_id||(gatewayList[0]?.id||'');
  const gatewaySelect=gatewayList.length>1?`<label class="label" for="portal-gateway-select">انتخاب درگاه بانکی فعال</label><select class="input" id="portal-gateway-select">${gatewayList.map(g=>`<option value="${esc(g.id)}" ${selectedGateway===g.id?'selected':''}>${esc(g.title)}</option>`).join('')}</select>`:(gatewayList.length?`<div class="muted" style="margin:8px 0">درگاه بانکی: ${esc(gatewayList[0].title)}</div>`:'');
  const gatewayWarning=!state.data.gateway_ready?'<div class="alert err">درگاه بانکی فعالی برای پرداخت یافت نشد؛ لطفاً به مدیریت اطلاع دهید.</div>':'';

  let cbBanner='';
  if(state.callbackResult){
    if(state.callbackResult.ok){
      if(state.callbackResult.intent_type==='debt'){
        cbBanner=`<div class="alert ok" id="portal-callback-banner"><b>پرداخت آنلاین بدهی با موفقیت انجام و از حساب شما کسر شد!</b><br>مبلغ پرداختی: ${money(state.callbackResult.paid_amount||state.callbackResult.amount)} ${esc(currencyLabel())} · مانده بدهی فعلی: <b>${money(state.callbackResult.remaining_debt)} ${esc(currencyLabel())}</b> · کد رهگیری: <span dir="ltr">${esc(state.callbackResult.ref_id)}</span></div>`;
      }else{
        const discInfo=Number(state.callbackResult.discount_amount||0)>0?` · تخفیف اعمال‌شده: <b>${money(state.callbackResult.discount_amount)} ${esc(currencyLabel())} (${esc(state.callbackResult.coupon_code)})</b>`:'';
        cbBanner=`<div class="alert ok" id="portal-callback-banner"><b>پرداخت آنلاین و تمدید عضویت با موفقیت انجام شد!</b><br>تعرفه: ${esc(state.callbackResult.plan_name)} · از ${esc(faNum(state.callbackResult.start_date_jalali))} تا ${esc(faNum(state.callbackResult.end_date_jalali))} · مبلغ پرداختی: <b>${money(state.callbackResult.amount)} ${esc(currencyLabel())}</b>${discInfo} · کد رهگیری: <span dir="ltr">${esc(state.callbackResult.ref_id)}</span>${state.callbackResult.needs_staff_review?'<br><small>اعلان بررسی و تأیید تاریخ شروع برای کارمند پذیرش ارسال شد.</small>':''}</div>`;
      }
    }else{
      cbBanner=`<div class="alert err" id="portal-callback-banner">${esc(state.callbackResult.message||'پرداخت ناموفق بود.')}</div>`;
    }
  }

  const scheduledHtml=scheduledMemberships.length?`<div style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--line);font-size:13px"><b>دورهٔ رزروشدهٔ بعدی:</b> ${scheduledMemberships.map(s=>`${esc(s.plan_name)} (${esc(faNum(s.start_date_jalali||ymdToJalali(s.start_date)))} تا ${esc(faNum(s.end_date_jalali||ymdToJalali(s.end_date)))})`).join(' ، ')}</div>`:'';

  const debtItemsHtml=(debt.items&&debt.items.length)?`<div style="margin-top:10px;display:flex;flex-direction:column;gap:6px">${debt.items.map(it=>`<div style="display:flex;justify-content:space-between;align-items:center;background:#0b1325;border:1px solid var(--line);padding:8px 12px;border-radius:10px;font-size:13px"><span>${esc(it.name||it.title||'بدهی')}${(it.end_date_jalali||it.end_date)?` <small class="muted">(تا ${faNum(it.end_date_jalali||ymdToJalali(it.end_date))})</small>`:''}</span><b style="color:#fca5a5">${money(it.amount_due??it.due)} ${esc(currencyLabel())}</b></div>`).join('')}</div>`:'';

  const debtCardHtml=totalDebt>0?`<section class="card" id="portal-debt-card" style="border-color:rgba(245,158,11,.55);background:linear-gradient(180deg,rgba(245,158,11,.1),var(--card))"><div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px"><div><h2 style="margin:0;color:#fcd34d">وضعیت بدهی حساب شما</h2><div class="muted">شما می‌توانید کل بدهی یا درصدی از بدهی خود را آنلاین پرداخت نمایید تا خودکار از حسابتان کسر شود.</div></div><button class="btn primary" type="button" id="portal-open-debt-btn" data-act="open-debt-modal">پرداخت آنلاین بدهی (${money(totalDebt)} ${esc(currencyLabel())})</button></div>${debtItemsHtml}</section>`:'';

  let raffleCardHtml='';
  if(raffle){
    const isPart=!!raffle.eligible;
    const isWin=!!raffle.my_winner;
    const statusTone=isWin||isPart?'green':'yellow';
    const prizesList=(Array.isArray(raffle.prizes)?raffle.prizes:[]).map(pr=>`<div style="display:flex;justify-content:space-between;align-items:center;background:#0b1325;border:1px solid var(--line);padding:8px 12px;border-radius:10px;font-size:13px"><span><b>${esc(pr.rank_label||('نفر '+pr.rank))}:</b> ${esc(pr.title||pr.prize_title||'جایزه')}</span><span class="status-pill green" style="font-size:11px">${faNum(pr.discount_percent||0)}٪ تخفیف (${faNum(pr.winners_count||1)} نفر)</span></div>`).join('');
    const winnersList=(Array.isArray(raffle.winners)&&raffle.winners.length)?`<div id="portal-raffle-winners-box" style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--line)"><h3 style="margin:0 0 8px;font-size:14px;color:#fcd34d">🏆 اسامی برندگان این دوره</h3><div style="display:flex;flex-direction:column;gap:6px">${raffle.winners.map(w=>`<div style="display:flex;justify-content:space-between;align-items:center;background:#0b1325;border:1px solid var(--line);padding:8px 12px;border-radius:10px;font-size:13px"><span><b>${esc(w.rank_label||('نفر '+w.rank))}:</b> ${esc(w.display_name||w.full_name||'برنده')} <small class="muted">· ${esc(w.prize_title||w.title||'جایزه')}</small></span><span style="color:#fcd34d;font-weight:700">${faNum(w.discount_percent||0)}٪ تخفیف</span></div>`).join('')}</div></div>`:'';
    const myCoupons=raffle.my_coupons||state.data.coupons||[];
    const couponsHtml=myCoupons.length?`<div id="portal-my-coupons-box" style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--line)"><h3 style="margin:0 0 8px;font-size:14px;color:#34d399">🎁 کد تخفیف من</h3><div style="display:flex;flex-direction:column;gap:8px">${myCoupons.map(c=>`<div class="portal-coupon-item" data-coupon-code="${esc(c.code)}" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.45);padding:10px 12px;border-radius:12px"><div><div style="font-weight:700;font-size:14px">کد: <code dir="ltr" style="background:#0b1325;padding:3px 8px;border-radius:6px;color:#6ee7b7;font-size:14px" class="my-coupon-code-text">${esc(c.code)}</code> · <b>${faNum(c.discount_percent)}٪ تخفیف</b></div><div class="muted" style="font-size:12px">${esc(c.prize_title||c.raffle_title||'جایزه قرعه‌کشی')} · اعتبار تا ${esc(faNum(c.expires_at_jalali||c.expires_at||'—'))} · وضعیت: <b>${c.status==='used'?'مصرف‌شده':(c.status==='reserved'?'در انتظار پرداخت':(c.status==='expired'?'منقضی':'قابل استفاده'))}</b></div></div><div style="display:flex;gap:6px"><button type="button" class="btn primary" style="padding:7px 12px;font-size:12px" data-act="copy-coupon" data-code="${esc(c.code)}">کپی</button></div></div>`).join('')}</div></div>`:'';
    const statusTitle=isWin?'تبریک! شما برندهٔ این دوره شده‌اید.':isPart?'شما در قرعه‌کشی این دوره شرکت دارید.':'وضعیت شرکت در قرعه‌کشی';
    const raffleStatus=raffle.status==='completed'?'انجام‌شده':raffle.status==='active'?'در جریان':String(raffle.status||'—');
    raffleCardHtml=`<section class="card" id="portal-raffle-card" style="border-color:rgba(16,185,129,.45)"><div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px"><h2 style="margin:0">🎯 ${esc(raffle.title||'قرعه‌کشی خوش‌حساب‌های باشگاه')}</h2><span class="status-pill ${isPart||isWin?'green':'yellow'}" id="portal-raffle-participants-badge">شرکت‌کنندگان: <b id="portal-raffle-participants-count">${faNum(raffle.participants_count||0)}</b> نفر</span></div><div class="muted" style="margin-top:4px">وضعیت دوره: <b>${esc(raffleStatus)}</b></div><div class="status-banner ${statusTone}" id="portal-raffle-status-box" style="margin-top:10px"><strong id="portal-raffle-status-title">${esc(statusTitle)}</strong><div style="font-size:13px" id="portal-raffle-status-msg">${esc(raffle.status_message||'')}</div></div><div style="margin-top:12px"><div class="label" style="margin:0 0 6px">لیست جوایز این دوره:</div><div id="portal-raffle-prizes" style="display:flex;flex-direction:column;gap:6px">${prizesList}</div></div>${winnersList}${couponsHtml}</section>`;
  }

  const plansHtml=plans.map(pl=>`<div class="plan-card" data-plan-card="${pl.id}"><div><h3>${esc(pl.name)}</h3><div class="muted">مدت دوره: ${faNum(pl.duration_days)} روز${pl.max_visits?` · ${faNum(pl.max_visits)} جلسه`:' · جلسات نامحدود'}</div>${pl.description?`<div class="muted" style="margin-top:4px">${esc(pl.description)}</div>`:''}</div><div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px"><span class="plan-price">${money(pl.price)} ${esc(currencyLabel())}</span><button class="btn primary" type="button" data-act="open-buy-modal" data-plan-id="${pl.id}">خرید / تمدید</button></div></div>`).join('')||'<p class="muted">تعرفهٔ فعالی یافت نشد.</p>';

  let debtModalHtml='';
  if(state.debtModalOpen && totalDebt>0){
    const payAmt=Math.min(totalDebt,Math.max(1,Math.round(Number(state.debtAmount||totalDebt))));
    const remAfter=Math.max(0,totalDebt-payAmt);
    debtModalHtml=`<div class="modal-backdrop" id="portal-debt-modal"><div class="modal-box"><h3>پرداخت آنلاین بدهی باشگاه</h3><p class="muted">جمع کل بدهی فعلی شما: <b id="debt-modal-total">${money(totalDebt)} ${esc(currencyLabel())}</b></p><label class="label">انتخاب سریع درصد پرداخت از بدهی:</label><div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px">${[25,50,75,100].map(pct=>`<button type="button" class="btn ${Number(state.debtPercent)===pct?'primary':''}" data-act="debt-pct" data-pct="${pct}">${faNum(pct)}٪${pct===100?' (کل)':''}</button>`).join('')}</div><div class="profile-grid" style="margin-top:8px"><div><label class="label" for="portal-debt-percent-input">درصد دلخواه (۱ تا ۱۰۰٪)</label><input class="input" id="portal-debt-percent-input" type="number" min="1" max="100" step="1" dir="ltr" value="${esc(state.debtPercent)}"></div><div><label class="label" for="portal-debt-amount-input">مبلغ پرداختی (تومان)</label><input class="input" id="portal-debt-amount-input" type="number" min="1" max="${totalDebt}" step="1000" dir="ltr" value="${esc(payAmt)}"></div></div><div class="alert ok" style="margin-top:12px" id="portal-debt-summary-box">مبلغ قابل پرداخت آنلاین: <b id="portal-debt-pay-display">${money(payAmt)} ${esc(currencyLabel())}</b> (${faNum(state.debtPercent)}٪)<br>مانده بدهی شما پس از کسر این پرداخت: <b id="portal-debt-rem-display">${money(remAfter)} ${esc(currencyLabel())}</b></div>${gatewaySelect}${gatewayWarning}<div style="display:flex;gap:8px;margin-top:14px"><button class="btn primary" style="flex:1" type="button" id="portal-confirm-debt-btn" data-act="confirm-debt-pay" ${state.loading?'disabled':''}>${state.loading?'در حال انتقال به درگاه…':'پرداخت آنلاین و کسر از بدهی'}</button><button class="btn ghost" type="button" data-act="close-debt-modal">انصراف</button></div></div></div>`;
  }

  let modalHtml='';
  if(state.modalPlan){
    const pl=state.modalPlan;
    const basePrice=Number(pl.price||0);
    const appCoupon=state.appliedCoupon;
    const discountAmt=appCoupon?Number(appCoupon.discount_amount||0):0;
    const finalPayPrice=appCoupon?Math.max(1000,Number(appCoupon.final_amount||(basePrice-discountAmt))):basePrice;
    const hasUnexpired=membershipHasUnexpired;
    let chosenStartYmd=BOOT.today;
    if(state.startMode==='after_current')chosenStartYmd=nextStartDate;
    else if(state.startMode==='after_previous_end')chosenStartYmd=nextStartDate;
    else if(state.startMode==='custom')chosenStartYmd=state.customDateYmd||BOOT.today;
    const chosenEndYmd=addDaysYmd(chosenStartYmd,Math.max(1,Number(pl.duration_days||30))-1);

    let calHtml='';
    if(state.startMode==='custom'){
      const len=jMonthLength(state.calJy,state.calJm);
      const [gy1,gm1,gd1]=j2g(state.calJy,state.calJm,1);
      const firstDow=(new Date(Date.UTC(gy1,gm1-1,gd1)).getUTCDay()+1)%7;
      const minCalYmd=BOOT.today;
      let cells='';
      for(let i=0;i<firstDow;i++)cells+='<div></div>';
      for(let d=1;d<=len;d++){
        const [gy,gm,gd]=j2g(state.calJy,state.calJm,d);
        const ymd=`${gy}-${pad2(gm)}-${pad2(gd)}`;
        const jStr=`${state.calJy}/${pad2(state.calJm)}/${pad2(d)}`;
        const disabled=ymd<minCalYmd;
        const selected=ymd===state.customDateYmd;
        cells+=`<button type="button" class="jcal-day ${selected?'selected':''}" data-act="pick-jday" data-ymd="${ymd}" data-jstr="${jStr}" ${disabled?'disabled':''}>${faNum(d)}</button>`;
      }
      calHtml=`<div class="jcal-box" id="portal-jalali-picker"><div class="jcal-head"><button type="button" class="btn" data-act="cal-prev">‹ ماه قبل</button><span>${J_MONTHS[state.calJm-1]} ${faNum(state.calJy)}</span><button type="button" class="btn" data-act="cal-next">ماه بعد ›</button></div><div class="jcal-grid">${J_WEEKDAYS.map(w=>`<div class="jcal-wd">${w}</div>`).join('')}${cells}</div><div style="margin-top:8px;display:flex;align-items:center;gap:8px"><label class="muted" for="portal-custom-jalali-input">تاریخ شمسی انتخاب‌شده:</label><input class="input" id="portal-custom-jalali-input" dir="ltr" style="max-width:140px;padding:6px 10px" value="${esc(state.customJalaliStr)}" placeholder="1405/08/15"></div></div>`;
    }

    const policyNotice=hasUnexpired
      ? `<div class="alert ok" id="portal-policy-banner" style="margin:8px 0">اشتراک فعلی شما تا تاریخ <b>${faNum(m.end_date_jalali)}</b> اعتبار دارد؛ بنابراین ثبت‌نام جدید شما به‌طور خودکار از <b>یک روز بعد از پایان ثبت‌نام قبلی (${faNum(nextStartJalali)})</b> آغاز می‌شود و شما به‌طور خودکار وارد قرعه‌کشی این دوره خواهید شد.</div>`
      : `<div class="alert" id="portal-policy-banner" style="margin:8px 0;background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.45);color:#fde68a">از آنجا که تاریخ ثبت‌نام قبلی شما گذشته است${previousEndJalali?` (پایان قبلی: ${faNum(previousEndJalali)})`:''}، تاریخ شروع را مشخص نمایید. پس از ثبت‌نام، اعلان بررسی تاریخ در سیستم کارمندان باشگاه نمایش داده می‌شود تا کارمند تاریخ را تأیید یا تنظیم نماید.</div>`;

    const prevOptionLabel=hasUnexpired
      ? `<div><b>شروع از فردای روزی که عضویت فعلی‌ام تمام می‌شود (پیشنهادی خودکار)</b><div class="muted">تاریخ شروع خودکار: ${faNum(nextStartJalali||BOOT.todayJalali)}</div></div>`
      : `<div><b>شروع پیوسته از فردای پایان ثبت‌نام قبلی (در صورت حضور در باشگاه)</b><div class="muted">تاریخ شروع: ${faNum(nextStartJalali||BOOT.todayJalali)}</div></div>`;

    const couponSectionHtml=`<div style="margin-top:12px;padding:12px;background:#0b1325;border:1px solid var(--line);border-radius:12px" id="portal-checkout-coupon-box"><label class="label" for="portal-coupon-input" style="margin-top:0">کد تخفیف دارید؟</label><div style="display:flex;gap:8px"><input class="input" id="portal-coupon-input" dir="ltr" placeholder="WIN-2026-XXXX" value="${esc(state.couponDraft)}" style="text-transform:uppercase"><button class="btn primary" type="button" id="portal-apply-coupon-btn" data-act="apply-coupon" ${state.loading?'disabled':''}>اعمال کد</button>${appCoupon?`<button class="btn ghost" type="button" data-act="remove-coupon">حذف</button>`:''}</div>${state.couponError?`<div class="alert err" id="portal-coupon-error" style="margin:8px 0 0;padding:8px 10px">${esc(state.couponError)}</div>`:''}${appCoupon?`<div class="alert ok" id="portal-coupon-applied-box" style="margin:8px 0 0;padding:8px 10px">کد تخفیف <b>${esc(appCoupon.code)}</b> (${faNum(appCoupon.discount_percent)}٪) اعمال شد!<br>مبلغ اصلی: ${money(basePrice)} ${esc(currencyLabel())} · کسر تخفیف: <b>${money(discountAmt)} ${esc(currencyLabel())}</b><br>مبلغ نهایی قابل پرداخت: <b id="portal-checkout-final-amount">${money(finalPayPrice)} ${esc(currencyLabel())}</b></div>`:''}</div>`;

    modalHtml=`<div class="modal-backdrop" id="portal-buy-modal"><div class="modal-box"><h3>خرید / تمدید آنلاین: ${esc(pl.name)}</h3><p class="muted">مبلغ قابل پرداخت: <b id="portal-modal-price-label">${money(finalPayPrice)} ${esc(currencyLabel())}</b>${appCoupon?` <del class="muted">${money(basePrice)} ${esc(currencyLabel())}</del>`:''} · مدت دوره: ${faNum(pl.duration_days)} روز</p>${policyNotice}<label class="label">انتخاب تاریخ شروع عضویت:</label><label class="radio-option ${state.startMode==='after_current'?'active':''}"><input type="radio" name="portal_start_mode" value="after_current" ${state.startMode==='after_current'?'checked':''}>${prevOptionLabel}</label><label class="radio-option ${state.startMode==='today'?'active':''}"><input type="radio" name="portal_start_mode" value="today" ${state.startMode==='today'?'checked':''}><div><b>شروع از همین امروز</b><div class="muted">تاریخ شروع: ${faNum(BOOT.todayJalali)}</div></div></label><label class="radio-option ${state.startMode==='custom'?'active':''}"><input type="radio" name="portal_start_mode" value="custom" ${state.startMode==='custom'?'checked':''}><div><b>انتخاب تاریخ دلخواه (با تقویم شمسی)</b><div class="muted">مشخص کردن روز دقیق شروع از روی تقویم شمسی</div></div></label>${calHtml}<div class="alert ok" style="margin-top:10px" id="portal-computed-period">بازهٔ دورهٔ انتخابی شما: از <b>${faNum(ymdToJalali(chosenStartYmd))}</b> تا <b>${faNum(ymdToJalali(chosenEndYmd))}</b></div>${couponSectionHtml}${gatewaySelect}${gatewayWarning}${state.data.terms_text?`<div class="terms-box">${esc(state.data.terms_text)}</div>`:''}<label style="display:flex;align-items:center;gap:8px;font-size:13px;margin:10px 0;cursor:pointer"><input type="checkbox" id="portal-terms-check" ${state.termsAccepted?'checked':''}> قوانین و شرایط باشگاه را مطالعه کرده‌ام و می‌پذیرم.</label><div style="display:flex;gap:8px;margin-top:14px"><button class="btn primary" style="flex:1" type="button" id="portal-confirm-pay-btn" data-act="confirm-checkout" ${state.loading?'disabled':''}>${state.loading?'در حال انتقال به درگاه…':`پرداخت آنلاین (${money(finalPayPrice)} ${esc(currencyLabel())})`}</button><button class="btn ghost" type="button" data-act="close-modal">انصراف</button></div></div></div>`;
  }

  const statusHtml=`<section class="card" id="portal-status-card"><div class="hub-kicker">MEMBERSHIP · وضعیت عضویت</div><h2>اشتراک من</h2><div class="status-banner ${esc(m.status_color||'red')}" id="portal-status-banner"><div style="display:flex;justify-content:space-between;gap:8px"><strong>${esc(m.plan_name||'فاقد اشتراک فعال')}</strong><span class="status-pill ${esc(m.status_color||'red')}" id="portal-status-pill">${esc(m.status_label||'منقضی')}</span></div><div class="profile-grid"><div class="profile-item"><small>شروع</small><strong id="mem-start-jalali">${esc(faNum(m.start_date_jalali||'—'))}</strong></div><div class="profile-item"><small>انقضا</small><strong id="mem-end-jalali">${esc(faNum(m.end_date_jalali||'—'))}</strong></div></div><div>روزهای باقی‌مانده: <b id="mem-days-remaining">${faNum(m.days_left||0)} روز</b></div>${scheduledHtml}</div></section>`;
  const plansSectionHtml=`<section class="card" id="portal-plans-card"><div class="hub-kicker">MEMBERSHIP PLANS · تعرفه‌های فعال</div><h2>خرید / تمدید اشتراک</h2><p class="muted">تعرفهٔ مورد نظر را انتخاب کنید و تاریخ شروع دوره را تعیین نمایید.</p><div class="plans-grid">${plansHtml}</div></section>`;
  const securityHtml=`<section class="card" id="portal-security-card"><div class="hub-kicker">ACCOUNT SECURITY · امنیت حساب</div><h2>تغییر رمز عبور</h2>${state.data.needs_password_change?'<div class="alert err">شما هنوز از رمز اولیهٔ چهار رقم آخر موبایل استفاده می‌کنید؛ لطفاً آن را تغییر دهید.</div>':''}<form id="portal-change-password-form"><p class="muted">رمز قبلی لازم نیست. برای حفظ امنیت، تغییر رمز تا ۱۵ دقیقه پس از ورود با رمز یا کد امکان‌پذیر است؛ بعد از آن دوباره وارد شوید.</p><label class="label" for="portal-new-password">رمز جدید (۸ تا ۱۲۸ نویسه)</label><input class="input" id="portal-new-password" type="password" name="new_password" minlength="8" maxlength="128" required autocomplete="new-password"><label class="label" for="portal-confirm-password">تکرار رمز جدید</label><input class="input" id="portal-confirm-password" type="password" name="confirm_password" minlength="8" maxlength="128" required autocomplete="new-password"><button class="btn primary full" type="submit" ${state.loading?'disabled':''}>ذخیره رمز جدید</button></form><button class="btn ghost full" type="button" data-act="reauth-otp">ورود دوباره با کد یک‌بارمصرف</button></section>`;
  const qrCode=String(p.member_code||'').trim();let qrSvg='';
  if(qrCode && typeof qrcode==='function'){
    try{const qr=qrcode(0,'M');qr.addData(qrCode);qr.make();qrSvg=qr.createSvgTag(4,4)}catch(_){qrSvg='';}
  }
  const trainingUrl=String(state.data.training_url||'');
  const hero=`<section class="card hub-hero hub-wide" id="portal-profile-card"><div class="hub-kicker">ATHLETE IDENTITY · کارت دیجیتال</div><div class="hub-name">${esc(p.first_name||'')} ${esc(p.last_name||'')}</div><div class="hub-stat"><span>کد عضویت</span><b dir="ltr" id="prof-member-code">${esc(p.member_code||'—')}</b></div><div class="hub-stat"><span>موبایل</span><b dir="ltr" id="prof-phone">${esc(p.phone||'—')}</b></div></section>`;
  const qrCard=`<section class="card"><div class="hub-kicker">GATE ACCESS · کد ورود</div><div class="hub-qr"><div><h2>ورود هوشمند</h2><p class="muted">کد QR عضویت را مقابل اسکنر گیت قرار دهید. اعتبار ورود را دستگاه و سرور تعیین می‌کنند.</p><b dir="ltr">${esc(qrCode||'کد ثبت نشده')}</b></div>${qrSvg||'<span class="muted">QR در دسترس نیست</span>'}</div></section>`;
  const trainingCard=`${trainingUrl?`<a class="card hub-training" href="${esc(trainingUrl)}" target="_blank" rel="noopener noreferrer"><div class="hub-kicker">TRAINING APP · همراه تمرین</div><h2>برنامه تمرینی ↗</h2><p class="muted">مشاهدهٔ برنامه و مسیر تمرین در اپلیکیشن اختصاصی شما</p><b>باز کردن اپ تمرینی ←</b></a>`:`<section class="card hub-training disabled"><div class="hub-kicker">TRAINING APP · همراه تمرین</div><h2>برنامه تمرینی</h2><p class="muted">اتصال اپ تمرینی هنوز توسط مدیریت تنظیم نشده است.</p><b>به‌زودی</b></section>`}`;
  const financeTop=`<section class="card hub-wide"><div class="hub-kicker">FINANCE · وضعیت حساب</div><h2>نمای مالی من</h2><div class="hub-stat"><span>مانده بدهی</span><b id="prof-balance-due" style="color:${totalDebt>0?'#fca5a5':'#8ce7c8'}">${money(totalDebt)} ${esc(currencyLabel())}</b></div>${totalDebt>0?'<button class="btn primary full" type="button" data-act="open-debt-modal">پرداخت کامل / درصدی بدهی</button>':'<p class="muted">حساب شما تسویه است.</p>'}</section>`;
  const visitHtml=visits.length?visits.map(v=>`<div class="hub-row"><span><b>${v.direction==='out'?'خروج':'ورود'}</b><small>${esc(faNum(v.occurred_at_jalali||''))} · ${esc(faNum(String(v.occurred_at||'').slice(11,16)))}</small></span><span class="status-pill ${v.decision==='allowed'?'green':'yellow'}">${v.decision==='allowed'?'ثبت‌شده':esc(v.decision||'نامشخص')}</span></div>`).join(''):'<p class="muted">ترددی برای نمایش ثبت نشده است.</p>';
  const paymentHtml=payments.length?payments.map(pay=>`<div class="hub-row"><span><b>${money(pay.amount)} ${esc(currencyLabel())}</b><small>${esc(faNum(pay.paid_at_jalali||''))} · ${esc(pay.method||'پرداخت')}</small></span><button class="btn ghost" type="button" data-act="view-receipt" data-id="${Number(pay.id)}">رسید #${faNum(pay.id)}</button></div>`).join(''):'<p class="muted">تراکنشی برای نمایش ثبت نشده است.</p>';
  const supportHtml=`<section class="card hub-wide" id="portal-support-card"><div style="display:flex;justify-content:space-between;gap:8px"><div><div class="hub-kicker">SUPPORT · گفتگوی خصوصی</div><h2>پشتیبانی باشگاه</h2></div><button class="btn ghost" type="button" data-act="refresh-support">↻ بروزرسانی</button></div><p class="muted">پیام شما مستقیماً در صندوق مدیریت ثبت می‌شود.</p><div class="hub-chat" id="portal-support-messages" role="log" aria-live="polite">${state.supportItems===null?'<p class="muted">برای دریافت گفتگو، «بروزرسانی» را بزنید.</p>':(state.supportItems.length?state.supportItems.map(x=>`<div class="hub-bubble ${x.from_athlete?'mine':''}">${esc(x.message)}<small>${x.from_athlete?'شما':'پشتیبانی'} · ${esc(faNum(x.created_at))}</small></div>`).join(''):'<p class="muted">هنوز پیامی ارسال نشده است.</p>')}</div><form id="portal-support-form" class="hub-chat-form"><textarea class="input" name="message" maxlength="2000" required placeholder="پیام خود را برای پشتیبانی بنویسید…"></textarea><button class="btn primary" type="submit" ${state.supportBusy?'disabled':''}>ارسال</button></form></section>`;
  const receipt=payments.find(x=>Number(x.id)===Number(state.receiptId));
  const receiptModal=receipt?`<div class="modal-backdrop" id="portal-receipt-modal"><div class="modal-box"><div class="hub-kicker">PAYMENT RECEIPT · رسید پرداخت</div><h2>${esc(BOOT.clubName)}</h2><p class="muted">این رسید سوابق پرداخت باشگاه است و جایگزین فاکتور رسمی مالیاتی نیست.</p><div class="hub-stat"><span>ورزشکار</span><b>${esc(p.first_name||'')} ${esc(p.last_name||'')}</b></div><div class="hub-stat"><span>شماره رسید</span><b>${faNum(receipt.id)}</b></div><div class="hub-stat"><span>مبلغ</span><b>${money(receipt.amount)} ${esc(currencyLabel())}</b></div><div class="hub-stat"><span>زمان</span><b>${esc(faNum(receipt.paid_at||''))}</b></div><div class="hub-stat"><span>روش</span><b>${esc(receipt.method||'—')}</b></div><div class="hub-stat"><span>شماره پیگیری</span><b dir="ltr">${esc(receipt.reference||'—')}</b></div><div class="hub-actions"><button class="btn primary" type="button" data-act="print-receipt">چاپ رسید</button><button class="btn ghost" type="button" data-act="close-receipt">بستن</button></div></div></div>`:'';
  const rulesModal=state.rulesOpen?`<div class="modal-backdrop" id="portal-rules-modal"><div class="modal-box"><div class="hub-kicker">CLUB RULES · قوانین</div><h2>قوانین و شرایط باشگاه</h2><div class="terms-box" style="max-height:55dvh">${esc(state.data.terms_text||'قوانینی برای نمایش ثبت نشده است.')}</div><button class="btn primary full" type="button" data-act="close-rules">متوجه شدم</button></div></div>`:'';
  const selected=['home','finance','history','profile'].includes(state.hubTab)?state.hubTab:'home';
  const headings={home:['خانه','هویت و عضویت شما'],finance:['مالی','بدهی، تعرفه‌ها و جوایز'],history:['سوابق','تردد و تراکنش‌های ثبت‌شده'],profile:['پروفایل و پشتیبانی','امنیت، گفتگو و قوانین']};
  const panel=selected==='home'?`<div class="hub-grid">${hero}${qrCard}${trainingCard}${statusHtml}</div>`:selected==='finance'?`<div class="hub-grid">${financeTop}${debtCardHtml}<div class="hub-wide">${raffleCardHtml}</div><div class="hub-wide">${plansSectionHtml}</div></div>`:selected==='history'?`<div class="hub-grid"><section class="card"><div class="hub-kicker">ACCESS HISTORY</div><h2>سوابق تردد (۳۰ مورد اخیر)</h2><div class="hub-list">${visitHtml}</div></section><section class="card"><div class="hub-kicker">PAYMENTS & RECEIPTS</div><h2>پرداخت‌ها و رسیدها (۱۰ مورد اخیر)</h2><div class="hub-list">${paymentHtml}</div></section></div>`:`<div class="hub-grid">${supportHtml}<div class="hub-wide">${securityHtml}</div><section class="card hub-wide"><h2>قوانین و حریم خصوصی</h2><p class="muted">قوانین باشگاه را در پنجرهٔ مستقل بخوانید.</p><button class="btn ghost" type="button" data-act="open-rules">مشاهده قوانین باشگاه</button></section></div>`;
  root.innerHTML=header+alerts+cbBanner+`<h2 class="hub-title">${headings[selected][0]}<small>${headings[selected][1]}</small></h2>${panel}<nav class="hub-nav" aria-label="بخش‌های Athlete Hub">${[['home','⌂','خانه'],['finance','◈','مالی'],['history','◷','سوابق'],['profile','♙','پروفایل']].map(([id,icon,label])=>`<button type="button" data-act="hub-tab" data-tab="${id}" class="${selected===id?'active':''}" ${selected===id?'aria-current="page"':''}><b aria-hidden="true">${icon}</b>${label}</button>`).join('')}</nav>${modalHtml}${debtModalHtml}${rulesModal}${receiptModal}`;
  if(passwordDraft&&selected==='profile'){
    const passwordForm=root.querySelector('#portal-change-password-form');
    if(passwordForm){
      passwordForm.elements.new_password.value=passwordDraft.new;
      passwordForm.elements.confirm_password.value=passwordDraft.confirm;
      if(['portal-new-password','portal-confirm-password'].includes(passwordDraft.focus)){
        document.getElementById(passwordDraft.focus)?.focus({preventScroll:true});
      }
    }
  }
  const chat=document.getElementById('portal-support-messages');if(chat)chat.scrollTop=chat.scrollHeight;
}

document.addEventListener('click',async e=>{
  const btn=e.target.closest('[data-act]');
  if(!btn)return;
  const act=btn.dataset.act;
  state.error='';state.notice='';
  if(act==='hub-tab'){state.hubTab=btn.dataset.tab||'home';state.rulesOpen=false;state.receiptId=0;render();window.scrollTo({top:0,behavior:'smooth'});if(state.hubTab==='profile'&&state.supportItems===null)loadAthleteSupport();return}
  if(act==='refresh-support'){loadAthleteSupport();return}
  if(act==='open-rules'){state.rulesOpen=true;render();return}
  if(act==='close-rules'){state.rulesOpen=false;render();return}
  if(act==='view-receipt'){state.receiptId=Number(btn.dataset.id)||0;render();return}
  if(act==='close-receipt'){state.receiptId=0;render();return}
  if(act==='print-receipt'){window.print();return}

  if(act==='go-forgot'){const phoneInput=document.getElementById('login-phone');if(phoneInput&&phoneInput.value.trim())state.phoneDraft=phoneInput.value.trim();state.view='forgot';state.forgotStep=1;render();return}
  if(act==='otp-resend'){state.forgotStep=1;state.error='';state.notice='';render();return}
  if(act==='reauth-otp'){state.phoneDraft=String(state.data?.profile?.phone||state.phoneDraft||'');state.view='forgot';state.forgotStep=1;state.token='';state.data=null;state.supportItems=null;localStorage.removeItem(TOKEN_KEY);render();return}
  if(act==='go-login'){state.view='login';render();return}
  if(act==='logout'){localStorage.removeItem(TOKEN_KEY);state.token='';state.data=null;state.supportItems=null;state.view='login';render();return}
  if(act==='open-debt-modal'){
    const totalDebt=Number(state.data?.debt?.total_debt??state.data?.profile?.balance_due??0);
    if(totalDebt<=0)return;
    state.debtModalOpen=true;
    state.debtPercent=100;
    state.debtAmount=totalDebt;
    state.debtPayMode='percent';
    render();
    return;
  }
  if(act==='close-debt-modal'){state.debtModalOpen=false;render();return}
  if(act==='debt-pct'){
    const totalDebt=Number(state.data?.debt?.total_debt??state.data?.profile?.balance_due??0);
    const pct=Math.max(1,Math.min(100,Number(btn.dataset.pct||100)));
    state.debtPercent=pct;
    state.debtAmount=pct===100?totalDebt:Math.max(1,Math.round(totalDebt*(pct/100)));
    state.debtPayMode='percent';
    render();
    return;
  }
  if(act==='confirm-debt-pay'){
    const totalDebt=Number(state.data?.debt?.total_debt??state.data?.profile?.balance_due??0);
    if(totalDebt<=0)return;
    state.loading=true;render();
    try{
      const paymentChoice=state.debtPayMode==='amount'?{amount:state.debtAmount}:{percent:state.debtPercent};
      const res=await portalApi('/debt/checkout','POST',{...paymentChoice,gateway_id:state.selectedGatewayId||state.data.default_gateway_id||state.data.available_gateways?.[0]?.id||'',request_uuid:newUuid()});
      if(res.payment_url){window.location.href=res.payment_url;}
      else{throw new Error('نشانی درگاه پرداخت دریافت نشد.')}
    }catch(err){
      state.error=err.message;
      state.loading=false;
      render();
    }
    return;
  }
  if(act==='open-buy-modal'){
    const pid=Number(btn.dataset.planId||0);
    const pl=(state.data?.plans||[]).find(x=>Number(x.id)===pid);
    if(!pl)return;
    state.modalPlan=pl;
    state.appliedCoupon=null;
    state.couponError='';
    const usableCoupon=(state.data?.raffle?.my_coupons||state.data?.coupons||[]).find(c=>c.status==='unused');
    state.couponDraft=usableCoupon?usableCoupon.code:'';
    const hasUnexpired=!!(state.data?.has_unexpired_membership||state.data?.membership?.has_unexpired_membership);
    state.startMode=hasUnexpired?'after_current':'today';
    render();
    return;
  }
  if(act==='close-modal'){state.modalPlan=null;state.appliedCoupon=null;state.couponError='';render();return}
  if(act==='copy-coupon'){
    const code=String(btn.dataset.code||'').trim();
    if(code){
      try{if(navigator.clipboard&&navigator.clipboard.writeText)await navigator.clipboard.writeText(code);}catch(_){}
      state.couponDraft=code;
      state.notice=`کد تخفیف ${code} در حافظه کپی شد و آمادهٔ استفاده در خرید اشتراک است.`;
      render();
    }
    return;
  }
  if(act==='apply-coupon'){
    if(!state.modalPlan)return;
    const inp=document.getElementById('portal-coupon-input');
    const code=String(inp?inp.value:state.couponDraft||'').trim().toUpperCase();
    state.couponDraft=code;
    state.couponError='';
    if(!code){state.couponError='لطفاً کد تخفیف را وارد کنید.';render();return}
    state.loading=true;render();
    try{
      const res=await portalApi('/coupon/validate','POST',{
        coupon_code:code,
        plan_id:state.modalPlan.id,
        amount:state.modalPlan.price
      });
      state.appliedCoupon=res;
      state.couponDraft=res.code||code;
      state.couponError='';
    }catch(err){
      state.appliedCoupon=null;
      state.couponError=err.message;
    }finally{
      state.loading=false;
      render();
    }
    return;
  }
  if(act==='remove-coupon'){
    state.appliedCoupon=null;
    state.couponError='';
    state.couponDraft='';
    render();
    return;
  }
  if(act==='cal-prev'){
    state.calJm--;if(state.calJm<1){state.calJm=12;state.calJy--;}
    render();return;
  }
  if(act==='cal-next'){
    state.calJm++;if(state.calJm>12){state.calJm=1;state.calJy++;}
    render();return;
  }
  if(act==='pick-jday'){
    state.customDateYmd=btn.dataset.ymd;
    state.customJalaliStr=btn.dataset.jstr;
    render();return;
  }
  if(act==='confirm-checkout'){
    if(!state.modalPlan)return;
    const chk=document.getElementById('portal-terms-check');
    state.termsAccepted=chk?chk.checked:true;
    if(!state.termsAccepted){state.error='لطفاً ابتدا قوانین و شرایط باشگاه را بپذیرید.';render();return}
    const cInp=document.getElementById('portal-coupon-input');
    const typedCoupon=cInp?String(cInp.value||'').trim().toUpperCase():'';
    const finalCoupon=state.appliedCoupon?(state.appliedCoupon.code||''):typedCoupon;
    state.loading=true;render();
    try{
      const res=await portalApi('/checkout','POST',{
        plan_id:Number(state.modalPlan.id),
        start_mode:state.startMode,
        start_date:state.startMode==='custom'?(state.customJalaliStr||state.customDateYmd):'',
        coupon_code:finalCoupon,
        terms_accepted:true,
        gateway_id:state.selectedGatewayId||state.data.default_gateway_id||state.data.available_gateways?.[0]?.id||'',
        request_uuid:newUuid()
      });
      if(res.free){state.modalPlan=null;state.appliedCoupon=null;state.notice='عضویت با کد تخفیف کامل ثبت شد.';await refreshPortalData();}
      else if(res.payment_url){window.location.href=res.payment_url;}
      else{throw new Error('نشانی درگاه پرداخت دریافت نشد.')}
    }catch(err){
      state.error=err.message;
      state.loading=false;
      render();
    }
  }
});

document.addEventListener('change',e=>{
  if(e.target.id==='portal-gateway-select'){
    state.selectedGatewayId=e.target.value;
  }
  if(e.target.name==='portal_start_mode'){
    state.startMode=e.target.value;
    render();
  }
  if(e.target.id==='portal-debt-percent-input'){
    const totalDebt=Number(state.data?.debt?.total_debt??state.data?.profile?.balance_due??0);
    const pct=Math.max(1,Math.min(100,Number(e.target.value||100)));
    state.debtPercent=pct;
    state.debtAmount=pct>=100?totalDebt:Math.max(1,Math.round(totalDebt*(pct/100)));
    state.debtPayMode='percent';
    render();
  }
  if(e.target.id==='portal-debt-amount-input'){
    const totalDebt=Number(state.data?.debt?.total_debt??state.data?.profile?.balance_due??0);
    const amt=Math.max(1,Math.min(totalDebt,Math.round(Number(e.target.value||totalDebt))));
    state.debtAmount=amt;
    state.debtPercent=totalDebt>0?Math.round((amt/totalDebt)*1000)/10:100;
    state.debtPayMode='amount';
    render();
  }
  if(e.target.id==='portal-custom-jalali-input'){
    const raw=String(e.target.value||'').replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).trim();
    const m=raw.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
    if(m){
      const [gy,gm,gd]=j2g(Number(m[1]),Number(m[2]),Number(m[3]));
      state.customDateYmd=`${gy}-${pad2(gm)}-${pad2(gd)}`;
      state.customJalaliStr=`${m[1]}/${pad2(m[2])}/${pad2(m[3])}`;
      render();
    }
  }
});

async function loadAthleteSupport(){
  if(!state.token||!state.data)return;
  try{const response=await portalApi('/support');state.supportItems=Array.isArray(response.items)?response.items:[];state.error='';}
  catch(err){state.error=err.message}
  render();
}

document.addEventListener('submit',async e=>{
  if(e.target.id==='portal-support-form'){e.preventDefault();const msg=String(new FormData(e.target).get('message')||'').trim();if(!msg||state.supportBusy)return;state.supportBusy=true;try{await portalApi('/support','POST',{message:msg});await loadAthleteSupport();state.notice='پیام شما ثبت شد.'}catch(err){state.error=err.message}finally{state.supportBusy=false;render()}return}

  const f=e.target;
  if(f.id==='portal-change-password-form'){
    e.preventDefault();
    const fd=new FormData(f),new_password=String(fd.get('new_password')||''),confirm_password=String(fd.get('confirm_password')||'');
    state.loading=true;state.error='';state.notice='';render();
    try{
      const res=await portalApi('/auth/change-password','POST',{new_password,confirm_password});
      state.token=String(res.token||'');
      if(!state.token)throw new Error('نشست تازه دریافت نشد؛ دوباره وارد شوید.');
      localStorage.setItem(TOKEN_KEY,state.token);
      await refreshPortalData();
      state.notice=res.message||'رمز عبور تغییر کرد.';
    }catch(err){state.error=err.message}
    finally{state.loading=false;render()}
    return;
  }
  if(f.id==='portal-login-form'){
    e.preventDefault();
    const fd=new FormData(f);
    state.phoneDraft=String(fd.get('phone')||'').trim();
    const password=String(fd.get('password')||'').trim();
    state.loading=true;state.error='';render();
    try{
      const res=await portalApi('/auth/login','POST',{phone:state.phoneDraft,password});
      state.token=String(res.token||'');
      if(!state.token)throw new Error('نشست ورود دریافت نشد.');
      localStorage.setItem(TOKEN_KEY,state.token);
      state.supportItems=null;
      await refreshPortalData();
    }catch(err){state.error=err.message}
    finally{state.loading=false;render()}
  }
  if(f.id==='portal-forgot-req-form'){
    e.preventDefault();
    const fd=new FormData(f);
    state.phoneDraft=String(fd.get('phone')||'').trim();
    state.loading=true;state.error='';render();
    try{
      const res=await portalApi('/auth/otp-request','POST',{phone:state.phoneDraft});
      state.notice=res.message||'کد تأیید پیامک شد.';
      state.forgotStep=2;
    }catch(err){state.error=err.message}
    finally{state.loading=false;render()}
  }
  if(f.id==='portal-forgot-verify-form'){
    e.preventDefault();
    const fd=new FormData(f);
    const otp=String(fd.get('otp')||'').trim();
    state.loading=true;state.error='';render();
    try{
      const res=await portalApi('/auth/otp-verify','POST',{phone:state.phoneDraft,otp});
      state.token=String(res.token||'');
      if(!state.token)throw new Error('نشست تازه دریافت نشد؛ دوباره وارد شوید.');
      localStorage.setItem(TOKEN_KEY,state.token);
      state.supportItems=null;
      await refreshPortalData();
      state.view='portal';
       state.notice=res.message||'با کد یک‌بارمصرف وارد شدید.';
    }catch(err){state.error=err.message}
    finally{state.loading=false;render()}
  }
});

(async function boot(){
  if(state.token){
    try{
      state.loading=true;render();
      state.data=await portalApi('/me','GET');
      state.view='portal';
    }catch(err){
      localStorage.removeItem(TOKEN_KEY);
      state.token='';
      state.data=null;
    }finally{
      state.loading=false;
    }
  }
  render();
})();
</script>
</body>
</html>
HTML;
        echo str_replace('__BOOT_JSON__',(string)$boot_json,$html);
    }
}

if (function_exists('add_action')) {
    add_action('wsm_sms_event_trigger', array('WSM_Club_Operations','handle_sms_event_trigger'), 10, 3);
    add_action('rest_api_init', array('WSM_Club_Operations','register_debt_routes'), 20);
    add_action('rest_api_init', array('WSM_Club_Operations','register_finance_routes'), 20);
    add_action('rest_api_init', array('WSM_Club_Operations','register_sms_recipients_route'), 20);
    add_action('rest_api_init', array('WSM_Club_Operations','register_athlete_portal_routes'), 20);
    add_action('woocommerce_payment_complete', array('WSM_Club_Operations','athlete_portal_woocommerce_order_paid'), 20, 1);
    add_action('woocommerce_order_status_processing', array('WSM_Club_Operations','athlete_portal_woocommerce_order_paid'), 20, 2);
    add_action('woocommerce_order_status_completed', array('WSM_Club_Operations','athlete_portal_woocommerce_order_paid'), 20, 2);
    add_filter('woocommerce_get_return_url', array('WSM_Club_Operations','athlete_portal_woocommerce_return_url'), 99, 2);
    add_action('init', array('WSM_Club_Operations','register_athlete_portal_rewrite'), 10);
    add_action('template_redirect', array('WSM_Club_Operations','maybe_render_athlete_portal_page'), 1);
    add_action('wsm_club_daily_tasks', array('WSM_Club_Operations','maybe_auto_draw_due_raffles'), 20);
    add_action('admin_post_wsm_create_backup', array('WSM_Club_Operations','handle_create_backup_request'));
    add_action('admin_post_wsm_test_backup_path', array('WSM_Club_Operations','handle_test_backup_path_request'));
    add_action('admin_post_wsm_delete_backup', array('WSM_Club_Operations','handle_delete_backup_request'));
    add_action('admin_post_wsm_download_backup', array('WSM_Club_Operations','handle_download_backup_request'));
    add_action('admin_post_wsm_preview_backup', array('WSM_Club_Operations','handle_backup_preview_request'));
    add_action('admin_post_wsm_describe_backup', array('WSM_Club_Operations', 'handle_backup_describe_request'));
    add_action('admin_post_wsm_apply_backup', array('WSM_Club_Operations', 'handle_backup_apply_request'));
    add_action('init', array('WSM_Club_Operations','ensure_backup_log_table'), 4);
    add_action('init', array('WSM_Club_Operations','ensure_financial_core_for_init'), 5);
    add_action('init', array('WSM_Club_Operations','sync_backup_schedule'), 6);
    add_action('wsm_scheduled_backup', array('WSM_Club_Operations','run_scheduled_backup'));
}
if (function_exists('add_filter')) {
    add_filter('cron_schedules', array('WSM_Club_Operations','backup_cron_schedules'));
    add_filter('query_vars', array('WSM_Club_Operations','register_athlete_portal_query_vars'));
    add_filter('rest_allowed_cors_headers', array('WSM_Club_Operations','allow_athlete_portal_cors_headers'), 20);
}
