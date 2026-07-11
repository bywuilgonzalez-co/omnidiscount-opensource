<?php

namespace Drw\App\Controllers;

use Drw\App\Models\PromoModel;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mints the single-use welcome coupon behind a popup email-capture
 * submission (wp_drw_popup_submissions row).
 *
 * Mirrors PromoBridgeController::compile_coupon()/build_coupon_data()'s
 * WC_Coupon construction pattern (same sequence of set_*() calls), but is
 * otherwise a SEPARATE, self-contained subsystem — see the plan's
 * "Restricción no negociable": it does NOT read or write PromoModel/
 * PromoTypeRegistry/wp_drw_promos beyond calling PromoModel::code_exists()
 * for the collision check below, and it never touches RulesEngine/
 * Adjustments/Conditions. Called internally only (from PopupController); no
 * register_hooks() here, same as PromoBridgeController.
 *
 * SECURITY NOTE (why this class clamps server-side, unlike its Vía A
 * reference): compile_coupon() never bounds a percent-type promo's `value`
 * to [0,100] because every promo passes through
 * PromosController::validate_promo()/assert_activatable() first, which
 * already rejects an out-of-range percent before it ever reaches the
 * bridge. The popup has no equivalent admin-side gate on the SAME request
 * that mints the coupon — `popup.discount_value` is merchant-configured
 * ahead of time via SettingsModel, but a bug or a future direct DB edit
 * could still hand this class an out-of-range number, and unlike a promo
 * this is a single anonymous-traffic-triggered mint with no second human
 * ever reviewing it before it goes live. mint_coupon() therefore clamps
 * unconditionally, every time, regardless of what SettingsModel validation
 * already did upstream.
 */
class PopupCouponBridge
{
    /**
     * 32-symbol alphabet, deliberately excluding 0/O/1/I/L so a re-typed
     * code (customer copying it off a phone screen, or from a printed
     * receipt) is never ambiguous.
     */
    const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Generated code length. 32^8 possibilities makes exhaustion effectively impossible. */
    const CODE_LENGTH = 8;

    /** Collision-retry budget before generate_unique_code() gives up. */
    const MAX_ATTEMPTS = 5;

