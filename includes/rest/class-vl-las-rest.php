<?php
/**
 * REST API endpoints for VL LAS.
 *
 * File: includes/rest/class-vl-las-rest.php
 * @package VL_LAS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'VL_LAS_REST' ) ) :

class VL_LAS_REST {

	/**
	 * Route namespace helper.
	 */
	protected static function ns() {
		return 'vl-las/v1';
	}

	/**
	 * Capability check.
	 */
	protected static function can_admin() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register all routes.
	 */
	public static function register_routes() {

		// --- Health: GET /ping
		register_rest_route( self::ns(), '/ping', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => function () {
				return rest_ensure_response( array(
					'ok'      => true,
					'plugin'  => 'vl-las',
					'version' => defined( 'VL_LAS_VERSION' ) ? VL_LAS_VERSION : 'dev',
					'time'    => time(),
				) );
			},
		) );

		// --- Introspection: GET /routes
		register_rest_route( self::ns(), '/routes', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$server = rest_get_server();
				$items  = array();
				if ( $server && method_exists( $server, 'get_routes' ) ) {
					foreach ( $server->get_routes() as $route => $defs ) {
						if ( strpos( $route, '/vl-las/v1/' ) !== 0 ) continue;
						$methods = array();
						foreach ( (array) $defs as $d ) {
							if ( ! empty( $d['methods'] ) ) $methods[] = $d['methods'];
						}
						$items[] = array( 'route' => $route, 'methods' => $methods );
					}
				}
				return rest_ensure_response( array( 'ok' => true, 'routes' => $items ) );
			},
		) );

		// --- GET /reports — list stored reports
		register_rest_route( self::ns(), '/reports', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'args'                => array(
				'page'     => array( 'required' => false, 'type' => 'integer', 'default' => 1 ),
				'per_page' => array( 'required' => false, 'type' => 'integer', 'default' => 20 ),
			),
			'callback'            => function ( \WP_REST_Request $req ) {
				if ( ! class_exists( 'VL_LAS_Audit_Store' ) ) {
					return rest_ensure_response( array( 'ok' => true, 'items' => array(), 'total' => 0, 'pages' => 0 ) );
				}
				$page     = max( 1, (int) $req->get_param( 'page' ) );
				$per_page = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ) );
				try {
					$listing = \VL_LAS_Audit_Store::list( $page, $per_page ); // <-- matches your class
					return rest_ensure_response( array( 'ok' => true ) + $listing );
				} catch ( \Throwable $t ) {
					return rest_ensure_response( array( 'ok' => false, 'error' => $t->getMessage() ) );
				}
			},
		) );

		// --- GET /report/{id} — fetch single report
		register_rest_route( self::ns(), '/report/(?P<id>[\w\-]+)', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'callback'            => function ( \WP_REST_Request $req ) {
				if ( ! class_exists( 'VL_LAS_Audit_Store' ) ) {
					return new \WP_Error( 'vl_las_no_store', 'Storage not available.', array( 'status' => 404 ) );
				}
				$id  = (int) $req['id'];
				$row = \VL_LAS_Audit_Store::get( $id ); // <-- matches your class
				if ( ! $row ) {
					return new \WP_Error( 'vl_las_not_found', 'Report not found.', array( 'status' => 404 ) );
				}
				return rest_ensure_response( $row );
			},
		) );

		// --- GET /report/{id}/download — download JSON
		register_rest_route( self::ns(), '/report/(?P<id>[\w\-]+)/download', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'callback'            => function ( \WP_REST_Request $req ) {
				if ( ! class_exists( 'VL_LAS_Audit_Store' ) ) {
					return new \WP_Error( 'vl_las_no_store', 'Storage not available.', array( 'status' => 404 ) );
				}
				$id  = (int) $req['id'];
				$row = \VL_LAS_Audit_Store::get( $id ); // <-- matches your class
				if ( ! $row ) {
					return new \WP_Error( 'vl_las_not_found', 'Report not found.', array( 'status' => 404 ) );
				}
				$filename = 'vl-las-report-' . $id . '.json';
				nocache_headers();
				header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset', 'utf-8' ) );
				header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
				echo wp_json_encode( $row['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				exit;
			},
		) );

		// --- GET /audit2 — quick probe
		register_rest_route( self::ns(), '/audit2', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'callback'            => function () {
				return rest_ensure_response( array( 'ok' => true, 'alive' => 'audit2 GET ok' ) );
			},
		) );

		// --- POST /audit2 — main audit runner (regex-only, safe)
		register_rest_route( self::ns(), '/audit2', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'args'                => array(
				'url'  => array( 'required' => false, 'type' => 'string' ),
				'html' => array( 'required' => false, 'type' => 'string' ),
			),
			'callback'            => array( __CLASS__, 'handle_audit_post' ),
		) );

		// --- POST /audit — compatibility alias to /audit2
		register_rest_route( self::ns(), '/audit', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'args'                => array(
				'url'  => array( 'required' => false, 'type' => 'string' ),
				'html' => array( 'required' => false, 'type' => 'string' ),
			),
			'callback'            => array( __CLASS__, 'handle_audit_post' ),
		) );

		// --- POST /gemini-test — test Gemini API key
		register_rest_route( self::ns(), '/gemini-test', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'callback'            => array( __CLASS__, 'handle_gemini_test' ),
		) );
	}

	/**
	 * Handle POST /audit2 (and /audit).
	 * Uses VL_LAS_Audit_Regex if present; does not autoload heavy classes.
	 */
	public static function handle_audit_post( \WP_REST_Request $req ) {
		try {
			$html = (string) $req->get_param( 'html' );
			$url  = (string) $req->get_param( 'url' );
			$url  = $url ? esc_url_raw( $url ) : home_url( '/' );

			// Only proceed if regex engine is already loaded.
			if ( ! class_exists( 'VL_LAS_Audit_Regex', false ) ) {
				return rest_ensure_response( array(
					'ok'    => false,
					'error' => 'Regex audit engine not loaded. Ensure class-vl-las-audit-regex.php is required by the main plugin file.',
				) );
			}

			// Run audit (safe regex).
			if ( method_exists( '\VL_LAS_Audit_Regex', 'run' ) ) {
				$report = ( $html !== '' )
					? \VL_LAS_Audit_Regex::run( $html, null )
					: \VL_LAS_Audit_Regex::run( '', $url );
			} else {
				return rest_ensure_response( array(
					'ok'    => false,
					'error' => 'VL_LAS_Audit_Regex::run not found.',
				) );
			}

			// Normalize fields for UI.
			if ( is_array( $report ) ) {
				if ( empty( $report['url'] ) )        $report['url']        = $url;
				if ( empty( $report['created_at'] ) ) $report['created_at'] = time();
			} else {
				$report = array(
					'raw'        => $report,
					'url'        => $url,
					'created_at' => time(),
				);
			}

			// Best-effort store (never fatal if DB not ready).
			if ( class_exists( 'VL_LAS_Audit_Store' ) && method_exists( 'VL_LAS_Audit_Store', 'save' ) ) {
				try {
					if ( method_exists( 'VL_LAS_Audit_Store', 'install' ) ) {
						\VL_LAS_Audit_Store::install();
					}
					$insert_id          = \VL_LAS_Audit_Store::save( $report ); // <-- matches your class
					$report['report_id'] = $insert_id;
				} catch ( \Throwable $t ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[VL_LAS] save error: ' . $t->getMessage() );
					}
				}
			}

			// Wrap as { ok, report } for admin.js pretty view.
			return rest_ensure_response( array( 'ok' => true, 'report' => $report ) );

		} catch ( \Throwable $t ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[VL_LAS] audit2 fatal: ' . $t->getMessage() . "\n" . $t->getTraceAsString() );
			}
			return new \WP_Error( 'vl_las_internal', 'Audit crashed: ' . $t->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Handle POST /gemini-test.
	 * Test the Gemini API key by making a real request to the Gemini API.
	 */
	public static function handle_gemini_test( \WP_REST_Request $req ) {
		try {
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
					'via' => 'vl-las-rest-class'
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
					'via' => 'vl-las-rest-class'
				) );
			}
			
			$data = json_decode( $response_body, true );
			if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
				return rest_ensure_response( array( 
					'ok' => true, 
					'status' => 'API test successful',
					'response' => $data['candidates'][0]['content']['parts'][0]['text'],
					'via' => 'vl-las-rest-class'
				) );
			} else {
				return rest_ensure_response( array( 
					'ok' => false, 
					'error' => 'Unexpected API response format',
					'raw_response' => $response_body,
					'via' => 'vl-las-rest-class'
				) );
			}
		} catch ( \Throwable $t ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[VL_LAS] gemini-test fatal: ' . $t->getMessage() . "\n" . $t->getTraceAsString() );
			}
			return new \WP_Error( 'vl_las_gemini_test_failed', 'Gemini test crashed: ' . $t->getMessage(), array( 'status' => 500 ) );
		}
	}
}

endif;
