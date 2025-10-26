<?php
/**
 * Legal shortcodes.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VL_LAS_Privacy {

    public static function register_shortcodes() {
        add_shortcode( 'vl_privacy_policy', array( __CLASS__, 'privacy_policy' ) );
        add_shortcode( 'vl_terms', array( __CLASS__, 'terms' ) );
        add_shortcode( 'vl_copyright', array( __CLASS__, 'copyright' ) );
        add_shortcode( 'vl_data_privacy_laws', array( __CLASS__, 'data_privacy' ) );
        add_shortcode( 'vl_cookie', array( __CLASS__, 'cookie' ) );
    }

    public static function privacy_policy() { return wp_kses_post( get_option( 'vl_las_legal_privacy_policy', '' ) ); }
    public static function terms()          { return wp_kses_post( get_option( 'vl_las_legal_terms', '' ) ); }
    public static function copyright()      { return wp_kses_post( get_option( 'vl_las_legal_copyright', '' ) ); }
    public static function data_privacy()   { return wp_kses_post( get_option( 'vl_las_legal_data_privacy', '' ) ); }
    public static function cookie()         { return wp_kses_post( get_option( 'vl_las_legal_cookie', '' ) ); }
}
