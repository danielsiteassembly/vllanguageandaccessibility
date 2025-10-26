<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * VL_LAS_Translate
 * - Uses Google Gemini 2.5 (generateContent) to translate short strings/snippets.
 * - Caches results in transients (7 days) to avoid repeat calls.
 * - Never fatal: on any error, returns the original $text.
 *
 * NOTE: This only translates the INPUT string. It does not rewrite the page.
 */
class VL_LAS_Translate {

    const MODEL = 'gemini-2.5-flash';   // fast, lower-cost; change to -pro if you prefer
    const TTL   = 7 * DAY_IN_SECONDS;   // cache time

    /**
     * Translate $text to the current visitor's language (from detector),
     * or to an explicit target label/code (optional $target_label_or_code).
     *
     * @param string $text
     * @param string|null $target_label_or_code  e.g. 'Spanish' or 'es'
     * @return string translated or original
     */
    public static function maybe( $text, $target_label_or_code = null ) {
        $text = (string) $text;
        if ( $text === '' ) return $text;

        // If no Gemini key or translation disabled, bail with original.
        $enabled = (int) get_option( 'vl_las_translate_enable', 0 );
        $api_key = trim( (string) get_option( 'vl_las_gemini_api_key', '' ) );
        if ( $enabled !== 1 || $api_key === '' ) return $text;

        // Figure target language label + 2-letter code
        if ( ! class_exists('VL_LAS_Language_Detect') ) return $text;

        // Manual override wins; else use current() detection
        $label = $target_label_or_code ? $target_label_or_code : VL_LAS_Language_Detect::current();
        $target_code  = VL_LAS_Language_Detect::label_to_code( $label );
        $target_label = $target_code ? VL_LAS_Language_Detect::map()[$target_code] : $label;

        if ( ! $target_code ) return $text;            // unknown target
        if ( $target_code === 'en' ) return $text;     // English requested → no translation

        // Cache key
        $src_fingerprint = md5( 'v1|' . self::MODEL . '|' . $target_code . '|' . $text );
        $cache_key = 'vl_t_' . $src_fingerprint;
        $cached = get_transient( $cache_key );
        if ( is_string( $cached ) && $cached !== '' ) {
            return $cached;
        }

        // Keep requests small & predictable
        $max_len = 6000; // chars, conservative for prompt+HTML safety
        $original = $text;
        $truncated = false;
        if ( function_exists('mb_strlen') ? mb_strlen($text) > $max_len : strlen($text) > $max_len ) {
            $text = function_exists('mb_substr') ? mb_substr($text, 0, $max_len) : substr($text, 0, $max_len);
            $truncated = true;
        }

        // Build a careful prompt: preserve markup, don’t rewrite meaning.
        $prompt = self::build_prompt( $text, $target_label );

        // Call Gemini
        $resp = self::call_gemini( $prompt, $api_key );
        $translation = is_string($resp) ? trim($resp) : '';

        // Fallbacks
        if ( $translation === '' ) {
            // Don’t cache empty; just return original
            return $original;
        }

        if ( $truncated ) {
            $translation .= ' …'; // indicate truncation subtly
        }

        // Cache & return
        set_transient( $cache_key, $translation, self::TTL );
        return $translation;
    }

    /**
     * Shortcode handler: [vl_t]Hello[/vl_t] or [vl_t lang="es"]Hello[/vl_t]
     * Usage in blocks is safe; HTML allowed in content and preserved by the prompt.
     */
    public static function shortcode( $atts = array(), $content = '' ) {
        $a = shortcode_atts( array(
            'lang' => '', // label or code; optional
        ), $atts, 'vl_t' );

        // Do not run autop here; content is raw inner content of shortcode
        $text = do_shortcode( $content ); // allow nesting, but keep it lean
        $target = $a['lang'] ? $a['lang'] : null;
        return self::maybe( $text, $target );
    }

    private static function build_prompt( $text, $target_label ) {
        // Keep system-like instruction first for consistent behavior
        $instructions =
'You are a precise translator. Translate the user content into TARGET_LANGUAGE.
- Preserve all HTML tags, entities, and basic formatting; translate only human-visible text.
- Do not add or remove links, attributes, or classes.
- Keep proper nouns and brand names as-is unless there is a widely accepted localized form.
- Return ONLY the translated content (no commentary, no code fences).';

        // We provide a very short marker for the target to avoid ambiguity
        $parts = array(
            array('text' => $instructions),
            array('text' => 'TARGET_LANGUAGE: ' . $target_label),
            array('text' => "CONTENT:\n" . $text),
        );

        return array('contents' => array(array('parts' => $parts)));
    }

    /**
     * Call Google Generative Language API.
     * Returns the text output (string) or '' on error.
     */
    private static function call_gemini( $payload, $api_key ) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(self::MODEL) . ':generateContent?key=' . rawurlencode($api_key);

        $args = array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        );
        $r = wp_remote_post( $url, $args );
        if ( is_wp_error( $r ) ) return '';

        $code = wp_remote_retrieve_response_code( $r );
        if ( (int) $code !== 200 ) return '';

        $data = json_decode( wp_remote_retrieve_body( $r ), true );
        // Gemini format → text lives in candidates[0].content.parts[*].text
        if ( ! isset( $data['candidates'][0]['content']['parts'] ) ) return '';

        $out = '';
        foreach ( (array) $data['candidates'][0]['content']['parts'] as $p ) {
            if ( isset($p['text']) ) $out .= $p['text'];
        }
        return $out;
    }

    /**
     * Register shortcode and (optionally) auto-translation aliases for legal docs.
     */
    public static function init() {
        add_shortcode( 'vl_t', array(__CLASS__, 'shortcode') );

        // Optional translated legal doc shortcodes (don’t replace your originals)
        add_shortcode( 'vl_privacy_policy_t', function(){
            $raw = (string) get_option( 'vl_las_legal_privacy_policy', '' );
            return VL_LAS_Translate::maybe( $raw );
        });
        add_shortcode( 'vl_terms_t', function(){
            $raw = (string) get_option( 'vl_las_legal_terms', '' );
            return VL_LAS_Translate::maybe( $raw );
        });
        add_shortcode( 'vl_copyright_t', function(){
            $raw = (string) get_option( 'vl_las_legal_copyright', '' );
            return VL_LAS_Translate::maybe( $raw );
        });
        add_shortcode( 'vl_data_privacy_t', function(){
            $raw = (string) get_option( 'vl_las_legal_data_privacy', '' );
            return VL_LAS_Translate::maybe( $raw );
        });
        add_shortcode( 'vl_cookie_t', function(){
            $raw = (string) get_option( 'vl_las_legal_cookie', '' );
            return VL_LAS_Translate::maybe( $raw );
        });
    }
}
VL_LAS_Translate::init();
