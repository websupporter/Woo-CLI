<?php
	class WooCLI extends WP_CLI_Command{

		/**
		 * Update orders from your WooCommerce Shop
		 * 
		 * ## OPTIONS
		 *
		 * <order_id>
		 * : the ID of the order to update
		 * <status>
		 * : the new status of the order
		 *
		 * ## EXAMPLES
		 *
		 *	wp woo customer list
		 *
		 * @synopsis <order_id> <status>
		 */
		function update_order( $args, $assoc_args ){
			list( $post_id, $status ) = $args;
			//Standardise status name
			$status = 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status;

			$WCstatuses = wc_get_order_statuses();
			$legal_status = array();
			foreach( $WCstatuses as $status_key => $label)
				$legal_status[] = preg_replace( '^wc\-^', '', $status_key );

			if( ! in_array( $status, $legal_status ) ){
				echo 'Possible status codes:' . PHP_EOL;
				foreach( $legal_status as $status )
					echo '- ' . $status . PHP_EOL;
				WP_CLI::error( 'No legal status submitted' );
				return false;
			}

			$post = get_post( $post_id );
			if( ! $post || $post->post_type != 'shop_order' ){
				WP_CLI::error( 'Order not found' );
				return false;
			}

			if( $post->post_status == 'wc-' . $status ){
				WP_CLI::success( 'Order status was already ' . $status );
				return;
			}

			$order = wc_get_order( $post_id );
			$return = $order->update_status( 'wc-' . $status, '', true );
			WP_CLI::success( 'Status of order #' . $post_id . ' is now ' . $status );
			return;
		}

		/**
		 * Retrieve orders from your WooCommerce Shop
		 * 
		 * ## OPTIONS
		 *
		 * <order>
		 * : Get the orders
		 *
		 * ## EXAMPLES
		 *
		 *	wp woo order list --format=json start=2015-05-05 13:00:00 end=2015-05-06 13:00:00
		 *
		 * @synopsis <order> [--type=<order-type>] [--format=<format>] [--start=<start>] [--end=<end>]
		 */
		function order( $args, $assoc_args ){
			list( $order ) = $args;
			$address = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state', 'email', 'phone' );
			
			if( isset( $assoc_args['type'] ) )
				$type = $assoc_args['type'];

			if( isset( $assoc_args['start'] ) )
				$start = strtotime( $assoc_args['start'] );

			if( isset( $assoc_args['end'] ) )
				$end = strtotime( $assoc_args['end'] );
			
			if( isset( $assoc_args['format'] ) )
				$format = $assoc_args['format'];

			if( is_numeric( $order ) ){
				//Output a single order
				$ord = (int) $order;


				$post = get_post( $ord );
				if( ! $post || $post->post_type != 'shop_order' ){
					WP_CLI::error( 'Order not found' );
					return false;
				}
				$WCorder = wc_get_order( $ord );

				$order = new StdClass();
				$order->order_id = $ord;

				$order->date = $WCorder->post->post_date;

				//Get the status of the order;
				$statuses = wc_get_order_statuses();
				$order->status = new StdClass();
				$order->status->message = $statuses[ 'wc-' . $WCorder->get_status() ];
				$order->status->code = $WCorder->get_status();

				//Get the customer data
				$order->customer = new StdClass();
				$order->customer->id = $WCorder->customer_user;
				$order->customer->ip = get_post_meta( $ord, '_customer_ip_address', true );

				//Get billing data
				$order->billing_address = new StdClass();
				foreach( $address as $address_line )
					$order->billing_address->{ $address_line } = $WCorder->{ 'billing_' . $address_line };

				//Get shipping data
				$order->shipping_address = new StdClass();
				foreach( $address as $address_line )
					$order->shipping_address->{ $address_line } = $WCorder->{ 'shipping_' . $address_line };

				//Get Payment info
				$payment_gateways = WC()->payment_gateways->payment_gateways();				
				$payment_method = $WCorder->payment_method;

				$order->payment = new StdClass();
				if( isset( $payment_gateways[ $payment_method ] ) ){
					$order->payment->title = $payment_gateways[ $payment_method ]->title;
					$order->payment->id = $payment_gateways[ $payment_method ]->id;
				} else {
					$order->payment->title = 'N/A';
					$order->payment->id = 'N/A';
				}

				//Get items
				$items = $WCorder->get_items( apply_filters( 'woocommerce_admin_order_item_types', 'line_item' ) );
				$order->items = [];
				foreach( $items as $item ){
					unset( $item['item_meta'] );
					unset( $item['item_meta_array'] );
					unset( $item['line_tax_data'] );
					$order->items[] = $item;
				}

				//Get feeds
				$fees = $WCorder->get_items( 'fees' );
				$order->fees = [];
				foreach( $fees as $item ){
					unset( $item['item_meta'] );
					unset( $item['item_meta_array'] );
					unset( $item['line_tax_data'] );
					$order->fees[] = $item;
				}

				//Get shipping
				$shipping = $WCorder->get_items( 'shipping' );
				$order->shipping = [];
				foreach( $shipping as $item ){
					unset( $item['item_meta'] );
					unset( $item['item_meta_array'] );
					unset( $item['line_tax_data'] );
					$order->shipping[] = $item;
				}

				//Get refunds
				$refunds = $WCorder->get_refunds();
				$order->refunds = $refunds;

				//Get coupons
				$coupons = $WCorder->get_items( array( 'coupon' ) );
				foreach( $coupons as $key => $val ){
					unset( $coupons[ $key ]['item_meta'] );
					unset( $coupons[ $key ]['item_meta_array'] );
				}
				$order->coupons = $coupons;

				//Prices
				$order->totals = new StdClass();
				$order->totals->refunded = $WCorder->get_total_refunded();
				$order->totals->discount = $WCorder->get_total_discount();
				$order->totals->shipping = $WCorder->get_total_shipping();

				$order->totals->taxes = array();
				$taxes = $WCorder->get_tax_totals();
				foreach( $taxes as $tax ){
					unset( $tax->formatted_amount );
					$order->totals->taxes[] = $tax;
				}

				$order->totals->order_total = $WCorder->get_total();

				if( isset( $format ) && 'json' == $format )
					echo json_encode( $order );
				else
					echo json_encode( $order );


			} elseif( 'list' == $order ){
				//Output a list of orders
				$args = array(
					'post_type' => 'shop_order',
					'posts_per_page' => -1,	
					'post_status' => 'any'				
				);
				if( isset( $start ) ){
					$args['date_query'] = array();
					$date = array(
						'year' => date( 'Y', $start ),
						'month' => date( 'm', $start ),
						'day' => date( 'd', $start ),
						'hour' => date( 'H', $start ),
						'minute' => date( 'm', $start ),
						'second' => date( 's', $start ),
						'compare' => '>=',
					);
					$args['date_query'][] = $date;
				}

				if( isset( $start ) ){
					if( ! isset( $args['date_query'] ) )
						$args['date_query'] = array();
					$date = array(
						'year' => date( 'Y', $end ),
						'month' => date( 'm', $end ),
						'day' => date( 'd', $end ),
						'hour' => date( 'H', $end ),
						'minute' => date( 'm', $end ),
						'second' => date( 's', $end ),
						'compare' => '<=',
					);
					$args['date_query'][] = $date;
				}

				if( isset( $type ) ){
					//Standardize type
					$type = 'wc-' === substr( $type, 0, 3 ) ? substr( $type, 3 ) : $type;				
					$args['post_status'] = 'wc-' . $type;
				}

				$query = new WP_Query( $args );

				$data = array();
				while( $query->have_posts() ){
					$query->the_post();

					$WCorder = wc_get_order( get_the_ID() );

					$order = new stdClass();
					$order->ID = get_the_ID();
					$order->date = get_the_date( 'Y-m-d H:i:s', get_the_ID() );
					$order->customer_id = $WCorder->customer_user;
					$order->status = preg_replace( '^wc\-^', '', get_post_status() );
					$order->total = $WCorder->get_total();


					//Get Payment info
					$payment_gateways = WC()->payment_gateways->payment_gateways();				
					$payment_method = $WCorder->payment_method;

					if( isset( $payment_gateways[ $payment_method ] ) ){
						$order->payment_title = $payment_gateways[ $payment_method ]->title;
						$order->payment_id = $payment_gateways[ $payment_method ]->id;
					} else {
						$order->payment_title = 'N/A';
						$order->payment_id = 'N/A';
					}

					//Get shipping
					$shipping = $WCorder->get_items( 'shipping' );
					foreach( $shipping as $item ){
						unset( $item['item_meta'] );
						unset( $item['item_meta_array'] );
						unset( $item['line_tax_data'] );
						$order->shipping_name = $item['name'];
						$order->shipping_id = $item['method_id'];
					}

					$data[] = $order;
				}
				
				if( isset( $format ) && $format == 'json' )
					echo json_encode( $data );
				elseif( ! isset( $format ) || $format == 'table' && isset( $data[0] ) ){
					$header = array();
					foreach( $data as $key => $val ){
						$data[ $key ] = (array) $val;
						unset( $data[ $key ]['shipping_name'] );
						unset( $data[ $key ]['payment_title'] );
						unset( $data[ $key ]['date'] );
					}

					foreach( $data[0] as $key => $val )
						$header[] = $key;

					$table = new \cli\Table();
					$table->setHeaders( $header );
					$table->setRows( $data );
					$table->display();
					
				}

			}
		}
	}

	WP_CLI::add_command( 'woo', 'WooCLI' );
?>