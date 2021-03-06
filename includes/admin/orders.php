<?php

/**
 * POS Order Admin Class
 * - only active for $screen-id = edit-shop_order or shop_order
 * - allow users to change order type: POS/Online
 * - allow users to switch POS payment method
 * - filter orders by POS vs Online
 *
 * @package    WCPOS\Admin_Orders
 * @author   Paul Kilmurray <paul@kilbot.com.au>
 * @link     http://www.wcpos.com
 */

namespace WCPOS\Admin;

use WCPOS\Admin\Settings\Checkout;

class Orders {

	private $pos_order;

	public function __construct() {

		// option for order type
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_details' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save' ), 10, 2 );

		// payment method dropdown
		if ( version_compare( WC()->version, '2.3', '>' ) ) {
			add_filter( 'woocommerce_payment_gateways', array( $this, 'payment_gateways' ), 20, 1 );
		}

		// pos vs online filter on edit-shop_order page
		add_filter( 'views_edit-shop_order', array( $this, 'pos_order_filters' ), 10, 1 );
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ), 10, 1 );

		// add class to pos order rows
		add_filter( 'post_class', array( $this, 'post_class' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );

	}

	/**
	 * Add select dropdown
	 * @param $order
	 */
	public function order_details( $order ) {
		$this->pos_order = get_post_meta( $order->id, '_pos', true );

		if ( ! $this->pos_order && get_current_screen()->action == 'add' ) {
			$this->pos_order = apply_filters( 'woocommerce_pos_default_order_type_is_pos', false );
		}
		include 'views/order-details.php';
	}

	/**
	 * Save the order type
	 * @param $post_id
	 * @param $post
	 */
	public function save( $post_id, $post ) {
		if ( isset( $_POST['wcpos_order_type'] ) ) {
			update_post_meta( $post_id, '_pos', $_POST['wcpos_order_type'] );
		}
	}

	/**
	 * Show POS enabled gateways
	 * note: only available in WC > 2.3
	 * @param $gateways
	 * @return array
	 */
	public function payment_gateways( $gateways ) {
		// get checkout settings data
		$settings    = Checkout::get_instance();
		$enabled_ids = $settings->get_enabled_gateway_ids();

		$loaded_gateways = array();

		foreach ( $gateways as $Gateway ) {
			$gateway = new $Gateway();
			if ( in_array( $gateway->id, $enabled_ids ) ) {
				$gateway->enabled = 'yes';
			}
			$loaded_gateways[] = $gateway;
		}

		return $loaded_gateways;
	}

	/**
	 * Order admin filter links
	 * @param  array $views
	 * @return array
	 */
	public function pos_order_filters( $views ) {
		global $wpdb;

		$visibility_filters = array(
			'yes' => __( 'POS', 'woocommerce-pos' ),
			'no'  => __( 'Online', 'woocommerce-pos' )
		);

		if ( isset( $_GET['pos_order'] ) && ! empty( $_GET['pos_order'] ) ) {
			$views['all'] = str_replace( 'class="current"', '', $views['all'] );
		}

		foreach ( $visibility_filters as $key => $label ) {
			$sql = "SELECT count(DISTINCT pm.post_id)
			FROM $wpdb->postmeta pm
			JOIN $wpdb->posts p ON (p.ID = pm.post_id)
			WHERE ";
			$sql .= $key == 'no' ? " pm.post_id NOT IN ( SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pos' ) " : " pm.meta_key = '_pos' AND pm.meta_value = '1' ";
//      if( version_compare( WC()->version, '2.2.0' ) >= 0 ) {
			$sql .= "AND p.post_type = 'shop_order' AND p.post_status = 'publish'";
//      } else {
//        $sql .= "AND p.post_type = 'shop_order' AND p.post_status = 'publish'";
//      }

			$count        = $wpdb->get_var( $sql );
			$class        = ( isset( $_GET['pos_order'] ) && $_GET['pos_order'] == $key ) ? 'current' : '';
			$query_string = remove_query_arg( array( 'pos_order' ) );
			if ( $class == '' ) {
				$query_string = remove_query_arg( array( 'paged' ) );
			}
			$query_string  = add_query_arg( 'pos_order', urlencode( $key ), $query_string );
			$views[ $key ] = '<a href="' . $query_string . '" class="' . esc_attr( $class ) . '">' . $label . ' <span class="count">(' . number_format_i18n( $count ) . ')</a>';
		}

		return $views;
	}

	/**
	 * Order admin filter
	 * @param $query
	 */
	function pre_get_posts( $query ) {

		if ( isset( $_GET['pos_order'] ) && ! empty( $_GET['pos_order'] ) ) {
			if ( $_GET['pos_order'] == 'yes' ) {
				$meta_query = array(
					array(
						'key'     => '_pos',
						'value'   => '1',
						'compare' => '=='
					)
				);
			} else {
				$meta_query = array(
					'relation' => 'OR',
					array(
						'key'     => '_pos',
						'value'   => '0',
						'compare' => '=='
					),
					array(
						'key'     => '_pos',
						'compare' => 'NOT EXISTS'
					)
				);
			}

			$query->set( 'meta_query', $meta_query );
		}

	}

	/**
	 * Add type-wcpos_order class to pos order rows
	 *
	 * @param $classes
	 * @param $class
	 * @param $id
	 * @return array
	 */
	public function post_class( $classes, $class, $id ) {
		if ( get_post_meta( $id, '_pos', true ) ) {
			$classes[] = 'type-wcpos_order';
		}

		return $classes;
	}

	/**
	 * CSS
	 */
	public function enqueue_admin_styles() {
		$css = '
      .widefat .type-wcpos_order .column-order_status {
        background: url( ' . \WCPOS\PLUGIN_URL . 'assets/logo.svg ) no-repeat 75% 9px;
        background-size: 18px;
        fill: #94d31b;
      }
      .widefat .type-wcpos_order .column-order_status mark {
        margin: 0 auto 0 0;
      }
    ';
		wp_add_inline_style( 'wp-admin', $css );
	}

}
