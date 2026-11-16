<?php namespace ChatScribe\Abilities;

/**
 * chatscribe/frontend-query: content search for site visitors. The frontend chat uses
 * only this ability, so it only reaches what Settings > ChatScribe > Frontend AI
 * requests allows, and never more than the visitor could see on the site themselves.
 *
 * Compared with the backend query:
 * - Never returns password-protected content.
 * - Only returns products the shop itself would show (catalog visibility).
 * - The only other data is a signed-in customer's own WooCommerce orders.
 *
 * The search itself is shared, in Query.
 */
class FrontendQuery extends Query {

	const CONTEXT = 'frontend';

	protected function label(): string {
		return __( 'Content Search', 'chatscribe' );
	}

	protected function describe_source( string $source ): string {
		return 'orders' === $source
			? ', "orders" for the signed-in customer\'s own orders (search by order number or product)'
			: '';
	}

	protected function source_denied(): \WP_Error {
		// Orders are the only other data, and customers just need to sign in.
		return new \WP_Error( 'sign_in_required', 'You must sign in to look up your orders.' );
	}

	/**
	 * Visitors never get password-protected content.
	 */
	protected function query_args(): array {
		return array( 'has_password' => false );
	}

	/**
	 * Visitors only get products the shop itself would show them: not hidden from the
	 * catalog (or from search), and not out of stock when the shop hides those.
	 */
	protected function extra_tax_query( array $post_types, string $search ): array {
		if ( ! in_array( 'product', $post_types, true ) || ! WooCommerce::active() ) {
			return array();
		}
		return array(
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => WooCommerce::hidden_visibility_terms( '' !== $search ),
				'operator' => 'NOT IN',
			),
		);
	}
}
