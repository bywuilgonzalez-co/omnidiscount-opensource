<?php
/**
 * Standalone smoke test for PopupController::csv_safe() (round-3 audit
 * fix -- CSV/formula injection in handle_csv_export()).
 *
 * $row['email'] in the popup submissions CSV export is fully
 * attacker-controlled (only has to pass is_email()/WordPress's own
 * local-part regex, both of which permit a leading =, +, -, or @), and a
 * spreadsheet app that opens the exported file treats any cell starting
 * with one of those four characters as a formula -- classic CSV/DDE
 * injection. csv_safe() is the mitigation: prefix such a cell with a
 * leading single quote before fputcsv() writes it, the standard technique
 * every major spreadsheet app renders as "this is text", stripping it from
 * display.
 *
 * csv_safe() is private, so this test reaches it via Reflection --
 * PHP_only, no PHPUnit, same technique already used elsewhere in this
 * suite (test-cartcontroller-welcome-verification.php, test-promo-bridge.php,
 * etc. all use ReflectionMethod::setAccessible() on private methods).
 *
 * Coverage:
 *   (a) A value starting with '=' is prefixed with a leading single quote.
 *   (b) A value starting with '+' is prefixed.
 *   (c) A value starting with '-' is prefixed.
 *   (d) A value starting with '@' is prefixed.
 *   (e) A completely ordinary email/value is passed through unchanged.
 *   (f) An empty string is passed through unchanged (no out-of-bounds
 *       access on $value[0]).
 *   (g) The dangerous character embedded MID-string (not leading) is left
 *       untouched -- only the leading character matters to a spreadsheet
 *       app's formula detection.
 */

namespace {

    define('ABSPATH', dirname(__DIR__) . '/');

    function assert_same($expected, $actual, $message) {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    // Minimal shims -- PopupController.php is only require_once'd for its
    // class definition here; none of its hook-registration/WP-runtime code
    // paths are exercised by this test (csv_safe() has zero WP dependencies).
    function __($text, $domain = null) { return $text; }

    require_once dirname(__DIR__) . '/src/Controllers/PopupController.php';

    $method = new \ReflectionMethod(\Drw\App\Controllers\PopupController::class, 'csv_safe');
    $method->setAccessible(true);

    $csv_safe = function ($value) use ($method) {
        return $method->invoke(null, $value);
    };

    // === (a)-(d) Leading dangerous character gets prefixed =====================
    assert_same("'=1+1@example.com", $csv_safe('=1+1@example.com'), '(a) A value starting with "=" must be prefixed with a leading single quote.');
    assert_same("'+SUM(1+1)@example.com", $csv_safe('+SUM(1+1)@example.com'), '(b) A value starting with "+" must be prefixed.');
    assert_same("'-2+3@example.com", $csv_safe('-2+3@example.com'), '(c) A value starting with "-" must be prefixed.');
    assert_same("'@SUM(A1:A9)@example.com", $csv_safe('@SUM(A1:A9)@example.com'), '(d) A value starting with "@" must be prefixed.');

    // === (e) Ordinary value passes through unchanged ===========================
    assert_same('cliente@example.com', $csv_safe('cliente@example.com'), '(e) An ordinary email must be passed through completely unchanged.');
    assert_same('issued', $csv_safe('issued'), '(e) An ordinary status value must be passed through unchanged.');

    // === (f) Empty string is safe (no out-of-bounds access) ====================
    assert_same('', $csv_safe(''), '(f) An empty string must be passed through unchanged, never throw/warn on $value[0].');

    // === (g) A dangerous character NOT in leading position is untouched =======
    assert_same('a=b@example.com', $csv_safe('a=b@example.com'), '(g) A "=" that is not the FIRST character must be left completely untouched.');

    echo "PopupController csv_safe() OK\n";
}
