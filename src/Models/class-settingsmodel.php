<?php

namespace Drw\App\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SettingsModel - Centralized settings store.
 */
class SettingsModel {
	const OPTION_KEY = 'drw_settings';
	const CACHE_KEY  = 'drw_settings_cache';
	const CACHE_TTL  = 43200;

	/**
	 * Return the complete default settings structure.
	 */
	public static function get_defaults() {
		return array(
			'discount_types' => array(
				'percentage'    => array( 'enabled' => true ),
				'fixed'         => array( 'enabled' => true ),
				'bulk'          => array(
					'enabled'   => true,
					'max_tiers' => 10,
				),
				'bogo'          => array( 'enabled' => true ),
				'bundle_set'    => array( 'enabled' => true ),
				'free_shipping' => array( 'enabled' => true ),
			),
			'conditions'     => array(
				'cart_subtotal'                 => array( 'enabled' => true ),
				'cart_items_quantity'           => array( 'enabled' => true ),
				'cart_items_weight'             => array( 'enabled' => true ),
				'cart_line_items_count'         => array( 'enabled' => true ),
				'billing_city'                  => array( 'enabled' => true ),
				'shipping_location'             => array( 'enabled' => true ),
				'user_logged_in'                => array( 'enabled' => true ),
				'user_email'                    => array( 'enabled' => true ),
				'user_role'                     => array( 'enabled' => true ),
				'user_list'                     => array( 'enabled' => true ),
				'cart_coupon'                   => array( 'enabled' => true ),
				'products'                      => array( 'enabled' => true ),
				'categories'                    => array( 'enabled' => true ),
				'cart_item_product_combination' => array( 'enabled' => true ),
				'cart_item_product_onsale'      => array( 'enabled' => true ),
				'purchase_history'              => array( 'enabled' => true ),
				'order_date'                    => array( 'enabled' => true ),
			),
			'filters'        => array(
				'product_ids'          => array( 'enabled' => true ),
				'category_ids'         => array( 'enabled' => true ),
				'exclude_product_ids'  => array( 'enabled' => true ),
				'exclude_category_ids' => array( 'enabled' => true ),
			),
			'theme'          => array(
				'preset'        => 'default',
				'custom_colors' => array(
					'primary'             => '#3b82f6',
					'secondary'           => '#475569',
					'success'             => '#16a34a',
					'warning'             => '#ea580c',
					'danger'              => '#dc2626',
					'badge_enabled_bg'    => '#dcfce7',
					'badge_enabled_text'  => '#166534',
					'badge_disabled_bg'   => '#f1f5f9',
					'badge_disabled_text' => '#64748b',
				),
				'typography'    => array(
					'font_family'  => 'system-ui',
					'base_size'    => 14,
					'heading_size' => 20,
				),
				'spacing'       => array(
					'padding_base'  => 16,
					'border_radius' => 8,
					'shadow_level'  => 'medium',
				),
			),
			'rules_behavior' => array(
				'allow_multiple_discounts' => true,
				'combination_strategy'     => 'sum_best',
				'apply_order'              => 'priority',
				'exclusive_override'       => true,
			),
			'features'       => array(
				'enable_scheduling'     => true,
				'enable_usage_limits'   => true,
				'enable_debug_mode'     => false,
				'show_discount_labels'  => true,
				'show_minicart_promos'  => true,
				'round_prices'          => 'standard',
			),
		);
	}

	/**
	 * Get all settings: defaults deep-merged with saved values.
	 */
	public static function get_all_settings() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$defaults = self::get_defaults();
		$saved    = get_option( self::OPTION_KEY, array() );
		$saved    = is_array( $saved ) ? $saved : array();

		$merged = self::deep_merge( $defaults, $saved );
		set_transient( self::CACHE_KEY, $merged, self::CACHE_TTL );

