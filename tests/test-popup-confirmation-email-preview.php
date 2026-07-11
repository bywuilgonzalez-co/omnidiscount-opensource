<?php
/**
 * Standalone smoke test for PopupController's confirmation-email builder
 * (src/Controllers/PopupController.php) -- covers the extract-refactor that
 * pulled the $body-building logic out of send_confirmation_email() into a
 * new, reusable build_confirmation_email_html(), shared with the admin
 * "Correo de confirmación" live preview REST endpoint (preview_email()).
 *
 * No PHPUnit, no WooCommerce, no database -- same style as
 * tests/test-popup-controller.php: minimal WP/WC shims in the global
 * namespace (PHP falls back to the global namespace for any unqualified
 * function call not found in the calling function's own namespace, which is
 * how PopupController.php's unqualified __()/esc_html()/WC()/etc. calls
 * resolve here), a stub WC()->mailer() that records what it was asked to
 * wrap/send, and hard-failing assert helpers.
 *
 * build_confirmation_email_html()/resolve_confirmation_email_texts() are
 * both `private static` -- exercised via \ReflectionMethod::setAccessible(),
 * same technique tests/test-cartcontroller-welcome-verification.php already
 * uses for CartController::should_show_identity_document_field().
 *
 * Unlike test-popup-controller.php's shims (deliberately identity/no-op
 * stand-ins for __()/esc_html()/esc_url() -- that file isn't testing
 * escaping), THIS file's esc_html()/esc_html__()/esc_url() do REAL
 * htmlspecialchars()-based escaping, because scenario (c) below specifically
 * proves the builder escapes attacker-controlled settings values rather than
 * echoing them raw.
 *
 * Coverage:
 *   (a) build_confirmation_email_html() produces byte-identical HTML to what
 *       send_confirmation_email() actually sends for the same settings +
 *       confirm URL -- proves the extraction didn't change the real send
 *       path's output at all.
 *   (b) Blank/absent email_subject/email_heading/email_intro fall back to
 *       the exact same hardcoded Spanish defaults resolve_confirmation_email_texts()
 *       and the pre-refactor send_confirmation_email() always used -- no
 *       blank/broken preview when the merchant hasn't typed anything yet.
 *   (c) A settings value containing '<script>alert(1)</script>' (email_intro)
 *       and one containing an HTML-special char sequence (email_heading via
 *       wrap_message's $heading arg, which is NOT escaped by the builder
 *       itself -- it is passed raw to $mailer->wrap_message(), exactly as
 *       the pre-refactor code did) is proven escaped in the $body markup
 *       specifically (email_intro, which the builder DOES esc_html() before
 *       interpolating) -- never injected as raw, executable-looking markup.
 *   (d) A subject containing HTML-special characters resolves through
 *       resolve_confirmation_email_texts() completely unescaped (subjects
 *       are plain email headers, never HTML -- matching the pre-refactor
 *       code, which never escaped $subject either).
 *
 * EXTENDED (custom-HTML confirm/code-reveal emails, render_email_template()):
 *   (e) Custom-HTML confirm-email mode substitutes all 5 {{tokens}}
 *       correctly, each with the RIGHT escaping function for its kind
 *       (esc_url for the link, esc_html for everything else), and a
 *       <script> tag embedded in the template is stripped by the
 *       render-time wp_kses_post() defense-in-depth pass.
 *   (f) email_confirm_wrap_wc_chrome=false skips $mailer->wrap_message()
 *       entirely -- the substituted HTML is used as-is.
 *   (g) build_code_reveal_email_html()/send_code_reveal_email() mirror (a)'s
 *       "builder output === what actually gets sent" proof, for the new
 *       code-reveal email.
 *   (h) Blank email_code_* fields fall back to the real hardcoded defaults,
 *       mirroring (b).
 *   (i) Custom-HTML code-reveal mode substitutes all 6 {{tokens}} correctly
 *       (mirrors (e)), <script> stripped.
 *   (j) email_code_wrap_wc_chrome=false skips wrap_message() (mirrors (f)).
 *   (k) render_email_template() leaves an unknown {{token}} (no matching
 *       key in $vars) untouched as literal text -- never silently removed.
 */

namespace {

    define('ABSPATH', dirname(__DIR__) . '/');

