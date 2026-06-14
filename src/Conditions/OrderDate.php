<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class OrderDate implements ConditionInterface
{
    /**
     * Check if the current date, time, and weekday fall within the configured schedule ranges.
     *
     * @param array $data The condition configuration.
     * @param \WC_Cart|null $cart WooCommerce Cart object.
     * @param \WC_Product|null $product WooCommerce Product object.
     * @return bool
     */
    public function check(array $data, $cart = null, $product = null)
    {
        $operator = !empty($data['operator']) ? $data['operator'] : 'in_list'; // in_list (within range), not_in_list (outside range)
        
        $current_time = current_time('timestamp');

        $in_range = true;

        // 1. Date Range Check
        $date_from = isset($data['date_from']) ? $data['date_from'] : '';
        $date_to   = isset($data['date_to']) ? $data['date_to'] : '';

        if (!empty($date_from)) {
            $from_ts = is_numeric($date_from) ? (int)$date_from : strtotime($date_from);
            if ($from_ts && $current_time < $from_ts) {
                $in_range = false;
            }
        }
        if ($in_range && !empty($date_to)) {
            $to_ts = is_numeric($date_to) ? (int)$date_to : strtotime($date_to);
            if ($to_ts && $current_time > $to_ts) {
                $in_range = false;
            }
        }

        // 2. Time Range Check (format e.g., '09:00' to '18:00')
        if ($in_range) {
            $time_from = !empty($data['time_from']) ? $data['time_from'] : (!empty($data['order_time_from']) ? $data['order_time_from'] : '');
            $time_to   = !empty($data['time_to']) ? $data['time_to'] : (!empty($data['order_time_to']) ? $data['order_time_to'] : '');
            
            if (!empty($time_from) || !empty($time_to)) {
                $current_time_str = date('H:i', $current_time);
                
                if (!empty($time_from) && $current_time_str < $time_from) {
                    $in_range = false;
                }
                if ($in_range && !empty($time_to) && $current_time_str > $time_to) {
                    $in_range = false;
                }
            }
        }

        // 3. Allowed Weekdays Check
        if ($in_range) {
            $weekdays = !empty($data['weekdays']) ? (array)$data['weekdays'] : (!empty($data['allowed_weekdays']) ? (array)$data['allowed_weekdays'] : []);
            
            if (!empty($weekdays)) {
                $current_w_numeric = (int)date('w', $current_time); // 0 (Sun) - 6 (Sat)
                $current_N_numeric = (int)date('N', $current_time); // 1 (Mon) - 7 (Sun)
                $current_name = strtolower(date('l', $current_time)); // e.g. 'monday'
                
                $weekday_matched = false;
                foreach ($weekdays as $day) {
                    $day_clean = strtolower(trim($day));
                    if (is_numeric($day_clean)) {
                        $day_val = (int)$day_clean;
                        if ($day_val === $current_w_numeric || $day_val === $current_N_numeric) {
                            $weekday_matched = true;
                            break;
                        }
                    } else {
                        // Match full name or abbreviation (e.g. 'mon' matches 'monday')
                        if ($day_clean === $current_name || strpos($current_name, $day_clean) === 0) {
                            $weekday_matched = true;
                            break;
                        }
                    }
                }
                if (!$weekday_matched) {
                    $in_range = false;
                }
            }
        }

        // 4. Operator Evaluation
        if ($operator === 'in_list' || $operator === 'in_range') {
            return $in_range;
        } elseif ($operator === 'not_in_list' || $operator === 'not_in_range') {
            return !$in_range;
        }

        return $in_range;
    }
}
