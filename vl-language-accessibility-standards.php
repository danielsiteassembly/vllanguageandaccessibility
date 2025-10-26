<?php
/**
 * Plugin Name: VL Language & Accessibility Standards
 * Description: Language, Compliance, Accessibility toolkit with cookie consent banner, legal shortcodes, WCAG audit checks, and optional Gemini 2.5-powered language assistance. Includes Corporate License Code field for secured Hub data.
 * Version: 1.1.1
 * Author: Visible Light AI
 * License: GPLv2 or later
 * Text Domain: vl-las
 * Domain Path: /languages
 *
 * @package VL_LAS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'VL_LAS_VERSION', '1.1.1' );
define( 'VL_LAS_PATH', plugin_dir_path( __FILE__ ) );
define( 'VL_LAS_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Lightweight autoloader for classes named VL_LAS_*
 * Maps VL_LAS_Foo_Bar -> includes/class-vl-las-foo-bar.php
 */
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'VL_LAS_' ) !== 0 ) return;
    $relative = 'includes/' . 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
    $file     = VL_LAS_PATH . $relative;
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

/** ------------------------------------------------------------------------
 * Explicit includes (non-standard names or utility files)
 * --------------------------------------------------------------------- */
require_once VL_LAS_PATH . 'admin/class-vl-las-admin.php';
require_once VL_LAS_PATH . 'includes/rest/class-vl-las-rest.php';
require_once VL_LAS_PATH . 'includes/class-vl-las-languages.php';
require_once VL_LAS_PATH . 'includes/class-vl-las-language-detect.php';
require_once VL_LAS_PATH . 'includes/class-vl-las-translate.php';

/** Optional: regex audit + storage (guarded to avoid fatals on missing files) */
$regex_engine = VL_LAS_PATH . 'includes/class-vl-las-audit-regex.php';
$store_engine = VL_LAS_PATH . 'includes/class-vl-las-audit-store.php';
if ( file_exists( $regex_engine ) ) { require_once $regex_engine; }
if ( file_exists( $store_engine ) ) { require_once $store_engine; }

/** ------------------------------------------------------------------------
 * Activation: set safe defaults; create audit reports table (dbDelta)
 * --------------------------------------------------------------------- */
function vl_las_activate() {
    $defaults = array(
        'languages'              => array( 'English' ),
        'gemini_api_key'         => '',
        'license_code'           => '',
        'cookie_consent_enabled' => 0,
        'cookie_visibility'      => 'show',
        'cookie_position'        => 'bottom-right',
        'cookie_message'         => '',
        'legal_privacy_policy'   => '',
        'legal_terms'            => '',
        'legal_copyright'        => '',
        'legal_data_privacy'     => '',
        'legal_cookie'           => '',
        'high_contrast'          => 0,
        // Audit defaults
        'audit_engine'           => 2, // 0 off, 1 diagnostics, 2 regex-only
        'audit_show_json'        => 0,
    );
    foreach ( $defaults as $key => $val ) {
        if ( get_option( 'vl_las_' . $key, null ) === null ) {
            add_option( 'vl_las_' . $key, $val );
        }
    }

    // Create / upgrade the audit reports table (safe)
    if ( class_exists( 'VL_LAS_Audit_Store' ) ) {
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        try {
            VL_LAS_Audit_Store::install();
        } catch ( \Throwable $t ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[VL_LAS] Audit_Store install error: ' . $t->getMessage() );
            }
        }
    }
}
register_activation_hook( __FILE__, 'vl_las_activate' );

/** ------------------------------------------------------------------------
 * plugins_loaded: bring up Admin UI (menus/fields)
 * --------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
    if ( is_admin() ) {
        VL_LAS_Admin::get_instance();
    }
} );

/** ------------------------------------------------------------------------
 * init: i18n + optional shortcodes (guarded)
 * --------------------------------------------------------------------- */