    function assert_same($expected, $actual, $message) {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function assert_true($condition, $message) {
        assert_same(true, (bool)$condition, $message);
    }

    function assert_contains($needle, $haystack, $message) {
        if (false === strpos((string)$haystack, $needle)) {
            fwrite(STDERR, "FAIL: {$message}\nExpected to find: " . var_export($needle, true) . "\nIn: " . var_export($haystack, true) . "\n");
            exit(1);
        }
    }

    function assert_not_contains($needle, $haystack, $message) {
        if (false !== strpos((string)$haystack, $needle)) {
            fwrite(STDERR, "FAIL: {$message}\nExpected NOT to find: " . var_export($needle, true) . "\nIn: " . var_export($haystack, true) . "\n");
            exit(1);
        }
    }

    // --- Minimal WP shims. Real escaping (not identity) -- see file docblock. ---
    function __($text, $domain = null) {
        return $text;
    }
    function esc_html($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
    function esc_html__($text, $domain = null) {
        return esc_html($text);
    }
    function esc_url($url) {
        return htmlspecialchars((string)$url, ENT_QUOTES, 'UTF-8');
    }
    function admin_url($path = '') {
        return 'https://shop.test/wp-admin/' . $path;
    }
    function add_query_arg($args, $url) {
        $sep   = (false === strpos($url, '?')) ? '?' : '&';
        $parts = array();
        foreach ($args as $k => $v) {
            $parts[] = $k . '=' . rawurlencode((string)$v);
        }
        return $url . $sep . implode('&', $parts);
    }
    function home_url($path = '/') {
        return 'https://shop.test' . $path;
    }
    /**
     * Minimal stand-in for WordPress's real wp_kses_post() -- just enough
     * surface to prove the render-time defense-in-depth call actually strips
     * a <script>...</script> block (the one concrete case this file's
     * scenario (e)/(i) prove), NOT a faithful reimplementation of the real,
     * much broader allowed-tags/allowed-attributes filter.
     */
    function wp_kses_post($html) {
        return preg_replace('#<script\b[^>]*>.*?</script>#is', '', (string)$html);
    }
    /** Real store name, deliberately containing an unescaped '&' -- proves {{tienda}} substitution is esc_html()'d, not passed through raw. */
    function get_bloginfo($show = '') {
        return ('name' === $show) ? 'Mi Tienda & Co' : '';
    }

    // --- WC()->mailer() stub -- records exactly what it was asked to wrap/send.
    class DrwTestMailer {
        public static $sent = array();
        public function wrap_message($heading, $body) {
            return '[[HEADING:' . $heading . ']][[BODY:' . $body . ']]';
        }
        public function send($to, $subject, $message, $headers = '', $attachments = array()) {
            self::$sent[] = array('to' => $to, 'subject' => $subject, 'message' => $message);
            return true;
        }
    }
    class DrwTestWC {
        public function mailer() {
            return new DrwTestMailer();
        }
    }
    function WC() {
        return new DrwTestWC();
    }

    require_once dirname(__DIR__) . '/src/Controllers/PopupController.php';

    use Drw\App\Controllers\PopupController;

    /** Reflection helper -- invokes a private static method on PopupController. */
    function invoke_private_static($method, array $args) {
        $reflection = new \ReflectionMethod(PopupController::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $args);
    }

    $confirm_url = 'https://shop.test/wp-admin/admin-ajax.php?action=drw_popup_confirm&token=abc123';

    // === (a) build_confirmation_email_html() matches send_confirmation_email()'s
    // === real output for the same settings + confirm URL. ===========================
    $settings_a = array(
        'email_subject' => 'Asunto A',
        'email_heading' => 'Encabezado A',
        'email_intro'   => 'Intro A',
    );

    $built_html = invoke_private_static('build_confirmation_email_html', array($settings_a, $confirm_url));

    DrwTestMailer::$sent = array();
    invoke_private_static('send_confirmation_email', array('cliente@example.com', 'abc123', $settings_a));
    assert_same(1, count(DrwTestMailer::$sent), '(a) send_confirmation_email() should call mailer->send() exactly once');
    assert_same($built_html, DrwTestMailer::$sent[0]['message'], '(a) build_confirmation_email_html() output must equal what send_confirmation_email() actually sends for identical settings + confirm URL');
    assert_same('Asunto A', DrwTestMailer::$sent[0]['subject'], '(a) send_confirmation_email() must still send the resolved subject unchanged by the refactor');
    assert_true(false !== strpos($built_html, 'Encabezado A'), '(a) sanity: built HTML actually carries the custom heading');
    assert_true(false !== strpos($built_html, 'Intro A'), '(a) sanity: built HTML actually carries the custom intro');

    // === (b) Blank fields fall back to the exact hardcoded Spanish defaults. =======
    $blank_settings = array('email_subject' => '', 'email_heading' => '', 'email_intro' => '');

    $texts_blank = invoke_private_static('resolve_confirmation_email_texts', array($blank_settings));
    assert_same('Confirma tu correo y obtén tu descuento de bienvenida', $texts_blank['subject'], '(b) blank email_subject must fall back to the real default subject');
    assert_same('Ya casi tienes tu descuento', $texts_blank['heading'], '(b) blank email_heading must fall back to the real default heading');
    assert_same('Haz clic en el botón para confirmar tu correo y ver tu código de descuento de bienvenida.', $texts_blank['intro'], '(b) blank email_intro must fall back to the real default intro');

    $html_blank = invoke_private_static('build_confirmation_email_html', array($blank_settings, $confirm_url));
    assert_contains('Ya casi tienes tu descuento', $html_blank, '(b) preview HTML for blank settings must show the real default heading, not a blank one');
    assert_contains('Haz clic en el botón para confirmar tu correo', $html_blank, '(b) preview HTML for blank settings must show the real default intro, not a blank one');

    // Absent keys (not merely empty strings) must resolve identically -- the
    // REST endpoint merges drafts over saved settings, so a field the
    // merchant never touched may simply be missing from the array.
    $texts_absent = invoke_private_static('resolve_confirmation_email_texts', array(array()));
    assert_same($texts_blank, $texts_absent, '(b) a settings array missing the email_* keys entirely must resolve identically to one with them explicitly blank');

    // === (c) Values are escaped in the $body markup, never injected raw. ===========
    $xss_settings = array(
        'email_subject' => 'Asunto normal',
        'email_heading' => 'Encabezado normal',
        'email_intro'   => 'Hola <script>alert(1)</script> & "amigo"',
    );

    $html_xss = invoke_private_static('build_confirmation_email_html', array($xss_settings, $confirm_url));
    assert_not_contains('<script>alert(1)</script>', $html_xss, '(c) a raw <script> tag in email_intro must never appear unescaped in the built HTML');
    assert_contains(htmlspecialchars('<script>alert(1)</script>', ENT_QUOTES, 'UTF-8'), $html_xss, '(c) the email_intro value must appear HTML-escaped in the built HTML');
    assert_contains(htmlspecialchars('&', ENT_QUOTES, 'UTF-8') . ' &quot;amigo&quot;', $html_xss, '(c) special characters (&, ") in email_intro must be escaped, not passed through raw');

    // === (d) Subject is a plain header value, resolved unescaped (matches
    // === pre-refactor behaviour -- subjects were never run through esc_html()). ====
    $texts_subject_html = invoke_private_static('resolve_confirmation_email_texts', array(array(
        'email_subject' => 'Oferta & <b>descuento</b>',
        'email_heading' => '',
        'email_intro'   => '',
    )));
    assert_same('Oferta & <b>descuento</b>', $texts_subject_html['subject'], '(d) subject text is returned exactly as provided, unescaped, matching pre-refactor behaviour');

    // === (e) Custom-HTML confirm email: all 5 {{tokens}} substituted with
    // === the right escaping, and an embedded <script> is stripped. ===============
    $custom_confirm_settings = array(
        'email_confirm_use_custom_html' => true,
        'email_confirm_html'            => '<p>Hola {{correo}} de {{tienda}}!</p>'
            . '<p>Tu descuento: {{descuento}}, vigente {{vigencia_dias}} días.</p>'
            . '<p><a href="{{enlace_confirmacion}}">Confirmar</a></p>'
            . '<script>alert(1)</script>',
        'email_confirm_wrap_wc_chrome'  => true,
        'email_heading'                 => 'Encabezado personalizado',
        'discount_type'                 => 'fixed',
        'discount_value'                => 10000.0,
        'expiry_days'                   => 15,
    );

    $html_custom = invoke_private_static('build_confirmation_email_html', array($custom_confirm_settings, $confirm_url, 'cliente & amigo@example.com'));

    assert_not_contains('<script>', $html_custom, '(e) A <script> tag in email_confirm_html must be stripped by the render-time wp_kses_post() defense-in-depth pass.');
    assert_contains(esc_html('cliente & amigo@example.com'), $html_custom, '(e) {{correo}} must be substituted with the esc_html-escaped recipient email.');
    assert_contains(esc_html(get_bloginfo('name')), $html_custom, '(e) {{tienda}} must be substituted with the esc_html-escaped store name (proves the & is escaped, not passed through raw).');
    assert_contains('$10.000', $html_custom, '(e) {{descuento}} must be substituted with the human discount string for a fixed-amount discount.');
    assert_contains('vigente 15 días', $html_custom, '(e) {{vigencia_dias}} must be substituted with expiry_days.');
    assert_contains(esc_url($confirm_url), $html_custom, '(e) {{enlace_confirmacion}} must be substituted with the esc_url-escaped confirm URL.');
    assert_contains('Encabezado personalizado', $html_custom, '(e) wrap_wc_chrome=true (default) must still pass the resolved heading to wrap_message().');

    // === (f) email_confirm_wrap_wc_chrome=false skips wrap_message() entirely. ===
    $custom_confirm_no_wrap = $custom_confirm_settings;
    $custom_confirm_no_wrap['email_confirm_wrap_wc_chrome'] = false;
    $html_no_wrap = invoke_private_static('build_confirmation_email_html', array($custom_confirm_no_wrap, $confirm_url, 'plain@example.com'));
    assert_not_contains('[[HEADING:', $html_no_wrap, '(f) wrap_wc_chrome=false must skip wrap_message() entirely -- no [[HEADING: marker from the DrwTestMailer stub.');
    assert_contains(esc_html('plain@example.com'), $html_no_wrap, '(f) sanity: substitution still happened even without the WC chrome wrap.');

    // === (g) build_code_reveal_email_html()/send_code_reveal_email(): the
    // === new code-reveal email mirrors (a)'s "builder output === what
    // === actually gets sent" proof. ================================================
    $code_settings_a = array(
        'email_code_subject' => 'Asunto código A',
        'email_code_heading' => 'Encabezado código A',
        'email_code_intro'   => 'Intro código A',
    );
    $store_url = home_url('/');

    $built_code_html = invoke_private_static('build_code_reveal_email_html', array($code_settings_a, 'CODEXYZ', $store_url, 'cliente@example.com'));

    DrwTestMailer::$sent = array();
    invoke_private_static('send_code_reveal_email', array('cliente@example.com', 'CODEXYZ', $code_settings_a));
    assert_same(1, count(DrwTestMailer::$sent), '(g) send_code_reveal_email() should call mailer->send() exactly once');
    assert_same($built_code_html, DrwTestMailer::$sent[0]['message'], '(g) build_code_reveal_email_html() output must equal what send_code_reveal_email() actually sends for identical inputs (send_code_reveal_email() resolves the same home_url(\'/\') store URl internally).');
    assert_same('Asunto código A', DrwTestMailer::$sent[0]['subject'], '(g) send_code_reveal_email() must send the resolved subject.');
    assert_true(false !== strpos($built_code_html, 'Encabezado código A'), '(g) sanity: built HTML carries the custom heading.');
    assert_true(false !== strpos($built_code_html, 'Intro código A'), '(g) sanity: built HTML carries the custom intro.');
    assert_contains(esc_html('CODEXYZ'), $built_code_html, '(g) sanity: the real code appears (escaped) in the simple-mode body.');

    // === (h) Blank email_code_* fields fall back to the real hardcoded defaults. ===
    $code_blank_settings = array('email_code_subject' => '', 'email_code_heading' => '', 'email_code_intro' => '');
    $code_texts_blank    = invoke_private_static('resolve_code_reveal_email_texts', array($code_blank_settings));
    assert_same('Tu código de descuento de bienvenida', $code_texts_blank['subject'], '(h) blank email_code_subject must fall back to the real default subject.');
    assert_same('¡Aquí está tu código!', $code_texts_blank['heading'], '(h) blank email_code_heading must fall back to the real default heading.');
    assert_same('Tu código de descuento de bienvenida ya está listo. Úsalo en tu próxima compra antes de que expire.', $code_texts_blank['intro'], '(h) blank email_code_intro must fall back to the real default intro.');

    $code_texts_absent = invoke_private_static('resolve_code_reveal_email_texts', array(array()));
    assert_same($code_texts_blank, $code_texts_absent, '(h) a settings array missing the email_code_* keys entirely must resolve identically to one with them explicitly blank.');

    // === (i) Custom-HTML code-reveal email: all 6 {{tokens}} substituted
    // === with the right escaping, <script> stripped. ================================
    $custom_code_settings = array(
        'email_code_use_custom_html' => true,
        'email_code_html'            => '<p>Código: {{codigo_descuento}}</p>'
            . '<p>Tienda: {{tienda}}</p>'
            . '<p>Ir a <a href="{{enlace_tienda}}">la tienda</a></p>'
            . '<p>Correo: {{correo}}</p>'
            . '<p>Descuento: {{descuento}} vigente {{vigencia_dias}} días.</p>'
            . '<script>alert(2)</script>',
        'email_code_wrap_wc_chrome'  => true,
        'email_code_heading'         => 'Encabezado código personalizado',
        'discount_type'              => 'percent',
        'discount_value'             => 25.0,
        'expiry_days'                => 30,
    );

    $html_custom_code = invoke_private_static('build_code_reveal_email_html', array($custom_code_settings, 'SECRETCODE', $store_url, 'cliente & amigo@example.com'));

    assert_not_contains('<script>', $html_custom_code, '(i) A <script> tag in email_code_html must be stripped by the render-time wp_kses_post() defense-in-depth pass.');
    assert_contains(esc_html('SECRETCODE'), $html_custom_code, '(i) {{codigo_descuento}} must be substituted, esc_html-escaped.');
    assert_contains(esc_url($store_url), $html_custom_code, '(i) {{enlace_tienda}} must be substituted, esc_url-escaped.');
    assert_contains(esc_html('cliente & amigo@example.com'), $html_custom_code, '(i) {{correo}} must be substituted, esc_html-escaped.');
    assert_contains(esc_html(get_bloginfo('name')), $html_custom_code, '(i) {{tienda}} must be substituted, esc_html-escaped.');
    assert_contains('25%', $html_custom_code, '(i) {{descuento}} must show the human discount string for a percent discount.');
    assert_contains('vigente 30 días', $html_custom_code, '(i) {{vigencia_dias}} must be substituted.');
    assert_contains('Encabezado código personalizado', $html_custom_code, '(i) wrap_wc_chrome=true must still pass the resolved heading to wrap_message().');

    // === (j) email_code_wrap_wc_chrome=false skips wrap_message() for the
    // === code-reveal email too. ========================================================
    $custom_code_no_wrap = $custom_code_settings;
    $custom_code_no_wrap['email_code_wrap_wc_chrome'] = false;
    $html_code_no_wrap = invoke_private_static('build_code_reveal_email_html', array($custom_code_no_wrap, 'SECRETCODE2', $store_url, 'plain2@example.com'));
    assert_not_contains('[[HEADING:', $html_code_no_wrap, '(j) wrap_wc_chrome=false must skip wrap_message() for the code-reveal email too.');
    assert_contains(esc_html('SECRETCODE2'), $html_code_no_wrap, '(j) sanity: substitution still happened even without the WC chrome wrap.');

    // === (k) render_email_template() leaves an unknown {{token}} untouched. =========
    $unknown_token_result = invoke_private_static('render_email_template', array(
        'Hola {{nombre}}, tu código es {{codigo_descuento}}.',
        array('codigo_descuento' => 'ABC123'),
    ));
    assert_same('Hola {{nombre}}, tu código es ABC123.', $unknown_token_result, '(k) An unknown {{token}} with no matching key in $vars must be left as literal text, never silently removed.');

    // === (l) render_email_template() does NOT chain replacements -- a
    // === substituted value that happens to contain another template's
    // === literal {{token}} text must not be re-substituted by a later
    // === pair in the same call (round-2 audit finding: str_replace() with
    // === parallel arrays chains sequentially; strtr() does a single pass
    // === and must not). Regression-tests the exact scenario the audit
    // === constructed: '{' and '}' are valid RFC 5322 email local-part
    // === characters, so a registration address can itself contain the
    // === literal text of another token. =========
    $chaining_result = invoke_private_static('render_email_template', array(
        '{{correo}} / {{tienda}}',
        array(
            'correo' => 'x{{tienda}}@evil.example',
            'tienda' => 'Mi Tienda',
        ),
    ));
    assert_same(
        'x{{tienda}}@evil.example / Mi Tienda',
        $chaining_result,
        '(l) A {{correo}} value containing the literal text "{{tienda}}" must NOT be re-substituted when the {{tienda}} pair is processed -- single-pass semantics, no chaining.'
    );

    echo "All PopupController confirmation-email-preview tests passed.\n";
}
