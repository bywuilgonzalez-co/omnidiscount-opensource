<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class ProgressBarController
{
    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks()
    {
        add_shortcode('drw_cart_progress', [$this, 'render_cart_progress']);
        add_shortcode('drw_volume_pricing', [$this, 'render_volume_pricing']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);
    }

    public function enqueue_public_assets()
    {
        if (!is_cart() && !is_checkout() && !is_product()) {
            return;
        }

        wp_enqueue_style(
            'drw-progress-bar',
            DRW_PLUGIN_URL . 'assets/css/public-style.css',
            [],
            DRW_VERSION
        );
    }

    /**
     * [drw_cart_progress] shortcode.
     * Shows a progress bar toward the next discount threshold or free shipping threshold.
     * Attributes: goal (amount), label (message with {remaining} placeholder), type (bar|text)
     */
    public function render_cart_progress($atts)
    {
        $atts = shortcode_atts([
            'goal'  => '',
            'label' => __('Spend {remaining} more to get free shipping!', 'discount-rules-woo'),
            'type'  => 'bar',
        ], $atts, 'drw_cart_progress');

        if (!function_exists('WC') || !WC()->cart) {
            return '';
        }

        $cart     = WC()->cart;
        $subtotal = (float) $cart->get_subtotal();

        // Determine goal: explicit attribute takes precedence, else look for a free-shipping rule threshold.
        $goal = (float) $atts['goal'];
        if ($goal <= 0) {
            $goal = $this->detect_free_shipping_threshold();
        }
        if ($goal <= 0) {
            return '';
        }

        $remaining = max(0, $goal - $subtotal);
        $percent   = min(100, round(($subtotal / $goal) * 100));

        if ($remaining <= 0) {
            return '<div class="drw-progress-wrap drw-progress-complete">'
                . '<p class="drw-progress-label">' . esc_html__('You have unlocked free shipping!', 'discount-rules-woo') . '</p>'
                . '</div>';
        }

        $label = str_replace('{remaining}', wp_kses_post(wc_price($remaining)), esc_html($atts['label']));

        $bar_html = '';
        if ($atts['type'] === 'bar') {
            $bar_html = '<div class="drw-progress-bar-outer" role="progressbar" aria-valuenow="' . esc_attr($percent) . '" aria-valuemin="0" aria-valuemax="100">'
                . '<div class="drw-progress-bar-inner" style="width:' . esc_attr($percent) . '%"></div>'
                . '</div>';
        }

        return '<div class="drw-progress-wrap">'
            . $bar_html
            . '<p class="drw-progress-label">' . $label . '</p>'
            . '</div>';
    }

    /**
     * Detect the lowest subtotal threshold for a free_shipping rule from active rules.
     */
    private function detect_free_shipping_threshold()
    {
        $engine        = RulesEngine::instance();
        $rules         = $engine->get_active_rules();
        $min_threshold = PHP_INT_MAX;
        $found         = false;

        foreach ($rules as $rule) {
            $adj = !empty($rule['adjustments']) ? (array) $rule['adjustments'] : [];
            if (empty($adj['type']) || $adj['type'] !== 'free_shipping') {
                continue;
            }

            $conditions = !empty($rule['conditions']) ? (array) $rule['conditions'] : [];
            foreach ($conditions as $cond) {
                $type = !empty($cond['type']) ? $cond['type'] : '';
                if (in_array($type, ['cart_subtotal', 'subtotal'], true)) {
                    $val = isset($cond['value']) ? (float) $cond['value'] : 0;
                    if ($val > 0 && $val < $min_threshold) {
                        $min_threshold = $val;
                        $found         = true;
                    }
                }
            }
        }

        return $found ? $min_threshold : 0;
    }

    /**
     * [drw_volume_pricing table="PRODUCT_ID"] shortcode.
     * Renders an HTML table of bulk/tiered pricing tiers for a product.
     */
    public function render_volume_pricing($atts)
    {
        $atts = shortcode_atts([
            'product_id' => 0,
            'title'      => __('Volume Pricing', 'discount-rules-woo'),
        ], $atts, 'drw_volume_pricing');

        $product_id = (int) $atts['product_id'];
        if (!$product_id) {
            global $post;
            $product_id = $post ? (int) $post->ID : 0;
        }
        if (!$product_id) {
            return '';
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return '';
        }

        $engine = RulesEngine::instance();
        $rules  = $engine->get_active_rules();
        $tiers  = [];

        foreach ($rules as $rule) {
            if (!$engine->is_product_targeted_by_rule($rule, $product)) {
                continue;
            }
            $adj = !empty($rule['adjustments']) ? (array) $rule['adjustments'] : [];
            if (empty($adj['type']) || $adj['type'] !== 'bulk') {
                continue;
            }
            if (empty($adj['tiers']) || !is_array($adj['tiers'])) {
                continue;
            }
            $tiers = array_merge($tiers, $adj['tiers']);
        }

        if (empty($tiers)) {
            return '';
        }

        $regular_price = (float) $product->get_regular_price();
        ob_start();
        ?>
        <div class="drw-volume-pricing">
            <h4 class="drw-volume-title"><?php echo esc_html($atts['title']); ?></h4>
            <table class="drw-volume-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Quantity', 'discount-rules-woo'); ?></th>
                        <th><?php esc_html_e('Price per Unit', 'discount-rules-woo'); ?></th>
                        <th><?php esc_html_e('Savings', 'discount-rules-woo'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tiers as $tier) :
                    $min = isset($tier['min']) ? (int) $tier['min'] : 1;
                    $max = isset($tier['max']) && $tier['max'] !== '' ? (int) $tier['max'] : null;
                    $tier_type  = !empty($tier['type']) ? $tier['type'] : 'percentage';
                    $tier_value = (float) (!empty($tier['value']) ? $tier['value'] : 0);

                    if ($tier_type === 'percentage') {
                        $discounted = $regular_price * (1 - $tier_value / 100);
                    } else {
                        $discounted = max(0, $regular_price - $tier_value);
                    }
                    $savings_pct = $regular_price > 0 ? round((($regular_price - $discounted) / $regular_price) * 100) : 0;
                    $qty_label   = $max ? "{$min}–{$max}" : "{$min}+";
                ?>
                    <tr>
                        <td><?php echo esc_html($qty_label); ?></td>
                        <td><?php echo wp_kses_post(wc_price($discounted)); ?></td>
                        <td><?php echo esc_html("{$savings_pct}%"); ?> <?php esc_html_e('off', 'discount-rules-woo'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