add_action( 'init', function () {
    load_plugin_textdomain( 'vl-las', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // Legal shortcodes, if class exists in your build
    if ( class_exists( 'VL_LAS_Privacy' ) && method_exists( 'VL_LAS_Privacy', 'register_shortcodes' ) ) {
        VL_LAS_Privacy::register_shortcodes();
    }
} ); // END init callback

/** ------------------------------------------------------------------------
 * REST routes — preferred: class; fallback: register any missing routes inline
 * --------------------------------------------------------------------- */
add_action( 'rest_api_init', function () {

    // Try class-based registration first.
    if ( class_exists( 'VL_LAS_REST' ) && is_callable( array( 'VL_LAS_REST', 'register_routes' ) ) ) {
        try { VL_LAS_REST::register_routes(); } catch ( \Throwable $t ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[VL_LAS] VL_LAS_REST::register_routes() failed: ' . $t->getMessage() );
            }
        }
    }

    // Helper to check if a route exists (exact path key).
    $route_exists = function( $path ) {
        $server = rest_get_server();
        if ( ! $server || ! method_exists( $server, 'get_routes' ) ) return false;
        $routes = $server->get_routes();
        return isset( $routes[ $path ] );
    };

    // --- Ensure /ping exists (public GET) ---
    if ( ! $route_exists( '/vl-las/v1/ping' ) ) {
        register_rest_route( 'vl-las/v1', '/ping', array(
            'methods'             => WP_REST_Server::READABLE, // GET
            'permission_callback' => '__return_true',
            'callback'            => function () {
                return rest_ensure_response( array(
                    'ok'      => true,
                    'plugin'  => 'vl-las',
                    'version' => defined('VL_LAS_VERSION') ? VL_LAS_VERSION : 'dev',
                    'time'    => time(),
                    'via'     => 'inline-fallback',
                ) );
            },
        ) );
    }

    // --- Ensure /routes exists (public GET) ---
    if ( ! $route_exists( '/vl-las/v1/routes' ) ) {
        register_rest_route( 'vl-las/v1', '/routes', array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback'            => function () {
                $server = rest_get_server();
                $out = array();
                if ( $server && method_exists( $server, 'get_routes' ) ) {
                    foreach ( $server->get_routes() as $route => $handlers ) {
                        if ( strpos( $route, '/vl-las/v1/' ) === 0 ) {
                            $methods = array();
                            foreach ( $handlers as $h ) {
                                if ( ! empty( $h['methods'] ) ) {
                                    $methods[] = $h['methods'];
                                }
                            }
                            $out[] = array( 'route' => $route, 'methods' => $methods );
                        }
                    }
                }
                return rest_ensure_response( array( 'ok' => true, 'routes' => $out ) );
            },
        ) );
    }

    // Admin-capability checker used below
    $can_manage = function () { return current_user_can( 'manage_options' ); };

    // --- Ensure /audit exists (GET + POST) ---
    if ( ! $route_exists( '/vl-las/v1/audit' ) ) {
        // GET: alive check
        register_rest_route( 'vl-las/v1', '/audit', array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $can_manage,
            'callback'            => function () {
                return rest_ensure_response( array(
                    'ok'      => true,
                    'message' => 'Audit endpoint is registered. POST to run.',
                    'via'     => 'inline-fallback',
                ) );
            },
        ) );

        // POST: run audit (HTML payload or URL fallback)
        register_rest_route( 'vl-las/v1', '/audit', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $can_manage,
            'args'                => array(
                'url'  => array( 'required' => false, 'type' => 'string' ),
                'html' => array( 'required' => false, 'type' => 'string' ),
            ),
            'callback'            => function( WP_REST_Request $req ) {
                $html = (string) $req->get_param( 'html' );
                $url  = $req->get_param( 'url' );
                $url  = $url ? esc_url_raw( $url ) : home_url( '/' );

                // Full engine if present
                if ( class_exists( 'VL_LAS_Accessibility_Audit' ) ) {
                    try {
                        $auditor = new \VL_LAS_Accessibility_Audit();
                        $report  = ( $html !== '' )
                            ? $auditor->run_audit_html( $html )
                            : $auditor->run_audit( $url );
                        return rest_ensure_response( array( 'ok' => true, 'report' => $report, 'engine' => 'full', 'via' => 'inline-fallback' ) );
                    } catch ( \Throwable $t ) {
                        return rest_ensure_response( array( 'ok' => false, 'error' => $t->getMessage() ) );
                    }
                }

                // Regex fallback
                if ( class_exists( 'VL_LAS_Audit_Regex' ) ) {
                    try {
                        $report = ( $html !== '' )
                            ? \VL_LAS_Audit_Regex::run( $html, null )
                            : \VL_LAS_Audit_Regex::run( '', $url );
                        return rest_ensure_response( array( 'ok' => true, 'report' => $report, 'engine' => 'regex', 'via' => 'inline-fallback' ) );
                    } catch ( \Throwable $t ) {
                        return rest_ensure_response( array( 'ok' => false, 'error' => $t->getMessage() ) );
                    }
                }

                return rest_ensure_response( array( 'ok' => false, 'error' => 'No audit engine available.' ) );
            },
        ) );
    }

    // --- Fallback: Ensure /gemini-test exists (POST) ---
    if ( ! $route_exists( '/vl-las/v1/gemini-test' ) ) {
        register_rest_route( 'vl-las/v1', '/gemini-test', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $can_manage,
            'callback'            => function() {
                $key = trim( (string) get_option( 'vl_las_gemini_api_key', '' ) );
                if ( empty( $key ) ) {
                    return rest_ensure_response( array( 'ok' => false, 'error' => 'No Gemini API key saved.' ) );
                }
                
                // Test the API key by making a real request to Gemini API
                $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=' . urlencode( $key );
                
                $test_payload = array(
                    'contents' => array(
                        array(
                            'parts' => array(
                                array( 'text' => 'Hello, this is a test message. Please respond with "API test successful".' )
                            )
                        )
                    ),
                    'generationConfig' => array(
                        'maxOutputTokens' => 50,
                        'temperature' => 0.1
                    )
                );
                
                $response = wp_remote_post( $api_url, array(
                    'method' => 'POST',
                    'headers' => array(
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode( $test_payload ),
                    'timeout' => 30,
                ) );
                
                if ( is_wp_error( $response ) ) {
                    return rest_ensure_response( array( 
                        'ok' => false, 
                        'error' => 'Network error: ' . $response->get_error_message(),
                        'via' => 'inline-fallback'
                    ) );
                }
                
                $response_code = wp_remote_retrieve_response_code( $response );
                $response_body = wp_remote_retrieve_body( $response );
                
                if ( $response_code !== 200 ) {
                    $error_data = json_decode( $response_body, true );
                    $error_message = 'HTTP ' . $response_code;
                    if ( isset( $error_data['error']['message'] ) ) {
                        $error_message .= ': ' . $error_data['error']['message'];
                    }
                    return rest_ensure_response( array( 
                        'ok' => false, 
                        'error' => $error_message,
                        'status_code' => $response_code,
                        'via' => 'inline-fallback'
                    ) );
                }
                
                $data = json_decode( $response_body, true );
                if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
                    return rest_ensure_response( array( 
                        'ok' => true, 
                        'status' => 'API test successful',
                        'response' => $data['candidates'][0]['content']['parts'][0]['text'],
                        'via' => 'inline-fallback'
                    ) );
                } else {
                    return rest_ensure_response( array( 
                        'ok' => false, 
                        'error' => 'Unexpected API response format',
                        'raw_response' => $response_body,
                        'via' => 'inline-fallback'
                    ) );
                }
            },
        ) );
    }

} );

