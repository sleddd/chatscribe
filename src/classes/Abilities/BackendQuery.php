<?php namespace ChatScribe\Abilities;

/**
 * chatscribe/backend-query: content search for signed-in users in the admin. The
 * backend chat uses only this ability, so it only reaches what Settings > ChatScribe >
 * Backend AI requests allows, and never more than the signed-in user could see themselves.
 *
 * Compared with the frontend query:
 * - Every match is checked against the user's own permissions, since it can include
 *   drafts, private and password-protected content and admin-only post types.
 * - Matches come with an edit link, and post meta when ticked.
 * - Other data, when ticked: users, options, comments, WooCommerce orders and customers.
 *
 * The search itself is shared, in Query.
 */
class BackendQuery extends Query {

	const CONTEXT = 'backend';

	protected function label(): string {
		return __( 'Content Search (Admin)', 'chatscribe' );
	}

	protected function describe_source( string $source ): string {
		$descriptions = array(
			'users'     => ', "users" for user accounts (search by name, username or email)',
			'options'   => ', "options" for site settings (search by option name or value)',
			'comments'  => ', "comments" for comments (search by text or author)',
			'orders'    => ', "orders" for WooCommerce orders (search by order number, customer name or email, or product)',
			'customers' => ', "customers" for WooCommerce customers, registered and guest (search by name, email, phone, username, city or postcode)',
		);
		return $descriptions[ $source ] ?? '';
	}

	protected function source_denied(): \WP_Error {
		return new \WP_Error( 'source_not_permitted', 'You do not have permission to see that data.' );
	}

	/**
	 * Every match is checked, including password-protected content.
	 */
	protected function needs_post_checks( array $post_types, array $statuses ): bool {
		return true;
	}

	/**
	 * An edit link, and post meta when ticked: only for posts this user can edit,
	 * with secrets hidden.
	 */
	protected function item_extras( \WP_Post $post ): array {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return array();
		}
		$extras = array();
		$edit   = get_edit_post_link( $post, 'raw' );
		if ( $edit ) {
			$extras['edit_link'] = $edit;
		}
		if ( DataSources::is_enabled( 'post_meta' ) ) {
			$extras['meta'] = DataSources::post_meta( $post->ID );
		}
		return $extras;
	}
}
