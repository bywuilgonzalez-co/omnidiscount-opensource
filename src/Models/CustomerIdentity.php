<?php

namespace Drw\App\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the current checkout customer to a stable string key used by
 * atomic per-customer redemption reservations (RuleModel::try_reserve_usage()
 * / customer_redemption_count()).
 *
 * This is a DELIBERATE duplicate of the customer-resolution logic already
 * used by Conditions/UserEmail.php and Conditions/PurchaseHistory.php (logged
 * in -> current user; guest -> billing email from $_POST / WC()->customer),
 * kept standalone on purpose per this project's established pattern rather
 * than refactoring those frozen Conditions/* files to share code.
 */
class CustomerIdentity
{
    /**
     * Resolve a stable identity key for the customer currently at checkout.
     *
     * 'user:<id>' for a logged-in user, 'email:<normalized email>' for a
     * guest. Returns null when no identity can be determined at all (no
     * logged-in user and no billing email available anywhere).
     *
     * @return string|null
     */
    public static function resolve_current()
    {
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            if ($user_id > 0) {
                return 'user:' . $user_id;
            }
        }

        $email = '';

        // Fallback to billing email during checkout if posted directly.
        if (empty($email) && !empty($_POST['billing_email'])) {
            $email = sanitize_email(wp_unslash($_POST['billing_email']));
        }

        // Fallback to the live WooCommerce customer/session object.
        if (empty($email) && function_exists('WC') && !empty(WC()->customer)) {
            $email = WC()->customer->get_billing_email();
        }

        return self::normalize_email($email);
    }

    /**
     * Resolve a stable identity key from a WC_Order object. Used around order
     * creation (reservation time), when there is no live cart/session to
     * read from.
     *
     * @param \WC_Order $order
     * @return string|null
     */
    public static function resolve_from_order($order)
    {
        if (!$order || !is_object($order) || !method_exists($order, 'get_customer_id')) {
            return null;
        }

        $customer_id = (int)$order->get_customer_id();
        if ($customer_id > 0) {
            return 'user:' . $customer_id;
        }

        $email = method_exists($order, 'get_billing_email') ? $order->get_billing_email() : '';

        return self::normalize_email($email);
    }

    /**
     * Lowercase + trim an email address into the 'email:<normalized>' key
     * form, or null if empty/invalid.
     *
     * @param string $email
     * @return string|null
     */
    private static function normalize_email($email)
    {
        $email = strtolower(trim((string)$email));

        if (empty($email)) {
            return null;
        }

        return 'email:' . $email;
    }
}
