<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class CartController
{
    private static $instance = null;
    private $is_recalculating = false;
    private $is_adding_to_cart = false;

    /**
     * SANDBOX MODE — per-request cache for PromoBridgeController's cookie
     * lookup (see get_sandbox_rule() below). Resolved at most once per
     * request/singleton lifetime, exactly like RulesEngine's own
     * $cached_rules; never shared across requests/users.
     */
    private $sandbox_rule_resolved = false;
    private $sandbox_rule = null;

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

    private function __construct() {}

    /**
     * Register hooks for cart and checkout pricing adjustments.
     */
    public function register_hooks()
    {
        // Line item pricing recalculation
        add_action('woocommerce_before_calculate_totals', [$this, 'recalculate_cart_item_prices'], 20, 1);

        // Cart-wide fees (subtotal based discounts)
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_cart_wide_fees'], 20, 1);

        // Save order metadata on checkout
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_line_item_metadata'], 20, 4);

        // Count real promo redemptions once an order is paid. Both statuses point
        // to the same handler; the per-order _drw_promos_counted flag guarantees a
        // promo is only ever counted once, no matter which/how many times it fires.
        add_action('woocommerce_order_status_processing', [$this, 'track_promo_redemptions'], 20, 2);
        add_action('woocommerce_order_status_completed', [$this, 'track_promo_redemptions'], 20, 2);

        // Shipping modifications
        add_filter('woocommerce_package_rates', [$this, 'modify_shipping_package_rates'], 20, 2);

        // Coupon matching
        add_filter('woocommerce_get_shop_coupon_data', [$this, 'get_shop_coupon_data'], 20, 2);

        // Layout formatting filters
        add_filter('woocommerce_cart_item_price', [$this, 'format_cart_item_price'], 20, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'format_cart_item_subtotal'], 20, 3);
        add_filter('woocommerce_cart_totals_order_total_html', [$this, 'format_cart_totals_order_total_html'], 20, 1);
        add_filter('woocommerce_get_formatted_order_total', [$this, 'format_order_total'], 20, 4);
        add_filter('woocommerce_order_formatted_line_subtotal', [$this, 'format_order_line_subtotal'], 20, 3);
        add_action('woocommerce_admin_order_totals_after_total', [$this, 'display_admin_order_totals_after_total'], 20, 1);
    }

    /**
     * Intercepts WooCommerce cart calculations to apply item-specific rules.
     *
     * @param \WC_Cart $cart WooCommerce Cart object
     */
    public function recalculate_cart_item_prices($cart)
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        // Avoid infinite loop
        if ($this->is_recalculating) {
            return;
        }

        $this->is_recalculating = true;

        $engine = \Drw\App\Controllers\RulesEngine::instance();

        // SANDBOX MODE — resolved once per request. Read-only, additive: see
        // get_sandbox_rule() for the full safety contract. $sandbox_rule stays
        // null for every request except the admin's own, cookie-carrying one.
        $sandbox_rule = $this->get_sandbox_rule();

        // BOGO Auto-addition logic
        if (!$this->is_adding_to_cart) {
            $rules = $engine->get_active_rules();
            if (null !== $sandbox_rule) {
                // Local copy only (get_active_rules() returns cached_rules by
                // value) — this does NOT mutate RulesEngine's internal cache,
                // so it has zero effect on any other code path or user.
                $rules[] = $sandbox_rule;
            }
            if (!empty($rules)) {
                foreach ($rules as $rule) {
                    $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
                    $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

                    if ($type === 'bogo') {
                        if ($engine->is_rule_matched($rule, $cart)) {
                            $buy_qty = isset($adjustments['buy_qty']) ? (int)$adjustments['buy_qty'] : (isset($adjustments['buy_quantity']) ? (int)$adjustments['buy_quantity'] : 1);
                            $get_qty = isset($adjustments['get_qty']) ? (int)$adjustments['get_qty'] : (isset($adjustments['get_quantity']) ? (int)$adjustments['get_quantity'] : 1);
                            $get_product_type = isset($adjustments['get_product_type']) ? $adjustments['get_product_type'] : (isset($adjustments['apply_to']) ? $adjustments['apply_to'] : 'same');

                            $buy_products = isset($adjustments['buy_products']) ? (array)$adjustments['buy_products'] : [];
                            $buy_categories = isset($adjustments['buy_categories']) ? (array)$adjustments['buy_categories'] : [];
                            $get_products = isset($adjustments['get_products']) ? (array)$adjustments['get_products'] : [];
                            $get_categories = isset($adjustments['get_categories']) ? (array)$adjustments['get_categories'] : [];

                            if ($get_product_type === 'different') {
                                $total_buy_qty = 0;
                                foreach ($cart->get_cart() as $item) {
                                    if ($this->is_product_in_list($item['data'], $buy_products, $buy_categories)) {
                                        $total_buy_qty += (int)$item['quantity'];
                                    }
                                }

                                if ($total_buy_qty >= $buy_qty && !empty($get_products)) {
                                    $gift_in_cart = false;
                                    foreach ($cart->get_cart() as $item) {
                                        if ($this->is_product_in_list($item['data'], $get_products, $get_categories)) {
                                            $gift_in_cart = true;
                                            break;
                                        }
                                    }

                                    if (!$gift_in_cart) {
                                        $gift_product_id = (int)reset($get_products);
                                        if ($gift_product_id > 0) {
                                            $this->is_adding_to_cart = true;
                                            WC()->cart->add_to_cart($gift_product_id, $get_qty);
                                            $this->is_adding_to_cart = false;
                                            break;
                                        }
                                    }
                                }
                            } elseif ($get_product_type === 'same') {
                                foreach ($cart->get_cart() as $item) {
                                    $product = $item['data'];
                                    if ($this->is_product_in_list($product, $buy_products, $buy_categories)) {
                                        $qty = (int)$item['quantity'];
                                        if ($qty >= $buy_qty && $qty < ($buy_qty + $get_qty)) {
                                            $product_id = $product->get_id();
                                            $variation_id = $item['variation_id'];
                                            $variations = $item['variation'];

                                            $this->is_adding_to_cart = true;
                                            WC()->cart->add_to_cart($product_id, $get_qty, $variation_id, $variations);
                                            $this->is_adding_to_cart = false;
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            
            // Get discounted price based on matching rules
            $discounted_price = $engine->calculate_cart_item_discount($cart_item, $cart);

            if ($discounted_price !== null) {
                // Apply the adjusted price to the product instance inside the cart
                $product->set_price($discounted_price);
                
                // Track metadata in the cart item session for display or saving later
                $cart->cart_contents[$cart_item_key]['drw_discounted'] = true;
                $cart->cart_contents[$cart_item_key]['drw_original_price'] = (float)$product->get_regular_price();
                $cart->cart_contents[$cart_item_key]['drw_discount_price'] = $discounted_price;
            }
        }

        // SANDBOX MODE — additive per-item preview layer, applied ONLY when
        // get_sandbox_rule() resolved a valid, current-admin-owned override
        // above. Wrapped in try/catch so a bug here can only ever break the
        // admin's OWN preview cart, never fatal the request for anyone.
        if (null !== $sandbox_rule) {
            try {
                $this->apply_sandbox_item_adjustments($engine, $cart, $sandbox_rule);
            } catch (\Throwable $e) {
                error_log('[discount-rules-woo] Sandbox preview (item pricing) failed: ' . $e->getMessage());
            }
        }

        $this->is_recalculating = false;
    }

    /**
     * SANDBOX MODE — resolve (and cache for the rest of the request) whether
     * the CURRENT user has a valid sandbox override active, per
     * PromoBridgeController::get_sandboxed_rule_for_current_user().
     *
     * This method, and everything it feeds into below, is the ONLY place
     * CartController deviates from the normal engine-driven calculation. It
     * is a strict no-op — returns null, changing nothing — for every request
     * that isn't the sandboxing admin's own: no cookie, wrong/expired/forged
     * cookie, cookie for a different user, or the current user lacking
     * manage_woocommerce all fall through to null here (see the bridge
     * method for the full check list). Wrapped in try/catch so any
     * unexpected failure (e.g. a DB hiccup resolving the promo/rule) degrades
     * to "sandbox off" instead of breaking cart calculation.
     *
     * @return array|null
     */
    private function get_sandbox_rule()
    {
        if ($this->sandbox_rule_resolved) {
            return $this->sandbox_rule;
        }
        $this->sandbox_rule_resolved = true;

        try {
            if (class_exists('\\Drw\\App\\Controllers\\PromoBridgeController')) {
                $this->sandbox_rule = \Drw\App\Controllers\PromoBridgeController::get_sandboxed_rule_for_current_user();
            }
        } catch (\Throwable $e) {
            error_log('[discount-rules-woo] Sandbox cookie resolution failed: ' . $e->getMessage());
            $this->sandbox_rule = null;
        }

        return $this->sandbox_rule;
    }

    /**
     * SANDBOX MODE — apply the ONE sandboxed rule's adjustment on top of
     * whatever price the real engine already computed for this cart, for the
     * current (admin, cookie-validated) request only.
     *
     * Deliberately does NOT touch RulesEngine's private $cached_rules / the
     * compounding pipeline in calculate_all_cart_discounts() — that cache has
     * no public mutator, and reaching into it was judged too risky to do
     * blindly for every other shopper's cart on the site. Instead this reuses
     * RulesEngine's existing PUBLIC matching helpers (is_rule_matched(),
     * is_product_targeted_by_rule()) plus the same standalone Adjustment
     * classes (Bogo / BundleSet) RulesEngine itself delegates to, and layers
     * the result on top of the current product price. Cart-level
     * percentage/fixed fees and free_shipping are handled separately in
     * apply_cart_wide_fees() / modify_shipping_package_rates() below.
     *
     * @param \Drw\App\Controllers\RulesEngine $engine
     * @param \WC_Cart $cart
     * @param array $rule Sandboxed rule (RuleModel-formatted row).
     */
    private function apply_sandbox_item_adjustments($engine, $cart, array $rule)
    {
        if ($engine->is_cart_level_rule($rule)) {
            // percentage/fixed cart-level and free_shipping are applied by
            // apply_cart_wide_fees() / modify_shipping_package_rates().
            return;
        }

        if (!$engine->is_rule_matched($rule, $cart)) {
            return;
        }

        $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
        $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

        if ($type === 'percentage' || $type === 'fixed') {
            $value = (float)(isset($adjustments['value']) ? $adjustments['value'] : 0);
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                if (!$engine->is_product_targeted_by_rule($rule, $product)) {
                    continue;
                }
                $current_price = (float)$product->get_price();
                $new_price = ($type === 'percentage')
                    ? $current_price - ($current_price * ($value / 100))
                    : $current_price - $value;
                $this->set_sandbox_item_price($cart, $cart_item_key, $product, $new_price);
            }
        } elseif ($type === 'bulk') {
            $tiers = !empty($adjustments['tiers']) ? (array)$adjustments['tiers'] : [];
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                if (!$engine->is_product_targeted_by_rule($rule, $product)) {
                    continue;
                }
                $qty = (int)$cart_item['quantity'];
                foreach ($tiers as $tier) {
                    $min_qty = isset($tier['min']) ? (int)$tier['min'] : 0;
                    $max_qty = isset($tier['max']) && $tier['max'] !== '' ? (int)$tier['max'] : PHP_INT_MAX;
                    if ($qty < $min_qty || $qty > $max_qty) {
                        continue;
                    }
                    $tier_type  = !empty($tier['type']) ? $tier['type'] : 'percentage';
                    $tier_value = (float)(!empty($tier['value']) ? $tier['value'] : 0);
                    $current_price = (float)$product->get_price();
                    $new_price = ($tier_type === 'fixed')
                        ? $current_price - $tier_value
                        : $current_price - ($current_price * ($tier_value / 100));
                    $this->set_sandbox_item_price($cart, $cart_item_key, $product, $new_price);
                    break;
                }
            }
        } elseif ($type === 'bogo') {
            $bogo_engine = new \Drw\App\Adjustments\Bogo();
            $bogo_results = $bogo_engine->calculate($adjustments, $cart);
            $this->apply_sandbox_ratio_results($cart, $bogo_results);
        } elseif ($type === 'bundle_set') {
            $bundle_engine = new \Drw\App\Adjustments\BundleSet();
            $bundle_results = $bundle_engine->calculate($adjustments, $cart);
            if (!empty($bundle_results['applied']) && !empty($bundle_results['items'])) {
                $this->apply_sandbox_ratio_results($cart, $bundle_results['items']);
            }
        }
    }

    /**
     * SANDBOX MODE helper — Bogo::calculate()/BundleSet::calculate() both
     * return { cart_item_key => new_unit_price } computed off each item's
     * REGULAR price. Convert that into a ratio and apply it to the item's
     * CURRENT price (which may already reflect a real, non-sandboxed
     * discount), exactly mirroring how RulesEngine's own private
     * apply_rule_adjustments() composes bogo/bundle_set with earlier rules.
     *
     * @param \WC_Cart $cart
     * @param array $results { cart_item_key => new_unit_price }
     */
    private function apply_sandbox_ratio_results($cart, array $results)
    {
        if (empty($results)) {
            return;
        }
        $cart_items = $cart->get_cart();
        foreach ($results as $cart_item_key => $new_unit_price) {
            if (!isset($cart_items[$cart_item_key])) {
                continue;
            }
            $product = $cart_items[$cart_item_key]['data'];
            $reg_price = (float)$product->get_regular_price();
            if ($reg_price <= 0) {
                continue;
            }
            $ratio = $new_unit_price / $reg_price;
            $new_price = (float)$product->get_price() * $ratio;
            $this->set_sandbox_item_price($cart, $cart_item_key, $product, $new_price);
        }
    }

    /**
     * SANDBOX MODE helper — apply a computed price to the product instance
     * and mirror recalculate_cart_item_prices()'s own metadata bookkeeping so
     * the existing UI (format_cart_item_price(), savings totals, etc.) shows
     * the sandboxed discount exactly like a real one, for this cart only.
     *
     * @param \WC_Cart $cart
     * @param string $cart_item_key
     * @param \WC_Product $product
     * @param float $new_price
     */
    private function set_sandbox_item_price($cart, $cart_item_key, $product, $new_price)
    {
        $new_price = max(0.0, (float)$new_price);
        $product->set_price($new_price);
        $cart->cart_contents[$cart_item_key]['drw_discounted'] = true;
        if (!isset($cart->cart_contents[$cart_item_key]['drw_original_price'])) {
            $cart->cart_contents[$cart_item_key]['drw_original_price'] = (float)$product->get_regular_price();
        }
        $cart->cart_contents[$cart_item_key]['drw_discount_price'] = $new_price;
    }

    /**
     * Calculates cart subtotal rules and applies them as a negative fee.
     *
     * @param \WC_Cart $cart WooCommerce Cart object
     */
    public function apply_cart_wide_fees($cart)
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        $engine = \Drw\App\Controllers\RulesEngine::instance();
        $discounts = $engine->calculate_cart_level_discounts($cart);

        if (!empty($discounts['fees'])) {
            // Each cart-level rule already caps its OWN fee at the subtotal in
            // RulesEngine::apply_rule_adjustments(), but several non-exclusive
            // automatic cart promos can still STACK and, combined, exceed the
            // real cart subtotal — an unintended free/negative checkout. Scale
            // the whole set down proportionally so the fees can never sum past
            // the subtotal actually being charged for the items at this point.
            $fees = $this->scale_fees_to_subtotal($discounts['fees'], (float)$cart->get_subtotal());
            foreach ($fees as $fee) {
                $cart->add_fee($fee['name'], $fee['amount'], true);
            }
        }

        // SANDBOX MODE — additive cart-level fee for a sandboxed
        // percentage/fixed promo (mirrors RulesEngine's own cart-level fee
        // branch). No-op unless get_sandbox_rule() resolved a valid,
        // current-admin-owned override; see that method for the full
        // safety contract. Wrapped in try/catch so a bug here can only ever
        // break the admin's OWN preview cart, never fatal the request.
        try {
            $sandbox_rule = $this->get_sandbox_rule();
            if (null !== $sandbox_rule && $engine->is_cart_level_rule($sandbox_rule) && $engine->is_rule_matched($sandbox_rule, $cart)) {
                $adjustments = !empty($sandbox_rule['adjustments']) ? (array)$sandbox_rule['adjustments'] : [];
                $type = !empty($adjustments['type']) ? $adjustments['type'] : '';
                if ($type === 'percentage' || $type === 'fixed') {
                    $subtotal = (float)$cart->get_subtotal();
                    $value = (float)(isset($adjustments['value']) ? $adjustments['value'] : 0);
                    $fee_amount = ($type === 'percentage') ? $subtotal * ($value / 100) : $value;
                    if ($fee_amount > 0) {
                        $fee_amount = min($fee_amount, $subtotal);
                        $label = !empty($sandbox_rule['title']) ? $sandbox_rule['title'] : __('Sandbox promo', 'discount-rules-woo');
                        $cart->add_fee('[Sandbox] ' . $label, -$fee_amount, true);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[discount-rules-woo] Sandbox preview (cart fee) failed: ' . $e->getMessage());
        }
    }

    /**
     * Scale stacked cart-wide discount fees down so their combined magnitude
     * can never exceed the cart subtotal they are applied against.
     *
     * RulesEngine::apply_rule_adjustments() already caps each rule's OWN fee at
     * the subtotal, but several non-exclusive automatic cart promos can still
     * stack: the sum of their (negative) amounts can drop the order below zero,
     * i.e. an unintended free/negative checkout. This is the last line of
     * defence — when the combined discount would exceed $subtotal, every fee is
     * multiplied by (subtotal / total) so the fees sum to exactly the subtotal
     * while each fee keeps its relative share; otherwise the fees are returned
     * untouched.
     *
     * Pure and side-effect free so it can be unit-tested in isolation. Fee
     * amounts are expected to be negative (discounts), matching the shape
     * produced by RulesEngine::apply_rule_adjustments():
     * [ 'name' => string, 'amount' => float ].
     *
     * @param array $fees     Fees, each [ 'name' => string, 'amount' => float ].
     * @param float $subtotal Real cart subtotal to cap the combined fees at.
     * @return array Fees with amounts scaled proportionally only when the cap
     *               is exceeded; otherwise the input array unchanged.
     */
    private function scale_fees_to_subtotal(array $fees, $subtotal)
    {
        $subtotal = (float)$subtotal;

        // An empty set has nothing to scale.
        if (empty($fees)) {
            return $fees;
        }

        $total = 0.0;
        foreach ($fees as $fee) {
            $total += abs(isset($fee['amount']) ? (float)$fee['amount'] : 0.0);
        }

        // Combined discount already fits within the subtotal (or there is
        // nothing to scale): leave every fee exactly as-is. No re-casting on
        // this path, so the input array is returned bit-for-bit unchanged.
        if ($total <= 0.0 || $total <= $subtotal) {
            return $fees;
        }

        // A real cart subtotal is never negative; clamp defensively so the
        // ratio can only shrink magnitudes, never flip a discount into a
        // surcharge. When the real subtotal is 0 (e.g. a fully item-discounted
        // cart) every fee scales to 0, which still sums to exactly the
        // subtotal and keeps checkout from ever going negative.
        $ratio = max(0.0, $subtotal) / $total;
        foreach ($fees as $key => $fee) {
            $amount = isset($fee['amount']) ? (float)$fee['amount'] : 0.0;
            $fees[$key]['amount'] = $amount * $ratio;
        }

        return $fees;
    }

    /**
     * Attaches metadata of the applied discounts to the order items.
     *
     * @param \WC_Order_Item_Product $item Order line item object
     * @param string $cart_item_key Cart item unique key
     * @param array $values Cart item array values
     * @param \WC_Order $order WooCommerce Order object
     */
    public function save_line_item_metadata($item, $cart_item_key, $values, $order)
    {
        if (!empty($values['drw_discounted'])) {
            $original = $values['drw_original_price'];
            $discounted = $values['drw_discount_price'];
            $saving = $original - $discounted;

            if ($saving > 0) {
                // Store saved amount in item meta (visible in admin backend)
                $item->add_meta_data('_drw_original_price', $original, true);
                $item->add_meta_data('_drw_discount_price', $discounted, true);
                $item->add_meta_data(
                    __('Ahorro OmniDiscount', 'discount-rules-woo'),
                    wc_price($saving * (int)$values['quantity']), 
                    false
                );
            }
        }
    }

    /**
     * Count real promo redemptions for a paid order, exactly once per order.
     *
     * Resolves which promos participated in the order and bumps each promo's
     * usage counter a single time:
     *   - Vía A (code-based promos): each applied coupon code is matched back to
     *     its promo. Promo codes are stored upper-cased, WooCommerce returns
     *     applied codes lower-cased, so the code is normalised before lookup.
     *   - Vía B (automatic promos compiled to drw_rules): the discounted line
     *     item carries the originating promo id as item meta (_drw_promo_id /
     *     promo_id), read through the CRUD data-store API.
     *
     * HPOS-safe: order/line-item data is read ONLY through $order->get_coupon_codes(),
     * $order->get_items() and ...->get_meta(); nothing touches postmeta/posts
     * directly, and the counted flag is persisted with update_meta_data()/save().
     * Idempotency: $order->get_meta('_drw_promos_counted') short-circuits repeat
     * runs (processing -> completed, order re-saves, etc.) so no promo is ever
     * double-counted for the same order.
     *
     * @param int            $order_id Order ID passed by the status hook.
     * @param \WC_Order|null $order    Order object passed by the status hook.
     */
    public function track_promo_redemptions($order_id, $order = null)
    {
        if (!($order instanceof \WC_Order)) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        }
        if (!$order) {
            return;
        }

        // Already tallied for this order: never count twice.
        if ($order->get_meta('_drw_promos_counted')) {
            return;
        }

        $promo_ids = [];

        // Vía A – coupon-backed promos.
        foreach ($order->get_coupon_codes() as $code) {
            $promo = \Drw\App\Models\PromoModel::get_promo_by_code(strtoupper((string)$code));
            if ($promo && !empty($promo['id'])) {
                $promo_ids[(int)$promo['id']] = true;
            }
        }

        // Vía B – automatic promos stamped onto their discounted line items.
        foreach ($order->get_items() as $item) {
            $pid = $item->get_meta('_drw_promo_id', true);
            if ($pid === '' || $pid === null) {
                $pid = $item->get_meta('promo_id', true);
            }
            if ($pid !== '' && $pid !== null && (int)$pid > 0) {
                $promo_ids[(int)$pid] = true;
            }
        }

        // One increment per promo per order (array keys are already unique).
        foreach (array_keys($promo_ids) as $pid) {
            \Drw\App\Models\PromoModel::increment_usage((int)$pid);
        }

        // Flag the order as counted even when no promo matched, so a promo-less
        // order isn't re-scanned on every subsequent status transition.
        $order->update_meta_data('_drw_promos_counted', 1);
        $order->save();
    }

    /**
     * Modifies shipping package rates to set shipping rate cost to 0 when Free Shipping is unlocked.
     */
    public function modify_shipping_package_rates($rates, $package)
    {
        $cart = WC()->cart;
        if (!$cart) {
            return $rates;
        }

        $engine = \Drw\App\Controllers\RulesEngine::instance();
        $discounts = $engine->calculate_cart_level_discounts($cart);

        $free_shipping = !empty($discounts['free_shipping']);

        // SANDBOX MODE — additive free-shipping preview for a sandboxed
        // free_ship_threshold-type promo. Only evaluated when the REAL
        // engine hasn't already unlocked free shipping. No-op unless
        // get_sandbox_rule() resolved a valid, current-admin-owned override;
        // see that method for the full safety contract. Wrapped in
        // try/catch so a bug here can only ever affect the admin's OWN
        // preview cart, never fatal the request.
        if (!$free_shipping) {
            try {
                $sandbox_rule = $this->get_sandbox_rule();
                if (null !== $sandbox_rule && $engine->is_rule_matched($sandbox_rule, $cart)) {
                    $adjustments = !empty($sandbox_rule['adjustments']) ? (array)$sandbox_rule['adjustments'] : [];
                    if (!empty($adjustments['type']) && $adjustments['type'] === 'free_shipping') {
                        $shipping_engine = new \Drw\App\Adjustments\FreeShipping();
                        if ($shipping_engine->is_free_shipping_unlocked($adjustments, $cart)) {
                            $free_shipping = true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('[discount-rules-woo] Sandbox preview (free shipping) failed: ' . $e->getMessage());
            }
        }

        if ($free_shipping) {
            foreach ($rates as $rate_key => $rate) {
                $rates[$rate_key]->cost = 0;
                $taxes = [];
                foreach ($rates[$rate_key]->taxes as $key => $tax) {
                    $taxes[$key] = 0;
                }
                $rates[$rate_key]->taxes = $taxes;
            }
        }

        return $rates;
    }

    /**
     * Integrates coupon matching filters to hook into WooCommerce core coupon operations for dynamic rules.
     */
    public function get_shop_coupon_data($data, $code)
    {
        if (empty($code)) {
            return $data;
        }

        // If the coupon already exists in the database, let WooCommerce handle it
        if ($data !== false && !empty($data)) {
            return $data;
        }

        $engine = \Drw\App\Controllers\RulesEngine::instance();
        $rules = $engine->get_active_rules();

        if (empty($rules)) {
            return $data;
        }

        $coupon_matched = false;
        foreach ($rules as $rule) {
            $conditions = !empty($rule['conditions']) ? (array)$rule['conditions'] : [];
            foreach ($conditions as $cond) {
                $type = !empty($cond['type']) ? $cond['type'] : '';
                if ($type === 'cart_coupon' || $type === 'coupon') {
                    $target_coupons = !empty($cond['value']) ? (array)$cond['value'] : [];
                    $target_coupons = array_map('strtolower', array_map('trim', $target_coupons));
                    if (in_array(strtolower($code), $target_coupons, true) && \Drw\App\Conditions\CartCoupon::is_schedule_matched($cond)) {
                        $coupon_matched = true;
                        break 2;
                    }
                }
            }
        }

        if ($coupon_matched) {
            return [
                'id'                         => 99999900 + mt_rand(1, 99),
                'code'                       => $code,
                'amount'                     => 0,
                'discount_type'              => 'fixed_cart',
                'individual_use'             => false,
                'product_ids'                => array(),
                'exclude_product_ids'        => array(),
                'usage_limit'                => '',
                'usage_limit_per_user'       => '',
                'limit_usage_to_x_items'     => '',
                'expiry_date'                => '',
                'free_shipping'              => false,
                'product_categories'         => array(),
                'exclude_product_categories' => array(),
                'exclude_sale_items'         => false,
                'minimum_amount'             => '',
                'maximum_amount'             => '',
                'customer_email'             => array(),
            ];
        }

        return $data;
    }

    /**
     * Formats cart item price.
     */
    public function format_cart_item_price($price_html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['drw_discounted']) && isset($cart_item['drw_original_price']) && isset($cart_item['drw_discount_price'])) {
            $original = (float)$cart_item['drw_original_price'];
            $discounted = (float)$cart_item['drw_discount_price'];
            if ($original > $discounted) {
                $price_html = '<del>' . wc_price($original) . '</del> <ins>' . wc_price($discounted) . '</ins>';
            }
        }
        return $price_html;
    }

    /**
     * Formats cart item subtotal.
     */
    public function format_cart_item_subtotal($subtotal_html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['drw_discounted']) && isset($cart_item['drw_original_price']) && isset($cart_item['drw_discount_price'])) {
            $qty = $cart_item['quantity'];
            $original_subtotal = (float)$cart_item['drw_original_price'] * $qty;
            $discounted_subtotal = (float)$cart_item['drw_discount_price'] * $qty;
            if ($original_subtotal > $discounted_subtotal) {
                $subtotal_html = '<del>' . wc_price($original_subtotal) . '</del> <ins>' . wc_price($discounted_subtotal) . '</ins>';
            }
        }
        return $subtotal_html;
    }

    /**
     * Formats cart totals order total html.
     */
    public function format_cart_totals_order_total_html($value)
    {
        $cart = WC()->cart;
        if ($cart) {
            $savings = $this->get_total_savings($cart);
            if ($savings > 0) {
                $value .= '<div class="drw-savings-message" style="font-size: 0.9em; color: #10b981; margin-top: 5px;">' . sprintf(__('You Saved: %s', 'discount-rules-woo'), wc_price($savings)) . '</div>';
            }
        }
        return $value;
    }

    /**
     * Formats formatted order total.
     */
    public function format_order_total($formatted_total, $order, $tax_display, $display_refunded)
    {
        $savings = $this->get_order_total_savings($order);
        if ($savings > 0) {
            $formatted_total .= '<div class="drw-savings-message" style="font-size: 0.9em; color: #10b981; margin-top: 5px;">' . sprintf(__('You Saved: %s', 'discount-rules-woo'), wc_price($savings)) . '</div>';
        }
        return $formatted_total;
    }

    /**
     * Formats order formatted line subtotal.
     */
    public function format_order_line_subtotal($subtotal, $item, $order)
    {
        $original = $item->get_meta('_drw_original_price', true);
        $discounted = $item->get_meta('_drw_discount_price', true);
        if ($original !== '' && $discounted !== '') {
            $original = (float)$original;
            $discounted = (float)$discounted;
            if ($original > $discounted) {
                $qty = (int)$item->get_quantity();
                $subtotal = '<del>' . wc_price($original * $qty) . '</del> <ins>' . wc_price($discounted * $qty) . '</ins>';
            }
        }
        return $subtotal;
    }

    /**
     * Displays admin order totals after total.
     */
    public function display_admin_order_totals_after_total($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $savings = $this->get_order_total_savings($order);
        if ($savings > 0) {
            ?>
            <tr>
                <td class="label"><?php _e('Total Saved:', 'discount-rules-woo'); ?></td>
                <td width="1%"></td>
                <td class="total" style="color: #10b981; font-weight: bold;">
                    <?php echo wc_price($savings); ?>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Helper to compute total cart savings.
     */
    private function get_total_savings($cart)
    {
        if (!$cart) {
            return 0.0;
        }

        $savings = 0.0;
        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['drw_discounted']) && isset($cart_item['drw_original_price']) && isset($cart_item['drw_discount_price'])) {
                $diff = (float)$cart_item['drw_original_price'] - (float)$cart_item['drw_discount_price'];
                if ($diff > 0) {
                    $savings += $diff * $cart_item['quantity'];
                }
            }
        }

        foreach ($cart->get_fees() as $fee) {
            if ($fee->amount < 0) {
                $savings += abs($fee->amount);
            }
        }

        return $savings;
    }

    /**
     * Helper to compute total order savings.
     */
    private function get_order_total_savings($order)
    {
        if (!$order) {
            return 0.0;
        }

        $savings = 0.0;
        foreach ($order->get_items() as $item) {
            $original = $item->get_meta('_drw_original_price', true);
            $discounted = $item->get_meta('_drw_discount_price', true);
            if ($original !== '' && $discounted !== '') {
                $diff = (float)$original - (float)$discounted;
                if ($diff > 0) {
                    $savings += $diff * (int)$item->get_quantity();
                }
            }
        }

        foreach ($order->get_fees() as $fee) {
            if ($fee->get_total() < 0) {
                $savings += abs($fee->get_total());
            }
        }

        return $savings;
    }

    /**
     * Check if product is in the products or categories lists.
     */
    private function is_product_in_list($product, $product_ids, $category_ids)
    {
        if (empty($product_ids) && empty($category_ids)) {
            return true;
        }
        
        $product_id = $product->get_id();
        $parent_id  = $product->get_parent_id();
        
        if (!empty($product_ids)) {
            $ids = array_map('intval', $product_ids);
            if (in_array($product_id, $ids, true) || ($parent_id && in_array($parent_id, $ids, true))) {
                return true;
            }
        }
        
        if (!empty($category_ids)) {
            $cats = array_map('intval', $category_ids);
            $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
            if (!empty(array_intersect($product_cats, $cats))) {
                return true;
            }
        }
        
        return false;
    }
}
