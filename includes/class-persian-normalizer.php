<?php
/**
 * نرمال‌سازی متن فارسی و جستجوی فازی (با تحمل غلط املایی)
 * سازگار با PHP 7.4 — بدون ویژگی‌های PHP 8+
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSM_Persian_Normalizer {

    /** @var array<string,string> */
    private static $map = array(
        "۰" => "0", "۱" => "1", "۲" => "2", "۳" => "3", "۴" => "4",
        "۵" => "5", "۶" => "6", "۷" => "7", "۸" => "8", "۹" => "9",
        "٠" => "0", "١" => "1", "٢" => "2", "٣" => "3", "٤" => "4",
        "٥" => "5", "٦" => "6", "٧" => "7", "٨" => "8", "٩" => "9",
        "ي" => "ی", "ى" => "ی", "ك" => "ک", "ۀ" => "ه", "ة" => "ه",
        "أ" => "ا", "إ" => "ا", "آ" => "ا", "ؤ" => "و", "ئ" => "ی",
        "‌" => " ", "ـ" => "",
    );

    private static function lower($text) {
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    private static function chars($text) {
        if (function_exists('mb_str_split')) {
            return mb_str_split($text, 1, 'UTF-8');
        }
        $parts = preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? $parts : str_split((string) $text);
    }

    private static function length($text) {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : count(self::chars($text));
    }

    /**
     * متن را برای مقایسه آماده می‌کند.
     */
    public static function normalize($text) {
        $text = (string) $text;
        $text = strtr($text, self::$map);
        // حرکات عربی
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        $text = self::lower($text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * فاصلهٔ لونشتاین با پشتیبانی UTF-8
     */
    public static function levenshtein_utf8($a, $b) {
        $a = self::chars($a);
        $b = self::chars($b);
        $la = count($a);
        $lb = count($b);
        if ($la === 0) {
            return $lb;
        }
        if ($lb === 0) {
            return $la;
        }
        $prev = range(0, $lb);
        for ($i = 1; $i <= $la; $i++) {
            $cur = array($i);
            for ($j = 1; $j <= $lb; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }
        return $prev[$lb];
    }

    /**
     * امتیاز تطابق بین ۰ تا ۱
     */
    public static function score($needle, $haystack) {
        $n = self::normalize($needle);
        $h = self::normalize($haystack);
        if ($n === '') {
            return 1.0;
        }
        if ($h === '') {
            return 0.0;
        }
        if (strpos($h, $n) !== false) {
            return 1.0;
        }

        $queryWords = array_values(array_filter(explode(' ', $n), 'strlen'));
        $textWords  = array_values(array_filter(explode(' ', $h), 'strlen'));
        $total = 0.0;

        foreach ($queryWords as $qw) {
            $qlen = self::length($qw);
            $maxDistance = $qlen <= 3 ? 0 : ($qlen <= 6 ? 1 : 2);
            $wordBest = 0.0;
            foreach ($textWords as $tw) {
                if (strpos($tw, $qw) === 0) {
                    $s = 1.0;
                } else {
                    $d = self::levenshtein_utf8($qw, $tw);
                    if ($d > $maxDistance) {
                        continue;
                    }
                    $s = 1 - ($d / max($qlen, self::length($tw)));
                }
                if ($s > $wordBest) {
                    $wordBest = $s;
                }
            }
            $total += $wordBest;
        }

        return count($queryWords) ? $total / count($queryWords) : 0.0;
    }

    /**
     * آیا متن با عبارت جستجو تطابق دارد؟ (آستانه ۰.۷)
     */
    public static function matches($needle, $haystack) {
        return self::score($needle, $haystack) >= 0.7;
    }
}

