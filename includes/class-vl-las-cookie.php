<?php
/**
 * Cookie Banner front-end.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VL_LAS_Cookie {

    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'wp_footer', array( __CLASS__, 'render_banner' ) );
        add_action( 'wp_body_open', array( __CLASS__, 'render_banner' ) );
    }

    public static function assets() {
        wp_enqueue_style( 'vl-las-cookie', VL_LAS_URL . 'assets/css/cookie-banner.css', array(), VL_LAS_VERSION );
        wp_enqueue_script( 'vl-las-cookie', VL_LAS_URL . 'assets/js/cookie-banner.js', array(), VL_LAS_VERSION, true );
        wp_localize_script( 'vl-las-cookie', 'VLLAS_COOKIE', array(
            'visibility' => get_option( 'vl_las_cookie_visibility', 'show' ),
            'position'   => get_option( 'vl_las_cookie_position', 'bottom-right' ),
            'policy'     => do_shortcode( '[vl_privacy_policy]' ),
            'cookie'     => do_shortcode( '[vl_cookie]' ),
            'forceShow'  => isset( $_GET['vl_las_preview_cookie'] ) ? 1 : 0,
        ) );
    }

    public static function render_banner() {
        if ( 'hide' === get_option( 'vl_las_cookie_visibility', 'show' ) ) {
            return;
        }

        // Short, admin-configurable banner message (shown left of Accept).
        $message = trim( (string) get_option( 'vl_las_cookie_message', '' ) );

        // Auto-translate the message if enabled and translator is available.
        if ( $message !== '' && (int) get_option( 'vl_las_translate_cookie', 0 ) === 1 && class_exists( 'VL_LAS_Translate' ) ) {
            $message = VL_LAS_Translate::maybe( $message );
        }
        ?>
        <div id="vl-las-cookie-banner" class="vl-las-cookie hidden <?php echo esc_attr( get_option( 'vl_las_cookie_position', 'bottom-right' ) ); ?>" role="dialog" aria-live="polite" aria-label="<?php esc_attr_e( 'Cookie Consent', 'vl-las' ); ?>">
            <div class="vl-las-cookie__content">
                <p><?php echo wp_kses_post( get_option( 'vl_las_legal_cookie', __( 'We use cookies to improve your experience.', 'vl-las' ) ) ); ?></p>

                <?php if ( $message !== '' ) : ?>
                    <span class="vl-las-cookie__message"><?php echo wp_kses_post( $message ); ?></span>
                <?php endif; ?>

                <div class="vl-las-cookie__actions">
                    <button class="vl-las-btn accept"><?php esc_html_e( 'Accept', 'vl-las' ); ?></button>
                    <button class="vl-las-btn reject"><?php esc_html_e( 'Reject', 'vl-las' ); ?></button>
                    <a class="vl-las-link" href="<?php echo esc_url( home_url( '/privacy-policy' ) ); ?>"><?php esc_html_e( 'Learn more', 'vl-las' ); ?></a>
                </div>
            </div>
        </div>
        <?php
    }
}
