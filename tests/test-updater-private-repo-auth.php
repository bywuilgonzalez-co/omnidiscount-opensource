<?php
/**
 * Standalone smoke test for the private-repo GitHub token support added to
 * Drw\App\Controllers\Updater: get_github_token(), get_download_url()'s
 * asset-URL-selection branching, and pre_download()'s must-not-break-
 * existing-behavior early returns. No PHPUnit, no WooCommerce, no database
 * -- same style as tests/test-rule-value-clamping.php: minimal WP function
 * stubs, private methods reached via reflection, hard-failing assert
 * helpers.
 *
 * Ordering matters here: DRW_GITHUB_TOKEN is a real PHP constant and, once
 * defined(), cannot be undefined again for the rest of the process. So every
 * "no token configured" assertion (the important must-not-break-public-repo-
 * behavior guarantee) runs FIRST, in Phase 1, before DRW_GITHUB_TOKEN is ever
 * defined. Phase 2 then defines it once and covers the "token configured"
 * branches.
 */

define('ABSPATH', dirname(__DIR__) . '/');
define('DRW_PLUGIN_BASENAME', 'discount-rules-woo/discount-rules-woo.php');

function assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/src/Controllers/Updater.php';

use Drw\App\Controllers\Updater;

function drw_get_github_token(Updater $updater) {
    $ref = new ReflectionMethod(Updater::class, 'get_github_token');
    $ref->setAccessible(true);
    return $ref->invoke($updater);
}

function drw_get_download_url(Updater $updater, $release) {
    $ref = new ReflectionMethod(Updater::class, 'get_download_url');
    $ref->setAccessible(true);
    return $ref->invoke($updater, $release);
}

function drw_make_release() {
    $asset = new \stdClass();
    $asset->name = 'discount-rules-woo.zip';
    $asset->url = 'https://api.github.com/repos/bywuilgonzalez-co/omnidiscount-pro/releases/assets/98765';
    $asset->browser_download_url = 'https://github.com/bywuilgonzalez-co/omnidiscount-pro/releases/download/v2.0.0/discount-rules-woo.zip';

    $release = new \stdClass();
    $release->assets = [$asset];
    $release->zipball_url = 'https://api.github.com/repos/bywuilgonzalez-co/omnidiscount-pro/zipball/v2.0.0';
    return $release;
}

$updater = Updater::instance();

// =============================================================================
// Phase 1 -- DRW_GITHUB_TOKEN is NOT defined yet. Every assertion here proves
// the "no token = today's exact unauthenticated public-repo behavior" contract.
// =============================================================================

// (a) get_github_token() returns '' when DRW_GITHUB_TOKEN is undefined.
assert_same('', drw_get_github_token($updater), 'get_github_token() must return empty string when DRW_GITHUB_TOKEN is undefined.');

// (b) get_download_url() prefers browser_download_url when there is no token.
$release = drw_make_release();
assert_same(
    $release->assets[0]->browser_download_url,
    drw_get_download_url($updater, $release),
    'Without a token, get_download_url() must prefer browser_download_url (today\'s exact behavior).'
);

// (c) pre_download(): $reply !== false is passed through unchanged, no matter what.
assert_same(
    'already-resolved-by-another-plugin',
    $updater->pre_download('already-resolved-by-another-plugin', 'https://example.com/whatever.zip', null, ['plugin' => DRW_PLUGIN_BASENAME]),
    'pre_download() must return a non-false $reply unchanged without inspecting anything else.'
);

// (c) pre_download(): $hook_extra['plugin'] does not match this plugin -> unchanged.
assert_same(
    false,
    $updater->pre_download(false, 'https://api.github.com/repos/bywuilgonzalez-co/omnidiscount-pro/releases/assets/98765', null, ['plugin' => 'some-other-plugin/some-other-plugin.php']),
    'pre_download() must return $reply unchanged when $hook_extra[\'plugin\'] does not match DRW_PLUGIN_BASENAME.'
);

