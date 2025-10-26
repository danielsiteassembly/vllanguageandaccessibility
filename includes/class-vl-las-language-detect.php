<?php
if ( ! defined('ABSPATH') ) exit;

class VL_LAS_Language_Detect {

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_capture_param'));     // URL param -> cookie
        add_action('init', array(__CLASS__, 'maybe_set_body_class'));    // add body class like vl-lang-es
        add_shortcode('vl_current_language', array(__CLASS__, 'shortcode_current_language'));
    }

    /**
     * Read current language with precedence:
     * 1) URL param (?vl_lang=es or ?vl_lang=Spanish)
     * 2) Cookie (vl_lang)
     * 3) Browser Accept-Language
     * 4) Site default (en)
     */
    public static function current() {
        // 1) URL param (not sanitizing to code/label yet; we normalize below)
        if ( isset($_GET['vl_lang']) && $_GET['vl_lang'] !== '' ) {
            $lang = self::normalize($_GET['vl_lang']);
            if ( $lang ) return $lang;
        }
        // 2) Cookie
        if ( isset($_COOKIE['vl_lang']) && $_COOKIE['vl_lang'] !== '' ) {
            $lang = self::normalize($_COOKIE['vl_lang']);
            if ( $lang ) return $lang;
        }
        // 3) Browser Accept-Language (only if enabled in settings)
        $detect_enabled = (int) get_option( 'vl_las_lang_detect', 0 ); // default ON for convenience; set to 0 to disable
        if ( $detect_enabled === 1 ) {
            $header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? sanitize_text_field($_SERVER['HTTP_ACCEPT_LANGUAGE']) : '';
            if ( $header ) {
                $code = strtolower( substr($header, 0, 2) ); // quick/typical match
                $lang = self::code_to_label($code);
                if ( $lang ) return $lang;
            }
        }
        // 4) Default
        return 'English';
    }

    /**
     * Capture ?vl_lang param and set cookie for 1 year
     */
    public static function maybe_capture_param() {
        if ( isset($_GET['vl_lang']) && $_GET['vl_lang'] !== '' ) {
            $lang = self::normalize($_GET['vl_lang']);
            if ( $lang ) {
                // set cookie (HTTP only path=/)
                setcookie('vl_lang', $lang, time()+365*24*60*60, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false);
                // also set in $_COOKIE for immediate availability
                $_COOKIE['vl_lang'] = $lang;
            }
        }
    }

    /**
     * Optional: add body class 'vl-lang-xx' for CSS targeting if you want
     */
    public static function maybe_set_body_class() {
        add_filter('body_class', function($classes){
            $label = self::current();
            $code  = self::label_to_code($label);
            if ( $code ) $classes[] = 'vl-lang-' . $code;
            return $classes;
        });
    }

    /**
     * Shortcode: [vl_current_language] -> prints "Spanish" (label)
     */
    public static function shortcode_current_language() {
        return esc_html( self::current() );
    }

    // --- helpers ---

    /**
     * Map language label <-> code. Keep in sync with your settings list.
     */
    public static function map() {
        return array(
            'en' => 'English',
            'es' => 'Spanish',
            'ar' => 'Arabic',
            'ru' => 'Russian',
            'vi' => 'Vietnamese',
            'tl' => 'Tagalog',
            'de' => 'German',
            'fr' => 'French',
            'zh' => 'Chinese',   // generic Chinese
            'zh-hans' => 'Mandarin',
            'zh-hant' => 'Cantonese',
            'pt' => 'Portuguese',
            'ja' => 'Japanese',
            'te' => 'Telugu',
            'pl' => 'Polish',
            'it' => 'Italian',
            'hi' => 'Hindi',
            'bn' => 'Bengali',
            'ur' => 'Urdu',
        );
    }

    public static function label_to_code($label) {
        $label = trim( strtolower( (string) $label ) );
        if ( $label === '' ) return '';
        $map = self::map(); // code => label
        // exact match by label
        foreach ( $map as $code => $lab ) {
            if ( strtolower($lab) === $label ) return $code;
        }
        // if they passed a code already
        if ( isset($map[$label]) ) return $label;
        return '';
    }

    public static function code_to_label($code) {
        $code = trim( strtolower( (string) $code ) );
        if ( $code === '' ) return '';
        $map = self::map();
        // normalize zh variants
        if ( $code === 'zh' || $code === 'zh-cn' ) return $map['zh'];      // generic Chinese
        if ( $code === 'zh-hans' ) return $map['zh-hans'];                  // Mandarin
        if ( $code === 'zh-hant' || $code === 'zh-tw' || $code === 'yue' ) return $map['zh-hant']; // Cantonese
        return isset($map[$code]) ? $map[$code] : '';
    }

    /**
     * Accept either label or code, return normalized label we use everywhere.
     */
    public static function normalize($val) {
        $val = trim( (string) $val );
        if ( $val === '' ) return '';
        // If it's a code, convert to label
        $label = self::code_to_label($val);
        if ( $label ) return $label;
        // If it's a label, standardize capitalization
        $code = self::label_to_code($val);
        if ( $code ) return self::map()[$code];
        return '';
    }
}

VL_LAS_Language_Detect::init();
