<?php namespace ChatScribe;

class Plugin {
    public function __construct() {
        $this->init();
    }

    public function init() {
        new Activation();
        new Deactivation();
        new Abilities\Query();
        new AI\Client();
        new API\Chat();
        new Shortcodes\Chat();
        $this->renderShortcode();
    }

    public function renderShortcode() {
        add_action('wp_footer', function() {
           echo do_shortcode('[chatscribe/]');
        });
    }
}