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
     * @return array List of badge descriptors.
     */
    public static function collect($cart)
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
}
