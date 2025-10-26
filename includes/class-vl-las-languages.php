<?php
if ( ! defined('ABSPATH') ) exit;

class VL_LAS_Languages_UI {
    public static function init() {
        add_shortcode('vl_language_switcher', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function assets() {
        // lightweight, only if shortcode is present on the page
        if ( ! is_singular() ) return;
        global $post;
        if ( $post && has_shortcode( $post->post_content, 'vl_language_switcher' ) ) {
            wp_register_script('vl-las-lang', plugins_url('../assets/js/lang-switcher.js', __FILE__), array(), defined('VL_LAS_VERSION') ? VL_LAS_VERSION : '1.0.0', true);
            wp_enqueue_script('vl-las-lang');
        }
    }

    public static function shortcode($atts) {
        $a = shortcode_atts(array(
            'display' => 'dropdown', // 'dropdown' or 'list'
        ), $atts, 'vl_language_switcher');

        // pull selected languages from existing option
        $selected = get_option('vl_las_languages', array()); // array of slugs/labels you saved
        if ( empty($selected) || ! is_array($selected) ) return '';

        // Normalize to label list
        $labels = array_values( array_unique( array_map('sanitize_text_field', $selected) ) );

        ob_start();
        ?>
        <div class="vl-las-language-switcher" data-display="<?php echo esc_attr($a['display']); ?>">
            <?php if ( $a['display'] === 'list' ) : ?>
                <ul class="vl-las-lang-list">
                    <?php foreach ( $labels as $label ) : ?>
                        <li><a href="#" class="vl-las-lang" data-lang="<?php echo esc_attr($label); ?>"><?php echo esc_html($label); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <label class="screen-reader-text" for="vl-las-lang-dd">Language</label>
                <select id="vl-las-lang-dd" class="vl-las-lang-dd">
                    <?php foreach ( $labels as $label ) : ?>
                        <option value="<?php echo esc_attr($label); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
VL_LAS_Languages_UI::init();