// (c) pre_download(): no $hook_extra['plugin'] key at all -> unchanged (not our download).
assert_same(
    false,
    $updater->pre_download(false, 'https://api.github.com/repos/bywuilgonzalez-co/omnidiscount-pro/releases/assets/98765', null, []),
    'pre_download() must return $reply unchanged when $hook_extra has no \'plugin\' key.'
);

// (c) pre_download(): our plugin, matching package URL shape, but NO token defined -> unchanged.
assert_same(
    false,
    $updater->pre_download(false, 'https://api.github.com/repos/bywuilgonzalez-co/omnidiscount-pro/releases/assets/98765', null, ['plugin' => DRW_PLUGIN_BASENAME]),
    'pre_download() must return $reply unchanged when no token is configured, even for our plugin.'
);

// =============================================================================
// Phase 2 -- DRW_GITHUB_TOKEN is now defined. Covers the "token configured"
// branches. This constant can never be undefined again in this process, so
// every Phase 1 assertion above had to run first.
// =============================================================================

define('DRW_GITHUB_TOKEN', 'fake-token-for-tests');

// (a) get_github_token() returns the token value once DRW_GITHUB_TOKEN is defined.
assert_same('fake-token-for-tests', drw_get_github_token($updater), 'get_github_token() must return the DRW_GITHUB_TOKEN constant value when defined.');

// (b) get_download_url() prefers the API asset "url" over browser_download_url when a token is present.
$release_with_token = drw_make_release();
assert_same(
    $release_with_token->assets[0]->url,
    drw_get_download_url($updater, $release_with_token),
    'With a token, get_download_url() must prefer the GitHub API asset "url" over browser_download_url.'
);

// (c) pre_download(): our plugin, token configured, but package URL does not match our private repo's API asset shape -> unchanged.
assert_same(
    false,
    $updater->pre_download(false, 'https://github.com/bywuilgonzalez-co/omnidiscount-pro/archive/refs/tags/v2.0.0.zip', null, ['plugin' => DRW_PLUGIN_BASENAME]),
    'pre_download() must return $reply unchanged when the package URL is not our private repo\'s API asset endpoint.'
);

// (d) get_download_url(): defensive fallback -- token present but the matching
// asset is missing its "url" property; must fall back to browser_download_url
// instead of silently skipping the asset and falling through to zipball_url.
$release_missing_api_url = drw_make_release();
$release_missing_api_url->assets[0]->url = '';
assert_same(
    $release_missing_api_url->assets[0]->browser_download_url,
    drw_get_download_url($updater, $release_missing_api_url),
    'With a token, get_download_url() must fall back to browser_download_url when the asset has no "url" property.'
);

// (e) get_download_url(): pre_download()'s anchored URL check (host === api.github.com
// AND path starts with /repos/{GITHUB_REPO}/) rejects a lookalike host even though
// it contains the expected path substring -- hardens the earlier plain strpos() check.
assert_same(
    false,
    $updater->pre_download(false, 'https://evil.example.com/api.github.com/repos/bywuilgonzalez-co/omnidiscount-pro/releases/assets/1', null, ['plugin' => DRW_PLUGIN_BASENAME]),
    'pre_download() must return $reply unchanged when the host is not api.github.com, even if the path contains the expected substring.'
);

// (f) get_download_url(): no assets at all -> falls back to zipball_url.
$release_no_assets = drw_make_release();
$release_no_assets->assets = [];
assert_same(
    $release_no_assets->zipball_url,
    drw_get_download_url($updater, $release_no_assets),
    'get_download_url() must fall back to zipball_url when the release has no assets.'
);

// (g) get_download_url(): no assets and no zipball_url -> returns ''.
$release_empty = drw_make_release();
$release_empty->assets = [];
$release_empty->zipball_url = '';
assert_same(
    '',
    drw_get_download_url($updater, $release_empty),
    'get_download_url() must return an empty string when there are no assets and no zipball_url.'
);

echo "Updater private-repo auth OK\n";
