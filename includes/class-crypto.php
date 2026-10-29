<?php
/**
 * رمزنگاری متقارن برای اسرار ذخیره‌شده (کلید امضای دستگاه، کلید تازه‌ساخته‌شده، رمز 2FA).
 * الگوریتم: AES-256-GCM با IV تصادفی ۱۲ بایتی و تگ ۱۶ بایتی. کلید از wp_salt('auth') مشتق می‌شود.
 * قالب خروجی: v1:base64(iv . tag . ciphertext)
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Crypto {

    const CIPHER  = 'aes-256-gcm';
    const VERSION = 'v1:';

    public static function available() {
        return function_exists('openssl_encrypt') && function_exists('openssl_decrypt')
            && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    private static function key() {
        return hash('sha256', wp_salt('auth') . '|wsm-secret-v1', true);
    }

    /**
     * @return string|false
     */
    public static function encrypt($plain) {
        if (!self::available()) {
            return false;
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt((string) $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false || strlen($tag) !== 16) {
            return false;
        }
        return self::VERSION . base64_encode($iv . $tag . $ct);
    }

    /**
     * @return string|false
     */
    public static function decrypt($encoded) {
        if (!self::available() || !is_string($encoded) || strpos($encoded, self::VERSION) !== 0) {
            return false;
        }
        $raw = base64_decode(substr($encoded, strlen(self::VERSION)), true);
        if ($raw === false || strlen($raw) < 29) {
            return false;
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? false : $pt;
    }
}

