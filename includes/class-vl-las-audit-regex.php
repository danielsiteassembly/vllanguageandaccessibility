<?php
/**
 * Regex-only WCAG-ish audit (safe, no DOM).
 *
 * @package VL_LAS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VL_LAS_Audit_Regex {

    /**
     * Run audit against an HTML string (preferred) or a fetched URL fallback.
     *
     * @param string $html Raw HTML (optional but recommended).
     * @param string $url  For metadata; not fetched here.
     * @return array Report payload
     */
    public static function run( $html, $url ) {
        $now  = current_time( 'mysql', 1 ); // GMT
        $out  = array(
            'ok'      => true,
            'engine'  => 'regex',
            'ts'      => $now,
            'url'     => esc_url_raw( $url ?: home_url('/') ),
            'summary' => array(),
            'checks'  => array(),
        );

        if ( ! is_string( $html ) ) {
            $html = '';
        }

        // Basic helpers
        $has = function( $pattern ) use ( $html ) {
            return (bool) preg_match( $pattern, $html );
        };
        $count = function( $pattern ) use ( $html ) {
            return preg_match_all( $pattern, $html, $m );
        };

        // --- Individual checks (boolean + details where relevant) ---

        // Title present
        self::push( $out, 'document_title', array(
            'ok'   => $has( '/<title\b[^>]*>.*?<\/title>/is' ),
            'why'  => 'Ensure Document has a <title> element',
        ) );

        // <html lang="..">
        self::push( $out, 'html_lang', array(
            'ok'   => $has( '/<html\b[^>]*\blang=[\'"][^\'"]+[\'"][^>]*>/i' ),
            'why'  => 'Ensure <html> element has [lang] attribute',
        ) );

        // Images have alt (flags images missing alt)
        $img_total = $count( '/<img\b[^>]*>/i' );
        $img_noalt = $count( '/<img\b(?![^>]*\balt=)[^>]*>/i' );
        self::push( $out, 'img_alt', array(
            'ok'      => ($img_total === 0) ? true : ($img_noalt === 0),
            'why'     => 'Ensure image elements have [alt] attributes',
            'metrics' => compact( 'img_total', 'img_noalt' ),
        ) );

        // Buttons have accessible name (text or aria-label)
        $btn_total = $count( '/<button\b[^>]*>.*?<\/button>/is' ) + $count( '/<input\b[^>]*\btype=[\'"](button|submit|reset)[\'"][^>]*>/i' );
        $btn_bad   = $count( '/<button\b(?:(?!aria-label).)*?>\s*<\/button>/is' ) + $count( '/<input\b[^>]*\btype=[\'"](button|submit|reset)[\'"][^>]*?(?!(value|aria-label)=)[^>]*>/i' );
        self::push( $out, 'buttons_accessible_name', array(
            'ok'      => ($btn_total === 0) ? true : ($btn_bad === 0),
            'why'     => 'Ensure buttons have an accessible name',
            'metrics' => compact( 'btn_total', 'btn_bad' ),
        ) );

        // Links have discernable names (text, img alt, or aria-label)
        $a_total = $count( '/<a\b[^>]*>/i' );
        $a_bad_text = $count( '/<a\b[^>]*>(?:\s|&nbsp;|<\!--.*?-->)*<\/a>/is' ); // empty anchors
        $a_bad_aria = $count( '/<a\b(?![^>]*aria-label=)[^>]*>(?:\s|&nbsp;|<img\b(?![^>]*alt=)[^>]*>)*<\/a>/is' );
        self::push( $out, 'links_discernable', array(
            'ok'      => ($a_total === 0) ? true : ($a_bad_text + $a_bad_aria === 0),
            'why'     => 'Ensure links have discernable names',
            'metrics' => array( 'a_total' => $a_total, 'a_bad_empty' => $a_bad_text, 'a_bad_missing_label' => $a_bad_aria ),
        ) );

        // Meta viewport anti-patterns
        $bad_viewport = $has( '/<meta\b[^>]*name=[\'"]viewport[\'"][^>]*\b(user-scalable\s*=\s*no|maximum-scale\s*=\s*(?:[0-4](?:\.\d+)?|[01]))/i' );
        self::push( $out, 'viewport_scaling', array(
            'ok'  => ! $bad_viewport,
            'why' => 'Ensure [user-scalable="no"] is not used and [maximum-scale] is not less than 5',
        ) );

        // Basic ARIA validity hints (very light regex sanity)
        $bad_aria_misspell = $has( '/\baria-[a-z0-9-]*[A-Z][a-zA-Z0-9-]*=/i' ); // uppercase inside aria-* is suspicious
        self::push( $out, 'aria_attributes_valid', array(
            'ok'  => ! $bad_aria_misspell,
            'why' => 'Ensure [aria-*] attributes are valid and not misspelled',
        ) );

        // Headings descending (heuristic)
        $headings = array();
        if ( preg_match_all( '/<(h[1-6])\b[^>]*>.*?<\/\1>/is', $html, $m ) ) {
            foreach ( $m[1] as $h ) {
                $headings[] = (int) substr( $h, 1 );
            }
        }
        $non_descending = 0;
        if ( $headings ) {
            $prev = $headings[0];
            foreach ( $headings as $idx => $lev ) {
                if ( $idx === 0 ) continue;
                if ( $lev - $prev > 1 ) $non_descending++;
                $prev = $lev;
            }
        }
        self::push( $out, 'headings_sequential', array(
            'ok'      => ($headings ? $non_descending === 0 : true),
            'why'     => 'Ensure heading elements are in sequentially-descending order',
            'metrics' => array( 'headings' => $headings, 'breaks' => $non_descending ),
        ) );

        // Touch target heuristic (can’t measure dimensions w/o layout; just flag presence of tiny <a> with 1 char)
        $tiny_links = $count( '/<a\b[^>]*>[\s\S]{0,1}<\/a>/i' );
        self::push( $out, 'touch_target_size_hint', array(
            'ok'      => ($tiny_links === 0),
            'why'     => 'Ensure touch targets have sufficient size and spacing (heuristic)',
            'metrics' => array( 'tiny_links' => $tiny_links ),
        ) );

        // Lists contain only <li> (basic)
        $bad_ul = $has( '/<ul\b[^>]*>(?:(?!<\/ul>).)*<(?!li\b|\/li\b|script\b|template\b)[a-z]/is' );
        $bad_ol = $has( '/<ol\b[^>]*>(?:(?!<\/ol>).)*<(?!li\b|\/li\b|script\b|template\b)[a-z]/is' );
        self::push( $out, 'lists_only_li', array(
            'ok'  => ! ($bad_ul || $bad_ol),
            'why' => 'Ensure Lists contain only <li> elements and script/template',
        ) );

        // Final summary
        $total  = count( $out['checks'] );
        $passed = count( array_filter( $out['checks'], function( $c ){ return ! empty( $c['ok'] ); } ) );
        $out['summary'] = array(
            'passed' => $passed,
            'total'  => $total,
            'score'  => $total ? round( ( $passed / $total ) * 100 ) : 100,
        );

        return $out;
    }

    private static function push( array &$out, $id, array $data ) {
        $data['id'] = (string) $id;
        $out['checks'][] = $data;
    }
}
