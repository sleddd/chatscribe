<?php namespace ChatScribe\Abilities;

use ChatScribe\Admin\SettingsPage;

/**
 * chatscribe/summarize-post: sums up a post's text as key points, going by the
 * "Post summaries" settings (max bullet points, max words per bullet point).
 *
 * Used by the "Summarize" box in the editor, which sends the post's current text
 * (saved or not) and shows the key points to copy into a block.
 */
class Summarize extends EditorAbility {

	const SETTING = 'summary';
	const NAME    = 'chatscribe/summarize-post';

	// The most text sent to the AI, in characters.
	const MAX_CHARS = 60000;

	public function register_ability() {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Summarize Post', 'chatscribe' ),
				'description'         => 'Sums up a blog post\'s text as a short list of key points.',
				'category'            => 'site',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'text' ),
					'properties'           => array(
						'text'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 500000,
							'description' => 'The post\'s text. HTML and block markup are removed.',
						),
						'title'   => array(
							'type'        => 'string',
							'maxLength'   => 300,
							'description' => 'The post\'s title, if it has one.',
						),
						'post_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'The post being summarized. The user must be able to edit it.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'bullets' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input text, and optionally title and post_id.
	 * @return array|\WP_Error [ 'bullets' => string[] ]
	 */
	public function execute( $input = array() ) {
		$input = (array) $input;
		$text  = mb_substr( self::plain_text( (string) ( $input['text'] ?? '' ) ), 0, self::MAX_CHARS );
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );

		if ( mb_strlen( $text ) < 20 ) {
			return new \WP_Error( 'too_short', __( 'There is not enough text to summarize yet.', 'chatscribe' ) );
		}

		$ready = self::ai_available();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$max_bullets = (int) SettingsPage::get( 'summary_bullet_count' );
		$max_words   = (int) SettingsPage::get( 'summary_bullet_words' );

		$prompt = ( '' !== $title ? "Title: {$title}\n\n" : '' ) . $text;
		$reply  = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->system_instruction( $max_bullets, $max_words ) )
			->using_temperature( 0.3 )
			->generate_text();

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$bullets = $this->bullets( (string) $reply, $max_bullets, $max_words );
		if ( ! $bullets ) {
			return new \WP_Error( 'no_summary', __( 'The AI did not return a summary. Please try again.', 'chatscribe' ) );
		}

		return array( 'bullets' => $bullets );
	}

	private function system_instruction( int $max_bullets, int $max_words ): string {
		return implode(
			"\n",
			array(
				'You summarize blog posts as key points for readers of the website "' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '".',
				'',
				'Rules:',
				"- Give at most {$max_bullets} key points, fewer if the post has fewer ideas worth listing. Most important first.",
				"- Each key point is at most {$max_words} words: one clear, complete statement.",
				'- Only use what the post says. Never add facts, opinions or advice of your own.',
				'- Write in the same language as the post.',
				'- Plain text only: no bold, links, headings, numbering or emoji.',
				'',
				'Reply with the key points only, one per line, each starting with "- ". No introduction or closing line.',
			)
		);
	}

	/**
	 * The key points from the model's reply, trimmed to the settings.
	 */
	private function bullets( string $reply, int $max_bullets, int $max_words ): array {
		$bullets = array();
		foreach ( preg_split( '/\R/', self::strip_fence( $reply ) ) as $line ) {
			$line = trim( preg_replace( '/^\s*(?:[-*•–]|\d+[.)])\s*/u', '', $line ) );
			$line = trim( str_replace( array( '**', '__' ), '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			// Kept to the word limit even if the model ran over.
			$bullets[] = wp_trim_words( sanitize_text_field( $line ), $max_words, '…' );
		}
		return array_slice( $bullets, 0, $max_bullets );
	}
}
