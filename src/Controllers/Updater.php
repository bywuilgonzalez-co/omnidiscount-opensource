<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class Updater
{
    /**
     * GitHub "owner/repo" slug that serves as the update-distribution channel.
     *
     * This is the private repo going forward. The public repo
     * (bywuilgonzalez-co/omnidiscount-opensource) is frozen and no longer
     * used as an active update source.
     */
    const GITHUB_REPO = 'bywuilgonzalez-co/omnidiscount-pro';

    private static $instance = null;

    /**
     * Singleton instance.
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    /**
     * Register updater hooks.
     */
    public function register_hooks()
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugins_api_handler'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'rename_github_folder'], 10, 4);
        add_filter('upgrader_pre_download', [$this, 'pre_download'], 10, 4);
    }

    /**
     * Check for plugin updates.
     *
     * @param object $transient
     * @return object
     */
    public function check_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ($release && !empty($release->tag_name)) {
            $remote_version = ltrim($release->tag_name, 'vV');
            if (version_compare($remote_version, DRW_VERSION, '>')) {
                $obj = new \stdClass();
                $obj->slug = 'discount-rules-woo';
                $obj->plugin = DRW_PLUGIN_BASENAME;
                $obj->new_version = $remote_version;
                $obj->package = $this->get_download_url($release);
                $obj->url = 'https://github.com/' . self::GITHUB_REPO;

                if (!isset($transient->response)) {
                    $transient->response = [];
                }
                $transient->response[DRW_PLUGIN_BASENAME] = $obj;
            }
        }

        return $transient;
    }

    /**
     * Filter plugins_api to provide custom plugin information.
     *
     * @param false|object|array $res
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public function plugins_api_handler($res, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $res;
        }

        if (!isset($args->slug) || $args->slug !== 'discount-rules-woo') {
            return $res;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $res;
        }

        $remote_version = ltrim($release->tag_name, 'vV');

        $res = new \stdClass();
        $res->name = 'OmniDiscount — Dynamic Pricing & Discount Rules for WooCommerce';
        $res->slug = 'discount-rules-woo';
        $res->version = $remote_version;
        $res->author = '<a href="https://bywuilgonzalez.com">Bywuilgonzalez.com</a>';
        $res->homepage = 'https://github.com/' . self::GITHUB_REPO;
        $download_url = $this->get_download_url($release);
        $res->download_link = $download_url;
        $res->trunk = $download_url;

        // Safely format the release body for changelog
        $changelog = !empty($release->body) ? wp_kses_post(nl2br($release->body)) : '';

        $res->sections = [
            'description' => '100% complete and fully featured dynamic pricing and discount rules plugin for WooCommerce.',
            'changelog'   => $changelog,
        ];

        return $res;
    }

    /**
     * Rename the GitHub extracted folder name back to the correct plugin folder name.
     *
     * @param string $source
     * @param string $remote_source
     * @param object $upgrader
     * @param array  $hook_extra
     * @return string
     */
    public function rename_github_folder($source, $remote_source, $upgrader, $hook_extra = [])
    {
        $is_our_plugin = false;
        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === DRW_PLUGIN_BASENAME) {
            $is_our_plugin = true;
        } elseif (isset($hook_extra['slug']) && $hook_extra['slug'] === 'discount-rules-woo') {
            $is_our_plugin = true;
        } elseif (strpos($source, 'discount-rules-woo') !== false) {
            $is_our_plugin = true;
        }

        if (!$is_our_plugin) {
            return $source;
        }

        $correct_folder_name = 'discount-rules-woo';
        $source_path = rtrim($source, '/\\');
        $target_path = rtrim($remote_source, '/\\') . '/' . $correct_folder_name;

        if ($source_path === $target_path) {
            return $source;
        }

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ($wp_filesystem && $wp_filesystem->move($source_path, $target_path, true)) {
            return trailingslashit($target_path);
        }

        return $source;
    }

    /**
     * Get the best download URL from a release.
     *
     * With a token (private repo), the GitHub REST API asset-download endpoint
     * ("url" property, e.g. https://api.github.com/repos/OWNER/REPO/releases/assets/{id})
     * is the only asset URL variant that honors Authorization + Accept:application/octet-stream
     * for a private repo, so it is preferred over browser_download_url.
     *
     * Without a token (public repo / no-token fallback), behavior is unchanged:
     * browser_download_url is preferred, zipball_url is the fallback.
     *
     * @param object $release
     * @return string
     */
    private function get_download_url($release)
    {
        $token = $this->get_github_token();

        if (!empty($release->assets) && is_array($release->assets)) {
            foreach ($release->assets as $asset) {
                if (empty($asset->name) || substr($asset->name, -4) !== '.zip') {
                    continue;
                }

                if ($token !== '') {
                    if (!empty($asset->url)) {
                        return $asset->url;
                    }
                    // Defensive: the GitHub REST API always includes "url" on
                    // asset objects, but fall back to browser_download_url
                    // rather than silently skipping the asset if it is ever
                    // missing.
                    if (!empty($asset->browser_download_url)) {
                        return $asset->browser_download_url;
                    }
                    continue;
                }

                if (!empty($asset->browser_download_url)) {
                    return $asset->browser_download_url;
                }
            }
        }

        // Fallback to zipball (requires rename_github_folder hook)
        return !empty($release->zipball_url) ? $release->zipball_url : '';
    }

    /**
     * Get the read-only GitHub token baked into the distributed ZIP at
     * release-build time (see src/Config/github-token.php, generated by
     * .github/workflows/build-release.yml -- never committed).
     *
     * An empty return value means "acting as a public/unauthenticated repo":
     * every other method in this class must degrade gracefully to today's
     * exact unauthenticated behavior when this returns ''.
     *
     * @return string
     */
    private function get_github_token()
    {
        if (defined('DRW_GITHUB_TOKEN') && DRW_GITHUB_TOKEN !== '') {
            return DRW_GITHUB_TOKEN;
        }
        return '';
    }

    /**
     * Get the latest release from GitHub API, with caching.
     *
     * @return object|false
     */
    public function get_latest_release()
    {
        $release = get_transient('drw_github_latest_release');
        if (false === $release) {
            $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
            $headers = [
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; DiscountRulesWooUpdater'
            ];

            $token = $this->get_github_token();
            if ($token !== '') {
                $headers['Authorization'] = 'Bearer ' . $token;
                $headers['Accept'] = 'application/vnd.github+json';
            }

            $response = wp_remote_get($url, [
                'headers' => $headers,
                'timeout' => 10,
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = wp_remote_retrieve_body($response);
                $decoded = json_decode($body);
                if ($decoded && is_object($decoded) && !empty($decoded->tag_name)) {
                    $release = $decoded;
                    set_transient('drw_github_latest_release', $release, 12 * HOUR_IN_SECONDS);
                } else {
                    $release = false;
                }
            } else {
                $release = false;
            }
        }
        return $release;
    }

    /**
     * Intercept the plugin ZIP download for our plugin's private-repo release
     * assets so we can perform the two-hop authenticated download manually.
     *
     * WordPress's Requests library forwards the Authorization header across
     * redirects without checking host, and GitHub's private-release-asset
     * flow 302-redirects to a signed S3/Blob URL that rejects requests
     * carrying both a signature-in-querystring and an Authorization header.
     * upgrader_pre_download lets us fully control each HTTP hop instead of
     * letting WP_Upgrader::download_package() follow the redirect itself.
     *
     * Returns $reply unchanged (letting WP Core's normal unauthenticated
     * download_url() run) whenever: another plugin already resolved this
     * download, this download isn't for our plugin, no token is configured,
     * or the package isn't one of our private-repo API asset URLs. This
     * preserves today's exact behavior for public-repo/no-token cases.
     *
     * @param bool|\WP_Error $reply
     * @param string         $package
     * @param \WP_Upgrader   $upgrader
     * @param array          $hook_extra
     * @return bool|\WP_Error|string
     */
    public function pre_download($reply, $package, $upgrader, $hook_extra = [])
    {
        if ($reply !== false) {
            return $reply;
        }

        $is_our_plugin = isset($hook_extra['plugin']) && $hook_extra['plugin'] === DRW_PLUGIN_BASENAME;
        if (!$is_our_plugin) {
            return $reply;
        }

        $token = $this->get_github_token();
        if ($token === '') {
            return $reply;
        }

        $parsed_package = parse_url($package);
        $expected_path_prefix = '/repos/' . self::GITHUB_REPO . '/';
        $is_our_api_asset_url = !empty($parsed_package['host'])
            && $parsed_package['host'] === 'api.github.com'
            && !empty($parsed_package['path'])
            && strpos($parsed_package['path'], $expected_path_prefix) === 0;

        if (!$is_our_api_asset_url) {
            return $reply;
        }

        return $this->download_authenticated_package($package, $token);
    }

    /**
     * Perform the two-hop authenticated download of a private-repo release
     * asset: hop 1 hits the GitHub API asset endpoint with the Authorization
     * header and redirection disabled, hop 2 follows the Location header to
     * the signed URL WITHOUT any Authorization header attached.
     *
     * @param string $url
     * @param string $token
     * @return string|\WP_Error Path to the downloaded temp file, or WP_Error.
     */
    private function download_authenticated_package($url, $token)
    {
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/octet-stream',
            'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; DiscountRulesWooUpdater',
        ];

        // wp_safe_remote_get() (not wp_remote_get()) forces reject_unsafe_urls,
        // matching the SSRF hardening WP Core's own download_url() applies --
        // see stream_to_tmp_file() below for the same reasoning on hop 2.
        $response = wp_safe_remote_get($url, [
            'headers'     => $headers,
            'timeout'     => 30,
            'redirection' => 0,
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error(
                'drw_update_download_failed',
                __('No se pudo descargar la actualización: error de conexión con GitHub.', 'discount-rules-woo')
            );
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status >= 300 && $status < 400) {
            $location = wp_remote_retrieve_header($response, 'location');
            if (empty($location)) {
                return new \WP_Error(
                    'drw_update_download_failed',
                    __('No se pudo descargar la actualización: respuesta de redirección inválida de GitHub.', 'discount-rules-woo')
                );
            }
            // Hop 2: the signed URL must go out clean, no Authorization header.
            return $this->stream_to_tmp_file($location, []);
        }

        if ($status === 200) {
            // Defensive fallback: some private-asset responses may not redirect.
            // Note hop 1 above already buffered the full response body in PHP
            // memory (it did not pass stream => true), so this branch does not
            // avoid that cost -- it re-issues the request so the ZIP ends up on
            // disk via the same stream_to_tmp_file() path every other download
            // uses, matching WP Core's download_url() pattern for consistency,
            // not for a memory-usage win. This re-issue is safe to call with the
            // original Authorization header: stream_to_tmp_file() forces
            // redirection => 0 whenever $headers is non-empty, so if this second
            // request is ever redirected it fails closed (WP_Error) rather than
            // forwarding Authorization to the redirect target -- the exact leak
            // this two-hop design exists to prevent.
            return $this->stream_to_tmp_file($url, $headers);
        }

        return new \WP_Error(
            'drw_update_download_failed',
            __('No se pudo descargar la actualización: GitHub respondió con un error inesperado.', 'discount-rules-woo')
        );
    }

    /**
     * Stream a URL to a temporary file, mirroring WP Core's download_url()
     * pattern.
     *
     * When $headers is non-empty (i.e. it may carry an Authorization header),
     * redirection is explicitly disabled: a redirect response is treated as
     * a failure (below, via the non-200 status check) rather than silently
     * followed, so Authorization is never forwarded to a redirect target by
     * this method. Callers that need to follow a redirect must resolve the
     * Location header themselves and call this method again with clean
     * headers, exactly as download_authenticated_package() does for hop 2.
     *
     * @param string $url
     * @param array  $headers
     * @return string|\WP_Error Path to the downloaded temp file, or WP_Error.
     */
    private function stream_to_tmp_file($url, array $headers = [])
    {
        $tmpfname = wp_tempnam($url);
        if (!$tmpfname) {
            return new \WP_Error(
                'drw_update_download_failed',
                __('No se pudo descargar la actualización: no fue posible crear un archivo temporal.', 'discount-rules-woo')
            );
        }

        $args = [
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $tmpfname,
        ];
        if (!empty($headers)) {
            $args['headers'] = $headers;
            // Defense-in-depth: never let a request carrying Authorization
            // follow a redirect out from under us.
            $args['redirection'] = 0;
        }

        // wp_safe_remote_get() forces reject_unsafe_urls, blocking requests to
        // internal/private/reserved IP ranges -- the same SSRF-hardening layer
        // WP Core's own download_url() applies. This hop's target is a GitHub
        // API host or a GitHub-issued redirect Location, not user input, but
        // there is no reason to have less protection here than WP Core's stock
        // (unauthenticated) download path already has.
        $response = wp_safe_remote_get($url, $args);

        if (is_wp_error($response)) {
            unlink($tmpfname);
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            unlink($tmpfname);
            return new \WP_Error(
                'drw_update_download_failed',
                __('No se pudo descargar la actualización: GitHub respondió con un error inesperado.', 'discount-rules-woo')
            );
        }

        return $tmpfname;
    }
}