/** ------------------------------------------------------------------------
 * Frontend boot: cookie banner & high contrast (frontend only)
 * --------------------------------------------------------------------- */
add_action( 'wp', function () {

    // Cookie banner (adds its own enqueue + render hooks)
    if ( (int) get_option( 'vl_las_cookie_consent_enabled', 0 ) === 1 && class_exists( 'VL_LAS_Cookie' ) ) {
        VL_LAS_Cookie::init();
    }

    // High-contrast CSS (scoped via body class)
    if ( (int) get_option( 'vl_las_high_contrast', 0 ) === 1 ) {
        wp_enqueue_style(
            'vl-las-high-contrast',
            VL_LAS_URL . 'assets/css/high-contrast.css',
            array(),
            VL_LAS_VERSION
        );
    }
} );

/** ------------------------------------------------------------------------
 * Add body class when high contrast is enabled (for CSS scoping)
 * --------------------------------------------------------------------- */
add_filter( 'body_class', function ( $classes ) {
    if ( (int) get_option( 'vl_las_high_contrast', 0 ) === 1 ) {
        $classes[] = 'vl-las-contrast';
    }
    return $classes;
} );

/** ------------------------------------------------------------------------
 * Apply selected/detected language to <html lang> when enabled.
 * --------------------------------------------------------------------- */
