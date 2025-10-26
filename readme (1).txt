=== VL Language & Accessibility Standards ===
Contributors: visiblelight
Tags: accessibility, wcag, language, compliance, cookie-consent, privacy, legal
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Language, Compliance, Accessibility toolkit with cookie consent banner, legal shortcodes, WCAG audit checks, and optional Gemini 2.5-powered language assistance. Includes Corporate License Code field for secured Hub data.

== Description ==

- Languages: choose supported languages; optional Gemini 2.5 API key (for future translation/detection features).
- Compliance: paste legal texts once and output via shortcodes: [vl_privacy_policy], [vl_terms], [vl_copyright], [vl_data_privacy_laws], [vl_cookie].
- Cookie Consent Banner: enable/disable; choose bottom-left or bottom-right position.
- Accessibility: one-click WCAG-oriented audit of homepage (server-side static checks); optional native high-contrast stylesheet.
- Security & License: Corporate License Code header attached to outbound requests to integrate with your secured Hub.

== Installation ==

Upload and activate. Configure under Settings → VL Language & Accessibility.

== Changelog ==
= 1.0.0 =
* Initial release.

= 1.0.1 =
* Scope high-contrast styles to body class to prevent theme overrides.
* Cookie banner now positions server-side and uses responsive sizing to avoid overflow-x.

= 1.0.2 =
* Add wp_body_open render fallback and admin-bar preview toggle for cookie banner.
* Improve REST audit error messages; more robust REST URL join; send JSON body.
* Add debug logs for cookie banner and respect ?vl_las_preview_cookie=1.

= 1.0.3 =
* Fix activation fatal by ensuring Admin Bar method is inside class.
* Guard audit against missing PHP DOM extension.

= 1.0.4 =
* Remove Admin Bar preview integration to resolve activation fatals.
* Keep cookie render hooks and previous fixes.

= 1.0.6 =
* Wrap REST callbacks (audit & gemini-test) in closures with try/catch to prevent HTTP 500 fatals and always return JSON.
* Guard for missing DOM/XML extension in audit.

= 1.0.7 =
* Admin JS cache-busting via filemtime to ensure latest script loads.
* Audit: more robust loopback request with diagnostics (status code, WP_Error code), probe query param, and safer sslverify=false.

= 1.0.8 =
* Client-side capture mode: admin sends current page HTML to REST audit to avoid host loopback and DOM issues.
* Regex fallback when PHP DOM/XML is unavailable; always returns JSON, never HTTP 500.

= 1.0.9 =
* Replace audit class with clean implementation; fixes PHP syntax error and ensures DOM/regex paths are valid.

= 1.1.0 =
* Switch /audit endpoint to diagnostics-only JSON to eliminate HTTP 500s and reveal environment details. Next step: re-enable full audit after confirming route stability on your host.

= 1.1.1 =
* Re-enable full audit using client-provided HTML (no loopback). Keep diagnostics via ?vl_las_diag=1.
