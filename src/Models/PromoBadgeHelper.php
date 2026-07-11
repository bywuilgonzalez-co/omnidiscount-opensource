<?php

namespace Drw\App\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PromoBadgeHelper — single source of truth for the badge/progress/message
 * descriptors of every Vía B (automatic) promo whose compiled wp_drw_rules row
 * (source='promo') is currently active.
 *
 * Extracted verbatim from StoreApiController::collect_promo_badges() so the same
 * data can drive multiple surfaces without duplicating the logic:
 *   - StoreApiController::get_cart_extension_data() — Store API schema payload
 *     for Cart/Checkout Blocks.
 *   - StoreApiController::emit_promo_cart_notices() — Block Cart notices.
 *   - CartController — the classic mini-cart widget / [woocommerce_cart].
 */
class PromoBadgeHelper
{
    /**
     * Build badge/progress/message data for every Vía B (automatic) promo
     * whose compiled wp_drw_rules row (source='promo') is currently active,
     * so any surface can render a badge, a progress bar (free_ship_threshold
     * especially) and the merchant's cart_message.
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
     * @param bool     $require_minicart_opt_in Whether to gate each promo on
     *   its own show_in_minicart flag. True (default) for the two surfaces
     *   this gate is actually documented/labeled for — the classic mini-cart
     *   widget and the Blocks Mini-Cart drawer badge. Pass false for
     *   surfaces that are NOT the mini-cart drawer (e.g. Cart/Checkout Block
     *   page notices), which must not be silently suppressed by a toggle
     *   scoped to a different UI element.
     * @return array List of badge descriptors.
     */
    public static function collect($cart, $require_minicart_opt_in = true)
    {
        if (!$cart || $cart->is_empty()) {
            return [];
        }

        $engine = \Drw\App\Controllers\RulesEngine::instance();
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

            // Opt-in per promo: the mini-cart badge is OFF by default
            // (show_in_minicart defaults to 0 in wp_drw_promos) and only
            // renders once the merchant explicitly turns it on for THIS
            // promo. This gate only applies to promo-compiled rules (the
            // branch above already `continue`s past hand-authored "Reglas
            // avanzadas", which have no promo to check the flag on, so their
            // badge behaviour is unchanged by this gate). Callers that are
            // not the mini-cart drawer itself (e.g. Cart/Checkout Block page
            // notices) pass $require_minicart_opt_in = false to skip this
            // gate entirely — it is scoped to "Mostrar en el mini-carrito",
            // not to whether the promo's message may appear anywhere at all.
            if ($require_minicart_opt_in && empty($promo['show_in_minicart'])) {
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
                // Best-effort re-check against exclude_sale_items: is_rule_matched()
                // only evaluates conditions, so it can't see that a rule opted out of
                // discounting on-sale products. If the cart's contents are entirely
                // on sale, the rule's real per-item discount is $0 even though its
                // conditions matched, so the badge must not claim "applied". Same
                // best-effort spirit as the rest of this method (see class docblock):
                // this does not re-verify product-level targeting (apply_to/filters),
                // it only checks whether ANY non-sale product exists in the cart.
                if ($applied && !empty($rule['exclude_sale_items'])) {
                    $applied = self::has_non_sale_cart_item($cart);
                }
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
     * Whether at least one cart line is a WC_Product NOT currently on sale.
     * Used only as the best-effort exclude_sale_items re-check above.
     *
     * @param \WC_Cart $cart
     * @return bool
     */
    private static function has_non_sale_cart_item($cart)
    {
        foreach ($cart->get_cart() as $item) {
            $product = isset($item['data']) ? $item['data'] : null;
            if ($product instanceof \WC_Product && !$product->is_on_sale()) {
                return true;
            }
        }

        return false;
    }
}
