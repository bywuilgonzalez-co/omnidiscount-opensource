<?php
namespace Drw\App\Controllers;

if (!defined('ABSPATH')) { exit; }

class StoreApiController {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        // Only register when Store API is available (WC 6.9+)
        add_action('woocommerce_blocks_loaded', [$this, 'register_store_api_integration']);
    }

    public function register_store_api_integration() {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        // Register cart extension data so discount info is available in the Block Cart response
        // Use the string identifier directly to avoid a hard dependency on WC's internal CartSchema class.
        woocommerce_store_api_register_endpoint_data([
            'endpoint'        => 'cart',
            'namespace'       => 'discount-rules-woo',
            'data_callback'   => [$this, 'get_cart_extension_data'],
            'schema_callback' => [$this, 'get_cart_extension_schema'],
            'schema_type'     => ARRAY_A,
        ]);

        // Apply discounts before Store API calculates totals
        add_action('woocommerce_store_api_cart_update_order_from_request', [$this, 'apply_discounts_to_block_cart'], 10, 2);
        add_filter('woocommerce_store_api_cart_errors', [$this, 'add_free_shipping_notice'], 10, 2);
    }

    /**
     * Apply plugin discounts when the Store API updates the cart (Block Cart/Checkout).
     * Mirrors the logic CartController uses via woocommerce_before_calculate_totals.
     */
    public function apply_discounts_to_block_cart($order, $request) {
        $cart = WC()->cart;
        if (!$cart) { return; }

        $engine = RulesEngine::instance();
        $engine->calculate_all_cart_discounts($cart);

        $item_prices = $engine->get_cached_cart_item_prices();
        if (!empty($item_prices)) {
            foreach ($cart->get_cart() as $key => $item) {
                if (isset($item_prices[$key])) {
                    $item['data']->set_price($item_prices[$key]);
                }
            }
        }

        $discounts = $engine->get_cached_cart_level_discounts();
        if (!empty($discounts['free_shipping'])) {
            WC()->session->set('drw_free_shipping', true);
        } else {
            WC()->session->set('drw_free_shipping', false);
        }

        $this->emit_promo_cart_notices($cart);
    }

    /**
     * Expose discount summary to the Block Cart/Checkout via Store API extension data.
     */
    public function get_cart_extension_data() {
        $cart   = WC()->cart;
        $engine = RulesEngine::instance();

        if (!$cart) {
            return ['discount_total' => 0, 'free_shipping' => false, 'applied_rules' => [], 'promos' => []];
        }

        $discounts    = $engine->get_cached_cart_level_discounts();
        $item_prices  = $engine->get_cached_cart_item_prices();
        $saved        = 0.0;

        foreach ($cart->get_cart() as $key => $item) {
            if (isset($item_prices[$key])) {
                $regular = (float)$item['data']->get_regular_price();
                $saved  += max(0, ($regular - $item_prices[$key]) * $item['quantity']);
            }
        }

        foreach (!empty($discounts['fees']) ? $discounts['fees'] : [] as $fee) {
            $saved += abs((float)$fee['amount']);
        }

        return [
            'discount_total' => wc_format_decimal($saved, 2),
            'free_shipping'  => !empty($discounts['free_shipping']),
            'applied_rules'  => [],
            'promos'         => $this->collect_promo_badges($cart),
        ];
    }

    /**
     * Schema definition for the Store API extension data.
     */
    public function get_cart_extension_schema() {
        return [
            'discount_total' => ['description' => 'Total discount applied by DRW rules', 'type' => 'string', 'context' => ['view', 'edit'], 'readonly' => true],
            'free_shipping'  => ['description' => 'Whether free shipping is granted by a rule', 'type' => 'boolean', 'context' => ['view', 'edit'], 'readonly' => true],
            'applied_rules'  => ['description' => 'List of applied rule IDs', 'type' => 'array', 'context' => ['view', 'edit'], 'readonly' => true, 'items' => ['type' => 'integer']],
            'promos'         => [
                'description' => 'Badge/message/progress data for active Vía B (automatic) promos, for Cart/Checkout Blocks display',
                'type'        => 'array',
                'context'     => ['view', 'edit'],
                'readonly'    => true,
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'promo_id' => ['type' => 'integer'],
                        'rule_id'  => ['type' => 'integer'],
                        'type'     => ['description' => 'Promo type, e.g. free_ship_threshold', 'type' => 'string'],
                        'title'    => ['type' => 'string'],
                        'message'  => ['description' => 'cart_message with {monto} resolved to the remaining amount, when applicable', 'type' => 'string'],
                        'applied'  => ['description' => 'Whether the promo is currently unlocked/matched for this cart', 'type' => 'boolean'],
                        'progress' => [
                            'description' => 'Progress bar data (free_ship_threshold and similar min_subtotal promos); null otherwise',
                            'type'        => ['object', 'null'],
                            'properties'  => [
                                'current'   => ['type' => 'string'],
                                'target'    => ['type' => 'string'],
                                'remaining' => ['type' => 'string'],
                                'percent'   => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Add a notice about free shipping in the Block Cart errors/notices channel.
     */
    public function add_free_shipping_notice($errors, $cart) {
        if (WC()->session && WC()->session->get('drw_free_shipping')) {
            // Notices for Block Cart use wc_add_notice, not this filter.
            // This filter is for error objects; skip silently.
        }
        return $errors;
    }

    /**
     * Build badge/progress/message data for every Vía B (automatic) promo
     * whose compiled wp_drw_rules row (source='promo') is currently active,
     * so Cart/Checkout Blocks can render a badge, a progress bar
     * (free_ship_threshold especially) and the merchant's cart_message.
     * Shared by get_cart_extension_data() (Store API schema payload) and
     * emit_promo_cart_notices() (Cart Notice) so both stay in sync from a
     * single source of truth.
     *
     * NOTE on "applied": for the free_shipping/min_subtotal case it is the
     * authoritative FreeShipping::is_free_shipping_unlocked() result (same
     * check CartController/RulesEngine use for the real discount). For every
     * other promo type it is a best-effort signal from
     * RulesEngine::is_rule_matched() (conditions only) — it does not
     * re-verify line-item targeting for bogo/bundle_set adjustments, so a
     * promo can show as "applied" slightly ahead of the exact item that
     * triggers it appearing in the cart.
     *
     * @param \WC_Cart $cart
     * @return array List of badge descriptors.
     */
    private function collect_promo_badges($cart) {
        if (!$cart || $cart->is_empty()) {
            return [];
        }

        $engine = RulesEngine::instance();
        $rules  = $engine->get_active_rules();
        if (empty($rules)) {
            return [];
        }

        $badges      = [];
        $promo_cache = [];

        foreach ($rules as $rule) {
            $promo_id = !empty($rule['promo_id']) ? (int)$rule['promo_id'] : 0;
            // Only Vía B promo-compiled rules carry marketing copy; hand-authored
            // "Reglas avanzadas" (source !== 'promo') have no promo to look up.
            if ($promo_id <= 0 || (isset($rule['source']) && $rule['source'] !== 'promo')) {
                continue;
            }

            if (!array_key_exists($promo_id, $promo_cache)) {
                $promo_cache[$promo_id] = \Drw\App\Models\PromoModel::get_promo($promo_id);
            }
            $promo = $promo_cache[$promo_id];
            if (!$promo) {
                continue;
            }

            $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
            $type        = !empty($adjustments['type']) ? $adjustments['type'] : '';

            $progress = null;
            $applied  = false;

            if ($type === 'free_shipping' && !empty($adjustments['min_subtotal'])) {
                // free_ship_threshold: evaluate progress independently of
                // is_rule_matched() — the compiled cart_subtotal condition only
                // becomes true once the threshold is already reached, which
                // would make a "you need $X more" progress bar impossible.
                $target    = (float)$adjustments['min_subtotal'];
                $current   = (float)$cart->get_subtotal();
                $remaining = max(0.0, $target - $current);
                $percent   = $target > 0 ? (int)min(100, round(($current / $target) * 100)) : 100;

                $shipping_engine = new \Drw\App\Adjustments\FreeShipping();
                $applied = $shipping_engine->is_free_shipping_unlocked($adjustments, $cart);

                $progress = [
                    'current'   => wc_format_decimal($current, 2),
                    'target'    => wc_format_decimal($target, 2),
                    'remaining' => wc_format_decimal($remaining, 2),
                    'percent'   => $percent,
                ];
            } else {
                $applied = $engine->is_rule_matched($rule, $cart);
            }

            $message = isset($promo['cart_message']) ? (string)$promo['cart_message'] : '';
            if ('' !== $message && null !== $progress) {
                $plain_amount = wp_strip_all_tags(wc_price((float)$progress['remaining']));
                $message      = str_replace('{monto}', $plain_amount, $message);
            }

            $badges[] = [
                'promo_id' => $promo_id,
                'rule_id'  => (int)$rule['id'],
                'type'     => isset($promo['type']) ? (string)$promo['type'] : $type,
                'title'    => isset($rule['title']) ? (string)$rule['title'] : '',
                'message'  => $message,
                'applied'  => $applied,
                'progress' => $progress,
            ];
        }

        return $badges;
    }

    /**
     * Surface each matching Vía B promo's cart_message as a WooCommerce
     * notice while the Store API is processing the cart update. WooCommerce
     * Blocks picks up notices added with wc_add_notice() during this request
     * and renders them in Cart/Checkout Blocks — the same mechanism the
     * classic wc_print_notices() cart page uses, so no separate "Blocks"
     * rendering code is needed here.
     *
     * free_ship_threshold messages are shown as a progress nudge ONLY while
     * the threshold hasn't been reached yet (the {monto} placeholder is
     * meaningful there); once unlocked, WooCommerce's own $0 shipping rate
     * already communicates that. Every other promo type announces itself
     * once it is actually applied.
     *
     * wc_has_notice() guards against duplicate notices if this hook fires
     * more than once for the same request.
     *
     * @param \WC_Cart $cart
     */
    private function emit_promo_cart_notices($cart) {
        if (!function_exists('wc_add_notice') || !function_exists('wc_has_notice')) {
            return;
        }

        foreach ($this->collect_promo_badges($cart) as $badge) {
            $message = $badge['message'];
            if ('' === $message) {
                continue;
            }

            $is_threshold = (null !== $badge['progress']);
            $remaining    = $is_threshold ? (float)$badge['progress']['remaining'] : 0.0;

            $should_show = $is_threshold
                ? (!$badge['applied'] && $remaining > 0)
                : (bool)$badge['applied'];

            if (!$should_show) {
                continue;
            }

            if (!wc_has_notice($message, 'notice')) {
                wc_add_notice($message, 'notice');
            }
        }
    }
}
