<?php

namespace Drw\App\Controllers;

use Drw\App\Models\PromoTypeRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for managing promotional offers / coupons.
 *
 * Storage: wp_options key `drw_promos` (JSON array).
 */
class PromosController {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * WordPress options key used to persist promos.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'drw_promos';

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	/**
	 * Register REST endpoints under drw/v1.
	 */
	public function register_routes() {
		$namespace = 'drw/v1';

		// GET  /promos      – list all
		// POST /promos      – create
		register_rest_route(
			$namespace,
			'/promos',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_promos' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_promo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// PUT    /promos/<id>  – update
		// DELETE /promos/<id>  – delete
		register_rest_route(
			$namespace,
			'/promos/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_promo' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_promo' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		// POST /promos/<id>/toggle – toggle active flag
		register_rest_route(
			$namespace,
			'/promos/(?P<id>\d+)/toggle',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'toggle_promo' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		// GET /promos/types – type definitions catalogue
		register_rest_route(
			$namespace,
			'/promos/types',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_types' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Permission
	// ------------------------------------------------------------------

	/**
	 * Require manage_woocommerce capability.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	// ------------------------------------------------------------------
	// Endpoint callbacks
	// ------------------------------------------------------------------

	/**
	 * GET /drw/v1/promos
	 *
	 * @return \WP_REST_Response
	 */
	public function get_promos() {
		$promos = $this->load_promos();
		return new \WP_REST_Response( $promos, 200 );
	}

	/**
	 * POST /drw/v1/promos
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function create_promo( $request ) {
		$data = $this->get_request_data( $request );

		$validated = $this->validate_promo( $data );
		if ( is_wp_error( $validated ) ) {
			return $this->validation_error_response( $validated );
		}

		$promos = $this->load_promos();

		$new_id           = $this->next_id( $promos );
		$validated['id']  = $new_id;
		$validated['uses'] = 0;

		$promos[] = $validated;
		$this->save_promos( $promos );

		return new \WP_REST_Response( $validated, 201 );
	}

	/**
	 * PUT /drw/v1/promos/<id>
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function update_promo( $request ) {
		$id     = (int) $request['id'];
		$promos = $this->load_promos();
		$index  = $this->find_index( $promos, $id );

		if ( false === $index ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Promo not found', 'discount-rules-woo' ) ),
				404
			);
		}

		$data      = $this->get_request_data( $request );
		$validated = $this->validate_promo( $data, true, $id );
		if ( is_wp_error( $validated ) ) {
			return $this->validation_error_response( $validated );
		}

		// Preserve immutable fields.
		$validated['id']  = $id;
		$validated['uses'] = isset( $promos[ $index ]['uses'] ) ? (int) $promos[ $index ]['uses'] : 0;

		$promos[ $index ] = $validated;
		$this->save_promos( $promos );

		return new \WP_REST_Response( $validated, 200 );
	}

	/**
	 * DELETE /drw/v1/promos/<id>
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function delete_promo( $request ) {
		$id     = (int) $request['id'];
		$promos = $this->load_promos();
		$index  = $this->find_index( $promos, $id );

		if ( false === $index ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Promo not found', 'discount-rules-woo' ) ),
				404
			);
		}

		array_splice( $promos, $index, 1 );
		$this->save_promos( $promos );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Promo deleted', 'discount-rules-woo' ),
			),
			200
		);
	}

	/**
	 * POST /drw/v1/promos/<id>/toggle
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function toggle_promo( $request ) {
		$id     = (int) $request['id'];
		$promos = $this->load_promos();
		$index  = $this->find_index( $promos, $id );

		if ( false === $index ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Promo not found', 'discount-rules-woo' ) ),
				404
			);
		}

		$promos[ $index ]['active'] = ! $promos[ $index ]['active'];
		$this->save_promos( $promos );

		return new \WP_REST_Response( $promos[ $index ], 200 );
	}

	/**
	 * GET /drw/v1/promos/types
	 *
	 * Returns the catalogue of supported promo types. Kept as a refresh
	 * endpoint; the admin UI receives the same data preloaded via
	 * wp_localize_script (see AdminController::enqueue_admin_assets).
	 *
	 * @return \WP_REST_Response
	 */
	public function get_types() {
		return new \WP_REST_Response( PromoTypeRegistry::all(), 200 );
	}

	// ------------------------------------------------------------------
	// Storage helpers
	// ------------------------------------------------------------------

	/**
	 * Load promos from the options table.
	 *
	 * @return array
	 */
	private function load_promos() {
		$raw = get_option( self::OPTION_KEY, '[]' );
		$arr = json_decode( $raw, true );
		return is_array( $arr ) ? $arr : array();
	}

	/**
	 * Persist promos to the options table.
	 *
	 * @param array $promos Promos array.
	 */
	private function save_promos( $promos ) {
		update_option( self::OPTION_KEY, wp_json_encode( array_values( $promos ) ), false );
	}

	/**
	 * Determine the next auto-increment ID.
	 *
	 * @param array $promos Existing promos.
	 * @return int
	 */
	private function next_id( $promos ) {
		$max = 0;
		foreach ( $promos as $p ) {
			if ( isset( $p['id'] ) && (int) $p['id'] > $max ) {
				$max = (int) $p['id'];
			}
		}
		return $max + 1;
	}

	/**
	 * Find the array index of a promo by ID.
	 *
	 * @param array $promos Promos array.
	 * @param int   $id     Promo ID.
	 * @return int|false
	 */
	private function find_index( $promos, $id ) {
		foreach ( $promos as $i => $p ) {
			if ( isset( $p['id'] ) && (int) $p['id'] === $id ) {
				return $i;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------
	// Validation & sanitisation
	// ------------------------------------------------------------------

	/**
	 * Extract JSON or body params from the request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	private function get_request_data( $request ) {
		$data = $request->get_json_params();
		if ( empty( $data ) ) {
			$data = $request->get_body_params();
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Validate and sanitise promo payload.
	 *
	 * @param array    $data       Raw input data.
	 * @param bool     $is_update  Whether this is an update (relaxed required checks).
	 * @param int|null $exclude_id Promo id to exclude from code-uniqueness checks (the promo being updated).
	 * @return array|\WP_Error Sanitised promo array or error.
	 */
	private function validate_promo( $data, $is_update = false, $exclude_id = null ) {

		// --- name -----------------------------------------------------------
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( strlen( $name ) < 3 ) {
			return new \WP_Error(
				'invalid_name',
				__( 'Name is required and must be at least 3 characters.', 'discount-rules-woo' ),
				array( 'field' => 'name', 'status' => 400 )
			);
		}

		// --- type -----------------------------------------------------------
		$type = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : '';
		if ( ! PromoTypeRegistry::exists( $type ) ) {
			return new \WP_Error(
				'invalid_type',
				/* translators: %s: comma-separated list of valid types */
				sprintf( __( 'Invalid type. Allowed: %s', 'discount-rules-woo' ), implode( ', ', PromoTypeRegistry::ids() ) ),
				array( 'field' => 'type', 'status' => 400 )
			);
		}

		// --- code -------------------------------------------------------------
		$code = isset( $data['code'] ) ? strtoupper( sanitize_text_field( $data['code'] ) ) : '';
		if ( '' !== $code && ! preg_match( '/^[A-Z0-9_]+$/', $code ) ) {
			return new \WP_Error(
				'invalid_code',
				__( 'Code must be uppercase alphanumeric with underscores only.', 'discount-rules-woo' ),
				array( 'field' => 'code', 'status' => 400 )
			);
		}

		if ( PromoTypeRegistry::needs_code( $type ) && '' === $code ) {
			return new \WP_Error(
				'code_required',
				__( 'This promo type requires a redeemable code.', 'discount-rules-woo' ),
				array( 'field' => 'code', 'status' => 400 )
			);
		}

		if ( '' !== $code ) {
			$duplicate = $this->find_duplicate_code( $code, $exclude_id );
			if ( $duplicate ) {
				return new \WP_Error(
					'duplicate_code',
					$duplicate,
					array( 'field' => 'code', 'status' => 400 )
				);
			}
		}

		// --- value ----------------------------------------------------------
		$value = isset( $data['value'] ) ? floatval( $data['value'] ) : 0;
		if ( $value < 0 ) {
			return new \WP_Error(
				'invalid_value',
				__( 'Value must be zero or positive.', 'discount-rules-woo' ),
				array( 'field' => 'value', 'status' => 400 )
			);
		}
		if ( 'percent' === $type && $value > 100 ) {
			return new \WP_Error(
				'invalid_percent',
				__( 'Percentage value cannot exceed 100.', 'discount-rules-woo' ),
				array( 'field' => 'value', 'status' => 400 )
			);
		}
		if ( 'cashback' === $type && $value > 100 ) {
			return new \WP_Error(
				'invalid_cashback',
				__( 'Cashback percentage cannot exceed 100.', 'discount-rules-woo' ),
				array( 'field' => 'value', 'status' => 400 )
			);
		}

		// --- dates ----------------------------------------------------------
		$start = isset( $data['start'] ) ? sanitize_text_field( $data['start'] ) : '';
		if ( '' !== $start && ! $this->is_valid_date( $start ) ) {
			return new \WP_Error(
				'invalid_start_date',
				__( 'Start date must be in Y-m-d format.', 'discount-rules-woo' ),
				array( 'field' => 'start', 'status' => 400 )
			);
		}

		$end = isset( $data['end'] ) ? sanitize_text_field( $data['end'] ) : '';
		if ( '' !== $end && ! $this->is_valid_date( $end ) ) {
			return new \WP_Error(
				'invalid_end_date',
				__( 'End date must be in Y-m-d format.', 'discount-rules-woo' ),
				array( 'field' => 'end', 'status' => 400 )
			);
		}

		if ( '' !== $start && '' !== $end && $end < $start ) {
			return new \WP_Error(
				'invalid_date_range',
				__( 'End date must be on or after the start date.', 'discount-rules-woo' ),
				array( 'field' => 'end', 'status' => 400 )
			);
		}

		// --- numeric limits -------------------------------------------------
		$min_amount   = isset( $data['minAmount'] ) ? floatval( $data['minAmount'] ) : 0;
		$limit_global = isset( $data['limitGlobal'] ) ? absint( $data['limitGlobal'] ) : 0;
		$limit_user   = isset( $data['limitUser'] ) ? absint( $data['limitUser'] ) : 0;
		$priority     = isset( $data['priority'] ) ? absint( $data['priority'] ) : 1;
		$priority     = max( 1, min( 10, $priority ) );

		// --- booleans -------------------------------------------------------
		$active = isset( $data['active'] ) ? (bool) $data['active'] : true;
		$home   = isset( $data['home'] ) ? (bool) $data['home'] : false;

		// --- text fields ----------------------------------------------------
		$scope        = isset( $data['scope'] ) ? sanitize_text_field( $data['scope'] ) : '';
		$cart_message = isset( $data['cartMessage'] ) ? sanitize_text_field( $data['cartMessage'] ) : '';
		$gift_text    = isset( $data['giftText'] ) ? sanitize_text_field( $data['giftText'] ) : '';

		return array(
			'name'        => $name,
			'code'        => $code,
			'type'        => $type,
			'value'       => $value,
			'scope'       => $scope,
			'minAmount'   => $min_amount,
			'limitGlobal' => $limit_global,
			'limitUser'   => $limit_user,
			'uses'        => 0,
			'start'       => $start,
			'end'         => $end,
			'active'      => $active,
			'home'        => $home,
			'priority'    => $priority,
			'cartMessage' => $cart_message,
			'giftText'    => $gift_text,
		);
	}

	/**
	 * Check whether a string is a valid Y-m-d date.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function is_valid_date( $date ) {
		$d = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Check a code against other promos and against native WooCommerce coupons.
	 *
	 * @param string   $code       Uppercase, already-sanitised code.
	 * @param int|null $exclude_id Promo id to exclude (the promo being updated).
	 * @return string|false Error message if the code collides, false if it's free.
	 */
	private function find_duplicate_code( $code, $exclude_id ) {
		foreach ( $this->load_promos() as $promo ) {
			if ( null !== $exclude_id && isset( $promo['id'] ) && (int) $promo['id'] === (int) $exclude_id ) {
				continue;
			}
			if ( isset( $promo['code'] ) && $promo['code'] === $code ) {
				return __( 'This code is already used by another promo.', 'discount-rules-woo' );
			}
		}

		if ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) ) {
			return __( 'This code is already used by an existing WooCommerce coupon.', 'discount-rules-woo' );
		}

		return false;
	}

	/**
	 * Build a structured 400 (or error-specified) response from a validation WP_Error.
	 *
	 * @param \WP_Error $error Validation error.
	 * @return \WP_REST_Response
	 */
	private function validation_error_response( \WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 400;

		return new \WP_REST_Response(
			array(
				'message' => $error->get_error_message(),
				'code'    => $error->get_error_code(),
				'field'   => isset( $data['field'] ) ? $data['field'] : null,
			),
			$status
		);
	}

}
