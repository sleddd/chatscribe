<?php namespace ChatScribe\Abilities;

use ChatScribe\Admin\SettingsPage;

/**
 * chatscribe/generation-query: the only tool the AI Writer (chatscribe/generate-post)
 * gets, to research a subject before writing. It is read-only and narrower than
 * the backend query, because what it finds may end up in published content:
 * - Published content only: never drafts, private, scheduled or password-protected posts.
 * - Only post types visitors can view, out of those ticked under Backend AI requests.
 * - Content only: never users, options, comments, orders, customers or post meta.
 * - Every match is still checked against what the signed-in user could see themselves.
 * - Products come with their public details only (no stock quantities).
 * It is not offered through the REST API or MCP, only to the AI Writer.
 */
class GenerationQuery extends Query {

	const NAME = 'chatscribe/generation-query';

	// Product details as a visitor would see them.
	const CONTEXT = 'frontend';

	protected function label(): string {
		return __( 'Content Research (AI Writer)', 'chatscribe' );
	}

	protected function ability_name(): string {
		return self::NAME;
	}

	/**
	 * The post types ticked under Backend AI requests that visitors can view.
	 */
	public function content(): array {
		return array_filter(
			SettingsPage::allowed_content( 'backend_access', 'backend' ),
			'is_post_type_viewable',
			ARRAY_FILTER_USE_KEY
		);
	}

	protected function statuses(): array {
		return array( 'publish' );
	}

	protected function sources(): array {
		return array();
	}

	protected function describe_source( string $source ): string {
		return '';
	}

	protected function source_denied(): \WP_Error {
		return new \WP_Error( 'source_not_allowed', 'Only content can be searched.' );
	}

	protected function user_can_use(): bool {
		return GeneratePost::user_can_use();
	}

	protected function query_args(): array {
		return array( 'has_password' => false );
	}

	/**
	 * Every match is checked against the user's own permissions.
	 */
	protected function needs_post_checks( array $post_types, array $statuses ): bool {
		return true;
	}

	protected function ability_meta(): array {
		return array(
			'show_in_rest' => false,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}
}
