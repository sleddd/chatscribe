<?php namespace ChatScribe\Admin;

use ChatScribe\API\BackendChat;
use ChatScribe\Shortcodes\Chat as ChatWindow;

/**
 * The backend chat: the chat window in the WordPress admin, talking to the backend
 * chat endpoint (and so only the backend query). Shown while "Enable backend chat"
 * is on, to users whose role is ticked under Backend AI requests > Permissions.
 */
class Chat {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( $this, 'render' ) );
	}

	private function is_available(): bool {
		return true === BackendChat::can_chat();
	}

	public function enqueue_assets() {
		if ( ! $this->is_available() ) {
			return;
		}
		ChatWindow::register_assets_for( 'backend' );
		wp_enqueue_style( ChatWindow::HANDLE );
		wp_enqueue_script( ChatWindow::HANDLE );
	}

	public function render() {
		if ( $this->is_available() ) {
			echo ChatWindow::markup(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in markup().
		}
	}
}
