<?php namespace ChatScribe\API;

use ChatScribe\AI\Client;
use ChatScribe\Abilities\Query;
use ChatScribe\Admin\SettingsPage;

/**
 * What the frontend and backend chats share: the REST route, who may use it,
 * the conversation history and the call to the AI. Each chat sets its CONTEXT
 * ('frontend' or 'backend') and writes its own system instruction.
 *
 * A chat only ever gets the query ability for its own context.
 */
abstract class Chat {

	const CHAT_REST_NAMESPACE = 'chatscribe/v1';
	const CONTEXT             = '';
	const HISTORY_MAX_TURNS   = 12;   // earlier messages sent back to the model
	const HISTORY_MAX_CHARS   = 4000; // per earlier message

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	abstract protected function get_system_instruction(): string;

	public static function route(): string {
		return '/chat/' . static::CONTEXT;
	}

	public static function rest_url(): string {
		return rest_url( self::CHAT_REST_NAMESPACE . static::route() );
	}

	public function register_routes() {
		register_rest_route(
			self::CHAT_REST_NAMESPACE,
			static::route(),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat_request' ),
				'permission_callback' => array( static::class, 'can_chat' ),
			)
		);
	}

	/**
	 * The chat must be switched on, and the user's role (or "guest" on the frontend)
	 * ticked under Permissions for this context.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_chat() {
		if ( ! SettingsPage::get( static::CONTEXT . '_chat_enabled' ) ) {
			return new \WP_Error( 'chat_disabled', 'Chat is turned off.', array( 'status' => 403 ) );
		}
		if ( ! SettingsPage::user_can_use( static::CONTEXT ) ) {
			return new \WP_Error( 'chat_not_allowed', 'You are not allowed to use the chat.', array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	public function handle_chat_request( $request ) {
		$data    = $request->get_json_params();
		$message = isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '';

		// Built per request, once WordPress is fully loaded, so the site details are current.
		$ai_client = new Client(
			'',
			$this->get_system_instruction(),
			0.7,
			array( Query::name( static::CONTEXT ) )
		);

		$response = $ai_client->sendMessage( $message, $this->get_history( $data['history'] ?? array() ) );

		return rest_ensure_response(
			array(
				'status'  => 'success',
				'message' => $response,
			)
		);
	}

	/**
	 * Cleans the earlier turns the browser sent, so the model can follow the conversation.
	 * The server keeps nothing between requests: the browser holds the conversation.
	 */
	protected function get_history( $history ) {
		$turns = array();

		foreach ( is_array( $history ) ? array_slice( $history, -self::HISTORY_MAX_TURNS ) : array() as $turn ) {
			$role = is_array( $turn ) ? ( $turn['role'] ?? '' ) : '';
			$text = is_array( $turn ) && is_string( $turn['text'] ?? null ) ? sanitize_textarea_field( $turn['text'] ) : '';
			$text = mb_substr( $text, 0, self::HISTORY_MAX_CHARS );

			if ( ! in_array( $role, array( 'user', 'bot' ), true ) || '' === $text ) {
				continue;
			}
			// A conversation has to open with the user.
			if ( ! $turns && 'user' !== $role ) {
				continue;
			}
			$turns[] = array(
				'role' => $role,
				'text' => $text,
			);
		}

		return $turns;
	}

	/**
	 * The "About this site" part of a system instruction.
	 */
	protected function about_site_lines(): array {
		return array(
			'',
			'## About this site',
			'- Language: ' . get_bloginfo( 'language' ),
			'- Timezone: ' . wp_timezone_string(),
			'- Current date and time: ' . wp_date( 'l, F j, Y g:i a' ),
			'- Theme: ' . wp_get_theme()->get( 'Name' ),
		);
	}

	/**
	 * "a, b and c".
	 */
	protected function join_words( array $words ): string {
		$last = array_pop( $words );
		return $words ? implode( ', ', $words ) . ' and ' . $last : (string) $last;
	}
}
