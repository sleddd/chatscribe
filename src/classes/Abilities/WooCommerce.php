<?php namespace ChatScribe\Abilities;

/**
 * WooCommerce support for the query abilities: product details for product results,
 * the catalog visibility rules for the frontend, and order lookups.
 *
 * Everything here does nothing unless WooCommerce is active.
 */
class WooCommerce {

	// The most orders checked against a search at once.
	const MAX_ORDERS = 1000;

	// Order post types: orders are searched with source "orders", never as content.
	const ORDER_POST_TYPES = array( 'shop_order', 'shop_order_refund', 'shop_order_placehold' );

	public static function active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/*
	------------------------------------------------------------------ *
	 * Products
	 * ------------------------------------------------------------------ */

	/**
	 * The product_visibility terms that keep a product off the shop for visitors:
	 * hidden from the catalog (or from search results, when searching), and out of
	 * stock when the store hides out-of-stock products.
	 */
	public static function hidden_visibility_terms( bool $searching ): array {
		$terms = array( $searching ? 'exclude-from-search' : 'exclude-from-catalog' );
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$terms[] = 'outofstock';
		}
		return $terms;
	}

	/**
	 * What a shopper (or, on the backend, a store manager) needs to know about a product.
	 * Hidden attributes and stock quantities are only included on the backend.
	 */
	public static function product_details( int $product_id, string $context ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}
		$backend = 'backend' === $context;
		$details = array();

		$price = self::product_price( $product );
		if ( '' !== $price ) {
			$details['price'] = $price;
		}
		if ( $product->is_on_sale() && ! $product->is_type( 'variable' ) && '' !== (string) $product->get_regular_price() ) {
			$details['regular_price'] = self::plain_price( $product->get_regular_price() );
		}
		if ( $product->get_sku() ) {
			$details['sku'] = $product->get_sku();
		}

		$stock_labels     = wc_get_product_stock_status_options();
		$details['stock'] = $stock_labels[ $product->get_stock_status() ] ?? $product->get_stock_status();
		if ( $backend && $product->managing_stock() ) {
			$details['stock_quantity'] = (int) $product->get_stock_quantity();
		}

		foreach ( array(
			'product_cat'   => 'categories',
			'product_tag'   => 'tags',
			'product_brand' => 'brands',
		) as $taxonomy => $key ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$names = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $names ) && $names ) {
					$details[ $key ] = array_map(
						function ( $name ) {
							return wp_specialchars_decode( $name, ENT_QUOTES );
						},
						$names
					);
				}
			}
		}

		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			// Shoppers only see the attributes shown on the product page.
			if ( ! $backend && ! $attribute->get_visible() ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product_id, $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			if ( $values ) {
				$attributes[ wc_attribute_label( $attribute->get_name(), $product ) ] = implode( ', ', array_map( 'strval', $values ) );
			}
		}
		if ( $attributes ) {
			$details['attributes'] = $attributes;
		}

		if ( $product->get_review_count() ) {
			$details['rating'] = round( (float) $product->get_average_rating(), 1 ) . ' out of 5 (' . (int) $product->get_review_count() . ' reviews)';
		}

		return $details;
	}

	private static function product_price( \WC_Product $product ): string {
		if ( $product->is_type( 'variable' ) ) {
			$min = $product->get_variation_price( 'min' );
			$max = $product->get_variation_price( 'max' );
			if ( '' === (string) $min ) {
				return '';
			}
			return $min === $max ? self::plain_price( $min ) : self::plain_price( $min ) . ' – ' . self::plain_price( $max );
		}
		return '' === (string) $product->get_price() ? '' : self::plain_price( $product->get_price() );
	}

	/**
	 * A price as plain text in the store's format, such as "$12.50".
	 */
	private static function plain_price( $amount, string $currency = '' ): string {
		$html = wc_price( (float) $amount, $currency ? array( 'currency' => $currency ) : array() );
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/*
	------------------------------------------------------------------ *
	 * Orders
	 * ------------------------------------------------------------------ */

	/**
	 * Order statuses the AI can filter by, without the "wc-" prefix: status => label.
	 */
	public static function order_statuses(): array {
		$statuses = array();
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$statuses[ preg_replace( '/^wc-/', '', $key ) ] = $label;
		}
		return $statuses;
	}

	/**
	 * Searches orders. On the backend: all orders (the caller checks the user can manage
	 * orders). On the frontend: only the signed-in customer's own orders.
	 */
	public static function orders( array $input, string $context ) {
		$frontend = 'frontend' === $context;
		$customer = get_current_user_id();
		if ( $frontend && ! $customer ) {
			return new \WP_Error( 'sign_in_required', 'The customer must sign in to look up their orders.' );
		}

		$statuses = array_keys( wc_get_order_statuses() );
		$status   = (string) ( $input['order_status'] ?? '' );
		if ( '' !== $status ) {
			if ( ! in_array( 'wc-' . $status, $statuses, true ) ) {
				return new \WP_Error( 'unknown_order_status', 'That order status does not exist.' );
			}
			$statuses = array( 'wc-' . $status );
		}

		$args = array(
			'type'    => 'shop_order',
			'status'  => $statuses,
			'orderby' => 'date',
			'order'   => 'DESC',
			'limit'   => self::MAX_ORDERS,
			'return'  => 'ids',
		);
		if ( $frontend ) {
			$args['customer_id'] = $customer;
		}
		$ids = array_map( 'intval', wc_get_orders( $args ) );

		// Search by order number, customer name or email, address or product name.
		$search = trim( (string) ( $input['search'] ?? '' ) );
		if ( '' !== $search ) {
			$matched = array_map( 'intval', (array) wc_order_search( $search ) );
			$number  = ltrim( $search, '#' );
			if ( ctype_digit( $number ) ) {
				$matched[] = (int) $number;
			}
			// Keeps the date order, and on the frontend only this customer's orders.
			$ids = array_values( array_intersect( $ids, $matched ) );
		}

		$result = array( 'total' => count( $ids ) );
		if ( ! empty( $input['count_only'] ) ) {
			return $result;
		}

		$limit  = min( 50, max( 1, (int) ( $input['limit'] ?? 5 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$result['items'] = array();
		foreach ( array_slice( $ids, $offset, $limit ) as $id ) {
			$order = wc_get_order( $id );
			// Belt and braces: a customer only ever gets their own orders.
			if ( ! $order || ( $frontend && (int) $order->get_customer_id() !== $customer ) ) {
				continue;
			}
			$result['items'][] = self::order_item( $order, $frontend );
		}
		return $result;
	}

	/*
	------------------------------------------------------------------ *
	 * Customers (backend only)
	 * ------------------------------------------------------------------ */

	/**
	 * Looks up customers: registered customers (the Customer role, or anyone who has
	 * placed an order) and guest checkouts (grouped by billing email). Most recently
	 * active first. The caller checks the user can manage WooCommerce.
	 *
	 * Built from the store's own users and orders rather than WooCommerce Analytics,
	 * whose tables are only filled once its import has run.
	 */
	public static function customers( array $input ) {
		$search     = trim( (string) ( $input['search'] ?? '' ) );
		$candidates = array(); // "user:ID" or "guest:email" => last active (timestamp)

		// Customers found through their orders, newest order first.
		$order_ids = wc_get_orders(
			array(
				'type'    => 'shop_order',
				'status'  => array_keys( wc_get_order_statuses() ),
				'orderby' => 'date',
				'order'   => 'DESC',
				'limit'   => self::MAX_ORDERS,
				'return'  => 'ids',
			)
		);
		if ( '' !== $search ) {
			$order_ids = array_values( array_intersect( array_map( 'intval', $order_ids ), self::search_order_ids( $search ) ) );
		}
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			$key   = $order ? self::customer_key( $order ) : '';
			if ( $key && ! isset( $candidates[ $key ] ) ) {
				$candidates[ $key ] = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
			}
		}

		// Registered customers whose account matches (or all of them, when listing).
		foreach ( self::matching_customer_users( $search ) as $user_id ) {
			$key = 'user:' . $user_id;
			if ( ! isset( $candidates[ $key ] ) ) {
				$user               = get_userdata( $user_id );
				$candidates[ $key ] = $user ? (int) strtotime( $user->user_registered ) : 0;
			}
		}

		arsort( $candidates );
		$result = array( 'total' => count( $candidates ) );
		if ( ! empty( $input['count_only'] ) ) {
			return $result;
		}

		$limit  = min( 50, max( 1, (int) ( $input['limit'] ?? 5 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$result['items'] = array();
		foreach ( array_slice( array_keys( $candidates ), $offset, $limit ) as $key ) {
			$profile = 0 === strpos( $key, 'user:' )
				? self::registered_customer( (int) substr( $key, 5 ) )
				: self::guest_customer( substr( $key, 6 ) );
			if ( $profile ) {
				$result['items'][] = $profile;
			}
		}
		return $result;
	}

	/**
	 * "user:ID" for a registered customer's order, "guest:email" for a guest checkout.
	 */
	private static function customer_key( \WC_Order $order ): string {
		if ( $order->get_customer_id() ) {
			return 'user:' . (int) $order->get_customer_id();
		}
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		return $email ? 'guest:' . $email : '';
	}

	/**
	 * Orders matching every word of the search (so "Jane Doe" finds Jane Doe even when
	 * first and last name are stored apart), or the order number itself.
	 */
	private static function search_order_ids( string $search ): array {
		$ids = null;
		foreach ( preg_split( '/\s+/', $search ) as $word ) {
			$found = array_map( 'intval', (array) wc_order_search( $word ) );
			$ids   = null === $ids ? $found : array_intersect( $ids, $found );
		}
		$number = ltrim( $search, '#' );
		if ( ctype_digit( $number ) ) {
			$ids[] = (int) $number;
		}
		return array_values( array_unique( (array) $ids ) );
	}

	/**
	 * Registered users matching every word of the search in their login, email, name,
	 * phone, city or postcode, who are customers: the Customer role, or they have ordered.
	 * With no search: every user with the Customer role.
	 */
	private static function matching_customer_users( string $search ): array {
		if ( '' === $search ) {
			return array_map(
				'intval',
				get_users(
					array(
						'role'   => 'customer',
						'fields' => 'ID',
						'number' => self::MAX_ORDERS,
					)
				)
			);
		}

		$ids = null;
		foreach ( preg_split( '/\s+/', $search ) as $word ) {
			$by_account = get_users(
				array(
					'search'         => '*' . $word . '*',
					'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
					'fields'         => 'ID',
					'number'         => self::MAX_ORDERS,
				)
			);
			$meta       = array( 'relation' => 'OR' );
			foreach ( array( 'first_name', 'last_name', 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone', 'billing_city', 'billing_postcode' ) as $key ) {
				$meta[] = array(
					'key'     => $key,
					'value'   => $word,
					'compare' => 'LIKE',
				);
			}
			$by_profile = get_users(
				array(
					'meta_query' => $meta,
					'fields'     => 'ID',
					'number'     => self::MAX_ORDERS,
				)
			);

			$found = array_map( 'intval', array_merge( $by_account, $by_profile ) );
			$ids   = null === $ids ? $found : array_intersect( $ids, $found );
		}

		return array_values(
			array_filter(
				array_unique( (array) $ids ),
				function ( $user_id ) {
					$user = get_userdata( $user_id );
					return $user && ( in_array( 'customer', (array) $user->roles, true ) || wc_get_orders(
						array(
							'customer_id' => $user_id,
							'status'      => array_keys( wc_get_order_statuses() ),
							'limit'       => 1,
							'return'      => 'ids',
						)
					) );
				}
			)
		);
	}

	private static function registered_customer( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}
		$customer = new \WC_Customer( $user_id );
		$name     = trim( $customer->get_first_name() . ' ' . $customer->get_last_name() )
			?: trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() )
			?: $user->display_name;

		$profile = array(
			'name'       => $name,
			'type'       => 'registered',
			'email'      => $customer->get_email(),
			'username'   => $user->user_login,
			'registered' => mysql2date( 'F j, Y', $user->user_registered ),
		);
		if ( $customer->get_billing_email() && strtolower( $customer->get_billing_email() ) !== strtolower( $customer->get_email() ) ) {
			$profile['billing_email'] = $customer->get_billing_email();
		}
		if ( $customer->get_billing_phone() ) {
			$profile['phone'] = $customer->get_billing_phone();
		}
		$address = self::plain_address( $customer->get_billing() );
		if ( $address ) {
			$profile['billing_address'] = $address;
		}

		$orders   = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array_keys( wc_get_order_statuses() ),
				'orderby'     => 'date',
				'order'       => 'DESC',
				'limit'       => self::MAX_ORDERS,
			)
		);
		$profile += self::order_summary( $orders );

		if ( current_user_can( 'edit_user', $user_id ) ) {
			$profile['edit_link'] = get_edit_user_link( $user_id );
		}
		$profile['orders_link'] = self::orders_admin_url( array( '_customer_user' => $user_id ) );
		return $profile;
	}

	private static function guest_customer( string $email ): array {
		$orders = array_values(
			array_filter(
				wc_get_orders(
					array(
						'billing_email' => $email,
						'status'        => array_keys( wc_get_order_statuses() ),
						'orderby'       => 'date',
						'order'         => 'DESC',
						'limit'         => self::MAX_ORDERS,
					)
				),
				function ( $order ) {
					return ! $order->get_customer_id();
				}
			)
		);
		if ( ! $orders ) {
			return array();
		}

		$latest  = $orders[0];
		$profile = array(
			'name'  => trim( $latest->get_formatted_billing_full_name() ) ?: $email,
			'type'  => 'guest',
			'email' => $latest->get_billing_email(),
		);
		if ( $latest->get_billing_phone() ) {
			$profile['phone'] = $latest->get_billing_phone();
		}
		$address = self::plain_address( $latest->get_address( 'billing' ) );
		if ( $address ) {
			$profile['billing_address'] = $address;
		}
		$profile               += self::order_summary( $orders );
		$profile['orders_link'] = self::orders_admin_url( array( 's' => $email ) );
		return $profile;
	}

	/**
	 * Order count, amount spent (paid orders, less refunds), last order date and the
	 * three most recent orders. $orders is newest first.
	 */
	private static function order_summary( array $orders ): array {
		$paid  = wc_get_is_paid_statuses();
		$spent = 0.0;
		foreach ( $orders as $order ) {
			if ( in_array( $order->get_status(), $paid, true ) ) {
				$spent += (float) $order->get_total() - (float) $order->get_total_refunded();
			}
		}

		$summary = array(
			'orders'      => count( $orders ),
			'total_spent' => self::plain_price( $spent, $orders ? $orders[0]->get_currency() : '' ),
		);
		if ( $orders && $orders[0]->get_date_created() ) {
			$summary['last_order'] = $orders[0]->get_date_created()->date_i18n( 'F j, Y' );
		}
		$summary['recent_orders'] = array_map(
			function ( $order ) {
				return '#' . $order->get_order_number() . ', ' . wc_get_order_status_name( $order->get_status() ) . ', '
				. self::plain_price( $order->get_total(), $order->get_currency() );
			},
			array_slice( $orders, 0, 3 )
		);
		return $summary;
	}

	/**
	 * An address on one line, such as "Jane Doe, 1 Main St, Springfield, IL 62701, United States".
	 */
	private static function plain_address( array $address ): string {
		$html = WC()->countries->get_formatted_address( $address );
		$text = preg_replace( '/\s*<br\s*\/?>\s*/i', ', ', (string) $html );
		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ), ', ' );
	}

	/**
	 * The admin orders screen with filters, for either order storage (HPOS or posts).
	 */
	private static function orders_admin_url( array $args ): string {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		return $hpos
			? add_query_arg( array( 'page' => 'wc-orders' ) + $args, admin_url( 'admin.php' ) )
			: add_query_arg( array( 'post_type' => 'shop_order' ) + $args, admin_url( 'edit.php' ) );
	}

	private static function order_item( \WC_Order $order, bool $frontend ): array {
		$items = array();
		foreach ( $order->get_items() as $line ) {
			$items[] = wp_specialchars_decode( $line->get_name(), ENT_QUOTES ) . ' × ' . (int) $line->get_quantity();
		}

		$created = $order->get_date_created();
		$item    = array(
			'number' => $order->get_order_number(),
			'date'   => $created ? $created->date_i18n( 'F j, Y' ) : '',
			'status' => wc_get_order_status_name( $order->get_status() ),
			'total'  => self::plain_price( $order->get_total(), $order->get_currency() ),
			'items'  => $items,
		);
		if ( $order->get_payment_method_title() ) {
			$item['payment_method'] = $order->get_payment_method_title();
		}
		if ( $order->get_shipping_method() ) {
			$item['shipping'] = $order->get_shipping_method();
		}

		if ( $frontend ) {
			$item['view_link'] = $order->get_view_order_url();
		} else {
			// The billing name, else the customer's account name; "Guest" only for guest checkouts.
			$name             = trim( $order->get_formatted_billing_full_name() );
			$user             = $order->get_customer_id() ? get_userdata( $order->get_customer_id() ) : false;
			$item['customer'] = $name ?: ( $user ? $user->display_name : __( 'Guest', 'chatscribe' ) );
			if ( $order->get_billing_email() ) {
				$item['email'] = $order->get_billing_email();
			}
			$item['edit_link'] = $order->get_edit_order_url();
		}
		return $item;
	}
}