		return $merged;
	}

	/**
	 * Get a single setting by dot-path key.
	 */
	public static function get_setting( $key, $fallback = null ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return $fallback;
		}

		$value = self::get_all_settings();

		foreach ( explode( '.', $key ) as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
			} else {
				return $fallback;
			}
		}

		return $value;
	}

	/**
	 * Save a single setting by dot-path key.
	 */
	public static function save_setting( $key, $value ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return false;
		}

		$clean = self::validate_setting( $key, $value );

		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$saved = self::set_by_path( $saved, $key, $clean );

		update_option( self::OPTION_KEY, $saved );
		self::flush_cache();

		return true;
	}

	/**
	 * Validate and sanitize a value for a given dot-path key.
	 */
	public static function validate_setting( $key, $value ) {
		$key     = (string) $key;
		$default = self::get_default_for_key( $key, $found );

		if ( ! $found ) {
			return self::sanitize_scalar( $value );
		}

		return self::sanitize_against_default( $value, $default );
	}

	/**
	 * Restore all settings to their original default values.
	 */
	public static function reset_to_defaults() {
		delete_option( self::OPTION_KEY );
		self::flush_cache();

		return self::get_defaults();
	}

	/**
	 * Flush the merged-settings transient cache.
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Get theme presets.
	 */
	public static function get_theme_presets() {
		return array(
			'default'  => array(
				'name'   => 'Default',
				'colors' => array(
					'primary'             => '#3b82f6',
					'secondary'           => '#475569',
					'success'             => '#16a34a',
					'warning'             => '#ea580c',
					'danger'              => '#dc2626',
					'badge_enabled_bg'    => '#dcfce7',
					'badge_enabled_text'  => '#166534',
					'badge_disabled_bg'   => '#f1f5f9',
					'badge_disabled_text' => '#64748b',
				),
			),
			'dark'     => array(
				'name'   => 'Dark',
				'colors' => array(
					'primary'             => '#60a5fa',
					'secondary'           => '#e2e8f0',
					'success'             => '#4ade80',
					'warning'             => '#fbbf24',
					'danger'              => '#f87171',
					'badge_enabled_bg'    => '#1e3a1f',
					'badge_enabled_text'  => '#86efac',
					'badge_disabled_bg'   => '#1e293b',
					'badge_disabled_text' => '#94a3b8',
				),
			),
			'colorful' => array(
				'name'   => 'Colorful',
				'colors' => array(
					'primary'             => '#8b5cf6',
					'secondary'           => '#ec4899',
					'success'             => '#06b6d4',
					'warning'             => '#f59e0b',
					'danger'              => '#ef4444',
					'badge_enabled_bg'    => '#ede9fe',
					'badge_enabled_text'  => '#6d28d9',
					'badge_disabled_bg'   => '#fce7f3',
					'badge_disabled_text' => '#be185d',
				),
			),
			'minimal'  => array(
				'name'   => 'Minimal',
				'colors' => array(
					'primary'             => '#000000',
					'secondary'           => '#404040',
					'success'             => '#000000',
					'warning'             => '#000000',
					'danger'              => '#000000',
					'badge_enabled_bg'    => '#f5f5f5',
					'badge_enabled_text'  => '#000000',
					'badge_disabled_bg'   => '#e5e5e5',
					'badge_disabled_text' => '#737373',
				),
			),
		);
	}

	/**
	 * Deep merge defaults with overrides.
	 */
	private static function deep_merge( $defaults, $overrides ) {
		$result = $defaults;

		foreach ( $overrides as $key => $value ) {
			if (
				array_key_exists( $key, $result )
				&& is_array( $result[ $key ] )
				&& is_array( $value )
				&& self::is_assoc( $result[ $key ] )
			) {
				$result[ $key ] = self::deep_merge( $result[ $key ], $value );
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Get default value for a dot-path key.
	 */
	private static function get_default_for_key( $key, &$found = null ) {
		$found = false;
		$node  = self::get_defaults();

		foreach ( explode( '.', (string) $key ) as $segment ) {
			if ( is_array( $node ) && array_key_exists( $segment, $node ) ) {
				$node = $node[ $segment ];
			} else {
				return null;
			}
		}

		$found = true;
		return $node;
	}

	/**
	 * Set value in array using dot-path key.
	 */
	private static function set_by_path( $arr, $key, $value ) {
		$segments = explode( '.', (string) $key );
		$ref      = &$arr;

		while ( count( $segments ) > 1 ) {
			$segment = array_shift( $segments );
			if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
				$ref[ $segment ] = array();
			}
			$ref = &$ref[ $segment ];
		}

		$ref[ array_shift( $segments ) ] = $value;

		return $arr;
	}

	/**
	 * Sanitize value against default structure.
	 */
	private static function sanitize_against_default( $value, $fallback ) {
		if ( is_array( $fallback ) && self::is_assoc( $fallback ) ) {
			$value = is_array( $value ) ? $value : array();
			$clean = array();
			foreach ( $fallback as $sub_key => $sub_default ) {
				if ( array_key_exists( $sub_key, $value ) ) {
					$clean[ $sub_key ] = self::sanitize_against_default( $value[ $sub_key ], $sub_default );
				} else {
					$clean[ $sub_key ] = $sub_default;
				}
			}
			return $clean;
		}

		if ( is_bool( $fallback ) ) {
			return self::to_bool( $value );
		}

		if ( is_int( $fallback ) ) {
			$int = (int) $value;
			return max( 0, $int );
		}

		if ( is_float( $fallback ) ) {
			return (float) $value;
		}

		if ( is_string( $fallback ) && self::is_hex_color( $fallback ) ) {
			$color = function_exists( 'sanitize_hex_color' )
				? sanitize_hex_color( (string) $value )
				: ( ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', (string) $value ) ) ? (string) $value : null );
			return $color ? $color : $fallback;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Sanitize scalar value.
	 */
	private static function sanitize_scalar( $value ) {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Convert value to boolean.
	 */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$v = strtolower( trim( $value ) );
			return in_array( $v, array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}

	/**
	 * Check if array is associative.
	 */
	private static function is_assoc( $arr ) {
		if ( ! is_array( $arr ) || array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Check if value is hex color.
	 */
	private static function is_hex_color( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $value );
	}
}
