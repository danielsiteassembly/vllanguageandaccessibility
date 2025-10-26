<?php
/**
 * Admin settings and pages.
 *
 * @package VL_LAS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VL_LAS_Admin {

    private static $instance = null;

    /**
     * Singleton
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
    add_action( 'admin_menu', array( $this, 'add_menu' ) );
    add_action( 'admin_init', array( $this, 'register_settings' ) );
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

    // NEW: fallback injector – if admin.js somehow doesn't load,
    // we’ll inject it ourselves at the bottom of the page.
    add_action( 'admin_footer', array( $this, 'print_fallback_loader' ), 99 );
}

/**
 * Enqueue admin assets (force-load + localize REST info).
 * Loads on ALL admin screens for now so we can see it in Network → Status 200.
 * We’ll narrow to just the settings page after we confirm it’s loading.
 */
public function enqueue( $hook ) {
    // Only load our JS on Settings → VL Language & Accessibility
    if ( $hook !== 'settings_page_vl-las' ) {
        return;
    }

    // Build asset URL/version
    $asset_rel  = 'assets/js/admin.js';
    $asset_path = trailingslashit( VL_LAS_PATH ) . $asset_rel;
    $asset_url  = trailingslashit( VL_LAS_URL )  . $asset_rel;

    $ver = defined( 'VL_LAS_VERSION' ) ? VL_LAS_VERSION : '1.0.0';
    if ( file_exists( $asset_path ) ) {
        $mt = @filemtime( $asset_path );
        if ( $mt ) { $ver = $mt; }
    }

    wp_enqueue_script(
        'vl-las-admin',
        $asset_url,
        array( 'jquery' ),
        $ver,
        true
    );

    // Expose REST info to admin.js as window.VLLAS
    wp_localize_script(
        'vl-las-admin',
        'VLLAS',
        array(
            'rest' => array(
                'root'  => esc_url_raw( rest_url( 'vl-las/v1' ) ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
            ),
        )
    );
}


/**
 * Fallback loader: if for any reason admin.js didn’t load (minify/concat/defer, etc.),
 * inject it dynamically and also provide minimal fallbacks for:
 *  - Clicking “Scan Homepage Now”
 *  - Loading the Past Reports list
 */
public function print_fallback_loader() {
    $asset_url = trailingslashit( VL_LAS_URL ) . 'assets/js/admin.js';
    $ver       = defined( 'VL_LAS_VERSION' ) ? VL_LAS_VERSION : '1.0.0';
    ?>
    <script>
    (function(){
      var needInject = (typeof window.VLLAS === 'undefined'); // admin.js didn't localize window.VLLAS

      function joinUrl(base, path){
        if(!base) return path||'';
        return String(base).replace(/\/+$/,'') + '/' + String(path||'').replace(/^\/+/,'');
      }
      function withNonce(url, nonce){
        return url + (url.indexOf('?')>=0 ? '&' : '?') + '_wpnonce=' + encodeURIComponent(nonce||'');
      }

      // ────────────── Fallback: bind Scan button ──────────────
      function bindInlineAudit(){
        var b = document.getElementById('vl-las-run-audit');
        if(!b || b.__vlLasBound) return;
        b.__vlLasBound = true;

        b.addEventListener('click', function(){
          var out   = document.getElementById('vl-las-audit-result');
          var root  = (window.VLLAS && VLLAS.rest && VLLAS.rest.root)  || (b.getAttribute('data-rest-root') || '/wp-json/vl-las/v1');
          var path  = b.getAttribute('data-rest-path') || 'audit2';
          var nonce = (window.VLLAS && VLLAS.rest && VLLAS.rest.nonce) || b.getAttribute('data-nonce') || '';

          if(out){ out.textContent = 'Running…'; out.setAttribute('aria-busy','true'); }
          var html = '<!DOCTYPE html><html><head><title>Probe</title></head><body>ok</body></html>';

          fetch(withNonce(joinUrl(root, path), nonce), {
            method: 'POST',
            headers: {
              'Content-Type':'application/json; charset=utf-8',
              'X-WP-Nonce': nonce,
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ url: location.origin + '/', html: html })
          })
          .then(function(r){ return r.text().then(function(t){ return {status:r.status, text:t}; }); })
          .then(function(x){
            var obj; try{ obj = JSON.parse(x.text); }catch(e){}
            if(out){
              out.innerHTML = '';
              var pre = document.createElement('pre');
              pre.textContent = obj ? JSON.stringify(obj, null, 2) : x.text;
              out.appendChild(pre);
              out.setAttribute('aria-busy','false');
            }
            console.info('[VL_LAS fallback] Audit HTTP', x.status);
          })
          .catch(function(err){
            if(out){ out.textContent = 'Audit error: ' + (err && err.message ? err.message : String(err)); out.setAttribute('aria-busy','false'); }
            console.warn('[VL_LAS fallback] fetch error:', err);
          });
        });
      }

      // ────────────── Fallback: bind Gemini test button ──────────────
      function bindGeminiTestButton(){
        var btn = document.getElementById('vl-las-test-gemini');
        if(!btn || btn.__vlLasGeminiBound) return;
        btn.__vlLasGeminiBound = true;
        
        // Wait a bit for admin class to load and localize script
        setTimeout(function() {
          // Re-check for nonce after delay
          if (!window.VLLAS || !window.VLLAS.rest || !window.VLLAS.rest.nonce) {
            console.warn('[VL_LAS] VLLAS object not found, using fallback nonce detection');
          }
        }, 100);

        btn.addEventListener('click', function(e){
          e.preventDefault();
          e.stopImmediatePropagation();

          var status = document.getElementById('vl-las-gemini-test-status');
          var jsonDiv = document.getElementById('vl-las-gemini-test-json');
          var jsonPre = jsonDiv ? jsonDiv.querySelector('pre') : null;
          
          var root = (window.VLLAS && VLLAS.rest && VLLAS.rest.root) || '/wp-json/vl-las/v1';
          var nonce = (window.VLLAS && VLLAS.rest && VLLAS.rest.nonce) || '';
          
          // Fallback: Try to get nonce from WordPress REST API
          if (!nonce) {
            // Try meta tag first
            var nonceMeta = document.querySelector('meta[name="wp-rest-nonce"]');
            if (nonceMeta) {
              nonce = nonceMeta.getAttribute('content');
            }
            
            // Try wpApiSettings
            if (!nonce && window.wpApiSettings) {
              nonce = window.wpApiSettings.nonce;
            }
            
            // Try to get nonce from WordPress REST API directly
            if (!nonce) {
              // Look for any script tag that might contain nonce
              var scripts = document.querySelectorAll('script');
              for (var i = 0; i < scripts.length; i++) {
                var scriptContent = scripts[i].textContent || scripts[i].innerHTML;
                if (scriptContent && scriptContent.indexOf('wp_rest') !== -1) {
                  var match = scriptContent.match(/"nonce":"([^"]+)"/);
                  if (match) {
                    nonce = match[1];
                    break;
                  }
                }
              }
            }
          }
          
          if (!nonce) {
            // Last resort: try to generate nonce from WordPress REST API
            console.warn('[VL_LAS] No nonce found, attempting to get one from WordPress REST API');
            
            // Try to get nonce from WordPress REST API
            fetch('/wp-json/wp/v2/users/me', {
              method: 'GET',
              credentials: 'same-origin'
            })
            .then(function(response) {
              if (response.ok) {
                // If we can access the API, try to get nonce from response headers
                var restNonce = response.headers.get('X-WP-Nonce');
                if (restNonce) {
                  nonce = restNonce;
                  console.info('[VL_LAS] Got nonce from REST API headers');
                } else {
                  // Generate a basic nonce (this is a fallback)
                  nonce = 'fallback-nonce-' + Date.now();
                  console.warn('[VL_LAS] Using fallback nonce');
                }
              } else {
                nonce = 'fallback-nonce-' + Date.now();
                console.warn('[VL_LAS] Using fallback nonce due to API access failure');
              }
            })
            .catch(function() {
              nonce = 'fallback-nonce-' + Date.now();
              console.warn('[VL_LAS] Using fallback nonce due to network error');
            })
            .finally(function() {
              if (!nonce) {
                if(status){ 
                  status.textContent = 'Failed: No nonce available';
                  status.style.color = 'red';
                  status.setAttribute('aria-busy','false');
                }
                return;
              }
              
              // Continue with the API call using the nonce we found/generated
              proceedWithGeminiTest();
            });
            
            return;
          }
          
          // If we have a nonce, proceed directly
          proceedWithGeminiTest();
          
          function proceedWithGeminiTest() {
            if(status){ 
              status.textContent = 'Testing…'; 
              status.setAttribute('aria-busy','true');
              status.style.color = '';
            }
            if(jsonDiv){ jsonDiv.style.display = 'none'; }
            if(jsonPre){ jsonPre.textContent = ''; }

            fetch(withNonce(joinUrl(root, 'gemini-test'), nonce), {
              method: 'POST',
              headers: {
                'Content-Type':'application/json; charset=utf-8',
                'X-WP-Nonce': nonce,
                'X-Requested-With': 'XMLHttpRequest'
              },
              body: JSON.stringify({})
            })
            .then(function(response){ 
              console.info('[VL_LAS] Gemini Test HTTP', response.status);
              return response.json().catch(function(){ 
                return { ok:false, error:'Invalid JSON from server' }; 
              }); 
            })
            .then(function(resp){
              var ok = resp && resp.ok === true;
              var code = resp && resp.status ? ' ' + resp.status : '';
              if(status){ 
                status.textContent = ok ? 'OK' + code : 'Failed' + code;
                status.style.color = ok ? 'green' : 'red';
                status.setAttribute('aria-busy','false');
              }
              if(jsonPre){
                jsonPre.textContent = JSON.stringify(resp, null, 2);
                if(jsonDiv){ jsonDiv.style.display = 'block'; }
              }
            })
            .catch(function(err){
              if(status){ 
                status.textContent = 'Failed: ' + (err && err.message ? err.message : String(err));
                status.style.color = 'red';
                status.setAttribute('aria-busy','false');
              }
              console.warn('[VL_LAS] Gemini test error:', err);
            });
          }
        });
      }

      // ────────────── Fallback: load Past Reports ──────────────
      function renderReports(host, list){
        host.innerHTML = '';
        if(!list || !list.length){
          host.innerHTML = '<p>No reports yet.</p>';
          return;
        }
        var tbl = document.createElement('table');
        tbl.className = 'widefat striped';
        tbl.innerHTML = '<thead><tr><th>ID</th><th>Date</th><th>URL</th><th>Issues</th></tr></thead><tbody></tbody>';
        var tb = tbl.querySelector('tbody');
        list.forEach(function(it){
          var id   = it.id || it.report_id || '';
          var date = it.created_at || it.date || '';
          try { var d = new Date(date); if(!isNaN(d)) date = d.toLocaleString(); } catch(e){}
          var url  = it.url || '';
          var issues = (typeof it.issues !== 'undefined')
              ? it.issues
              : (it.counts && typeof it.counts.fail !== 'undefined' ? it.counts.fail
                 : (it.summary && typeof it.summary.passed !== 'undefined' && typeof it.summary.total !== 'undefined'
                    ? (it.summary.total - it.summary.passed) : ''));
          var tr = document.createElement('tr');
          tr.innerHTML = '<td>'+id+'</td><td>'+date+'</td><td>'+url+'</td><td>'+issues+'</td>';
          tb.appendChild(tr);
        });
        host.appendChild(tbl);
      }

      function fallbackLoadReports(){
        var host = document.getElementById('vl-las-audit-list');
        if(!host) return;
        var btn   = document.getElementById('vl-las-run-audit');
        var root  = (window.VLLAS && VLLAS.rest && VLLAS.rest.root)  || (btn && btn.getAttribute('data-rest-root')) || '/wp-json/vl-las/v1';
        var nonce = (window.VLLAS && VLLAS.rest && VLLAS.rest.nonce) || (btn && btn.getAttribute('data-nonce')) || '';

        host.innerHTML = '<p>Loading reports…</p>';

        fetch(withNonce(joinUrl(root, 'reports?per_page=20'), nonce), {
          method: 'GET',
          headers: { 'X-WP-Nonce': nonce, 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r){ return r.json(); })
        .then(function(resp){
          var list = (resp && resp.ok && Array.isArray(resp.items)) ? resp.items :
                     (Array.isArray(resp) ? resp : []);
          renderReports(host, list);
          console.info('[VL_LAS fallback] Reports loaded');
        })
        .catch(function(err){
          host.innerHTML = '<p>Failed to load reports.</p>';
          console.warn('[VL_LAS fallback] reports error:', err);
        });
      }

      // If needed, inject admin.js (still keep fallbacks for safety)
      if (needInject) {
        var s = document.createElement('script');
        s.src = '<?php echo esc_js( $asset_url ); ?>?v=<?php echo esc_js( $ver ); ?>&cb=' + Date.now();
        s.async = false;
        s.onload = function(){
          console.info('[VL_LAS fallback] admin.js injected');
          bindInlineAudit();
          bindGeminiTestButton();
          fallbackLoadReports();
        };
        document.head.appendChild(s);
      } else {
        // admin.js present → still ensure both fallbacks are bound (harmless no-ops if admin.js already did it)
        bindInlineAudit();
        bindGeminiTestButton();
        fallbackLoadReports();
      }
    })();
    </script>
    <?php
}


    /**
     * Add settings page under Settings.
     */
    public function add_menu() {
        add_options_page(
            __( 'VL Language & Accessibility', 'vl-las' ),
            __( 'VL Language & Accessibility', 'vl-las' ),
            'manage_options',
            'vl-las',
            array( $this, 'render_settings' )
        );
    }

    /**
     * Help/usage content for the Languages section.
     */
    public function languages_help() {
        ?>
        <div class="notice notice-info" style="margin:10px 0;">
            <p><strong><?php esc_html_e('How to use language detection & translation', 'vl-las'); ?></strong></p>
            <ol style="margin-left:20px;">
                <li><strong><?php esc_html_e('Manual choice wins', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('Visitors can choose a language via your switcher (cookie saved).', 'vl-las'); ?>
                </li>
                <li><strong><?php esc_html_e('URL param for testing', 'vl-las'); ?>:</strong>
                    <code>?vl_lang=es</code>
                    <?php esc_html_e('forces Spanish and sets the cookie.', 'vl-las'); ?>
                </li>
                <li><strong><?php esc_html_e('Browser preference (optional)', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('If enabled below, we use Accept-Language only when no manual choice exists.', 'vl-las'); ?>
                </li>
                <li><strong><?php esc_html_e('HTML lang (optional)', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('If enabled, the current language is reflected in the <html lang> attribute for a11y/SEO.', 'vl-las'); ?>
                </li>
                <li><strong><?php esc_html_e('Inline translation shortcode', 'vl-las'); ?>:</strong>
                    <div style="margin-top:6px;">
                        <code>[vl_t]Welcome to our site![/vl_t]</code><br/>
                        <code>[vl_t lang="es"]Override to force Spanish here[/vl_t]</code>
                    </div>
                    <em><?php esc_html_e('Requires a valid Gemini 2.5 API key and the “Translate with Gemini 2.5” toggle enabled.', 'vl-las'); ?></em>
                </li>
            </ol>
        </div>
        <?php
    }

    /**
     * Help/usage content for the Compliance section.
     */
    public function compliance_help() {
        ?>
        <div class="notice notice-info" style="margin:10px 0;">
            <p><strong><?php esc_html_e('How to use cookie banner & legal translations', 'vl-las'); ?></strong></p>
            <ul style="margin-left:20px; list-style:disc;">
                <li><strong><?php esc_html_e('Cookie Banner Message', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('Enter your short message below. If “Auto-translate Cookie Banner Message” is enabled, it is translated to the visitor’s language.', 'vl-las'); ?>
                </li>
                <li><strong><?php esc_html_e('Translated legal shortcodes (opt-in)', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('Use these variants on pages where you want translated output:', 'vl-las'); ?>
                    <div style="margin-top:6px;">
                        <code>[vl_privacy_policy_t]</code><br/>
                        <code>[vl_terms_t]</code><br/>
                        <code>[vl_copyright_t]</code><br/>
                        <code>[vl_data_privacy_t]</code><br/>
                        <code>[vl_cookie_t]</code>
                    </div>
                    <em><?php esc_html_e('They respect the master “Translate with Gemini 2.5” toggle.', 'vl-las'); ?></em>
                </li>
                <li><strong><?php esc_html_e('Styling', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('Buttons/links keep your Customizer CSS. The message text can be styled with', 'vl-las'); ?>
                    <code>.vl-las-cookie__message</code>.
                </li>
            </ul>
        </div>
        <?php
    }

    /**
     * Help/usage content for SOC 2 automation.
     */
    public function soc2_help() {
        ?>
        <div class="notice notice-info" style="margin:10px 0;">
            <p><strong><?php esc_html_e( 'Enterprise SOC 2 automation overview', 'vl-las' ); ?></strong></p>
            <ul style="margin-left:20px; list-style:disc;">
                <li><?php esc_html_e( 'Ensure your Corporate License Code is saved so the plugin can authenticate with the VL Hub.', 'vl-las' ); ?></li>
                <li><?php esc_html_e( 'Click “Sync & Generate SOC 2 Report” to pull the latest controls, evidence, and risk data into WordPress.', 'vl-las' ); ?></li>
                <li><?php esc_html_e( 'Download the JSON or Markdown package to hand off to executive stakeholders, auditors, or investors.', 'vl-las' ); ?></li>
            </ul>
        </div>
        <?php
    }

    /**
     * Register settings, sections, and fields.
     */
    public function register_settings() {

        // Sections (page slug = 'vl-las') — using callbacks for help panels
        add_settings_section( 'vl_las_languages',     __( 'Languages', 'vl-las' ),    array( $this, 'languages_help' ), 'vl-las' );
        add_settings_section( 'vl_las_compliance',    __( 'Compliance', 'vl-las' ),   array( $this, 'compliance_help' ), 'vl-las' );
        add_settings_section( 'vl_las_accessibility', __( 'Accessibility', 'vl-las' ),  null, 'vl-las' );
        add_settings_section( 'vl_las_security',      __( 'Security & License', 'vl-las' ), null, 'vl-las' );
        add_settings_section( 'vl_las_audit',         __( 'Audit (WCAG 2.1 AA)', 'vl-las' ), null, 'vl-las' );
        add_settings_section( 'vl_las_soc2',          __( 'SOC 2 Type II Automation', 'vl-las' ), array( $this, 'soc2_help' ), 'vl-las' );

        /**
         * Languages: list + Gemini 2.5 + detection + translate toggles
         */
        register_setting( 'vl-las', 'vl_las_languages',         array( $this, 'sanitize_array' ) );
        register_setting( 'vl-las', 'vl_las_gemini_api_key',    array( $this, 'sanitize_text' ) );
        register_setting( 'vl-las', 'vl_las_lang_detect',       array( $this, 'sanitize_int' ) );
        register_setting( 'vl-las', 'vl_las_apply_html_lang',   array( $this, 'sanitize_int' ) );
        register_setting( 'vl-las', 'vl_las_translate_enable',  array( $this, 'sanitize_int' ) );

        add_settings_field(
            'vl_las_languages_field',
            __( 'Available Languages', 'vl-las' ),
            array( $this, 'languages_field' ),
            'vl-las',
            'vl_las_languages'
        );

        add_settings_field(
            'vl_las_gemini',
            __( 'Gemini 2.5 API Key', 'vl-las' ),
            array( $this, 'text_field' ),
            'vl-las',
            'vl_las_languages',
            array(
                'key'         => 'gemini_api_key',
                'placeholder' => 'AIza...',
            )
        );

        // Validate Gemini Key (button + status area)
        add_settings_field(
            'vl_las_gemini_test',
            __( 'Validate Gemini API Key', 'vl-las' ),
            function () {
                $raw    = trim( (string) get_option( 'vl_las_gemini_api_key', '' ) );
                $masked = $raw ? ( '••••' . substr( $raw, -4 ) ) : __( '(no key saved)', 'vl-las' );
                echo '<p style="margin:0 0 6px 0;">' . sprintf( esc_html__( 'Saved key: %s', 'vl-las' ), '<code>'.$masked.'</code>' ) . '</p>';
                echo '<button type="button" class="button" id="vl-las-test-gemini">'. esc_html__( 'Run Test', 'vl-las' ) .'</button> ';
                echo '<span id="vl-las-gemini-test-status" style="margin-left:8px;"></span>';
                echo '<div id="vl-las-gemini-test-json" style="margin-top:8px; display:none;"><pre style="max-height:220px; overflow:auto;"></pre></div>';
                echo '<p class="description">'. esc_html__( 'Checks connectivity and returns the API response status. No page content is sent.', 'vl-las' ) .'</p>';
            },
            'vl-las',
            'vl_las_languages'
        );

        add_settings_field(
            'vl_las_lang_detect',
            __( 'Auto-detect by Browser', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_languages',
            array(
                'key'   => 'lang_detect',
                'label' => __( 'Use browser “Accept-Language” if no language is chosen yet', 'vl-las' ),
            )
        );

        add_settings_field(
            'vl_las_apply_html_lang',
            __( 'Apply to <html lang>', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_languages',
            array(
                'key'   => 'apply_html_lang',
                'label' => __( 'Reflect the current language in the <html lang> attribute', 'vl-las' ),
            )
        );

        add_settings_field(
            'vl_las_translate_enable',
            __( 'Translate with Gemini 2.5', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_languages',
            array(
                'key'   => 'translate_enable',
                'label' => __( 'Enable on-the-fly translation where used', 'vl-las' ),
            )
        );

        /**
         * Compliance: legal texts + cookie banner + translate toggles
         */
        $legal_fields = array(
            'data_privacy'   => __( 'Data Privacy Laws Disclosure', 'vl-las' ),
            'privacy_policy' => __( 'Privacy Policy', 'vl-las' ),
            'cookie'         => __( 'Cookie Consent Disclosure', 'vl-las' ),
            'terms'          => __( 'Terms and Conditions', 'vl-las' ),
            'copyright'      => __( 'Copyright Notice', 'vl-las' ),
        );

        foreach ( $legal_fields as $key => $label ) {
            register_setting( 'vl-las', 'vl_las_legal_' . $key, array( $this, 'sanitize_html' ) );
            add_settings_field(
                'vl_las_legal_' . $key,
                $label . ' ' . sprintf( esc_html__( '(Shortcode: [%s])', 'vl-las' ), 'vl_' . $key ),
                array( $this, 'textarea_field' ),
                'vl-las',
                'vl_las_compliance',
                array( 'key' => 'legal_' . $key )
            );
        }

        // Cookie Banner Message
        register_setting(
            'vl-las',
            'vl_las_cookie_message',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'wp_kses_post',
                'default'           => '',
            )
        );

        add_settings_field(
            'vl_las_cookie_message',
            __( 'Cookie Banner Message', 'vl-las' ),
            function() {
                $val = get_option( 'vl_las_cookie_message', '' );
                echo '<textarea name="vl_las_cookie_message" rows="3" class="large-text" placeholder="' .
                    esc_attr__( 'Short message shown on the banner (left of Accept).', 'vl-las' ) .
                    '">'. esc_textarea( $val ) .'</textarea>';
                echo '<p class="description">' .
                    esc_html__( 'Keep it short. You can style its color in Customizer → Additional CSS using', 'vl-las' ) .
                    ' <code>.vl-las-cookie__message</code>.</p>';
            },
            'vl-las',
            'vl_las_compliance'
        );

        // Cookie consent controls
        register_setting( 'vl-las', 'vl_las_cookie_consent_enabled', array( $this, 'sanitize_int' ) );
        register_setting( 'vl-las', 'vl_las_cookie_visibility',      array( $this, 'sanitize_text' ) );
        register_setting( 'vl-las', 'vl_las_cookie_position',        array( $this, 'sanitize_text' ) );

        add_settings_field(
            'vl_las_cookie_enabled',
            __( 'Cookie Consent', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_compliance',
            array(
                'key'   => 'cookie_consent_enabled',
                'label' => __( 'Enable Cookie Consent Banner', 'vl-las' ),
            )
        );

        add_settings_field(
            'vl_las_cookie_visibility',
            __( 'Banner Visibility', 'vl-las' ),
            array( $this, 'select_field' ),
            'vl-las',
            'vl_las_compliance',
            array(
                'key'     => 'cookie_visibility',
                'options' => array(
                    'show' => __( 'Show', 'vl-las' ),
                    'hide' => __( 'Hide', 'vl-las' ),
                ),
            )
        );

        add_settings_field(
            'vl_las_cookie_position',
            __( 'Banner Position', 'vl-las' ),
            array( $this, 'select_field' ),
            'vl-las',
            'vl_las_compliance',
            array(
                'key'     => 'cookie_position',
                'options' => array(
                    'bottom-left'  => __( 'Bottom Left', 'vl-las' ),
                    'bottom-right' => __( 'Bottom Right', 'vl-las' ),
                ),
            )
        );

        // Translate toggles (compliance)
        register_setting( 'vl-las', 'vl_las_translate_cookie', array( $this, 'sanitize_int' ) );
        register_setting( 'vl-las', 'vl_las_translate_legal',  array( $this, 'sanitize_int' ) );

        add_settings_field(
            'vl_las_translate_cookie',
            __( 'Auto-translate Cookie Banner Message', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_compliance',
            array(
                'key'   => 'translate_cookie',
                'label' => __( 'Translate the banner message to the visitor’s language', 'vl-las' ),
            )
        );

        add_settings_field(
            'vl_las_translate_legal',
            __( 'Auto-translate Legal Docs (shortcodes)', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_compliance',
            array(
                'key'   => 'translate_legal',
                'label' => __( 'When using the “*_t” legal shortcodes, translate output to visitor’s language', 'vl-las' ),
            )
        );

        /**
         * Accessibility
         */
        register_setting( 'vl-las', 'vl_las_high_contrast', array( $this, 'sanitize_int' ) );
        add_settings_field(
            'vl_las_high_contrast',
            __( 'Native high-contrast CSS', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_accessibility',
            array(
                'key'   => 'high_contrast',
                'label' => __( 'Enable sitewide high-contrast stylesheet', 'vl-las' ),
            )
        );

        /**
         * Security & License
         */
        register_setting( 'vl-las', 'vl_las_license_code', array( $this, 'sanitize_text' ) );
        add_settings_field(
            'vl_las_license_code',
            __( 'Corporate License Code', 'vl-las' ),
            array( $this, 'text_field' ),
            'vl-las',
            'vl_las_security',
            array(
                'key'         => 'license_code',
                'placeholder' => __( 'Enter your Corporate License Code...', 'vl-las' ),
            )
        );

        /**
         * Audit UI (engine + button + JSON toggle + past reports list)
         */

        // Persisted options
        register_setting( 'vl-las', 'vl_las_audit_engine', array( $this, 'sanitize_audit_engine' ) ); // 0 off, 1 diag, 2 regex
        register_setting( 'vl-las', 'vl_las_audit_show_json', array( $this, 'sanitize_int' ) );

        // Engine radios
        add_settings_field(
            'vl_las_audit_engine',
            __( 'Audit Engine', 'vl-las' ),
            function(){
                $val  = (int) get_option( 'vl_las_audit_engine', 2 );
                $opts = array(
                    0 => __( 'Off', 'vl-las' ),
                    1 => __( 'Diagnostics (safe echo)', 'vl-las' ),
                    2 => __( 'Regex-only (recommended)', 'vl-las' ),
                );
                echo '<fieldset>';
                foreach ( $opts as $k => $label ) {
                    printf(
                        '<label style="display:block;margin:2px 0;"><input type="radio" name="vl_las_audit_engine" value="%1$d" %3$s> %2$s</label>',
                        (int) $k,
                        esc_html( $label ),
                        checked( $val, $k, false )
                    );
                }
                echo '</fieldset>';
            },
            'vl-las',
            'vl_las_audit'
        );

        // Show raw JSON toggle
        add_settings_field(
            'vl_las_audit_show_json',
            __( 'Show raw JSON', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_audit',
            array(
                'key'   => 'audit_show_json',
                'label' => __( 'Display raw JSON under results', 'vl-las' ),
            )
        );

        // Run button + results (use a named method to avoid any closure edge-cases)
        add_settings_field(
            'vl_las_audit_btn',
            __( 'Run Accessibility Audit', 'vl-las' ),
            array( $this, 'audit_button_field' ),
            'vl-las',
            'vl_las_audit'
        );

        // Past reports list container (admin.js fills via REST)
        add_settings_field(
            'vl_las_audit_list',
            __( 'Past Reports', 'vl-las' ),
            function(){
                echo '<div id="vl-las-audit-list"></div>';
            },
            'vl-las',
            'vl_las_audit'
        );

        /**
         * SOC 2 automation
         */
        register_setting( 'vl-las', 'vl_las_soc2_enabled', array( $this, 'sanitize_int' ) );
        register_setting(
            'vl-las',
            'vl_las_soc2_endpoint',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default'           => 'https://hub.visiblelight.ai/api/soc2/snapshot',
            )
        );

        add_settings_field(
            'vl_las_soc2_enabled',
            __( 'Enable SOC 2 Automation', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_soc2',
            array(
                'key'   => 'soc2_enabled',
                'label' => __( 'Allow the plugin to sync SOC 2 evidence from the VL Hub', 'vl-las' ),
            )
        );

        add_settings_field(
            'vl_las_soc2_endpoint',
            __( 'VL Hub SOC 2 Endpoint', 'vl-las' ),
            array( $this, 'text_field' ),
            'vl-las',
            'vl_las_soc2',
            array(
                'key'         => 'soc2_endpoint',
                'placeholder' => 'https://hub.visiblelight.ai/api/soc2/snapshot',
            )
        );

        add_settings_field(
            'vl_las_soc2_runner',
            __( 'Enterprise Report Generator', 'vl-las' ),
            array( $this, 'soc2_run_field' ),
            'vl-las',
            'vl_las_soc2'
        );
    }

    // ----------------------------
    // Sanitize helpers
    // ----------------------------
    public function sanitize_text( $val ) {
        return sanitize_text_field( $val );
    }

    public function sanitize_int( $val ) {
        return (int) $val ? 1 : 0;
    }

    public function sanitize_array( $val ) {
        return is_array( $val ) ? array_map( 'sanitize_text_field', $val ) : array();
    }

    public function sanitize_html( $val ) {
        return wp_kses_post( $val );
    }

    public function sanitize_audit_engine( $val ) {
        $v = is_numeric( $val ) ? (int) $val : 0;
        // Allowed: 0 = Off, 1 = Diagnostics, 2 = Regex-only
        return in_array( $v, array( 0, 1, 2 ), true ) ? $v : 2;
    }

    public function sanitize_soc2_endpoint( $val ) {
        $raw_value = trim( (string) $val );
        $default   = defined( 'VL_LAS_SOC2_ENDPOINT_DEFAULT' )
            ? VL_LAS_SOC2_ENDPOINT_DEFAULT
            : 'https://hub.visiblelight.ai/api/soc2/snapshot';
        $previous  = get_option( 'vl_las_soc2_endpoint', $default );

        if ( '' === $raw_value ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_empty',
                __( 'Provide the SOC 2 snapshot endpoint assigned to your organization by Visible Light.', 'vl-las' )
            );
            return $previous;
        }

        $url = esc_url_raw( $raw_value );
        if ( '' === $url ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_invalid',
                __( 'Enter a valid HTTPS URL for the VL Hub SOC 2 endpoint.', 'vl-las' )
            );
            return $previous;
        }

        $parts = wp_parse_url( $url );
        if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_parts',
                __( 'The SOC 2 endpoint must include a hostname and path.', 'vl-las' )
            );
            return $previous;
        }

        if ( 'https' !== strtolower( $parts['scheme'] ) ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_scheme',
                __( 'The SOC 2 endpoint must use HTTPS.', 'vl-las' )
            );
            return $previous;
        }

        if ( empty( $parts['path'] ) || false === strpos( $parts['path'], '/soc2' ) ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_path',
                __( 'The SOC 2 endpoint should point to the /api/soc2/snapshot service provided by Visible Light.', 'vl-las' )
            );
            return $previous;
        }

        $headers = array( 'Accept' => 'application/json' );
        $license = trim( (string) get_option( 'vl_las_license_code', '' ) );
        if ( '' !== $license ) {
            $headers['X-VL-License'] = $license;
        }

        $response = wp_remote_get( $url, array(
            'timeout'     => 10,
            'redirection' => 3,
            'headers'     => $headers,
        ) );

        if ( is_wp_error( $response ) ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_unreachable',
                sprintf(
                    /* translators: %s: WordPress HTTP error message. */
                    __( 'Could not connect to the VL Hub endpoint: %s', 'vl-las' ),
                    $response->get_error_message()
                )
            );
            return $previous;
        }

        $code       = (int) wp_remote_retrieve_response_code( $response );
        $valid_codes = apply_filters(
            'vl_las_soc2_endpoint_valid_codes',
            array( 200, 201, 202, 203, 204, 206, 401, 403 )
        );

        if ( ! in_array( $code, $valid_codes, true ) ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_http',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Unexpected response from the VL Hub endpoint (HTTP %d).', 'vl-las' ),
                    $code
                )
            );
            return $previous;
        }

        if ( $code >= 400 ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_auth',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Endpoint reachable (HTTP %d). Confirm your Corporate License Code with Visible Light.', 'vl-las' ),
                    $code
                ),
                'updated'
            );
        } else {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_ok',
                __( 'Connection to the VL Hub SOC 2 endpoint succeeded.', 'vl-las' ),
                'updated'
            );
        }

        return $url;
    }

    // ----------------------------
    // Field renderers
    // ----------------------------
    public function render_settings() {
        include VL_LAS_PATH . 'admin/views/settings-page.php';
    }

    public function languages_field() {
        $langs = self::languages_list();
        $saved = (array) get_option( 'vl_las_languages', array( 'English' ) );

        echo '<div class="vl-las-grid">';
        foreach ( $langs as $l ) {
            $checked = in_array( $l, $saved, true ) ? 'checked' : '';
            printf(
                '<label><input type="checkbox" name="vl_las_languages[]" value="%1$s" %3$s> %2$s</label>',
                esc_attr( $l ),
                esc_html( $l ),
                $checked
            );
        }
        echo '</div>';

        echo '<p class="description">' .
            esc_html__( 'Optionally uses your Gemini 2.5 API key to power language detection/translation utilities in shortcodes and future features.', 'vl-las' ) .
            '</p>';
    }

    public function text_field( $args ) {
        $key = $args['key'];
        $val = get_option( 'vl_las_' . $key, '' );

        printf(
            '<input type="text" name="vl_las_%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
            esc_attr( $key ),
            esc_attr( $val ),
            isset( $args['placeholder'] ) ? esc_attr( $args['placeholder'] ) : ''
        );
    }

    public function soc2_endpoint_field() {
        $default = defined( 'VL_LAS_SOC2_ENDPOINT_DEFAULT' )
            ? VL_LAS_SOC2_ENDPOINT_DEFAULT
            : 'https://hub.visiblelight.ai/api/soc2/snapshot';
        $value = get_option( 'vl_las_soc2_endpoint', $default );

        printf(
            '<input type="url" name="vl_las_soc2_endpoint" value="%1$s" class="regular-text code" placeholder="%2$s" pattern="https://.*" />',
            esc_attr( $value ),
            esc_attr( $default )
        );

        echo '<p class="description">' . esc_html__( 'Provide the tenant-specific SOC 2 snapshot URL issued by Visible Light. The plugin expects a secure endpoint hosted on the VL Hub.', 'vl-las' ) . '</p>';
        printf(
            '<p class="description">%s</p>',
            wp_kses_post(
                sprintf(
                    /* translators: %s: example SOC 2 endpoint URL */
                    __( 'Example endpoint: %s (replace the tenant token with the value Visible Light provides).', 'vl-las' ),
                    '<code>https://hub.visiblelight.ai/api/soc2/snapshot?tenant=your-company-slug</code>'
                )
            )
        );
        echo '<p class="description">' . esc_html__( 'Saving this field pings the VL Hub immediately to validate the connection.', 'vl-las' ) . '</p>';
    }

    public function textarea_field( $args ) {
        $key = $args['key'];
        $val = get_option( 'vl_las_' . $key, '' );

        printf(
            '<textarea name="vl_las_%1$s" rows="6" class="large-text code">%2$s</textarea>',
            esc_attr( $key ),
            esc_textarea( $val )
        );
    }

    public function checkbox_field( $args ) {
        $key   = $args['key'];
        $label = isset( $args['label'] ) ? $args['label'] : '';
        $val   = (int) get_option( 'vl_las_' . $key, 0 );

        printf(
            '<label><input type="checkbox" name="vl_las_%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr( $key ),
            checked( 1, $val, false ),
            esc_html( $label )
        );
    }

    public function select_field( $args ) {
        $key     = $args['key'];
        $options = (array) $args['options'];
        $val     = get_option( 'vl_las_' . $key, '' );

        echo '<select name="vl_las_' . esc_attr( $key ) . '">';
        foreach ( $options as $k => $label ) {
            printf(
                '<option value="%1$s" %3$s>%2$s</option>',
                esc_attr( $k ),
                esc_html( $label ),
                selected( $val, $k, false )
            );
        }
        echo '</select>';
    }

    /**
     * Field renderer: Audit run button + results container.
     * Uses data-rest-path="audit2" to avoid conflicts with other plugins' /audit routes.
     */
    public function audit_button_field() {
        $rest_root = esc_url_raw( rest_url( 'vl-las/v1' ) );
        $nonce     = wp_create_nonce( 'wp_rest' );

        // Force visible in case an admin stylesheet hides buttons by id.
        echo '<style>#vl-las-run-audit{display:inline-block!important;}</style>';

        echo '<button type="button" class="button button-primary" id="vl-las-run-audit"'
            . ' data-rest-root="' . esc_attr( $rest_root ) . '"'
            . ' data-rest-path="audit2"' // <-- use the working route
            . ' data-nonce="' . esc_attr( $nonce ) . '">'
            . esc_html__( 'Scan Homepage Now', 'vl-las' )
            . '</button>';

        echo '<div id="vl-las-audit-result" style="margin-top:10px;"></div>';

        // Inline fallback click-handler (only binds once).
        ?>
        <script>
        (function(){
          if (window.vlLasAuditInlineBound) return;
          window.vlLasAuditInlineBound = true;

          function joinUrl(base, path){
            if(!base) return path;
            return String(base).replace(/\/+$/,'') + '/' + String(path).replace(/^\/+/,'');
          }

          var btn = document.getElementById('vl-las-run-audit');
          if(!btn) return;

          btn.addEventListener('click', function(){
            var out = document.getElementById('vl-las-audit-result');
            if(out){ out.textContent = 'Running…'; out.setAttribute('aria-busy','true'); }

            var restRoot = btn.getAttribute('data-rest-root') || '/wp-json/vl-las/v1';
            var restPath = btn.getAttribute('data-rest-path') || 'audit2'; // <-- default to audit2
            var nonce    = btn.getAttribute('data-nonce') || '';

            var html = null;
            try {
              var doc = document.documentElement;
              if (doc) html = '<!DOCTYPE html>' + doc.outerHTML;
            } catch(e){}

            fetch(joinUrl(restRoot, restPath), {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json; charset=utf-8',
                'X-WP-Nonce': nonce
              },
              body: JSON.stringify({ url: window.location.origin + '/', html: html })
            }).then(function(r){
              return r.json().catch(function(){ return { ok:false, error:'Invalid JSON from server' }; });
            }).then(function(resp){
              var text = JSON.stringify((resp && resp.ok && resp.report) ? resp.report : resp, null, 2);
              if(out){
                var pre = document.createElement('pre');
                pre.textContent = text;
                out.innerHTML = '';
                out.appendChild(pre);
              }
            }).catch(function(err){
              if(out){ out.textContent = 'Audit request error: ' + (err && err.message ? err.message : String(err)); }
            }).finally(function(){
              if(out){ out.setAttribute('aria-busy','false'); }
            });
          });
        })();
        </script>
        <?php
    }

    /**
     * Render the SOC 2 automation controls.
     */
    public function soc2_run_field() {
        $rest_root = esc_url_raw( rest_url( 'vl-las/v1' ) );
        $nonce     = wp_create_nonce( 'wp_rest' );

        $bundle = class_exists( 'VL_LAS_SOC2' ) ? \VL_LAS_SOC2::get_cached_bundle() : array();
        $bundle['enabled'] = (bool) get_option( 'vl_las_soc2_enabled', 0 );
        $meta   = isset( $bundle['meta'] ) && is_array( $bundle['meta'] ) ? $bundle['meta'] : array();
        $report = isset( $bundle['report'] ) && is_array( $bundle['report'] ) ? $bundle['report'] : array();

        $last_generated = isset( $meta['generated_at'] ) ? sanitize_text_field( $meta['generated_at'] ) : '';
        $trusts         = array();
        if ( ! empty( $meta['trust_services'] ) && is_array( $meta['trust_services'] ) ) {
            foreach ( $meta['trust_services'] as $tsc ) {
                $trusts[] = sanitize_text_field( $tsc );
            }
        }

        $has_report   = ! empty( $report );
        $trust_summary = $trusts ? implode( ', ', $trusts ) : __( 'baseline criteria', 'vl-las' );
        $status_text   = $has_report
            ? sprintf(
                /* translators: 1: generated time, 2: trust services criteria list */
                __( 'Last generated on %1$s covering %2$s.', 'vl-las' ),
                $last_generated,
                $trust_summary
            )
            : __( 'No SOC 2 report generated yet.', 'vl-las' );

        echo '<p class="description">' . esc_html__( 'Runs a full SOC 2 Type II sync from the VL Hub and prepares an executive-ready package.', 'vl-las' ) . '</p>';

        echo '<p>';
        echo '<button type="button" class="button button-primary" id="vl-las-soc2-run"';
        echo ' data-rest-root="' . esc_attr( $rest_root ) . '"';
        echo ' data-rest-path="soc2/run"';
        echo ' data-nonce="' . esc_attr( $nonce ) . '">';
        echo esc_html__( 'Sync & Generate SOC 2 Report', 'vl-las' );
        echo '</button> ';

        $disabled = $has_report ? '' : ' disabled="disabled"';
        echo '<button type="button" class="button" id="vl-las-soc2-download-json"' . $disabled . '>' . esc_html__( 'Download JSON', 'vl-las' ) . '</button> ';
        echo '<button type="button" class="button" id="vl-las-soc2-download-markdown"' . $disabled . '>' . esc_html__( 'Download Markdown', 'vl-las' ) . '</button>';
        echo '</p>';

        echo '<div id="vl-las-soc2-status" style="margin-top:8px;">' . esc_html( $status_text ) . '</div>';
        echo '<div id="vl-las-soc2-report" class="vl-las-soc2-report" style="margin-top:12px;"></div>';
        echo '<details id="vl-las-soc2-raw" style="margin-top:12px; display:none;">'
            . '<summary>' . esc_html__( 'Show raw SOC 2 JSON', 'vl-las' ) . '</summary>'
            . '<pre style="max-height:340px; overflow:auto;"></pre>'
            . '</details>';

        echo '<script>window.VLLAS = window.VLLAS || {}; window.VLLAS.soc2Initial = ' . wp_json_encode( $bundle ) . ';</script>';

        if ( $has_report ) {
            $raw_json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            echo '<script>window.VLLAS.soc2InitialRaw = ' . wp_json_encode( $raw_json ) . ';</script>';
        } else {
            echo '<script>window.VLLAS.soc2InitialRaw = null;</script>';
        }
    }

    /**
     * Normalized language list (duplicates/typos fixed).
     */
    public static function languages_list() {
        return array(
            'English',
            'Spanish',
            'Arabic',
            'Russian',
            'Vietnamese',
            'Tagalog',
            'German',
            'French',
            'Mandarin',
            'Cantonese',
            'Chinese',
            'Portuguese',
            'Japanese',
            'Telugu',
            'Polish',
            'Italian',
            'Hindi',
            'Bengali',
            'Urdu',
        );
    }
}
