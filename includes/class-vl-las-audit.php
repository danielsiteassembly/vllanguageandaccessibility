<?php
if ( ! defined('ABSPATH') ) exit;

class VL_LAS_Audit {

    // caps
    const MAX_BYTES = 1500000; // 1.5MB
    const TIME_BUDGET_MS = 400;

    public static function run_regex( $html_raw, $url = '' ) {
        $t0 = microtime(true);

        if ( ! is_string($html_raw) || $html_raw === '' ) {
            return array('ok'=>false, 'error'=>'Empty HTML');
        }

        $html_len = strlen($html_raw);

        // Strip heavy blocks
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html_raw);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is',  '', $html);
        $html = preg_replace('#<(template|noscript)\b[^>]*>.*?</\1>#is', '', $html);

        // Truncate safely if huge
        $truncated = false;
        if ( strlen($html) > self::MAX_BYTES ) {
            $html = substr($html, 0, self::MAX_BYTES);
            $truncated = true;
        }

        // Quick helpers
        $elapsed = function() use ($t0){ return (microtime(true)-$t0)*1000.0; };
        $timed_out = false;
        $maybe_timeout = function() use (&$timed_out, $elapsed){ if ($elapsed() > VL_LAS_Audit::TIME_BUDGET_MS) { $timed_out = true; return true; } return false; };

        $report = array();
        $pass = array(); $fail = array(); $notes = array();

        // Document title
        $has_title = (bool) preg_match('/<title\b[^>]*>.*?<\/title>/is', $html);
        $report['document_title'] = $has_title;
        ($has_title ? $pass : $fail)[] = 'Document has a <title> element';

        // html[lang]
        $has_lang = (bool) preg_match('/<html\b[^>]*\blang=[\'"][^\'"]+[\'"][^>]*>/i', $html);
        $report['html_lang'] = $has_lang;
        ($has_lang ? $pass : $fail)[] = 'HTML element has [lang] attribute';

        if ($maybe_timeout()) goto _SUM;

        // viewport scalable
        $vp = array();
        if ( preg_match('/<meta\s+name=[\'"]viewport[\'"][^>]*content=[\'"]([^\'"]+)[\'"][^>]*>/i', $html, $m) ) {
            $vp = array_map('trim', explode(',', strtolower($m[1])));
        }
        $vp_str = implode(',', $vp);
        $user_scalable_no = (strpos($vp_str, 'user-scalable=no') !== false);
        $max_scale_too_low = (preg_match('/maximum-scale\s*=\s*([0-9.]+)/', $vp_str, $mm) && (float)$mm[1] < 5.0);
        $report['viewport_scalable'] = !($user_scalable_no || $max_scale_too_low);
        ($report['viewport_scalable'] ? $pass : $fail)[] = 'Viewport allows user scaling';

        // Headings sequential
        $seq_ok = true; $last = 0; $ho_examples = array();
        if ( preg_match_all('/<h([1-6])\b[^>]*>/i', $html, $m) ) {
            foreach ( $m[1] as $idx => $lvl ) {
                $lvl = (int)$lvl;
                if ($last>0 && $lvl > $last + 1) { $seq_ok = false; $ho_examples[] = array('sequence'=>array('h'.$last,'h'.$lvl)); }
                $last = $lvl;
                if ($maybe_timeout()) break;
            }
        }
        $report['headings_sequential'] = $seq_ok;
        ($seq_ok ? $pass : $fail)[] = 'Heading elements are in sequential order';
        if (!$seq_ok && $ho_examples) $report['examples']['headings_out_of_order'] = $ho_examples;

        if ($maybe_timeout()) goto _SUM;

        // Links named
        $links_total = preg_match_all('/<a\b[^>]*>/i', $html);
        $links_named = preg_match_all('/<a\b[^>]*?(?:aria-label|title)=[\'"][^\'"]+[\'"][^>]*>.*?<\/a>|<a\b[^>]*>\s*[^<\s].*?<\/a>/is', $html);
        $report['links_named'] = ( $links_total === 0 || $links_named >= $links_total );
        ($report['links_named'] ? $pass : $fail)[] = 'Links have discernible names';
        if ($links_total && $links_named < $links_total) {
            $report['examples']['links_missing_name'] = array( 'approx_missing' => max(0, $links_total - $links_named) );
        }

        // Buttons named
        $btns       = preg_match_all('/<(?:button\b[^>]*>|input\b[^>]*type=(?:[\'"](button|submit|image)[\'"]).*?>)/i', $html);
        $btns_named = preg_match_all('/<button\b[^>]*>(\s*[^<][\s\S]*?)<\/button>|<input\b[^>]*(?:aria-label|title|alt|value)=/i', $html);
        $report['buttons_named'] = ( $btns === 0 || $btns_named >= $btns );
        ($report['buttons_named'] ? $pass : $fail)[] = 'Buttons/inputs have accessible names';

        if ($maybe_timeout()) goto _SUM;

        // Images alt
        $total_imgs   = preg_match_all('/<img\b[^>]*>/i', $html);
        $imgs_withalt = preg_match_all('/<img\b[^>]*\balt=[\'"][^\'"]*[\'"][^>]*>/i', $html);
        $imgs_missing = max(0, $total_imgs - $imgs_withalt);
        $report['images_alt'] = ( $imgs_missing <= 0 );
        (($imgs_missing <= 0) ? $pass : $fail)[] = 'Image elements have [alt] attributes';
        if ($imgs_missing > 0) {
            $report['examples']['images_missing_alt'] = array( 'approx_missing' => $imgs_missing );
        }

        // Forms labels (heuristic)
        $inputs     = preg_match_all('/<(input|select|textarea)\b[^>]*>/i', $html);
        $labels_for = preg_match_all('/<label\b[^>]*for=[\'"][^\'"]+[\'"][^>]*>/i', $html);
        $report['form_labels'] = ( $inputs === 0 || $labels_for >= $inputs );
        ($report['form_labels'] ? $pass : $fail)[] = 'Form elements have associated labels';

        // ARIA sanity (very light)
        $aria_typos = preg_match('/\baria-[a-z0-9\-]*[^a-z0-9\-"]/i', $html) ? true : false;
        $report['aria_basic_sanity'] = !$aria_typos;
        ($report['aria_basic_sanity'] ? $pass : $fail)[] = 'ARIA attributes appear syntactically valid';

_SUM:
        $notes[] = 'contrast: manual';
        if ($truncated) $notes[] = 'html_truncated';
        if ($timed_out) $notes[] = 'time_budget_exceeded';

        $summary = array(
            'pass'  => $pass,
            'fail'  => $fail,
            'notes' => $notes,
        );

        return array(
            'ok'         => true,
            'engine'     => 'regex',
            'truncated'  => $truncated ? 1 : 0,
            'timed_out'  => $timed_out ? 1 : 0,
            'html_len'   => $html_len,
            'report'     => array_merge($report, array('summary'=>$summary)),
        );
    }
}
