<?php

namespace Drw\App\Controllers;

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
	 * Allowed promo types.
	 *
	 * @var string[]
	 */
	const TYPES = array(
		'percent',
		'fixed',
		'launch',
		'2x1',
		'3x2',
		'second_unit',
		'tiered',
		'bundle',
		'free_ship_threshold',
		'free_ship',
		'welcome',
		'gift',
		'cashback',
		'flash',
		'data_capture',
	);

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
			return new \WP_REST_Response(
				array( 'message' => $validated->get_error_message() ),
				400
			);
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
		$validated = $this->validate_promo( $data, true );
		if ( is_wp_error( $validated ) ) {
			return new \WP_REST_Response(
				array( 'message' => $validated->get_error_message() ),
				400
			);
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
	 * Returns the catalogue of the 15 supported promo types.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_types() {
		return new \WP_REST_Response( $this->type_definitions(), 200 );
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
	 * @param array $data      Raw input data.
	 * @param bool  $is_update Whether this is an update (relaxed required checks).
	 * @return array|\WP_Error Sanitised promo array or error.
	 */
	private function validate_promo( $data, $is_update = false ) {

		// --- name -----------------------------------------------------------
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( strlen( $name ) < 3 ) {
			return new \WP_Error(
				'invalid_name',
				__( 'Name is required and must be at least 3 characters.', 'discount-rules-woo' )
			);
		}

		// --- type -----------------------------------------------------------
		$type = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error(
				'invalid_type',
				/* translators: %s: comma-separated list of valid types */
				sprintf( __( 'Invalid type. Allowed: %s', 'discount-rules-woo' ), implode( ', ', self::TYPES ) )
			);
		}

		// --- code -----------------------------------------------------------
		$code = isset( $data['code'] ) ? strtoupper( sanitize_text_field( $data['code'] ) ) : '';
		if ( '' !== $code && ! preg_match( '/^[A-Z0-9_]+$/', $code ) ) {
			return new \WP_Error(
				'invalid_code',
				__( 'Code must be uppercase alphanumeric with underscores only.', 'discount-rules-woo' )
			);
		}

		// --- value ----------------------------------------------------------
		$value = isset( $data['value'] ) ? floatval( $data['value'] ) : 0;
		if ( $value < 0 ) {
			return new \WP_Error(
				'invalid_value',
				__( 'Value must be zero or positive.', 'discount-rules-woo' )
			);
		}
		if ( 'percent' === $type && $value > 100 ) {
			return new \WP_Error(
				'invalid_percent',
				__( 'Percentage value cannot exceed 100.', 'discount-rules-woo' )
			);
		}
		if ( 'cashback' === $type && $value > 100 ) {
			return new \WP_Error(
				'invalid_cashback',
				__( 'Cashback percentage cannot exceed 100.', 'discount-rules-woo' )
			);
		}

		// --- dates ----------------------------------------------------------
		$start = isset( $data['start'] ) ? sanitize_text_field( $data['start'] ) : '';
		if ( '' !== $start && ! $this->is_valid_date( $start ) ) {
			return new \WP_Error(
				'invalid_start_date',
				__( 'Start date must be in Y-m-d format.', 'discount-rules-woo' )
			);
		}

		$end = isset( $data['end'] ) ? sanitize_text_field( $data['end'] ) : '';
		if ( '' !== $end && ! $this->is_valid_date( $end ) ) {
			return new \WP_Error(
				'invalid_end_date',
				__( 'End date must be in Y-m-d format.', 'discount-rules-woo' )
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

	// ------------------------------------------------------------------
	// Type definitions catalogue
	// ------------------------------------------------------------------

	/**
	 * Return the 15 promo type definitions.
	 *
	 * @return array
	 */
	private function type_definitions() {
		return array(
			array(
				'id'        => 'percent',
				'label'     => __( 'Percentage discount', 'discount-rules-woo' ),
				'icon'      => 'percent',
				'color'     => '#4F46E5',
				'needsCode' => true,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'fixed',
				'label'     => __( 'Fixed amount discount', 'discount-rules-woo' ),
				'icon'      => 'dollar-sign',
				'color'     => '#059669',
				'needsCode' => true,
				'valueType' => 'currency',
			),
			array(
				'id'        => 'launch',
				'label'     => __( 'Launch offer', 'discount-rules-woo' ),
				'icon'      => 'rocket',
				'color'     => '#DC2626',
				'needsCode' => false,
				'valueType' => 'percent',
			),
			array(
				'id'        => '2x1',
				'label'     => __( 'Buy 2 get 1 free', 'discount-rules-woo' ),
				'icon'      => 'gift',
				'color'     => '#7C3AED',
				'needsCode' => false,
				'valueType' => 'none',
			),
			array(
				'id'        => '3x2',
				'label'     => __( 'Buy 3 pay 2', 'discount-rules-woo' ),
				'icon'      => 'shopping-bag',
				'color'     => '#DB2777',
				'needsCode' => false,
				'valueType' => 'none',
			),
			array(
				'id'        => 'second_unit',
				'label'     => __( 'Second unit discount', 'discount-rules-woo' ),
				'icon'      => 'layers',
				'color'     => '#2563EB',
				'needsCode' => false,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'tiered',
				'label'     => __( 'Tiered pricing', 'discount-rules-woo' ),
				'icon'      => 'trending-down',
				'color'     => '#0891B2',
				'needsCode' => false,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'bundle',
				'label'     => __( 'Bundle deal', 'discount-rules-woo' ),
				'icon'      => 'package',
				'color'     => '#CA8A04',
				'needsCode' => false,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'free_ship_threshold',
				'label'     => __( 'Free shipping over threshold', 'discount-rules-woo' ),
				'icon'      => 'truck',
				'color'     => '#16A34A',
				'needsCode' => false,
				'valueType' => 'currency',
			),
			array(
				'id'        => 'free_ship',
				'label'     => __( 'Free shipping coupon', 'discount-rules-woo' ),
				'icon'      => 'truck',
				'color'     => '#65A30D',
				'needsCode' => true,
				'valueType' => 'none',
			),
			array(
				'id'        => 'welcome',
				'label'     => __( 'Welcome discount', 'discount-rules-woo' ),
				'icon'      => 'user-plus',
				'color'     => '#8B5CF6',
				'needsCode' => true,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'gift',
				'label'     => __( 'Free gift with purchase', 'discount-rules-woo' ),
				'icon'      => 'gift',
				'color'     => '#F43F5E',
				'needsCode' => false,
				'valueType' => 'none',
			),
			array(
				'id'        => 'cashback',
				'label'     => __( 'Cashback', 'discount-rules-woo' ),
				'icon'      => 'refresh-cw',
				'color'     => '#0D9488',
				'needsCode' => false,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'flash',
				'label'     => __( 'Flash sale', 'discount-rules-woo' ),
				'icon'      => 'zap',
				'color'     => '#EA580C',
				'needsCode' => false,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'data_capture',
				'label'     => __( 'Data capture offer', 'discount-rules-woo' ),
				'icon'      => 'mail',
				'color'     => '#6366F1',
				'needsCode' => true,
				'valueType' => 'percent',
			),
		);
	}
}
