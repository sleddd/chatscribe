<?php namespace ChatScribe\Admin;

use ChatScribe\Abilities\GeneratePost;
use ChatScribe\Abilities\Summarize;

/**
 * The AI boxes in the post editor's sidebar, on the post types ticked under
 * Settings > ChatScribe:
 *   Summarize - sums up the post as key points (chatscribe/summarize-post) to copy into a block.
 *   Generate  - writes content from instructions (chatscribe/generate-post) and adds it
 *               to the post as a Group block.
 * Both call their ability through the Abilities API's REST route. Nothing is saved
 * until the user saves the post.
 */
class EditorTools {

	const HANDLE = 'chatscribe-editor-tools';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Which boxes the current user gets on this post: [ 'summary' => bool, 'generation' => bool ].
	 */
	private function boxes_for( $post ): array {
		$id = $post instanceof \WP_Post ? $post->ID : 0;
		return array(
			'summary'    => $id && Summarize::user_can_use( $id ),
			'generation' => $id && GeneratePost::user_can_use( $id ),
		);
	}

	public function add_meta_boxes( $post_type, $post ) {
		$boxes = $this->boxes_for( $post );

		if ( $boxes['summary'] ) {
			add_meta_box( 'chatscribe-summary', __( 'AI Summary', 'chatscribe' ), array( $this, 'render_summary_box' ), $post_type, 'side' );
		}
		if ( $boxes['generation'] ) {
			add_meta_box( 'chatscribe-generation', __( 'AI Writer', 'chatscribe' ), array( $this, 'render_generation_box' ), $post_type, 'side' );
		}
	}

	public function enqueue_assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$post  = get_post();
		$boxes = $this->boxes_for( $post );
		if ( ! array_filter( $boxes ) ) {
			return;
		}

		$css  = 'src/assets/css/admin-editor.css';
		$js   = 'src/assets/js/admin-editor.js';
		$dir  = plugin_dir_path( CHATSCRIBE_FILE );
		$deps = array( 'wp-api-fetch' );
		if ( use_block_editor_for_post( $post ) ) {
			$deps = array_merge( $deps, array( 'wp-blocks', 'wp-data', 'wp-editor', 'wp-block-editor' ) );
		}

		wp_enqueue_style( self::HANDLE, plugins_url( $css, CHATSCRIBE_FILE ), array(), filemtime( $dir . $css ) );
		wp_enqueue_script( self::HANDLE, plugins_url( $js, CHATSCRIBE_FILE ), $deps, filemtime( $dir . $js ), true );
		wp_localize_script(
			self::HANDLE,
			'ChatScribeEditor',
			array(
				'postId'    => $post->ID,
				'abilities' => array(
					'summarize' => '/wp-abilities/v1/abilities/' . Summarize::NAME . '/run',
					'generate'  => '/wp-abilities/v1/abilities/' . GeneratePost::NAME . '/run',
				),
				'i18n'      => array(
					'summarizing' => __( 'Summarizing…', 'chatscribe' ),
					'generating'  => __( 'Researching and writing… this can take a minute.', 'chatscribe' ),
					'copied'      => __( 'Copied. Paste it into a block.', 'chatscribe' ),
					'copyFailed'  => __( 'Could not copy. Select the points and copy them instead.', 'chatscribe' ),
					'groupName'   => __( 'AI Post Content', 'chatscribe' ),
					'inserted'    => __( 'Added to the end of the post as the "AI Post Content" group.', 'chatscribe' ),
					'insertedRaw' => __( 'Added to the end of the post.', 'chatscribe' ),
					'noText'      => __( 'Add some text to the post first.', 'chatscribe' ),
					'noPrompt'    => __( 'Say what to write about first.', 'chatscribe' ),
					'error'       => __( 'Something went wrong. Please try again.', 'chatscribe' ),
				),
			)
		);
	}

	public function render_summary_box() {
		?>
		<div class="chatscribe-editor-box" data-chatscribe-tool="summary">
			<p class="description">
				<?php
				printf(
					/* translators: 1: max number of bullet points, 2: max words per bullet point. */
					esc_html__( 'Sums up the post as up to %1$d key points of up to %2$d words each.', 'chatscribe' ),
					(int) SettingsPage::get( 'summary_bullet_count' ),
					(int) SettingsPage::get( 'summary_bullet_words' )
				);
				?>
			</p>
			<p><button type="button" class="button" data-chatscribe-action="summarize"><?php esc_html_e( 'Summarize post', 'chatscribe' ); ?></button></p>
			<p class="chatscribe-editor-box__status" role="status" aria-live="polite"></p>
			<div class="chatscribe-editor-box__result" hidden>
				<ul class="chatscribe-editor-box__bullets"></ul>
				<p><button type="button" class="button button-secondary" data-chatscribe-action="copy"><?php esc_html_e( 'Copy key points', 'chatscribe' ); ?></button></p>
			</div>
		</div>
		<?php
	}

	public function render_generation_box() {
		?>
		<div class="chatscribe-editor-box" data-chatscribe-tool="generation">
			<p>
				<label for="chatscribe-generation-instructions"><?php esc_html_e( 'What should the AI write?', 'chatscribe' ); ?></label>
				<textarea id="chatscribe-generation-instructions" class="widefat" rows="5" maxlength="2000"
					placeholder="<?php esc_attr_e( 'For example: A post about our spring collection, highlighting the new arrivals.', 'chatscribe' ); ?>"></textarea>
			</p>
			<p class="description"><?php esc_html_e( 'The AI looks up what this site has on the subject, then writes. The result is added to the end of the post for you to edit.', 'chatscribe' ); ?></p>
			<p><button type="button" class="button button-primary" data-chatscribe-action="generate"><?php esc_html_e( 'Generate content', 'chatscribe' ); ?></button></p>
			<p class="chatscribe-editor-box__status" role="status" aria-live="polite"></p>
		</div>
		<?php
	}
}
