<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class Updater
{
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
                $obj->url = 'https://github.com/bywuilgonzalez-co/discount-rules-woo';

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
        $res->homepage = 'https://github.com/bywuilgonzalez-co/discount-rules-woo';
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
     * Prefers the uploaded release asset (discount-rules-woo.zip) over zipball_url.
     *
     * @param object $release
     * @return string
     */
    private function get_download_url($release)
    {
        // Prefer the uploaded .zip asset (clean folder structure)
        if (!empty($release->assets) && is_array($release->assets)) {
            foreach ($release->assets as $asset) {
                if (
                    !empty($asset->browser_download_url) &&
                    substr($asset->name, -4) === '.zip'
                ) {
                    return $asset->browser_download_url;
                }
            }
        }

        // Fallback to zipball (requires rename_github_folder hook)
        return !empty($release->zipball_url) ? $release->zipball_url : '';
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
            $url = 'https://api.github.com/repos/bywuilgonzalez-co/discount-rules-woo/releases/latest';
            $response = wp_remote_get($url, [
                'headers' => [
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; DiscountRulesWooUpdater'
                ],
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
}
