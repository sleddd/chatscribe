<?php namespace ChatScribe;

class Deactivation {
	public function __construct() {
		register_deactivation_hook( CHATSCRIBE_FILE, array( $this, 'deactivate_plugin' ) );
	}

	public function deactivate_plugin() {
		// Do de-activation steps here.
	}
}
