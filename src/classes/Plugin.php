<?php namespace ChatScribe;

class Plugin {
	public function __construct() {
		$this->init();
	}

	public function init() {
		new Activation();
		new Deactivation();
		new Abilities\FrontendQuery();
		new Abilities\BackendQuery();
		new Abilities\Summarize();
		new Abilities\GeneratePost();
		new Abilities\GenerationQuery();
		new AI\Client();
		new API\FrontendChat();
		new API\BackendChat();
		new Shortcodes\Chat();
		new Admin\Chat();
		new Admin\SettingsPage();
		new Admin\EditorTools();
		$this->renderShortcode();
	}

	public function renderShortcode() {
		add_action(
			'wp_footer',
			function () {
				echo do_shortcode( '[chatscribe/]' );
			}
		);
	}
}