    /**
     * Generate a code that collides with none of the three places a coupon
     * code can already live: native WooCommerce coupons (a coupon code is
     * global across all of WooCommerce, not scoped to this plugin), the
     * promo catalogue (wp_drw_promos, via PromoModel::code_exists()), and
     * this class's own previously-minted popup codes
     * (wp_drw_popup_submissions.coupon_code). Retries up to MAX_ATTEMPTS
     * times; returns a WP_Error on exhaustion rather than silently reusing
     * or mangling a colliding code.
     *
     * @return string|\WP_Error
     */
    public static function generate_unique_code()
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = self::random_code();
            if (!self::code_collides($code)) {
                return $code;
            }
        }

        return new \WP_Error(
            'drw_popup_code_exhausted',
            __('No se pudo generar un código de cupón único. Inténtalo de nuevo.', 'discount-rules-woo'),
            array('status' => 500)
        );
    }

    /**
     * Mint the real WC_Coupon for a submission: generates a collision-free
     * code, applies the anti-abuse clamps documented on the class docblock,
     * and constructs the coupon with the fixed, non-configurable
     * individual_use/usage_limit/usage_limit_per_user flags that are this
     * feature's actual security model (see the plan's "Anti-abuso" §4 — a
     * merchant setting could otherwise turn a "one code per visitor" popup
     * into an unlimited-use shared code).
     *
     * @param int   $submission_id wp_drw_popup_submissions.id this coupon belongs to.
     * @param array $template      The 'popup' settings sub-array (SettingsModel::get_setting('popup')
     *                             shape): discount_type, discount_value, expiry_days, min_cart_amount.
     * @return array|\WP_Error {
     *   @type int    $coupon_id
     *   @type string $code
     * }
     */
    public static function mint_coupon($submission_id, array $template)
    {
        $code = self::generate_unique_code();
        if (is_wp_error($code)) {
            return $code;
        }

        $discount_type  = isset($template['discount_type']) ? (string)$template['discount_type'] : 'percent';
        $raw_value      = isset($template['discount_value']) ? (float)$template['discount_value'] : 0.0;
        $raw_expiry     = isset($template['expiry_days']) ? (int)$template['expiry_days'] : 0;
        $raw_min_amount = isset($template['min_cart_amount']) ? (float)$template['min_cart_amount'] : 0.0;

        // Normalize ONCE, then reuse the same boolean for both the value
        // clamp and the WC discount-type branch below. Deliberately NOT two
        // separate '===' checks against the literal strings 'percent'/
        // 'fixed' -- that used to let a $discount_type outside those two
        // exact strings (e.g. '', a future third type, a bad direct DB
        // write) fall into "not fixed" for the type branch (defaulting to
        // WooCommerce's 'percent' discount type) while simultaneously
        // falling into "not percent" for the value clamp (skipping the
        // [0,100] cap) -- producing an unclamped percent-type coupon (e.g.
        // 5000% off). Any non-'fixed' input is now consistently treated as
        // percent by BOTH branches.
        $is_fixed = ('fixed' === $discount_type);

        // Clamp #1: percent bounded to [0,100]; fixed bounded to a
        // non-negative amount (mirrors RuleModel::sanitize_adjustments()'s
        // treatment of percentage adjustments, applied here because
        // compile_coupon() itself does not -- see class docblock).
        $discount_value = $is_fixed
            ? max(0.0, $raw_value)
            : max(0.0, min(100.0, $raw_value));

        // Clamp #2: expiry bounded to [1,365] days -- never an immediately
        // (or already) expired coupon, never an effectively-forever one.
        $expiry_days = max(1, min(365, $raw_expiry));

        // Clamp #3: minimum cart amount, if any, is never negative.
        $min_cart_amount = max(0.0, $raw_min_amount);

        $coupon = new \WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type($is_fixed ? 'fixed_cart' : 'percent');
        $coupon->set_amount($discount_value);

        // Fixed in code, never exposed as a merchant setting -- see class
        // docblock and plan §"Anti-abuso" point 4.
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);

        // GMT, NOT current_time('timestamp') (no $gmt arg) -- WC_Data::set_date_prop()
        // treats whatever integer this receives as a literal UTC timestamp
        // (see WooCommerce core's abstract-wc-data.php), so seeding the '+N
        // days' calculation from WordPress's "fake local" timestamp would
        // shift the real expiry by the site's gmt_offset on any non-UTC
        // site -- the exact class of bug this file's own docblock (and
        // PopupModel's) calls out for every other DATETIME value in this
        // feature.
        $coupon->set_date_expires(strtotime('+' . $expiry_days . ' days', current_time('timestamp', true)));

        if ($min_cart_amount > 0) {
            $coupon->set_minimum_amount($min_cart_amount);
        }

        // Traceability only, for the identity-check phase (a later phase) to
        // recognise this as a popup-minted welcome coupon. Deliberately NOT
        // '_drw_welcome_coupon_used' -- that meta belongs on the ORDER, set
        // by CartController when the coupon is actually redeemed at
        // checkout, not here at mint time.
        $coupon->update_meta_data('_drw_popup_submission_id', (int)$submission_id);

        $coupon->save();

        return array(
            'coupon_id' => (int)$coupon->get_id(),
            'code'      => $code,
        );
    }

    /**
     * Whether $code already exists in any of the three places a coupon code
     * can live (see generate_unique_code()'s docblock).
     *
     * @param string $code
     * @return bool
     */
    private static function code_collides($code)
    {
        if (function_exists('wc_get_coupon_id_by_code') && (int)wc_get_coupon_id_by_code($code) > 0) {
            return true;
        }

        if (PromoModel::code_exists($code)) {
            return true;
        }

        return self::popup_code_exists($code);
    }

    /**
     * Whether $code is already recorded as a minted popup coupon.
     *
     * @param string $code
     * @return bool
     */
    private static function popup_code_exists($code)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_popup_submissions';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE coupon_code = %s",
            $code
        ));

        return (int)$count > 0;
    }

    /**
     * Generate a single random CODE_LENGTH-character code from
     * CODE_ALPHABET using random_int() (CSPRNG) -- never rand()/mt_rand(),
     * since a predictable coupon code generator would let an attacker guess
     * other visitors' single-use welcome codes.
     *
     * @return string
     */
    private static function random_code()
    {
        $alphabet = self::CODE_ALPHABET;
        $max      = strlen($alphabet) - 1;

        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
