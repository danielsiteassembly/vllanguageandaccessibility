<?php
/**
 * Accessibility audit logic.
 * Provides two paths:
 *  - run_audit( $url ): server fetch then audit
 *  - run_audit_html( $html ): audit provided HTML (fallback for loopback-blocked hosts)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VL_LAS_Accessibility_Audit {

    /**
     * Server fetch + audit.
     */
    public function run_audit( $url ) {
        $url = esc_url_raw( $url );
        if ( empty( $url ) ) {
            return array( 'ok' => false, 'error' => 'Empty URL' );
        }

        $args = array(
            'timeout'     => 20,
            'redirection' => 3,
            'sslverify'   => false,
            'headers'     => array( 'User-Agent' => 'VL-LAS/1.0 (+wordpress)' ),
        );

        $probe = add_query_arg( 'vl_las_probe', time(), $url );
        $resp  = wp_remote_get( $probe, $args );

        if ( is_wp_error( $resp ) ) {
            return array(
                'ok'    => false,
                'error' => $resp->get_error_message(),
                'code'  => $resp->get_error_code(),
            );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( (int) $code >= 400 || (int) $code === 0 ) {
            return array( 'ok' => false, 'error' => 'HTTP status ' . $code, 'status' => $code );
        }

        $html = wp_remote_retrieve_body( $resp );
        if ( empty( $html ) ) {
            return array( 'ok' => false, 'error' => 'Empty HTML' );
        }

        return $this->audit_html_internal( $html );
    }

    /**
     * HTML-only audit (preferred for reliability).
     */
    public function run_audit_html( $html ) {
        if ( ! is_string( $html ) || $html === '' ) {
            return array( 'ok' => false, 'error' => 'Empty HTML input' );
        }
        return $this->audit_html_internal( $html );
    }

    /**
     * Internal: DOM-first, then regex fallback.
     */
    private function audit_html_internal( $html ) {
        // DOM path
        if ( class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' ) ) {
            try {
                $dom = new DOMDocument();
                libxml_use_internal_errors( true );
                $dom->loadHTML( $html );
                libxml_clear_errors();
                $xpath  = new DOMXPath( $dom );
                $report = $this->evaluate_dom( $xpath );
                return array( 'ok' => true, 'report' => $report );
            } catch ( \Throwable $t ) {
                // fall through to regex
            }
        }

        // Regex path
        $report = $this->evaluate_regex( $html );
        return array( 'ok' => true, 'report' => $report );
    }

    /**
     * Evaluate using DOMXPath.
     */
    private function evaluate_dom( \DOMXPath $xpath ) {
        $report = array(); $pass = array(); $fail = array();

        // <title>
        $has_title = $xpath->query( '//title' )->length > 0;
        $report['document_title'] = $has_title;
        ( $has_title ? $pass : $fail )[] = 'Document has a <title> element';

        // html[lang]
        $html_el  = $xpath->query( '/html' )->item( 0 );
        $has_lang = $html_el && $html_el->hasAttribute( 'lang' ) && strlen( trim( $html_el->getAttribute( 'lang' ) ) ) > 0;
        $report['html_lang'] = $has_lang;
        ( $has_lang ? $pass : $fail )[] = 'HTML element has [lang] attribute';

        // img[alt]
        $imgs = $xpath->query( '//img[not(@alt) or normalize-space(@alt)=""]' );
        $report['images_alt'] = ( $imgs->length === 0 );
        ( ( $imgs->length === 0 ) ? $pass : $fail )[] = 'Image elements have [alt] attributes';

        // Buttons/inputs have accessible names
        $btns = $xpath->query( '//button|//input[@type="button" or @type="submit" or @type="image"]' );
        $btn_fail = 0;
        foreach ( $btns as $b ) {
            $has_text  = trim( $b->textContent ) !== '';
            $has_label = $b->hasAttribute( 'aria-label' ) || $b->hasAttribute( 'title' ) || $b->hasAttribute( 'alt' ) || $b->hasAttribute( 'value' );
            if ( ! $has_text && ! $has_label ) { $btn_fail++; }
        }
        $report['buttons_named'] = ( $btn_fail === 0 );
        ( ( $btn_fail === 0 ) ? $pass : $fail )[] = 'Buttons/inputs have accessible names';

        // Heading order
        $headings = array();
        for ( $i = 1; $i <= 6; $i++ ) {
            $nodes = $xpath->query( '//h' . $i );
            foreach ( $nodes as $n ) { $headings[] = $i; }
        }
        $seq_ok = true; $last = 0;
        foreach ( $headings as $level ) {
            if ( $last > 0 && $level > $last + 1 ) { $seq_ok = false; break; }
            $last = $level;
        }
        $report['headings_sequential'] = $seq_ok;
        ( $seq_ok ? $pass : $fail )[] = 'Heading elements are in sequential order';

        // Links named
        $links = $xpath->query( '//a' );
        $link_fail = 0;
        foreach ( $links as $a ) {
            $text = trim( $a->textContent );
            if ( $text === '' && ! $a->hasAttribute( 'aria-label' ) && ! $a->hasAttribute( 'title' ) ) { $link_fail++; }
        }
        $report['links_named'] = ( $link_fail === 0 );
        ( ( $link_fail === 0 ) ? $pass : $fail )[] = 'Links have discernible names';

        // Form labels
        $inputs = $xpath->query( '//input|//select|//textarea' );
        $form_fail = 0;
        foreach ( $inputs as $i ) {
            $id = $i->getAttribute( 'id' );
            $label = null;
            if ( $id ) {
                $nodes = $xpath->query( '//label[@for="'.$id.'"]' );
                if ( $nodes->length > 0 ) { $label = $nodes->item(0); }
            }
            if ( ! $label && ! $i->hasAttribute( 'aria-label' ) && ! $i->hasAttribute( 'aria-labelledby' ) ) { $form_fail++; }
        }
        $report['form_labels'] = ( $form_fail === 0 );
        ( ( $form_fail === 0 ) ? $pass : $fail )[] = 'Form elements have associated labels';

        // Notes
        $report['contrast_check'] = 'manual';
        $report['summary'] = array(
            'pass'  => $pass,
            'fail'  => $fail,
            'notes' => array(
                'Contrast ratios and touch target sizes require client-side evaluation.'
            )
        );

        return $report;
    }

    /**
     * Evaluate using regex heuristics.
     */
    private function evaluate_regex( $html ) {
        $report = array(); $pass = array(); $fail = array();

        // <title>
        $has_title = (bool) preg_match('/<title\\b[^>]*>.*?<\\/title>/is', $html);
        $report['document_title'] = $has_title;
        ( $has_title ? $pass : $fail )[] = 'Document has a <title> element';

        // html[lang]
        $has_lang = (bool) preg_match('/<html\\b[^>]*\\blang=[\'"][^\'"]+[\'"][^>]*>/i', $html);
        $report['html_lang'] = $has_lang;
        ( $has_lang ? $pass : $fail )[] = 'HTML element has [lang] attribute';

        // img alt
        $total_imgs   = preg_match_all('/<img\\b[^>]*>/i', $html);
        $imgs_withalt = preg_match_all('/<img\\b[^>]*\\balt=[\'"][^\'"]*[\'"][^>]*>/i', $html);
        $imgs_missing = $total_imgs - $imgs_withalt;
        $report['images_alt'] = ( $imgs_missing <= 0 );
        ( ( $imgs_missing <= 0 ) ? $pass : $fail )[] = 'Image elements have [alt] attributes';

        // Buttons named
        $btns       = preg_match_all('/<(?:button\\b[^>]*>|input\\b[^>]*type=(?:[\'"](button|submit|image)[\'"]).*?>)/i', $html);
        $btns_named = preg_match_all('/<button\\b[^>]*>(\\s*[^<][\\s\\S]*?)<\\/button>|<input\\b[^>]*(?:aria-label|title|alt|value)=/i', $html);
        $report['buttons_named'] = ( $btns === 0 || $btns_named >= $btns );
        ( $report['buttons_named'] ? $pass : $fail )[] = 'Buttons/inputs have accessible names';

        // Heading order heuristic
        $seq_ok = true; $last = 0;
        if ( preg_match_all('/<h([1-6])\\b[^>]*>/i', $html, $m) ) {
            foreach ( $m[1] as $lvl ) {
                $lvl = intval( $lvl );
                if ( $last > 0 && $lvl > $last + 1 ) { $seq_ok = false; break; }
                $last = $lvl;
            }
        }
        $report['headings_sequential'] = $seq_ok;
        ( $seq_ok ? $pass : $fail )[] = 'Heading elements are in sequential order';

        // Links named
        $links_total = preg_match_all('/<a\\b[^>]*>/i', $html);
        $links_named = preg_match_all('/<a\\b[^>]*?(?:aria-label|title)=[\'"][^\'"]+[\'"][^>]*>.*?<\\/a>|<a\\b[^>]*>\\s*[^<\\s].*?<\\/a>/is', $html);
        $report['links_named'] = ( $links_total === 0 || $links_named >= $links_total );
        ( $report['links_named'] ? $pass : $fail )[] = 'Links have discernible names';

        // Form labels (heuristic)
        $inputs     = preg_match_all('/<(input|select|textarea)\\b[^>]*>/i', $html);
        $labels_for = preg_match_all('/<label\\b[^>]*for=[\'"][^\'"]+[\'"][^>]*>/i', $html);
        $report['form_labels'] = ( $inputs === 0 || $labels_for >= $inputs );
        ( $report['form_labels'] ? $pass : $fail )[] = 'Form elements have associated labels';

        $report['contrast_check'] = 'manual';
        $report['summary'] = array(
            'pass'  => $pass,
            'fail'  => $fail,
            'notes' => array('Regex fallback used; contrast/touch targets require client-side checks.')
        );

        return $report;
    }
}