add_filter( 'language_attributes', function( $output, $doctype ) {
    if ( (int) get_option( 'vl_las_apply_html_lang', 0 ) !== 1 ) {
        return $output;
    }
    if ( ! class_exists( 'VL_LAS_Language_Detect' ) ) {
        return $output;
    }

    $label = VL_LAS_Language_Detect::current();
    $code  = VL_LAS_Language_Detect::label_to_code( $label );
    if ( ! $code ) {
        return $output;
    }

    if ( preg_match( '/\blang="[^"]*"/i', $output ) ) {
        $output = preg_replace( '/\blang="[^"]*"/i', 'lang="' . esc_attr( $code ) . '"', $output );
    } else {
        $output .= ' lang="' . esc_attr( $code ) . '"';
    }
    return $output;
}, 20, 2 );

/** ------------------------------------------------------------------------
 * WP-CLI: audit command (legacy; safe fallback)
 * --------------------------------------------------------------------- */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'vl-las audit', function( $args, $assoc_args ) {
        $url = isset( $assoc_args['url'] ) ? esc_url_raw( $assoc_args['url'] ) : home_url( '/' );
        if ( class_exists( 'VL_LAS_Accessibility_Audit' ) ) {
            $auditor = new VL_LAS_Accessibility_Audit();
            $report  = $auditor->run_audit( $url );
            WP_CLI::log( json_encode( $report, JSON_PRETTY_PRINT ) );
        } elseif ( class_exists( 'VL_LAS_Audit_Regex' ) ) {
            $report  = VL_LAS_Audit_Regex::run( '', $url );
            WP_CLI::log( json_encode( $report, JSON_PRETTY_PRINT ) );
        } else {
            WP_CLI::warning( 'Audit engine not available in this build.' );
        }
    } );
}

/**
 * 🔧 Hard-register the /vl-las/v1/audit endpoint (GET + POST) with a very late priority.
 * This guarantees the route exists even if class-based registration is missing.
 */
add_action( 'rest_api_init', function () {

    // GET: simple alive check (requires admin to avoid exposing internals)
    register_rest_route( 'vl-las/v1', '/audit', array(
        'methods'             => WP_REST_Server::READABLE, // GET
        'permission_callback' => function(){ return current_user_can( 'manage_options' ); },
        'callback'            => function () {
            return rest_ensure_response( array(
                'ok'      => true,
                'message' => 'Audit endpoint is registered. POST to run.',
                'via'     => 'hard-register',
            ) );
        },
    ), true );

    // POST: run audit (html OR url)
    register_rest_route( 'vl-las/v1', '/audit', array(
        'methods'             => WP_REST_Server::CREATABLE, // POST
        'permission_callback' => function(){ return current_user_can( 'manage_options' ); },
        'args'                => array(
            'url'  => array( 'required' => false, 'type' => 'string' ),
            'html' => array( 'required' => false, 'type' => 'string' ),
        ),
        'callback'            => function( WP_REST_Request $req ) {

            $html = (string) $req->get_param( 'html' );
            $url  = $req->get_param( 'url' );
            $url  = $url ? esc_url_raw( $url ) : home_url( '/' );

            // Prefer full engine if present
            if ( class_exists( 'VL_LAS_Accessibility_Audit' ) ) {
                try {
                    $auditor = new \VL_LAS_Accessibility_Audit();
                    $report  = ( $html !== '' )
                        ? $auditor->run_audit_html( $html )
                        : $auditor->run_audit( $url );
                    return rest_ensure_response( array( 'ok' => true, 'report' => $report, 'engine' => 'full', 'via' => 'hard-register' ) );
                } catch ( \Throwable $t ) {
                    return rest_ensure_response( array( 'ok' => false, 'error' => $t->getMessage() ) );
                }
            }

            // Fallback to regex engine if available
            if ( class_exists( 'VL_LAS_Audit_Regex' ) ) {
                try {
                    $report = ( $html !== '' )
                        ? \VL_LAS_Audit_Regex::run( $html, null )
                        : \VL_LAS_Audit_Regex::run( '', $url );
                    return rest_ensure_response( array( 'ok' => true, 'report' => $report, 'engine' => 'regex', 'via' => 'hard-register' ) );
                } catch ( \Throwable $t ) {
                    return rest_ensure_response( array( 'ok' => false, 'error' => $t->getMessage() ) );
                }
            }

            return rest_ensure_response( array( 'ok' => false, 'error' => 'No audit engine available.' ) );
        },
    ), true );

}, 10000 ); // very late priority

