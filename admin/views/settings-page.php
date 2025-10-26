<?php
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap vl-las-settings-wrap">
    <h1><?php esc_html_e( 'VL Language & Accessibility Standards', 'vl-las' ); ?></h1>

    <?php
    // Surface Settings API messages (sanitization errors, updated notices, etc.)
    settings_errors();
    ?>

    <form method="post" action="options.php">
        <?php
            // Nonce + option group
            settings_fields( 'vl-las' );

            // Render all sections/fields registered to the 'vl-las' page
            do_settings_sections( 'vl-las' );

            // Save button
            submit_button();
        ?>
    </form>

    <hr/>

    <p>
        <?php
        echo wp_kses_post( sprintf(
            /* translators: 1: WP Plugin Guidelines URL, 2: WCAG 2.1 AA URL */
            __( 'This plugin follows the <a href="%1$s" target="_blank" rel="noopener">WordPress.org Plugin Guidelines</a> and provides tools aligned with <a href="%2$s" target="_blank" rel="noopener">WCAG 2.1 AA</a>.', 'vl-las' ),
            esc_url( 'https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/' ),
            esc_url( 'https://www.w3.org/TR/WCAG21/' )
        ) );
        ?>
    </p>
</div>
