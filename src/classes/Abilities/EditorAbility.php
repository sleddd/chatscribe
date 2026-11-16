<?php namespace ChatScribe\Abilities;

use ChatScribe\Admin\SettingsPage;

/**
 * What the editor abilities share: post summaries (chatscribe/summarize-post) and
 * post generation (chatscribe/generate-post).
 *
 * Each is switched on, and limited to post types, under Settings > ChatScribe
 * (summary_* and generation_* settings). Both are backend AI requests, so only
 * roles ticked under Backend AI requests > Permissions can use them, and only on
 * posts they could edit themselves.
 */
abstract class EditorAbility {

	// 'summary' or 'generation': the settings prefix and post type context.
	const SETTING = '';

	// The ability name, such as 'chatscribe/summarize-post'.
	const NAME = '';

	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	abstract public function register_ability();

	/**
	 * Whether this feature is switched on in the settings.
	 */
	public static function enabled(): bool {
		return (bool) SettingsPage::get( static::SETTING . '_enabled' );
	}

	/**
	 * The post types this feature is allowed on.
	 */
	public static function post_types(): array {
		return array_keys( SettingsPage::allowed_content( static::SETTING . '_access', static::SETTING ) );
	}

	/**
	 * Whether the current user may use this feature, on a post when $post_id is given.
	 *
	 * @param int $post_id The post being edited, or 0 for none.
	 */
	public static function user_can_use( int $post_id = 0 ): bool {
		if ( ! static::enabled() || ! SettingsPage::user_can_use( 'backend' ) ) {
			return false;
		}
		if ( ! $post_id ) {
			return current_user_can( 'edit_posts' );
		}
		return in_array( get_post_type( $post_id ), static::post_types(), true )
			&& current_user_can( 'edit_post', $post_id );
	}

	/**
	 * The permission callback: the feature is on, and the user may edit the post (if one is given).
	 *
	 * @param mixed $input The ability input.
	 */
	public function check_permission( $input = array() ): bool {
		$input = (array) $input;
		return static::user_can_use( (int) ( $input['post_id'] ?? 0 ) );
	}

	/**
	 * Whether the AI Client can generate text: WordPress 7.0+ with a provider connected.
	 *
	 * @return true|\WP_Error
	 */
	protected static function ai_available() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error( 'missing_ai_client', 'WordPress 7.0+ AI Client is required.' );
		}
		if ( ! wp_ai_client_prompt( 'test' )->is_supported_for_text_generation() ) {
			return new \WP_Error( 'invalid_api_key', 'Please add your API key in Settings > Connectors.' );
		}
		return true;
	}

	/**
	 * Plain text from post content: no blocks, HTML or shortcodes.
	 */
	protected static function plain_text( string $content ): string {
		// A line break after each heading, paragraph, list item and so on, so their words do not run together.
		$content = preg_replace( '#</(p|h[1-6]|li|blockquote|figcaption|td|th|div)>|<br\s*/?>#i', "$0\n", $content );
		$text    = wp_strip_all_tags( strip_shortcodes( $content ), false );
		$text    = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
		return trim( preg_replace( "/\n{3,}/", "\n\n", preg_replace( '/[ \t]+/', ' ', $text ) ) );
	}

	/**
	 * Removes a ``` fence the model may wrap its answer in.
	 */
	protected static function strip_fence( string $text ): string {
		return trim( preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', trim( $text ) ) );
	}
}
