<?php
/**
 * Corporate license helper.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VL_LAS_License {

    public static function get_code() {
        return sanitize_text_field( get_option( 'vl_las_license_code', '' ) );
    }

    public static function add_request_headers( $args ) {
        $code = self::get_code();
        if ( $code ) {
            if ( empty( $args['headers'] ) ) $args['headers'] = array();
            $args['headers']['X-VL-License'] = $code;
        }
        return $args;
    }
}
